<?php

namespace App\Controller;

use App\Entity\DocumentTag;
use App\Form\AdminPlatformType;
use App\Form\DocumentTagType;
use App\Form\AdminHomeType;
use App\Form\AdminGroupsType;
use App\Form\AdminMenusType;
use App\Entity\AppLink;
use App\Entity\AppLinkGroup;
use App\Entity\Usergroup;

use App\Service\AdminManager;
use App\Service\SlugGenerator;
use App\Security\GroupVoter;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


class AdminController extends AbstractController {
	/**************************************************
	 * EVENTS
	 **************************************************/

	/**
	 * @Route("/administration/platform", name="administration_platform")
	 * @param \Symfony\Component\HttpFoundation\Request                               $request
	 * @param \Doctrine\ORM\EntityManagerInterface                       $manager,
	 * @param \App\Service\FileManager                                   $fileManager
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function adminPlatformEdit (
		Request $request,
		EntityManagerInterface $manager,
		\App\Service\FileManager $fileManager
	) {
		$platformForm          = $this->createForm( AdminPlatformType::class);
		$platformForm->handleRequest( $request );

		if ( $platformForm->isSubmitted() && $platformForm->isValid() ) {

			// Logo
			$uploadFile = $platformForm->get( 'logofile' )->getData();

			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\AppFileManager $appFileManager
				 */
				$appFileManager = $fileManager->getManager( 'appfiles' );
				$newLogoFile = $appFileManager->changeWithUploadedFile( $uploadFile, 'logo');
				$manager->persist( $newLogoFile );
				$manager->flush();

				// Put the new logo id in admin config file
				$appFileManager->setAppImageId('platform', 'logo', $newLogoFile->getId());

			}

			return $this->redirectToRoute( 'administration_platform' );
		}

		$communauteGroup = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => 'communaute' ] );

		$this->denyAccessUnlessGranted(GroupVoter::ADMIN, $communauteGroup);

		return $this->render( 'pages/user/admin-edit.html.twig', [
			'tab' => 'platform',
			'form' => $platformForm->createView(),
		] );
	}


	/**
	 * @Route("/administration/home", name="administration_home")
	 * @param \Symfony\Component\HttpFoundation\Request                               $request
	 * @param \Doctrine\ORM\EntityManagerInterface                       $manager,
	 * @param \App\Service\FileManager                                   $fileManager
	 * @param \App\Service\AppTextManager                                $appTextManager
	 * @param \Symfony\Component\Routing\Generator\UrlGeneratorInterface $router

	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function adminHomeEdit (
		Request $request,
		EntityManagerInterface $manager,
		\App\Service\FileManager $fileManager,
		\App\Service\AppTextManager $appTextManager,
		UrlGeneratorInterface $router
	) {
		$texts = $appTextManager->getTabText('home');
		$homeForm          = $this->createForm( AdminHomeType::class, $texts);
		$homeForm->handleRequest( $request );

		if ( $homeForm->isSubmitted() && $homeForm->isValid() ) {

			// Front
			// Update all textuals info
			foreach ( $homeForm->getData() as $key => $value ) {
				$appTextManager->changeText('home', $key, $value);
			}

			// Update images info
			$uploadFile = $homeForm->get( 'frontfile' )->getData();
			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\AppFileManager $appFileManager
				 */
				$appFileManager = $fileManager->getManager( 'appfiles' );
				$newFrontFile = $appFileManager->changeWithUploadedFile( $uploadFile, 'front');
				$manager->persist( $newFrontFile );
				$manager->flush();

				// Put the new front id in admin config file
				$appFileManager->setAppImageId('home', 'front', $newFrontFile->getId());
			}


			return $this->redirectToRoute( 'administration_home' );
		}

		$communauteGroup = $manager->getRepository( Usergroup::class )
		->findOneBy( [ 'slug' => 'communaute' ] );

		$this->denyAccessUnlessGranted(GroupVoter::ADMIN, $communauteGroup);

		return $this->render( 'pages/user/admin-edit.html.twig', [
			'tab' => 'home',
			'form' => $homeForm->createView(),
			'upload'     => $router->generate( 'admin_file_upload', [ 'tab' => 'home' ] ),
		] );
	}

	/**
	 * @Route("/administration/groups", name="administration_groups")
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function adminGroupsEdit (
		Request $request,
		EntityManagerInterface $manager,
		\App\Service\FileManager $fileManager,
		\App\Service\SearchEngineManager $searchEngineManager
	) {
		// Get all usergroups and current important groups
		$usergroups = $manager->getRepository(\App\Entity\Usergroup::class)->findAll();
		$importantGroups = $manager->getRepository(\App\Entity\Usergroup::class)->findBy(['isImportant' => true]);
		
		$groupForm = $this->createForm( AdminGroupsType::class, null, [
			'important_groups' => $importantGroups
		]);
		$groupForm->handleRequest( $request );
		
		if ( $groupForm->isSubmitted() && $groupForm->isValid() ) {
			// Handle important groups update
			$selectedImportantGroups = $groupForm->get('importantGroups')->getData();
			
			// Reset all groups to not important
			foreach ($usergroups as $group) {
				$group->setIsImportant(false);
			}
			
			// Set selected groups as important
			foreach ($selectedImportantGroups as $group) {
				$group->setIsImportant(true);
			}
			
			// Save important groups changes
			$manager->flush();
			
			// Reindex groups to update search with new importance order
			$searchEngineManager->reindexGroups();
			
			$this->addFlash('success', 'Les groupes importants ont été mis à jour et l\'index de recherche a été actualisé.');

			// Entete
			$uploadFile = $groupForm->get( 'frontgroupfile' )->getData();

			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\AppFileManager $appFileManager
				 */
				$appFileManager = $fileManager->getManager( 'appfiles' );
				$newFile = $appFileManager->changeWithUploadedFile( $uploadFile, 'frontgroup');
				$manager->persist( $newFile );
				$manager->flush();

				// Put the new id in admin config file
				$appFileManager->setAppImageId('groups', 'frontgroup', $newFile->getId());

			}

			return $this->redirectToRoute( 'administration_groups' );
		}
		return $this->render( 'pages/user/admin-edit.html.twig', [
			'tab' => 'groups',
			'form' => $groupForm->createView(),
		] );
	}

	/**
	 * @Route("/administration/menus", name="administration_menus")
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function adminMenusEdit (
		Request $request,
		EntityManagerInterface $manager,
		\App\Service\AppTextManager $appTextManager
	) {
		$menusTexts = $appTextManager->getTabText('menus');
		$appLinkGroup = new AppLinkGroup();
		foreach($menusTexts as $liensType => $liens) {
			$setFunction = 'set'.ucfirst($liensType).'Title';
			$appLinkGroup->{$setFunction}($liens['title']);
		}
		$liensTypes = [];
		foreach($menusTexts as $liensType => $liens) {
			array_push($liensTypes, $liensType);
			$getFunction = 'get'.ucfirst($liensType);
			foreach ($liens['liens'] as $lien) {
				if(!is_null($lien['nom']) & !is_null($lien['lien'])){
					$linkTemp = new AppLink();
					$linkTemp->setNom($lien['nom']);
					$linkTemp->setLien($lien['lien']);
					$appLinkGroup->{$getFunction}()->add($linkTemp);
				}
			}
		}

		$menuForm          = $this->createForm( AdminMenusType::class, $appLinkGroup);
		$menuForm->handleRequest( $request );

		if ( $menuForm->isSubmitted() && $menuForm->isValid() ) {
			foreach($liensTypes as $liensType) {
				// navbarLiens has no title
				if($liensType!='navbarLiens'){
					$linksTitle = $menuForm->get($liensType.'Title')->getData();
					$appTextManager->changeLiensTitle('menus', $linksTitle, $liensType);
				}
				$appTextManager->changeLiens('menus', $menuForm->get($liensType)->getData(), $liensType);
			}
			return $this->redirectToRoute( 'administration_menus' );
		}

		$communauteGroup = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => 'communaute' ] );

		$this->denyAccessUnlessGranted(GroupVoter::ADMIN, $communauteGroup);

		return $this->render( 'pages/user/admin-edit.html.twig', [
			'tab' => 'menus',
			'form' => $menuForm->createView(),
		] );
	}

	/**
	 * @Route("/administration/administrators", name="administration_administrators")
	 * @param \App\Service\AdminManager       $adminManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	/**
	 * Le vocabulaire d'étiquettes des documents : le créer, le renommer, en
	 * retirer une entrée. (#26)
	 *
	 * Fermé et tenu ici plutôt que saisi librement au dépôt d'un document :
	 * des étiquettes libres se dédoublent en synonymes, et le filtre ne veut
	 * plus rien dire au bout de quelques mois.
	 *
	 * @Route("/administration/document-tags", name="administration_document_tags")
	 *
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 * @param \App\Service\SlugGenerator               $slugGenerator
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function adminDocumentTags (
			Request $request,
			EntityManagerInterface $manager,
			SlugGenerator $slugGenerator
	) {
		$communauteGroup = $manager->getRepository( Usergroup::class )
								   ->findOneBy( [ 'slug' => 'communaute' ] );

		$this->denyAccessUnlessGranted( GroupVoter::ADMIN, $communauteGroup );

		$repository = $manager->getRepository( DocumentTag::class );

		$form = $this->createForm( DocumentTagType::class );
		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			$name = trim( (string) $form->get( 'name' )->getData() );
			$slug = SlugGenerator::slugify( $name );

			if ( $name === '' ) {
				$this->addFlash( 'warning', 'messages.document.tag_empty' );
			}
			elseif ( $repository->findOneBy( [ 'slug' => $slug ] ) ) {
				// Deux fois la même étiquette sous deux orthographes, c'est
				// exactement ce qu'une liste fermée doit empêcher.
				$this->addFlash( 'warning', 'messages.document.tag_exists' );
			}
			else {
				$tag = new DocumentTag();
				$tag->setName( $name );
				$tag->setSlug( $slugGenerator->generateSlug( $name, DocumentTag::class ) );

				$manager->persist( $tag );
				$manager->flush();

				$this->addFlash( 'notice', 'messages.document.tag_created' );
			}

			return $this->redirectToRoute( 'administration_document_tags' );
		}

		return $this->render( 'pages/user/admin-edit.html.twig', [
				'tab'  => 'document-tags',
				'form' => $form->createView(),
				'tags' => $repository->findAll(),
		] );
	}

	/**
	 * Retirer une étiquette du vocabulaire. Les documents qui la portaient la
	 * perdent — c'est le sens d'un vocabulaire tenu : ce qui n'y est plus ne
	 * classe plus rien. (#26)
	 *
	 * @Route("/administration/document-tags/{tagId}/delete", name="administration_document_tag_delete", methods={"POST"})
	 *
	 * @param                                            $tagId
	 * @param \Symfony\Component\HttpFoundation\Request $request
	 * @param \Doctrine\ORM\EntityManagerInterface      $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function adminDocumentTagDelete (
			$tagId,
			Request $request,
			EntityManagerInterface $manager
	) {
		$communauteGroup = $manager->getRepository( Usergroup::class )
								   ->findOneBy( [ 'slug' => 'communaute' ] );

		$this->denyAccessUnlessGranted( GroupVoter::ADMIN, $communauteGroup );

		if ( !$this->isCsrfTokenValid( 'delete-document-tag', $request->request->get( '_token' ) ) ) {
			throw $this->createAccessDeniedException( 'Invalid token' );
		}

		$tag = $manager->getRepository( DocumentTag::class )->find( $tagId );

		if ( !$tag ) {
			throw $this->createNotFoundException( 'The tag does not exist' );
		}

		$manager->remove( $tag );
		$manager->flush();

		$this->addFlash( 'notice', 'messages.document.tag_deleted' );

		return $this->redirectToRoute( 'administration_document_tags' );
	}

	public function adminAdministratorsEdit (
		EntityManagerInterface $manager,
		AdminManager $adminManager
	) {
		$data = $adminManager->getCommuniteAdminMembers();
		$communauteGroup = $manager->getRepository( Usergroup::class )
		->findOneBy( [ 'slug' => 'communaute' ] );

		$this->denyAccessUnlessGranted(GroupVoter::ADMIN, $communauteGroup);
		return $this->render( 'pages/user/admin-edit.html.twig', [
			'tab' => 'admin',
			'members' => $data[ 'members' ]
		] );
	}
}
