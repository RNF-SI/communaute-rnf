<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Notification;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Messaging\DockPresenter;
use App\Security\ConversationVoter;
use App\Security\UserVoter;
use App\Service\ConversationManager;
use App\Service\Tagging\TagParser;
use App\Service\UnreadCounts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ce que le dock demande au serveur : l'état de la boîte, un fil, et ce qui
 * est arrivé depuis la dernière fois.
 *
 * Le dock, ce sont les conversations qui restent ouvertes en bas de l'écran
 * pendant qu'on lit autre chose. Il ne remplace pas la page `/messages` : il
 * la double. Tout ce qu'il fait ici se fait aussi là-bas, en HTML, sans une
 * ligne de JavaScript — et c'est ce qui autorise le dock à n'être que du
 * confort. Une panne du sondage laisse une plateforme entière.
 *
 * **Rien de neuf côté droits.** Chaque route repasse par ConversationVoter,
 * qui, lui, ne court-circuite pas pour les administrateurs. Une messagerie
 * qui s'ouvrirait plus largement par une route JSON que par sa propre page
 * n'aurait pas de porte, elle aurait deux portes.
 *
 * Note pour qui relit : pas d'exemple de tag écrit en toutes lettres dans ces
 * blocs de documentation. Le chargeur de routes lit chacun d'eux comme une
 * annotation, et une arobase suivie d'un nom y fait échouer le démarrage de
 * toute l'application.
 */
class MessagingDockController extends AbstractController {
	/**
	 * Combien de conversations le panneau montre. Au-delà, on ne parcourt
	 * plus une liste : on ouvre la page, qui sait chercher.
	 */
	private const LIST_SIZE = 20;

	/**
	 * Combien de messages une fenêtre du dock charge en s'ouvrant. Le reste
	 * se lit sur la page, dont le lien est dans l'en-tête de la fenêtre.
	 */
	private const THREAD_SIZE = 40;

	/**
	 * Ce que le sondage rapporte au plus en un tour. Une borne, pas une
	 * pagination : quelqu'un qui revient après une semaine reprend au dernier
	 * message, pas à celui d'il y a mille.
	 */
	private const CATCH_UP = 100;

	/**
	 * Ce qui a bougé depuis la dernière fois.
	 *
	 * La seule route appelée en boucle. Elle répond en deux temps :
	 *
	 * - les compteurs, toujours — deux COUNT, c'est ce qui fait vivre la
	 *   pastille de l'en-tête même quand le dock n'a jamais été ouvert ;
	 * - le reste, seulement s'il s'est passé quelque chose.
	 *
	 * La liste des conversations repart **entière** dès qu'un message est
	 * arrivé, plutôt qu'en différences. C'est trois fois rien à calculer, et
	 * cela répare tout seul un dock qui aurait manqué un tour : la liste
	 * qu'il affiche est celle de la base, pas celle de ce qu'il a cru voir
	 * passer.
	 *
	 * @Route("/messages/live", name="messages_live", methods={"GET"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Messaging\DockPresenter              $presenter
	 * @param \App\Service\UnreadCounts                 $counts
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function live (
			Request $request,
			EntityManagerInterface $manager,
			DockPresenter $presenter,
			UnreadCounts $counts
	) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$full   = $request->query->getBoolean( 'full' );
		$since  = max( 0, $request->query->getInt( 'since' ) );
		$notice = max( 0, $request->query->getInt( 'notice' ) );

		$messagesRepository = $manager->getRepository( PrivateMessage::class );

		// Un dock qui s'ouvre sans curseur ne rejoue pas l'historique : il se
		// cale sur le dernier message et écoute la suite.
		if ( !$since && !$full ) {
			$since = $messagesRepository->lastIdFor( $user );
		}

		$fresh = $since
				? $messagesRepository->findSinceFor( $user, $since, self::CATCH_UP )
				: [];

		$cursor = $since;

		foreach ( $fresh as $message ) {
			$cursor = max( $cursor, (int) $message->getId() );
		}

		if ( !$cursor ) {
			$cursor = $messagesRepository->lastIdFor( $user );
		}

		$payload = [
				'cursor' => $cursor,
				'counts' => $counts->forUser( $user ),
		];

		// Les fils que le dock tient dépliés : c'est là, et là seulement, que
		// les messages eux-mêmes sont utiles.
		$open = $this->threadIds( $request->query->get( 'threads' ) );

		$messages = [];

		foreach ( $fresh as $message ) {
			$conversation = $message->getConversation();

			if ( !$conversation || !in_array( (int) $conversation->getId(), $open, TRUE ) ) {
				continue;
			}

			if ( !$this->isGranted( ConversationVoter::READ, $conversation ) ) {
				continue;
			}

			$messages[ $conversation->getId() ][] = $presenter->message( $message, $user );
		}

		if ( !empty( $messages ) ) {
			$payload[ 'messages' ] = $messages;
		}

		if ( $full || !empty( $fresh ) ) {
			$payload[ 'conversations' ] = $this->listFor( $user, $manager, $presenter );
		}

		// Les notifications passent au sondage pour que la pastille s'anime
		// sans recharger, mais celles d'un message privé s'arrêtent ici : le
		// dock montre déjà le message, et l'annoncer par-dessus ferait deux
		// bulles pour une phrase reçue.
		$fresher = $notice
				? $manager->getRepository( Notification::class )->findUnreadSince( $user, $notice )
				: [];

		$announced = [];
		$latest    = $notice;

		foreach ( $fresher as $notification ) {
			$latest = max( $latest, (int) $notification->getId() );

			if ( $notification->getType() === Notification::MESSAGE_NEW ) {
				continue;
			}

			$announced[] = $presenter->notification( $notification );
		}

		if ( !$latest ) {
			$latest = $manager->getRepository( Notification::class )->lastIdFor( $user );
		}

		$payload[ 'notice' ] = $latest;

		if ( !empty( $announced ) ) {
			$payload[ 'notifications' ] = $announced;
		}

		return $this->quiet( $payload );
	}

	/**
	 * Un fil, tel qu'une fenêtre du dock l'ouvre.
	 *
	 * L'ouvrir, c'est le lire — comme sur la page. Les compteurs renvoyés
	 * sont donc ceux d'après cette lecture.
	 *
	 * **Sauf `read=0`.** Le dock rouvre ses fenêtres à chaque page, celles
	 * qui sont repliées comprises : sans cette réserve, une fenêtre réduite
	 * dans un coin marquerait la conversation comme lue à chaque navigation,
	 * et les messages arrivés pendant ce temps disparaîtraient du compteur
	 * sans que personne les ait regardés.
	 *
	 * @Route("/messages/dock/thread/{id}", name="messages_dock_thread", methods={"GET"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Messaging\DockPresenter               $presenter
	 * @param \App\Service\ConversationManager           $conversations
	 * @param \App\Service\UnreadCounts                  $counts
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function thread (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			DockPresenter $presenter,
			ConversationManager $conversations,
			UnreadCounts $counts
	) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$conversation = $manager->getRepository( Conversation::class )->find( (int) $id );

		if ( !$conversation ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::READ, $conversation );

		$all = $manager->getRepository( PrivateMessage::class )->findForConversation( $conversation );

		$shown = array_slice( $all, -self::THREAD_SIZE );

		$rendered = [];

		foreach ( $shown as $message ) {
			$rendered[] = $presenter->message( $message, $user );
		}

		// `read` absent vaut « oui » : la lecture est le cas ordinaire, et
		// c'est la fenêtre repliée qui doit se signaler.
		if ( !$request->query->has( 'read' ) || $request->query->getBoolean( 'read' ) ) {
			$conversations->markRead( $conversation, $user );

			// Les compteurs ont changé du fait de cette lecture : ce qui a
			// été mémorisé avant l'écriture ne vaut plus.
			$counts->forget();
		}

		$last = end( $all );

		return $this->quiet( [
				'thread'   => $presenter->conversation( $conversation, $user, $last ?: NULL ),
				'messages' => $rendered,
				'more'     => count( $all ) > count( $shown ),
				'write'    => $this->isGranted( ConversationVoter::PARTICIPATE, $conversation ),
				'counts'   => $counts->forUser( $user ),
		] );
	}

	/**
	 * Écrire depuis le dock.
	 *
	 * Même service que la page — ConversationManager::post —, donc mêmes
	 * notifications, même remontée de la conversation chez ceux qui l'avaient
	 * rangée. Ce qui change tient à l'écran : les avertissements repartent
	 * dans la réponse au lieu de passer par un message éclair, qu'une page
	 * qui ne se recharge pas n'afficherait jamais.
	 *
	 * @Route("/messages/dock/send/{id}", name="messages_dock_send", methods={"POST"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\ConversationManager           $conversations
	 * @param \App\Messaging\DockPresenter               $presenter
	 * @param \App\Service\Tagging\TagParser             $tags
	 * @param \App\Service\UnreadCounts                  $counts
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function send (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations,
			DockPresenter $presenter,
			TagParser $tags,
			TranslatorInterface $translator,
			UnreadCounts $counts
	) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$conversation = $manager->getRepository( Conversation::class )->find( (int) $id );

		if ( !$conversation ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::PARTICIPATE, $conversation );

		if ( !$this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) ) {
			return $this->refused( 'messages.messaging.empty' );
		}

		$body = trim( (string) $request->request->get( 'body', '' ) );

		if ( $body === '' ) {
			return $this->refused( 'messages.messaging.empty' );
		}

		$message = $conversations->post( $conversation, $user, $body );

		$counts->forget();

		return $this->quiet( [
				'message'  => $presenter->message( $message, $user ),
				'cursor'   => $message->getId(),
				'warnings' => $this->outsiders( $tags, $translator, $message ),
				'counts'   => $counts->forUser( $user ),
		] );
	}

	/**
	 * Marquer une conversation comme lue depuis le dock.
	 *
	 * Appelée quand une fenêtre dépliée reçoit un message et que l'onglet est
	 * sous les yeux : ce qu'on est en train de lire ne doit pas rester compté
	 * comme en attente.
	 *
	 * @Route("/messages/dock/read/{id}", name="messages_dock_read", methods={"POST"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\ConversationManager           $conversations
	 * @param \App\Service\UnreadCounts                  $counts
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function read (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations,
			UnreadCounts $counts
	) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$conversation = $manager->getRepository( Conversation::class )->find( (int) $id );

		if ( !$conversation ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::READ, $conversation );

		if ( !$this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) ) {
			return $this->refused( 'messages.messaging.empty' );
		}

		$conversations->markRead( $conversation, $user );

		$counts->forget();

		return $this->quiet( [ 'counts' => $counts->forUser( $user ) ] );
	}

	/**
	 * Ouvrir une fenêtre sur quelqu'un, depuis sa fiche.
	 *
	 * Rien n'est créé : on cherche le tête-à-tête qui existe déjà. S'il n'y
	 * en a pas, le dock ouvre une fenêtre vide, et c'est le premier message
	 * envoyé qui crée la conversation — comme sur la page, où rien ne
	 * s'ouvre tant que rien n'est écrit.
	 *
	 * @Route("/messages/dock/with/{id}", name="messages_dock_with", methods={"GET"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Messaging\DockPresenter               $presenter
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function with ( $id, EntityManagerInterface $manager, DockPresenter $presenter ) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$other = $manager->getRepository( User::class )
						 ->findOneBy( [ 'id' => (int) $id, 'status' => User::STATUS_ACTIVE ] );

		if ( !$other ) {
			return $this->unknown();
		}

		if ( !$this->isGranted( ConversationVoter::CONTACT, $other ) ) {
			return $this->refused( 'messages.messaging.closed_box' );
		}

		$existing = $manager->getRepository( Conversation::class )->findPair( $user, $other );

		return $this->quiet( [
				'thread' => $existing ? $existing->getId() : 0,
				'person' => $presenter->person( $other ),
		] );
	}

	/**
	 * Créer la conversation et y déposer le premier message, depuis une
	 * fenêtre ouverte sur quelqu'un à qui l'on n'avait jamais écrit.
	 *
	 * @Route("/messages/dock/start/{id}", name="messages_dock_start", methods={"POST"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\ConversationManager           $conversations
	 * @param \App\Messaging\DockPresenter               $presenter
	 * @param \App\Service\Tagging\TagParser             $tags
	 * @param \App\Service\UnreadCounts                  $counts
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function start (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations,
			DockPresenter $presenter,
			TagParser $tags,
			TranslatorInterface $translator,
			UnreadCounts $counts
	) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$other = $manager->getRepository( User::class )
						 ->findOneBy( [ 'id' => (int) $id, 'status' => User::STATUS_ACTIVE ] );

		if ( !$other ) {
			return $this->unknown();
		}

		if ( !$this->isGranted( ConversationVoter::CONTACT, $other ) ) {
			return $this->refused( 'messages.messaging.closed_box' );
		}

		if ( !$this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) ) {
			return $this->refused( 'messages.messaging.empty' );
		}

		$body = trim( (string) $request->request->get( 'body', '' ) );

		if ( $body === '' ) {
			return $this->refused( 'messages.messaging.empty' );
		}

		$conversation = $conversations->open( $user, [ $other ] );
		$message      = $conversations->post( $conversation, $user, $body );

		$counts->forget();

		return $this->quiet( [
				'thread'   => $conversation->getId(),
				'message'  => $presenter->message( $message, $user ),
				'cursor'   => $message->getId(),
				'warnings' => $this->outsiders( $tags, $translator, $message ),
				'counts'   => $counts->forUser( $user ),
		] );
	}

	/**
	 * La boîte de quelqu'un, telle que le panneau la déroule.
	 *
	 * @param \App\Entity\User                     $user
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param \App\Messaging\DockPresenter         $presenter
	 *
	 * @return array
	 */
	private function listFor ( User $user, EntityManagerInterface $manager, DockPresenter $presenter ) {
		$list = array_slice(
				$manager->getRepository( Conversation::class )->findForUser( $user ),
				0,
				self::LIST_SIZE
		);

		$lasts = $manager->getRepository( PrivateMessage::class )->findLastFor( $list );

		$rows = [];

		foreach ( $list as $conversation ) {
			$id = $conversation->getId();

			$rows[] = $presenter->conversation(
					$conversation,
					$user,
					isset( $lasts[ $id ] ) ? $lasts[ $id ] : NULL
			);
		}

		return $rows;
	}

	/**
	 * Les identifiants de fils que le dock dit tenir ouverts.
	 *
	 * Bornés au nombre de fenêtres qu'un écran peut porter : le paramètre
	 * vient du navigateur, et rien n'empêcherait d'y écrire mille
	 * identifiants pour faire travailler le serveur.
	 *
	 * @param string|null $raw
	 *
	 * @return int[]
	 */
	private function threadIds ( $raw ) {
		// `?threads[]=1` rendrait un tableau là où l'on attend une chaîne, et
		// le convertir lèverait une alerte que l'environnement de test
		// transforme en échec. Ce paramètre vient du navigateur : il n'a pas
		// à être bien formé.
		if ( !is_scalar( $raw ) ) {
			return [];
		}

		$ids = array_filter( array_map( 'intval', explode( ',', (string) $raw ) ) );

		return array_slice( array_values( array_unique( $ids ) ), 0, 6 );
	}

	/**
	 * Ce qu'on dit à celui qui vient d'écrire quand son message désigne
	 * quelqu'un qui n'est pas dans la conversation : ce tag ne préviendra
	 * personne, lui montrer l'échange n'étant pas une option.
	 *
	 * @param \App\Service\Tagging\TagParser                     $tags
	 * @param \Symfony\Contracts\Translation\TranslatorInterface $translator
	 * @param \App\Entity\PrivateMessage                         $message
	 *
	 * @return string[]
	 */
	private function outsiders ( TagParser $tags, TranslatorInterface $translator, PrivateMessage $message ) {
		$conversation = $message->getConversation();

		if ( !$conversation ) {
			return [];
		}

		$names = [];

		foreach ( $tags->findPeople( $message->getBody(), $message->getAuthor() ) as $person ) {
			if ( !$conversation->includes( $person ) ) {
				$names[] = $person->getName();
			}
		}

		if ( empty( $names ) ) {
			return [];
		}

		// Le dock pose ces phrases en texte, pas en HTML : rien n'est échappé
		// ici, à la différence du message éclair de la page.
		return [ $translator->trans( 'messages.messaging.outsiders', [ '%names%' => implode( ', ', $names ) ] ) ];
	}

	/**
	 * Une réponse JSON qu'aucun cache intermédiaire ne doit garder.
	 *
	 * Un sondage renvoie l'état d'une boîte privée : le laisser passer dans
	 * un cache partagé le donnerait à lire au suivant.
	 *
	 * @param array $payload
	 * @param int   $status
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	private function quiet ( array $payload, $status = Response::HTTP_OK ) {
		$response = new JsonResponse( $payload, $status );

		$response->setPrivate();
		$response->headers->addCacheControlDirective( 'no-store' );

		return $response;
	}

	/**
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	private function unknown () {
		return $this->quiet( [ 'error' => 'unknown' ], Response::HTTP_NOT_FOUND );
	}

	/**
	 * @param string $key
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	private function refused ( $key ) {
		return $this->quiet( [ 'error' => $key ], Response::HTTP_BAD_REQUEST );
	}
}
