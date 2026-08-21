<?php

namespace App\Controller;

use App\Entity\Discussion;
use App\Entity\File;
use App\Entity\LogEvent;
use App\Entity\Notification;
use App\Entity\User;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationCategory;
use App\Notification\NotificationLevel;
use App\Form\UserProfileType;
use App\Security\LoginFormAuthenticator;
use App\Security\UserVoter;
use App\Service\Community;
use App\Service\EmailSender;
use App\Service\FileManager;
use App\Service\HashGenerator;
use App\Service\UserAnonymize;
use App\Twig\TourExtension;
use App\Util\Geocoder;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class UserController extends AbstractController
{

	private $geocoder;
	private $recaptchaSiteKey;
	private $recaptchaSecretKey;

	public function __construct(Geocoder $myUtil, ParameterBagInterface $params)
	{
		$this->geocoder = $myUtil;
		$this->recaptchaSiteKey = $params->get('google_recaptcha_site_key');
		$this->recaptchaSecretKey = $params->get('google_recaptcha_secret_key');
	}

	/**
	 * Login form can be embed in pages
	 *
	 * @param \Symfony\Component\Security\Http\Authentication\AuthenticationUtils $authenticationUtils
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function loginForm(AuthenticationUtils $authenticationUtils)
	{
		$error        = $authenticationUtils->getLastAuthenticationError();
		$lastUsername = $authenticationUtils->getLastUsername();

		if (!empty($error)) {
			$key = $error->getMessageKey();
			if ($key === 'Invalid credentials.') {
				$key = 'messages.user.invalid_credentials';
			}

			$this->addFlash('error', $key);
		}

		return $this->render('forms/user/login.html.twig', [
			'last_username' => $lastUsername,
		]);
	}

	/**
	 * @Route("/user/login", name="user_login")
	 *
	 * Sur la production, l'identité fait autorité chez GeoNature : cette
	 * adresse renvoie au SSO. Là où `FORM_LOGIN_ENABLED=1` — une préproduction
	 * de recette, un poste de développement — elle présente le formulaire de
	 * mot de passe, sans lequel les comptes des données de test ne peuvent pas
	 * entrer : ils n'existent pas dans GeoNature.
	 *
	 * @param \App\Security\LoginFormAuthenticator                            $authenticator
	 * @param \Symfony\Component\Security\Http\Authentication\AuthenticationUtils $utils
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function loginPage( LoginFormAuthenticator $authenticator, AuthenticationUtils $utils )
	{
		if ( !$authenticator->isEnabled() ) {
			return $this->redirectToRoute('rnf_auth_login');
		}

		if ( $error = $utils->getLastAuthenticationError() ) {
			$key = $error->getMessageKey();

			$this->addFlash( 'error', $key === 'Invalid credentials.' ? 'messages.user.invalid_credentials' : $key );
		}

		return $this->render( 'pages/user/login-form.html.twig', [
				'last_username' => $utils->getLastUsername(),
		] );
	}

	/**
	 * @Route("/user/logout", name="user_logout")
	 */
	public function logout()
	{
		return $this->redirectToRoute('homepage');
	}

	/**
	 * @Route("/user/profile/edit", name="user_profile_edit")
	 *
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface;      $manager
	 * @param \App\Service\FileManager                   $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
	 */
	public function profileEdit(
		Request $request,
		EntityManagerInterface $manager,
		FileManager $fileManager
	) {
		$this->denyAccessUnlessGranted(UserVoter::LOGGED);

		/**
		 * @var User $user
		 */
		$user = $this->getUser();
		$form = $this->createForm(UserProfileType::class, $user);
		$form->handleRequest($request);

		if ($form->isSubmitted() && $form->isValid()) {
			// Avatar
			$uploadFile = $form->get('avatarfile')->getData();

			if (!empty($uploadFile)) {
				/**
				 * @var \App\Service\UserFileManager $userFileManager
				 */
				$userFileManager = $fileManager->getManager(File::USER_FILES);
				$file            = $userFileManager->createFromUploadedFile($uploadFile, $user);

				$manager->persist($file);

				$user->setAvatar($file);
			}

			// Convert Latitude et Longitude to Nuts code (european Region Code)
			$latitude = $user->getLatitude();
			$longitude = $user->getLongitude();
			$NUTS_ID = $this->geocoder->getNutsId($latitude, $longitude);

			// Associer la région et le pays au membre
			if ($NUTS_ID) {
				$user->setRegion($NUTS_ID);
			}

			// Mark profile as updated
			$user->setProfileUpdatedAt(new \DateTime());

			$manager->flush();

			$this->addFlash('notice', 'messages.user.profile_updated');

			return $this->redirectToRoute('user_dashboard');
		}

		return $this->render('pages/user/profile-edit.html.twig', ['form' => $form->createView(), 'user' => $user]);
	}

	/**
	 * @Route("/user/parameters/edit", name="user_parameters_edit", methods={"GET", "POST"})
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function parametersEdit(
		Request $request,
		EntityManagerInterface $manager
	) {
		$this->denyAccessUnlessGranted(UserVoter::LOGGED);

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		if ($request->isMethod('POST') && $this->isCsrfTokenValid('notifications', $request->request->get('_token'))) {
			$settings = $request->request->get('notifications', []);

			$user->setWantsEmails(!empty($settings['emails']));

			// Le réglage général : ce que vaut un groupe qui ne dit rien.
			foreach (NotificationCategory::all() as $category) {
				if (!empty($settings['defaults'][$category])) {
					$user->setDefaultNotificationLevel($category, $settings['defaults'][$category]);
				}
			}

			// « Remettre tous mes groupes au réglage général » : un bouton à
			// part, parce qu'il efface des choix — la seule façon, sinon, de
			// rattraper trente groupes réglés un par un serait de les
			// reprendre un par un.
			$resetGroups = $request->request->has('reset-groups');

			foreach ($user->getUsergroupMemberships() as $membership) {
				$groupId = $membership->getUsergroup() ? $membership->getUsergroup()->getId() : NULL;

				if (!$groupId) {
					continue;
				}

				if ($resetGroups) {
					$membership->followGeneralSettings();

					continue;
				}

				$chosen = isset($settings['groups'][$groupId]) ? $settings['groups'][$groupId] : [];

				foreach (NotificationCategory::all() as $category) {
					if (!isset($chosen[$category])) {
						continue;
					}

					// Vide : la catégorie repasse sous le réglage général,
					// elle n'est pas recopiée.
					if ($chosen[$category] === '') {
						$membership->clearNotificationLevel($category);

						continue;
					}

					$membership->setNotificationLevel($category, $chosen[$category]);
				}
			}

			$manager->flush();

			$this->addFlash('notice', $resetGroups ? 'messages.user.notifications_reset' : 'messages.user.notifications_updated');

			return $this->redirectToRoute('user_parameters_edit');
		}

		// Y venir, c'est avoir lu l'annonce : elle envoie ici, et la répéter
		// ensuite ne dirait plus rien. (#40)
		if ($user->awaitsNotificationsNotice()) {
			$user->markNotificationsNoticeSeen(new DateTime());
			$manager->flush();
		}

		return $this->render('pages/user/parameters-edit.html.twig', [
				'user'       => $user,
				'categories' => NotificationCategory::all(),
				'levels'     => NotificationLevel::all(),
		]);
	}

	/**
	 * @Route("/user/dashboard", name="user_dashboard")
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface;      $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
	 */
	public function dashboard(
		EntityManagerInterface $manager
	) {
		$this->denyAccessUnlessGranted(UserVoter::LOGGED);

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		$groups = array_map(function (UsergroupMembership $membership) {
			return $membership->getUsergroup();
		}, iterator_to_array($user->getUsergroupMemberships()));

		$logEvents = $manager->getRepository(LogEvent::class)
			->findForGroups($groups);

		return $this->render('pages/user/dashboard.html.twig', ['logEvents' => $logEvents]);
	}





	/**
	 * @Route("/user/groups", name="user_groups")
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
	 */
	public function userGroups(
		EntityManagerInterface $manager
	) {
		$this->denyAccessUnlessGranted(UserVoter::LOGGED);

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		return $this->render('pages/user/my-groups.html.twig', ['user' => $user]);
	}

	/**
	 * The address the List-Unsubscribe header of every e-mail points at.
	 * Reachable without signing in, since a mail client follows it on its own,
	 * and answering POST is what makes one-click unsubscribe work. (#14)
	 *
	 * @Route("/user/notifications/unsubscribe/{hash}", name="user_notifications_unsubscribe", methods={"GET", "POST"})
	 *
	 * @param                                       $hash
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface  $manager
	 * @param \App\Service\HashGenerator            $hashGenerator
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function userNotificationsUnsubscribe(
		$hash,
		Request $request,
		EntityManagerInterface $manager,
		HashGenerator $hashGenerator
	) {
		$user = $hashGenerator->getUserFromHash($hash);

		if (!$user) {
			throw $this->createNotFoundException('Unknown recipient');
		}

		$user->setWantsEmails(false);
		$manager->flush();

		if ($request->isMethod('POST')) {
			// One-click: the mail client expects an answer, not a page.
			return new Response('', Response::HTTP_OK);
		}

		$this->addFlash('notice', 'messages.user.emails_stopped');

		return $this->redirectToRoute('user_parameters_edit');
	}

	/**
	 * La visite guidée a été vue : elle ne se relancera plus d'elle-même.
	 *
	 * Appelée par la visite elle-même, aussi bien quand on la termine que
	 * quand on la referme — quelqu'un qui la ferme au premier écran a dit ce
	 * qu'il pensait de la proposition, la relancer à chaque page serait la
	 * transformer en harcèlement. (#39)
	 *
	 * @Route("/user/tour/seen", name="user_tour_seen", methods={"POST"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function userTourSeen ( Request $request, EntityManagerInterface $manager ) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		if ( !$this->isCsrfTokenValid( 'guided-tour', $request->request->get( '_token' ) ) ) {
			throw $this->createAccessDeniedException( 'Invalid token' );
		}

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		// Rien à réécrire si elle a déjà été vue : la date qui compte est
		// celle de la première fois.
		if ( !$user->hasSeenTour() ) {
			$user->setTourSeenAt( new DateTime() );
			$manager->flush();
		}

		return $this->json( [ 'seen' => TRUE ] );
	}

	/**
	 * Rejouer la visite, depuis les paramètres.
	 *
	 * @Route("/user/tour", name="user_tour", methods={"GET"})
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function userTour () {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		// Sur « mes groupes », là où la visite a de quoi montrer.
		return $this->redirectToRoute( 'user_groups', [ TourExtension::REPLAY => 1 ] );
	}

	/**
	 * Refermer l'annonce du changement de notifications. (#40)
	 *
	 * Un formulaire, pas un appel JavaScript : le bandeau doit pouvoir se
	 * refermer sans scripts, comme le reste de la page des paramètres.
	 *
	 * @Route("/user/notifications/notice", name="user_notifications_notice", methods={"POST"})
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function userNotificationsNotice ( Request $request, EntityManagerInterface $manager ) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		if ( !$this->isCsrfTokenValid( 'notifications-notice', $request->request->get( '_token' ) ) ) {
			throw $this->createAccessDeniedException( 'Invalid token' );
		}

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		if ( $user->awaitsNotificationsNotice() ) {
			$user->markNotificationsNoticeSeen( new DateTime() );
			$manager->flush();
		}

		// Revenir là où on était : l'annonce suit le membre de page en page,
		// la refermer ne doit pas le déplacer.
		$back = $request->request->get( 'back' );

		return $this->redirect( $this->isSafeRedirect( $back ) ? $back : $this->generateUrl( 'homepage' ) );
	}

	/**
	 * Une adresse interne, et rien d'autre : un chemin absolu qui ne repart
	 * pas vers un autre hôte.
	 *
	 * @param string|null $target
	 *
	 * @return bool
	 */
	private function isSafeRedirect ( $target ) {
		return is_string( $target )
			   && ( strpos( $target, '/' ) === 0 )
			   && ( strpos( $target, '//' ) !== 0 )
			   && ( strpos( $target, '/\\' ) !== 0 );
	}

	/**
	 * @Route("/user/notifications", name="user_notifications")
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function userNotifications(
		EntityManagerInterface $manager
	) {
		$this->denyAccessUnlessGranted(UserVoter::LOGGED);

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		$repository    = $manager->getRepository(Notification::class);
		$notifications = $repository->findForUser($user);

		// Opening the page is what acknowledges them; the ones just listed
		// keep their unread look for this render.
		$repository->markAllAsRead($user, new \DateTime());

		return $this->render('pages/user/notifications.html.twig', [
				'user'          => $user,
				'notifications' => $notifications,
		]);
	}

	/**
	 * @Route("/user/discussions", name="user_discussions")
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
	 */
	public function userDiscussions(
		EntityManagerInterface $manager
	) {
		$this->denyAccessUnlessGranted(UserVoter::LOGGED);

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		$discussions = $manager->getRepository(Discussion::class)
							   ->findByParticipant($user);

		return $this->render('pages/user/my-discussions.html.twig', [
				'user'        => $user,
				'discussions' => $discussions,
		]);
	}

	/**
	 * @Route("/user/delete", name="user_delete")
	 *
	 * @param EntityManagerInterface $manager
	 * @param UserAnonymize          $userAnonymize
	 * @return RedirectResponse
	 */
	public function userDelete(
		EntityManagerInterface $manager,
		UserAnonymize $userAnonymize
	) {
		if (!$this->isGranted(UserVoter::LOGGED)) {
			return $this->redirectToRoute('user_login');
		}

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		$userAnonymize->anonymize($user);
		$manager->flush();

		$this->addFlash('notice', 'messages.user.account_deleted');

		return $this->redirectToRoute('user_logout');
	}
}
