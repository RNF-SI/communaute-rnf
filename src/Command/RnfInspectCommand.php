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
use Throwable;

/**
 * Issue #28 — regarder ce que GeoNature dit réellement d'un compte.
 *
 * Le schéma de l'export « Liens utilisateurs-réserves » n'est pas publié : le
 * swagger n'annonce que les filtres, et l'export répond 403 sans jeton. La
 * seule façon honnête de connaître les colonnes est de les regarder, une fois
 * le jeton en main.
 *
 * Cette commande sert aussi à répondre aux deux inconnues qui restent de #28 :
 * d'où viendrait la **fonction**, et où trouver le **nom** de l'organisme dont
 * on ne reçoit que l'identifiant. Elle affiche pour cela le `roleOPNLInfo`
 * que le SSO nous donne à chaque connexion et que personne n'a jamais lu.
 */
class RnfInspectCommand extends Command {
	protected static $defaultName = 'app:rnf:inspect';

	private $manager;

	private $reserves;

	public function __construct ( EntityManagerInterface $manager, RnfReserves $reserves ) {
		$this->manager  = $manager;
		$this->reserves = $reserves;

		parent::__construct();
	}

	protected function configure () {
		$this
				->setDescription( 'Show what GeoNature says about one account' )
				->setHelp(
						"Diagnostic only, writes nothing. Give it the e-mail of an account that\n"
						. "signs in through the single sign-on, or a GeoNature role id."
				)
				->addOption( 'email', NULL, InputOption::VALUE_REQUIRED, 'The local account to look at' )
				->addOption( 'role-id', NULL, InputOption::VALUE_REQUIRED, 'A GeoNature role id, without a local account' );
	}

	protected function execute ( InputInterface $input, OutputInterface $output ) {
		$io = new SymfonyStyle( $input, $output );

		$roleId = $input->getOption( 'role-id' );
		$user   = NULL;

		if ( $input->getOption( 'email' ) ) {
			$user = $this->manager->getRepository( User::class )
								  ->findOneBy( [ 'email' => $input->getOption( 'email' ) ] );

			if ( !$user ) {
				$io->error( sprintf( 'No account with the address %s', $input->getOption( 'email' ) ) );

				return 1;
			}

			$roleId = $user->getRnfIdRole();
		}

		if ( $user ) {
			$io->section( 'What the single sign-on already gave us, at every login' );

			$io->definitionList(
					[ 'rnfIdRole' => (string) $user->getRnfIdRole() ],
					[ 'rnfIdOrganisme' => (string) $user->getRnfIdOrganisme() ],
					[ 'rnfUserLogin' => (string) $user->getRnfUserLogin() ],
					[ 'rnfPrenomRole' => (string) $user->getRnfPrenomRole() ],
					[ 'rnfNomRole' => (string) $user->getRnfNomRole() ]
			);

			// Stocké à chaque connexion, jamais lu : c'est là que la fonction
			// et le nom de l'organisme pourraient se trouver.
			$io->text( 'roleOPNLInfo — stored at every login, read nowhere:' );
			$io->writeln( json_encode( $user->getRnfRoleInfo(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}

		if ( !$roleId ) {
			$io->warning( 'No GeoNature role id: this account does not come from the single sign-on.' );

			return 0;
		}

		$io->section( sprintf( 'What the reserves export answers for role %d', $roleId ) );

		if ( !$this->reserves->isConfigured() ) {
			$io->warning( 'RNF_EXPORT_TOKEN is empty: the export answers 403 without it.' );

			return 0;
		}

		try {
			$items = $this->reserves->fetch( $roleId );
		}
		catch ( Throwable $error ) {
			$io->error( $error->getMessage() );

			return 1;
		}

		if ( empty( $items ) ) {
			$io->text( 'The export answered, with nothing for this account.' );

			return 0;
		}

		$io->text( sprintf( '%d links. Columns of the first one:', count( $items ) ) );
		$io->writeln( json_encode( $items[ 0 ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

		$io->text( 'What the profile would show:' );
		$io->writeln( (string) $this->reserves->format( $items ) );

		return 0;
	}
}
