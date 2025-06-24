<?php

namespace App\Service;

use App\Entity\Usergroup;
use Doctrine\ORM\EntityManagerInterface;

class Community {
	/**
	 * @var \App\Entity\Usergroup|null
	 */
	private $group = null;

	/**
	 * Community constructor.
	 *
	 * @param string                                     $slug
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 */
	public function __construct (
			string $slug,
            EntityManagerInterface $manager
	) {
		if ( !empty( $slug ) ) {
			$this->group = $manager->getRepository( Usergroup::class )
								   ->findOneBy( [ 'slug' => $slug ] );
		}
	}

	/**
	 * @return \App\Entity\Usergroup|null
	 */
	public function getGroup () {
		return $this->group;
	}
}
