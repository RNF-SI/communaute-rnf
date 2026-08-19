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
 */
class NotificationPreferencesTest extends TestCase {
	/**
	 * @param array|null $settings
	 *
	 * @return \App\Entity\UsergroupMembership
	 */
	private function membership ( $settings = [] ) {
		$membership = new UsergroupMembership();
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setNotificationsSettings( $settings );

		return $membership;
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
