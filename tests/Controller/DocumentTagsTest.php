<?php

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\DocumentTag;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #26 — filtrer les documents par étiquette.
 *
 * Le vocabulaire est fermé et tenu par les administrateurs : à 120 personnes,
 * des étiquettes libres se dédoublent en synonymes et le filtre ne veut plus
 * rien dire au bout de quelques mois. Ce qui est vérifié ici : qui tient la
 * liste, ce qu'elle refuse, et ce que le filtre ramène.
 */
class DocumentTagsTest extends WebTestCase {
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
		$this->group->setSlug( 'tags-group-' . uniqid() );
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
	 * @param bool $siteAdmin
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $siteAdmin = FALSE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( $siteAdmin ? [ User::ROLE_USER, User::ROLE_ADMIN ] : [ User::ROLE_USER ] );
		$this->manager->persist( $user );

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_ADMIN );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$this->group->addMember( $membership );

		$this->manager->flush();

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );

		return $user;
	}

	/**
	 * @param string $name
	 *
	 * @return \App\Entity\DocumentTag
	 */
	private function tag ( $name ) {
		$tag = new DocumentTag();
		$tag->setName( $name );
		$tag->setSlug( 'tag-' . uniqid() );

		$this->manager->persist( $tag );
		$this->manager->flush();

		return $tag;
	}

	/**
	 * @param \App\Entity\User            $author
	 * @param \App\Entity\DocumentTag[]   $tags
	 *
	 * @return \App\Entity\Document
	 */
	private function document ( User $author, array $tags = [], $title = 'Compte rendu' ) {
		$document = new Document();
		$document->setTitle( $title );
		$document->setSlug( 'doc-' . uniqid() );
		$document->setUsergroup( $this->group );
		$document->setUser( $author );
		$document->setCreatedAt( new DateTime() );

		foreach ( $tags as $tag ) {
			$document->addTag( $tag );
		}

		$this->manager->persist( $document );
		$this->manager->flush();

		return $document;
	}

	/**
	 * @param string $name
	 */
	private function submitNewTag ( $name ) {
		$crawler = $this->client->request( 'GET', '/administration/document-tags' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$form = $crawler->filter( 'form[name="document_tag"]' )->form();
		$form[ 'document_tag[name]' ] = $name;

		$this->client->submit( $form );
	}

	/**
	 * @param string $name
	 *
	 * @return \App\Entity\DocumentTag|null
	 */
	private function findTag ( $name ) {
		$this->manager->clear();

		return $this->manager->getRepository( DocumentTag::class )->findOneBy( [ 'name' => $name ] );
	}

	/**************************************************
	 * LE VOCABULAIRE
	 **************************************************/

	public function testAnAdministratorAddsATag () {
		$this->user( TRUE );

		$this->submitNewTag( 'Zone humide test' );

		$this->assertNotNull( $this->findTag( 'Zone humide test' ) );
	}

	public function testTheSameTagIsNotAddedTwice () {
		$this->user( TRUE );

		$this->submitNewTag( 'Zone humide test' );
		$this->submitNewTag( 'zone humide TEST' );

		$this->manager->clear();

		$this->assertCount(
				1,
				$this->manager->getRepository( DocumentTag::class )
							  ->findBy( [ 'slug' => 'zone-humide-test' ] ),
				'Assert a closed vocabulary is exactly what prevents two spellings of one notion'
		);
	}

	public function testAnEmptyTagIsRefused () {
		$this->user( TRUE );

		$before = count( $this->manager->getRepository( DocumentTag::class )->findAll() );

		$this->submitNewTag( '   ' );

		$this->manager->clear();

		$this->assertCount( $before, $this->manager->getRepository( DocumentTag::class )->findAll() );
	}

	public function testAPlainMemberCannotReachTheVocabulary () {
		$this->user();

		$this->client->request( 'GET', '/administration/document-tags' );

		$this->assertEquals(
				403,
				$this->client->getResponse()->getStatusCode(),
				'Assert the list is kept by the administrators, not by whoever passes by'
		);
	}

	public function testRemovingATagUnclassifiesTheDocuments () {
		$admin    = $this->user( TRUE );
		$tag      = $this->tag( 'Zone humide test' );
		$document = $this->document( $admin, [ $tag ] );
		$id       = $document->getId();
		$tagId    = $tag->getId();

		$crawler = $this->client->request( 'GET', '/administration/document-tags' );
		$form    = $crawler->filter( 'form[action$="/' . $tagId . '/delete"]' )->form();

		$this->client->submit( $form );

		$this->manager->clear();

		$this->assertNull( $this->manager->getRepository( DocumentTag::class )->find( $tagId ) );
		$this->assertCount(
				0,
				$this->manager->getRepository( Document::class )->find( $id )->getTags(),
				'Assert what is no longer in the vocabulary no longer classifies anything'
		);
	}

	public function testRemovingATagNeedsATokenOfItsOwn () {
		$this->user( TRUE );
		$tag = $this->tag( 'Zone humide test' );

		$this->client->request( 'POST', '/administration/document-tags/' . $tag->getId() . '/delete', [
				'_token' => 'forgé',
		] );

		$this->assertEquals(
				403,
				$this->client->getResponse()->getStatusCode(),
				'Assert a tag cannot be dropped by a link somebody was tricked into following'
		);
	}

	/**************************************************
	 * ÉTIQUETER UN DOCUMENT
	 **************************************************/

	public function testTheDocumentFormOffersTheVocabulary () {
		$this->user( TRUE );
		$tag = $this->tag( 'Zone humide test' );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents/new' );

		$this->assertEquals(
				1,
				$crawler->filter( 'input[name="document[tags][]"][value="' . $tag->getId() . '"]' )->count()
		);
	}

	public function testTheTagsShowOnTheDocument () {
		$admin = $this->user( TRUE );
		$this->document( $admin, [ $this->tag( 'Zone humide test' ) ] );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents' );

		$this->assertStringContainsString( 'Zone humide test', $crawler->filter( '.document--tags' )->text() );
	}

	public function testADocumentWithoutTagsShowsNone () {
		$admin = $this->user( TRUE );
		$this->document( $admin );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents' );

		$this->assertEquals( 0, $crawler->filter( '.document--tags' )->count() );
	}

	/**************************************************
	 * FILTRER
	 **************************************************/

	public function testTheListFiltersByTag () {
		$admin = $this->user( TRUE );
		$cycle = $this->tag( 'Zone humide test' );

		$this->document( $admin, [ $cycle ], 'Support du cycle' );
		$this->document( $admin, [], 'Note sans étiquette' );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/documents?form[tags][]=' . $cycle->getId()
		);

		$listing = $crawler->filter( '.main__documents-index' )->text();

		$this->assertStringContainsString( 'Support du cycle', $listing );
		$this->assertStringNotContainsString(
				'Note sans étiquette',
				$listing,
				'Assert the filter actually filters'
		);
	}

	public function testWithoutAFilterEverythingIsListed () {
		$admin = $this->user( TRUE );

		$this->document( $admin, [ $this->tag( 'Zone humide test' ) ], 'Support du cycle' );
		$this->document( $admin, [], 'Note sans étiquette' );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents' );
		$listing = $crawler->filter( '.main__documents-index' )->text();

		$this->assertStringContainsString( 'Support du cycle', $listing );
		$this->assertStringContainsString( 'Note sans étiquette', $listing );
	}

	public function testADocumentAnsweringOneOfTheTagsIsKept () {
		$admin = $this->user( TRUE );
		$cycle = $this->tag( 'Zone humide test' );
		$other = $this->tag( 'Prairie test' );

		$this->document( $admin, [ $cycle ], 'Support du cycle' );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/documents'
				. '?form[tags][]=' . $cycle->getId() . '&form[tags][]=' . $other->getId()
		);

		$this->assertStringContainsString(
				'Support du cycle',
				$crawler->filter( '.main__documents-index' )->text(),
				'Assert two ticked tags mean « either », not « both »'
		);
	}
}
