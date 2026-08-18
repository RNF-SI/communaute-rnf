<?php

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * The upload ceiling declared by the forms is capped at runtime by the PHP
 * settings of public/.user.ini (see FileManager::fileUploadMaxSize). A form
 * asking for more than the server allows silently loses the difference, which
 * is exactly how the 10 MB limit of issue #25 went unnoticed.
 */
class UploadLimitsTest extends TestCase {
	/**
	 * @param string $size
	 *
	 * @return int
	 */
	private function toBytes ( $size ) {
		$unit  = preg_replace( '/[^bkmgtpezy]/i', '', $size );
		$value = (float) preg_replace( '/[^0-9.]/', '', $size );

		return $unit
				? (int) round( $value * pow( 1024, stripos( 'bkmgtpezy', $unit[ 0 ] ) ) )
				: (int) round( $value );
	}

	/**
	 * @return array
	 */
	private function serverLimits () {
		$ini = parse_ini_file( dirname( __DIR__, 2 ) . '/public/.user.ini' );

		$this->assertNotFalse( $ini, 'Assert public/.user.ini can be parsed' );

		return $ini;
	}

	/**
	 * @return array form file name => requested ceiling in bytes
	 */
	private function formLimits () {
		$limits = [];

		foreach ( glob( dirname( __DIR__, 2 ) . '/src/Form/*.php' ) as $path ) {
			if ( preg_match( "/fileUploadMaxSize\(\s*'([^']+)'\s*\)/", file_get_contents( $path ), $matches ) ) {
				$limits[ basename( $path ) ] = $this->toBytes( $matches[ 1 ] );
			}
		}

		return $limits;
	}

	public function testServerAllowsAtLeastWhatTheFormsAskFor () {
		$server = $this->serverLimits();
		$forms  = $this->formLimits();

		$this->assertNotEmpty( $forms, 'Assert at least one form declares an upload ceiling' );

		$allowed = min(
				$this->toBytes( $server[ 'upload_max_filesize' ] ),
				$this->toBytes( $server[ 'post_max_size' ] )
		);

		foreach ( $forms as $form => $requested ) {
			$this->assertGreaterThanOrEqual(
					$requested,
					$allowed,
					sprintf(
							'Assert public/.user.ini allows the %s bytes requested by %s',
							$requested,
							$form
					)
			);
		}
	}

	public function testPostMaxSizeIsNotBelowUploadMaxFilesize () {
		$server = $this->serverLimits();

		$this->assertGreaterThanOrEqual(
				$this->toBytes( $server[ 'upload_max_filesize' ] ),
				$this->toBytes( $server[ 'post_max_size' ] ),
				'Assert post_max_size does not silently cap upload_max_filesize'
		);
	}
}
