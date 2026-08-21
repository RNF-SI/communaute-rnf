<?php

namespace App\Command;

use App\Entity\Notification;
use App\Entity\User;
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
 * jour, dans le même e-mail. (#38, #40)
 *
 * Nothing is sent to somebody who has nothing waiting, so a quiet day sends
 * no e-mail at all.
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
						. "les deux tiennent dans le même e-mail. Personne n'est écrit à vide."
				)
				->addOption( 'dry-run', NULL, InputOption::VALUE_NONE, 'Report what would be sent without sending' )
				->addOption(
						'day',
						NULL,
						InputOption::VALUE_REQUIRED,
						'The day to send for, YYYY-MM-DD. Defaults to today; useful to check what a Monday would send.'
				);
	}

	protected function execute ( InputInterface $input, OutputInterface $output ) {
		$io     = new SymfonyStyle( $input, $output );
		$dryRun = $input->getOption( 'dry-run' );
		$day    = $input->getOption( 'day' );

		try {
			$day = $day ? new DateTime( $day ) : new DateTime();
		}
		catch ( \Exception $exception ) {
			$io->error( sprintf( 'Unreadable day: %s', $input->getOption( 'day' ) ) );

			return 1;
		}

		$repository = $this->manager->getRepository( Notification::class );
		$recipients = $repository->findRecipientsAwaitingDigest();

		$io->title( sprintf(
				'%s — %d members have notifications waiting',
				$day->format( 'l j F Y' ),
				count( $recipients )
		) );

		$sent    = 0;
		$skipped = 0;
		$waiting = 0;
		$failed  = 0;

		foreach ( $recipients as $recipient ) {
			// Le rythme hebdomadaire se joue ici : rien n'est marqué comme
			// envoyé, les notifications restent en attente jusqu'à lundi.
			// Depuis #40 le rythme se lit sur chaque notification, si bien
			// qu'un même membre peut avoir du quotidien qui part aujourd'hui
			// et de l'hebdomadaire qui attend. (#38, #40)
			$notifications = $repository->findAwaitingDigestFor( $recipient, $day );

			if ( empty( $notifications ) ) {
				$waiting++;

				continue;
			}

			// The preference may have changed since the notifications were
			// queued; the last word belongs to what the member wants now.
			if ( !$recipient->wantsEmails() ) {
				$this->markAsSent( $notifications );
				$skipped++;

				continue;
			}

			if ( $dryRun ) {
				$io->text( sprintf( '#%d: %d notifications', $recipient->getId(), count( $notifications ) ) );
				$sent++;

				continue;
			}

			try {
				$this->send( $recipient, $notifications );
				$this->markAsSent( $notifications );

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

		return 0;
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
