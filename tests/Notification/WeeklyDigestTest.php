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
	 * @return \App\Entity\User
	 */
	private function user () {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setDisplayName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * Le rythme est porté par la notification depuis #38, et non plus par son
	 * destinataire.
	 *
	 * @param \App\Entity\User $recipient
	 * @param string           $rhythm
	 *
	 * @return \App\Entity\Notification
	 */
	private function notification ( User $recipient, $rhythm = NotificationRhythm::DAILY ) {
		$notification = new Notification();
		$notification->setRecipient( $recipient );
		$notification->setUsergroup( $this->group );
		$notification->setType( Notification::PAGE_CREATE );
		$notification->setTitle( 'Compte rendu de mars' );
		$notification->setUrl( '/groups/g/pages/compte-rendu' );
		$notification->setCreatedAt( new DateTime() );
		$notification->setByEmail( TRUE );
		$notification->setRhythm( $rhythm );

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
		$notification = $this->notification( $this->user(), NotificationRhythm::WEEKLY );

		$this->digest( self::TUESDAY );

		$this->assertFalse(
				$this->wasSent( $notification ),
				'Assert the summary of a weekly reader does not leave any day of the week'
		);
	}

	public function testWhatWaitedIsNotLost () {
		$notification = $this->notification( $this->user(), NotificationRhythm::WEEKLY );

		$this->digest( self::TUESDAY );

		$this->assertNull(
				$notification->getEmailedAt(),
				'Assert a passed-over notification stays waiting rather than being marked as sent'
		);
	}

	public function testTheMondayCarriesIt () {
		$notification = $this->notification( $this->user(), NotificationRhythm::WEEKLY );

		$this->digest( self::MONDAY );

		$this->assertTrue( $this->wasSent( $notification ) );
	}

	public function testAWholeWeekLeavesInOneGo () {
		$user = $this->user();

		$notifications = [
				$this->notification( $user, NotificationRhythm::WEEKLY ),
				$this->notification( $user, NotificationRhythm::WEEKLY ),
				$this->notification( $user, NotificationRhythm::WEEKLY ),
		];

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
		$notification = $this->notification( $this->user() );

		$this->digest( self::TUESDAY );

		$this->assertTrue(
				$this->wasSent( $notification ),
				'Assert the weekly rhythm did not slow anybody else down'
		);
	}

	public function testTheReportSaysWhoIsBeingHeld () {
		$this->notification( $this->user(), NotificationRhythm::WEEKLY );

		$this->digest( self::TUESDAY );

		$this->assertStringContainsString(
				'with nothing due today',
				$this->command->getDisplay(),
				'Assert an operator reading the output can tell nothing was lost'
		);
	}

	/**
	 * Depuis #38, un même membre peut avoir du quotidien et de l'hebdomadaire
	 * en attente. Le mardi n'emporte que le premier, et le lundi les deux —
	 * dans le même e-mail. (#38)
	 */
	public function testTheSameMemberCanHaveBothRhythmsWaiting () {
		$user = $this->user();

		$daily  = $this->notification( $user, NotificationRhythm::DAILY );
		$weekly = $this->notification( $user, NotificationRhythm::WEEKLY );

		$this->digest( self::TUESDAY );

		$this->assertTrue( $this->wasSent( $daily ), 'Assert the daily one leaves on a Tuesday' );
		$this->assertFalse( $this->wasSent( $weekly ), 'Assert the weekly one waits for its Monday' );

		$this->digest( self::MONDAY );

		$this->assertTrue( $this->wasSent( $weekly ) );
	}

	public function testTheDayIsShownInTheReport () {
		$this->notification( $this->user() );

		$this->digest( self::MONDAY );

		$this->assertStringContainsString(
				'Monday 24 August 2026',
				$this->command->getDisplay(),
				'Assert running for another day says so, rather than looking like today'
		);
	}

	public function testAnUnreadableDayIsRefused () {
		$notification = $this->notification( $this->user() );

		$this->command->execute( [ '--day' => 'lundi prochain' ] );

		$this->assertFalse(
				$this->wasSent( $notification ),
				'Assert a typo in a scheduled task does not silently send the wrong day'
		);
	}

	/**
	 * Ce qui suit : éprouver l'envoi sur une préproduction sans écrire à tout
	 * le réseau, et sans consommer ce qu'on veut revoir.
	 *
	 * L'enjeu n'est pas le confort. Une préproduction porte souvent une copie
	 * anonymisée dont toutes les adresses sont en `@example.org` : un résumé
	 * lancé tel quel y produit autant de rebonds durs que de comptes, et
	 * Postmark suspend un serveur pour moins que ça — celui de la production.
	 */
	public function testOnlyWritesToTheOneAccountAsked () {
		$tested = $this->user();
		$other  = $this->user();

		$mine   = $this->notification( $tested );
		$theirs = $this->notification( $other );

		$this->command->execute( [ '--day' => self::TUESDAY, '--only' => $tested->getEmail() ] );

		$this->assertTrue( $this->wasSent( $mine ), 'Assert the account asked for was served' );
		$this->assertFalse(
				$this->wasSent( $theirs ),
				'Assert nobody else was written to — that is the whole point of the option'
		);
	}

	public function testAMondayCanBeRehearsedForOneAccount () {
		$user   = $this->user();
		$weekly = $this->notification( $user, NotificationRhythm::WEEKLY );

		$this->command->execute( [ '--day' => self::MONDAY, '--only' => $user->getEmail() ] );

		$this->assertTrue(
				$this->wasSent( $weekly ),
				'Assert the weekly summary can be seen without waiting for a Monday'
		);
	}

	public function testTheReportSaysWhatIsHeldForThatAccount () {
		$user = $this->user();
		$this->notification( $user, NotificationRhythm::WEEKLY );

		$this->command->execute( [ '--day' => self::TUESDAY, '--only' => $user->getEmail() ] );

		$this->assertStringContainsString(
				'en attente d\'un lundi',
				$this->command->getDisplay(),
				'Assert the line says what is waiting, rather than showing an account with nothing'
		);
	}

	public function testKeepConsumesNothing () {
		$user         = $this->user();
		$notification = $this->notification( $user );

		$this->command->execute( [
				'--day'  => self::TUESDAY,
				'--only' => $user->getEmail(),
				'--keep' => TRUE,
		] );

		$this->assertFalse(
				$this->wasSent( $notification ),
				'Assert the same summary can be sent again, which is what makes it testable'
		);
	}

	/**
	 * Sans --only, --keep enverrait à tout le monde et laisserait tout en
	 * attente : le même résumé repartirait le lendemain, et le surlendemain.
	 */
	public function testKeepAloneIsRefused () {
		$notification = $this->notification( $this->user() );

		$this->command->execute( [ '--day' => self::TUESDAY, '--keep' => TRUE ] );

		$this->assertSame( 1, $this->command->getStatusCode() );
		$this->assertFalse( $this->wasSent( $notification ), 'Assert nothing left at all' );
	}

	public function testAnUnknownAddressIsRefused () {
		$this->command->execute( [ '--day' => self::TUESDAY, '--only' => 'personne@example.org' ] );

		$this->assertSame(
				1,
				$this->command->getStatusCode(),
				'Assert a typo in the address says so, instead of reporting a successful run that sent nothing'
		);
		$this->assertStringContainsString(
				'Aucun compte ne porte',
				$this->command->getDisplay(),
				'Assert a wrong address is told apart from an account with nothing waiting'
		);
	}

	/**
	 * « 0 members have notifications waiting » recouvre trois situations qu'un
	 * exploitant ne peut pas deviner : rien n'a été publié, tout est déjà
	 * parti, ou tout est créé sans e-mail. Un zéro muet le renvoie à la base
	 * de données, où il n'ira pas — il conclut que l'envoi est en panne.
	 */
	public function testAnAccountWithNothingWaitingIsToldWhy () {
		$user = $this->user();

		$this->command->execute( [ '--day' => self::TUESDAY, '--only' => $user->getEmail() ] );

		$display = $this->command->getDisplay();

		$this->assertSame( 1, $this->command->getStatusCode() );
		$this->assertStringContainsString(
				'RIEN N\'A ÉTÉ PUBLIÉ',
				$display,
				'Assert the report says what to do next, not only what is missing'
		);
	}

	public function testAnAccountWhoseSummaryAlreadyLeftIsToldSo () {
		$user = $this->user();
		$this->notification( $user );

		$this->command->execute( [ '--day' => self::TUESDAY, '--only' => $user->getEmail() ] );
		$this->command->execute( [ '--day' => self::TUESDAY, '--only' => $user->getEmail() ] );

		$this->assertStringContainsString(
				'TOUT EST DÉJÀ PARTI',
				$this->command->getDisplay(),
				'Assert a summary that was consumed is told apart from one that never existed'
		);
	}

	public function testNotificationsCreatedWithoutEmailAreToldApart () {
		$user         = $this->user();
		$notification = $this->notification( $user );
		$notification->setByEmail( FALSE );
		$this->manager->flush();

		$this->command->execute( [ '--day' => self::TUESDAY, '--only' => $user->getEmail() ] );

		$this->assertStringContainsString(
				'CE SONT LES RÉGLAGES',
				$this->command->getDisplay(),
				'Assert a settings problem is not read as a broken transport'
		);
	}
}
