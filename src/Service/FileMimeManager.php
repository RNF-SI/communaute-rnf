<?php
/**
 * User: Maxime Cousinou
 * Date: 14/11/2019
 * Time: 16:39
 */

namespace App\Service;

class FileMimeManager {
	public const DOCUMENTS = 'docs';
	public const PDF       = 'pdf';
	public const IMAGES    = 'images';
	public const ARCHIVES  = 'archives';

	public static function getMimes ( $documentType = '' ) {
		// https://developer.mozilla.org/fr/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Complete_list_of_MIME_types

		switch ( $documentType ) {
			case self::DOCUMENTS:
				return [
					// TXT
					'text/plain',
					// DOC
					'application/msword',
					// DOCX
					'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					// ODT
					'application/vnd.oasis.opendocument.text',
					// XLS
					'application/vnd.ms-excel',
					// XLSX
					'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
					// CSV
					'text/csv',
					// ODS
					'application/vnd.oasis.opendocument.spreadsheet',
					// PPT
					'application/vnd.ms-powerpoint',
					// PPTX
					'application/vnd.openxmlformats-officedocument.presentationml.presentation',
					// ODP
					'application/vnd.oasis.opendocument.presentation',
				];
				break;

			case self::PDF:
				return [
						'application/pdf',
						'application/x-pdf',
				];
				break;

			case self::IMAGES:
				return [
						'image/gif',
						'image/png',
						'image/jpeg',
						'image/svg+xml',
				];
				break;

			case self::ARCHIVES:
				return [
						'application/zip',
						'application/x-7z-compressed',
						'application/x-tar',
				];
				break;

			default:
				return [];
		}
	}

	/**************************************************
	 * CE QUI S'AFFICHE, ET CE QUI SE TÉLÉCHARGE
	 **************************************************/

	/**
	 * Les types qu'un navigateur sait montrer lui-même, sans rien installer
	 * ni rien appeler au dehors. (#43)
	 *
	 * @param string|null $mime
	 *
	 * @return bool
	 */
	public static function isPdf ( ?string $mime ): bool {
		return in_array( self::normalize( $mime ), self::getMimes( self::PDF ), TRUE );
	}

	/**
	 * @param string|null $mime
	 *
	 * @return bool
	 */
	public static function isImage ( ?string $mime ): bool {
		return in_array( self::normalize( $mime ), self::getMimes( self::IMAGES ), TRUE );
	}

	/**
	 * Ce qu'il ne faut jamais servir « inline » depuis notre propre domaine.
	 *
	 * Un SVG est une image pour `<img>` et un document scriptable pour la
	 * barre d'adresse : ouvert directement, son `<script>` s'exécute dans
	 * notre origine, avec le cookie de session de celui qui l'ouvre. Même
	 * chose pour un HTML ou un XML déposé comme document. Le poser en pièce
	 * jointe coupe court, sans rien casser : une sous-ressource — le `src`
	 * d'un `<img>` — ignore `Content-Disposition` et continue de s'afficher.
	 *
	 * @param string|null $mime
	 *
	 * @return bool
	 */
	public static function mustDownload ( ?string $mime ): bool {
		$dangerous = [
				'image/svg+xml',
				'image/svg',
				'text/html',
				'application/xhtml+xml',
				'text/xml',
				'application/xml',
				'application/xhtml',
		];

		return in_array( self::normalize( $mime ), $dangerous, TRUE );
	}

	/**
	 * L'extension d'un nom de fichier, en minuscules et sans le point.
	 *
	 * @param string|null $name
	 *
	 * @return string
	 */
	public static function extension ( ?string $name ): string {
		return strtolower( (string) pathinfo( (string) $name, PATHINFO_EXTENSION ) );
	}

	/**
	 * Le type d'un fichier déduit de son extension.
	 *
	 * Sert à l'enregistrement d'une version rendue par l'éditeur en ligne :
	 * ce qui revient est un flux d'octets, sans type déclaré, et prendre le
	 * type de la version précédente serait faux dès que l'éditeur a converti
	 * le format. (#43)
	 *
	 * @param string $extension
	 *
	 * @return string
	 */
	public static function mimeForExtension ( string $extension ): string {
		$mimes = [
				'pdf'  => 'application/pdf',
				'txt'  => 'text/plain',
				'csv'  => 'text/csv',
				'rtf'  => 'application/rtf',
				'doc'  => 'application/msword',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'docm' => 'application/vnd.ms-word.document.macroEnabled.12',
				'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
				'odt'  => 'application/vnd.oasis.opendocument.text',
				'ott'  => 'application/vnd.oasis.opendocument.text-template',
				'fodt' => 'application/vnd.oasis.opendocument.text-flat-xml',
				'xls'  => 'application/vnd.ms-excel',
				'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
				'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
				'ots'  => 'application/vnd.oasis.opendocument.spreadsheet-template',
				'ppt'  => 'application/vnd.ms-powerpoint',
				'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'pptm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
				'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
				'odp'  => 'application/vnd.oasis.opendocument.presentation',
				'otp'  => 'application/vnd.oasis.opendocument.presentation-template',
		];

		return $mimes[ strtolower( $extension ) ] ?? 'application/octet-stream';
	}

	/**
	 * Un type peut arriver suivi de ses paramètres — « text/html; charset=… ».
	 *
	 * @param string|null $mime
	 *
	 * @return string
	 */
	private static function normalize ( ?string $mime ): string {
		$mime = strtolower( trim( (string) $mime ) );

		$separator = strpos( $mime, ';' );

		if ( $separator !== FALSE ) {
			$mime = trim( substr( $mime, 0, $separator ) );
		}

		return $mime;
	}
}
