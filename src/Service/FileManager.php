<?php

namespace App\Service;

use App\Entity\File;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class FileManager {
	private $managers = [];
	/**
	 * @var CacheManager $cacheManager
	 */
	private $cacheManager;

	public function __construct (
			UserFileManager $userFileManager,
			UsergroupFileManager $usergroupFileManager,
			AppFileManager $appFileManager,
			CacheManager $cacheManager
	) {
		$this->managers[ File::USER_FILES ]      = $userFileManager;
		$this->managers[ File::USERGROUP_FILES ] = $usergroupFileManager;
		$this->managers[ File::APP_FILES ] = $appFileManager;

		$this->cacheManager = $cacheManager;
	}

	/**
	 * Sert un fichier.
	 *
	 * Deux choses se décident ici, et nulle part ailleurs. La première :
	 * `nosniff`, pour qu'un navigateur ne devine pas un type que nous avons
	 * déclaré — sans quoi un fichier annoncé « texte » et contenant du HTML
	 * s'exécute dans notre origine. La seconde : « inline » ou « pièce
	 * jointe ». Un PDF, une image, un texte s'affichent dans la page ; un SVG
	 * ou un HTML déposé comme document se téléchargent, quoi qu'il arrive
	 * (voir `FileMimeManager::mustDownload`). (#43)
	 *
	 * @param \App\Entity\File $file
	 * @param bool             $forceDownload demandé par le bouton « Télécharger »
	 *
	 * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
	 */
	public function getFile ( File $file, bool $forceDownload = FALSE ) {
		$response = new BinaryFileResponse( sprintf( 'gaufrette://%s', $file->getFilesystem() . '/' . $file->getPath() ) );
		$response->headers->set( 'Content-Type', $file->getType() );
		$response->headers->set( 'X-Content-Type-Options', 'nosniff' );

		$inline = !$forceDownload && !FileMimeManager::mustDownload( $file->getType() );

		$response->setContentDisposition(
				$inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
				$file->getName()
		);

		return $response;
	}

	public function deleteFile ( File $file ) {
		/**
		 * @var \Gaufrette\FilesystemInterface $fs
		 */
		$fs = $this->getManager( $file->getFilesystem() )
				   ->getFileSystem();

		if ( $fs->has( $file->getPath() ) ) {
			return $fs->delete( $file->getPath() );
		}

		return TRUE;
	}

	public function getResized ( File $file ) {
		$image = $this->cacheManager->resolve( $file->getPath(), 'avatar' );

		return new RedirectResponse( $image );
	}

	public function getManager ( string $filesytem ) {
		return $this->managers[ $filesytem ];
	}

	/**************************************************
	 * UPLOAD TOOLS
	 **************************************************/

	/**
	 * @param     $bytes
	 * @param int $precision
	 *
	 * @return string
	 */
	public function formatSize ( $bytes, $precision = 2 ) {
		$units = array( 'B', 'Ko', 'Mo', 'Go', 'To' );

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * PHP jette le corps d'une requête qui dépasse `post_max_size`, avant que
	 * Symfony la voie : $_POST et $_FILES arrivent vides alors que le
	 * navigateur a bien envoyé quelque chose. Le formulaire ne se croit donc
	 * pas soumis, la page se réaffiche vierge, et rien n'est dit — c'est ce
	 * qui laissait déposer un document sans fichier. (#40)
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 *
	 * @return bool
	 */
	public function requestWasDiscarded ( Request $request ): bool {
		if ( !$request->isMethod( 'POST' ) ) {
			return FALSE;
		}

		// Un POST qui a porté quelque chose jusqu'ici n'a pas été jeté.
		if ( ( $request->request->count() > 0 ) || ( $request->files->count() > 0 ) ) {
			return FALSE;
		}

		return (int) $request->server->get( 'CONTENT_LENGTH', 0 ) > 0;
	}

	/**
	 *
	 * @param $size
	 *
	 * @return float|mixed
	 */
	public function fileUploadMaxSize ( $size ) {
		$targetSize    = $this->parseSize( $size );
		$serverMaxSize = $this->serverFileUploadMaxSize();

		if ( !empty( $serverMaxSize ) ) {
			return min( $targetSize, $serverMaxSize );
		}

		return $targetSize;
	}

	private $maxSize = -1;

	/**
	 * Get max upload size from server.
	 * from Drupal https://api.drupal.org/api/drupal/includes%21file.inc/function/file_upload_max_size/7.x
	 *
	 * @return float|int
	 */
	private function serverFileUploadMaxSize () {
		if ( $this->maxSize < 0 ) {
			// Start with post_max_size.
			$post_max_size = $this->parseSize( ini_get( 'post_max_size' ) );
			if ( $post_max_size > 0 ) {
				$this->maxSize = $post_max_size;
			}

			// If upload_max_size is less, then reduce. Except if upload_max_size is
			// zero, which indicates no limit.
			$upload_max = $this->parseSize( ini_get( 'upload_max_filesize' ) );
			if ( $upload_max > 0 && $upload_max < $this->maxSize ) {
				$this->maxSize = $upload_max;
			}
		}

		return $this->maxSize;
	}

	/**
	 * @param $size
	 *
	 * @return false|float
	 */
	private function parseSize ( $size ) {
		$unit = preg_replace( '/[^bkmgtpezy]/i', '', $size ); // Remove the non-unit characters from the size.
		$size = preg_replace( '/[^0-9.]/', '', $size ); // Remove the non-numeric characters from the size.
		if ( $unit ) {
			// Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
			return round( $size * pow( 1024, stripos( 'bkmgtpezy', $unit[ 0 ] ) ) );
		}
		else {
			return round( $size );
		}
	}
}
