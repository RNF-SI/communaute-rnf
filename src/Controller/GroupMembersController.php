<?php

namespace App\Controller;

use App\Entity\LogEvent;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Security\GroupVoter;
use App\Security\UserVoter;
use App\Service\EmailSender;
use App\Service\UsergroupMembersManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GroupMembersController extends AbstractController {
	/**
	 * The names that can be mentioned in a message of this group, for the
	 * editor to propose after an « @ ». (#37)
	 *
	 * Reserved to those who may write in the group: the mention must not turn
	 * into a way of finding out who is a member of a group one cannot read.
	 *
	 * @Route("/groups/{groupSlug}/mentions", name="group_mentions", methods={"GET"})
	 *
	 * @param                                      $groupSlug
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function groupMentions ( $groupSlug, EntityManagerInterface $manager ) {
		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\Usergroup $group
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupVoter::PARTICIPATE, $group );

		$names = [];

		/**
		 * @var \App\Entity\UsergroupMembership $membership
		 */
		foreach ( $group->getMembers() as $membership ) {
			if ( $membership->getStatus() !== UsergroupMembership::STATUS_MEMBER ) {
				continue;
			}

			$member = $membership->getUser();

			if ( !$member || ( $member->getStatus() !== User::STATUS_ACTIVE ) ) {
				continue;
			}

			$name = trim( (string) $member->getName() );

			// A nameless account cannot be named. Nothing to propose.
			if ( $name === '' ) {
				continue;
			}

			$names[ $member->getId() ] = [
					'id'   => $member->getId(),
					'name' => $name,
			];
		}

		usort( $names, function ( $left, $right ) {
			return strcmp( mb_strtolower( $left[ 'name' ] ), mb_strtolower( $right[ 'name' ] ) );
		} );

		return $this->json( $names );
	}

	/**
	 * @Route("/groups/{groupSlug}/members", name="group_members_index")
	 * @param                                            $groupSlug
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\UsergroupMembersManager       $usergroupMembersManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function groupMembers (
			$groupSlug,
			Request $request,
			EntityManagerInterface $manager,
			UsergroupMembersManager $usergroupMembersManager
	) {
		if ( !$this->isGranted( UserVoter::LOGGED ) ) {
			$this->addFlash( 'notice', 'messages.user.login_requested' );
		}

		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		/**
		 * @var \App\Entity\Usergroup $group
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		if ( !$this->isGranted( GroupVoter::READ, $group ) ) {
			$this->addFlash( 'notice', 'messages.group.access_denied' );

			return $this->redirectToRoute( 'group_index', [ 'groupSlug' => $groupSlug ] );
		}

		$this->denyAccessUnlessGranted( GroupVoter::READ, $group );

		$page     = $request->query->get( 'page', 0 );
		$per_page = 20;

		$filters = $request->query->get( 'form', [] );

		if ( $this->isGranted( GroupVoter::ADMIN, $group ) ) {
			$dataFilters = array_merge( $filters, [
					'group'  => $group,
					'status' => UsergroupMembership::STATUS_ALL,
			] );
		}
		else {
			$dataFilters = array_merge( $filters, [
					'group' => $group,
			] );
		}

		$data = $usergroupMembersManager->getFormAndMembers(
				$dataFilters,
				[ 'page' => $page, 'per_page' => $per_page ]
		);

		foreach ( $filters as $key => $value ) {
			if ( $value == '' || $value == [] ) {
				unset( $filters[ $key ] );
			}
		}
		unset( $filters[ '_token' ] );
		unset( $filters[ 'submit' ] );

		return $this->render( 'pages/member/members-index.html.twig', [
				'group'   => $group,
				'form'    => $data[ 'form' ]->createView(),
				'members' => $data[ 'members' ],
				'all_members' => $data[ 'all_members' ],
				'pager'   => [
						'base_url' => $request->getPathInfo() . '?' . http_build_query( [ 'form' => $filters ] ) . '&',
						'page'     => $page,
						'last'     => ceil( $data[ 'total' ] / $per_page ) - 1,
				],
		] );
	}

	/**
	 * @Route("/groups/{groupSlug}/members/new", name="group_member_new")
	 * @param                                            $groupSlug
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\EmailSender                   $mailer
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function groupMemberNew (
			$groupSlug,
			EntityManagerInterface $manager,
			EmailSender $mailer,
			LoggerInterface $logger
	) {
		if ( !$this->isGranted( UserVoter::LOGGED ) ) {
			$this->addFlash( 'notice', 'messages.user.login_requested' );
		}

		$this->denyAccessUnlessGranted( UserVoter::LOGGED );

		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		/**
		 * @var User $user
		 */
		$user = $this->getUser();

		$membership = $manager->getRepository( UsergroupMembership::class )
							  ->getMembership( $user, $group );

		if ( !empty( $membership ) ) {
			if ( $membership->getStatus() === UsergroupMembership::STATUS_BANNED ) {
				$this->addFlash( 'warning', 'messages.group.user_banned' );
			}

			return $this->redirectToRoute( 'group_index', [ 'groupSlug' => $groupSlug ] );
		}

		$membership = new UsergroupMembership();
		$membership->setUsergroup( $group );
		$membership->setUser( $user );
		$membership->setRole( UsergroupMembership::ROLE_USER );
		$membership->setJoinedAt( new DateTime() );

		if ( $this->isGranted( GroupVoter::JOIN, $group ) ) {
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );

			// Log Event

			$log = new LogEvent();
			$log->setType( LogEvent::USER_JOIN );
			$log->setUser( $this->getUser() );
			$log->setUsergroup( $group );
			$log->setCreatedAt( new DateTime() );
			$manager->persist( $log );

			// --

			$this->addFlash( 'notice', 'messages.group.joined' );
		}
		else {
			$membership->setStatus( UsergroupMembership::STATUS_PENDING );

			$this->addFlash( 'notice', 'messages.group.candidature_sent' );
		}

		// The request is recorded before anything is sent. A misconfigured or
		// unreachable mail service must not make the membership disappear. (#4)
		$manager->persist( $membership );
		$manager->flush();

		if ( $membership->getStatus() === UsergroupMembership::STATUS_PENDING ) {
			$this->notifyPendingRequest( $manager, $mailer, $logger, $group, $user );
		}

		return $this->redirectToRoute( 'group_index', [ 'groupSlug' => $groupSlug ] );
	}

	/**
	 * Warns whoever can approve a pending request. Failing to send must never
	 * break the request itself, so any error is logged and swallowed.
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param \App\Service\EmailSender             $mailer
	 * @param \Psr\Log\LoggerInterface             $logger
	 * @param \App\Entity\Usergroup                $group
	 * @param \App\Entity\User                     $user
	 */
	private function notifyPendingRequest (
			EntityManagerInterface $manager,
			EmailSender $mailer,
			LoggerInterface $logger,
			Usergroup $group,
			User $user
	) {
		$recipients = [];

		foreach ( $group->getMembersByRole( UsergroupMembership::ROLE_ADMIN ) as $adminMembership ) {
			if ( $adminMembership->getStatus() === UsergroupMembership::STATUS_MEMBER ) {
				$recipients[] = $adminMembership->getUser();
			}
		}

		// Nobody administers this group: the request would sit unseen forever.
		if ( empty( $recipients ) ) {
			$recipients = $manager->getRepository( User::class )->findSiteAdmins();

			$logger->warning( 'Group {group} has no administrator, falling back on the site administrators', [
					'group' => $group->getSlug(),
			] );
		}

		$multiple = count( $recipients ) > 1;

		foreach ( $recipients as $admin ) {
			try {
				$message = $this->renderView(
						'emails/group-join-request.html.twig',
						[
								'admin'     => $admin,
								'user'      => $user,
								'usergroup' => $group,
								'url'       => $this->generateUrl( 'group_members_index', [ 'groupSlug' => $group->getSlug() ], UrlGeneratorInterface::ABSOLUTE_URL ),
								'multiple'  => $multiple,
						]
				);

				$mailer->send(
						[ $this->getParameter( 'plateform' )[ 'from' ] => $this->getParameter( 'plateform' )[ 'name' ] ],
						$admin->getEmail(),
						$mailer->getSubjectFromTitle( $message ),
						$message
				);
			}
			catch ( Throwable $e ) {
				$logger->error( 'Could not warn {admin} of a request to join {group}: {error}', [
						'admin' => $admin->getId(),
						'group' => $group->getSlug(),
						'error' => $e->getMessage(),
				] );
			}
		}
	}

	/**
	 * Demander à devenir animateur du groupe, ou retirer sa demande. (#42)
	 *
	 * Le retour de recette venait de quelqu'un qui aurait dû animer un groupe
	 * et ne le pouvait pas : rien ne disait à qui s'adresser. La demande est
	 * posée sur l'adhésion, et les animateurs en sont prévenus par e-mail —
	 * les administrateurs de la plateforme si le groupe n'en a aucun, comme
	 * pour une demande d'adhésion.
	 *
	 * @Route("/groups/{groupSlug}/animator-request", name="group_animator_request", methods={"POST"})
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function groupAnimatorRequest (
			$groupSlug,
			Request $request,
			EntityManagerInterface $manager,
			EmailSender $mailer,
			LoggerInterface $logger
	) {
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupVoter::PARTICIPATE, $group );

		if ( !$this->isCsrfTokenValid( 'animator-request-' . $group->getId(), $request->request->get( '_token' ) ) ) {
			throw $this->createAccessDeniedException( 'Invalid token' );
		}

		/**
		 * @var \App\Entity\User $user
		 */
		$user       = $this->getUser();
		$membership = $manager->getRepository( UsergroupMembership::class )
							  ->getMembership( $user, $group );

		// Seul un membre ordinaire a quelque chose à demander.
		if ( empty( $membership )
			 || ( $membership->getStatus() !== UsergroupMembership::STATUS_MEMBER )
			 || ( $membership->getRole() === UsergroupMembership::ROLE_ADMIN ) ) {
			throw $this->createAccessDeniedException( 'Nothing to request' );
		}

		if ( $request->request->getBoolean( 'cancel' ) ) {
			$membership->setAnimatorRequestedAt( NULL );
			$manager->flush();

			$this->addFlash( 'notice', 'messages.group.animator_request_cancelled' );
		}
		elseif ( !$membership->hasAnimatorRequest() ) {
			$membership->setAnimatorRequestedAt( new DateTime() );
			$manager->flush();

			$this->notifyAnimatorRequest( $manager, $mailer, $logger, $group, $user );

			$this->addFlash( 'notice', 'messages.group.animator_request_sent' );
		}

		return $this->redirectToRoute( 'group_members_index', [ 'groupSlug' => $group->getSlug() ] );
	}

	/**
	 * Accepter ou décliner une demande à devenir animateur. (#42)
	 *
	 * @Route(
	 *     "/groups/{groupSlug}/animator-request/{userId}/{decision}",
	 *     name="group_animator_decide",
	 *     methods={"POST"},
	 *     requirements={"userId"="\d+", "decision"="accept|decline"}
	 * )
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function groupAnimatorDecide (
			$groupSlug,
			$userId,
			$decision,
			Request $request,
			EntityManagerInterface $manager
	) {
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupVoter::ADMIN, $group );

		if ( !$this->isCsrfTokenValid( 'animator-decide-' . $group->getId(), $request->request->get( '_token' ) ) ) {
			throw $this->createAccessDeniedException( 'Invalid token' );
		}

		$user       = $manager->getRepository( User::class )->find( $userId );
		$membership = $user
				? $manager->getRepository( UsergroupMembership::class )->getMembership( $user, $group )
				: NULL;

		if ( empty( $membership ) || !$membership->hasAnimatorRequest() ) {
			$this->addFlash( 'notice', 'messages.group.animator_request_gone' );

			return $this->redirectToRoute( 'group_members_index', [ 'groupSlug' => $group->getSlug() ] );
		}

		$membership->setAnimatorRequestedAt( NULL );

		if ( $decision === 'accept' ) {
			$membership->setRole( UsergroupMembership::ROLE_ADMIN );

			$log = new LogEvent();
			$log->setType( LogEvent::USER_ADMIN );
			$log->setUser( $user );
			$log->setUsergroup( $group );
			$log->setCreatedAt( new DateTime() );
			$log->setData( [ 'admin' => $this->getUser()->getId() ] );
			$manager->persist( $log );

			$this->addFlash( 'notice', 'messages.group.user_set_admin' );
		}
		else {
			$this->addFlash( 'notice', 'messages.group.animator_request_declined' );
		}

		$manager->flush();

		return $this->redirectToRoute( 'group_members_index', [ 'groupSlug' => $group->getSlug() ] );
	}

	/**
	 * Prévient les animateurs d'une demande à rejoindre leurs rangs. Comme
	 * pour une demande d'adhésion, un envoi qui échoue n'annule pas la
	 * demande : il est journalisé. (#42)
	 */
	private function notifyAnimatorRequest (
			EntityManagerInterface $manager,
			EmailSender $mailer,
			LoggerInterface $logger,
			Usergroup $group,
			User $user
	) {
		$recipients = [];

		foreach ( $group->getMembersByRole( UsergroupMembership::ROLE_ADMIN ) as $adminMembership ) {
			if ( $adminMembership->getStatus() === UsergroupMembership::STATUS_MEMBER ) {
				$recipients[] = $adminMembership->getUser();
			}
		}

		if ( empty( $recipients ) ) {
			$recipients = $manager->getRepository( User::class )->findSiteAdmins();
		}

		foreach ( $recipients as $admin ) {
			try {
				$message = $this->renderView( 'emails/group-animator-request.html.twig', [
						'admin'     => $admin,
						'user'      => $user,
						'usergroup' => $group,
						'url'       => $this->generateUrl( 'group_members_index', [ 'groupSlug' => $group->getSlug() ], UrlGeneratorInterface::ABSOLUTE_URL ),
						'multiple'  => count( $recipients ) > 1,
				] );

				$mailer->send(
						[ $this->getParameter( 'plateform' )[ 'from' ] => $this->getParameter( 'plateform' )[ 'name' ] ],
						$admin->getEmail(),
						$mailer->getSubjectFromTitle( $message ),
						$message
				);
			}
			catch ( Throwable $e ) {
				$logger->error( 'Could not warn {admin} of a request to animate {group}: {error}', [
						'admin' => $admin->getId(),
						'group' => $group->getSlug(),
						'error' => $e->getMessage(),
				] );
			}
		}
	}

	/**
	 * @Route("/groups/{groupSlug}/members/{userId}/admin/{status}", name="group_member_admin")
	 * @param                                            $groupSlug
	 * @param                                            $userId
	 * @param                                            $status
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 * @throws \Exception
	 */
	public function groupMemberJoin (
			$groupSlug,
			$userId,
			$status,
			EntityManagerInterface $manager
	) {
		/**
		 * @var \App\Entity\Usergroup $group
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupVoter::ADMIN, $group );

		$user = $manager->getRepository( User::class )
						->findOneBy( [ 'id' => $userId ] );

		if ( !$user ) {
			throw $this->createNotFoundException( 'The user does not exist' );
		}

		if ( $user->getStatus() !== User::STATUS_ACTIVE ) {
			$this->addFlash( 'error', 'messages.user.inactive' );

			return $this->redirectToRoute( 'group_members_index', [ 'groupSlug' => $groupSlug ] );
		}

		$membership = $manager->getRepository( UsergroupMembership::class )
							  ->getMembership( $user, $group );

		switch ( $status ) {
			case UsergroupMembership::STATUS_MEMBER:
				if ( empty( $membership ) ) {
					$membership = new UsergroupMembership();
					$membership->setUsergroup( $group );
					$membership->setUser( $user );

					$manager->persist( $membership );
				}

				$membership->setJoinedAt( new DateTime() );
				$membership->setStatus( UsergroupMembership::STATUS_MEMBER );

				$this->addFlash( 'notice', 'messages.group.user_set_member' );

				// Log Event

				$log = new LogEvent();
				$log->setType( LogEvent::USER_JOIN );
				$log->setUser( $membership->getUser() );
				$log->setUsergroup( $group );
				$log->setCreatedAt( new DateTime() );
				$log->setData( [ 'admin' => $this->getUser()->getId() ] );
				$manager->persist( $log );

				// --
				break;

			case 'remove':
				if ( !empty( $membership ) ) {
					$manager->remove( $membership );

					$this->addFlash( 'notice', 'messages.group.user_set_remove' );
				}
				break;

			case UsergroupMembership::STATUS_BANNED:
				if ( !empty( $membership ) ) {
					$membership->setStatus( UsergroupMembership::STATUS_BANNED );

					$this->addFlash( 'notice', 'messages.group.user_set_banned' );
				}
				break;

			case UsergroupMembership::ROLE_ADMIN:
				if ( !empty( $membership ) ) {
					$membership->setRole( UsergroupMembership::ROLE_ADMIN );
					$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
					// Nommé par un autre chemin : la demande est satisfaite. (#42)
					$membership->setAnimatorRequestedAt( NULL );

					$this->addFlash( 'notice', 'messages.group.user_set_admin' );

					// Log Event

					$log = new LogEvent();
					$log->setType( LogEvent::USER_ADMIN );
					$log->setUser( $membership->getUser() );
					$log->setUsergroup( $group );
					$log->setCreatedAt( new DateTime() );
					$log->setData( [ 'admin' => $this->getUser()->getId() ] );
					$manager->persist( $log );
					// --
				}
				break;

			case UsergroupMembership::ROLE_USER:
				if ( !empty( $membership ) ) {
					$membership->setRole( UsergroupMembership::ROLE_USER );
					$membership->setStatus( UsergroupMembership::STATUS_MEMBER );

					$this->addFlash( 'notice', 'messages.group.user_set_user' );
				}
				break;
		}

		$manager->flush();

		return $this->redirectToRoute( 'group_members_index', [ 'groupSlug' => $groupSlug ] );
	}

	/**
	 * @Route("/groups/{groupSlug}/quit", name="group_member_quit")
	 * @param                                            $groupSlug
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
	 */
	public function groupMemberQuit (
			$groupSlug,
			Request $request,
			EntityManagerInterface $manager
	) {
		/**
		 * @var \App\Entity\Usergroup $group
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		$user = $this->getUser();

		if ( !$user ) {
			throw $this->createNotFoundException( 'The user does not exist' );
		}

		$membership = $manager->getRepository( UsergroupMembership::class )
							  ->getMembership( $user, $group );

		// Confirmation form

		$form = $this->createFormBuilder()
					 ->add( 'submit', SubmitType::class )
					 ->getForm();

		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			if ( !empty( $membership ) ) {
				$manager->remove( $membership );
				$manager->flush();

				$this->addFlash( 'notice', 'messages.group.quit' );

				return $this->redirectToRoute( 'group_index', [ 'groupSlug' => $groupSlug ] );
			}
		}

		return $this->render( 'pages/confirm.html.twig', [
				'form' => $form->createView(),
		] );
	}
}
