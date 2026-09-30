<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\AppTextManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #42 (8) — relier la communauté aux autres plateformes du réseau :
 * une colonne « Nos autres plateformes » dans le pied de page, tenue depuis
 * l'administration comme les trois autres.
 */
class FooterPlatformsTest extends WebTestCase {
	public function testTheFooterLinksToTheNetwork () {
		$client  = static::createClient();
		$crawler = $client->request( 'GET', '/' );

		$footer = $crawler->filter( 'footer.footer' );

		$this->assertStringContainsString( 'Nos autres plateformes', $footer->text() );

		$link = $footer->filter( 'a[href="https://www.reserves-naturelles.org/"]' );

		$this->assertCount( 1, $link, 'Assert the RNF website is linked' );
		$this->assertSame( '_blank', $link->attr( 'target' ), 'Assert leaving for another site opens a new tab' );
		$this->assertStringContainsString( 'noopener', $link->attr( 'rel' ) );
	}

	public function testInternalLinksStayInTheTab () {
		$client  = static::createClient();
		$crawler = $client->request( 'GET', '/' );

		$this->assertCount( 0, $crawler->filter( 'footer.footer a[href^="/"][target]' ) );
	}

	/**
	 * config.yaml n'est pas versionné : il a été copié du défaut à
	 * l'installation, avant que la colonne existe. Elle doit venir du défaut
	 * tant que l'administration ne l'a pas enregistrée — et non faire tomber
	 * le pied de page de toutes les pages.
	 */
	public function testAnInstallationPredatingTheColumnFallsBackOnTheDefault () {
		self::bootKernel();

		$dir = sys_get_temp_dir() . '/footer-' . uniqid();
		mkdir( $dir . '/config/platform', 0777, TRUE );

		$default = self::$container->getParameter( 'kernel.project_dir' ) . '/config/platform/default.config.yaml';
		copy( $default, $dir . '/config/platform/default.config.yaml' );

		// Un config.yaml d'avant : la section n'y est pas.
		file_put_contents( $dir . '/config/platform/config.yaml', "menus:\n  navbarLiens:\n    title:\n    liens: []\n" );

		$manager = new AppTextManager( self::$container->get( 'doctrine.orm.entity_manager' ), $dir );

		$column = $manager->getTabSectionText( 'menus', 'footbarPlatformsLiens' );
		$this->assertSame( 'Nos autres plateformes', $column[ 'title' ] );

		$this->assertArrayHasKey( 'footbarPlatformsLiens', $manager->getTabText( 'menus' ), 'Assert the admin page offers it too' );

		array_map( 'unlink', glob( $dir . '/config/platform/*' ) );
		rmdir( $dir . '/config/platform' );
		rmdir( $dir . '/config' );
		rmdir( $dir );
	}

	/**
	 * Ouverte seulement : l'enregistrer réécrirait le config.yaml du poste.
	 */
	public function testTheAdministrationOffersTheColumn () {
		$client = static::createClient();
		$admin  = self::$container->get( EntityManagerInterface::class )
								  ->getRepository( User::class )
								  ->findOneBy( [ 'email' => 'admin@example.org' ] );

		if ( !$admin ) {
			$this->markTestSkipped( 'Fixtures not loaded' );
		}

		$session = self::$container->get( 'session' );
		$session->set( '_security_main', serialize( new UsernamePasswordToken( $admin, NULL, 'main', $admin->getRoles() ) ) );
		$session->save();
		$client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );

		$crawler = $client->request( 'GET', '/administration/menus' );

		$this->assertSame( 200, $client->getResponse()->getStatusCode() );
		$this->assertStringNotContainsString( 'aaa', $this->getActualOutput(), 'Assert no debugging output leaks before the page' );
		$this->assertCount( 1, $crawler->filter( '[name="admin_menus[footbarPlatformsLiensTitle]"]' ) );
		$this->assertSame( 'Nos autres plateformes', $crawler->filter( '[name="admin_menus[footbarPlatformsLiensTitle]"]' )->attr( 'value' ) );
	}
}
