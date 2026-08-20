<?php

namespace App\Twig;

use App\Service\GuidedTour;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose la visite guidée au gabarit de base, pour qu'aucun contrôleur n'ait
 * à la passer page par page. (#39)
 */
class TourExtension extends AbstractExtension {
	/**
	 * Ce qui, dans l'adresse, redemande la visite depuis les paramètres.
	 */
	public const REPLAY = 'tour';

	private $tour;

	private $security;

	private $requests;

	public function __construct ( GuidedTour $tour, Security $security, RequestStack $requests ) {
		$this->tour     = $tour;
		$this->security = $security;
		$this->requests = $requests;
	}

	public function getFunctions (): array {
		return [
				new TwigFunction( 'guided_tour', [ $this, 'steps' ] ),
		];
	}

	/**
	 * Les étapes à jouer maintenant, ou un tableau vide s'il n'y a pas lieu.
	 *
	 * @return array[]
	 */
	public function steps () {
		$user = $this->security->getUser();

		if ( !$this->tour->isDueFor( $user ) && !$this->replayAsked() ) {
			return [];
		}

		// Une visite ne se rejoue pas pour quelqu'un qui n'est pas connecté :
		// elle parle de groupes, de notifications et de réglages de compte.
		return $user ? $this->tour->steps() : [];
	}

	/**
	 * @return bool
	 */
	private function replayAsked () {
		$request = $this->requests->getCurrentRequest();

		return $request && $request->query->has( self::REPLAY );
	}
}
