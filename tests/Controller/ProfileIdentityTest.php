<?php

namespace App\Tests\Controller;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #36 — GeoNature is the reference for the identity of an account that
 * signs in through the single sign-on: name and display name are rewritten at
 * every login. The profile form must not pretend otherwise.
 */
class ProfileIdentityTest extends WebTestCase {
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
	 * @param int|null $rnfIdRole
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $rnfIdRole = NULL ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Jeanne Reserve' );
		$user->setDisplayName( 'Jeanne R.' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRnfIdRole( $rnfIdRole );

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
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	private function openProfileForm () {
		$crawler = $this->client->request( 'GET', '/user/profile/edit' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		return $crawler;
	}

	public function testIdentityFieldsAreLockedForAnRnfAccount () {
		$this->user( 4242 );

		$crawler = $this->openProfileForm();

		$this->assertEquals(
				1,
				$crawler->filter( '#user_profile_name[disabled]' )->count(),
				'Assert the full name cannot be edited'
		);
		$this->assertEquals(
				1,
				$crawler->filter( '#user_profile_displayname[disabled]' )->count(),
				'Assert the display name cannot be edited'
		);
	}

	public function testTheReasonIsExplained () {
		$this->user( 4242 );

		$this->assertStringContainsString(
				'compte RNF',
				$this->openProfileForm()->filter( '.form-rows' )->text(),
				'Assert the user is told why the field is locked'
		);
	}

	public function testIdentityFieldsStayEditableWithoutRnfAccount () {
		$this->user( NULL );

		$crawler = $this->openProfileForm();

		$this->assertEquals(
				0,
				$crawler->filter( '#user_profile_name[disabled]' )->count(),
				'Assert an account without single sign-on keeps its editable name'
		);
		$this->assertEquals(
				0,
				$crawler->filter( '#user_profile_displayname[disabled]' )->count()
		);
	}

	public function testASubmittedNameIsIgnoredForAnRnfAccount () {
		$user = $this->user( 4242 );
		$id   = $user->getId();

		$crawler = $this->openProfileForm();
		$form    = $crawler->filter( 'form[name="user_profile"]' )->form();

		// A disabled field is not posted by a browser; forge it anyway to make
		// sure the server never trusts it.
		$this->client->request( 'POST', '/user/profile/edit', [
				'user_profile' => [
						'name'        => 'Nom Forgé',
						'displayname' => 'Pseudo Forgé',
						'city'        => 'Lille',
						'_token'      => $form->get( 'user_profile[_token]' )->getValue(),
				],
		] );

		$this->manager->clear();
		$user = $this->manager->getRepository( User::class )->find( $id );

		$this->assertEquals( 'Jeanne Reserve', $user->getName(), 'Assert the forged name is ignored' );
		$this->assertEquals( 'Jeanne R.', $user->getDisplayName() );
	}

	public function testOtherFieldsStayEditableForAnRnfAccount () {
		$user = $this->user( 4242 );
		$id   = $user->getId();

		$crawler = $this->openProfileForm();
		$form    = $crawler->filter( 'form[name="user_profile"]' )->form();

		$this->client->request( 'POST', '/user/profile/edit', [
				'user_profile' => [
						'city'   => 'Lille',
						'_token' => $form->get( 'user_profile[_token]' )->getValue(),
				],
		] );

		$this->manager->clear();

		$this->assertEquals(
				'Lille',
				$this->manager->getRepository( User::class )->find( $id )->getCity(),
				'Assert locking the identity does not lock the rest of the profile'
		);
	}
}
