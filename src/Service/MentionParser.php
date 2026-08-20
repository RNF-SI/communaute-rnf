<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Usergroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Reads the « @Prénom Nom » someone wrote in a message and says who they
 * meant. (#37)
 *
 * A mention is stored as plain text rather than as markup: it stays readable
 * in the e-mail copy of the message, survives a copy-paste, and does not
 * depend on the editor having behaved. The link is rebuilt at display time by
 * matching the text against the members of the group.
 */
class MentionParser {
	/**
	 * An « @ » followed by up to four words. Four is what the longest names in
	 * the network need; going further would start swallowing the sentence.
	 *
	 * The « @ » must not follow a letter, a digit or a dot, otherwise every
	 * e-mail address written in a message would read as a mention.
	 */
	private const PATTERN = '/(?<![\p{L}\p{N}@._\-])@([\p{L}\p{N}][\p{L}\p{N}\'’.\-]*(?:[ \x{00A0}]+[\p{L}\p{N}][\p{L}\p{N}\'’.\-]*){0,3})/u';

	/**
	 * What may trail a name without being part of it. Trimmed with a regular
	 * expression rather than rtrim(): several of these characters take more
	 * than one byte, and rtrim() would cut through the middle of the next one.
	 */
	private const TRAILING = '/[\s.,;:!?…"\'’»)\]}]+$/u';

	/**
	 * A message naming more people than this is not addressing anyone: stop
	 * looking rather than query the database for a whole paragraph.
	 */
	private const MAX_LABELS = 50;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $router;

	public function __construct ( EntityManagerInterface $manager, UrlGeneratorInterface $router ) {
		$this->manager = $manager;
		$this->router  = $router;
	}

	/**
	 * The members named in a message, each one once.
	 *
	 * @param string                     $body
	 * @param \App\Entity\Usergroup|null $group
	 * @param \App\Entity\User|null      $author who wrote it, never a mention of themselves
	 *
	 * @return \App\Entity\User[]
	 */
	public function find ( $body, Usergroup $group = NULL, User $author = NULL ) {
		$found = [];
		$text  = $this->toText( $body );

		$this->walk( $text, $this->members( $text, $group ), function ( User $user, $matched ) use ( &$found, $author ) {
			if ( $author && ( $author->getId() !== NULL ) && ( $author->getId() === $user->getId() ) ) {
				return $matched;
			}

			$found[ $user->getId() ] = $user;

			return $matched;
		} );

		return array_values( $found );
	}

	/**
	 * The same message, with every recognised mention turned into a link to
	 * the directory.
	 *
	 * @param string                     $body
	 * @param \App\Entity\Usergroup|null $group
	 *
	 * @return string
	 */
	public function render ( $body, Usergroup $group = NULL ) {
		$body = (string) $body;

		if ( strpos( $body, '@' ) === FALSE ) {
			return $body;
		}

		// Split the markup away from the text so a mention is never looked for
		// inside an attribute — an image title or a link address would be
		// rewritten into broken HTML.
		$parts = preg_split( '/(<[^>]*>)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( $parts === FALSE ) {
			return $body;
		}

		// Looked up once for the whole message, not once per chunk of text
		// between two tags.
		$members = $this->members( $this->toText( $body ), $group );

		if ( empty( $members ) ) {
			return $body;
		}

		foreach ( $parts as $index => $part ) {
			if ( ( $index % 2 ) === 1 ) {
				continue;
			}

			$parts[ $index ] = $this->walk( $part, $members, function ( User $user, $matched ) {
				return sprintf(
						'<a class="mention" href="%s">%s</a>',
						htmlspecialchars(
								$this->router->generate( 'member', [ 'user_id' => $user->getId() ] ),
								ENT_QUOTES
						),
						$matched
				);
			} );
		}

		return implode( '', $parts );
	}

	/**
	 * Walks the mentions of a piece of text, handing each recognised one to
	 * the caller and putting back what it returns.
	 *
	 * @param string             $text
	 * @param \App\Entity\User[] $members indexed by folded name
	 * @param callable           $callback ( User $user, string $matched ): string
	 *
	 * @return string
	 */
	private function walk ( $text, array $members, callable $callback ) {
		$text = (string) $text;

		if ( empty( $members ) || ( strpos( $text, '@' ) === FALSE ) ) {
			return $text;
		}

		$replaced = preg_replace_callback(
				self::PATTERN,
				function ( $matches ) use ( $members, $callback ) {
					foreach ( $this->labels( $matches[ 1 ] ) as $label => $read ) {
						$key = $this->fold( $label );

						if ( !isset( $members[ $key ] ) ) {
							continue;
						}

						// Only the part that names somebody is consumed; what
						// followed it in the sentence is put back untouched.
						$rest = substr( $matches[ 0 ], strlen( '@' . $read ) );

						return $callback( $members[ $key ], '@' . $read ) . $rest;
					}

					return $matches[ 0 ];
				},
				$text
		);

		return ( $replaced === NULL ) ? $text : $replaced;
	}

	/**
	 * The members of the group whose name appears in the text, indexed by
	 * folded name. One query, whatever the size of the group.
	 *
	 * @param string                $text
	 * @param \App\Entity\Usergroup $group
	 *
	 * @return \App\Entity\User[]
	 */
	private function members ( $text, Usergroup $group = NULL ) {
		$labels = [];

		if ( !$group || !preg_match_all( self::PATTERN, $text, $matches ) ) {
			return [];
		}

		foreach ( $matches[ 1 ] as $candidate ) {
			foreach ( array_keys( $this->labels( $candidate ) ) as $label ) {
				$labels[ $this->fold( $label ) ] = $label;
			}
		}

		if ( empty( $labels ) ) {
			return [];
		}

		$labels = array_slice( $labels, 0, self::MAX_LABELS );

		$users = $this->manager->getRepository( User::class )
							   ->findMentionable( $group, array_values( $labels ) );

		$members = [];

		foreach ( $users as $user ) {
			foreach ( [ $user->getName(), $user->getDisplayName() ] as $name ) {
				$key = $this->fold( (string) $name );

				// A name nobody wrote is of no use, and the first member
				// answering to a name wins over the next: an ambiguity is
				// settled the same way at every display.
				if ( ( $key === '' ) || !isset( $labels[ $key ] ) || isset( $members[ $key ] ) ) {
					continue;
				}

				$members[ $key ] = $user;
			}
		}

		return $members;
	}

	/**
	 * The names a captured « @… » may stand for, longest first: « Jeanne
	 * Reserve du Marais » before « Jeanne Reserve » before « Jeanne ».
	 *
	 * Each name is given with the exact text it stands for, so that what
	 * followed it in the sentence can be handed back untouched.
	 *
	 * @param string $candidate
	 *
	 * @return string[] name => text it was read from
	 */
	private function labels ( $candidate ) {
		if ( !preg_match_all( '/[^\s]+/u', $candidate, $matches, PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$words  = $matches[ 0 ];
		$labels = [];

		for ( $length = count( $words ); $length > 0; $length-- ) {
			$last = $words[ $length - 1 ];
			$raw  = substr( $candidate, 0, $last[ 1 ] + strlen( $last[ 0 ] ) );

			// With the closing punctuation and without: « Jeanne R. » is a
			// name, the dot of « merci @Jeanne Reserve. » is not.
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
	 * Case and accents are not what tells two members apart: « @jeanne
	 * reserve » must reach Jeanne Réserve, the way the database itself
	 * compares two names.
	 *
	 * @param string $label
	 *
	 * @return string
	 */
	private function fold ( $label ) {
		$label = preg_replace( '/[ \x{00A0}\t]+/u', ' ', trim( (string) $label ) );

		$label = strtr( mb_strtolower( $label ), [
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

		return $label;
	}

	/**
	 * The readable text of a message, tags replaced by a space so two words
	 * separated by markup do not end up glued together.
	 *
	 * @param string $body
	 *
	 * @return string
	 */
	private function toText ( $body ) {
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $body );

		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
