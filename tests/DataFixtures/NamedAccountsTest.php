<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\AppFixtures;
use App\DataFixtures\NetworkContent;
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

	/**
	 * Une boîte fermée et des boîtes ouvertes. Sans les deux, on ne voit
	 * jamais disparaître le bouton « Écrire » sur une fiche.
	 */
	public function testABoxIsClosedAndTheOthersAreOpen () {
		$closed = $this->count( function ( array $profile ) {
			return array_key_exists( 'messages', $profile ) && ( $profile[ 'messages' ] === FALSE );
		} );

		$open = $this->count( function ( array $profile ) {
			return !empty( $profile ) && !array_key_exists( 'messages', $profile );
		} );

		$this->assertEquals( 1, $closed, 'Assert exactly one mailbox is closed, so the case stays recognisable' );
		$this->assertGreaterThan( 0, $open, 'Assert the default — an open mailbox — is represented too' );
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

	public function testAnAccountComesFromTheSingleSignOn () {
		$fromSso = 0;

		foreach ( $this->accounts() as $account ) {
			if ( !empty( $account[ 'rnfIdRole' ] ) ) {
				$fromSso++;
			}
		}

		$this->assertGreaterThan(
				0,
				$fromSso,
				'Assert the locked identity of #36 and the GeoNature-held reserves of #28 can be seen'
		);
		$this->assertLessThan(
				count( $this->accounts() ),
				$fromSso,
				'Assert a plain local account is seeded too, the one that still writes its own profile'
		);
	}

	/**
	 * Le contenu inventé se relit ; le faux latin se contente de remplir. Une
	 * plateforme d'essai remplie de « Aut quia rerum » permet de vérifier
	 * qu'un titre s'affiche, pas de comprendre à quoi elle sert — et une
	 * recette faite là-dessus ne ressemble à rien de ce que le réseau verra.
	 */
	public function testTheFixturesGenerateNoLoremIpsum () {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DataFixtures/AppFixtures.php' );

		foreach ( [ 'faker->sentence', 'faker->paragraphs', 'faker->words', 'faker->text' ] as $call ) {
			$this->assertStringNotContainsString(
					$call,
					$source,
					sprintf( 'Assert "%s" is not back: content comes from NetworkContent', $call )
			);
		}
	}

	/**
	 * Les catégories sont indexées par **nom de commission**, plus par un
	 * numéro d'ordre. Le jour où c'est passé de l'un à l'autre, un appel est
	 * resté en arrière : « Undefined offset: 0 », au milieu du chargement, la
	 * base à moitié remplie.
	 *
	 * Rien dans le langage ne signale ce genre d'oubli — d'où ce contrôle.
	 */
	public function testTheFixturesReadCategoriesByName () {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DataFixtures/AppFixtures.php' );

		$this->assertSame(
				0,
				preg_match( '/\$categories\s*\[\s*\d+\s*\]/', $source ),
				'Assert no leftover numeric access: the categories are keyed by commission name'
		);
	}

	public function testEveryGroupBelongsToACommissionThatExists () {
		foreach ( NetworkContent::GROUPS as $group ) {
			$this->assertArrayHasKey(
					$group[ 'commission' ],
					NetworkContent::COMMISSIONS,
					sprintf( 'Assert the commission filter finds something coherent for "%s"', $group[ 'name' ] )
			);
		}
	}

	public function testTheContentFitsInItsColumns () {
		foreach ( [ NetworkContent::PAGES, NetworkContent::ARTICLES, NetworkContent::DOCUMENTS, NetworkContent::DISCUSSIONS ] as $list ) {
			foreach ( $list as $entry ) {
				$this->assertLessThanOrEqual(
						100,
						mb_strlen( $entry[ 'title' ] ),
						sprintf( 'Assert "%s" is not silently truncated on save', $entry[ 'title' ] )
				);
			}
		}

		foreach ( NetworkContent::PRESENTATIONS as $presentation ) {
			$this->assertLessThanOrEqual( 32, mb_strlen( $presentation ) );
		}
	}

	public function testADiscussionReadsAsAConversation () {
		$withAnswers = 0;

		foreach ( NetworkContent::DISCUSSIONS as $thread ) {
			$this->assertNotEmpty( $thread[ 'messages' ], sprintf( '"%s" has nothing in it', $thread[ 'title' ] ) );

			if ( count( $thread[ 'messages' ] ) > 1 ) {
				$withAnswers++;
			}
		}

		$this->assertGreaterThan(
				count( NetworkContent::DISCUSSIONS ) / 2,
				$withAnswers,
				'Assert most threads carry answers: a wall of unanswered questions teaches nothing'
		);
	}

	public function testTheContentCyclesWithoutRunningOut () {
		foreach ( [ 0, 1, 17, 99, 1000 ] as $index ) {
			$this->assertArrayHasKey(
					'name',
					NetworkContent::pick( NetworkContent::GROUPS, $index ),
					'Assert walking past the end of a list starts over rather than failing'
			);
		}
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
