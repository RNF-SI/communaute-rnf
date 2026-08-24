<?php

namespace App\Security;

use App\Entity\Conversation;
use App\Entity\PrivateMessage;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui a le droit de faire quoi dans la messagerie.
 *
 * **Ce voter ne court-circuite pas pour les administrateurs**, à la différence
 * de GroupVoter. C'est délibéré et c'est le point à ne pas « corriger » en
 * relisant : l'équipe RNF doit pouvoir modérer partout dans les groupes, mais
 * une conversation privée n'est pas un groupe. Ce qu'un administrateur peut
 * lire d'un échange privé, il le lit dans un signalement — c'est-à-dire une
 * copie que quelqu'un lui a transmise —, jamais dans le fil lui-même.
 *
 * Un seul voter pour trois sujets — la conversation, un de ses messages, et la
 * personne à qui on voudrait écrire — parce que les trois répondent à la même
 * question et se relisent mieux côte à côte.
 */
class ConversationVoter extends Voter {
	/**
	 * Ouvrir une conversation et lire ce qui s'y dit.
	 */
	const READ = 'conversation:read';

	/**
	 * Y écrire, y ajouter quelqu'un.
	 */
	const PARTICIPATE = 'conversation:participate';

	/**
	 * Modifier ou effacer un message.
	 */
	const EDIT = 'message:edit';

	/**
	 * Transmettre un message à l'équipe RNF.
	 */
	const REPORT = 'message:report';

	/**
	 * Ouvrir une conversation avec cette personne-là.
	 */
	const CONTACT = 'user:contact';

	protected function supports ( $attribute, $subject ) {
		if ( in_array( $attribute, [ self::READ, self::PARTICIPATE ], TRUE ) ) {
			return $subject instanceof Conversation;
		}

		if ( in_array( $attribute, [ self::EDIT, self::REPORT ], TRUE ) ) {
			return $subject instanceof PrivateMessage;
		}

		if ( $attribute === self::CONTACT ) {
			return $subject instanceof User;
		}

		return FALSE;
	}

	protected function voteOnAttribute ( $attribute, $subject, TokenInterface $token ) {
		$user = $token->getUser();

		if ( !$user instanceof User ) {
			return FALSE;
		}

		switch ( $attribute ) {
			case self::READ:
			case self::PARTICIPATE:
				return $subject->includes( $user );

			case self::EDIT:
				return $this->mayEdit( $subject, $user );

			case self::REPORT:
				return $this->mayReport( $subject, $user );

			case self::CONTACT:
				return $this->mayContact( $subject, $user );
		}

		throw new \LogicException( 'This code should not be reached!' );
	}

	/**
	 * Chacun corrige et retire ce qu'il a écrit, et rien d'autre. Il n'y a pas
	 * d'animateur dans une conversation privée : personne n'y a autorité sur
	 * les mots d'un autre.
	 *
	 * @param \App\Entity\PrivateMessage $message
	 * @param \App\Entity\User           $user
	 *
	 * @return bool
	 */
	private function mayEdit ( PrivateMessage $message, User $user ) {
		$author       = $message->getAuthor();
		$conversation = $message->getConversation();

		if ( !$author || ( $author->getId() !== $user->getId() ) ) {
			return FALSE;
		}

		if ( $message->isDeleted() ) {
			return FALSE;
		}

		return $conversation && $conversation->includes( $user );
	}

	/**
	 * On signale ce qu'on a reçu, pas ce qu'on a écrit.
	 *
	 * @param \App\Entity\PrivateMessage $message
	 * @param \App\Entity\User           $user
	 *
	 * @return bool
	 */
	private function mayReport ( PrivateMessage $message, User $user ) {
		$author       = $message->getAuthor();
		$conversation = $message->getConversation();

		if ( $message->isDeleted() ) {
			return FALSE;
		}

		if ( $author && ( $author->getId() === $user->getId() ) ) {
			return FALSE;
		}

		return $conversation && $conversation->includes( $user );
	}

	/**
	 * Tout membre actif peut écrire à tout membre actif — sauf à celui qui a
	 * fermé sa boîte.
	 *
	 * Fermer sa boîte ne vaut pas contre les administrateurs de la plateforme :
	 * l'équipe RNF doit pouvoir joindre quelqu'un pour lui parler d'un
	 * signalement, et c'est précisément la conversation qu'on ne peut pas
	 * refuser.
	 *
	 * @param \App\Entity\User $recipient
	 * @param \App\Entity\User $user
	 *
	 * @return bool
	 */
	private function mayContact ( User $recipient, User $user ) {
		if ( $recipient->getId() === $user->getId() ) {
			return FALSE;
		}

		if ( $recipient->getStatus() !== User::STATUS_ACTIVE ) {
			return FALSE;
		}

		return $recipient->isMessagesOpen() || $user->isAdmin();
	}
}
