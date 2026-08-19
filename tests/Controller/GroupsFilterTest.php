<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #23 — filtering a list of 58 groups so that somebody arriving on the
 * platform is not handed everything at once.
 *
 * The filter relies on the hierarchy that already exists in production:
 * commissions, then groups and pôles, then ateliers. It is the only data that
 * actually sorts the groups — the categories have never been filled in, and a
 * group carries no geographic information at all.
 */
class GroupsFilterTest extends WebTestCase {
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

	public function testTheFilterOffersTheGroupsThatLeadOthers () {
		$crawler = $this->client->request( 'GET', '/groups' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$options = $crawler->filter( '#groups-filter-commission option' )->extract( [ 'value' ] );

		$this->assertContains(
				'commission-de-test',
				$options,
				'Assert a group leading others is offered as a filter'
		);
		$this->assertNotContains(
				'groupe-de-test',
				$options,
				'Assert a group leading nobody is not offered as a filter'
		);
	}

	public function testWithoutAFilterEveryGroupIsListed () {
		$crawler = $this->client->request( 'GET', '/groups' );
		$text    = $crawler->filter( '#all-groups-container' )->text();

		$this->assertStringContainsString( 'Groupe de test', $text );
		$this->assertGreaterThan(
				5,
				$crawler->filter( '#all-groups-container .group__teaser' )->count(),
				'Assert the whole list is shown by default'
		);
	}

	public function testFilteringKeepsTheCommissionAndWhatItLeads () {
		$crawler = $this->client->request( 'GET', '/groups?commission=commission-de-test' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$text = $crawler->filter( '#all-groups-container' )->text();

		$this->assertStringContainsString( 'Commission de test', $text );
		$this->assertStringContainsString( 'Groupe de test', $text );
	}

	public function testFilteringLeavesTheOtherGroupsOut () {
		$crawler = $this->client->request( 'GET', '/groups?commission=commission-de-test' );

		$this->assertEquals(
				3,
				$crawler->filter( '#all-groups-container .group__teaser' )->count(),
				'Assert only the commission and its two groups are listed'
		);
	}

	public function testAnUnknownFilterFallsBackOnTheWholeList () {
		$crawler = $this->client->request( 'GET', '/groups?commission=nawak' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertGreaterThan(
				5,
				$crawler->filter( '#all-groups-container .group__teaser' )->count(),
				'Assert a bad parameter does not empty the page'
		);
	}

	public function testTheChosenFilterIsShownBackToTheUser () {
		$crawler = $this->client->request( 'GET', '/groups?commission=commission-de-test' );

		$this->assertEquals(
				'commission-de-test',
				$crawler->filter( '#groups-filter-commission option[selected]' )->attr( 'value' )
		);
	}

	public function testTheSearchKeepsTheFilterInPlace () {
		$this->client->request(
				'GET',
				'/groups/search?type=all-groups-container&q=&commission=commission-de-test'
		);

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$groups = json_decode( $this->client->getResponse()->getContent(), TRUE )[ 'groups' ];

		$this->assertStringContainsString( 'Groupe de test', $groups );
		$this->assertEquals(
				3,
				substr_count( $groups, 'group__teaser' ),
				'Assert typing in the search box does not bring back the whole list'
		);
	}
}
