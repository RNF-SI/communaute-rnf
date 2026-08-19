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
use App\Notification\NotificationRhythm;
use App\Form\UserProfileType;
use App\Security\UserVoter;
use App\Service\Community;
use App\Service\EmailSender;
use App\Service\FileManager;
use App\Service\HashGenerator;
use App\Service\UserAnonymize;
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
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function loginPage()
	{
		// Redirect to RNF authentication
		return $this->redirectToRoute('rnf_auth_login');
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

			if (!empty($settings['discussionRhythm'])) {
				$user->setDiscussionEmailRhythm($settings['discussionRhythm']);
			}

			foreach ($user->getUsergroupMemberships() as $membership) {
				$groupId = $membership->getUsergroup() ? $membership->getUsergroup()->getId() : NULL;

				if (!$groupId || empty($settings['groups'][$groupId])) {
					continue;
				}

				foreach ($settings['groups'][$groupId] as $category => $level) {
					$membership->setNotificationLevel($category, $level);
				}
			}

			$manager->flush();

			$this->addFlash('notice', 'messages.user.notifications_updated');

			return $this->redirectToRoute('user_parameters_edit');
		}

		return $this->render('pages/user/parameters-edit.html.twig', [
				'user'       => $user,
				'categories' => NotificationCategory::all(),
				'levels'     => NotificationLevel::all(),
				'rhythms'    => NotificationRhythm::all(),
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
