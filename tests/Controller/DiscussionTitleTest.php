<?php

namespace App\Tests\Controller;

use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\LogEvent;
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
 * Renommer un sujet : l'auteur qui a posé le titre, ou un animateur du groupe
 * quand l'intitulé ne dit plus ce dont on parle.
 */
class DiscussionTitleTest extends WebTestCase {
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
		$this->group->setSlug( 'rename-test-group' );
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
	 * @param string $role
	 * @param bool   $isMember
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $role = UsergroupMembership::ROLE_USER, $isMember = TRUE ) {
		$user = new User();
		$user->setEmail( uniqid() . '@example.org' );
		$user->setName( 'Test User' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );
		$this->manager->persist( $user );

		if ( $isMember ) {
			$membership = new UsergroupMembership();
			$membership->setUser( $user );
			$membership->setUsergroup( $this->group );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
			$membership->setRole( $role );
			$membership->setJoinedAt( new DateTime() );
			$this->manager->persist( $membership );
			$this->group->addMember( $membership );
		}

		// Flushed straight away: the security token serialises the account, it
		// needs its identifier.
		$this->manager->flush();

		return $user;
	}

	/**
	 * @param \App\Entity\User $author
	 * @param \App\Entity\User $answering
	 *
	 * @return \App\Entity\Discussion
	 */
	private function discussion ( User $author, User $answering = NULL ) {
		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'Rencontre anuelle' );
		$discussion->setUsergroup( $this->group );
		$discussion->setAuthor( $author );
		$discussion->setCreatedAt( new DateTime() );
		$discussion->setActiveAt( new DateTime() );
		$this->manager->persist( $discussion );

		foreach ( [ $author, $answering ] as $writer ) {
			if ( !$writer ) {
				continue;
			}

			$message = new DiscussionMessage();
			$message->setDiscussion( $discussion );
			$message->setAuthor( $writer );
			$message->setBody( '<p>Bonjour</p>' );
			$message->setCreatedAt( new DateTime() );
			$this->manager->persist( $message );

			$discussion->addMessage( $message );
		}

		$this->manager->flush();

		return $discussion;
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
	 * @param \App\Entity\Discussion $discussion
	 *
	 * @return string
	 */
	private function editUrl ( Discussion $discussion ) {
		return '/groups/' . $this->group->getSlug() . '/discussions/' . $discussion->getUuid() . '/edit';
	}

	/**
	 * @param \App\Entity\Discussion $discussion
	 *
	 * @return string
	 */
	private function readUrl ( Discussion $discussion ) {
		return '/groups/' . $this->group->getSlug() . '/discussions/' . $discussion->getUuid();
	}

	/**
	 * @param \App\Entity\Discussion $discussion
	 * @param string                 $title
	 */
	private function rename ( Discussion $discussion, $title ) {
		$crawler = $this->client->request( 'GET', $this->editUrl( $discussion ) );

		$form = $crawler->filter( 'form[name="discussion_title"]' )->form();
		$form[ 'discussion_title[title]' ] = $title;

		$this->client->submit( $form );
	}

	/**
	 * @param int $id
	 *
	 * @return \App\Entity\Discussion
	 */
	private function reload ( $id ) {
		$this->manager->clear();

		return $this->manager->getRepository( Discussion::class )->find( $id );
	}

	public function testTheAuthorCanRenameTheirDiscussion () {
		$author     = $this->user();
		$discussion = $this->discussion( $author );
		$id         = $discussion->getId();

		$this->logIn( $author );
		$this->rename( $discussion, 'Rencontre annuelle' );

		$this->assertEquals( 'Rencontre annuelle', $this->reload( $id )->getTitle() );
	}

	public function testTheAuthorCanStillRenameOnceOthersAnswered () {
		$author     = $this->user();
		$discussion = $this->discussion( $author, $this->user() );
		$id         = $discussion->getId();

		$this->logIn( $author );
		$this->rename( $discussion, 'Rencontre annuelle' );

		$this->assertEquals(
				'Rencontre annuelle',
				$this->reload( $id )->getTitle(),
				'Assert a title stays the author to fix, unlike the discussion itself'
		);
	}

	public function testAGroupAdministratorCanRenameTheDiscussion () {
		$discussion = $this->discussion( $this->user() );
		$id         = $discussion->getId();

		$this->logIn( $this->user( UsergroupMembership::ROLE_ADMIN ) );
		$this->rename( $discussion, 'Rencontre annuelle 2026' );

		$this->assertEquals(
				'Rencontre annuelle 2026',
				$this->reload( $id )->getTitle(),
				'Assert moderation of a misleading subject line stays possible'
		);
	}

	public function testAnotherMemberCannotRenameTheDiscussion () {
		$discussion = $this->discussion( $this->user() );

		$this->logIn( $this->user() );
		$this->client->request( 'GET', $this->editUrl( $discussion ) );

		$this->assertEquals(
				403,
				$this->client->getResponse()->getStatusCode(),
				'Assert a member cannot retitle somebody else subject'
		);
	}

	public function testAnEmptyTitleIsRefused () {
		$author     = $this->user();
		$discussion = $this->discussion( $author );
		$id         = $discussion->getId();

		$this->logIn( $author );
		$this->rename( $discussion, '   ' );

		$this->assertEquals(
				'Rencontre anuelle',
				$this->reload( $id )->getTitle(),
				'Assert an empty title is refused rather than leaving a nameless subject'
		);
	}

	public function testTheNewTitleIsShownInTheDiscussion () {
		$author     = $this->user();
		$discussion = $this->discussion( $author );

		$this->logIn( $author );
		$this->rename( $discussion, 'Rencontre annuelle' );

		$crawler = $this->client->request( 'GET', $this->readUrl( $discussion ) );

		$this->assertStringContainsString(
				'Rencontre annuelle',
				$crawler->filter( '.discussion__full h1' )->text()
		);
	}

	public function testTheRenamingIsLogged () {
		$author     = $this->user();
		$discussion = $this->discussion( $author );

		$this->logIn( $author );
		$this->rename( $discussion, 'Rencontre annuelle' );

		$log = $this->manager->getRepository( LogEvent::class )
							 ->findOneBy( [ 'type' => LogEvent::DISCUSSION_EDIT, 'usergroup' => $this->group ] );

		$this->assertNotNull( $log, 'Assert the group activity shows who changed the subject line' );
	}

	public function testAnArchivedDiscussionCannotBeRenamedByItsAuthor () {
		$author     = $this->user();
		$discussion = $this->discussion( $author );
		$discussion->setArchivedAt( new DateTime() );
		$this->manager->flush();

		$this->logIn( $author );
		$this->client->request( 'GET', $this->editUrl( $discussion ) );

		$this->assertEquals(
				404,
				$this->client->getResponse()->getStatusCode(),
				'Assert an archived discussion is no more renamable than it is readable'
		);
	}

	public function testTheRenameLinkIsOfferedToTheAuthorOnly () {
		$author     = $this->user();
		$discussion = $this->discussion( $author );

		$this->logIn( $author );
		$crawler = $this->client->request( 'GET', $this->readUrl( $discussion ) );

		$this->assertEquals( 1, $crawler->filter( 'a.edit_discussion' )->count() );

		$this->logIn( $this->user() );
		$crawler = $this->client->request( 'GET', $this->readUrl( $discussion ) );

		$this->assertEquals(
				0,
				$crawler->filter( 'a.edit_discussion' )->count(),
				'Assert the link is not offered to someone who cannot use it'
		);
	}
}
