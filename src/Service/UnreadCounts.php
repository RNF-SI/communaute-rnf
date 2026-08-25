<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qui attend quelqu'un, compté une seule fois et au même endroit.
 *
 * Trois nombres, et un total. L'en-tête les affiche — la pastille sur
 * l'avatar, puis le détail ligne par ligne dans le menu —, et le sondage du
 * dock renvoie exactement les mêmes : deux façons de compter finiraient par
 * diverger d'une unité, et c'est précisément l'écart qu'un lecteur remarque.
 *
 * **Un message privé n'est compté qu'une fois.** Il ouvre pourtant deux
 * lignes en base : une conversation non lue, et une notification de type
 * `message:new`. Le compteur des notifications écarte donc ce type — sans
 * quoi la pastille dirait « 2 » pour un seul message reçu. La page des
 * notifications, elle, continue de les lister : ce qu'elle montre est une
 * histoire, pas un compteur.
 *
 * **Les discussions ne s'additionnent pas au reste.** Rien en base ne suit la
 * lecture d'une discussion de groupe ; le nombre affiché est celui des
 * notifications de discussion non lues, c'est-à-dire un sous-ensemble du
 * compteur des notifications. Il détaille ce compteur, il ne s'y ajoute pas —
 * d'où son absence du total.
 */
class UnreadCounts {
	/**
	 * Les notifications qu'un message privé engendre. Écartées du compteur
	 * des notifications parce qu'elles ont leur propre ligne.
	 */
	private const MESSAGE_TYPES = [ Notification::MESSAGE_NEW ];

	/**
	 * Ce qui, dans les notifications, parle d'une discussion de groupe.
	 */
	private const DISCUSSION_TYPES = [
			Notification::DISCUSSION_MESSAGE,
			Notification::DISCUSSION_MENTION,
	];

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * Le compte déjà fait pour ce tour de requête, par identifiant de membre.
	 *
	 * L'en-tête appelle trois fonctions Twig d'affilée sur la même personne ;
	 * sans ce garde-fou, la même paire de COUNT partirait trois fois à chaque
	 * page.
	 *
	 * @var array
	 */
	private $memo = [];

	public function __construct ( EntityManagerInterface $manager ) {
		$this->manager = $manager;
	}

	/**
	 * @param \App\Entity\User|null $user
	 *
	 * @return array {messages: int, notifications: int, discussions: int, total: int}
	 */
	public function forUser ( User $user = NULL ) {
		if ( !$user || !$user->getId() ) {
			return $this->none();
		}

		$id = $user->getId();

		if ( isset( $this->memo[ $id ] ) ) {
			return $this->memo[ $id ];
		}

		$messages      = $this->manager->getRepository( Conversation::class )->countUnread( $user );
		$notifications = $this->manager->getRepository( Notification::class )
									   ->countUnreadExcept( $user, self::MESSAGE_TYPES );
		$discussions   = $this->manager->getRepository( Notification::class )
									   ->countUnreadOfTypes( $user, self::DISCUSSION_TYPES );

		return $this->memo[ $id ] = [
				'messages'      => $messages,
				'notifications' => $notifications,
				'discussions'   => $discussions,
				'total'         => $messages + $notifications,
		];
	}

	/**
	 * Oublier ce qui a été compté.
	 *
	 * Le sondage du dock marque des conversations comme lues au milieu de la
	 * requête, puis renvoie les compteurs : sans cet oubli, il renverrait
	 * ceux d'avant sa propre écriture.
	 *
	 * @return void
	 */
	public function forget () {
		$this->memo = [];
	}

	/**
	 * @return array
	 */
	private function none () {
		return [
				'messages'      => 0,
				'notifications' => 0,
				'discussions'   => 0,
				'total'         => 0,
		];
	}
}
