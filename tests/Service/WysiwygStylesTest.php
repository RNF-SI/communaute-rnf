<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Issue #11 — the stylesheet that makes published content look like what the
 * author wrote.
 *
 * Quill styles its own classes only inside .ql-editor. Everything it produces
 * and that the reader has to see again lives here; losing these rules silently
 * flattens every indented list back to a single level.
 */
class WysiwygStylesTest extends TestCase {
	/**
	 * @return string
	 */
	private function stylesheet () {
		return file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/components/_wysiwyg.scss' );
	}

	public function testIndentedListsAreStyled () {
		$this->assertRegExp(
				'/li\.ql-indent-#\{\$level\}/',
				$this->stylesheet(),
				'Assert indented list items keep their indentation once published'
		);
	}

	/**
	 * @dataProvider quillClasses
	 *
	 * @param string $class
	 */
	public function testTheClassesQuillProducesAreStyled ( $class ) {
		$this->assertStringContainsString(
				$class,
				$this->stylesheet(),
				sprintf( 'Assert "%s", produced by the editor, is styled for the reader', $class )
		);
	}

	/**
	 * Each entry matches a button offered by the toolbar in
	 * assets/js/ui/wysiwyg.js.
	 *
	 * @return array
	 */
	public function quillClasses () {
		return [
				'alignement centré'  => [ '.ql-align-center' ],
				'alignement droite'  => [ '.ql-align-right' ],
				'justification'      => [ '.ql-align-justify' ],
				'petite taille'      => [ '.ql-size-small' ],
				'grande taille'      => [ '.ql-size-large' ],
				'très grande taille' => [ '.ql-size-huge' ],
				'sens d’écriture'    => [ '.ql-direction-rtl' ],
				'citation'           => [ '.ql-blockquote' ],
				'bloc de code'       => [ '.ql-code-block' ],
		];
	}
}
