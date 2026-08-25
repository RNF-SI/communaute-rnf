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
 *
 * Et « messages » se distingue une seconde fois : c'est la seule catégorie
 * qui n'envoie **aucun** e-mail. Voir self::sendsEmail().
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
	 * Cette catégorie peut-elle donner lieu à un e-mail ?
	 *
	 * La messagerie, non — et c'est la seule. Un message privé se lit sur la
	 * plateforme, où il est déjà annoncé par la pastille et par le dock. Le
	 * doubler d'un e-mail multipliait le volume envoyé par le nombre de
	 * messages échangés, ce qu'un réseau de cette taille ne peut pas payer,
	 * pour prévenir de quelque chose que le destinataire voit en se
	 * connectant.
	 *
	 * Ce n'est donc pas un réglage laissé à chacun : la catégorie ne propose
	 * plus que « rien » ou « sur la plateforme ». Les autres catégories ne
	 * changent pas — une actualité paraît une fois, un message s'échange.
	 *
	 * @param string $category
	 *
	 * @return bool
	 */
	public static function sendsEmail ( $category ) {
		return $category !== self::MESSAGES;
	}

	/**
	 * Les niveaux qu'on propose sur cette catégorie.
	 *
	 * Deux listes, parce que toutes les catégories ne savent pas envoyer un
	 * e-mail. C'est ici, et pas dans le gabarit, que la question se tranche :
	 * une page qui déciderait seule de ce qu'elle affiche finirait par
	 * proposer un niveau que la lecture refuse.
	 *
	 * @param string $category
	 *
	 * @return string[]
	 */
	public static function levelsFor ( $category ) {
		return self::sendsEmail( $category )
				? NotificationLevel::all()
				: [ NotificationLevel::NONE, NotificationLevel::APP ];
	}

	/**
	 * Ramène un niveau à ce que cette catégorie sait tenir.
	 *
	 * Employé des deux côtés — à la lecture comme à l'écriture — pour qu'un
	 * réglage choisi avant que la messagerie cesse d'envoyer des e-mails ne
	 * promette pas un e-mail qui ne partira jamais. On ne réécrit rien en
	 * base : la traduction se fait au passage, comme celle de l'ancien
	 * vocabulaire dans NotificationLevel::fromLegacy().
	 *
	 * @param string $category
	 * @param string $level
	 *
	 * @return string one of NotificationLevel
	 */
	public static function clamp ( $category, $level ) {
		return ( !self::sendsEmail( $category ) && NotificationLevel::sendsEmail( $level ) )
				? NotificationLevel::APP
				: $level;
	}

	/**
	 * Ce que vaut cette catégorie tant que personne n'a rien choisi.
	 *
	 * Le quotidien partout, sauf la messagerie, qui s'annonce sur la
	 * plateforme et n'envoie plus d'e-mail du tout — voir self::sendsEmail().
	 *
	 * @param string $category
	 *
	 * @return string one of NotificationLevel
	 */
	public static function defaultLevel ( $category ) {
		return ( $category === self::MESSAGES )
				? NotificationLevel::APP
				: NotificationLevel::DEFAULT_LEVEL;
	}
}
