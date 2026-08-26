<?php

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Service\FileManager;
use App\Service\OnlyOffice\OnlyOfficeToken;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #43 — la page qui porte l'éditeur en ligne, serveur configuré.
 *
 * Le serveur de documents n'est pas là pendant les tests, et il n'a pas à
 * l'être : ce qui se joue ici est ce que **nous** lui remettons. C'est-à-dire
 * l'endroit exact où un droit se perd — la configuration de l'éditeur est
 * rendue dans la page, donc lue par celui qui regarde, et c'est elle qui dit
 * s'il y a de quoi enregistrer.
 *
 * `ONLYOFFICE_URL` est posée dans l'environnement du processus : les
 * paramètres `%env()%` sont résolus à l'exécution, pas à la compilation du
 * conteneur.
 */
class OnlyOfficeEditorTest extends WebTestCase {
	private const FIREWALL = 'main';
	private const SERVER   = 'https://docs.exemple.test';

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
	 * @var \App\Entity\File[]
	 */
	private $written = [];

	/**
	 * @var string[]
	 */
	private $temporary = [];

	protected function setUp (): void {
		$_ENV[ 'ONLYOFFICE_URL' ]    = self::SERVER;
		$_SERVER[ 'ONLYOFFICE_URL' ] = self::SERVER;
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->files   = self::$container->get( FileManager::class );

		$this->manager->getConnection()->beginTransaction();

		$this->group = new Usergroup();
		$this->group->setSlug( 'office-group-' . uniqid() );
		$this->group->setName( 'Test group' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );
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

		$_ENV[ 'ONLYOFFICE_URL' ]    = '';
		$_SERVER[ 'ONLYOFFICE_URL' ] = '';
		parent::tearDown();
	}

	/**
	 * Le seul appel au serveur de documents que fasse le navigateur.
	 */
	public function testThePageLoadsTheEditorFromTheConfiguredServer () {
		$this->member( UsergroupMembership::ROLE_ADMIN );

		$document = $this->deposit( 'Compte rendu ' . uniqid() );

		$crawler = $this->client->request( 'GET', $this->sheet( $document ) . '/office' );

		$this->assertTrue( $this->client->getResponse()->isSuccessful() );
		$this->assertCount(
				1,
				$crawler->filter( 'script[src="' . self::SERVER . '/web-apps/apps/api/documents/api.js"]' )
		);
	}

	/**
	 * L'invariant : une consultation n'emporte pas de quoi réécrire.
	 */
	public function testAReaderGetsNoCallbackAndNoWriteToken () {
		// Le document a été déposé par quelqu'un d'autre : ce lecteur-ci n'en
		// est ni l'auteur ni animateur du groupe.
		$reader   = $this->member( UsergroupMembership::ROLE_USER );
		$document = $this->depositAs( $this->stranger(), $reader, 'Compte rendu ' . uniqid() );

		$config = $this->config( $document );

		$this->assertArrayNotHasKey( 'callbackUrl', $config[ 'editorConfig' ] );
		$this->assertSame( 'view', $config[ 'editorConfig' ][ 'mode' ] );
		$this->assertSame( OnlyOfficeToken::READ, $this->tokenOf( $config[ 'document' ][ 'url' ] )[ 'mode' ] );
	}

	public function testAnAnimatorGetsBoth () {
		$animator = $this->member( UsergroupMembership::ROLE_ADMIN );
		$document = $this->depositAs( $this->stranger(), $animator, 'Compte rendu ' . uniqid() );

		$config = $this->config( $document );

		$this->assertArrayHasKey( 'callbackUrl', $config[ 'editorConfig' ] );
		$this->assertSame( 'edit', $config[ 'editorConfig' ][ 'mode' ] );
		$this->assertSame( OnlyOfficeToken::WRITE, $this->tokenOf( $config[ 'document' ][ 'url' ] )[ 'mode' ] );
	}

	/**
	 * Le jeton d'un lecteur ne doit rien pouvoir enregistrer, même employé sur
	 * la bonne route : c'est la seule défense de ce chemin sans session.
	 */
	public function testAReadTokenIsRefusedByTheSavingRoute () {
		$tokens = self::$container->get( OnlyOfficeToken::class );

		$this->client->request(
				'POST',
				'/office/' . $tokens->create( 1, OnlyOfficeToken::READ ) . '/callback',
				[], [], [],
				'{"status":2,"url":"https://docs.exemple.test/cache/x.docx"}'
		);

		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
	}

	/**
	 * Les statuts qui ne demandent rien — quelqu'un édite, la séance s'est
	 * fermée sans modification — se répondent sans aller chercher de fichier.
	 */
	public function testAnUneventfulCallbackIsAcknowledged () {
		$tokens = self::$container->get( OnlyOfficeToken::class );

		$this->client->request(
				'POST',
				'/office/' . $tokens->create( 1, OnlyOfficeToken::WRITE ) . '/callback',
				[], [], [],
				'{"status":1}'
		);

		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertSame( [ 'error' => 0 ], json_decode( $this->client->getResponse()->getContent(), TRUE ) );
	}

	/**
	 * Le piège de cette intégration, et il ne se voit nulle part ailleurs :
	 * OnlyOffice signe ses appels avec un entête `Authorization: Bearer`, et
	 * `RnfAuthenticatorGuard` se déclenche sur **toute** requête qui en porte
	 * un. Dans le pare-feu principal, le callback partait s'authentifier
	 * contre GeoNature et n'atteignait jamais son contrôleur — un
	 * enregistrement perdu, sans rien dans les journaux. D'où son propre
	 * pare-feu, `security: false`.
	 */
	public function testABearerHeaderDoesNotSendTheCallbackToTheSingleSignOn () {
		$tokens = self::$container->get( OnlyOfficeToken::class );

		$this->client->request(
				'POST',
				'/office/' . $tokens->create( 1, OnlyOfficeToken::WRITE ) . '/callback',
				[], [], [ 'HTTP_AUTHORIZATION' => 'Bearer un.jeton.onlyoffice' ],
				'{"status":4}'
		);

		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertSame( [ 'error' => 0 ], json_decode( $this->client->getResponse()->getContent(), TRUE ) );
	}

	/**
	 * Le serveur de documents jette sa copie sur `{"error":0}` : la donner
	 * quand rien n'a été enregistré, c'est perdre la séance.
	 */
	public function testAFailedSaveIsNotAcknowledged () {
		$this->member( UsergroupMembership::ROLE_ADMIN );

		$document = $this->deposit( 'Compte rendu ' . uniqid() );
		$tokens   = self::$container->get( OnlyOfficeToken::class );

		$this->client->request(
				'POST',
				'/office/' . $tokens->create( $document->getId(), OnlyOfficeToken::WRITE ) . '/callback',
				[], [], [],
				// Une adresse qui ne mène nulle part : il n'y a rien à enregistrer.
				'{"status":2,"url":"http://127.0.0.1:9/absent.docx","filetype":"txt"}'
		);

		$this->assertSame( 500, $this->client->getResponse()->getStatusCode() );
		$this->assertSame( 1, json_decode( $this->client->getResponse()->getContent(), TRUE )[ 'error' ] );

		// Et le document n'a pas bougé.
		$this->manager->clear();

		$unchanged = $this->manager->getRepository( Document::class )->find( $document->getId() );

		$this->assertNotNull( $unchanged->getFile() );
	}

	/**************************************************
	 * OUTILLAGE
	 **************************************************/

	/**
	 * La configuration remise à l'éditeur, relue depuis la page.
	 *
	 * @param \App\Entity\Document $document
	 *
	 * @return array
	 */
	private function config ( Document $document ) {
		$crawler = $this->client->request( 'GET', $this->sheet( $document ) . '/office' );

		$this->assertTrue( $this->client->getResponse()->isSuccessful() );

		$editor = $crawler->filter( '#onlyoffice-editor' );

		$this->assertCount( 1, $editor, 'Assert the page carries the editor' );

		return json_decode( $editor->attr( 'data-config' ), TRUE );
	}

	/**
	 * @param string $url
	 *
	 * @return array
	 */
	private function tokenOf ( $url ) {
		preg_match( '#/office/([^/]+)/content#', $url, $matches );

		$this->assertNotEmpty( $matches, 'Assert the file URL carries a token' );

		return self::$container->get( OnlyOfficeToken::class )->read( $matches[ 1 ] );
	}

	/**
	 * @param \App\Entity\Document $document
	 *
	 * @return string
	 */
	private function sheet ( Document $document ) {
		return '/groups/' . $this->group->getSlug() . '/documents/' . $document->getId();
	}

	/**
	 * @param string $role
	 *
	 * @return \App\Entity\User
	 */
	private function member ( $role = UsergroupMembership::ROLE_USER ) {
		$user = $this->stranger();

		$membership = new UsergroupMembership();
		$membership->setUser( $user );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( $role );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$this->manager->flush();

		$this->connect( $user );

		return $user;
	}

	/**
	 * @return \App\Entity\User
	 */
	private function stranger () {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $user
	 */
	private function connect ( User $user ) {
		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * @param string $title
	 *
	 * @return \App\Entity\Document
	 */
	private function deposit ( $title ) {
		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents/new' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();

		$form[ 'document[title]' ] = $title;
		$form[ 'document[filefile]' ]->upload( $this->temporaryFile() );

		$this->client->submit( $form );

		$this->manager->clear();

		$document = $this->manager->getRepository( Document::class )->findOneBy( [ 'title' => $title ] );

		$this->assertNotNull( $document, 'Assert the deposit went through' );
		$this->assertNotNull( $document->getFile(), 'Assert the deposit attached its file' );

		$this->manager->initializeObject( $document->getFile() );

		$this->written[] = $document->getFile();

		return $document;
	}

	/**
	 * Déposer sous une autre identité, puis revenir à la sienne : sans quoi le
	 * lecteur serait l'auteur du document, et l'auteur peut le modifier.
	 *
	 * @param \App\Entity\User $author
	 * @param \App\Entity\User $reader celui à qui l'on rend la main ensuite
	 * @param string           $title
	 *
	 * @return \App\Entity\Document
	 */
	private function depositAs ( User $author, User $reader, $title ) {
		$readerId = $reader->getId();

		$membership = new UsergroupMembership();
		$membership->setUser( $author );
		$membership->setUsergroup( $this->group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$this->manager->flush();

		$this->connect( $author );

		$document = $this->deposit( $title );

		$this->connect( $this->manager->getRepository( User::class )->find( $readerId ) );

		return $document;
	}

	/**
	 * Un `.txt` : c'est un format de traitement de texte pour OnlyOffice, et
	 * il se réenregistre — de quoi éprouver les deux modes.
	 *
	 * @return string
	 */
	private function temporaryFile () {
		$path = sys_get_temp_dir() . '/' . uniqid( 'document-' ) . '.txt';

		file_put_contents( $path, "Compte rendu de l'atelier.\n" );

		$this->temporary[] = $path;

		return $path;
	}
}
