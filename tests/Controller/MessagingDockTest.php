<?php

namespace App\Tests\Controller;

use App\Entity\Conversation;
use App\Entity\Notification;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Service\ConversationManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Le dock de la messagerie, vu du navigateur.
 *
 * Ce qui est éprouvé ici, ce sont les promesses qu'une seconde porte d'entrée
 * pourrait discrètement trahir :
 *
 * - **elle ne s'ouvre pas plus largement que la première.** Une conversation
 *   dont on n'est pas se refuse par la route JSON comme par la page, y compris
 *   à un administrateur — ConversationVoter ne court-circuite pas.
 * - **le sondage ne raconte que ce qu'on a le droit d'entendre**, et ne rend
 *   les messages que des fils qu'on lui dit tenir ouverts.
 * - **une fenêtre repliée ne marque rien comme lu.** Le dock rouvre ses
 *   fenêtres à chaque page ; sans cette réserve, les messages arrivés
 *   pendant qu'elle était réduite disparaîtraient du compteur sans que
 *   personne les ait regardés.
 * - **les compteurs disent la même chose que l'en-tête**, un message privé
 *   n'étant compté qu'une fois — il ouvre pourtant deux lignes en base.
 */
class MessagingDockTest extends WebTestCase {
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
	 * @param bool   $admin
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $name, $admin = FALSE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( $name );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( $admin ? [ 'ROLE_USER', 'ROLE_ADMIN' ] : [ 'ROLE_USER' ] );
		$user->setMessagesOpen( TRUE );

		// Rien ne doit partir par e-mail : ce qui est vérifié ici, c'est le
		// dock, pas le transport.
		$user->setDefaultNotificationLevel( NotificationCategory::MESSAGES, NotificationLevel::APP );

		$this->manager->persist( $user );
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return void
	 */
	private function logIn ( User $user ) {
		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * Une conversation entre ces deux-là, avec un message dedans.
	 *
	 * Passe par le service et non par la base à la main : c'est lui qui pose
	 * la date d'activité, la lecture de celui qui écrit et la notification, et
	 * un fil monté autrement ne ressemblerait à aucun fil réel.
	 *
	 * @param \App\Entity\User $author
	 * @param \App\Entity\User $other
	 * @param string           $body
	 *
	 * @return \App\Entity\Conversation
	 */
	private function conversation ( User $author, User $other, $body = 'Bonjour.' ) {
		$conversations = self::$container->get( ConversationManager::class );

		$conversation = $conversations->open( $author, [ $other ] );

		$conversations->post( $conversation, $author, $body );

		return $conversation;
	}

	/**
	 * @return array la charge JSON de la dernière réponse
	 */
	private function answer () {
		return json_decode( $this->client->getResponse()->getContent(), TRUE );
	}

	/**
	 * Le jeton de la messagerie, pris là où le navigateur le prend : dans une
	 * page rendue. Le tirer du conteneur passerait à côté du seul cas qui
	 * compte — celui où le jeton servi à la page et celui attendu par la
	 * route ne viennent pas de la même session.
	 *
	 * @return string
	 */
	private function token () {
		$crawler = $this->client->request( 'GET', '/messages/new' );

		return $crawler->filter( 'input[name="_token"]' )->first()->attr( 'value' );
	}

	/**************************************************
	 * QUI PEUT ÉCOUTER
	 *************************************************/

	public function testTheLiveRouteRefusesAnonymousVisitors () {
		$this->client->request( 'GET', '/messages/live' );

		$this->assertNotSame( 200, $this->client->getResponse()->getStatusCode() );
	}

	public function testAThreadOneIsNotPartOfIsRefused () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );
		$tiers  = $this->user( 'Camille Dune' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $tiers );
		$this->client->request( 'GET', '/messages/dock/thread/' . $conversation->getId() );

		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
	}

	/**
	 * Le point à ne pas « corriger » en relisant : l'équipe RNF modère partout
	 * dans les groupes, mais une conversation privée n'est pas un groupe. Ce
	 * qu'un administrateur peut lire d'un échange privé, il le lit dans un
	 * signalement — une copie que quelqu'un lui a transmise.
	 */
	public function testAnAdministratorIsRefusedLikeAnybodyElse () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );
		$chef   = $this->user( 'Alex Équipe', TRUE );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $chef );
		$this->client->request( 'GET', '/messages/dock/thread/' . $conversation->getId() );

		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
	}

	public function testWritingInSomebodyElsesThreadIsRefused () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );
		$tiers  = $this->user( 'Camille Dune' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $tiers );
		$this->client->request( 'POST', '/messages/dock/send/' . $conversation->getId(), [
				'_token' => $this->token(),
				'body'   => 'Je passais par là.',
		] );

		$this->assertSame( 403, $this->client->getResponse()->getStatusCode() );
	}

	public function testSendingWithoutTheTokenIsRefused () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'POST', '/messages/dock/send/' . $conversation->getId(), [
				'_token' => 'ceci-n-est-pas-un-jeton',
				'body'   => 'Bonjour.',
		] );

		$this->assertSame( 400, $this->client->getResponse()->getStatusCode() );
	}

	/**************************************************
	 * CE QUE LE SONDAGE RAPPORTE
	 *************************************************/

	public function testTheFirstPollHandsBackTheList () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/live?full=1' );

		$answer = $this->answer();

		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertCount( 1, $answer[ 'conversations' ] );
		$this->assertSame( 'Jeanne Réserve', $answer[ 'conversations' ][ 0 ][ 'title' ] );
		$this->assertTrue( $answer[ 'conversations' ][ 0 ][ 'unread' ] );
	}

	/**
	 * Le tour ordinaire, celui qui part toutes les trois secondes : deux
	 * compteurs, et rien d'autre. C'est ce qui rend le sondage tenable.
	 */
	public function testAQuietPollCarriesNothingButTheCounts () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/live' );

		$answer = $this->answer();

		$this->assertArrayHasKey( 'counts', $answer );
		$this->assertArrayNotHasKey( 'conversations', $answer );
		$this->assertArrayNotHasKey( 'messages', $answer );
	}

	/**
	 * Les messages ne repartent que pour les fils que le dock dit tenir
	 * ouverts : ailleurs, la liste suffit à faire remonter la conversation.
	 */
	public function testMessagesComeBackOnlyForTheThreadsTheDockHoldsOpen () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$conversation = $this->conversation( $jeanne, $pierre, 'Premier.' );

		$this->logIn( $pierre );

		// Pierre se cale sur ce qui existe déjà.
		$this->client->request( 'GET', '/messages/live?full=1' );
		$cursor = $this->answer()[ 'cursor' ];

		// Jeanne écrit à nouveau.
		self::$container->get( ConversationManager::class )
						->post( $conversation, $jeanne, 'Second.' );

		$this->client->request( 'GET', '/messages/live?since=' . $cursor );
		$this->assertArrayNotHasKey( 'messages', $this->answer() );

		$this->client->request( 'GET', '/messages/live?since=' . $cursor . '&threads=' . $conversation->getId() );
		$answer = $this->answer();

		$this->assertArrayHasKey( 'messages', $answer );
		$this->assertCount( 1, $answer[ 'messages' ][ $conversation->getId() ] );
		$this->assertStringContainsString( 'Second.', $answer[ 'messages' ][ $conversation->getId() ][ 0 ][ 'html' ] );
	}

	/**
	 * Déclarer le fil de quelqu'un d'autre ne le donne pas à lire : le
	 * paramètre vient du navigateur, et rien n'empêcherait d'y écrire
	 * n'importe quel identifiant.
	 */
	public function testDeclaringSomebodyElsesThreadHandsBackNothing () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );
		$tiers  = $this->user( 'Camille Dune' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $tiers );
		$this->client->request( 'GET', '/messages/live?full=1&since=1&threads=' . $conversation->getId() );

		$answer = $this->answer();

		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertArrayNotHasKey( 'messages', $answer );
		$this->assertSame( [], $answer[ 'conversations' ] );
	}

	/**
	 * Un curseur absent ne rejoue pas l'historique : un dock qui s'ouvre se
	 * cale sur le dernier message et écoute la suite.
	 */
	public function testAPollWithoutACursorDoesNotReplayTheHistory () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/live?threads=' . $conversation->getId() );

		$this->assertArrayNotHasKey( 'messages', $this->answer() );
	}

	/**************************************************
	 * LIRE, ET NE PAS LIRE
	 *************************************************/

	public function testOpeningAThreadReadsIt () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/dock/thread/' . $conversation->getId() );

		$this->assertSame( 0, $this->answer()[ 'counts' ][ 'messages' ] );
	}

	/**
	 * La fenêtre repliée. Le dock la rouvre à chaque page : la marquer lue
	 * ferait disparaître, sans un regard, tout ce qui est arrivé pendant
	 * qu'elle était réduite dans un coin.
	 */
	public function testAFoldedWindowReadsNothing () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/dock/thread/' . $conversation->getId() . '?read=0' );

		$answer = $this->answer();

		$this->assertCount( 1, $answer[ 'messages' ] );
		$this->assertSame( 1, $answer[ 'counts' ][ 'messages' ] );
	}

	/**************************************************
	 * ÉCRIRE
	 *************************************************/

	public function testSendingFromTheDockPostsInTheSameThread () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'POST', '/messages/dock/send/' . $conversation->getId(), [
				'_token' => $this->token(),
				'body'   => 'Bien reçu.',
		] );

		$answer = $this->answer();

		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertTrue( $answer[ 'message' ][ 'mine' ] );
		$this->assertStringContainsString( 'Bien reçu.', $answer[ 'message' ][ 'html' ] );

		$this->manager->clear();

		$messages = $this->manager->getRepository( PrivateMessage::class )
								  ->findForConversation(
										  $this->manager->getRepository( Conversation::class )
														->find( $conversation->getId() )
								  );

		$this->assertCount( 2, $messages );
	}

	public function testAnEmptyMessageIsRefused () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'POST', '/messages/dock/send/' . $conversation->getId(), [
				'_token' => $this->token(),
				'body'   => '   ',
		] );

		$this->assertSame( 400, $this->client->getResponse()->getStatusCode() );
	}

	/**
	 * Rien n'est créé tant que rien n'est écrit — comme sur la page. Une
	 * fenêtre ouverte sur quelqu'un ne dépose pas une conversation vide dans
	 * sa boîte.
	 */
	public function testOpeningAWindowOnSomebodyCreatesNothing () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$this->logIn( $jeanne );
		$this->client->request( 'GET', '/messages/dock/with/' . $pierre->getId() );

		$answer = $this->answer();

		$this->assertSame( 0, $answer[ 'thread' ] );
		$this->assertSame( 'Pierre Marais', $answer[ 'person' ][ 'name' ] );
		$this->assertCount( 0, $this->manager->getRepository( Conversation::class )->findForUser( $jeanne ) );
	}

	public function testTheWindowFindsTheConversationThatAlreadyExists () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$conversation = $this->conversation( $jeanne, $pierre );

		$this->logIn( $jeanne );
		$this->client->request( 'GET', '/messages/dock/with/' . $pierre->getId() );

		$this->assertSame( $conversation->getId(), $this->answer()[ 'thread' ] );
	}

	public function testAClosedBoxStaysClosedFromTheDock () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$pierre->setMessagesOpen( FALSE );
		$this->manager->flush();

		$this->logIn( $jeanne );
		$this->client->request( 'GET', '/messages/dock/with/' . $pierre->getId() );

		$this->assertSame( 400, $this->client->getResponse()->getStatusCode() );

		$this->client->request( 'POST', '/messages/dock/start/' . $pierre->getId(), [
				'_token' => $this->token(),
				'body'   => 'Bonjour quand même.',
		] );

		$this->assertSame( 400, $this->client->getResponse()->getStatusCode() );
	}

	public function testTheFirstMessageOpensTheConversation () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$this->logIn( $jeanne );
		$this->client->request( 'POST', '/messages/dock/start/' . $pierre->getId(), [
				'_token' => $this->token(),
				'body'   => 'Premier mot.',
		] );

		$answer = $this->answer();

		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertNotSame( 0, $answer[ 'thread' ] );
		$this->assertCount( 1, $this->manager->getRepository( Conversation::class )->findForUser( $jeanne ) );
	}

	/**************************************************
	 * LES COMPTEURS
	 *************************************************/

	/**
	 * Un message privé ouvre deux lignes en base : une conversation non lue,
	 * et une notification de type `message:new`. La pastille de l'en-tête
	 * additionne les deux compteurs — elle dirait donc « 2 » pour un seul
	 * message si le compteur des notifications ne l'écartait pas.
	 */
	public function testAPrivateMessageIsCountedOnce () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/live' );

		$counts = $this->answer()[ 'counts' ];

		// La notification existe bien : c'est le compteur qui l'écarte, pas
		// l'envoi qui l'oublie.
		$this->assertCount(
				1,
				$this->manager->getRepository( Notification::class )
							  ->findBy( [ 'recipient' => $pierre, 'type' => Notification::MESSAGE_NEW ] )
		);

		$this->assertSame( 1, $counts[ 'messages' ] );
		$this->assertSame( 0, $counts[ 'notifications' ] );
		$this->assertSame( 1, $counts[ 'total' ] );
	}

	/**
	 * Le nombre des discussions détaille celui des notifications, il ne s'y
	 * ajoute pas : rien en base ne suit la lecture d'une discussion de groupe,
	 * et le seul repère qui existe est la notification.
	 */
	public function testDiscussionsDetailTheNotificationsRatherThanAddToThem () {
		$pierre = $this->user( 'Pierre Marais' );

		$notification = new Notification();
		$notification->setRecipient( $pierre );
		$notification->setType( Notification::DISCUSSION_MESSAGE );
		$notification->setTitle( 'Un sujet' );
		$notification->setUrl( '/groups/x/discussions/1' );
		$notification->setCreatedAt( new DateTime() );

		$this->manager->persist( $notification );
		$this->manager->flush();

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/live' );

		$counts = $this->answer()[ 'counts' ];

		$this->assertSame( 1, $counts[ 'notifications' ] );
		$this->assertSame( 1, $counts[ 'discussions' ] );
		$this->assertSame( 1, $counts[ 'total' ] );
	}

	/**
	 * Ce que le sondage annonce en passant : tout sauf un message privé, que
	 * le dock montre déjà. L'annoncer par-dessus ferait deux bulles pour une
	 * phrase reçue.
	 */
	public function testAPrivateMessageIsNeverAnnouncedTwice () {
		$jeanne = $this->user( 'Jeanne Réserve' );
		$pierre = $this->user( 'Pierre Marais' );

		$this->conversation( $jeanne, $pierre );

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/live?notice=1' );

		$answer = $this->answer();

		$this->assertArrayNotHasKey( 'notifications', $answer );
		$this->assertGreaterThan( 1, $answer[ 'notice' ] );
	}

	/**************************************************
	 * CE QUI NE DOIT PAS FUIR
	 *************************************************/

	/**
	 * Un sondage renvoie l'état d'une boîte privée. Le laisser passer dans un
	 * cache partagé le donnerait à lire au suivant.
	 */
	public function testTheLiveAnswerIsNeverCached () {
		$pierre = $this->user( 'Pierre Marais' );

		$this->logIn( $pierre );
		$this->client->request( 'GET', '/messages/live' );

		$control = $this->client->getResponse()->headers->get( 'Cache-Control' );

		$this->assertStringContainsString( 'private', $control );
		$this->assertStringContainsString( 'no-store', $control );
	}
}
