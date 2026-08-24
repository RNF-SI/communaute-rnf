<?php

namespace App\Entity;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce qu'un membre transmet à l'équipe RNF quand un message privé lui pose
 * problème.
 *
 * Le signalement **recopie** le message incriminé et les quelques messages qui
 * le précèdent, au moment où il est fait. C'est délibéré, et c'est le
 * compromis qui tient devant les membres : l'équipe voit assez de contexte
 * pour juger, et rien de plus — la page d'administration n'interroge jamais la
 * conversation elle-même, elle lit ce qui est là. Un administrateur ne peut
 * donc pas remonter un échange privé à partir d'un signalement.
 *
 * Corollaire : ces copies ne bougent plus. Effacer le message après coup
 * n'efface pas ce qui a été signalé, et c'est bien ce qu'on veut d'un
 * signalement.
 *
 * @ORM\Table(
 *     name="message_reports",
 *     indexes={
 *         @ORM\Index(name="report_pending", columns={"handled_at", "created_at"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\MessageReportRepository")
 */
class MessageReport {
	/**
	 * @ORM\Id()
	 * @ORM\GeneratedValue()
	 * @ORM\Column(type="integer")
	 */
	private $id;

	/**
	 * Le lien vers le message n'est là que pour retrouver l'objet tant qu'il
	 * existe. Le signalement se lit sans lui.
	 *
	 * @ORM\ManyToOne(targetEntity="App\Entity\PrivateMessage", inversedBy="reports")
	 * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
	 */
	private $message;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
	 */
	private $reporter;

	/**
	 * Celui dont le message est signalé. La relation permet d'agir sur le
	 * compte ; le nom recopié permet de lire le signalement même une fois le
	 * compte parti.
	 *
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
	 */
	private $reported;

	/**
	 * @ORM\Column(type="string", length=150, nullable=true)
	 */
	private $reportedName;

	/**
	 * Un simple entier, pas une relation : de quoi rapprocher deux
	 * signalements du même fil sans donner à la page d'administration le
	 * moyen d'ouvrir ce fil.
	 *
	 * @ORM\Column(type="integer", nullable=true)
	 */
	private $conversationId;

	/**
	 * Ce que le message disait au moment du signalement.
	 *
	 * @ORM\Column(type="text", nullable=true)
	 */
	private $excerpt;

	/**
	 * Les quelques messages qui précédaient, recopiés :
	 * [ { author, at, body }, … ]. Une phrase seule se lit de travers ;
	 * c'est souvent le contexte qui dit si elle est une insulte ou une
	 * plaisanterie entre collègues.
	 *
	 * @ORM\Column(type="json", nullable=true)
	 */
	private $context = [];

	/**
	 * @ORM\Column(type="text", nullable=true)
	 */
	private $reason;

	/**
	 * @ORM\Column(type="datetime")
	 */
	private $createdAt;

	/**
	 * @ORM\Column(type="datetime", nullable=true)
	 */
	private $handledAt;

	/**
	 * @ORM\ManyToOne(targetEntity="App\Entity\User")
	 * @ORM\JoinColumn(nullable=true, onDelete="SET NULL")
	 */
	private $handledBy;

	public function __construct () {
		$this->createdAt = new \DateTime();
	}

	public function getId (): ?int {
		return $this->id;
	}

	public function getMessage (): ?PrivateMessage {
		return $this->message;
	}

	public function setMessage ( ?PrivateMessage $message ): self {
		$this->message = $message;

		return $this;
	}

	public function getReporter (): ?User {
		return $this->reporter;
	}

	public function setReporter ( ?User $reporter ): self {
		$this->reporter = $reporter;

		return $this;
	}

	public function getReported (): ?User {
		return $this->reported;
	}

	public function setReported ( ?User $reported ): self {
		$this->reported = $reported;

		if ( $reported ) {
			$this->reportedName = $reported->getName();
		}

		return $this;
	}

	public function getReportedName (): ?string {
		return $this->reportedName;
	}

	public function setReportedName ( ?string $reportedName ): self {
		$this->reportedName = $reportedName;

		return $this;
	}

	public function getConversationId (): ?int {
		return $this->conversationId;
	}

	public function setConversationId ( ?int $conversationId ): self {
		$this->conversationId = $conversationId;

		return $this;
	}

	public function getExcerpt (): ?string {
		return $this->excerpt;
	}

	public function setExcerpt ( ?string $excerpt ): self {
		$this->excerpt = $excerpt;

		return $this;
	}

	public function getContext (): array {
		return $this->context ?: [];
	}

	public function setContext ( ?array $context ): self {
		$this->context = $context ?: [];

		return $this;
	}

	public function getReason (): ?string {
		return $this->reason;
	}

	public function setReason ( ?string $reason ): self {
		$this->reason = $reason;

		return $this;
	}

	public function getCreatedAt (): ?DateTimeInterface {
		return $this->createdAt;
	}

	public function setCreatedAt ( DateTimeInterface $createdAt ): self {
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getHandledAt (): ?DateTimeInterface {
		return $this->handledAt;
	}

	public function setHandledAt ( ?DateTimeInterface $handledAt ): self {
		$this->handledAt = $handledAt;

		return $this;
	}

	public function isHandled (): bool {
		return $this->handledAt !== NULL;
	}

	public function getHandledBy (): ?User {
		return $this->handledBy;
	}

	public function setHandledBy ( ?User $handledBy ): self {
		$this->handledBy = $handledBy;

		return $this;
	}
}
