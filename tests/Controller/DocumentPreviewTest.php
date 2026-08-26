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
 * Issue #43 — voir un document sans le télécharger.
 *
 * Un document n'était qu'un téléchargement : pour savoir ce qu'il y avait dans
 * un compte rendu, il fallait l'enregistrer sur son disque et l'ouvrir. Un PDF
 * s'affiche désormais dans la page.
 *
 * Trois choses sont éprouvées ici, et les deux dernières sont celles qui
 * mordent : que l'aperçu soit là, que le bouton « Télécharger » **télécharge**
 * malgré l'aperçu, et qu'un SVG ne s'affiche jamais dans notre domaine.
 *
 * Les fichiers déposés sont écrits pour de vrai dans le stockage du groupe :
 * la transaction ne les emporte pas, ils sont retirés à la main.
 */
class DocumentPreviewTest extends WebTestCase {
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
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->files   = self::$container->get( FileManager::class );

		$this->manager->getConnection()->beginTransaction();

		$this->group = new Usergroup();
		$this->group->setSlug( 'preview-group-' . uniqid() );
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

		parent::tearDown();
	}

	/**************************************************
	 * L'APERÇU
	 **************************************************/

	public function testAPdfIsShownInThePage () {
		$this->member();

		$document = $this->deposit( 'Plan de gestion ' . uniqid(), 'pdf' );

		$crawler = $this->client->request( 'GET', $this->sheet( $document ) );

		$this->assertTrue( $this->client->getResponse()->isSuccessful() );

		$frame = $crawler->filter( 'iframe.document-preview--frame' );

		$this->assertCount( 1, $frame, 'Assert the sheet embeds the document' );
		$this->assertStringContainsString(
				'/documents/' . $document->getId() . '/get',
				$frame->attr( 'src' ),
				'Assert the preview reads the file through the platform route, not from elsewhere'
		);
	}

	/**
	 * Un `.docx` posé dans un cadre ne donnerait qu'une page d'octets : mieux
	 * vaut ne rien montrer.
	 */
	public function testWhatCannotBeShownIsNotShown () {
		$this->member();

		$document = $this->deposit( 'Compte rendu ' . uniqid(), 'txt' );

		$crawler = $this->client->request( 'GET', $this->sheet( $document ) );

		$this->assertCount( 0, $crawler->filter( '.document-preview' ) );
	}

	/**
	 * L'aperçu ne contourne rien : c'est la route du fichier, donc le voteur.
	 */
	public function testThePreviewIsNoWayAroundTheVoter () {
		$this->group->setVisibility( Usergroup::PRIVATE );
		$this->manager->flush();

		$document = $this->deposit( 'Confidentiel ' . uniqid(), 'pdf', TRUE );

		// On se déconnecte en repartant d'un client vierge.
		$this->client->getCookieJar()->clear();

		$this->client->request( 'GET', $this->file( $document ) );

		$this->assertFalse(
				$this->client->getResponse()->isSuccessful(),
				'Assert an outsider gets nothing from the file route'
		);
	}

	/**************************************************
	 * LE BOUTON « TÉLÉCHARGER » TÉLÉCHARGE
	 **************************************************/

	/**
	 * Sans `?download=1`, le bouton aurait ouvert le lecteur au lieu
	 * d'enregistrer le fichier — le contraire de ce qu'il annonce.
	 */
	public function testTheDownloadButtonAsksForADownload () {
		$this->member();

		$document = $this->deposit( 'Protocole ' . uniqid(), 'pdf' );

		$crawler = $this->client->request( 'GET', $this->sheet( $document ) );

		$links = $crawler->filter( 'a[href*="/get?download=1"], a[href*="/get?download=1&"], a[href*="download=1"]' );

		$this->assertGreaterThan( 0, $links->count(), 'Assert the sheet offers a real download' );
	}

	public function testAPdfIsServedInlineAndDownloadedOnDemand () {
		$this->member();

		$document = $this->deposit( 'Note ' . uniqid(), 'pdf' );

		$this->client->request( 'GET', $this->file( $document ) );
		$this->assertStringStartsWith(
				'inline',
				(string) $this->client->getResponse()->headers->get( 'Content-Disposition' )
		);

		$this->client->request( 'GET', $this->file( $document ) . '?download=1' );
		$this->assertStringStartsWith(
				'attachment',
				(string) $this->client->getResponse()->headers->get( 'Content-Disposition' )
		);
	}

	/**************************************************
	 * CE QUI NE S'AFFICHE JAMAIS
	 **************************************************/

	/**
	 * Ouvert dans l'onglet, un SVG exécute son `<script>` dans notre origine,
	 * avec le cookie de session de celui qui l'ouvre. Il se télécharge, même
	 * sans qu'on l'ait demandé.
	 */
	public function testASvgIsNeverServedInline () {
		$this->member();

		$document = $this->deposit( 'Schéma ' . uniqid(), 'svg' );

		$this->client->request( 'GET', $this->file( $document ) );

		$this->assertStringStartsWith(
				'attachment',
				(string) $this->client->getResponse()->headers->get( 'Content-Disposition' ),
				'Assert a SVG cannot be opened in our own origin'
		);
	}

	/**
	 * Un navigateur ne doit pas deviner un type que nous avons déclaré.
	 */
	public function testEveryFileIsServedWithoutSniffing () {
		$this->member();

		$document = $this->deposit( 'Note ' . uniqid(), 'pdf' );

		$this->client->request( 'GET', $this->file( $document ) );

		$this->assertSame(
				'nosniff',
				$this->client->getResponse()->headers->get( 'X-Content-Type-Options' )
		);
	}

	/**************************************************
	 * L'ÉDITEUR EN LIGNE, ÉTEINT
	 **************************************************/

	/**
	 * `ONLYOFFICE_URL` est vide dans l'environnement de test, comme dans toute
	 * installation qui n'a pas de serveur de documents : rien ne doit renvoyer
	 * vers une page qui répondrait 404.
	 */
	public function testNoOnlineEditorIsOfferedWithoutAServer () {
		$this->member();

		$document = $this->deposit( 'Compte rendu ' . uniqid(), 'txt' );

		$crawler = $this->client->request( 'GET', $this->sheet( $document ) );

		$this->assertCount(
				0,
				$crawler->filter( 'a[href*="/office"]' ),
				'Assert nothing links to an editor that is not configured'
		);

		$this->client->request( 'GET', $this->sheet( $document ) . '/office' );

		$this->assertSame( 404, $this->client->getResponse()->getStatusCode() );
	}

	/**
	 * Les deux routes du serveur de documents sont ouvertes : ce qui les
	 * protège est le jeton, et rien d'autre.
	 */
	public function testTheDocumentServerRoutesRefuseAForgedToken () {
		// Un 403 sec, et non une redirection vers la page de connexion : le
		// serveur de documents enregistrerait ce formulaire à la place du
		// fichier.
		$this->client->request( 'GET', '/office/1.w.99999999999.deadbeef/content' );
		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );

		$this->client->request( 'POST', '/office/1.w.99999999999.deadbeef/callback', [], [], [], '{"status":2}' );
		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
	}

	/**************************************************
	 * OUTILLAGE
	 **************************************************/

	/**
	 * @param \App\Entity\Document $document
	 *
	 * @return string
	 */
	private function sheet ( Document $document ) {
		return '/groups/' . $this->group->getSlug() . '/documents/' . $document->getId();
	}

	/**
	 * @param \App\Entity\Document $document
	 *
	 * @return string
	 */
	private function file ( Document $document ) {
		return $this->sheet( $document ) . '/get';
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
	 * Dépose un document par le formulaire, seul chemin qui lui donne
	 * réellement un fichier.
	 *
	 * @param string $title
	 * @param string $kind « pdf », « svg » ou « txt »
	 * @param bool   $connect ouvrir une session avant de déposer
	 *
	 * @return \App\Entity\Document
	 */
	private function deposit ( $title, $kind, $connect = FALSE ) {
		if ( $connect ) {
			$this->member();
		}

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/documents/new' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();

		$form[ 'document[title]' ] = $title;
		$form[ 'document[filefile]' ]->upload( $this->temporaryFile( $kind ) );

		$this->client->submit( $form );

		$this->manager->clear();

		$document = $this->manager->getRepository( Document::class )->findOneBy( [ 'title' => $title ] );

		$this->assertNotNull( $document, 'Assert the deposit went through' );
		$this->assertNotNull( $document->getFile(), 'Assert the deposit attached its file' );

		// Ce que rend `getFile()` après un `clear()` est un mandataire vide :
		// on le charge tout de suite, pour que le nettoyage sache quoi effacer.
		$this->manager->initializeObject( $document->getFile() );

		$this->written[] = $document->getFile();

		return $document;
	}

	/**
	 * @param string $kind
	 *
	 * @return string
	 */
	private function temporaryFile ( $kind ) {
		$contents = [
				// Le type est deviné du contenu : l'en-tête suffit à faire un PDF.
				'pdf' => [ 'pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" ],
				'svg' => [ 'svg', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"10\" height=\"10\"></svg>\n" ],
				'txt' => [ 'txt', "Compte rendu de l'atelier.\n" ],
		];

		list( $extension, $content ) = $contents[ $kind ];

		$path = sys_get_temp_dir() . '/' . uniqid( 'document-' ) . '.' . $extension;

		file_put_contents( $path, $content );

		$this->temporary[] = $path;

		return $path;
	}
}
