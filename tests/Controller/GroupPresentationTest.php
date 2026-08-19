<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #5 — la description et la présentation d'un groupe s'affichent pour
 * tout le monde.
 *
 * Elles n'étaient montrées qu'à ceux qui n'appartenaient pas au groupe : y
 * entrer faisait donc disparaître ce qui l'expliquait, ce qui est exactement
 * l'inverse de ce qu'on attend.
 */
class GroupPresentationTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	protected function setUp (): void {
		$this->client = static::createClient();
	}

	/**
	 * @param string $email
	 */
	private function logIn ( $email ) {
		$user = self::$container->get( EntityManagerInterface::class )
								->getRepository( User::class )
								->findOneBy( [ 'email' => $email ] );

		if ( !$user ) {
			$this->markTestSkipped( sprintf( 'Fixtures not loaded: %s', $email ) );
		}

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	public function testAMemberSeesTheDescription () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertStringContainsString(
				'visible des membres comme des non-membres',
				$crawler->filter( '.group-description' )->text(),
				'Assert belonging to a group no longer hides what it is about'
		);
	}

	public function testAMemberIsOfferedTheFullPresentation () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test' );

		$this->assertGreaterThan(
				0,
				$crawler->filter( '.group-description [data-see-more]' )->count(),
				'Assert the button opening the full presentation is offered'
		);
		$this->assertStringContainsString(
				'éprouver la plateforme à la main',
				$crawler->filter( '.group-description .wysiwyg-content' )->text()
		);
	}

	public function testSomebodyOutsideStillSeesIt () {
		$this->logIn( 'exterieur@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertStringContainsString(
				'visible des membres comme des non-membres',
				$crawler->filter( '.group-description' )->text(),
				'Assert what already worked has not been broken'
		);
	}

	public function testTheAnimatorSeesItToo () {
		$this->logIn( 'referent@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test' );

		$this->assertGreaterThan( 0, $crawler->filter( '.group-description' )->count() );
	}
}
