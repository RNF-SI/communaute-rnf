<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un message dans une conversation privée.
 *
 * Le corps est du texte, pas du HTML : il n'y a pas d'éditeur riche dans la
 * messagerie, et ce qui ressemble à une balise dans ce qu'on écrit doit
 * s'afficher tel quel. Ce que le texte contient, en revanche, ce sont des
 * tags, relus à l'affichage par TagParser (les exemples sont donnés sur le
 * champ lui-même, plus bas : une arobase en tête de commentaire de classe se
 * lit comme une annotation Doctrine, et fait échouer le chargement).
 *
 * Rien n'est stocké d'autre que ce que l'auteur a tapé : le message reste
 * lisible tel quel, un copier-coller n'en perd rien, et renommer un document
 * ne réécrit aucune ligne de cette table.
 *
 * @ORM\Table(
 *     name="private_messages",
 *     indexes={
 *         @ORM\Index(name="message_thread", columns={"conversation_id", "created_at"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\PrivateMessageRepository")
 */
class PrivateMessage {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\Conversation", inversedBy="messages")
	 * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
	 */
	private $conversation;

	/**
	 * Le compte supprimé laisse ses messages : les effacer trouerait la
	 * conversation de ceux qui restent. Ils s'affichent alors sans nom.
	 *
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
	 */
	private $author;

	// Le texte tel qu'il a été tapé, tags compris : « @Prénom Nom » désigne
	// quelqu'un, « #Titre » un groupe, un document, une page, une actualité ou
	// une discussion. Volontairement en commentaire de ligne : Doctrine lit
	// les blocs /** */ comme des annotations, et l'arobase y ferait échouer le
	// chargement de l'entité.

	/**
	 * @ORM\Column(type="text")
	 */
	private $body;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $createdAt;

	/**
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $editedAt;

	/**
	 * Supprimer, c'est retirer le texte, pas la ligne : le fil garde sa suite
	 * et affiche « message supprimé » à la place — comme dans les discussions
	 * de groupe.
	 *
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $deletedAt;

	/**
	 * @ORM\OneToMany(targetEntity="App\Entity\MessageReport", mappedBy="message")
	 */
	private $reports;

	public function __construct () {
		$this->reports   = new ArrayCollection();
		$this->createdAt = new \DateTime();
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

	public function getAuthor (): ?User {
		return $this->author;
	}

	public function setAuthor ( ?User $author ): self {
		$this->author = $author;

		return $this;
	}

	public function getBody (): ?string {
		return $this->body;
	}

	public function setBody ( ?string $body ): self {
		$this->body = $body;

		return $this;
	}

	public function getCreatedAt (): ?DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt ( DateTimeInterface $createdAt ): self {
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getEditedAt (): ?DateTimeInterface {
		return $this->editedAt;
	}

	public function setEditedAt ( ?DateTimeInterface $editedAt ): self {
		$this->editedAt = $editedAt;

		return $this;
	}

	public function isEdited (): bool {
		return $this->editedAt !== NULL;
	}

	public function getDeletedAt (): ?DateTimeInterface {
		return $this->deletedAt;
	}

	public function setDeletedAt ( ?DateTimeInterface $deletedAt ): self {
		$this->deletedAt = $deletedAt;

		return $this;
	}

	public function isDeleted (): bool {
		return $this->deletedAt !== NULL;
	}

	/**
	 * @return Collection|MessageReport[]
	 */
	public function getReports (): Collection {
		return $this->reports;
	}

	/**
	 * Les premiers mots, pour nommer la conversation dans la liste sans y
	 * entrer.
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	public function getExcerpt ( int $length = 120 ): string {
		if ( $this->isDeleted() ) {
			return '';
		}

		$text = preg_replace( '/\s+/u', ' ', trim( (string) $this->body ) );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, $length ) ) . '…';
	}
}
