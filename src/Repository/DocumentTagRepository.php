<?php

namespace App\Repository;

use App\Entity\DocumentTag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method DocumentTag|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method DocumentTag|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method DocumentTag[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class DocumentTagRepository extends ServiceEntityRepository {
	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, DocumentTag::class );
	}

	/**
	 * Le vocabulaire complet, dans l'ordre où on le lit. (#26)
	 *
	 * @return DocumentTag[]
	 */
	public function findAll () {
		return $this->createQueryBuilder( 't' )
					->orderBy( 't.name', 'ASC' )
					->getQuery()
					->getResult();
	}
}
