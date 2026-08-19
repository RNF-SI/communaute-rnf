<?php

namespace App\Notification;

/**
 * When the e-mails of a discussion leave. A single choice per member, all
 * groups taken together. (#34)
 */
final class NotificationRhythm {
	/**
	 * One e-mail per message, carrying the Reply-To that makes answering from
	 * a mailbox possible.
	 */
	const IMMEDIATE = 'immediate';

	/**
	 * Discussion messages join the daily summary. One e-mail a day, but no
	 * more answering by e-mail.
	 */
	const DIGEST = 'digest';

	/**
	 * Nobody sees their current behaviour change without having asked.
	 */
	const DEFAULT_RHYTHM = self::IMMEDIATE;

	/**
	 * @return string[]
	 */
	public static function all () {
		return [ self::IMMEDIATE, self::DIGEST ];
	}

	/**
	 * @param string $rhythm
	 *
	 * @return bool
	 */
	public static function exists ( $rhythm ) {
		return in_array( $rhythm, self::all(), TRUE );
	}
}
