<?php

namespace App\Tests\Controller;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\Document;
use App\Entity\DocumentFolder;
use App\Entity\DocumentTag;
use App\Entity\Page;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #32 — la fiche d'un document.
 *
 * « Les documents sont bruts » : un document n'était qu'un téléchargement. On
 * ne pouvait ni y envoyer quelqu'un, ni revenir dessus, ni voir ce qui en
 * avait été dit. La fiche lui donne une adresse et referme la boucle — depuis
 * le document, on retrouve les discussions et les pages qui y renvoient.
 */
class DocumentPageTest extends WebTestCase {
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

		$this->group = $this->makeGroup( 'sheet-group-' . uniqid(), Usergroup::PUBLIC );
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string $slug
	 * @param string $visibility
	 *
	 * @return \App\Entity\Usergroup
	 */
	private function makeGroup ( $slug, $visibility ) {
		$group = new Usergroup();
		$group->setSlug( $slug );
		$group->setName( 'Test group' );
		$group->setVisibility( $visibility );
		$group->setCreatedAt( new DateTime() );
		$group->setIsActive( TRUE );

		$this->manager->persist( $group );
		$this->manager->flush();

		return $group;
	}

	/**
	 * @param \App\Entity\Usergroup|null $group NULL pour rester dehors
	 *
	 * @return \App\Entity\User
	 */
	private function user ( Usergroup $group = NULL ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $user );

		if ( $group ) {
			$membership = new UsergroupMembership();
			$membership->setUser( $user );
			$membership->setUsergroup( $group );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
			$membership->setRole( UsergroupMembership::ROLE_USER );
			$membership->setJoinedAt( new DateTime() );
			$this->manager->persist( $membership );
			$group->addMember( $membership );
		}

		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $user
	 */
	private function logIn ( User $user ) {
		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * @param \App\Entity\User      $author
	 * @param \App\Entity\Usergroup $group
	 *
	 * @return \App\Entity\Document
	 */
	private function document ( User $author, Usergroup $group = NULL ) {
		$document = new Document();
		$document->setTitle( 'Compte rendu de mars' );
		$document->setSlug( 'doc-' . uniqid() );
		$document->setDescription( 'Le compte rendu de l’atelier pâturage.' );
		$document->setUsergroup( $group ?: $this->group );
		$document->setUser( $author );
		$document->setCreatedAt( new DateTime() );

		$this->manager->persist( $document );
		$this->manager->flush();

		return $document;
	}

	/**
	 * @param \App\Entity\Document $document
	 *
	 * @return string
	 */
	private function sheetUrl ( Document $document ) {
		return '/groups/' . $document->getUsergroup()->getSlug() . '/documents/' . $document->getId();
	}

	/**
	 * @param \App\Entity\Document $document
	 *
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	private function openSheet ( Document $document ) {
		$crawler = $this->client->request( 'GET', $this->sheetUrl( $document ) );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		return $crawler;
	}

	/**
	 * @param \App\Entity\User     $author
	 * @param \App\Entity\Document $document
	 * @param bool                 $linking
	 *
	 * @return \App\Entity\Discussion
	 */
	private function discussion ( User $author, Document $document = NULL, $linking = TRUE ) {
		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'Retour sur l’atelier' );
		$discussion->setUsergroup( $this->group );
		$discussion->setAuthor( $author );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $discussion );

		$message = new DiscussionMessage();
		$message->setDiscussion( $discussion );
		$message->setAuthor( $author );
		$message->setBody( $linking && $document
				? '<p>Voir <a href="' . $this->sheetUrl( $document ) . '">le compte rendu</a></p>'
				: '<p>Rien à signaler</p>' );
		$message->setCreatedAt( new DateTime() );
		$this->manager->persist( $message );
		$discussion->addMessage( $message );

		$this->manager->flush();

		return $discussion;
	}

	/**************************************************
	 * LA FICHE
	 **************************************************/

	public function testTheSheetShowsWhatTheDocumentIs () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );

		$this->logIn( $author );

		$text = $this->openSheet( $document )->filter( '.main__document-index' )->text();

		$this->assertStringContainsString( 'Compte rendu de mars', $text );
		$this->assertStringContainsString( 'Le compte rendu de l’atelier pâturage.', $text );
	}

	public function testTheSheetShowsTheTagsAndTheFolder () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );

		$tag = new DocumentTag();
		$tag->setName( 'Zone humide test' );
		$tag->setSlug( 'tag-' . uniqid() );
		$this->manager->persist( $tag );
		$document->addTag( $tag );

		$folder = new DocumentFolder();
		$folder->setUsergroup( $this->group );
		$folder->setTitle( 'Comptes rendus' );
		$this->manager->persist( $folder );
		$document->setFolder( $folder );

		$this->manager->flush();

		$this->logIn( $author );

		$crawler = $this->openSheet( $document );

		$this->assertStringContainsString( 'Zone humide test', $crawler->filter( '.document-sheet--tags' )->text() );
		$this->assertStringContainsString( 'Comptes rendus', $crawler->filter( '.document-sheet--facts' )->text() );
	}

	public function testADocumentWithoutAFileOffersNoDownload () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );

		$this->logIn( $author );

		$crawler = $this->openSheet( $document );

		$this->assertEquals(
				0,
				$crawler->filter( '.panel--edit a[href$="/get"]' )->count(),
				'Assert nothing offers to download a file that is not there'
		);
	}

	public function testTheSheetOfAPrivateGroupIsRefusedToOutsiders () {
		$private  = $this->makeGroup( 'private-sheet-' . uniqid(), Usergroup::PRIVATE );
		$author   = $this->user( $private );
		$document = $this->document( $author, $private );

		$this->logIn( $this->user() );

		$this->client->request( 'GET', $this->sheetUrl( $document ) );

		$this->assertEquals( 403, $this->client->getResponse()->getStatusCode() );
	}

	public function testADocumentOfAnotherGroupIsNotReachableThroughThisOne () {
		$other    = $this->makeGroup( 'other-sheet-' . uniqid(), Usergroup::PUBLIC );
		$author   = $this->user( $other );
		$document = $this->document( $author, $other );

		$this->logIn( $this->user( $this->group ) );

		$this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/documents/' . $document->getId()
		);

		$this->assertEquals(
				404,
				$this->client->getResponse()->getStatusCode(),
				'Assert the address of a group does not open the documents of another'
		);
	}

	public function testTheCreationFormIsStillReachable () {
		$this->logIn( $this->user( $this->group ) );

		$this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents/new' );

		$this->assertEquals(
				200,
				$this->client->getResponse()->getStatusCode(),
				'Assert the sheet address did not swallow « /documents/new »'
		);
	}

	/**************************************************
	 * DEPUIS LA LISTE
	 **************************************************/

	public function testTheListLeadsToTheSheet () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );

		$this->logIn( $author );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents' );

		$this->assertEquals(
				1,
				$crawler->filter( 'a.document-infos[href$="/documents/' . $document->getId() . '"]' )->count()
		);
	}

	public function testTheListStillOffersTheDownloadInOneClick () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );

		$file = new \App\Entity\File();
		$file->setName( 'compte-rendu.pdf' );
		$file->setPath( 'compte-rendu.pdf' );
		$file->setType( 'application/pdf' );
		$file->setFilesystem( 'usergroupfiles' );
		$this->manager->persist( $file );
		$document->setFile( $file );
		$this->manager->flush();

		$this->logIn( $author );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents' );

		$this->assertEquals(
				1,
				$crawler->filter( 'a.document--download' )->count(),
				'Assert going through the sheet did not cost a click to everybody'
		);
	}

	/**************************************************
	 * CE QUI EN A ÉTÉ DIT
	 **************************************************/

	public function testADiscussionLinkingToTheDocumentIsListed () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );
		$this->discussion( $author, $document );

		$this->logIn( $author );

		$this->assertStringContainsString(
				'Retour sur l’atelier',
				$this->openSheet( $document )->filter( '.document-sheet--talk' )->text()
		);
	}

	public function testADiscussionThatSaysNothingAboutItIsNotListed () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );
		$this->discussion( $author, $document, FALSE );

		$this->logIn( $author );

		$this->assertStringNotContainsString(
				'Retour sur l’atelier',
				$this->openSheet( $document )->filter( '.document-sheet--talk' )->text()
		);
	}

	public function testAnArchivedDiscussionIsNotListed () {
		$author     = $this->user( $this->group );
		$document   = $this->document( $author );
		$discussion = $this->discussion( $author, $document );

		$discussion->setArchivedAt( new DateTime() );
		$this->manager->flush();

		$this->logIn( $author );

		$this->assertStringNotContainsString(
				'Retour sur l’atelier',
				$this->openSheet( $document )->filter( '.document-sheet--talk' )->text(),
				'Assert a retired discussion does not come back through the document'
		);
	}

	public function testAPageLinkingToTheDocumentIsListed () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );

		$page = new Page();
		$page->setTitle( 'Mode d’emploi du pâturage' );
		$page->setSlug( 'page-' . uniqid() );
		$page->setUsergroup( $this->group );
		$page->setAuthor( $author );
		$page->setBody( '<p>Voir <a href="' . $this->sheetUrl( $document ) . '">le compte rendu</a></p>' );
		$page->setCreatedAt( new DateTime() );
		$this->manager->persist( $page );
		$this->manager->flush();

		$this->logIn( $author );

		$this->assertStringContainsString(
				'Mode d’emploi du pâturage',
				$this->openSheet( $document )->filter( '.document-sheet--talk' )->text()
		);
	}

	public function testASheetNobodyTalkedAboutSaysSo () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );

		$this->logIn( $author );

		$this->assertStringContainsString(
				'Personne n’en a encore parlé',
				$this->openSheet( $document )->filter( '.document-sheet--talk' )->text()
		);
	}

	/**************************************************
	 * EN DISCUTER
	 **************************************************/

	public function testTheDiscussionIsPreparedFromTheDocument () {
		$author   = $this->user( $this->group );
		$document = $this->document( $author );

		$this->logIn( $author );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/new?document=' . $document->getId()
		);

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$form = $crawler->filter( 'form[name="discussion"]' )->form();

		$this->assertStringContainsString( 'Compte rendu de mars', $form->get( 'discussion[title]' )->getValue() );
		$this->assertStringContainsString(
				'/documents/' . $document->getId(),
				$form->get( 'discussion[body]' )->getValue(),
				'Assert the link is already in the message, so the document will find the discussion back'
		);
	}

	public function testTheFormStaysEmptyWithoutADocument () {
		$this->logIn( $this->user( $this->group ) );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/discussions/new' );

		$form = $crawler->filter( 'form[name="discussion"]' )->form();

		$this->assertEmpty( $form->get( 'discussion[title]' )->getValue() );
	}

	public function testADocumentOfAnotherGroupPreparesNothing () {
		$other    = $this->makeGroup( 'other-prep-' . uniqid(), Usergroup::PUBLIC );
		$document = $this->document( $this->user( $other ), $other );

		$this->logIn( $this->user( $this->group ) );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/new?document=' . $document->getId()
		);

		$form = $crawler->filter( 'form[name="discussion"]' )->form();

		$this->assertEmpty(
				$form->get( 'discussion[title]' )->getValue(),
				'Assert a forged identifier does not name somebody else document in this group'
		);
	}
}
