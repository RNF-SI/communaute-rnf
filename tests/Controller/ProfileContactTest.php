<?php

namespace App\Tests\Controller;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #27 — les coordonnées de contact de l'annuaire. Chacun publie ce
 * qu'il veut : un téléphone s'il le saisit, une adresse qu'il peut retirer.
 */
class ProfileContactTest extends WebTestCase {
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
	 * @param array $roles
	 *
	 * @return \App\Entity\User
	 */
	private function user ( array $roles = [] ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Jeanne Reserve' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( $roles );

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

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	private function openProfileOf ( User $user ) {
		$crawler = $this->client->request( 'GET', '/members/' . $user->getId() );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		return $crawler;
	}

	public function testAnAddressIsShownToTheOtherMembersByDefault () {
		$member = $this->user();

		$this->logIn( $this->user() );

		$this->assertStringContainsString(
				$member->getEmail(),
				$this->openProfileOf( $member )->filter( '.user-contact' )->text(),
				'Assert an account that changed nothing keeps the address it always showed'
		);
	}

	public function testAMemberCanTakeTheirAddressOffTheirProfile () {
		$member = $this->user();
		$id     = $member->getId();

		$this->logIn( $member );
		// Une case décochée n'est pas postée : son absence est le refus.
		$this->submitProfile( [] );

		$this->assertFalse( $this->reload( $id )->isEmailVisible() );
	}

	public function testAHiddenAddressDisappearsFromTheProfile () {
		$member = $this->user();
		$member->setEmailVisible( FALSE );
		$this->manager->flush();

		$this->logIn( $this->user() );

		$contact = $this->openProfileOf( $member );

		$this->assertStringNotContainsString(
				$member->getEmail(),
				$contact->filter( '.user-contact' )->text(),
				'Assert the address is no longer readable'
		);
		$this->assertEquals(
				0,
				$contact->filter( '.user-contact a[href^="mailto:"]' )->count(),
				'Assert the contact button does not offer to write either'
		);
	}

	public function testAPlatformAdministratorStillReachesAHiddenAddress () {
		$member = $this->user();
		$member->setEmailVisible( FALSE );
		$this->manager->flush();

		$this->logIn( $this->user( [ User::ROLE_ADMIN ] ) );

		$this->assertStringContainsString(
				$member->getEmail(),
				$this->openProfileOf( $member )->filter( '.user-contact' )->text(),
				'Assert moderating the platform still means being able to reach an account'
		);
	}

	public function testAPhoneNumberIsSavedFromTheProfileForm () {
		$member = $this->user();
		$id     = $member->getId();

		$this->logIn( $member );
		$this->submitProfile( [ 'phone' => '01 23 45 67 89', 'emailVisible' => '1' ] );

		$this->assertEquals( '01 23 45 67 89', $this->reload( $id )->getPhone() );
	}

	public function testAPhoneNumberIsShownToTheOtherMembers () {
		$member = $this->user();
		$member->setPhone( '01 23 45 67 89' );
		$this->manager->flush();

		$this->logIn( $this->user() );

		$crawler = $this->openProfileOf( $member );

		$this->assertEquals(
				1,
				$crawler->filter( '.user--phone a[href="tel:0123456789"]' )->count(),
				'Assert the number can be dialed from a phone'
		);
		$this->assertStringContainsString( '01 23 45 67 89', $crawler->filter( '.user--phone' )->text() );
	}

	public function testNoPhoneLineWithoutANumber () {
		$member = $this->user();

		$this->logIn( $this->user() );

		$this->assertEquals(
				0,
				$this->openProfileOf( $member )->filter( '.user--phone' )->count(),
				'Assert an empty field leaves no empty line behind'
		);
	}

	public function testAProfileWithoutAnyContactDetailSaysSo () {
		$member = $this->user();
		$member->setEmailVisible( FALSE );
		$this->manager->flush();

		$this->logIn( $this->user() );

		$this->assertStringContainsString(
				'coordonnées',
				$this->openProfileOf( $member )->filter( '.user-contact' )->text(),
				'Assert a silent panel does not read as a bug'
		);
	}

	public function testABlankPhoneNumberIsStoredAsNothing () {
		$member = $this->user();
		$id     = $member->getId();

		$this->logIn( $member );
		$this->submitProfile( [ 'phone' => '   ' ] );

		$this->assertNull(
				$this->reload( $id )->getPhone(),
				'Assert whitespace does not make the profile look like it has a number'
		);
	}
}
