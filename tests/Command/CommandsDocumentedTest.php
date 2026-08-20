<?php

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * Une commande non documentée est une commande que personne ne lancera.
 *
 * Le constat qui a motivé ce contrôle : treize commandes existaient, la page
 * de référence n'en citait que sept, et le README en documentait deux qui
 * n'existaient plus. Chacune était pourtant mentionnée quelque part — au
 * milieu d'une note de déploiement, dans un commentaire d'issue — c'est-à-dire
 * nulle part où on la chercherait.
 */
class CommandsDocumentedTest extends TestCase {
	/**
	 * @return string
	 */
	private function root () {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Les commandes déclarées, lues dans les sources plutôt que dans un
	 * conteneur : ce contrôle doit tourner sans démarrer l'application.
	 *
	 * @return string[]
	 */
	private function declared () {
		$names = [];

		foreach ( glob( $this->root() . '/src/Command/*.php' ) as $file ) {
			if ( preg_match( '/\$defaultName\s*=\s*\'([^\']+)\'/', (string) file_get_contents( $file ), $found ) ) {
				$names[] = $found[ 1 ];
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * @return string
	 */
	private function reference () {
		return (string) file_get_contents( $this->root() . '/docs/commandes.md' );
	}

	public function testThereAreCommandsToDocument () {
		$this->assertNotEmpty( $this->declared(), 'Assert the commands can be found at all' );
	}

	public function testEveryCommandIsInTheReference () {
		$reference = $this->reference();

		foreach ( $this->declared() as $name ) {
			$this->assertStringContainsString(
					$name,
					$reference,
					sprintf( 'Assert docs/commandes.md mentions "%s"', $name )
			);
		}
	}

	public function testEveryCommandHasASectionOfItsOwn () {
		$reference = $this->reference();

		foreach ( $this->declared() as $name ) {
			$this->assertStringContainsString(
					'`' . $name . '`',
					$reference,
					sprintf( 'Assert "%s" has a heading, not only a passing mention', $name )
			);
		}
	}

	public function testTheReferenceInventsNoCommand () {
		$declared = $this->declared();

		// Les titres de la page : « ### `app:mail:check` », éventuellement
		// deux commandes jumelles séparées par une barre oblique.
		preg_match_all( '/^### `([^`]+)`(?: \/ `([^`]+)`)?/m', $this->reference(), $found );

		$documented = array_filter( array_merge( $found[ 1 ], $found[ 2 ] ) );

		foreach ( $documented as $name ) {
			$this->assertContains(
					$name,
					$declared,
					sprintf( 'Assert "%s" still exists — the README once documented two commands that did not', $name )
			);
		}
	}

	public function testTheReadmeDocumentsNoGhostCommand () {
		$readme   = (string) file_get_contents( $this->root() . '/README.md' );
		$declared = $this->declared();

		preg_match_all( '/bin\/console ((?:app|user|search|import):[a-z:-]+)/', $readme, $found );

		foreach ( array_unique( $found[ 1 ] ) as $name ) {
			$this->assertContains(
					$name,
					$declared,
					sprintf( 'Assert the README does not send anybody towards "%s", which does not exist', $name )
			);
		}
	}
}
