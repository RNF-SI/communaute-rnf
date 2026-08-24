<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce qu'une conversation est pour l'un de ceux qui en sont : jusqu'où il l'a
 * lue, s'il l'a rangée, s'il l'a quittée.
 *
 * Trois dates plutôt que trois booléens : elles disent aussi quand, ce qui
 * suffit à afficher « untel a quitté la conversation » à sa place dans le fil
 * sans écrire de message système.
 *
 * @ORM\Table(
 *     name="conversations_participants",
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="conversation_participant", columns={"conversation_id", "user_id"})
 *     },
 *     indexes={
 *         @ORM\Index(name="participant_inbox", columns={"user_id", "archived_at"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\ConversationParticipantRepository")
 */
class ConversationParticipant {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\Conversation", inversedBy="participants")
	 * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
	 */
	private $conversation;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
	 */
	private $user;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $joinedAt;

	/**
	 * Jusqu'où ce membre a lu. NULL veut dire « jamais ouverte », ce qui n'est
	 * pas la même chose que « lue jusqu'au début » : la première est en gras
	 * dans la liste, la seconde ne l'est pas.
	 *
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $lastReadAt;

	/**
	 * Rangée : sortie de ma liste, intacte pour les autres. Un nouveau message
	 * la fait revenir — archiver n'est pas se désabonner.
	 *
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $archivedAt;

	/**
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $leftAt;

	public function __construct ( User $user = NULL ) {
		$this->joinedAt = new \DateTime();

		if ( $user ) {
			$this->user = $user;
		}
	}

	public function getId (): ?int {
		return $this->id;
	}

	public function getConversation (): ?Conversation {
		return $this->conversation;
	}

	public function setConversation ( ?Conversation $conversation ): self {
		$this->conversation = $conversation;

		return $this;
	}

	public function getUser (): ?User {
		return $this->user;
	}

	public function setUser ( ?User $user ): self {
		$this->user = $user;

		return $this;
	}

	public function getJoinedAt (): ?DateTimeInterface {
		return $this->joinedAt;
	}

	public function setJoinedAt ( DateTimeInterface $joinedAt ): self {
		$this->joinedAt = $joinedAt;

		return $this;
	}

	public function getLastReadAt (): ?DateTimeInterface {
		return $this->lastReadAt;
	}

	public function setLastReadAt ( ?DateTimeInterface $lastReadAt ): self {
		$this->lastReadAt = $lastReadAt;

		return $this;
	}

	public function getArchivedAt (): ?DateTimeInterface {
		return $this->archivedAt;
	}

	public function setArchivedAt ( ?DateTimeInterface $archivedAt ): self {
		$this->archivedAt = $archivedAt;

		return $this;
	}

	public function isArchived (): bool {
		return $this->archivedAt !== NULL;
	}

	public function getLeftAt (): ?DateTimeInterface {
		return $this->leftAt;
	}

	public function setLeftAt ( ?DateTimeInterface $leftAt ): self {
		$this->leftAt = $leftAt;

		return $this;
	}

	public function hasLeft (): bool {
		return $this->leftAt !== NULL;
	}

	/**
	 * Ce message a-t-il été posté depuis ma dernière lecture ?
	 *
	 * Comparer des dates plutôt que compter des messages lus un par un : la
	 * boîte n'a alors qu'une colonne à tenir à jour, et non une ligne par
	 * message et par lecteur.
	 *
	 * @param \App\Entity\PrivateMessage $message
	 *
	 * @return bool
	 */
	public function hasNotRead ( PrivateMessage $message ): bool {
		$author = $message->getAuthor();

		// Ce qu'on vient d'écrire soi-même n'est jamais « non lu ».
		if ( $author && $this->user && ( $author->getId() === $this->user->getId() ) ) {
			return FALSE;
		}

		if ( !$message->getCreatedAt() ) {
			return FALSE;
		}

		return !$this->lastReadAt || ( $message->getCreatedAt() > $this->lastReadAt );
	}
}
