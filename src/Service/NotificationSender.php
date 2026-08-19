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

	public function __construct ( EntityManagerInterface $manager, UrlGeneratorInterface $router ) {
		$this->manager = $manager;
		$this->router  = $router;
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
				$discussion
		);
	}

	/**
	 * @param \App\Entity\Usergroup       $group
	 * @param string                      $category
	 * @param string                      $type
	 * @param string                      $title
	 * @param string                      $url
	 * @param \App\Entity\User|null       $author
	 * @param \App\Entity\Discussion|null $discussion
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
			Discussion $discussion = NULL
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
	 * Discussion messages only join the summary for members who asked for that
	 * rhythm; the others already got an e-mail as the message was posted.
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
			return $recipient->getDiscussionEmailRhythm() === NotificationRhythm::DIGEST;
		}

		return TRUE;
	}
}
