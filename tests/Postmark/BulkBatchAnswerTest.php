<?php

namespace App\Tests\Postmark;

use App\Postmark\BulkTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Swift_Message;

/**
 * **Un 200 ne veut pas dire « envoyé ».**
 *
 * L'API par lot de Postmark répond 200 en portant un verdict par message :
 * une signature d'expéditeur non confirmée, une adresse désactivée, un flux
 * absent s'y lisent message par message, et le code HTTP reste 200. Le
 * transport ne regardait que lui.
 *
 * Ce que ça donnait : `ContentSender` recevait « tout est parti »,
 * `NotificationSender` posait `emailedAt`, et le résumé — qui devait
 * rattraper ce qui n'était pas passé — ne reprenait plus rien. Sur la
 * préproduction, neuf notifications portaient une date d'envoi pour des
 * e-mails que personne n'avait reçus.
 *
 * Le refus est aussi **retenu** : Postmark dit précisément ce qui ne va pas,
 * et ne le disait à personne.
 */
class BulkBatchAnswerTest extends TestCase {
	/**
	 * Un transport dont on tient la réponse HTTP.
	 *
	 * @param \GuzzleHttp\Psr7\Response $response
	 *
	 * @return \App\Postmark\BulkTransport
	 */
	private function transport ( Response $response ) {
		$client = new Client( [ 'handler' => HandlerStack::create( new MockHandler( [ $response ] ) ) ] );

		return new class( 'un-jeton', $client ) extends BulkTransport {
			private $client;

			public function __construct ( $token, Client $client ) {
				parent::__construct( $token );

				$this->client = $client;
			}

			protected function getHttpClient () {
				return $this->client;
			}
		};
	}

	/**
	 * @return \Swift_Message
	 */
	private function message () {
		return ( new Swift_Message( 'Objet' ) )
				->setFrom( 'noreply@rnfrance.org' )
				->setTo( 'membre@rnfrance.org' )
				->setBody( '<p>Bonjour</p>', 'text/html' );
	}

	public function testAnAcceptedBatchIsCounted () {
		$transport = $this->transport( new Response( 200, [], json_encode( [
				[ 'ErrorCode' => 0, 'Message' => 'OK', 'To' => 'membre@rnfrance.org' ],
		] ) ) );

		self::assertSame( 1, $transport->sendMultiple( [ $this->message() ] ) );
		self::assertNull( $transport->getLastError() );
	}

	/**
	 * Le cas rencontré : le jeton est bon, le transport répond 200, et chaque
	 * message est refusé pour son adresse d'expédition.
	 */
	public function testASenderPostmarkRefusesIsNotASend () {
		$transport = $this->transport( new Response( 200, [], json_encode( [
				[
						'ErrorCode' => 400,
						'Message'   => 'Sender signature not confirmed for from address',
				],
		] ) ) );

		self::assertSame(
				0,
				$transport->sendMultiple( [ $this->message() ] ),
				'Assert a per-message refusal is not read as a delivery'
		);
		self::assertContains(
				'Sender signature not confirmed',
				(string) $transport->getLastError(),
				'Assert Postmark’s own words reach whoever is looking'
		);
	}

	public function testAHalfAcceptedBatchIsRefused () {
		$transport = $this->transport( new Response( 200, [], json_encode( [
				[ 'ErrorCode' => 0, 'Message' => 'OK' ],
				[ 'ErrorCode' => 406, 'Message' => 'You tried to send to a recipient that has been marked as inactive.' ],
		] ) ) );

		self::assertSame(
				0,
				$transport->sendMultiple( [ $this->message(), $this->message() ] ),
				'Assert half a batch is not half a success: better twice than never'
		);
	}

	public function testAnHttpErrorSaysWhatPostmarkAnswered () {
		$transport = $this->transport( new Response( 422, [], json_encode( [
				'ErrorCode' => 10,
				'Message'   => 'Bad or missing API token',
		] ) ) );

		self::assertSame( 0, $transport->sendMultiple( [ $this->message() ] ) );
		self::assertContains( '422', (string) $transport->getLastError() );
		self::assertContains( 'Bad or missing API token', (string) $transport->getLastError() );
	}

	/**
	 * Une réponse qu'on ne sait pas lire ne prouve pas un envoi : le résumé
	 * doit pouvoir rattraper.
	 */
	public function testAnUnreadableAnswerIsNotASend () {
		$transport = $this->transport( new Response( 200, [], '<html>maintenance</html>' ) );

		self::assertSame( 0, $transport->sendMultiple( [ $this->message() ] ) );
	}
}
