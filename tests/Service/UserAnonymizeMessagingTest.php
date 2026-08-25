<?php

namespace App\Tests\Service;

use App\Entity\ConversationParticipant;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Service\ConversationManager;
use App\Service\UserAnonymize;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ce que devient la messagerie quand quelqu'un fait supprimer son compte.
 *
 * Deux anonymisations existent, et elles ne font pas la même chose. Celle-ci
 * est celle d'un compte, demandée depuis la plateforme ; l'autre est celle
 * d'une copie de la base entière, par `app:db:anonymize`, qui réécrit le texte
 * des messages mais laisse les conversations debout — voir
 * AnonymizeMessagingTest. Les confondre en relisant l'une ou l'autre ferait
 * soit fuiter des messages, soit vider une copie de ce qu'on voulait y
 * recetter.
 *
 * Ici, trois choses tiennent ensemble :
 *
 * - **ce qui a été écrit reste**, comme pour un message de discussion :
 *   l'effacer trouerait le fil de ceux qui restent ;
 * - **le compte sort de ses conversations** et ferme sa boîte, de sorte que
 *   plus personne ne lui écrit et qu'il ne reçoit plus rien ;
 * - **la clé du tête-à-tête est rendue**. C'est le point qu'on oublie : elle
 *   porte une contrainte d'unicité en base, et laissée derrière un compte
 *   sorti, elle interdirait à l'autre d'ouvrir une conversation avec qui
 *   reprend le poste — une erreur qui ne se verrait qu'au moment où quelqu'un
 *   essaierait d'écrire.
 */
class UserAnonymizeMessagingTest extends KernelTestCase {
	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Service\UserAnonymize
	 */
	private $anonymize;

	/**
	 * @var \App\Service\ConversationManager
	 */
	private $conversations;

	protected function setUp (): void {
		self::bootKernel();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->anonymize     = self::$container->get( UserAnonymize::class );
		$this->conversations = self::$container->get( ConversationManager::class );
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
		$user->setEmail( uniqid() . '@example.org' );
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
	 * Un tête-à-tête où chacun a parlé.
	 *
	 * Il est ouvert par le service et non à la main : c'est lui qui pose la
	 * clé du tête-à-tête, et c'est elle qu'on veut voir rendue ensuite.
	 *
	 * @param \App\Entity\User $one
	 * @param \App\Entity\User $other
	 *
	 * @return \App\Entity\Conversation
	 */
	private function conversation ( User $one, User $other ) {
		$conversation = $this->conversations->open( $one, [ $other ] );

		$this->conversations->post( $conversation, $one, 'Bonjour, une question sur le protocole.' );
		$this->conversations->post( $conversation, $other, 'Je regarde et je te réponds demain.' );

		return $conversation;
	}

	/**
	 * Le service n'écrit pas de lui-même : c'est l'appelant qui enregistre.
	 *
	 * @param \App\Entity\User $user
	 */
	private function anonymiseAccount ( User $user ) {
		$this->anonymize->anonymize( $user );
		$this->manager->flush();
	}

	public function testWhatWasWrittenStaysWhereItWas () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversation( $jeanne, $paul );

		$this->anonymiseAccount( $jeanne );

		$messages = $this->manager->getRepository( PrivateMessage::class )
								  ->findBy( [ 'conversation' => $conversation->getId() ] );

		$this->assertCount(
				2,
				$messages,
				'Assert the thread of whoever stays is not left with holes in it'
		);

		$bodies = array_map( function ( PrivateMessage $message ) {
			return $message->getBody();
		}, $messages );

		$this->assertContains(
				'Bonjour, une question sur le protocole.',
				$bodies,
				'Assert a departing account leaves its messages behind, like a discussion message'
		);
	}

	public function testTheAccountLeavesItsConversations () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversation( $jeanne, $paul );

		$this->anonymiseAccount( $jeanne );

		$this->assertFalse(
				$conversation->includes( $jeanne ),
				'Assert the account is out of the thread'
		);

		$this->assertTrue(
				$conversation->includes( $paul ),
				'Assert the other one is still there, with the thread intact'
		);
	}

	public function testTheBoxIsClosed () {
		$jeanne = $this->user( 'Jeanne Réserve' );

		$this->anonymiseAccount( $jeanne );

		$this->assertFalse(
				$jeanne->isMessagesOpen(),
				'Assert nobody can start a conversation with an account that no longer reads anything'
		);
	}

	public function testThePairKeyIsHandedBack () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversation( $jeanne, $paul );

		$this->assertNotNull(
				$conversation->getPairKey(),
				'Assert the one-to-one carried its key to begin with'
		);

		$this->anonymiseAccount( $jeanne );

		$this->assertNull(
				$conversation->getPairKey(),
				'Assert a conversation can be opened again with whoever takes the job over'
		);
	}

	/**
	 * Un fil à plusieurs n'a jamais porté de clé de tête-à-tête, et il ne doit
	 * pas en gagner une : le départ d'un participant n'en fait pas un
	 * tête-à-tête entre les deux qui restent.
	 */
	public function testAThreadWithSeveralPeopleKeepsGoingWithoutOne () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );
		$sophie = $this->user( 'Sophie Collègue' );

		$conversation = $this->conversations->open( $jeanne, [ $paul, $sophie ] );
		$this->conversations->post( $conversation, $jeanne, 'Bonjour à vous deux.' );

		$this->assertNull(
				$conversation->getPairKey(),
				'Assert a thread of three never carried a one-to-one key'
		);

		$this->anonymiseAccount( $jeanne );

		$this->assertCount(
				2,
				$conversation->getActiveUsers(),
				'Assert the two who stay keep their thread'
		);

		$this->assertNull(
				$conversation->getPairKey(),
				'Assert a departure does not turn what is left into a one-to-one'
		);
	}

	/**
	 * Le départ est daté une fois pour toutes. Une deuxième passe ne doit pas
	 * repousser la date : la commande qui anonymise une copie se relance, et
	 * un compte déjà sorti doit être laissé tel quel.
	 */
	public function testASecondPassDoesNotMoveTheDeparture () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$paul   = $this->user( 'Paul Collègue' );

		$conversation = $this->conversation( $jeanne, $paul );

		$this->anonymiseAccount( $jeanne );

		$participant = $this->manager->getRepository( ConversationParticipant::class )
									 ->findOneBy( [ 'conversation' => $conversation->getId(), 'user' => $jeanne->getId() ] );

		$leftAt = $participant->getLeftAt();

		$this->assertNotNull( $leftAt, 'Assert the departure was recorded on the first pass' );

		$this->anonymiseAccount( $jeanne );

		$this->assertEquals(
				$leftAt->format( 'Y-m-d H:i:s' ),
				$participant->getLeftAt()->format( 'Y-m-d H:i:s' ),
				'Assert an account already out of its conversations is left alone'
		);
	}
}
