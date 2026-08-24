<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Conversation|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method Conversation|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method Conversation[]    findAll()
 * @method Conversation[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class ConversationRepository extends ServiceEntityRepository {
	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, Conversation::class );
	}

	/**
	 * La boîte de quelqu'un, la plus récente d'abord.
	 *
	 * @param \App\Entity\User $user
	 * @param string|null      $query    filtre sur le texte des messages et
	 *                                   sur le nom des participants
	 * @param bool             $archived les rangées plutôt que les autres
	 *
	 * @return Conversation[]
	 */
	public function findForUser ( User $user, $query = NULL, $archived = FALSE ) {
		// `mine` dit de quelle boîte il s'agit ; `p` ramène *tous* les
		// participants, pour que la liste puisse nommer chaque conversation
		// sans une requête par ligne. Les deux jointures portent sur la même
		// association et ne se confondent pas : seule la seconde est
		// sélectionnée, la première est un filtre — la sélectionner ne
		// ramènerait qu'un participant par conversation, et la liste
		// n'afficherait plus qu'un nom sur trois.
		$builder = $this->createQueryBuilder( 'c' )
						->innerJoin( 'c.participants', 'mine' )
						->leftJoin( 'c.participants', 'p' )
						->leftJoin( 'p.user', 'pu' )
						->addSelect( 'p' )
						->addSelect( 'pu' )
						->andWhere( 'mine.user = :user' )
						->andWhere( 'mine.leftAt IS NULL' )
						->andWhere( $archived ? 'mine.archivedAt IS NOT NULL' : 'mine.archivedAt IS NULL' )
						->setParameter( 'user', $user )
						->orderBy( 'c.lastMessageAt', 'DESC' )
						->addOrderBy( 'c.id', 'DESC' );

		$query = trim( (string) $query );

		if ( $query !== '' ) {
			// Chercher le mot dans les messages *et* dans le nom de ceux à qui
			// on parle : quand on cherche « Jeanne », on cherche presque
			// toujours la conversation avec Jeanne, pas le mot « Jeanne ».
			//
			// Une troisième jointure sur les participants, et non `pu` : filtrer
			// sur l'alias qu'on hydrate ne ramènerait que les participants qui
			// répondent au filtre, et une conversation trouvée par le nom de
			// l'un d'eux n'afficherait plus que celui-là.
			$builder->leftJoin( 'c.messages', 'm' )
					->leftJoin( 'c.participants', 'sp' )
					->leftJoin( 'sp.user', 'su' )
					->andWhere( '( m.body LIKE :needle AND m.deletedAt IS NULL ) OR su.name LIKE :needle OR su.displayName LIKE :needle' )
					->setParameter( 'needle', '%' . $query . '%' )
					->distinct();
		}

		return $builder->getQuery()->getResult();
	}

	/**
	 * Le tête-à-tête entre ces deux-là, s'il existe déjà.
	 *
	 * @param \App\Entity\User $one
	 * @param \App\Entity\User $other
	 *
	 * @return \App\Entity\Conversation|null
	 */
	public function findPair ( User $one, User $other ) {
		$key = Conversation::pairKeyFor( $one, $other );

		return $key ? $this->findOneBy( [ 'pairKey' => $key ] ) : NULL;
	}

	/**
	 * Combien de conversations attendent d'être lues — le nombre porté par
	 * l'en-tête.
	 *
	 * Ce sont bien des conversations et non des messages : « 3 » veut dire
	 * trois personnes à qui répondre, ce qui est l'information utile.
	 *
	 * @param \App\Entity\User $user
	 *
	 * @return int
	 */
	public function countUnread ( User $user ) {
		return (int) $this->createQueryBuilder( 'c' )
						  ->select( 'COUNT(DISTINCT c.id)' )
						  ->innerJoin( 'c.participants', 'mine' )
						  ->innerJoin( 'c.messages', 'm' )
						  ->andWhere( 'mine.user = :user' )
						  ->andWhere( 'mine.leftAt IS NULL' )
						  ->andWhere( 'm.deletedAt IS NULL' )
						  ->andWhere( 'm.author IS NULL OR m.author != :user' )
						  ->andWhere( 'mine.lastReadAt IS NULL OR m.createdAt > mine.lastReadAt' )
						  ->setParameter( 'user', $user )
						  ->getQuery()
						  ->getSingleScalarResult();
	}
}
