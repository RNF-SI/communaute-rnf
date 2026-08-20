<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\RnfUserProvider;
use App\Service\RnfAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * `refreshUser` est appelée à **chaque requête**, pour recharger le compte que
 * porte la session. C'est le point où une connexion réussie peut se défaire.
 *
 * Elle ne savait lire que la session SSO. Une connexion par mot de passe n'en
 * crée pas : elle authentifiait une fois, puis levait UsernameNotFoundException
 * à la requête suivante — la personne était éjectée sans avoir rien vu, avec
 * une erreur qui ne disait pas pourquoi.
 */
class RnfUserProviderTest extends TestCase {
	/**
	 * @param bool                  $ssoSession
	 * @param \App\Entity\User|null $inDatabase
	 *
	 * @return \App\Security\RnfUserProvider
	 */
	private function provider ( $ssoSession, User $inDatabase = NULL ) {
		$auth = $this->createMock( RnfAuthService::class );
		$auth->method( 'isAuthenticated' )->willReturn( $ssoSession );
		$auth->method( 'getCurrentUser' )->willReturn( $ssoSession ? [ 'id_role' => 4242 ] : NULL );

		$repository = $this->createMock( ObjectRepository::class );
		$repository->method( 'find' )->willReturn( $inDatabase );
		$repository->method( 'findOneBy' )->willReturn( $inDatabase );

		$manager = $this->createMock( EntityManagerInterface::class );
		$manager->method( 'getRepository' )->willReturn( $repository );

		return new RnfUserProvider( $auth, $manager );
	}

	/**
	 * @param int $id
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $id = 7 ) {
		$user = new User();
		$user->setEmail( 'membre@example.org' );
		$user->setName( 'Manon Membre' );

		$property = new ReflectionProperty( User::class, 'id' );
		$property->setAccessible( TRUE );
		$property->setValue( $user, $id );

		return $user;
	}

	public function testWithoutAnSsoSessionTheAccountIsReloadedFromTheDatabase () {
		$stored = $this->user();

		$this->assertSame(
				$stored,
				$this->provider( FALSE, $stored )->refreshUser( $this->user() ),
				'Assert a password login survives the request that follows it'
		);
	}

	public function testWithoutAnSsoSessionAndWithoutTheAccountItIsRefused () {
		$this->expectException( UsernameNotFoundException::class );

		$this->provider( FALSE, NULL )->refreshUser( $this->user() );
	}

	public function testWithAnSsoSessionTheAccountIsResynchronised () {
		$stored = $this->user();

		// Le chemin SSO passe par loadUserByUsername, qui lit la session : ce
		// que ce test tient, c'est qu'il n'a pas changé.
		$this->assertSame( $stored, $this->provider( TRUE, $stored )->refreshUser( $this->user() ) );
	}

	public function testSomethingElseThanAnAccountIsRefused () {
		$this->expectException( UnsupportedUserException::class );

		$this->provider( FALSE )->refreshUser( $this->createMock( UserInterface::class ) );
	}

	public function testOnlyOurAccountsAreSupported () {
		$provider = $this->provider( FALSE );

		$this->assertTrue( $provider->supportsClass( User::class ) );
		$this->assertFalse( $provider->supportsClass( 'App\Entity\Usergroup' ) );
	}
}
