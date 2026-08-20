<?php

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\SearchEngineIndexSubscriber;
use App\Service\SearchEngineManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Un index de recherche est un objet **dérivé** : il se rebâtit en une
 * commande. Le perdre un instant fait manquer un résultat ; empêcher un
 * enregistrement, c'est bloquer la plateforme.
 *
 * Ce n'est pas théorique. Les fichiers SQLite des index appartiennent à qui a
 * lancé la dernière réindexation à la main. Si ce n'est pas le serveur web,
 * chaque écriture lève « attempt to write a readonly database » — et comme
 * enregistrer un compte déclenche l'indexation, c'est **la connexion** qui
 * tombe. C'est arrivé en préproduction, deux déploiements de suite.
 */
class SearchEngineIndexSubscriberTest extends TestCase {
	/**
	 * @param \Throwable|null $failure ce que l'indexation rencontre
	 *
	 * @return array le sujet et le journal
	 */
	private function subscriber ( $failure = NULL ) {
		$manager = $this->createMock( SearchEngineManager::class );

		if ( $failure ) {
			$manager->method( 'setTNTSearchConfiguration' )->willThrowException( $failure );
		}

		$logger = $this->createMock( LoggerInterface::class );

		return [ new SearchEngineIndexSubscriber( $manager, $logger ), $logger ];
	}

	/**
	 * @return \Doctrine\Persistence\Event\LifecycleEventArgs
	 */
	private function event () {
		$user = new User();
		$user->setEmail( 'membre@example.org' );

		return new LifecycleEventArgs( $user, $this->createMock( EntityManagerInterface::class ) );
	}

	public function testAReadonlyIndexDoesNotBreakTheSave () {
		list( $subscriber ) = $this->subscriber(
				new RuntimeException( 'SQLSTATE[HY000]: General error: 8 attempt to write a readonly database' )
		);

		$subscriber->postPersist( $this->event() );

		$this->assertTrue( TRUE, 'Assert saving an account survives an index that cannot be written' );
	}

	public function testTheFailureIsWrittenDownRatherThanSwallowed () {
		list( $subscriber, $logger ) = $this->subscriber( new RuntimeException( 'readonly database' ) );

		$logger->expects( $this->once() )
			   ->method( 'error' )
			   ->with(
					   $this->stringContains( 'index' ),
					   $this->callback( function ( array $context ) {
						   return isset( $context[ 'error' ] ) && isset( $context[ 'action' ] );
					   } )
			   );

		$subscriber->postPersist( $this->event() );
	}

	public function testAnUpdateSurvivesTooAndSaysWhichAction () {
		list( $subscriber, $logger ) = $this->subscriber( new RuntimeException( 'disk full' ) );

		$logger->expects( $this->once() )
			   ->method( 'error' )
			   ->with(
					   $this->anything(),
					   $this->callback( function ( array $context ) {
						   return $context[ 'action' ] === 'update';
					   } )
			   );

		$subscriber->postUpdate( $this->event() );
	}

	public function testARemovalSurvivesToo () {
		list( $subscriber ) = $this->subscriber( new RuntimeException( 'index missing' ) );

		$subscriber->preRemove( $this->event() );

		$this->assertTrue( TRUE );
	}

	public function testNothingIsLoggedWhenAllGoesWell () {
		list( $subscriber, $logger ) = $this->subscriber();

		$logger->expects( $this->never() )->method( 'error' );

		$subscriber->postPersist( $this->event() );
	}
}
