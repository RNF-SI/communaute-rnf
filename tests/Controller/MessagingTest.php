<?php

namespace App\Tests\Controller;

use App\Entity\Conversation;
use App\Entity\Document;
use App\Entity\MessageReport;
use App\Entity\Notification;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * La messagerie vue du navigateur.
 *
 * Ce qui est éprouvé ici, ce sont les promesses qu'on ne peut pas tenir avec
 * de la bonne volonté : qu'écrire deux fois à la même personne ne coupe pas la
 * conversation en deux, qu'un tag n'ouvre pas ce que le lecteur n'a pas le
 * droit de lire, qu'une boîte fermée le reste, et qu'un signalement transmet
 * une copie plutôt qu'une clé.
 *
 * La lecture des tags elle-même est éprouvée à part, par TagScannerTest.
 */
class MessagingTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();
		$this->client->followRedirects();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**************************************************
	 * DE QUOI SE PARLER
	 *************************************************/

	/**
	 * @param string $name
	 * @param bool   $open la boîte accepte-t-elle les messages ?
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $name, $open = TRUE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( $name );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$user->setMessagesOpen( $open );

		// Rien ne doit partir par e-mail pendant l'épreuve : ce qui est
		// vérifié ici, c'est la messagerie, pas le transport.
		$user->setDefaultNotificationLevel( NotificationCategory::MESSAGES, NotificationLevel::APP );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $user
	 */
	private function logIn ( User $user ) {
		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * Écrire à quelqu'un, en passant par le formulaire comme le ferait un
	 * membre.
	 *
	 * @param \App\Entity\User $author
	 * @param \App\Entity\User $recipient
	 * @param string           $body
	 *
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	private function writeTo ( User $author, User $recipient, $body ) {
		$crawler = $this->client->request( 'GET', '/messages/new?to=' . $recipient->getId() );

		$form = $crawler->filter( 'form.messages-new--form' )->form();
		$form[ 'body' ] = $body;

		return $this->client->submit( $form );
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return \App\Entity\Conversation[]
	 */
	private function conversationsOf ( User $user ) {
		return $this->manager->getRepository( Conversation::class )->findForUser( $user );
	}

	/**
	 * Un groupe et un document qui y vit.
	 *
	 * @param string $visibility
	 *
	 * @return \App\Entity\Document
	 */
	private function document ( $visibility, $title = 'Tagtest' ) {
		$group = new Usergroup();
		$group->setSlug( 'messaging-group-' . uniqid() );
		$group->setName( 'Groupe ' . uniqid() );
		$group->setVisibility( $visibility );
		$group->setCreatedAt( new DateTime() );
		$group->setIsActive( TRUE );
		$this->manager->persist( $group );

		// Un titre que rien d'autre ne porte : les épreuves cherchent dessus,
		// et les données de test contiennent déjà des « Guide… ».
		$document = new Document();
		$document->setTitle( $title . ' ' . uniqid() );
		$document->setUsergroup( $group );
		$document->setCreatedAt( new DateTime() );
		$this->manager->persist( $document );

		$this->manager->flush();

		return $document;
	}

	/**************************************************
	 * OUVRIR UNE CONVERSATION
	 *************************************************/

	public function testWritingToSomebodyOpensAConversation () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Bonjour Paul, on se voit en commission ?' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$conversations = $this->conversationsOf( $author );

		$this->assertCount( 1, $conversations );
		$this->assertCount( 1, $conversations[ 0 ]->getMessages() );
		$this->assertTrue( $conversations[ 0 ]->includes( $recipient ) );
	}

	/**
	 * Le tête-à-tête est une boîte aux lettres, pas un sujet : y revenir ne
	 * doit pas couper la conversation en deux.
	 */
	public function testWritingTwiceStaysInTheSameConversation () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Premier message' );

		// « Écrire à » une seconde fois ne rouvre pas un formulaire vierge :
		// on retombe dans la conversation qui existe déjà.
		$crawler = $this->client->request( 'GET', '/messages/new?to=' . $recipient->getId() );

		$this->assertCount( 0, $crawler->filter( 'form.messages-new--form' ) );

		$form           = $crawler->filter( 'form.thread--reply' )->form();
		$form[ 'body' ] = 'Second message';
		$this->client->submit( $form );

		$conversations = $this->conversationsOf( $author );

		$this->assertCount( 1, $conversations );
		$this->assertCount( 2, $this->manager->getRepository( PrivateMessage::class )
											 ->findForConversation( $conversations[ 0 ] ) );
	}

	public function testTheOtherFindsItWaiting () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Bonjour Paul' );

		$repository = $this->manager->getRepository( Conversation::class );

		$this->assertSame( 1, $repository->countUnread( $recipient ) );

		// Ce qu'on vient d'écrire soi-même n'attend pas d'être lu.
		$this->assertSame( 0, $repository->countUnread( $author ) );
	}

	public function testOpeningAConversationMarksItRead () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Bonjour Paul' );

		$conversation = $this->conversationsOf( $author )[ 0 ];

		$this->logIn( $recipient );
		$this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$this->manager->clear();

		$this->assertSame(
				0,
				$this->manager->getRepository( Conversation::class )
							  ->countUnread( $this->manager->getRepository( User::class )->find( $recipient->getId() ) )
		);
	}

	/**
	 * Une notification de message privé ne porte aucun groupe — c'est la seule
	 * — et son titre est le nom de celui qui écrit, jamais un extrait.
	 */
	public function testTheNotificationCarriesTheAuthorAndNoGroup () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Un secret qui ne doit pas ressortir' );

		$notifications = $this->manager->getRepository( Notification::class )->findForUser( $recipient );

		$this->assertCount( 1, $notifications );
		$this->assertSame( Notification::MESSAGE_NEW, $notifications[ 0 ]->getType() );
		$this->assertNull( $notifications[ 0 ]->getUsergroup() );
		$this->assertSame( 'Jeanne Réserve', $notifications[ 0 ]->getTitle() );
		$this->assertStringNotContainsString( 'secret', (string) $notifications[ 0 ]->getTitle() );
	}

	/**************************************************
	 * UNE BOÎTE FERMÉE
	 *************************************************/

	public function testAClosedBoxRefusesANewConversation () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin', FALSE );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Bonjour Paul' );

		$this->assertCount( 0, $this->conversationsOf( $author ) );
	}

	/**
	 * Fermer sa boîte ferme la porte, pas les conversations déjà ouvertes :
	 * on continue d'y répondre.
	 */
	public function testAClosedBoxStillReceivesInAConversationAlreadyOpen () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Bonjour Paul' );

		$conversation = $this->conversationsOf( $author )[ 0 ];

		$recipient->setMessagesOpen( FALSE );
		$this->manager->flush();

		$crawler = $this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );
		$form    = $crawler->filter( 'form.thread--reply' )->form();

		$form[ 'body' ] = 'Une relance';
		$this->client->submit( $form );

		$this->assertCount(
				2,
				$this->manager->getRepository( PrivateMessage::class )->findForConversation( $conversation )
		);
	}

	/**************************************************
	 * LES TAGS, ET QUI PEUT LES SUIVRE
	 *************************************************/

	public function testATagTowardsAReadableDocumentIsALink () {
		$document = $this->document( Usergroup::PUBLIC );

		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Regarde #' . $document->getTitle() . ' avant jeudi.' );

		$conversation = $this->conversationsOf( $author )[ 0 ];

		$this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );
		$content = $this->client->getResponse()->getContent();

		$this->assertStringContainsString( 'msg-tag__content', $content );
		$this->assertStringNotContainsString( 'msg-tag__locked', $content );
	}

	/**
	 * Le titre reste — il est dans la phrase de toute façon — mais il ne mène
	 * nulle part pour qui n'a pas le droit de l'ouvrir.
	 */
	public function testATagTowardsAPrivateDocumentLosesItsLink () {
		$document = $this->document( Usergroup::PRIVATE );

		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Regarde #' . $document->getTitle() . ' avant jeudi.' );

		$conversation = $this->conversationsOf( $author )[ 0 ];

		$this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );
		$content = $this->client->getResponse()->getContent();

		$this->assertStringContainsString( 'msg-tag__locked', $content );
		$this->assertStringContainsString( $document->getTitle(), $content );
		$this->assertStringNotContainsString( 'msg-tag__content', $content );
	}

	/**
	 * Le même message, deux lecteurs, deux rendus : c'est pour cela que rien
	 * n'est mis en cache.
	 */
	public function testTheSameMessageReadsDifferentlyForAMember () {
		$document = $this->document( Usergroup::PRIVATE );
		$group    = $document->getUsergroup();

		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$membership = new UsergroupMembership();
		$membership->setUser( $author );
		$membership->setUsergroup( $group );
		$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
		$membership->setRole( UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );
		$this->manager->persist( $membership );
		$group->addMember( $membership );
		$this->manager->flush();

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Regarde #' . $document->getTitle() . '.' );

		$conversation = $this->conversationsOf( $author )[ 0 ];

		// L'auteur en est membre : le lien s'affiche.
		$this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );
		$this->assertStringContainsString( 'msg-tag__content', $this->client->getResponse()->getContent() );

		// Le destinataire ne l'est pas : le tag est gris.
		$this->logIn( $recipient );
		$this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );
		$this->assertStringContainsString( 'msg-tag__locked', $this->client->getResponse()->getContent() );
	}

	public function testTagsAreNotHtml () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Attention <script>alert(1)</script>' );

		$conversation = $this->conversationsOf( $author )[ 0 ];

		$this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $this->client->getResponse()->getContent() );
	}

	/**************************************************
	 * LA LISTE DE SUGGESTIONS
	 *************************************************/

	public function testTheSuggestionsNeverOfferWhatTheWriterCannotOpen () {
		$secret = $this->document( Usergroup::PRIVATE );
		$open   = $this->document( Usergroup::PUBLIC );

		$this->logIn( $this->user( 'Jeanne Réserve' ) );

		$this->client->request( 'GET', '/messages/suggestions?prefix=%23&q=Tagtest' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		$labels = array_column(
				json_decode( $this->client->getResponse()->getContent(), TRUE )[ 'suggestions' ],
				'label'
		);

		$this->assertContains( $open->getTitle(), $labels );
		$this->assertNotContains( $secret->getTitle(), $labels );
	}

	/**************************************************
	 * SIGNALER
	 *************************************************/

	public function testAReportCarriesACopyOfTheMessageAndItsContext () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Le premier message' );

		$conversation = $this->conversationsOf( $author )[ 0 ];

		$crawler        = $this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );
		$form           = $crawler->filter( 'form.thread--reply' )->form();
		$form[ 'body' ] = 'Le second message, celui qui pose problème';
		$this->client->submit( $form );

		$messages = $this->manager->getRepository( PrivateMessage::class )->findForConversation( $conversation );
		$reported = end( $messages );

		$this->logIn( $recipient );

		$crawler = $this->client->request( 'GET', '/messages/message/' . $reported->getId() . '/report' );
		$form    = $crawler->filter( 'form' )->reduce( function ( $node ) {
			return strpos( (string) $node->attr( 'action' ), '/report' ) !== FALSE;
		} )->form();

		$form[ 'reason' ] = 'Ce ton ne va pas.';
		$this->client->submit( $form );

		$reports = $this->manager->getRepository( MessageReport::class )->findForAdmin();

		$this->assertCount( 1, $reports );

		$report = $reports[ 0 ];

		$this->assertSame( 'Le second message, celui qui pose problème', $report->getExcerpt() );
		$this->assertSame( $author->getId(), $report->getReported()->getId() );
		$this->assertSame( 'Jeanne Réserve', $report->getReportedName() );

		// Le contexte est recopié, pas pointé : c'est ce qui permet à
		// l'administration de juger sans ouvrir la conversation.
		$context = $report->getContext();

		$this->assertCount( 1, $context );
		$this->assertSame( 'Le premier message', $context[ 0 ][ 'body' ] );
	}

	/**
	 * Un signalement survit à ce qu'il signale : effacer le message après coup
	 * n'efface pas ce qui a été transmis.
	 */
	public function testDeletingTheMessageDoesNotEmptyTheReport () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Un message regrettable' );

		$conversation = $this->conversationsOf( $author )[ 0 ];
		$messages     = $this->manager->getRepository( PrivateMessage::class )->findForConversation( $conversation );
		$message      = $messages[ 0 ];

		$this->logIn( $recipient );

		$crawler = $this->client->request( 'GET', '/messages/message/' . $message->getId() . '/report' );
		$form    = $crawler->filter( 'form' )->reduce( function ( $node ) {
			return strpos( (string) $node->attr( 'action' ), '/report' ) !== FALSE;
		} )->form();
		$this->client->submit( $form );

		// L'auteur efface son message.
		$this->logIn( $author );

		$crawler = $this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );
		$this->client->submit( $crawler->filter( 'form[action$="/delete"]' )->form() );

		$this->manager->clear();

		$message = $this->manager->getRepository( PrivateMessage::class )->find( $message->getId() );

		$this->assertTrue( $message->isDeleted() );
		$this->assertSame( '', (string) $message->getBody() );

		$report = $this->manager->getRepository( MessageReport::class )->findForAdmin()[ 0 ];

		$this->assertSame( 'Un message regrettable', $report->getExcerpt() );
	}

	/**************************************************
	 * QUITTER
	 *************************************************/

	public function testLeavingKeepsWhatWasWrittenForTheOthers () {
		$author    = $this->user( 'Jeanne Réserve' );
		$recipient = $this->user( 'Paul Martin' );

		$this->logIn( $author );
		$this->writeTo( $author, $recipient, 'Bonjour Paul' );

		$conversation = $this->conversationsOf( $author )[ 0 ];

		$crawler = $this->client->request( 'GET', '/messages?conversation=' . $conversation->getId() );
		$this->client->submit( $crawler->filter( 'form[action$="/leave"]' )->form() );

		$this->manager->clear();

		$conversation = $this->manager->getRepository( Conversation::class )->find( $conversation->getId() );
		$author       = $this->manager->getRepository( User::class )->find( $author->getId() );
		$recipient    = $this->manager->getRepository( User::class )->find( $recipient->getId() );

		$this->assertFalse( $conversation->includes( $author ) );
		$this->assertTrue( $conversation->includes( $recipient ) );
		$this->assertCount(
				1,
				$this->manager->getRepository( PrivateMessage::class )->findForConversation( $conversation )
		);

		// Le tête-à-tête quitté n'est plus une boîte aux lettres : l'autre
		// doit pouvoir réécrire.
		$this->assertNull( $conversation->getPairKey() );
	}
}
