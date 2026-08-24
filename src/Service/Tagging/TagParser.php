<?php

namespace App\Service\Tagging;

use App\Entity\Article;
use App\Entity\Discussion;
use App\Entity\Document;
use App\Entity\Page;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Security\GroupVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les tags d'un message privé : « @Prénom Nom » pour quelqu'un, « #Titre »
 * pour un groupe, un document, une page, une actualité ou une discussion.
 *
 * Rien n'est stocké d'autre que ce qui a été tapé. Le message garde le texte
 * « #Guide de gestion 2024 » et le lien est refait à chaque affichage, comme
 * les mentions des discussions le font depuis #37. On y gagne trois choses :
 * le message reste lisible tel quel — dans un copier-coller, dans un
 * export —, aucune table de liaison ne peut se désynchroniser, et un tag
 * écrit à la main vaut autant qu'un tag inséré par la liste de suggestions.
 * On y perd une chose, et il faut la dire : un document renommé perd son
 * lien, et le texte redevient du texte.
 *
 * **Le rendu dépend de qui lit.** Le même message affiche un lien pour un
 * membre du groupe où vit le document, et un tag grisé pour quelqu'un qui n'y
 * est pas. Le titre reste visible — il est dans la phrase de toute façon,
 * l'effacer rendrait le message incompréhensible — mais il ne mène nulle
 * part. C'est pour cela que rien n'est mis en cache ici : deux lecteurs n'ont
 * pas le même message sous les yeux.
 *
 * Limite connue : un titre qui contient une ponctuation interne (« Guide :
 * gestion ») n'est reconnu que jusqu'à cette ponctuation, et le tag retombe
 * alors en texte simple. Élargir ce que peut contenir un tag reviendrait à
 * avaler la phrase qui le suit.
 */
class TagParser {
	/**
	 * Combien de contenus une liste de suggestions propose. Au-delà, on ne
	 * choisit plus, on relit.
	 */
	private const SUGGESTIONS = 8;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $router;

	/**
	 * @var \Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface
	 */
	private $authorization;

	/**
	 * @var \App\Service\Tagging\TagScanner
	 */
	private $people;

	/**
	 * @var \App\Service\Tagging\TagScanner
	 */
	private $things;

	public function __construct (
			EntityManagerInterface $manager,
			UrlGeneratorInterface $router,
			AuthorizationCheckerInterface $authorization
	) {
		$this->manager       = $manager;
		$this->router        = $router;
		$this->authorization = $authorization;

		$this->people = new TagScanner( '@', 4 );

		// Huit mots, parce qu'un titre de document est plus long qu'un nom.
		// Et « # » n'ouvre pas de tag après « / » ni après « & » : sans quoi
		// l'ancre d'une adresse collée dans un message, et la moindre entité
		// numérique du genre &#039;, se liraient comme des tags.
		$this->things = new TagScanner( '#', 8, '\p{L}\p{N}._\-\/&' );
	}

	/**
	 * Les membres nommés dans un message, chacun une fois.
	 *
	 * À la différence des mentions d'une discussion, on cherche dans tout le
	 * réseau et non dans un groupe : une conversation privée n'a pas de
	 * périmètre, et c'est justement pour joindre quelqu'un qu'on ne croise
	 * pas qu'on écrit.
	 *
	 * @param string                $body
	 * @param \App\Entity\User|null $author jamais une mention de lui-même
	 *
	 * @return \App\Entity\User[]
	 */
	public function findPeople ( $body, User $author = NULL ) {
		$found = [];

		$this->people->scan(
				(string) $body,
				$this->peopleIn( $body ),
				static function ( User $user, $matched ) use ( &$found, $author ) {
					if ( $author && ( $author->getId() !== NULL ) && ( $author->getId() === $user->getId() ) ) {
						return $matched;
					}

					$found[ $user->getId() ] = $user;

					return $matched;
				}
		);

		return array_values( $found );
	}

	/**
	 * Le message tel qu'il s'affiche pour celui qui le lit en ce moment :
	 * échappé, ses retours à la ligne conservés, ses tags devenus des liens —
	 * ou des tags grisés, pour ce à quoi ce lecteur n'a pas droit.
	 *
	 * @param string $body
	 *
	 * @return string du HTML
	 */
	public function render ( $body ) {
		$body = (string) $body;

		$escape = static function ( $chunk ) {
			return nl2br( htmlspecialchars( $chunk, ENT_QUOTES, 'UTF-8' ) );
		};

		// Les personnes d'abord, les contenus ensuite, sur ce que la première
		// passe a laissé de texte : les deux préfixes ne se rencontrent
		// jamais, et le balisage produit par l'une ne doit pas être relu par
		// l'autre. D'où l'échappement fait ici, tag par tag, plutôt qu'une
		// fois sur tout le message — échapper d'abord transformerait les
		// apostrophes en &#039; et aucun nom ne se reconnaîtrait plus.
		$people = $this->peopleIn( $body );
		$things = $this->thingsIn( $body );

		return $this->people->scan(
				$body,
				$people,
				function ( User $user, $matched ) {
					return $this->personLink( $user, $matched );
				},
				function ( $chunk ) use ( $things, $escape ) {
					return $this->things->scan(
							$chunk,
							$things,
							function ( TaggedThing $thing, $matched ) use ( $escape ) {
								return $this->thingLink( $thing, $escape( $matched ) );
							},
							$escape
					);
				}
		);
	}

	/**
	 * Ce que propose la liste de suggestions quand on tape « @ » ou « # ».
	 *
	 * @param string           $prefix « @ » ou « # »
	 * @param string           $query
	 * @param \App\Entity\User $viewer celui qui écrit — on ne lui propose que
	 *                                 ce qu'il peut lui-même ouvrir
	 *
	 * @return array[] [ { label, kind, hint } ]
	 */
	public function suggest ( $prefix, $query, User $viewer = NULL ) {
		$query = trim( (string) $query );

		if ( mb_strlen( $query ) < 1 ) {
			return [];
		}

		return ( $prefix === '#' )
				? $this->suggestThings( $query )
				: $this->suggestPeople( $query, $viewer );
	}

	/**
	 * @param string $query
	 * @param \App\Entity\User|null $viewer
	 *
	 * @return array[]
	 */
	private function suggestPeople ( $query, User $viewer = NULL ) {
		$found = [];

		foreach ( $this->searchPeople( $query ) as $user ) {
			if ( $viewer && ( $user->getId() === $viewer->getId() ) ) {
				continue;
			}

			$found[] = [
					'label' => $user->getName(),
					'kind'  => 'member',
					'hint'  => (string) $user->getOrganisation(),
			];
		}

		return array_slice( $found, 0, self::SUGGESTIONS );
	}

	/**
	 * Les contenus dont le titre commence par ce qui a été tapé — et
	 * seulement ceux que celui qui écrit peut lui-même ouvrir.
	 *
	 * Proposer un document qu'on n'a pas le droit de lire ferait de la liste
	 * de suggestions un annuaire des groupes privés.
	 *
	 * @param string $query
	 *
	 * @return array[]
	 */
	private function suggestThings ( $query ) {
		$found = [];

		foreach ( TaggedThing::kinds() as $kind ) {
			foreach ( $this->searchThings( $kind, $query ) as $thing ) {
				if ( !$this->mayRead( $thing ) ) {
					continue;
				}

				$group = $thing->getGroup();

				$found[] = [
						'label' => $thing->getTitle(),
						'kind'  => $thing->getKind(),
						'hint'  => ( $group && ( $thing->getKind() !== TaggedThing::GROUP ) )
								? (string) $group->getName()
								: '',
				];

				if ( count( $found ) >= self::SUGGESTIONS ) {
					return $found;
				}
			}
		}

		return $found;
	}

	/**
	 * Ce lecteur-ci a-t-il le droit d'ouvrir ce contenu ?
	 *
	 * La question se pose sur le groupe qui l'héberge : c'est GroupVoter qui
	 * tranche, exactement comme il tranchera si le lien est suivi. Un tag
	 * cliquable qui mènerait à un 403 serait un mensonge poli.
	 *
	 * @param \App\Service\Tagging\TaggedThing $thing
	 *
	 * @return bool
	 */
	private function mayRead ( TaggedThing $thing ) {
		$group = $thing->getGroup();

		// Sans groupe, on ne sait pas : on ne montre pas de lien.
		return $group && $this->authorization->isGranted( GroupVoter::READ, $group );
	}

	/**
	 * @param \App\Entity\User $user
	 * @param string           $matched
	 *
	 * @return string
	 */
	private function personLink ( User $user, $matched ) {
		return sprintf(
				'<a class="msg-tag msg-tag__member" data-kind="member" href="%s">%s</a>',
				htmlspecialchars(
						$this->router->generate( 'member', [ 'user_id' => $user->getId() ] ),
						ENT_QUOTES
				),
				htmlspecialchars( $matched, ENT_QUOTES, 'UTF-8' )
		);
	}

	/**
	 * @param \App\Service\Tagging\TaggedThing $thing
	 * @param string                           $label déjà échappé
	 *
	 * @return string
	 */
	private function thingLink ( TaggedThing $thing, $label ) {
		if ( !$this->mayRead( $thing ) ) {
			// Le titre reste, le lien part. Il est dans la phrase de toute
			// façon : l'effacer rendrait le message incompréhensible sans
			// rien protéger de plus.
			return sprintf(
					'<span class="msg-tag msg-tag__locked" data-kind="%s">%s</span>',
					htmlspecialchars( $thing->getKind(), ENT_QUOTES ),
					$label
			);
		}

		return sprintf(
				'<a class="msg-tag msg-tag__content" data-kind="%s" href="%s">%s</a>',
				htmlspecialchars( $thing->getKind(), ENT_QUOTES ),
				htmlspecialchars( $thing->getUrl(), ENT_QUOTES ),
				$label
		);
	}

	/**
	 * Les membres dont le nom apparaît dans ce texte, indexés par nom replié.
	 * Une requête, quelle que soit la longueur du message.
	 *
	 * @param string $body
	 *
	 * @return \App\Entity\User[]
	 */
	private function peopleIn ( $body ) {
		$labels = $this->people->labelsIn( (string) $body );

		if ( empty( $labels ) ) {
			return [];
		}

		$users = $this->manager->createQueryBuilder()
							   ->select( 'u' )
							   ->from( User::class, 'u' )
							   ->andWhere( 'u.status = :status' )
							   ->andWhere( 'u.name IN (:names) OR u.displayName IN (:names)' )
							   ->setParameter( 'status', User::STATUS_ACTIVE )
							   ->setParameter( 'names', array_values( $labels ) )
							   ->orderBy( 'u.id', 'ASC' )
							   ->getQuery()
							   ->getResult();

		$found = [];

		foreach ( $users as $user ) {
			foreach ( [ $user->getName(), $user->getDisplayName() ] as $name ) {
				$key = $this->people->fold( (string) $name );

				// Le premier qui répond à un nom l'emporte sur le suivant :
				// une ambiguïté se tranche de la même façon à chaque
				// affichage.
				if ( ( $key === '' ) || !isset( $labels[ $key ] ) || isset( $found[ $key ] ) ) {
					continue;
				}

				$found[ $key ] = $user;
			}
		}

		return $found;
	}

	/**
	 * Les contenus dont le titre apparaît dans ce texte, indexés par titre
	 * replié. Une requête par type, pas une par tag.
	 *
	 * @param string $body
	 *
	 * @return \App\Service\Tagging\TaggedThing[]
	 */
	private function thingsIn ( $body ) {
		$labels = $this->things->labelsIn( (string) $body );

		if ( empty( $labels ) ) {
			return [];
		}

		$titles = array_values( $labels );
		$found  = [];

		foreach ( TaggedThing::kinds() as $kind ) {
			foreach ( $this->findThingsByTitle( $kind, $titles ) as $thing ) {
				$key = $this->things->fold( $thing->getTitle() );

				if ( ( $key === '' ) || !isset( $labels[ $key ] ) || isset( $found[ $key ] ) ) {
					continue;
				}

				$found[ $key ] = $thing;
			}
		}

		return $found;
	}

	/**
	 * @param string   $kind
	 * @param string[] $titles
	 *
	 * @return \App\Service\Tagging\TaggedThing[]
	 */
	private function findThingsByTitle ( $kind, array $titles ) {
		return $this->wrap( $kind, $this->queryFor( $kind )
										->andWhere( 'e.' . $this->titleField( $kind ) . ' IN (:titles)' )
										->setParameter( 'titles', $titles )
										->getQuery()
										->getResult() );
	}

	/**
	 * @param string $kind
	 * @param string $query
	 *
	 * @return \App\Service\Tagging\TaggedThing[]
	 */
	private function searchThings ( $kind, $query ) {
		$field = $this->titleField( $kind );

		return $this->wrap( $kind, $this->queryFor( $kind )
										->andWhere( 'e.' . $field . ' LIKE :needle' )
										->setParameter( 'needle', $query . '%' )
										->orderBy( 'e.' . $field, 'ASC' )
										// Plus que la liste n'en montre : le
										// filtre des droits, qui vient après,
										// en retire.
										->setMaxResults( self::SUGGESTIONS * 4 )
										->getQuery()
										->getResult() );
	}

	/**
	 * @param string $query
	 *
	 * @return \App\Entity\User[]
	 */
	private function searchPeople ( $query ) {
		return $this->manager->createQueryBuilder()
							 ->select( 'u' )
							 ->from( User::class, 'u' )
							 ->andWhere( 'u.status = :status' )
							 ->andWhere( 'u.name LIKE :needle OR u.displayName LIKE :needle' )
							 ->setParameter( 'status', User::STATUS_ACTIVE )
							 ->setParameter( 'needle', $query . '%' )
							 ->orderBy( 'u.name', 'ASC' )
							 ->setMaxResults( self::SUGGESTIONS )
							 ->getQuery()
							 ->getResult();
	}

	/**
	 * @param string $kind
	 *
	 * @return \Doctrine\ORM\QueryBuilder
	 */
	private function queryFor ( $kind ) {
		$builder = $this->manager->createQueryBuilder()
								 ->select( 'e' )
								 ->from( $this->entityFor( $kind ), 'e' );

		if ( $kind === TaggedThing::GROUP ) {
			// Un groupe désactivé n'est plus une destination.
			return $builder->andWhere( 'e.isActive = :active' )->setParameter( 'active', TRUE );
		}

		return $builder->andWhere( 'e.usergroup IS NOT NULL' );
	}

	/**
	 * @param string $kind
	 *
	 * @return string
	 */
	private function entityFor ( $kind ) {
		$entities = [
				TaggedThing::GROUP      => Usergroup::class,
				TaggedThing::DOCUMENT   => Document::class,
				TaggedThing::PAGE       => Page::class,
				TaggedThing::ARTICLE    => Article::class,
				TaggedThing::DISCUSSION => Discussion::class,
		];

		return $entities[ $kind ];
	}

	/**
	 * @param string $kind
	 *
	 * @return string
	 */
	private function titleField ( $kind ) {
		return ( $kind === TaggedThing::GROUP ) ? 'name' : 'title';
	}

	/**
	 * @param string  $kind
	 * @param array   $entities
	 *
	 * @return \App\Service\Tagging\TaggedThing[]
	 */
	private function wrap ( $kind, array $entities ) {
		$things = [];

		foreach ( $entities as $entity ) {
			$thing = $this->describe( $kind, $entity );

			if ( $thing ) {
				$things[] = $thing;
			}
		}

		return $things;
	}

	/**
	 * @param string $kind
	 * @param object $entity
	 *
	 * @return \App\Service\Tagging\TaggedThing|null
	 */
	private function describe ( $kind, $entity ) {
		if ( $kind === TaggedThing::GROUP ) {
			return new TaggedThing(
					$kind,
					$entity->getName(),
					$this->router->generate( 'group_index', [ 'groupSlug' => $entity->getSlug() ] ),
					$entity
			);
		}

		$group = $entity->getUsergroup();

		if ( !$group ) {
			return NULL;
		}

		$routes = [
				TaggedThing::DOCUMENT   => [ 'group_document_index', 'documentId', 'getId' ],
				TaggedThing::PAGE       => [ 'group_page_index', 'pageSlug', 'getSlug' ],
				TaggedThing::ARTICLE    => [ 'group_article_index', 'articleSlug', 'getSlug' ],
				TaggedThing::DISCUSSION => [ 'group_discussion_index', 'discussionUuid', 'getUuid' ],
		];

		list( $route, $parameter, $accessor ) = $routes[ $kind ];

		return new TaggedThing(
				$kind,
				$entity->getTitle(),
				$this->router->generate( $route, [
						'groupSlug' => $group->getSlug(),
						$parameter  => $entity->{$accessor}(),
				] ),
				$group
		);
	}
}
