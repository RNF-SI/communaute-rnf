<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un échange privé entre plusieurs membres, hors de tout groupe.
 *
 * Il n'y a pas deux modèles, l'un pour écrire à une personne et l'autre pour
 * écrire à plusieurs : le tête-à-tête est le cas où la conversation compte
 * deux participants, et ajouter quelqu'un en cours de route ne change que ce
 * nombre. C'est ce qui permet à un référent de commission d'élargir un
 * échange sans le recommencer ailleurs.
 *
 * Une conversation n'a pas de titre. Elle se reconnaît à qui en est, et un
 * sujet qu'il faudrait remplir avant d'écrire est exactement ce qui fait
 * renoncer à écrire.
 *
 * @ORM\Table(
 *     name="conversations",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="conversation_pair", columns={"pair_key"})
 *     },
 *     indexes={
 *         @ORM\Index(name="conversation_activity", columns={"last_message_at"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\ConversationRepository")
 */
class Conversation {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $createdAt;

	/**
	 * Quand on s'y est parlé pour la dernière fois. Recopié du dernier
	 * message plutôt que calculé : la liste des conversations se trie
	 * dessus, et un tri qui demande une jointure sur toute la table des
	 * messages se paie à chaque affichage de la boîte.
	 *
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $lastMessageAt;

	/**
	 * Les deux identifiants du tête-à-tête, triés et joints par un tiret.
	 *
	 * C'est l'unicité en base — et non un contrôle dans le code — qui garantit
	 * qu'écrire deux fois à la même personne ne crée pas deux fils entre
	 * lesquels la conversation se couperait en deux. Dès qu'un troisième
	 * participant entre, la clé est effacée : le fil cesse d'être un
	 * tête-à-tête, et un nouveau tête-à-tête entre les deux premiers redevient
	 * possible.
	 *
	 * @ORM\Column(type="string", length=64, nullable=true)
	 */
	private $pairKey;

	/**
	 * @ORM\OneToMany(
	 *     targetEntity="App\Entity\ConversationParticipant",
	 *     mappedBy="conversation",
	 *     cascade={"persist"},
	 *     orphanRemoval=true
	 * )
	 */
	private $participants;

	/**
	 * @ORM\OneToMany(
	 *     targetEntity="App\Entity\PrivateMessage",
	 *     mappedBy="conversation",
	 *     orphanRemoval=true
	 * )
	 * @ORM\OrderBy({"createdAt": "ASC", "id": "ASC"})
	 */
	private $messages;

	public function __construct () {
		$this->participants = new ArrayCollection();
		$this->messages     = new ArrayCollection();
		$this->createdAt    = new \DateTime();
	}

	public function getId (): ?int {
		return $this->id;
	}

	public function getCreatedAt (): ?DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt ( DateTimeInterface $createdAt ): self {
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getLastMessageAt (): ?DateTimeInterface {
		return $this->lastMessageAt;
	}

	public function setLastMessageAt ( ?DateTimeInterface $lastMessageAt ): self {
		$this->lastMessageAt = $lastMessageAt;

		return $this;
	}

	public function getPairKey (): ?string {
		return $this->pairKey;
	}

	public function setPairKey ( ?string $pairKey ): self {
		$this->pairKey = $pairKey;

		return $this;
	}

	/**
	 * La clé d'un tête-à-tête entre ces deux-là, quel que soit l'ordre dans
	 * lequel on les donne.
	 *
	 * @param \App\Entity\User $one
	 * @param \App\Entity\User $other
	 *
	 * @return string|null NULL tant que l'un des deux n'est pas enregistré
	 */
	public static function pairKeyFor ( User $one, User $other ): ?string {
		$ids = [ $one->getId(), $other->getId() ];

		if ( in_array( NULL, $ids, TRUE ) ) {
			return NULL;
		}

		sort( $ids );

		return implode( '-', $ids );
	}

	/**
	 * @return Collection|ConversationParticipant[]
	 */
	public function getParticipants (): Collection {
		return $this->participants;
	}

	/**
	 * Ceux qui en sont encore : quelqu'un qui a quitté le fil reste dans la
	 * table — ses messages doivent garder un auteur nommé — mais il ne compte
	 * plus parmi les destinataires.
	 *
	 * @return ConversationParticipant[]
	 */
	public function getActiveParticipants (): array {
		$active = [];

		foreach ( $this->participants as $participant ) {
			if ( !$participant->hasLeft() && $participant->getUser() ) {
				$active[] = $participant;
			}
		}

		return $active;
	}

	/**
	 * @return \App\Entity\User[]
	 */
	public function getActiveUsers (): array {
		return array_map( static function ( ConversationParticipant $participant ) {
			return $participant->getUser();
		}, $this->getActiveParticipants() );
	}

	/**
	 * Tout le monde sauf soi — ce qui nomme la conversation dans la liste.
	 *
	 * @param \App\Entity\User $user
	 *
	 * @return \App\Entity\User[]
	 */
	public function getOthers ( User $user ): array {
		$others = [];

		foreach ( $this->getActiveParticipants() as $participant ) {
			$member = $participant->getUser();

			if ( $member && ( $member->getId() !== $user->getId() ) ) {
				$others[] = $member;
			}
		}

		return $others;
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return \App\Entity\ConversationParticipant|null
	 */
	public function getParticipantFor ( User $user ): ?ConversationParticipant {
		foreach ( $this->participants as $participant ) {
			$member = $participant->getUser();

			if ( $member && ( $member->getId() === $user->getId() ) ) {
				return $participant;
			}
		}

		return NULL;
	}

	/**
	 * Quelqu'un qui en est encore, et qui peut donc y écrire.
	 *
	 * @param \App\Entity\User $user
	 *
	 * @return bool
	 */
	public function includes ( User $user ): bool {
		$participant = $this->getParticipantFor( $user );

		return $participant && !$participant->hasLeft();
	}

	public function addParticipant ( ConversationParticipant $participant ): self {
		if ( !$this->participants->contains( $participant ) ) {
			$this->participants[] = $participant;
			$participant->setConversation( $this );
		}

		return $this;
	}

	/**
	 * @return Collection|PrivateMessage[]
	 */
	public function getMessages (): Collection {
		return $this->messages;
	}
}
