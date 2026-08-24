<?php

namespace App\Service\Tagging;

use App\Entity\Usergroup;

/**
 * Ce qu'un « #… » désigne, une fois retrouvé : de quoi l'afficher, y mener,
 * et savoir qui a le droit de le lire.
 *
 * Un objet plutôt que l'entité elle-même parce que cinq entités sans ancêtre
 * commun — un groupe, un document, une page, une actualité, une discussion —
 * répondent ici à la même question, et que la seule chose dont l'affichage a
 * besoin est ce que cette classe porte.
 */
class TaggedThing {
	const GROUP      = 'group';
	const DOCUMENT   = 'document';
	const PAGE       = 'page';
	const ARTICLE    = 'article';
	const DISCUSSION = 'discussion';

	/**
	 * L'ordre dans lequel un même titre est cherché. Un groupe d'abord : son
	 * nom est unique dans le réseau, alors qu'une page et une actualité
	 * peuvent s'appeler pareil dans deux groupes. Un ordre fixe est ce qui
	 * fait qu'une ambiguïté se tranche de la même façon à chaque affichage —
	 * un tag qui changerait de cible d'une page à l'autre serait pire qu'un
	 * tag qui se trompe.
	 *
	 * @return string[]
	 */
	public static function kinds () {
		return [ self::GROUP, self::DOCUMENT, self::PAGE, self::ARTICLE, self::DISCUSSION ];
	}

	/**
	 * @var string
	 */
	private $kind;

	/**
	 * @var string
	 */
	private $title;

	/**
	 * @var string
	 */
	private $url;

	/**
	 * Le groupe dont ce contenu relève, et donc ce qui dit qui peut le lire.
	 * Pour un tag de groupe, c'est le groupe lui-même.
	 *
	 * @var \App\Entity\Usergroup|null
	 */
	private $group;

	public function __construct ( $kind, $title, $url, Usergroup $group = NULL ) {
		$this->kind  = $kind;
		$this->title = (string) $title;
		$this->url   = (string) $url;
		$this->group = $group;
	}

	public function getKind () {
		return $this->kind;
	}

	public function getTitle () {
		return $this->title;
	}

	public function getUrl () {
		return $this->url;
	}

	public function getGroup () {
		return $this->group;
	}
}
