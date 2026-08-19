<?php

namespace App\Service;

use App\Service\Community;
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
	 * C'est la seule donnée dont on dispose réellement pour trier les groupes.
	 * Les catégories n'ont jamais été renseignées, et un groupe ne porte aucune
	 * information géographique. (#23)
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
