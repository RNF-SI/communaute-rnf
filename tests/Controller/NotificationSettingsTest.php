<?php

namespace App\Tests\Controller;

use App\Entity\Discussion;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #34 — the screens where a member states what they want to hear about.
 *
 * The page is also what keeps somebody who sits in thirty groups from having
 * to copy the same choice thirty times: the general setting at the top is the
 * one that applies, and a group only appears further down to say how it
 * differs. Sending a group — or every group — back under that setting has to
 * work from the form, otherwise the copying comes back through the window.
 */
class NotificationSettingsTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Entity\Usergroup
	 */
	private $group;

	/**
	 * @var \App\Entity\User
	 */
	private $user;

	/**
	 * @var \App\Entity\UsergroupMembership
	 */
	private $membership;

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->group = new Usergroup();
		$this->group->setSlug( 'settings-group-' . uniqid() );
		$this->group->setName( 'Commission montagne' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );

		$this->user = new User();
		$this->user->setEmail( uniqid() . '@example.org' );
		$this->user->setName( 'Test User' );
		$this->user->setDisplayName( 'Test User' );
		$this->user->setCreatedAt( new DateTime() );
		$this->user->setStatus( User::STATUS_ACTIVE );
		$this->user->setPassword( '' );
		$this->user->setHasAgreedTermsOfUse( TRUE );
		$this->manager->persist( $this->user );

		$this->membership = new UsergroupMembership();
		$this->membership->setUser( $this->user );
		$this->membership->setUsergroup( $this->group );
		$this->membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$this->membership->setRole( UsergroupMembership::ROLE_USER );
		$this->membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $this->membership );
		$this->group->addMember( $this->membership );
		$this->user->addUsergroupMembership( $this->membership );

		$this->manager->flush();

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $this->user, NULL, self::FIREWALL, $this->user->getRoles() );
		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();
		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * Symfony resets the entity manager between two requests, which detaches
	 * everything built in setUp. Anything read after a request has to be
	 * fetched again.
	 *
	 * @return \App\Entity\UsergroupMembership
	 */
	private function reloadMembership () {
		return $this->manager->getRepository( UsergroupMembership::class )
							 ->find( $this->membership->getId() );
	}

	/**
	 * @return \App\Entity\User
	 */
	private function reloadUser () {
		return $this->manager->getRepository( User::class )->find( $this->user->getId() );
	}

	/**
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	private function openSettings () {
		$crawler = $this->client->request( 'GET', '/user/parameters/edit' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		return $crawler;
	}

	public function testTheSettingsOfferEveryCategoryOfEveryGroup () {
		$crawler = $this->openSettings();

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals(
					1,
					$crawler->filter( '#notif-' . $this->group->getId() . '-' . $category )->count(),
					sprintf( 'Assert "%s" can be set for this group', $category )
			);
		}
	}

	public function testACategoryCanBeChanged () {
		$crawler = $this->openSettings();
		$form    = $crawler->filter( '.notifications-settings form' )->form();

		$form[ 'notifications[groups][' . $this->group->getId() . '][' . NotificationCategory::PAGES . ']' ]
				->select( NotificationLevel::NONE );

		$this->client->submit( $form );

		$membership = $this->reloadMembership();

		$this->assertEquals(
				NotificationLevel::NONE,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert the choice is kept'
		);
		$this->assertEquals(
				NotificationLevel::DAILY,
				$membership->getNotificationLevel( NotificationCategory::DOCUMENTS ),
				'Assert the other categories are untouched'
		);
	}

	public function testTheGeneralSettingIsOfferedForEveryCategory () {
		$crawler = $this->openSettings();

		foreach ( NotificationCategory::all() as $category ) {
			$this->assertEquals(
					1,
					$crawler->filter( '#notif-default-' . $category )->count(),
					sprintf( 'Assert "%s" can be set once for every group', $category )
			);
		}
	}

	/**
	 * La messagerie n'envoie plus d'e-mail : la page ne propose donc que
	 * « rien » et « sur la plateforme ». Proposer un niveau que l'écriture
	 * refuse ferait une page qui ment sur ce qu'elle enregistre.
	 */
	public function testTheMailboxOffersNoEmailLevel () {
		$crawler = $this->openSettings();

		$offered = $crawler->filter( '#notif-default-' . NotificationCategory::MESSAGES . ' option' )
						   ->extract( [ 'value' ] );

		$this->assertSame( [ NotificationLevel::NONE, NotificationLevel::APP ], $offered );
	}

	public function testTheGroupsStillOfferEveryLevel () {
		$crawler = $this->openSettings();

		$offered = $crawler->filter( '#notif-default-' . NotificationCategory::DISCUSSIONS . ' option' )
						   ->extract( [ 'value' ] );

		$this->assertSame(
				NotificationLevel::all(),
				$offered,
				'Assert only the mailbox lost its e-mail levels'
		);
	}

	/**
	 * Et un formulaire forgé n'y arrive pas non plus : le niveau est ramené
	 * à ce que la catégorie sait tenir, plutôt qu'enregistré tel quel.
	 */
	public function testAnEmailLevelPostedOnTheMailboxIsBroughtBack () {
		$crawler = $this->openSettings();
		$form    = $crawler->filter( '.notifications-settings form' )->form();

		$values = $form->getPhpValues();

		$values[ 'notifications' ][ 'defaults' ][ NotificationCategory::MESSAGES ] = NotificationLevel::IMMEDIATE;

		$this->client->request( 'POST', $form->getUri(), $values );

		$this->assertEquals(
				NotificationLevel::APP,
				$this->reloadUser()->getDefaultNotificationLevel( NotificationCategory::MESSAGES )
		);
	}

	public function testTheGeneralSettingReachesAGroupThatSaysNothing () {
		$crawler = $this->openSettings();
		$form    = $crawler->filter( '.notifications-settings form' )->form();

		$form[ 'notifications[defaults][' . NotificationCategory::PAGES . ']' ]->select( NotificationLevel::NONE );

		$this->client->submit( $form );

		$membership = $this->reloadMembership();

		$this->assertEquals(
				NotificationLevel::NONE,
				$membership->getNotificationLevel( NotificationCategory::PAGES ),
				'Assert the group follows without anything being written on it'
		);
		$this->assertNull(
				$membership->getOwnNotificationLevel( NotificationCategory::PAGES ),
				'Assert the choice is not copied onto every group, which is the whole point'
		);
	}

	public function testAGroupCanBeSentBackToTheGeneralSetting () {
		$this->membership->setNotificationLevel( NotificationCategory::PAGES, NotificationLevel::NONE );
		$this->manager->flush();

		$crawler = $this->openSettings();
		$form    = $crawler->filter( '.notifications-settings form' )->form();

		$form[ 'notifications[groups][' . $this->group->getId() . '][' . NotificationCategory::PAGES . ']' ]
				->select( '' );

		$this->client->submit( $form );

		$this->assertNull(
				$this->reloadMembership()->getOwnNotificationLevel( NotificationCategory::PAGES ),
				'Assert « comme le réglage général » really lets go of the group setting'
		);
	}

	public function testEveryGroupCanBeSentBackAtOnce () {
		foreach ( NotificationCategory::all() as $category ) {
			$this->membership->setNotificationLevel( $category, NotificationLevel::NONE );
		}

		$this->manager->flush();

		$crawler = $this->openSettings();

		// Le bouton porte son propre nom : c'est lui, et non « Enregistrer »,
		// qui déclenche la remise à zéro.
		$this->client->submit( $crawler->filter( 'button[name="reset-groups"]' )->form() );

		$this->assertTrue(
				$this->reloadMembership()->followsGeneralSettings(),
				'Assert thirty groups set one by one can be taken back in one gesture'
		);
	}

	public function testTheSettingsSayWhetherAGroupFollowsOrNot () {
		$crawler = $this->openSettings();

		$this->assertStringNotContainsString(
				'notifications-settings--group-state__apart',
				$crawler->filter( '.notifications-settings--group .notifications-settings--group-state' )
						->attr( 'class' ),
				'Assert a group is announced as following before anything is said about it'
		);

		$this->membership->setNotificationLevel( NotificationCategory::PAGES, NotificationLevel::NONE );
		$this->manager->flush();

		$crawler = $this->openSettings();

		$this->assertStringContainsString(
				'notifications-settings--group-state__apart',
				$crawler->filter( '.notifications-settings--group .notifications-settings--group-state' )
						->attr( 'class' ),
				'Assert a group that differs is the one that catches the eye'
		);
	}

	public function testEmailsCanBeSwitchedOffEntirely () {
		$crawler = $this->openSettings();
		$form    = $crawler->filter( '.notifications-settings form' )->form();

		$form[ 'notifications[emails]' ]->untick();

		$this->client->submit( $form );

		$this->assertFalse( $this->reloadUser()->wantsEmails() );
	}

	/**
	 * Le rythme se choisit dans la même liste que le reste, catégorie par
	 * catégorie : il n'y a plus de réglage séparé. (#38)
	 */
	public function testTheRhythmIsChosenCategoryByCategory () {
		$crawler = $this->openSettings();
		$form    = $crawler->filter( '.notifications-settings form' )->form();

		$form[ 'notifications[defaults][' . NotificationCategory::DISCUSSIONS . ']' ]
				->select( NotificationLevel::IMMEDIATE );
		$form[ 'notifications[defaults][' . NotificationCategory::DOCUMENTS . ']' ]
				->select( NotificationLevel::WEEKLY );

		$this->client->submit( $form );

		$user = $this->reloadUser();

		$this->assertEquals(
				NotificationLevel::IMMEDIATE,
				$user->getDefaultNotificationLevel( NotificationCategory::DISCUSSIONS )
		);
		$this->assertEquals(
				NotificationLevel::WEEKLY,
				$user->getDefaultNotificationLevel( NotificationCategory::DOCUMENTS )
		);
	}

	public function testTheFiveChoicesAreOffered () {
		$crawler = $this->openSettings();

		$options = $crawler
				->filter( '#notif-default-' . NotificationCategory::PAGES . ' option' )
				->each( function ( $option ) {
					return $option->attr( 'value' );
				} );

		$this->assertEquals(
				NotificationLevel::all(),
				$options,
				'Assert the whole scale is offered: nothing, platform, immediate, daily, weekly'
		);
	}

	public function testTheOldSeparateRhythmFieldIsGone () {
		$crawler = $this->openSettings();

		$this->assertEquals(
				0,
				$crawler->filter( '#discussion-rhythm' )->count(),
				'Assert the rhythm is not asked twice, in two places that could disagree'
		);
	}

	public function testSettingsAreRefusedWithoutAValidToken () {
		$this->client->request( 'POST', '/user/parameters/edit', [
				'_token'        => 'forged',
				'notifications' => [ 'emails' => '' ],
		] );

		$this->assertTrue( $this->reloadUser()->wantsEmails(), 'Assert a forged post changes nothing' );
	}

	/**
	 * @return \App\Entity\Discussion
	 */
	private function discussion () {
		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'Une discussion' );
		$discussion->setUsergroup( $this->group );
		$discussion->setAuthor( $this->user );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );

		$this->manager->persist( $discussion );
		$this->manager->flush();

		return $discussion;
	}

	public function testADiscussionCanBeMuted () {
		$discussion = $this->discussion();

		$this->client->request( 'GET', sprintf(
				'/groups/%s/discussions/%s/follow/none',
				$this->group->getSlug(),
				$discussion->getUuid()
		) );

		$this->assertEquals(
				NotificationLevel::NONE,
				$this->reloadMembership()->getLevelForDiscussion( $discussion->getUuid() )
		);
	}

	public function testADiscussionCanBeFollowedThoughItsCategoryIsMuted () {
		$discussion = $this->discussion();

		$this->membership->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::NONE );
		$this->manager->flush();

		$this->client->request( 'GET', sprintf(
				'/groups/%s/discussions/%s/follow/%s',
				$this->group->getSlug(),
				$discussion->getUuid(),
				NotificationLevel::DAILY
		) );

		$this->assertEquals(
				NotificationLevel::DAILY,
				$this->reloadMembership()->getLevelForDiscussion( $discussion->getUuid() ),
				'Assert the special case of the issue can be reached from the interface'
		);
	}

	/**
	 * « Suivre » doit dire un rythme, maintenant qu'il y en a trois. Il
	 * reprend celui que le membre a choisi sur les discussions du groupe
	 * plutôt que de lui en imposer un. (#38)
	 */
	public function testFollowingADiscussionKeepsTheRhythmTheMemberChose () {
		$this->membership->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::WEEKLY );

		$this->assertEquals( NotificationLevel::WEEKLY, $this->membership->getFollowLevel() );

		$this->membership->setNotificationLevel( NotificationCategory::DISCUSSIONS, NotificationLevel::NONE );

		$this->assertEquals(
				NotificationLevel::DAILY,
				$this->membership->getFollowLevel(),
				'Assert a muted category falls back on the daily summary rather than on nothing'
		);
	}

	public function testADiscussionCanGoBackToFollowingItsCategory () {
		$discussion = $this->discussion();

		$this->client->request( 'GET', sprintf(
				'/groups/%s/discussions/%s/follow/none',
				$this->group->getSlug(),
				$discussion->getUuid()
		) );
		$this->client->request( 'GET', sprintf(
				'/groups/%s/discussions/%s/follow/default',
				$this->group->getSlug(),
				$discussion->getUuid()
		) );

		$this->assertNull( $this->reloadMembership()->getDiscussionOverride( $discussion->getUuid() ) );
	}

	public function testTheDiscussionPageOffersToMuteIt () {
		$discussion = $this->discussion();

		$crawler = $this->client->request( 'GET', sprintf(
				'/groups/%s/discussions/%s',
				$this->group->getSlug(),
				$discussion->getUuid()
		) );

		// Le lien de suivi, et non « le lien du panneau » : celui qui a ouvert
		// le sujet — ou l'anime — y trouve aussi « Renommer le sujet », et les
		// deux partagent la même colonne.
		$this->assertEquals(
				1,
				$crawler->filter( '.discussion-follow a[href*="/follow/"]' )->count(),
				'Assert the button is offered to a member'
		);
	}
}
