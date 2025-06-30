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
        
        // Debug: log what data we have
        error_log('RnfUserProvider: Loading user for username: ' . $username);
        error_log('RnfUserProvider: RNF session data: ' . json_encode($rnfUserData));
        
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
                error_log('RnfUserProvider: Found user by RNF ID: ' . $rnfUserData['id_role']);
            }
        }
        
        // If not found by RNF ID, try by email
        if (!$user) {
            $user = $userRepository->findOneBy(['email' => $email]);
        }
        
        // Debug log to see which user is being loaded
        error_log('RnfUserProvider: Looking for user with email: ' . $email);
        if ($user) {
            error_log('RnfUserProvider: Found user ID: ' . $user->getId() . ', Name: ' . $user->getName());
        } else {
            error_log('RnfUserProvider: No user found with email: ' . $email);
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

    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
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