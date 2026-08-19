<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #11 — what an author writes must look the same once published.
 *
 * Quill does not nest its lists: a second-level bullet stays a <li> of the same
 * list, marked with a ql-indent-N class. Its own stylesheet only styles those
 * classes inside .ql-editor, so the rendered content has to carry the
 * wysiwyg-content class for our own rules to apply.
 */
class WysiwygRenderingTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	protected function setUp (): void {
		$this->client = static::createClient();
	}

	private function logIn ( $email ) {
		$user = self::$container->get( EntityManagerInterface::class )
								->getRepository( User::class )
								->findOneBy( [ 'email' => $email ] );

		if ( !$user ) {
			$this->markTestSkipped( sprintf( 'Fixtures not loaded: %s is missing', $email ) );
		}

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	public function testADiscussionMessageIsRenderedAsFormattedContent () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/discussions' );
		$link    = $crawler->filter( '.discussions-list a' )->eq( 0 )->link()->getUri();

		$crawler = $this->client->request( 'GET', $link );

		$this->assertGreaterThan(
				0,
				$crawler->filter( '.message--body.wysiwyg-content' )->count(),
				'Assert a message body carries the class its formatting depends on'
		);
	}

	public function testAPageIsRenderedAsFormattedContent () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/pages/page-de-test-groupe-de-test' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertGreaterThan(
				0,
				$crawler->filter( '.page-body.wysiwyg-content' )->count()
		);
	}
}
