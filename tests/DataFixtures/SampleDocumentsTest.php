<?php

namespace App\Tests\DataFixtures;

use App\Entity\Document;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Service\FileManager;
use App\Service\UrlManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #42 (4) — « je n'arrive pas à télécharger ou consulter les
 * documents ».
 *
 * La préproduction tourne sur les fixtures, et leurs documents n'avaient
 * aucun fichier. Les listes courtes, la recherche et le fil d'activité
 * menaient pourtant droit au fichier : une page 404 pour chaque clic, et
 * « aucun fichier n'est attaché » sur la fiche. Les fixtures déposent
 * désormais de vrais fichiers, et tout lien mène à la fiche.
 */
class SampleDocumentsTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	protected function setUp (): void {
		$this->client  = static::createClient();
		$this->manager = self::$container->get( EntityManagerInterface::class );

		$user = $this->manager->getRepository( User::class )->findOneBy( [ 'email' => 'membre@example.org' ] );

		if ( !$user ) {
			$this->markTestSkipped( 'Fixtures not loaded' );
		}

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * @param string $slug
	 *
	 * @return \App\Entity\Document[]
	 */
	private function documentsOf ( $slug ) {
		$group = $this->manager->getRepository( Usergroup::class )->findOneBy( [ 'slug' => $slug ] );

		if ( !$group ) {
			$this->markTestSkipped( 'Fixtures not loaded: ' . $slug );
		}

		return $this->manager->getRepository( Document::class )->findBy( [ 'usergroup' => $group ] );
	}

	public function testEverySampleDocumentHasAReadableFile () {
		$files = self::$container->get( FileManager::class );

		foreach ( [ 'groupe-de-test', 'communaute' ] as $slug ) {
			$documents = $this->documentsOf( $slug );

			$this->assertNotEmpty( $documents );

			foreach ( $documents as $document ) {
				$this->assertNotNull( $document->getFile(), sprintf( 'Assert « %s » has a file', $document->getTitle() ) );
				$this->assertTrue(
						$files->isAvailable( $document->getFile() ),
						sprintf( 'Assert the file of « %s » is really in the storage', $document->getTitle() )
				);
			}
		}
	}

	public function testTheReferenceDocumentIsAPdfShownInThePage () {
		$document = NULL;

		foreach ( $this->documentsOf( 'groupe-de-test' ) as $candidate ) {
			if ( $candidate->getTitle() === 'Document de test' ) {
				$document = $candidate;
			}
		}

		$this->assertNotNull( $document );

		$url     = '/groups/groupe-de-test/documents/' . $document->getId();
		$crawler = $this->client->request( 'GET', $url );

		$this->assertCount( 1, $crawler->filter( 'iframe.document-preview--frame' ) );

		$this->client->request( 'GET', $url . '/get?download=1' );

		$response = $this->client->getResponse();

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'application/pdf', $response->headers->get( 'Content-Type' ) );
	}

	public function testTheSamplesCoverTheOnlineEditor () {
		$types = [];

		foreach ( $this->documentsOf( 'communaute' ) as $document ) {
			$types[] = $document->getFile()->getType();
		}

		$this->assertContains( 'application/pdf', $types );
		$this->assertContains( 'text/csv', $types );
	}

	public function testTheGroupHomeLinksToTheSheetNotTheFile () {
		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test' );

		$links = $crawler->filter( 'a.document__in-items-list' );

		$this->assertGreaterThan( 0, $links->count() );

		foreach ( $links->extract( [ 'href' ] ) as $href ) {
			$this->assertRegExp( '#/documents/\d+$#', $href, 'Assert the short list opens the sheet' );
		}
	}

	public function testTheActivityLinkOpensTheSheet () {
		$document = $this->documentsOf( 'groupe-de-test' )[ 0 ];

		$this->assertSame(
				'/groups/groupe-de-test/documents/' . $document->getId(),
				self::$container->get( UrlManager::class )->documentUrlFromId( $document->getId() )
		);
	}
}
