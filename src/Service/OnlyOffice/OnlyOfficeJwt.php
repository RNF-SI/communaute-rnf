<?php

namespace App\Service\OnlyOffice;

/**
 * La signature partagée avec le serveur de documents. (#43)
 *
 * OnlyOffice n'a pas de compte chez nous et nous n'en avons pas chez lui :
 * ce qui les relie est un secret commun. Toute configuration qu'on lui remet
 * porte sa signature, et toute requête qu'il nous adresse porte la sienne —
 * sans quoi n'importe qui sachant l'adresse du serveur pourrait lui faire
 * ouvrir n'importe quel fichier, et n'importe qui sachant la nôtre pourrait
 * nous faire enregistrer n'importe quel contenu.
 *
 * HS256 à la main plutôt qu'une bibliothèque : deux appels à `hash_hmac`, et
 * une dépendance de moins sur un projet en PHP 7.3.
 *
 * Sans secret configuré, le service se déclare éteint et rien n'est signé —
 * c'est le mode « JWT_ENABLED=false » d'OnlyOffice, à réserver à un serveur
 * de documents qui n'est joignable que depuis la machine.
 */
class OnlyOfficeJwt {
	/**
	 * @var string
	 */
	private $secret;

	public function __construct ( string $secret = '' ) {
		$this->secret = trim( $secret );
	}

	public function isEnabled (): bool {
		return $this->secret !== '';
	}

	/**
	 * @param array $payload
	 *
	 * @return string
	 */
	public function encode ( array $payload ): string {
		$segments = [
				self::encodeSegment( [ 'alg' => 'HS256', 'typ' => 'JWT' ] ),
				self::encodeSegment( $payload ),
		];

		$segments[] = self::base64( hash_hmac( 'sha256', implode( '.', $segments ), $this->secret, TRUE ) );

		return implode( '.', $segments );
	}

	/**
	 * @param string $jwt
	 *
	 * @return array|null la charge utile, ou NULL si la signature ne tient pas
	 */
	public function decode ( string $jwt ): ?array {
		$parts = explode( '.', trim( $jwt ) );

		if ( count( $parts ) !== 3 ) {
			return NULL;
		}

		list( $header, $payload, $signature ) = $parts;

		$expected = self::base64( hash_hmac( 'sha256', $header . '.' . $payload, $this->secret, TRUE ) );

		if ( !hash_equals( $expected, $signature ) ) {
			return NULL;
		}

		$decoded = json_decode( self::unbase64( $payload ), TRUE );

		return is_array( $decoded ) ? $decoded : NULL;
	}

	/**
	 * @param array $data
	 *
	 * @return string
	 */
	private static function encodeSegment ( array $data ): string {
		return self::base64( json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * @param string $raw
	 *
	 * @return string
	 */
	private static function base64 ( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $encoded
	 *
	 * @return string
	 */
	private static function unbase64 ( string $encoded ): string {
		$padded = str_pad( strtr( $encoded, '-_', '+/' ), (int) ( ceil( strlen( $encoded ) / 4 ) * 4 ), '=' );

		return (string) base64_decode( $padded, TRUE );
	}
}
