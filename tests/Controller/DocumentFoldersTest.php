<?php

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\DocumentFolder;
use App\Entity\File;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Service\DocumentFolderResolver;
use App\Service\FileManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #8 — des sous-dossiers, pour que le classement reste possible quand un
 * groupe accumule des documents.
 *
 * Le classement se pilote en tapant un nom de dossier ; un chemin
 * « Comptes rendus / 2026 » prolonge cette habitude plutôt que d'imposer une
 * mécanique nouvelle.
 */
class DocumentFoldersTest extends WebTestCase {
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
	 * @var \App\Service\DocumentFolderResolver
	 */
	private $resolver;

	/**
	 * @var \App\Entity\Usergroup
	 */
	private $group;

	/**
	 * @var \App\Service\FileManager
	 */
	private $files;

	/**
	 * Les fichiers écrits pendant le test, à retirer du stockage ensuite : la
	 * transaction annule les lignes, elle ne rend pas les octets déposés.
	 *
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

		$this->manager  = self::$container->get( EntityManagerInterface::class );
		$this->resolver = self::$container->get( DocumentFolderResolver::class );
		$this->files    = self::$container->get( FileManager::class );

		$this->manager->getConnection()->beginTransaction();

		$this->group = $this->manager->getRepository( Usergroup::class )
									 ->findOneBy( [ 'slug' => 'groupe-de-test' ] );

		if ( !$this->group ) {
			$this->markTestSkipped( 'Fixtures not loaded' );
		}
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

	private function logIn ( $email ) {
		$user = $this->manager->getRepository( User::class )->findOneBy( [ 'email' => $email ] );

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	public function testAPathCreatesTheFoldersItNames () {
		$folder = $this->resolver->resolve( $this->group, 'Protocoles / Forêts / PSDRF' );

		$this->assertEquals( 'PSDRF', $folder->getTitle() );
		$this->assertEquals( 'Forêts', $folder->getParent()->getTitle() );
		$this->assertEquals( 'Protocoles', $folder->getParent()->getParent()->getTitle() );
		$this->assertNull( $folder->getParent()->getParent()->getParent() );
	}

	public function testAPathReusesTheFoldersThatExist () {
		$first  = $this->resolver->resolve( $this->group, 'Protocoles / Forêts' );
		$second = $this->resolver->resolve( $this->group, 'Protocoles / Forêts' );

		$this->assertEquals( $first->getId(), $second->getId(), 'Assert no duplicate is created' );
	}

	public function testTwoBranchesMayShareAName () {
		$forets  = $this->resolver->resolve( $this->group, 'Protocoles / Suivi' );
		$oiseaux = $this->resolver->resolve( $this->group, 'Ateliers / Suivi' );

		$this->assertNotEquals(
				$forets->getId(),
				$oiseaux->getId(),
				'Assert a name only has to be unique inside its parent'
		);
	}

	public function testAnEmptyPathMeansNoFolder () {
		$this->assertNull( $this->resolver->resolve( $this->group, '' ) );
		$this->assertNull( $this->resolver->resolve( $this->group, '   /  / ' ) );
	}

	public function testSpacingAroundTheSeparatorIsIgnored () {
		$loose  = $this->resolver->resolve( $this->group, '  Protocoles/Forêts  ' );
		$spaced = $this->resolver->resolve( $this->group, 'Protocoles / Forêts' );

		$this->assertEquals( $loose->getId(), $spaced->getId() );
	}

	public function testNestingIsBounded () {
		$folder = $this->resolver->resolve( $this->group, 'a / b / c / d / e / f / g / h' );

		$this->assertLessThanOrEqual(
				4,
				$folder->getDepth(),
				'Assert a path cannot nest indefinitely'
		);
	}

	public function testAFolderKnowsItsPath () {
		$folder = $this->resolver->resolve( $this->group, 'Protocoles / Forêts / PSDRF' );

		$this->assertEquals( 'Protocoles / Forêts / PSDRF', $folder->getPath() );
	}

	public function testTheListShowsTheNesting () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/documents' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertGreaterThan(
				0,
				$crawler->filter( '.documents-folder .documents-folder' )->count(),
				'Assert a sub-folder is shown inside its parent'
		);
	}

	public function testAFolderThatOnlyHoldsSubFoldersIsKept () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/documents' );
		$text    = $crawler->filter( '.documents-folder' )->text();

		// « Comptes rendus » ne contient aucun document en propre : seulement
		// le sous-dossier « 2026 ». Il ne doit pas disparaître pour autant.
		$this->assertStringContainsString( 'Comptes rendus', $text );
		$this->assertStringContainsString( 'Compte rendu de mars', $text );
	}

	/**
	 * Un fichier est joint, et ce n'est pas un détail de montage : depuis #40
	 * un dépôt sans fichier est refusé. Le formulaire soumis à vide ne créait
	 * plus rien, et le test cherchait un document que la plateforme avait eu
	 * raison de ne pas écrire.
	 */
	public function testADocumentCanBeFiledFromTheUploadForm () {
		$this->logIn( 'referent@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/documents/new' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();

		$title = 'Document classé ' . uniqid();

		$form[ 'document[title]' ]        = $title;
		$form[ 'document[folderTitle]' ]  = 'Protocoles / Forêts';
		$form[ 'document[filefile]' ]->upload( $this->temporaryFile( 'Protocole de suivi.' ) );

		$this->client->submit( $form );

		$this->manager->clear();

		$document = $this->manager->getRepository( Document::class )->findOneBy( [ 'title' => $title ] );

		$this->assertNotNull( $document, 'Assert the deposit went through' );

		$this->remember( $document->getFile() );

		$this->assertEquals( 'Protocoles / Forêts', $document->getFolder()->getPath() );
	}

	/**
	 * @param string $content
	 *
	 * @return string chemin du fichier à envoyer
	 */
	private function temporaryFile ( $content ) {
		$path = sys_get_temp_dir() . '/' . uniqid( 'document-' ) . '.txt';

		file_put_contents( $path, $content );

		$this->temporary[] = $path;

		return $path;
	}

	/**
	 * @param \App\Entity\File|null $file
	 */
	private function remember ( File $file = NULL ) {
		if ( !$file ) {
			return;
		}

		$this->manager->initializeObject( $file );

		$this->written[] = $file;
	}
}
