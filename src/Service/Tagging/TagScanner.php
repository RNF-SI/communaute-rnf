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
	 * La forme entre guillemets : « #"Guide : gestion des mares" ».
	 *
	 * Elle existe parce qu'un nom nu s'arrête à la première ponctuation
	 * interne — c'est ce qui permet à « merci @Jeanne, tu peux ? » de garder
	 * sa virgule —, si bien qu'un titre à deux points n'était adressable
	 * d'aucune façon. Les guillemets disent où le nom finit ; la question ne
	 * se pose plus.
	 *
	 * Trois paires, parce qu'on écrit en français : le guillemet droit, les
	 * chevrons, et les guillemets courbes que dépose un copier-coller depuis
	 * un traitement de texte. Aucune ne franchit une fin de ligne : un
	 * guillemet resté ouvert avalerait le reste du message.
	 */
	private const QUOTED = '"[^"\n]{1,200}"|«[^»\n]{1,200}»|“[^”\n]{1,200}”';

	/**
	 * Ce qui encadre un nom, et l'espace qui traîne à l'intérieur.
	 */
	private const QUOTES = '/^["«“][ \x{00A0}]*|[ \x{00A0}]*["»”]$/u';

	/**
	 * @var string « @ » ou « # »
	 */
	private $prefix;

	/**
	 * @var string
	 */
	private $pattern;

	/**
	 * @var bool ce scanner-ci lit-il la forme entre guillemets ?
	 */
	private $quoted;

	/**
	 * @param string $prefix   le caractère qui ouvre un tag
	 * @param int    $maxWords combien de mots un tag peut compter
	 * @param string $notAfter classe de caractères après lesquels le préfixe
	 *                         n'ouvre pas un tag
	 * @param bool   $quoted   accepter aussi la forme entre guillemets, qui
	 *                         porte les titres que le comptage de mots ne
	 *                         sait pas atteindre
	 */
	public function __construct ( $prefix, $maxWords = 4, $notAfter = '\p{L}\p{N}._\-', $quoted = FALSE ) {
		$this->prefix = $prefix;
		$this->quoted = (bool) $quoted;

		$word = '[\p{L}\p{N}][\p{L}\p{N}\'’.\-]*';
		$run  = sprintf(
				'(?P<words>%s(?:[ \x{00A0}]+%s){0,%d})',
				$word,
				$word,
				max( 0, $maxWords - 1 )
		);

		// Les guillemets d'abord : « #"Jean" » doit se lire comme un nom
		// entre guillemets, non comme un tag vide suivi de texte.
		$this->pattern = sprintf(
				'/(?<![%s%s])%s(?:%s%s)/u',
				$notAfter,
				preg_quote( $prefix, '/' ),
				preg_quote( $prefix, '/' ),
				$this->quoted ? '(?P<quoted>' . self::QUOTED . ')|' : '',
				$run
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

			$found = $this->read( $matches, $subjects );

			if ( $found === NULL ) {
				$out .= $onText( $matched );
				$offset = $start + strlen( $matched );

				continue;
			}

			list( $subject, $consumed ) = $found;

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

		if ( !preg_match_all( $this->pattern, (string) $text, $all, PREG_SET_ORDER ) ) {
			return [];
		}

		foreach ( $all as $matches ) {
			$quoted = $this->captured( $matches, 'quoted' );

			// Entre guillemets, il n'y a rien à deviner : le nom est celui
			// qu'on a encadré, et lui seul. C'est tout l'intérêt de la forme.
			if ( $quoted !== NULL ) {
				$label = $this->unquote( $quoted );

				if ( $label !== '' ) {
					$labels[ $this->fold( $label ) ] = $label;
				}

				continue;
			}

			foreach ( array_keys( $this->labels( (string) $this->captured( $matches, 'words' ) ) ) as $label ) {
				$labels[ $this->fold( $label ) ] = $label;
			}
		}

		return array_slice( $labels, 0, self::MAX_LABELS, TRUE );
	}

	/**
	 * Ce que ce tag désigne dans le dictionnaire, et le texte exact qu'il
	 * occupe — celui-là seul est consommé, ce qui le suivait dans la phrase
	 * est rendu intact.
	 *
	 * @param array $matches  ce que le motif a capturé
	 * @param array $subjects ce que le texte peut nommer, indexé par nom replié
	 *
	 * @return array|null [ mixed $subject, string $consumed ]
	 */
	private function read ( array $matches, array $subjects ) {
		$quoted = $this->captured( $matches, 'quoted' );

		if ( $quoted !== NULL ) {
			$key = $this->fold( $this->unquote( $quoted ) );

			// Les guillemets font partie du tag : les laisser hors du texte
			// consommé les rendrait au message comme s'ils étaient à lui.
			return isset( $subjects[ $key ] )
					? [ $subjects[ $key ], $this->prefix . $quoted ]
					: NULL;
		}

		foreach ( $this->labels( (string) $this->captured( $matches, 'words' ) ) as $label => $candidate ) {
			$key = $this->fold( $label );

			if ( isset( $subjects[ $key ] ) && ( $candidate !== '' ) ) {
				return [ $subjects[ $key ], $this->prefix . $candidate ];
			}
		}

		return NULL;
	}

	/**
	 * Un groupe nommé du motif, ou NULL s'il n'a pas participé.
	 *
	 * Les deux formes de capture se présentent ici : avec
	 * PREG_OFFSET_CAPTURE, un groupe est une paire dont le décalage vaut -1
	 * quand il n'a rien pris ; sans, c'est une chaîne, vide dans le même cas.
	 *
	 * @param array  $matches
	 * @param string $name
	 *
	 * @return string|null
	 */
	private function captured ( array $matches, $name ) {
		if ( !isset( $matches[ $name ] ) ) {
			return NULL;
		}

		$value = $matches[ $name ];

		if ( is_array( $value ) ) {
			return ( $value[ 1 ] === -1 ) ? NULL : $value[ 0 ];
		}

		return ( $value === '' ) ? NULL : $value;
	}

	/**
	 * Le nom que ces guillemets encadrent.
	 *
	 * Retiré à l'expression régulière et non à substr() : chevrons et
	 * guillemets courbes tiennent sur plusieurs octets, et substr()
	 * trancherait au milieu.
	 *
	 * @param string $quoted
	 *
	 * @return string
	 */
	private function unquote ( $quoted ) {
		$label = preg_replace( self::QUOTES, '', (string) $quoted );

		return preg_replace( '/[\s\x{00A0}]+/u', ' ', trim( (string) $label ) );
	}

	/**
	 * Le tag à écrire pour désigner ce nom-ci : nu quand il se relit tel
	 * quel, entre guillemets sinon.
	 *
	 * C'est ici que le bouton « Insérer un lien » et la main de celui qui
	 * tape se rejoignent. Le serveur ne devine pas la syntaxe côté
	 * navigateur : il la donne, et elle est celle que ce même scanner relira.
	 *
	 * @param string $label
	 *
	 * @return string
	 */
	public function write ( $label ) {
		$label = $this->unquote( $label );

		if ( $label === '' ) {
			return '';
		}

		if ( $this->reads( $label ) ) {
			return $this->prefix . $label;
		}

		if ( !$this->quoted ) {
			// Ce scanner ne sait pas lire de guillemets : le tag nu est ce
			// qu'on peut faire de mieux, et le titre reste lisible.
			return $this->prefix . $label;
		}

		// Un titre qui porte déjà l'une des paires est encadré par une autre.
		foreach ( [ [ '"', '"' ], [ '«', '»' ], [ '“', '”' ] ] as $pair ) {
			if ( ( mb_strpos( $label, $pair[ 0 ] ) === FALSE ) && ( mb_strpos( $label, $pair[ 1 ] ) === FALSE ) ) {
				return $this->prefix . $pair[ 0 ] . $label . $pair[ 1 ];
			}
		}

		// Trois paires déjà dans le titre : il n'y a plus rien à emprunter.
		return $this->prefix . $label;
	}

	/**
	 * Ce nom nu, précédé du préfixe, se relit-il en entier ?
	 *
	 * La question est posée au motif lui-même plutôt qu'à une liste de
	 * ponctuations : c'est la seule façon que la réponse ne dérive pas du
	 * jour où le motif changera.
	 *
	 * @param string $label
	 *
	 * @return bool
	 */
	private function reads ( $label ) {
		$text = $this->prefix . $label;

		if ( !preg_match( $this->pattern, $text, $matches ) || ( $matches[ 0 ] !== $text ) ) {
			return FALSE;
		}

		// Le motif a tout pris, encore faut-il que labels() en tire le nom
		// entier : « #Note v1. » se lit, « Note v1. » doit s'y trouver.
		return array_key_exists( $label, $this->labels( (string) $this->captured( $matches, 'words' ) ) );
	}

	/**
	 * Le tag tel qu'on le montre à celui qui lit : sans ses guillemets.
	 *
	 * Ils disent où le nom finit, ce qui n'intéresse que la lecture ; les
	 * afficher ferait payer au lecteur une syntaxe qui ne le regarde pas. Le
	 * message conserve, lui, ce qui a été écrit.
	 *
	 * @param string $consumed le texte du tag, préfixe compris
	 *
	 * @return string
	 */
	public function readable ( $consumed ) {
		$consumed = (string) $consumed;
		$label    = mb_substr( $consumed, mb_strlen( $this->prefix ) );

		if ( !preg_match( '/^["«“]/u', $label ) ) {
			return $consumed;
		}

		return $this->prefix . $this->unquote( $label );
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
