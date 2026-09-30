<?php

namespace App\Service;

use App\Entity\Usergroup;

/**
 * Ce qu'est un groupe dans l'organisation du réseau : commission, pôle,
 * groupe ou atelier. (#42)
 *
 * Aucun champ ne le dit : c'est le nom qui le porte, et c'est lui qu'on lit —
 * « Commission scientifique », « Pôle zones humides », « Groupe de travail
 * forêt ». Le premier mot décide, sans égard pour la casse ni les accents ;
 * un nom qui ne commence par aucun d'eux n'a pas de type, et le groupe n'en
 * est pas moins filtrable. Renommer un groupe change donc son type : c'est le
 * prix d'une déduction plutôt que d'un champ à tenir.
 */
class GroupKind {
	public const COMMISSION = 'commission';
	public const POLE       = 'pole';
	public const GROUPE     = 'groupe';
	public const ATELIER    = 'atelier';

	/**
	 * Premier mot du nom, sans accent ni majuscule => type.
	 */
	private const PREFIXES = [
			'commission'  => self::COMMISSION,
			'commissions' => self::COMMISSION,
			'pole'        => self::POLE,
			'poles'       => self::POLE,
			'groupe'      => self::GROUPE,
			'groupes'     => self::GROUPE,
			'gt'          => self::GROUPE,
			'atelier'     => self::ATELIER,
			'ateliers'    => self::ATELIER,
	];

	/**
	 * Dans l'ordre de la hiérarchie du réseau : c'est celui des listes.
	 *
	 * @return string[]
	 */
	public static function all (): array {
		return [ self::COMMISSION, self::POLE, self::GROUPE, self::ATELIER ];
	}

	/**
	 * @param \App\Entity\Usergroup|string|null $group un groupe ou son nom
	 *
	 * @return string|null
	 */
	public static function of ( $group ): ?string {
		$name = $group instanceof Usergroup ? $group->getName() : $group;

		if ( !is_string( $name ) || trim( $name ) === '' ) {
			return NULL;
		}

		$first = preg_split( '/[^\p{L}\p{N}]+/u', trim( $name ), 2, PREG_SPLIT_NO_EMPTY )[ 0 ] ?? '';
		$first = SlugGenerator::slugify( $first );

		return self::PREFIXES[ $first ] ?? NULL;
	}
}
