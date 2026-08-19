<?php

namespace App\Notification;

/**
 * The kinds of content a member can be warned about, inside a given group.
 */
final class NotificationCategory {
	const DISCUSSIONS = 'discussions';
	const PAGES       = 'pages';
	const ARTICLES    = 'articles';
	const DOCUMENTS   = 'documents';

	/**
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
	 * @param string $category
	 *
	 * @return bool
	 */
	public static function exists ( $category ) {
		return in_array( $category, self::all(), TRUE );
	}
}
