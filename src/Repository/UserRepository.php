<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Traits\SearchableRepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method User|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method User|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method User[]    findAll()
 * @method User[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class UserRepository extends ServiceEntityRepository {
	use SearchableRepositoryTrait;

	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, User::class );
	}

	/**
	 * Active accounts holding ROLE_ADMIN. Used as a last resort recipient when
	 * a group has nobody able to approve a request.
	 *
	 * @return User[]
	 */
	public function findSiteAdmins () {
		return $this->createQueryBuilder( 'u' )
					->andWhere( 'u.roles LIKE :role' )
					->andWhere( 'u.status = :status' )
					->setParameter( 'role', '%ROLE_ADMIN%' )
					->setParameter( 'status', User::STATUS_ACTIVE )
					->orderBy( 'u.id', 'ASC' )
					->getQuery()
					->getResult();
	}

	/**
	 * The members of a group answering to one of the given names. Used to turn
	 * a « @Prénom Nom » into somebody, without loading the whole group. (#37)
	 *
	 * Comparison is left to the database, whose collation ignores case and
	 * accents — the same forgiveness the reader expects when typing a name.
	 *
	 * @param \App\Entity\Usergroup $group
	 * @param string[]              $names
	 *
	 * @return User[]
	 */
	public function findMentionable ( Usergroup $group, array $names ) {
		if ( empty( $names ) ) {
			return [];
		}

		return $this->createQueryBuilder( 'u' )
					->innerJoin( 'u.usergroupMemberships', 'm' )
					->andWhere( 'm.usergroup = :group' )
					->andWhere( 'm.status = :membership' )
					->andWhere( 'u.status = :status' )
					->andWhere( 'u.name IN (:names) OR u.displayName IN (:names)' )
					->setParameter( 'group', $group )
					->setParameter( 'membership', UsergroupMembership::STATUS_MEMBER )
					->setParameter( 'status', User::STATUS_ACTIVE )
					->setParameter( 'names', $names )
					->orderBy( 'u.id', 'ASC' )
					->getQuery()
					->getResult();
	}

	/**
	 * Les membres actifs dont le nom commence par ce qui a été tapé.
	 *
	 * Sert aux écrans de la messagerie, où l'on choisit à qui écrire : la
	 * recherche se fait côté serveur, et la page se passe donc de JavaScript.
	 *
	 * @param string $query
	 * @param int    $limit
	 *
	 * @return User[]
	 */
	public function searchActiveByName ( $query, $limit = 20 ) {
		$query = trim( (string) $query );

		if ( $query === '' ) {
			return [];
		}

		return $this->createQueryBuilder( 'u' )
					->andWhere( 'u.status = :status' )
					->andWhere( 'u.name LIKE :needle OR u.displayName LIKE :needle' )
					->setParameter( 'status', User::STATUS_ACTIVE )
					->setParameter( 'needle', '%' . $query . '%' )
					->orderBy( 'u.name', 'ASC' )
					->setMaxResults( $limit )
					->getQuery()
					->getResult();
	}

	public function getCountries ( $filters = [] ) {
		$qb = $this->createQueryBuilder( 'u' );

		$i = 1;

		/**
		 * GROUP
		 */
		if ( !empty( $filters[ 'group' ] ) ) {
			$qb->innerJoin( 'u.usergroupMemberships', 'g' );

			/**
			 * @var \App\Entity\Usergroup $group
			 */
			$group = $filters[ 'group' ];
			$var   = 'group' . ( $i++ );
			$qb->andWhere( $qb->expr()->eq( 'g.usergroup', ':' . $var ) );
			$qb->setParameter( $var, $group );
		}

		return $qb->select( 'u.country' )
				  ->andWhere( 'u.country IS NOT NULL' )
				  ->distinct()
				  ->orderBy( 'u.country', 'ASC' )
				  ->getQuery()
				  ->getResult();
	}

	/**************************************************
	 * SEARCH
	 *************************************************
	 *
	 * @param \Doctrine\ORM\QueryBuilder $qb
	 * @param array                      $filters
	 */

	protected function searchAddOption ( QueryBuilder &$qb, $filters = [] ) {
		$i = 1;

		$qb->andWhere( $qb->expr()->eq( 'u.status', User::STATUS_ACTIVE ) );

		/**
		 * GROUP
		 */
		if ( !empty( $filters[ 'group' ] ) ) {
			$qb->innerJoin( 'u.usergroupMemberships', 'g' );

			/**
			 * @var \App\Entity\Usergroup $group
			 */
			$group = $filters[ 'group' ];
			$var   = 'group' . ( $i++ );
			$qb->andWhere( $qb->expr()->eq( 'g.usergroup', ':' . $var ) );
			$qb->setParameter( $var, $group );

			if ( empty( $filters[ 'status' ] ) ) {
				$var = 'group' . ( $i++ );
				$qb->andWhere( $qb->expr()->like( 'g.status', ':' . $var ) );
				$qb->setParameter( $var, UsergroupMembership::STATUS_MEMBER );
			}
			else if ( $filters[ 'status' ] !== UsergroupMembership::STATUS_ALL ) {
				$var = 'group' . ( $i++ );
				$qb->andWhere( $qb->expr()->like( 'g.status', ':' . $var ) );
				$qb->setParameter( $var, $filters[ 'status' ] );
			}
		}

		/**
		 * QUERY
		 */
		if ( !empty( $filters[ 'query' ] ) ) {
			$words = array_filter( explode( ' ', $filters[ 'query' ] ), function ( $word ) {
				return !empty( $word );
			} );

			foreach ( $words as $word ) {
				$var = 'word' . ( $i++ );
				$qb->andWhere( $qb->expr()->orX(
						$qb->expr()->like( 'u.name', ':' . $var ),
						$qb->expr()->like( 'u.displayName', ':' . $var ),
						$qb->expr()->like( 'u.presentation', ':' . $var ),
						$qb->expr()->like( 'u.jobTitle', ':' . $var ),
						$qb->expr()->like( 'u.organisation', ':' . $var ),
						$qb->expr()->like( 'u.reserves', ':' . $var )
				) )
				   ->setParameter( $var, '%' . $word . '%' );
			}
		}

		/**
		 * COUNTRY
		 */
		if ( !empty( $filters[ 'country' ] ) ) {
			if ( !is_array( $filters[ 'country' ] ) ) {
				$filters[ 'country' ] = [ $filters[ 'country' ] ];
			}

			$query = [];
			foreach ( $filters[ 'country' ] as $country ) {
				$var     = 'country' . ( $i++ );
				$query[] = $qb->expr()->eq( 'u.country', ':' . $var );
				$qb->setParameter( $var, $country );
			}
			$qb->andWhere( new Orx( $query ) );
		}

		/**
		 * SKILLS
		 */
		if ( !empty( $filters[ 'skills' ] ) ) {
			if ( !is_array( $filters[ 'skills' ] ) ) {
				$filters[ 'skills' ] = [ $filters[ 'skills' ] ];
			}

			$qb->innerJoin( 'u.skills', 's' );

			$query = [];
			foreach ( $filters[ 'skills' ] as $skill ) {
				$var     = 'skill' . ( $i++ );
				$query[] = $qb->expr()->eq( 's.id', ':' . $var );
				$qb->setParameter( $var, $skill );
			}
			$qb->andWhere( new Orx( $query ) );
		}
	}

	public function searchCount ( $filters, $options = [] ) {
		$qb = $this->createQueryBuilder( 'u' );
		$qb->select( 'COUNT(u)' );

		$this->searchAddOption( $qb, $filters );

		return $qb->getQuery()
				  ->getSingleScalarResult();
	}

	public function search ( $filters, $options = [] ) {
		$options = array_merge( [ 'page' => 0, 'limit' => 20 ], $options );

		$qb = $this->createQueryBuilder( 'u' );

		$this->searchAddOption( $qb, $filters );

		/**
		 * GROUP
		 */
		if ( !empty( $filters[ 'group' ] ) ) {
			if ( !empty( $filters[ 'status' ] ) && ( $filters[ 'status' ] === UsergroupMembership::STATUS_ALL ) ) {
				$qb->addOrderBy( 'g.status', 'DESC' );
			}
			$qb->addOrderBy( 'g.joinedAt', 'DESC' );
		}
		else {
			$qb->addOrderBy( 'u.createdAt', 'DESC' );
		}

		return $qb->setFirstResult( $options[ 'limit' ] * $options[ 'page' ] )
				  ->setMaxResults( $options[ 'limit' ] )
				  ->getQuery()
				  ->getResult();
	}


	/**************************************************
	 * SEARCH ADMINS
	 *************************************************
	 *
	 * @param \Doctrine\ORM\QueryBuilder $qb
	 */
	public function searchCommunauteAdmins ( ) {

		$role = 'admin';
		$groupSlug = 'communaute';

		$qb = $this->createQueryBuilder('u')
			->leftJoin('u.usergroupMemberships', 'm')
			->leftJoin('m.usergroup', 'g')
			->where('m.role LIKE :role')
			->andWhere('g.slug = :groupSlug')
			->setParameter('role', $role)
			->setParameter('groupSlug', $groupSlug);
	
		return $qb->getQuery()->getResult();
	}

	/**************************************************
	 * Search number of user/adaptative approach by region
	 *************************************************
	*
	* @param \Doctrine\ORM\QueryBuilder $qb
	*/
	public function countUsersByRegion(bool $adaptativeApproachWanted)
	{
		$qb = $this->createQueryBuilder('u');
		if($adaptativeApproachWanted === true){
			$qb->addSelect('u.region')
			->select('u.region as regionCode, COUNT(u.id) as userCount')
			->where('u.hasAdaptativeApproach = :hasAdaptativeApproach')
			->setParameter('hasAdaptativeApproach', $adaptativeApproachWanted)
			->groupBy('u.region');
		} else {
			$qb->addSelect('u.region')
				->select('u.region as regionCode, COUNT(u.id) as userCount')
				->groupBy('u.region');
		}
	
		$results = $qb->getQuery()->getResult();
	
		// Transformer les résultats en un tableau associatif
		$countByRegion = [];
		foreach ($results as $result) {
			$countByRegion[$result['regionCode']] = $result['userCount'];
		}
	
		return $countByRegion;
	}

	/**************************************************
	 * Search number of user by country
	 *************************************************
	*/
	public function countUsersByCountry($adaptativeApproachWanted)
	{
		$qb = $this->createQueryBuilder('u');

		$excludedCountries = ['PL', 'GB', 'RS', 'SI', 'IS', 'HU', 'AL', 'NL', 'EE', 'AT', 'MT', 'IE', 'ME', 'CH', 'RO', 'LU', 'MK', 'FI', 'ES', 'PT', 'BE', 'IT', 'DK', 'SK', 'FR', 'CZ', 'BG', 'LT', 'LV', 'NO', 'EL', 'HR', 'CY', 'SE', 'TR', 'DE', 'LI'];

		if($adaptativeApproachWanted){
			$qb->addSelect('u.country')
				->select('u.country as countryCode, COUNT(u.id) as userCount')
				->where('u.hasAdaptativeApproach = :hasAdaptativeApproach')
				->andWhere($qb->expr()->orX(
					$qb->expr()->notIn('u.country', $excludedCountries),
					$qb->expr()->isNotNull('u.region')
				))
				->setParameter('hasAdaptativeApproach', $adaptativeApproachWanted)
				->groupBy('u.country');
		} else {
			$qb->addSelect('u.country')
				->select('u.country as countryCode, COUNT(u.id) as userCount')
				->andWhere($qb->expr()->orX(
					$qb->expr()->notIn('u.country', $excludedCountries),
					$qb->expr()->isNotNull('u.region')
				))
				->groupBy('u.country');
		}
	
		$results = $qb->getQuery()->getResult();
	
		// Transformer les résultats en un tableau associatif
		$countByCountry = [];
		foreach ($results as $result) {
			$countByCountry[$result['countryCode']] = $result['userCount'];
		}
	
		return $countByCountry;
	}

}
