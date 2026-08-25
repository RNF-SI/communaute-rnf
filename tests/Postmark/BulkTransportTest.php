<?php

namespace App\Tests\Postmark;

use App\Postmark\BulkTransport;
use PHPUnit\Framework\TestCase;

/**
 * Le lot vide.
 *
 * Envoyer un message de discussion à personne n'a rien d'exceptionnel :
 * depuis #38 le rythme par défaut est le résumé quotidien, si bien que le
 * courrier à chaud ne part que vers ceux qui ont explicitement choisi
 * l'immédiat — et le plus souvent, personne dans le groupe ne l'a fait. Une
 * copie anonymisée produit le même lot vide, MailGuard écartant toutes les
 * adresses.
 *
 * Le transport lisait pourtant `$messages[ 0 ]` avant de compter : Swift
 * recevait NULL là où il attend un message, et la page tombait en 500 **après**
 * que le message avait été enregistré. L'auteur voyait une erreur, son texte
 * était bien là, et rien ne disait lequel des deux croire.
 *
 * Les tests de DiscussionSender remplacent le transport par un double : ils ne
 * pouvaient pas voir ça. D'où celui-ci, qui éprouve la vraie classe.
 */
class BulkTransportTest extends TestCase {
	/**
	 * Jeton renseigné : c'est le chemin qui allait chercher $messages[ 0 ].
	 */
	public function testAnEmptyBatchSendsNothingAndRaisesNothing () {
		$transport = new BulkTransport( 'un-jeton-jamais-employé' );

		self::assertSame( 0, $transport->sendMultiple( [] ) );
	}

	/**
	 * Sans jeton, le transport se tait — et le **dit**.
	 *
	 * Il rendait TRUE, ce qui se lit « réussi » chez qui regarde la valeur.
	 * ContentSender marquait alors comme parties des notifications qui
	 * n'étaient jamais sorties de la machine, et le résumé, qui devait les
	 * rattraper, ne les reprenait plus. Sur une préproduction dont le
	 * POSTMARK_BULK_TOKEN est vide, cela donne des envois enregistrés que
	 * personne n'a reçus — et rien pour le signaler.
	 *
	 * Zéro est ce qui s'est passé.
	 */
	public function testWithoutATokenNothingIsSentAndTheCountSaysSo () {
		$transport = new BulkTransport( '' );

		self::assertSame( 0, $transport->sendMultiple( [] ) );
		self::assertSame(
				0,
				$transport->sendMultiple( [ new \Swift_Message( 'Objet' ) ] ),
				'Assert a mute transport never reports a delivery it did not make'
		);
	}
}
