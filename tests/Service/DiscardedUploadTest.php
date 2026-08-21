<?php

namespace App\Tests\Service;

use App\Service\AppFileManager;
use App\Service\FileManager;
use App\Service\UserFileManager;
use App\Service\UsergroupFileManager;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issue #40 — reconnaître une requête que PHP a vidée.
 *
 * Au-delà de `post_max_size`, PHP jette le corps de la requête avant que
 * Symfony la voie : $_POST et $_FILES arrivent vides alors que le navigateur a
 * bien envoyé quelque chose. Le formulaire ne se croit donc pas soumis, se
 * réaffiche vierge, et l'on croit avoir déposé un document. C'est ce qui
 * laissait des documents sans fichier en base (#6).
 *
 * Le seul indice qui reste est l'écart entre un corps annoncé et un corps
 * absent. Ces tests tiennent cette lecture.
 */
class DiscardedUploadTest extends TestCase {
	/**
	 * Aucune des dépendances du service n'intervient dans ce contrôle : il ne
	 * lit que la requête.
	 *
	 * @return \App\Service\FileManager
	 */
	private function manager () {
		return new FileManager(
				$this->createMock( UserFileManager::class ),
				$this->createMock( UsergroupFileManager::class ),
				$this->createMock( AppFileManager::class ),
				$this->createMock( CacheManager::class )
		);
	}

	/**
	 * @param string $method
	 * @param array  $parameters
	 * @param array  $server
	 *
	 * @return \Symfony\Component\HttpFoundation\Request
	 */
	private function request ( $method, array $parameters = [], array $server = [] ) {
		// Construite à la main plutôt que par Request::create(), qui remplit
		// lui-même des en-têtes : ce qui est éprouvé ici est justement leur
		// présence ou leur absence.
		$server = array_merge( [
				'REQUEST_METHOD' => $method,
				'REQUEST_URI'    => '/groups/test-group/documents/new',
		], $server );

		return new Request( [], $parameters, [], [], [], $server );
	}

	public function testAnEmptyPostAnnouncingABodyIsDiscarded () {
		$request = $this->request( 'POST', [], [ 'CONTENT_LENGTH' => 80000000 ] );

		$this->assertTrue(
				$this->manager()->requestWasDiscarded( $request ),
				'Assert a body announced but absent is recognised as thrown away'
		);
	}

	public function testAnOrdinaryPostIsNotDiscarded () {
		$request = $this->request( 'POST', [ 'document' => [ 'title' => 'Compte rendu' ] ], [ 'CONTENT_LENGTH' => 400 ] );

		$this->assertFalse(
				$this->manager()->requestWasDiscarded( $request ),
				'Assert a submission that arrived is left alone'
		);
	}

	/**
	 * Un formulaire dont tous les champs sont vides mais qui a bien été reçu
	 * porte quand même son jeton et son bouton : la distinction ne se fait pas
	 * sur le contenu, mais sur la présence.
	 */
	public function testAPostWithoutAnnouncedBodyIsNotDiscarded () {
		$request = $this->request( 'POST' );

		$this->assertFalse(
				$this->manager()->requestWasDiscarded( $request ),
				'Assert nothing announced means nothing to have lost'
		);
	}

	public function testAGetIsNeverDiscarded () {
		$request = $this->request( 'GET', [], [ 'CONTENT_LENGTH' => 80000000 ] );

		$this->assertFalse(
				$this->manager()->requestWasDiscarded( $request ),
				'Assert only a submission can be thrown away'
		);
	}
}
