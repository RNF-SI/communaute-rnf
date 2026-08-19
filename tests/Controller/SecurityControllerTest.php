<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Signing in goes through the RNF single sign-on; the form login of the
 * original platform is no longer wired into the firewall.
 */
class SecurityControllerTest extends WebTestCase {
	public function testTheLegacyLoginPageLeadsToTheRnfSignIn () {
		$client = static::createClient();

		$client->request( 'GET', '/user/login' );

		$this->assertEquals( 302, $client->getResponse()->getStatusCode() );
		$this->assertStringContainsString(
				'/auth/login',
				(string) $client->getResponse()->headers->get( 'Location' ),
				'Assert visitors are sent to the RNF sign-in'
		);
	}

	public function testTheRnfSignInIsReachableAnonymously () {
		$client = static::createClient();

		$client->request( 'GET', '/auth/login' );

		$this->assertEquals(
				200,
				$client->getResponse()->getStatusCode(),
				'Assert nobody is locked out of the page that lets them in'
		);
	}
}
