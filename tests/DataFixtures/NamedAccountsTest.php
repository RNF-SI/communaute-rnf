<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\AppFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Les comptes nommés des données de test portent chacun une situation, et
 * l'ensemble doit couvrir les cas **et leur contraire** : une fiche joignable
 * et une fiche muette, une adresse publiée et une adresse retirée. Une
 * recette qui ne voit qu'un seul côté ne prouve rien.
 *
 * Ce contrôle ne demande pas de base de données : il lit la table des comptes
 * telle qu'elle est déclarée. Ce que les fixtures en font une fois chargées
 * est vérifié à part, par SeedDataTest.
 */
class NamedAccountsTest extends TestCase {
	/**
	 * @return array
	 */
	private function accounts () {
		return ( new ReflectionClass( AppFixtures::class ) )->getConstant( 'NAMED_ACCOUNTS' );
	}

	/**
	 * @return array[]
	 */
	private function profiles () {
		return array_map( function ( array $account ) {
			return isset( $account[ 'profile' ] ) ? $account[ 'profile' ] : [];
		}, $this->accounts() );
	}

	/**
	 * @param callable $matches
	 *
	 * @return int
	 */
	private function count ( callable $matches ) {
		return count( array_filter( $this->profiles(), $matches ) );
	}

	/**
	 * @param array $profile
	 *
	 * @return bool
	 */
	private function hidesAddress ( array $profile ) {
		return array_key_exists( 'emailVisible', $profile ) && ( $profile[ 'emailVisible' ] === FALSE );
	}

	public function testEveryAccountDeclaresAProfile () {
		foreach ( $this->accounts() as $email => $account ) {
			$this->assertArrayHasKey(
					'profile',
					$account,
					sprintf( 'Assert %s says what its directory profile looks like, even if empty', $email )
			);
		}
	}

	public function testAnAccountPublishesAPhoneNumber () {
		$this->assertGreaterThan( 0, $this->count( function ( array $profile ) {
			return !empty( $profile[ 'phone' ] );
		} ), 'Assert the phone number of #27 can be seen without filling a profile first' );
	}

	public function testAnAccountKeepsItsAddressVisible () {
		$this->assertGreaterThan( 0, $this->count( function ( array $profile ) {
			return !empty( $profile ) && !$this->hidesAddress( $profile );
		} ), 'Assert the default — an address anybody can read — is represented' );
	}

	public function testAnAccountHidesItsAddress () {
		$this->assertGreaterThan( 0, $this->count( function ( array $profile ) {
			return $this->hidesAddress( $profile );
		} ), 'Assert the opt-out of #27 is represented too' );
	}

	public function testAnAccountShowsNoContactDetailAtAll () {
		$this->assertGreaterThan( 0, $this->count( function ( array $profile ) {
			return $this->hidesAddress( $profile ) && empty( $profile[ 'phone' ] );
		} ), 'Assert the silent profile exists, the one that must not read as a bug' );
	}

	public function testAnAccountShowsAPhoneButNoAddress () {
		$this->assertGreaterThan( 0, $this->count( function ( array $profile ) {
			return $this->hidesAddress( $profile ) && !empty( $profile[ 'phone' ] );
		} ), 'Assert the two halves of the choice are both represented' );
	}

	public function testAnAccountCarriesTheThreeJobFields () {
		$this->assertGreaterThan( 0, $this->count( function ( array $profile ) {
			return !empty( $profile[ 'jobTitle' ] )
				   && !empty( $profile[ 'organisation' ] )
				   && !empty( $profile[ 'reserves' ] );
		} ), 'Assert a complete directory profile of #30 is seeded' );
	}

	public function testAnAccountIsLeftBlank () {
		$this->assertGreaterThan( 0, $this->count( function ( array $profile ) {
			return $profile === [];
		} ), 'Assert an untouched profile is seeded, so its rendering can be checked' );
	}

	public function testTheTwoStatesOfTheGuidedTourAreSeeded () {
		$seen = 0;

		foreach ( $this->accounts() as $account ) {
			if ( !empty( $account[ 'tourSeen' ] ) ) {
				$seen++;
			}
		}

		$this->assertGreaterThan(
				0,
				$seen,
				'Assert an account that already watched the tour is seeded (#39)'
		);
		$this->assertLessThan(
				count( $this->accounts() ),
				$seen,
				'Assert an account that never watched it is seeded too, so the automatic launch can be tried'
		);
	}

	public function testTheJobFieldsStayWithinTheColumnWidths () {
		$widths = [ 'jobTitle' => 100, 'organisation' => 150, 'reserves' => 255, 'phone' => 30 ];

		foreach ( $this->profiles() as $profile ) {
			foreach ( $widths as $field => $width ) {
				if ( empty( $profile[ $field ] ) ) {
					continue;
				}

				$this->assertLessThanOrEqual(
						$width,
						mb_strlen( $profile[ $field ] ),
						sprintf( 'Assert the seeded "%s" is not silently truncated on save', $field )
				);
			}
		}
	}
}
