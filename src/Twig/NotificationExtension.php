<?php

namespace App\Twig;

use App\Entity\User;
use App\Service\UnreadCounts;
use Symfony\Component\Security\Core\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Lets the layout show how many notifications are waiting, without every
 * controller having to pass the count along. (#34)
 *
 * Les trois nombres viennent d'un seul service, `UnreadCounts`, que le
 * sondage du dock interroge lui aussi : la pastille de l'en-tête et ce que
 * renvoie `/messages/live` doivent dire la même chose, sans quoi l'un des
 * deux corrige l'autre à chaque tour et le nombre clignote.
 */
class NotificationExtension extends AbstractExtension {
	/**
	 * @var \App\Service\UnreadCounts
	 */
	private $counts;

	/**
	 * @var \Symfony\Component\Security\Core\Security
	 */
	private $security;

	public function __construct ( UnreadCounts $counts, Security $security ) {
		$this->counts   = $counts;
		$this->security = $security;
	}

	public function getFunctions (): array {
		return [
				new TwigFunction( 'unread_notifications', [ $this, 'unreadNotifications' ] ),
				new TwigFunction( 'unread_discussions', [ $this, 'unreadDiscussions' ] ),
				new TwigFunction( 'unread_total', [ $this, 'unreadTotal' ] ),
		];
	}

	/**
	 * Les notifications qui attendent, **les messages privés exceptés** : ils
	 * ont leur propre ligne dans le menu. Voir UnreadCounts.
	 *
	 * @return int
	 */
	public function unreadNotifications () {
		return $this->mine()[ 'notifications' ];
	}

	/**
	 * Ce qui, dans ces notifications, parle d'une discussion de groupe. Un
	 * détail du nombre précédent, pas une ligne de plus dans le total.
	 *
	 * @return int
	 */
	public function unreadDiscussions () {
		return $this->mine()[ 'discussions' ];
	}

	/**
	 * Le nombre que porte la pastille sur l'avatar.
	 *
	 * @return int
	 */
	public function unreadTotal () {
		return $this->mine()[ 'total' ];
	}

	/**
	 * @return array
	 */
	private function mine () {
		$user = $this->security->getUser();

		return $this->counts->forUser( $user instanceof User ? $user : NULL );
	}
}
