<?php

namespace App\Notification;

/**
 * When the e-mails of a discussion leave. A single choice per member, all
 * groups taken together. (#34)
 */
final class NotificationRhythm {
	/**
	 * The day the weekly summary goes out, ISO-8601 style: 1 is Monday.
	 *
	 * A fixed day, the same for everybody, rather than a choice left to each
	 * member: the support can then answer « il part le lundi matin » without
	 * having to look at an account. (#38)
	 */
	const WEEKLY_DAY = 1;

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
	 * Same summary, but only once a week — for those to whom a daily e-mail
	 * is still one e-mail too many. (#38)
	 */
	const WEEKLY = 'weekly';

	/**
	 * Nobody sees their current behaviour change without having asked.
	 */
	const DEFAULT_RHYTHM = self::IMMEDIATE;

	/**
	 * @return string[]
	 */
	public static function all () {
		return [ self::IMMEDIATE, self::DIGEST, self::WEEKLY ];
	}

	/**
	 * Whether a summary at this rhythm leaves on the given day.
	 *
	 * The command runs every day: it is here that a weekly reader is passed
	 * over six days out of seven. Their notifications stay waiting, they are
	 * not lost — the next Monday carries them all.
	 *
	 * @param string             $rhythm
	 * @param \DateTimeInterface $day
	 *
	 * @return bool
	 */
	public static function sendsOn ( $rhythm, \DateTimeInterface $day ) {
		if ( $rhythm === self::WEEKLY ) {
			return (int) $day->format( 'N' ) === self::WEEKLY_DAY;
		}

		// L'immédiat ne passe pas par le résumé, mais s'il s'y trouve — un
		// message posté avant un changement de réglage — rien ne justifie de
		// le retenir un jour de plus.
		return TRUE;
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
