<?php

namespace App\Notification;

/**
 * Ce qu'un membre demande sur une catégorie de contenu : rien, la plateforme
 * seule, ou la plateforme et un e-mail — et, dans ce dernier cas, à quel
 * rythme cet e-mail part. (#38)
 *
 * Une seule liste plutôt que deux réglages à croiser : « aucune notification »,
 * « sur la plateforme seulement », « e-mail immédiat », « résumé quotidien »,
 * « résumé hebdomadaire ». Le rythme se choisit donc catégorie par catégorie —
 * suivre une commission message par message et ne lire ses documents qu'une
 * fois par semaine est une demande courante, et elle était impossible tant que
 * le rythme était unique.
 *
 * Avant #38 la valeur stockée valait « email » et le rythme vivait à part, sur
 * le membre, dans `discussionRhythm`. Cette valeur-là n'est plus écrite, mais
 * elle est encore lue : voir self::fromLegacy().
 */
final class NotificationLevel {
	/**
	 * Nothing at all.
	 */
	const NONE = 'none';

	/**
	 * Shown on the platform, never sent by e-mail.
	 */
	const APP = 'app';

	/**
	 * Un e-mail par contenu, au moment où il paraît.
	 */
	const IMMEDIATE = 'immediate';

	/**
	 * Rejoint le résumé, qui part tous les jours.
	 */
	const DAILY = 'daily';

	/**
	 * Le même résumé, mais le lundi seulement.
	 */
	const WEEKLY = 'weekly';

	/**
	 * La valeur écrite avant #38, quand le niveau ne disait pas le rythme.
	 * Jamais proposée, toujours lue.
	 */
	const LEGACY_EMAIL = 'email';

	/**
	 * Ce qu'on reçoit sans avoir rien choisi : le résumé quotidien. Un e-mail
	 * par jour prévient de tout sans remplir une boîte aux lettres — c'est le
	 * défaut demandé en #38, là où #34 laissait l'immédiat.
	 */
	const DEFAULT_LEVEL = self::DAILY;

	/**
	 * @return string[]
	 */
	public static function all () {
		return [ self::NONE, self::APP, self::IMMEDIATE, self::DAILY, self::WEEKLY ];
	}

	/**
	 * Une valeur qu'on accepte d'écrire : ce que la page des paramètres a le
	 * droit de renvoyer.
	 *
	 * @param string $level
	 *
	 * @return bool
	 */
	public static function exists ( $level ) {
		return in_array( $level, self::all(), TRUE );
	}

	/**
	 * Une valeur qu'on accepte de lire, l'ancienne comprise. Sert à distinguer
	 * « ce groupe n'a rien dit » de « ce groupe a dit quelque chose, écrit
	 * dans l'ancien vocabulaire ».
	 *
	 * @param string $level
	 *
	 * @return bool
	 */
	public static function stored ( $level ) {
		return self::exists( $level ) || ( $level === self::LEGACY_EMAIL );
	}

	/**
	 * Traduit ce qui est en base dans le vocabulaire d'aujourd'hui.
	 *
	 * Le seul cas qui demande à réfléchir est l'ancien « email », qui ne dit
	 * pas son rythme : on le lit dans le réglage que le membre avait à côté.
	 * Et comme, avant #38, l'immédiat ne valait que pour les discussions — une
	 * page, une actualité, un document partaient dans le résumé quel que soit
	 * ce réglage — on ne le transpose que sur cette catégorie-là. Personne ne
	 * se met ainsi à recevoir des e-mails qu'il ne recevait pas.
	 *
	 * @param string|null $level    ce qui est stocké
	 * @param string|null $rhythm   l'ancien discussionRhythm, s'il a été choisi
	 * @param string|null $category la catégorie concernée
	 *
	 * @return string|null one of the levels, or NULL when nothing was stored
	 */
	public static function fromLegacy ( $level, $rhythm = NULL, $category = NULL ) {
		if ( self::exists( $level ) ) {
			return $level;
		}

		if ( $level !== self::LEGACY_EMAIL ) {
			return NULL;
		}

		// Rien de choisi à côté : le nouveau défaut s'applique, et c'est bien
		// la bascule voulue en #38.
		if ( !NotificationRhythm::stored( $rhythm ) ) {
			return self::DEFAULT_LEVEL;
		}

		if ( $rhythm === NotificationRhythm::WEEKLY ) {
			return self::WEEKLY;
		}

		if ( ( $rhythm === NotificationRhythm::IMMEDIATE ) && ( $category === NotificationCategory::DISCUSSIONS ) ) {
			return self::IMMEDIATE;
		}

		return self::DAILY;
	}

	/**
	 * @param string $level
	 *
	 * @return bool
	 */
	public static function showsOnPlatform ( $level ) {
		return self::stored( $level ) && ( $level !== self::NONE );
	}

	/**
	 * @param string $level
	 *
	 * @return bool
	 */
	public static function sendsEmail ( $level ) {
		return in_array( $level, [ self::IMMEDIATE, self::DAILY, self::WEEKLY, self::LEGACY_EMAIL ], TRUE );
	}

	/**
	 * Cet e-mail part-il au moment où le contenu paraît, plutôt que dans un
	 * résumé ?
	 *
	 * @param string $level
	 *
	 * @return bool
	 */
	public static function sendsNow ( $level ) {
		return $level === self::IMMEDIATE;
	}

	/**
	 * Le rythme que porte ce niveau, ou NULL s'il n'envoie pas d'e-mail.
	 *
	 * @param string $level
	 *
	 * @return string|null one of NotificationRhythm
	 */
	public static function rhythm ( $level ) {
		if ( !self::sendsEmail( $level ) ) {
			return NULL;
		}

		// L'ancien « email » qui aurait échappé à la traduction vaut ce que
		// vaut le défaut : il rejoint le résumé quotidien plutôt que de rester
		// sans jour d'envoi.
		if ( $level === self::LEGACY_EMAIL ) {
			return NotificationRhythm::DAILY;
		}

		return $level;
	}
}
