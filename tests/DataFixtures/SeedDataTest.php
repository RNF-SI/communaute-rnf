<?php

namespace App\Tests\DataFixtures;

use App\Entity\Discussion;
use App\Entity\Document;
use App\Entity\DocumentTag;
use App\Entity\Notification;
use App\Entity\Page;
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
}
