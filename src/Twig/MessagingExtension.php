<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\Tagging\TagParser;
use App\Service\UnreadCounts;
use Symfony\Component\Security\Core\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Ce dont les gabarits de la messagerie ont besoin sans qu'un contrôleur ait à
 * le leur passer : le nombre de conversations qui attendent, et le rendu des
 * tags d'un message.
 *
 * Le filtre `tags` **dépend de qui regarde** : il donne un lien à celui qui a
 * le droit d'ouvrir le contenu tagué, et un tag grisé aux autres. Ne pas le
 * mettre en cache, et ne pas s'en servir pour fabriquer un e-mail — le rendu
 * n'aurait alors pas de lecteur, et rien ne dirait à qui il s'adresse.
 */
class MessagingExtension extends AbstractExtension {
	/**
	 * @var \App\Service\UnreadCounts
	 */
	private $counts;

	/**
	 * @var \Symfony\Component\Security\Core\Security
	 */
	private $security;

	/**
	 * @var \App\Service\Tagging\TagParser
	 */
	private $tags;

	public function __construct ( UnreadCounts $counts, Security $security, TagParser $tags ) {
		$this->counts   = $counts;
		$this->security = $security;
		$this->tags     = $tags;
	}

	public function getFunctions (): array {
		return [
				new TwigFunction( 'unread_messages', [ $this, 'unreadMessages' ] ),
		];
	}

	public function getFilters (): array {
		return [
				new TwigFilter( 'tags', [ $this, 'render' ], [ 'is_safe' => [ 'html' ] ] ),
		];
	}

	/**
	 * Combien de conversations attendent une lecture.
	 *
	 * @return int
	 */
	public function unreadMessages () {
		$user = $this->security->getUser();

		return $this->counts->forUser( $user instanceof User ? $user : NULL )[ 'messages' ];
	}

	/**
	 * @param string $body
	 *
	 * @return string
	 */
	public function render ( $body ) {
		return $this->tags->render( $body );
	}
}
