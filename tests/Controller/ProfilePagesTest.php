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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Pages of the directory and of the user account, seen by a logged-in member.
 */
class ProfilePagesTest extends WebTestCase {
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
		$this->client  = static::createClient();
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
		$user->setRoles( [ 'ROLE_USER' ] );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * Symfony 4.4 has no KernelBrowser::loginUser(), the session token has to
	 * be planted by hand.
	 *
	 * @param \App\Entity\User $user
	 */
	private function logIn ( User $user ) {
		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	public function testDirectoryProfileShowsTheEmailInClear () {
		$visitor = $this->user( 'visitor@example.org' );
		$member  = $this->user( 'contact-me@example.org' );

		$this->logIn( $visitor );

		$crawler = $this->client->request( 'GET', '/members/' . $member->getId() );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$this->assertStringContainsString(
				'contact-me@example.org',
				$crawler->filter( '.user-contact' )->text(),
				'Assert the e-mail address is readable, not only hidden behind a mailto link'
		);
	}

	public function testDirectoryProfileOffersToCopyTheEmail () {
		$visitor = $this->user( 'visitor@example.org' );
		$member  = $this->user( 'contact-me@example.org' );

		$this->logIn( $visitor );

		$crawler = $this->client->request( 'GET', '/members/' . $member->getId() );

		$this->assertEquals(
				'contact-me@example.org',
				$crawler->filter( '[data-copy-value]' )->attr( 'data-copy-value' ),
				'Assert the copy button carries the address'
		);
	}

	public function testMailtoLinkNoLongerOpensABlankTab () {
		$visitor = $this->user( 'visitor@example.org' );
		$member  = $this->user( 'contact-me@example.org' );

		$this->logIn( $visitor );

		$crawler = $this->client->request( 'GET', '/members/' . $member->getId() );

		$this->assertEquals(
				0,
				$crawler->filter( '.user-contact a[target="_blank"]' )->count(),
				'Assert the mailto link is not opened in a new tab'
		);
	}

	public function testOwnProfileShowsTheEditButtonInsteadOfContactDetails () {
		$user = $this->user( 'me@example.org' );

		$this->logIn( $user );

		$crawler = $this->client->request( 'GET', '/members/' . $user->getId() );

		$this->assertEquals(
				0,
				$crawler->filter( '[data-copy-value]' )->count(),
				'Assert a user is not offered to copy their own address'
		);
	}

	public function testMyDiscussionsPageListsTheDiscussionsOfEveryGroup () {
		$user = $this->user( 'me@example.org' );

		$group = new Usergroup();
		$group->setSlug( 'test-group' );
		$group->setName( 'Test group' );
		$group->setVisibility( Usergroup::PUBLIC );
		$group->setCreatedAt( new DateTime() );
		$group->setIsActive( TRUE );
		$this->manager->persist( $group );

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );

		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'A discussion of mine' );
		$discussion->setUsergroup( $group );
		$discussion->setAuthor( $user );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $discussion );

		$message = new DiscussionMessage();
		$message->setDiscussion( $discussion );
		$message->setAuthor( $user );
		$message->setBody( 'Hello' );
		$message->setCreatedAt( new DateTime() );
		$this->manager->persist( $message );

		$this->manager->flush();

		$this->logIn( $user );

		$crawler = $this->client->request( 'GET', '/user/discussions' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertStringContainsString(
				'A discussion of mine',
				$crawler->filter( '.discussions-list' )->text(),
				'Assert the discussion is listed'
		);
		$this->assertStringContainsString(
				'Test group',
				$crawler->filter( '.discussions-list' )->text(),
				'Assert the group each discussion belongs to is shown'
		);
	}

	public function testMyDiscussionsPageIsRestrictedToLoggedUsers () {
		$this->client->request( 'GET', '/user/discussions' );

		$this->assertEquals(
				302,
				$this->client->getResponse()->getStatusCode(),
				'Assert the page is not readable anonymously'
		);
	}
}
