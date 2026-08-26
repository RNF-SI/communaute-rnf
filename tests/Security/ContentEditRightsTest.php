<?php

namespace App\Tests\Security;

use App\Entity\Article;
use App\Entity\Document;
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
 * Issue #33 — qui modifie le contenu d'un groupe.
 *
 * Les pages et les documents étaient en écriture collective par héritage,
 * jamais par décision : n'importe quel membre pouvait réécrire la page d'un
 * autre ou la fiche de son document. Ils suivent désormais la règle des
 * actualités, qui est déjà celle de la suppression pour les trois — l'auteur,
 * ou un animateur du groupe. Les discussions, elles, restent ouvertes.
 */
class ContentEditRightsTest extends WebTestCase {
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
		$this->group->setSlug( 'rights-group-' . uniqid() );
		$this->group->setName( 'Test group' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );
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
	 * @param bool   $isMember
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $role = UsergroupMembership::ROLE_USER, $isMember = TRUE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $user );

		if ( $isMember ) {
			$membership = new UsergroupMembership();
			$membership->setUser( $user );
			$membership->setUsergroup( $this->group );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
			$membership->setRole( $role );
			$membership->setJoinedAt( new DateTime() );
			$this->manager->persist( $membership );
			$this->group->addMember( $membership );
		}

		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\Page
	 */
	private function page ( User $author ) {
		$page = new Page();
		$page->setTitle( 'Compte rendu' );
		$page->setSlug( 'compte-rendu-' . uniqid() );
		$page->setBody( '<p>Bonjour</p>' );
		$page->setUsergroup( $this->group );
		$page->setAuthor( $author );
		$page->setCreatedAt( new DateTime() );

		$this->manager->persist( $page );
		$this->manager->flush();

		return $page;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\Document
	 */
	private function document ( User $author ) {
		$document = new Document();
		$document->setTitle( 'Compte rendu' );
		$document->setSlug( 'compte-rendu-' . uniqid() );
		$document->setUsergroup( $this->group );
		$document->setUser( $author );
		$document->setCreatedAt( new DateTime() );

		$this->manager->persist( $document );
		$this->manager->flush();

		return $document;
	}

	/**
	 * @param \App\Entity\User $author
	 *
	 * @return \App\Entity\Article
	 */
	private function article ( User $author ) {
		$article = new Article();
		$article->setTitle( 'Compte rendu' );
		$article->setSlug( 'compte-rendu-' . uniqid() );
		$article->setBody( '<p>Bonjour</p>' );
		$article->setUsergroup( $this->group );
		$article->setAuthor( $author );
		$article->setCreatedAt( new DateTime() );

		$this->manager->persist( $article );
		$this->manager->flush();

		return $article;
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
	 * @param string $url
	 *
	 * @return int
	 */
	private function statusOf ( $url ) {
		$this->client->request( 'GET', $url );

		return $this->client->getResponse()->getStatusCode();
	}

	/**
	 * @param \App\Entity\Page $page
	 *
	 * @return string
	 */
	private function pageEditUrl ( Page $page ) {
		return '/groups/' . $this->group->getSlug() . '/pages/' . $page->getSlug() . '/edit';
	}

	/**
	 * @param \App\Entity\Document $document
	 *
	 * @return string
	 */
	private function documentEditUrl ( Document $document ) {
		return '/groups/' . $this->group->getSlug() . '/documents/' . $document->getId() . '/edit';
	}

	/**
	 * @param \App\Entity\Article $article
	 *
	 * @return string
	 */
	private function articleEditUrl ( Article $article ) {
		return '/groups/' . $this->group->getSlug() . '/articles/' . $article->getSlug() . '/edit';
	}

	/**************************************************
	 * PAGES
	 **************************************************/

	public function testTheAuthorOfAPageCanEditIt () {
		$author = $this->user();

		$this->logIn( $author );

		$this->assertEquals( 200, $this->statusOf( $this->pageEditUrl( $this->page( $author ) ) ) );
	}

	public function testAnotherMemberCannotEditAPage () {
		$page = $this->page( $this->user() );

		$this->logIn( $this->user() );

		$this->assertEquals(
				403,
				$this->statusOf( $this->pageEditUrl( $page ) ),
				'Assert a page is no longer rewritable by anybody who happens to be a member'
		);
	}

	public function testAnAnimatorCanEditAnyPage () {
		$page = $this->page( $this->user() );

		$this->logIn( $this->user( UsergroupMembership::ROLE_ADMIN ) );

		$this->assertEquals(
				200,
				$this->statusOf( $this->pageEditUrl( $page ) ),
				'Assert animating a group still means being able to fix what it publishes'
		);
	}

	public function testAMemberCanStillCreateAPage () {
		$this->logIn( $this->user() );

		$this->assertEquals(
				200,
				$this->statusOf( '/groups/' . $this->group->getSlug() . '/pages/new' ),
				'Assert restricting the edition did not close the writing'
		);
	}

	/**************************************************
	 * DOCUMENTS
	 **************************************************/

	public function testWhoeverAddedADocumentCanEditIt () {
		$author = $this->user();

		$this->logIn( $author );

		$this->assertEquals( 200, $this->statusOf( $this->documentEditUrl( $this->document( $author ) ) ) );
	}

	/**
	 * Revirement assumé sur #33, demandé par le réseau : un document est un
	 * outil de travail commun, pas la pièce jointe de celui qui l'a posée. Un
	 * tableau de suivi qu'une seule personne peut modifier n'est pas un
	 * tableau de suivi. (#43)
	 */
	public function testAnyMemberCanEditADocument () {
		$document = $this->document( $this->user() );

		$this->logIn( $this->user() );

		$this->assertEquals(
				200,
				$this->statusOf( $this->documentEditUrl( $document ) ),
				'Assert a document is a shared working tool, not its author\'s attachment'
		);
	}

	/**
	 * L'autre moitié de la règle, et celle qu'il ne faut pas perdre en la
	 * relisant : **ouvrir la modification n'est pas ouvrir l'effacement.**
	 * Remplacer un fichier laisse au moins la fiche, ses étiquettes et les
	 * discussions qui y renvoient ; supprimer n'en laisse rien.
	 */
	public function testAnotherMemberStillCannotDeleteADocument () {
		$document = $this->document( $this->user() );

		$this->logIn( $this->user() );

		$this->assertEquals(
				403,
				$this->statusOf(
						'/groups/' . $this->group->getSlug() . '/documents/' . $document->getId() . '/delete'
				),
				'Assert deleting stays with the author and the animators'
		);
	}

	/**
	 * « Tout membre » veut bien dire membre : le groupe reste un groupe.
	 */
	public function testSomebodyOutsideTheGroupCannotEditADocument () {
		$document = $this->document( $this->user() );

		$this->logIn( $this->user( UsergroupMembership::ROLE_USER, FALSE ) );

		$this->assertEquals( 403, $this->statusOf( $this->documentEditUrl( $document ) ) );
	}

	public function testAnAnimatorCanEditAnyDocument () {
		$document = $this->document( $this->user() );

		$this->logIn( $this->user( UsergroupMembership::ROLE_ADMIN ) );

		$this->assertEquals( 200, $this->statusOf( $this->documentEditUrl( $document ) ) );
	}

	public function testAMemberCanStillAddADocument () {
		$this->logIn( $this->user() );

		$this->assertEquals(
				200,
				$this->statusOf( '/groups/' . $this->group->getSlug() . '/documents/new' )
		);
	}

	/**************************************************
	 * ACTUALITÉS
	 *
	 * C'est d'elles que la règle vient : les pages et les documents s'y sont
	 * rangés. Elles n'étaient pourtant contrôlées nulle part — la règle qui
	 * sert de référence était la seule à n'avoir aucun test.
	 **************************************************/

	public function testTheAuthorOfAnArticleCanEditIt () {
		$author = $this->user();

		$this->logIn( $author );

		$this->assertEquals( 200, $this->statusOf( $this->articleEditUrl( $this->article( $author ) ) ) );
	}

	public function testAnotherMemberCannotEditAnArticle () {
		$article = $this->article( $this->user() );

		$this->logIn( $this->user() );

		$this->assertEquals(
				403,
				$this->statusOf( $this->articleEditUrl( $article ) ),
				'Assert what a group publishes is not rewritable by anybody who happens to be a member'
		);
	}

	public function testAnAnimatorCanEditAnyArticle () {
		$article = $this->article( $this->user() );

		$this->logIn( $this->user( UsergroupMembership::ROLE_ADMIN ) );

		$this->assertEquals(
				200,
				$this->statusOf( $this->articleEditUrl( $article ) ),
				'Assert animating a group still means being able to fix what it announces'
		);
	}

	public function testSomebodyOutsideTheGroupCannotEditAnArticle () {
		$article = $this->article( $this->user() );

		$this->logIn( $this->user( UsergroupMembership::ROLE_USER, FALSE ) );

		$this->assertEquals(
				403,
				$this->statusOf( $this->articleEditUrl( $article ) ),
				'Assert a public group is readable by anyone, and writable by its members'
		);
	}

	public function testAMemberCanStillWriteAnArticle () {
		$this->logIn( $this->user() );

		$this->assertEquals(
				200,
				$this->statusOf( '/groups/' . $this->group->getSlug() . '/articles/new' ),
				'Assert restricting the edition did not close the writing'
		);
	}

	/**************************************************
	 * DISCUSSIONS
	 **************************************************/

	public function testDiscussionsStayOpenToEveryMember () {
		$this->logIn( $this->user() );

		$this->assertEquals(
				200,
				$this->statusOf( '/groups/' . $this->group->getSlug() . '/discussions/new' ),
				'Assert the space for exchanges was not tightened along with the animation content'
		);
	}

	/**************************************************
	 * CE QUI EN EST DIT
	 **************************************************/

	public function testTheRuleIsSpelledOutToTheMembers () {
		$this->logIn( $this->user() );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/pages' );

		$this->assertEquals(
				1,
				$crawler->filter( '.permission-note' )->count(),
				'Assert a member reads who may edit what, where the question comes up'
		);
	}

	public function testTheRuleIsNotShownToSomebodyOutsideTheGroup () {
		$this->logIn( $this->user( UsergroupMembership::ROLE_USER, FALSE ) );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/pages' );

		$this->assertEquals(
				0,
				$crawler->filter( '.permission-note' )->count(),
				'Assert somebody who has not joined is told how to join, not who edits'
		);
	}
}
