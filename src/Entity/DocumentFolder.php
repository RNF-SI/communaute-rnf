<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\DocumentFolderRepository")
 */
class DocumentFolder {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\Usergroup", inversedBy="documentFolders")
	 * @ORM\JoinColumn(nullable=false)
	 */
	private $usergroup;

	/**
	 * @ORM\Column(type="string", length=100)
	 */
	private $title;

	/**
	 * @ORM\OneToMany(targetEntity="App\Entity\Document", mappedBy="folder")
	 * @ORM\OrderBy({"title"="ASC"})
	 */
	private $documents;

	/**
	 * Le séparateur employé quand un dossier est désigné par son chemin.
	 */
	public const SEPARATOR = '/';

	public function __construct () {
		$this->documents = new ArrayCollection();
		$this->children = new ArrayCollection();
	}

	/**
	 * Le dossier qui contient celui-ci, s'il y en a un. Les dossiers étaient
	 * jusqu'ici tous au même niveau, ce qui rend le classement impossible dès
	 * qu'un groupe accumule des documents. (#8)
	 *
	 * @ORM\ManyToOne(targetEntity="App\Entity\DocumentFolder", inversedBy="children")
	 * @ORM\JoinColumn(nullable=true, onDelete="CASCADE")
	 */
	private $parent;

	/**
	 * @ORM\OneToMany(targetEntity="App\Entity\DocumentFolder", mappedBy="parent")
	 * @ORM\OrderBy({"title"="ASC"})
	 */
	private $children;

	public function getId (): ?int {
		return $this->id;
	}

	public function getParent (): ?self {
		return $this->parent;
	}

	public function setParent ( ?self $parent ): self {
		$this->parent = $parent;

		return $this;
	}

	public function getChildren (): Collection {
		return $this->children;
	}

	/**
	 * Le chemin complet du dossier, tel qu'on l'écrit pour le désigner :
	 * « Comptes rendus / 2026 ».
	 *
	 * @return string
	 */
	public function getPath (): string {
		$names  = [ (string) $this->getTitle() ];
		$folder = $this->getParent();
		$seen   = [ $this->getId() => TRUE ];

		while ( $folder && !isset( $seen[ $folder->getId() ] ) ) {
			$seen[ $folder->getId() ] = TRUE;

			array_unshift( $names, (string) $folder->getTitle() );

			$folder = $folder->getParent();
		}

		return implode( ' ' . self::SEPARATOR . ' ', $names );
	}

	/**
	 * @return int
	 */
	public function getDepth (): int {
		$depth  = 0;
		$folder = $this->getParent();
		$seen   = [ $this->getId() => TRUE ];

		while ( $folder && !isset( $seen[ $folder->getId() ] ) ) {
			$seen[ $folder->getId() ] = TRUE;
			$depth++;

			$folder = $folder->getParent();
		}

		return $depth;
	}

	public function getUsergroup (): ?Usergroup {
		return $this->usergroup;
	}

	public function setUsergroup ( ?Usergroup $usergroup ): self {
		$this->usergroup = $usergroup;

		return $this;
	}

	public function getTitle (): ?string {
		return $this->title;
	}

	public function setTitle ( string $title ): self {
		$this->title = $title;

		return $this;
	}

	/**
	 * @return Collection|Document[]
	 */
	public function getDocuments (): Collection {
		return $this->documents;
	}

	public function addDocument ( Document $document ): self {
		if ( !$this->documents->contains( $document ) ) {
			$this->documents[] = $document;
			$document->setFolder( $this );
		}

		return $this;
	}

	public function removeDocument ( Document $document ): self {
		if ( $this->documents->contains( $document ) ) {
			$this->documents->removeElement( $document );
			// set the owning side to null (unless already changed)
			if ( $document->getFolder() === $this ) {
				$document->setFolder( NULL );
			}
		}

		return $this;
	}
}
