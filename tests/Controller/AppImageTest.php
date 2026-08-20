<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les illustrations de la plateforme — le logo, les bandeaux — sont servies
 * par une route qui lit un identifiant dans `config/platform/config.yaml`.
 *
 * Cet identifiant survit à la ligne qu'il désigne : recharger les données de
 * test vide la table des fichiers, la configuration garde l'ancien numéro. Le
 * service promettait alors un File et recevait NULL, ce qui levait une erreur
 * fatale **sur chaque page affichant le logo** — c'est-à-dire toutes.
 *
 * Une illustration manquante retombe désormais sur celle livrée avec
 * l'application.
 */
class AppImageTest extends WebTestCase {
	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	protected function setUp (): void {
		$this->client = static::createClient();
	}

	public function testTheLogoIsAlwaysServed () {
		$this->client->request( 'GET', '/app/platform/logo' );

		$this->assertEquals(
				200,
				$this->client->getResponse()->getStatusCode(),
				'Assert a missing file row does not take down every page showing the logo'
		);
	}

	public function testTheHomeIllustrationIsAlwaysServed () {
		$this->client->request( 'GET', '/app/home/front' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
	}

	public function testItIsServedWithoutSigningIn () {
		$this->client->request( 'GET', '/app/platform/logo' );

		$this->assertNotEquals(
				401,
				$this->client->getResponse()->getStatusCode(),
				'Assert the logo of a login page does not itself require a session'
		);
	}
}
