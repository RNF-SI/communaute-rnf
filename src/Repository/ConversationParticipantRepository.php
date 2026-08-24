<?php

namespace App\Repository;

use App\Entity\ConversationParticipant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method ConversationParticipant|null find( $id, $lockMode = NULL, $lockVersion = NULL )
 * @method ConversationParticipant|null findOneBy( array $criteria, array $orderBy = NULL )
 * @method ConversationParticipant[]    findAll()
 * @method ConversationParticipant[]    findBy( array $criteria, array $orderBy = NULL, $limit = NULL, $offset = NULL )
 */
class ConversationParticipantRepository extends ServiceEntityRepository {
	public function __construct ( ManagerRegistry $registry ) {
		parent::__construct( $registry, ConversationParticipant::class );
	}
}
