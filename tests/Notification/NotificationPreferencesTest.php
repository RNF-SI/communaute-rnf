<?php

namespace App\Tests\Notification;

use App\Entity\User;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Notification\NotificationRhythm;
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
					NotificationLevel::EMAIL,
					$membership->getNotificationLevel( $category ),
					sprintf( 'Assert "%s" warns by default, as asked in #34', $category )
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
				NotificationLevel::EMAIL,
				$membership->getNotificationLevel( NotificationCategory::DOCUMENTS ),
				'Assert the other categories are untouched'
		);
	}

	public function testChoosingACategoryClearsTheLegacyOptOut () {
		$membership = $this->membership( [ 'unsubscribed' => TRUE ] );
		$membership->setNotificationLevel( NotificationCategory::PAGES, NotificationLevel::EMAIL );

		$this->assertEquals(
				NotificationLevel::EMAIL,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert an explicit choice is not overridden by the old flag'
		);
		$this->assertEquals(
				NotificationLevel::EMAIL,
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
		$membership->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::EMAIL );

		$this->assertEquals(
				NotificationLevel::EMAIL,
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
		$membership = $this->membership( [], $this->member( NotificationLevel::EMAIL ) );

		foreach ( NotificationCategory::all() as $category ) {
			$membership->setNotificationLevel( $category, NotificationLevel::NONE );
		}

		$membership->followGeneralSettings();

		$this->assertTrue( $membership->followsGeneralSettings() );

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals( NotificationLevel::EMAIL, $membership->getNotificationLevel( $category ) );
		}
	}

	public function testALegacyOptOutIsNotUndoneByAGeneralSetting () {
		$membership = $this->membership( [ 'unsubscribed' => TRUE ], $this->member( NotificationLevel::EMAIL ) );

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
		$membership = $this->membership( [ 'unsubscribed' => TRUE ], $this->member( NotificationLevel::EMAIL ) );

		$this->assertEquals(
				array_fill_keys( NotificationCategory::all(), NotificationLevel::NONE ),
				$membership->getOwnNotificationLevels(),
				'Assert the settings page can show a muted group as muted, not as following a setting it ignores'
		);
	}

	public function testAskingAGroupToFollowDropsTheLegacyOptOut () {
		$membership = $this->membership( [ 'unsubscribed' => TRUE ], $this->member( NotificationLevel::EMAIL ) );
		$membership->followGeneralSettings();

		$this->assertEquals(
				NotificationLevel::EMAIL,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert asking for it explicitly does what it says'
		);
	}

	public function testAMemberWithoutAGeneralSettingKeepsTheBuiltInDefault () {
		$user = new User();

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals( NotificationLevel::EMAIL, $user->getDefaultNotificationLevel( $category ) );
		}
	}

	public function testAnUnknownCategoryOrLevelIsRefusedAsAGeneralSetting () {
		$user = new User();
		$user->setDefaultNotificationLevel( 'nonsense', NotificationLevel::NONE );
		$user->setDefaultNotificationLevel( NotificationCategory::PAGES, 'nonsense' );

		$this->assertEquals( NotificationLevel::EMAIL, $user->getDefaultNotificationLevel( NotificationCategory::PAGES ) );
	}

	public function testAnUnknownCategoryOrLevelIsRefused () {
		$membership = $this->membership();
		$membership->setNotificationLevel( 'nonsense', NotificationLevel::NONE );
		$membership->setNotificationLevel( NotificationCategory::PAGES, 'nonsense' );

		$this->assertEquals( NotificationLevel::EMAIL, $membership->getNotificationLevel( NotificationCategory::PAGES ) );
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
		$membership->setDiscussionOverride( 'followed', NotificationLevel::EMAIL );

		$this->assertEquals(
				NotificationLevel::EMAIL,
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
		$this->assertEquals( NotificationLevel::EMAIL, $membership->getLevelForDiscussion( 'another' ) );
	}

	public function testADiscussionChoiceCanBeTakenBack () {
		$membership = $this->membership();
		$membership->setDiscussionOverride( 'noisy', NotificationLevel::NONE );
		$membership->setDiscussionOverride( 'noisy', NULL );

		$this->assertNull( $membership->getDiscussionOverride( 'noisy' ) );
		$this->assertEquals(
				NotificationLevel::EMAIL,
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

	public function testDiscussionEmailsLeaveImmediatelyByDefault () {
		$user = new User();

		$this->assertEquals(
				NotificationRhythm::IMMEDIATE,
				$user->getDiscussionEmailRhythm(),
				'Assert nobody sees their current behaviour change without asking'
		);
	}

	public function testTheDiscussionRhythmCanBeChanged () {
		$user = new User();
		$user->setDiscussionEmailRhythm( NotificationRhythm::DIGEST );

		$this->assertEquals( NotificationRhythm::DIGEST, $user->getDiscussionEmailRhythm() );

		$user->setDiscussionEmailRhythm( 'nonsense' );

		$this->assertEquals(
				NotificationRhythm::DIGEST,
				$user->getDiscussionEmailRhythm(),
				'Assert an unknown rhythm is refused rather than stored'
		);
	}
}
