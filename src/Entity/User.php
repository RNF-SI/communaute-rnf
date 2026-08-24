<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Notification\NotificationRhythm;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @ORM\Table(name="users")
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 */
class User implements UserInterface, JsonSerializable {
	public const STATUS_DISABLED = 0;
	public const STATUS_ACTIVE   = 1;
	public const STATUS_PENDING  = 2;

	public const ROLE_USER  = 'ROLE_USER';
	public const ROLE_ADMIN = 'ROLE_ADMIN';



	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * @ORM\Column(type="string", length=180, unique=true)
	 */
	private $email;

	/**
	 * @ORM\Column(type="json")
	 */
	private $roles = [];

	/**
	 * @var string The hashed password
	 * @ORM\Column(type="string")
	 */
	private $password;

	/**
	 * @ORM\Column(type="smallint")
	 */
	private $status;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $name;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $displayName;

	/**
	 * @ORM\OneToOne(targetEntity="App\Entity\File", cascade={"persist", "remove"})
	 */
	private $avatar;

	/**
	 * @ORM\Column(type="string", length=10, nullable=true)
	 */
	private $zipcode;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $city;

	/**
	 * @ORM\Column(type="string", length=2, nullable=true)
	 */
	private $country;

	/**
	 * @ORM\Column(type="string", length=5, nullable=true)
	 */
	private $region;

	/**
	 * @ORM\Column(type="text", length=64, nullable=true)
	 */
	private $presentation;

	/**
	 * Ce qu'un annuaire professionnel doit dire d'abord : la fonction, la
	 * structure, et les réserves suivies. Saisi à la main pour l'instant ;
	 * GeoNature fait autorité sur ces informations et devrait les fournir un
	 * jour, comme il le fait déjà pour le nom. (#30, #28)
	 *
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $jobTitle;

	/**
	 * @ORM\Column(type="string", length=150, nullable=true)
	 */
	private $organisation;

	/**
	 * @ORM\Column(type="string", length=255, nullable=true)
	 */
	private $reserves;

	/**
	 * Héritage NaturAdapt, retiré du profil et de la fiche annuaire : une
	 * biographie libre ne disait pas ce qu'on cherche dans cet annuaire.
	 * La colonne reste le temps de vérifier ce qu'elle contient encore, elle
	 * n'est plus ni lue ni écrite nulle part. (#30)
	 *
	 * @ORM\Column(type="text", nullable=true)
	 */
	private $bio;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $profileVisibility;

	/**
	 * Quand la visite guidée a été vue. Tant que c'est vide, elle se lance
	 * d'elle-même à l'arrivée sur la plateforme ; ensuite elle ne se relance
	 * que sur demande, depuis les paramètres. (#39)
	 *
	 * Une date plutôt qu'un oui/non : le jour où la visite change, on saura
	 * qui l'a vue dans sa version d'avant.
	 *
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $tourSeenAt;

	/**
	 * Le téléphone direct que le membre veut bien publier dans l'annuaire.
	 * Vide par défaut : le renseigner est le consentement. (#27)
	 *
	 * @ORM\Column(type="string", length=30, nullable=true)
	 */
	private $phone;

	/**
	 * L'adresse est le moyen de contact du réseau, elle est donc montrée par
	 * défaut aux membres connectés. Qui ne le souhaite pas la retire de sa
	 * fiche. (#27)
	 *
	 * @ORM\Column(type="boolean", options={"default": true})
	 */
	private $emailVisible = TRUE;


	/**
	 * @ORM\ManyToMany(targetEntity="App\Entity\Skill")
	 * @ORM\JoinTable(name="users_skills")
	 */
	private $skills;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $locale;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $timezone;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $createdAt;

	/**
	 * @ORM\Column(type="datetime",nullable=true)
	 */
	private $seenAt;

	/**
	 * @ORM\Column(type="string", length=255, nullable=true)
	 */
	private $resetToken;

	/**
	 * @ORM\OneToMany(targetEntity="App\Entity\UsergroupMembership", mappedBy="user", orphanRemoval=true)
	 */
	private $usergroupMemberships;

	/**
	 * @ORM\Column(type="float", nullable=true)
	 */
	private $latitude;

	/**
	 * @ORM\Column(type="float", nullable=true)
	 */
	private $longitude;

	/**
	 * @ORM\Column(type="string", length=180, nullable=true)
	 */
	private $emailNew;

	/**
	 * @ORM\Column(type="string", length=255, nullable=true)
	 */
	private $emailToken;

	/**
	 * Terms Of Use
	 *
	 * @ORM\Column(type="boolean", options={"default":"0"})
	 */
	private $hasAgreedTermsOfUse;


	/**
	 * RNF specific fields for external authentication
	 */
	
	/**
	 * @ORM\Column(type="integer", nullable=true)
	 */
	private $rnfIdRole;

	/**
	 * @ORM\Column(type="integer", nullable=true)
	 */
	private $rnfIdOrganisme;

	/**
	 * @ORM\Column(type="string", length=255, nullable=true)
	 */
	private $rnfUserLogin;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $rnfPrenomRole;

	/**
	 * @ORM\Column(type="string", length=100, nullable=true)
	 */
	private $rnfNomRole;

	/**
	 * @ORM\Column(type="json", nullable=true)
	 */
	private $rnfRoleInfo = [];

	/**
	 * @ORM\Column(type="boolean", options={"default":"0"})
	 */
	private $firstLoginNotified = false;

	/**
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $profileUpdatedAt;

	/**
	 * Cette boîte accepte-t-elle qu'un membre y écrive ?
	 *
	 * Une colonne à elle plutôt qu'une ligne de plus dans le JSON des
	 * notifications : ce n'est pas un réglage d'e-mail mais une porte, et
	 * c'est une requête — « à qui puis-je écrire » — qui la lit.
	 *
	 * Fermer sa boîte ne ferme pas les conversations déjà ouvertes : on y
	 * répond encore. Ce qu'on ne peut plus, c'est en ouvrir une nouvelle.
	 *
	 * @ORM\Column(type="boolean", options={"default":"1"})
	 */
	private $messagesOpen = TRUE;

	/**
	 * Choices that apply to every group at once: whether e-mails go out at
	 * all, at what rhythm discussion e-mails leave (#34), and what a group
	 * that says nothing of its own is worth, category by category.
	 *
	 * That last part is what keeps the settings page usable for somebody who
	 * sits in thirty groups: the choice is made once here, and a group only
	 * appears in the list to say how it differs.
	 *
	 * @ORM\Column(type="json", nullable=true)
	 */
	private $notificationsSettings = [];

	public function __construct () {
		$this->usergroupMemberships = new ArrayCollection();
		$this->skills               = new ArrayCollection();
	}

	public function getId (): ?int {
		return $this->id;
	}

	/**
	 * A unique identifier that represents this user.
	 *
	 * @see UserInterface
	 */
	public function getUsername (): string {
		return (string)$this->getEmail();
	}

	/****************************************
	 * FIELDS
	 ****************************************/

	public function getDisplayName (): ?string {
		if ( !empty( $this->displayName ) ) {
			return $this->displayName;
		}

		if ( !empty( $this->getName() ) ) {
			return $this->getName();
		}

		return mb_convert_case( explode( '@', $this->getEmail() )[ 0 ], MB_CASE_TITLE );
	}

	public function setDisplayName ( ?string $displayName ): self {
		$this->displayName = trim( $displayName );

		return $this;
	}

	public function getName (): ?string {
		return $this->name;
	}

	public function setName ( string $name ): self {
		$this->name = mb_convert_case( trim( $name ), MB_CASE_TITLE );

		return $this;
	}

	public function getEmail (): ?string {
		return $this->email;
	}

	public function setEmail ( string $email ): self {
		$this->email = mb_convert_case( trim( $email ), MB_CASE_LOWER );

		return $this;
	}

	public function isAdmin (): ?bool {
		return in_array( User::ROLE_ADMIN, $this->getRoles() );
	}

	/**
	 * @see UserInterface
	 */
	public function getRoles (): array {
		$roles = $this->roles;
		// guarantee every user at least has ROLE_USER
		$roles[] = User::ROLE_USER;

		return array_unique( $roles );
	}

	public function setRoles ( array $roles ): self {
		$this->roles = array_unique( $roles );

		return $this;
	}

	/**
	 * @see UserInterface
	 */
	public function getPassword (): string {
		return (string)$this->password;
	}

	public function setPassword ( string $password ): self {
		$this->password = $password;

		return $this;
	}

	/**
	 * @see UserInterface
	 */
	public function getSalt () {
		// not needed when using the "bcrypt" algorithm in security.yaml
	}

	/**
	 * @see UserInterface
	 */
	public function eraseCredentials () {
		// If you store any temporary, sensitive data on the user, clear it here
		// $this->plainPassword = null;
	}

	public function getPresentation (): ?string {
		return mb_substr( $this->presentation ?? '', 0, 32 );
	}

	public function setPresentation ( ?string $presentation ): self {
		$this->presentation = mb_substr( trim( $presentation ?? '' ), 0, 32 );

		return $this;
	}

	public function getTourSeenAt (): ?DateTimeInterface {
		return $this->tourSeenAt;
	}

	public function setTourSeenAt ( ?DateTimeInterface $tourSeenAt ): self {
		$this->tourSeenAt = $tourSeenAt;

		return $this;
	}

	public function hasSeenTour (): bool {
		return $this->tourSeenAt !== NULL;
	}

	public function getJobTitle (): ?string {
		return $this->jobTitle;
	}

	public function setJobTitle ( ?string $jobTitle ): self {
		$jobTitle = trim( $jobTitle ?? '' );

		$this->jobTitle = ( $jobTitle === '' ) ? NULL : mb_substr( $jobTitle, 0, 100 );

		return $this;
	}

	public function getOrganisation (): ?string {
		return $this->organisation;
	}

	public function setOrganisation ( ?string $organisation ): self {
		$organisation = trim( $organisation ?? '' );

		$this->organisation = ( $organisation === '' ) ? NULL : mb_substr( $organisation, 0, 150 );

		return $this;
	}

	public function getReserves (): ?string {
		return $this->reserves;
	}

	public function setReserves ( ?string $reserves ): self {
		$reserves = trim( $reserves ?? '' );

		$this->reserves = ( $reserves === '' ) ? NULL : mb_substr( $reserves, 0, 255 );

		return $this;
	}

	public function getPhone (): ?string {
		return $this->phone;
	}

	public function setPhone ( ?string $phone ): self {
		$phone = trim( $phone ?? '' );

		$this->phone = ( $phone === '' ) ? NULL : mb_substr( $phone, 0, 30 );

		return $this;
	}

	/**
	 * Les comptes créés avant l'ajout de la colonne valent NULL en base tant
	 * qu'ils n'ont pas été réenregistrés : les traiter comme visibles, ce
	 * qu'ils étaient jusque là.
	 */
	public function isEmailVisible (): bool {
		return $this->emailVisible !== FALSE;
	}

	public function setEmailVisible ( ?bool $emailVisible ): self {
		$this->emailVisible = (bool) $emailVisible;

		return $this;
	}

	public function getProfileVisibility (): ?string {
		return $this->profileVisibility;
	}

	public function setProfileVisibility ( ?string $profileVisibility ): self {
		$this->profileVisibility = $profileVisibility;

		return $this;
	}

	public function getLocale (): ?string {
		return $this->locale;
	}

	public function setLocale ( ?string $locale ): self {
		$this->locale = $locale;

		return $this;
	}

	public function getTimezone (): ?string {
		return $this->timezone;
	}

	public function setTimezone ( ?string $timezone ): self {
		$this->timezone = $timezone;

		return $this;
	}

	public function getCreatedAt (): ?DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt ( DateTimeInterface $createdAt ): self {
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getSeenAt (): ?DateTimeInterface {
		return $this->seenAt;
	}

	public function setSeenAt ( ?DateTimeInterface $seenAt ): self {
		$this->seenAt = $seenAt;

		return $this;
	}

	public function getResetToken (): ?string {
		return $this->resetToken;
	}

	public function setResetToken ( ?string $resetToken ): self {
		$this->resetToken = $resetToken;

		return $this;
	}

	/**
	 * @return Collection|UsergroupMembership[]
	 */
	public function getUsergroupMemberships (): Collection {
		return $this->usergroupMemberships;
	}

	public function addUsergroupMembership ( UsergroupMembership $usergroupMembership ): self {
		if ( !$this->usergroupMemberships->contains( $usergroupMembership ) ) {
			$this->usergroupMemberships[] = $usergroupMembership;
			$usergroupMembership->setUser( $this );
		}

		return $this;
	}

	public function removeUsergroupMembership ( UsergroupMembership $usergroupMembership ): self {
		if ( $this->usergroupMemberships->contains( $usergroupMembership ) ) {
			$this->usergroupMemberships->removeElement( $usergroupMembership );
			// set the owning side to null (unless already changed)
			if ( $usergroupMembership->getUser() === $this ) {
				$usergroupMembership->setUser( NULL );
			}
		}

		return $this;
	}

	public function getStatus (): ?int {
		return $this->status;
	}

	public function setStatus ( int $status ): self {
		$this->status = $status;

		return $this;
	}

	public function getCity (): ?string {
		return $this->city;
	}

	public function setCity ( ?string $city ): self {
		$this->city = mb_convert_case( trim( $city ), MB_CASE_TITLE );

		return $this;
	}

	public function getZipcode (): ?string {
		return $this->zipcode;
	}

	public function setZipcode ( ?string $zipcode ): self {
		$this->zipcode = trim( $zipcode );

		return $this;
	}

	public function getCountry (): ?string {
		return $this->country;
	}

	public function setCountry ( ?string $country ): self {
		$this->country = mb_convert_case( trim( $country ), MB_CASE_UPPER );

		return $this;
	}

	public function getRegion (): ?string {
		return $this->region;
	}

	public function setRegion ( ?string $region ): self {
		$this->region = $region !== null ? mb_convert_case( trim( $region ), MB_CASE_UPPER ) : null;

		return $this;
	}

	public function getBio (): ?string {
		return $this->bio;
	}

	public function setBio ( ?string $bio ): self {
		$this->bio = trim( $bio );

		return $this;
	}

	/**
	 * @return Collection|Skill[]
	 */
	public function getSkills (): Collection {
		return $this->skills;
	}

	public function setSkills ( Collection $skills ) {
		$this->skills = new ArrayCollection();

		foreach ( $skills as $skill ) {
			$this->addSkill( $skill );
		}

		return $this;
	}

	public function addSkill ( Skill $skill ): self {
		if ( !$this->skills->contains( $skill ) ) {
			$this->skills[] = $skill;
		}

		return $this;
	}

	public function removeSkill ( Skill $skill ): self {
		if ( $this->skills->contains( $skill ) ) {
			$this->skills->removeElement( $skill );
		}

		return $this;
	}

	public function getAvatar (): ?File {
		return $this->avatar;
	}

	public function setAvatar ( ?File $avatar ): self {
		$this->avatar = $avatar;

		return $this;
	}

	public function getLatitude (): ?float {
		return $this->latitude;
	}

	public function setLatitude ( ?float $latitude ): self {
		$this->latitude = $latitude;

		return $this;
	}

	public function getLongitude (): ?float {
		return $this->longitude;
	}

	public function setLongitude ( ?float $longitude ): self {
		$this->longitude = $longitude;

		return $this;
	}

	public function getEmailNew (): ?string {
		return $this->emailNew;
	}

	public function setEmailNew ( ?string $emailNew ): self {
		$this->emailNew = mb_convert_case( trim( $emailNew ), MB_CASE_LOWER );;

		return $this;
	}

	public function getEmailToken (): ?string {
		return $this->emailToken;
	}

	public function setEmailToken ( ?string $emailToken ): self {
		$this->emailToken = $emailToken;

		return $this;
	}

	public function getHasAgreedTermsOfUse (): bool {
		return $this->hasAgreedTermsOfUse;
	}

	public function setHasAgreedTermsOfUse ( ?bool $hasAgreedTermsOfUse ): self {
		$this->hasAgreedTermsOfUse = $hasAgreedTermsOfUse ?? false;

		return $this;
	}


	// RNF specific getters and setters
	
	public function getRnfIdRole(): ?int
	{
		return $this->rnfIdRole;
	}

	public function setRnfIdRole(?int $rnfIdRole): self
	{
		$this->rnfIdRole = $rnfIdRole;
		return $this;
	}

	public function getRnfIdOrganisme(): ?int
	{
		return $this->rnfIdOrganisme;
	}

	public function setRnfIdOrganisme(?int $rnfIdOrganisme): self
	{
		$this->rnfIdOrganisme = $rnfIdOrganisme;
		return $this;
	}

	public function getRnfUserLogin(): ?string
	{
		return $this->rnfUserLogin;
	}

	public function setRnfUserLogin(?string $rnfUserLogin): self
	{
		$this->rnfUserLogin = $rnfUserLogin;
		return $this;
	}

	public function getRnfPrenomRole(): ?string
	{
		return $this->rnfPrenomRole;
	}

	public function setRnfPrenomRole(?string $rnfPrenomRole): self
	{
		$this->rnfPrenomRole = $rnfPrenomRole;
		return $this;
	}

	public function getRnfNomRole(): ?string
	{
		return $this->rnfNomRole;
	}

	public function setRnfNomRole(?string $rnfNomRole): self
	{
		$this->rnfNomRole = $rnfNomRole;
		return $this;
	}

	public function getRnfRoleInfo(): array
	{
		return $this->rnfRoleInfo ?? [];
	}

	public function setRnfRoleInfo(?array $rnfRoleInfo): self
	{
		$this->rnfRoleInfo = $rnfRoleInfo ?? [];
		return $this;
	}

	public function getFirstLoginNotified(): bool
	{
		return $this->firstLoginNotified ?? false;
	}

	public function setFirstLoginNotified(bool $firstLoginNotified): self
	{
		$this->firstLoginNotified = $firstLoginNotified;
		return $this;
	}

	public function getProfileUpdatedAt(): ?\DateTimeInterface
	{
		return $this->profileUpdatedAt;
	}

	/**
	 * @return bool
	 */
	public function isMessagesOpen (): bool {
		return $this->messagesOpen === NULL ? TRUE : (bool) $this->messagesOpen;
	}

	public function setMessagesOpen ( ?bool $messagesOpen ): self {
		$this->messagesOpen = $messagesOpen === NULL ? TRUE : $messagesOpen;

		return $this;
	}

	/**
	 * Whether this member wants e-mails at all. Switching it off keeps the
	 * notifications visible on the platform. (#34)
	 *
	 * @return bool
	 */
	public function wantsEmails (): bool {
		$settings = $this->notificationsSettings ?: [];

		return !isset( $settings[ 'emails' ] ) || (bool) $settings[ 'emails' ];
	}

	public function setWantsEmails ( bool $wantsEmails ): self {
		$settings             = $this->notificationsSettings ?: [];
		$settings[ 'emails' ] = $wantsEmails;

		$this->notificationsSettings = $settings;

		return $this;
	}

	/**
	 * @return string|null the rhythm this member chose before #38, when the
	 *                     rhythm was one setting for everything, or NULL if
	 *                     they never chose one
	 */
	public function getLegacyDiscussionRhythm (): ?string {
		$settings = $this->notificationsSettings ?: [];
		$rhythm   = isset( $settings[ 'discussionRhythm' ] ) ? $settings[ 'discussionRhythm' ] : NULL;

		return NotificationRhythm::stored( $rhythm ) ? $rhythm : NULL;
	}

	/**
	 * @deprecated depuis #38 : le rythme se lit dans le niveau de chaque
	 *             catégorie. Ne subsiste que pour lire les comptes d'avant.
	 *
	 * @return string one of NotificationRhythm
	 */
	public function getDiscussionEmailRhythm (): string {
		$rhythm = $this->getLegacyDiscussionRhythm();

		return ( $rhythm === NotificationRhythm::LEGACY_DIGEST )
				? NotificationRhythm::DAILY
				: ( $rhythm ?: NotificationRhythm::DEFAULT_RHYTHM );
	}

	/**
	 * Ce qu'on reçoit d'un groupe qui n'a rien dit de particulier.
	 *
	 * @param string $category
	 *
	 * @return string one of NotificationLevel
	 */
	public function getDefaultNotificationLevel ( string $category ): string {
		$settings = $this->notificationsSettings ?: [];
		$stored   = isset( $settings[ 'categories' ][ $category ] ) ? $settings[ 'categories' ][ $category ] : NULL;

		$level = NotificationLevel::fromLegacy( $stored, $this->getLegacyDiscussionRhythm(), $category );

		return $level !== NULL ? $level : NotificationCategory::defaultLevel( $category );
	}

	/**
	 * @param string $category
	 * @param string $level
	 *
	 * @return $this
	 */
	public function setDefaultNotificationLevel ( string $category, string $level ): self {
		if ( !NotificationCategory::exists( $category ) || !NotificationLevel::exists( $level ) ) {
			return $this;
		}

		$settings = $this->notificationsSettings ?: [];

		$settings[ 'categories' ][ $category ] = $level;

		$this->notificationsSettings = $settings;

		return $this;
	}

	/**
	 * @deprecated depuis #38. Accepte l'ancien vocabulaire — « digest » — pour
	 *             que les comptes d'avant se reconstituent tels quels.
	 */
	public function setDiscussionEmailRhythm ( string $rhythm ): self {
		if ( !NotificationRhythm::stored( $rhythm ) ) {
			return $this;
		}

		$settings                       = $this->notificationsSettings ?: [];
		$settings[ 'discussionRhythm' ] = $rhythm;

		$this->notificationsSettings = $settings;

		return $this;
	}

	/**
	 * Le JSON tel quel. Écrire par ici court-circuite les validations : c'est
	 * fait pour reconstituer un compte d'avant #38, pas pour enregistrer un
	 * choix.
	 *
	 * @return array
	 */
	public function getNotificationsSettings (): array {
		return $this->notificationsSettings ?: [];
	}

	public function setNotificationsSettings ( ?array $settings ): self {
		$this->notificationsSettings = $settings ?: [];

		return $this;
	}

	/**
	 * Reste-t-il à prévenir ce membre que les notifications ont changé de
	 * fonctionnement ? (#38)
	 *
	 * Le changement de défaut ne se voit pas : quelqu'un qui recevait un
	 * e-mail par message de discussion se retrouve avec un résumé quotidien
	 * sans que rien ne le lui dise. L'annonce est là pour ça, elle ne se
	 * montre qu'une fois, et elle ne s'adresse qu'aux comptes que la bascule
	 * a effectivement traversée : c'est la migration qui pose le drapeau sur
	 * les comptes existants, personne ne le pose ensuite, et les inscrits
	 * d'après ne lisent donc jamais l'annonce d'un changement qu'ils n'ont
	 * pas connu.
	 *
	 * @return bool
	 */
	public function awaitsNotificationsNotice (): bool {
		$settings = $this->notificationsSettings ?: [];

		return !empty( $settings[ 'noticePending' ] );
	}

	/**
	 * @return $this
	 */
	public function markNotificationsNoticeSeen ( DateTimeInterface $seenAt ): self {
		$settings = $this->notificationsSettings ?: [];

		unset( $settings[ 'noticePending' ] );

		$settings[ 'noticeSeenAt' ] = $seenAt->format( DATE_ATOM );

		$this->notificationsSettings = $settings;

		return $this;
	}

	public function setProfileUpdatedAt(?\DateTimeInterface $profileUpdatedAt): self
	{
		$this->profileUpdatedAt = $profileUpdatedAt;
		return $this;
	}

	public function jsonSerialize()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
			'city' => $this->city,
			'zipcode' => $this->zipcode,
			'rnf_id_role' => $this->rnfIdRole,
			'rnf_id_organisme' => $this->rnfIdOrganisme,
        ];
    }
}
