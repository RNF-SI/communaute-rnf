<?php

namespace App\Repository;

use App\Entity\Page;
use App\Entity\Usergroup;
use App\Traits\SearchableRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Page|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method Page|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method Page[]    findAll()
 * @method Page[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class PageRepository extends ServiceEntityRepository {
	use SearchableRepositoryTrait;

	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, Page::class );
	}

	// /**
	//  * @return Page[] Returns an array of Page objects
	//  */
	/**
	 * Les pages du groupe dont le corps renvoie vers ce document. (#32)
	 *
	 * @param \App\Entity\Usergroup $group
	 * @param int                   $documentId
	 *
	 * @return Page[]
	 */
	public function findMentioningDocument ( Usergroup $group, $documentId ) {
		return $this->createQueryBuilder( 'p' )
					->andWhere( 'p.usergroup = :group' )
					->andWhere( 'p.body LIKE :link' )
					->setParameter( 'group', $group )
					->setParameter( 'link', '%/documents/' . (int) $documentId . '%' )
					->orderBy( 'p.title', 'ASC' )
					->getQuery()
					->getResult();
	}

	/*
	public function findByExampleField($value)
	{
		return $this->createQueryBuilder('p')
			->andWhere('p.exampleField = :val')
			->setParameter('val', $value)
			->orderBy('p.id', 'ASC')
			->setMaxResults(10)
			->getQuery()
			->getResult()
		;
	}
	*/

	/*
	public function findOneBySomeField($value): ?Page
	{
		return $this->createQueryBuilder('p')
			->andWhere('p.exampleField = :val')
			->setParameter('val', $value)
			->getQuery()
			->getOneOrNullResult()
		;
	}
	*/
}
