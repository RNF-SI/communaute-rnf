<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RedirectDomainsSubscriber implements EventSubscriberInterface {
	public function __construct () {
	}

	public static function getSubscribedEvents () {
		return [
				KernelEvents::REQUEST => [ [ 'onKernelRequest' ] ],
		];
	}

	public function onKernelRequest ( RequestEvent $event ) {
		$request = $event->getRequest();
		$host    = $request->getHost();

		switch ( $host ) {
			case 'www.communaute-rnf.fr':
			case 'communaute-rnf.com':
			case 'www.communaute-rnf.com':
				if ( NULL !== $qs = $request->getQueryString() ) {
					$qs = '?' . $qs;
				}
				$uri = $request->getBaseUrl() . $request->getPathInfo() . $qs;
				$event->setResponse( new RedirectResponse( 'https://communaute-rnf.fr' . $uri, 301 ) );
				break;
		}
	}
}
