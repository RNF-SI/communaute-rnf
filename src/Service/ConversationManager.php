<?php

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\MessageReport;
use App\Entity\PrivateMessage;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qui arrive à une conversation privée : l'ouvrir, y écrire, la lire, la
 * ranger, la quitter, signaler ce qui s'y dit.
 *
 * Tout est ici plutôt que dans le contrôleur parce que chacun de ces gestes
 * touche deux ou trois tables à la fois — un message, la date d'activité de la
 * conversation, la lecture de celui qui écrit — et qu'une de ces écritures
 * oubliée dans un chemin sur quatre donne une boîte qui ment.
 */
class ConversationManager {
	/**
	 * Combien de messages un signalement recopie autour de celui qui est
	 * signalé. Assez pour lire une phrase dans son échange, pas assez pour
	 * reconstituer une conversation.
	 */
	private const REPORT_CONTEXT = 3;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Service\NotificationSender
	 */
	private $notifications;

	public function __construct ( EntityManagerInterface $manager, NotificationSender $notifications ) {
		$this->manager       = $manager;
		$this->notifications = $notifications;
	}

	/**
	 * La conversation entre ces personnes-là : celle qui existe déjà si c'est
	 * un tête-à-tête, une neuve sinon.
	 *
	 * On ne cherche pas à réutiliser un fil à trois ou plus : deux échanges
	 * successifs entre les mêmes cinq personnes ne portent pas sur la même
	 * chose, et les fondre ferait un fil illisible. Le tête-à-tête, lui, est
	 * une boîte aux lettres et non un sujet — d'où la clé unique.
	 *
	 * @param \App\Entity\User   $author
	 * @param \App\Entity\User[] $recipients
	 *
	 * @return \App\Entity\Conversation
	 */
	public function open ( User $author, array $recipients ) {
		$members = $this->uniquePeople( array_merge( [ $author ], $recipients ) );

		if ( count( $members ) === 2 ) {
			$existing = $this->manager->getRepository( Conversation::class )
									  ->findPair( $members[ 0 ], $members[ 1 ] );

			if ( $existing ) {
				// Quelqu'un qui avait quitté le tête-à-tête et à qui on
				// réécrit y revient : sinon son message partirait dans un fil
				// dont il ne fait pas partie.
				foreach ( $members as $member ) {
					$this->rejoin( $existing, $member );
				}

				return $existing;
			}
		}

		$conversation = new Conversation();
		$conversation->setCreatedAt( new DateTime() );

		if ( count( $members ) === 2 ) {
			$conversation->setPairKey( Conversation::pairKeyFor( $members[ 0 ], $members[ 1 ] ) );
		}

		foreach ( $members as $member ) {
			$conversation->addParticipant( new ConversationParticipant( $member ) );
		}

		$this->manager->persist( $conversation );
		$this->manager->flush();

		return $conversation;
	}

	/**
	 * Écrire dans une conversation.
	 *
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $author
	 * @param string                   $body
	 *
	 * @return \App\Entity\PrivateMessage
	 */
	public function post ( Conversation $conversation, User $author, $body ) {
		$now = new DateTime();

		$message = new PrivateMessage();
		$message->setConversation( $conversation );
		$message->setAuthor( $author );
		$message->setBody( trim( (string) $body ) );
		$message->setCreatedAt( $now );

		$conversation->setLastMessageAt( $now );

		foreach ( $conversation->getActiveParticipants() as $participant ) {
			$member = $participant->getUser();

			// Un message fait ressortir la conversation de chez ceux qui
			// l'avaient rangée : ranger n'est pas se désabonner.
			$participant->setArchivedAt( NULL );

			if ( $member && ( $member->getId() === $author->getId() ) ) {
				$participant->setLastReadAt( $now );
			}
		}

		$this->manager->persist( $message );
		$this->manager->flush();

		$this->notifications->notifyNewPrivateMessage( $message );

		return $message;
	}

	/**
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $user
	 *
	 * @return void
	 */
	public function markRead ( Conversation $conversation, User $user ) {
		$participant = $conversation->getParticipantFor( $user );

		if ( !$participant ) {
			return;
		}

		$participant->setLastReadAt( new DateTime() );

		$this->manager->flush();
	}

	/**
	 * Ajouter quelqu'un à un échange en cours.
	 *
	 * Ce qui a été dit avant reste lisible par le nouveau venu : le contraire
	 * demanderait de masquer une partie du fil, et personne n'ajoute quelqu'un
	 * à une conversation pour lui en cacher le début. C'est à celui qui ajoute
	 * de savoir ce qu'il ouvre — l'écran le dit avant de valider.
	 *
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $user
	 *
	 * @return bool FALSE si cette personne en faisait déjà partie
	 */
	public function add ( Conversation $conversation, User $user ) {
		if ( $conversation->includes( $user ) ) {
			return FALSE;
		}

		$this->rejoin( $conversation, $user );

		// Ce n'est plus un tête-à-tête : la clé s'efface, et un tête-à-tête
		// entre les deux premiers redevient possible ailleurs.
		$conversation->setPairKey( NULL );

		$this->manager->flush();

		return TRUE;
	}

	/**
	 * Quitter une conversation. Les messages déjà écrits restent — les
	 * effacer trouerait le fil de ceux qui restent.
	 *
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $user
	 *
	 * @return void
	 */
	public function leave ( Conversation $conversation, User $user ) {
		$participant = $conversation->getParticipantFor( $user );

		if ( !$participant || $participant->hasLeft() ) {
			return;
		}

		$participant->setLeftAt( new DateTime() );

		// Un tête-à-tête qu'on quitte n'est plus une boîte aux lettres : sans
		// cela, l'autre ne pourrait plus jamais nous écrire, la clé unique
		// renvoyant sur un fil dont nous sommes sortis.
		$conversation->setPairKey( NULL );

		$this->manager->flush();
	}

	/**
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $user
	 * @param bool                     $archived
	 *
	 * @return void
	 */
	public function archive ( Conversation $conversation, User $user, $archived = TRUE ) {
		$participant = $conversation->getParticipantFor( $user );

		if ( !$participant ) {
			return;
		}

		$participant->setArchivedAt( $archived ? new DateTime() : NULL );

		$this->manager->flush();
	}

	/**
	 * Supprimer son propre message : le texte s'en va, la ligne reste, et le
	 * fil garde sa suite.
	 *
	 * @param \App\Entity\PrivateMessage $message
	 *
	 * @return void
	 */
	public function delete ( PrivateMessage $message ) {
		$message->setBody( '' );
		$message->setDeletedAt( new DateTime() );

		$this->manager->flush();
	}

	/**
	 * @param \App\Entity\PrivateMessage $message
	 * @param string                     $body
	 *
	 * @return void
	 */
	public function edit ( PrivateMessage $message, $body ) {
		$message->setBody( trim( (string) $body ) );
		$message->setEditedAt( new DateTime() );

		$this->manager->flush();
	}

	/**
	 * Transmettre un message à l'équipe RNF.
	 *
	 * Le signalement **recopie** ce qu'il faut lire au lieu de pointer vers la
	 * conversation : c'est ce qui permet à la page d'administration de juger
	 * sans jamais ouvrir un échange privé. Voir MessageReport.
	 *
	 * @param \App\Entity\PrivateMessage $message
	 * @param \App\Entity\User           $reporter
	 * @param string|null                $reason
	 *
	 * @return \App\Entity\MessageReport
	 */
	public function report ( PrivateMessage $message, User $reporter, $reason = NULL ) {
		$report = new MessageReport();
		$report->setMessage( $message );
		$report->setReporter( $reporter );
		$report->setReported( $message->getAuthor() );
		$report->setReason( $reason ? trim( $reason ) : NULL );
		$report->setExcerpt( (string) $message->getBody() );
		$report->setCreatedAt( new DateTime() );

		$conversation = $message->getConversation();

		if ( $conversation ) {
			$report->setConversationId( $conversation->getId() );
		}

		$before = $this->manager->getRepository( PrivateMessage::class )
								->findBefore( $message, self::REPORT_CONTEXT );

		$context = [];

		foreach ( $before as $previous ) {
			$author = $previous->getAuthor();

			$context[] = [
					'author' => $author ? $author->getName() : NULL,
					'at'     => $previous->getCreatedAt() ? $previous->getCreatedAt()->format( DATE_ATOM ) : NULL,
					'body'   => $previous->isDeleted() ? NULL : (string) $previous->getBody(),
			];
		}

		$report->setContext( $context );

		$this->manager->persist( $report );
		$this->manager->flush();

		return $report;
	}

	/**
	 * Remettre quelqu'un dans une conversation, qu'il l'ait quittée ou qu'il
	 * n'en ait jamais fait partie.
	 *
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $user
	 *
	 * @return void
	 */
	private function rejoin ( Conversation $conversation, User $user ) {
		$participant = $conversation->getParticipantFor( $user );

		if ( $participant ) {
			$participant->setLeftAt( NULL );
			$participant->setArchivedAt( NULL );

			return;
		}

		$participant = new ConversationParticipant( $user );

		$conversation->addParticipant( $participant );

		$this->manager->persist( $participant );
	}

	/**
	 * @param \App\Entity\User[] $people
	 *
	 * @return \App\Entity\User[]
	 */
	private function uniquePeople ( array $people ) {
		$unique = [];

		foreach ( $people as $person ) {
			if ( $person instanceof User ) {
				$unique[ $person->getId() ] = $person;
			}
		}

		return array_values( $unique );
	}
}
