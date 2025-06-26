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
