<?php

namespace App\Notification;

/**
 * The kinds of content a member can be warned about, inside a given group.
 *
 * Depuis la messagerie, toutes ne vivent plus dans un groupe : « messages »
 * désigne ce qui arrive dans la boîte, et une boîte n'appartient à aucun
 * groupe. D'où deux listes plutôt qu'une — self::all() est celle qu'un groupe
 * sait régler, self::general() est celle que la page des paramètres propose
 * en réglage général. Confondre les deux ferait apparaître, sous chaque
 * groupe, un réglage « messages » qui ne voudrait rien dire.
 */
final class NotificationCategory {
	const DISCUSSIONS = 'discussions';
	const PAGES       = 'pages';
	const ARTICLES    = 'articles';
	const DOCUMENTS   = 'documents';

	/**
	 * Les messages privés. Réglable une seule fois, pour toute la plateforme.
	 */
	const MESSAGES = 'messages';

	/**
	 * Ce qu'un groupe sait régler pour lui-même.
	 *
	 * @return string[]
	 */
	public static function all () {
		return [
				self::DISCUSSIONS,
				self::PAGES,
				self::ARTICLES,
				self::DOCUMENTS,
		];
	}

	/**
	 * Tout ce qui se règle en général, la messagerie comprise.
	 *
	 * @return string[]
	 */
	public static function general () {
		return array_merge( self::all(), [ self::MESSAGES ] );
	}

	/**
	 * @param string $category
	 *
	 * @return bool
	 */
	public static function exists ( $category ) {
		return in_array( $category, self::general(), TRUE );
	}

	/**
	 * Cette catégorie peut-elle être réglée groupe par groupe ?
	 *
	 * @param string $category
	 *
	 * @return bool
	 */
	public static function inGroup ( $category ) {
		return in_array( $category, self::all(), TRUE );
	}

	/**
	 * Ce que vaut cette catégorie tant que personne n'a rien choisi.
	 *
	 * Le quotidien partout, sauf la messagerie : quelqu'un qui écrit
	 * directement à une personne attend une réponse, et lui répondre le
	 * lendemain soir n'est pas une conversation.
	 *
	 * @param string $category
	 *
	 * @return string one of NotificationLevel
	 */
	public static function defaultLevel ( $category ) {
		return ( $category === self::MESSAGES )
				? NotificationLevel::IMMEDIATE
				: NotificationLevel::DEFAULT_LEVEL;
	}
}
