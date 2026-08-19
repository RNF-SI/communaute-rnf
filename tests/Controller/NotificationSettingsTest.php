<?php

namespace App\Tests\Controller;

use App\Entity\Discussion;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Notification\NotificationRhythm;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #34 — the screens where a member states what they want to hear about.
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
				NotificationLevel::EMAIL,
				$membership->getNotificationLevel( NotificationCategory::DOCUMENTS ),
				'Assert the other categories are untouched'
		);
	}

	public function testEmailsCanBeSwitchedOffEntirely () {
		$crawler = $this->openSettings();
		$form    = $crawler->filter( '.notifications-settings form' )->form();

		$form[ 'notifications[emails]' ]->untick();

		$this->client->submit( $form );

		$this->assertFalse( $this->reloadUser()->wantsEmails() );
	}

	public function testTheDiscussionRhythmCanBeChanged () {
		$crawler = $this->openSettings();
		$form    = $crawler->filter( '.notifications-settings form' )->form();

		$form[ 'notifications[discussionRhythm]' ]->select( NotificationRhythm::DIGEST );

		$this->client->submit( $form );

		$this->assertEquals( NotificationRhythm::DIGEST, $this->reloadUser()->getDiscussionEmailRhythm() );
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
				'/groups/%s/discussions/%s/follow/email',
				$this->group->getSlug(),
				$discussion->getUuid()
		) );

		$this->assertEquals(
				NotificationLevel::EMAIL,
				$this->reloadMembership()->getLevelForDiscussion( $discussion->getUuid() ),
				'Assert the special case of the issue can be reached from the interface'
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

		$this->assertEquals(
				1,
				$crawler->filter( '.discussion-follow a' )->count(),
				'Assert the button is offered to a member'
		);
	}
}
