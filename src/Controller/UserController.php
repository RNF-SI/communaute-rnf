<?php

namespace App\Controller;

use App\Entity\File;
use App\Entity\LogEvent;
use App\Entity\Site;
use App\Entity\User;
use App\Entity\UsergroupMembership;
use App\Form\UserProfileType;
use App\Security\UserVoter;
use App\Service\Community;
use App\Service\EmailSender;
use App\Service\FileManager;
use App\Service\UserAnonymize;
use App\Util\Geocoder;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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
			// Site
			$siteName = trim($form->get('siteName')->getData());
			if (!empty($siteName)) {
				$site = $manager->getRepository(Site::class)->findOneBy(['name' => $siteName]);
				if (!$site) {
					$site = new Site();
					$site->setName($siteName);

					$manager->persist($site);
				}
				$user->setSite($site);
			} else {
				$user->setSite(NULL);
			}

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
		} else {
			$site = $user->getSite();
			if ($site) {
				$form->get('siteName')->setData($site->getName());
			}
		}

		return $this->render('pages/user/profile-edit.html.twig', ['form' => $form->createView(), 'user' => $user]);
	}

	/**
	 * @Route("/user/parameters/edit", name="user_parameters_edit")
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function parametersEdit()
	{
		$this->denyAccessUnlessGranted(UserVoter::LOGGED);

		return $this->render('pages/user/parameters-edit.html.twig');
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
