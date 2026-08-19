<?php

namespace App\Notification;

/**
 * How far a notification goes: nowhere, on the platform only, or on the
 * platform and by e-mail.
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
	 * Shown on the platform and sent by e-mail.
	 */
	const EMAIL = 'email';

	/**
	 * What a member gets without having chosen anything. Warning on new
	 * content is the behaviour asked for in #34.
	 */
	const DEFAULT_LEVEL = self::EMAIL;

	/**
	 * @return string[]
	 */
	public static function all () {
		return [ self::NONE, self::APP, self::EMAIL ];
	}

	/**
	 * @param string $level
	 *
	 * @return bool
	 */
	public static function exists ( $level ) {
		return in_array( $level, self::all(), TRUE );
	}

	/**
	 * @param string $level
	 *
	 * @return bool
	 */
	public static function showsOnPlatform ( $level ) {
		return in_array( $level, [ self::APP, self::EMAIL ], TRUE );
	}

	/**
	 * @param string $level
	 *
	 * @return bool
	 */
	public static function sendsEmail ( $level ) {
		return $level === self::EMAIL;
	}
}
