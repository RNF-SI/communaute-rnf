<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class HashGenerator {

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface
	 */
	private $passwordEncoder;

	/**
	 * HashGenerator constructor.
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface                                  $manager
	 * @param \Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface $passwordEncoder
	 */
	/**
	 * @var string
	 */
	private $secret;

	/**
	 * HashGenerator constructor.
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface                                  $manager
	 * @param \Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface $passwordEncoder
	 * @param string                                                                $secret
	 */
	public function __construct (
        EntityManagerInterface $manager,
        UserPasswordEncoderInterface $passwordEncoder,
        string $secret
	) {
		$this->manager         = $manager;
		$this->passwordEncoder = $passwordEncoder;
		$this->secret          = $secret;
	}

	/**
	 * @param \App\Entity\User $user
	 *
	 * @return string
	 */
	public function generateUserHash ( User $user ) {
		// The application secret is part of the mix: accounts coming from the
		// single sign-on carry no password, and the hash would otherwise be
		// nothing but the SHA-256 of an account id — guessable for anybody,
		// which is enough to unsubscribe somebody else. (#14)
		return $user->getId() . '|' . hash_hmac(
						'sha256',
						$user->getId() . '|' . $user->getPassword(),
						$this->secret
				);
	}

	/**
	 * @param string $hash
	 *
	 * @return \App\Entity\User[]|bool|object[]
	 */
	public function getUserFromHash ( string $hash ) {
		$u = explode( '|', $hash );

		$user = $this->manager->getRepository( User::class )->findOneBy( [ 'id' => $u[ 0 ] ] );

		if ( $user && hash_equals( $this->generateUserHash( $user ), $hash ) ) {
			return $user;
		}

		return FALSE;
	}
}
