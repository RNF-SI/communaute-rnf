<?php

namespace App\Tests\Notification;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Service\NotificationSender;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Issue #37 — être nommé dans un message. Une mention s'adresse à quelqu'un
 * en particulier : elle le rejoint même s'il a mis la discussion en sourdine,
 * et elle remplace l'avertissement ordinaire plutôt que de s'y ajouter.
 */
class DiscussionMentionTest extends KernelTestCase {
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
		$this->group->setSlug( 'mention-group-' . uniqid() );
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
	 * @param string $name
	 * @param bool   $isMember
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $name, $isMember = TRUE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( $name );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$this->manager->persist( $user );

		if ( $isMember ) {
			$membership = new UsergroupMembership();
			$membership->setUser( $user );
			$membership->setUsergroup( $this->group );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
			$membership->setRole( UsergroupMembership::ROLE_USER );
			$membership->setJoinedAt( new DateTime() );
			$this->manager->persist( $membership );
			$this->group->addMember( $membership );
		}

		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return \App\Entity\UsergroupMembership|null
	 */
	private function membershipOf ( User $user ) {
		foreach ( $this->group->getMembers() as $membership ) {
			if ( $membership->getUser() === $user ) {
				return $membership;
			}
		}

		return NULL;
	}

	/**
	 * @param \App\Entity\User $author
	 * @param string           $body
	 * @param callable|null    $before run once the discussion exists, before sending
	 *
	 * @return \App\Entity\DiscussionMessage
	 */
	private function post ( User $author, $body, callable $before = NULL ) {
		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'Rencontre annuelle' );
		$discussion->setUsergroup( $this->group );
		$discussion->setAuthor( $author );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $discussion );

		$message = new DiscussionMessage();
		$message->setDiscussion( $discussion );
		$message->setAuthor( $author );
		$message->setBody( $body );
		$message->setCreatedAt( new DateTime() );
		$this->manager->persist( $message );
		$discussion->addMessage( $message );

		if ( $before ) {
			$before( $discussion );
		}

		$this->manager->flush();

		$this->sender->notifyNewDiscussionMessage( $message );

		return $message;
	}

	/**
	 * @param \App\Entity\User $recipient
	 * @param string           $type
	 *
	 * @return \App\Entity\Notification[]
	 */
	private function notificationsOf ( User $recipient, $type = NULL ) {
		$criteria = [ 'recipient' => $recipient ];

		if ( $type !== NULL ) {
			$criteria[ 'type' ] = $type;
		}

		return $this->manager->getRepository( Notification::class )->findBy( $criteria );
	}

	public function testANamedMemberIsWarnedTheyWereNamed () {
		$author = $this->user( 'Paul Marais' );
		$jeanne = $this->user( 'Jeanne Reserve' );

		$this->post( $author, '<p>Bonjour @Jeanne Reserve, peux-tu regarder ?</p>' );

		$this->assertCount( 1, $this->notificationsOf( $jeanne, Notification::DISCUSSION_MENTION ) );
	}

	public function testBeingNamedReplacesThePlainWarning () {
		$author = $this->user( 'Paul Marais' );
		$jeanne = $this->user( 'Jeanne Reserve' );

		$this->post( $author, '<p>@Jeanne Reserve ?</p>' );

		$this->assertCount(
				0,
				$this->notificationsOf( $jeanne, Notification::DISCUSSION_MESSAGE ),
				'Assert one message is worth one notification'
		);
		$this->assertCount( 1, $this->notificationsOf( $jeanne ) );
	}

	public function testTheOtherMembersGetThePlainWarning () {
		$author = $this->user( 'Paul Marais' );
		$this->user( 'Jeanne Reserve' );
		$other = $this->user( 'Simon Foret' );

		$this->post( $author, '<p>@Jeanne Reserve ?</p>' );

		$this->assertCount( 1, $this->notificationsOf( $other, Notification::DISCUSSION_MESSAGE ) );
		$this->assertCount( 0, $this->notificationsOf( $other, Notification::DISCUSSION_MENTION ) );
	}

	public function testAMutedDiscussionStillLetsAMentionThrough () {
		$author = $this->user( 'Paul Marais' );
		$jeanne = $this->user( 'Jeanne Reserve' );

		$this->post( $author, '<p>@Jeanne Reserve, une dernière chose</p>', function ( Discussion $discussion ) use ( $jeanne ) {
			$this->membershipOf( $jeanne )
				 ->setDiscussionOverride( $discussion->getUuid(), NotificationLevel::NONE );
		} );

		$this->assertCount(
				1,
				$this->notificationsOf( $jeanne, Notification::DISCUSSION_MENTION ),
				'Assert what was muted is the group talking, not somebody calling you'
		);
	}

	public function testAMutedMemberReadsTheMentionInTheSummary () {
		$author = $this->user( 'Paul Marais' );
		$jeanne = $this->user( 'Jeanne Reserve' );

		$this->membershipOf( $jeanne )
			 ->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::APP );

		$this->post( $author, '<p>@Jeanne Reserve ?</p>' );

		$notifications = $this->notificationsOf( $jeanne, Notification::DISCUSSION_MENTION );

		$this->assertCount( 1, $notifications );
		$this->assertTrue(
				$notifications[ 0 ]->isByEmail(),
				'Assert the summary is the only way a mention reaches somebody who gets no message e-mail'
		);
	}

	public function testAMemberAlreadyGettingTheMessageIsNotToldTwice () {
		$author = $this->user( 'Paul Marais' );
		$jeanne = $this->user( 'Jeanne Reserve' );

		$this->membershipOf( $jeanne )
			 ->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::IMMEDIATE );
		$this->manager->flush();

		$this->post( $author, '<p>@Jeanne Reserve ?</p>' );

		$notifications = $this->notificationsOf( $jeanne, Notification::DISCUSSION_MENTION );

		$this->assertCount( 1, $notifications );
		$this->assertFalse(
				$notifications[ 0 ]->isByEmail(),
				'Assert the message is already on its way, the summary adds nothing'
		);
	}

	public function testNamingYourselfWarnsNobody () {
		$author = $this->user( 'Paul Marais' );

		$this->post( $author, '<p>note pour @Paul Marais</p>' );

		$this->assertCount( 0, $this->notificationsOf( $author ) );
	}

	public function testSomebodyOutsideTheGroupIsNotWarned () {
		$author  = $this->user( 'Paul Marais' );
		$outside = $this->user( 'Jeanne Reserve', FALSE );

		$this->post( $author, '<p>@Jeanne Reserve ?</p>' );

		$this->assertCount(
				0,
				$this->notificationsOf( $outside ),
				'Assert a mention does not reach outside the group'
		);
	}

	public function testTheNotificationPointsAtTheDiscussion () {
		$author = $this->user( 'Paul Marais' );
		$jeanne = $this->user( 'Jeanne Reserve' );

		$message = $this->post( $author, '<p>@Jeanne Reserve ?</p>' );

		$notification = $this->notificationsOf( $jeanne, Notification::DISCUSSION_MENTION )[ 0 ];

		$this->assertEquals( 'Rencontre annuelle', $notification->getTitle() );
		$this->assertStringContainsString(
				(string) $message->getDiscussion()->getUuid(),
				$notification->getUrl()
		);
	}
}
