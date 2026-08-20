<?php

namespace App\Tests\Command;

use App\Command\ReindexAllCommand;
use App\Command\ReindexCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

/**
 * Un index de recherche se remplit par deux chemins qui doivent s'accorder :
 *
 * - au fil de l'eau, quand une entité est enregistrée, à partir de
 *   `indexPropertyList` (config/services.yaml) ;
 * - en bloc, par `search:reindex*`, à partir d'une requête SQL écrite à la
 *   main dans les commandes.
 *
 * Si une colonne n'existe que d'un côté, rien ne casse bruyamment : la
 * recherche trouve, ou ne trouve pas, selon que l'entité a été réenregistrée
 * depuis le dernier réindexage complet. C'est le genre de panne qu'on met
 * des mois à comprendre.
 *
 * Ce contrôle exige que **tout ce que le chemin au fil de l'eau indexe soit
 * aussi ramené par la requête en bloc**. L'inverse est toléré : une requête
 * peut sélectionner une colonne de tri dont l'index n'a que faire.
 */
class SearchIndexConsistencyTest extends TestCase {
	/**
	 * Le nom d'index de chaque catégorie, tel que les commandes le
	 * connaissent : services.yaml le donne suffixé « .index ».
	 *
	 * @return array catégorie => nom d'index
	 */
	private function indexes () {
		$indexes = [];

		foreach ( $this->categories() as $category => $parameters ) {
			$indexes[ $category ] = preg_replace( '/\.index$/', '', $parameters[ 'index' ] );
		}

		return $indexes;
	}

	/**
	 * @return array
	 */
	private function categories () {
		$services = Yaml::parseFile( dirname( __DIR__, 2 ) . '/config/services.yaml' );

		foreach ( $services[ 'services' ] as $definition ) {
			// Toutes les définitions ne sont pas des tableaux : _defaults, les
			// imports de répertoire, les alias en chaîne.
			if ( is_array( $definition ) && isset( $definition[ 'arguments' ][ '$categoriesParameters' ] ) ) {
				return $definition[ 'arguments' ][ '$categoriesParameters' ];
			}
		}

		$this->fail( 'Assert the search categories are declared in services.yaml' );
	}

	/**
	 * Les deux commandes portent chacune leur copie des requêtes : elles
	 * doivent tenir la même promesse.
	 *
	 * @return string[]
	 */
	private function commands () {
		return [ ReindexAllCommand::class, ReindexCommand::class ];
	}

	/**
	 * Les colonnes ramenées par la requête de réindexage, sous le nom que
	 * l'index leur donnera — donc l'alias quand il y en a un.
	 *
	 * @param string $class
	 * @param string $index
	 *
	 * @return string[]
	 */
	private function selected ( $class, $index ) {
		$command = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();

		$method = new ReflectionMethod( $class, 'getQueryFromIndex' );
		$method->setAccessible( TRUE );

		$query = (string) $method->invoke( $command, $index );

		if ( $query === '' ) {
			return [];
		}

		if ( !preg_match( '/SELECT\s+(.*?)\s+FROM/is', $query, $matches ) ) {
			$this->fail( sprintf( 'Assert the query of "%s" can be read', $index ) );
		}

		return array_map( function ( $column ) {
			$column = trim( $column );

			// « job_title AS jobTitle » : c'est l'alias qui nomme la colonne
			// de l'index.
			if ( preg_match( '/\s+AS\s+(\S+)$/i', $column, $alias ) ) {
				return $alias[ 1 ];
			}

			return $column;
		}, explode( ',', $matches[ 1 ] ) );
	}

	public function testEveryCategoryHasAReindexQuery () {
		foreach ( $this->commands() as $class ) {
			foreach ( $this->indexes() as $category => $index ) {
				$this->assertNotEmpty(
						$this->selected( $class, $index ),
						sprintf( 'Assert "%s" can be rebuilt from scratch, not only kept up to date', $category )
				);
			}
		}
	}

	public function testTheBulkQueryBringsEverythingTheLiveIndexWrites () {
		foreach ( $this->commands() as $class ) {
			foreach ( $this->categories() as $category => $parameters ) {
				if ( empty( $parameters[ 'indexPropertyList' ] ) ) {
					continue;
				}

				$index    = preg_replace( '/\.index$/', '', $parameters[ 'index' ] );
				$selected = $this->selected( $class, $index );

				$missing = array_values( array_diff( $parameters[ 'indexPropertyList' ], $selected ) );

				$this->assertEquals(
						[],
						$missing,
						sprintf(
								'Assert a full reindex of "%s" by %s does not lose what saving an entity indexes: %s',
								$category,
								$class,
								implode( ', ', $missing )
						)
				);
			}
		}
	}

	public function testTheMemberIndexCarriesTheDirectoryFields () {
		$categories = $this->categories();

		$this->assertContains(
				'jobTitle',
				$categories[ 'membres' ][ 'indexPropertyList' ],
				'Assert somebody can be found by what they do (#30)'
		);
		$this->assertNotContains(
				'bio',
				$categories[ 'membres' ][ 'indexPropertyList' ],
				'Assert the field that left the profile also left the index'
		);
	}
}
