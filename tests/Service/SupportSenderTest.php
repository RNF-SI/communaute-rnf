<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\EmailSender;
use App\Service\SupportSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Issue #45 — le formulaire de contact, côté envoi.
 *
 * Sans adresse de destination, le service se tait : c'est ce qui éteint la
 * page plutôt que de lui laisser accepter des messages qu'elle jetterait. La
 * même règle que RNF_EXPORT_TOKEN et ONLYOFFICE_URL.
 */
class SupportSenderTest extends TestCase {
	/**
	 * @param string $support
	 * @param mixed  $mailer
	 *
	 * @return \App\Service\SupportSender
	 */
	private function sender ( $support, $mailer ) {
		$twig = $this->createMock( Environment::class );
		$twig->method( 'render' )->willReturn( '<p>message</p>' );

		$router = $this->createMock( UrlGeneratorInterface::class );
		$router->method( 'generate' )->willReturn( 'https://example.org/members/1' );

		return new SupportSender( $mailer, $twig, $router, new ParameterBag( [
				'plateform' => [
						'name'    => 'Communauté RNF',
						'from'    => 'noreply@example.org',
						'support' => $support,
				],
		] ) );
	}

	/**
	 * @return \App\Entity\User
	 */
	private function user () {
		$user = new User();
		$user->setEmail( 'camille@example.org' );
		$user->setName( 'Camille Test' );

		return $user;
	}

	public function testItSendsToTheConfiguredAddressAndAnswersToTheAuthor () {
		$mailer = $this->createMock( EmailSender::class );

		$mailer->expects( $this->once() )
			   ->method( 'send' )
			   ->with(
					   [ 'noreply@example.org' => 'Communauté RNF' ],
					   'support@reserves-naturelles.org',
					   $this->stringContains( 'Un document ne s’ouvre pas' ),
					   $this->anything(),
					   [ 'Reply-To' => 'camille@example.org' ]
			   )
			   ->willReturn( 1 );

		$sender = $this->sender( 'support@reserves-naturelles.org', $mailer );

		$this->assertTrue( $sender->send( $this->user(), 'Un document ne s’ouvre pas', 'Bonjour' ) );
	}

	public function testWithoutAnAddressNothingIsSent () {
		$mailer = $this->createMock( EmailSender::class );
		$mailer->expects( $this->never() )->method( 'send' );

		$sender = $this->sender( '', $mailer );

		$this->assertFalse( $sender->isConfigured() );
		$this->assertFalse( $sender->send( $this->user(), 'Objet', 'Message' ) );
	}

	/**
	 * Un transport n'échoue pas seulement en levant : il rend un nombre, et
	 * zéro veut dire que rien n'est parti — le garde a refusé l'adresse, ou
	 * Postmark le lot. Dire « envoyé » là-dessus serait mentir à celui qui
	 * attend une réponse. (#38)
	 */
	public function testARefusedSendIsNotReportedAsSent () {
		$mailer = $this->createMock( EmailSender::class );
		$mailer->method( 'send' )->willReturn( 0 );

		$sender = $this->sender( 'support@reserves-naturelles.org', $mailer );

		$this->assertFalse( $sender->send( $this->user(), 'Objet', 'Message' ) );
	}
}
