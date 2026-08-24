<?php

namespace App\Tests\DataFixtures;

use App\Entity\Article;
use App\Entity\Conversation;
use App\Entity\Discussion;
use App\Entity\Document;
use App\Entity\DocumentTag;
use App\Entity\MessageReport;
use App\Entity\Notification;
use App\Entity\Page;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Entity\Usergroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ce que les données de test doivent contenir pour qu'une recette soit
 * possible sans rien saisir à la main.
 *
 * Chaque fonctionnalité livrée a besoin d'un cas visible **et** de son
 * contraire : une fiche joignable et une fiche muette, un document étiqueté
 * et un document sans étiquette, une notification lue et une non lue. Une
 * fixture qui ne montre qu'un seul côté ne prouve rien.
 *
 * Ce test tient ce contrat : si quelqu'un allège les fixtures, il saura
 * lequel de ces cas il vient de faire disparaître.
 */
class SeedDataTest extends KernelTestCase {
	private const REFERENCE_GROUP = 'groupe-de-test';

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	protected function setUp (): void {
		self::bootKernel();

		$this->manager = self::$container->get( EntityManagerInterface::class );
	}

	/**
	 * @param string $email
	 *
	 * @return \App\Entity\User
	 */
	private function account ( $email ) {
		$user = $this->manager->getRepository( User::class )->findOneBy( [ 'email' => $email ] );

		if ( !$user ) {
			$this->markTestSkipped( sprintf( 'Fixtures not loaded: %s', $email ) );
		}

		return $user;
	}

	/**
	 * @return \App\Entity\Usergroup
	 */
	private function referenceGroup () {
		$group = $this->manager->getRepository( Usergroup::class )
							   ->findOneBy( [ 'slug' => self::REFERENCE_GROUP ] );

		if ( !$group ) {
			$this->markTestSkipped( 'Fixtures not loaded: reference group' );
		}

		return $group;
	}

	/**************************************************
	 * LE GROUPE OÙ TOUT LE MONDE ARRIVE
	 **************************************************/

	/**
	 * @return \App\Entity\Usergroup
	 */
	private function communityGroup () {
		$group = $this->manager->getRepository( Usergroup::class )
							   ->findOneBy( [ 'slug' => 'communaute' ] );

		if ( !$group ) {
			$this->markTestSkipped( 'Fixtures not loaded: community group' );
		}

		return $group;
	}

	public function testTheCommunityGroupHasPages () {
		$this->assertNotEmpty(
				$this->manager->getRepository( Page::class )->findBy( [ 'usergroup' => $this->communityGroup() ] ),
				'Assert the group everybody lands on is not empty'
		);
	}

	public function testTheCommunityGroupHasDiscussionsThatCarryMessages () {
		$discussions = $this->manager->getRepository( Discussion::class )
									 ->findBy( [ 'usergroup' => $this->communityGroup() ] );

		$this->assertNotEmpty( $discussions );

		$withMessages = 0;

		foreach ( $discussions as $discussion ) {
			if ( count( $discussion->getMessages() ) > 0 ) {
				$withMessages++;
			}
		}

		$this->assertGreaterThan( 0, $withMessages, 'Assert the threads are not empty shells' );
	}

	public function testTheCommunityGroupHasArticles () {
		$this->assertNotEmpty(
				$this->manager->getRepository( Article::class )->findBy( [ 'usergroup' => $this->communityGroup() ] )
		);
	}

	public function testTheCommunityGroupHasDocuments () {
		$this->assertNotEmpty(
				$this->manager->getRepository( Document::class )->findBy( [ 'usergroup' => $this->communityGroup() ] )
		);
	}

	public function testTheCommunityGroupOpensOnAHighlightedPage () {
		$pages = $this->manager->getRepository( Page::class )
							   ->findBy( [ 'usergroup' => $this->communityGroup() ] );

		if ( empty( $pages ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: community pages' );
		}

		$highlighted = 0;

		foreach ( $pages as $page ) {
			if ( $page->getIsImportant() ) {
				$highlighted++;
			}
		}

		$this->assertGreaterThan(
				0,
				$highlighted,
				'Assert somebody arriving finds what the platform is for, in front of them'
		);
	}

	/**************************************************
	 * #30 — LES CHAMPS MÉTIER
	 **************************************************/

	public function testANamedAccountCarriesAFullDirectoryProfile () {
		$referent = $this->account( 'referent@example.org' );

		$this->assertNotEmpty( $referent->getJobTitle(), 'Assert the directory has a function to show' );
		$this->assertNotEmpty( $referent->getOrganisation() );
		$this->assertNotEmpty( $referent->getReserves() );
	}

	public function testANamedAccountIsLeftEmptyOnPurpose () {
		$candidate = $this->account( 'candidat@example.org' );

		$this->assertEmpty(
				$candidate->getJobTitle(),
				'Assert an untouched profile is among the seed data, so its rendering can be checked'
		);
	}

	/**************************************************
	 * #27 — LES COORDONNÉES DE CONTACT
	 **************************************************/

	public function testAnAccountPublishesItsPhoneNumber () {
		$this->assertNotEmpty( $this->account( 'admin@example.org' )->getPhone() );
	}

	public function testAnAccountHidesItsAddress () {
		$this->assertFalse(
				$this->account( 'membre@example.org' )->isEmailVisible(),
				'Assert the opt-out is exercised by the seed data'
		);
	}

	public function testAnAccountShowsNoContactDetailAtAll () {
		$member = $this->account( 'membre@example.org' );

		$this->assertFalse( $member->isEmailVisible() );
		$this->assertEmpty(
				$member->getPhone(),
				'Assert the silent profile exists, the one that must not read as a bug'
		);
	}

	public function testAnAccountShowsItsPhoneButNotItsAddress () {
		$outside = $this->account( 'exterieur@example.org' );

		$this->assertNotEmpty( $outside->getPhone() );
		$this->assertFalse( $outside->isEmailVisible(), 'Assert the other half of the choice is shown too' );
	}

	/**************************************************
	 * #26 — LES ÉTIQUETTES DE DOCUMENTS
	 **************************************************/

	public function testTheTagVocabularyIsSeeded () {
		$tags = $this->manager->getRepository( DocumentTag::class )->findAll();

		if ( empty( $tags ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: document tags' );
		}

		$names = array_map( function ( DocumentTag $tag ) {
			return $tag->getName();
		}, $tags );

		$this->assertContains( 'Grand public', $names, 'Assert the vocabulary asked for is the one seeded' );
		$this->assertContains( 'Cycle 1', $names );
	}

	public function testADocumentCarriesTagsAndAnotherCarriesNone () {
		$documents = $this->manager->getRepository( Document::class )
								   ->findBy( [ 'usergroup' => $this->referenceGroup() ] );

		if ( empty( $documents ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: reference documents' );
		}

		$tagged   = 0;
		$untagged = 0;

		foreach ( $documents as $document ) {
			count( $document->getTags() ) > 0 ? $tagged++ : $untagged++;
		}

		$this->assertGreaterThan( 0, $tagged, 'Assert the filter has something to find' );
		$this->assertGreaterThan( 0, $untagged, 'Assert the filter has something to leave out' );
	}

	/**************************************************
	 * #33 — QUI MODIFIE QUOI
	 **************************************************/

	public function testThePagesOfTheReferenceGroupHaveTwoDifferentAuthors () {
		$pages = $this->manager->getRepository( Page::class )
							   ->findBy( [ 'usergroup' => $this->referenceGroup() ] );

		if ( empty( $pages ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: reference pages' );
		}

		$authors = [];

		foreach ( $pages as $page ) {
			$authors[ $page->getAuthor()->getEmail() ] = TRUE;
		}

		$this->assertGreaterThan(
				1,
				count( $authors ),
				'Assert « I edit mine, not yours » can be tried without writing a page first'
		);
	}

	public function testAPlainMemberAuthoredSomething () {
		$member = $this->account( 'membre@example.org' );

		$pages = $this->manager->getRepository( Page::class )
							   ->findBy( [ 'usergroup' => $this->referenceGroup(), 'author' => $member ] );

		$this->assertNotEmpty(
				$pages,
				'Assert the seed data holds content whose author is not an animator'
		);
	}

	/**************************************************
	 * #37 — LES MENTIONS
	 **************************************************/

	public function testAMessageNamesSomebody () {
		$member = $this->account( 'membre@example.org' );

		$discussions = $this->manager->getRepository( Discussion::class )
									 ->findBy( [ 'usergroup' => $this->referenceGroup() ] );

		if ( empty( $discussions ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: reference discussions' );
		}

		$mentioning = FALSE;

		foreach ( $discussions as $discussion ) {
			foreach ( $discussion->getMessages() as $message ) {
				$mentioning = $mentioning
							  || ( strpos( (string) $message->getBody(), '@' . $member->getName() ) !== FALSE );
			}
		}

		$this->assertTrue(
				$mentioning,
				'Assert a mention is posted in advance, so its link and its notification can be seen'
		);
	}

	/**************************************************
	 * #32 — LA NAVIGATION ENTRE DOCUMENTS ET PAGES
	 **************************************************/

	public function testSomethingLinksToADocument () {
		$documents = $this->manager->getRepository( Document::class )
								   ->findBy( [ 'usergroup' => $this->referenceGroup() ] );

		if ( empty( $documents ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: reference documents' );
		}

		$linked = FALSE;

		foreach ( $documents as $document ) {
			$fromPages = $this->manager->getRepository( Page::class )
									   ->findMentioningDocument( $this->referenceGroup(), $document->getId() );

			$fromDiscussions = $this->manager->getRepository( Discussion::class )
											 ->findMentioningDocument( $this->referenceGroup(), $document->getId() );

			$linked = $linked || !empty( $fromPages ) || !empty( $fromDiscussions );
		}

		$this->assertTrue(
				$linked,
				'Assert a document sheet shows what was said about it, rather than « nobody talked about it »'
		);
	}

	/**************************************************
	 * #28 / #36 — UN COMPTE VENANT DE GEONATURE
	 **************************************************/

	public function testAnAccountCarriesAGeoNatureIdentity () {
		$this->assertNotNull(
				$this->account( 'sso@example.org' )->getRnfIdRole(),
				'Assert the locked fields can be seen without a real single sign-on round trip'
		);
	}

	public function testAPlainLocalAccountExistsBesideIt () {
		$this->assertNull(
				$this->account( 'membre@example.org' )->getRnfIdRole(),
				'Assert the account that still writes its own profile is seeded too'
		);
	}

	/**************************************************
	 * #39 — LA VISITE GUIDÉE
	 **************************************************/

	public function testAnAccountHasAlreadyWatchedTheTour () {
		$this->assertNotNull(
				$this->account( 'admin@example.org' )->getTourSeenAt(),
				'Assert the replay link can be tried without watching the tour first'
		);
	}

	public function testAnAccountHasNeverWatchedIt () {
		$this->assertNull(
				$this->account( 'membre@example.org' )->getTourSeenAt(),
				'Assert the automatic launch can be tried by signing in'
		);
	}

	/**************************************************
	 * #34 — LES NOTIFICATIONS
	 **************************************************/

	public function testTheNotificationListIsNotEmpty () {
		$notifications = $this->manager->getRepository( Notification::class )
									   ->findBy( [ 'recipient' => $this->account( 'membre@example.org' ) ] );

		$this->assertNotEmpty(
				$notifications,
				'Assert the notifications page shows something on a freshly loaded database'
		);
	}

	public function testAMentionNotificationAndAReadOneAreSeeded () {
		$notifications = $this->manager->getRepository( Notification::class )
									   ->findBy( [ 'recipient' => $this->account( 'membre@example.org' ) ] );

		if ( empty( $notifications ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: notifications' );
		}

		$types = [];
		$read  = 0;

		foreach ( $notifications as $notification ) {
			$types[ $notification->getType() ] = TRUE;

			if ( $notification->getReadAt() !== NULL ) {
				$read++;
			}
		}

		$this->assertArrayHasKey(
				Notification::DISCUSSION_MENTION,
				$types,
				'Assert « votre nom a été cité » can be read without posting a message first'
		);
		$this->assertGreaterThan( 0, $read, 'Assert read and unread can be told apart' );
		$this->assertLessThan( count( $notifications ), $read, 'Assert one of them is still unread' );
	}

	/**************************************************
	 * LA MESSAGERIE
	 **************************************************/

	/**
	 * Les conversations d'un compte, la boîte et les archives ensemble.
	 *
	 * @param string $email
	 *
	 * @return \App\Entity\Conversation[]
	 */
	private function conversationsOf ( $email ) {
		$user       = $this->account( $email );
		$repository = $this->manager->getRepository( Conversation::class );

		$found = array_merge(
				$repository->findForUser( $user ),
				$repository->findForUser( $user, NULL, TRUE )
		);

		if ( empty( $found ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: conversations' );
		}

		return $found;
	}

	/**
	 * Tout ce qui a été écrit dans les conversations d'un compte.
	 *
	 * @param string $email
	 *
	 * @return \App\Entity\PrivateMessage[]
	 */
	private function messagesOf ( $email ) {
		$messages = [];

		foreach ( $this->conversationsOf( $email ) as $conversation ) {
			foreach ( $this->manager->getRepository( PrivateMessage::class )->findForConversation( $conversation ) as $message ) {
				$messages[] = $message;
			}
		}

		return $messages;
	}

	public function testTheInboxIsNotEmpty () {
		$this->assertNotEmpty(
				$this->conversationsOf( 'membre@example.org' ),
				'Assert the messaging page shows something on a freshly loaded database'
		);
	}

	/**
	 * Sans conversation non lue, ni le compteur de l'en-tête ni la barre
	 * « nouveaux messages » n'ont rien à montrer.
	 */
	public function testAConversationIsWaitingToBeRead () {
		$this->assertGreaterThan(
				0,
				$this->manager->getRepository( Conversation::class )
							  ->countUnread( $this->account( 'membre@example.org' ) ),
				'Assert an unread conversation is seeded, so the header count is not always zero'
		);
	}

	public function testAConversationIsArchivedAndAnotherIsNot () {
		$user       = $this->account( 'membre@example.org' );
		$repository = $this->manager->getRepository( Conversation::class );

		$this->assertNotEmpty(
				$repository->findForUser( $user, NULL, TRUE ),
				'Assert the « Archivées » tab has something to show'
		);

		$this->assertNotEmpty(
				$repository->findForUser( $user ),
				'Assert archiving one conversation did not empty the inbox'
		);
	}

	/**
	 * Le tête-à-tête et le fil à plusieurs : les deux cas du même modèle.
	 */
	public function testAOneToOneAndAGroupConversationAreBothSeeded () {
		$pairs  = 0;
		$groups = 0;

		foreach ( $this->conversationsOf( 'membre@example.org' ) as $conversation ) {
			if ( count( $conversation->getActiveParticipants() ) > 2 ) {
				$groups++;

				continue;
			}

			$pairs++;
		}

		$this->assertGreaterThan( 0, $pairs, 'Assert a one-to-one conversation is seeded' );
		$this->assertGreaterThan( 0, $groups, 'Assert a conversation with more than two people is seeded' );
	}

	/**
	 * Quitter laisse les messages en place : sans un fil que quelqu'un a
	 * quitté, on ne voit jamais que le fil de ceux qui restent tient debout.
	 */
	public function testSomebodyLeftAConversationWithoutEmptyingIt () {
		$left = 0;

		foreach ( $this->conversationsOf( 'membre@example.org' ) as $conversation ) {
			foreach ( $conversation->getParticipants() as $participant ) {
				if ( $participant->hasLeft() ) {
					$left++;
				}
			}
		}

		$this->assertGreaterThan( 0, $left, 'Assert a conversation somebody left is seeded' );
	}

	public function testAMessageWasEditedAndAnotherWasDeleted () {
		$edited  = 0;
		$deleted = 0;

		foreach ( $this->messagesOf( 'membre@example.org' ) as $message ) {
			if ( $message->isEdited() ) {
				$edited++;
			}

			if ( $message->isDeleted() ) {
				$deleted++;
			}
		}

		$this->assertGreaterThan( 0, $edited, 'Assert « modifié » can be seen without editing a message first' );
		$this->assertGreaterThan( 0, $deleted, 'Assert a deleted message keeps its place in the thread' );
	}

	/**
	 * Un compte ouvert et un compte fermé : sans les deux, on ne voit jamais
	 * le bouton « Écrire » disparaître.
	 */
	public function testABoxIsClosedAndTheOthersAreOpen () {
		$this->assertFalse(
				$this->account( 'exterieur@example.org' )->isMessagesOpen(),
				'Assert a closed mailbox is seeded'
		);

		$this->assertTrue(
				$this->account( 'membre@example.org' )->isMessagesOpen(),
				'Assert the default — an open mailbox — is represented too'
		);
	}

	/**
	 * Fermer sa boîte n'interrompt pas les conversations déjà ouvertes. La
	 * règle ne s'éprouve qu'avec un compte fermé qui en a déjà une.
	 */
	public function testTheClosedBoxStillCarriesAConversation () {
		$this->assertNotEmpty(
				$this->conversationsOf( 'exterieur@example.org' ),
				'Assert the closed mailbox already holds a conversation, so answering it can be tried'
		);
	}

	/**************************************************
	 * LES TAGS D'UN MESSAGE
	 **************************************************/

	/**
	 * Un tag vers une personne, un vers un groupe, un vers un document : les
	 * trois sortes doivent être écrites quelque part, sinon le rendu ne se
	 * regarde pas.
	 */
	public function testTheThreeKindsOfTagAreWrittenSomewhere () {
		$bodies = '';

		foreach ( $this->messagesOf( 'membre@example.org' ) as $message ) {
			$bodies .= "\n" . $message->getBody();
		}

		foreach ( $this->messagesOf( 'candidat@example.org' ) as $message ) {
			$bodies .= "\n" . $message->getBody();
		}

		$this->assertRegExp( '/@[A-ZÉÈÀÂÎÔÛ]/u', $bodies, 'Assert somebody is named with an « @ » tag' );
		$this->assertStringContainsString( '#Groupe de test', $bodies, 'Assert a group is pointed at with a « # » tag' );
		$this->assertStringContainsString( '#Guide des suivis partagés', $bodies, 'Assert a document is pointed at' );
	}

	/**
	 * Le rendu d'un tag dépend du lecteur. Sans un contenu que l'un des deux
	 * correspondants ne peut pas ouvrir, le tag grisé ne se voit nulle part.
	 */
	public function testAMessagePointsAtSomethingItsReaderMayNotOpen () {
		$note = $this->manager->getRepository( Document::class )
							  ->findOneBy( [ 'title' => 'Note de cadrage du bureau' ] );

		if ( !$note ) {
			$this->markTestSkipped( 'Fixtures not loaded: the private document' );
		}

		$this->assertEquals(
				Usergroup::PRIVATE,
				$note->getUsergroup()->getVisibility(),
				'Assert the tagged document lives where not everybody may read it'
		);

		$bodies = '';

		foreach ( $this->messagesOf( 'candidat@example.org' ) as $message ) {
			$bodies .= "\n" . $message->getBody();
		}

		$this->assertStringContainsString(
				'#' . $note->getTitle(),
				$bodies,
				'Assert the greyed-out tag can be seen by somebody who is not a member'
		);
	}

	/**
	 * Le titre tagué ne doit désigner qu'une chose. Les contenus du groupe de
	 * référence s'appellent tous pareil dans les trois groupes : un « # »
	 * écrit dessus se résoudrait sur le premier trouvé, ce qui ne se raconte
	 * pas dans une recette.
	 */
	public function testTheTaggedTitlesAreUnique () {
		foreach ( [ 'Guide des suivis partagés', 'Note de cadrage du bureau' ] as $title ) {
			$this->assertCount(
					1,
					$this->manager->getRepository( Document::class )->findBy( [ 'title' => $title ] ),
					sprintf( 'Assert « %s » names one document and one only', $title )
			);
		}
	}

	/**************************************************
	 * LES SIGNALEMENTS
	 **************************************************/

	public function testAReportIsWaitingAndAnotherIsHandled () {
		$repository = $this->manager->getRepository( MessageReport::class );

		$pending = $repository->findForAdmin();
		$handled = $repository->findForAdmin( TRUE );

		if ( empty( $pending ) && empty( $handled ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: reports' );
		}

		$this->assertNotEmpty( $pending, 'Assert the « À traiter » tab has something to show' );
		$this->assertNotEmpty( $handled, 'Assert the « Traités » tab has something to show too' );
	}

	/**
	 * Un signalement porte sa propre copie : c'est ce qui permet à
	 * l'administration de juger sans jamais ouvrir la conversation.
	 */
	public function testAReportCarriesItsOwnCopyAndItsContext () {
		$reports = $this->manager->getRepository( MessageReport::class )->findForAdmin();

		if ( empty( $reports ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: reports' );
		}

		$withContext = 0;

		foreach ( $reports as $report ) {
			$this->assertNotEmpty(
					$report->getExcerpt(),
					'Assert the report reads without going back to the message'
			);

			if ( !empty( $report->getContext() ) ) {
				$withContext++;
			}
		}

		$this->assertGreaterThan(
				0,
				$withContext,
				'Assert a report carries the messages around the one being reported'
		);
	}

	/**
	 * La notification d'un message privé est la seule sans groupe, et son
	 * titre est le nom de celui qui écrit — jamais un extrait.
	 */
	public function testAPrivateMessageNotificationIsSeeded () {
		$notifications = $this->manager->getRepository( Notification::class )
									   ->findBy( [
											   'recipient' => $this->account( 'membre@example.org' ),
											   'type'      => Notification::MESSAGE_NEW,
									   ] );

		if ( empty( $notifications ) ) {
			$this->markTestSkipped( 'Fixtures not loaded: message notifications' );
		}

		foreach ( $notifications as $notification ) {
			$this->assertNull( $notification->getUsergroup(), 'Assert a private message belongs to no group' );
			$this->assertEquals(
					$notification->getAuthor() ? $notification->getAuthor()->getName() : NULL,
					$notification->getTitle(),
					'Assert the notification names the author rather than quoting the message'
			);
		}
	}
}
