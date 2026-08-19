<?php

namespace App\Tests\Notification;

use App\Entity\Article;
use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\Document;
use App\Entity\Notification;
use App\Entity\Page;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Notification\NotificationRhythm;
use App\Service\NotificationSender;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #34 — who gets warned about what.
 */
class NotificationSenderTest extends KernelTestCase {
	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Service\NotificationSender
	 */
	private $sender;

	/**
	 * @var \App\Entity\Usergroup
	 */
	private $group;

	protected function setUp (): void {
		self::bootKernel();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->sender  = self::$container->get( NotificationSender::class );

		$this->manager->getConnection()->beginTransaction();

		$this->group = new Usergroup();
		$this->group->setSlug( 'notif-group-' . uniqid() );
		$this->group->setName( 'Test group' );
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
	 * @param string $status
	 *
	 * @return \App\Entity\UsergroupMembership
	 */
	private function member ( $status = UsergroupMembership::STATUS_MEMBER ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$this->manager->persist( $user );

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( $status );
		$membership->setRole( UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$this->group->addMember( $membership );

		$this->manager->flush();

		return $membership;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\Page
	 */
	private function page ( User $author ) {
		$page = new Page();
		$page->setTitle( 'Compte rendu de mars' );
		$page->setSlug( 'compte-rendu-' . uniqid() );
		$page->setUsergroup( $this->group );
		$page->setAuthor( $author );
		$page->setBody( '<p>…</p>' );
		$page->setCreatedAt( new DateTime() );
		$this->manager->persist( $page );
		$this->manager->flush();

		return $page;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\DiscussionMessage
	 */
	private function discussionMessage ( User $author ) {
		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'Une discussion' );
		$discussion->setUsergroup( $this->group );
		$discussion->setAuthor( $author );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $discussion );

		$message = new DiscussionMessage();
		$message->setDiscussion( $discussion );
		$message->setAuthor( $author );
		$message->setBody( 'Bonjour' );
		$message->setCreatedAt( new DateTime() );
		$this->manager->persist( $message );
		$this->manager->flush();

		return $message;
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return Notification[]
	 */
	private function notificationsFor ( User $user ) {
		return $this->manager->getRepository( Notification::class )
							 ->findBy( [ 'recipient' => $user ] );
	}

	public function testEveryMemberIsWarnedOfANewPage () {
		$author = $this->member();
		$reader = $this->member();

		$this->sender->notifyNewPage( $this->page( $author->getUser() ) );

		$this->assertCount( 1, $this->notificationsFor( $reader->getUser() ) );
	}

	public function testTheAuthorIsNotWarnedOfTheirOwnPage () {
		$author = $this->member();
		$this->member();

		$this->sender->notifyNewPage( $this->page( $author->getUser() ) );

		$this->assertCount(
				0,
				$this->notificationsFor( $author->getUser() ),
				'Assert nobody is told about what they just did themselves'
		);
	}

	public function testTheNotificationCarriesWhatIsNeededToReadIt () {
		$author = $this->member();
		$reader = $this->member();

		$this->sender->notifyNewPage( $this->page( $author->getUser() ) );

		$notification = $this->notificationsFor( $reader->getUser() )[ 0 ];

		$this->assertEquals( Notification::PAGE_CREATE, $notification->getType() );
		$this->assertEquals( 'Compte rendu de mars', $notification->getTitle() );
		$this->assertStringContainsString( '/pages/', $notification->getUrl() );
		$this->assertEquals( $this->group->getId(), $notification->getUsergroup()->getId() );
		$this->assertNull( $notification->getReadAt() );
	}

	public function testAMutedCategoryWarnsNobody () {
		$author = $this->member();
		$reader = $this->member();
		$reader->setNotificationLevel( NotificationCategory::PAGES, NotificationLevel::NONE );
		$this->manager->flush();

		$this->sender->notifyNewPage( $this->page( $author->getUser() ) );

		$this->assertCount( 0, $this->notificationsFor( $reader->getUser() ) );
	}

	public function testAnInAppOnlyCategoryDoesNotQueueAnEmail () {
		$author = $this->member();
		$reader = $this->member();
		$reader->setNotificationLevel( NotificationCategory::PAGES, NotificationLevel::APP );
		$this->manager->flush();

		$this->sender->notifyNewPage( $this->page( $author->getUser() ) );

		$notifications = $this->notificationsFor( $reader->getUser() );

		$this->assertCount( 1, $notifications, 'Assert it is still shown on the platform' );
		$this->assertFalse( $notifications[ 0 ]->isByEmail(), 'Assert no e-mail is queued' );
	}

	public function testAMemberWhoRefusesEmailsStillSeesNotifications () {
		$author = $this->member();
		$reader = $this->member();
		$reader->getUser()->setWantsEmails( FALSE );
		$this->manager->flush();

		$this->sender->notifyNewPage( $this->page( $author->getUser() ) );

		$notifications = $this->notificationsFor( $reader->getUser() );

		$this->assertCount( 1, $notifications );
		$this->assertFalse( $notifications[ 0 ]->isByEmail() );
	}

	public function testAPendingMemberIsNotWarned () {
		$author = $this->member();
		$reader = $this->member( UsergroupMembership::STATUS_PENDING );

		$this->sender->notifyNewPage( $this->page( $author->getUser() ) );

		$this->assertCount( 0, $this->notificationsFor( $reader->getUser() ) );
	}

	public function testADiscussionMessageOnTheImmediateRhythmIsNotQueuedForTheSummary () {
		$author = $this->member();
		$reader = $this->member();

		$this->sender->notifyNewDiscussionMessage( $this->discussionMessage( $author->getUser() ) );

		$notifications = $this->notificationsFor( $reader->getUser() );

		$this->assertCount( 1, $notifications );
		$this->assertFalse(
				$notifications[ 0 ]->isByEmail(),
				'Assert the message already left by e-mail and is not sent twice'
		);
	}

	public function testADiscussionMessageOnTheDigestRhythmIsQueuedForTheSummary () {
		$author = $this->member();
		$reader = $this->member();
		$reader->getUser()->setDiscussionEmailRhythm( NotificationRhythm::DIGEST );
		$this->manager->flush();

		$this->sender->notifyNewDiscussionMessage( $this->discussionMessage( $author->getUser() ) );

		$this->assertTrue( $this->notificationsFor( $reader->getUser() )[ 0 ]->isByEmail() );
	}

	public function testFollowingOneDiscussionInAMutedCategory () {
		$author  = $this->member();
		$reader  = $this->member();
		$message = $this->discussionMessage( $author->getUser() );

		$reader->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::NONE );
		$reader->setDiscussionOverride( $message->getDiscussion()->getUuid(), NotificationLevel::EMAIL );
		$this->manager->flush();

		$this->sender->notifyNewDiscussionMessage( $message );

		$this->assertCount(
				1,
				$this->notificationsFor( $reader->getUser() ),
				'Assert the special case of the issue works end to end'
		);
	}

	public function testMutingOneDiscussionInAFollowedCategory () {
		$author  = $this->member();
		$reader  = $this->member();
		$message = $this->discussionMessage( $author->getUser() );

		$reader->setDiscussionOverride( $message->getDiscussion()->getUuid(), NotificationLevel::NONE );
		$this->manager->flush();

		$this->sender->notifyNewDiscussionMessage( $message );

		$this->assertCount( 0, $this->notificationsFor( $reader->getUser() ) );
	}
}
