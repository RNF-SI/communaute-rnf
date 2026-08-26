<?php

namespace App\Service\OnlyOffice;

/**
 * L'adresse par laquelle le serveur de documents va chercher un fichier, et
 * celle par laquelle il rend la version modifiée. (#43)
 *
 * Le serveur de documents n'est pas un navigateur : il n'a ni cookie ni
 * session, et les deux routes qu'il emploie sont donc ouvertes. Ce qui les
 * protège est ce jeton — l'identifiant du document, un droit, une échéance, et
 * la signature du tout par le secret de l'application. Un jeton fabriqué à la
 * main ne passe pas, un jeton périmé non plus.
 *
 * **Le droit est dans le jeton**, et c'est le point à ne pas perdre en
 * relisant : la configuration de l'éditeur est rendue dans la page, donc lue
 * par celui qui regarde. Un lecteur qui n'a pas le droit de modifier reçoit un
 * jeton de lecture seule, avec lequel la route d'enregistrement refuse — sans
 * cela, ouvrir un document en consultation donnerait de quoi le réécrire.
 *
 * Il porte l'identifiant du document et non celui du fichier : enregistrer une
 * version remplace le fichier, et un jeton attaché à l'ancien deviendrait
 * caduc au premier enregistrement, au milieu d'une séance d'édition.
 */
class OnlyOfficeToken {
	public const READ  = 'r';
	public const WRITE = 'w';

	/**
	 * Une séance d'édition peut durer. Une journée couvre un document laissé
	 * ouvert dans un onglet ; au-delà, l'éditeur redemande la page et un jeton
	 * neuf est émis.
	 */
	private const LIFETIME = 86400;

	/**
	 * @var string
	 */
	private $secret;

	public function __construct ( string $secret ) {
		$this->secret = $secret;
	}

	/**
	 * @param int    $documentId
	 * @param string $mode self::READ ou self::WRITE
	 * @param int    $now  injectable pour les tests
	 *
	 * @return string
	 */
	public function create ( int $documentId, string $mode, int $now = 0 ): string {
		$now = $now ?: time();

		$claims = sprintf( '%d.%s.%d', $documentId, $mode === self::WRITE ? self::WRITE : self::READ, $now + self::LIFETIME );

		return $claims . '.' . hash_hmac( 'sha256', $claims, $this->secret );
	}

	/**
	 * @param string $token
	 * @param int    $now injectable pour les tests
	 *
	 * @return array|null [ 'document' => int, 'mode' => string ], ou NULL
	 */
	public function read ( string $token, int $now = 0 ): ?array {
		$now = $now ?: time();

		$parts = explode( '.', $token );

		if ( count( $parts ) !== 4 ) {
			return NULL;
		}

		$signature = array_pop( $parts );
		$claims    = implode( '.', $parts );

		if ( !hash_equals( hash_hmac( 'sha256', $claims, $this->secret ), $signature ) ) {
			return NULL;
		}

		list( $documentId, $mode, $expiresAt ) = $parts;

		if ( (int) $expiresAt < $now ) {
			return NULL;
		}

		return [ 'document' => (int) $documentId, 'mode' => $mode ];
	}
}
