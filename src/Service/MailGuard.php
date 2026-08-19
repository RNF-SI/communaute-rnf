<?php

namespace App\Service;

/**
 * Refuses to hand an undeliverable address to the mail service.
 *
 * A copy of the production database is anonymised towards example.org, a
 * domain reserved by RFC 2606 that accepts nothing. Sending there produces a
 * hard bounce per message, and a mail provider suspends an account whose
 * bounce rate climbs — which would take the real platform down along with the
 * staging one. (#14)
 *
 * The guard only bites in production, staging included: locally, sending to
 * example.org is exactly what a mail catcher is for.
 */
class MailGuard {
	/**
	 * Reserved by RFC 2606 and RFC 6761: guaranteed never to accept mail.
	 */
	private const RESERVED_DOMAINS = [
			'example.com',
			'example.net',
			'example.org',
			'localhost',
	];

	private const RESERVED_SUFFIXES = [
			'.test',
			'.example',
			'.invalid',
			'.localhost',
			'.local',
	];

	/**
	 * @var bool
	 */
	private $enforced;

	public function __construct ( string $environment ) {
		$this->enforced = ( $environment === 'prod' );
	}

	/**
	 * @param string|null $email
	 *
	 * @return bool
	 */
	public function isDeliverable ( ?string $email ) {
		if ( !$this->enforced ) {
			return TRUE;
		}

		if ( empty( $email ) || ( strpos( $email, '@' ) === FALSE ) ) {
			return FALSE;
		}

		$domain = mb_strtolower( substr( strrchr( $email, '@' ), 1 ) );

		if ( in_array( $domain, self::RESERVED_DOMAINS, TRUE ) ) {
			return FALSE;
		}

		foreach ( self::RESERVED_SUFFIXES as $suffix ) {
			if ( substr( $domain, -strlen( $suffix ) ) === $suffix ) {
				return FALSE;
			}
		}

		return TRUE;
	}
}
