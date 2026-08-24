<?php

namespace App\Repository;

use App\Entity\MessageReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method MessageReport|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method MessageReport|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method MessageReport[]    findAll()
 * @method MessageReport[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class MessageReportRepository extends ServiceEntityRepository {
	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, MessageReport::class );
	}

	/**
	 * Les signalements, ceux qui attendent d'abord.
	 *
	 * @param bool $handled
	 *
	 * @return MessageReport[]
	 */
	public function findForAdmin ( $handled = FALSE ) {
		return $this->createQueryBuilder( 'r' )
					->andWhere( $handled ? 'r.handledAt IS NOT NULL' : 'r.handledAt IS NULL' )
					->orderBy( 'r.createdAt', 'DESC' )
					->getQuery()
					->getResult();
	}

	/**
	 * @return int
	 */
	public function countPending () {
		return (int) $this->createQueryBuilder( 'r' )
						  ->select( 'COUNT(r.id)' )
						  ->andWhere( 'r.handledAt IS NULL' )
						  ->getQuery()
						  ->getSingleScalarResult();
	}
}
