<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Le formulaire de contact, côté envoi (#45).
 *
 * Trois choses qui ne sont pas interchangeables, et que c'est tout l'objet de
 * ce service de tenir ensemble :
 *
 * - **L'expéditeur est la plateforme**, jamais celui qui écrit. Postmark ne
 *   signe qu'un domaine à lui ; partir de l'adresse du membre ferait refuser
 *   le message, ou le ferait tomber en indésirable chez le support — c'est de
 *   l'usurpation, du point de vue de SPF et de DKIM.
 * - **Le Reply-To porte son adresse**, pour qu'on lui réponde d'un clic. Sans
 *   lui, répondre obligerait à recopier une adresse lue dans le corps du
 *   message.
 * - **Sans adresse de destination, on n'envoie pas** : `isConfigured()` rend
 *   FALSE, la route ne répond plus et le lien du pied de page disparaît. Même
 *   règle que RNF_EXPORT_TOKEN ou ONLYOFFICE_URL — une intégration non
 *   configurée ne fabrique pas de pages mortes, et surtout pas un formulaire
 *   qui accepterait un message pour le jeter.
 */
class SupportSender {
	/**
	 * @var \App\Service\EmailSender
	 */
	private $mailer;

	/**
	 * @var \Twig\Environment
	 */
	private $twig;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $router;

	/**
	 * @var array
	 */
	private $platform;

	public function __construct (
			EmailSender $mailer,
			Environment $twig,
			UrlGeneratorInterface $router,
			ParameterBagInterface $parameters
	) {
		$this->mailer   = $mailer;
		$this->twig     = $twig;
		$this->router   = $router;
		$this->platform = $parameters->has( 'plateform' ) ? (array) $parameters->get( 'plateform' ) : [];
	}

	/**
	 * @return string
	 */
	public function getAddress () {
		return trim( (string) ( $this->platform[ 'support' ] ?? '' ) );
	}

	/**
	 * @return bool
	 */
	public function isConfigured () {
		return $this->getAddress() !== '';
	}

	/**
	 * @param \App\Entity\User $user    qui écrit
	 * @param string           $subject objet saisi
	 * @param string           $body    message saisi, en texte brut
	 *
	 * @return bool TRUE si le message a bien été remis au transport
	 */
	public function send ( User $user, $subject, $body ) {
		if ( !$this->isConfigured() ) {
			return FALSE;
		}

		$html = $this->twig->render( 'emails/contact-support.html.twig', [
				'user'    => $user,
				'subject' => $subject,
				'body'    => $body,
				'profile' => $this->router->generate(
						'member',
						[ 'user_id' => $user->getId() ],
						UrlGeneratorInterface::ABSOLUTE_URL
				),
		] );

		$sent = $this->mailer->send(
				[ $this->platform[ 'from' ] => $this->platform[ 'name' ] ],
				$this->getAddress(),
				sprintf( '[%s] %s', $this->platform[ 'name' ], $subject ),
				$html,
				// Ce que l'on ouvre en cliquant « Répondre » : la personne, et
				// non la boîte d'envoi de la plateforme, qui ne lit rien.
				[ 'Reply-To' => $user->getEmail() ]
		);

		// `send()` rend un nombre de destinataires — zéro quand le garde
		// refuse une adresse indélivrable. Le lire est ce qui permet de dire
		// « envoyé » sans mentir. (#38)
		return $sent > 0;
	}
}
