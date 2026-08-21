<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #23 — le second axe de tri des groupes : la thématique.
 *
 * La commission dit de qui un groupe dépend, la thématique de quoi il parle,
 * et les deux ne se recouvrent pas — c'est pourquoi elles se cumulent. Les
 * thématiques des données de test traversent délibérément la hiérarchie : un
 * second filtre qui ne ferait que répéter le premier n'aurait aucune raison
 * d'exister.
 */
class GroupsThemeFilterTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * La thématique que les fixtures posent sur les trois groupes de
	 * référence, et une autre qu'ils ne portent pas : c'est l'écart entre les
	 * deux qui prouve que le filtre trie.
	 */
	private const THEME_OF_REFERENCE = 'suivis-et-protocoles';
	private const OTHER_THEME         = 'accueil-du-public';

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

	public function testTheThemesAreOffered () {
		$crawler = $this->client->request( 'GET', '/groups' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertGreaterThan(
				1,
				$crawler->filter( '#groups-filter-theme option' )->count(),
				'Assert the themes that classify at least one group are offered'
		);
	}

	public function testFilteringOnAThemeReducesTheList () {
		$whole = $this->client->request( 'GET', '/groups' )
							  ->filter( '#all-groups-container .group__teaser' )
							  ->count();

		$crawler = $this->client->request( 'GET', '/groups?theme=' . self::THEME_OF_REFERENCE );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$filtered = $crawler->filter( '#all-groups-container .group__teaser' )->count();

		$this->assertGreaterThan( 0, $filtered, 'Assert the theme still shows something' );
		$this->assertLessThan( $whole, $filtered, 'Assert the list is actually reduced' );
	}

	/**
	 * Le choix en cours doit rester visible : un filtre qui s'applique sans
	 * que la liste le dise laisse croire que des groupes ont disparu.
	 */
	public function testTheChosenThemeStaysSelected () {
		$theme = self::THEME_OF_REFERENCE;

		$crawler = $this->client->request( 'GET', '/groups?theme=' . $theme );

		$this->assertEquals(
				1,
				$crawler->filter( '#groups-filter-theme option[selected]' )->count()
		);
		$this->assertEquals(
				$theme,
				$crawler->filter( '#groups-filter-theme option[selected]' )->attr( 'value' )
		);
	}

	/**
	 * Deux questions différentes, qui doivent pouvoir être posées ensemble.
	 */
	public function testAThemeAndACommissionApplyTogether () {
		$crawler = $this->client->request(
				'GET',
				'/groups?commission=commission-de-test&theme=' . self::THEME_OF_REFERENCE
		);

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertStringContainsString(
				'Groupe de test',
				$crawler->filter( '#all-groups-container' )->text(),
				'Assert the reference group answers both questions at once'
		);
	}

	/**
	 * Une thématique inconnue réaffiche la liste entière plutôt que de vider
	 * la page : une adresse recopiée de travers ne doit pas laisser croire que
	 * le réseau n'a plus de groupes.
	 */
	public function testAnUnknownThemeShowsEverything () {
		$crawler = $this->client->request( 'GET', '/groups?theme=ceci-n-existe-pas' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertGreaterThan(
				5,
				$crawler->filter( '#all-groups-container .group__teaser' )->count()
		);
	}

	/**
	 * Le filtre doit survivre à la recherche, comme celui des commissions :
	 * sans cela, taper une lettre ramènerait les 58 groupes.
	 */
	public function testTheThemeSurvivesASearch () {
		$this->client->request(
				'GET',
				'/groups/search?type=all-groups-container&q=&theme=' . self::OTHER_THEME
		);

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$groups = json_decode( $this->client->getResponse()->getContent(), TRUE )[ 'groups' ];

		$this->assertStringNotContainsString(
				'Groupe de test',
				$groups,
				'Assert a group outside the theme is not brought back by the search'
		);
	}

	/**
	 * Les deux filtres se cumulent : demander une commission et une thématique
	 * qu'aucun de ses groupes ne porte doit rendre une liste vide, et non pas
	 * l'un des deux critères silencieusement ignoré.
	 */
	public function testBothFiltersNarrowTogether () {
		$crawler = $this->client->request(
				'GET',
				'/groups?commission=commission-de-test&theme=' . self::OTHER_THEME
		);

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals(
				0,
				$crawler->filter( '#all-groups-container .group__teaser' )->count(),
				'Assert neither criterion is quietly dropped'
		);
	}
}
