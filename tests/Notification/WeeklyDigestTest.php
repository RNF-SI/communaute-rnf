<?php

namespace App\Tests\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Notification\NotificationRhythm;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Issue #38 — le résumé hebdomadaire.
 *
 * La commande tourne tous les jours ; un abonné hebdomadaire est passé six
 * jours sur sept. Ce qui compte, et que ce test tient : ses notifications
 * **attendent** au lieu d'être perdues, et le lundi les emporte toutes.
 */
class WeeklyDigestTest extends KernelTestCase {
	private const MONDAY  = '2026-08-24';
	private const TUESDAY = '2026-08-25';

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \Symfony\Component\Console\Tester\CommandTester
	 */
	private $command;

	/**
	 * @var \App\Entity\Usergroup
	 */
	private $group;

	protected function setUp (): void {
		self::bootKernel();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->command = new CommandTester(
				( new Application( self::$kernel ) )->find( 'app:notifications:digest' )
		);

		// Ce qui traînait en attente dans la base ne regarde pas ce test.
		$this->manager->createQueryBuilder()
					  ->update( Notification::class, 'n' )
					  ->set( 'n.emailedAt', ':now' )
					  ->where( 'n.emailedAt IS NULL' )
					  ->setParameter( 'now', new DateTime() )
					  ->getQuery()
					  ->execute();

		$this->group = new Usergroup();
		$this->group->setSlug( 'weekly-group-' . uniqid() );
		$this->group->setName( 'Commission montagne' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string $rhythm
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $rhythm ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setDisplayName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setDiscussionEmailRhythm( $rhythm );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $recipient
	 *
	 * @return \App\Entity\Notification
	 */
	private function notification ( User $recipient ) {
		$notification = new Notification();
		$notification->setRecipient( $recipient );
		$notification->setUsergroup( $this->group );
		$notification->setType( Notification::PAGE_CREATE );
		$notification->setTitle( 'Compte rendu de mars' );
		$notification->setUrl( '/groups/g/pages/compte-rendu' );
		$notification->setCreatedAt( new DateTime() );
		$notification->setByEmail( TRUE );

		$this->manager->persist( $notification );
		$this->manager->flush();

		return $notification;
	}

	/**
	 * @param string $day
	 */
	private function digest ( $day ) {
		$this->command->execute( [ '--day' => $day ] );
	}

	/**
	 * @param \App\Entity\Notification $notification
	 *
	 * @return bool
	 */
	private function wasSent ( Notification $notification ) {
		$this->manager->refresh( $notification );

		return $notification->getEmailedAt() !== NULL;
	}

	public function testAWeeklyReaderIsPassedOverOnATuesday () {
		$notification = $this->notification( $this->user( NotificationRhythm::WEEKLY ) );

		$this->digest( self::TUESDAY );

		$this->assertFalse(
				$this->wasSent( $notification ),
				'Assert the summary of a weekly reader does not leave any day of the week'
		);
	}

	public function testWhatWaitedIsNotLost () {
		$notification = $this->notification( $this->user( NotificationRhythm::WEEKLY ) );

		$this->digest( self::TUESDAY );

		$this->assertNull(
				$notification->getEmailedAt(),
				'Assert a passed-over notification stays waiting rather than being marked as sent'
		);
	}

	public function testTheMondayCarriesIt () {
		$notification = $this->notification( $this->user( NotificationRhythm::WEEKLY ) );

		$this->digest( self::MONDAY );

		$this->assertTrue( $this->wasSent( $notification ) );
	}

	public function testAWholeWeekLeavesInOneGo () {
		$user = $this->user( NotificationRhythm::WEEKLY );

		$notifications = [ $this->notification( $user ), $this->notification( $user ), $this->notification( $user ) ];

		$this->digest( self::TUESDAY );
		$this->digest( self::MONDAY );

		foreach ( $notifications as $index => $notification ) {
			$this->assertTrue(
					$this->wasSent( $notification ),
					sprintf( 'Assert notification %d of the week is carried too', $index + 1 )
			);
		}
	}

	public function testADailyReaderIsServedOnATuesday () {
		$notification = $this->notification( $this->user( NotificationRhythm::DIGEST ) );

		$this->digest( self::TUESDAY );

		$this->assertTrue(
				$this->wasSent( $notification ),
				'Assert the weekly rhythm did not slow anybody else down'
		);
	}

	public function testTheReportSaysWhoIsBeingHeld () {
		$this->notification( $this->user( NotificationRhythm::WEEKLY ) );

		$this->digest( self::TUESDAY );

		$this->assertStringContainsString(
				'held until their weekly day',
				$this->command->getDisplay(),
				'Assert an operator reading the output can tell nothing was lost'
		);
	}

	public function testTheDayIsShownInTheReport () {
		$this->notification( $this->user( NotificationRhythm::DIGEST ) );

		$this->digest( self::MONDAY );

		$this->assertStringContainsString(
				'Monday 24 August 2026',
				$this->command->getDisplay(),
				'Assert running for another day says so, rather than looking like today'
		);
	}

	public function testAnUnreadableDayIsRefused () {
		$notification = $this->notification( $this->user( NotificationRhythm::DIGEST ) );

		$this->command->execute( [ '--day' => 'lundi prochain' ] );

		$this->assertFalse(
				$this->wasSent( $notification ),
				'Assert a typo in a scheduled task does not silently send the wrong day'
		);
	}
}
