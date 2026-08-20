<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\RnfReserves;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Issue #28 — lire les réserves d'un compte dans l'export GeoNature.
 *
 * Le schéma de la vue n'est pas publié et l'export répond 403 sans jeton : ce
 * service est donc écrit pour survivre à ce qu'il ne sait pas. Ce test tient
 * cette promesse — un nom de colonne qui change, une API muette, une API
 * indisponible : dans aucun de ces cas un annuaire ne doit se vider.
 */
class RnfReservesTest extends TestCase {
	/**
	 * @param array  $payload
	 * @param int    $status
	 * @param string $token
	 *
	 * @return \App\Service\RnfReserves
	 */
	private function service ( array $payload = [], $status = 200, $token = 'jeton' ) {
		$client = new MockHttpClient( new MockResponse(
				json_encode( $payload ),
				[ 'http_code' => $status, 'response_headers' => [ 'content-type' => 'application/json' ] ]
		) );

		return new RnfReserves( $client, new NullLogger(), 'https://geonature.example.org', $token, 3 );
	}

	/**
	 * @param int|null $roleId
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $roleId = 4242 ) {
		$user = new User();
		$user->setName( 'Jeanne Reserve' );
		$user->setRnfIdRole( $roleId );

		$property = new ReflectionProperty( User::class, 'id' );
		$property->setAccessible( TRUE );
		$property->setValue( $user, 1 );

		return $user;
	}

	/**
	 * @param array $rows
	 *
	 * @return array
	 */
	private function payload ( array $rows ) {
		return [ 'items' => $rows, 'total' => count( $rows ), 'limit' => 100, 'page' => 0 ];
	}

	/**************************************************
	 * SANS JETON
	 **************************************************/

	public function testWithoutATokenTheServiceStaysSilent () {
		$service = $this->service( [], 200, '' );

		$this->assertFalse( $service->isConfigured() );
		$this->assertNull(
				$service->forUser( $this->user() ),
				'Assert an unconfigured export leaves the field filled in by hand'
		);
	}

	public function testWithATokenTheServiceIsConfigured () {
		$this->assertTrue( $this->service()->isConfigured() );
	}

	/**************************************************
	 * QUI TIENT LE CHAMP
	 **************************************************/

	public function testWithoutATokenTheFieldStaysHandWritten () {
		$this->assertFalse(
				$this->service( [], 200, '' )->feedsProfileOf( $this->user() ),
				'Assert nobody is locked out of a field nothing else fills'
		);
	}

	public function testWithATokenGeoNatureHoldsTheField () {
		$this->assertTrue( $this->service()->feedsProfileOf( $this->user() ) );
	}

	public function testALocalAccountKeepsItsField () {
		$this->assertFalse(
				$this->service()->feedsProfileOf( $this->user( NULL ) ),
				'Assert an account that does not come from the single sign-on keeps writing its own'
		);
	}

	public function testNobodyIsNotLockedOut () {
		$this->assertFalse( $this->service()->feedsProfileOf( NULL ) );
	}

	/**************************************************
	 * CE QUE L'EXPORT RÉPOND
	 **************************************************/

	public function testTheReservesAreRead () {
		$service = $this->service( $this->payload( [
				[ 'role_id' => 4242, 'rn_id' => 'FR001', 'rn_nom' => 'RN du Marais' ],
				[ 'role_id' => 4242, 'rn_id' => 'FR002', 'rn_nom' => 'RN de la Bassée' ],
		] ) );

		$this->assertEquals( 'RN de la Bassée, RN du Marais', $service->forUser( $this->user() ) );
	}

	public function testTheSameReserveIsReadOnce () {
		$service = $this->service( $this->payload( [
				[ 'rn_nom' => 'RN du Marais' ],
				[ 'rn_nom' => 'RN du Marais' ],
		] ) );

		$this->assertEquals(
				'RN du Marais',
				$service->forUser( $this->user() ),
				'Assert two links to one reserve do not read as two reserves'
		);
	}

	public function testAnotherColumnNameStillWorks () {
		$service = $this->service( $this->payload( [ [ 'nom' => 'RN du Marais' ] ] ) );

		$this->assertEquals(
				'RN du Marais',
				$service->forUser( $this->user() ),
				'Assert a renamed column on the GeoNature side does not silently empty the profiles'
		);
	}

	public function testWithoutANameTheIdentifierIsShown () {
		$service = $this->service( $this->payload( [ [ 'role_id' => 4242, 'rn_id' => 'FR001' ] ] ) );

		$this->assertEquals(
				'FR001',
				$service->forUser( $this->user() ),
				'Assert a code is more useful than nothing, and noticeable'
		);
	}

	public function testARowThatNamesNothingIsIgnored () {
		$service = $this->service( $this->payload( [ [ 'role_id' => 4242 ], [ 'rn_nom' => 'RN du Marais' ] ] ) );

		$this->assertEquals( 'RN du Marais', $service->forUser( $this->user() ) );
	}

	public function testAnEmptyAnswerSaysNothingRatherThanNothingAtAll () {
		$service = $this->service( $this->payload( [] ) );

		$this->assertNull(
				$service->forUser( $this->user() ),
				'Assert « GeoNature knows nothing » is told apart from « GeoNature says: no reserve »'
		);
	}

	/**************************************************
	 * QUAND ÇA SE PASSE MAL
	 **************************************************/

	public function testARefusedExportDoesNotEmptyTheProfile () {
		$service = $this->service( [], 403 );

		$this->assertNull( $service->forUser( $this->user() ) );
	}

	public function testAServerErrorDoesNotEmptyTheProfile () {
		$service = $this->service( [], 500 );

		$this->assertNull(
				$service->forUser( $this->user() ),
				'Assert a directory does not empty itself because an API is down'
		);
	}

	public function testAnAccountWithoutAGeoNatureIdentityIsLeftAlone () {
		$service = $this->service( $this->payload( [ [ 'rn_nom' => 'RN du Marais' ] ] ) );

		$this->assertNull( $service->forUser( $this->user( NULL ) ) );
	}

	public function testFetchingWithoutATokenIsRefusedLoudly () {
		$this->expectException( \RuntimeException::class );

		$this->service( [], 200, '' )->fetch( 4242 );
	}

	/**************************************************
	 * LA MISE EN FORME
	 **************************************************/

	public function testALongListIsCutAtAWholeReserve () {
		$names = [];

		for ( $i = 0; $i < 40; $i++ ) {
			$names[] = [ 'rn_nom' => sprintf( 'RN de la Vallée numéro %02d', $i ) ];
		}

		$formatted = $this->service()->format( $names );

		$this->assertLessThanOrEqual( 255, mb_strlen( $formatted ) );
		$this->assertStringEndsWith( ')', $formatted, 'Assert what is missing is announced' );
		$this->assertStringContainsString( '(+', $formatted );
	}

	public function testAShortListIsLeftWhole () {
		$formatted = $this->service()->format( [ [ 'rn_nom' => 'RN du Marais' ] ] );

		$this->assertEquals( 'RN du Marais', $formatted );
	}

	public function testTheOrderDoesNotDependOnTheApi () {
		$first  = $this->service()->format( [ [ 'rn_nom' => 'RN du Marais' ], [ 'rn_nom' => 'RN de la Bassée' ] ] );
		$second = $this->service()->format( [ [ 'rn_nom' => 'RN de la Bassée' ], [ 'rn_nom' => 'RN du Marais' ] ] );

		$this->assertEquals(
				$first,
				$second,
				'Assert the profile does not appear to change because the export answered in another order'
		);
	}

	public function testSomethingThatIsNotARowIsIgnored () {
		$this->assertNull( $this->service()->format( [ 'pas un tableau', 42 ] ) );
	}
}
