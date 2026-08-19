<?php

namespace App\Service;

use App\Entity\DocumentFolder;
use App\Entity\Usergroup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Traduit un chemin écrit à la main — « Comptes rendus / 2026 » — en dossiers
 * emboîtés, en créant au passage ceux qui manquent. (#8)
 *
 * Le classement des documents se pilote en tapant un nom de dossier ; le
 * chemin prolonge cette habitude au lieu d'imposer une nouvelle mécanique.
 */
class DocumentFolderResolver {
	/**
	 * Au-delà, ce n'est plus du classement.
	 */
	private const MAX_DEPTH = 5;

	private $manager;

	public function __construct ( EntityManagerInterface $manager ) {
		$this->manager = $manager;
	}

	/**
	 * @param \App\Entity\Usergroup $group
	 * @param string|null           $path
	 *
	 * @return \App\Entity\DocumentFolder|null le dossier le plus profond du
	 *                                        chemin, NULL si le chemin est vide
	 */
	public function resolve ( Usergroup $group, ?string $path ): ?DocumentFolder {
		$names = $this->split( $path );

		if ( empty( $names ) ) {
			return NULL;
		}

		$repository = $this->manager->getRepository( DocumentFolder::class );
		$parent     = NULL;

		foreach ( $names as $name ) {
			$folder = $repository->findOneBy( [
					'usergroup' => $group,
					'title'     => $name,
					'parent'    => $parent,
			] );

			if ( !$folder ) {
				$folder = new DocumentFolder();
				$folder->setUsergroup( $group );
				$folder->setTitle( $name );
				$folder->setParent( $parent );

				$this->manager->persist( $folder );

				// Le dossier doit exister avant qu'on cherche son enfant.
				$this->manager->flush();
			}

			$parent = $folder;
		}

		return $parent;
	}

	/**
	 * @param string|null $path
	 *
	 * @return string[]
	 */
	public function split ( ?string $path ): array {
		$names = [];

		foreach ( explode( DocumentFolder::SEPARATOR, (string) $path ) as $name ) {
			$name = trim( $name );

			if ( $name === '' ) {
				continue;
			}

			$names[] = mb_substr( $name, 0, 100 );

			if ( count( $names ) >= self::MAX_DEPTH ) {
				break;
			}
		}

		return $names;
	}
}
