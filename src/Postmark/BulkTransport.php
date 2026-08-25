<?php

namespace App\Postmark;

use Postmark\Transport;
use Swift_Mime_MimePart;
use Swift_Mime_SimpleMessage;

class BulkTransport extends Transport {
	/**
	 * Ce que Postmark a répondu la dernière fois qu'il a refusé quelque chose.
	 *
	 * Le transport ne peut pas décider seul quoi faire d'un refus — il est
	 * appelé au milieu de la requête de quelqu'un qui vient de publier. Mais
	 * l'information ne doit pas disparaître pour autant : sans elle,
	 * `app:mail:check` ne peut dire que « rien n'est parti », là où Postmark
	 * disait précisément pourquoi.
	 *
	 * @var string|null
	 */
	private $lastError;

	/**
	 * @return string|null
	 */
	public function getLastError () {
		return $this->lastError;
	}

	/**
	 * @param array $messages
	 *
	 * @return int le nombre de messages que Postmark a acceptés
	 */
	public function sendMultiple ( array $messages ) {
		$this->lastError = NULL;

		// Sans jeton, rien ne part. Rendre TRUE le disait « réussi » à qui
		// lisait la valeur, et un appelant marquait alors comme envoyé ce qui
		// n'était jamais sorti de la machine. Zéro est ce qui s'est passé.
		if ( empty( $this->serverToken ) ) {
			return 0;
		}

		// Un lot vide n'est pas une anomalie : personne n'attendait cet e-mail.
		// Depuis #38 le rythme par défaut est le résumé quotidien, si bien qu'un
		// message de discussion ne part à chaud que vers ceux qui ont choisi
		// l'immédiat — et le plus souvent, personne ne l'a choisi. Sans ce
		// retour, `$messages[ 0 ]` rend NULL, Swift refuse le type, et la page
		// tombe en 500 *après* que le message a été enregistré.
		if ( empty( $messages ) ) {
			return 0;
		}

		$client = $this->getHttpClient();

		if ( $evt = $this->_eventDispatcher->createSendEvent( $this, $messages[ 0 ] ) ) {
			$this->_eventDispatcher->dispatchEvent( $evt, 'beforeSendPerformed' );
			if ( $evt->bubbleCancelled() ) {
				return 0;
			}
		}

		$v = $this->version;
		$o = $this->os;

		$total          = count( $messages );
		$sendSuccessful = TRUE;
		$loop           = 0;
		$messagesPool   = [];

		foreach ( $messages as $message ) {
			$loop++;

			$messagesPool[] = $this->getMessagePayload( $message );

			if ( ( ( $loop % 500 ) === 0 ) || ( $loop >= $total ) ) {
				$response       = $client->request( 'POST', 'https://api.postmarkapp.com/email/batch', [
						'headers'     => [
								'X-Postmark-Server-Token' => $this->serverToken,
								'Content-Type'            => 'application/json',
								'User-Agent'              => "swiftmailer-postmark (PHP Version: $v, OS: $o)",
								'X-PM-Message-Stream'     => 'broadcast',
						],
						'json'        => $messagesPool,
						'http_errors' => FALSE,
				] );
				// **Un 200 ne veut pas dire « envoyé ».** L'API par lot répond
				// 200 en portant un verdict par message : une signature
				// d'expéditeur non confirmée, une adresse désactivée, un flux
				// absent s'y lisent message par message, et le code HTTP reste
				// 200. Ne regarder que lui, c'est enregistrer comme partis des
				// messages que Postmark vient de refuser un par un.
				$sendSuccessful = $this->accepted( $response, count( $messagesPool ) ) && $sendSuccessful;

				$messagesPool = [];
			}
		}

		if ( $evt && $sendSuccessful ) {
			$evt->setResult( \Swift_Events_SendEvent::RESULT_SUCCESS );
			$this->_eventDispatcher->dispatchEvent( $evt, 'sendPerformed' );
		}

		return $sendSuccessful
				? $total
				: 0;
	}

	/**
	 * Est-ce que ce lot a été accepté, en entier ?
	 *
	 * Un lot à moitié accepté est traité comme refusé : l'appelant ne saurait
	 * pas lesquels marquer, et mieux vaut qu'un membre reçoive deux fois
	 * qu'aucune.
	 *
	 * @param \Psr\Http\Message\ResponseInterface $response
	 * @param int                                  $expected
	 *
	 * @return bool
	 */
	private function accepted ( $response, $expected ) {
		$body = (string) $response->getBody();

		if ( $response->getStatusCode() != 200 ) {
			$this->remember( sprintf( 'HTTP %d — %s', $response->getStatusCode(), $this->firstMessage( $body ) ) );

			return FALSE;
		}

		$results = json_decode( $body, TRUE );

		// Une réponse qu'on ne sait pas lire ne prouve pas un envoi. On ne la
		// prend pas pour un succès : le résumé rattrapera.
		if ( !is_array( $results ) ) {
			$this->remember( 'réponse illisible de Postmark' );

			return FALSE;
		}

		$accepted = 0;

		foreach ( $results as $result ) {
			if ( !is_array( $result ) ) {
				continue;
			}

			if ( isset( $result[ 'ErrorCode' ] ) && ( (int) $result[ 'ErrorCode' ] !== 0 ) ) {
				$this->remember( sprintf(
						'%s (ErrorCode %d)',
						isset( $result[ 'Message' ] ) ? $result[ 'Message' ] : 'message refusé',
						(int) $result[ 'ErrorCode' ]
				) );

				continue;
			}

			$accepted++;
		}

		return $accepted >= $expected;
	}

	/**
	 * Le premier refus est le seul retenu : ils se ressemblent tous quand
	 * c'est la configuration qui est en cause, et une liste de cinq cents
	 * lignes identiques n'apprend rien de plus.
	 *
	 * @param string $error
	 */
	private function remember ( $error ) {
		if ( $this->lastError === NULL ) {
			$this->lastError = $error;
		}
	}

	/**
	 * @param string $body
	 *
	 * @return string
	 */
	private function firstMessage ( $body ) {
		$decoded = json_decode( $body, TRUE );

		if ( is_array( $decoded ) && isset( $decoded[ 'Message' ] ) ) {
			return (string) $decoded[ 'Message' ];
		}

		return mb_substr( trim( $body ), 0, 200 ) ?: 'aucune réponse';
	}

	/**************************************************
	 * COPIES OF private METHODS
	 **************************************************/

	/**
	 * Get the number of recipients for a message
	 *
	 * @param Swift_Mime_SimpleMessage $message
	 *
	 * @return int
	 */
	protected function getRecipientCount ( Swift_Mime_SimpleMessage $message ) {
		return count( array_merge(
						(array)$message->getTo(),
						(array)$message->getCc(),
						(array)$message->getBcc() )
		);
	}

	/**
	 * Convert email dictionary with emails and names
	 * to array of emails with names.
	 *
	 * @param array $emails
	 *
	 * @return array
	 */
    protected function convertEmailsArray ( array $emails ) {
		$convertedEmails = array();
		foreach ( $emails as $email => $name ) {
			$convertedEmails[] = $name
					? '"' . str_replace( '"', '\\"', $name ) . "\" <{$email}>"
					: $email;
		}

		return $convertedEmails;
	}

	/**
	 * Gets MIME parts that match the message type.
	 * Excludes parts of type \Swift_Mime_Attachment as those
	 * are handled later.
	 *
	 * @param Swift_Mime_SimpleMessage $message
	 * @param string                   $mimeType
	 *
	 * @return Swift_Mime_MimePart
	 */
    protected function getMIMEPart ( Swift_Mime_SimpleMessage $message, $mimeType ) {
		foreach ( $message->getChildren() as $part ) {
			if ( strpos( $part->getContentType(), $mimeType ) === 0 && !( $part instanceof \Swift_Mime_Attachment ) ) {
				return $part;
			}
		}
	}

	/**
	 * Convert a Swift Mime Message to a Postmark Payload.
	 *
	 * @param Swift_Mime_SimpleMessage $message
	 *
	 * @return object
	 */
    protected function getMessagePayload ( Swift_Mime_SimpleMessage $message ) {
		$payload = [];

		$this->processRecipients( $payload, $message );

		$this->processMessageParts( $payload, $message );

		if ( $message->getHeaders() ) {
			$this->processHeaders( $payload, $message );
		}

		return $payload;
	}

	/**
	 * Applies the recipients of the message into the API Payload.
	 *
	 * @param array                    $payload
	 * @param Swift_Mime_SimpleMessage $message
	 *
	 * @return object
	 */
    protected function processRecipients ( &$payload, $message ) {
		$payload[ 'From' ] = join( ',', $this->convertEmailsArray( $message->getFrom() ) );
		if ( $to = $message->getTo() ) {
			$payload[ 'To' ] = join( ',', $this->convertEmailsArray( $to ) );
		}
		$payload[ 'Subject' ] = $message->getSubject();

		if ( $cc = $message->getCc() ) {
			$payload[ 'Cc' ] = join( ',', $this->convertEmailsArray( $cc ) );
		}
		if ( $reply_to = $message->getReplyTo() ) {
			$payload[ 'ReplyTo' ] = join( ',', $this->convertEmailsArray( $reply_to ) );
		}
		if ( $bcc = $message->getBcc() ) {
			$payload[ 'Bcc' ] = join( ',', $this->convertEmailsArray( $bcc ) );
		}
	}

	/**
	 * Applies the message parts and attachments
	 * into the API Payload.
	 *
	 * @param array                    $payload
	 * @param Swift_Mime_SimpleMessage $message
	 *
	 * @return object
	 */
    protected function processMessageParts ( &$payload, $message ) {
		//Get the primary message.
		switch ( $message->getContentType() ) {
			case 'text/html':
			case 'multipart/alternative':
			case 'multipart/mixed':
				$payload[ 'HtmlBody' ] = $message->getBody();
				break;
			default:
				$payload[ 'TextBody' ] = $message->getBody();
				break;
		}

		// Provide an alternate view from the secondary parts.
		if ( $plain = $this->getMIMEPart( $message, 'text/plain' ) ) {
			$payload[ 'TextBody' ] = $plain->getBody();
		}
		if ( $html = $this->getMIMEPart( $message, 'text/html' ) ) {
			$payload[ 'HtmlBody' ] = $html->getBody();
		}
		if ( $message->getChildren() ) {
			$payload[ 'Attachments' ] = array();
			foreach ( $message->getChildren() as $attachment ) {
				if ( is_object( $attachment ) and $attachment instanceof \Swift_Mime_Attachment ) {
					$a = array(
							'Name'        => $attachment->getFilename(),
							'Content'     => base64_encode( $attachment->getBody() ),
							'ContentType' => $attachment->getContentType(),
					);
					if ( $attachment->getDisposition() != 'attachment' && $attachment->getId() != NULL ) {
						$a[ 'ContentID' ] = 'cid:' . $attachment->getId();
					}
					$payload[ 'Attachments' ][] = $a;
				}
			}
		}
	}

	/**
	 * Applies the headers into the API Payload.
	 *
	 * @param array                    $payload
	 * @param Swift_Mime_SimpleMessage $message
	 *
	 * @return object
	 */
    protected function processHeaders ( &$payload, $message ) {
		$headers = [];

		foreach ( $message->getHeaders()->getAll() as $key => $value ) {
			$fieldName = $value->getFieldName();

			$excludedHeaders = [ 'Subject', 'Content-Type', 'MIME-Version', 'Date' ];

			if ( !in_array( $fieldName, $excludedHeaders ) ) {

				if ( $value instanceof \Swift_Mime_Headers_UnstructuredHeader ||
					 $value instanceof \Swift_Mime_Headers_OpenDKIMHeader ) {
					if ( $fieldName != 'X-PM-Tag' ) {
						array_push( $headers, [
								"Name"  => $fieldName,
								"Value" => $value->getValue(),
						] );
					}
					else {
						$payload[ "Tag" ] = $value->getValue();
					}
				}
				else if ( $value instanceof \Swift_Mime_Headers_DateHeader ||
						  $value instanceof \Swift_Mime_Headers_IdentificationHeader ||
						  $value instanceof \Swift_Mime_Headers_ParameterizedHeader ||
						  $value instanceof \Swift_Mime_Headers_PathHeader ) {
					array_push( $headers, [
							"Name"  => $fieldName,
							"Value" => $value->getFieldBody(),
					] );

					if ( $value->getFieldName() == 'Message-ID' ) {
						array_push( $headers, [
								"Name"  => 'X-PM-KeepID',
								"Value" => 'true',
						] );
					}
				}
			}
		}
		$payload[ 'Headers' ] = $headers;
	}
}
