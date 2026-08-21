<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\Document;
use App\Entity\Notification;
use App\Entity\Page;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Notification\NotificationRhythm;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns something that happened in a group into one notification per member
 * who asked to hear about it. (#34)
 *
 * A notification is always shown on the platform. Ce qui la suit dépend du
 * niveau choisi sur la catégorie : rien, le résumé quotidien, le résumé du
 * lundi, ou un e-mail tout de suite — celui-là part d'ici, par ContentSender.
 * (#38)
 */
class NotificationSender {
	private $manager;

	private $router;

	private $mentions;

	/**
	 * @var \App\Service\ContentSender
	 */
	private $sender;

	public function __construct (
			EntityManagerInterface $manager,
			UrlGeneratorInterface $router,
			MentionParser $mentions,
			ContentSender $sender
	) {
		$this->manager  = $manager;
		$this->router   = $router;
		$this->mentions = $mentions;
		$this->sender   = $sender;
	}

	/**
	 * @param \App\Entity\Page $page
	 *
	 * @return int number of notifications created
	 */
	public function notifyNewPage ( Page $page ) {
		return $this->notify(
				$page->getUsergroup(),
				NotificationCategory::PAGES,
				Notification::PAGE_CREATE,
				$page->getTitle(),
				$this->router->generate( 'group_page_index', [
						'groupSlug' => $page->getUsergroup()->getSlug(),
						'pageSlug'  => $page->getSlug(),
				] ),
				$page->getAuthor()
		);
	}

	/**
	 * @param \App\Entity\Article $article
	 *
	 * @return int
	 */
	public function notifyNewArticle ( Article $article ) {
		return $this->notify(
				$article->getUsergroup(),
				NotificationCategory::ARTICLES,
				Notification::ARTICLE_CREATE,
				$article->getTitle(),
				$this->router->generate( 'group_article_index', [
						'groupSlug'   => $article->getUsergroup()->getSlug(),
						'articleSlug' => $article->getSlug(),
				] ),
				$article->getAuthor()
		);
	}

	/**
	 * @param \App\Entity\Document $document
	 *
	 * @return int
	 */
	public function notifyNewDocument ( Document $document ) {
		return $this->notify(
				$document->getUsergroup(),
				NotificationCategory::DOCUMENTS,
				Notification::DOCUMENT_CREATE,
				$document->getTitle(),
				$this->router->generate( 'group_documents_index', [
						'groupSlug' => $document->getUsergroup()->getSlug(),
				] ),
				$document->getUser()
		);
	}

	/**
	 * A discussion message follows the level of its own discussion, which may
	 * differ from the level of the category.
	 *
	 * @param \App\Entity\DiscussionMessage $message
	 *
	 * @return int
	 */
	public function notifyNewDiscussionMessage ( DiscussionMessage $message ) {
		/**
		 * @var \App\Entity\Discussion $discussion
		 */
		$discussion = $message->getDiscussion();

		// Being named comes first, and replaces the plain warning: one message
		// is worth one notification, and « on vous a nommé » says more than
		// « nouveau message ». (#37)
		$mentioned = $this->notifyMentions( $message );

		return $this->notify(
				$discussion->getUsergroup(),
				NotificationCategory::DISCUSSIONS,
				Notification::DISCUSSION_MESSAGE,
				$discussion->getTitle(),
				$this->router->generate( 'group_discussion_index', [
						'groupSlug'      => $discussion->getUsergroup()->getSlug(),
						'discussionUuid' => $discussion->getUuid(),
				] ),
				$message->getAuthor(),
				$discussion,
				array_keys( $mentioned )
		) + count( $mentioned );
	}

	/**
	 * Warns the members named in a message.
	 *
	 * A mention is addressed to someone in particular, so it reaches them even
	 * when they muted the discussion: what they turned off is the group
	 * talking, not somebody calling them. Only the « I want no e-mail at all »
	 * setting still holds. (#37)
	 *
	 * @param \App\Entity\DiscussionMessage $message
	 *
	 * @return \App\Entity\User[] indexed by user id
	 */
	private function notifyMentions ( DiscussionMessage $message ) {
		/**
		 * @var \App\Entity\Discussion $discussion
		 */
		$discussion = $message->getDiscussion();
		$group      = $discussion->getUsergroup();

		if ( !$group ) {
			return [];
		}

		$mentioned = $this->mentions->find( $message->getBody(), $group, $message->getAuthor() );

		if ( empty( $mentioned ) ) {
			return [];
		}

		$url = $this->router->generate( 'group_discussion_index', [
				'groupSlug'      => $group->getSlug(),
				'discussionUuid' => $discussion->getUuid(),
		] );

		$notified = [];

		foreach ( $mentioned as $recipient ) {
			$level = $this->levelForDiscussion( $recipient, $group, $discussion );

			$notification = new Notification();
			$notification->setRecipient( $recipient );
			$notification->setAuthor( $message->getAuthor() );
			$notification->setUsergroup( $group );
			$notification->setType( Notification::DISCUSSION_MENTION );
			$notification->setTitle( (string) $discussion->getTitle() );
			$notification->setUrl( $url );
			$notification->setCreatedAt( new DateTime() );

			// Une mention passe outre une discussion coupée : le rythme du
			// résumé se lit alors sur le réglage quand il en porte un, et vaut
			// le quotidien sinon. Ce qu'on ne fait pas, c'est la retenir.
			$rhythm = NotificationLevel::rhythm( $level );
			$notification->setRhythm( $rhythm ?: NotificationRhythm::DEFAULT_RHYTHM );

			// The summary is the only way a mention reaches a muted member by
			// e-mail; those who already get the message as it is posted do not
			// need to read about it twice.
			$notification->setByEmail(
					$recipient->wantsEmails() && !NotificationLevel::sendsNow( $level )
			);

			$this->manager->persist( $notification );

			$notified[ $recipient->getId() ] = $recipient;
		}

		$this->manager->flush();

		return $notified;
	}

	/**
	 * Ce que ce membre a demandé sur cette discussion-là — ce qui dit à la
	 * fois s'il reçoit déjà le message à chaud et à quel rythme son résumé
	 * part.
	 *
	 * @param \App\Entity\User      $recipient
	 * @param \App\Entity\Usergroup $group
	 * @param \App\Entity\Discussion $discussion
	 *
	 * @return string one of NotificationLevel
	 */
	private function levelForDiscussion ( User $recipient, Usergroup $group, Discussion $discussion ) {
		foreach ( $group->getMembers() as $membership ) {
			$member = $membership->getUser();

			if ( !$member || ( $member->getId() !== $recipient->getId() ) ) {
				continue;
			}

			return $membership->getLevelForDiscussion( $discussion->getUuid() );
		}

		return NotificationLevel::NONE;
	}

	/**
	 * @param \App\Entity\Usergroup       $group
	 * @param string                      $category
	 * @param string                      $type
	 * @param string                      $title
	 * @param string                      $url
	 * @param \App\Entity\User|null       $author
	 * @param \App\Entity\Discussion|null $discussion
	 * @param int[]                       $except ids of members already warned otherwise
	 *
	 * @return int
	 */
	private function notify (
			Usergroup $group = NULL,
			$category,
			$type,
			$title,
			$url,
			User $author = NULL,
			Discussion $discussion = NULL,
			array $except = []
	) {
		if ( !$group ) {
			return 0;
		}

		$created   = 0;
		$immediate = [];

		/**
		 * @var \App\Entity\UsergroupMembership $membership
		 */
		foreach ( $group->getMembers() as $membership ) {
			if ( $membership->getStatus() !== UsergroupMembership::STATUS_MEMBER ) {
				continue;
			}

			$recipient = $membership->getUser();

			if ( !$recipient || ( $recipient->getStatus() !== User::STATUS_ACTIVE ) ) {
				continue;
			}

			// Nobody is told about what they just did themselves. (#15)
			if ( $author && ( $author->getId() !== NULL ) && ( $author->getId() === $recipient->getId() ) ) {
				continue;
			}

			if ( in_array( $recipient->getId(), $except, TRUE ) ) {
				continue;
			}

			$level = $discussion
					? $membership->getLevelForDiscussion( $discussion->getUuid() )
					: $membership->getNotificationLevel( $category );

			if ( !NotificationLevel::showsOnPlatform( $level ) ) {
				continue;
			}

			$notification = new Notification();
			$notification->setRecipient( $recipient );
			$notification->setAuthor( $author );
			$notification->setUsergroup( $group );
			$notification->setType( $type );
			$notification->setTitle( (string) $title );
			$notification->setUrl( $url );
			$notification->setCreatedAt( new DateTime() );
			$notification->setRhythm( NotificationLevel::rhythm( $level ) );
			$notification->setByEmail( $this->shouldGoInTheSummary( $recipient, $level ) );

			// L'immédiat sur une page, une actualité ou un document part
			// d'ici. Sur un message de discussion, non : DiscussionSender l'a
			// déjà envoyé, avec le Reply-To qui permet d'y répondre.
			if ( $this->shouldBeSentNow( $recipient, $level, $type ) ) {
				$immediate[] = $notification;
			}

			$this->manager->persist( $notification );

			$created++;
		}

		$this->manager->flush();

		$this->sendNow( $immediate );

		return $created;
	}

	/**
	 * @param \App\Entity\User $recipient
	 * @param string           $level
	 * @param string           $type
	 *
	 * @return bool
	 */
	private function shouldBeSentNow ( User $recipient, $level, $type ) {
		if ( !NotificationLevel::sendsNow( $level ) || !$recipient->wantsEmails() ) {
			return FALSE;
		}

		return !in_array( $type, [ Notification::DISCUSSION_MESSAGE, Notification::DISCUSSION_MENTION ], TRUE );
	}

	/**
	 * Ce qui vient de partir ne doit pas repartir le soir dans le résumé.
	 *
	 * Une notification que le transport a refusée reste non marquée : elle
	 * rejoint le résumé plutôt que de disparaître.
	 *
	 * @param Notification[] $notifications
	 *
	 * @return void
	 */
	private function sendNow ( array $notifications ) {
		if ( empty( $notifications ) ) {
			return;
		}

		$sent = $this->sender->sendNow( $notifications );

		if ( empty( $sent ) ) {
			return;
		}

		$now = new DateTime();

		foreach ( $sent as $notification ) {
			$notification->setByEmail( FALSE );
			$notification->setEmailedAt( $now );
		}

		$this->manager->flush();
	}

	/**
	 * Ce qui part tout de suite ne rejoint pas le résumé : celui qui reçoit
	 * le message à la seconde où il est posté n'a pas à le relire le soir.
	 * Quotidien ou hebdomadaire, c'est le même résumé — seul le jour de départ
	 * change, et il est retenu sur la notification. (#38)
	 *
	 * @param \App\Entity\User $recipient
	 * @param string           $level
	 *
	 * @return bool
	 */
	private function shouldGoInTheSummary ( User $recipient, $level ) {
		if ( !NotificationLevel::sendsEmail( $level ) || !$recipient->wantsEmails() ) {
			return FALSE;
		}

		// L'immédiat ne passe pas par le résumé : l'e-mail est déjà parti, ou
		// s'apprête à partir. S'il échoue, sendNow() laisse la notification
		// non marquée et le résumé la reprend.
		return !NotificationLevel::sendsNow( $level );
	}
}
