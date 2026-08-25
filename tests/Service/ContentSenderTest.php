<?php

namespace App\Tests\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Postmark\BulkTransport;
use App\Service\ContentSender;
use App\Service\HashGenerator;
use App\Service\HtmlToText;
use App\Service\MailGuard;
use DateTime;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swift_Message;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Le troisième chemin d'e-mail : une page, une actualité ou un document qui
 * paraît, pour qui a demandé l'immédiat sur cette catégorie. (#38)
 *
 * Ce qui se contrôle ici n'est pas l'envoi — c'est **ce qui se passe quand il
 * n'a pas lieu**. Ce chemin est emprunté au milieu de la requête de quelqu'un
 * qui vient de publier : une adresse d'expédition absente, un gabarit qui
 * lève, un transport muet ne doivent pas lui rendre une page d'erreur alors
 * que son contenu, lui, est bien enregistré.
 *
 * Et le corollaire, qui est la vraie raison d'être de la valeur de retour :
 * ce qui n'est pas parti n'est **pas** rendu à l'appelant, donc pas marqué
 * `emailedAt`, donc repris par le résumé. Un envoi raté doit se rattraper, pas
 * disparaître.
 */
class ContentSenderTest extends TestCase {
	/**
	 * @param \App\Entity\Usergroup|null $group
	 * @param string                     $email
	 *
	 * @return \App\Entity\Notification
	 */
	private function notification ( Usergroup $group = NULL, $email = 'membre@rnfrance.org' ) {
		$recipient = new User();
		$recipient->setEmail( $email );
		$recipient->setName( 'Manon Membre' );

		$author = new User();
		$author->setEmail( 'auteur@rnfrance.org' );
		$author->setName( 'Rémi Référent' );

		$notification = new Notification();
		$notification->setRecipient( $recipient );
		$notification->setAuthor( $author );
		$notification->setUsergroup( $group );
		$notification->setType( Notification::ARTICLE_CREATE );
		$notification->setTitle( 'Le séminaire annuel se tiendra en octobre' );
		$notification->setUrl( '/groups/groupe-de-test/articles/le-seminaire' );
		$notification->setCreatedAt( new DateTime() );
		$notification->setByEmail( TRUE );

		return $notification;
	}

	/**
	 * @return \App\Entity\Usergroup
	 */
	private function group () {
		$group = new Usergroup();
		$group->setName( 'Groupe de test' );
		$group->setSlug( 'groupe-de-test' );

		return $group;
	}

	/**
	 * @param \App\Postmark\BulkTransport $transport
	 * @param array                       $params
	 * @param string                      $environment
	 *
	 * @return \App\Service\ContentSender
	 */
	private function sender ( BulkTransport $transport, array $params = NULL, $environment = 'test' ) {
		$twig = $this->createMock( Environment::class );
		$twig->method( 'render' )->willReturn( '<p>Bonjour <a href="https://example.org/x">l’actualité</a></p>' );

		$hashGenerator = $this->createMock( HashGenerator::class );
		$hashGenerator->method( 'generateUserHash' )->willReturn( '1|hash' );

		$router = $this->createMock( UrlGeneratorInterface::class );
		$router->method( 'generate' )->willReturn( 'https://example.org/unsubscribe/1%7Chash' );

		$translator = $this->createMock( TranslatorInterface::class );
		$translator->method( 'trans' )->willReturn( 'Nouvelle actualité' );

		return new ContentSender(
				$transport,
				$params === NULL ? [ 'from' => 'plateforme@rnfrance.org', 'name' => 'Communauté RNF' ] : $params,
				$twig,
				new HtmlToText(),
				$hashGenerator,
				$router,
				new MailGuard( $environment ),
				$translator
		);
	}

	/**
	 * Un transport qui note ce qu'on lui donne.
	 *
	 * @param \Swift_Message[] $sent rempli par référence
	 *
	 * @return \App\Postmark\BulkTransport
	 */
	private function recordingTransport ( array &$sent ) {
		$transport = $this->createMock( BulkTransport::class );
		$transport->method( 'sendMultiple' )
				  ->willReturnCallback( function ( array $messages ) use ( &$sent ) {
					  $sent = $messages;

					  return count( $messages );
				  } );

		return $transport;
	}

	/**
	 * @return \App\Postmark\BulkTransport
	 */
	private function failingTransport () {
		$transport = $this->createMock( BulkTransport::class );
		$transport->method( 'sendMultiple' )
				  ->willThrowException( new RuntimeException( 'Postmark is silent' ) );

		return $transport;
	}

	public function testWhatWentOutIsHandedBackToBeMarked () {
		$sent         = [];
		$notification = $this->notification( $this->group() );

		$done = $this->sender( $this->recordingTransport( $sent ) )->sendNow( [ $notification ] );

		$this->assertCount( 1, $sent, 'Assert the message was handed to the transport' );
		$this->assertSame(
				[ $notification ],
				$done,
				'Assert the caller learns what left, so the summary does not send it a second time'
		);
	}

	/**
	 * Le cas qui a fait tomber une page : la messagerie part en immédiat par
	 * défaut, si bien qu'un environnement sans adresse d'expédition échouait
	 * dès qu'on écrivait à quelqu'un.
	 */
	public function testNothingIsAttemptedWithoutASendingAddress () {
		$transport = $this->createMock( BulkTransport::class );
		$transport->expects( $this->never() )->method( 'sendMultiple' );

		$sender = $this->sender( $transport, [ 'from' => '', 'name' => 'Communauté RNF' ] );

		$this->assertSame(
				[],
				$sender->sendNow( [ $this->notification( $this->group() ) ] ),
				'Assert an unconfigured platform gives up quietly instead of failing a page'
		);
	}

	/**
	 * Un transport qui ne lève pas, et n'envoie rien.
	 *
	 * @param int|bool $answer ce que rend sendMultiple()
	 *
	 * @return \App\Postmark\BulkTransport
	 */
	private function muteTransport ( $answer ) {
		$transport = $this->createMock( BulkTransport::class );
		$transport->method( 'sendMultiple' )->willReturn( $answer );

		return $transport;
	}

	/**
	 * **Le cas qui a fait croire à une panne d'e-mail sur la préproduction.**
	 *
	 * Un transport n'échoue pas seulement en levant. Postmark qui refuse le
	 * lot rend zéro ; un POSTMARK_BULK_TOKEN vide rend TRUE sans avoir rien
	 * envoyé. Cette valeur était jetée, si bien que des notifications jamais
	 * sorties de la machine étaient marquées comme parties — et le résumé,
	 * qui devait rattraper, ne les reprenait plus.
	 *
	 * @dataProvider muteAnswers
	 *
	 * @param int|bool $answer
	 */
	public function testATransportThatSendsNothingMarksNothing ( $answer ) {
		$done = $this->sender( $this->muteTransport( $answer ) )
					 ->sendNow( [ $this->notification( $this->group() ) ] );

		$this->assertSame(
				[],
				$done,
				'Assert what never left is left for the summary, whatever shape the refusal takes'
		);
	}

	/**
	 * @return array
	 */
	public function muteAnswers () {
		return [
				'Postmark refuse le lot' => [ 0 ],
				'aucun jeton configuré'  => [ TRUE ],
		];
	}

	/**
	 * Un lot partiellement remis n'est pas un lot remis : on préfère qu'un
	 * membre reçoive deux fois plutôt qu'aucune fois.
	 */
	public function testAPartialBatchIsNotTakenForASuccess () {
		$transport = $this->muteTransport( 1 );

		$done = $this->sender( $transport )->sendNow( [
				$this->notification( $this->group(), 'un@rnfrance.org' ),
				$this->notification( $this->group(), 'deux@rnfrance.org' ),
		] );

		$this->assertSame( [], $done );
	}

	public function testATransportThatFailsMarksNothing () {
		$done = $this->sender( $this->failingTransport() )
					 ->sendNow( [ $this->notification( $this->group() ) ] );

		$this->assertSame(
				[],
				$done,
				'Assert a silent transport leaves the notification for the summary to pick up'
		);
	}

	/**
	 * Une copie anonymisée porte des adresses en example.org, qui n'acceptent
	 * rien : chaque envoi y produit un rejet dur, et un fournisseur suspend un
	 * compte dont le taux de rejet grimpe. (#14)
	 */
	public function testAnUndeliverableAddressIsLeftOut () {
		$sent = [];

		$done = $this->sender( $this->recordingTransport( $sent ), NULL, 'prod' )
					 ->sendNow( [ $this->notification( $this->group(), 'deleted-12@example.org' ) ] );

		$this->assertSame( [], $sent, 'Assert nothing is handed to the transport' );
		$this->assertSame( [], $done, 'Assert nothing is reported as sent either' );
	}

	public function testTheGroupIsNamedInTheSubject () {
		$sent = [];

		$this->sender( $this->recordingTransport( $sent ) )
			 ->sendNow( [ $this->notification( $this->group() ) ] );

		$this->assertSame(
				'[Groupe de test] Le séminaire annuel se tiendra en octobre',
				$sent[ 0 ]->getSubject(),
				'Assert an e-mail says which group it comes from, before saying anything else'
		);
	}

	/**
	 * La notification d'un message privé est la seule sans groupe, et son
	 * titre est le **nom de celui qui écrit**. « Jeanne Réserve » seul en objet
	 * ne dirait rien : on met devant la phrase que la plateforme affiche.
	 */
	public function testWithoutAGroupTheSubjectSaysWhatItIs () {
		$sent = [];

		$notification = $this->notification();
		$notification->setType( Notification::MESSAGE_NEW );
		$notification->setTitle( 'Jeanne Réserve' );

		$this->sender( $this->recordingTransport( $sent ) )->sendNow( [ $notification ] );

		$this->assertSame(
				'Nouvelle actualité Jeanne Réserve',
				$sent[ 0 ]->getSubject(),
				'Assert a subject without brackets still says what it is about'
		);
	}

	public function testTheMessageCarriesAPlainTextHalf () {
		$sent = [];

		$this->sender( $this->recordingTransport( $sent ) )
			 ->sendNow( [ $this->notification( $this->group() ) ] );

		$parts = array_map( function ( $part ) {
			return $part->getContentType();
		}, $sent[ 0 ]->getChildren() );

		$this->assertContains(
				'text/plain',
				$parts,
				'Assert an HTML-only message is not sent: it is one of the oldest spam signals there is'
		);
	}

	/**
	 * Un désabonnement en un clic, exigé des expéditeurs en masse par Gmail et
	 * Yahoo depuis 2024. (#14)
	 */
	public function testTheMessageCarriesAOneClickUnsubscribe () {
		$sent = [];

		$this->sender( $this->recordingTransport( $sent ) )
			 ->sendNow( [ $this->notification( $this->group() ) ] );

		$headers = $sent[ 0 ]->getHeaders();

		$this->assertTrue( $headers->has( 'List-Unsubscribe' ) );
		$this->assertTrue( $headers->has( 'List-Unsubscribe-Post' ) );
	}

	/**
	 * Un envoi en lot, et non un envoi par membre : c'est ce qui distingue ce
	 * chemin du résumé, et ce qui fait qu'un groupe de deux cents personnes ne
	 * retient pas l'auteur sur sa page.
	 */
	public function testEverybodyLeavesInASingleCall () {
		$sent = [];

		$transport = $this->createMock( BulkTransport::class );

		$calls = 0;
		$transport->method( 'sendMultiple' )
				  ->willReturnCallback( function ( array $messages ) use ( &$sent, &$calls ) {
					  $calls++;
					  $sent = $messages;

					  return count( $messages );
				  } );

		$group = $this->group();

		$done = $this->sender( $transport )->sendNow( [
				$this->notification( $group, 'un@rnfrance.org' ),
				$this->notification( $group, 'deux@rnfrance.org' ),
				$this->notification( $group, 'trois@rnfrance.org' ),
		] );

		$this->assertSame( 1, $calls, 'Assert one call to the API, not one per member' );
		$this->assertCount( 3, $sent );
		$this->assertCount( 3, $done );
	}
}
