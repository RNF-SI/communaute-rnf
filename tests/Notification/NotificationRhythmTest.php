<?php

namespace App\Tests\Notification;

use App\Notification\NotificationRhythm;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #38 — le jour où part un résumé.
 *
 * La commande tourne tous les jours ; c'est cette règle, et elle seule, qui
 * fait qu'un abonné hebdomadaire n'est servi que le lundi. Elle ne demande ni
 * base de données ni noyau : elle se vérifie ici, jour par jour.
 */
class NotificationRhythmTest extends TestCase {
	/**
	 * @param string $day
	 *
	 * @return \DateTime
	 */
	private function day ( $day ) {
		return new DateTime( $day );
	}

	public function testTheWeeklySummaryLeavesOnMonday () {
		$this->assertTrue(
				NotificationRhythm::sendsOn( NotificationRhythm::WEEKLY, $this->day( '2026-08-24' ) ),
				'Assert 24 August 2026 is a Monday and carries the weekly summary'
		);
	}

	public function testTheWeeklySummaryWaitsEveryOtherDay () {
		foreach ( [ '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28', '2026-08-29', '2026-08-30' ] as $day ) {
			$this->assertFalse(
					NotificationRhythm::sendsOn( NotificationRhythm::WEEKLY, $this->day( $day ) ),
					sprintf( 'Assert nothing leaves on %s', $day )
			);
		}
	}

	public function testTheDailySummaryLeavesEveryDay () {
		foreach ( [ '2026-08-24', '2026-08-27', '2026-08-30' ] as $day ) {
			$this->assertTrue(
					NotificationRhythm::sendsOn( NotificationRhythm::DAILY, $this->day( $day ) ),
					sprintf( 'Assert the daily summary is not held back on %s', $day )
			);
		}
	}

	public function testTheImmediateRhythmIsNeverHeldBack () {
		$this->assertTrue(
				NotificationRhythm::sendsOn( NotificationRhythm::IMMEDIATE, $this->day( '2026-08-27' ) ),
				'Assert a notification queued before a change of setting is not stuck until Monday'
		);
	}

	public function testTheThreeRhythmsAreOffered () {
		$this->assertEquals(
				[ NotificationRhythm::IMMEDIATE, NotificationRhythm::DAILY, NotificationRhythm::WEEKLY ],
				NotificationRhythm::all()
		);
	}

	public function testTheOldNameOfTheDailySummaryIsStillRead () {
		$this->assertFalse(
				NotificationRhythm::exists( NotificationRhythm::LEGACY_DIGEST ),
				'Assert it is not offered any more'
		);
		$this->assertTrue(
				NotificationRhythm::stored( NotificationRhythm::LEGACY_DIGEST ),
				'Assert an account settled before #40 is still readable'
		);
	}

	public function testEveryRhythmIsRecognised () {
		foreach ( NotificationRhythm::all() as $rhythm ) {
			$this->assertTrue( NotificationRhythm::exists( $rhythm ) );
		}
	}

	public function testAnUnknownRhythmIsRefused () {
		$this->assertFalse( NotificationRhythm::exists( 'mensuel' ) );
	}

	public function testTheDefaultRhythmIsTheDailySummary () {
		$this->assertEquals(
				NotificationRhythm::DAILY,
				NotificationRhythm::DEFAULT_RHYTHM,
				'Assert an e-mail nobody said anything about goes out once a day, as asked in #40'
		);
	}

	public function testTheWeeklyDayIsMonday () {
		$this->assertEquals(
				1,
				NotificationRhythm::WEEKLY_DAY,
				'Assert the day announced to the members is the day the code applies'
		);
	}
}
