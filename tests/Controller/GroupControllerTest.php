<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The groups, seen from outside and from inside.
 *
 * Relies on the accounts and reference groups built by the fixtures, see
 * docs/donnees-reelles.md.
 */
class GroupControllerTest extends WebTestCase {
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
			$this->markTestSkipped( sprintf( 'Fixtures not loaded: %s is missing', $email ) );
		}

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	public function testTheGroupsAreNotReadableAnonymously () {
		$this->client->request( 'GET', '/groups' );

		$this->assertEquals(
				302,
				$this->client->getResponse()->getStatusCode(),
				'Assert the platform stays private to visitors'
		);
	}

	public function testAMemberSeesTheGroups () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertGreaterThan(
				0,
				$crawler->filter( '.groups-list .group__teaser' )->count(),
				'Assert the list is not empty'
		);
		$this->assertStringContainsString(
				'Groupe de test',
				$crawler->filter( '.groups-list' )->text(),
				'Assert the reference group is listed'
		);
	}

	public function testAGroupPageIsReadableByItsMembers () {
		$this->logIn( 'membre@example.org' );

		$this->client->request( 'GET', '/groups/groupe-de-test' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
	}

	public function testTheDiscussionsOfAGroupAreReadableByItsMembers () {
		$this->logIn( 'membre@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/discussions' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertStringContainsString(
				'Discussion de test',
				$crawler->filter( '.discussions-list' )->text()
		);
	}

	public function testAPrivateGroupIsClosedToSomebodyOutside () {
		$this->logIn( 'exterieur@example.org' );

		$this->client->request( 'GET', '/groups/groupe-prive-de-test/discussions' );

		$this->assertContains(
				$this->client->getResponse()->getStatusCode(),
				[ 302, 403 ],
				'Assert the content of a private group is not handed to a non-member'
		);
	}

	public function testABannedMemberIsKeptOutOfAPrivateGroup () {
		$this->logIn( 'banni@example.org' );

		$this->client->request( 'GET', '/groups/groupe-prive-de-test/discussions' );

		$this->assertContains(
				$this->client->getResponse()->getStatusCode(),
				[ 302, 403 ],
				'Assert an excluded member no longer reads a private group'
		);
	}

	/**
	 * A public group stays readable by anybody signed in, banned or not: being
	 * banned takes away the right to take part, not the right to look.
	 */
	public function testABannedMemberCannotPostInAPublicGroup () {
		$this->logIn( 'banni@example.org' );

		$crawler = $this->client->request( 'GET', '/groups/groupe-de-test/discussions' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$link = $crawler->filter( '.discussions-list a' )->eq( 0 )->link()->getUri();

		$crawler = $this->client->request( 'GET', $link );

		$this->assertEquals(
				0,
				$crawler->filter( 'form[name="discussion_message"]' )->count(),
				'Assert an excluded member is not offered to answer'
		);
	}
}
