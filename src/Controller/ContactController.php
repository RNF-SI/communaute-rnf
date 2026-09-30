<?php

namespace App\Controller;

use App\Form\ContactType;
use App\Security\UserVoter;
use App\Service\SupportSender;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Écrire au support depuis la plateforme (#45).
 *
 * Réservé aux membres connectés : l'identité de celui qui écrit vient de son
 * compte, ce qui évite à la fois d'avoir à la saisir et d'avoir à protéger la
 * page des automates. Qui n'arrive pas à se connecter n'a donc pas ce chemin —
 * il reste l'adresse du support, que le pied de page et les pages d'aide
 * portent en clair.
 */
class ContactController extends AbstractController {
	/**
	 * @Route("/contact", name="contact", methods={"GET", "POST"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \App\Service\SupportSender                $support
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function contact ( Request $request, SupportSender $support ) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		// Sans adresse de destination, la page n'existe pas plutôt que
		// d'accepter un message qu'elle ne saurait où remettre.
		if ( !$support->isConfigured() ) {
			throw $this->createNotFoundException( 'No support address configured' );
		}

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$form = $this->createForm( ContactType::class );
		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			$sent = $support->send(
					$user,
					(string) $form->get( 'subject' )->getData(),
					(string) $form->get( 'message' )->getData()
			);

			// Ne dire « transmis » que de ce qui est parti : un envoi refusé
			// laisse le formulaire rempli, pour qu'on ne réécrive pas tout.
			if ( $sent ) {
				$this->addFlash( 'notice', 'messages.contact.sent' );

				return $this->redirectToRoute( 'contact' );
			}

			$this->addFlash( 'error', 'messages.contact.failed' );
		}

		return $this->render( 'pages/contact.html.twig', [
				'form'    => $form->createView(),
				'user'    => $user,
				'support' => $support->getAddress(),
		] );
	}
}
