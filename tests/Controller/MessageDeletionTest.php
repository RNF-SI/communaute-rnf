<?php

namespace App\Tests\Controller;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #3 — deleting a message in a discussion answered with
 * « Key "0" does not exist as the array is empty. »
 */
class MessageDeletionTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Entity\Usergroup
	 */
	private $group;

	/**
	 * @var \App\Entity\Discussion
	 */
	private $discussion;

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->group = new Usergroup();
		$this->group->setSlug( 'test-group' );
		$this->group->setName( 'Test group' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );

		$this->discussion = new Discussion();
		$this->discussion->setUuid( Uuid::uuid4() );
		$this->discussion->setTitle( 'A discussion' );
		$this->discussion->setUsergroup( $this->group );
		$this->discussion->setCreatedAt( new DateTime() );
		$this->discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $this->discussion );
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param bool $isAdmin
	 *
	 * @return \App\Entity\User
	 */
	private function member ( $isAdmin = FALSE ) {
		$user = new User();
		$user->setEmail( ( $isAdmin ? 'admin' : 'member' ) . uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( $isAdmin ? [ 'ROLE_USER', 'ROLE_ADMIN' ] : [ 'ROLE_USER' ] );
		$this->manager->persist( $user );

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( $isAdmin ? UsergroupMembership::ROLE_ADMIN : UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );

		return $user;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\DiscussionMessage
	 */
	private function message ( User $author ) {
		if ( !$this->discussion->getAuthor() ) {
			$this->discussion->setAuthor( $author );
		}

		$message = new DiscussionMessage();
		$message->setDiscussion( $this->discussion );
		$message->setAuthor( $author );
		$message->setBody( 'Hello' );
		$message->setCreatedAt( new DateTime() );
		$this->manager->persist( $message );

		return $message;
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
	 * @param \App\Entity\DiscussionMessage $message
	 */
	private function delete ( DiscussionMessage $message ) {
		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/message/' . $message->getId() . '/delete'
		);

		$this->assertEquals(
				200,
				$this->client->getResponse()->getStatusCode(),
				'Assert the confirmation page opens'
		);

		$this->client->submit( $crawler->selectButton( 'form[submit]' )->form() );
	}

	public function testDeletingTheOnlyMessageOfADiscussion () {
		$admin   = $this->member( TRUE );
		$message = $this->message( $admin );
		$this->manager->flush();

		$this->logIn( $admin );
		$this->delete( $message );

		$this->client->followRedirect();

		$this->assertEquals(
				200,
				$this->client->getResponse()->getStatusCode(),
				'Assert the discussion page still renders once its last message is gone'
		);
	}

	public function testDeletingOneMessageAmongSeveral () {
		$admin = $this->member( TRUE );
		$first = $this->message( $admin );
		$this->message( $admin );
		$this->manager->flush();

		$this->logIn( $admin );
		$this->delete( $first );

		$this->client->followRedirect();

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
	}

	public function testGroupListStillRendersAfterTheLastMessageIsDeleted () {
		$admin   = $this->member( TRUE );
		$message = $this->message( $admin );
		$this->manager->flush();

		$this->logIn( $admin );
		$this->delete( $message );

		$this->client->request( 'GET', '/user/groups' );

		$this->assertEquals(
				200,
				$this->client->getResponse()->getStatusCode(),
				'Assert the group listing renders for a group without any activity to show'
		);
	}

	public function testDiscussionListStillRendersAfterTheLastMessageIsDeleted () {
		$admin   = $this->member( TRUE );
		$message = $this->message( $admin );
		$this->manager->flush();

		$this->logIn( $admin );
		$this->delete( $message );

		$this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/discussions' );

		$this->assertEquals(
				200,
				$this->client->getResponse()->getStatusCode(),
				'Assert the discussion listing renders a discussion that has no message left'
		);
	}
}
