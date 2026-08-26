<?php

namespace App\Tests\Controller;

use App\Service\OnlyOffice\OnlyOfficeToken;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #43 — les routes du serveur de documents répondent en clair.
 *
 * Quand la plateforme et le serveur de documents partagent une seule adresse
 * publique, le seul chemin qui reste entre eux est le réseau interne, **en
 * HTTP** : le TLS est terminé par un reverse proxy que l'intérieur ne voit
 * pas. Ces deux routes doivent donc répondre là où tout le reste du site est
 * renvoyé vers HTTPS par `requires_channel`.
 *
 * C'est le pare-feu dédié qui le donne, et il le donne sans qu'on l'ait
 * demandé : `security: false` ne pose aucun écouteur, donc pas de
 * `ChannelListener`. Les remettre dans le pare-feu principal ferait répondre
 * une redirection 301 là où le serveur de documents attend un fichier — et il
 * enregistrerait la redirection à la place du document.
 *
 * Classe à part, parce que `SECURE_SCHEME=https` vaut pour tout le noyau : dans
 * la classe voisine, les dépôts de documents par formulaire partiraient tous
 * en 301.
 */
class OnlyOfficeChannelTest extends WebTestCase {
	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var string
	 */
	private $previousScheme;

	protected function setUp (): void {
		$this->previousScheme = (string) ( $_SERVER[ 'SECURE_SCHEME' ] ?? 'http' );

		$_ENV[ 'SECURE_SCHEME' ]    = 'https';
		$_SERVER[ 'SECURE_SCHEME' ] = 'https';

		$this->client = static::createClient();
	}

	protected function tearDown (): void {
		$_ENV[ 'SECURE_SCHEME' ]    = $this->previousScheme;
		$_SERVER[ 'SECURE_SCHEME' ] = $this->previousScheme;

		parent::tearDown();
	}

	public function testTheDocumentServerRoutesAnswerOverPlainHttp () {
		$tokens = self::$container->get( OnlyOfficeToken::class );

		$this->client->request(
				'GET',
				'http://localhost/office/' . $tokens->create( 1, OnlyOfficeToken::READ ) . '/content'
		);

		// 403, 404 : peu importe, ce sont les routes qui ont répondu. Ce qui
		// est interdit ici, c'est la redirection.
		$this->assertNotSame(
				301,
				$this->client->getResponse()->getStatusCode(),
				'Assert the document server is not bounced to HTTPS'
		);
	}

	/**
	 * L'assertion qui donne son sens à la précédente : sans elle, le test
	 * passerait aussi dans un environnement où la redirection n'est pas
	 * active, et ne prouverait rien.
	 */
	public function testTheRestOfTheSiteIsBouncedToHttps () {
		$this->client->request( 'GET', 'http://localhost/groups' );

		$this->assertSame( 301, $this->client->getResponse()->getStatusCode() );
	}
}
