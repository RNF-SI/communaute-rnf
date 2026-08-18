<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\LoginFormAuthenticator;
use App\Security\RnfAuthenticatorGuard;
use App\Service\RnfAuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the user lands right after logging in, through either of the two
 * authentication mechanisms.
 */
class LoginRedirectTest extends TestCase {
	private const PROVIDER_KEY = 'main';

	private const ROUTES = [
			'user_groups'    => '/user/groups',
			'user_dashboard' => '/user/dashboard',
			'homepage'       => '/',
			'rnf_auth_login' => '/auth/login',
	];

	/**
	 * @return \Symfony\Component\Routing\RouterInterface
	 */
	private function router () {
		$router = $this->createMock( RouterInterface::class );
		$router->method( 'generate' )
			   ->willReturnCallback( function ( $name ) {
				   return self::ROUTES[ $name ];
			   } );

		return $router;
	}

	/**
	 * @param string|null $targetPath path the user was heading to before login
	 *
	 * @return \Symfony\Component\HttpFoundation\Request
	 */
	private function request ( $targetPath = NULL ) {
		$session = new Session( new MockArraySessionStorage() );

		if ( $targetPath !== NULL ) {
			$session->set( '_security.' . self::PROVIDER_KEY . '.target_path', $targetPath );
		}

		$request = new Request();
		$request->setSession( $session );

		return $request;
	}

	/**
	 * @return \Symfony\Component\Security\Core\Authentication\Token\TokenInterface
	 */
	private function token () {
		$token = $this->createMock( TokenInterface::class );
		$token->method( 'getUser' )->willReturn( new User() );

		return $token;
	}

	/**
	 * @return \App\Security\LoginFormAuthenticator
	 */
	private function formAuthenticator () {
		return new LoginFormAuthenticator(
				$this->createMock( EntityManagerInterface::class ),
				$this->router(),
				$this->createMock( CsrfTokenManagerInterface::class ),
				$this->createMock( UserPasswordEncoderInterface::class ),
				$this->createMock( TranslatorInterface::class )
		);
	}

	/**
	 * @return \App\Security\RnfAuthenticatorGuard
	 */
	private function rnfGuard () {
		return new RnfAuthenticatorGuard(
				$this->createMock( RnfAuthService::class ),
				$this->router(),
				$this->createMock( CsrfTokenManagerInterface::class )
		);
	}

	public function testFormLoginLandsOnMyGroups () {
		$response = $this->formAuthenticator()
						 ->onAuthenticationSuccess( $this->request(), $this->token(), self::PROVIDER_KEY );

		$this->assertInstanceOf( RedirectResponse::class, $response );
		$this->assertEquals(
				'/user/groups',
				$response->getTargetUrl(),
				'Assert form login lands on the user groups page'
		);
	}

	public function testFormLoginHonoursTheRequestedPage () {
		$response = $this->formAuthenticator()
						 ->onAuthenticationSuccess(
								 $this->request( '/groups/commission/discussions/abc' ),
								 $this->token(),
								 self::PROVIDER_KEY
						 );

		$this->assertEquals(
				'/groups/commission/discussions/abc',
				$response->getTargetUrl(),
				'Assert form login sends the user back to the page they asked for'
		);
	}

	public function testRnfLoginLandsOnMyGroups () {
		$response = $this->rnfGuard()
						 ->onAuthenticationSuccess( $this->request(), $this->token(), self::PROVIDER_KEY );

		$this->assertInstanceOf( RedirectResponse::class, $response );
		$this->assertEquals(
				'/user/groups',
				$response->getTargetUrl(),
				'Assert RNF login lands on the user groups page'
		);
	}

	public function testRnfLoginHonoursTheRequestedPage () {
		$response = $this->rnfGuard()
						 ->onAuthenticationSuccess(
								 $this->request( '/groups/commission/documents' ),
								 $this->token(),
								 self::PROVIDER_KEY
						 );

		$this->assertEquals(
				'/groups/commission/documents',
				$response->getTargetUrl(),
				'Assert a deep link coming from a notification e-mail survives the RNF login'
		);
	}

	public function testRnfLoginNeverBouncesBackToTheLoginPage () {
		$response = $this->rnfGuard()
						 ->onAuthenticationSuccess(
								 $this->request( '/auth/login' ),
								 $this->token(),
								 self::PROVIDER_KEY
						 );

		$this->assertEquals(
				'/user/groups',
				$response->getTargetUrl(),
				'Assert a stored target path pointing at the login page does not loop'
		);
	}

	public function testRnfLoginClearsTheStoredTargetPath () {
		$request = $this->request( '/groups/commission/documents' );

		$this->rnfGuard()->onAuthenticationSuccess( $request, $this->token(), self::PROVIDER_KEY );

		$this->assertFalse(
				$request->getSession()->has( '_security.' . self::PROVIDER_KEY . '.target_path' ),
				'Assert the target path is consumed, so the next login does not reuse it'
		);
	}
}
