<?php

namespace App\Service;

use Swift_Message;

class EmailSender {
	/**
	 * @var \Swift_Mailer
	 */
	private $mailer;

	/**
	 * @var \App\Service\HtmlToText
	 */
	private $htmlToText;

	public function __construct ( \Swift_Mailer $mailer, HtmlToText $htmlToText ) {
		$this->mailer     = $mailer;
		$this->htmlToText = $htmlToText;
	}

	/**
	 * @param mixed  $from
	 * @param mixed  $to
	 * @param string $subject
	 * @param string $message HTML body
	 * @param array  $headers extra headers, name => value
	 *
	 * @return int
	 */
	public function send ( $from, $to, $subject, $message, array $headers = [] ) {
		$mail = ( new Swift_Message( $subject ) )
				->setFrom( $from )
				->setTo( $to )
				->setBody( $message, 'text/html' )
				// Never send HTML alone: it is one of the oldest spam signals
				// there is. (#14)
				->addPart( $this->htmlToText->convert( $message ), 'text/plain' );

		foreach ( $headers as $name => $value ) {
			$mail->getHeaders()->addTextHeader( $name, $value );
		}

		return $this->mailer->send( $mail );
	}

	public function getSubjectFromTitle ( $message, $default = 'Subject' ) {
		return preg_match( '/<title[^>]*>(.*?)<\/title>/ims', $message, $matches ) ? $matches[ 1 ] : $default;
	}
}
