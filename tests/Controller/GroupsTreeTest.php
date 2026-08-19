<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #22 — la liste des groupes suit la hiérarchie qui existe déjà en base,
 * commission puis pôles puis ateliers, au lieu de tout présenter à plat.
 */
class GroupsTreeTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	protected function setUp (): void {
		$this->client = static::createClient();

		$user = self::$container->get( EntityManagerInterface::class )
								->getRepository( User::class )
								->findOneBy( [ 'email' => 'membre@example.org' ] );

		if ( !$user ) {
			$this->markTestSkipped( 'Fixtures not loaded' );
		}

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	public function testTheListIsRenderedAsATree () {
		$crawler = $this->client->request( 'GET', '/groups' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertGreaterThan(
				0,
				$crawler->filter( '.groups-tree' )->count(),
				'Assert the list carries the hierarchy'
		);
	}

	public function testAGroupIsShownUnderTheCommissionItBelongsTo () {
		$crawler = $this->client->request( 'GET', '/groups?commission=commission-de-test' );

		$root = $crawler->filter( '.groups-tree > ul > .groups-tree--node' );

		$this->assertEquals(
				1,
				$root->count(),
				'Assert the commission is the only thing at the first level'
		);
		$this->assertStringContainsString( 'Commission de test', $root->text() );

		$children = $root->filter( '.groups-tree--children > .groups-tree--node' );

		$this->assertEquals( 2, $children->count(), 'Assert both groups hang under it' );
		$this->assertStringContainsString( 'Groupe de test', $children->text() );
	}

	public function testAGroupWithoutAVisibleParentStaysAtTheFirstLevel () {
		// Le groupe est demandé seul : sa commission n'est pas affichée, il ne
		// doit donc pas disparaître.
		$crawler = $this->client->request( 'GET', '/groups?commission=groupe-de-test' );

		$root = $crawler->filter( '.groups-tree > ul > .groups-tree--node' );

		$this->assertEquals( 1, $root->count() );
		$this->assertStringContainsString( 'Groupe de test', $root->text() );
	}

	public function testEveryGroupIsStillReachable () {
		$crawler = $this->client->request( 'GET', '/groups' );

		$this->assertGreaterThan(
				20,
				$crawler->filter( '.groups-tree .group__teaser' )->count(),
				'Assert nesting hides no group'
		);
	}

	public function testTheSearchAlsoAnswersATree () {
		$this->client->request( 'GET', '/groups/search?type=all-groups-container&q=&commission=commission-de-test' );

		$groups = json_decode( $this->client->getResponse()->getContent(), TRUE )[ 'groups' ];

		$this->assertStringContainsString(
				'groups-tree--children',
				$groups,
				'Assert the hierarchy survives a search'
		);
	}
}
