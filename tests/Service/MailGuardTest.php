<?php

namespace App\Tests\Service;

use App\Service\MailGuard;
use PHPUnit\Framework\TestCase;

/**
 * Issue #14 — an anonymised copy carries addresses that accept nothing.
 * Sending there produces one hard bounce per message, and a mail provider
 * suspends an account whose bounce rate climbs.
 */
class MailGuardTest extends TestCase {
	/**
	 * @return \App\Service\MailGuard
	 */
	private function inProduction () {
		return new MailGuard( 'prod' );
	}

	/**
	 * @return \App\Service\MailGuard
	 */
	private function locally () {
		return new MailGuard( 'dev' );
	}

	public function testARealAddressGoesThrough () {
		$this->assertTrue( $this->inProduction()->isDeliverable( 'jeanne@rnfrance.org' ) );
	}

	/**
	 * @dataProvider undeliverable
	 *
	 * @param string $email
	 */
	public function testAReservedAddressIsRefused ( $email ) {
		$this->assertFalse(
				$this->inProduction()->isDeliverable( $email ),
				sprintf( 'Assert %s is never handed to the mail service', $email )
		);
	}

	/**
	 * @return array
	 */
	public function undeliverable () {
		return [
				'anonymisation'   => [ 'patricia.boulay+2@example.org' ],
				'example.com'     => [ 'quelqu-un@example.com' ],
				'example.net'     => [ 'quelqu-un@example.net' ],
				'domaine de test' => [ 'admin@communaute-rnf.test' ],
				'invalid'         => [ 'admin@quelque-chose.invalid' ],
				'localhost'       => [ 'root@localhost' ],
				'réseau local'    => [ 'admin@serveur.local' ],
				'casse'           => [ 'Admin@EXAMPLE.ORG' ],
				'vide'            => [ '' ],
				'sans arobase'    => [ 'pas-une-adresse' ],
		];
	}

	public function testADomainMerelyEndingLikeAReservedOneGoesThrough () {
		$this->assertTrue(
				$this->inProduction()->isDeliverable( 'contact@mytest.fr' ),
				'Assert the check is on the suffix, not on a substring'
		);
		$this->assertTrue( $this->inProduction()->isDeliverable( 'contact@example.org.fr' ) );
	}

	public function testNothingIsRefusedOutsideProduction () {
		$this->assertTrue(
				$this->locally()->isDeliverable( 'patricia.boulay+2@example.org' ),
				'Assert a local mail catcher still receives everything'
		);
	}
}
