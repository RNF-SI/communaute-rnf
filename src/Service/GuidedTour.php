<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
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
 * Une visite qui reste sur place ne montre presque rien : la plateforme se
 * lit d'une page à l'autre, la visite aussi. Chaque étape déclare donc la
 * page où elle se joue (`route`) et l'élément qu'elle entoure (`target`).
 * Quand l'étape suivante est ailleurs, le navigateur y va et la visite
 * reprend à cette étape — c'est le rôle du bouton dont le libellé annonce la
 * destination (`link` dans les traductions).
 *
 * Trois précautions :
 *
 * - une étape sans `route` se joue là où l'on est — c'est le cas de celles
 *   qui parlent de l'entête, qui est sur toutes les pages ;
 * - une étape dont l'élément est absent s'affiche au centre plutôt que de
 *   pointer dans le vide ; rien ne dépend donc de l'endroit où la visite a
 *   été lancée ;
 * - les étapes qui visitent un groupe ont besoin d'un groupe à montrer. On
 *   prend le premier de la personne, à défaut le groupe communauté ; s'il
 *   n'y en a aucun, ces étapes disparaissent au lieu de mener à une erreur.
 */
class GuidedTour {
	/**
	 * Dans l'ordre où elles se lisent. La clé sert à retrouver le texte dans
	 * les traductions, sous `pages.tour.steps.<clé>`.
	 *
	 * - `target` : sélecteur CSS facultatif de l'élément à entourer.
	 * - `route`  : page où l'étape se joue ; la visite s'y rend si besoin.
	 * - `group`  : l'étape parle d'un groupe, la route en prend le slug.
	 * - `open`   : sélecteur d'un bouton à actionner pour déplier la cible.
	 */
	private const STEPS = [
			// Ce qui est sur toutes les pages : on ne bouge pas encore.
			[ 'key' => 'welcome' ],
			[ 'key' => 'search', 'target' => '#search-bar-input' ],
			[
					'key'    => 'account',
					'target' => '.header--user-menu .links',
					'open'   => '.header--user-menu > .toggle-button',
			],

			// Les groupes du réseau.
			[ 'key' => 'groups', 'route' => 'groups_index', 'target' => '.groups-list.groups-active' ],
			[ 'key' => 'groups_graph', 'route' => 'groups_index', 'target' => '.groups-view-toggle' ],
			[ 'key' => 'create_group', 'route' => 'groups_index', 'target' => '.groups-create-link' ],
			[ 'key' => 'my_groups', 'route' => 'user_groups', 'target' => '.my-groups-list' ],

			// Un groupe, celui de la personne : ce qu'on y trouve.
			[ 'key' => 'group', 'route' => 'group_index', 'group' => TRUE, 'target' => '.group-infos' ],
			[ 'key' => 'group_search', 'route' => 'group_index', 'group' => TRUE, 'target' => '.group-search-bar' ],
			[ 'key' => 'discussions', 'route' => 'group_index', 'group' => TRUE, 'target' => '.group-app__discussions' ],
			[ 'key' => 'articles', 'route' => 'group_index', 'group' => TRUE, 'target' => '.group-app__articles' ],
			[ 'key' => 'documents', 'route' => 'group_index', 'group' => TRUE, 'target' => '.group-app__documents' ],
			[ 'key' => 'pages', 'route' => 'group_index', 'group' => TRUE, 'target' => '.group-app__pages' ],
			[ 'key' => 'members', 'route' => 'group_index', 'group' => TRUE, 'target' => '.group-app__members' ],

			// La bibliothèque du groupe, et la règle qui y est affichée.
			[
					'key'    => 'documents_filters',
					'route'  => 'group_documents_index',
					'group'  => TRUE,
					'target' => '.filters',
			],
			[ 'key' => 'rights', 'route' => 'group_documents_index', 'group' => TRUE, 'target' => '.permission-note' ],

			// Les personnes du réseau.
			[ 'key' => 'directory', 'route' => 'members', 'target' => '#mapCommunauteId' ],
			[ 'key' => 'profile', 'route' => 'user_profile_edit', 'target' => '.profile-form' ],

			// Ce qui vous arrive, et le robinet.
			[ 'key' => 'notifications', 'route' => 'user_notifications', 'target' => '.panel' ],
			[ 'key' => 'settings', 'route' => 'user_parameters_edit', 'target' => '.notifications-settings' ],
			[ 'key' => 'rhythm', 'route' => 'user_parameters_edit', 'target' => '#discussion-rhythm' ],
			[ 'key' => 'replay', 'route' => 'user_parameters_edit', 'target' => '.tour-replay' ],
			[ 'key' => 'end', 'route' => 'user_parameters_edit' ],
	];

	/**
	 * @var \Symfony\Contracts\Translation\TranslatorInterface
	 */
	private $translator;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $router;

	/**
	 * @var \App\Service\Community
	 */
	private $community;

	public function __construct (
			TranslatorInterface $translator,
			UrlGeneratorInterface $router,
			Community $community
	) {
		$this->translator = $translator;
		$this->router     = $router;
		$this->community  = $community;
	}

	/**
	 * Les étapes, prêtes à être lues par le navigateur.
	 *
	 * `url` dit sur quelle page l'étape se joue ; `label` n'est posé que sur
	 * celles qui changent de page, parce que c'est le libellé du bouton qui
	 * y emmène — enchaîner deux étapes de la même page ne demande rien à
	 * annoncer.
	 *
	 * @param \App\Entity\User|null $user
	 *
	 * @return array[]
	 */
	public function steps ( $user = NULL ) {
		$group    = $this->groupToShow( $user );
		$steps    = [];
		$previous = NULL;

		foreach ( self::STEPS as $step ) {
			// Pas de groupe à montrer : plutôt sauter ces étapes que de
			// promener quelqu'un sur une page qui n'existe pas.
			if ( !empty( $step[ 'group' ] ) && empty( $group ) ) {
				continue;
			}

			$prepared = [
					'key'   => $step[ 'key' ],
					'title' => $this->translator->trans( 'pages.tour.steps.' . $step[ 'key' ] . '.title' ),
					'body'  => $this->translator->trans( 'pages.tour.steps.' . $step[ 'key' ] . '.body' ),
			];

			if ( !empty( $step[ 'target' ] ) ) {
				$prepared[ 'target' ] = $step[ 'target' ];
			}

			if ( !empty( $step[ 'open' ] ) ) {
				$prepared[ 'open' ] = $step[ 'open' ];
			}

			if ( !empty( $step[ 'route' ] ) ) {
				$url = $this->router->generate(
						$step[ 'route' ],
						empty( $step[ 'group' ] ) ? [] : [ 'groupSlug' => $group->getSlug() ]
				);

				$prepared[ 'url' ] = $url;

				if ( $url !== $previous ) {
					$prepared[ 'label' ] = $this->translator
							->trans( 'pages.tour.steps.' . $step[ 'key' ] . '.link' );
				}

				$previous = $url;
			}

			$steps[] = $prepared;
		}

		return $steps;
	}

	/**
	 * Le groupe que la visite ouvre.
	 *
	 * Le sien d'abord : une visite qui montre un groupe dont on est membre
	 * montre des discussions et des documents, pas une page d'invitation à
	 * rejoindre. Le groupe communauté sert de repli — tout le monde en est.
	 *
	 * @param \App\Entity\User|null $user
	 *
	 * @return \App\Entity\Usergroup|null
	 */
	private function groupToShow ( $user ) {
		$community = $this->community->getGroup();

		if ( $user instanceof User ) {
			foreach ( $user->getUsergroupMemberships() as $membership ) {
				$group = $membership->getUsergroup();

				if ( empty( $group ) || !$group->getIsActive() ) {
					continue;
				}

				if ( $membership->getStatus() !== UsergroupMembership::STATUS_MEMBER ) {
					continue;
				}

				if ( $community instanceof Usergroup && $group->getId() === $community->getId() ) {
					continue;
				}

				return $group;
			}
		}

		return $community;
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
