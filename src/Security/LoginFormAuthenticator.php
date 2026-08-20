<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * La connexion par mot de passe.
 *
 * **Éteinte par défaut.** Sur les serveurs, l'identité fait autorité chez
 * GeoNature : c'est le SSO qui connecte, et lui seul. Ce chemin n'existe que
 * pour les environnements où l'on doit pouvoir entrer avec les comptes des
 * données de test — un poste de développement, une préproduction dédiée à la
 * recette — puisque ces comptes n'existent pas dans GeoNature.
 *
 * `FORM_LOGIN_ENABLED=1` l'allume. **Jamais sur la production.**
 */
class LoginFormAuthenticator extends AbstractFormLoginAuthenticator {
	use TargetPathTrait;

	private $entityManager;
	private $router;
	private $csrfTokenManager;
	private $passwordEncoder;
	private $translator;
	private $enabled;

	public function __construct ( EntityManagerInterface $entityManager, RouterInterface $router, CsrfTokenManagerInterface $csrfTokenManager, UserPasswordEncoderInterface $passwordEncoder, TranslatorInterface $translator, string $enabled = '' ) {
		$this->entityManager    = $entityManager;
		$this->router           = $router;
		$this->csrfTokenManager = $csrfTokenManager;
		$this->passwordEncoder  = $passwordEncoder;
		$this->translator       = $translator;
		$this->enabled          = filter_var( $enabled, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * @return bool
	 */
	public function isEnabled () {
		return $this->enabled;
	}

	public function supports ( Request $request ) {
		// Éteint, l'authentificateur ne regarde même pas la requête : le
		// formulaire ne mène nulle part, et c'est le SSO qui reste.
		if ( !$this->enabled ) {
			return FALSE;
		}

		return ( $request->attributes->get( '_route' ) === 'user_login' )
			   && $request->isMethod( 'POST' )
			   && $request->request->has( 'email' )
			   && $request->request->has( 'password' )
			   && $request->request->has( '_csrf_token' );
	}

	public function getCredentials ( Request $request ) {
		$credentials = [
				'email'      => $request->request->get( 'email' ),
				'password'   => $request->request->get( 'password' ),
				'csrf_token' => $request->request->get( '_csrf_token' ),
		];
		$request->getSession()->set(
				Security::LAST_USERNAME,
				$credentials[ 'email' ]
		);

		return $credentials;
	}

	public function getUser ( $credentials, UserProviderInterface $userProvider ) {
		$token = new CsrfToken( 'authenticate', $credentials[ 'csrf_token' ] );
		if ( !$this->csrfTokenManager->isTokenValid( $token ) ) {
			throw new InvalidCsrfTokenException();
		}

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->entityManager->getRepository( User::class )->findOneBy( [ 'email' => $credentials[ 'email' ] ] );

		if ( $user === NULL ) {
			throw new CustomUserMessageAuthenticationException( 'messages.user.unknown' );
		}

		return $user;
	}

	public function checkCredentials ( $credentials, UserInterface $user ) {
		// Un compte venu du SSO n'a pas de mot de passe : sa colonne vaut la
		// chaîne vide. bcrypt refuserait déjà une empreinte vide, mais le dire
		// ici rend la règle lisible et vérifiable — aucun compte GeoNature ne
		// s'ouvre par ce chemin, même allumé.
		if ( trim( (string) $user->getPassword() ) === '' ) {
			return FALSE;
		}

		return $this->passwordEncoder->isPasswordValid( $user, $credentials[ 'password' ] );
	}

	public function onAuthenticationSuccess ( Request $request, TokenInterface $token, $providerKey ) {
		
		$user = $token->getUser();
		if (!$user instanceof UserInterface) {
			throw new \Exception('Invalid user object');
		}
	
		
		if ( $targetPath = $this->getTargetPath( $request->getSession(), $providerKey ) ) {
			return new RedirectResponse( $targetPath );
		}

		return new RedirectResponse( $this->router->generate( 'user_groups' ) );
	}

	protected function getLoginUrl () {
		return $this->router->generate( 'user_login' );
	}
}
