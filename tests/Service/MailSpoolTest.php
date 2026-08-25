<?php

namespace App\Tests\Service;

use App\Service\MailSpool;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swift_Mailer;
use Swift_Spool;
use Swift_Transport;
use Swift_Transport_SpoolTransport;

/**
 * « Remis au transport » ne veut rien dire quand le transport est une file.
 *
 * Swiftmailer est configuré en file mémoire : `send()` rend le nombre de
 * destinataires sans avoir joint Postmark, et l'envoi n'a lieu qu'à la fin de
 * la requête. Sur une page, c'est ce qu'on veut — personne n'attend un
 * aller-retour. En ligne de commande, cela veut dire que le résumé quotidien
 * marquait `emailedAt` sur des e-mails **mis en file**, dont le refus arrivait
 * après la fin de la commande, sans personne pour l'entendre.
 *
 * La distinction qui porte tout : **NULL n'est pas zéro.** Sans file, l'envoi
 * a déjà eu lieu et ce service n'a rien à en dire ; confondre les deux ferait
 * tenir pour perdu ce qui est parti, et un résumé serait renvoyé chaque jour.
 */
class MailSpoolTest extends TestCase {
	/**
	 * @param \Swift_Spool|null $spool
	 *
	 * @return \Swift_Mailer
	 */
	private function mailer ( Swift_Spool $spool = NULL ) {
		$transport = $spool
				? new Swift_Transport_SpoolTransport( $this->createMock( \Swift_Events_EventDispatcher::class ), $spool )
				: $this->createMock( Swift_Transport::class );

		$mailer = $this->createMock( Swift_Mailer::class );
		$mailer->method( 'getTransport' )->willReturn( $transport );

		return $mailer;
	}

	public function testWithoutAQueueThereIsNothingToConclude () {
		$spool = new MailSpool( $this->mailer(), $this->createMock( Swift_Transport::class ) );

		$this->assertFalse( $spool->isSpooled() );
		$this->assertNull(
				$spool->flush(),
				'Assert a direct transport says NULL, never 0 — what left must not be taken for lost'
		);
	}

	public function testWhatLeavesTheQueueIsCounted () {
		$queue = $this->createMock( Swift_Spool::class );
		$queue->method( 'flushQueue' )->willReturn( 3 );

		$spool = new MailSpool( $this->mailer( $queue ), $this->createMock( Swift_Transport::class ) );

		$this->assertTrue( $spool->isSpooled() );
		$this->assertSame( 3, $spool->flush() );
	}

	/**
	 * Le cas rencontré : Postmark refuse l'adresse d'expédition. Zéro, et
	 * l'appelant ne marque rien.
	 */
	public function testAQueueThatDeliversNothingSaysZero () {
		$queue = $this->createMock( Swift_Spool::class );
		$queue->method( 'flushQueue' )->willReturn( 0 );

		$spool = new MailSpool( $this->mailer( $queue ), $this->createMock( Swift_Transport::class ) );

		$this->assertSame( 0, $spool->flush() );
	}

	public function testATransportThatThrowsDoesNotBreakTheCommand () {
		$queue = $this->createMock( Swift_Spool::class );
		$queue->method( 'flushQueue' )->willThrowException( new RuntimeException( 'Postmark is unreachable' ) );

		$spool = new MailSpool( $this->mailer( $queue ), $this->createMock( Swift_Transport::class ) );

		$this->assertSame(
				0,
				$spool->flush(),
				'Assert an unreachable Postmark leaves the summary to be retried, not the command to fail'
		);
	}

	/**
	 * Une file sans transport réel ne peut rien vider : c'est le cas d'un
	 * environnement où la file n'est pas configurée côté conteneur.
	 */
	public function testAQueueWithoutARealTransportConcludesNothing () {
		$queue = $this->createMock( Swift_Spool::class );
		$queue->expects( $this->never() )->method( 'flushQueue' );

		$spool = new MailSpool( $this->mailer( $queue ), NULL );

		$this->assertNull( $spool->flush() );
	}
}
