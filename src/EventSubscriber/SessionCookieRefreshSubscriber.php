<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Repousse l'échéance du cookie de session à chaque page vue.
 *
 * Sans lui, `cookie_lifetime` est un délai **absolu** : PHP n'émet le cookie
 * qu'au moment où il crée la session, jamais ensuite. Quelqu'un qui vient tous
 * les jours serait donc déconnecté au trentième, en pleine rédaction, sans
 * avoir rien fait. Ici, les trente jours se comptent depuis la dernière visite
 * et non depuis la connexion : on ne perd sa place qu'en ne revenant pas.
 *
 * Le fichier de session, lui, n'a pas besoin de ce soin — le ramassage de PHP
 * regarde sa date de modification, que chaque requête met à jour.
 *
 * Les réglages sont lus dans `session.storage.options`, c'est-à-dire dans
 * framework.yaml, et non dans les `ini_get` de PHP : c'est la même source que
 * celle qui a posé le cookie d'origine, et deux lectures qui divergeraient
 * fabriqueraient un second cookie de portée différente — que le navigateur
 * garderait à côté du premier, sans jamais les départager.
 */
class SessionCookieRefreshSubscriber implements EventSubscriberInterface {
	/** @var array */
	private $options;

	public function __construct ( array $options = [] ) {
		$this->options = $options;
	}

	public static function getSubscribedEvents (): array {
		return [
				KernelEvents::RESPONSE => [ 'onKernelResponse', -1000 ],
		];
	}

	public function onKernelResponse ( ResponseEvent $event ): void {
		if ( !$event->isMasterRequest() ) {
			return;
		}

		$lifetime = (int) ( $this->options[ 'cookie_lifetime' ] ?? 0 );

		// Durée nulle : le cookie meurt avec le navigateur, c'est un choix
		// délibéré et rien ici n'a de sens.
		if ( $lifetime <= 0 ) {
			return;
		}

		$request = $event->getRequest();

		// `hasPreviousSession` veut dire : le navigateur a présenté le cookie.
		// C'est exactement le cas à traiter — la requête qui *crée* la session
		// reçoit déjà son cookie de PHP, et le lui renvoyer serait au mieux
		// inutile.
		if ( !$request->hasPreviousSession() ) {
			return;
		}

		$session = $request->getSession();

		if ( $session === NULL || !$session->isStarted() ) {
			return;
		}

		$response = $event->getResponse();

		// Connexion, déconnexion, changement d'identifiant de session : le
		// cookie qui fait foi est déjà dans la réponse, le doubler y
		// remettrait l'ancien identifiant.
		foreach ( $response->headers->getCookies() as $cookie ) {
			if ( $cookie->getName() === $session->getName() ) {
				return;
			}
		}

		$response->headers->setCookie( new Cookie(
				$session->getName(),
				$session->getId(),
				time() + $lifetime,
				(string) ( $this->options[ 'cookie_path' ] ?? '/' ),
				$this->options[ 'cookie_domain' ] ?? NULL,
				$this->isSecure( $request ),
				(bool) ( $this->options[ 'cookie_httponly' ] ?? TRUE ),
				FALSE,
				$this->options[ 'cookie_samesite' ] ?? NULL
		) );
	}

	/**
	 * `cookie_secure: auto` ne se tranche qu'une fois la requête connue —
	 * c'est ainsi que la préproduction en clair et la production en HTTPS
	 * partagent la même configuration.
	 *
	 * @param Request $request
	 *
	 * @return bool
	 */
	private function isSecure ( Request $request ) {
		$secure = $this->options[ 'cookie_secure' ] ?? FALSE;

		return $secure === 'auto' ? $request->isSecure() : (bool) $secure;
	}
}
