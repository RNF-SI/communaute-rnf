<?php

namespace App\Tests\Controller;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #45 — écrire au support depuis la plateforme.
 *
 * Ce que l'épreuve tient, et qui se perdrait sans elle : le message part à
 * l'adresse configurée et à elle seule ; il part de la plateforme mais se
 * répond à celui qui l'a écrit (Reply-To) ; et un formulaire vide n'envoie
 * rien plutôt que de transmettre un message creux.
 */
class ContactFormTest extends WebTestCase {
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

	/**
	 * @return \App\Entity\User
	 */
	private function member () {
		$user = new User();

		// Les données de test peuplent la même base, et la colonne est unique.
		$user->setEmail( uniqid() . '-membre@example.org' );
		$user->setName( 'Camille Test' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );

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
	 * @return string l'adresse du support, telle que la configuration la donne
	 */
	private function supportAddress () {
		return self::$container->getParameter( 'plateform' )[ 'support' ];
	}

	/**
	 * @return \Swift_Mime_SimpleMessage[]
	 */
	private function sentMessages () {
		return $this->client->getProfile()->getCollector( 'swiftmailer' )->getMessages();
	}

	/**
	 * Le formulaire est posté tel que la page le rend — jeton compris, la
	 * protection CSRF étant active.
	 *
	 * @param array $values
	 *
	 * @return \Symfony\Component\DomCrawler\Crawler
	 */
	private function submit ( array $values ) {
		$crawler = $this->client->request( 'GET', '/contact' );
		$form    = $crawler->filter( 'form[name="contact"]' )->form();

		$this->client->enableProfiler();

		return $this->client->request( 'POST', '/contact', [
				'contact' => array_merge(
						$values,
						[ '_token' => $form->get( 'contact[_token]' )->getValue() ]
				),
		] );
	}

	/**
	 * L'adresse livrée avec l'application est celle du support RNF : c'est
	 * très exactement ce que demandait l'issue, et rien dans le code ne le
	 * dirait autrement — la valeur vit dans la configuration.
	 */
	public function testTheShippedAddressIsTheSupportOne () {
		$env = file_get_contents( self::$container->getParameter( 'kernel.project_dir' ) . '/.env' );

		$this->assertStringContainsString( 'SUPPORT_EMAIL=support@reserves-naturelles.org', $env );
	}

	public function testAVisitorIsSentToTheLogin () {
		$this->client->request( 'GET', '/contact' );

		$this->assertTrue(
				$this->client->getResponse()->isRedirect(),
				'Assert an anonymous visitor does not reach the form'
		);
	}

	public function testAMemberGetsTheFormWithoutHavingToSayWhoTheyAre () {
		$user = $this->member();
		$this->logIn( $user );

		$crawler = $this->client->request( 'GET', '/contact' );

		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertCount( 1, $crawler->filter( '[name="contact[subject]"]' ) );
		$this->assertCount( 1, $crawler->filter( '[name="contact[message]"]' ) );

		// Ni nom ni adresse à saisir : ils viennent du compte. Les demander
		// laisserait écrire sous un autre nom.
		$this->assertCount( 0, $crawler->filter( '[name="contact[email]"]' ) );
		$this->assertStringContainsString( $user->getEmail(), $crawler->filter( '.contact-form' )->text() );
	}

	public function testTheMessageGoesToTheSupportAndAnswersToItsAuthor () {
		$user = $this->member();
		$this->logIn( $user );

		$this->submit( [
				'subject' => 'Un document ne s’ouvre pas',
				'message' => "Bonjour,\n\nLe tableau de suivi ne s’ouvre plus depuis hier.",
		] );

		$messages = $this->sentMessages();

		$this->assertCount( 1, $messages, 'Assert exactly one e-mail leaves' );

		$message = $messages[ 0 ];

		$this->assertSame( [ $this->supportAddress() ], array_keys( $message->getTo() ) );
		$this->assertStringContainsString( 'Un document ne s’ouvre pas', $message->getSubject() );

		// L'expéditeur est le domaine que Postmark signe : partir de l'adresse
		// du membre ferait refuser le message, ou l'enverrait en indésirable.
		$this->assertSame(
				[ self::$container->getParameter( 'plateform' )[ 'from' ] ],
				array_keys( $message->getFrom() )
		);

		// Et répondre écrit à la personne, pas à la boîte d'envoi.
		$replyTo = $message->getHeaders()->get( 'Reply-To' );

		$this->assertNotNull( $replyTo, 'Assert support can answer in one click' );
		$this->assertStringContainsString( $user->getEmail(), $replyTo->getFieldBody() );

		$this->assertStringContainsString(
				'tableau de suivi',
				$message->getBody(),
				'Assert the message itself travels'
		);

		$this->assertTrue( $this->client->getResponse()->isRedirect( '/contact' ) );
	}

	public function testAnEmptyMessageSendsNothing () {
		$this->logIn( $this->member() );

		$crawler = $this->submit( [ 'subject' => '', 'message' => '' ] );

		$this->assertCount( 0, $this->sentMessages(), 'Assert nothing leaves' );
		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertGreaterThan(
				0,
				$crawler->filter( '.form_errors li' )->count(),
				'Assert the page says what is missing'
		);
	}

	/**
	 * Le pied de page ne mène pas à une page que le lecteur ne peut pas
	 * ouvrir : le lien n'apparaît qu'une fois connecté.
	 */
	public function testTheFooterCarriesTheLinkForMembersOnly () {
		$crawler = $this->client->request( 'GET', '/' );

		$this->assertCount( 0, $crawler->filter( 'footer.footer a[href="/contact"]' ) );

		$this->logIn( $this->member() );

		$crawler = $this->client->request( 'GET', '/' );

		$this->assertCount( 1, $crawler->filter( 'footer.footer a[href="/contact"]' ) );
	}
}
