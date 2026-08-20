<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les étapes de la visite guidée. (#39)
 *
 * Elles sont déclarées ici et rédigées dans les fichiers de traduction :
 * changer une formulation ne demande pas de toucher au code, ajouter une
 * étape si. C'est le choix assumé — le contenu bouge rarement, et une visite
 * qui se règle en administration coûte un écran de gestion de plus.
 *
 * Une étape peut viser un élément de la page (`target`) : la visite l'entoure
 * alors et s'ancre dessus. Si l'élément n'est pas là — on n'est pas sur la
 * bonne page — l'étape s'affiche au centre. Aucune étape ne dépend donc de
 * l'endroit où la visite est lancée.
 */
class GuidedTour {
	/**
	 * Dans l'ordre où elles se lisent. La clé sert à retrouver le texte dans
	 * les traductions, sous `pages.tour.steps.<clé>`.
	 *
	 * - `target` : sélecteur CSS facultatif de l'élément à entourer.
	 * - `route`  : route facultative vers laquelle l'étape propose d'aller.
	 */
	private const STEPS = [
			[ 'key' => 'welcome' ],
			[ 'key' => 'groups', 'target' => '.my-groups-list', 'route' => 'groups_index' ],
			[ 'key' => 'tabs', 'target' => '.group-tabs' ],
			[ 'key' => 'discussions' ],
			[ 'key' => 'documents' ],
			[ 'key' => 'rights' ],
			[ 'key' => 'create_group', 'route' => 'group_new' ],
			[ 'key' => 'notifications', 'route' => 'user_notifications' ],
			[ 'key' => 'settings', 'route' => 'user_parameters_edit' ],
			[ 'key' => 'end' ],
	];

	/**
	 * @var \Symfony\Contracts\Translation\TranslatorInterface
	 */
	private $translator;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $router;

	public function __construct ( TranslatorInterface $translator, UrlGeneratorInterface $router ) {
		$this->translator = $translator;
		$this->router     = $router;
	}

	/**
	 * Les étapes, prêtes à être lues par le navigateur.
	 *
	 * @return array[]
	 */
	public function steps () {
		$steps = [];

		foreach ( self::STEPS as $step ) {
			$prepared = [
					'key'   => $step[ 'key' ],
					'title' => $this->translator->trans( 'pages.tour.steps.' . $step[ 'key' ] . '.title' ),
					'body'  => $this->translator->trans( 'pages.tour.steps.' . $step[ 'key' ] . '.body' ),
			];

			if ( !empty( $step[ 'target' ] ) ) {
				$prepared[ 'target' ] = $step[ 'target' ];
			}

			if ( !empty( $step[ 'route' ] ) ) {
				$prepared[ 'url' ]   = $this->router->generate( $step[ 'route' ] );
				$prepared[ 'label' ] = $this->translator->trans( 'pages.tour.steps.' . $step[ 'key' ] . '.link' );
			}

			$steps[] = $prepared;
		}

		return $steps;
	}

	/**
	 * Faut-il la lancer d'elle-même ?
	 *
	 * @param \App\Entity\User|null $user
	 *
	 * @return bool
	 */
	public function isDueFor ( $user ) {
		return ( $user instanceof User ) && !$user->hasSeenTour();
	}
}
