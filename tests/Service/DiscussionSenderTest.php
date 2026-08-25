<?php

namespace App\Tests\Service;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Postmark\BulkTransport;
use App\Service\DiscussionSender;
use App\Service\HashGenerator;
use App\Service\HtmlToText;
use App\Service\MailGuard;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Swift_Message;
use Twig\Environment;

class DiscussionSenderTest extends TestCase {
	/**
	 * @param string $email
	 *
	 * @return \App\Entity\User
	 */
	private function makeUser ( $email ) {
		$user = new User();
		$user->setEmail( $email );
		$user->setName( 'Test User' );

		return $user;
	}

	/**
	 * Un groupe dont les membres ont demandé l'e-mail **immédiat** sur les
	 * discussions.
	 *
	 * Il fallait le dire. Un réglage vide valait « e-mail tout de suite »
	 * jusqu'à #38 ; depuis, le défaut est le résumé quotidien, et un groupe
	 * dont personne n'a rien demandé n'envoie plus rien à chaud. Laissé à
	 * vide, ce montage éprouvait un envoi qui n'a pas lieu — c'est-à-dire
	 * rien. Voir testTheDefaultRhythmSendsNothingAtOnce, qui tient l'autre
	 * bout.
	 *
	 * @param \App\Entity\User[] $users
	 * @param string             $level
	 *
	 * @return \App\Entity\Usergroup
	 */
	private function makeGroup ( array $users, $level = NotificationLevel::IMMEDIATE ) {
		$group = new Usergroup();
		$group->setName( 'Test group' );
		$group->setSlug( 'test-group' );

		foreach ( $users as $user ) {
			$membership = new UsergroupMembership();
			$membership->setUser( $user );
			$membership->setUsergroup( $group );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
			$membership->setNotificationsSettings( [
					'categories' => [ NotificationCategory::DISCUSSIONS => $level ],
			] );

			$group->addMember( $membership );
		}

		return $group;
	}

	/**
	 * @param \App\Entity\Usergroup $group
	 * @param \App\Entity\User      $author
	 *
	 * @return \App\Entity\DiscussionMessage
	 */
	private function makeMessage ( Usergroup $group, User $author ) {
		$discussion = new Discussion();
		$discussion->setTitle( 'Test discussion' );
		$discussion->setUsergroup( $group );
		$discussion->setAuthor( $author );

		$message = new DiscussionMessage();
		$message->setDiscussion( $discussion );
		$message->setAuthor( $author );
		$message->setBody( 'Hello' );

		return $message;
	}

	/**
	 * Captures the recipients of every message handed to the transport.
	 *
	 * @param \App\Entity\DiscussionMessage $message
	 *
	 * @return string[]
	 */
	private function collectRecipients ( DiscussionMessage $message ) {
		$sent = [];

		$transport = $this->createMock( BulkTransport::class );
		$transport->method( 'sendMultiple' )
				  ->willReturnCallback( function ( array $messages ) use ( &$sent ) {
					  /**
					   * @var Swift_Message $swiftMessage
					   */
					  foreach ( $messages as $swiftMessage ) {
						  $sent = array_merge( $sent, array_keys( $swiftMessage->getTo() ) );
					  }

					  return count( $messages );
				  } );

		$sender = $this->sender( $transport );
		$sender->sendDiscussionMessage( $message );

		return $sent;
	}

	/**
	 * @param \App\Postmark\BulkTransport $transport
	 *
	 * @return \App\Service\DiscussionSender
	 */
	private function sender ( BulkTransport $transport ) {
		$twig = $this->createMock( Environment::class );
		$twig->method( 'render' )->willReturn( '<p>Bonjour <a href="https://example.org/x">la discussion</a></p>' );

		$hashGenerator = $this->createMock( HashGenerator::class );
		$hashGenerator->method( 'generateUserHash' )->willReturn( '1|hash' );

		$router = $this->createMock( UrlGeneratorInterface::class );
		$router->method( 'generate' )->willReturn( 'https://example.org/unsubscribe/1%7Chash' );

		return new DiscussionSender(
				$transport,
				[ 'list_domain' => 'example.org' ],
				$twig,
				new HtmlToText(),
				$hashGenerator,
				$router,
				new MailGuard( 'test' )
		);
	}

	/**
	 * Captures the messages handed to the transport.
	 *
	 * @param \App\Entity\DiscussionMessage $message
	 *
	 * @return \Swift_Message[]
	 */
	private function collectMessages ( DiscussionMessage $message ) {
		$sent = [];

		$transport = $this->createMock( BulkTransport::class );
		$transport->method( 'sendMultiple' )
				  ->willReturnCallback( function ( array $messages ) use ( &$sent ) {
					  $sent = $messages;

					  return count( $messages );
				  } );

		$this->sender( $transport )->sendDiscussionMessage( $message );

		return $sent;
	}

	public function testAuthorDoesNotReceiveTheirOwnMessage () {
		$author = $this->makeUser( 'author@example.org' );
		$other  = $this->makeUser( 'other@example.org' );

		$group   = $this->makeGroup( [ $author, $other ] );
		$message = $this->makeMessage( $group, $author );

		$recipients = $this->collectRecipients( $message );

		$this->assertNotContains(
				'author@example.org',
				$recipients,
				'Assert the author of a message is not notified of their own message'
		);
	}

	public function testOtherMembersStillReceiveTheMessage () {
		$author = $this->makeUser( 'author@example.org' );
		$other  = $this->makeUser( 'other@example.org' );

		$group   = $this->makeGroup( [ $author, $other ] );
		$message = $this->makeMessage( $group, $author );

		$recipients = $this->collectRecipients( $message );

		$this->assertEquals(
				[ 'other@example.org' ],
				$recipients,
				'Assert every other member of the group is still notified'
		);
	}

	public function testTheMessageCarriesAPlainTextHalf () {
		$author = $this->makeUser( 'author@example.org' );
		$other  = $this->makeUser( 'other@example.org' );

		$sent = $this->collectMessages( $this->makeMessage( $this->makeGroup( [ $author, $other ] ), $author ) );

		$this->assertCount( 1, $sent );

		$parts = array_map( function ( $part ) {
			return $part->getContentType();
		}, $sent[ 0 ]->getChildren() );

		$this->assertContains(
				'text/plain',
				$parts,
				'Assert an HTML-only message is never sent, it is a spam signal'
		);
	}

	public function testThePlainTextHalfKeepsTheLinks () {
		$author = $this->makeUser( 'author@example.org' );
		$other  = $this->makeUser( 'other@example.org' );

		$sent = $this->collectMessages( $this->makeMessage( $this->makeGroup( [ $author, $other ] ), $author ) );

		$text = '';

		foreach ( $sent[ 0 ]->getChildren() as $part ) {
			if ( $part->getContentType() === 'text/plain' ) {
				$text = $part->getBody();
			}
		}

		$this->assertStringContainsString( 'la discussion', $text );
		$this->assertStringContainsString( 'https://example.org/x', $text );
	}

	public function testTheMessageOffersOneClickUnsubscribe () {
		$author = $this->makeUser( 'author@example.org' );
		$other  = $this->makeUser( 'other@example.org' );

		$sent    = $this->collectMessages( $this->makeMessage( $this->makeGroup( [ $author, $other ] ), $author ) );
		$headers = $sent[ 0 ]->getHeaders();

		$this->assertTrue(
				$headers->has( 'List-Unsubscribe' ),
				'Assert Gmail and Yahoo find the header they require of bulk senders'
		);
		$this->assertEquals(
				'List-Unsubscribe=One-Click',
				$headers->get( 'List-Unsubscribe-Post' )->getFieldBody()
		);
	}

	/**
	 * Le cas qui a rendu une 500 en préproduction, et que rien n'éprouvait.
	 *
	 * Depuis #38 le rythme par défaut est le résumé quotidien : dans un groupe
	 * où personne n'a demandé l'immédiat, il n'y a **rien** à envoyer. C'est
	 * devenu le cas ordinaire, et non l'exception — d'où le lot vide que le
	 * transport allait déréférencer.
	 */
	public function testTheDefaultRhythmSendsNothingAtOnce () {
		$author = $this->makeUser( 'author@example.org' );
		$other  = $this->makeUser( 'other@example.org' );

		$group   = $this->makeGroup( [ $author, $other ], NotificationLevel::DAILY );
		$message = $this->makeMessage( $group, $author );

		$this->assertSame(
				[],
				$this->collectRecipients( $message ),
				'Assert nobody is written to at once when everybody is on the summary'
		);
	}

	/**
	 * Et le lot vide ne doit pas remonter en erreur : le message est déjà
	 * enregistré, la page de celui qui vient d'écrire ne regarde pas ce que
	 * Postmark en fait.
	 */
	public function testAnEmptyBatchIsNotAFailure () {
		$author = $this->makeUser( 'author@example.org' );
		$other  = $this->makeUser( 'other@example.org' );

		$group   = $this->makeGroup( [ $author, $other ], NotificationLevel::DAILY );
		$message = $this->makeMessage( $group, $author );

		$transport = $this->createMock( BulkTransport::class );
		$transport->expects( $this->never() )->method( 'sendMultiple' );

		$this->assertSame(
				0,
				$this->sender( $transport )->sendDiscussionMessage( $message ),
				'Assert publishing a message survives a group nobody wants an e-mail from'
		);
	}

	public function testMessageWithoutAuthorIsSentToEveryone () {
		$first  = $this->makeUser( 'first@example.org' );
		$second = $this->makeUser( 'second@example.org' );

		$group   = $this->makeGroup( [ $first, $second ] );
		$message = $this->makeMessage( $group, $first );

		// An anonymised account leaves the message without an author: nobody
		// can be excluded, everyone must still be notified.
		$message->setAuthor( NULL );

		$recipients = $this->collectRecipients( $message );

		$this->assertEquals(
				[ 'first@example.org', 'second@example.org' ],
				$recipients,
				'Assert an authorless message is still sent to every member'
		);
	}
}
