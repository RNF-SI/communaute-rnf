<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:preflight` dit ce qui échouera silencieusement sur un serveur.
 *
 * Elle n'avait aucune épreuve, alors qu'elle est la première commande lancée
 * après un déploiement et la dernière chose qu'on regarde avant de déclarer
 * que tout va bien. Une vérification qui se tromperait — ou qui disparaîtrait
 * d'une refonte — ne se verrait qu'au moment où la panne qu'elle devait
 * annoncer se produit.
 */
class PreflightTest extends KernelTestCase {
	/**
	 * @var \Symfony\Component\Console\Tester\CommandTester
	 */
	private $command;

	protected function setUp (): void {
		self::bootKernel();

		$this->command = new CommandTester(
				( new Application( self::$kernel ) )->find( 'app:preflight' )
		);
	}

	/**
	 * @return string ce qu'elle a écrit, sur une seule ligne
	 */
	private function display () {
		return trim( preg_replace( '/\s+/u', ' ', $this->command->getDisplay() ) );
	}

	public function testItRunsAndSaysWhatItLookedAt () {
		$this->command->execute( [] );

		$display = $this->display();

		foreach ( [
				'Connexion base de données',
				'Migrations',
				'Écriture des index de recherche',
				'Écriture des sessions',
				'Durée de session',
				'Assets compilés',
		] as $check ) {
			$this->assertStringContainsString(
					$check,
					$display,
					sprintf( 'Assert « %s » is still looked at', $check )
			);
		}
	}

	/**
	 * Le 25 août, le staging a rendu 500 sur toutes ses pages parce qu'un
	 * `chown -R` avait donné les fichiers de session au déployeur : PHP refuse
	 * de lire un `sess_*` dont le propriétaire n'est pas le processus, quels
	 * que soient les droits et les ACL.
	 *
	 * `app:preflight` avait dit « Rien de bloquant » quelques minutes plus tôt,
	 * et elle avait raison sur ce qu'elle regardait — le répertoire était bel
	 * et bien inscriptible. Ce qui manquait était la propriété, que rien
	 * n'affichait nulle part.
	 *
	 * Elle ne peut pas en juger : la console ignore sous quel utilisateur
	 * tourne le serveur web, et en développement les deux sont le même. Elle
	 * la **montre**, et c'est tout ce qu'on lui demande.
	 */
	public function testItShowsWhoOwnsTheSessionFiles () {
		$this->command->execute( [] );

		$this->assertStringContainsString(
				'Propriété des fichiers de session',
				$this->display(),
				'Assert the ownership that took the staging down is visible without being hunted for'
		);
	}

	/**
	 * Une information n'est pas un verdict : cette ligne ne doit jamais faire
	 * échouer la commande, sans quoi tout poste de développement — où la
	 * console et le serveur web sont le même utilisateur — la verrait rouge
	 * sans raison.
	 */
	public function testTheOwnershipLineNeverBlocks () {
		$this->assertSame(
				0,
				$this->command->execute( [] ),
				'Assert nothing new here turns a healthy environment into a failure'
		);
	}
}
