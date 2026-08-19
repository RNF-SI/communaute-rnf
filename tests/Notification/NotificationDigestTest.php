<?php

namespace App\Tests\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Usergroup;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Issue #34 — one e-mail a day per member, and nothing at all on a quiet day.
 */
class NotificationDigestTest extends KernelTestCase {
	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \Symfony\Component\Console\Tester\CommandTester
	 */
	private $command;

	/**
	 * @var \App\Entity\Usergroup
	 */
	private $group;

	protected function setUp (): void {
		self::bootKernel();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->command = new CommandTester(
				( new Application( self::$kernel ) )->find( 'app:notifications:digest' )
		);

		$this->group = new Usergroup();
		$this->group->setSlug( 'digest-group-' . uniqid() );
		$this->group->setName( 'Commission montagne' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @return \App\Entity\User
	 */
	private function user () {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setDisplayName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $recipient
	 * @param bool             $byEmail
	 *
	 * @return \App\Entity\Notification
	 */
	private function notification ( User $recipient, $byEmail = TRUE ) {
		$notification = new Notification();
		$notification->setRecipient( $recipient );
		$notification->setUsergroup( $this->group );
		$notification->setType( Notification::PAGE_CREATE );
		$notification->setTitle( 'Compte rendu de mars' );
		$notification->setUrl( '/groups/g/pages/compte-rendu' );
		$notification->setCreatedAt( new DateTime() );
		$notification->setByEmail( $byEmail );

		$this->manager->persist( $notification );
		$this->manager->flush();

		return $notification;
	}

	private function digest ( array $options = [] ) {
		$this->command->execute( $options );
	}

	public function testAMemberWithNothingWaitingGetsNothing () {
		$this->user();

		$this->digest( [ '--dry-run' => TRUE ] );

		$this->assertStringContainsString( '0 summaries would be sent', $this->command->getDisplay() );
	}

	public function testOneSummaryPerMemberWhateverTheNumberOfNotifications () {
		$user = $this->user();
		$this->notification( $user );
		$this->notification( $user );
		$this->notification( $user );

		$this->digest( [ '--dry-run' => TRUE ] );

		$this->assertStringContainsString(
				'1 summaries would be sent',
				$this->command->getDisplay(),
				'Assert three notifications make one e-mail, not three'
		);
	}

	public function testASummaryIsNotSentTwice () {
		$user = $this->user();
		$this->notification( $user );

		$this->digest();
		$this->manager->clear();
		$this->digest();

		$this->assertStringContainsString(
				'0 summaries sent',
				$this->command->getDisplay(),
				'Assert notifications already summarised are not sent again'
		);
	}

	public function testNotificationsAreMarkedOnceSummarised () {
		$user         = $this->user();
		$notification = $this->notification( $user );
		$id           = $notification->getId();

		$this->digest();
		$this->manager->clear();

		$this->assertNotNull(
				$this->manager->getRepository( Notification::class )->find( $id )->getEmailedAt(),
				'Assert the notification records that it left'
		);
	}

	public function testInAppOnlyNotificationsAreNeverSummarised () {
		$user = $this->user();
		$this->notification( $user, FALSE );

		$this->digest( [ '--dry-run' => TRUE ] );

		$this->assertStringContainsString( '0 summaries would be sent', $this->command->getDisplay() );
	}

	public function testAMemberWhoStoppedWantingEmailsIsNotWrittenTo () {
		$user = $this->user();
		$this->notification( $user );

		// The preference changed after the notification was queued.
		$user->setWantsEmails( FALSE );
		$this->manager->flush();

		$this->digest();

		$this->assertStringContainsString(
				'1 dropped for members who refuse e-mails',
				$this->command->getDisplay(),
				'Assert the last word belongs to what the member wants now'
		);
	}

	public function testADroppedSummaryDoesNotComeBackTheNextDay () {
		$user = $this->user();
		$this->notification( $user );
		$user->setWantsEmails( FALSE );
		$this->manager->flush();

		$this->digest();
		$this->manager->clear();
		$this->digest();

		$this->assertStringContainsString( '0 dropped', $this->command->getDisplay() );
	}

	/**
	 * The summary is sent by a scheduled command, with no HTTP request to take
	 * a host from. Without a configured request context the router falls back
	 * on "localhost" and every link of the e-mail — including the unsubscribe
	 * header — points nowhere.
	 */
	public function testLinksBuiltOutsideARequestCarryTheRealHost () {
		$url = self::$container->get( 'router' )->generate(
				'user_parameters_edit',
				[],
				UrlGeneratorInterface::ABSOLUTE_URL
		);

		$this->assertStringStartsWith( 'http', $url );
		$this->assertStringNotContainsString(
				'localhost',
				$url,
				'Assert a scheduled command does not build links towards localhost'
		);
	}

	public function testDryRunSendsNothingAndMarksNothing () {
		$user         = $this->user();
		$notification = $this->notification( $user );
		$id           = $notification->getId();

		$this->digest( [ '--dry-run' => TRUE ] );
		$this->manager->clear();

		$this->assertNull(
				$this->manager->getRepository( Notification::class )->find( $id )->getEmailedAt()
		);
	}
}
