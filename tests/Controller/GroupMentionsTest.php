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
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #37 — les mentions vues du navigateur : la liste de noms que
 * l'éditeur va chercher après un « @ », et le lien que le message affiche
 * une fois posté.
 *
 * Le rapprochement texte/membre est vérifié à part, par MentionParserTest ;
 * ce qui est éprouvé ici, c'est le câblage — qui a le droit d'obtenir la
 * liste, ce qu'elle contient, et le fait que le gabarit passe bien par le
 * filtre.
 */
class GroupMentionsTest extends WebTestCase {
	private const FIREWALL = 'main';

	/**
	 * @var \Symfony\Bundle\FrameworkBundle\KernelBrowser
	 */
	private $client;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Entity\Usergroup
	 */
	private $group;

	protected function setUp (): void {
		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$this->group = new Usergroup();
		$this->group->setSlug( 'mentions-group-' . uniqid() );
		$this->group->setName( 'Test group' );
		$this->group->setVisibility( Usergroup::PUBLIC );
		$this->group->setCreatedAt( new DateTime() );
		$this->group->setIsActive( TRUE );
		$this->manager->persist( $this->group );
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		parent::tearDown();
	}

	/**
	 * @param string      $name
	 * @param string|null $membership one of UsergroupMembership::STATUS_*, NULL to stay outside
	 * @param int         $status     one of User::STATUS_*
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $name, $membership = UsergroupMembership::STATUS_MEMBER, $status = User::STATUS_ACTIVE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( $name );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( $status );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $user );

		if ( $membership !== NULL ) {
			$link = new UsergroupMembership();
			$link->setUser( $user );
			$link->setUsergroup( $this->group );
			$link->setStatus( $membership );
			$link->setRole( UsergroupMembership::ROLE_USER );
			$link->setJoinedAt( new DateTime() );
			$this->manager->persist( $link );
			$this->group->addMember( $link );
		}

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
	 * @return array
	 */
	private function mentionables () {
		$this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/mentions' );

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );

		return json_decode( $this->client->getResponse()->getContent(), TRUE );
	}

	/**
	 * @param array $list
	 *
	 * @return string[]
	 */
	private function names ( array $list ) {
		return array_map( function ( $entry ) {
			return $entry[ 'name' ];
		}, $list );
	}

	/**
	 * @param \App\Entity\User $author
	 * @param string           $body
	 *
	 * @return \App\Entity\Discussion
	 */
	private function discussion ( User $author, $body ) {
		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'Rencontre annuelle' );
		$discussion->setUsergroup( $this->group );
		$discussion->setAuthor( $author );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $discussion );

		$message = new DiscussionMessage();
		$message->setDiscussion( $discussion );
		$message->setAuthor( $author );
		$message->setBody( $body );
		$message->setCreatedAt( new DateTime() );
		$this->manager->persist( $message );
		$discussion->addMessage( $message );

		$this->manager->flush();

		return $discussion;
	}

	/**************************************************
	 * LA LISTE PROPOSÉE PAR L'ÉDITEUR
	 **************************************************/

	public function testAMemberGetsTheNamesOfTheGroup () {
		$this->user( 'Jeanne Reserve' );

		$this->logIn( $this->user( 'Paul Marais' ) );

		$this->assertContains( 'Jeanne Reserve', $this->names( $this->mentionables() ) );
	}

	public function testTheNamesComeWithTheirIdentifier () {
		$jeanne = $this->user( 'Jeanne Reserve' );

		$this->logIn( $this->user( 'Paul Marais' ) );

		$found = array_values( array_filter( $this->mentionables(), function ( $entry ) {
			return $entry[ 'name' ] === 'Jeanne Reserve';
		} ) );

		$this->assertEquals( $jeanne->getId(), $found[ 0 ][ 'id' ] );
	}

	public function testSomebodyOutsideTheGroupIsNotProposed () {
		$this->user( 'Paul Ailleurs', NULL );

		$this->logIn( $this->user( 'Paul Marais' ) );

		$this->assertNotContains( 'Paul Ailleurs', $this->names( $this->mentionables() ) );
	}

	public function testAPendingMemberIsNotProposed () {
		$this->user( 'Camille Candidate', UsergroupMembership::STATUS_PENDING );

		$this->logIn( $this->user( 'Paul Marais' ) );

		$this->assertNotContains(
				'Camille Candidate',
				$this->names( $this->mentionables() ),
				'Assert somebody still waiting at the door cannot be called into the room'
		);
	}

	public function testADisabledAccountIsNotProposed () {
		$this->user( 'Compte Fermé', UsergroupMembership::STATUS_MEMBER, User::STATUS_DISABLED );

		$this->logIn( $this->user( 'Paul Marais' ) );

		$this->assertNotContains( 'Compte Fermé', $this->names( $this->mentionables() ) );
	}

	public function testTheNamesAreSorted () {
		$this->user( 'Zoé Zone' );
		$this->user( 'Anne Aulne' );

		$this->logIn( $this->user( 'Paul Marais' ) );

		$names  = $this->names( $this->mentionables() );
		$sorted = $names;
		usort( $sorted, function ( $left, $right ) {
			return strcmp( mb_strtolower( $left ), mb_strtolower( $right ) );
		} );

		$this->assertEquals( $sorted, $names, 'Assert the editor proposes a list one can read' );
	}

	public function testSomebodyWhoCannotWriteInTheGroupGetsNothing () {
		$this->user( 'Jeanne Reserve' );

		$this->logIn( $this->user( 'Éric Extérieur', NULL ) );

		$this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/mentions' );

		$this->assertEquals(
				403,
				$this->client->getResponse()->getStatusCode(),
				'Assert the mention does not become a way of finding out who is a member'
		);
	}

	public function testTheEditorIsToldWhereToAskForTheNames () {
		$this->logIn( $this->user( 'Paul Marais' ) );

		$crawler = $this->client->request( 'GET', '/groups/' . $this->group->getSlug() . '/discussions/new' );

		$this->assertEquals(
				1,
				$crawler->filter( '.wysiwyg-editor[data-mentions$="/mentions"]' )->count()
		);
	}

	/**************************************************
	 * LA MENTION UNE FOIS POSTÉE
	 **************************************************/

	public function testAMentionBecomesALinkToTheDirectory () {
		$jeanne = $this->user( 'Jeanne Reserve' );
		$paul   = $this->user( 'Paul Marais' );

		$discussion = $this->discussion( $paul, '<p>Bonjour @Jeanne Reserve, une idée ?</p>' );

		$this->logIn( $paul );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/' . $discussion->getUuid()
		);

		$link = $crawler->filter( '.message--body a.mention' );

		$this->assertEquals( 1, $link->count(), 'Assert the template goes through the mentions filter' );
		$this->assertEquals( '@Jeanne Reserve', $link->text() );
		$this->assertStringEndsWith( '/members/' . $jeanne->getId(), $link->attr( 'href' ) );
	}

	public function testAnUnknownNameStaysPlainText () {
		$paul = $this->user( 'Paul Marais' );

		$discussion = $this->discussion( $paul, '<p>Bonjour @Personne Inconnue</p>' );

		$this->logIn( $paul );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/' . $discussion->getUuid()
		);

		$this->assertEquals( 0, $crawler->filter( '.message--body a.mention' )->count() );
		$this->assertStringContainsString(
				'@Personne Inconnue',
				$crawler->filter( '.message--body' )->text(),
				'Assert a mention that matches nobody stays readable as the text it always was'
		);
	}

	public function testAMessageWithoutMentionIsLeftAlone () {
		$paul = $this->user( 'Paul Marais' );

		$discussion = $this->discussion( $paul, '<p>Bonjour tout le monde</p>' );

		$this->logIn( $paul );

		$crawler = $this->client->request(
				'GET',
				'/groups/' . $this->group->getSlug() . '/discussions/' . $discussion->getUuid()
		);

		$this->assertEquals( 0, $crawler->filter( '.message--body a.mention' )->count() );
		$this->assertStringContainsString( 'Bonjour tout le monde', $crawler->filter( '.message--body' )->text() );
	}
}
