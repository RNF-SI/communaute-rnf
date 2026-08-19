<?php

namespace App\Tests\Command;

use App\DataFixtures\AppFixtures;
use App\Service\SlugGenerator;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

/**
 * Charger les fixtures vide la base avant de la remplir. En production, ce
 * serait la perte de tout : comptes, groupes, discussions, documents.
 *
 * Une préproduction tournant elle aussi en environnement « prod »,
 * l'environnement seul ne suffit pas à distinguer les deux — d'où
 * l'autorisation explicite.
 */
class FixturesGuardTest extends TestCase {
	/**
	 * @param string $environment
	 * @param string $allowFixtures
	 *
	 * @return \App\DataFixtures\AppFixtures
	 */
	private function fixtures ( $environment, $allowFixtures = '' ) {
		return new AppFixtures(
				$this->createMock( UserPasswordEncoderInterface::class ),
				$this->createMock( SlugGenerator::class ),
				'',
				'communaute',
				$environment,
				$allowFixtures
		);
	}

	/**
	 * @param \App\DataFixtures\AppFixtures $fixtures
	 *
	 * @return string|null le message du refus, NULL si rien n'a été refusé
	 */
	private function refusal ( AppFixtures $fixtures ) {
		try {
			$fixtures->load( $this->createMock( ObjectManager::class ) );
		}
		catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		catch ( \Throwable $e ) {
			// Le garde-fou a laissé passer : la suite échoue sur les doublures,
			// ce qui n'est pas notre affaire ici.
			return NULL;
		}

		return NULL;
	}

	public function testProductionIsRefused () {
		$message = $this->refusal( $this->fixtures( 'prod' ) );

		$this->assertNotNull( $message, 'Assert loading fixtures on production is refused' );
		$this->assertStringContainsString( 'VIDENT la base', $message );
		$this->assertStringContainsString( 'ALLOW_FIXTURES', $message, 'Assert the message says how to allow it' );
	}

	/**
	 * @dataProvider allowedValues
	 *
	 * @param string $value
	 */
	public function testAnExplicitAuthorisationLetsItThrough ( $value ) {
		$this->assertNull(
				$this->refusal( $this->fixtures( 'prod', $value ) ),
				sprintf( 'Assert "%s" is understood as an authorisation', $value )
		);
	}

	/**
	 * @return array
	 */
	public function allowedValues () {
		return [ [ '1' ], [ 'true' ], [ 'yes' ], [ 'on' ] ];
	}

	/**
	 * @dataProvider refusedValues
	 *
	 * @param string $value
	 */
	public function testAnythingElseIsRefused ( $value ) {
		$this->assertNotNull(
				$this->refusal( $this->fixtures( 'prod', $value ) ),
				sprintf( 'Assert "%s" does not open the door', $value )
		);
	}

	/**
	 * @return array
	 */
	public function refusedValues () {
		return [ 'vide' => [ '' ], 'zéro' => [ '0' ], 'faux' => [ 'false' ], 'texte' => [ 'peut-être' ] ];
	}

	/**
	 * @dataProvider ordinaryEnvironments
	 *
	 * @param string $environment
	 */
	public function testDevelopmentAndTestAreNeverBlocked ( $environment ) {
		$this->assertNull(
				$this->refusal( $this->fixtures( $environment ) ),
				'Assert the guard does not get in the way where fixtures belong'
		);
	}

	/**
	 * @return array
	 */
	public function ordinaryEnvironments () {
		return [ [ 'dev' ], [ 'test' ] ];
	}
}
