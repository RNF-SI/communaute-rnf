<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\PrivateMessage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method PrivateMessage|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method PrivateMessage|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method PrivateMessage[]    findAll()
 * @method PrivateMessage[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class PrivateMessageRepository extends ServiceEntityRepository {
	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, PrivateMessage::class );
	}

	/**
	 * Le fil, dans l'ordre où il s'est écrit.
	 *
	 * @param \App\Entity\Conversation $conversation
	 *
	 * @return PrivateMessage[]
	 */
	public function findForConversation ( Conversation $conversation ) {
		return $this->createQueryBuilder( 'm' )
					->andWhere( 'm.conversation = :conversation' )
					->setParameter( 'conversation', $conversation )
					->orderBy( 'm.createdAt', 'ASC' )
					->addOrderBy( 'm.id', 'ASC' )
					->getQuery()
					->getResult();
	}

	/**
	 * Le dernier message de chacune de ces conversations, indexé par
	 * conversation.
	 *
	 * Deux requêtes pour toute la liste plutôt qu'une par ligne : la boîte de
	 * quelqu'un qui siège dans le réseau depuis dix ans compte des dizaines de
	 * conversations, et parcourir la collection de messages de chacune pour
	 * n'en garder que le dernier chargerait toute la messagerie pour afficher
	 * cinquante extraits.
	 *
	 * @param \App\Entity\Conversation[] $conversations
	 *
	 * @return \App\Entity\PrivateMessage[] indexé par identifiant de conversation
	 */
	public function findLastFor ( array $conversations ) {
		if ( empty( $conversations ) ) {
			return [];
		}

		$rows = $this->createQueryBuilder( 'm' )
					 ->select( 'MAX(m.id) AS last' )
					 ->andWhere( 'm.conversation IN (:conversations)' )
					 ->setParameter( 'conversations', $conversations )
					 ->groupBy( 'm.conversation' )
					 ->getQuery()
					 ->getArrayResult();

		$ids = array_filter( array_column( $rows, 'last' ) );

		if ( empty( $ids ) ) {
			return [];
		}

		$messages = $this->createQueryBuilder( 'm' )
						 ->andWhere( 'm.id IN (:ids)' )
						 ->setParameter( 'ids', $ids )
						 ->getQuery()
						 ->getResult();

		$last = [];

		foreach ( $messages as $message ) {
			$conversation = $message->getConversation();

			if ( $conversation ) {
				$last[ $conversation->getId() ] = $message;
			}
		}

		return $last;
	}

	/**
	 * Le dernier message dit, qui nomme la conversation dans la liste.
	 *
	 * @param \App\Entity\Conversation $conversation
	 *
	 * @return \App\Entity\PrivateMessage|null
	 */
	public function findLast ( Conversation $conversation ) {
		$found = $this->createQueryBuilder( 'm' )
					  ->andWhere( 'm.conversation = :conversation' )
					  ->setParameter( 'conversation', $conversation )
					  ->orderBy( 'm.createdAt', 'DESC' )
					  ->addOrderBy( 'm.id', 'DESC' )
					  ->setMaxResults( 1 )
					  ->getQuery()
					  ->getResult();

		return $found ? $found[ 0 ] : NULL;
	}

	/**
	 * Ce qui s'est écrit, dans les conversations de quelqu'un, depuis le
	 * message qu'il connaît déjà.

	 * C'est la seule requête que le sondage du dock fait à chaque tour.
	 * Elle est bornée par un identifiant et non par une date : deux messages
	 * de la même seconde ne se marchent pas dessus, et l'index de la clé
	 * primaire suffit.
	 *
	 * Le curseur reste **le flux de ce lecteur** et non le dernier
	 * identifiant de la table : avancer sur les messages des autres ferait
	 * sauter, un jour ou l'autre, celui d'une transaction validée dans le
	 * désordre.
	 *
	 * @param \App\Entity\User $user
	 * @param int              $since
	 * @param int              $limit
	 *
	 * @return PrivateMessage[] du plus ancien au plus récent
	 */
	public function findSinceFor ( User $user, $since, $limit = 200 ) {
		return $this->createQueryBuilder( 'm' )
					->innerJoin( 'm.conversation', 'c' )
					->innerJoin( 'c.participants', 'mine' )
					->andWhere( 'mine.user = :user' )
					->andWhere( 'mine.leftAt IS NULL' )
					->andWhere( 'm.id > :since' )
					->setParameter( 'user', $user )
					->setParameter( 'since', (int) $since )
					->orderBy( 'm.id', 'ASC' )
					->setMaxResults( $limit )
					->getQuery()
					->getResult();
	}

	/**
	 * Le dernier identifiant du flux de ce lecteur — le curseur d'où repart
	 * un dock qui vient de s'ouvrir.
	 *
	 * @param \App\Entity\User $user
	 *
	 * @return int 0 quand il n'a jamais rien reçu
	 */
	public function lastIdFor ( User $user ) {
		return (int) $this->createQueryBuilder( 'm' )
						  ->select( 'COALESCE(MAX(m.id), 0)' )
						  ->innerJoin( 'm.conversation', 'c' )
						  ->innerJoin( 'c.participants', 'mine' )
						  ->andWhere( 'mine.user = :user' )
						  ->andWhere( 'mine.leftAt IS NULL' )
						  ->setParameter( 'user', $user )
						  ->getQuery()
						  ->getSingleScalarResult();
	}

	/**
	 * Les quelques messages qui précèdent celui-là, du plus ancien au plus
	 * récent. C'est ce que recopie un signalement.
	 *
	 * @param \App\Entity\PrivateMessage $message
	 * @param int                        $limit
	 *
	 * @return PrivateMessage[]
	 */
	public function findBefore ( PrivateMessage $message, $limit = 3 ) {
		$found = $this->createQueryBuilder( 'm' )
					  ->andWhere( 'm.conversation = :conversation' )
					  ->andWhere( 'm.id < :id' )
					  ->setParameter( 'conversation', $message->getConversation() )
					  ->setParameter( 'id', $message->getId() )
					  ->orderBy( 'm.id', 'DESC' )
					  ->setMaxResults( $limit )
					  ->getQuery()
					  ->getResult();

		return array_reverse( $found );
	}
}
