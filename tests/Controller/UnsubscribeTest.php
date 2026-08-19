<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\HashGenerator;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #14 — the address every e-mail points at so a reader can stop them,
 * followed by mail clients without a session.
 */
class UnsubscribeTest extends WebTestCase {
	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Service\HashGenerator
	 */
	private $hashGenerator;

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager       = self::$container->get( EntityManagerInterface::class );
		$this->hashGenerator = self::$container->get( HashGenerator::class );

		$this->manager->getConnection()->beginTransaction();
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string $password
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $password = '' ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( $password );
		$user->setHasAgreedTermsOfUse( TRUE );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param int $id
	 *
	 * @return \App\Entity\User
	 */
	private function reload ( $id ) {
		return $this->manager->getRepository( User::class )->find( $id );
	}

	public function testFollowingTheLinkStopsTheEmails () {
		$user = $this->user();
		$id   = $user->getId();

		$this->client->request( 'GET', '/user/notifications/unsubscribe/' . $this->hashGenerator->generateUserHash( $user ) );

		$this->assertFalse( $this->reload( $id )->wantsEmails() );
	}

	public function testOneClickAnswersTheMailClient () {
		$user = $this->user();
		$id   = $user->getId();

		$this->client->request( 'POST', '/user/notifications/unsubscribe/' . $this->hashGenerator->generateUserHash( $user ) );

		$this->assertEquals(
				200,
				$this->client->getResponse()->getStatusCode(),
				'Assert a one-click unsubscribe gets an answer rather than a redirect'
		);
		$this->assertFalse( $this->reload( $id )->wantsEmails() );
	}

	public function testItWorksWithoutSigningIn () {
		$user = $this->user();

		$this->client->request( 'GET', '/user/notifications/unsubscribe/' . $this->hashGenerator->generateUserHash( $user ) );

		$this->assertStringNotContainsString(
				'login',
				(string) $this->client->getResponse()->headers->get( 'Location' ),
				'Assert the reader is not sent to a login page'
		);
	}

	public function testAForgedTokenIsRefused () {
		$user = $this->user();
		$id   = $user->getId();

		$this->client->request( 'GET', '/user/notifications/unsubscribe/' . $id . '|' . hash( 'sha256', (string) $id ) );

		$this->assertEquals( 404, $this->client->getResponse()->getStatusCode() );
		$this->assertTrue(
				$this->reload( $id )->wantsEmails(),
				'Assert an account without a password cannot be unsubscribed by guessing'
		);
	}

	public function testTheTokenOfOneAccountDoesNotWorkForAnother () {
		$first  = $this->user();
		$second = $this->user();
		$id     = $second->getId();

		$forged = $id . '|' . explode( '|', $this->hashGenerator->generateUserHash( $first ) )[ 1 ];

		$this->client->request( 'GET', '/user/notifications/unsubscribe/' . $forged );

		$this->assertEquals( 404, $this->client->getResponse()->getStatusCode() );
		$this->assertTrue( $this->reload( $id )->wantsEmails() );
	}
}
