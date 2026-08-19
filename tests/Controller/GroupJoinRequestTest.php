<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Service\EmailSender;
use DateTime;
use RuntimeException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #4 — asking to join a private group must warn the people who can
 * approve it, otherwise the request sits unseen.
 */
class GroupJoinRequestTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();

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
	 * @param string $visibility
	 *
	 * @return \App\Entity\Usergroup
	 */
	private function group ( $visibility ) {
		$group = new Usergroup();
		$group->setSlug( 'test-group' );
		$group->setName( 'Test group' );
		$group->setVisibility( $visibility );
		$group->setCreatedAt( new DateTime() );
		$group->setIsActive( TRUE );

		$this->manager->persist( $group );

		return $group;
	}

	/**
	 * @param string $email
	 * @param bool   $isSiteAdmin
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $email, $isSiteAdmin = FALSE ) {
		$user = new User();

		// Unique address: the fixtures populate the same database, and the
		// column is unique.
		$user->setEmail( uniqid() . '-' . $email );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( $isSiteAdmin ? [ 'ROLE_USER', 'ROLE_ADMIN' ] : [ 'ROLE_USER' ] );

		$this->manager->persist( $user );

		return $user;
	}

	/**
	 * @param \App\Entity\User      $user
	 * @param \App\Entity\Usergroup $group
	 * @param string                $role
	 */
	private function join ( User $user, Usergroup $group, $role ) {
		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( $role );
		$membership->setJoinedAt( new DateTime() );

		$this->manager->persist( $membership );
		$group->addMember( $membership );
	}

	/**
	 * @param \App\Entity\User $user
	 */
	private function logIn ( User $user ) {
		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * @return string[] the addresses the request sent an e-mail to
	 */
	private function askToJoin () {
		$this->client->enableProfiler();
		$this->client->request( 'GET', '/groups/test-group/members/new' );

		$collector = $this->client->getProfile()->getCollector( 'swiftmailer' );

		$recipients = [];

		foreach ( $collector->getMessages() as $message ) {
			$recipients = array_merge( $recipients, array_keys( $message->getTo() ) );
		}

		sort( $recipients );

		return $recipients;
	}

	public function testAdminsOfAPrivateGroupAreWarned () {
		$group = $this->group( Usergroup::PRIVATE );
		$admin = $this->user( 'admin@example.org' );
		$this->join( $admin, $group, UsergroupMembership::ROLE_ADMIN );

		$candidate = $this->user( 'candidate@example.org' );
		$this->manager->flush();

		$this->logIn( $candidate );

		$this->assertEquals(
				[ $admin->getEmail() ],
				$this->askToJoin(),
				'Assert the group administrator is warned of the request'
		);
	}

	public function testEveryAdminIsWarned () {
		$group  = $this->group( Usergroup::PRIVATE );
		$first  = $this->user( 'admin1@example.org' );
		$second = $this->user( 'admin2@example.org' );
		$this->join( $first, $group, UsergroupMembership::ROLE_ADMIN );
		$this->join( $second, $group, UsergroupMembership::ROLE_ADMIN );

		$candidate = $this->user( 'candidate@example.org' );
		$this->manager->flush();

		$this->logIn( $candidate );

		$expected = [ $first->getEmail(), $second->getEmail() ];
		sort( $expected );

		$this->assertEquals( $expected, $this->askToJoin() );
	}

	public function testPlainMembersAreNotWarned () {
		$group = $this->group( Usergroup::PRIVATE );
		$admin = $this->user( 'admin@example.org' );
		$this->join( $admin, $group, UsergroupMembership::ROLE_ADMIN );
		$this->join( $this->user( 'member@example.org' ), $group, UsergroupMembership::ROLE_USER );

		$candidate = $this->user( 'candidate@example.org' );
		$this->manager->flush();

		$this->logIn( $candidate );

		$this->assertEquals( [ $admin->getEmail() ], $this->askToJoin() );
	}

	public function testTheRequestIsRecordedAsPending () {
		$group = $this->group( Usergroup::PRIVATE );
		$this->join( $this->user( 'admin@example.org' ), $group, UsergroupMembership::ROLE_ADMIN );

		$candidate = $this->user( 'candidate@example.org' );
		$this->manager->flush();

		$this->logIn( $candidate );
		$this->askToJoin();

		$membership = $this->manager->getRepository( UsergroupMembership::class )
									->getMembership( $candidate, $group );

		$this->assertEquals( UsergroupMembership::STATUS_PENDING, $membership->getStatus() );
	}

	public function testAFailingMailServiceDoesNotLoseTheRequest () {
		$group = $this->group( Usergroup::PRIVATE );
		$this->join( $this->user( 'admin@example.org' ), $group, UsergroupMembership::ROLE_ADMIN );

		$candidate = $this->user( 'candidate@example.org' );
		$this->manager->flush();

		$broken = $this->createMock( EmailSender::class );
		$broken->method( 'getSubjectFromTitle' )->willReturn( 'Subject' );
		$broken->method( 'send' )
			   ->willThrowException( new RuntimeException( 'Address in mailbox given [] does not comply with RFC 2822' ) );

		self::$container->set( EmailSender::class, $broken );

		$this->logIn( $candidate );
		$this->client->request( 'GET', '/groups/test-group/members/new' );

		$this->assertEquals(
				302,
				$this->client->getResponse()->getStatusCode(),
				'Assert a mail failure does not turn into an error page'
		);

		$this->manager->clear();
		$membership = $this->manager->getRepository( UsergroupMembership::class )
									->getMembership( $candidate, $group );

		$this->assertNotNull(
				$membership,
				'Assert the request is recorded even though no warning could be sent'
		);
		$this->assertEquals( UsergroupMembership::STATUS_PENDING, $membership->getStatus() );
	}

	public function testJoiningAPublicGroupWarnsNobody () {
		$group = $this->group( Usergroup::PUBLIC );
		$this->join( $this->user( 'admin@example.org' ), $group, UsergroupMembership::ROLE_ADMIN );

		$candidate = $this->user( 'candidate@example.org' );
		$this->manager->flush();

		$this->logIn( $candidate );

		$this->assertEquals(
				[],
				$this->askToJoin(),
				'Assert joining an open group does not need an approval, so warns nobody'
		);
	}

	public function testAPrivateGroupWithoutAdminFallsBackOnSiteAdmins () {
		$group = $this->group( Usergroup::PRIVATE );
		$this->join( $this->user( 'member@example.org' ), $group, UsergroupMembership::ROLE_USER );

		$siteAdmin = $this->user( 'siteadmin@example.org', TRUE );

		$candidate = $this->user( 'candidate@example.org' );
		$this->manager->flush();

		$this->logIn( $candidate );

		// Other site administrators may exist in the database; what matters is
		// that the request reaches them rather than nobody.
		$this->assertContains(
				$siteAdmin->getEmail(),
				$this->askToJoin(),
				'Assert a request to a group nobody administers is not lost'
		);
	}
}
