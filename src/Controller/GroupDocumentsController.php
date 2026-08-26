<?php

namespace App\Controller;

use App\Entity\Discussion;
use App\Entity\Document;
use App\Entity\DocumentFolder;
use App\Entity\DocumentTag;
use App\Entity\File;
use App\Entity\LogEvent;
use App\Entity\Page;
use App\Entity\Usergroup;
use App\Form\DocumentType;
use App\Security\GroupDocumentVoter;
use App\Security\GroupVoter;
use App\Service\DocumentFolderResolver;
use App\Service\FileManager;
use App\Service\NotificationSender;
use App\Service\FileMimeManager;
use App\Service\OnlyOffice\OnlyOfficeService;
use App\Service\SlugGenerator;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Repository\DocumentTagRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class GroupDocumentsController extends AbstractController {
	/**
	 * @param \App\Entity\Document $document
	 * @param                      $filters
	 *
	 * @return bool
	 */
	/**
	 * Ce que la fiche sait afficher elle-même, et sous quelle forme. (#43)
	 *
	 * Rien ici n'est chargé de l'extérieur : le lecteur de PDF est celui du
	 * navigateur, et une image est une image. Ce qui n'est ni l'un ni l'autre
	 * ne se prévisualise pas — un `.docx` posé dans une balise ne donnerait
	 * qu'une page d'octets.
	 *
	 * @param \App\Entity\File $file
	 *
	 * @return string|null « pdf », « image », ou NULL
	 */
	private function previewKind ( File $file ): ?string {
		if ( FileMimeManager::isPdf( $file->getType() ) ) {
			return 'pdf';
		}

		if ( FileMimeManager::isImage( $file->getType() ) ) {
			return 'image';
		}

		return NULL;
	}

	private function match ( Document $document, $filters ) {
		// Keywords

		$matchKeywords = FALSE;

		if ( !empty( $filters[ 'keywords' ] ) ) {
			$title = SlugGenerator::slugify( $document->getTitle() . '-' . $document->getFile()->getName() );

			foreach ( $filters[ 'keywords' ] as $keyword ) {
				$matchKeywords = $matchKeywords || ( strpos( $title, $keyword ) !== FALSE );
			}
		}
		else {
			$matchKeywords = TRUE;
		}

		if ( !$matchKeywords ) {
			return FALSE;
		}

		// Filetype

		if ( !empty( $filters[ 'filetype' ] ) ) {
			$matchFiletype = FALSE;

			foreach ( $filters[ 'filetype' ] as $type ) {
				$matchFiletype = $matchFiletype || ( in_array( $document->getFile()->getType(), FileMimeManager::getMimes( $type ) ) );
			}
		}
		else {
			$matchFiletype = TRUE;
		}

		if ( !$matchFiletype ) {
			return FALSE;
		}

		// Étiquettes : un document répond dès qu'il en porte une de celles
		// demandées. Exiger toutes les étiquettes cochées ne ramènerait
		// presque jamais rien. (#26)

		if ( !empty( $filters[ 'tags' ] ) ) {
			$wanted = array_map( 'intval', (array) $filters[ 'tags' ] );

			if ( empty( array_intersect( $wanted, $document->getTagIds() ) ) ) {
				return FALSE;
			}
		}

		return TRUE;
	}

	/**************************************************
	 * DOCUMENTS
	 **************************************************/

	/**
	 * @Route("/groups/{groupSlug}/documents", name="group_documents_index")
	 * @param                                            $groupSlug
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function documentsIndex (
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

		$this->denyAccessUnlessGranted( GroupVoter::READ, $group );

		// Filters

		$filters = $request->query->get( 'form', [] );
		unset( $filters[ 'submit' ] );

		if ( !empty( $filters[ 'query' ] ) ) {
			$filters[ 'keywords' ] = explode( '-', SlugGenerator::slugify( $filters[ 'query' ] ) );
			unset( $filters[ 'query' ] );
		}

		$form = $this->createFormBuilder( NULL, [ 'csrf_protection' => FALSE ] )
					 ->setMethod( 'get' )
					 ->add( 'filetype', ChoiceType::class, [
							 'required' => FALSE,
							 'expanded' => TRUE,
							 'multiple' => TRUE,
							 'choices'  => [
									 'pages.document.list.types.' . FileMimeManager::DOCUMENTS => FileMimeManager::DOCUMENTS,
									 'pages.document.list.types.' . FileMimeManager::PDF       => FileMimeManager::PDF,
									 'pages.document.list.types.' . FileMimeManager::IMAGES    => FileMimeManager::IMAGES,
									 'pages.document.list.types.' . FileMimeManager::ARCHIVES  => FileMimeManager::ARCHIVES,
							 ],
					 ] )
					 ->add( 'tags', EntityType::class, [
							 // Le vocabulaire complet, même les étiquettes que
							 // ce groupe n'emploie pas : il classe comme les
							 // autres, avec les mêmes mots. (#26)
							 'class'         => DocumentTag::class,
							 'required'      => FALSE,
							 'expanded'      => TRUE,
							 'multiple'      => TRUE,
							 'choice_label'  => 'name',
							 'query_builder' => function ( DocumentTagRepository $repository ) {
								 return $repository->createQueryBuilder( 't' )
												   ->orderBy( 't.name', 'ASC' );
							 },
					 ] )
					 ->add( 'query', SearchType::class, [
							 'required' => FALSE,
					 ] )
					 ->add( 'submit', SubmitType::class )
					 ->getForm();

		$form->handleRequest( $request );

		// Folders

		$folders = $this->folderTree(
				$manager->getRepository( DocumentFolder::class )->findRootsForGroup( $group ),
				$filters
		);

		// Documents

		$documents = $manager->getRepository( Document::class )->findRootDocuments( $group );

		$documents = array_filter( $documents, function ( $document ) use ( $filters ) {
			return $this->match( $document, $filters );
		} );

		return $this->render( 'pages/document/documents-index.html.twig', [
				'group'     => $group,
				'folders'   => $folders,
				'documents' => $documents,
				'form'      => $form->createView(),
		] );
	}

	/**
	 * Les dossiers d'un groupe, emboîtés, dépouillés de ce qui ne correspond
	 * pas au filtre en cours.
	 *
	 * Un dossier est conservé dès qu'il contient un document retenu ou qu'un
	 * de ses sous-dossiers en contient : sans quoi un dossier de classement,
	 * vide par nature, emporterait tout son contenu. (#8)
	 *
	 * @param iterable $folders
	 * @param array    $filters
	 * @param array    $seen identifiants déjà traversés
	 *
	 * @return array liste de ['folder' => …, 'documents' => …, 'children' => …]
	 */
	private function folderTree ( $folders, array $filters, array $seen = [] ) {
		$tree = [];

		foreach ( $folders as $folder ) {
			if ( isset( $seen[ $folder->getId() ] ) ) {
				continue;
			}

			$seen[ $folder->getId() ] = TRUE;

			$documents = [];

			foreach ( $folder->getDocuments() as $document ) {
				if ( $this->match( $document, $filters ) ) {
					$documents[] = $document;
				}
			}

			$children = $this->folderTree( $folder->getChildren(), $filters, $seen );

			if ( empty( $documents ) && empty( $children ) ) {
				continue;
			}

			$tree[] = [
					'folder'    => $folder,
					'documents' => $documents,
					'children'  => $children,
			];
		}

		return $tree;
	}

	/**************************************************
	 * DOCUMENT
	 **************************************************/

	/**
	 * @Route("/groups/{groupSlug}/documents/new", name="group_document_new")
	 * @param                                            $groupSlug
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\FileManager                   $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \Exception
	 */
	public function documentNew (
			$groupSlug,
			Request $request,
            EntityManagerInterface $manager,
			FileManager $fileManager,
			NotificationSender $notificationSender,
			DocumentFolderResolver $folderResolver,
			TranslatorInterface $translator
	) {
		/**
		 * @var $group \App\Entity\Usergroup
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		$this->denyAccessUnlessGranted( GroupDocumentVoter::CREATE, $group );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		/**************************************************
		 * DOCUMENT
		 **************************************************/

		$existingFolders = $manager->getRepository( DocumentFolder::class )
								   ->findForGroup( $group );

		$document = new Document();
		$form     = $this->createForm( DocumentType::class, $document, [
				'folders'      => $existingFolders,
				'require_file' => TRUE,
		] );

		// Un fichier trop lourd pour `post_max_size` fait jeter la requête
		// entière par PHP : le formulaire ne se voit pas soumis et se
		// réafficherait vierge, sans un mot. (#40)
		if ( $fileManager->requestWasDiscarded( $request ) ) {
			$this->addFlash( 'error', $translator->trans( 'messages.document.upload_discarded', [
					'%size%' => $fileManager->formatSize( $fileManager->fileUploadMaxSize( '50M' ) ),
			] ) );
		}

		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			$document->setUser( $user );
			$document->setUsergroup( $group );
			$document->setCreatedAt( new DateTime() );

			// Le champ accepte un chemin : « Comptes rendus / 2026 » range le
			// document dans un sous-dossier, en créant ce qui manque. (#8)
			$document->setFolder(
					$folderResolver->resolve( $group, $form->get( 'folderTitle' )->getData() )
			);

			$manager->persist( $document );

			// File
			$uploadFile = $form->get( 'filefile' )->getData();

			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\UsergroupFileManager $groupFileManager
				 */
				$groupFileManager = $fileManager->getManager( File::USERGROUP_FILES );
				$file             = $groupFileManager->createFromUploadedFile( $uploadFile, $user, $group );

				$manager->persist( $file );

				$document->setFile( $file );

				if ( empty( $document->getTitle() ) ) {
					$document->setTitle( pathinfo( $file->getName(), PATHINFO_FILENAME ) );
				}
			}

			$manager->flush();

			// Log Event

			$log = new LogEvent();
			$log->setType( LogEvent::DOCUMENT_CREATE );
			$log->setUser( $this->getUser() );
			$log->setUsergroup( $group );
			$log->setCreatedAt( new DateTime() );
			$log->setData( [ 'document' => $document->getId(), 'title' => $document->getTitle() ] );
			$manager->persist( $log );
			$manager->flush();

			// Notifications (#34)

			$notificationSender->notifyNewDocument( $document );

			// --

			$this->addFlash( 'notice', 'messages.document.document_created' );

			return $this->redirectToRoute( 'group_documents_index', [ 'groupSlug' => $group->getSlug() ] );
		}

		return $this->render( 'pages/document/document-create.html.twig', [
				'group' => $group,
				'form'  => $form->createView(),
		] );
	}

	/**
	 * @Route("/groups/{groupSlug}/documents/{documentId}/edit", name="group_document_edit")
	 * @param                                            $groupSlug
	 * @param                                            $documentId
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\FileManager                   $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \Exception
	 */
	public function documentEdit (
			$groupSlug,
			$documentId,
			Request $request,
            EntityManagerInterface $manager,
			FileManager $fileManager,
			DocumentFolderResolver $folderResolver,
			TranslatorInterface $translator
	) {
		/**
		 * @var  \App\Entity\Usergroup $group
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		/**
		 * @var \App\Entity\Page $page
		 */
		$document = $manager->getRepository( Document::class )
							->findOneBy( [ 'id' => $documentId ] );

		if ( !$document ) {
			throw $this->createNotFoundException( 'The document does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupDocumentVoter::EDIT, $document );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		/**************************************************
		 * DOCUMENT
		 **************************************************/

		$existingFolders = $manager->getRepository( DocumentFolder::class )
								   ->findForGroup( $group );

		$form = $this->createForm( DocumentType::class, $document, [ 'folders' => $existingFolders ] );

		// Ici aussi, un fichier trop lourd emporte toute la modification sans
		// rien dire — description et étiquettes comprises. (#40)
		if ( $fileManager->requestWasDiscarded( $request ) ) {
			$this->addFlash( 'error', $translator->trans( 'messages.document.upload_discarded', [
					'%size%' => $fileManager->formatSize( $fileManager->fileUploadMaxSize( '50M' ) ),
			] ) );
		}

		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			$folderTitle    = trim( $form->get( 'folderTitle' )->getData() );
			// Le champ accepte un chemin, comme au dépôt. (#8)
			$document->setFolder( $folderResolver->resolve( $group, $folderTitle ) );

			// File
			//
			// Le remplacement était désactivé par un « FALSE && » venu du dépôt
			// d'origine : on choisissait un fichier, la plateforme répondait
			// « Le document a été mis à jour », et l'ancien restait. Une panne
			// qui affirme avoir réussi est pire que pas de fonctionnalité du
			// tout. (#41)
			$uploadFile   = $form->get( 'filefile' )->getData();
			$replacedFile = NULL;

			if ( !empty( $uploadFile ) ) {
				/**
				 * @var \App\Service\UsergroupFileManager $groupFileManager
				 */
				$groupFileManager = $fileManager->getManager( File::USERGROUP_FILES );
				$file             = $groupFileManager->createFromUploadedFile( $uploadFile, $user, $group );

				$manager->persist( $file );

				$replacedFile = $document->getFile();

				$document->setFile( $file );

				// Le titre ne prend le nom du fichier que s'il n'y en a pas :
				// remplacer un fichier ne renomme pas un document que
				// quelqu'un a pris la peine d'intituler.
				if ( empty( $document->getTitle() ) ) {
					$document->setTitle( pathinfo( $file->getName(), PATHINFO_FILENAME ) );
				}
			}

			$manager->flush();

			// L'ancien fichier n'est plus référencé par rien. Il n'est retiré
			// qu'une fois le remplaçant enregistré : si l'enregistrement avait
			// échoué, on aurait perdu les deux.
			if ( !empty( $replacedFile ) ) {
				$fileManager->deleteFile( $replacedFile );
				$manager->remove( $replacedFile );
			}

			// Clean previous Folder

			if ( !empty( $previousFolder ) && ( $previousFolder->getTitle() !== $folderTitle ) ) {
				$documents = $manager->getRepository( Document::class )
									 ->findBy( [ 'folder' => $previousFolder ] );

				if ( empty( $documents ) ) {
					$manager->remove( $previousFolder );
				}
			}

			// Log Event

			$log = new LogEvent();
			$log->setType( LogEvent::DOCUMENT_EDIT );
			$log->setUser( $this->getUser() );
			$log->setUsergroup( $group );
			$log->setCreatedAt( new DateTime() );
			$log->setData( [ 'document' => $document->getId(), 'title' => $document->getTitle() ] );
			$manager->persist( $log );
			$manager->flush();

			// --

			$this->addFlash( 'notice', 'messages.document.document_updated' );

			return $this->redirectToRoute( 'group_documents_index', [ 'groupSlug' => $group->getSlug() ] );
		}

		return $this->render( 'pages/document/document-edit.html.twig', [
				'group'    => $group,
				'form'     => $form->createView(),
				'document' => $document,
		] );
	}

	/**
	 * La fiche d'un document. (#32)
	 *
	 * Il n'y en avait pas : un document n'était qu'un téléchargement. On ne
	 * pouvait donc ni lui envoyer quelqu'un, ni revenir dessus, ni voir ce
	 * qui en avait été dit — « les documents sont bruts ». Cette page lui
	 * donne une adresse, montre ce qu'il est, et rattache les discussions et
	 * les pages qui y renvoient.
	 *
	 * Déclarée après « /new » et bornée aux nombres, pour qu'aucune des deux
	 * adresses ne mange l'autre.
	 *
	 * @Route(
	 *     "/groups/{groupSlug}/documents/{documentId}",
	 *     name="group_document_index",
	 *     methods={"GET"},
	 *     requirements={"documentId"="\d+"}
	 * )
	 *
	 * @param                                      $groupSlug
	 * @param                                      $documentId
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param \App\Service\FileManager             $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function documentIndex (
			$groupSlug,
			$documentId,
			EntityManagerInterface $manager,
			FileManager $fileManager,
			OnlyOfficeService $onlyoffice
	) {
		/**
		 * @var \App\Entity\Usergroup $group
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		/**
		 * @var \App\Entity\Document $document
		 */
		$document = $manager->getRepository( Document::class )
							->findOneBy( [ 'id' => $documentId, 'usergroup' => $group ] );

		if ( !$document ) {
			throw $this->createNotFoundException( 'The document does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupDocumentVoter::READ, $document );

		$file = $document->getFile();

		return $this->render( 'pages/document/document-index.html.twig', [
				'group'       => $group,
				'document'    => $document,
				'size'        => $file && $file->getSize() ? $fileManager->formatSize( $file->getSize() ) : NULL,
				// Ce que la page sait montrer elle-même : un PDF et une image
				// s'affichent dans le navigateur, sans rien installer et sans
				// rien appeler au dehors. Le reste se télécharge — ou s'ouvre
				// dans l'éditeur en ligne, quand il est configuré. (#43)
				'preview'     => $file ? $this->previewKind( $file ) : NULL,
				'office'      => $onlyoffice->supports( $file ),
				'officeEdit'  => $onlyoffice->isEditable( $file )
								 && $this->isGranted( GroupDocumentVoter::EDIT, $document ),
				'discussions' => $manager->getRepository( Discussion::class )
										 ->findMentioningDocument( $group, $document->getId() ),
				'pages'       => $manager->getRepository( Page::class )
										 ->findMentioningDocument( $group, $document->getId() ),
		] );
	}

	/**
	 * Le fichier lui-même.
	 *
	 * Sans rien, il s'affiche dans la page quand le navigateur sait le faire —
	 * c'est ce qui met un PDF sous les yeux plutôt que dans le dossier des
	 * téléchargements. Avec `?download=1`, il se télécharge : c'est le bouton
	 * « Télécharger », qui doit rester un téléchargement même pour un PDF. (#43)
	 *
	 * @Route("/groups/{groupSlug}/documents/{documentId}/get", name="group_document_get")
	 * @param                                            $groupSlug
	 * @param                                            $documentId
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\FileManager                   $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
	 */
	public function documentGet (
			$groupSlug,
			$documentId,
			Request $request,
            EntityManagerInterface $manager,
			FileManager $fileManager
	) {
		/**
		 * @var  \App\Entity\Usergroup $group
		 */
		$group = $manager->getRepository( Usergroup::class )
						 ->findOneBy( [ 'slug' => $groupSlug ] );

		if ( !$group ) {
			throw $this->createNotFoundException( 'The group does not exist' );
		}

		/**
		 * @var \App\Entity\Page $page
		 */
		$document = $manager->getRepository( Document::class )
							->findOneBy( [ 'id' => $documentId ] );

		if ( !$document ) {
			throw $this->createNotFoundException( 'The document does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupDocumentVoter::READ, $document );

		$file = $document->getFile();
		if ( !$file ) {
			throw $this->createNotFoundException( 'The document file does not exist' );
		}

		return $fileManager->getFile( $file, $request->query->getBoolean( 'download' ) );
	}

	/**
	 * @Route("/groups/{groupSlug}/documents/{documentId}/delete", name="group_document_delete")
	 * @param                                            $groupSlug
	 * @param                                            $documentId
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\FileManager                   $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 * @throws \Exception
	 */
	public function documentDelete (
			$groupSlug,
			$documentId,
			Request $request,
            EntityManagerInterface $manager,
			FileManager $fileManager
	) {
		/**
		 * @var \App\Entity\Document $document
		 */
		$document = $manager->getRepository( Document::class )
							->findOneBy( [ 'id' => $documentId ] );

		if ( !$document ) {
			throw $this->createNotFoundException( 'The document does not exist' );
		}

		$this->denyAccessUnlessGranted( GroupDocumentVoter::DELETE, $document );

		// Delete confirmation form

		$form = $this->createFormBuilder()
					 ->add( 'submit', SubmitType::class )
					 ->getForm();

		$form->handleRequest( $request );

		if ( $form->isSubmitted() && $form->isValid() ) {
			if ( !empty( $document->getFile() ) ) {
				$fileManager->deleteFile( $document->getFile() );
				$manager->remove( $document->getFile() );
			}

			// Log Event

			$log = new LogEvent();
			$log->setType( LogEvent::DOCUMENT_DELETE );
			$log->setUser( $this->getUser() );
			$log->setUsergroup( $document->getUsergroup() );
			$log->setCreatedAt( new DateTime() );
			$log->setData( [ 'document' => $document->getId(), 'title' => $document->getTitle() ] );
			$manager->persist( $log );

			// --

			$manager->remove( $document );

			$manager->flush();

			$this->addFlash( 'notice', 'messages.document.document_deleted' );

			return $this->redirectToRoute( 'group_index', [ 'groupSlug' => $groupSlug ] );
		}

		return $this->render( 'pages/confirm.html.twig', [
				'form' => $form->createView(),
		] );
	}
}
