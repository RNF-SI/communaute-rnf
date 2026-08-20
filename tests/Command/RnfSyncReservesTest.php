<?php

namespace App\Tests\Command;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Issue #28 — la synchronisation des réserves depuis GeoNature.
 *
 * Le jeton d'export n'existe pas en test, et n'existera pas davantage sur un
 * poste de développement : c'est précisément le cas qu'il faut tenir. Une
 * commande qui, faute de jeton, viderait les fiches ferait plus de dégâts
 * qu'elle n'en répare.
 *
 * Ce que l'export répond quand il répond est vérifié à part, sans réseau, par
 * RnfReservesTest.
 */
class RnfSyncReservesTest extends KernelTestCase {
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
				( new Application( self::$kernel ) )->find( 'app:rnf:sync-reserves' )
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
	 * @param int|null $roleId
	 * @param string   $reserves
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $roleId, $reserves = 'RN du Marais' ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRnfIdRole( $roleId );
		$user->setReserves( $reserves );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	public function testWithoutATokenTheCommandSaysSo () {
		$this->command->execute( [] );

		$this->assertStringContainsString(
				'RNF_EXPORT_TOKEN',
				$this->command->getDisplay(),
				'Assert an operator learns why nothing happened'
		);
	}

	public function testWithoutATokenNothingIsEmptied () {
		$user = $this->user( 4242 );
		$id   = $user->getId();

		$this->command->execute( [] );

		$this->manager->clear();

		$this->assertEquals(
				'RN du Marais',
				$this->manager->getRepository( User::class )->find( $id )->getReserves(),
				'Assert a missing token does not wipe the directory it was meant to fill'
		);
	}

	public function testALocalAccountIsNeverTouched () {
		$user = $this->user( NULL, 'RN saisie à la main' );
		$id   = $user->getId();

		$this->command->execute( [] );

		$this->manager->clear();

		$this->assertEquals(
				'RN saisie à la main',
				$this->manager->getRepository( User::class )->find( $id )->getReserves(),
				'Assert an account outside the single sign-on keeps what it typed'
		);
	}

	public function testTheCommandEndsWell () {
		$this->assertEquals( 0, $this->command->execute( [] ) );
	}
}
