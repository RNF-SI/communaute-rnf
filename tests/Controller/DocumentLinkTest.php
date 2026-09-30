<?php

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\File;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Service\FileManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #42 (4) — déposer un lien vers un document hébergé sur une autre
 * plateforme, à la place d'un fichier.
 *
 * Un fichier ou un lien : il faut l'un des deux, jamais les deux au dépôt. À
 * la modification, l'un remplace l'autre, comme un fichier en remplace un
 * autre depuis #41.
 */
class DocumentLinkTest extends WebTestCase {
	private const FIREWALL = 'main';

	private const URL = 'https://docs.example.org/plans/Plan%20de%20gestion.pdf';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Service\FileManager
	 */
	private $files;

	/**
	 * @var \App\Entity\Usergroup
	 */
	private $group;

	/**
	 * @var \App\Entity\User
	 */
	private $user;

	/**
	 * @var string[]
	 */
	private $temporary = [];

	/**
	 * @var \App\Entity\File[]
	 */
	private $written = [];

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->files   = self::$container->get( FileManager::class );
		$this->manager->getConnection()->beginTransaction();

		$this->group = new Usergroup();
		$this->group->setSlug( 'link-group-' . uniqid() );
		$this->group->setName( 'Test group' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );

		$this->user = new User();
		$this->user->setEmail( uniqid() . '@example.org' );
		$this->user->setName( 'Test User' );
		$this->user->setCreatedAt( new DateTime() );
		$this->user->setStatus( User::STATUS_ACTIVE );
		$this->user->setPassword( '' );
		$this->user->setHasAgreedTermsOfUse( TRUE );
		$this->user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $this->user );

		$membership = new UsergroupMembership();
		$membership->setUser( $this->user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );

		$this->manager->flush();

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $this->user, NULL, self::FIREWALL, $this->user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	protected function tearDown (): void {
		foreach ( $this->written as $file ) {
			$this->files->deleteFile( $file );
		}

		foreach ( $this->temporary as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @return string
	 */
	private function base () {
		return '/groups/' . $this->group->getSlug() . '/documents';
	}

	/**
	 * @param array       $fields
	 * @param string|null $upload chemin d'un fichier à joindre
	 *
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	private function deposit ( array $fields, $upload = NULL ) {
		$crawler = $this->client->request( 'GET', $this->base() . '/new' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();

		foreach ( $fields as $name => $value ) {
			$form[ 'document[' . $name . ']' ] = $value;
		}

		if ( $upload ) {
			$form[ 'document[filefile]' ]->upload( $upload );
		}

		return $this->client->submit( $form );
	}

	/**
	 * @param string $title
	 *
	 * @return \App\Entity\Document|null
	 */
	private function find ( $title ) {
		$this->manager->clear();

		return $this->manager->getRepository( Document::class )->findOneBy( [ 'title' => $title ] );
	}

	/**
	 * @return string
	 */
	private function pdf () {
		$path = sys_get_temp_dir() . '/' . uniqid( 'document-' ) . '.pdf';
		file_put_contents( $path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" );

		$this->temporary[] = $path;

		return $path;
	}

	/**
	 * @param string|null $url
	 *
	 * @return \App\Entity\Document
	 */
	private function linkDocument ( $url = self::URL ) {
		$document = new Document();
		$document->setTitle( 'Lien ' . uniqid() );
		$document->setSlug( 'lien-' . uniqid() );
		$document->setUrl( $url );
		$document->setUsergroup( $this->group );
		$document->setUser( $this->user );
		$document->setCreatedAt( new DateTime() );
		$this->manager->persist( $document );
		$this->manager->flush();

		return $document;
	}

	/**************************************************
	 * DÉPOSER
	 **************************************************/

	public function testALinkAloneMakesADocument () {
		$title = 'Plan de gestion ' . uniqid();

		$this->deposit( [ 'title' => $title, 'url' => self::URL ] );

		$document = $this->find( $title );

		$this->assertNotNull( $document, 'Assert a link is enough to deposit a document' );
		$this->assertNull( $document->getFile() );
		$this->assertSame( self::URL, $document->getUrl() );
		$this->assertTrue( $document->isLink() );
	}

	public function testALinkWithoutATitleIsNamedAfterItsAddress () {
		$this->deposit( [ 'url' => 'https://socle.example.org/fiches/suivi-avifaune' ] );

		$this->manager->clear();

		$document = $this->manager->getRepository( Document::class )->findOneBy( [ 'url' => 'https://socle.example.org/fiches/suivi-avifaune' ] );

		$this->assertNotNull( $document );
		$this->assertSame( 'socle.example.org — suivi-avifaune', $document->getTitle() );
	}

	public function testAFileAndALinkTogetherAreRefused () {
		$title   = 'Les deux ' . uniqid();
		$crawler = $this->deposit( [ 'title' => $title, 'url' => self::URL ], $this->pdf() );

		$this->assertNull( $this->find( $title ) );
		$this->assertStringContainsString( 'pas les deux', $crawler->filter( '.form-row__url .form_errors' )->text() );
	}

	public function testNeitherFileNorLinkIsRefused () {
		$title   = 'Rien ' . uniqid();
		$crawler = $this->deposit( [ 'title' => $title ] );

		$this->assertNull( $this->find( $title ) );
		$this->assertStringContainsString( 'lien', $crawler->filter( '.form-row__file .form_errors' )->text() );
	}

	/**
	 * Le lien s'ouvre d'un clic depuis la fiche : ni `javascript:`, ni
	 * `data:`, ni `file:`.
	 */
	public function testOnlyWebAddressesAreAccepted () {
		foreach ( [ 'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'file:///etc/passwd' ] as $i => $url ) {
			$title = 'Piège ' . $i . ' ' . uniqid();

			$this->deposit( [ 'title' => $title, 'url' => $url ] );

			$this->assertNull( $this->find( $title ), sprintf( 'Assert « %s » is refused', $url ) );
		}
	}

	/**************************************************
	 * OUVRIR
	 **************************************************/

	public function testTheSheetOpensTheLinkElsewhere () {
		$document = $this->linkDocument();

		$crawler = $this->client->request( 'GET', $this->base() . '/' . $document->getId() );

		$this->assertTrue( $this->client->getResponse()->isSuccessful() );

		$link = $crawler->filter( '.panel--edit a[href="' . self::URL . '"]' );

		$this->assertCount( 1, $link, 'Assert the sheet opens the link' );
		$this->assertSame( '_blank', $link->attr( 'target' ) );
		$this->assertStringContainsString( 'noopener', $link->attr( 'rel' ) );

		$this->assertCount( 0, $crawler->filter( '.document-sheet--nofile' ), 'Assert a link is not reported as a missing file' );
		$this->assertCount( 0, $crawler->filter( 'a[href*="download=1"]' ) );
		$this->assertStringContainsString( 'docs.example.org', $crawler->filter( '.document-sheet--facts' )->text() );
	}

	public function testTheListOffersToOpenTheLink () {
		$this->linkDocument();

		$crawler = $this->client->request( 'GET', $this->base() );

		$this->assertCount( 1, $crawler->filter( 'a.document--link[href="' . self::URL . '"]' ) );
	}

	/**
	 * La recherche de la liste fouillait le nom du fichier sans vérifier
	 * qu'il y en ait un : un seul document-lien faisait tomber la page.
	 */
	public function testSearchingAndFilteringSurviveALink () {
		$document = $this->linkDocument();

		$crawler = $this->client->request( 'GET', $this->base() . '?form[query]=example' );

		$this->assertTrue( $this->client->getResponse()->isSuccessful() );
		$this->assertStringContainsString( $document->getTitle(), $crawler->filter( 'body' )->text(), 'Assert its address is searched' );

		$this->client->request( 'GET', $this->base() . '?form[filetype][]=pdf' );

		$this->assertTrue( $this->client->getResponse()->isSuccessful() );
	}

	public function testTheFileRouteLeadsToTheLink () {
		$document = $this->linkDocument();

		$this->client->request( 'GET', $this->base() . '/' . $document->getId() . '/get' );

		$this->assertTrue( $this->client->getResponse()->isRedirect( self::URL ) );
	}

	/**************************************************
	 * MODIFIER
	 **************************************************/

	public function testAFileReplacesTheLink () {
		$document = $this->linkDocument();

		$crawler = $this->client->request( 'GET', $this->base() . '/' . $document->getId() . '/edit' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[filefile]' ]->upload( $this->pdf() );

		$this->client->submit( $form );

		$this->manager->clear();
		$document = $this->manager->getRepository( Document::class )->find( $document->getId() );

		$this->assertNotNull( $document->getFile(), 'Assert the file was attached' );
		$this->assertNull( $document->getUrl(), 'Assert the link it replaces is gone' );

		$this->manager->initializeObject( $document->getFile() );
		$this->written[] = $document->getFile();
	}

	public function testALinkReplacesTheFile () {
		$title = 'Fichier puis lien ' . uniqid();
		$this->deposit( [ 'title' => $title ], $this->pdf() );

		$document = $this->find( $title );
		$this->manager->initializeObject( $document->getFile() );
		$old = $document->getFile();

		$crawler = $this->client->request( 'GET', $this->base() . '/' . $document->getId() . '/edit' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[url]' ] = self::URL;

		$this->client->submit( $form );

		$this->manager->clear();
		$document = $this->manager->getRepository( Document::class )->find( $document->getId() );

		$this->assertTrue( $document->isLink() );
		$this->assertNull( $this->manager->getRepository( File::class )->find( $old->getId() ), 'Assert the replaced file is removed' );
		$this->assertFalse( $this->files->isAvailable( $old ), 'Assert it leaves the storage too' );
	}

	public function testALinkCannotBeEmptiedIntoNothing () {
		$document = $this->linkDocument();

		$crawler = $this->client->request( 'GET', $this->base() . '/' . $document->getId() . '/edit' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[url]' ] = '';

		$this->client->submit( $form );

		$this->assertFalse( $this->client->getResponse()->isRedirect(), 'Assert the form is refused' );

		$this->manager->clear();

		$this->assertSame( self::URL, $this->manager->getRepository( Document::class )->find( $document->getId() )->getUrl() );
	}
}
