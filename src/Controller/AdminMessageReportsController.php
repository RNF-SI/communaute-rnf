<?php

namespace App\Controller;

use App\Entity\MessageReport;
use App\Security\GroupVoter;
use App\Service\Community;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Les signalements de messages privés, pour l'équipe RNF.
 *
 * **Cet écran n'ouvre aucune conversation.** Il lit ce que chaque signalement
 * porte : la copie du message incriminé et celle des quelques messages qui le
 * précédaient, faites au moment du signalement. Aucune requête ne part d'ici
 * vers la messagerie, et c'est la propriété qu'il faut préserver en le
 * modifiant — la promesse faite aux membres est que personne ne lit leurs
 * échanges, et elle ne tient que si le code la rend vraie.
 *
 * L'accès suit celui des autres écrans d'administration : animateur du groupe
 * communauté, ce qui comprend les administrateurs de la plateforme.
 */
class AdminMessageReportsController extends AbstractController {
	/**
	 * @Route("/administration/message-reports", name="administration_message_reports", methods={"GET"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Service\Community                    $community
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function reports (
			Request $request,
			EntityManagerInterface $manager,
			Community $community
	) {
		$this->denyAccessUnlessGranted( GroupVoter::ADMIN, $community->getGroup() );

		$handled = $request->query->getBoolean( 'handled' );

		return $this->render( 'pages/messages/reports.html.twig', [
				'tab'     => 'message-reports',
				'reports' => $manager->getRepository( MessageReport::class )->findForAdmin( $handled ),
				'handled' => $handled,
				'pending' => $manager->getRepository( MessageReport::class )->countPending(),
		] );
	}

	/**
	 * Classer un signalement. Ce qui est fait du compte visé — un rappel, une
	 * désactivation — se fait ailleurs : ici on note seulement que quelqu'un
	 * s'en est occupé, pour que deux personnes de l'équipe ne traitent pas le
	 * même deux fois.
	 *
	 * @Route(
	 *     "/administration/message-reports/{id}/handle",
	 *     name="administration_message_report_handle",
	 *     methods={"POST"},
	 *     requirements={"id"="\d+"}
	 * )
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Service\Community                    $community
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function handle (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			Community $community
	) {
		$this->denyAccessUnlessGranted( GroupVoter::ADMIN, $community->getGroup() );

		// Cherché à la main, comme partout ailleurs dans ce dépôt.
		$report = $manager->getRepository( MessageReport::class )->find( (int) $id );

		if ( !$report ) {
			return $this->redirectToRoute( 'administration_message_reports' );
		}

		if ( $this->isCsrfTokenValid( 'message-reports', $request->request->get( '_token' ) ) ) {
			$reopen = $request->request->getBoolean( 'reopen' );

			$report->setHandledAt( $reopen ? NULL : new DateTime() );
			$report->setHandledBy( $reopen ? NULL : $this->getUser() );

			$manager->flush();

			$this->addFlash( 'notice', $reopen
					? 'messages.messaging.report_reopened'
					: 'messages.messaging.report_handled' );
		}

		return $this->redirectToRoute( 'administration_message_reports', [
				'handled' => $request->request->getBoolean( 'reopen' ) ? 1 : 0,
		] );
	}
}
