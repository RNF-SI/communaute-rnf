<?php

namespace App\Tests\DataFixtures;

use App\DataFixtures\SampleFiles;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Issue #42 (4) — les fichiers que les fixtures donnent à leurs documents
 * doivent s'ouvrir pour de vrai : un PDF dont la table des références est
 * fausse s'affiche « réparé », ou pas du tout, et c'est justement l'aperçu
 * que la recette veut éprouver.
 */
class SampleFilesTest extends TestCase {
	public function testThePdfPointsAtItsOwnCrossReferenceTable () {
		$pdf = SampleFiles::pdf( 'Plan de gestion — été 2026', "Première ligne.\nSeconde (entre parenthèses)." );

		$this->assertStringStartsWith( '%PDF-1.4', $pdf );
		$this->assertStringEndsWith( "%%EOF\n", $pdf );

		$this->assertSame( 1, preg_match( '/startxref\n(\d+)\n/', $pdf, $match ) );
		$this->assertSame( 'xref', substr( $pdf, (int) $match[ 1 ], 4 ), 'Assert startxref points at the table' );

		// Chaque entrée de la table désigne le début de son objet.
		preg_match_all( '/^(\d{10}) 00000 n $/m', $pdf, $offsets );

		foreach ( $offsets[ 1 ] as $i => $offset ) {
			$this->assertSame( ( $i + 1 ) . ' 0 obj', substr( $pdf, (int) $offset, strlen( ( $i + 1 ) . ' 0 obj' ) ) );
		}
	}

	public function testThePdfStreamLengthIsExact () {
		$pdf = SampleFiles::pdf( 'Titre', 'Corps' );

		$this->assertSame( 1, preg_match( "/<< \\/Length (\\d+) >>\nstream\n(.*?)endstream/s", $pdf, $match ) );
		$this->assertSame( (int) $match[ 1 ], strlen( $match[ 2 ] ) );
	}

	public function testTheDocxIsAWordDocumentCarryingItsTitle () {
		if ( !class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'zip extension missing' );
		}

		$path = tempnam( sys_get_temp_dir(), 'docx' );
		file_put_contents( $path, SampleFiles::docx( 'Compte rendu & bilan', 'Texte' ) );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path ) === TRUE );

		$this->assertNotFalse( $zip->locateName( '[Content_Types].xml' ) );
		$this->assertStringContainsString( 'Compte rendu &amp; bilan', $zip->getFromName( 'word/document.xml' ) );

		$zip->close();
		unlink( $path );
	}

	public function testThePngIsAnImage () {
		$png = SampleFiles::png( 'Carte des habitats' );

		if ( $png === NULL ) {
			$this->markTestSkipped( 'Neither gd nor imagick' );
		}

		$size = getimagesizefromstring( $png );

		$this->assertSame( 'image/png', $size[ 'mime' ] );
	}

	/**
	 * La rotation couvre chaque geste de la fiche : aperçu PDF, aperçu image,
	 * édition en ligne.
	 */
	public function testTheKindsRotate () {
		$kinds = [];

		for ( $i = 0; $i < count( SampleFiles::KINDS ); $i++ ) {
			$kinds[] = SampleFiles::kindFor( $i );
		}

		$this->assertEqualsCanonicalizing( SampleFiles::KINDS, $kinds );
	}

	public function testTheFileNameKeepsAccentsButNotSeparators () {
		list( $name, $type ) = SampleFiles::make( 'pdf', 'Suivi 2025/2026 : « été »' );

		$this->assertSame( 'application/pdf', $type );
		$this->assertStringNotContainsString( '/', $name );
		$this->assertStringContainsString( 'été', $name );
	}
}
