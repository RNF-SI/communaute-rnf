<?php

namespace App\Tests\Session;

use App\Session\ResilientFileSessionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Un fichier de session illisible déconnecte une personne, il ne met pas la
 * plateforme à terre.
 *
 * Ce qui se contrôle ici est notre décision, pas le contrôle de propriété de
 * PHP — qui ne nous appartient pas et qui, selon la façon dont l'interpréteur
 * est bâti, ne se déclenche pas partout. La trace laissée par le staging dit
 * précisément quel maillon casse : l'avertissement est émis depuis
 * `StrictSessionHandler::read()`, donc à l'intérieur de `$handler->read()`,
 * puis PHP rend « Failed to read session data ». La panne est donc, très
 * exactement, **une lecture qui rend FALSE**.
 *
 * C'est ce cas-là qui est éprouvé, en le provoquant plutôt qu'en l'attendant.
 */
class ResilientSessionHandlerTest extends TestCase {
	/**
	 * @param string|false $result ce que rend la lecture native
	 *
	 * @return \App\Session\ResilientFileSessionHandler
	 */
	private function handler ( $result, $logger = NULL ) {
		return new class( $result, $logger ) extends ResilientFileSessionHandler {
			private $result;

			/**
			 * Le constructeur parent n'est pas appelé : il pose des `ini_set()`
			 * sur le module de session, que PHP refuse une fois la sortie
			 * commencée — c'est-à-dire toujours, sous PHPUnit. On installe donc
			 * les deux champs à la main.
			 */
			public function __construct ( $result, $logger ) {
				$this->savePath = sys_get_temp_dir();
				$this->logger   = $logger;
				$this->result   = $result;
			}

			protected function readFromParent ( $sessionId ) {
				return $this->result;
			}
		};
	}

	/**
	 * @return \Psr\Log\AbstractLogger
	 */
	private function logger () {
		return new class extends AbstractLogger {
			public $lines = [];

			public function log ( $level, $message, array $context = [] ) {
				$this->lines[] = [ 'level' => $level, 'message' => (string) $message ];
			}
		};
	}

	/**
	 * Le cœur de l'affaire : FALSE devient une session vide. C'est ce qui fait
	 * la différence entre « une personne est déconnectée » et « toutes les
	 * pages rendent 500 », la session étant ouverte à chaque requête.
	 */
	public function testAnUnreadableSessionBecomesAnEmptyOne () {
		$this->assertSame(
				'',
				$this->handler( FALSE )->read( 'whatever' ),
				'Assert a session that cannot be read does not take the whole platform down with it'
		);
	}

	/**
	 * Dégrader en silence serait pire que tomber : on échangerait une panne
	 * bruyante contre des déconnexions inexplicables, que ce projet a déjà
	 * passé des jours à traquer.
	 */
	public function testItSaysSoInTheLog () {
		$logger = $this->logger();

		$this->handler( FALSE, $logger )->read( 'whatever' );

		$this->assertCount( 1, $logger->lines, 'Assert the degradation leaves a trace' );
		$this->assertSame( 'warning', $logger->lines[ 0 ][ 'level' ] );
		$this->assertStringContainsString(
				'propriétaire',
				$logger->lines[ 0 ][ 'message' ],
				'Assert the message names the cause, not just the symptom'
		);
	}

	/**
	 * Et le cas ordinaire, qui est de très loin le plus fréquent : on ne
	 * touche à rien, et rien n'est journalisé.
	 */
	public function testAReadableSessionIsHandedBackUntouched () {
		$logger = $this->logger();

		$this->assertSame(
				'compteur|i:42;',
				$this->handler( 'compteur|i:42;', $logger )->read( 'whatever' )
		);

		$this->assertSame( [], $logger->lines, 'Assert a healthy read is not announced' );
	}

	/**
	 * Une session vide n'est pas une session illisible : le premier passage de
	 * quelqu'un rend une chaîne vide, et ce n'est pas un incident.
	 */
	public function testAnEmptySessionIsNotAnIncident () {
		$logger = $this->logger();

		$this->assertSame( '', $this->handler( '', $logger )->read( 'whatever' ) );
		$this->assertSame( [], $logger->lines, 'Assert a first visit is not reported as a failure' );
	}
}
