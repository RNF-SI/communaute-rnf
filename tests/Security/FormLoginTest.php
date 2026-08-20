<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\LoginFormAuthenticator;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * La connexion par mot de passe est **éteinte par défaut**.
 *
 * Sur les serveurs, l'identité fait autorité chez GeoNature et le SSO connecte
 * seul. Ce chemin n'existe que là où il faut pouvoir entrer avec les comptes
 * des données de test, qui n'existent pas dans GeoNature — une préproduction
 * de recette, un poste de développement.
 *
 * Deux règles se vérifient ici, et elles comptent : éteint, l'authentificateur
 * ne regarde même pas la requête ; allumé, il n'ouvre jamais un compte venu du
 * SSO, dont la colonne de mot de passe est vide.
 */
class FormLoginTest extends TestCase {
	/**
	 * @param string $enabled
	 *
	 * @return \App\Security\LoginFormAuthenticator
	 */
	private function authenticator ( $enabled ) {
		return new LoginFormAuthenticator(
				$this->createMock( EntityManagerInterface::class ),
				$this->createMock( RouterInterface::class ),
				$this->createMock( CsrfTokenManagerInterface::class ),
				$this->createMock( UserPasswordEncoderInterface::class ),
				$this->createMock( TranslatorInterface::class ),
				$enabled
		);
	}

	/**
	 * Une requête de connexion complète et bien formée.
	 *
	 * @return \Symfony\Component\HttpFoundation\Request
	 */
	private function request () {
		$request = Request::create( '/user/login', 'POST', [
				'email'       => 'membre@example.org',
				'password'    => 'test',
				'_csrf_token' => 'un-jeton',
		] );

		$request->attributes->set( '_route', 'user_login' );

		return $request;
	}

	/**
	 * @param string $password
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $password ) {
		$user = new User();
		$user->setEmail( 'membre@example.org' );
		$user->setCreatedAt( new DateTime() );
		$user->setPassword( $password );

		return $user;
	}

	/**************************************************
	 * L'INTERRUPTEUR
	 **************************************************/

	public function testItIsOffByDefault () {
		$this->assertFalse( $this->authenticator( '' )->isEnabled() );
	}

	public function testAnEmptyFlagLeavesItOff () {
		$this->assertFalse(
				$this->authenticator( '0' )->supports( $this->request() ),
				'Assert a complete, well-formed login request is ignored while the switch is off'
		);
	}

	public function testTheFlagTurnsItOn () {
		$this->assertTrue( $this->authenticator( '1' )->supports( $this->request() ) );
		$this->assertTrue( $this->authenticator( 'true' )->supports( $this->request() ) );
	}

	public function testAnythingElseLeavesItOff () {
		foreach ( [ 'non', 'oui', 'off', '2', 'FORM_LOGIN_ENABLED' ] as $value ) {
			$this->assertFalse(
					$this->authenticator( $value )->isEnabled(),
					sprintf( 'Assert "%s" does not read as a yes', $value )
			);
		}
	}

	public function testAnotherRouteIsNeverAnswered () {
		$request = $this->request();
		$request->attributes->set( '_route', 'rnf_auth_login' );

		$this->assertFalse(
				$this->authenticator( '1' )->supports( $request ),
				'Assert the two authenticators do not fight over the single sign-on route'
		);
	}

	public function testAGetIsNotALoginAttempt () {
		$request = Request::create( '/user/login', 'GET' );
		$request->attributes->set( '_route', 'user_login' );

		$this->assertFalse( $this->authenticator( '1' )->supports( $request ) );
	}

	public function testARequestWithoutATokenIsNotAnswered () {
		$request = Request::create( '/user/login', 'POST', [
				'email'    => 'membre@example.org',
				'password' => 'test',
		] );

		$request->attributes->set( '_route', 'user_login' );

		$this->assertFalse( $this->authenticator( '1' )->supports( $request ) );
	}

	/**************************************************
	 * LES COMPTES VENUS DU SSO
	 **************************************************/

	public function testAnAccountWithoutAPasswordIsNeverOpened () {
		$this->assertFalse(
				$this->authenticator( '1' )->checkCredentials(
						[ 'password' => 'nimporte quoi' ],
						$this->user( '' )
				),
				'Assert a GeoNature account, whose password column is empty, cannot be opened this way'
		);
	}

	public function testAPasswordMadeOfSpacesIsNoPassword () {
		$this->assertFalse(
				$this->authenticator( '1' )->checkCredentials( [ 'password' => '   ' ], $this->user( '   ' ) )
		);
	}

	public function testAnAccountWithAPasswordIsCheckedAgainstIt () {
		$encoder = $this->createMock( UserPasswordEncoderInterface::class );
		$encoder->expects( $this->once() )
				->method( 'isPasswordValid' )
				->willReturn( TRUE );

		$authenticator = new LoginFormAuthenticator(
				$this->createMock( EntityManagerInterface::class ),
				$this->createMock( RouterInterface::class ),
				$this->createMock( CsrfTokenManagerInterface::class ),
				$encoder,
				$this->createMock( TranslatorInterface::class ),
				'1'
		);

		$this->assertTrue(
				$authenticator->checkCredentials( [ 'password' => 'test' ], $this->user( '$2y$hash' ) )
		);
	}
}
