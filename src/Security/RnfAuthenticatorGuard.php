<?php

namespace App\Security;

use App\Service\RnfAuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

class RnfAuthenticatorGuard extends AbstractGuardAuthenticator
{
    use TargetPathTrait;

    /** @var RnfAuthService */
    private $rnfAuthService;
    /** @var RouterInterface */
    private $router;
    /** @var CsrfTokenManagerInterface */
    private $csrfTokenManager;

    public function __construct(
        RnfAuthService $rnfAuthService,
        RouterInterface $router,
        CsrfTokenManagerInterface $csrfTokenManager
    ) {
        $this->rnfAuthService = $rnfAuthService;
        $this->router = $router;
        $this->csrfTokenManager = $csrfTokenManager;
    }

    public function supports(Request $request)
    {
        // Support form-based login
        $isFormLogin = $request->attributes->get('_route') === 'rnf_auth_login'
            && $request->isMethod('POST')
            && ($request->request->has('login') || $request->request->has('email'))
            && $request->request->has('password')
            && $request->request->has('_csrf_token');
            
        // Support Bearer token authentication
        $hasBearerToken = $request->headers->has('Authorization') 
            && str_starts_with($request->headers->get('Authorization'), 'Bearer ');
            
        // Debug logging
        if ($request->attributes->get('_route') === 'rnf_auth_login' && $request->isMethod('POST')) {
            error_log('RNF Auth Support Check: route=' . $request->attributes->get('_route') . 
                     ', method=' . $request->getMethod() . 
                     ', has_login=' . ($request->request->has('login') ? 'yes' : 'no') .
                     ', has_password=' . ($request->request->has('password') ? 'yes' : 'no') .
                     ', has_csrf=' . ($request->request->has('_csrf_token') ? 'yes' : 'no'));
        }
            
        return $isFormLogin || $hasBearerToken;
    }

    public function getCredentials(Request $request)
    {
        // Handle Bearer token authentication
        if ($request->headers->has('Authorization') && 
            str_starts_with($request->headers->get('Authorization'), 'Bearer ')) {
            $token = substr($request->headers->get('Authorization'), 7);
            return [
                'type' => 'bearer_token',
                'token' => $token,
            ];
        }
        
        // Handle form-based login
        $login = $request->request->get('login') ?: $request->request->get('email');
        return [
            'type' => 'form_login',
            'login' => $login,
            'password' => $request->request->get('password'),
            'csrf_token' => $request->request->get('_csrf_token'),
        ];
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        try {
            if ($credentials['type'] === 'bearer_token') {
                // Handle Bearer token authentication
                $user = $this->rnfAuthService->getUserFromToken($credentials['token']);
                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('Token invalide ou expiré');
                }
                return $user;
            }
            
            // Handle form login
            // Validate CSRF token
            $token = new CsrfToken('authenticate', $credentials['csrf_token']);
            if (!$this->csrfTokenManager->isTokenValid($token)) {
                throw new InvalidCsrfTokenException();
            }

            // Authenticate with RNF platform
            $rnfUserData = $this->rnfAuthService->authenticate(
                $credentials['login'],
                $credentials['password']
            );

            // Create/sync local user
            $user = $this->rnfAuthService->syncLocalUser($rnfUserData);
            
            return $user;

        } catch (\Exception $e) {
            throw new CustomUserMessageAuthenticationException('Échec de l\'authentification: ' . $e->getMessage());
        }
    }

    public function checkCredentials($credentials, UserInterface $user)
    {
        // Credentials are already validated in getUser()
        return true;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        // The user is already set in the token by Symfony's authentication system
        // We just need to redirect to the appropriate page

        // Send the user back to the page they were trying to reach, typically
        // a deep link from a notification e-mail.
        $targetPath = $this->getTargetPath($request->getSession(), $providerKey);
        $this->removeTargetPath($request->getSession(), $providerKey);

        $loginPath = $this->router->generate('rnf_auth_login');

        // Never bounce back to the login page itself, that would loop.
        if ($targetPath && (strpos($targetPath, $loginPath) === false)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->router->generate('user_groups'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        $message = strtr($exception->getMessageKey(), $exception->getMessageData());

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => $message], 401);
        }

        $request->getSession()->getFlashBag()->add('error', $message);
        return new RedirectResponse($this->router->generate('rnf_auth_login'));
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        // Redirect to login page
        return new RedirectResponse($this->router->generate('rnf_auth_login'));
    }

    public function supportsRememberMe()
    {
        return false; // RNF handles session management
    }
}