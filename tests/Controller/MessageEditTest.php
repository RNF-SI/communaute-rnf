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
 * Issue #19 — the author of a message may correct it, and the correction is
 * shown rather than silent.
 */
class MessageEditTest extends WebTestCase {
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
		$this->group->setSlug( 'edit-test-group' );
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
	 * @param string $role
	 * @param bool   $isMember
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $role = UsergroupMembership::ROLE_USER, $isMember = TRUE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $user );

		if ( $isMember ) {
			$membership = new UsergroupMembership();
			$membership->setUser( $user );
			$membership->setUsergroup( $this->group );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
			$membership->setRole( $role );
			$membership->setJoinedAt( new DateTime() );
			$this->manager->persist( $membership );
			$this->group->addMember( $membership );
		}

		// Flushed straight away: the security token serialises the account, it
		// needs its identifier.
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\DiscussionMessage
	 */
	private function message ( User $author ) {
		if ( !$this->discussion ) {
			$this->discussion = new Discussion();
			$this->discussion->setUuid( Uuid::uuid4() );
			$this->discussion->setTitle( 'A discussion' );
			$this->discussion->setUsergroup( $this->group );
			$this->discussion->setAuthor( $author );
			$this->discussion->setCreatedAt( new DateTime() );
			$this->discussion->setActiveAt( new DateTime() );
			$this->manager->persist( $this->discussion );
		}

		$message = new DiscussionMessage();
		$message->setDiscussion( $this->discussion );
		$message->setAuthor( $author );
		$message->setBody( '<p>Bonjur tout le monde</p>' );
		$message->setCreatedAt( new DateTime() );
		$this->manager->persist( $message );

		// Both sides of the relation: the request runs on the same entity
		// manager, and the discussion would otherwise still look empty.
		$this->discussion->addMessage( $message );

		$this->manager->flush();

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
	 *
	 * @return string
	 */
	private function editUrl ( DiscussionMessage $message ) {
		return '/groups/' . $this->group->getSlug() . '/message/' . $message->getId() . '/edit';
	}

	/**
	 * @param \App\Entity\DiscussionMessage $message
	 * @param string                        $body
	 */
	private function edit ( DiscussionMessage $message, $body ) {
		$crawler = $this->client->request( 'GET', $this->editUrl( $message ) );

		$form = $crawler->filter( 'form[name="discussion_message"]' )->form();
		$form[ 'discussion_message[body]' ] = $body;

		$this->client->submit( $form );
	}

	/**
	 * @param int $id
	 *
	 * @return \App\Entity\DiscussionMessage
	 */
	private function reload ( $id ) {
		$this->manager->clear();

		return $this->manager->getRepository( DiscussionMessage::class )->find( $id );
	}

	public function testTheAuthorCanCorrectTheirMessage () {
		$author  = $this->user();
		$message = $this->message( $author );
		$id      = $message->getId();

		$this->logIn( $author );
		$this->edit( $message, '<p>Bonjour tout le monde</p>' );

		$this->assertEquals( '<p>Bonjour tout le monde</p>', $this->reload( $id )->getBody() );
	}

	public function testTheCorrectionIsDated () {
		$author  = $this->user();
		$message = $this->message( $author );
		$id      = $message->getId();

		$this->assertNull( $message->getEditedAt(), 'Assert a fresh message is not marked as edited' );

		$this->logIn( $author );
		$this->edit( $message, '<p>Bonjour tout le monde</p>' );

		$this->assertNotNull(
				$this->reload( $id )->getEditedAt(),
				'Assert a correction is dated, so it can be shown'
		);
	}

	public function testTheCorrectionIsVisibleInTheDiscussion () {
		$author  = $this->user();
		$message = $this->message( $author );

		$this->logIn( $author );
		$this->edit( $message, '<p>Bonjour tout le monde</p>' );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/' . $this->discussion->getUuid()
		);

		$this->assertEquals(
				1,
				$crawler->filter( '.message--edited' )->count(),
				'Assert the reader is told the message was corrected'
		);
	}

	public function testAnotherMemberCannotEditTheMessage () {
		$author  = $this->user();
		$message = $this->message( $author );

		$this->logIn( $this->user() );
		$this->client->request( 'GET', $this->editUrl( $message ) );

		$this->assertEquals(
				403,
				$this->client->getResponse()->getStatusCode(),
				'Assert a member cannot rewrite somebody else message'
		);
	}

	public function testAGroupAdministratorCanEditTheMessage () {
		$author  = $this->user();
		$message = $this->message( $author );
		$id      = $message->getId();

		$this->logIn( $this->user( UsergroupMembership::ROLE_ADMIN ) );
		$this->edit( $message, '<p>Modéré</p>' );

		$this->assertEquals(
				'<p>Modéré</p>',
				$this->reload( $id )->getBody(),
				'Assert moderation stays possible'
		);
	}

	public function testAnEmptyBodyDoesNotWipeTheMessage () {
		$author  = $this->user();
		$message = $this->message( $author );
		$id      = $message->getId();

		$this->logIn( $author );
		$this->edit( $message, '' );

		$this->assertEquals(
				'<p>Bonjur tout le monde</p>',
				$this->reload( $id )->getBody(),
				'Assert an empty correction is refused rather than emptying the message'
		);
	}

	public function testTheEditLinkIsOfferedToTheAuthorOnly () {
		$author  = $this->user();
		$message = $this->message( $author );

		$this->logIn( $author );
		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/' . $this->discussion->getUuid()
		);

		$this->assertEquals( 1, $crawler->filter( 'a.edit_message' )->count() );

		$this->logIn( $this->user() );
		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/' . $this->discussion->getUuid()
		);

		$this->assertEquals(
				0,
				$crawler->filter( 'a.edit_message' )->count(),
				'Assert the link is not offered to someone who cannot use it'
		);
	}
}
