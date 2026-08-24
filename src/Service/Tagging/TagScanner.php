<?php

namespace App\Service\Tagging;

/**
 * Lit dans un texte les « @… » et les « #… » que quelqu'un y a écrits, et
 * rend la main à l'appelant sur chacun de ceux qui désignent réellement
 * quelque chose.
 *
 * Cette classe ne connaît ni les membres ni les documents : on lui donne un
 * dictionnaire, elle dit où sont les mots. C'est ce qui permet à
 * MentionParser (#37), qui cherche des membres dans un groupe, et à TagParser,
 * qui cherche des personnes, des groupes et des contenus dans un message
 * privé, de partager exactement la même lecture — la même façon de deviner où
 * s'arrête un nom, la même indifférence à la casse et aux accents. Deux
 * lectures qui divergeraient d'un caractère donneraient des liens à
 * l'affichage là où la notification n'aurait prévenu personne.
 */
class TagScanner {
	/**
	 * Ce qui peut suivre un nom sans en faire partie. Coupé à l'expression
	 * régulière plutôt qu'à rtrim() : plusieurs de ces caractères tiennent sur
	 * plus d'un octet, et rtrim() trancherait au milieu du suivant.
	 */
	private const TRAILING = '/[\s.,;:!?…"\'’»)\]}]+$/u';

	/**
	 * Un texte qui nomme plus de choses que cela ne nomme rien : on cesse de
	 * chercher plutôt que d'interroger la base sur un paragraphe entier.
	 */
	public const MAX_LABELS = 50;

	/**
	 * @var string « @ » ou « # »
	 */
	private $prefix;

	/**
	 * @var string
	 */
	private $pattern;

	/**
	 * @param string $prefix   le caractère qui ouvre un tag
	 * @param int    $maxWords combien de mots un tag peut compter
	 * @param string $notAfter classe de caractères après lesquels le préfixe
	 *                         n'ouvre pas un tag
	 */
	public function __construct ( $prefix, $maxWords = 4, $notAfter = '\p{L}\p{N}._\-' ) {
		$this->prefix = $prefix;

		$word = '[\p{L}\p{N}][\p{L}\p{N}\'’.\-]*';

		$this->pattern = sprintf(
				'/(?<![%s%s])%s(%s(?:[ \x{00A0}]+%s){0,%d})/u',
				$notAfter,
				preg_quote( $prefix, '/' ),
				preg_quote( $prefix, '/' ),
				$word,
				$word,
				max( 0, $maxWords - 1 )
		);
	}

	/**
	 * @return string
	 */
	public function getPrefix () {
		return $this->prefix;
	}

	/**
	 * Parcourt le texte et le reconstruit : chaque tag reconnu passe par
	 * $onTag, tout le reste par $onText.
	 *
	 * Seule la part du texte qui nomme quelque chose est consommée ; ce qui
	 * la suivait dans la phrase est rendu intact. « Merci @Jeanne Réserve. »
	 * garde son point, et « @Jeanne, tu peux ? » garde sa virgule et sa
	 * question.
	 *
	 * @param string   $text
	 * @param array    $subjects ce que le texte peut nommer, indexé par nom replié
	 * @param callable $onTag    ( mixed $subject, string $matched ): string
	 * @param callable $onText   ( string $text ): string
	 *
	 * @return string
	 */
	public function scan ( $text, array $subjects, callable $onTag, callable $onText = NULL ) {
		$text   = (string) $text;
		$onText = $onText ?: static function ( $chunk ) {
			return $chunk;
		};

		if ( empty( $subjects ) || ( strpos( $text, $this->prefix ) === FALSE ) ) {
			return $onText( $text );
		}

		$out    = '';
		$offset = 0;

		while (
				( $offset <= strlen( $text ) )
				&& preg_match( $this->pattern, $text, $matches, PREG_OFFSET_CAPTURE, $offset )
		) {
			$start   = $matches[ 0 ][ 1 ];
			$matched = $matches[ 0 ][ 0 ];

			$out .= $onText( substr( $text, $offset, $start - $offset ) );

			$subject = NULL;
			$read    = NULL;

			foreach ( $this->labels( $matches[ 1 ][ 0 ] ) as $label => $candidate ) {
				$key = $this->fold( $label );

				if ( isset( $subjects[ $key ] ) ) {
					$subject = $subjects[ $key ];
					$read    = $candidate;

					break;
				}
			}

			if ( ( $subject === NULL ) || ( $read === NULL ) || ( $read === '' ) ) {
				$out .= $onText( $matched );
				$offset = $start + strlen( $matched );

				continue;
			}

			$consumed = $this->prefix . $read;

			$out .= $onTag( $subject, $consumed );
			$offset = $start + strlen( $consumed );
		}

		return $out . $onText( substr( $text, $offset ) );
	}

	/**
	 * Tout ce que ce texte peut nommer, indexé par nom replié — de quoi faire
	 * une seule requête, quelle que soit la longueur du message.
	 *
	 * @param string $text
	 *
	 * @return string[] nom replié => nom tel qu'il a été écrit
	 */
	public function labelsIn ( $text ) {
		$labels = [];

		if ( !preg_match_all( $this->pattern, (string) $text, $matches ) ) {
			return [];
		}

		foreach ( $matches[ 1 ] as $candidate ) {
			foreach ( array_keys( $this->labels( $candidate ) ) as $label ) {
				$labels[ $this->fold( $label ) ] = $label;
			}
		}

		return array_slice( $labels, 0, self::MAX_LABELS, TRUE );
	}

	/**
	 * Ce qu'un « @… » ou un « #… » capturé peut désigner, le plus long
	 * d'abord : « Jeanne Réserve du Marais » avant « Jeanne Réserve » avant
	 * « Jeanne ».
	 *
	 * Chaque nom est donné avec le texte exact dont il est tiré, pour que ce
	 * qui le suivait dans la phrase puisse être rendu intact.
	 *
	 * @param string $candidate
	 *
	 * @return string[] nom => texte dont il est lu
	 */
	public function labels ( $candidate ) {
		if ( !preg_match_all( '/[^\s]+/u', (string) $candidate, $matches, PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$words  = $matches[ 0 ];
		$labels = [];

		for ( $length = count( $words ); $length > 0; $length-- ) {
			$last = $words[ $length - 1 ];
			$raw  = substr( $candidate, 0, $last[ 1 ] + strlen( $last[ 0 ] ) );

			// Avec la ponctuation finale et sans : « Jeanne R. » est un nom,
			// le point de « merci @Jeanne Réserve. » n'en est pas un.
			foreach ( [ $raw, preg_replace( self::TRAILING, '', $raw ) ] as $read ) {
				if ( ( $read === NULL ) || ( $read === '' ) ) {
					continue;
				}

				$label = preg_replace( '/\s+/u', ' ', $read );

				if ( !isset( $labels[ $label ] ) ) {
					$labels[ $label ] = $read;
				}
			}
		}

		return $labels;
	}

	/**
	 * Ni la casse ni les accents ne distinguent deux noms : « @jeanne
	 * reserve » doit atteindre Jeanne Réserve, comme la base elle-même
	 * compare deux noms.
	 *
	 * @param string $label
	 *
	 * @return string
	 */
	public function fold ( $label ) {
		$label = preg_replace( '/[ \x{00A0}\t]+/u', ' ', trim( (string) $label ) );

		return strtr( mb_strtolower( $label ), [
				'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
				'ç' => 'c',
				'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
				'î' => 'i', 'ï' => 'i', 'ì' => 'i', 'í' => 'i',
				'ô' => 'o', 'ö' => 'o', 'ò' => 'o', 'ó' => 'o', 'õ' => 'o',
				'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
				'ÿ' => 'y',
				'ñ' => 'n',
				'œ' => 'oe', 'æ' => 'ae',
				'’' => '\'',
		] );
	}

	/**
	 * Le texte lisible d'un message, balises remplacées par une espace pour
	 * que deux mots séparés par du balisage ne se retrouvent pas collés.
	 *
	 * @param string $body
	 *
	 * @return string
	 */
	public function toText ( $body ) {
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $body );

		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
