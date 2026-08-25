<?php

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Entity\User;
use App\Entity\Usergroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Les actualités telles qu'on les lit : la liste d'un groupe, et la page de
 * chacune.
 *
 * Ces deux routes n'étaient éprouvées nulle part. Seules `/articles/new` et
 * `/articles/{slug}/edit` l'étaient, et encore, depuis ce matin seulement. On
 * pouvait donc peupler les actualités des données de test sans que rien ne
 * dise si la page qui les affiche tenait le coup.
 *
 * Le contrôle se fait sur les fixtures et non sur des données fabriquées ici :
 * c'est très exactement le contenu qui part en préproduction qu'on veut voir
 * s'afficher.
 */
class ArticlePagesTest extends WebTestCase {
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
	}

	/**
	 * @param string $slug
	 *
	 * @return \App\Entity\Usergroup
	 */
	private function group ( $slug ) {
		$group = $this->manager->getRepository( Usergroup::class )->findOneBy( [ 'slug' => $slug ] );

		if ( !$group ) {
			$this->markTestSkipped( sprintf( 'Fixtures not loaded: %s', $slug ) );
		}

		return $group;
	}

	/**
	 * @param string $email
	 */
	private function logIn ( $email ) {
		$user = $this->manager->getRepository( User::class )->findOneBy( [ 'email' => $email ] );

		if ( !$user ) {
			$this->markTestSkipped( sprintf( 'Fixtures not loaded: %s', $email ) );
		}

		$session = self::$container->get( 'session' );
		$token   = new UsernamePasswordToken( $user, NULL, self::FIREWALL, $user->getRoles() );

		$session->set( '_security_' . self::FIREWALL, serialize( $token ) );
		$session->save();

		$this->client->getCookieJar()->set( new Cookie( $session->getName(), $session->getId() ) );
	}

	/**
	 * @param string $url
	 *
	 * @return int
	 */
	private function statusOf ( $url ) {
		$this->client->request( 'GET', $url );

		return $this->client->getResponse()->getStatusCode();
	}

	public function testTheListOfTheReferenceGroupRenders () {
		$this->logIn( 'membre@example.org' );

		$this->assertEquals(
				200,
				$this->statusOf( '/groups/groupe-de-test/articles' ),
				'Assert the list of news renders with the seeded content'
		);
	}

	public function testTheListOfTheCommunityGroupRenders () {
		$this->logIn( 'membre@example.org' );

		$this->assertEquals( 200, $this->statusOf( '/groups/communaute/articles' ) );
	}

	/**
	 * Sans session, on est renvoyé vers la connexion — y compris sur un groupe
	 * public.
	 *
	 * Ce n'est pas le voteur qui tranche : `access_control` se termine par
	 * `{ path: ^/, roles: ROLE_USER }`, et le pare-feu redirige avant que
	 * `GroupVoter` soit consulté. À savoir en lisant CLAUDE.md, qui décrit un
	 * groupe public comme « lisible par n'importe qui, visiteurs anonymes
	 * compris » : c'est vrai du voteur, pas de la plateforme telle qu'elle est
	 * configurée.
	 */
	public function testWithoutASessionTheVisitorIsSentToTheLogin () {
		$this->assertEquals( 302, $this->statusOf( '/groups/communaute/articles' ) );
	}

	/**
	 * Chaque actualité du groupe de référence, une par une : une liste qui
	 * s'affiche ne dit pas que les pages derrière s'affichent.
	 */
	public function testEveryArticleOfTheReferenceGroupOpens () {
		$this->logIn( 'membre@example.org' );

		$articles = $this->manager->getRepository( Article::class )
								  ->findBy( [ 'usergroup' => $this->group( 'groupe-de-test' ) ] );

		$this->assertNotEmpty( $articles, 'Assert the reference group carries news to read' );

		foreach ( $articles as $article ) {
			$this->assertEquals(
					200,
					$this->statusOf( '/groups/groupe-de-test/articles/' . $article->getSlug() ),
					sprintf( 'Assert « %s » opens', $article->getTitle() )
			);
		}
	}

	public function testEveryArticleOfTheCommunityGroupOpens () {
		$this->logIn( 'membre@example.org' );

		$articles = $this->manager->getRepository( Article::class )
								  ->findBy( [ 'usergroup' => $this->group( 'communaute' ) ] );

		$this->assertNotEmpty( $articles );

		foreach ( $articles as $article ) {
			$this->assertEquals(
					200,
					$this->statusOf( '/groups/communaute/articles/' . $article->getSlug() ),
					sprintf( 'Assert « %s » opens', $article->getTitle() )
			);
		}
	}

	/**
	 * La liste globale, celle du menu : elle redirige vers le groupe
	 * communauté et ne doit pas dépendre de ce que ce groupe contient.
	 */
	public function testTheGlobalNewsEntryPointRenders () {
		$this->logIn( 'membre@example.org' );

		$this->client->request( 'GET', '/articles' );
		$this->client->followRedirect();

		$this->assertEquals( 200, $this->client->getResponse()->getStatusCode() );
	}
}
