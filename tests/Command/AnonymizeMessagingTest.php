<?php

namespace App\Tests\Command;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\MessageReport;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Service\ConversationManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Ce que l'anonymisation fait de la messagerie.
 *
 * Le reste de la plateforme est laissé tel quel : une discussion, une page,
 * une actualité peuvent encore nommer quelqu'un, et la commande le dit. Les
 * messages privés sont l'exception, parce qu'ils sont ce qu'il y a de plus
 * personnel et que personne ne les relira jamais pour les nettoyer à la main.
 *
 * Deux exigences se tiennent en équilibre, et c'est cet équilibre qui est
 * contrôlé ici :
 *
 * - **il ne doit plus rien rester de ce qui a été dit** — le texte des
 *   messages, celui que recopie un signalement, et jusqu'aux messages qui
 *   précédaient le message signalé ;
 * - **une copie doit rester utilisable** — qui a écrit à qui, quand, combien
 *   de fois, quelles conversations sont lues ou archivées. Une copie où les
 *   conversations auraient disparu ne permettrait plus de recetter la
 *   messagerie, et c'est bien pour cela qu'on en fait une.
 */
class AnonymizeMessagingTest extends KernelTestCase {
	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Service\ConversationManager
	 */
	private $conversations;

	/**
	 * @var \Symfony\Component\Console\Tester\CommandTester
	 */
	private $command;

	protected function setUp (): void {
		self::bootKernel();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->conversations = self::$container->get( ConversationManager::class );

		$this->command = new CommandTester(
				( new Application( self::$kernel ) )->find( 'app:db:anonymize' )
		);
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string $name
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $name ) {
		$user = new User();
		$user->setEmail( uniqid() . '@rnfrance.org' );
		$user->setName( $name );
		$user->setPassword( '' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param array $options
	 */
	private function anonymize ( array $options = [] ) {
		$this->command->execute( $options );
		$this->manager->clear();
	}

	/**
	 * @param string $class
	 * @param int    $id
	 *
	 * @return object|null
	 */
	private function reload ( $class, $id ) {
		return $this->manager->getRepository( $class )->find( $id );
	}

	public function testTheMessagesAreStillThereAndNoLongerSayAnything () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversations->open( $jeanne, [ $paul ] );
		$first        = $this->conversations->post( $conversation, $jeanne, 'Le comptage a eu lieu sur la mare du bas.' );
		$this->conversations->post( $conversation, $paul, 'Je passe demain, garde-moi la fiche.' );

		$conversationId = $conversation->getId();
		$firstId        = $first->getId();

		$this->anonymize();

		$messages = $this->manager->getRepository( PrivateMessage::class )
								  ->findBy( [ 'conversation' => $conversationId ] );

		$this->assertCount(
				2,
				$messages,
				'Assert the copy still carries conversations to try the messaging out on'
		);

		$rewritten = $this->reload( PrivateMessage::class, $firstId );

		$this->assertNotEquals(
				'Le comptage a eu lieu sur la mare du bas.',
				$rewritten->getBody(),
				'Assert what was written in private does not travel with the copy'
		);

		$this->assertNotEmpty(
				$rewritten->getBody(),
				'Assert a message keeps a body: an empty thread would look like a bug, not like a copy'
		);
	}

	/**
	 * Ce qui reste après le passage : qui a parlé à qui, et quand. C'est ce
	 * qui fait qu'une copie sert encore à quelque chose.
	 */
	public function testWhoWroteToWhomAndWhenIsKept () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversations->open( $jeanne, [ $paul ] );
		$message      = $this->conversations->post( $conversation, $jeanne, 'Bonjour Paul.' );

		$messageId = $message->getId();
		$authorId  = $jeanne->getId();
		$writtenAt = $message->getCreatedAt()->format( 'Y-m-d H:i:s' );

		$this->anonymize();

		$rewritten = $this->reload( PrivateMessage::class, $messageId );

		$this->assertEquals(
				$authorId,
				$rewritten->getAuthor()->getId(),
				'Assert the message is still attributed to the account that wrote it'
		);

		$this->assertEquals(
				$writtenAt,
				$rewritten->getCreatedAt()->format( 'Y-m-d H:i:s' ),
				'Assert the order of a thread survives, or nothing of it can be read'
		);
	}

	/**
	 * Un message effacé n'a plus de texte : lui en rendre un ferait
	 * réapparaître, sur la copie, quelque chose que son auteur avait retiré.
	 */
	public function testADeletedMessageIsNotGivenWordsBack () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversations->open( $jeanne, [ $paul ] );
		$message      = $this->conversations->post( $conversation, $jeanne, 'Message écrit trop vite.' );

		$this->conversations->delete( $message );

		$messageId = $message->getId();

		$this->anonymize();

		$deleted = $this->reload( PrivateMessage::class, $messageId );

		$this->assertTrue( $deleted->isDeleted(), 'Assert a deleted message stays deleted' );
		$this->assertSame( '', (string) $deleted->getBody(), 'Assert nothing is written back into it' );
	}

	/**************************************************
	 * LES SIGNALEMENTS
	 *
	 * Le signalement recopie le message et ceux qui le précédaient : c'est
	 * une seconde copie du même texte, qu'il faut nettoyer aussi. L'oublier
	 * reviendrait à laisser dans la base ce qu'on croit en avoir retiré.
	 **************************************************/

	public function testAReportKeepsNoCopyOfWhatWasSaid () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversations->open( $jeanne, [ $paul ] );
		$this->conversations->post( $conversation, $paul, 'Ce qui précédait le message signalé.' );
		$message = $this->conversations->post( $conversation, $jeanne, 'Le message que Paul signale.' );

		$report   = $this->conversations->report( $message, $paul, 'Propos déplacés.' );
		$reportId = $report->getId();

		$this->anonymize();

		$cleaned = $this->reload( MessageReport::class, $reportId );

		$this->assertNotEquals(
				'Le message que Paul signale.',
				$cleaned->getExcerpt(),
				'Assert the copy carried by the report is rewritten too'
		);

		$this->assertNotEquals(
				'Propos déplacés.',
				$cleaned->getReason(),
				'Assert what the reporter wrote is rewritten as well'
		);

		foreach ( $cleaned->getContext() as $entry ) {
			$this->assertNotEquals(
					'Ce qui précédait le message signalé.',
					isset( $entry[ 'body' ] ) ? $entry[ 'body' ] : NULL,
					'Assert the messages copied around the reported one are rewritten'
			);
		}
	}

	/**
	 * Le nom recopié au moment du signalement est celui d'avant. Laissé tel
	 * quel, il serait le seul endroit de la copie où l'ancien nom subsiste —
	 * et l'endroit qu'une équipe de modération lit en premier.
	 */
	public function testTheNameCopiedByAReportFollowsTheAccount () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversations->open( $jeanne, [ $paul ] );
		$message      = $this->conversations->post( $conversation, $jeanne, 'Bonjour.' );

		$report   = $this->conversations->report( $message, $paul );
		$reportId = $report->getId();

		$this->assertEquals( 'Jeanne Réserve', $report->getReportedName() );

		$this->anonymize();

		$cleaned = $this->reload( MessageReport::class, $reportId );

		$this->assertNotEquals(
				'Jeanne Réserve',
				$cleaned->getReportedName(),
				'Assert the name kept by the report does not outlive the account it names'
		);

		$this->assertEquals(
				$cleaned->getReported()->getName(),
				$cleaned->getReportedName(),
				'Assert the report reads the same name as the account it points at'
		);
	}

	/**************************************************
	 * CE QUI RESTE DEBOUT
	 *
	 * La copie doit garder la **forme** de la messagerie : les conversations,
	 * leurs participants, la clé du tête-à-tête, les boîtes ouvertes. Seuls
	 * les mots s'en vont. Fermer les boîtes ou vider les fils rendrait la
	 * messagerie inrecettable sur la copie — et c'est pour la recetter qu'on
	 * en fait une.
	 *
	 * À ne pas confondre avec la suppression d'un compte par son titulaire,
	 * qui elle le sort de ses conversations : voir UserAnonymizeMessagingTest.
	 **************************************************/

	public function testTheConversationsAreLeftStanding () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation   = $this->conversations->open( $jeanne, [ $paul ] );
		$conversationId = $conversation->getId();
		$jeanneId       = $jeanne->getId();
		$paulId         = $paul->getId();

		$this->anonymize();

		$conversation = $this->reload( Conversation::class, $conversationId );

		$this->assertTrue(
				$conversation->includes( $this->reload( User::class, $jeanneId ) ),
				'Assert the copy still has somebody to try the messaging out with'
		);

		$this->assertTrue(
				$conversation->includes( $this->reload( User::class, $paulId ) ),
				'Assert both sides of the thread are still there'
		);

		$this->assertNotNull(
				$conversation->getPairKey(),
				'Assert the one-to-one keeps its key: it is what makes it one letterbox and not two'
		);
	}

	public function testTheBoxesStayOpen () {
		$jeanne   = $this->user( 'Jeanne Réserve' );
		$jeanneId = $jeanne->getId();

		$this->anonymize();

		$this->assertTrue(
				$this->reload( User::class, $jeanneId )->isMessagesOpen(),
				'Assert nobody has to reopen every box before the copy can be used'
		);
	}

	public function testTheParticipantsKeepWhatTheyHadRead () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversations->open( $jeanne, [ $paul ] );
		$this->conversations->post( $conversation, $jeanne, 'Bonjour Paul.' );
		$this->conversations->markRead( $conversation, $paul );

		$conversationId = $conversation->getId();
		$paulId         = $paul->getId();

		$this->anonymize();

		$participant = $this->manager->getRepository( ConversationParticipant::class )
									 ->findOneBy( [ 'conversation' => $conversationId, 'user' => $paulId ] );

		$this->assertNotNull(
				$participant,
				'Assert nobody is taken out of the conversations they belong to'
		);

		$this->assertNotNull(
				$participant->getLastReadAt(),
				'Assert a read conversation stays read, unread stays unread — the badge is worth trying'
		);
	}

	/**
	 * Le texte inventé est tiré de l'identifiant du message : deux passages
	 * sur le même dump donnent le même résultat, et une copie déjà nettoyée
	 * peut l'être encore sans que tout change.
	 */
	public function testRunningItTwiceRewritesTheSameThing () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversations->open( $jeanne, [ $paul ] );
		$message      = $this->conversations->post( $conversation, $jeanne, 'Bonjour Paul.' );
		$messageId    = $message->getId();

		$this->anonymize();
		$first = $this->reload( PrivateMessage::class, $messageId )->getBody();

		$this->anonymize();
		$second = $this->reload( PrivateMessage::class, $messageId )->getBody();

		$this->assertEquals( $first, $second, 'Assert a second pass does not churn the whole copy' );
	}
}
