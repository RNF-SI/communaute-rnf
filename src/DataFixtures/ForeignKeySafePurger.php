<?php

namespace App\DataFixtures;

use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vide la base avant les fixtures sans buter sur les fichiers.
 *
 * `File` pointe vers son auteur et son groupe, et un groupe comme un compte
 * pointent à leur tour vers des fichiers (logo, couverture, avatar) : il n'y a
 * pas d'ordre de suppression qui satisfasse toutes les clés étrangères, et le
 * purgeur de Doctrine en choisit un au hasard du graphe. Tant que les fixtures
 * ne créaient aucun fichier, cela ne se voyait que sur une copie de la
 * production ; depuis qu'elles en déposent (#42), le deuxième chargement
 * échouait sur `FK_…` des fichiers.
 *
 * Les contrôles sont suspendus le temps de la purge, et seulement sur MySQL :
 * une fois tout vidé, aucune ligne ne peut plus rien violer.
 */
class ForeignKeySafePurger extends ORMPurger {
	private $manager;

	public function __construct ( EntityManagerInterface $manager, array $excluded = [] ) {
		parent::__construct( $manager, $excluded );

		$this->manager = $manager;
	}

	public function purge () {
		$connection = $this->manager->getConnection();
		$mysql      = $connection->getDatabasePlatform()->getName() === 'mysql';

		if ( $mysql ) {
			$connection->executeStatement( 'SET FOREIGN_KEY_CHECKS = 0' );
		}

		try {
			parent::purge();
		}
		finally {
			if ( $mysql ) {
				$connection->executeStatement( 'SET FOREIGN_KEY_CHECKS = 1' );
			}
		}
	}
}
