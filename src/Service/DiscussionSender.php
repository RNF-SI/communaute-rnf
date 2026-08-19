<?php

namespace App\Service;

use App\Entity\DiscussionMessage;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationLevel;
use App\Notification\NotificationRhythm;
use App\Postmark\BulkTransport;
use Swift_Message;
use Throwable;
use Twig\Environment;

class DiscussionSender {
	/**
	 * @var \App\Postmark\BulkTransport
	 */
	private $transport;

	private $params;

	private $twig;

	/**
	 * BulkSender constructor.
	 *
	 * @param \App\Postmark\BulkTransport $transport
	 * @param                             $params
	 * @param \Twig\Environment           $twig
	 */
	public function __construct ( BulkTransport $transport, $params, Environment $twig ) {
		$this->transport = $transport;
		$this->params    = $params;
		$this->twig      = $twig;
	}

	/**
	 * Whether this member gets an e-mail as the message is posted.
	 *
	 * Three things have to line up: the level that applies to this discussion
	 * in particular, the member wanting e-mails at all, and their chosen
	 * rhythm. Someone on the daily summary is served later by the digest
	 * command, not here. (#34)
	 *
	 * @param \App\Entity\UsergroupMembership $membership
	 * @param \App\Entity\DiscussionMessage   $discussionMessage
	 *
	 * @return bool
	 */
	private function shouldSendNow ( UsergroupMembership $membership, DiscussionMessage $discussionMessage ) {
		if ( $membership->getStatus() !== UsergroupMembership::STATUS_MEMBER ) {
			return FALSE;
		}

		$user = $membership->getUser();

		if ( !$user || !$user->wantsEmails() ) {
			return FALSE;
		}

		if ( $user->getDiscussionEmailRhythm() !== NotificationRhythm::IMMEDIATE ) {
			return FALSE;
		}

		$level = $membership->getLevelForDiscussion( $discussionMessage->getDiscussion()->getUuid() );

		return NotificationLevel::sendsEmail( $level );
	}

	/**
	 * @param \App\Entity\DiscussionMessage $discussionMessage
	 * @param bool                          $first
	 *
	 * @return bool|int
	 */
	public function sendDiscussionMessage ( DiscussionMessage $discussionMessage, $first = FALSE ) {
		$subject = ( $first ? '' : 'Re: ' ) . $discussionMessage->getDiscussion()->getTitle();
		$from    = 'noreply@' . $this->params[ 'list_domain' ];

		$to       = $discussionMessage->getDiscussion()->getUsergroup()->getMembers();
		$author   = $discussionMessage->getAuthor();
		$messages = [];

		/**
		 * @var \App\Entity\UsergroupMembership $membership
		 */
		foreach ( $to as $membership ) {
			if ( $this->shouldSendNow( $membership, $discussionMessage ) ) {
				$user = $membership->getUser();

				// don't notify the author of their own message. Compare the
				// instances first, then the identifiers, so that two entities
				// that are not persisted yet — both with a NULL id — are never
				// mistaken for one another.
				if ( $author && $user
					 && ( ( $author === $user )
						  || ( ( $author->getId() !== NULL ) && ( $author->getId() === $user->getId() ) ) ) ) {
					continue;
				}

				try {
					$body = $this->twig->render( $first ? 'emails/discussion-new.html.twig' : 'emails/discussion-message.html.twig', [
							'user'    => $user,
							'message' => $discussionMessage,
					] );
				} catch ( Throwable $e ) {
					$body = '';
				}

				$message = ( new Swift_Message( $subject ) )
					->setFrom( $from )
					->setTo( $user->getEmail() )
					->setReplyTo( $discussionMessage->getDiscussion()->getUsergroup()->getSlug() . '+' . $discussionMessage->getDiscussion()->getUuid() . '@' . $this->params[ 'list_domain' ] )
					->setBody( $body, 'text/html' );

				// set headers to disable auto responders
				$headers = $message->getHeaders();
				$headers->addTextHeader('Auto-submitted', 'auto-generated'); // RFC 3834
				$headers->addTextHeader('List-Id', $discussionMessage->getDiscussion()->getId()); // RFC 2919
				$headers->addTextHeader('Precedence', 'list'); // MS Outlook
				$headers->addTextHeader('X-Auto-Response-Suppress', 'All'); // MS Outlook

				$messages[] = $message;
			}
		}

		return $this->transport->sendMultiple( $messages );
	}
}
