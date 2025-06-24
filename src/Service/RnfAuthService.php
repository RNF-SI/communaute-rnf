<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Psr\Log\LoggerInterface;

class RnfAuthService
{
    /** @var HttpClientInterface */
    private $httpClient;
    /** @var ParameterBagInterface */
    private $params;
    /** @var SessionInterface */
    private $session;
    /** @var LoggerInterface */
    private $logger;
    /** @var EntityManagerInterface */
    private $entityManager;

    public function __construct(
        HttpClientInterface $httpClient,
        ParameterBagInterface $params,
        SessionInterface $session,
        LoggerInterface $logger,
        EntityManagerInterface $entityManager
    ) {
        $this->httpClient = $httpClient;
        $this->params = $params;
        $this->session = $session;
        $this->logger = $logger;
        $this->entityManager = $entityManager;
    }

    /**
     * Authenticate user with RNF platform
     */
    public function authenticate(string $login, string $password): array
    {
        $authConfig = $this->params->get('rnf_auth');
        
        $this->logger->info('RNF authentication attempt', [
            'login' => $login,
            'api_endpoint' => $authConfig['api_endpoint'],
            'id_application' => $authConfig['id_application']
        ]);
        
        // Use only the working endpoint
        $endpoints = ['/api/auth/login'];
        $response = null;
        $lastException = null;
        
        foreach ($endpoints as $endpoint) {
            $url = $authConfig['api_endpoint'] . $endpoint;
            $this->logger->info('Trying RNF authentication endpoint: ' . $url);
            
            try {
                // Use only JSON format like the mobile app
                $formats = [
                    // Format 1: JSON (as in mobile app) - correct format
                    [
                        'json' => [
                            'login' => $login,
                            'password' => $password,
                            'id_application' => $authConfig['id_application']
                        ],
                        'headers' => [
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json'
                        ]
                    ]
                ];
                
                $formatSuccess = false;
                foreach ($formats as $formatIndex => $format) {
                    try {
                        $this->logger->info('Trying format ' . ($formatIndex + 1) . ' for endpoint: ' . $endpoint);
                        $response = $this->httpClient->request('POST', $url, $format);
                        
                        // If we get here without exception, check the status
                        $statusCode = $response->getStatusCode();
                        if ($statusCode === 200) {
                            $this->logger->info('Successful authentication with endpoint: ' . $endpoint . ' format: ' . ($formatIndex + 1));
                            $formatSuccess = true;
                            break; // Success, exit format loop
                        } else {
                            $responseContent = $response->getContent(false);
                            $this->logger->warning('Non-200 status from endpoint ' . $endpoint . ' format ' . ($formatIndex + 1) . ': ' . $statusCode, [
                                'response_content' => substr($responseContent, 0, 500)
                            ]);
                            
                            // For 400 status, this might be the most promising - let's see what's missing
                            if ($statusCode === 400) {
                                $this->logger->error('Status 400 details for ' . $endpoint . ' format ' . ($formatIndex + 1), [
                                    'full_response' => $responseContent,
                                    'endpoint' => $url,
                                    'sent_data' => $format
                                ]);
                            }
                        }
                        
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed with endpoint ' . $endpoint . ' format ' . ($formatIndex + 1) . ': ' . $e->getMessage());
                        $lastException = $e;
                    }
                }
                
                if ($formatSuccess) {
                    break; // Success, exit endpoint loop
                }
                
            } catch (\Exception $e) {
                $this->logger->warning('Failed with endpoint ' . $endpoint . ': ' . $e->getMessage());
                $lastException = $e;
                $response = null;
                continue; // Try next endpoint
            }
        }
        
        if (!$response) {
            throw $lastException ?? new \Exception('All authentication endpoints failed');
        }

        $statusCode = $response->getStatusCode();
        $content = $response->getContent(false); // false to get content even on error
        
        $this->logger->info('RNF API response', [
            'status_code' => $statusCode,
            'response_body' => substr($content, 0, 500) // Log first 500 chars
        ]);

        if ($statusCode !== 200) {
            $this->logger->error('RNF authentication failed', [
                'status_code' => $statusCode,
                'url' => $url,
                'content' => $content,
                'login' => $login
            ]);
            
            $errorMessage = 'Authentication failed with status: ' . $statusCode;
            if ($content) {
                try {
                    $errorData = json_decode($content, true);
                    if (isset($errorData['message'])) {
                        $errorMessage .= ' - ' . $errorData['message'];
                    } elseif (isset($errorData['error'])) {
                        $errorMessage .= ' - ' . $errorData['error'];
                    }
                } catch (\Exception $e) {
                    // If JSON decode fails, append raw content
                    $errorMessage .= ' - Content: ' . substr($content, 0, 200);
                }
            }
            
            // Status 490 is often a custom error, let's check if it's about missing endpoint
            if ($statusCode === 490) {
                $errorMessage .= ' (This might indicate the endpoint /auth/login does not exist on the RNF server)';
            }
            
            throw new \Exception($errorMessage);
        }

        // Parse response JSON
        try {
            $userData = $response->toArray();
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse RNF response as JSON', [
                'content' => $content,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Invalid JSON response from RNF server: ' . $e->getMessage());
        }
        
        // Extract user data from response
        // The API returns { "token": "...", "user": {...} }
        if (isset($userData['user']) && is_array($userData['user'])) {
            $actualUserData = $userData['user'];
        } else {
            // Fallback if structure is different
            $actualUserData = $userData;
        }
        
        // Store user data in session
        $this->session->set('rnf_user', $actualUserData);
        
        $this->logger->info('RNF authentication successful', [
            'user_login' => $login,
            'id_role' => $actualUserData['id_role'] ?? null,
            'email' => $actualUserData['email'] ?? null,
            'identifiant' => $actualUserData['identifiant'] ?? null
        ]);

        return $actualUserData;
    }

    /**
     * Get current authenticated user from session
     */
    public function getCurrentUser(): ?array
    {
        return $this->session->get('rnf_user');
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        return $this->getCurrentUser() !== null;
    }

    /**
     * Logout user
     */
    public function logout(): void
    {
        $this->session->remove('rnf_user');
        $this->session->invalidate();
    }

    /**
     * Get inscription URL
     */
    public function getInscriptionUrl(): string
    {
        $authConfig = $this->params->get('rnf_auth');
        return $authConfig['inscription_url'];
    }

    /**
     * Get platform URL
     */
    public function getPlatformUrl(): string
    {
        $authConfig = $this->params->get('rnf_auth');
        return $authConfig['platform_url'];
    }

    /**
     * Create or update local user from RNF data
     */
    public function syncLocalUser(array $rnfUserData): User
    {
        // Debug: log the data we receive
        error_log('RNF syncLocalUser: Received data: ' . json_encode($rnfUserData));
        
        // Extract email from RNF data - use the real email field first
        $email = $rnfUserData['email'] ?? '';
        
        // If no valid email, fallback to constructing one from identifiant
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $login = $rnfUserData['identifiant'] ?? $rnfUserData['user_login'] ?? '';
            if (!$login) {
                error_log('RNF syncLocalUser ERROR: No email or identifiant. Data keys: ' . implode(', ', array_keys($rnfUserData)));
                
                // More helpful error message
                $availableFields = [];
                if (isset($rnfUserData['email'])) $availableFields[] = 'email=' . $rnfUserData['email'];
                if (isset($rnfUserData['identifiant'])) $availableFields[] = 'identifiant=' . $rnfUserData['identifiant'];
                if (isset($rnfUserData['user_login'])) $availableFields[] = 'user_login=' . $rnfUserData['user_login'];
                
                throw new \Exception('Impossible de créer l\'utilisateur: données RNF invalides. Disponible: ' . implode(', ', $availableFields));
            }
            $email = $login . '@rnf.local';
        }
        
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['email' => $email]);
        
        if (!$user) {
            // Create new user
            $user = new User();
            $user->setEmail($email);
            $user->setCreatedAt(new \DateTime());
            $user->setStatus(User::STATUS_ACTIVE);
            $user->setHasAgreedTermsOfUse(true);
            $user->setPassword(''); // RNF users don't need local passwords
        }
        
        // Update user data from RNF
        $user->setName($this->getFullName($rnfUserData));
        $user->setDisplayName($this->getFullName($rnfUserData));
        
        // Map RNF specific fields
        $user->setRnfIdRole($rnfUserData['id_role'] ?? null);
        $user->setRnfIdOrganisme($rnfUserData['id_organisme'] ?? null);
        $user->setRnfUserLogin($rnfUserData['identifiant'] ?? $rnfUserData['user_login'] ?? '');
        $user->setRnfPrenomRole($rnfUserData['prenom_role'] ?? '');
        $user->setRnfNomRole($rnfUserData['nom_role'] ?? '');
        $user->setRnfRoleInfo($rnfUserData['roleOPNLInfo'] ?? []);
        
        // Only set roles for new users, preserve existing roles for existing users
        if (!$user->getId()) {
            // New user - set initial roles
            $roles = ['ROLE_USER'];
            if (isset($rnfUserData['is_admin']) && $rnfUserData['is_admin']) {
                $roles[] = 'ROLE_ADMIN';
            }
            $user->setRoles($roles);
            error_log('RNF: Setting initial roles for new user ' . $user->getEmail() . ': ' . json_encode($roles));
        } else {
            // Existing user - do NOT modify roles to preserve manually set admin rights
            error_log('RNF: Preserving existing roles for user ' . $user->getEmail() . ': ' . json_encode($user->getRoles()));
        }
        
        // Persist user
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * Get full name from RNF user data
     */
    private function getFullName(array $rnfUserData): string
    {
        $firstName = $rnfUserData['prenom_role'] ?? '';
        $lastName = $rnfUserData['nom_role'] ?? '';
        
        return trim($firstName . ' ' . $lastName) ?: ($rnfUserData['nom_complet'] ?? 'Utilisateur');
    }

    /**
     * Test API connectivity and discover available endpoints
     */
    public function testApiConnectivity(): array
    {
        $authConfig = $this->params->get('rnf_auth');
        $results = [];
        
        $testEndpoints = [
            '/api/auth/login',
            '/auth/login',
            '/login',
            '/api/login',
            '/api/status',
            '/status',
            '/health',
            '/api/health'
        ];
        
        foreach ($testEndpoints as $endpoint) {
            try {
                $response = $this->httpClient->request('GET', $authConfig['api_endpoint'] . $endpoint, [
                    'headers' => [
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $results[$endpoint] = [
                    'status' => $response->getStatusCode(),
                    'content' => substr($response->getContent(false), 0, 200)
                ];
                
            } catch (\Exception $e) {
                $results[$endpoint] = [
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Generate JWT-like token for API authentication
     * This is a simplified token - in production, use a proper JWT library
     */
    public function generateToken(User $user, array $rnfUserData): string
    {
        $payload = [
            'userId' => $user->getId(),
            'email' => $user->getEmail(),
            'userName' => $user->getDisplayName(),
            'rnf_id_role' => $rnfUserData['id_role'] ?? null,
            'rnf_id_organisme' => $rnfUserData['id_organisme'] ?? null,
            'issued_at' => time(),
            'expires_at' => time() + (24 * 60 * 60), // 24 hours
        ];

        // Simple base64 encoded token (replace with proper JWT in production)
        $token = base64_encode(json_encode($payload));
        
        // Store token in session for web authentication
        $this->session->set('rnf_auth_token', $token);
        $this->session->set('rnf_user_id', $user->getId());
        
        return $token;
    }

    /**
     * Validate and decode token
     */
    public function validateToken(string $token): ?array
    {
        try {
            $payload = json_decode(base64_decode($token), true);
            
            if (!$payload || !isset($payload['expires_at']) || $payload['expires_at'] < time()) {
                return null;
            }
            
            return $payload;
        } catch (\Exception $e) {
            $this->logger->error('Token validation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user from token
     */
    public function getUserFromToken(string $token): ?User
    {
        $payload = $this->validateToken($token);
        
        if (!$payload || !isset($payload['userId'])) {
            return null;
        }
        
        return $this->entityManager->getRepository(User::class)->find($payload['userId']);
    }
}