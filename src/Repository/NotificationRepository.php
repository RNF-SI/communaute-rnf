<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use App\Notification\NotificationRhythm;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Notification|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method Notification|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method Notification[]    findAll()
 * @method Notification[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class NotificationRepository extends ServiceEntityRepository {
	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, Notification::class );
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return int
	 */
	public function countUnread ( User $user ) {
		return $this->countUnreadWhere( $user );
	}

	/**
	 * Les notifications non lues qui ne sont pas de ces types-là.
	 *
	 * Sert Ã  retirer `message:new` du compteur de l'en-tÃªte : un message
	 * privÃ© y a dÃ©jÃ  sa propre ligne, et le compter deux fois ferait mentir
	 * la pastille qui additionne les deux. La page des notifications, elle,
	 * continue de les lister â c'est son histoire.
	 *
	 * @param \App\Entity\User $user
	 * @param string[]         $types
	 *
	 * @return int
	 */
	public function countUnreadExcept ( User $user, array $types ) {
		return $this->countUnreadWhere( $user, $types, FALSE );
	}

	/**
	 * Les notifications non lues qui sont de ces types-lÃ .
	 *
	 * @param \App\Entity\User $user
	 * @param string[]         $types
	 *
	 * @return int
	 */
	public function countUnreadOfTypes ( User $user, array $types ) {
		if ( empty( $types ) ) {
			return 0;
		}

		return $this->countUnreadWhere( $user, $types, TRUE );
	}

	/**
	 * @param \App\Entity\User $user
	 * @param string[]         $types
	 * @param bool             $among TRUE pour garder ces types, FALSE pour les Ã©carter
	 *
	 * @return int
	 */
	private function countUnreadWhere ( User $user, array $types = [], $among = TRUE ) {
		$builder = $this->createQueryBuilder( 'n' )
						->select( 'COUNT(n.id)' )
						->andWhere( 'n.recipient = :user' )
						->andWhere( 'n.readAt IS NULL' )
						->setParameter( 'user', $user );

		if ( !empty( $types ) ) {
			$builder->andWhere( $among ? 'n.type IN (:types)' : 'n.type NOT IN (:types)' )
					->setParameter( 'types', $types );
		}

		return (int) $builder->getQuery()->getSingleScalarResult();
	}

	/**
	 * Les notifications non lues arrivées depuis celle que le dock connaît
	 * déjà — ce qu'il annonce sans recharger la page.
	 *
	 * Bornée par un identifiant, comme le flux des messages : c'est le seul
	 * repère qui ne dépende ni de l'horloge du navigateur ni de deux
	 * enregistrements de la même seconde.
	 *
	 * @param \App\Entity\User $user
	 * @param int              $since
	 * @param int              $limit
	 *
	 * @return Notification[] de la plus ancienne à la plus récente
	 */
	public function findUnreadSince ( User $user, $since, $limit = 20 ) {
		return $this->createQueryBuilder( 'n' )
					->andWhere( 'n.recipient = :user' )
					->andWhere( 'n.readAt IS NULL' )
					->andWhere( 'n.id > :since' )
					->setParameter( 'user', $user )
					->setParameter( 'since', (int) $since )
					->orderBy( 'n.id', 'ASC' )
					->setMaxResults( $limit )
					->getQuery()
					->getResult();
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return int
	 */
	public function lastIdFor ( User $user ) {
		return (int) $this->createQueryBuilder( 'n' )
						  ->select( 'COALESCE(MAX(n.id), 0)' )
						  ->andWhere( 'n.recipient = :user' )
						  ->setParameter( 'user', $user )
						  ->getQuery()
						  ->getSingleScalarResult();
	}

	/**
	 * @param \App\Entity\User $user
	 * @param int              $limit
	 *
	 * @return Notification[]
	 */
	public function findForUser ( User $user, $limit = 50 ) {
		return $this->createQueryBuilder( 'n' )
					->andWhere( 'n.recipient = :user' )
					->setParameter( 'user', $user )
					->orderBy( 'n.createdAt', 'DESC' )
					->setMaxResults( $limit )
					->getQuery()
					->getResult();
	}

	/**
	 * @param \App\Entity\User $user
	 * @param \DateTimeInterface $readAt
	 *
	 * @return int number of notifications marked as read
	 */
	public function markAllAsRead ( User $user, DateTimeInterface $readAt ) {
		return $this->createQueryBuilder( 'n' )
					->update()
					->set( 'n.readAt', ':readAt' )
					->andWhere( 'n.recipient = :user' )
					->andWhere( 'n.readAt IS NULL' )
					->setParameter( 'readAt', $readAt )
					->setParameter( 'user', $user )
					->getQuery()
					->execute();
	}

	/**
	 * Recipients who have at least one notification waiting to be summarised.
	 *
	 * @return User[]
	 */
	public function findRecipientsAwaitingDigest () {
		return $this->getEntityManager()
					->createQueryBuilder()
					->select( 'DISTINCT u' )
					->from( User::class, 'u' )
					->innerJoin( Notification::class, 'n', Join::WITH, 'n.recipient = u' )
					->andWhere( 'n.byEmail = :byEmail' )
					->andWhere( 'n.emailedAt IS NULL' )
					->andWhere( 'u.status = :status' )
					->setParameter( 'byEmail', TRUE )
					->setParameter( 'status', User::STATUS_ACTIVE )
					->getQuery()
					->getResult();
	}

	/**
	 * Ce qui attend un résumé pour ce membre, et qui part ce jour-là.
	 *
	 * Depuis #38 le rythme est porté par la notification, pas par le membre :
	 * un lundi ramasse le quotidien et l'hebdomadaire dans le même e-mail, les
	 * autres jours ne prennent que le quotidien. Le tri se fait ici plutôt
	 * qu'en SQL — la règle du lundi vit dans NotificationRhythm, et une seule
	 * personne à la fois est concernée.
	 *
	 * @param \App\Entity\User        $user
	 * @param \DateTimeInterface|null $day the day the summary leaves
	 *
	 * @return Notification[]
	 */
	public function findAwaitingDigestFor ( User $user, DateTimeInterface $day = NULL ) {
		$waiting = $this->createQueryBuilder( 'n' )
						->andWhere( 'n.recipient = :user' )
						->andWhere( 'n.byEmail = :byEmail' )
						->andWhere( 'n.emailedAt IS NULL' )
						->setParameter( 'byEmail', TRUE )
						->setParameter( 'user', $user )
						->orderBy( 'n.createdAt', 'ASC' )
						->getQuery()
						->getResult();

		if ( !$day ) {
			return $waiting;
		}

		return array_values( array_filter( $waiting, function ( Notification $notification ) use ( $day ) {
			return NotificationRhythm::sendsOn( $notification->getRhythm(), $day );
		} ) );
	}
}
