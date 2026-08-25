<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Security\ConversationVoter;
use App\Security\UserVoter;
use App\Service\ConversationManager;
use App\Service\Tagging\TaggedThing;
use App\Service\Tagging\TagParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * La messagerie : des conversations privées entre membres, hors de tout
 * groupe.
 *
 * Une seule page les porte toutes — la liste à gauche, le fil ouvert à
 * droite —, et elle s'affiche entièrement côté serveur. Le JavaScript
 * n'ajoute que la liste de suggestions des tags : sans lui, on tape le tag à
 * la main et le message part pareil.
 *
 * Note pour qui relit : pas d'exemple de tag écrit en toutes lettres dans ces
 * blocs de documentation. Le chargeur de routes lit chacun d'eux comme une
 * annotation, et une arobase suivie d'un nom y fait échouer le démarrage de
 * toute l'application. Les exemples vivent dans TagParser, que rien ne relit
 * de cette façon.
 */
class MessagesController extends AbstractController {
	/**
	 * Combien de conversations la liste montre. Au-delà, on ne parcourt plus
	 * une liste : on cherche, et le filtre est là pour ça.
	 */
	private const PER_PAGE = 50;

	/**
	 * @Route("/messages", name="messages_index", methods={"GET"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Service\ConversationManager          $conversations
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function index (
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations
	) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$query    = trim( (string) $request->query->get( 'q', '' ) );
		$archived = $request->query->getBoolean( 'archived' );

		$list = array_slice(
				$manager->getRepository( Conversation::class )->findForUser( $user, $query, $archived ),
				0,
				self::PER_PAGE
		);

		// Le dernier message de chaque conversation, ramassé d'un coup : ce
		// qui nomme une ligne de la liste sans charger son fil.
		$lasts = $manager->getRepository( PrivateMessage::class )->findLastFor( $list );

		$open     = NULL;
		$messages = [];

		if ( $request->query->has( 'conversation' ) ) {
			$open = $manager->getRepository( Conversation::class )
							->find( $request->query->getInt( 'conversation' ) );

			if ( !$open || !$this->isGranted( ConversationVoter::READ, $open ) ) {
				$this->addFlash( 'error', 'messages.messaging.unknown_conversation' );

				return $this->redirectToRoute( 'messages_index' );
			}

			$messages = $manager->getRepository( PrivateMessage::class )->findForConversation( $open );

			// Ouvrir une conversation, c'est la lire. La barre « nouveaux
			// messages » est repérée avant, pour que le fil l'affiche encore
			// cette fois-ci.
			//
			// Elle est cherchée ici et non dans le gabarit : en Twig, un
			// `set` posé dans une boucle ne survit pas à la boucle, et la
			// barre se répéterait à chaque message non lu.
			$participant = $open->getParticipantFor( $user );

			$firstUnread = $this->firstUnread( $messages, $participant );

			$conversations->markRead( $open, $user );
		}

		// « Ajouter quelqu'un » cherche côté serveur : la page n'a pas besoin
		// de JavaScript pour qu'on choisisse à qui on parle.
		$search = trim( (string) $request->query->get( 'add', '' ) );

		// Ceux qu'on peut encore ajouter : le tri se fait ici, pour la même
		// raison qu'au-dessus.
		$candidates = [];

		if ( $open && ( $search !== '' ) ) {
			foreach ( $manager->getRepository( User::class )->searchActiveByName( $search ) as $candidate ) {
				if ( !$open->includes( $candidate ) ) {
					$candidates[] = $candidate;
				}
			}
		}

		return $this->render( 'pages/messages/index.html.twig', [
				'conversations' => $list,
				'lasts'         => $lasts,
				'conversation'  => $open,
				'messages'      => $messages,
				'firstUnread'   => isset( $firstUnread ) ? $firstUnread : NULL,
				'query'         => $query,
				'archived'      => $archived,
				'search'        => $search,
				'candidates'    => $candidates,
		] );
	}

	/**
	 * Ouvrir une conversation : avec une personne trouvée dans l'annuaire,
	 * ou avec plusieurs d'un coup.
	 *
	 * Rien n'est créé tant que rien n'est écrit — une conversation vide dans
	 * la boîte de quelqu'un ne dit rien et ne se supprime pas.
	 *
	 * @Route("/messages/new", name="messages_new", methods={"GET", "POST"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Service\ConversationManager          $conversations
	 * @param \App\Service\Tagging\TagParser            $tags
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function create (
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations,
			TagParser $tags,
			TranslatorInterface $translator
	) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$recipients = $this->people( $manager, (array) $request->get( 'to', [] ) );
		$body       = (string) $request->request->get( 'body', '' );

		// « Répondre en privé » depuis une discussion de groupe arrive avec le
		// titre de cette discussion : le message s'ouvre sur le tag qui y
		// ramène, et celui qui le reçoit sait de quoi on lui parle.
		if ( !$request->isMethod( 'POST' ) && $request->query->get( 'about' ) ) {
			$body = '#' . trim( (string) $request->query->get( 'about' ) ) . ' ';
		}

		// Une boîte fermée dans un lot de cinq n'annule pas les quatre autres :
		// on écarte cette personne-là, on le dit, et on garde le reste — sans
		// quoi il faudrait tout recocher pour un refus qui n'en concernait
		// qu'un.
		$allowed = [];

		foreach ( $recipients as $recipient ) {
			if ( $this->isGranted( ConversationVoter::CONTACT, $recipient ) ) {
				$allowed[] = $recipient;

				continue;
			}

			$this->addFlash( 'error', $this->named( $translator, 'messages.messaging.closed_box', $recipient ) );
		}

		$recipients = $allowed;

		// Le tête-à-tête existe déjà : on n'ouvre pas un second fil, on
		// rejoint le premier. Y arriver par « Écrire à » ne doit pas couper
		// une conversation en deux.
		if ( !$request->isMethod( 'POST' ) && ( count( $recipients ) === 1 ) ) {
			$existing = $manager->getRepository( Conversation::class )->findPair( $user, $recipients[ 0 ] );

			if ( $existing && !$request->query->get( 'about' ) ) {
				return $this->redirectToRoute( 'messages_index', [ 'conversation' => $existing->getId() ] );
			}
		}

		if (
				$request->isMethod( 'POST' )
				&& !$request->request->has( 'search-submit' )
				&& $this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) )
		) {
			if ( empty( $recipients ) || ( trim( $body ) === '' ) ) {
				$this->addFlash( 'error', 'messages.messaging.incomplete' );
			}
			else {
				$conversation = $conversations->open( $user, $recipients );
				$message      = $conversations->post( $conversation, $user, $body );

				$this->warnAboutOutsiders( $tags, $translator, $message );

				return $this->redirectToRoute( 'messages_index', [ 'conversation' => $conversation->getId() ] );
			}
		}

		$search = trim( (string) $request->get( 'search', '' ) );
		$chosen = [];

		foreach ( $recipients as $recipient ) {
			$chosen[ $recipient->getId() ] = TRUE;
		}

		// Écarter ici ce qui est déjà coché plutôt que dans le gabarit : en
		// Twig, un `set` posé dans une boucle ne survit pas à la boucle, et la
		// liste proposerait deux fois les mêmes personnes.
		$candidates = [];

		if ( $search !== '' ) {
			foreach ( $manager->getRepository( User::class )->searchActiveByName( $search ) as $candidate ) {
				if ( !isset( $chosen[ $candidate->getId() ] ) ) {
					$candidates[] = $candidate;
				}
			}
		}

		return $this->render( 'pages/messages/new.html.twig', [
				'recipients' => $recipients,
				'body'       => $body,
				'search'     => $search,
				'candidates' => $candidates,
		] );
	}

	/**
	 * @Route("/messages/{id}/send", name="messages_send", methods={"POST"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \App\Service\ConversationManager          $conversations
	 * @param \App\Service\Tagging\TagParser            $tags
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function send (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations,
			TagParser $tags,
			TranslatorInterface $translator
	) {
		$conversation = $this->conversation( $manager, $id );

		if ( !$conversation ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::PARTICIPATE, $conversation );

		$body = trim( (string) $request->request->get( 'body', '' ) );

		if ( !$this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) || ( $body === '' ) ) {
			$this->addFlash( 'error', 'messages.messaging.empty' );

			return $this->back( $conversation );
		}

		$message = $conversations->post( $conversation, $this->getUser(), $body );

		$this->warnAboutOutsiders( $tags, $translator, $message );

		return $this->back( $conversation );
	}

	/**
	 * @Route("/messages/{id}/add", name="messages_add", methods={"POST"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Service\ConversationManager          $conversations
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function add (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations,
			TranslatorInterface $translator
	) {
		$conversation = $this->conversation( $manager, $id );

		if ( !$conversation ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::PARTICIPATE, $conversation );

		if ( !$this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) ) {
			return $this->back( $conversation );
		}

		$added = 0;

		foreach ( $this->people( $manager, (array) $request->request->get( 'to', [] ) ) as $recipient ) {
			if ( !$this->isGranted( ConversationVoter::CONTACT, $recipient ) ) {
				$this->addFlash( 'error', $this->named( $translator, 'messages.messaging.closed_box', $recipient ) );

				continue;
			}

			$added += $conversations->add( $conversation, $recipient ) ? 1 : 0;
		}

		if ( $added ) {
			$this->addFlash( 'notice', 'messages.messaging.added' );
		}

		return $this->back( $conversation );
	}

	/**
	 * @Route("/messages/{id}/leave", name="messages_leave", methods={"POST"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \App\Service\ConversationManager          $conversations
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function leave (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations
	) {
		$conversation = $this->conversation( $manager, $id );

		if ( !$conversation ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::PARTICIPATE, $conversation );

		if ( $this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) ) {
			$conversations->leave( $conversation, $this->getUser() );

			$this->addFlash( 'notice', 'messages.messaging.left' );
		}

		return $this->redirectToRoute( 'messages_index' );
	}

	/**
	 * @Route("/messages/{id}/archive", name="messages_archive", methods={"POST"}, requirements={"id"="\d+"})
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \App\Service\ConversationManager          $conversations
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function archive (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations
	) {
		$conversation = $this->conversation( $manager, $id );

		if ( !$conversation ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::READ, $conversation );

		if ( $this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) ) {
			$archived = !$request->request->getBoolean( 'restore' );

			$conversations->archive( $conversation, $this->getUser(), $archived );

			$this->addFlash( 'notice', $archived ? 'messages.messaging.archived' : 'messages.messaging.restored' );
		}

		return $this->redirectToRoute( 'messages_index', [ 'archived' => $request->request->getBoolean( 'restore' ) ? 0 : 1 ] );
	}

	/**
	 * @Route(
	 *     "/messages/message/{id}/edit",
	 *     name="messages_message_edit",
	 *     methods={"POST"},
	 *     requirements={"id"="\d+"}
	 * )
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \App\Service\ConversationManager          $conversations
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function editMessage (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations
	) {
		$message = $this->message( $manager, $id );

		if ( !$message ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::EDIT, $message );

		$body = trim( (string) $request->request->get( 'body', '' ) );

		if ( $this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) && ( $body !== '' ) ) {
			$conversations->edit( $message, $body );
		}

		return $this->back( $message->getConversation() );
	}

	/**
	 * @Route(
	 *     "/messages/message/{id}/delete",
	 *     name="messages_message_delete",
	 *     methods={"POST"},
	 *     requirements={"id"="\d+"}
	 * )
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \App\Service\ConversationManager          $conversations
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function deleteMessage (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations
	) {
		$message = $this->message( $manager, $id );

		if ( !$message ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::EDIT, $message );

		if ( $this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) ) ) {
			$conversations->delete( $message );

			$this->addFlash( 'notice', 'messages.messaging.deleted' );
		}

		return $this->back( $message->getConversation() );
	}

	/**
	 * Transmettre un message à l'équipe RNF.
	 *
	 * L'écran dit ce qui sera transmis — le message et les quelques messages
	 * qui le précèdent, recopiés — avant de valider. Signaler quelqu'un est
	 * un geste sérieux : il ne doit pas se faire sans savoir ce qu'on donne à
	 * lire.
	 *
	 * @Route(
	 *     "/messages/message/{id}/report",
	 *     name="messages_message_report",
	 *     methods={"GET", "POST"},
	 *     requirements={"id"="\d+"}
	 * )
	 *
	 * @param                                            $id
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Service\ConversationManager          $conversations
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function reportMessage (
			$id,
			Request $request,
			EntityManagerInterface $manager,
			ConversationManager $conversations
	) {
		$message = $this->message( $manager, $id );

		if ( !$message ) {
			return $this->unknown();
		}

		$this->denyAccessUnlessGranted( ConversationVoter::REPORT, $message );

		if (
				$request->isMethod( 'POST' )
				&& $this->isCsrfTokenValid( 'messages', $request->request->get( '_token' ) )
		) {
			$conversations->report( $message, $this->getUser(), $request->request->get( 'reason' ) );

			$this->addFlash( 'notice', 'messages.messaging.reported' );

			return $this->back( $message->getConversation() );
		}

		return $this->render( 'pages/messages/report.html.twig', [
				'message' => $message,
				'context' => $manager->getRepository( PrivateMessage::class )->findBefore( $message ),
		] );
	}

	/**
	 * Ce que propose la liste quand on tape « @ » ou « # » dans un message.
	 *
	 * @Route("/messages/suggestions", name="messages_suggestions", methods={"GET"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \App\Service\Tagging\TagParser            $tags
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function suggestions ( Request $request, TagParser $tags ) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		$prefix = ( $request->query->get( 'prefix' ) === '#' ) ? '#' : '@';

		return new JsonResponse( [
				'suggestions' => $tags->suggest( $prefix, $request->query->get( 'q', '' ), $this->getUser() ),
		] );
	}

	/**
	 * Ce que montre le bouton « Insérer un lien », qui fait au clic ce que la
	 * liste des tags fait à la frappe.
	 *
	 * Même service, mêmes droits, et surtout même syntaxe : c'est le serveur
	 * qui rend le texte à écrire — « #Titre », ou « #"Titre : à rallonge" »
	 * quand le titre ne se relit pas nu. Le navigateur n'a pas à connaître la
	 * grammaire d'un tag ; s'il la connaissait, elle finirait par diverger de
	 * celle qui la relit.
	 *
	 * @Route("/messages/picker", name="messages_picker", methods={"GET"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \App\Service\Tagging\TagParser            $tags
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function picker ( Request $request, TagParser $tags ) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		$kind = $request->query->get( 'kind' );

		// Un type inconnu vaut « tous les types » plutôt qu'une erreur : la
		// requête vient d'une liste déroulante, pas d'une API publique.
		if ( !in_array( $kind, TaggedThing::kinds(), TRUE ) ) {
			$kind = NULL;
		}

		return new JsonResponse( [
				'suggestions' => $tags->pick( $request->query->get( 'q', '' ), $kind, $this->getUser() ),
		] );
	}

	/**
	 * La conversation portant cet identifiant.
	 *
	 * Cherchée à la main, comme partout ailleurs dans ce dépôt : aucun
	 * contrôleur ici ne s'en remet au ParamConverter, et une messagerie n'est
	 * pas l'endroit où introduire une seconde façon de faire.
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param int                                   $id
	 *
	 * @return \App\Entity\Conversation|null
	 */
	private function conversation ( EntityManagerInterface $manager, $id ) {
		return $manager->getRepository( Conversation::class )->find( (int) $id );
	}

	/**
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param int                                   $id
	 *
	 * @return \App\Entity\PrivateMessage|null
	 */
	private function message ( EntityManagerInterface $manager, $id ) {
		return $manager->getRepository( PrivateMessage::class )->find( (int) $id );
	}

	/**
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	private function unknown () {
		$this->addFlash( 'error', 'messages.messaging.unknown_conversation' );

		return $this->redirectToRoute( 'messages_index' );
	}

	/**
	 * Le premier message que ce lecteur n'avait pas encore vu — celui devant
	 * lequel le fil pose sa barre « nouveaux messages ».
	 *
	 * @param \App\Entity\PrivateMessage[]                $messages
	 * @param \App\Entity\ConversationParticipant|null    $participant
	 *
	 * @return int|null l'identifiant du message, ou NULL s'il n'y en a pas
	 */
	private function firstUnread ( array $messages, ConversationParticipant $participant = NULL ) {
		if ( !$participant ) {
			return NULL;
		}

		foreach ( $messages as $message ) {
			if ( $participant->hasNotRead( $message ) ) {
				return $message->getId();
			}
		}

		return NULL;
	}

	/**
	 * Un tag qui désigne quelqu'un d'étranger à la conversation ne prévient
	 * personne — le prévenir reviendrait à lui montrer un échange dont il
	 * n'est pas. On le dit à celui qui vient d'écrire, plutôt que de le
	 * laisser attendre une réponse qui ne viendra pas.
	 *
	 * @param \App\Service\Tagging\TagParser                     $tags
	 * @param \Symfony\Contracts\Translation\TranslatorInterface $translator
	 * @param \App\Entity\PrivateMessage                         $message
	 *
	 * @return void
	 */
	private function warnAboutOutsiders ( TagParser $tags, TranslatorInterface $translator, PrivateMessage $message ) {
		$conversation = $message->getConversation();

		if ( !$conversation ) {
			return;
		}

		$outsiders = [];

		foreach ( $tags->findPeople( $message->getBody(), $message->getAuthor() ) as $person ) {
			if ( !$conversation->includes( $person ) ) {
				$outsiders[] = $person->getName();
			}
		}

		if ( empty( $outsiders ) ) {
			return;
		}

		// Échappé ici : les messages éclair sont rendus avec |raw, un nom qui
		// contiendrait du balisage passerait donc tel quel.
		$this->addFlash( 'notice', $translator->trans( 'messages.messaging.outsiders', [
				'%names%' => htmlspecialchars( implode( ', ', $outsiders ), ENT_QUOTES, 'UTF-8' ),
		] ) );
	}

	/**
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param array                                $ids
	 *
	 * @return \App\Entity\User[]
	 */
	private function people ( EntityManagerInterface $manager, array $ids ) {
		$ids = array_filter( array_map( 'intval', $ids ) );

		if ( empty( $ids ) ) {
			return [];
		}

		return $manager->getRepository( User::class )
					   ->findBy( [ 'id' => array_slice( $ids, 0, 20 ), 'status' => User::STATUS_ACTIVE ] );
	}

	/**
	 * @param \App\Entity\Conversation|null $conversation
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	private function back ( Conversation $conversation = NULL ) {
		return $this->redirectToRoute(
				'messages_index',
				$conversation ? [ 'conversation' => $conversation->getId() ] : []
		);
	}

	/**
	 * @param \Symfony\Contracts\Translation\TranslatorInterface $translator
	 * @param string                                             $key
	 * @param \App\Entity\User                                   $user
	 *
	 * @return string
	 */
	private function named ( TranslatorInterface $translator, $key, User $user ) {
		return $translator->trans( $key, [
				'%name%' => htmlspecialchars( (string) $user->getName(), ENT_QUOTES, 'UTF-8' ),
		] );
	}
}
