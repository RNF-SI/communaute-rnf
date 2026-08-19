<?php

namespace App\Security;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\User;
use App\Entity\Usergroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Security;

class GroupDiscussionVoter extends Voter {
	const CREATE      = 'group:discussion:create';
	const READ        = 'group:discussion:read';
	const PARTICIPATE = 'group:discussion:participate';
	const EDIT        = 'group:discussion:edit';
	const DELETE      = 'group:discussion:delete';

	/**
	 * Applies to a single message rather than to the whole discussion: its
	 * author may correct what they wrote. (#19)
	 */
	const EDIT_MESSAGE = 'group:discussion:message:edit';

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \Symfony\Component\Security\Core\Security
	 */
	private $security;

	public function __construct ( EntityManagerInterface $manager, Security $security ) {
		$this->manager  = $manager;
		$this->security = $security;
	}

	protected function supports ( $attribute, $subject ) {
		if ( in_array( $attribute, [ self::CREATE ] ) && ( $subject instanceof Usergroup ) ) {
			return TRUE;
		}

		if ( in_array( $attribute, [ self::READ, self::PARTICIPATE, self::EDIT, self::DELETE ] ) && ( $subject instanceof Discussion ) ) {
			return TRUE;
		}

		if ( ( $attribute === self::EDIT_MESSAGE ) && ( $subject instanceof DiscussionMessage ) ) {
			return TRUE;
		}

		return FALSE;
	}

	protected function voteOnAttribute ( $attribute, $subject, TokenInterface $token ) {
		$user = $token->getUser();

		if ( ( $user instanceof User ) && $user->isAdmin() ) {
			return TRUE;
		}

		switch ( $attribute ) {
			case self::CREATE:
				/**
				 * @var \App\Entity\Usergroup $group
				 */
				$group = $subject;

				return $this->security->isGranted( GroupVoter::PARTICIPATE, $group );

			case self::PARTICIPATE:
				/**
				 * @var \App\Entity\Discussion $discussion
				 */
				$discussion = $subject;

				return $this->security->isGranted( GroupVoter::PARTICIPATE, $discussion->getUsergroup() );

			case self::READ:
				/**
				 * @var \App\Entity\Discussion $discussion
				 */
				$discussion = $subject;

				return $this->security->isGranted( GroupVoter::READ, $discussion->getUsergroup() );

			case self::EDIT:
				/**
				 * @var \App\Entity\Discussion $discussion
				 */
				$discussion = $subject;

				return $this->security->isGranted( GroupVoter::EDIT, $discussion->getUsergroup() );

			case self::DELETE:
				/**
				 * @var \App\Entity\Discussion $discussion
				 */
				$discussion = $subject;

				return $this->security->isGranted( GroupVoter::DELETE, $discussion->getUsergroup() );

			case self::EDIT_MESSAGE:
				/**
				 * @var \App\Entity\DiscussionMessage $message
				 */
				$message = $subject;
				$group   = $message->getDiscussion()->getUsergroup();
				$author  = $message->getAuthor();

				// The author corrects their own message, as long as they may
				// still take part in the group.
				if ( ( $user instanceof User ) && $author && ( $author->getId() === $user->getId() ) ) {
					return $this->security->isGranted( GroupVoter::PARTICIPATE, $group );
				}

				// Otherwise it takes the right to moderate the group.
				return $this->security->isGranted( GroupVoter::EDIT, $group );
		}

		throw new \LogicException( 'This code should not be reached!' );
	}
}
