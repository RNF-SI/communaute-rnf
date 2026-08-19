<?php

namespace App\Tests\Command;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A copy of the production database must not carry the identities of the
 * network members onto a local machine or onto staging.
 */
class AnonymizeDatabaseCommandTest extends KernelTestCase {
	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \Symfony\Component\Console\Tester\CommandTester
	 */
	private $command;

	protected function setUp (): void {
		self::bootKernel();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->command = new CommandTester(
				( new Application( self::$kernel ) )->find( 'app:db:anonymize' )
		);
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string $email
	 *
	 * @return \App\Entity\User
	 */
	private function realUser ( $email ) {
		$user = new User();
		$user->setEmail( $email );
		$user->setName( 'Jeanne Réserve' );
		$user->setDisplayName( 'Jeanne R.' );
		$user->setCity( 'Valenciennes' );
		$user->setZipCode( '59300' );
		$user->setLatitude( 50.35 );
		$user->setLongitude( 3.52 );
		$user->setBio( 'Conservatrice depuis 2011.' );
		$user->setPresentation( 'Conservatrice' );
		$user->setPassword( '$2y$13$something' );
		$user->setRnfIdRole( 4242 );
		$user->setRnfUserLogin( 'jreserve' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setHasAgreedTermsOfUse( TRUE );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param array $options
	 */
	private function anonymize ( array $options = [] ) {
		$this->command->execute( $options );
		$this->manager->clear();
	}

	/**
	 * @param int $id
	 *
	 * @return \App\Entity\User
	 */
	private function reload ( $id ) {
		return $this->manager->getRepository( User::class )->find( $id );
	}

	public function testIdentityIsReplaced () {
		$id = $this->realUser( 'jeanne@rnfrance.org' )->getId();

		$this->anonymize();

		$user = $this->reload( $id );

		$this->assertStringEndsWith( '@example.org', $user->getEmail() );
		$this->assertNotEquals( 'Jeanne Réserve', $user->getName() );
		$this->assertNotEquals( 'Jeanne R.', $user->getDisplayName() );
		$this->assertNotEquals( 'Valenciennes', $user->getCity() );
	}

	public function testCredentialsAndGeonatureLinkAreDropped () {
		$id = $this->realUser( 'jeanne@rnfrance.org' )->getId();

		$this->anonymize();

		$user = $this->reload( $id );

		$this->assertEquals( '', $user->getPassword(), 'Assert no password survives the copy' );
		$this->assertNull( $user->getRnfIdRole() );
		$this->assertNull( $user->getRnfUserLogin() );
		$this->assertNull( $user->getLatitude() );
		$this->assertNull( $user->getLongitude() );
	}

	public function testTheAccountStaysUsable () {
		$user = $this->realUser( 'jeanne@rnfrance.org' );
		$id   = $user->getId();

		$this->anonymize();

		$user = $this->reload( $id );

		$this->assertNotEmpty( $user->getName(), 'Assert the account still has a name to display' );
		$this->assertEquals( User::STATUS_ACTIVE, $user->getStatus(), 'Assert the account is not disabled' );
	}

	public function testGeneratedEmailIsUsableAsAnAddress () {
		$user = $this->realUser( 'jeanne@rnfrance.org' );
		$user->setName( 'Timothée Lenoir-Çadeau' );
		$this->manager->flush();
		$id = $user->getId();

		$this->anonymize();

		$this->assertRegExp(
				'/^[a-z0-9.+-]+@example\.org$/',
				$this->reload( $id )->getEmail(),
				'Assert accents and punctuation never leak into the generated address'
		);
	}

	public function testKeptAccountIsLeftUntouched () {
		$id = $this->realUser( 'jeanne@rnfrance.org' )->getId();

		$this->anonymize( [ '--keep-email' => [ 'jeanne@rnfrance.org' ] ] );

		$this->assertEquals(
				'jeanne@rnfrance.org',
				$this->reload( $id )->getEmail(),
				'Assert an allowed account can still sign in afterwards'
		);
	}

	public function testDryRunChangesNothing () {
		$id = $this->realUser( 'jeanne@rnfrance.org' )->getId();

		$this->anonymize( [ '--dry-run' => TRUE ] );

		$this->assertEquals( 'jeanne@rnfrance.org', $this->reload( $id )->getEmail() );
	}

	public function testTwoAccountsNeverCollideOnTheSameAddress () {
		$first  = $this->realUser( 'first@rnfrance.org' )->getId();
		$second = $this->realUser( 'second@rnfrance.org' )->getId();

		$this->anonymize();

		$this->assertNotEquals(
				$this->reload( $first )->getEmail(),
				$this->reload( $second )->getEmail(),
				'Assert the generated addresses stay unique, the column is unique'
		);
	}

	public function testAnAlreadyAnonymisedAccountIsLeftAlone () {
		$user = $this->realUser( 'jeanne@rnfrance.org' );
		$id   = $user->getId();

		$this->anonymize();
		$first = $this->reload( $id );
		$name  = $first->getName();
		$email = $first->getEmail();

		$this->anonymize();
		$second = $this->reload( $id );

		$this->assertEquals( $email, $second->getEmail() );
		$this->assertEquals( $name, $second->getName() );
	}

	public function testDryRunReportsACleanCopy () {
		$this->realUser( 'jeanne@rnfrance.org' );

		$this->anonymize();
		$this->command->execute( [ '--dry-run' => TRUE ] );

		$this->assertStringContainsString(
				'0 accounts would be anonymised',
				$this->command->getDisplay(),
				'Assert --dry-run can be used to check that a copy carries nothing personal'
		);
	}

	public function testRunningTwiceGivesTheSameResult () {
		$id = $this->realUser( 'jeanne@rnfrance.org' )->getId();

		$this->anonymize();
		$first = $this->reload( $id )->getEmail();

		$this->anonymize();

		$this->assertEquals(
				$first,
				$this->reload( $id )->getEmail(),
				'Assert the command is stable, so a reloaded dump gives the same fake identities'
		);
	}
}
