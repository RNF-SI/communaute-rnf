<?php

namespace App\Repository;

use App\Entity\Discussion;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Discussion|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method Discussion|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method Discussion[]    findAll()
 * @method Discussion[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class DiscussionRepository extends ServiceEntityRepository {
	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, Discussion::class );
	}

	/**
	 * Discussions the user takes part in: they either opened the discussion
	 * or posted at least one message in it. Restricted to the groups they are
	 * still an active member of, so every listed discussion stays reachable.
	 *
	 * @param \App\Entity\User $user
	 *
	 * @return Discussion[]
	 */
	public function findByParticipant ( User $user ) {
		return $this->createQueryBuilder( 'd' )
					->distinct()
					->innerJoin( 'd.usergroup', 'g' )
					->innerJoin(
							UsergroupMembership::class,
							'm',
							Join::WITH,
							'm.usergroup = g AND m.user = :user AND m.status = :status'
					)
					->leftJoin( 'd.messages', 'msg' )
					->andWhere( 'd.archivedAt IS NULL' )
					->andWhere( 'g.isActive = :active' )
					->andWhere( 'd.author = :user OR msg.author = :user' )
					->setParameter( 'active', TRUE )
					->setParameter( 'user', $user )
					->setParameter( 'status', UsergroupMembership::STATUS_MEMBER )
					->orderBy( 'd.activeAt', 'DESC' )
					->addOrderBy( 'd.createdAt', 'DESC' )
					->getQuery()
					->getResult();
	}

	// /**
	//  * @return Discussion[] Returns an array of Discussion objects
	//  */
	/**
	 * Les discussions du groupe dont un message renvoie vers ce document.
	 *
	 * C'est ce qui referme la boucle entre les documents et le reste : depuis
	 * un document, on retrouve ce qui en a été dit. Le lien est cherché dans
	 * le texte des messages, parce que c'est ainsi qu'il a été écrit — aucune
	 * table de liaison à tenir à jour, et un lien collé à la main compte
	 * autant qu'un lien inséré par l'éditeur. (#32)
	 *
	 * @param \App\Entity\Usergroup $group
	 * @param int                   $documentId
	 *
	 * @return Discussion[]
	 */
	public function findMentioningDocument ( Usergroup $group, $documentId ) {
		return $this->createQueryBuilder( 'd' )
					->innerJoin( 'd.messages', 'm' )
					->andWhere( 'd.usergroup = :group' )
					->andWhere( 'd.archivedAt IS NULL' )
					->andWhere( 'm.deletedAt IS NULL' )
					->andWhere( 'm.body LIKE :link' )
					->setParameter( 'group', $group )
					->setParameter( 'link', '%/documents/' . (int) $documentId . '%' )
					->groupBy( 'd.id' )
					->orderBy( 'd.activeAt', 'DESC' )
					->getQuery()
					->getResult();
	}

	/*
	public function findByExampleField($value)
	{
		return $this->createQueryBuilder('d')
			->andWhere('d.exampleField = :val')
			->setParameter('val', $value)
			->orderBy('d.id', 'ASC')
			->setMaxResults(10)
			->getQuery()
			->getResult()
		;
	}
	*/

	/*
	public function findOneBySomeField($value): ?Discussion
	{
		return $this->createQueryBuilder('d')
			->andWhere('d.exampleField = :val')
			->setParameter('val', $value)
			->getQuery()
			->getOneOrNullResult()
		;
	}
	*/
}
