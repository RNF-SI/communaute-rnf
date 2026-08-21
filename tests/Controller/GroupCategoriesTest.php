<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #23 — le vocabulaire des thématiques de groupe.
 *
 * La table existait depuis 2019 sans qu'aucun écran ne permette de la remplir.
 * Elle est maintenant tenue par les administrateurs, comme celle des
 * étiquettes de documents (#26) et pour la même raison : des thématiques
 * saisies librement à la création d'un groupe se dédoubleraient en synonymes,
 * et le filtre cesserait de trier quoi que ce soit.
 */
class GroupCategoriesTest extends WebTestCase {
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
		$this->group->setSlug( 'categories-group-' . uniqid() );
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
	 * @param bool $siteAdmin
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $siteAdmin = FALSE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( $siteAdmin ? [ User::ROLE_USER, User::ROLE_ADMIN ] : [ User::ROLE_USER ] );
		$this->manager->persist( $user );

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_ADMIN );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$this->group->addMember( $membership );

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
	 */
	private function submitNewCategory ( $name ) {
		$crawler = $this->client->request( 'GET', '/administration/group-categories' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$form = $crawler->filter( 'form[name="category"]' )->form();
		$form[ 'category[name]' ] = $name;

		$this->client->submit( $form );
	}

	public function testAnAdministratorAddsATheme () {
		$this->user( TRUE );

		$this->submitNewCategory( 'Tourbières de test' );

		$this->manager->clear();

		$this->assertNotNull(
				$this->manager->getRepository( Category::class )->findOneBy( [ 'name' => 'Tourbières de test' ] )
		);
	}

	public function testTheSameThemeIsNotAddedTwice () {
		$this->user( TRUE );

		$this->submitNewCategory( 'Tourbières de test' );
		$this->submitNewCategory( 'tourbieres DE TEST' );

		$this->manager->clear();

		$this->assertCount(
				1,
				$this->manager->getRepository( Category::class )->findBy( [ 'slug' => 'tourbieres-de-test' ] ),
				'Assert a closed vocabulary is exactly what prevents two spellings of one notion'
		);
	}

	public function testAnEmptyThemeIsRefused () {
		$this->user( TRUE );

		$before = count( $this->manager->getRepository( Category::class )->findAll() );

		$this->submitNewCategory( '   ' );

		$this->manager->clear();

		$this->assertCount( $before, $this->manager->getRepository( Category::class )->findAll() );
	}

	public function testAPlainMemberCannotReachTheVocabulary () {
		$this->user();

		$this->client->request( 'GET', '/administration/group-categories' );

		$this->assertEquals(
				403,
				$this->client->getResponse()->getStatusCode(),
				'Assert the list is kept by the administrators, not by whoever passes by'
		);
	}

	/**
	 * Retirer une thématique déclasse les groupes qui la portaient : c'est le
	 * sens d'un vocabulaire tenu. Le groupe, lui, reste.
	 */
	public function testRemovingAThemeUnclassifiesTheGroups () {
		$this->user( TRUE );

		$category = new Category();
		$category->setName( 'Tourbières de test' );
		$category->setSlug( 'theme-' . uniqid() );
		$this->manager->persist( $category );

		$this->group->addCategory( $category );
		$this->manager->flush();

		$groupId    = $this->group->getId();
		$categoryId = $category->getId();

		$crawler = $this->client->request( 'GET', '/administration/group-categories' );
		$form    = $crawler->filter( 'form[action$="/' . $categoryId . '/delete"]' )->form();

		$this->client->submit( $form );

		$this->manager->clear();

		$group = $this->manager->getRepository( Usergroup::class )->find( $groupId );

		$this->assertNotNull( $group, 'Assert the group itself survives' );
		$this->assertCount( 0, $group->getCategories() );
	}

	public function testRemovingAThemeNeedsATokenOfItsOwn () {
		$this->user( TRUE );

		$category = new Category();
		$category->setName( 'Tourbières de test' );
		$category->setSlug( 'theme-' . uniqid() );
		$this->manager->persist( $category );
		$this->manager->flush();

		$this->client->request(
				'POST',
				'/administration/group-categories/' . $category->getId() . '/delete',
				[ '_token' => 'nawak' ]
		);

		$this->assertEquals( 403, $this->client->getResponse()->getStatusCode() );
	}
}
