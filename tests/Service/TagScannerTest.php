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

	/**
	 * Le scanner de la messagerie pour les contenus, construit comme
	 * TagParser le construit : huit mots, et la forme entre guillemets.
	 *
	 * @return \App\Service\Tagging\TagScanner
	 */
	private function things () {
		return new TagScanner( '#', 8, '\p{L}\p{N}._\-\/&', TRUE );
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

	/**************************************************
	 * LA FORME ENTRE GUILLEMETS
	 *************************************************/

	/**
	 * Un titre nu s'arrête à la première ponctuation interne — c'est ce qui
	 * rend « @Jeanne, tu peux ? » à sa virgule. Un document appelé « Guide :
	 * gestion des mares » n'était donc adressable d'aucune façon.
	 */
	public function testAPunctuatedTitleIsNotReadableBare () {
		$scanner = $this->things();

		$this->assertSame(
				'#Guide : gestion des mares',
				$this->render( $scanner, '#Guide : gestion des mares', [ 'Guide : gestion des mares' ] )
		);
	}

	public function testQuotesSayWhereTheTitleEnds () {
		$scanner = $this->things();

		$this->assertSame(
				'lire [Guide : gestion des mares|#"Guide : gestion des mares"] ce soir',
				$this->render( $scanner, 'lire #"Guide : gestion des mares" ce soir', [ 'Guide : gestion des mares' ] )
		);
	}

	/**
	 * On écrit en français, et un copier-coller depuis un traitement de texte
	 * dépose des guillemets courbes. Les trois paires se lisent.
	 */
	public function testEveryPairOfQuotesIsRead () {
		$scanner = $this->things();

		foreach ( [ '#«Bilan (2025)»', '#“Bilan (2025)”', '#"Bilan (2025)"' ] as $written ) {
			$this->assertSame(
					'[Bilan (2025)|' . $written . ']',
					$this->render( $scanner, $written, [ 'Bilan (2025)' ] )
			);
		}
	}

	/**
	 * Un guillemet resté ouvert avalerait le reste du message : il ne
	 * franchit pas la fin de ligne, et le texte reste du texte.
	 */
	public function testAnUnclosedQuoteDoesNotSwallowTheMessage () {
		$scanner = $this->things();

		$this->assertSame(
				'il a dit #"bonjour et puis rien',
				$this->render( $scanner, 'il a dit #"bonjour et puis rien', [ 'bonjour' ] )
		);
	}

	public function testQuotesAroundNothingKnownStayText () {
		$scanner = $this->things();

		$this->assertSame(
				'dit #"Inconnu au bataillon" tiens',
				$this->render( $scanner, 'dit #"Inconnu au bataillon" tiens', [ 'Autre chose' ] )
		);
	}

	/**
	 * Le « @ » ne connaît pas les guillemets, et ne doit pas les apprendre :
	 * un nom de personne n'a pas de ponctuation interne, et MentionParser lit
	 * les mentions d'une discussion avec ce scanner-là. Une divergence d'un
	 * caractère donnerait un lien à l'affichage là où la notification n'aurait
	 * prévenu personne.
	 */
	public function testTheMentionScannerIgnoresQuotes () {
		$scanner = $this->scanner();

		$this->assertSame(
				'salut @"Jeanne Réserve" !',
				$this->render( $scanner, 'salut @"Jeanne Réserve" !', [ 'Jeanne Réserve' ] )
		);
	}

	public function testQuotedTitlesAreListedForTheOneQueryToTheBase () {
		$scanner = $this->things();

		$this->assertSame(
				[ 'guide : gestion des mares' => 'Guide : gestion des mares' ],
				$scanner->labelsIn( 'voir #"Guide : gestion des mares" merci' )
		);
	}

	/**************************************************
	 * ÉCRIRE UN TAG, POUR LE BOUTON « INSÉRER UN LIEN »
	 *************************************************/

	public function testWritesABareTagWhenTheTitleReadsBack () {
		$scanner = $this->things();

		$this->assertSame( '#Plan de gestion 2026', $scanner->write( 'Plan de gestion 2026' ) );
	}

	public function testWritesQuotesOnlyWhenTheTitleNeedsThem () {
		$scanner = $this->things();

		$this->assertSame( '#"Guide : gestion des mares"', $scanner->write( 'Guide : gestion des mares' ) );
		$this->assertSame( '#"Bilan (2025)"', $scanner->write( 'Bilan (2025)' ) );
	}

	/**
	 * Huit mots au plus dans un titre nu ; au-delà, les guillemets disent où
	 * il finit, sans quoi le tag ne désignerait que son début.
	 */
	public function testWritesQuotesWhenTheTitleRunsPastTheWordCount () {
		$scanner = $this->things();

		$long = 'Un titre de neuf mots un deux trois quatre cinq';

		$this->assertSame( '#"' . $long . '"', $scanner->write( $long ) );
	}

	public function testBorrowsAnotherPairWhenTheTitleCarriesQuotes () {
		$scanner = $this->things();

		$this->assertSame( '#«Le "vrai" bilan»', $scanner->write( 'Le "vrai" bilan' ) );
	}

	/**
	 * Ce que le bouton écrit, le scanner doit le relire : c'est la seule
	 * propriété qui compte, et elle vaut pour les deux formes.
	 */
	public function testWhatItWritesItReadsBack () {
		$scanner = $this->things();

		$titles = [
				'Plan de gestion 2026',
				'Guide : gestion des mares',
				'Bilan (2025)',
				'Le "vrai" bilan',
				'Compte rendu — réunion du 3',
				'Zones humides & prairies',
				'Un titre de neuf mots un deux trois quatre cinq',
		];

		foreach ( $titles as $title ) {
			$this->assertSame(
					'voir [' . $title . '|' . $scanner->write( $title ) . '] merci',
					$this->render( $scanner, 'voir ' . $scanner->write( $title ) . ' merci', [ $title ] ),
					$title
			);
		}
	}

	public function testTheMentionScannerWritesBareTags () {
		$scanner = $this->scanner();

		$this->assertSame( '@Jeanne Réserve', $scanner->write( 'Jeanne Réserve' ) );
	}

	/**************************************************
	 * CE QUE LE LECTEUR VOIT
	 *************************************************/

	/**
	 * Les guillemets disent où le titre finit, ce qui ne regarde que la
	 * lecture. Le message garde ce qui a été écrit, l'affichage montre le
	 * titre.
	 */
	public function testTheReaderIsNotShownTheQuotes () {
		$scanner = $this->things();

		$this->assertSame( '#Guide : gestion des mares', $scanner->readable( '#"Guide : gestion des mares"' ) );
		$this->assertSame( '#Guide : gestion des mares', $scanner->readable( '#«Guide : gestion des mares»' ) );
	}

	public function testABareTagIsShownAsItWasWritten () {
		$scanner = $this->things();

		$this->assertSame( '#Suivi avifaune', $scanner->readable( '#Suivi avifaune' ) );
	}
}
