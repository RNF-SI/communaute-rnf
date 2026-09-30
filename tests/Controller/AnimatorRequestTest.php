<?php

namespace App\Tests\Controller;

use App\Entity\Discussion;
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
 * Issue #42 (5) — « pouvoir modifier une discussion qu'on n'a pas créée ».
 *
 * Arbitré avec le mainteneur : le retour venait de quelqu'un qui aurait dû
 * animer le groupe et n'en avait pas le rôle. Les droits ne changent donc pas
 * (#33) ; ce qui manquait, c'est de savoir qui peut renommer une discussion,
 * et de pouvoir demander à le devenir — une demande que les animateurs
 * acceptent ou déclinent.
 */
class AnimatorRequestTest extends WebTestCase {
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

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->group = new Usergroup();
		$this->group->setSlug( 'animator-group-' . uniqid() );
		$this->group->setName( 'Test group' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );
		$this->manager->flush();
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
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Membre ' . uniqid() );
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
	 * @param \App\Entity\User $user
	 *
	 * @return \App\Entity\UsergroupMembership
	 */
	private function membershipOf ( User $user ) {
		$this->manager->clear();

		return $this->manager->getRepository( UsergroupMembership::class )->findOneBy( [
				'user'      => $user->getId(),
				'usergroup' => $this->group->getId(),
		] );
	}

	/**
	 * @return string
	 */
	private function members () {
		return '/groups/' . $this->group->getSlug() . '/members';
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return \App\Entity\User
	 */
	private function withRequest ( User $user ) {
		$membership = $this->manager->getRepository( UsergroupMembership::class )->findOneBy( [
				'user'      => $user,
				'usergroup' => $this->group,
		] );
		$membership->setAnimatorRequestedAt( new DateTime( '-1 day' ) );
		$this->manager->flush();

		return $user;
	}

	/**************************************************
	 * DEMANDER
	 **************************************************/

	public function testAMemberCanAsk () {
		$this->member( UsergroupMembership::ROLE_ADMIN );
		$user = $this->member();

		$this->logIn( $user );

		$crawler = $this->client->request( 'GET', $this->members() );
		$this->client->submit( $crawler->filter( '#animator-request form' )->form() );

		$this->assertTrue( $this->client->getResponse()->isRedirect( $this->members() ) );
		$this->assertTrue( $this->membershipOf( $user )->hasAnimatorRequest() );

		$crawler = $this->client->followRedirect();

		$this->assertCount( 1, $crawler->filter( '#animator-request input[name="cancel"]' ), 'Assert the member sees the request is waiting' );
	}

	public function testAMemberCanWithdrawTheRequest () {
		$user = $this->withRequest( $this->member() );

		$this->logIn( $user );

		$crawler = $this->client->request( 'GET', $this->members() );
		$this->client->submit( $crawler->filter( '#animator-request form' )->form() );

		$this->assertFalse( $this->membershipOf( $user )->hasAnimatorRequest() );
	}

	public function testAnAnimatorHasNothingToAsk () {
		$this->logIn( $this->member( UsergroupMembership::ROLE_ADMIN ) );

		$crawler = $this->client->request( 'GET', $this->members() );

		$this->assertCount( 0, $crawler->filter( '#animator-request' ) );
	}

	public function testAForgedRequestIsRefused () {
		$user = $this->member();

		$this->logIn( $user );
		$this->client->request( 'POST', '/groups/' . $this->group->getSlug() . '/animator-request', [ '_token' => 'nope' ] );

		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
		$this->assertFalse( $this->membershipOf( $user )->hasAnimatorRequest() );
	}

	/**************************************************
	 * RÉPONDRE
	 **************************************************/

	public function testAnAnimatorAccepts () {
		$user = $this->withRequest( $this->member() );

		$this->logIn( $this->member( UsergroupMembership::ROLE_ADMIN ) );

		$crawler = $this->client->request( 'GET', $this->members() );

		$this->assertStringContainsString( $user->getName(), $crawler->filter( '#animator-requests' )->text() );

		$this->client->submit( $crawler->filter( '#animator-requests form[action$="/accept"]' )->form() );

		$membership = $this->membershipOf( $user );

		$this->assertSame( UsergroupMembership::ROLE_ADMIN, $membership->getRole() );
		$this->assertNull( $membership->getAnimatorRequestedAt() );
	}

	public function testAnAnimatorDeclines () {
		$user = $this->withRequest( $this->member() );

		$this->logIn( $this->member( UsergroupMembership::ROLE_ADMIN ) );

		$crawler = $this->client->request( 'GET', $this->members() );
		$this->client->submit( $crawler->filter( '#animator-requests form[action$="/decline"]' )->form() );

		$membership = $this->membershipOf( $user );

		$this->assertSame( UsergroupMembership::ROLE_USER, $membership->getRole() );
		$this->assertFalse( $membership->hasAnimatorRequest() );
	}

	public function testAnotherMemberCannotDecide () {
		$user = $this->withRequest( $this->member() );

		$this->logIn( $this->member() );

		$crawler = $this->client->request( 'GET', $this->members() );
		$this->assertCount( 0, $crawler->filter( '#animator-requests' ), 'Assert the pending requests are the animators’ business' );

		$this->client->request( 'POST', '/groups/' . $this->group->getSlug() . '/animator-request/' . $user->getId() . '/accept' );

		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
		$this->assertSame( UsergroupMembership::ROLE_USER, $this->membershipOf( $user )->getRole() );
	}

	/**
	 * Nommé par le chemin d'avant — le menu de l'annuaire —, la demande est
	 * satisfaite et ne doit pas rester à attendre.
	 */
	public function testPromotingOtherwiseClosesTheRequest () {
		$user = $this->withRequest( $this->member() );

		$this->logIn( $this->member( UsergroupMembership::ROLE_ADMIN ) );
		$this->client->request( 'GET', $this->members() . '/' . $user->getId() . '/admin/' . UsergroupMembership::ROLE_ADMIN );

		$this->assertNull( $this->membershipOf( $user )->getAnimatorRequestedAt() );
	}

	/**************************************************
	 * LA DISCUSSION DIT QUI PEUT LA RENOMMER
	 **************************************************/

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return string
	 */
	private function discussion ( User $author ) {
		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'Sujet ' . uniqid() );
		$discussion->setAuthor( $author );
		$discussion->setUsergroup( $this->group );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $discussion );
		$this->manager->flush();

		return '/groups/' . $this->group->getSlug() . '/discussions/' . $discussion->getUuid();
	}

	public function testAMemberWhoCannotRenameIsToldWhoCan () {
		$url = $this->discussion( $this->member() );

		$this->logIn( $this->member() );

		$crawler = $this->client->request( 'GET', $url );

		$note = $crawler->filter( '.discussion-rename-note' );

		$this->assertCount( 1, $note );
		$this->assertStringContainsString( 'animateur', $note->text() );
		$this->assertStringContainsString( '/members#animator-request', $note->filter( 'a' )->attr( 'href' ) );
	}

	public function testTheAuthorAndTheAnimatorsAreNotBothered () {
		$author = $this->member();
		$url    = $this->discussion( $author );

		foreach ( [ $author, $this->member( UsergroupMembership::ROLE_ADMIN ) ] as $user ) {
			$this->logIn( $user );

			$crawler = $this->client->request( 'GET', $url );

			$this->assertCount( 0, $crawler->filter( '.discussion-rename-note' ) );
			$this->assertCount( 1, $crawler->filter( '.edit_discussion' ) );
		}
	}
}
