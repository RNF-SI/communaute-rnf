<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une thématique de groupe : « Gestion des milieux », « Sensibilisation »,
 * « Police de la nature »… (#23)
 *
 * Second axe de tri de la liste des groupes, à côté de la commission qui les
 * chapeaute : la hiérarchie dit de qui un groupe dépend, la thématique dit de
 * quoi il parle, et les deux ne se recouvrent pas.
 *
 * Le vocabulaire est fermé et tenu par les administrateurs, comme celui des
 * étiquettes de documents (#26) et pour la même raison : à cette échelle, des
 * thématiques saisies librement se dédoublent en synonymes et le filtre cesse
 * de vouloir dire quelque chose.
 *
 * @ORM\Table(name="categories")
 * @ORM\Entity(repositoryClass="App\Repository\CategoryRepository")
 */
class Category {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\Column(type="string", length=255)
	 */
	private $name;

	/**
	 * Ce qui identifie la thématique dans une adresse de filtre, et ce qui
	 * empêche de la créer deux fois sous deux orthographes.
	 *
	 * @ORM\Column(type="string", length=255, unique=true)
	 */
	private $slug;

	/**
	 * @ORM\Column(type="text", nullable=true)
	 */
	private $description;

	/**
	 * @ORM\ManyToMany(targetEntity="App\Entity\Usergroup", mappedBy="categories")
	 */
	private $usergroups;

	public function __construct () {
		$this->usergroups = new ArrayCollection();
	}

	public function getId (): ?int {
		return $this->id;
	}

	public function getName (): ?string {
		return $this->name;
	}

	public function setName ( string $name ): self {
		$this->name = $name;

		return $this;
	}

	public function getSlug (): ?string {
		return $this->slug;
	}

	public function setSlug ( string $slug ): self {
		$this->slug = $slug;

		return $this;
	}

	public function getDescription (): ?string {
		return $this->description;
	}

	public function setDescription ( ?string $description ): self {
		$this->description = $description;

		return $this;
	}

	/**
	 * @return Collection|Usergroup[]
	 */
	public function getUsergroups (): Collection {
		return $this->usergroups;
	}

	public function addUsergroup ( Usergroup $usergroup ): self {
		if ( !$this->usergroups->contains( $usergroup ) ) {
			$this->usergroups[] = $usergroup;
			$usergroup->addCategory( $this );
		}

		return $this;
	}

	public function removeUsergroup ( Usergroup $usergroup ): self {
		if ( $this->usergroups->contains( $usergroup ) ) {
			$this->usergroups->removeElement( $usergroup );
			$usergroup->removeCategory( $this );
		}

		return $this;
	}
}
