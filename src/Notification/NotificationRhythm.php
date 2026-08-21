<?php

namespace App\Notification;

/**
 * Quand part l'e-mail que porte un niveau : tout de suite, tous les jours, ou
 * le lundi.
 *
 * Depuis #40 le rythme n'est plus un réglage à lui : il est la part e-mail
 * d'un NotificationLevel, et se choisit donc catégorie par catégorie et groupe
 * par groupe. Cette classe garde ce qui, lui, reste commun à toute la
 * plateforme — le jour où part l'hebdomadaire — et sait encore lire l'ancien
 * champ `discussionRhythm`. (#34, #38)
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
	 * Le résumé, une fois par jour.
	 */
	const DAILY = 'daily';

	/**
	 * Same summary, but only once a week — for those to whom a daily e-mail
	 * is still one e-mail too many. (#38)
	 */
	const WEEKLY = 'weekly';

	/**
	 * Le nom que portait le quotidien avant #40, dans le champ
	 * `discussionRhythm`. Jamais écrit, encore lu.
	 */
	const LEGACY_DIGEST = 'digest';

	/**
	 * Le rythme d'un e-mail dont personne n'a rien dit : une fois par jour.
	 * (#40)
	 */
	const DEFAULT_RHYTHM = self::DAILY;

	/**
	 * @return string[]
	 */
	public static function all () {
		return [ self::IMMEDIATE, self::DAILY, self::WEEKLY ];
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

	/**
	 * Une valeur qu'on accepte de lire, l'ancien « digest » compris.
	 *
	 * @param string $rhythm
	 *
	 * @return bool
	 */
	public static function stored ( $rhythm ) {
		return self::exists( $rhythm ) || ( $rhythm === self::LEGACY_DIGEST );
	}
}
