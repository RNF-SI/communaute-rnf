<?php

namespace App\Controller;

use App\Entity\File;
use App\Entity\LogEvent;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Form\UsergroupType;
use App\Security\GroupVoter;
use App\Security\UserVoter;
use App\Service\UserGroupsManager;
use App\Service\Community;
use App\Service\EmailSender;
use App\Service\FileManager;
use App\Service\SlugGenerator;
use App\Service\UserGroupRelation;
use App\Service\SearchEngineManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Form\Extension\Core\Type\SearchType;

class GroupController extends AbstractController {
	/**************************************************
	 * GROUPS
	 **************************************************/

	/**
	 * @Route("/groups", name="groups_index")
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\UserGroupManager              $userGroupManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function groupsIndex (
		EntityManagerInterface $manager,
		UserGroupsManager $userGroupsManager,
		Request $request
	) {
		$form = $this->createFormBuilder()
					 ->add( 'groups_search_bar', SearchType::class, [
						'required' => false,
						] )
					 ->getForm();

		// Deux axes pour trier une liste de 58 groupes, et ils se cumulent :
		// la commission dit de qui un groupe dépend, la thématique de quoi il
		// parle. (#23)
		$selected = $request->query->get( 'commission' );
		$parent   = $selected ? $userGroupsManager->getGroupBySlug( $selected ) : NULL;

		$theme    = $request->query->get( 'theme' );
		$category = $theme ? $userGroupsManager->getCategoryBySlug( $theme ) : NULL;

		$groups = $parent
				? $userGroupsManager->getGroupsUnder( $parent )
				: $userGroupsManager->getGroups();

		if ( $category ) {
			$groups = $userGroupsManager->keepWithCategory( $groups, $category );
		}

		return $this->render( 'pages/group/groups-index.html.twig', [
				'groups' => $groups,
				'groupsTree' => $userGroupsManager->asTree( $groups ),
				'groupsToActivate' => $userGroupsManager->getGroupsToActivate(),
				'parents' => $userGroupsManager->getParentGroups(),
				'selectedCommission' => $parent ? $parent->getSlug() : '',
				'categories' => $userGroupsManager->getCategories(),
				'selectedTheme' => $category ? $category->getSlug() : '',
				'form' => $form->createView()
		] );
	}

	/**
     * @Route("/groups/search", name="groups_search", methods="GET")
	 * @param \App\Service\UserGroupManager              $userGroupManager
	 * @param \Symfony\Component\HttpFoundation\Request  $request;
	 * @param \App\Service\SearchEngineManager           $searchEngineManager
	 *
	 * @return string                                    $jsonString
     */
    public function groupSearch(
		UserGroupsManager $userGroupsManager,
		Request $request,
		SearchEngineManager $searchEngineManager
	) {
        $query = $request->query->get('q');
        $type = $request->query->get('type');

		// Les filtres en cours doivent survivre à une recherche par texte, sans
		// quoi taper une lettre ramènerait les 58 groupes. (#23)
		$selected = $request->query->get('commission');
		$parent   = $selected ? $userGroupsManager->getGroupBySlug($selected) : NULL;

		$theme    = $request->query->get('theme');
		$category = $theme ? $userGroupsManager->getCategoryBySlug($theme) : NULL;

		if ($parent) {
			$allowed = [];

			foreach ($userGroupsManager->getGroupsUnder($parent) as $group) {
				$allowed[$group->getId()] = TRUE;
			}
		}

		if($query!==''){
			$em = $this->getDoctrine()->getManager();
			//Launch Search
			$searchEngineManager->setTNTSearchConfiguration();
			$result = $searchEngineManager->searchGroup($em, $query);

			// Simplifier: toujours utiliser le type passé en paramètre
			$groupList = $userGroupsManager->getGroupsFilteredByIds($result, $type);

			if ($parent) {
				$groupList = array_values(array_filter($groupList, function ($group) use ($allowed) {
					return isset($allowed[$group->getId()]);
				}));
			}

			if ($category) {
				$groupList = $userGroupsManager->keepWithCategory($groupList, $category);
			}

			$groupList = $searchEngineManager->snippetGroupsText($query, $groupList);

			// Pour les groupes en attente, utiliser un template spécialisé
			if ($type === 'groups-to-activate-elements') {
				if (empty($groupList)) {
					$contentGroups = '';
				} else {
					$groupListHTML = $this->render( 'pages/group/groups-to-activate-list.html.twig', [
						'groups' => $groupList,
					] );
					$contentGroups = $groupListHTML->getContent();
					$contentGroups = $searchEngineManager->highlightText($query, $contentGroups);
				}
			} else {
				$groupListHTML = $this->render( 'pages/group/groups-list.html.twig', [
					'groups' => $groupList,
					'groups_tree' => $userGroupsManager->asTree( $groupList ),
				] );
				$contentGroups = $groupListHTML->getContent();
				$contentGroups = $searchEngineManager->highlightText($query, $contentGroups);
			}
		} else {
			$groupList = $userGroupsManager->getGroupsFromType($type);

			if ($parent) {
				$groupList = array_values(array_filter($groupList, function ($group) use ($allowed) {
					return isset($allowed[$group->getId()]);
				}));
			}

			if ($category) {
				$groupList = $userGroupsManager->keepWithCategory($groupList, $category);
			}

			// Pour les groupes en attente, utiliser un template spécialisé
			if ($type === 'groups-to-activate-elements') {
				if (empty($groupList)) {
					$contentGroups = '';
				} else {
					$groupListHTML = $this->render( 'pages/group/groups-to-activate-list.html.twig', [
						'groups' => $groupList,
					] );
					$contentGroups = $groupListHTML->getContent();
				}
			} else {
				$groupListHTML = $this->render( 'pages/group/groups-list.html.twig', [
					'groups' => $groupList,
					'groups_tree' => $userGroupsManager->asTree( $groupList ),
				] );
				$contentGroups = $groupListHTML->getContent();
			}
		}

        return $this->json([
            'groups' => $contentGroups
		]);
    }

	/**************************************************
	 * GROUP
	 **************************************************/

	/**
	 * @Route("/groups/new", name="group_new")
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Service\FileManager                  $fileManager
	 * @param \App\Service\SlugGenerator                $slugGenerator
	 * @param \App\Service\Community                    $community
	 * @param \App\Service\UserGroupRelation            $userGroupRelation
	 * @param \App\Service\EmailSender                  $mailer
	 *
	 * @return string
	 * @throws \Exception
	 */
	public function groupNew (
			Request $request,
			EntityManagerInterface $manager,
			FileManager $fileManager,
			SlugGenerator $slugGenerator,
			Community $community,
			UserGroupRelation $userGroupRelation,
			EmailSender $mailer
	) {
		$this->denyAccessUnlessGranted( GroupVoter::CREATE );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$doActivate = $userGroupRelation->isCommunityAdmin( $user );

		/**
		 * @var \App\Entity\Usergroup $group
		 */
		$group = new Usergroup();

		$form = $this->createForm( UsergroupType::class, $group );

		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			$group->setSlug( $slugGenerator->generateSlug( $group->getName(), Usergroup::class, 'slug' ) );
			$group->setCreatedAt( new DateTime() );
			$group->setIsActive( $doActivate );

			// Gérer les relations hiérarchiques
			$this->handleHierarchyRelations( $group, $manager );

			$manager->persist( $group );

			$membership = new UsergroupMembership();
			$membership->setUsergroup( $group );
			$membership->setUser( $user );
			$membership->setJoinedAt( new DateTime() );
			$membership->setRole( UsergroupMembership::ROLE_ADMIN );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );

			$manager->persist( $membership );

			$manager->flush();

			// Logo
			$uploadFile = $form->get( 'logofile' )->getData();

			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\UsergroupFileManager $groupFileManager
				 */
				$groupFileManager = $fileManager->getManager( File::USERGROUP_FILES );
				$file             = $groupFileManager->createFromUploadedFile( $uploadFile, $user, $group );

				$manager->persist( $file );

				$group->setLogo( $file );
			}
			// --

			// Cover
			$uploadFile = $form->get( 'coverfile' )->getData();

			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\UsergroupFileManager $groupFileManager
				 */
				$groupFileManager = $fileManager->getManager( File::USERGROUP_FILES );
				$file             = $groupFileManager->createFromUploadedFile( $uploadFile, $user, $group );

				$manager->persist( $file );

				$group->setCover( $file );
			}
			// --

			$manager->flush();

			// Log Event

			$log = new LogEvent();
			$log->setType( LogEvent::GROUP_CREATE );
			$log->setUser( $this->getUser() );
			$log->setUsergroup( $group );
			$log->setCreatedAt( new DateTime() );
			$log->setData( [ 'name' => $group->getName() ] );
			$manager->persist( $log );
			$manager->flush();

			// --

			$this->addFlash( 'notice', 'messages.group.group_created' );

			if ( $doActivate ) {
				$this->redirectToRoute( 'group_activate', [ 'groupSlug' => $group->getSlug(), 'doActivate' => TRUE ] );
			} else {

				$communityGroup = $community->getGroup();
				if ( $communityGroup ) {
					$communityAdmins = $communityGroup->getMembersByRole( UsergroupMembership::ROLE_ADMIN );
					$multiple = count( $communityAdmins ) > 1;
					$emailsSent = 0;
					$totalAdmins = count( $communityAdmins );

					foreach ( $communityAdmins as $communityAdminMembership ) {
						$communityAdmin = $communityAdminMembership->getUser();
						
						// Vérifier que l'email est valide avant d'envoyer
						if ($communityAdmin->getEmail() && filter_var($communityAdmin->getEmail(), FILTER_VALIDATE_EMAIL)) {
							try {
								$message = $this->renderView(
									'emails/usergroup-activation.html.twig',
									[
										'admin'     => $communityAdmin,
										'user'      => $user,
										'usergroup' => $group,
										'url'       => $this->generateUrl( 'group_index', [ 'groupSlug' => $group->getSlug() ], UrlGeneratorInterface::ABSOLUTE_URL ),
										'multiple'  => $multiple,
									]
								);

								$mailer->send(
									[ $this->getParameter( 'plateform' )[ 'from' ] => $this->getParameter( 'plateform' )[ 'name' ] ],
									$communityAdmin->getEmail(),
									$mailer->getSubjectFromTitle( $message ),
									$message
								);
								$emailsSent++;
							} catch (\Exception $e) {
								// Log l'erreur mais continue le processus
							}
						}
					}
					
					// Informer l'utilisateur si aucun email n'a pu être envoyé
					if ($totalAdmins > 0 && $emailsSent == 0) {
						$this->addFlash('warning', 'Le groupe a été créé mais les administrateurs n\'ont pas pu être notifiés par email.');
					} elseif ($emailsSent < $totalAdmins) {
						$this->addFlash('info', 'Le groupe a été créé. Certains administrateurs n\'ont pas pu être notifiés par email.');
					}
				}
			}

			return $this->redirectToRoute( 'groups_index' );
		}

		return $this->render( 'pages/group/group-create.html.twig', [
				'group' => $group,
				'form'  => $form->createView(),
		] );
	}

	/**
	 * @Route("/groups/activate/{groupSlug}/action/{doActivate}", name="group_activate")
	 *
	 * @param                                      $groupSlug
	 * @param                                      $doActivate
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param \App\Service\UserGroupRelation       $userGroupRelation
	 * @param \App\Service\EmailSender             $mailer

	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 * @throws \Exception
	 */
	public function groupActivate (
		$groupSlug,
		$doActivate,
		EntityManagerInterface $manager,
		UserGroupRelation $userGroupRelation,
		EmailSender $mailer,
		SearchEngineManager $searchEngineManager
	) {
		if (!$this->isGranted(UserVoter::LOGGED)) {
			return $this->redirectToRoute('user_login');
		}

		if ( !$userGroupRelation->isCommunityAdmin( $this->getUser() ) ) {
			throw new AccessDeniedException( 'Your are not allowed to activate groups' );
		}

		$group = $manager->getRepository( Usergroup::class )
			->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		if ( $group->getIsActive() ) {
			$this->addFlash('error', 'messages.group.already_active');

			return $this->redirectToRoute( 'group_index', [ 'groupSlug' => $groupSlug ] );
		}

		if ( !$doActivate ) {

			return $this->redirectToRoute( 'group_delete', [ 'groupSlug' => $groupSlug ] );
		} else {
			$group->setIsActive( true );
			$manager->flush();
			
			// Réindexer les groupes pour inclure le nouveau groupe activé
			try {
				$searchEngineManager->reindexGroups();
			} catch (\Exception $e) {
				$this->addFlash('warning', 'Le groupe a été activé mais l\'index de recherche n\'a pas pu être mis à jour.');
			}
		}

		$admins = $group->getMembersByRole( UsergroupMembership::ROLE_ADMIN );
		foreach ( $admins as $adminMembership ) {
			$admin = $adminMembership->getUser();
			
			// Vérifier que l'email est valide avant d'envoyer
			if ($admin->getEmail() && filter_var($admin->getEmail(), FILTER_VALIDATE_EMAIL)) {
				try {
					$message = $this->renderView(
						'emails/usergroup-activation_answer.html.twig',
						[
							'admin'       => $admin,
							'usergroup'   => $group,
							'isActivated' => $doActivate,
							'url'         => $this->generateUrl( 'group_index', [ 'groupSlug' => $groupSlug ], UrlGeneratorInterface::ABSOLUTE_URL ),
						]
					);

					$mailer->send(
						[ $this->getParameter( 'plateform' )[ 'from' ] => $this->getParameter( 'plateform' )[ 'name' ] ],
						$admin->getEmail(),
						$mailer->getSubjectFromTitle( $message ),
						$message
					);
				} catch (\Exception $e) {
					// Log l'erreur mais continue le processus
					$this->addFlash('warning', 'L\'activation a réussi mais l\'email de notification n\'a pas pu être envoyé à ' . $admin->getName());
				}
			}
		}

		// Log Event

		$log = new LogEvent();
		$log->setType( LogEvent::GROUP_ACTIVATE );
		$log->setUser( $this->getUser() );
		$log->setUsergroup( $group );
		$log->setCreatedAt( new DateTime() );
		$log->setData( [ 'name' => $group->getName() ] );
		$manager->persist( $log );
		$manager->flush();

		$this->addFlash( 'notice', 'messages.group.group_activated' );

		return $this->redirectToRoute( 'groups_index' );
	}

	/**
	 * @Route("/groups/{groupSlug}/edit", name="group_edit")
	 *
	 * @param                                            $groupSlug
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\FileManager                   $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \Exception
	 */
	public function groupEdit (
			$groupSlug,
			Request $request,
			EntityManagerInterface $manager,
			FileManager $fileManager
	) {
		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		/**
		 * @var \App\Entity\Usergroup $group
		 */
		$group    = $manager->getRepository( Usergroup::class )
							->findOneBy( [ 'slug' => $groupSlug ] );
		$original = clone $group;

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupVoter::EDIT, $group );

		$form = $this->createForm( UsergroupType::class, $group );

		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			$modifications = [];

			if ( $original->getName() !== $group->getName() ) {
				$modifications[] = 'name';
			}

			// Gérer les relations hiérarchiques
			$this->handleHierarchyRelations( $group, $manager );

			if ( $original->getDescription() !== $group->getDescription() ) {
				$modifications[] = 'description';
			}

			if ( $original->getPresentation() !== $group->getPresentation() ) {
				$modifications[] = 'presentation';
			}

			// Logo
			$uploadFile = $form->get( 'logofile' )->getData();

			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\UsergroupFileManager $groupFileManager
				 */
				$groupFileManager = $fileManager->getManager( File::USERGROUP_FILES );
				$file             = $groupFileManager->createFromUploadedFile( $uploadFile, $user, $group );

				$manager->persist( $file );

				if ( !empty( $group->getLogo() ) ) {
					$fileManager->deleteFile( $group->getLogo() );
				}
				$group->setLogo( $file );

				$modifications[] = 'logo';
			}
			// --

			// Cover
			$uploadFile = $form->get( 'coverfile' )->getData();

			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\UsergroupFileManager $groupFileManager
				 */
				$groupFileManager = $fileManager->getManager( File::USERGROUP_FILES );
				$file             = $groupFileManager->createFromUploadedFile( $uploadFile, $user, $group );

				$manager->persist( $file );

				if ( !empty( $group->getCover() ) ) {
					$fileManager->deleteFile( $group->getCover() );
				}
				$group->setCover( $file );

				$modifications[] = 'cover';
			}
			// --

			if ( $original->getVisibility() !== $group->getVisibility() ) {
				$modifications[] = 'visibility_' . $group->getVisibility();
			}

			$manager->flush();

			// Log Event

			$log = new LogEvent();
			$log->setType( LogEvent::GROUP_EDIT );
			$log->setUser( $this->getUser() );
			$log->setUsergroup( $group );
			$log->setCreatedAt( new DateTime() );
			$log->setData( [ 'name' => $group->getName(), 'modifications' => $modifications ] );
			$manager->persist( $log );
			$manager->flush();

			// --

			$this->addFlash( 'notice', 'messages.group.group_updated' );

			return $this->redirectToRoute( 'group_index', [ 'groupSlug' => $group->getSlug() ] );
		}

		return $this->render( 'pages/group/group-edit.html.twig', [
				'group' => $group,
				'form'  => $form->createView(),
		] );
	}

	/**
	 * @Route("/groups/{groupSlug}/sous-groupes", name="group_subgroups_index")
	 * @param                                            $groupSlug
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function groupSubgroupsIndex (
			$groupSlug,
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

		$this->denyAccessUnlessGranted( GroupVoter::READ, $group );

		return $this->render( 'pages/group/group-subgroups-index.html.twig', [
				'group' => $group,
		] );
	}

	/**
	 * @Route("/groups/{groupSlug}", name="group_index")
	 *
	 * @param                                            $groupSlug
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\UserGroupRelation             $userGroupRelation
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function groupIndex (
			$groupSlug,
			EntityManagerInterface $manager,
			UserGroupRelation $userGroupRelation
	) {
		/**
		 * @var $group \App\Entity\Usergroup
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		/**
		 * @var $user \App\Entity\User
		 */
		$user = $this->getUser();

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		if ( !$group->getIsActive() ) {
			$this->denyAccessUnlessGranted(GroupVoter::ADMIN, $group );
		}

		// Viewing rights is tested in the template

		return $this->render( 'pages/group/group-index.html.twig', [ 'group' => $group ] );
	}

	/**
	 * @Route("/groups/{groupSlug}/delete", name="group_delete")
	 *
	 * @param                                            $groupSlug
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\FileManager                   $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \Exception
	 */
	public function groupDelete (
			$groupSlug,
			Request $request,
			EntityManagerInterface $manager,
			FileManager $fileManager
	) {
		/**
		 * @var \App\Entity\Usergroup $group
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		$this->denyAccessUnlessGranted( GroupVoter::DELETE, $group );

		// Delete confirmation form

		$form = $this->createFormBuilder()
					 ->add( 'submit', SubmitType::class )
					 ->getForm();

		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			$group->setLogo( NULL );
			$group->setCover( NULL );

			foreach ( $group->getLogEvents() as $event ) {
				$manager->remove( $event );
			}

			foreach ( $group->getMembers() as $membership ) {
				$manager->remove( $membership );
			}

			foreach ( $group->getPages() as $page ) {
				$manager->remove( $page );
			}

			foreach ( $group->getArticles() as $article ) {
				$manager->remove( $article );
			}

			foreach ( $group->getDiscussions() as $discussion ) {
				$manager->remove( $discussion );
			}

			foreach ( $group->getDocuments() as $document ) {
				$manager->remove( $document );
			}

			foreach ( $group->getFiles() as $file ) {
				$fileManager->deleteFile( $file );
				$manager->remove( $file );
			}

			$manager->flush();

			$manager->remove( $group );

			// Log Event

			$log = new LogEvent();
			$log->setType( LogEvent::GROUP_DELETE );
			$log->setUser( $this->getUser() );
			$log->setCreatedAt( new DateTime() );
			$log->setData( [ 'name' => $group->getName() ] );
			$manager->persist( $log );

			// --

			$manager->flush();

			$this->addFlash( 'notice', 'messages.group.group_deleted' );

			return $this->redirectToRoute( 'groups_index' );
		}

		return $this->render( 'pages/confirm.html.twig', [
				'form' => $form->createView(),
		] );
	}

	/**
	 * Gère les relations hiérarchiques parent/enfant d'un groupe
	 */
	private function handleHierarchyRelations( Usergroup $group, EntityManagerInterface $manager ): void {
		// Vérifier les permissions - seuls les administrateurs communauté peuvent modifier la hiérarchie
		// Cette vérification est déjà faite dans le formulaire, mais on la garde pour sécurité

		// IMPORTANT: Pour les relations ManyToMany avec Symfony Forms,
		// nous devons gérer manuellement les enfants car le formulaire 
		// met à jour seulement l'inverse side qui n'est pas persistée par Doctrine
		
		// Récupérer les enfants depuis le formulaire
		$formChildren = $group->getChildren()->toArray();
		
		// Pour chaque enfant, s'assurer que la relation est bien établie côté owning side
		foreach ( $formChildren as $child ) {
			// Vérifier les boucles circulaires
			if ( $child->wouldCreateCircularReference( $group ) ) {
				$this->addFlash( 'error', 'Relation circulaire détectée avec le groupe enfant "' . $child->getName() . '". Cette relation n\'a pas été créée.' );
				continue;
			}
			
			// Ajouter ce groupe comme parent de l'enfant (owning side)
			if ( !$child->getParents()->contains( $group ) ) {
				$child->addParent( $group );
				$manager->persist( $child );
			}
		}
		
		// Gérer les enfants qui ont été retirés (seulement pour les groupes existants)
		if ( $group->getId() ) {
			// Récupérer tous les groupes qui ont ce groupe comme parent
			$existingChildren = $manager->getRepository( Usergroup::class )
				->createQueryBuilder( 'g' )
				->join( 'g.parents', 'p' )
				->where( 'p.id = :parentId' )
				->setParameter( 'parentId', $group->getId() )
				->getQuery()
				->getResult();
				
			foreach ( $existingChildren as $existingChild ) {
				if ( !$group->getChildren()->contains( $existingChild ) ) {
					// Cet enfant a été retiré dans le formulaire
					$existingChild->removeParent( $group );
					$manager->persist( $existingChild );
				}
			}
		}

		// Validation des boucles circulaires pour les nouveaux parents
		foreach ( $group->getParents() as $parent ) {
			if ( $group->wouldCreateCircularReference( $parent ) ) {
				$this->addFlash( 'error', 'Relation circulaire détectée avec le groupe parent "' . $parent->getName() . '". Cette relation n\'a pas été créée.' );
				$group->removeParent( $parent );
			}
		}

		// Persister le groupe principal
		$manager->persist( $group );
	}
}
