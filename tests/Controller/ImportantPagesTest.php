<?php

namespace App\Tests\Controller;

use App\Entity\Page;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #2 — les pages que les animateurs mettent en avant doivent ressortir,
 * et seuls les animateurs peuvent le décider.
 */
class ImportantPagesTest extends WebTestCase {
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

		// Ce test crée des pages, ce qui produit des notifications. Sans
		// transaction, elles survivraient au test et fausseraient ceux du
		// résumé quotidien.
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
	 * @param string $email
	 */
	private function logIn ( $email ) {
		$user = $this->manager->getRepository( User::class )->findOneBy( [ 'email' => $email ] );

		if ( !$user ) {
			$this->markTestSkipped( sprintf( 'Fixtures not loaded: %s', $email ) );
		}

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	public function testAnImportantPageComesFirst () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/pages' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$titles = $crawler->filter( '.pages-list .page__teaser h2' )->each( function ( $node ) {
			return trim( $node->text() );
		} );

		$this->assertNotEmpty( $titles );
		$this->assertStringContainsString(
				'Page importante de test',
				$titles[ 0 ],
				'Assert the highlighted page is listed first'
		);
	}

	public function testAnImportantPageCarriesAMarker () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/pages' );

		$this->assertGreaterThan(
				0,
				$crawler->filter( '.page__important .page-important-badge' )->count(),
				'Assert the reader can tell which page is highlighted'
		);
	}

	public function testAnAnimatorIsOfferedToHighlightAPage () {
		$this->logIn( 'referent@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/pages/new' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals(
				1,
				$crawler->filter( '#page_isImportant' )->count(),
				'Assert an animator can decide'
		);
	}

	public function testAPlainMemberIsNotOfferedTheChoice () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/pages/new' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals(
				0,
				$crawler->filter( '#page_isImportant' )->count(),
				'Assert highlighting is the animators business'
		);
	}

	/**
	 * Le champ n'existe pas dans le formulaire d'un simple membre. Symfony ne
	 * se contente pas de l'ignorer : il refuse le formulaire entier, ce qui
	 * est une garantie plus forte encore.
	 */
	public function testAMemberCannotForceThePageToBeHighlighted () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/pages/new' );
		$form    = $crawler->filter( 'form[name="page"]' )->form();

		$title = 'Page forgee ' . uniqid();

		$form[ 'page[title]' ] = $title;
		$form[ 'page[body]' ]  = '<p>Contenu</p>';

		$values = $form->getPhpValues();
		$values[ 'page' ][ 'isImportant' ] = '1';

		$this->client->request( 'POST', $form->getUri(), $values );

		$this->manager->clear();

		$this->assertNull(
				$this->manager->getRepository( Page::class )->findOneBy( [ 'title' => $title ] ),
				'Assert the forged submission is refused rather than quietly accepted'
		);
	}

	public function testAMemberCanStillCreateAnOrdinaryPage () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/pages/new' );
		$form    = $crawler->filter( 'form[name="page"]' )->form();

		$title = 'Page ordinaire ' . uniqid();

		$form[ 'page[title]' ] = $title;
		$form[ 'page[body]' ]  = '<p>Contenu</p>';

		$this->client->submit( $form );

		$this->manager->clear();

		$page = $this->manager->getRepository( Page::class )->findOneBy( [ 'title' => $title ] );

		$this->assertNotNull( $page, 'Assert the restriction does not block ordinary contributions' );
		$this->assertFalse( $page->getIsImportant() );
	}
}
