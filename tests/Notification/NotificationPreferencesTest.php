<?php

namespace App\Tests\Notification;

use App\Entity\User;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Notification\NotificationRhythm;
use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #34 — what a member is warned about, group by group and category by
 * category, plus the choice that can be made on a single discussion.
 *
 * Since then, a group that says nothing of its own follows the member's
 * general setting: somebody sitting in thirty groups states their choice once
 * instead of copying it thirty times. What follows pins the order in which
 * the three sources are read — the group, the legacy opt-out, the general
 * setting — because getting it wrong either resubscribes people who had
 * unsubscribed, or silently overrides a choice they made on purpose.
 */
class NotificationPreferencesTest extends TestCase {
	/**
	 * @param array|null $settings
	 *
	 * @return \App\Entity\UsergroupMembership
	 */
	private function membership ( $settings = [], User $user = NULL ) {
		$membership = new UsergroupMembership();
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setNotificationsSettings( $settings );

		if ( $user ) {
			$membership->setUser( $user );
		}

		return $membership;
	}

	/**
	 * @param string|null $level applied to every category
	 *
	 * @return \App\Entity\User
	 */
	private function member ( $level = NULL ) {
		$user = new User();

		if ( $level !== NULL ) {
			foreach ( NotificationCategory::all() as $category ) {
				$user->setDefaultNotificationLevel( $category, $level );
			}
		}

		return $user;
	}

	public function testEveryCategoryIsFollowedByDefault () {
		$membership = $this->membership();

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals(
					NotificationLevel::DAILY,
					$membership->getNotificationLevel( $category ),
					sprintf( 'Assert "%s" warns by default — dans le résumé quotidien depuis #40', $category )
			);
		}
	}

	public function testALegacyOptOutStaysAnOptOut () {
		$membership = $this->membership( [ 'unsubscribed' => TRUE ] );

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals(
					NotificationLevel::NONE,
					$membership->getNotificationLevel( $category ),
					'Assert somebody who had unsubscribed is not resubscribed by the change'
			);
		}
	}

	public function testACategoryCanBeSetAndRead () {
		$membership = $this->membership();
		$membership->setNotificationLevel( NotificationCategory::PAGES, NotificationLevel::APP );

		$this->assertEquals( NotificationLevel::APP, $membership->getNotificationLevel( NotificationCategory::PAGES ) );
		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getNotificationLevel( NotificationCategory::DOCUMENTS ),
				'Assert the other categories are untouched'
		);
	}

	public function testChoosingACategoryClearsTheLegacyOptOut () {
		$membership = $this->membership( [ 'unsubscribed' => TRUE ] );
		$membership->setNotificationLevel( NotificationCategory::PAGES, NotificationLevel::DAILY );

		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert an explicit choice is not overridden by the old flag'
		);
		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getNotificationLevel( NotificationCategory::DOCUMENTS ),
				'Assert the old flag stops applying once the member states a choice'
		);
	}

	public function testAGroupThatSaysNothingFollowsTheGeneralSetting () {
		$membership = $this->membership( [], $this->member( NotificationLevel::APP ) );

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals(
					NotificationLevel::APP,
					$membership->getNotificationLevel( $category ),
					sprintf( 'Assert "%s" follows the general setting rather than the built-in default', $category )
			);
		}

		$this->assertTrue( $membership->followsGeneralSettings() );
	}

	public function testTheGeneralSettingDoesNotOverrideAGroupSetOnPurpose () {
		$membership = $this->membership( [], $this->member( NotificationLevel::NONE ) );
		$membership->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::DAILY );

		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getNotificationLevel( NotificationCategory::DISCUSSIONS ),
				'Assert what was said about this group wins'
		);
		$this->assertEquals(
				NotificationLevel::NONE,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert the rest still follows the general setting'
		);
		$this->assertFalse( $membership->followsGeneralSettings() );
	}

	public function testACategoryCanBeSentBackToTheGeneralSetting () {
		$membership = $this->membership( [], $this->member( NotificationLevel::APP ) );
		$membership->setNotificationLevel( NotificationCategory::PAGES, NotificationLevel::NONE );
		$membership->clearNotificationLevel( NotificationCategory::PAGES );

		$this->assertNull( $membership->getOwnNotificationLevel( NotificationCategory::PAGES ) );
		$this->assertEquals(
				NotificationLevel::APP,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert the group goes back under the general setting instead of keeping a copy of it'
		);
		$this->assertTrue( $membership->followsGeneralSettings() );
	}

	public function testAWholeGroupCanBeSentBackInOneGo () {
		$membership = $this->membership( [], $this->member( NotificationLevel::DAILY ) );

		foreach ( NotificationCategory::all() as $category ) {
			$membership->setNotificationLevel( $category, NotificationLevel::NONE );
		}

		$membership->followGeneralSettings();

		$this->assertTrue( $membership->followsGeneralSettings() );

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals( NotificationLevel::DAILY, $membership->getNotificationLevel( $category ) );
		}
	}

	public function testALegacyOptOutIsNotUndoneByAGeneralSetting () {
		$membership = $this->membership( [ 'unsubscribed' => TRUE ], $this->member( NotificationLevel::DAILY ) );

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals(
					NotificationLevel::NONE,
					$membership->getNotificationLevel( $category ),
					'Assert somebody who had unsubscribed is not resubscribed by a setting made elsewhere'
			);
		}

		$this->assertFalse(
				$membership->followsGeneralSettings(),
				'Assert the settings page shows such a group as set apart, not as following'
		);
	}

	public function testALegacyOptOutIsShownAsWhatItIs () {
		$membership = $this->membership( [ 'unsubscribed' => TRUE ], $this->member( NotificationLevel::DAILY ) );

		$this->assertEquals(
				array_fill_keys( NotificationCategory::all(), NotificationLevel::NONE ),
				$membership->getOwnNotificationLevels(),
				'Assert the settings page can show a muted group as muted, not as following a setting it ignores'
		);
	}

	public function testAskingAGroupToFollowDropsTheLegacyOptOut () {
		$membership = $this->membership( [ 'unsubscribed' => TRUE ], $this->member( NotificationLevel::DAILY ) );
		$membership->followGeneralSettings();

		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert asking for it explicitly does what it says'
		);
	}

	public function testAnUnknownCategoryOrLevelIsRefusedAsAGeneralSetting () {
		$user = new User();
		$user->setDefaultNotificationLevel( 'nonsense', NotificationLevel::NONE );
		$user->setDefaultNotificationLevel( NotificationCategory::PAGES, 'nonsense' );

		$this->assertEquals( NotificationLevel::DAILY, $user->getDefaultNotificationLevel( NotificationCategory::PAGES ) );
	}

	public function testAnUnknownCategoryOrLevelIsRefused () {
		$membership = $this->membership();
		$membership->setNotificationLevel( 'nonsense', NotificationLevel::NONE );
		$membership->setNotificationLevel( NotificationCategory::PAGES, 'nonsense' );

		$this->assertEquals( NotificationLevel::DAILY, $membership->getNotificationLevel( NotificationCategory::PAGES ) );
	}

	public function testADiscussionFollowsItsCategoryByDefault () {
		$membership = $this->membership();
		$membership->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::APP );

		$this->assertEquals( NotificationLevel::APP, $membership->getLevelForDiscussion( 'some-uuid' ) );
		$this->assertNull( $membership->getDiscussionOverride( 'some-uuid' ) );
	}

	public function testFollowingOneDiscussionInAMutedCategory () {
		$membership = $this->membership();
		$membership->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::NONE );
		$membership->setDiscussionOverride( 'followed', NotificationLevel::DAILY );

		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getLevelForDiscussion( 'followed' ),
				'Assert the case spelled out in the issue: a muted category does not prevent following one discussion'
		);
		$this->assertEquals(
				NotificationLevel::NONE,
				$membership->getLevelForDiscussion( 'another' ),
				'Assert the other discussions stay muted'
		);
	}

	public function testMutingOneDiscussionInAFollowedCategory () {
		$membership = $this->membership();
		$membership->setDiscussionOverride( 'noisy', NotificationLevel::NONE );

		$this->assertEquals( NotificationLevel::NONE, $membership->getLevelForDiscussion( 'noisy' ) );
		$this->assertEquals( NotificationLevel::DAILY, $membership->getLevelForDiscussion( 'another' ) );
	}

	public function testADiscussionChoiceCanBeTakenBack () {
		$membership = $this->membership();
		$membership->setDiscussionOverride( 'noisy', NotificationLevel::NONE );
		$membership->setDiscussionOverride( 'noisy', NULL );

		$this->assertNull( $membership->getDiscussionOverride( 'noisy' ) );
		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getLevelForDiscussion( 'noisy' ),
				'Assert it goes back to following the category'
		);
	}

	public function testAMemberWantsEmailsUntilTheySayOtherwise () {
		$user = new User();

		$this->assertTrue( $user->wantsEmails() );

		$user->setWantsEmails( FALSE );

		$this->assertFalse( $user->wantsEmails() );
	}

	public function testTheDefaultIsTheDailySummary () {
		$user = new User();

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals(
					NotificationLevel::DAILY,
					$user->getDefaultNotificationLevel( $category ),
					sprintf( 'Assert "%s" lands in the daily summary, the default asked for in #40', $category )
			);
		}
	}

	public function testARhythmIsChosenCategoryByCategory () {
		$membership = $this->membership( [], $this->member( NotificationLevel::DAILY ) );
		$membership->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::IMMEDIATE );
		$membership->setNotificationLevel( NotificationCategory::DOCUMENTS, NotificationLevel::WEEKLY );

		$this->assertEquals(
				NotificationLevel::IMMEDIATE,
				$membership->getNotificationLevel( NotificationCategory::DISCUSSIONS ),
				'Assert the case #40 was asked for: this commission message by message…'
		);
		$this->assertEquals(
				NotificationLevel::WEEKLY,
				$membership->getNotificationLevel( NotificationCategory::DOCUMENTS ),
				'…and its documents once a week'
		);
		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert the categories that were left alone keep following the general setting'
		);
	}

	/**
	 * Ce qu'un compte réglé avant #40 devient. La règle : ceux qui avaient
	 * choisi gardent leur choix, ceux qui n'avaient rien choisi basculent sur
	 * le quotidien.
	 */
	public function testALegacyEmailLevelWithoutARhythmBecomesTheDailySummary () {
		$user = new User();
		$user->setNotificationsSettings( [
				'categories' => [ NotificationCategory::PAGES => NotificationLevel::LEGACY_EMAIL ],
		] );

		$this->assertEquals(
				NotificationLevel::DAILY,
				$user->getDefaultNotificationLevel( NotificationCategory::PAGES ),
				'Assert somebody who never chose a rhythm is moved to the daily summary'
		);
	}

	public function testALegacyImmediateRhythmIsKeptOnDiscussions () {
		$user = new User();
		$user->setNotificationsSettings( [
				'discussionRhythm' => NotificationRhythm::IMMEDIATE,
				'categories'       => array_fill_keys( NotificationCategory::all(), NotificationLevel::LEGACY_EMAIL ),
		] );

		$this->assertEquals(
				NotificationLevel::IMMEDIATE,
				$user->getDefaultNotificationLevel( NotificationCategory::DISCUSSIONS ),
				'Assert a choice made on purpose survives the change'
		);

		$this->assertEquals(
				NotificationLevel::DAILY,
				$user->getDefaultNotificationLevel( NotificationCategory::PAGES ),
				'Assert it is not extended to pages, which never left immediately before #40'
		);
	}

	public function testALegacyWeeklyRhythmIsKeptEverywhere () {
		$user = new User();
		$user->setNotificationsSettings( [
				'discussionRhythm' => NotificationRhythm::WEEKLY,
				'categories'       => array_fill_keys( NotificationCategory::all(), NotificationLevel::LEGACY_EMAIL ),
		] );

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals(
					NotificationLevel::WEEKLY,
					$user->getDefaultNotificationLevel( $category ),
					'Assert somebody who asked for one e-mail a week still gets one e-mail a week'
			);
		}
	}

	public function testALegacyDailyRhythmIsRead () {
		$user = new User();
		$user->setNotificationsSettings( [
				'discussionRhythm' => NotificationRhythm::LEGACY_DIGEST,
				'categories'       => [ NotificationCategory::PAGES => NotificationLevel::LEGACY_EMAIL ],
		] );

		$this->assertEquals(
				NotificationLevel::DAILY,
				$user->getDefaultNotificationLevel( NotificationCategory::PAGES ),
				'Assert the old name of the daily summary is still understood'
		);
	}

	public function testALegacyGroupSettingIsReadThroughTheMemberRhythm () {
		$user = new User();
		$user->setNotificationsSettings( [ 'discussionRhythm' => NotificationRhythm::WEEKLY ] );

		$membership = $this->membership(
				[ 'categories' => [ NotificationCategory::DOCUMENTS => NotificationLevel::LEGACY_EMAIL ] ],
				$user
		);

		$this->assertEquals(
				NotificationLevel::WEEKLY,
				$membership->getOwnNotificationLevel( NotificationCategory::DOCUMENTS ),
				'Assert a group set apart before #40 is read in the new vocabulary too'
		);
		$this->assertFalse(
				$membership->followsGeneralSettings(),
				'Assert it still counts as a group that says something of its own'
		);
	}

	public function testTheOldEmailValueIsNoLongerAccepted () {
		$user = new User();
		$user->setDefaultNotificationLevel( NotificationCategory::PAGES, NotificationLevel::LEGACY_EMAIL );

		$this->assertEquals(
				NotificationLevel::DAILY,
				$user->getDefaultNotificationLevel( NotificationCategory::PAGES ),
				'Assert a form cannot write back a value that no longer says its rhythm'
		);
	}

	public function testTheNoticeIsShownOnceAndOnlyToThoseTheChangeCrossed () {
		$newcomer = new User();

		$this->assertFalse(
				$newcomer->awaitsNotificationsNotice(),
				'Assert somebody who joined after the change is not told about it'
		);

		$before = new User();
		$before->setNotificationsSettings( [ 'noticePending' => TRUE ] );

		$this->assertTrue( $before->awaitsNotificationsNotice() );

		$before->markNotificationsNoticeSeen( new DateTime( '2026-08-21 09:00:00' ) );

		$this->assertFalse(
				$before->awaitsNotificationsNotice(),
				'Assert the notice does not come back page after page'
		);
	}
}
