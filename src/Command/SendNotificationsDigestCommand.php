<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationRhythm;
use App\Service\EmailSender;
use App\Service\HashGenerator;
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

	public function __construct (
			EntityManagerInterface $manager,
			EmailSender $mailer,
			Environment $twig,
			ParameterBagInterface $parameters,
			HashGenerator $hashGenerator,
			UrlGeneratorInterface $router
	) {
		$this->manager       = $manager;
		$this->mailer        = $mailer;
		$this->twig          = $twig;
		$this->parameters    = $parameters;
		$this->hashGenerator = $hashGenerator;
		$this->router        = $router;

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
			$recipients = $this->restrictTo( $recipients, $only );

			if ( $recipients === NULL ) {
				$io->error( sprintf(
						'Rien n\'attend d\'e-mail pour « %s » : compte inconnu, inactif, ou aucune notification en attente.',
						$only
				) );

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
				$this->send( $recipient, $notifications );

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
	 * @param string             $email
	 *
	 * @return \App\Entity\User[]|null
	 */
	private function restrictTo ( array $recipients, $email ) {
		foreach ( $recipients as $recipient ) {
			if ( mb_strtolower( (string) $recipient->getEmail() ) === mb_strtolower( trim( $email ) ) ) {
				return [ $recipient ];
			}
		}

		return NULL;
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

		$this->mailer->send(
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
