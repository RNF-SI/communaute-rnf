<?php

namespace App\Postmark;

use Swift_Mailer;

/**
 * Stands in for the real bulk transport outside production.
 *
 * The regular one posts straight to the Postmark API, which means it obeys
 * neither MAILER_URL nor delivery_addresses: locally, discussion e-mails
 * either vanish — no token — or leave for real. Neither lets anybody look at
 * what was actually sent.
 *
 * This one hands the messages to the configured mailer instead, so they land
 * wherever the environment says: a local catcher, a single redirect address,
 * or nowhere at all.
 */
class LocalBulkTransport extends BulkTransport {
	/**
	 * @var \Swift_Mailer
	 */
	private $mailer;

	public function __construct ( Swift_Mailer $mailer ) {
		$this->mailer = $mailer;

		parent::__construct( '' );
	}

	/**
	 * @param array $messages
	 *
	 * @return int
	 */
	public function sendMultiple ( array $messages ) {
		$sent = 0;

		foreach ( $messages as $message ) {
			$sent += (int) $this->mailer->send( $message );
		}

		return $sent;
	}
}
