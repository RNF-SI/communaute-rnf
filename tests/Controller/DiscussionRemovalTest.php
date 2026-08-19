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
 * Issues #19 et #20 — retirer un message, retirer une discussion.
 *
 * Un message supprimé laisse sa place : les réponses qui lui succèdent
 * perdraient leur sens sans lui. Une discussion supprimée est archivée : les
 * contributions des autres ne sont pas à l'auteur seul.
 */
class DiscussionRemovalTest extends WebTestCase {
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
		$this->group->setSlug( 'removal-' . uniqid() );
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
	 *
	 * @return \App\Entity\User
	 */
	private function member ( $role = UsergroupMembership::ROLE_USER ) {
		// Le gestionnaire d'entités est réinitialisé entre deux requêtes : le
		// groupe construit dans setUp peut être détaché.
		$this->group = $this->manager->getRepository( Usergroup::class )
									 ->findOneBy( [ 'slug' => $this->group->getSlug() ] ) ?: $this->group;

		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $user );

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( $role );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$this->group->addMember( $membership );

		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\Discussion
	 */
	private function discussion ( User $author ) {
		$this->discussion = new Discussion();
		$this->discussion->setUuid( Uuid::uuid4() );
		$this->discussion->setTitle( 'Une discussion' );
		$this->discussion->setUsergroup( $this->group );
		$this->discussion->setAuthor( $author );
		$this->discussion->setCreatedAt( new DateTime() );
		$this->discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $this->discussion );
		$this->manager->flush();

		return $this->discussion;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\DiscussionMessage
	 */
	private function message ( User $author ) {
		$message = new DiscussionMessage();
		$message->setDiscussion( $this->discussion );
		$message->setAuthor( $author );
		$message->setBody( '<p>Bonjour tout le monde</p>' );
		$message->setCreatedAt( new DateTime() );
		$this->manager->persist( $message );
		$this->discussion->addMessage( $message );
		$this->manager->flush();

		return $message;
	}

	private function logIn ( User $user ) {
		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * @param string $url
	 *
	 * @return bool whether the confirmation form was reachable and submitted
	 */
	private function confirm ( $url ) {
		$crawler = $this->client->request( 'GET', $url );

		if ( $this->client->getResponse()->getStatusCode() !== 200 ) {
			return FALSE;
		}

		$this->client->submit( $crawler->selectButton( 'form[submit]' )->form() );

		return TRUE;
	}

	/**
	 * @param int $id
	 *
	 * @return \App\Entity\DiscussionMessage
	 */
	private function reloadMessage ( $id ) {
		$this->manager->clear();

		return $this->manager->getRepository( DiscussionMessage::class )->find( $id );
	}

	/**
	 * @param int $id
	 *
	 * @return \App\Entity\Discussion
	 */
	private function reloadDiscussion ( $id ) {
		$this->manager->clear();

		return $this->manager->getRepository( Discussion::class )->find( $id );
	}

	/**************************************************
	 * #19 — SUPPRESSION D'UN MESSAGE
	 **************************************************/

	public function testTheAuthorCanRemoveTheirOwnMessage () {
		$author = $this->member();
		$this->discussion( $author );
		$message = $this->message( $author );
		$id      = $message->getId();

		$this->logIn( $author );

		$this->assertTrue( $this->confirm( '/groups/' . $this->group->getSlug() . '/message/' . $id . '/delete' ) );

		$message = $this->reloadMessage( $id );

		$this->assertNotNull( $message, 'Assert the message keeps its place in the thread' );
		$this->assertTrue( $message->isDeleted() );
		$this->assertEmpty( $message->getBody(), 'Assert the content is gone' );
	}

	public function testAnotherMemberCannotRemoveTheMessage () {
		$author = $this->member();
		$this->discussion( $author );
		$message = $this->message( $author );
		$id      = $message->getId();

		$this->logIn( $this->member() );
		$this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/message/' . $id . '/delete' );

		$this->assertEquals( 403, $this->client->getResponse()->getStatusCode() );
		$this->assertFalse( $this->reloadMessage( $id )->isDeleted() );
	}

	public function testAnAnimatorCanRemoveAnybodyMessage () {
		$author = $this->member();
		$this->discussion( $author );
		$message = $this->message( $author );
		$id      = $message->getId();

		$this->logIn( $this->member( UsergroupMembership::ROLE_ADMIN ) );

		$this->assertTrue( $this->confirm( '/groups/' . $this->group->getSlug() . '/message/' . $id . '/delete' ) );
		$this->assertTrue( $this->reloadMessage( $id )->isDeleted(), 'Assert moderation stays possible' );
	}

	public function testTheThreadShowsThatAMessageWasRemoved () {
		$author = $this->member();
		$this->discussion( $author );
		$message = $this->message( $author );
		$this->message( $author );

		$this->logIn( $author );
		$this->confirm( '/groups/' . $this->group->getSlug() . '/message/' . $message->getId() . '/delete' );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/' . $this->discussion->getUuid()
		);

		$this->assertEquals( 1, $crawler->filter( '.message__deleted' )->count() );
		$this->assertEquals(
				1,
				$crawler->filter( '.message__full' )->count(),
				'Assert the message that remains is still readable'
		);
	}

	/**************************************************
	 * #20 — ARCHIVAGE D'UNE DISCUSSION
	 **************************************************/

	public function testTheAuthorCanRemoveADiscussionNobodyAnswered () {
		$author = $this->member();
		$this->discussion( $author );
		$this->message( $author );
		$id = $this->discussion->getId();

		$this->logIn( $author );

		$this->assertTrue( $this->confirm(
				'/groups/' . $this->group->getSlug() . '/discussions/' . $this->discussion->getUuid() . '/delete'
		) );
		$this->assertTrue( $this->reloadDiscussion( $id )->isArchived() );
	}

	public function testTheAuthorCannotRemoveADiscussionOthersTookPartIn () {
		$author = $this->member();
		$this->discussion( $author );
		$this->message( $author );
		$this->message( $this->member() );
		$id = $this->discussion->getId();

		$this->logIn( $author );
		$this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/' . $this->discussion->getUuid() . '/delete'
		);

		$this->assertEquals( 403, $this->client->getResponse()->getStatusCode() );
		$this->assertFalse(
				$this->reloadDiscussion( $id )->isArchived(),
				'Assert a discussion others contributed to is no longer the author to throw away'
		);
	}

	public function testAnAnimatorCanRemoveADiscussionWhateverHappened () {
		$author = $this->member();
		$this->discussion( $author );
		$this->message( $author );
		$this->message( $this->member() );
		$id = $this->discussion->getId();

		$this->logIn( $this->member( UsergroupMembership::ROLE_ADMIN ) );

		$this->assertTrue( $this->confirm(
				'/groups/' . $this->group->getSlug() . '/discussions/' . $this->discussion->getUuid() . '/delete'
		) );
		$this->assertTrue( $this->reloadDiscussion( $id )->isArchived() );
	}

	public function testNothingIsDestroyed () {
		$author = $this->member();
		$this->discussion( $author );
		$this->message( $author );
		$id = $this->discussion->getId();

		$this->logIn( $author );
		$this->confirm( '/groups/' . $this->group->getSlug() . '/discussions/' . $this->discussion->getUuid() . '/delete' );

		$this->assertCount(
				1,
				$this->reloadDiscussion( $id )->getMessages(),
				'Assert the messages are still there, so the removal can be undone'
		);
	}

	public function testAnArchivedDiscussionLeavesTheListAndThePage () {
		$author = $this->member();
		$this->discussion( $author );
		$this->message( $author );
		$uuid = $this->discussion->getUuid();

		$this->logIn( $author );
		$this->confirm( '/groups/' . $this->group->getSlug() . '/discussions/' . $uuid . '/delete' );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/discussions' );

		$this->assertEquals(
				0,
				$crawler->filter( '.discussions-list .discussion__teaser' )->count(),
				'Assert members no longer see it listed'
		);

		$this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/discussions/' . $uuid );

		$this->assertEquals( 404, $this->client->getResponse()->getStatusCode() );
	}

	public function testAnAnimatorStillSeesItAndCanPutItBack () {
		$author = $this->member();
		$this->discussion( $author );
		$this->message( $author );
		$uuid = $this->discussion->getUuid();
		$id   = $this->discussion->getId();

		$this->logIn( $author );
		$this->confirm( '/groups/' . $this->group->getSlug() . '/discussions/' . $uuid . '/delete' );

		$this->logIn( $this->member( UsergroupMembership::ROLE_ADMIN ) );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/discussions' );

		$this->assertEquals(
				1,
				$crawler->filter( '.discussion__archived' )->count(),
				'Assert an animator sees what was archived'
		);

		$this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/discussions/' . $uuid . '/restore' );

		$this->assertFalse( $this->reloadDiscussion( $id )->isArchived() );
	}

	public function testAMemberCannotPutADiscussionBack () {
		$author = $this->member();
		$this->discussion( $author );
		$this->message( $author );
		$uuid = $this->discussion->getUuid();
		$id   = $this->discussion->getId();

		$this->logIn( $author );
		$this->confirm( '/groups/' . $this->group->getSlug() . '/discussions/' . $uuid . '/delete' );

		$this->logIn( $this->member() );
		$this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/discussions/' . $uuid . '/restore' );

		$this->assertEquals( 403, $this->client->getResponse()->getStatusCode() );
		$this->assertTrue( $this->reloadDiscussion( $id )->isArchived() );
	}
}
