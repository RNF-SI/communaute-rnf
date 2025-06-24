<?php

namespace App\Controller;

use App\Service\RnfAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class RnfAuthController extends AbstractController
{
    /** @var RnfAuthService */
    private $rnfAuthService;

    public function __construct(RnfAuthService $rnfAuthService)
    {
        $this->rnfAuthService = $rnfAuthService;
    }

    /**
     * @Route("/auth/login", name="rnf_auth_login", methods={"GET", "POST"})
     */
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // If user is already authenticated through Symfony security, redirect
        if ($this->getUser()) {
            return $this->redirectToRoute('user_dashboard');
        }

        // Get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        
        // Last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('forms/user/rnf-login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'inscription_url' => $this->rnfAuthService->getInscriptionUrl(),
        ]);
    }

    /**
     * @Route("/auth/api/login", name="rnf_auth_api_login", methods={"POST"})
     */
    public function apiLogin(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['login']) || !isset($data['password'])) {
            return new JsonResponse(['error' => 'Login and password are required'], 400);
        }

        try {
            // Authenticate with RNF and get/create local user
            $userData = $this->rnfAuthService->authenticate($data['login'], $data['password']);
            $user = $this->rnfAuthService->syncLocalUser($userData);
            
            // Generate JWT token (simulated for now - would use proper JWT library)
            $token = $this->rnfAuthService->generateToken($user, $userData);
            
            return new JsonResponse([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user->getId(),
                    'userId' => $user->getId(),
                    'userName' => $user->getDisplayName(),
                    'email' => $user->getEmail(),
                    'id_role' => $userData['id_role'] ?? null,
                    'identifiant' => $userData['identifiant'] ?? $userData['user_login'] ?? '',
                    'nom_complet' => $userData['nom_complet'] ?? '',
                    'id_organisme' => $userData['id_organisme'] ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 401);
        }
    }

    /**
     * @Route("/auth/logout", name="rnf_auth_logout", methods={"GET"})
     */
    public function logout(): Response
    {
        $this->rnfAuthService->logout();
        
        $this->addFlash('success', 'Vous avez été déconnecté avec succès.');
        
        return $this->redirectToRoute('rnf_auth_login');
    }

    /**
     * @Route("/auth/inscription", name="rnf_auth_inscription", methods={"GET"})
     */
    public function inscription(): Response
    {
        // Redirect to RNF inscription page
        return $this->redirect($this->rnfAuthService->getInscriptionUrl());
    }

    /**
     * @Route("/auth/user", name="rnf_auth_current_user", methods={"GET"})
     */
    public function getCurrentUser(): JsonResponse
    {
        $userData = $this->rnfAuthService->getCurrentUser();
        
        if (!$userData) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        return new JsonResponse([
            'user' => [
                'id_role' => $userData['id_role'] ?? null,
                'identifiant' => $userData['identifiant'] ?? $userData['user_login'] ?? '',
                'nom_complet' => $userData['nom_complet'] ?? '',
                'prenom_role' => $userData['prenom_role'] ?? '',
                'nom_role' => $userData['nom_role'] ?? '',
                'id_organisme' => $userData['id_organisme'] ?? null,
            ]
        ]);
    }

    /**
     * @Route("/auth/debug/api-test", name="rnf_auth_debug_api", methods={"GET"})
     */
    public function debugApiConnectivity(): JsonResponse
    {
        $results = $this->rnfAuthService->testApiConnectivity();
        
        return new JsonResponse([
            'test_results' => $results,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}