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
 * Issue #41 — remplacer le fichier d'un document.
 *
 * Le formulaire de modification proposait le champ, la plateforme répondait
 * « Le document a été mis à jour », et l'ancien fichier restait : un « FALSE && »
 * venu du dépôt d'origine désactivait le remplacement depuis 2019. Une panne
 * qui affirme avoir réussi ne se voit qu'au téléchargement, des mois plus tard.
 *
 * Les fichiers déposés par ces tests sont écrits pour de vrai dans le stockage
 * du groupe : la transaction ne les emporte pas, ils sont retirés à la main.
 */
class DocumentFileReplacementTest extends WebTestCase {
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
	 * Les fichiers écrits pendant le test, à retirer du stockage ensuite.
	 *
	 * @var \App\Entity\File[]
	 */
	private $written = [];

	/**
	 * Les fichiers temporaires servant d'envoi.
	 *
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
		$this->group->setSlug( 'test-group' );
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
	 * Retenir un fichier à effacer du stockage, **chargé**.
	 *
	 * Ce que rend `getFile()` après un `clear()` est un mandataire vide : il
	 * ira chercher sa ligne au premier appel. Or c'est tout le sujet de #41
	 * que de supprimer l'ancienne ligne — si bien que le nettoyage, à la fin,
	 * réveillait un mandataire dont la ligne n'existait plus et le test
	 * finissait en `EntityNotFoundException`, après avoir pourtant vérifié ce
	 * qu'il avait à vérifier.
	 *
	 * Le charger tout de suite lui donne son chemin et son système de
	 * fichiers ; ce qu'on en fait ensuite en base ne le regarde plus.
	 *
	 * @param \App\Entity\File|null $file
	 */
	private function remember ( File $file = NULL ) {
		if ( !$file ) {
			return;
		}

		$this->manager->initializeObject( $file );

		$this->written[] = $file;
	}

	/**
	 * @param string $content
	 *
	 * @return string chemin du fichier à envoyer
	 */
	private function temporaryFile ( $content ) {
		// L'extension compte : le type est deviné du contenu, mais le nom
		// déposé est celui qui restera dans la bibliothèque du groupe.
		$path = sys_get_temp_dir() . '/' . uniqid( 'document-' ) . '.txt';

		file_put_contents( $path, $content );

		$this->temporary[] = $path;

		return $path;
	}

	/**
	 * Dépose un document par le formulaire, seul chemin qui lui donne
	 * réellement un fichier.
	 *
	 * @param string $title
	 * @param string $content
	 *
	 * @return \App\Entity\Document
	 */
	private function deposit ( $title, $content ) {
		$crawler = $this->client->request( 'GET', '/groups/test-group/documents/new' );
		$form    = $crawler->filter( 'form[name="document"]' )->form();

		$form[ 'document[title]' ] = $title;
		$form[ 'document[filefile]' ]->upload( $this->temporaryFile( $content ) );

		$this->client->submit( $form );

		$this->manager->clear();

		$document = $this->manager->getRepository( Document::class )->findOneBy( [ 'title' => $title ] );

		$this->assertNotNull( $document, 'Assert the deposit went through' );
		$this->assertNotNull( $document->getFile(), 'Assert the deposit attached its file' );

		$this->remember( $document->getFile() );

		return $document;
	}

	public function testANewFileTakesThePlaceOfTheOldOne () {
		$this->member();

		$document = $this->deposit( 'Protocole ' . uniqid(), 'Version de mars.' );
		$previous = $document->getFile();

		$crawler = $this->client->request(
				'GET',
				'/groups/test-group/documents/' . $document->getId() . '/edit'
		);

		$form = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[filefile]' ]->upload( $this->temporaryFile( 'Version de juin.' ) );

		$this->client->submit( $form );

		$this->manager->clear();

		$replaced = $this->manager->getRepository( Document::class )->find( $document->getId() );

		$this->assertNotNull( $replaced->getFile() );
		$this->assertNotEquals(
				$previous->getId(),
				$replaced->getFile()->getId(),
				'Assert the document now points at another file'
		);

		$this->remember( $replaced->getFile() );
	}

	/**
	 * L'ancien fichier n'est plus référencé par rien : le laisser dans le
	 * stockage remplirait le disque de versions que personne ne peut plus
	 * atteindre.
	 */
	public function testTheReplacedFileLeavesTheStorage () {
		$this->member();

		$document = $this->deposit( 'Plan de gestion ' . uniqid(), 'Version de mars.' );
		$previous = $document->getFile();

		$crawler = $this->client->request(
				'GET',
				'/groups/test-group/documents/' . $document->getId() . '/edit'
		);

		$form = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[filefile]' ]->upload( $this->temporaryFile( 'Version de juin.' ) );

		$this->client->submit( $form );

		$this->manager->clear();

		$replaced = $this->manager->getRepository( Document::class )->find( $document->getId() );
		$this->remember( $replaced->getFile() );

		$this->assertNull(
				$this->manager->getRepository( File::class )->find( $previous->getId() ),
				'Assert the file nobody can reach any more is not kept'
		);
	}

	/**
	 * Au dépôt, un titre laissé vide prend le nom du fichier. Au remplacement,
	 * il ne doit pas écraser celui que quelqu'un a choisi.
	 */
	public function testReplacingAFileKeepsTheChosenTitle () {
		$this->member();

		$title    = 'Compte rendu de l’atelier pâturage ' . uniqid();
		$document = $this->deposit( $title, 'Version de mars.' );

		$crawler = $this->client->request(
				'GET',
				'/groups/test-group/documents/' . $document->getId() . '/edit'
		);

		$form = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[filefile]' ]->upload( $this->temporaryFile( 'Version de juin.' ) );

		$this->client->submit( $form );

		$this->manager->clear();

		$replaced = $this->manager->getRepository( Document::class )->find( $document->getId() );
		$this->remember( $replaced->getFile() );

		$this->assertEquals( $title, $replaced->getTitle(), 'Assert the title is left alone' );
	}

	/**
	 * L'autre moitié de la règle : ne rien choisir veut dire « garde celui qui
	 * est déjà là », et surtout pas « efface-le ».
	 */
	public function testEditingWithoutAFileKeepsTheExistingOne () {
		$this->member();

		$document = $this->deposit( 'Note ' . uniqid(), 'Version de mars.' );
		$previous = $document->getFile();

		$crawler = $this->client->request(
				'GET',
				'/groups/test-group/documents/' . $document->getId() . '/edit'
		);

		$form = $crawler->filter( 'form[name="document"]' )->form();
		$form[ 'document[description]' ] = 'Relu en juin.';

		$this->client->submit( $form );

		$this->manager->clear();

		$kept = $this->manager->getRepository( Document::class )->find( $document->getId() );

		$this->assertNotNull( $kept->getFile(), 'Assert the document still has its file' );
		$this->assertEquals(
				$previous->getId(),
				$kept->getFile()->getId(),
				'Assert it is still the same one'
		);
	}
}
