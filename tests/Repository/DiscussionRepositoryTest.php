<?php

namespace App\Tests\Repository;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Mes discussions" (issue #21): the discussions a user takes part in, across
 * every group they belong to.
 */
class DiscussionRepositoryTest extends KernelTestCase {
	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	protected function setUp (): void {
		self::bootKernel();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string $email
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $email ) {
		$user = new User();
		$user->setEmail( $email );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );

		$this->manager->persist( $user );

		return $user;
	}

	/**
	 * @param string $slug
	 * @param bool   $isActive
	 *
	 * @return \App\Entity\Usergroup
	 */
	private function group ( $slug, $isActive = TRUE ) {
		$group = new Usergroup();
		$group->setSlug( $slug );
		$group->setName( 'Group ' . $slug );
		$group->setVisibility( Usergroup::PUBLIC );
		$group->setCreatedAt( new DateTime() );
		$group->setIsActive( $isActive );

		$this->manager->persist( $group );

		return $group;
	}

	/**
	 * @param \App\Entity\User      $user
	 * @param \App\Entity\Usergroup $group
	 * @param string                $status
	 */
	private function join ( User $user, Usergroup $group, $status = UsergroupMembership::STATUS_MEMBER ) {
		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $group );
		$membership->setStatus( $status );
		$membership->setRole( UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );

		$this->manager->persist( $membership );
	}

	/**
	 * @param \App\Entity\Usergroup $group
	 * @param \App\Entity\User      $author
	 * @param string                $title
	 *
	 * @return \App\Entity\Discussion
	 */
	private function discussion ( Usergroup $group, User $author, $title ) {
		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( $title );
		$discussion->setUsergroup( $group );
		$discussion->setAuthor( $author );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );

		$this->manager->persist( $discussion );

		return $discussion;
	}

	/**
	 * @param \App\Entity\Discussion $discussion
	 * @param \App\Entity\User       $author
	 */
	private function message ( Discussion $discussion, User $author ) {
		$message = new DiscussionMessage();
		$message->setDiscussion( $discussion );
		$message->setAuthor( $author );
		$message->setBody( 'Hello' );
		$message->setCreatedAt( new DateTime() );

		$this->manager->persist( $message );
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return string[] discussion titles
	 */
	private function titlesFor ( User $user ) {
		$this->manager->flush();

		$discussions = $this->manager->getRepository( Discussion::class )
									 ->findByParticipant( $user );

		return array_map( function ( Discussion $discussion ) {
			return $discussion->getTitle();
		}, $discussions );
	}

	public function testDiscussionsTheUserOpened () {
		$user  = $this->user( 'member@example.org' );
		$group = $this->group( 'group-a' );
		$this->join( $user, $group );

		$this->discussion( $group, $user, 'Opened by the user' );

		$this->assertEquals( [ 'Opened by the user' ], $this->titlesFor( $user ) );
	}

	public function testDiscussionsTheUserRepliedTo () {
		$user   = $this->user( 'member@example.org' );
		$author = $this->user( 'author@example.org' );
		$group  = $this->group( 'group-a' );
		$this->join( $user, $group );
		$this->join( $author, $group );

		$discussion = $this->discussion( $group, $author, 'Opened by someone else' );
		$this->message( $discussion, $user );

		$this->assertEquals( [ 'Opened by someone else' ], $this->titlesFor( $user ) );
	}

	public function testDiscussionsTheUserNeverTouchedAreExcluded () {
		$user   = $this->user( 'member@example.org' );
		$author = $this->user( 'author@example.org' );
		$group  = $this->group( 'group-a' );
		$this->join( $user, $group );
		$this->join( $author, $group );

		$discussion = $this->discussion( $group, $author, 'Someone else conversation' );
		$this->message( $discussion, $author );

		$this->assertEquals( [], $this->titlesFor( $user ) );
	}

	public function testDiscussionsOfGroupsTheUserLeftAreExcluded () {
		$user  = $this->user( 'member@example.org' );
		$group = $this->group( 'group-a' );

		// The user opened the discussion but is no longer a member: listing it
		// would send them to a page they cannot open.
		$this->discussion( $group, $user, 'Group the user left' );

		$this->assertEquals( [], $this->titlesFor( $user ) );
	}

	public function testDiscussionsOfInactiveGroupsAreExcluded () {
		$user  = $this->user( 'member@example.org' );
		$group = $this->group( 'group-a', FALSE );
		$this->join( $user, $group );

		$this->discussion( $group, $user, 'Inactive group' );

		$this->assertEquals( [], $this->titlesFor( $user ) );
	}

	public function testPendingMembershipDoesNotGrantAccess () {
		$user  = $this->user( 'member@example.org' );
		$group = $this->group( 'group-a' );
		$this->join( $user, $group, UsergroupMembership::STATUS_PENDING );

		$this->discussion( $group, $user, 'Awaiting approval' );

		$this->assertEquals( [], $this->titlesFor( $user ) );
	}

	public function testADiscussionIsListedOnlyOnceWhateverTheNumberOfMessages () {
		$user  = $this->user( 'member@example.org' );
		$group = $this->group( 'group-a' );
		$this->join( $user, $group );

		$discussion = $this->discussion( $group, $user, 'Chatty discussion' );
		$this->message( $discussion, $user );
		$this->message( $discussion, $user );
		$this->message( $discussion, $user );

		$this->assertEquals(
				[ 'Chatty discussion' ],
				$this->titlesFor( $user ),
				'Assert the join on messages does not duplicate the discussion'
		);
	}

	public function testDiscussionsSpanEveryGroupAndComeBackMostRecentFirst () {
		$user   = $this->user( 'member@example.org' );
		$first  = $this->group( 'group-a' );
		$second = $this->group( 'group-b' );
		$this->join( $user, $first );
		$this->join( $user, $second );

		$older = $this->discussion( $first, $user, 'Older' );
		$older->setActiveAt( new DateTime( '-10 days' ) );

		$newer = $this->discussion( $second, $user, 'Newer' );
		$newer->setActiveAt( new DateTime( '-1 hour' ) );

		$this->assertEquals(
				[ 'Newer', 'Older' ],
				$this->titlesFor( $user ),
				'Assert discussions of every group are merged, most recently active first'
		);
	}
}
