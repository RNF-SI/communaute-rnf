<?php

namespace App\Command;

use App\Postmark\BulkTransport;
use App\Service\EmailSender;
use App\Service\MailDeliverability;
use Swift_Message;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Throwable;

/**
 * « Est-ce que les e-mails marchent ? », répondu depuis la machine qui les
 * envoie. (#14)
 *
 * `app:preflight` dit si les jetons sont renseignés. Ce n'est pas la même
 * question : un jeton présent peut être invalide, et surtout un envoi peut
 * partir sans jamais arriver, parce que le domaine n'a pas autorisé Postmark.
 * C'est le cas aujourd'hui, et rien ne permettait de le constater ailleurs
 * que dans une boîte de réception.
 *
 * Trois choses, dans l'ordre où elles cassent :
 *
 * 1. le DNS du domaine d'envoi — SPF, DKIM, Return-Path, DMARC ;
 * 2. la configuration des deux chemins d'envoi ;
 * 3. sur demande, un vrai message par chacun de ces deux chemins.
 *
 * Deux chemins, parce que la plateforme envoie par deux transports avec deux
 * jetons différents : l'un peut fonctionner pendant que l'autre est muet,
 * c'est exactement ce qui s'était produit en #4.
 */
class MailCheckCommand extends Command {
	protected static $defaultName = 'app:mail:check';

	private $parameters;

	private $deliverability;

	private $sender;

	private $bulk;

	/**
	 * Le transport des discussions, pas le mailer : c'est lui qui porte le
	 * second jeton, et c'est lui qu'il faut éprouver.
	 */
	public function __construct (
			ParameterBagInterface $parameters,
			MailDeliverability $deliverability,
			EmailSender $sender,
			BulkTransport $bulk
	) {
		$this->parameters     = $parameters;
		$this->deliverability = $deliverability;
		$this->sender         = $sender;
		$this->bulk           = $bulk;

		parent::__construct();
	}

	protected function configure () {
		$this
				->setDescription( 'Check that this environment can actually deliver e-mail' )
				->setHelp(
						"Reads nothing but the DNS and the configuration unless --to is given.\n"
						. "With --to, sends one real message through each of the two paths, so that\n"
						. "a working transactional token and a silent bulk one can be told apart."
				)
				->addOption(
						'to',
						NULL,
						InputOption::VALUE_REQUIRED,
						'Send a real test message to this address, through both paths'
				);
	}

	protected function execute ( InputInterface $input, OutputInterface $output ) {
		$io = new SymfonyStyle( $input, $output );

		$environment = $this->parameters->get( 'kernel.environment' );
		$platform    = $this->parameters->get( 'plateform' );
		$postmark    = $this->parameters->get( 'postmark' );

		$io->title( sprintf( 'E-mails — environnement « %s »', $environment ) );

		$domains = $this->domainsOf( $postmark, $platform );

		/**
		 * 1. LE DNS
		 *
		 * Les deux chemins d'envoi ne partent pas forcément du même domaine :
		 * le transactionnel de POSTMARK_SENDER, les discussions de
		 * POSTMARK_LIST_DOMAIN. Un domaine autorisé et l'autre non donne une
		 * moitié d'e-mails qui arrive — le pire cas pour diagnostiquer.
		 */
		$blocked = 0;

		foreach ( $domains as $usage => $domain ) {
			$io->section( sprintf( 'Le DNS de %s — %s', $domain ?: '(domaine inconnu)', $usage ) );

			$rows = [];

			foreach ( $this->deliverability->check( $domain ) as $record ) {
				if ( $record[ 'status' ] === MailDeliverability::FAILED ) {
					$blocked++;
				}

				$rows[] = [ $this->badge( $record[ 'status' ] ), $record[ 'label' ], $record[ 'detail' ] ];
			}

			$io->table( [ '', 'Enregistrement', 'Constat' ], $rows );
		}

		if ( $blocked > 0 ) {
			$io->warning(
					'Tant que ces enregistrements manquent, un e-mail peut partir sans arriver : '
					. 'il échoue l’authentification, et DMARC dit aux destinataires quoi en faire. '
					. 'Voir docs/delivrabilite-emails.md pour les valeurs à publier.'
			);
		}

		/**
		 * 2. LA CONFIGURATION
		 */
		$io->section( 'Les deux chemins d’envoi' );

		$io->table(
				[ '', 'Chemin', 'Détail' ],
				[
						[
								$this->badge( empty( $platform[ 'from' ] ) ? MailDeliverability::FAILED : MailDeliverability::OK ),
								'Expéditeur',
								$platform[ 'from' ] ?: 'POSTMARK_SENDER vide',
						],
						[
								$this->badge( empty( $postmark[ 'list_domain' ] ) ? MailDeliverability::WARNING : MailDeliverability::OK ),
								'Domaine de liste',
								$postmark[ 'list_domain' ] ?: 'POSTMARK_LIST_DOMAIN vide',
						],
						[
								$this->badge( empty( $postmark[ 'server_token' ] ) ? MailDeliverability::FAILED : MailDeliverability::OK ),
								'Transactionnel (résumé, adhésion, mot de passe)',
								empty( $postmark[ 'server_token' ] )
										? 'POSTMARK_SERVER_TOKEN vide'
										: 'jeton renseigné',
						],
						[
								$this->badge( empty( $postmark[ 'bulk_token' ] ) ? MailDeliverability::FAILED : MailDeliverability::OK ),
								'Discussions',
								empty( $postmark[ 'bulk_token' ] )
										? 'POSTMARK_BULK_TOKEN vide — rien ne part, et rien ne le signale'
										: 'jeton renseigné',
						],
				]
		);

		/**
		 * 3. UN VRAI ENVOI
		 */
		$to = $input->getOption( 'to' );

		if ( !$to ) {
			$io->note( 'Aucun envoi : relancer avec --to=adresse@exemple.org pour éprouver les deux chemins.' );

			return $blocked > 0 ? 1 : 0;
		}

		$io->section( sprintf( 'Deux messages vers %s', $to ) );

		$transactional = $this->sendTransactional( $to, $platform, $environment );
		$discussion    = $this->sendBulk( $to, $postmark, $environment );

		$io->table(
				[ '', 'Chemin', 'Résultat' ],
				[
						[ $this->badge( $transactional[ 0 ] ), 'Transactionnel', $transactional[ 1 ] ],
						[ $this->badge( $discussion[ 0 ] ), 'Discussions', $discussion[ 1 ] ],
				]
		);

		$failed = ( $transactional[ 0 ] === MailDeliverability::FAILED )
				  || ( $discussion[ 0 ] === MailDeliverability::FAILED );

		if ( !$failed ) {
			$io->success(
					'Les deux messages ont été remis au transport. Reste à vérifier qu’ils arrivent : '
					. 'regarder la boîte de réception, et le dossier indésirables.'
			);
		}

		return ( $failed || ( $blocked > 0 ) ) ? 1 : 0;
	}

	/**
	 * @param string $to
	 * @param array  $platform
	 * @param string $environment
	 *
	 * @return array status, detail
	 */
	private function sendTransactional ( $to, array $platform, $environment ) {
		if ( empty( $platform[ 'from' ] ) ) {
			return [ MailDeliverability::FAILED, 'pas d’expéditeur configuré' ];
		}

		try {
			$sent = $this->sender->send(
					$platform[ 'from' ],
					$to,
					$this->subject( 'transactionnel', $environment ),
					$this->body( 'transactionnel', $environment )
			);

			// Le garde de #14 refuse une adresse qui ne peut rien recevoir, et
			// renvoie 0 sans rien dire : c'est le cas à ne pas confondre avec
			// une panne de transport.
			return $sent > 0
					? [ MailDeliverability::OK, 'remis au transport' ]
					: [
							MailDeliverability::WARNING,
							'rien envoyé — adresse jugée non délivrable, ou livraison désactivée dans cet environnement',
					];
		}
		catch ( Throwable $error ) {
			return [ MailDeliverability::FAILED, $error->getMessage() ];
		}
	}

	/**
	 * @param string $to
	 * @param array  $postmark
	 * @param string $environment
	 *
	 * @return array status, detail
	 */
	private function sendBulk ( $to, array $postmark, $environment ) {
		if ( empty( $postmark[ 'bulk_token' ] ) && ( $environment === 'prod' ) ) {
			return [ MailDeliverability::FAILED, 'POSTMARK_BULK_TOKEN vide — le transport ne fait rien, en silence' ];
		}

		$from = 'noreply@' . ( $postmark[ 'list_domain' ] ?: 'localhost' );

		try {
			$message = ( new Swift_Message( $this->subject( 'discussion', $environment ) ) )
					->setFrom( $from )
					->setTo( $to )
					->setBody( $this->body( 'discussion', $environment ), 'text/html' )
					->addPart( strip_tags( $this->body( 'discussion', $environment ) ), 'text/plain' );

			// Le transport expose sendMultiple, comme DiscussionSender l'appelle.
			$sent = $this->bulk->sendMultiple( [ $message ] );

			return $sent > 0
					? [ MailDeliverability::OK, sprintf( 'remis au transport, expéditeur %s', $from ) ]
					: [ MailDeliverability::WARNING, 'rien envoyé — transport muet dans cet environnement' ];
		}
		catch ( Throwable $error ) {
			return [ MailDeliverability::FAILED, $error->getMessage() ];
		}
	}

	/**
	 * Les domaines depuis lesquels la plateforme écrit, et à quoi chacun sert.
	 *
	 * Un seul quand les deux coïncident : on ne fait pas lire deux fois le
	 * même tableau.
	 *
	 * @param array $postmark
	 * @param array $platform
	 *
	 * @return array<string, string> usage => domaine
	 */
	private function domainsOf ( array $postmark, array $platform ) {
		$sender = '';

		if ( !empty( $platform[ 'from' ] ) && ( strpos( $platform[ 'from' ], '@' ) !== FALSE ) ) {
			$sender = substr( strrchr( $platform[ 'from' ], '@' ), 1 );
		}

		$list = !empty( $postmark[ 'list_domain' ] ) ? $postmark[ 'list_domain' ] : '';

		if ( $sender && $list && ( mb_strtolower( $sender ) === mb_strtolower( $list ) ) ) {
			return [ 'les deux chemins' => $sender ];
		}

		$domains = [];

		if ( $sender ) {
			$domains[ 'résumé, adhésion, mot de passe' ] = $sender;
		}

		if ( $list ) {
			$domains[ 'messages de discussion' ] = $list;
		}

		// Aucun des deux configuré : le contrôle doit le dire plutôt que de
		// n'afficher aucun tableau.
		return $domains ?: [ 'domaine d’envoi' => '' ];
	}

	/**
	 * @param string $path
	 * @param string $environment
	 *
	 * @return string
	 */
	private function subject ( $path, $environment ) {
		return sprintf( '[%s] Test d’envoi — chemin %s', $environment, $path );
	}

	/**
	 * @param string $path
	 * @param string $environment
	 *
	 * @return string
	 */
	private function body ( $path, $environment ) {
		return sprintf(
				'<p>Message de contrôle envoyé par <code>app:mail:check</code> depuis l’environnement'
				. ' <strong>%s</strong>, par le chemin <strong>%s</strong>.</p>'
				. '<p>S’il est arrivé dans les indésirables, le DNS du domaine d’envoi est en cause,'
				. ' pas la plateforme.</p>',
				htmlspecialchars( $environment, ENT_QUOTES ),
				htmlspecialchars( $path, ENT_QUOTES )
		);
	}

	/**
	 * @param string $status
	 *
	 * @return string
	 */
	private function badge ( $status ) {
		switch ( $status ) {
			case MailDeliverability::OK:
				return 'OK';

			case MailDeliverability::WARNING:
				return 'attention';

			default:
				return 'ÉCHEC';
		}
	}
}
