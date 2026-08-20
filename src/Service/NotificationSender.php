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
 * Nothing is sent from here: a notification is shown on the platform, and the
 * ones flagged for e-mail are picked up later by the daily summary.
 */
class NotificationSender {
	private $manager;

	private $router;

	private $mentions;

	public function __construct (
			EntityManagerInterface $manager,
			UrlGeneratorInterface $router,
			MentionParser $mentions
	) {
		$this->manager  = $manager;
		$this->router   = $router;
		$this->mentions = $mentions;
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
			$notification = new Notification();
			$notification->setRecipient( $recipient );
			$notification->setAuthor( $message->getAuthor() );
			$notification->setUsergroup( $group );
			$notification->setType( Notification::DISCUSSION_MENTION );
			$notification->setTitle( (string) $discussion->getTitle() );
			$notification->setUrl( $url );
			$notification->setCreatedAt( new DateTime() );

			// The summary is the only way a mention reaches a muted member by
			// e-mail; those who already get the message as it is posted do not
			// need to read about it twice.
			$notification->setByEmail(
					$recipient->wantsEmails() && !$this->alreadyEmailed( $recipient, $group, $discussion )
			);

			$this->manager->persist( $notification );

			$notified[ $recipient->getId() ] = $recipient;
		}

		$this->manager->flush();

		return $notified;
	}

	/**
	 * Whether the message itself is already on its way to this member.
	 *
	 * @param \App\Entity\User      $recipient
	 * @param \App\Entity\Usergroup $group
	 * @param \App\Entity\Discussion $discussion
	 *
	 * @return bool
	 */
	private function alreadyEmailed ( User $recipient, Usergroup $group, Discussion $discussion ) {
		if ( $recipient->getDiscussionEmailRhythm() !== NotificationRhythm::IMMEDIATE ) {
			return FALSE;
		}

		foreach ( $group->getMembers() as $membership ) {
			$member = $membership->getUser();

			if ( !$member || ( $member->getId() !== $recipient->getId() ) ) {
				continue;
			}

			return NotificationLevel::sendsEmail( $membership->getLevelForDiscussion( $discussion->getUuid() ) );
		}

		return FALSE;
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

		$created = 0;

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
			$notification->setByEmail( $this->shouldGoInTheSummary( $recipient, $level, $type ) );

			$this->manager->persist( $notification );

			$created++;
		}

		$this->manager->flush();

		return $created;
	}

	/**
	 * Discussion messages only join the summary for members who did not ask
	 * for the immediate e-mail; the others already got one as the message was
	 * posted. Quotidien ou hebdomadaire, c'est le même résumé — seul le jour
	 * de départ change, et c'est la commande qui en décide. (#38)
	 *
	 * @param \App\Entity\User $recipient
	 * @param string           $level
	 * @param string           $type
	 *
	 * @return bool
	 */
	private function shouldGoInTheSummary ( User $recipient, $level, $type ) {
		if ( !NotificationLevel::sendsEmail( $level ) || !$recipient->wantsEmails() ) {
			return FALSE;
		}

		if ( $type === Notification::DISCUSSION_MESSAGE ) {
			return $recipient->getDiscussionEmailRhythm() !== NotificationRhythm::IMMEDIATE;
		}

		return TRUE;
	}
}
