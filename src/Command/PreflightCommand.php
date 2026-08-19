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
