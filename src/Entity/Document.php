<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\DocumentRepository")
 */
class Document {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 */
	private $user;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\Usergroup", inversedBy="documents")
	 */
	private $usergroup;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\File")
	 */
	private $file;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $slug;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $title;

	/**
	 * Quelques mots pour dire de quoi parle le document, afin de lui donner de
	 * la visibilité dans les listes et dans la recherche.
	 *
	 * @ORM\Column(type="text", nullable=true)
	 */
	private $description;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $createdAt;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\DocumentFolder", inversedBy="documents")
	 */
	private $folder;

	/**
	 * Le classement transversal : un document peut relever de plusieurs
	 * étiquettes, et une étiquette traverse les dossiers et les groupes. Le
	 * dossier dit où il est rangé, l'étiquette dit ce qu'il est. (#26)
	 *
	 * @ORM\ManyToMany(targetEntity="App\Entity\DocumentTag", inversedBy="documents")
	 * @ORM\JoinTable(name="documents_tags")
	 * @ORM\OrderBy({"name": "ASC"})
	 */
	private $tags;

	public function __construct () {
		$this->tags = new ArrayCollection();
	}

	/**
	 * @return Collection|DocumentTag[]
	 */
	public function getTags (): Collection {
		return $this->tags;
	}

	public function addTag ( DocumentTag $tag ): self {
		if ( !$this->tags->contains( $tag ) ) {
			$this->tags[] = $tag;
		}

		return $this;
	}

	public function removeTag ( DocumentTag $tag ): self {
		if ( $this->tags->contains( $tag ) ) {
			$this->tags->removeElement( $tag );
		}

		return $this;
	}

	/**
	 * Les identifiants des étiquettes portées, pour comparer un document au
	 * filtre en cours sans repasser par la base.
	 *
	 * @return int[]
	 */
	public function getTagIds () {
		$ids = [];

		foreach ( $this->tags as $tag ) {
			$ids[] = $tag->getId();
		}

		return $ids;
	}

	public function getId (): ?int {
		return $this->id;
	}

	public function getDescription (): ?string {
		return $this->description;
	}

	public function setDescription ( ?string $description ): self {
		$this->description = $description;

		return $this;
	}

	public function getUser (): ?User {
		return $this->user;
	}

	public function setUser ( ?User $user ): self {
		$this->user = $user;

		return $this;
	}

	public function getUsergroup (): ?Usergroup {
		return $this->usergroup;
	}

	public function setUsergroup ( ?Usergroup $usergroup ): self {
		$this->usergroup = $usergroup;

		return $this;
	}

	public function getFile (): ?File {
		return $this->file;
	}

	public function setFile ( ?File $file ): self {
		$this->file = $file;

		return $this;
	}

	public function getSlug (): ?string {
		return $this->slug;
	}

	public function setSlug ( ?string $slug ): self {
		$this->slug = $slug;

		return $this;
	}

	public function getTitle (): ?string {
		return $this->title;
	}

	public function setTitle ( ?string $title ): self {
		$this->title = mb_substr( $title, 0, 100 );

		return $this;
	}

	public function getCreatedAt (): ?\DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt ( \DateTimeInterface $createdAt ): self {
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getFolder (): ?DocumentFolder {
		return $this->folder;
	}

	public function setFolder ( ?DocumentFolder $folder ): self {
		$this->folder = $folder;

		return $this;
	}
}
