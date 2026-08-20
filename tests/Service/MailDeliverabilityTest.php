<?php

namespace App\Tests\Service;

use App\Service\MailDeliverability;
use PHPUnit\Framework\TestCase;

/**
 * Issue #14 — ce que le DNS du domaine d'envoi doit dire.
 *
 * Un jeton renseigné ne prouve rien : la plateforme peut remettre ses messages
 * à Postmark et les destinataires les mettre en quarantaine, parce que le
 * domaine ne l'a jamais autorisé. Ce contrôle lit les enregistrements et dit
 * lequel manque.
 *
 * Le résolveur est simulé : ce qui est vérifié ici est la lecture, pas la
 * capacité de PHP à interroger un serveur DNS.
 */
class MailDeliverabilityTest extends TestCase {
	/**
	 * @param array $zone name => list of records
	 *
	 * @return \App\Service\MailDeliverability
	 */
	private function service ( array $zone ) {
		return new MailDeliverability( function ( $name, $type ) use ( $zone ) {
			$key = $name . '|' . ( $type === DNS_CNAME ? 'CNAME' : 'TXT' );

			return isset( $zone[ $key ] ) ? $zone[ $key ] : [];
		} );
	}

	/**
	 * Une zone complète et correcte, dont chaque test retire une pièce.
	 *
	 * @param array $overrides
	 *
	 * @return array
	 */
	private function zone ( array $overrides = [] ) {
		$zone = [
				'rnfrance.org|TXT'              => [ [ 'txt' => 'v=spf1 include:spf.mtasv.net include:_spf.oktey.com ~all' ] ],
				'pm._domainkey.rnfrance.org|TXT' => [ [ 'txt' => 'k=rsa; p=MIGfMA0GCSq' ] ],
				'pm-bounces.rnfrance.org|CNAME'  => [ [ 'target' => 'pm.mtasv.net' ] ],
				'_dmarc.rnfrance.org|TXT'        => [ [ 'txt' => 'v=DMARC1; p=quarantine' ] ],
		];

		foreach ( $overrides as $key => $value ) {
			if ( $value === NULL ) {
				unset( $zone[ $key ] );

				continue;
			}

			$zone[ $key ] = $value;
		}

		return $zone;
	}

	/**
	 * @param array  $zone
	 * @param string $key
	 *
	 * @return array
	 */
	private function record ( array $zone, $key ) {
		foreach ( $this->service( $zone )->check( 'rnfrance.org' ) as $record ) {
			if ( $record[ 'key' ] === $key ) {
				return $record;
			}
		}

		$this->fail( sprintf( 'No "%s" record in the report', $key ) );
	}

	/**************************************************
	 * UNE ZONE COMPLÈTE
	 **************************************************/

	public function testACompleteZonePassesEverything () {
		foreach ( $this->service( $this->zone() )->check( 'rnfrance.org' ) as $record ) {
			$this->assertEquals(
					MailDeliverability::OK,
					$record[ 'status' ],
					sprintf( '%s should pass', $record[ 'label' ] )
			);
		}
	}

	public function testACompleteZoneIsReady () {
		$this->assertTrue( $this->service( $this->zone() )->isReady( 'rnfrance.org' ) );
	}

	/**************************************************
	 * SPF
	 **************************************************/

	public function testAMissingSpfFails () {
		$this->assertEquals(
				MailDeliverability::FAILED,
				$this->record( $this->zone( [ 'rnfrance.org|TXT' => NULL ] ), 'spf' )[ 'status' ]
		);
	}

	public function testAnSpfWithoutPostmarkFails () {
		$zone = $this->zone( [
				'rnfrance.org|TXT' => [ [ 'txt' => 'v=spf1 include:_spf.oktey.com ~all' ] ],
		] );

		$record = $this->record( $zone, 'spf' );

		$this->assertEquals( MailDeliverability::FAILED, $record[ 'status' ] );
		$this->assertStringContainsString(
				MailDeliverability::SPF_INCLUDE,
				$record[ 'detail' ],
				'Assert the report says what to add'
		);
	}

	public function testTwoSpfRecordsFail () {
		$zone = $this->zone( [
				'rnfrance.org|TXT' => [
						[ 'txt' => 'v=spf1 include:spf.mtasv.net ~all' ],
						[ 'txt' => 'v=spf1 include:_spf.oktey.com ~all' ],
				],
		] );

		$this->assertEquals(
				MailDeliverability::FAILED,
				$this->record( $zone, 'spf' )[ 'status' ],
				'Assert two SPF records are caught: they break SPF for the whole domain, mail included'
		);
	}

	public function testOtherTxtRecordsDoNotConfuseSpf () {
		$zone = $this->zone( [
				'rnfrance.org|TXT' => [
						[ 'txt' => 'MS=ms77693354' ],
						[ 'txt' => 'v=spf1 include:spf.mtasv.net ~all' ],
						[ 'txt' => 'brevo-code:dfc16d1feb' ],
				],
		] );

		$this->assertEquals(
				MailDeliverability::OK,
				$this->record( $zone, 'spf' )[ 'status' ],
				'Assert a domain verification record is not read as a second SPF'
		);
	}

	public function testTooManyLookupsWarn () {
		$includes = str_repeat( 'include:a.example.org ', 11 );

		$zone = $this->zone( [
				'rnfrance.org|TXT' => [ [ 'txt' => 'v=spf1 include:spf.mtasv.net ' . $includes . '~all' ] ],
		] );

		$this->assertEquals(
				MailDeliverability::WARNING,
				$this->record( $zone, 'spf' )[ 'status' ],
				'Assert going over the ten DNS lookups of SPF is noticed'
		);
	}

	/**************************************************
	 * DKIM ET RETURN-PATH
	 **************************************************/

	public function testAMissingDkimFails () {
		$record = $this->record( $this->zone( [ 'pm._domainkey.rnfrance.org|TXT' => NULL ] ), 'dkim' );

		$this->assertEquals( MailDeliverability::FAILED, $record[ 'status' ] );
		$this->assertStringContainsString( 'pm._domainkey', $record[ 'detail' ] );
	}

	public function testAMissingReturnPathOnlyWarns () {
		$this->assertEquals(
				MailDeliverability::WARNING,
				$this->record( $this->zone( [ 'pm-bounces.rnfrance.org|CNAME' => NULL ] ), 'return_path' )[ 'status' ],
				'Assert the alignment refinement is not confused with what blocks delivery'
		);
	}

	/**************************************************
	 * DMARC
	 **************************************************/

	public function testAStrictDmarcWithoutAuthenticationFails () {
		$zone = $this->zone( [ 'pm._domainkey.rnfrance.org|TXT' => NULL ] );

		$record = $this->record( $zone, 'dmarc' );

		$this->assertEquals(
				MailDeliverability::FAILED,
				$record[ 'status' ],
				'Assert asking for your own mail to be quarantined is reported as the fault it is'
		);
		$this->assertStringContainsString( 'quarantine', $record[ 'detail' ] );
	}

	public function testAStrictDmarcWithAuthenticationPasses () {
		$this->assertEquals( MailDeliverability::OK, $this->record( $this->zone(), 'dmarc' )[ 'status' ] );
	}

	public function testNoDmarcOnlyWarns () {
		$this->assertEquals(
				MailDeliverability::WARNING,
				$this->record( $this->zone( [ '_dmarc.rnfrance.org|TXT' => NULL ] ), 'dmarc' )[ 'status' ]
		);
	}

	public function testAnObservingDmarcPassesEvenWithoutAuthentication () {
		$zone = $this->zone( [
				'pm._domainkey.rnfrance.org|TXT' => NULL,
				'_dmarc.rnfrance.org|TXT'        => [ [ 'txt' => 'v=DMARC1; p=none; rua=mailto:dmarc@rnfrance.org' ] ],
		] );

		$this->assertEquals(
				MailDeliverability::OK,
				$this->record( $zone, 'dmarc' )[ 'status' ],
				'Assert an observing policy is a stage of the fix, not a fault'
		);
	}

	/**************************************************
	 * CE QUI N'EST PAS UN DOMAINE
	 **************************************************/

	public function testAnUnknownDomainIsReportedRatherThanCrashing () {
		$report = $this->service( [] )->check( '' );

		$this->assertCount( 1, $report );
		$this->assertEquals( MailDeliverability::FAILED, $report[ 0 ][ 'status' ] );
	}

	public function testAnEmptyZoneIsNotReady () {
		$this->assertFalse( $this->service( [] )->isReady( 'rnfrance.org' ) );
	}

	public function testTxtRecordsSplitInEntriesAreRead () {
		$zone = $this->zone( [
				'rnfrance.org|TXT' => [ [ 'entries' => [ 'v=spf1 include:', 'spf.mtasv.net ~all' ] ] ],
		] );

		$this->assertEquals(
				MailDeliverability::OK,
				$this->record( $zone, 'spf' )[ 'status' ],
				'Assert a long record split by the resolver is put back together'
		);
	}
}
