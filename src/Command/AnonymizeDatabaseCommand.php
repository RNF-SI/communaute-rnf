<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Faker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Replaces the personal data of every account by plausible but made-up data,
 * so that a copy of the production database can be used locally and on
 * staging without carrying the identities of the network members.
 *
 * Volumetry and relations are left untouched: same accounts, same
 * memberships, same discussions, same documents. Only who people are changes.
 */
class AnonymizeDatabaseCommand extends Command {
	protected static $defaultName = 'app:db:anonymize';

	/**
	 * Reserved by RFC 2606: nothing sent there can reach a real mailbox.
	 */
	private const MAIL_DOMAIN = 'example.org';

	private $manager;

	private $environment;

	public function __construct ( EntityManagerInterface $manager, ParameterBagInterface $parameters ) {
		$this->manager     = $manager;
		$this->environment = $parameters->get( 'kernel.environment' );

		parent::__construct();
	}

	protected function configure () {
		$this
				->setDescription( 'Replace the personal data of every account by made-up data' )
				->setHelp(
						"Meant to be run right after loading a copy of the production database\n"
						. "into a local or staging environment. Refuses to run in prod." )
				->addOption(
						'keep-email',
						NULL,
						InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
						'E-mail address to leave untouched, so that account can still sign in. Repeatable.'
				)
				->addOption( 'dry-run', NULL, InputOption::VALUE_NONE, 'Report what would change without writing' )
				->addOption( 'force', NULL, InputOption::VALUE_NONE, 'Run even outside dev and staging' );
	}

	protected function execute ( InputInterface $input, OutputInterface $output ) {
		$io = new SymfonyStyle( $input, $output );

		if ( ( $this->environment === 'prod' ) && !$input->getOption( 'force' ) ) {
			$io->error( 'Refusing to anonymise a production environment. Use --force if you really mean it.' );

			return 1;
		}

		$keep   = array_map( 'mb_strtolower', $input->getOption( 'keep-email' ) );
		$dryRun = $input->getOption( 'dry-run' );

		$users = $this->manager->getRepository( User::class )->findAll();

		$io->title( sprintf( '%d accounts found', count( $users ) ) );

		$anonymised = 0;
		$kept       = 0;

		foreach ( $users as $user ) {
			if ( in_array( mb_strtolower( (string) $user->getEmail() ), $keep, TRUE ) ) {
				$io->text( sprintf( 'keeping account #%d', $user->getId() ) );
				$kept++;

				continue;
			}

			if ( !$dryRun ) {
				$this->anonymise( $user );
			}

			$anonymised++;
		}

		if ( !$dryRun ) {
			$this->manager->flush();
		}

		$io->success( sprintf(
				'%d accounts %s, %d left untouched',
				$anonymised,
				$dryRun ? 'would be anonymised' : 'anonymised',
				$kept
		) );

		if ( !$dryRun ) {
			$io->note( 'Free-text content — discussions, pages, articles, document names — is left as it is and may still name people.' );
		}

		return 0;
	}

	/**
	 * Made-up data is derived from the account id, so that running the command
	 * twice on the same dump gives the same result.
	 *
	 * @param \App\Entity\User $user
	 */
	private function anonymise ( User $user ) {
		$id = $user->getId();

		$faker = Faker\Factory::create( 'fr_FR' );
		$faker->seed( $id );

		$firstName = $faker->firstName();
		$lastName  = $faker->lastName();

		$user->setEmail( sprintf(
				'%s.%s+%d@%s',
				$this->slug( $firstName ),
				$this->slug( $lastName ),
				$id,
				self::MAIL_DOMAIN
		) );
		$user->setName( $firstName . ' ' . $lastName );
		$user->setDisplayName( $firstName . ' ' . $lastName );

		$user->setCity( $faker->city() );
		$user->setZipCode( $faker->postcode() );
		$user->setCountry( 'FR' );
		$user->setLatitude( NULL );
		$user->setLongitude( NULL );

		if ( !empty( $user->getPresentation() ) ) {
			$user->setPresentation( $faker->sentence( 6 ) );
		}

		if ( !empty( $user->getBio() ) ) {
			$user->setBio( implode( ' ', $faker->paragraphs( 2 ) ) );
		}

		// Nobody must be able to sign in with a password taken from the copy.
		$user->setPassword( '' );
		$user->setResetToken( NULL );
		$user->setEmailNew( NULL );
		$user->setEmailToken( NULL );

		// Identifiers of the GeoNature account behind this one.
		$user->setRnfIdRole( NULL );
		$user->setRnfIdOrganisme( NULL );
		$user->setRnfUserLogin( NULL );
		$user->setRnfPrenomRole( NULL );
		$user->setRnfNomRole( NULL );
		$user->setRnfRoleInfo( [] );
	}

	/**
	 * Accents are folded from an explicit table rather than through iconv,
	 * whose transliteration depends on the locale of the server.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	private function slug ( $value ) {
		$value = strtr( mb_strtolower( $value ), [
				'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
				'ç' => 'c',
				'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
				'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
				'ñ' => 'n',
				'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
				'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
				'ý' => 'y', 'ÿ' => 'y',
				'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
		] );

		return trim( preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
	}
}
