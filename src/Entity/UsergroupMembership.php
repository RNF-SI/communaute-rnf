<?php

namespace App\Entity;

use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="usergroups_memberships",uniqueConstraints={@ORM\UniqueConstraint(name="user_usergroup", columns={"user_id", "usergroup_id"})},indexes={@ORM\Index(name="status", columns={"status"})})
 * @ORM\Entity(repositoryClass="App\Repository\UsergroupMembershipRepository")
 */
class UsergroupMembership {
	const ROLE_ADMIN = 'admin';
	const ROLE_USER  = 'user';

	const STATUS_PENDING = 'pending';
	const STATUS_MEMBER  = 'member';
	const STATUS_BANNED  = 'banned';

	const STATUS_ALL = 'all';

	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\User", inversedBy="usergroupMemberships")
	 * @ORM\JoinColumn(nullable=false)
	 */
	private $user;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\Usergroup", inversedBy="members")
	 * @ORM\JoinColumn(nullable=false)
	 */
	private $usergroup;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $joinedAt;

	/**
	 * @ORM\Column(type="string", length=255, nullable=true)
	 */
	private $role;

	/**
	 * @ORM\Column(type="json", nullable=true)
	 */
	private $notificationsSettings = [];

	/**
	 * @ORM\Column(type="string", length=32, nullable=true)
	 */
	private $status;

	public function getId (): ?int {
		return $this->id;
	}

	public function getUser (): ?User {
		return $this->user;
	}

	public function setUser ( ?User $user ): self {
		$this->user = $user;

		return $this;
	}

	public function getJoinedAt (): ?\DateTimeInterface {
		return $this->joinedAt;
	}

	public function setJoinedAt ( \DateTimeInterface $joinedAt ): self {
		$this->joinedAt = $joinedAt;

		return $this;
	}

	public function getRole (): ?string {
		return $this->role;
	}

	public function setRole ( ?string $role ): self {
		$this->role = $role;

		return $this;
	}

	public function getNotificationsSettings (): ?array {
		return $this->notificationsSettings;
	}

	public function setNotificationsSettings ( ?array $notificationsSettings ): self {
		$this->notificationsSettings = $notificationsSettings;

		return $this;
	}

	public function getUsergroup (): ?Usergroup {
		return $this->usergroup;
	}

	public function setUsergroup ( ?Usergroup $usergroup ): self {
		$this->usergroup = $usergroup;

		return $this;
	}

	public function getStatus (): ?string {
		return $this->status;
	}

	public function setStatus ( ?string $status ): self {
		$this->status = $status;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function shouldReceiveDiscussionsEmails () {
		return ( $this->getStatus() === UsergroupMembership::STATUS_MEMBER ) && empty( $this->getNotificationsSettings()[ 'unsubscribed' ] );
	}

	/**
	 * How far notifications of a given kind of content go, for this member in
	 * this group.
	 *
	 * Settings written before #34 only knew a single `unsubscribed` flag for
	 * the whole group. They are read as they were meant: someone who opted out
	 * stays opted out on every category. Nobody is resubscribed by the change.
	 *
	 * @param string $category
	 *
	 * @return string one of NotificationLevel
	 */
	public function getNotificationLevel ( $category ) {
		$settings = $this->getNotificationsSettings() ?: [];

		if ( isset( $settings[ 'categories' ][ $category ] )
			 && NotificationLevel::exists( $settings[ 'categories' ][ $category ] ) ) {
			return $settings[ 'categories' ][ $category ];
		}

		if ( !empty( $settings[ 'unsubscribed' ] ) ) {
			return NotificationLevel::NONE;
		}

		return NotificationLevel::DEFAULT_LEVEL;
	}

	/**
	 * @param string $category
	 * @param string $level
	 *
	 * @return $this
	 */
	public function setNotificationLevel ( $category, $level ) {
		if ( !NotificationCategory::exists( $category ) || !NotificationLevel::exists( $level ) ) {
			return $this;
		}

		$settings = $this->getNotificationsSettings() ?: [];

		// The legacy flag has no meaning left once a category is set by hand;
		// keeping it would silently override what the member just chose.
		unset( $settings[ 'unsubscribed' ] );

		$settings[ 'categories' ][ $category ] = $level;

		return $this->setNotificationsSettings( $settings );
	}

	/**
	 * What this member chose for one discussion in particular, if anything.
	 *
	 * @param string $discussionUuid
	 *
	 * @return string|null one of NotificationLevel, or NULL when the member
	 *                     never said anything about this discussion
	 */
	public function getDiscussionOverride ( $discussionUuid ) {
		$settings = $this->getNotificationsSettings() ?: [];
		$override = isset( $settings[ 'discussions' ][ $discussionUuid ] )
				? $settings[ 'discussions' ][ $discussionUuid ]
				: NULL;

		return NotificationLevel::exists( $override ) ? $override : NULL;
	}

	/**
	 * @param string      $discussionUuid
	 * @param string|null $level NULL goes back to following the category
	 *
	 * @return $this
	 */
	public function setDiscussionOverride ( $discussionUuid, $level ) {
		$settings = $this->getNotificationsSettings() ?: [];

		if ( $level === NULL ) {
			unset( $settings[ 'discussions' ][ $discussionUuid ] );

			return $this->setNotificationsSettings( $settings );
		}

		if ( !NotificationLevel::exists( $level ) ) {
			return $this;
		}

		$settings[ 'discussions' ][ $discussionUuid ] = $level;

		return $this->setNotificationsSettings( $settings );
	}

	/**
	 * The level that actually applies to one discussion. A choice made on a
	 * single discussion wins over the category setting, in both directions:
	 * following a discussion in a muted category works, and muting a
	 * discussion in a followed category works too. (#34)
	 *
	 * @param string $discussionUuid
	 *
	 * @return string one of NotificationLevel
	 */
	public function getLevelForDiscussion ( $discussionUuid ) {
		$override = $this->getDiscussionOverride( $discussionUuid );

		return $override !== NULL
				? $override
				: $this->getNotificationLevel( NotificationCategory::DISCUSSIONS );
	}
}
