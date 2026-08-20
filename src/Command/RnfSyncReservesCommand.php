<?php

namespace App\Command;

use App\Entity\User;
use App\Service\RnfReserves;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Issue #28 — remplir les réserves suivies depuis GeoNature.
 *
 * Une commande plutôt qu'un appel à chaque connexion : l'export répond en
 * quelques centaines de millisecondes, et personne n'a envie que sa connexion
 * attende une API tierce. Les liens entre un agent et ses réserves ne bougent
 * pas d'un jour à l'autre ; un passage nocturne suffit.
 *
 * Ne touche que les comptes venant du SSO, et seulement quand GeoNature a
 * quelque chose à dire : une API indisponible ne doit pas vider un annuaire.
 */
class RnfSyncReservesCommand extends Command {
	protected static $defaultName = 'app:rnf:sync-reserves';

	private $manager;

	private $reserves;

	public function __construct ( EntityManagerInterface $manager, RnfReserves $reserves ) {
		$this->manager  = $manager;
		$this->reserves = $reserves;

		parent::__construct();
	}

	protected function configure () {
		$this
				->setDescription( 'Fill the reserves of the SSO accounts from GeoNature' )
				->setHelp(
						"Reads the « Liens utilisateurs-réserves » export, one call per account.\n"
						. "Needs RNF_EXPORT_TOKEN; without it the command says so and does nothing."
				)
				->addOption( 'dry-run', NULL, InputOption::VALUE_NONE, 'Report what would change without writing' )
				->addOption( 'role-id', NULL, InputOption::VALUE_REQUIRED, 'Only that GeoNature account' );
	}

	protected function execute ( InputInterface $input, OutputInterface $output ) {
		$io     = new SymfonyStyle( $input, $output );
		$dryRun = $input->getOption( 'dry-run' );

		if ( !$this->reserves->isConfigured() ) {
			$io->warning(
					'RNF_EXPORT_TOKEN is empty: the reserves export cannot be read, '
					. 'and the field stays filled in by hand.'
			);

			return 0;
		}

		$criteria = [];

		if ( $input->getOption( 'role-id' ) ) {
			$criteria[ 'rnfIdRole' ] = (int) $input->getOption( 'role-id' );
		}

		/**
		 * @var \App\Entity\User[] $users
		 */
		$users = $this->manager->getRepository( User::class )->findBy( $criteria );

		$changed = 0;
		$same    = 0;
		$silent  = 0;

		foreach ( $users as $user ) {
			// Un compte local, sans identité GeoNature, garde ce qu'il a saisi.
			if ( !$user->getRnfIdRole() || ( $user->getStatus() !== User::STATUS_ACTIVE ) ) {
				continue;
			}

			$found = $this->reserves->forUser( $user );

			if ( $found === NULL ) {
				$silent++;

				continue;
			}

			if ( $found === $user->getReserves() ) {
				$same++;

				continue;
			}

			$io->text( sprintf(
					'#%d %s : %s → %s',
					$user->getId(),
					$user->getName(),
					$user->getReserves() ?: '(vide)',
					$found
			) );

			if ( !$dryRun ) {
				$user->setReserves( $found );
			}

			$changed++;
		}

		if ( !$dryRun ) {
			$this->manager->flush();
		}

		$io->success( sprintf(
				'%d accounts %s, %d already up to date, %d left untouched because GeoNature said nothing',
				$changed,
				$dryRun ? 'would change' : 'updated',
				$same,
				$silent
		) );

		return 0;
	}
}
