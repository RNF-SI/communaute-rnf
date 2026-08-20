<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une étiquette de classement des documents : « Grand public », « Cycle 1 »,
 * « Retour d'expérience »… (#26)
 *
 * Le vocabulaire est fermé et tenu par les administrateurs, commun à toute la
 * plateforme. Des étiquettes libres, dans un réseau de cette taille, se
 * dédoublent en synonymes — « cycle 1 », « Cycle1 », « cycle I » — et le
 * filtre ne veut plus rien dire au bout de quelques mois.
 *
 * @ORM\Table(name="document_tags")
 * @ORM\Entity(repositoryClass="App\Repository\DocumentTagRepository")
 */
class DocumentTag {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\Column(type="string", length=60)
	 */
	private $name;

	/**
	 * Ce qui identifie l'étiquette dans une adresse de filtre, et ce qui
	 * empêche de créer deux fois la même sous deux orthographes.
	 *
	 * @ORM\Column(type="string", length=60, unique=true)
	 */
	private $slug;

	/**
	 * @ORM\ManyToMany(targetEntity="App\Entity\Document", mappedBy="tags")
	 */
	private $documents;

	public function __construct () {
		$this->documents = new ArrayCollection();
	}

	public function getId (): ?int {
		return $this->id;
	}

	public function getName (): ?string {
		return $this->name;
	}

	public function setName ( ?string $name ): self {
		$this->name = mb_substr( trim( $name ?? '' ), 0, 60 );

		return $this;
	}

	public function getSlug (): ?string {
		return $this->slug;
	}

	public function setSlug ( ?string $slug ): self {
		$this->slug = $slug;

		return $this;
	}

	/**
	 * @return Collection|Document[]
	 */
	public function getDocuments (): Collection {
		return $this->documents;
	}

	public function __toString () {
		return (string) $this->name;
	}
}
