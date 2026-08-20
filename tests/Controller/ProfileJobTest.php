<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\UserAnonymize;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #30 — ce qu'on cherche dans un annuaire professionnel : « nom prénom,
 * fonction, RN(s) gérée(s), OG ou structure ». La biographie libre héritée de
 * NaturAdapt ne le disait pas, elle laisse la place à trois champs.
 */
class ProfileJobTest extends WebTestCase {
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
	 * @param string $name
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $name = 'Jeanne Reserve' ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( $name );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $user
	 */
	private function logIn ( User $user ) {
		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * @param array $fields
	 */
	private function submitProfile ( array $fields ) {
		$crawler = $this->client->request( 'GET', '/user/profile/edit' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$form = $crawler->filter( 'form[name="user_profile"]' )->form();

		$this->client->request( 'POST', '/user/profile/edit', [
				'user_profile' => array_merge( [
						'name'   => 'Jeanne Reserve',
						'_token' => $form->get( 'user_profile[_token]' )->getValue(),
				], $fields ),
		] );
	}

	/**
	 * @param int $id
	 *
	 * @return \App\Entity\User
	 */
	private function reload ( $id ) {
		$this->manager->clear();

		return $this->manager->getRepository( User::class )->find( $id );
	}

	public function testTheThreeFieldsAreOfferedInTheProfileForm () {
		$this->logIn( $this->user() );

		$crawler = $this->client->request( 'GET', '/user/profile/edit' );

		$this->assertEquals( 1, $crawler->filter( '#user_profile_jobTitle' )->count() );
		$this->assertEquals( 1, $crawler->filter( '#user_profile_organisation' )->count() );
		$this->assertEquals( 1, $crawler->filter( '#user_profile_reserves' )->count() );
	}

	public function testTheBiographyIsGone () {
		$this->logIn( $this->user() );

		$crawler = $this->client->request( 'GET', '/user/profile/edit' );

		$this->assertEquals(
				0,
				$crawler->filter( '#user_profile_bio' )->count(),
				'Assert the field nobody knew what to write in is no longer asked for'
		);
	}

	public function testTheFieldsAreSaved () {
		$user = $this->user();
		$id   = $user->getId();

		$this->logIn( $user );
		$this->submitProfile( [
				'jobTitle'     => 'Conservatrice de réserve naturelle',
				'organisation' => 'Conservatoire d’espaces naturels',
				'reserves'     => 'RN de la Bassée',
		] );

		$saved = $this->reload( $id );

		$this->assertEquals( 'Conservatrice de réserve naturelle', $saved->getJobTitle() );
		$this->assertEquals( 'Conservatoire d’espaces naturels', $saved->getOrganisation() );
		$this->assertEquals( 'RN de la Bassée', $saved->getReserves() );
	}

	public function testABlankFieldIsStoredAsNothing () {
		$user = $this->user();
		$id   = $user->getId();

		$this->logIn( $user );
		$this->submitProfile( [ 'jobTitle' => '   ' ] );

		$this->assertNull(
				$this->reload( $id )->getJobTitle(),
				'Assert whitespace does not make a profile look filled in'
		);
	}

	public function testTheFunctionShowsOnTheDirectoryProfile () {
		$member = $this->user();
		$member->setJobTitle( 'Conservatrice de réserve naturelle' );
		$member->setOrganisation( 'Conservatoire d’espaces naturels' );
		$this->manager->flush();

		$this->logIn( $this->user() );

		$crawler = $this->client->request( 'GET', '/members/' . $member->getId() );

		$this->assertStringContainsString(
				'Conservatrice de réserve naturelle',
				$crawler->filter( '.user--job' )->text()
		);
		$this->assertStringContainsString(
				'Conservatoire d’espaces naturels',
				$crawler->filter( '.user--job' )->text(),
				'Assert the organisation is read next to the function, not somewhere else'
		);
	}

	public function testTheReservesShowOnTheDirectoryProfile () {
		$member = $this->user();
		$member->setReserves( 'RN de la Bassée, RN du Marais' );
		$this->manager->flush();

		$this->logIn( $this->user() );

		$crawler = $this->client->request( 'GET', '/members/' . $member->getId() );

		$this->assertStringContainsString(
				'RN de la Bassée',
				$crawler->filter( '.user--reserves' )->text()
		);
	}

	public function testAProfileWithoutThoseFieldsShowsNoEmptyLine () {
		$member = $this->user();

		$this->logIn( $this->user() );

		$crawler = $this->client->request( 'GET', '/members/' . $member->getId() );

		$this->assertEquals( 0, $crawler->filter( '.user--job' )->count() );
		$this->assertEquals( 0, $crawler->filter( '.user--reserves' )->count() );
	}

	public function testTheDirectoryCanBeSearchedByFunction () {
		$member = $this->user( 'Camille Ornithologue' );
		$member->setJobTitle( 'Ornithologue' );
		$this->manager->flush();

		$this->logIn( $this->user( 'Paul Chercheur' ) );

		$crawler = $this->client->request( 'GET', '/members?form[query]=Ornithologue' );
		$listing = $crawler->filter( '.main__members-list' )->text();

		$this->assertStringContainsString(
				'Camille Ornithologue',
				$listing,
				'Assert somebody can be found by what they do, not only by their name'
		);
		$this->assertStringNotContainsString(
				'Paul Chercheur',
				$listing,
				'Assert the filter still filters'
		);
	}

	public function testAnonymisingAnAccountClearsTheFields () {
		$user = $this->user();
		$user->setJobTitle( 'Ornithologue' );
		$user->setOrganisation( 'Conservatoire' );
		$user->setReserves( 'RN de la Bassée' );
		$user->setPhone( '01 23 45 67 89' );
		$this->manager->flush();

		self::$container->get( UserAnonymize::class )->anonymize( $user );
		$this->manager->flush();

		$this->assertNull( $user->getJobTitle() );
		$this->assertNull( $user->getOrganisation() );
		$this->assertNull( $user->getReserves() );
		$this->assertNull( $user->getPhone() );
	}
}
