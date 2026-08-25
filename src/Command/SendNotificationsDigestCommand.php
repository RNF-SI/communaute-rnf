<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationRhythm;
use App\Service\EmailSender;
use App\Service\HashGenerator;
use App\Service\MailSpool;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Throwable;
use Twig\Environment;

/**
 * Issue #34 — one e-mail per member summarising everything they asked to hear
 * about, instead of one e-mail per event.
 *
 * Meant to be run **once a day, every day**. Ce qui est réglé sur
 * l'hebdomadaire est passé six jours sur sept ; ces notifications-là attendent
 * plutôt que d'être perdues, et le lundi les emporte — avec le quotidien du
 * jour, dans le même e-mail. (#38)
 *
 * Nothing is sent to somebody who has nothing waiting, so a quiet day sends
 * no e-mail at all.
 *
 * **S'éprouver soi-même fait partie du travail.** L'hebdomadaire ne part qu'un
 * jour sur sept, et une préproduction porte souvent une copie anonymisée dont
 * toutes les adresses rebondissent : attendre lundi pour voir, ou lancer la
 * commande telle quelle, ne sont ni l'un ni l'autre des façons de la tester.
 * D'où `--day` (se placer un lundi), `--only` (n'écrire qu'à un compte) et
 * `--keep` (ne rien consommer, pour recommencer). Ce sont des options de la
 * vraie commande et non une commande de démonstration à côté : une seconde
 * mécanique d'envoi finirait par diverger de celle qui part la nuit, et le
 * test dirait alors le contraire de la production.
 */
class SendNotificationsDigestCommand extends Command {
	protected static $defaultName = 'app:notifications:digest';

	private $manager;

	private $mailer;

	private $twig;

	private $parameters;

	private $hashGenerator;

	private $router;

	/**
	 * @var \App\Service\MailSpool
	 */
	private $spool;

	public function __construct (
			EntityManagerInterface $manager,
			EmailSender $mailer,
			Environment $twig,
			ParameterBagInterface $parameters,
			HashGenerator $hashGenerator,
			UrlGeneratorInterface $router,
			MailSpool $spool
	) {
		$this->manager       = $manager;
		$this->mailer        = $mailer;
		$this->twig          = $twig;
		$this->parameters    = $parameters;
		$this->hashGenerator = $hashGenerator;
		$this->router        = $router;
		$this->spool         = $spool;

		parent::__construct();
	}

	protected function configure () {
		$this
				->setDescription( 'Send the summary of notifications due today' )
				->setHelp(
						"Run once a day, every day. Ce qu'un membre a réglé sur l'hebdomadaire\n"
						. "n'est emporté que le lundi ; le reste part tous les jours, et le lundi\n"
						. "les deux tiennent dans le même e-mail. Personne n'est écrit à vide.\n"
						. "\n"
						. "Pour l'éprouver sur une préproduction sans écrire à tout le réseau :\n"
						. "  --day=2026-08-31 --dry-run                      ce qu'un lundi enverrait\n"
						. "  --day=2026-08-31 --only=vous@rnfrance.org --keep le vrai e-mail, à vous seul"
				)
				->addOption( 'dry-run', NULL, InputOption::VALUE_NONE, 'Report what would be sent without sending' )
				->addOption(
						'day',
						NULL,
						InputOption::VALUE_REQUIRED,
						'The day to send for, YYYY-MM-DD. Defaults to today; useful to check what a Monday would send.'
				)
				->addOption(
						'only',
						NULL,
						InputOption::VALUE_REQUIRED,
						'Only write to this member, by e-mail address. Pour éprouver l\'envoi sans écrire à tout le réseau.'
				)
				->addOption(
						'keep',
						NULL,
						InputOption::VALUE_NONE,
						'Ne marque rien comme envoyé : le même résumé repart au prochain lancement. Exige --only.'
				);
	}

	protected function execute ( InputInterface $input, OutputInterface $output ) {
		$io     = new SymfonyStyle( $input, $output );
		$dryRun = $input->getOption( 'dry-run' );
		$day    = $input->getOption( 'day' );
		$only   = $input->getOption( 'only' );
		$keep   = $input->getOption( 'keep' );

		try {
			$day = $day ? new DateTime( $day ) : new DateTime();
		}
		catch ( \Exception $exception ) {
			$io->error( sprintf( 'Unreadable day: %s', $input->getOption( 'day' ) ) );

			return 1;
		}

		// Sans --only, --keep laisserait tout le réseau en attente : le même
		// résumé repartirait à tout le monde le lendemain, et le surlendemain.
		// C'est une option pour recommencer un essai, pas pour un vrai envoi.
		if ( $keep && !$only ) {
			$io->error( '--keep ne s\'emploie qu\'avec --only : sinon le même résumé repartirait à tout le monde demain.' );

			return 1;
		}

		$repository = $this->manager->getRepository( Notification::class );
		$recipients = $repository->findRecipientsAwaitingDigest();

		if ( $only ) {
			$target = $this->manager->getRepository( User::class )
								    ->findOneBy( [ 'email' => trim( $only ) ] );

			if ( !$target ) {
				$io->error( sprintf( 'Aucun compte ne porte l\'adresse « %s ».', trim( $only ) ) );

				return 1;
			}

			$recipients = $this->restrictTo( $recipients, $target );

			if ( $recipients === NULL ) {
				$io->error( sprintf( 'Rien n\'attend d\'e-mail pour %s.', $target->getEmail() ) );
				$this->explainNothing( $io, $repository->digestState( $target ), $target );

				return 1;
			}
		}

		$io->title( sprintf(
				'%s — %d members have notifications waiting',
				$day->format( 'l j F Y' ),
				count( $recipients )
		) );

		if ( $only ) {
			$io->note( sprintf(
					'Restreint à %s%s.',
					$only,
					$keep ? ', et rien ne sera marqué comme envoyé' : ''
			) );
		}

		$sent    = 0;
		$skipped = 0;
		$waiting = 0;
		$failed  = 0;

		foreach ( $recipients as $recipient ) {
			// Le rythme hebdomadaire se joue ici : rien n'est marqué comme
			// envoyé, les notifications restent en attente jusqu'à lundi.
			// Depuis #38 le rythme se lit sur chaque notification, si bien
			// qu'un même membre peut avoir du quotidien qui part aujourd'hui
			// et de l'hebdomadaire qui attend. (#38)
			$notifications = $repository->findAwaitingDigestFor( $recipient, $day );

			// En inspection, la ligne est écrite avant tout tri : un compte
			// qui n'a que de l'hebdomadaire doit se voir passé un mardi, pas
			// disparaître dans un total.
			if ( $dryRun || $only ) {
				$io->text( sprintf(
						'#%d %s : %s',
						$recipient->getId(),
						$recipient->getEmail(),
						$this->describe( $repository->findAwaitingDigestFor( $recipient ), $notifications )
				) );
			}

			if ( empty( $notifications ) ) {
				$waiting++;

				continue;
			}

			// The preference may have changed since the notifications were
			// queued; the last word belongs to what the member wants now.
			if ( !$recipient->wantsEmails() ) {
				if ( !$keep ) {
					$this->markAsSent( $notifications );
				}

				$skipped++;

				continue;
			}

			if ( $dryRun ) {
				$sent++;

				continue;
			}

			try {
				// Swiftmailer est en file mémoire : sans ce contrôle, on
				// marquait comme envoyé ce qui n'était que mis en file, et le
				// refus de Postmark arrivait après la fin de la commande, sans
				// personne pour l'entendre.
				if ( !$this->send( $recipient, $notifications ) ) {
					$io->warning( sprintf(
							'#%d : refusé à l\'envoi. Rien n\'est marqué, le prochain lancement réessaiera.',
							$recipient->getId()
					) );

					$failed++;

					continue;
				}

				if ( !$keep ) {
					$this->markAsSent( $notifications );
				}

				$sent++;
			}
			catch ( Throwable $e ) {
				// Left unmarked on purpose: the next run tries again rather
				// than dropping the summary on the floor.
				$io->warning( sprintf( '#%d: %s', $recipient->getId(), $e->getMessage() ) );

				$failed++;
			}
		}

		if ( !$dryRun ) {
			$this->manager->flush();
		}

		$io->success( sprintf(
				'%d summaries %s, %d with nothing due today, %d dropped for members who refuse e-mails, %d failed',
				$sent,
				$dryRun ? 'would be sent' : 'sent',
				$waiting,
				$skipped,
				$failed
		) );

		// Le compte rendu d'abord, l'explication ensuite : un zéro sans raison
		// renvoie à la base de données, où personne n'ira regarder.
		if ( empty( $recipients ) ) {
			$this->explainNothing( $io, $repository->digestState() );
		}

		if ( $keep && ( $sent > 0 ) ) {
			$io->note( 'Rien n\'a été consommé : les mêmes notifications repartiront au prochain lancement avec --only.' );
		}

		return 0;
	}

	/**
	 * Le seul destinataire retenu, ou NULL s'il n'y a rien à lui envoyer.
	 *
	 * On filtre la liste plutôt que d'aller chercher le compte : ce qu'on veut
	 * éprouver, c'est le chemin que suit la commande la nuit, sélection
	 * comprise. Un compte absent de cette liste n'aurait rien reçu de toute
	 * façon, et la commande doit le dire au lieu d'envoyer un e-mail vide.
	 *
	 * @param \App\Entity\User[] $recipients
	 * @param \App\Entity\User   $target
	 *
	 * @return \App\Entity\User[]|null
	 */
	private function restrictTo ( array $recipients, User $target ) {
		foreach ( $recipients as $recipient ) {
			if ( $recipient->getId() === $target->getId() ) {
				return [ $recipient ];
			}
		}

		return NULL;
	}

	/**
	 * Dire pourquoi il n'y a rien, plutôt que zéro.
	 *
	 * Un exploitant qui lit « 0 » sur une préproduction ne peut pas savoir
	 * s'il a mal réglé quelque chose ou si simplement personne n'a rien
	 * publié. Il conclut alors que « les e-mails ne marchent pas », ce qui
	 * n'est ni vrai ni faux, et cherche du côté de Postmark.
	 *
	 * @param \Symfony\Component\Console\Style\SymfonyStyle $io
	 * @param array                                        $state
	 * @param \App\Entity\User|null                        $user
	 */
	private function explainNothing ( SymfonyStyle $io, array $state, User $user = NULL ) {
		$io->section( $user ? sprintf( 'Ce que la base dit de %s', $user->getEmail() ) : 'Ce que la base dit' );

		if ( $user ) {
			$io->table( [ 'Le compte', '' ], [
					[ 'Statut', $user->getStatus() === User::STATUS_ACTIVE ? 'actif' : $user->getStatus() ],
					[ 'Accepte les e-mails', $user->wantsEmails() ? 'oui' : 'NON — réglage général « aucun e-mail »' ],
			] );
		}

		$io->table( [ 'Notifications', '' ], [
				[ 'En tout', $state[ 'total' ] ],
				[ 'En attente d\'un résumé', $state[ 'waiting' ] ],
				[ 'Déjà parties', $state[ 'sent' ] ],
				[ 'Sans e-mail (byEmail = 0)', $state[ 'silent' ] ],
				[ 'La plus récente', $state[ 'last' ] ?: '—' ],
				[ 'Dernier envoi', $state[ 'lastSent' ] ?: '—' ],
		] );

		// Le résumé ne va qu'aux comptes actifs : un compte suspendu peut avoir
		// tout ce qu'il faut en attente et n'être servi par personne.
		if ( $user && ( $state[ 'waiting' ] > 0 ) && ( $user->getStatus() !== User::STATUS_ACTIVE ) ) {
			$io->warning(
					'Des notifications attendent, mais ce compte n\'est pas actif : le résumé ne s\'adresse '
					. 'qu\'aux comptes actifs.'
			);

			return;
		}

		// Les trois verdicts commencent par une phrase courte : c'est elle
		// qu'on lit, et c'est elle que les épreuves attendent — le reste peut
		// se replier au gré de la largeur du terminal.
		if ( $state[ 'total' ] === 0 ) {
			$io->warning( implode( "\n", [
					'RIEN N\'A ÉTÉ PUBLIÉ.',
					'Aucune notification n\'existe' . ( $user ? ' pour ce compte' : '' ) . ', donc rien à résumer.',
					'Ce n\'est pas une panne d\'envoi.',
					'Publier une page ou une actualité dans un groupe, avec un AUTRE compte :',
					'personne n\'est notifié de ce qu\'il publie lui-même.',
			] ) );

			return;
		}

		if ( $state[ 'waiting' ] > 0 ) {
			return;
		}

		if ( $state[ 'silent' ] >= $state[ 'sent' ] ) {
			$io->warning( implode( "\n", [
					'CE SONT LES RÉGLAGES.',
					'Des notifications existent, mais aucune ne doit partir par e-mail :',
					'niveau « aucune » ou « sur la plateforme seulement », e-mail immédiat',
					'déjà parti, ou refus général des e-mails.',
					'Régler une catégorie sur « résumé quotidien » ou « hebdomadaire »',
					'dans /user/parameters/edit, puis republier.',
			] ) );

			return;
		}

		$io->warning( implode( "\n", [
				'TOUT EST DÉJÀ PARTI.',
				'Voir « Dernier envoi » ci-dessus : un résumé n\'est envoyé qu\'une fois.',
				'Pour le revoir, republier quelque chose — ou employer --only et --keep,',
				'qui ne consomment rien.',
		] ) );
	}

	/**
	 * Ce qui part aujourd'hui, et ce qui attend un autre jour.
	 *
	 * C'est la ligne qui répond à « est-ce que l'hebdomadaire fonctionne ? » :
	 * un jour ordinaire montre des notifications retenues, un lundi les montre
	 * emportées. Le partage entre les deux n'est pas recalculé ici — il est lu
	 * sur ce que le dépôt a retenu pour ce jour-là.
	 *
	 * @param Notification[] $all what is waiting for an e-mail, any day
	 * @param Notification[] $due what leaves on the day asked for
	 *
	 * @return string
	 */
	private function describe ( array $all, array $due ) {
		$rhythms = [];

		foreach ( $due as $notification ) {
			$rhythm             = $notification->getRhythm();
			$rhythms[ $rhythm ] = isset( $rhythms[ $rhythm ] ) ? $rhythms[ $rhythm ] + 1 : 1;
		}

		$detail = [];

		foreach ( NotificationRhythm::all() as $rhythm ) {
			if ( !empty( $rhythms[ $rhythm ] ) ) {
				$detail[] = sprintf( '%s %d', $rhythm, $rhythms[ $rhythm ] );
			}
		}

		$held = count( $all ) - count( $due );

		return sprintf(
				'%d notifications%s%s',
				count( $due ),
				$detail ? sprintf( ' (%s)', implode( ', ', $detail ) ) : '',
				$held > 0 ? sprintf( ', %d en attente d\'un lundi', $held ) : ''
		);
	}

	/**
	 * @param \App\Entity\User $recipient
	 * @param Notification[]   $notifications
	 *
	 * @return bool whether there is nothing left to retry — a refused
	 *              transport says FALSE, an address that will never accept
	 *              anything says TRUE, because tomorrow would refuse it too
	 */
	private function send ( User $recipient, array $notifications ) {
		$message = $this->twig->render( 'emails/notifications-digest.html.twig', [
				'user'   => $recipient,
				'total'  => count( $notifications ),
				'groups' => $this->groupByUsergroup( $notifications ),
		] );

		$unsubscribe = $this->router->generate(
				'user_notifications_unsubscribe',
				[ 'hash' => $this->hashGenerator->generateUserHash( $recipient ) ],
				UrlGeneratorInterface::ABSOLUTE_URL
		);

		$queued = $this->mailer->send(
				[ $this->parameters->get( 'plateform' )[ 'from' ] => $this->parameters->get( 'plateform' )[ 'name' ] ],
				$recipient->getEmail(),
				$this->mailer->getSubjectFromTitle( $message ),
				$message,
				[
						// Required of bulk senders by Gmail and Yahoo. (#14)
						'List-Unsubscribe'      => '<' . $unsubscribe . '>',
						'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
						'Precedence'            => 'bulk',
						'Auto-Submitted'        => 'auto-generated',
				]
		);

		// Le garde de #14 refuse une adresse qui ne peut rien recevoir et rend
		// zéro. Ce n'est pas une panne : demain la refuserait pareil, et on ne
		// veut pas la retenter chaque jour jusqu'à la fin des temps.
		if ( $queued < 1 ) {
			return TRUE;
		}

		$flushed = $this->spool->flush();

		// Pas de file : l'envoi a déjà eu lieu à l'appel ci-dessus, et son
		// compte a été rendu. Rien à conclure de plus.
		return ( $flushed === NULL ) || ( $flushed > 0 );
	}

	/**
	 * One heading per group, so a summary spanning several groups stays
	 * readable.
	 *
	 * @param Notification[] $notifications
	 *
	 * @return array
	 */
	private function groupByUsergroup ( array $notifications ) {
		$groups = [];

		foreach ( $notifications as $notification ) {
			$group = $notification->getUsergroup();
			$key   = $group ? $group->getId() : 0;

			if ( !isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
						'name'          => $group ? $group->getName() : '',
						'notifications' => [],
				];
			}

			$groups[ $key ][ 'notifications' ][] = $notification;
		}

		return array_values( $groups );
	}

	/**
	 * @param Notification[] $notifications
	 */
	private function markAsSent ( array $notifications ) {
		$now = new DateTime();

		foreach ( $notifications as $notification ) {
			$notification->setEmailedAt( $now );
		}
	}
}
