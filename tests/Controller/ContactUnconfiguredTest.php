<?php

namespace App\Tests\Controller;

use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Issue #45 — ce que devient le formulaire de contact sans adresse de
 * destination.
 *
 * `SupportSenderTest` montre que le service se tait ; ici c'est la page qui
 * est éprouvée, et c'est une autre affaire : un service muet derrière une
 * route qui répond 200 accepterait des messages pour les jeter, et un lien
 * resté dans le pied de page mènerait à une page morte.
 *
 * L'adresse est vidée **avant** le démarrage du noyau : le conteneur lit
 * `SUPPORT_EMAIL` à l'exécution (`$this->getEnv(...)`), une fois par instance.
 * D'où le noyau neuf, et d'où la première épreuve — sans elle, une surcharge
 * qui cesserait d'agir laisserait les deux autres passer pour la mauvaise
 * raison.
 */
class ContactUnconfiguredTest extends WebTestCase {
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
	 * @var string|null
	 */
	private $previous;

	protected function setUp (): void {
		$this->previous = $_SERVER[ 'SUPPORT_EMAIL' ] ?? NULL;

		$_ENV[ 'SUPPORT_EMAIL' ] = $_SERVER[ 'SUPPORT_EMAIL' ] = '';

		self::ensureKernelShutdown();

		$this->client = static::createClient();
		$this->client->disableReboot();

		$this->manager = self::$container->get( EntityManagerInterface::class );
		$this->manager->getConnection()->beginTransaction();

		$user = new User();
		$user->setEmail( uniqid() . '-sans-support@example.org' );
		$user->setName( 'Camille Test' );
		$user->setCreatedAt( new DateTime() );
		$user->setStatus( User::STATUS_ACTIVE );
		$user->setPassword( '' );
		$user->setHasAgreedTermsOfUse( TRUE );
		$user->setRoles( [ 'ROLE_USER' ] );

		$this->manager->persist( $user );
		$this->manager->flush();

		$session = self::$container->get( 'session' );
		$session->set(
				'_security_' . self::FIREWALL,
				serialize( new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() ) )
		);
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	protected function tearDown (): void {
		$connection = $this->manager->getConnection();

		if ( $connection->isTransactionActive() ) {
			$connection->rollBack();
		}

		// Remettre l'adresse, et le noyau avec : les classes suivantes
		// tournent dans le même processus.
		if ( $this->previous === NULL ) {
			unset( $_ENV[ 'SUPPORT_EMAIL' ], $_SERVER[ 'SUPPORT_EMAIL' ] );
		}
		else {
			$_ENV[ 'SUPPORT_EMAIL' ] = $_SERVER[ 'SUPPORT_EMAIL' ] = $this->previous;
		}

		self::ensureKernelShutdown();

		parent::tearDown();
	}

	public function testTheAddressIsReallyEmptyForThisKernel () {
		$this->assertSame(
				'',
				self::$container->getParameter( 'plateform' )[ 'support' ],
				'Assert the override still reaches the container — the two tests below mean nothing otherwise'
		);
	}

	public function testThePageDoesNotExist () {
		$this->client->request( 'GET', '/contact' );

		$this->assertSame(
				404,
				$this->client->getResponse()->getStatusCode(),
				'Assert no form accepts a message it could not deliver'
		);
	}

	public function testTheFooterDropsTheLink () {
		$crawler = $this->client->request( 'GET', '/' );

		$this->assertSame( 200, $this->client->getResponse()->getStatusCode() );
		$this->assertCount( 0, $crawler->filter( 'footer.footer a[href="/contact"]' ) );
	}
}
