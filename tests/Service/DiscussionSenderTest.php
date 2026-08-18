<?php

namespace App\Tests\Service;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Postmark\BulkTransport;
use App\Service\DiscussionSender;
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
	 * Builds a group whose members are the given users, all of them
	 * subscribed to the discussions e-mails.
	 *
	 * @param \App\Entity\User[] $users
	 *
	 * @return \App\Entity\Usergroup
	 */
	private function makeGroup ( array $users ) {
		$group = new Usergroup();
		$group->setName( 'Test group' );
		$group->setSlug( 'test-group' );

		foreach ( $users as $user ) {
			$membership = new UsergroupMembership();
			$membership->setUser( $user );
			$membership->setUsergroup( $group );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
			$membership->setNotificationsSettings( [] );

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

		$twig = $this->createMock( Environment::class );
		$twig->method( 'render' )->willReturn( '<p>body</p>' );

		$sender = new DiscussionSender( $transport, [ 'list_domain' => 'example.org' ], $twig );
		$sender->sendDiscussionMessage( $message );

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
