<?php

namespace App\Messaging;

use App\Entity\Conversation;
use App\Entity\Notification;
use App\Entity\PrivateMessage;
use App\Entity\User;
use App\Service\Tagging\TagParser;
use App\Twig\ColorExtension;
use Knp\Bundle\TimeBundle\DateTimeFormatter;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ce que le dock reçoit du serveur : des conversations, des messages, des
 * notifications, réduits à ce qui s'affiche.
 *
 * Un seul endroit les met en forme parce que trois routes les renvoient — la
 * liste, un fil, et le sondage —, et qu'une conversation qui ne se décrirait
 * pas pareil selon la porte d'entrée ferait sauter la ligne dans la liste dès
 * qu'un message arrive.
 *
 * **Le corps d'un message part en HTML déjà rendu, jamais en texte brut.**
 * Deux raisons, et aucune n'est un détail :
 *
 * - `TagParser::render` échappe. Le navigateur pose le résultat en
 *   `innerHTML` ; lui envoyer le texte tel qu'il a été tapé le rendrait
 *   responsable d'un échappement qu'il ferait mal un jour.
 * - **Le rendu dépend de qui lit.** Un tag vers un document donne un lien à
 *   un membre du groupe et un libellé grisé aux autres. Ce tri se fait
 *   lecteur par lecteur, côté serveur. Le dock ne doit donc **rien mettre en
 *   cache** de ce qu'il reçoit là, et le corps rendu ne doit jamais servir à
 *   fabriquer autre chose qu'un affichage pour cette personne-là.
 */
class DockPresenter {
	/**
	 * Combien de caractères de l'extrait qui nomme une ligne de la liste.
	 */
	private const EXCERPT = 90;

	/**
	 * @var \App\Service\Tagging\TagParser
	 */
	private $tags;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $urls;

	/**
	 * @var \Knp\Bundle\TimeBundle\DateTimeFormatter
	 */
	private $dates;

	/**
	 * @var \Liip\ImagineBundle\Imagine\Cache\CacheManager
	 */
	private $images;

	/**
	 * @var \App\Twig\ColorExtension
	 */
	private $colors;

	/**
	 * @var \Symfony\Contracts\Translation\TranslatorInterface
	 */
	private $translator;

	public function __construct (
			TagParser $tags,
			UrlGeneratorInterface $urls,
			DateTimeFormatter $dates,
			CacheManager $images,
			ColorExtension $colors,
			TranslatorInterface $translator
	) {
		$this->tags       = $tags;
		$this->urls       = $urls;
		$this->dates      = $dates;
		$this->images     = $images;
		$this->colors     = $colors;
		$this->translator = $translator;
	}

	/**
	 * Une ligne de la liste des conversations.
	 *
	 * @param \App\Entity\Conversation         $conversation
	 * @param \App\Entity\User                 $reader
	 * @param \App\Entity\PrivateMessage|null  $last le dernier message, déjà
	 *                                               ramassé pour toute la liste
	 *
	 * @return array
	 */
	public function conversation ( Conversation $conversation, User $reader, PrivateMessage $last = NULL ) {
		$mine   = $conversation->getParticipantFor( $reader );
		$others = $conversation->getOthers( $reader );

		return [
				'id'        => $conversation->getId(),
				'title'     => $this->title( $others ),
				'people'    => array_map( [ $this, 'person' ], array_slice( $others, 0, 3 ) ),
				'excerpt'   => $this->excerpt( $last ),
				'at'        => $this->at( $conversation->getLastMessageAt() ),
				'atLabel'   => $this->atLabel( $conversation->getLastMessageAt() ),
				'lastId'    => $last ? $last->getId() : 0,
				'unread'    => (bool) ( $last && $mine && $mine->hasNotRead( $last ) ),
				'archived'  => (bool) ( $mine && $mine->isArchived() ),
				'url'       => $this->urls->generate( 'messages_index', [ 'conversation' => $conversation->getId() ] ),
		];
	}

	/**
	 * Un message du fil.
	 *
	 * @param \App\Entity\PrivateMessage $message
	 * @param \App\Entity\User           $reader
	 *
	 * @return array
	 */
	public function message ( PrivateMessage $message, User $reader ) {
		$author       = $message->getAuthor();
		$conversation = $message->getConversation();

		return [
				'id'      => $message->getId(),
				'thread'  => $conversation ? $conversation->getId() : 0,
				'author'  => $author ? $this->person( $author ) : NULL,
				'mine'    => (bool) ( $author && ( $author->getId() === $reader->getId() ) ),
				// Rendu ici, pour ce lecteur-ci. Voir l'en-tête de la classe.
				'html'    => $message->isDeleted() ? NULL : $this->tags->render( (string) $message->getBody() ),
				'deleted' => $message->isDeleted(),
				'edited'  => $message->isEdited(),
				'at'      => $this->at( $message->getCreatedAt() ),
				'atLabel' => $this->atLabel( $message->getCreatedAt() ),
		];
	}

	/**
	 * Une notification, telle que le dock l'annonce en passant.
	 *
	 * Celles qui annoncent un message privé n'arrivent pas jusqu'ici : le
	 * dock montre le message lui-même, et l'annoncer une seconde fois par-
	 * dessus ferait deux bulles pour une seule phrase reçue.
	 *
	 * @param \App\Entity\Notification $notification
	 *
	 * @return array
	 */
	public function notification ( Notification $notification ) {
		$group = $notification->getUsergroup();

		return [
				'id'      => $notification->getId(),
				'type'    => $notification->getType(),
				'title'   => $notification->getTitle(),
				'url'     => $notification->getUrl(),
				'group'   => $group ? $group->getName() : NULL,
				'at'      => $this->at( $notification->getCreatedAt() ),
				'atLabel' => $this->atLabel( $notification->getCreatedAt() ),
		];
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return array
	 */
	public function person ( User $user ) {
		$avatar = $user->getAvatar();

		return [
				'id'     => $user->getId(),
				'name'   => $user->getName(),
				'url'    => $this->urls->generate( 'member', [ 'user_id' => $user->getId() ] ),
				'avatar' => $avatar && $avatar->getPath()
						? $this->images->getBrowserPath( $avatar->getPath(), 'avatar' )
						: NULL,
				// La même teinte que le gabarit Twig, prise au même filtre :
				// un visage sans photo ne doit pas changer de couleur selon
				// qu'il est peint par le serveur ou par le dock.
				'color'  => $this->colors->generateFromString( (string) $user->getName() ),
		];
	}

	/**
	 * @param \App\Entity\User[] $others
	 *
	 * @return string
	 */
	private function title ( array $others ) {
		if ( empty( $others ) ) {
			return $this->translator->trans( 'pages.messages.nobody' );
		}

		$names = [];

		foreach ( array_slice( $others, 0, 3 ) as $other ) {
			$names[] = $other->getName();
		}

		$title = implode( ', ', $names );

		if ( count( $others ) > 3 ) {
			$title .= ' +' . ( count( $others ) - 3 );
		}

		return $title;
	}

	/**
	 * @param \App\Entity\PrivateMessage|null $last
	 *
	 * @return string
	 */
	private function excerpt ( PrivateMessage $last = NULL ) {
		if ( !$last ) {
			return '';
		}

		if ( $last->isDeleted() ) {
			return $this->translator->trans( 'pages.messages.deleted' );
		}

		return $last->getExcerpt( self::EXCERPT );
	}

	/**
	 * @param \DateTimeInterface|null $date
	 *
	 * @return string|null
	 */
	private function at ( $date = NULL ) {
		return $date ? $date->format( DATE_ATOM ) : NULL;
	}

	/**
	 * L'heure telle qu'elle se lit — « il y a 3 minutes ».
	 *
	 * Formatée par le serveur, avec le même service que le filtre `ago` des
	 * gabarits : une seconde façon de dire l'heure, écrite en JavaScript,
	 * aurait sa propre idée des traductions et du fuseau.
	 *
	 * @param \DateTimeInterface|null $date
	 *
	 * @return string
	 */
	private function atLabel ( $date = NULL ) {
		return $date ? $this->dates->formatDiff( $date, new \DateTime() ) : '';
	}
}
