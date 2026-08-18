<?php

namespace App\Tests\Controller;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #12 — replies sent by e-mail were recorded, and notified, several
 * times. Postmark retries a webhook it believes failed, and nothing told the
 * application it had already handled that delivery.
 */
class InboundMailTest extends WebTestCase {
	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Entity\Discussion
	 */
	private $discussion;

	/**
	 * @var \App\Entity\User
	 */
	private $user;

	protected function setUp (): void {
		$this->client = static::createClient();

		// Several requests per test: without this the kernel would be rebooted
		// between them, opening a new connection that cannot see the data
		// created in the still-open transaction.
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );

		$this->manager->getConnection()->beginTransaction();

		$this->user = new User();
		$this->user->setEmail( 'member@example.org' );
		$this->user->setName( 'Test User' );
		$this->user->setCreatedAt( new DateTime() );
		$this->user->setStatus( User::STATUS_ACTIVE );
		$this->user->setPassword( '' );
		$this->user->setHasAgreedTermsOfUse( TRUE );
		$this->manager->persist( $this->user );

		$group = new Usergroup();
		$group->setSlug( 'test-group' );
		$group->setName( 'Test group' );
		$group->setVisibility( Usergroup::PUBLIC );
		$group->setCreatedAt( new DateTime() );
		$group->setIsActive( TRUE );
		$this->manager->persist( $group );

		$membership = new UsergroupMembership();
		$membership->setUser( $this->user );
		$membership->setUsergroup( $group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );

		$this->discussion = new Discussion();
		$this->discussion->setUuid( Uuid::uuid4() );
		$this->discussion->setTitle( 'Existing discussion' );
		$this->discussion->setUsergroup( $group );
		$this->discussion->setAuthor( $this->user );
		$this->discussion->setCreatedAt( new DateTime() );
		$this->discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $this->discussion );

		$this->manager->flush();
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string      $messageId
	 * @param string|null $body
	 */
	private function deliver ( $messageId, $body = 'Ma réponse' ) {
		$payload = [
				'MessageID'         => $messageId,
				'OriginalRecipient' => 'test-group+' . $this->discussion->getUuid() . '@example.org',
				'MailboxHash'       => $this->discussion->getUuid(),
				'From'              => 'member@example.org',
				'Subject'           => 'Re: Existing discussion',
				'TextBody'          => $body,
				'HtmlBody'          => '<p>' . $body . '</p>',
				'StrippedTextBody'  => $body,
				'Headers'           => [],
				'Attachments'       => [],
		];

		$this->client->request(
				'POST',
				'/ws/list/inbound/' . self::$container->getParameter( 'postmark' )[ 'inbound_key' ],
				[],
				[],
				[ 'CONTENT_TYPE' => 'application/json' ],
				json_encode( $payload )
		);
	}

	/**
	 * @return int
	 */
	private function messageCount () {
		return (int) $this->manager->createQueryBuilder()
								   ->select( 'COUNT(m.id)' )
								   ->from( DiscussionMessage::class, 'm' )
								   ->where( 'm.discussion = :discussion' )
								   ->setParameter( 'discussion', $this->discussion )
								   ->getQuery()
								   ->getSingleScalarResult();
	}

	public function testAReplyByEmailIsRecorded () {
		$this->deliver( 'aaaa-1111' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertEquals( 1, $this->messageCount(), 'Assert the reply is recorded' );
	}

	public function testTheSameDeliveryIsNeverRecordedTwice () {
		$this->deliver( 'aaaa-1111' );
		$this->deliver( 'aaaa-1111' );
		$this->deliver( 'aaaa-1111' );

		$this->assertEquals(
				1,
				$this->messageCount(),
				'Assert a webhook retried by Postmark does not duplicate the message'
		);
	}

	public function testARetryIsAnsweredWithoutError () {
		$this->deliver( 'aaaa-1111' );
		$this->deliver( 'aaaa-1111' );

		$this->assertEquals(
				200,
				$this->client->getResponse()->getStatusCode(),
				'Assert the retry gets a success answer, so Postmark stops retrying'
		);
		$this->assertStringContainsString(
				'already processed',
				$this->client->getResponse()->getContent()
		);
	}

	public function testTwoDistinctRepliesAreBothRecorded () {
		$this->deliver( 'aaaa-1111', 'Première réponse' );
		$this->deliver( 'bbbb-2222', 'Deuxième réponse' );

		$this->assertEquals(
				2,
				$this->messageCount(),
				'Assert two genuine replies are not mistaken for a retry'
		);
	}
}
