<?php

namespace App\Service;

use App\Entity\DiscussionMessage;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationLevel;
use App\Postmark\BulkTransport;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
	/**
	 * @var \App\Service\HtmlToText
	 */
	private $htmlToText;

	/**
	 * @var \App\Service\HashGenerator
	 */
	private $hashGenerator;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $router;

	/**
	 * @var \App\Service\MailGuard
	 */
	private $guard;

	public function __construct (
			BulkTransport $transport,
			$params,
			Environment $twig,
			HtmlToText $htmlToText,
			HashGenerator $hashGenerator,
			UrlGeneratorInterface $router,
			MailGuard $guard
	) {
		$this->guard = $guard;
		$this->transport     = $transport;
		$this->params        = $params;
		$this->twig          = $twig;
		$this->htmlToText    = $htmlToText;
		$this->hashGenerator = $hashGenerator;
		$this->router        = $router;
	}

	/**
	 * @param \App\Entity\DiscussionMessage $discussionMessage
	 * @param \App\Entity\User              $user
	 *
	 * @return string
	 */
	private function unsubscribeUrl ( DiscussionMessage $discussionMessage, $user ) {
		return $this->router->generate( 'group_discussions_notifications', [
				'groupSlug' => $discussionMessage->getDiscussion()->getUsergroup()->getSlug(),
				'status'    => 'unsubscribe',
				'redirect'  => 'group',
				'hash'      => $this->hashGenerator->generateUserHash( $user ),
		], UrlGeneratorInterface::ABSOLUTE_URL );
	}

	/**
	 * Whether this member gets an e-mail as the message is posted.
	 *
	 * Two things have to line up : que le membre veuille des e-mails, et que
	 * le niveau qui s'applique à cette discussion-là soit l'immédiat. Depuis
	 * #38 le rythme est dans ce niveau, il n'y a plus de réglage à croiser :
	 * qui est au résumé est servi plus tard par la commande, pas ici.
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

		// Anonymised copies carry addresses that accept nothing. (#14)
		if ( !$this->guard->isDeliverable( $user->getEmail() ) ) {
			return FALSE;
		}

		$level = $membership->getLevelForDiscussion( $discussionMessage->getDiscussion()->getUuid() );

		return NotificationLevel::sendsNow( $level );
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
					->setBody( $body, 'text/html' )
					// A message carrying only HTML is one of the oldest spam
					// signals there is. (#14)
					->addPart( $this->htmlToText->convert( $body ), 'text/plain' );

				// set headers to disable auto responders
				$headers = $message->getHeaders();
				$headers->addTextHeader('Auto-submitted', 'auto-generated'); // RFC 3834
				$headers->addTextHeader('List-Id', $discussionMessage->getDiscussion()->getId()); // RFC 2919
				$headers->addTextHeader('Precedence', 'list'); // MS Outlook
				$headers->addTextHeader('X-Auto-Response-Suppress', 'All'); // MS Outlook

				// One-click unsubscribe. Required of bulk senders by Gmail and
				// Yahoo since 2024, and a strong signal for everybody else. (#14)
				$headers->addTextHeader( 'List-Unsubscribe', '<' . $this->unsubscribeUrl( $discussionMessage, $user ) . '>' ); // RFC 2369
				$headers->addTextHeader( 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click' ); // RFC 8058

				$messages[] = $message;
			}
		}

		if ( empty( $messages ) ) {
			return 0;
		}

		try {
			return $this->transport->sendMultiple( $messages );
		}
		catch ( Throwable $e ) {
			// Un transport qui refuse ne doit pas faire échouer la publication :
			// le message est déjà enregistré, et le résumé rattrapera ceux qui
			// devaient être prévenus puisque rien n'aura été marqué comme parti.
			// C'est la propriété que ContentSender tient déjà de son côté.
			return 0;
		}
	}
}
