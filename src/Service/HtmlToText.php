<?php

namespace App\Service;

/**
 * Builds the plain text half of an e-mail from its HTML.
 *
 * An HTML-only message is one of the oldest spam signals there is: every
 * legitimate mailer sends both halves. (#14)
 */
class HtmlToText {
	/**
	 * @param string $html
	 *
	 * @return string
	 */
	public function convert ( $html ) {
		$text = preg_replace( '#<(head|style|script)\b[^>]*>.*?</\1>#is', '', (string) $html );

		// Keep the address of a link next to its label, otherwise the text
		// half loses everything the reader is meant to click.
		$text = preg_replace_callback(
				'#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
				function ( $matches ) {
					$label = trim( html_entity_decode( strip_tags( $matches[ 3 ] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
					$url   = trim( $matches[ 2 ] );

					if ( $label === '' ) {
						return $url;
					}

					// Parentheses rather than angle brackets: strip_tags runs
					// after this and would take <url> for a tag.
					return ( $label === $url ) ? $url : sprintf( '%s (%s)', $label, $url );
				},
				$text
		);

		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = preg_replace( '#</(p|div|h[1-6]|li|tr|table)>#i', "\n\n", $text );
		$text = preg_replace( '#<li\b[^>]*>#i', '- ', $text );

		$text = html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Collapse the whitespace the markup left behind.
		$text = preg_replace( '#[ \t]+#', ' ', $text );
		$text = preg_replace( '#\n[ \t]+#', "\n", $text );
		$text = preg_replace( '#\n{3,}#', "\n\n", $text );

		return trim( $text );
	}
}
