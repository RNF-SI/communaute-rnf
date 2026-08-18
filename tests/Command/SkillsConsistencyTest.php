<?php

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The skills offered in the profile come from three places that have to stay
 * in sync: the slugs created by import:skills, and the French and English
 * label files. A slug without a label shows up raw in the interface.
 */
class SkillsConsistencyTest extends TestCase {
	private const LOCALES = [ 'fr', 'en' ];

	/**
	 * @return string[]
	 */
	private function commandSlugs () {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Command/ImportSkillsCommand.php' );

		$this->assertNotEmpty( $source, 'Assert the import command can be read' );

		preg_match( '/\$slugs\s*=\s*\[(.*?)\];/s', $source, $matches );

		$this->assertNotEmpty( $matches, 'Assert the import command declares a $slugs array' );

		preg_match_all( "/'([^']+)'/", $matches[ 1 ], $slugs );

		return $slugs[ 1 ];
	}

	/**
	 * @param string $locale
	 *
	 * @return array
	 */
	private function labels ( $locale ) {
		return Yaml::parseFile( dirname( __DIR__, 2 ) . '/translations/skills.' . $locale . '.yml' );
	}

	public function testEverySkillHasALabelInEveryLocale () {
		$slugs = $this->commandSlugs();

		$this->assertGreaterThan( 0, count( $slugs ), 'Assert the import command declares skills' );

		foreach ( self::LOCALES as $locale ) {
			$labels = $this->labels( $locale );

			$this->assertEquals(
					[],
					array_values( array_diff( $slugs, array_keys( $labels ) ) ),
					sprintf( 'Assert every imported skill has a "%s" label', $locale )
			);
		}
	}

	public function testNoOrphanLabel () {
		$slugs = $this->commandSlugs();

		foreach ( self::LOCALES as $locale ) {
			$this->assertEquals(
					[],
					array_values( array_diff( array_keys( $this->labels( $locale ) ), $slugs ) ),
					sprintf( 'Assert no "%s" label refers to a skill that is never imported', $locale )
			);
		}
	}

	public function testNoDuplicateSlug () {
		$slugs = $this->commandSlugs();

		$this->assertEquals(
				array_values( array_unique( $slugs ) ),
				$slugs,
				'Assert the import command declares no duplicate slug'
		);
	}

	public function testLabelsAreNeverEmpty () {
		foreach ( self::LOCALES as $locale ) {
			foreach ( $this->labels( $locale ) as $slug => $label ) {
				$this->assertNotEmpty(
						trim( (string) $label ),
						sprintf( 'Assert the "%s" label of skill "%s" is not empty', $locale, $slug )
				);
			}
		}
	}
}
