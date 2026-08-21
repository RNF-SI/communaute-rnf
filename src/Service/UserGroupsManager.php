<?php

namespace App\Service;

use App\Service\Community;
use App\Entity\Category;
use App\Entity\Usergroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;


class UserGroupsManager {
	/**
	 * @var array
	 */
	private $groups;
	/**
	 * @var array
	 */
	private $groupsToActivate;

	/**
	 * Community constructor.
	 *
	 * @param                                            $community
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 */
	public function __construct (
		Community $community,
		EntityManagerInterface $manager
	) {
		$groupsManager = $manager->getRepository( Usergroup::class );
		$this->setGroups($groupsManager->getGroupsWithMembers( $community->getGroup(), true ));
		$this->setGroupsToActivate($groupsManager->getGroupsWithMembers( false, false ));
	}

	public function getGroups(): array {
		return $this->groups;
	}
	public function setGroups(array $groups) {
		$this->groups = $groups;
	}

	public function getGroupsToActivate(): array {
		return $this->groupsToActivate;
	}
	public function setGroupsToActivate(array $groupsToActivate) {
		$this->groupsToActivate = $groupsToActivate;
	}

	public function getGroupsFromType(string $groupType): array{
		if($groupType=='all-groups-container'){
			$groups = $this->getGroups();
		} else if ($groupType=='groups-to-activate-elements'){
			$groups = $this->getGroupsToActivate();
		} else {
			$groups = [];
		}
		return $groups;
	}

	public function getImportantGroups(): array {
		$allGroups = $this->getGroups();
		$importantGroups = [];
		foreach ($allGroups as $group) {
			if ($group->getIsImportant()) {
				$importantGroups[] = $group;
			}
		}
		return $importantGroups;
	}

	public function getRegularGroups(): array {
		$allGroups = $this->getGroups();
		$regularGroups = [];
		foreach ($allGroups as $group) {
			if (!$group->getIsImportant()) {
				$regularGroups[] = $group;
			}
		}
		return $regularGroups;
	}

	/**
	 * Les groupes qui en chapeautent d'autres : commissions, pôles, collectifs.
	 *
	 * Le premier des deux axes de tri : de qui un groupe dépend. Le second est
	 * la thématique, de quoi il parle. Un groupe ne porte en revanche aucune
	 * information géographique, et le filtre par périmètre a été écarté faute
	 * de donnée à filtrer. (#23)
	 *
	 * @return \App\Entity\Usergroup[]
	 */
	public function getParentGroups(): array {
		$parents = [];

		foreach ($this->getGroups() as $group) {
			if (!$group->getChildren()->isEmpty()) {
				$parents[] = $group;
			}
		}

		usort($parents, function (Usergroup $a, Usergroup $b) {
			return strcoll((string) $a->getName(), (string) $b->getName());
		});

		return $parents;
	}

	/**
	 * Les thématiques qui classent réellement au moins un groupe. (#23)
	 *
	 * Une entrée du vocabulaire que personne n'a encore attribuée ne serait
	 * qu'un choix qui vide la liste : elle n'est proposée qu'une fois portée.
	 *
	 * @return \App\Entity\Category[]
	 */
	public function getCategories(): array {
		$categories = [];

		foreach ($this->getGroups() as $group) {
			foreach ($group->getCategories() as $category) {
				$categories[$category->getId()] = $category;
			}
		}

		$categories = array_values($categories);

		usort($categories, function (Category $a, Category $b) {
			return strcoll((string) $a->getName(), (string) $b->getName());
		});

		return $categories;
	}

	/**
	 * @param string $slug
	 *
	 * @return \App\Entity\Category|null
	 */
	public function getCategoryBySlug(string $slug): ?Category {
		foreach ($this->getCategories() as $category) {
			if ($category->getSlug() === $slug) {
				return $category;
			}
		}

		return NULL;
	}

	/**
	 * Ne garder d'une liste que les groupes portant cette thématique. (#23)
	 *
	 * Le tri se fait sur une liste déjà constituée plutôt que par une requête,
	 * de sorte que thématique et commission se cumulent : les deux filtres
	 * répondent à deux questions différentes, et l'on peut vouloir poser les
	 * deux.
	 *
	 * @param \App\Entity\Usergroup[] $groups
	 * @param \App\Entity\Category    $category
	 *
	 * @return \App\Entity\Usergroup[]
	 */
	public function keepWithCategory(array $groups, Category $category): array {
		return array_values(array_filter($groups, function (Usergroup $group) use ($category) {
			foreach ($group->getCategories() as $held) {
				if ($held->getId() === $category->getId()) {
					return TRUE;
				}
			}

			return FALSE;
		}));
	}

	/**
	 * @param string $slug
	 *
	 * @return \App\Entity\Usergroup|null
	 */
	public function getGroupBySlug(string $slug): ?Usergroup {
		foreach ($this->getGroups() as $group) {
			if ($group->getSlug() === $slug) {
				return $group;
			}
		}

		return NULL;
	}

	/**
	 * Un groupe et tout ce qu'il chapeaute, à n'importe quelle profondeur.
	 *
	 * Un même groupe peut dépendre de plusieurs parents — « Atelier inter
	 * commissions Montagne » en dépend de cinq — d'où le suivi des groupes déjà
	 * vus, qui évite aussi de tourner en rond sur un cycle.
	 *
	 * @param \App\Entity\Usergroup $parent
	 *
	 * @return \App\Entity\Usergroup[]
	 */
	public function getGroupsUnder(Usergroup $parent): array {
		$found = [];
		$queue = [$parent];

		while ($queue) {
			$group = array_shift($queue);
			$id    = $group->getId();

			if (isset($found[$id])) {
				continue;
			}

			$found[$id] = $group;

			foreach ($group->getChildren() as $child) {
				$queue[] = $child;
			}
		}

		// Restreint à ce que l'appelant a le droit de voir.
		$visible = [];

		foreach ($this->getGroups() as $group) {
			if (isset($found[$group->getId()])) {
				$visible[] = $group;
			}
		}

		return $visible;
	}

	/**
	 * Range une liste plate de groupes selon la hiérarchie qui les relie.
	 *
	 * Un groupe est une racine quand aucun de ses parents ne figure dans la
	 * liste affichée : une recherche qui ne remonte qu'un atelier le montre
	 * donc au premier niveau plutôt que de le faire disparaître sous une
	 * commission absente.
	 *
	 * Un même groupe peut dépendre de plusieurs parents — « Atelier inter
	 * commissions Montagne » en dépend de cinq — et apparaît alors sous chacun
	 * d'eux. Le chemin parcouru est suivi pour qu'un cycle ne fasse pas tourner
	 * la construction en rond. (#22)
	 *
	 * @param \App\Entity\Usergroup[] $groups
	 *
	 * @return array liste de ['group' => Usergroup, 'children' => array]
	 */
	public function asTree(array $groups): array {
		$visible = [];

		foreach ($groups as $group) {
			$visible[$group->getId()] = $group;
		}

		$roots = [];

		foreach ($groups as $group) {
			$hasVisibleParent = FALSE;

			foreach ($group->getParents() as $parent) {
				if (isset($visible[$parent->getId()])) {
					$hasVisibleParent = TRUE;
					break;
				}
			}

			if (!$hasVisibleParent) {
				$roots[] = $group;
			}
		}

		$tree = [];

		foreach ($roots as $root) {
			$tree[] = $this->branch($root, $visible, []);
		}

		return $tree;
	}

	/**
	 * @param \App\Entity\Usergroup   $group
	 * @param \App\Entity\Usergroup[] $visible
	 * @param array                    $path identifiants déjà traversés
	 *
	 * @return array
	 */
	private function branch(Usergroup $group, array $visible, array $path): array {
		$path[$group->getId()] = TRUE;

		$children = [];

		foreach ($group->getChildren() as $child) {
			if (!isset($visible[$child->getId()]) || isset($path[$child->getId()])) {
				continue;
			}

			$children[] = $this->branch($child, $visible, $path);
		}

		usort($children, function (array $a, array $b) {
			return strcoll((string) $a['group']->getName(), (string) $b['group']->getName());
		});

		return [
				'group'    => $group,
				'children' => $children,
		];
	}

	public function getGroupsFilteredByIds(array $idsList, string $groupType): array{
		$groups = $this->getGroupsFromType($groupType);
		$result = [];
		foreach($groups as $group){
			if (in_array($group->getId(), $idsList)){
				array_push($result, $group);
			}
		}
		return $result;
	}
}
