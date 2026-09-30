<?php

namespace App\DataFixtures;

use ZipArchive;

/**
 * De vrais fichiers pour les documents des fixtures. (#42)
 *
 * Les documents des fixtures n'avaient aucun fichier : la préproduction, qui
 * tourne sur ces données, n'offrait donc rien à consulter ni à télécharger, et
 * la recette l'a rapporté comme une panne. C'était surtout un état que la
 * plateforme ne peut pas produire — le formulaire exige un fichier.
 *
 * Un format par geste à éprouver : un PDF pour l'aperçu dans la page, une
 * image pour l'aperçu en image, un .docx et un .csv pour l'édition en ligne,
 * un .txt pour le reste. Tout est fabriqué ici, sans modèle binaire versé
 * dans le dépôt, et reste de quelques kilo-octets.
 */
class SampleFiles {
	public const KINDS = [ 'pdf', 'docx', 'csv', 'png', 'txt' ];

	/**
	 * @param int $rank
	 *
	 * @return string
	 */
	public static function kindFor ( $rank ) {
		return self::KINDS[ $rank % count( self::KINDS ) ];
	}

	/**
	 * @param string $kind  l'un de KINDS
	 * @param string $title
	 * @param string $body
	 *
	 * @return array [nom du fichier, type MIME, contenu]
	 */
	public static function make ( $kind, $title, $body = '' ) {
		$name = self::fileName( $title );

		switch ( $kind ) {
			case 'pdf':
				return [ $name . '.pdf', 'application/pdf', self::pdf( $title, $body ) ];

			case 'docx':
				// Sans l'extension zip, un texte : mieux vaut un fichier de
				// moins qu'un fichier illisible.
				if ( class_exists( ZipArchive::class ) ) {
					return [
							$name . '.docx',
							'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
							self::docx( $title, $body ),
					];
				}

				return [ $name . '.txt', 'text/plain', self::txt( $title, $body ) ];

			case 'csv':
				return [ $name . '.csv', 'text/csv', self::csv() ];

			case 'png':
				$png = self::png( $title );

				if ( $png !== NULL ) {
					return [ $name . '.png', 'image/png', $png ];
				}

				return [ $name . '.txt', 'text/plain', self::txt( $title, $body ) ];

			default:
				return [ $name . '.txt', 'text/plain', self::txt( $title, $body ) ];
		}
	}

	/**
	 * Un nom comme en déposent les gens : accents et espaces compris.
	 *
	 * @param string $title
	 *
	 * @return string
	 */
	private static function fileName ( $title ) {
		$name = trim( preg_replace( '/[\/\\\\:*?"<>|%]+/u', ' ', $title ) );
		$name = preg_replace( '/\s+/u', ' ', $name );

		return mb_substr( $name, 0, 60 ) ?: 'Document';
	}

	/**
	 * @param string $title
	 * @param string $body
	 *
	 * @return string
	 */
	private static function txt ( $title, $body ) {
		return $title . "\n\n" . ( $body ?: 'Document d’exemple.' ) . "\n";
	}

	/**
	 * Un PDF d'une page, titre et texte, avec une table des références juste
	 * — sans quoi certains lecteurs le « réparent » ou le refusent.
	 *
	 * @param string $title
	 * @param string $body
	 *
	 * @return string
	 */
	public static function pdf ( $title, $body = '' ) {
		$lines   = array_merge( [ $title, '' ], self::wrap( $body ?: 'Document d’exemple.', 80 ) );
		$content = "BT\n/F1 12 Tf\n14 TL\n50 780 Td\n";

		foreach ( $lines as $line ) {
			$content .= '(' . self::pdfString( $line ) . ") Tj T*\n";
		}

		$content .= "ET\n";

		$objects = [
				'<< /Type /Catalog /Pages 2 0 R >>',
				'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
				'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
				sprintf( "<< /Length %d >>\nstream\n%sendstream", strlen( $content ), $content ),
		];

		$pdf     = "%PDF-1.4\n";
		$offsets = [];

		foreach ( $objects as $i => $object ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= sprintf( "%d 0 obj\n%s\nendobj\n", $i + 1, $object );
		}

		$xref = strlen( $pdf );
		$pdf .= sprintf( "xref\n0 %d\n0000000000 65535 f \n", count( $objects ) + 1 );

		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}

		$pdf .= sprintf( "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count( $objects ) + 1, $xref );

		return $pdf;
	}

	/**
	 * La police de base du PDF lit du WinAnsi, pas de l'UTF-8.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	private static function pdfString ( $text ) {
		$text = strtr( $text, [ '’' => "'", '«' => '"', '»' => '"', "\u{a0}" => ' ', "\u{202f}" => ' ' ] );
		$text = @iconv( 'UTF-8', 'Windows-1252//IGNORE', $text );

		return strtr( (string) $text, [ '\\' => '\\\\', '(' => '\\(', ')' => '\\)' ] );
	}

	/**
	 * @param string $text
	 * @param int    $width
	 *
	 * @return string[]
	 */
	private static function wrap ( $text, $width ) {
		$lines = [];

		foreach ( preg_split( '/\R/u', $text ) as $paragraph ) {
			$line = '';

			foreach ( preg_split( '/\s+/u', trim( $paragraph ) ) as $word ) {
				if ( $line !== '' && mb_strlen( $line . ' ' . $word ) > $width ) {
					$lines[] = $line;
					$line    = $word;
				}
				else {
					$line = $line === '' ? $word : $line . ' ' . $word;
				}
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Le plus petit .docx qu'un traitement de texte ouvre sans broncher.
	 *
	 * @param string $title
	 * @param string $body
	 *
	 * @return string
	 */
	public static function docx ( $title, $body = '' ) {
		$paragraphs = '';

		foreach ( array_merge( [ $title ], preg_split( '/\R/u', $body ?: 'Document d’exemple.' ) ) as $text ) {
			$paragraphs .= '<w:p><w:r><w:t xml:space="preserve">'
						   . htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' )
						   . '</w:t></w:r></w:p>';
		}

		$path = tempnam( sys_get_temp_dir(), 'docx' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::OVERWRITE );

		$zip->addFromString( '[Content_Types].xml',
				'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
				. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
				. '<Default Extension="xml" ContentType="application/xml"/>'
				. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
				. '</Types>'
		);
		$zip->addFromString( '_rels/.rels',
				'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
				. '</Relationships>'
		);
		$zip->addFromString( 'word/document.xml',
				'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
				. '<w:body>' . $paragraphs . '</w:body></w:document>'
		);
		$zip->close();

		$content = file_get_contents( $path );
		unlink( $path );

		return $content;
	}

	/**
	 * Un tableau de suivi, le cas même qui a fait ouvrir l'édition à tous
	 * les membres (#43).
	 *
	 * @return string
	 */
	public static function csv () {
		return "Date;Site;Observation;Effectif\n"
			   . "2026-03-12;Prairie humide;Courlis cendré;4\n"
			   . "2026-04-02;Roselière;Butor étoilé;1\n"
			   . "2026-05-18;Mare temporaire;Triton crêté;12\n";
	}

	/**
	 * @param string $title
	 *
	 * @return string|null NULL sans imagick ni gd
	 */
	public static function png ( $title ) {
		$hue = hexdec( substr( md5( $title ), 0, 2 ) );

		if ( function_exists( 'imagecreatetruecolor' ) ) {
			$image = imagecreatetruecolor( 480, 320 );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 11, 100 + ( $hue % 80 ), 90 ) );
			imagestring( $image, 5, 20, 20, (string) @iconv( 'UTF-8', 'ASCII//TRANSLIT', mb_substr( $title, 0, 50 ) ), imagecolorallocate( $image, 255, 255, 255 ) );

			ob_start();
			imagepng( $image );
			imagedestroy( $image );

			return ob_get_clean();
		}

		if ( class_exists( \Imagick::class ) ) {
			$image = new \Imagick();
			$image->newImage( 480, 320, new \ImagickPixel( sprintf( 'rgb(11,%d,90)', 100 + ( $hue % 80 ) ) ) );
			$image->setImageFormat( 'png' );

			return $image->getImageBlob();
		}

		return NULL;
	}
}
