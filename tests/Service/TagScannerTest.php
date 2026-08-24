<?php

namespace App\Tests\Service;

use App\Service\Tagging\TagScanner;
use PHPUnit\Framework\TestCase;

/**
 * La lecture des tags, indépendamment de ce qu'ils désignent.
 *
 * TagScanner est ce que MentionParser (#37) et la messagerie partagent : la
 * même façon de deviner où s'arrête un nom, la même indifférence à la casse et
 * aux accents. Deux lectures qui divergeraient d'un caractère donneraient un
 * lien à l'affichage là où la notification n'aurait prévenu personne — d'où
 * ces épreuves ici, sur la mécanique seule.
 */
class TagScannerTest extends TestCase {
	/**
	 * @param string $prefix
	 * @param int    $words
	 * @param string $notAfter
	 *
	 * @return \App\Service\Tagging\TagScanner
	 */
	private function scanner ( $prefix = '@', $words = 4, $notAfter = '\p{L}\p{N}._\-' ) {
		return new TagScanner( $prefix, $words, $notAfter );
	}

	/**
	 * Le dictionnaire attendu par scan() : des noms repliés vers ce qu'ils
	 * désignent.
	 *
	 * @param \App\Service\Tagging\TagScanner $scanner
	 * @param string[]                        $names
	 *
	 * @return array
	 */
	private function subjects ( TagScanner $scanner, array $names ) {
		$subjects = [];

		foreach ( $names as $name ) {
			$subjects[ $scanner->fold( $name ) ] = $name;
		}

		return $subjects;
	}

	/**
	 * @param \App\Service\Tagging\TagScanner $scanner
	 * @param string                          $text
	 * @param string[]                        $names
	 *
	 * @return string
	 */
	private function render ( TagScanner $scanner, $text, array $names ) {
		return $scanner->scan(
				$text,
				$this->subjects( $scanner, $names ),
				static function ( $subject, $matched ) {
					return '[' . $subject . '|' . $matched . ']';
				}
		);
	}

	/**************************************************
	 * OÙ COMMENCE ET OÙ S'ARRÊTE UN TAG
	 *************************************************/

	public function testReadsANameOfSeveralWords () {
		$scanner = $this->scanner();

		$this->assertSame(
				'Bonjour [Jeanne Réserve|@Jeanne Réserve], merci.',
				$this->render( $scanner, 'Bonjour @Jeanne Réserve, merci.', [ 'Jeanne Réserve' ] )
		);
	}

	public function testPrefersTheLongestNameItKnows () {
		$scanner = $this->scanner();

		$this->assertSame(
				'[Jeanne Réserve du Marais|@Jeanne Réserve du Marais] arrive',
				$this->render(
						$scanner,
						'@Jeanne Réserve du Marais arrive',
						[ 'Jeanne', 'Jeanne Réserve du Marais' ]
				)
		);
	}

	public function testGivesBackWhatFollowedTheName () {
		$scanner = $this->scanner();

		// Seule la part qui nomme quelqu'un est consommée : la question reste.
		$this->assertSame(
				'[Jeanne|@Jeanne], tu peux relire ?',
				$this->render( $scanner, '@Jeanne, tu peux relire ?', [ 'Jeanne' ] )
		);
	}

	public function testIgnoresCaseAndAccents () {
		$scanner = $this->scanner();

		$this->assertSame(
				'[Jeanne Réserve|@jeanne reserve]',
				$this->render( $scanner, '@jeanne reserve', [ 'Jeanne Réserve' ] )
		);
	}

	public function testLeavesAnUnknownNameAlone () {
		$scanner = $this->scanner();

		$this->assertSame(
				'@Personne inconnue ici',
				$this->render( $scanner, '@Personne inconnue ici', [ 'Jeanne' ] )
		);
	}

	/**************************************************
	 * CE QUI N'OUVRE PAS UN TAG
	 *************************************************/

	public function testAnEmailAddressIsNotAMention () {
		$scanner = $this->scanner();

		$this->assertSame(
				'écrivez à jeanne@example.org',
				$this->render( $scanner, 'écrivez à jeanne@example.org', [ 'example.org', 'example' ] )
		);
	}

	public function testAnAnchorInAnAddressIsNotAContentTag () {
		// Le « # » de la messagerie : il n'ouvre rien après « / » ni « & ».
		$scanner = $this->scanner( '#', 8, '\p{L}\p{N}._\-\/&' );

		$this->assertSame(
				'voir https://exemple.org/page/#Rapport annuel',
				$this->render( $scanner, 'voir https://exemple.org/page/#Rapport annuel', [ 'Rapport annuel' ] )
		);
	}

	public function testANumericEntityIsNotAContentTag () {
		$scanner = $this->scanner( '#', 8, '\p{L}\p{N}._\-\/&' );

		$this->assertSame(
				'l&#039;an dernier',
				$this->render( $scanner, 'l&#039;an dernier', [ '039' ] )
		);
	}

	/**************************************************
	 * UN TITRE EST PLUS LONG QU'UN NOM
	 *************************************************/

	public function testAContentTagMayCountEightWords () {
		$scanner = $this->scanner( '#', 8, '\p{L}\p{N}._\-\/&' );
		$title   = 'Guide de gestion des tourbières de montagne 2024';

		$this->assertSame(
				'[' . $title . '|#' . $title . '] est en ligne',
				$this->render( $scanner, '#' . $title . ' est en ligne', [ $title ] )
		);
	}

	/**************************************************
	 * CE QUE LA REQUÊTE VA CHERCHER
	 *************************************************/

	public function testListsEveryNameATextMayBeNaming () {
		$scanner = $this->scanner();
		$labels  = $scanner->labelsIn( 'merci @Jeanne Réserve et @Paul' );

		// Les noms sont indexés repliés, et donnés tels qu'ils ont été écrits :
		// c'est ce couple qui permet une seule requête, puis un rapprochement
		// exact au retour.
		$this->assertArrayHasKey( 'jeanne reserve', $labels );
		$this->assertArrayHasKey( 'jeanne', $labels );
		$this->assertArrayHasKey( 'paul', $labels );
		$this->assertSame( 'Jeanne Réserve', $labels[ 'jeanne reserve' ] );
	}

	public function testStopsListingBeyondTheCap () {
		$scanner = $this->scanner();

		$words = [];

		for ( $i = 0; $i < 40; $i++ ) {
			$words[] = '@Nom' . $i;
		}

		$this->assertLessThanOrEqual(
				TagScanner::MAX_LABELS,
				count( $scanner->labelsIn( implode( ' ', $words ) ) )
		);
	}

	/**************************************************
	 * LE TEXTE AUTOUR
	 *************************************************/

	public function testTheTextAroundGoesThroughTheOtherCallback () {
		$scanner = $this->scanner();

		$rendered = $scanner->scan(
				'salut @Jeanne <b>',
				$this->subjects( $scanner, [ 'Jeanne' ] ),
				static function ( $subject, $matched ) {
					return '[' . $matched . ']';
				},
				static function ( $chunk ) {
					return htmlspecialchars( $chunk, ENT_QUOTES, 'UTF-8' );
				}
		);

		// Le tag n'est pas échappé — c'est lui qui produit le balisage — mais
		// tout ce qui l'entoure l'est. C'est ce qui permet à un message d'être
		// du texte brut sans qu'une balise tapée par mégarde ne s'exécute.
		$this->assertSame( 'salut [@Jeanne] &lt;b&gt;', $rendered );
	}

	public function testAnEmptyDictionaryStillEscapesTheText () {
		$scanner = $this->scanner();

		$rendered = $scanner->scan(
				'<script>',
				[],
				static function ( $subject, $matched ) {
					return $matched;
				},
				static function ( $chunk ) {
					return htmlspecialchars( $chunk, ENT_QUOTES, 'UTF-8' );
				}
		);

		$this->assertSame( '&lt;script&gt;', $rendered );
	}
}
