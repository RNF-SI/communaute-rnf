<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
		return (int) $this->createQueryBuilder( 'n' )
						  ->select( 'COUNT(n.id)' )
						  ->andWhere( 'n.recipient = :user' )
						  ->andWhere( 'n.readAt IS NULL' )
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
		return $this->createQueryBuilder( 'n' )
					->select( 'DISTINCT u' )
					->innerJoin( 'n.recipient', 'u' )
					->andWhere( 'n.byEmail = TRUE' )
					->andWhere( 'n.emailedAt IS NULL' )
					->andWhere( 'u.status = :status' )
					->setParameter( 'status', User::STATUS_ACTIVE )
					->getQuery()
					->getResult();
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return Notification[]
	 */
	public function findAwaitingDigestFor ( User $user ) {
		return $this->createQueryBuilder( 'n' )
					->andWhere( 'n.recipient = :user' )
					->andWhere( 'n.byEmail = TRUE' )
					->andWhere( 'n.emailedAt IS NULL' )
					->setParameter( 'user', $user )
					->orderBy( 'n.createdAt', 'ASC' )
					->getQuery()
					->getResult();
	}
}
