<?php

namespace App\Tests\Controller;

use App\Entity\Page;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Service\Tagging\TagParser;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #42 (3) — archiver une page qui porte sur un format ou un projet qui
 * n'existe plus, plutôt que la supprimer.
 *
 * Archivée, elle reste lisible à son adresse, avec un bandeau qui le dit ;
 * elle quitte la liste des pages, l'accueil du groupe et les suggestions de
 * tags, et se range dans une section repliée d'où on la ressort.
 */
class PageArchiveTest extends WebTestCase {
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
		$this->group->setSlug( 'archive-group-' . uniqid() );
		$this->group->setName( 'Test group' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );
		$this->manager->flush();
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string $role
	 *
	 * @return \App\Entity\User
	 */
	private function member ( $role = UsergroupMembership::ROLE_USER ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Membre ' . uniqid() );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $user );

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( $role );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$this->group->addMember( $membership );

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
	 * @param \App\Entity\User $author
	 * @param string           $title
	 *
	 * @return \App\Entity\Page
	 */
	private function page ( User $author, $title ) {
		$page = new Page();
		$page->setTitle( $title );
		$page->setSlug( 'page-' . uniqid() );
		$page->setBody( '<p>Corps.</p>' );
		$page->setAuthor( $author );
		$page->setUsergroup( $this->group );
		$page->setCreatedAt( new DateTime() );
		$this->manager->persist( $page );
		$this->group->addPage( $page );
		$this->manager->flush();

		return $page;
	}

	/**
	 * @param \App\Entity\Page $page
	 *
	 * @return string
	 */
	private function url ( Page $page ) {
		return '/groups/' . $this->group->getSlug() . '/pages/' . $page->getSlug();
	}

	/**
	 * Archive (ou ressort) par le bouton de la page, comme le ferait un clic.
	 *
	 * @param \App\Entity\Page $page
	 */
	private function toggle ( Page $page ) {
		$crawler = $this->client->request( 'GET', $this->url( $page ) );

		$this->client->submit( $crawler->filter( 'form.page-archive-form' )->form() );
	}

	/**
	 * @param \App\Entity\Page $page
	 *
	 * @return \App\Entity\Page
	 */
	private function reload ( Page $page ) {
		$this->manager->clear();

		return $this->manager->getRepository( Page::class )->find( $page->getId() );
	}

	public function testTheAuthorArchivesAndThePageStaysReadable () {
		$author = $this->member();
		$page   = $this->page( $author, 'Ancien format ' . uniqid() );

		$this->logIn( $author );
		$this->toggle( $page );

		$this->assertTrue( $this->reload( $page )->isArchived() );

		$crawler = $this->client->request( 'GET', $this->url( $page ) );

		$this->assertTrue( $this->client->getResponse()->isSuccessful(), 'Assert an archived page is still readable' );
		$this->assertCount( 1, $crawler->filter( '.page-archived' ), 'Assert the page says it is archived' );
	}

	public function testAnArchivedPageLeavesTheListForTheArchives () {
		$author  = $this->member();
		$current = $this->page( $author, 'Page en cours ' . uniqid() );
		$old     = $this->page( $author, 'Page archivée ' . uniqid() );

		$old->setArchivedAt( new DateTime() );
		$this->manager->flush();

		$this->logIn( $author );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/pages' );

		$this->assertStringContainsString( $current->getTitle(), $crawler->filter( '.pages-list' )->text() );
		$this->assertStringNotContainsString( $old->getTitle(), $crawler->filter( '.pages-list' )->text() );
		$this->assertStringContainsString( $old->getTitle(), $crawler->filter( 'details.pages-archived' )->text() );
		$this->assertNull( $crawler->filter( 'details.pages-archived' )->attr( 'open' ), 'Assert the archives are folded' );

		// L'accueil du groupe ne la montre plus, ni ne la compte.
		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() );

		$this->assertStringNotContainsString( $old->getTitle(), $crawler->filter( '.group-app__pages' )->text() );
		$this->assertSame( '1', trim( $crawler->filter( '.group-app__pages .count' )->text() ) );
	}

	public function testItComesBackOut () {
		$author = $this->member();
		$page   = $this->page( $author, 'Revenue ' . uniqid() );

		$page->setArchivedAt( new DateTime() );
		$this->manager->flush();

		$this->logIn( $author );
		$this->toggle( $page );

		$this->assertFalse( $this->reload( $page )->isArchived() );
	}

	/**
	 * Le droit de la modifier, pas plus : l'auteur et les animateurs.
	 */
	public function testAnotherMemberCannotArchiveIt () {
		$author = $this->member();
		$page   = $this->page( $author, 'Pas à toi ' . uniqid() );

		$this->logIn( $this->member() );

		$crawler = $this->client->request( 'GET', $this->url( $page ) );
		$this->assertCount( 0, $crawler->filter( 'form.page-archive-form' ), 'Assert the button is not offered' );

		$this->client->request( 'POST', $this->url( $page ) . '/archive', [ 'archive' => 1 ] );

		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
		$this->assertFalse( $this->reload( $page )->isArchived() );
	}

	public function testAnAnimatorCanArchiveIt () {
		$page = $this->page( $this->member(), 'Animateur ' . uniqid() );

		$this->logIn( $this->member( UsergroupMembership::ROLE_ADMIN ) );
		$this->toggle( $page );

		$this->assertTrue( $this->reload( $page )->isArchived() );
	}

	public function testAForgedRequestIsRefused () {
		$author = $this->member();
		$page   = $this->page( $author, 'Jeton ' . uniqid() );

		$this->logIn( $author );
		$this->client->request( 'POST', $this->url( $page ) . '/archive', [ 'archive' => 1, '_token' => 'nope' ] );

		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
		$this->assertFalse( $this->reload( $page )->isArchived() );
	}

	public function testAnArchivedPageIsNoLongerSuggestedAsATag () {
		$author = $this->member();
		$needle = 'Archivetag' . substr( uniqid(), -6 );
		$page   = $this->page( $author, $needle . ' ancien' );

		$this->logIn( $author );

		$labels = function () use ( $needle ) {
			$this->client->request( 'GET', '/messages/suggestions?prefix=%23&q=' . $needle );

			return array_column(
					json_decode( $this->client->getResponse()->getContent(), TRUE )[ 'suggestions' ],
					'label'
			);
		};

		$this->assertContains( $page->getTitle(), $labels(), 'Assert the page is suggested while current' );

		$page = $this->reload( $page );
		$page->setArchivedAt( new DateTime() );
		$this->manager->flush();

		$this->assertNotContains( $page->getTitle(), $labels() );
	}

	/**
	 * Proposer, non ; retrouver, oui. Un message envoyé avant l'archivage
	 * garde son lien : la page reste lisible à son adresse.
	 */
	public function testATagAlreadyWrittenStillLeadsToIt () {
		$author = $this->member();
		$page   = $this->page( $author, 'Tagarchive' . substr( uniqid(), -6 ) );

		$page->setArchivedAt( new DateTime() );
		$this->manager->flush();

		self::$container->get( 'security.token_storage' )->setToken(
				new UsernamePasswordToken( $author, NULL, self::FIREWALL, $author->getRoles() )
		);

		$html = self::$container->get( TagParser::class )->render( 'Voir #' . $page->getTitle() );

		$this->assertStringContainsString( 'href="' . $this->url( $page ) . '"', $html );
	}
}
