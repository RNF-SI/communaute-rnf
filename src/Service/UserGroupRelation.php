<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use Doctrine\ORM\EntityManagerInterface;

class UserGroupRelation {

	private $manager;

	private $community;

	public function __construct (
		EntityManagerInterface $manager,
		Community $community
	) {
		$this->manager = $manager;
		$this->community = $community;
	}

	public function isAdmin ( ?User $user, Usergroup $group ) {
		if ( empty( $user ) || !( $user instanceof User ) ) {
			return FALSE;
		}

		$membership = $this->manager->getRepository( UsergroupMembership::class )
									->getMembership( $user, $group );

		return !empty( $membership ) && ( $membership->getRole() === UsergroupMembership::ROLE_ADMIN );
	}

	public function isCommunityAdmin ( ?User $user ) {
		$communityGroup = $this->community->getGroup();
		if ( !$communityGroup ) {
			return FALSE;
		}

		return $this->isAdmin( $user, $communityGroup );
	}

	/**
	 * Qui modère la plateforme : un administrateur (`ROLE_ADMIN`) ou un
	 * animateur du groupe communauté. `GroupVoter` accorde tout à l'un comme à
	 * l'autre ; valider un groupe, le créer sans validation ou ranger la
	 * hiérarchie doit suivre la même définition, sans quoi un administrateur
	 * qui n'anime pas la communauté voit ses propres groupes partir en
	 * attente de validation. (#42)
	 *
	 * @param \App\Entity\User|null $user
	 *
	 * @return bool
	 */
	public function isPlatformModerator ( ?User $user ) {
		if ( !( $user instanceof User ) ) {
			return FALSE;
		}

		return $user->isAdmin() || $this->isCommunityAdmin( $user );
	}

	/**
	 * @param \App\Entity\User|null $user
	 * @param \App\Entity\Usergroup $group
	 *
	 * @return \App\Entity\UsergroupMembership|null
	 */
	public function getMembership ( ?User $user, Usergroup $group ) {
		if ( !( $user instanceof User ) ) {
			return NULL;
		}

		return $this->manager->getRepository( UsergroupMembership::class )
							 ->getMembership( $user, $group );
	}

	public function isMember ( ?User $user, Usergroup $group ) {
		return $this->manager->getRepository( UsergroupMembership::class )
							 ->isMember( $user, $group );
	}

	public function isSubscribed ( ?User $user, Usergroup $group ) {
		return $this->manager->getRepository( UsergroupMembership::class )
							 ->isSubscribed( $user, $group );
	}

	public function isBanned ( ?User $user, Usergroup $group ) {
		return $this->manager->getRepository( UsergroupMembership::class )
							 ->isBanned( $user, $group );
	}

	public function isPending ( ?User $user, Usergroup $group ) {
		return $this->manager->getRepository( UsergroupMembership::class )
							 ->isPending( $user, $group );
	}

	public function getGroupsUserCanAdmin ( ?User $user, array $groups ) {
		if ( empty( $user ) || !( $user instanceof User ) ) {
			return FALSE;
		}

		if ( $this->isPlatformModerator( $user ) ) {
			return $groups;
		}

		$groupsUserCanAdmin = [];
		foreach ( $groups as $group ) {
			if ( $this->isAdmin( $user, $group ) ) {
				$groupsUserCanAdmin[] = $group;
			}
		}

		return $groupsUserCanAdmin;
	}
}
