<?php

namespace App\Tests\Security;

use App\EventSubscriber\SessionCookieRefreshSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Toute l'authentification tient dans la session PHP : ni « remember me », ni
 * jeton persistant, rien qui rattrape son expiration. Ce que règle
 * framework.yaml est donc, très exactement, la durée pendant laquelle on reste
 * connecté — et les valeurs par défaut de PHP la ramenaient à vingt-quatre
 * minutes d'inactivité, sur un répertoire partagé avec les autres sites de la
 * machine.
 */
class SessionLifetimeTest extends TestCase {
	/**
	 * @return array
	 */
	private function sessionConfig () {
		$config = Yaml::parseFile( dirname( __DIR__, 2 ) . '/config/packages/framework.yaml' );

		$this->assertArrayHasKey( 'session', $config[ 'framework' ], 'Assert session support is configured' );

		return $config[ 'framework' ][ 'session' ];
	}

	/**
	 * PHP décidait seul : son save_path, partagé, et son gc_maxlifetime de
	 * 1440 secondes. Sur Debian, un cron balaie ce répertoire selon le php.ini
	 * de la CLI — celui d'un voisin suffisait à nous déconnecter.
	 */
	public function testSessionsAreStoredInOurOwnDirectory () {
		$session = $this->sessionConfig();

		$this->assertSame(
				'session.handler.native_file',
				$session[ 'handler_id' ] ?? NULL,
				'Assert the session handler is ours, not whatever php.ini names'
		);

		$this->assertStringContainsString(
				'%kernel.project_dir%',
				(string) ( $session[ 'save_path' ] ?? '' ),
				'Assert sessions are written inside the project'
		);

		// %kernel.cache_dir% est le défaut de Symfony, et `cache:clear`
		// déconnecterait tout le monde à chaque déploiement.
		$this->assertStringNotContainsString(
				'cache_dir',
				(string) ( $session[ 'save_path' ] ?? '' ),
				'Assert a cache:clear does not log everybody out'
		);
	}

	/**
	 * Le cookie et le fichier doivent expirer ensemble : un cookie plus long
	 * que la session désigne un fichier déjà ramassé, et l'on est déconnecté
	 * sans comprendre.
	 */
	public function testCookieAndFileExpireTogether () {
		$session = $this->sessionConfig();

		$this->assertSame(
				$session[ 'cookie_lifetime' ] ?? NULL,
				$session[ 'gc_maxlifetime' ] ?? NULL,
				'Assert the cookie does not outlive the session file'
		);
	}

	/**
	 * Debian met gc_probability à 0 et confie le ménage à son cron, qui ignore
	 * notre répertoire : sans ramassage à nous, il grossit sans fin.
	 */
	public function testSessionsAreGarbageCollected () {
		$session = $this->sessionConfig();

		$this->assertGreaterThan(
				0,
				(int) ( $session[ 'gc_probability' ] ?? 0 ),
				'Assert we collect our own session files'
		);
	}

	/**
	 * @return int
	 */
	private function configuredLifetime () {
		$env = ( new Dotenv() )->parse( file_get_contents( dirname( __DIR__, 2 ) . '/.env' ) );

		$this->assertArrayHasKey( 'SESSION_LIFETIME', $env, 'Assert the lifetime has a documented default' );

		return (int) $env[ 'SESSION_LIFETIME' ];
	}

	public function testDefaultLifetimeIsCountedInDays () {
		$this->assertGreaterThanOrEqual(
				86400,
				$this->configuredLifetime(),
				'Assert nobody is logged out in the middle of writing'
		);
	}

	/**
	 * PHP n'émet le cookie qu'en créant la session : sans ce rappel,
	 * `cookie_lifetime` serait un délai absolu et quelqu'un qui vient tous les
	 * jours serait tout de même déconnecté au trentième.
	 */
	public function testTheCookieDeadlineIsPushedBackOnEveryVisit () {
		$session  = $this->startedSession();
		$request  = $this->requestCarrying( $session, $session->getId() );
		$response = new Response();

		$this->refresh( $request, $response );

		$cookies = $response->headers->getCookies();

		$this->assertCount( 1, $cookies, 'Assert the session cookie is sent again' );
		$this->assertSame( $session->getName(), $cookies[ 0 ]->getName() );
		$this->assertSame( $session->getId(), $cookies[ 0 ]->getValue() );
		$this->assertGreaterThan(
				time() + $this->configuredLifetime() - 60,
				$cookies[ 0 ]->getExpiresTime(),
				'Assert the deadline is counted from this visit'
		);
	}

	/**
	 * Le cookie reposé doit avoir la portée exacte de celui que PHP avait
	 * posé : une portée qui diffère d'un caractère fait un second cookie, que
	 * le navigateur garde à côté du premier sans jamais les départager.
	 */
	public function testTheRefreshedCookieKeepsTheConfiguredScope () {
		$session  = $this->startedSession();
		$request  = $this->requestCarrying( $session, $session->getId() );
		$response = new Response();

		$this->refresh( $request, $response );

		$cookie = $response->headers->getCookies()[ 0 ];

		$this->assertSame( '/', $cookie->getPath() );
		$this->assertSame( 'lax', $cookie->getSameSite() );
		$this->assertTrue( $cookie->isHttpOnly() );
		// `cookie_secure: auto` se tranche sur la requête, et celle-ci est en
		// clair : marquer le cookie « secure » ici, c'est un cookie que le
		// navigateur ne renverrait jamais.
		$this->assertFalse( $cookie->isSecure() );
	}

	/**
	 * La requête qui crée la session, celle qui connecte, celle qui déconnecte :
	 * le cookie qui fait foi est déjà dans la réponse, le doubler y remettrait
	 * l'ancien identifiant.
	 */
	public function testAnAlreadySentCookieIsLeftAlone () {
		$session  = $this->startedSession();
		$request  = $this->requestCarrying( $session, 'ancien-identifiant' );
		$response = new Response();
		$response->headers->setCookie( new Cookie( $session->getName(), 'identifiant-tout-neuf' ) );

		$this->refresh( $request, $response );

		$cookies = $response->headers->getCookies();

		$this->assertCount( 1, $cookies, 'Assert no second session cookie is added' );
		$this->assertSame( 'identifiant-tout-neuf', $cookies[ 0 ]->getValue() );
	}

	/**
	 * Rien à repousser tant que le navigateur n'a pas présenté de cookie :
	 * c'est la requête qui crée la session, et PHP lui donne déjà le sien.
	 */
	public function testNothingIsSentWhenTheBrowserCarriedNoCookie () {
		$session = $this->startedSession();

		$request = new Request();
		$request->setSession( $session );

		$response = new Response();

		$this->refresh( $request, $response );

		$this->assertCount( 0, $response->headers->getCookies() );
	}

	/**
	 * Sans durée réglée, le cookie meurt avec le navigateur : c'est un choix,
	 * et le rappel n'a alors rien à dire.
	 */
	public function testNothingIsSentWhenTheCookieHasNoDeadline () {
		$session  = $this->startedSession();
		$request  = $this->requestCarrying( $session, $session->getId() );
		$response = new Response();

		$this->refresh( $request, $response, [ 'cookie_lifetime' => 0 ] );

		$this->assertCount( 0, $response->headers->getCookies() );
	}

	/**
	 * @return Session
	 */
	private function startedSession () {
		$session = new Session( new MockArraySessionStorage() );
		$session->start();

		return $session;
	}

	/**
	 * @param Session $session
	 * @param string  $value
	 *
	 * @return Request
	 */
	private function requestCarrying ( Session $session, $value ) {
		$request = new Request();
		$request->setSession( $session );
		$request->cookies->set( $session->getName(), $value );

		return $request;
	}

	/**
	 * @param Request  $request
	 * @param Response $response
	 * @param array    $options overrides the session options of framework.yaml
	 */
	private function refresh ( Request $request, Response $response, array $options = [] ) {
		// Les vrais réglages, pour que le cookie reposé soit éprouvé contre la
		// portée qu'aura celui de PHP — et non contre une copie de complaisance
		// qui suivrait framework.yaml sans jamais le contredire.
		$defaults = $this->sessionConfig();
		$defaults[ 'cookie_lifetime' ] = $this->configuredLifetime();

		$subscriber = new SessionCookieRefreshSubscriber( array_merge( $defaults, $options ) );

		$subscriber->onKernelResponse( new ResponseEvent(
				$this->createMock( HttpKernelInterface::class ),
				$request,
				HttpKernelInterface::MASTER_REQUEST,
				$response
		) );
	}
}
