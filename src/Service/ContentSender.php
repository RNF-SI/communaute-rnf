<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Postmark\BulkTransport;
use Swift_Message;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use Twig\Environment;

/**
 * L'e-mail qui part au moment où une page, une actualité ou un document
 * paraît, pour les membres qui ont demandé l'immédiat sur cette catégorie.
 * (#38)
 *
 * Les messages de discussion ont leur propre chemin, DiscussionSender : ils
 * portent un Reply-To qui permet de répondre depuis une boîte aux lettres, et
 * ce n'est pas le cas ici — on ne répond pas à une page. Ce qui est commun,
 * c'est le transport : l'API Postmark en lot, un seul appel pour tout un
 * groupe, plutôt qu'un envoi synchrone par membre pendant que l'auteur attend
 * sa redirection.
 */
class ContentSender {
	/**
	 * @var \App\Postmark\BulkTransport
	 */
	private $transport;

	private $params;

	/**
	 * @var \Twig\Environment
	 */
	private $twig;

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
		$this->transport     = $transport;
		$this->params        = $params;
		$this->twig          = $twig;
		$this->htmlToText    = $htmlToText;
		$this->hashGenerator = $hashGenerator;
		$this->router        = $router;
		$this->guard         = $guard;
	}

	/**
	 * Envoie d'un coup les notifications qu'on vient de créer et qui demandent
	 * l'immédiat.
	 *
	 * Rien n'est marqué ici : c'est l'appelant qui pose emailedAt, une fois
	 * l'envoi accepté, pour que le résumé du soir ne les reprenne pas.
	 *
	 * @param Notification[] $notifications
	 *
	 * @return \App\Entity\Notification[] celles qui sont effectivement parties
	 */
	public function sendNow ( array $notifications ) {
		$messages = [];
		$sent     = [];

		foreach ( $notifications as $notification ) {
			$recipient = $notification->getRecipient();

			if ( !$recipient instanceof User ) {
				continue;
			}

			// Anonymised copies carry addresses that accept nothing. (#14)
			if ( !$this->guard->isDeliverable( $recipient->getEmail() ) ) {
				continue;
			}

			$message = $this->message( $notification, $recipient );

			if ( !$message ) {
				continue;
			}

			$messages[] = $message;
			$sent[]     = $notification;
		}

		if ( empty( $messages ) ) {
			return [];
		}

		try {
			$this->transport->sendMultiple( $messages );
		}
		catch ( Throwable $e ) {
			// Un transport muet ne doit pas faire échouer la création du
			// contenu : la notification reste sur la plateforme, et elle
			// repartira dans le résumé puisqu'elle n'aura pas été marquée.
			return [];
		}

		return $sent;
	}

	/**
	 * @param \App\Entity\Notification $notification
	 * @param \App\Entity\User         $recipient
	 *
	 * @return \Swift_Message|null
	 */
	private function message ( Notification $notification, User $recipient ) {
		$group = $notification->getUsergroup();

		try {
			$body = $this->twig->render( 'emails/content-new.html.twig', [
					'user'         => $recipient,
					'notification' => $notification,
					'group'        => $group,
			] );
		}
		catch ( Throwable $e ) {
			return NULL;
		}

		$subject = trim( sprintf(
				'%s%s',
				$group ? '[' . $group->getName() . '] ' : '',
				(string) $notification->getTitle()
		) );

		$message = ( new Swift_Message( $subject ) )
				->setFrom( $this->params[ 'from' ], $this->params[ 'name' ] )
				->setTo( $recipient->getEmail() )
				->setBody( $body, 'text/html' )
				// A message carrying only HTML is one of the oldest spam
				// signals there is. (#14)
				->addPart( $this->htmlToText->convert( $body ), 'text/plain' );

		$headers = $message->getHeaders();
		$headers->addTextHeader( 'Auto-Submitted', 'auto-generated' ); // RFC 3834
		$headers->addTextHeader( 'Precedence', 'bulk' );
		$headers->addTextHeader( 'X-Auto-Response-Suppress', 'All' ); // MS Outlook

		// One-click unsubscribe. Required of bulk senders by Gmail and Yahoo
		// since 2024, and a strong signal for everybody else. (#14)
		$headers->addTextHeader( 'List-Unsubscribe', '<' . $this->unsubscribeUrl( $recipient ) . '>' ); // RFC 2369
		$headers->addTextHeader( 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click' ); // RFC 8058

		return $message;
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return string
	 */
	private function unsubscribeUrl ( User $user ) {
		return $this->router->generate(
				'user_notifications_unsubscribe',
				[ 'hash' => $this->hashGenerator->generateUserHash( $user ) ],
				UrlGeneratorInterface::ABSOLUTE_URL
		);
	}
}
