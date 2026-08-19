<?php

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #7 — being able to describe a resource document in a few words, to
 * give it some visibility.
 */
class DocumentDescriptionTest extends WebTestCase {
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
		$this->group->setSlug( 'test-group' );
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
	 * @return \App\Entity\User
	 */
	private function admin () {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER', 'ROLE_ADMIN' ] );
		$this->manager->persist( $user );

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_ADMIN );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );

		$this->manager->flush();

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );

		return $user;
	}

	/**
	 * @param \App\Entity\User $author
	 * @param string|null      $description
	 *
	 * @return \App\Entity\Document
	 */
	private function document ( User $author, $description = NULL ) {
		$document = new Document();
		$document->setTitle( 'Compte rendu' );
		$document->setSlug( 'compte-rendu-' . uniqid() );
		$document->setDescription( $description );
		$document->setUsergroup( $this->group );
		$document->setUser( $author );
		$document->setCreatedAt( new DateTime() );

		$this->manager->persist( $document );
		$this->manager->flush();

		return $document;
	}

	public function testTheFormOffersADescription () {
		$this->admin();

		$crawler = $this->client->request( 'GET', '/groups/test-group/documents/new' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals(
				1,
				$crawler->filter( '#document_description' )->count(),
				'Assert the upload form offers a description field'
		);
	}

	public function testTheDescriptionIsSaved () {
		$author   = $this->admin();
		$document = $this->document( $author );

		$crawler = $this->client->request(
				'GET',
				'/groups/test-group/documents/' . $document->getId() . '/edit'
		);

		$form = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[description]' ] = 'Compte rendu de l’atelier pâturage de mars 2026.';

		$this->client->submit( $form );

		$this->manager->clear();

		$this->assertEquals(
				'Compte rendu de l’atelier pâturage de mars 2026.',
				$this->manager->getRepository( Document::class )->find( $document->getId() )->getDescription()
		);
	}

	public function testTheDescriptionShowsInTheDocumentList () {
		$author = $this->admin();
		$this->document( $author, 'Compte rendu de l’atelier pâturage.' );

		$crawler = $this->client->request( 'GET', '/groups/test-group/documents' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertStringContainsString(
				'Compte rendu de l’atelier pâturage.',
				$crawler->filter( '.documents-list' )->text(),
				'Assert the description is visible without opening the document'
		);
	}

	public function testADocumentWithoutDescriptionRendersFine () {
		$author = $this->admin();
		$this->document( $author, NULL );

		$crawler = $this->client->request( 'GET', '/groups/test-group/documents' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals(
				0,
				$crawler->filter( '.document--description' )->count(),
				'Assert nothing empty is displayed when no description was given'
		);
	}

	public function testTheDescriptionIsOptional () {
		$author   = $this->admin();
		$document = $this->document( $author, 'Une description' );

		$crawler = $this->client->request(
				'GET',
				'/groups/test-group/documents/' . $document->getId() . '/edit'
		);

		$form = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[description]' ] = '';

		$this->client->submit( $form );

		$this->manager->clear();

		$this->assertEmpty(
				$this->manager->getRepository( Document::class )->find( $document->getId() )->getDescription(),
				'Assert a description can be removed'
		);
	}
}
