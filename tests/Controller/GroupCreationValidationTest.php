<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Service\Community;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #42 (1) — un administrateur qui crée un groupe ne doit pas le voir
 * partir en validation.
 *
 * La création ne tenait pour « administrateur » que l'animateur du groupe
 * communauté, là où `GroupVoter` accorde aussi tout à `ROLE_ADMIN`. Un
 * administrateur de plateforme créait donc un groupe inactif, et lisait
 * « Votre groupe devra être validé… » — affiché de toute façon à tout le
 * monde, même à qui le groupe serait activé d'office.
 */
class GroupCreationValidationTest extends WebTestCase {
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
	 * @param array $roles
	 *
	 * @return \App\Entity\User
	 */
	private function logIn ( array $roles = [ 'ROLE_USER' ] ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( $roles );
		$this->manager->persist( $user );
		$this->manager->flush();

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );

		return $user;
	}

	/**
	 * @param string $name
	 *
	 * @return \App\Entity\Usergroup|null
	 */
	private function createGroup ( $name ) {
		$crawler = $this->client->request( 'GET', '/groups/new' );

		$form = $crawler->filter( 'form[name="usergroup"]' )->form();
		$form[ 'usergroup[name]' ]        = $name;
		$form[ 'usergroup[description]' ] = 'Un groupe de test.';
		$form[ 'usergroup[visibility]' ]  = Usergroup::PUBLIC;

		$this->client->submit( $form );

		$this->manager->clear();

		return $this->manager->getRepository( Usergroup::class )->findOneBy( [ 'name' => $name ] );
	}

	public function testAPlatformAdminIsNotToldAboutValidation () {
		$this->logIn( [ 'ROLE_USER', 'ROLE_ADMIN' ] );

		$crawler = $this->client->request( 'GET', '/groups/new' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals( 0, $crawler->filter( '.group-creation-validation' )->count() );
	}

	public function testAPlatformAdminCreatesAnActiveGroup () {
		$this->logIn( [ 'ROLE_USER', 'ROLE_ADMIN' ] );

		$group = $this->createGroup( 'Groupe admin ' . uniqid() );

		$this->assertNotNull( $group );
		$this->assertTrue( $group->getIsActive(), 'Assert an administrator’s group needs no validation' );
		$this->assertTrue(
				$this->client->getResponse()->isRedirect( '/groups/' . $group->getSlug() ),
				'Assert the administrator lands on the new group, not on the list of pending ones'
		);
	}

	public function testACommunityAnimatorCreatesAnActiveGroup () {
		$user      = $this->logIn();
		$community = self::$container->get( Community::class )->getGroup();

		if ( !$community ) {
			$this->markTestSkipped( 'No community group in this database' );
		}

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->manager->getReference( Usergroup::class, $community->getId() ) );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_ADMIN );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$this->manager->flush();

		$group = $this->createGroup( 'Groupe animateur ' . uniqid() );

		$this->assertTrue( $group->getIsActive() );
	}

	public function testAMemberIsToldAndWaits () {
		$this->logIn();

		$crawler = $this->client->request( 'GET', '/groups/new' );

		$this->assertEquals( 1, $crawler->filter( '.group-creation-validation' )->count() );

		$group = $this->createGroup( 'Groupe membre ' . uniqid() );

		$this->assertNotNull( $group );
		$this->assertFalse( $group->getIsActive(), 'Assert a member’s group still goes through validation' );
		$this->assertTrue( $this->client->getResponse()->isRedirect( '/groups' ) );
	}

	public function testAPlatformAdminCanValidateAPendingGroup () {
		$this->logIn( [ 'ROLE_USER', 'ROLE_ADMIN' ] );

		$group = new Usergroup();
		$group->setSlug( 'groupe-en-attente-' . uniqid() );
		$group->setName( 'Groupe en attente' );
		$group->setDescription( 'En attente.' );
		$group->setVisibility( Usergroup::PUBLIC );
		$group->setCreatedAt( new DateTime() );
		$group->setIsActive( FALSE );
		$this->manager->persist( $group );
		$this->manager->flush();

		$crawler = $this->client->request( 'GET', '/groups/' . $group->getSlug() );
		$this->assertGreaterThan(
				0,
				$crawler->filter( '.group-activate-actions a[href*="/action/1"]' )->count(),
				'Assert the administrator is offered to validate it'
		);

		$this->client->request( 'GET', '/groups/activate/' . $group->getSlug() . '/action/1' );

		$this->manager->clear();

		$this->assertTrue(
				$this->manager->getRepository( Usergroup::class )->find( $group->getId() )->getIsActive()
		);
	}
}
