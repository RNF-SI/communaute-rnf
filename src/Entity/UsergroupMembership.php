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
	 * Two sources: what was said about this group — the legacy opt-out
	 * included, see below — then the member's general setting. Someone who
	 * opted out before #34 stays opted out on every category, and nobody is
	 * resubscribed by a general setting made afterwards; saying « suivre le
	 * réglage général » on the group is what drops that flag, and it is an
	 * explicit gesture.
	 *
	 * @param string $category
	 *
	 * @return string one of NotificationLevel
	 */
	public function getNotificationLevel ( $category ) {
		$own = $this->getOwnNotificationLevel( $category );

		if ( $own !== NULL ) {
			return $own;
		}

		$user = $this->getUser();

		return $user instanceof User
				? $user->getDefaultNotificationLevel( $category )
				: NotificationLevel::DEFAULT_LEVEL;
	}

	/**
	 * Ce que ce groupe dit de lui-même, ou rien du tout — auquel cas il suit
	 * le réglage général.
	 *
	 * Un désabonnement d'avant #34 est lu comme ce qu'il est : un « aucune
	 * notification » sur les quatre catégories. C'est le seul moyen que la
	 * page des paramètres montre la vérité — elle afficherait sinon « comme le
	 * réglage général » sur un groupe qui, lui, reste muet — et enregistrer
	 * une fois suffit alors à convertir l'ancien drapeau en choix explicites.
	 *
	 * @param string $category
	 *
	 * @return string|null one of NotificationLevel, or NULL
	 */
	public function getOwnNotificationLevel ( $category ) {
		$settings = $this->getNotificationsSettings() ?: [];
		$stored   = isset( $settings[ 'categories' ][ $category ] ) ? $settings[ 'categories' ][ $category ] : NULL;

		$user  = $this->getUser();
		$level = NotificationLevel::fromLegacy(
				$stored,
				$user instanceof User ? $user->getLegacyDiscussionRhythm() : NULL,
				$category
		);

		if ( $level !== NULL ) {
			return $level;
		}

		if ( !empty( $settings[ 'unsubscribed' ] ) && NotificationCategory::exists( $category ) ) {
			return NotificationLevel::NONE;
		}

		return NULL;
	}

	/**
	 * Ce que ce groupe dit de lui-même, catégorie par catégorie, dans l'ordre
	 * des catégories : de quoi le résumer en une ligne sans le déplier.
	 *
	 * @return array [ 'discussions' => 'none' ]
	 */
	public function getOwnNotificationLevels () {
		$levels = [];

		foreach ( NotificationCategory::all() as $category ) {
			$level = $this->getOwnNotificationLevel( $category );

			if ( $level !== NULL ) {
				$levels[ $category ] = $level;
			}
		}

		return $levels;
	}

	/**
	 * Ce groupe suit-il le réglage général, sans rien dire de particulier ?
	 * Un désabonnement d'avant #34 compte comme un réglage à lui.
	 *
	 * @return bool
	 */
	public function followsGeneralSettings () {
		return empty( $this->getOwnNotificationLevels() );
	}

	/**
	 * Ne plus rien dire de particulier sur une catégorie : elle repasse sous
	 * le réglage général.
	 *
	 * @param string $category
	 *
	 * @return $this
	 */
	public function clearNotificationLevel ( $category ) {
		$settings = $this->getNotificationsSettings() ?: [];

		unset( $settings[ 'categories' ][ $category ] );

		if ( isset( $settings[ 'categories' ] ) && empty( $settings[ 'categories' ] ) ) {
			unset( $settings[ 'categories' ] );
		}

		return $this->setNotificationsSettings( $settings );
	}

	/**
	 * Tout remettre sous le réglage général, y compris un désabonnement
	 * d'avant #34 : le demander est un geste explicite.
	 *
	 * @return $this
	 */
	public function followGeneralSettings () {
		$settings = $this->getNotificationsSettings() ?: [];

		unset( $settings[ 'categories' ], $settings[ 'unsubscribed' ] );

		return $this->setNotificationsSettings( $settings );
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

		$user = $this->getUser();

		return NotificationLevel::fromLegacy(
				$override,
				$user instanceof User ? $user->getLegacyDiscussionRhythm() : NULL,
				NotificationCategory::DISCUSSIONS
		);
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
	 * Ce que « suivre cette discussion » veut dire pour ce membre-là.
	 *
	 * Depuis #38 il n'y a plus un seul niveau « par e-mail » : il faut dire
	 * lequel. On reprend ce que le membre a demandé sur les discussions de ce
	 * groupe quand cela porte un e-mail — suivre une discussion ne doit pas
	 * lui imposer un rythme qu'il a écarté — et le résumé quotidien sinon.
	 *
	 * @return string one of NotificationLevel
	 */
	public function getFollowLevel () {
		$level = $this->getNotificationLevel( NotificationCategory::DISCUSSIONS );

		return NotificationLevel::sendsEmail( $level ) ? $level : NotificationLevel::DEFAULT_LEVEL;
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
