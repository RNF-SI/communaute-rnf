<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Something that happened in a group, worth telling one member about. (#34)
 *
 * The title and the address of the content are copied rather than pointed at:
 * a notification stays readable once the page or the document it announces has
 * been removed, and building the list costs no join.
 *
 * @ORM\Table(
 *     name="notifications",
 *     indexes={
 *         @ORM\Index(name="recipient_read", columns={"recipient_id", "read_at"}),
 *         @ORM\Index(name="pending_email", columns={"by_email", "emailed_at"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\NotificationRepository")
 */
class Notification {
	const DISCUSSION_MESSAGE = 'discussion:message';
	const PAGE_CREATE        = 'page:create';
	const ARTICLE_CREATE     = 'article:create';
	const DOCUMENT_CREATE    = 'document:create';

	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
	 */
	private $recipient;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
	 */
	private $author;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\Usergroup")
	 * @ORM\JoinColumn(nullable=true, onDelete="CASCADE")
	 */
	private $usergroup;

	/**
	 * @ORM\Column(type="string", length=50)
	 */
	private $type;

	/**
	 * @ORM\Column(type="string", length=255)
	 */
	private $title;

	/**
	 * @ORM\Column(type="string", length=255)
	 */
	private $url;

	/**
	 * Whether this one belongs in the daily summary. Decided when the
	 * notification is created, from the preferences in force at that moment.
	 *
	 * @ORM\Column(type="boolean")
	 */
	private $byEmail = FALSE;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $createdAt;

	/**
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $readAt;

	/**
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $emailedAt;

	public function getId (): ?int {
		return $this->id;
	}

	public function getRecipient (): ?User {
		return $this->recipient;
	}

	public function setRecipient ( ?User $recipient ): self {
		$this->recipient = $recipient;

		return $this;
	}

	public function getAuthor (): ?User {
		return $this->author;
	}

	public function setAuthor ( ?User $author ): self {
		$this->author = $author;

		return $this;
	}

	public function getUsergroup (): ?Usergroup {
		return $this->usergroup;
	}

	public function setUsergroup ( ?Usergroup $usergroup ): self {
		$this->usergroup = $usergroup;

		return $this;
	}

	public function getType (): ?string {
		return $this->type;
	}

	public function setType ( string $type ): self {
		$this->type = $type;

		return $this;
	}

	public function getTitle (): ?string {
		return $this->title;
	}

	public function setTitle ( string $title ): self {
		$this->title = mb_substr( $title, 0, 255 );

		return $this;
	}

	public function getUrl (): ?string {
		return $this->url;
	}

	public function setUrl ( string $url ): self {
		$this->url = $url;

		return $this;
	}

	public function isByEmail (): bool {
		return (bool) $this->byEmail;
	}

	public function setByEmail ( bool $byEmail ): self {
		$this->byEmail = $byEmail;

		return $this;
	}

	public function getCreatedAt (): ?\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt ( \DateTimeInterface $createdAt ): self {
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getReadAt (): ?\DateTimeInterface {
		return $this->readAt;
	}

	public function setReadAt ( ?\DateTimeInterface $readAt ): self {
		$this->readAt = $readAt;

		return $this;
	}

	public function isRead (): bool {
		return $this->readAt !== NULL;
	}

	public function getEmailedAt (): ?\DateTimeInterface {
		return $this->emailedAt;
	}

	public function setEmailedAt ( ?\DateTimeInterface $emailedAt ): self {
		$this->emailedAt = $emailedAt;

		return $this;
	}
}
