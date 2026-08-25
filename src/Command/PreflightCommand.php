<?php

namespace App\Command;

use App\Service\MailGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Checks, on the machine that will actually run the platform, everything whose
 * absence causes a silent failure rather than an error.
 *
 * Every line here stands for a trap met while working on this codebase: an
 * extension without which nothing boots, a token whose absence sends no e-mail
 * and raises nothing, a host without which every link of a scheduled e-mail
 * points at localhost.
 */
class PreflightCommand extends Command {
	protected static $defaultName = 'app:preflight';

	private $manager;

	private $parameters;

	private $router;

	private $guard;

	public function __construct (
			EntityManagerInterface $manager,
			ParameterBagInterface $parameters,
			UrlGeneratorInterface $router,
			MailGuard $guard
	) {
		$this->manager    = $manager;
		$this->parameters = $parameters;
		$this->router     = $router;
		$this->guard      = $guard;

		parent::__construct();
	}

	protected function configure () {
		$this->setDescription( 'Check that this environment can actually run the platform' );
	}

	protected function execute ( InputInterface $input, OutputInterface $output ) {
		$io = new SymfonyStyle( $input, $output );

		$io->title( sprintf( 'Environment "%s"', $this->parameters->get( 'kernel.environment' ) ) );

		$rows     = [];
		$blocking = 0;

		foreach ( $this->checks() as $check ) {
			list( $label, $ok, $detail, $isBlocking ) = $check;

			if ( !$ok && $isBlocking ) {
				$blocking++;
			}

			$rows[] = [ $ok ? 'OK' : ( $isBlocking ? 'ÉCHEC' : 'attention' ), $label, $detail ];
		}

		$io->table( [ '', 'Vérification', 'Détail' ], $rows );

		if ( $blocking > 0 ) {
			$io->error( sprintf( '%d vérification(s) bloquante(s) en échec.', $blocking ) );

			return 1;
		}

		$io->success( 'Rien de bloquant.' );

		return 0;
	}

	/**
	 * @return array each entry: label, ok, detail, blocking
	 */
	private function checks () {
		$checks = [];

		// LiipImagine is configured on imagick; without it nothing boots.
		$checks[] = [
				'Extension imagick',
				extension_loaded( 'imagick' ),
				extension_loaded( 'imagick' ) ? 'présente' : 'absente — l’application ne démarrera pas',
				TRUE,
		];

		// Le mode debug en production expose les traces d'exécution, ralentit
		// tout, et surtout change le comportement des gabarits : il active
		// strict_variables, qui transforme une valeur absente en erreur fatale
		// plutôt qu'en silence. C'est ce qui rendait #3 visible aux membres.
		$debug = (bool) $this->parameters->get( 'kernel.debug' );
		$prod  = $this->parameters->get( 'kernel.environment' ) === 'prod';

		$checks[] = [
				'Mode debug',
				!( $prod && $debug ),
				$prod && $debug
						? 'actif en production — traces exposées, et les gabarits ne se comportent pas comme prévu'
						: ( $debug ? 'actif, cohérent avec cet environnement' : 'désactivé' ),
				FALSE,
		];

		$checks[] = [
				'Connexion base de données',
				$this->canReachDatabase(),
				$this->canReachDatabase() ? 'joignable' : 'injoignable',
				TRUE,
		];

		$checks[] = [
				'Migrations',
				$this->pendingMigrations() === 0,
				$this->pendingMigrations() === 0
						? 'à jour'
						: sprintf( '%d migration(s) non jouée(s)', $this->pendingMigrations() ),
				TRUE,
		];

		// Without a request context the router falls back on localhost, and
		// every link of a scheduled e-mail points nowhere.
		$host = $this->parameters->has( 'router.request_context.host' )
				? (string) $this->parameters->get( 'router.request_context.host' )
				: '';
		$checks[] = [
				'SITE_HOST',
				( $host !== '' ) && ( $host !== 'localhost' ),
				$host === '' ? 'vide' : $host,
				FALSE,
		];

		$platform = $this->parameters->get( 'plateform' );
		$checks[] = [
				'POSTMARK_SENDER',
				!empty( $platform[ 'from' ] ),
				empty( $platform[ 'from' ] )
						? 'vide — les demandes d’adhésion échoueront'
						: $platform[ 'from' ],
				FALSE,
		];

		$postmark = $this->parameters->get( 'postmark' );

		$checks[] = [
				'POSTMARK_SERVER_TOKEN',
				!empty( $postmark[ 'server_token' ] ),
				empty( $postmark[ 'server_token' ] ) ? 'vide — aucun e-mail transactionnel' : 'renseigné',
				FALSE,
		];

		// Its absence sends nothing and raises nothing at all.
		$checks[] = [
				'POSTMARK_BULK_TOKEN',
				!empty( $postmark[ 'bulk_token' ] ),
				empty( $postmark[ 'bulk_token' ] )
						? 'vide — aucun e-mail de discussion, silencieusement'
						: 'renseigné',
				FALSE,
		];

		$checks[] = [
				'POSTMARK_INBOUND_KEY',
				!empty( $postmark[ 'inbound_key' ] ),
				empty( $postmark[ 'inbound_key' ] )
						? 'vide — les réponses par e-mail n’aboutiront pas'
						: 'renseigné',
				FALSE,
		];

		$checks[] = [
				'Configuration plateforme',
				file_exists( $this->parameters->get( 'kernel.project_dir' ) . '/config/platform/config.yaml' ),
				file_exists( $this->parameters->get( 'kernel.project_dir' ) . '/config/platform/config.yaml' )
						? 'présente'
						: 'config/platform/config.yaml manquant',
				TRUE,
		];

		foreach ( [ 'var/files/users', 'var/files/groups' ] as $path ) {
			$full = $this->parameters->get( 'kernel.project_dir' ) . '/' . $path;
			$ok   = is_dir( $full ) && is_writable( $full );

			$checks[] = [
					'Écriture ' . $path,
					$ok,
					$ok ? 'accessible en écriture' : 'absent ou non inscriptible',
					FALSE,
			];
		}

		// TNTSearch écrit ses index dans des fichiers SQLite. Une commande
		// console lancée par un autre utilisateur qu'Apache les rend
		// inaccessibles en écriture, et la création d'un compte échoue sur
		// « attempt to write a readonly database » — au moment précis où
		// quelqu'un se connecte pour la première fois.
		$indexDir = rtrim( $this->parameters->get( 'kernel.project_dir' ), '/' )
					. '/' . trim( (string) $this->parameters->get( 'search_index_dir' ), '/' );

		$indexWritable = is_dir( $indexDir ) && is_writable( $indexDir ) && $this->indexFilesWritable( $indexDir );

		$checks[] = [
				'Écriture des index de recherche',
				$indexWritable,
				$indexWritable
						? 'accessible en écriture'
						: sprintf( '%s non inscriptible — la création de compte échouera', $indexDir ),
				TRUE,
		];

		// Toute l'authentification tient dans la session : il n'y a ni
		// « remember me » ni jeton persistant. Une session trop courte, ou un
		// répertoire que le serveur web ne peut pas écrire, et l'on est
		// déconnecté sans que rien n'apparaisse dans les journaux.
		$lifetime = (int) $this->parameters->get( 'session_lifetime' );

		$checks[] = [
				'Durée de session',
				$lifetime >= 3600,
				$lifetime === 0
						? 'non réglée — PHP décide, souvent 24 minutes d’inactivité'
						: sprintf(
								'%s (SESSION_LIFETIME=%d)',
								$this->humanDuration( $lifetime ),
								$lifetime
						),
				FALSE,
		];

		// Le répertoire n'existe pas encore au premier déploiement : c'est la
		// première requête web qui le crée. Ce qui compte est donc de savoir
		// si le serveur web y arrivera — d'où la remontée jusqu'au premier
		// parent existant.
		$sessionDir = (string) $this->parameters->get( 'session.save_path' );
		$sessionOk  = is_writable( $this->nearestExistingDirectory( $sessionDir ) );

		$checks[] = [
				'Écriture des sessions',
				$sessionOk,
				$sessionOk
						? ( is_dir( $sessionDir ) ? $sessionDir : $sessionDir . ' (sera créé)' )
						: sprintf(
								'%s non inscriptible — personne ne pourra se connecter',
								$sessionDir
						),
				TRUE,
		];

		$checks[] = [
				'Assets compilés',
				file_exists( $this->parameters->get( 'kernel.project_dir' ) . '/public/build/entrypoints.json' ),
				file_exists( $this->parameters->get( 'kernel.project_dir' ) . '/public/build/entrypoints.json' )
						? 'présents'
						: 'public/build absent — npm run build a échoué',
				TRUE,
		];

		return $checks;
	}

	/**
	 * @param string $path
	 *
	 * @return string the first ancestor of $path that exists, $path included
	 */
	private function nearestExistingDirectory ( $path ) {
		while ( !is_dir( $path ) ) {
			$parent = dirname( $path );

			if ( $parent === $path ) {
				return $path;
			}

			$path = $parent;
		}

		return $path;
	}

	/**
	 * @param int $seconds
	 *
	 * @return string
	 */
	private function humanDuration ( $seconds ) {
		if ( $seconds >= 86400 ) {
			return sprintf( '%d jour(s)', (int) round( $seconds / 86400 ) );
		}

		if ( $seconds >= 3600 ) {
			return sprintf( '%d heure(s)', (int) round( $seconds / 3600 ) );
		}

		return sprintf( '%d minute(s)', (int) round( $seconds / 60 ) );
	}

	/**
	 * Le répertoire ne suffit pas : SQLite doit pouvoir réécrire chaque index.
	 *
	 * @param string $directory
	 *
	 * @return bool
	 */
	private function indexFilesWritable ( $directory ) {
		foreach ( (array) glob( $directory . '/*.index' ) as $index ) {
			if ( !is_writable( $index ) ) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**
	 * @return bool
	 */
	private function canReachDatabase () {
		try {
			$this->manager->getConnection()->connect();

			return $this->manager->getConnection()->isConnected();
		}
		catch ( Throwable $e ) {
			return FALSE;
		}
	}

	/**
	 * @return int
	 */
	private function pendingMigrations () {
		try {
			$available = count( glob( $this->parameters->get( 'kernel.project_dir' ) . '/migrations/Version*.php' ) );
			$executed  = (int) $this->manager->getConnection()
											 ->fetchColumn( 'SELECT COUNT(*) FROM doctrine_migration_versions' );

			return max( 0, $available - $executed );
		}
		catch ( Throwable $e ) {
			return 0;
		}
	}
}
