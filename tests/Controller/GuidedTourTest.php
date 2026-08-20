<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\GuidedTour;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #39 — la visite guidée se lance d'elle-même tant qu'elle n'a pas été
 * vue, puis plus jamais sans qu'on la redemande.
 *
 * Fermer vaut avoir vu : quelqu'un qui la referme au premier écran a dit ce
 * qu'il pensait de la proposition. La relancer à chaque page serait la
 * transformer en harcèlement — c'est la règle que ce test tient.
 */
class GuidedTourTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param \DateTime|null $seenAt
	 *
	 * @return \App\Entity\User
	 */
	private function user ( DateTime $seenAt = NULL ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setTourSeenAt( $seenAt );

		$this->manager->persist( $user );
		$this->manager->flush();

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );

		return $user;
	}

	/**
	 * @param string $url
	 *
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	private function open ( $url = '/user/groups' ) {
		$crawler = $this->client->request( 'GET', $url );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		return $crawler;
	}

	/**
	 * @param \Symfony\Component\DomCrawler\Crawler $crawler
	 *
	 * @return array
	 */
	private function steps ( $crawler ) {
		$container = $crawler->filter( '#guided-tour' );

		if ( $container->count() === 0 ) {
			return [];
		}

		return json_decode( $container->attr( 'data-steps' ), TRUE );
	}

	public function testTheTourIsOfferedToSomebodyWhoNeverSawIt () {
		$this->user();

		$this->assertNotEmpty(
				$this->steps( $this->open() ),
				'Assert arriving on the platform proposes the tour without asking'
		);
	}

	public function testTheTourIsNotOfferedAgainOnceSeen () {
		$this->user( new DateTime( '-1 day' ) );

		$this->assertEquals(
				[],
				$this->steps( $this->open() ),
				'Assert somebody who already saw it is left alone'
		);
	}

	public function testTheTourCanBeAskedForAgain () {
		$this->user( new DateTime( '-1 day' ) );

		$this->assertNotEmpty(
				$this->steps( $this->open( '/user/groups?tour=1' ) ),
				'Assert the settings link can bring it back'
		);
	}

	public function testTheReplayLinkLandsWhereTheTourHasSomethingToShow () {
		$this->user( new DateTime( '-1 day' ) );

		$this->client->request( 'GET', '/user/tour' );

		$this->assertTrue( $this->client->getResponse()->isRedirect() );
		$this->assertStringContainsString(
				'tour=1',
				$this->client->getResponse()->headers->get( 'Location' )
		);
	}

	public function testTheSettingsOfferToWatchItAgain () {
		$this->user( new DateTime( '-1 day' ) );

		$crawler = $this->open( '/user/parameters/edit' );

		$this->assertEquals( 1, $crawler->filter( 'a.tour-replay' )->count() );
	}

	public function testClosingTheTourCountsAsHavingSeenIt () {
		$user = $this->user();
		$id   = $user->getId();

		$crawler = $this->open();
		$token   = $crawler->filter( '#guided-tour' )->attr( 'data-token' );

		$this->client->request( 'POST', '/user/tour/seen', [ '_token' => $token ] );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$this->manager->clear();

		$this->assertNotNull(
				$this->manager->getRepository( User::class )->find( $id )->getTourSeenAt()
		);
	}

	public function testTheDateOfTheFirstTimeIsKept () {
		$first = new DateTime( '-10 days' );
		$user  = $this->user( $first );
		$id    = $user->getId();

		$crawler = $this->open( '/user/groups?tour=1' );

		$this->client->request( 'POST', '/user/tour/seen', [
				'_token' => $crawler->filter( '#guided-tour' )->attr( 'data-token' ),
		] );

		$this->manager->clear();

		$this->assertEquals(
				$first->format( 'Y-m-d' ),
				$this->manager->getRepository( User::class )->find( $id )->getTourSeenAt()->format( 'Y-m-d' ),
				'Assert watching it again does not rewrite when it was first seen'
		);
	}

	public function testMarkingItSeenNeedsATokenOfItsOwn () {
		$user = $this->user();
		$id   = $user->getId();

		$this->client->request( 'POST', '/user/tour/seen', [ '_token' => 'forgé' ] );

		$this->assertEquals( 403, $this->client->getResponse()->getStatusCode() );

		$this->manager->clear();

		$this->assertNull( $this->manager->getRepository( User::class )->find( $id )->getTourSeenAt() );
	}

	public function testTheTourIsNotShownToSomebodyWhoIsNotSignedIn () {
		$crawler = $this->client->request( 'GET', '/' );

		$this->assertEquals(
				0,
				$crawler->filter( '#guided-tour' )->count(),
				'Assert a tour about groups and account settings waits for an account'
		);
	}

	public function testEveryStepIsWrittenInBothHalves () {
		$steps = self::$container->get( GuidedTour::class )->steps();

		$this->assertNotEmpty( $steps );

		foreach ( $steps as $step ) {
			$this->assertNotEmpty( $step[ 'title' ], sprintf( 'Assert "%s" has a title', $step[ 'key' ] ) );
			$this->assertNotEmpty( $step[ 'body' ], sprintf( 'Assert "%s" has a body', $step[ 'key' ] ) );

			// Une clé de traduction manquante ressort telle quelle : c'est
			// exactement ce qu'on ne veut pas lire dans une visite d'accueil.
			$this->assertStringNotContainsString( 'pages.tour.', $step[ 'title' ] );
			$this->assertStringNotContainsString( 'pages.tour.', $step[ 'body' ] );
		}
	}

	public function testAStepThatSendsSomewhereSaysWhere () {
		foreach ( self::$container->get( GuidedTour::class )->steps() as $step ) {
			if ( !isset( $step[ 'url' ] ) ) {
				continue;
			}

			$this->assertNotEmpty( $step[ 'label' ], sprintf( 'Assert the link of "%s" is labelled', $step[ 'key' ] ) );
			$this->assertStringStartsWith( '/', $step[ 'url' ] );
		}
	}
}
