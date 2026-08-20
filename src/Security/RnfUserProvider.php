<?php

namespace App\Security;

use App\Entity\User;
use App\Service\RnfAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class RnfUserProvider implements UserProviderInterface
{
    /** @var RnfAuthService */
    private $rnfAuthService;
    
    /** @var EntityManagerInterface */
    private $entityManager;

    public function __construct(RnfAuthService $rnfAuthService, EntityManagerInterface $entityManager)
    {
        $this->rnfAuthService = $rnfAuthService;
        $this->entityManager = $entityManager;
    }

    public function loadUserByUsername($username)
    {
        $rnfUserData = $this->rnfAuthService->getCurrentUser();
        

        if (!$rnfUserData) {
            throw new UsernameNotFoundException('User not found in RNF session');
        }

        // Find or create local user
        $userRepository = $this->entityManager->getRepository(User::class);
        
        // Prepare email for fallback search and logging
        $email = $rnfUserData['email'] ?? '';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $login = $rnfUserData['identifiant'] ?? $rnfUserData['user_login'] ?? '';
            $email = $login . '@rnf.local';
        }
        
        // First try to find by RNF ID (most stable identifier)
        $user = null;
        if (isset($rnfUserData['id_role']) && $rnfUserData['id_role']) {
            $user = $userRepository->findOneBy(['rnfIdRole' => $rnfUserData['id_role']]);
            if ($user) {
            }
        }
        
        // If not found by RNF ID, try by email
        if (!$user) {
            $user = $userRepository->findOneBy(['email' => $email]);
        }
        
        
        if (!$user) {
            // Create new user from RNF data
            $user = $this->rnfAuthService->syncLocalUser($rnfUserData);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } else {
            // Update existing user with RNF data
            $user->setRnfIdRole($rnfUserData['id_role'] ?? null);
            $user->setRnfIdOrganisme($rnfUserData['id_organisme'] ?? null);
            $user->setRnfUserLogin($rnfUserData['identifiant'] ?? $rnfUserData['user_login'] ?? '');
            $user->setRnfPrenomRole($rnfUserData['prenom_role'] ?? '');
            $user->setRnfNomRole($rnfUserData['nom_role'] ?? '');
            $user->setRnfRoleInfo($rnfUserData['roleOPNLInfo'] ?? []);
            
            // Always update name with RNF data if available
            $firstName = $rnfUserData['prenom_role'] ?? '';
            $lastName = $rnfUserData['nom_role'] ?? '';
            $fullName = trim($firstName . ' ' . $lastName) ?: ($rnfUserData['nom_complet'] ?? '');
            
            if ($fullName && $fullName !== 'Utilisateur') {
                $user->setName($fullName);
                $user->setDisplayName($fullName);
            }
            
            $this->entityManager->flush();
        }

        return $user;
    }

    /**
     * Appelée à chaque requête, pour recharger le compte porté par la session.
     *
     * `loadUserByUsername` ne sait lire que la session SSO. Une connexion par
     * mot de passe n'en crée pas : sans le repli ci-dessous, elle authentifie
     * une fois puis lève UsernameNotFoundException à la requête suivante, et
     * la personne est éjectée sans avoir rien vu.
     *
     * Rien ne change pour le SSO : tant que sa session est là, c'est elle qui
     * fait autorité et le compte est resynchronisé comme avant.
     */
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        if (!$this->rnfAuthService->isAuthenticated()) {
            $fresh = $this->entityManager->getRepository(User::class)->find($user->getId());

            if (!$fresh) {
                throw new UsernameNotFoundException('The account no longer exists');
            }

            return $fresh;
        }

        return $this->loadUserByUsername($user->getEmail());
    }

    public function supportsClass($class)
    {
        return User::class === $class;
    }

    public function loadUserByIdentifier($identifier)
    {
        return $this->loadUserByUsername($identifier);
    }
}