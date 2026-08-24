<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Usergroup;
use App\Service\Tagging\TagScanner;
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
 *
 * La lecture proprement dite — où commence un nom, où il s'arrête, ce qu'on
 * ignore de la casse et des accents — vit dans TagScanner, que la messagerie
 * emploie aussi pour ses « # ». Ce qui reste ici est ce qui n'appartient qu'à
 * la mention dans un groupe : chercher parmi les membres, et pointer vers
 * l'annuaire.
 */
class MentionParser {
	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $router;

	/**
	 * Quatre mots : ce dont les noms les plus longs du réseau ont besoin.
	 * Au-delà, on commencerait à avaler la phrase.
	 *
	 * @var \App\Service\Tagging\TagScanner
	 */
	private $scanner;

	public function __construct ( EntityManagerInterface $manager, UrlGeneratorInterface $router ) {
		$this->manager = $manager;
		$this->router  = $router;
		$this->scanner = new TagScanner( '@', 4 );
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
		$text  = $this->scanner->toText( $body );

		$this->scanner->scan(
				$text,
				$this->members( $text, $group ),
				function ( User $user, $matched ) use ( &$found, $author ) {
					if ( $author && ( $author->getId() !== NULL ) && ( $author->getId() === $user->getId() ) ) {
						return $matched;
					}

					$found[ $user->getId() ] = $user;

					return $matched;
				}
		);

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
		$members = $this->members( $this->scanner->toText( $body ), $group );

		if ( empty( $members ) ) {
			return $body;
		}

		foreach ( $parts as $index => $part ) {
			if ( ( $index % 2 ) === 1 ) {
				continue;
			}

			$parts[ $index ] = $this->scanner->scan( $part, $members, function ( User $user, $matched ) {
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
	 * The members of the group whose name appears in the text, indexed by
	 * folded name. One query, whatever the size of the group.
	 *
	 * @param string                $text
	 * @param \App\Entity\Usergroup $group
	 *
	 * @return \App\Entity\User[]
	 */
	private function members ( $text, Usergroup $group = NULL ) {
		if ( !$group ) {
			return [];
		}

		$labels = $this->scanner->labelsIn( $text );

		if ( empty( $labels ) ) {
			return [];
		}

		$users = $this->manager->getRepository( User::class )
							   ->findMentionable( $group, array_values( $labels ) );

		$members = [];

		foreach ( $users as $user ) {
			foreach ( [ $user->getName(), $user->getDisplayName() ] as $name ) {
				$key = $this->scanner->fold( (string) $name );

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
}
