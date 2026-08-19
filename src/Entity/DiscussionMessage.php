<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\DiscussionMessageRepository")
 */
class DiscussionMessage {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\Discussion", inversedBy="messages")
	 * @ORM\JoinColumn(nullable=false)
	 */
	private $discussion;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 * @ORM\JoinColumn(nullable=false)
	 */
	private $author;

	/**
	 * @ORM\Column(type="text", nullable=true)
	 */
	private $body;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $createdAt;

	/**
	 * When this message was removed. Its content goes, the message stays: a
	 * discussion whose answers reply to something that vanished becomes
	 * unreadable. (#19)
	 *
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $deletedAt;

	/**
	 * When the author last corrected their message. Displayed next to it, so
	 * that a correction stays visible rather than silent. (#19)
	 *
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $editedAt;

	/**
	 * @ORM\Column(type="boolean", nullable=true)
	 */
	private $masked;

	/**
	 * @ORM\ManyToMany(targetEntity="App\Entity\File")
	 */
	private $files;

	/**
	 * Identifier of the inbound e-mail this message was created from, when it
	 * comes from a reply by e-mail. Postmark retries a webhook it believes
	 * failed, so it is used to recognise a delivery that was already handled.
	 *
	 * @ORM\Column(type="string", length=255, nullable=true, unique=true)
	 */
	private $inboundMessageId;

	public function __construct () {
		$this->files = new ArrayCollection();
	}

	public function getId (): ?int {
		return $this->id;
	}

	public function getDeletedAt (): ?\DateTimeInterface {
		return $this->deletedAt;
	}

	public function setDeletedAt ( ?\DateTimeInterface $deletedAt ): self {
		$this->deletedAt = $deletedAt;

		return $this;
	}

	public function isDeleted (): bool {
		return $this->deletedAt !== NULL;
	}

	public function getEditedAt (): ?\DateTimeInterface {
		return $this->editedAt;
	}

	public function setEditedAt ( ?\DateTimeInterface $editedAt ): self {
		$this->editedAt = $editedAt;

		return $this;
	}

	public function getInboundMessageId (): ?string {
		return $this->inboundMessageId;
	}

	public function setInboundMessageId ( ?string $inboundMessageId ): self {
		$this->inboundMessageId = $inboundMessageId;

		return $this;
	}

	public function getDiscussion (): ?Discussion {
		return $this->discussion;
	}

	public function setDiscussion ( ?Discussion $discussion ): self {
		$this->discussion = $discussion;

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
		$this->body = trim( $body );

		return $this;
	}

	public function getCreatedAt (): ?\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt ( \DateTimeInterface $createdAt ): self {
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getMasked (): ?bool {
		return $this->masked;
	}

	public function setMasked ( ?bool $masked ): self {
		$this->masked = $masked;

		return $this;
	}

	/**
	 * @return Collection|File[]
	 */
	public function getFiles (): Collection {
		return $this->files;
	}

	public function addFile ( File $file ): self {
		if ( !$this->files->contains( $file ) ) {
			$this->files[] = $file;
		}

		return $this;
	}

	public function removeFile ( File $file ): self {
		if ( $this->files->contains( $file ) ) {
			$this->files->removeElement( $file );
		}

		return $this;
	}
}
