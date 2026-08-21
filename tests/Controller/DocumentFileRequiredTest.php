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
 * Issue #40 — un document sans fichier ne doit pas pouvoir naître.
 *
 * Le champ était facultatif et le document enregistré avant que le fichier lui
 * soit rattaché : valider le formulaire sans rien choisir créait une entrée
 * vide, silencieusement. C'est la cause de #6, dont le correctif n'avait
 * traité que le symptôme — la page des documents qui tombait ensuite.
 *
 * À la modification, en revanche, l'absence de fichier veut dire « garde celui
 * qui est déjà là » : l'exiger empêcherait de corriger une description.
 */
class DocumentFileRequiredTest extends WebTestCase {
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
	private function member () {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
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

	public function testTheDepositFormAsksForAFile () {
		$this->member();

		$crawler = $this->client->request( 'GET', '/groups/test-group/documents/new' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals(
				1,
				$crawler->filter( '#document_filefile[required]' )->count(),
				'Assert the file is announced as mandatory when depositing'
		);
	}

	/**
	 * L'attribut HTML ne tient que dans le navigateur. Ce qui compte est que le
	 * serveur refuse, car une requête vidée par PHP n'en porte pas trace.
	 */
	public function testADepositWithoutAFileIsRefused () {
		$this->member();

		$crawler = $this->client->request( 'GET', '/groups/test-group/documents/new' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();

		$title = 'Document sans fichier ' . uniqid();

		$form[ 'document[title]' ] = $title;

		$this->client->submit( $form );

		$this->manager->clear();

		$this->assertNull(
				$this->manager->getRepository( Document::class )->findOneBy( [ 'title' => $title ] ),
				'Assert an empty document is not created'
		);
	}

	public function testTheRefusalIsExplained () {
		$this->member();

		$crawler = $this->client->request( 'GET', '/groups/test-group/documents/new' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();

		$form[ 'document[title]' ] = 'Document sans fichier ' . uniqid();

		$crawler = $this->client->submit( $form );

		$errors = $crawler->filter( '.form-row__file .form_errors' );

		$this->assertGreaterThan( 0, $errors->count(), 'Assert the form is redisplayed with its errors' );
		$this->assertNotSame(
				'',
				trim( $errors->text() ),
				'Assert the person is told why nothing was saved'
		);
	}

	public function testTheEditFormDoesNotAskForAFileAgain () {
		$author   = $this->member();
		$document = $this->document( $author );

		$crawler = $this->client->request(
				'GET',
				'/groups/test-group/documents/' . $document->getId() . '/edit'
		);

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals(
				0,
				$crawler->filter( '#document_filefile[required]' )->count(),
				'Assert an existing document keeps its file without re-uploading it'
		);
	}

	/**
	 * La régression que ce changement pourrait provoquer : ne plus pouvoir
	 * corriger une description sans redéposer le fichier.
	 */
	public function testADescriptionCanStillBeEditedWithoutAFile () {
		$author   = $this->member();
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
				$this->manager->getRepository( Document::class )->find( $document->getId() )->getDescription(),
				'Assert editing a document does not demand its file again'
		);
	}
}
