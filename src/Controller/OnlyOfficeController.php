<?php

namespace App\Controller;

use App\Entity\Document;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Security\GroupDocumentVoter;
use App\Service\FileManager;
use App\Service\OnlyOffice\OnlyOfficeJwt;
use App\Service\OnlyOffice\OnlyOfficeSaver;
use App\Service\OnlyOffice\OnlyOfficeService;
use App\Service\OnlyOffice\OnlyOfficeToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * L'édition en ligne des documents bureautiques. (#43)
 *
 * Trois routes, et deux publics. La première est une page ordinaire, derrière
 * la session et le voteur habituel : elle installe l'éditeur. Les deux autres
 * ne sont pas pour un navigateur mais pour le serveur de documents, qui n'a
 * pas de session — ce qui les autorise est le jeton signé qu'elles portent, et
 * rien d'autre. Elles figurent à ce titre dans `access_control`.
 *
 * Le droit de modifier est tranché **une fois**, ici, par `GroupDocumentVoter`,
 * et il est ensuite inscrit dans le jeton. La route d'enregistrement ne
 * reconsulte pas le voteur : elle n'a personne à qui le demander.
 */
class OnlyOfficeController extends AbstractController {
	/**
	 * La page qui porte l'éditeur.
	 *
	 * @Route(
	 *     "/groups/{groupSlug}/documents/{documentId}/office",
	 *     name="group_document_office",
	 *     methods={"GET"},
	 *     requirements={"documentId"="\d+"}
	 * )
	 *
	 * @param                                                   $groupSlug
	 * @param                                                   $documentId
	 * @param \Doctrine\ORM\EntityManagerInterface              $manager
	 * @param \App\Service\OnlyOffice\OnlyOfficeService         $onlyoffice
	 * @param \Symfony\Component\HttpFoundation\Request         $request
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function editor (
			$groupSlug,
			$documentId,
			EntityManagerInterface $manager,
			OnlyOfficeService $onlyoffice,
			Request $request
	) {
		$document = $this->document( $manager, $groupSlug, $documentId );

		$this->denyAccessUnlessGranted( GroupDocumentVoter::READ, $document );

		// Éteint, ou format que le serveur de documents ne sait pas ouvrir :
		// la page n'existe pas, plutôt que d'exister vide.
		if ( !$onlyoffice->supports( $document->getFile() ) ) {
			throw $this->createNotFoundException( 'Online editing is not available for this document' );
		}

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $this->getUser();

		$canWrite = $this->isGranted( GroupDocumentVoter::EDIT, $document );

		return $this->render( 'pages/document/document-office.html.twig', [
				'group'     => $document->getUsergroup(),
				'document'  => $document,
				'apiUrl'    => $onlyoffice->apiUrl(),
				'config'    => $onlyoffice->editorConfig( $document, $user, $canWrite, $request->getLocale() ),
				'canWrite'  => $canWrite && $onlyoffice->isEditable( $document->getFile() ),
		] );
	}

	/**
	 * Le fichier, servi au serveur de documents.
	 *
	 * @Route("/office/{token}/content", name="onlyoffice_content", methods={"GET"})
	 *
	 * @param string                                     $token
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\OnlyOffice\OnlyOfficeToken    $tokens
	 * @param \App\Service\FileManager                   $fileManager
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function content (
			string $token,
			EntityManagerInterface $manager,
			OnlyOfficeToken $tokens,
			FileManager $fileManager
	) {
		$claims = $tokens->read( $token );

		// Un 403 sec, et non l'exception de sécurité : celle-ci renverrait un
		// visiteur anonyme vers la page de connexion, et le serveur de
		// documents enregistrerait ce formulaire à la place du fichier.
		if ( !$claims ) {
			throw new AccessDeniedHttpException( 'Invalid or expired token' );
		}

		/**
		 * @var \App\Entity\Document $document
		 */
		$document = $manager->getRepository( Document::class )
							->findOneBy( [ 'id' => $claims[ 'document' ] ] );

		if ( !$document || !$document->getFile() ) {
			throw $this->createNotFoundException( 'The document does not exist' );
		}

		return $fileManager->getFile( $document->getFile() );
	}

	/**
	 * Ce que le serveur de documents raconte de la séance d'édition.
	 *
	 * Le protocole tient dans un nombre : 1 quelqu'un édite, 2 la version
	 * finale est prête, 3 l'enregistrement a échoué chez lui, 4 la séance
	 * s'est fermée sans modification, 6 enregistrement en cours de séance,
	 * 7 échec du précédent.
	 *
	 * On répond `{"error":0}` dans tous les cas où il n'y a plus rien à
	 * tenter : renvoyer une erreur ferait recommencer le serveur de documents
	 * indéfiniment. On ne répond 0 après un 2 ou un 6 **que si le fichier a
	 * effectivement été enregistré** — sinon il jetterait une version qu'on
	 * n'a pas.
	 *
	 * @Route("/office/{token}/callback", name="onlyoffice_callback", methods={"POST"})
	 *
	 * @param string                                     $token
	 * @param \Symfony\Component\HttpFoundation\Request  $request
	 * @param \Doctrine\ORM\EntityManagerInterface       $manager
	 * @param \App\Service\OnlyOffice\OnlyOfficeToken    $tokens
	 * @param \App\Service\OnlyOffice\OnlyOfficeJwt      $jwt
	 * @param \App\Service\OnlyOffice\OnlyOfficeSaver    $saver
	 *
	 * @return \Symfony\Component\HttpFoundation\JsonResponse
	 */
	public function callback (
			string $token,
			Request $request,
			EntityManagerInterface $manager,
			OnlyOfficeToken $tokens,
			OnlyOfficeJwt $jwt,
			OnlyOfficeSaver $saver
	) {
		$claims = $tokens->read( $token );

		// Un jeton de lecture ne donne pas de quoi réécrire le document : la
		// consultation et l'édition ouvrent la même page, et sa configuration
		// est lisible par qui la reçoit.
		if ( !$claims || ( $claims[ 'mode' ] !== OnlyOfficeToken::WRITE ) ) {
			return new JsonResponse( [ 'error' => 1, 'message' => 'Invalid or expired token' ], 403 );
		}

		$body = json_decode( $request->getContent(), TRUE );

		if ( !is_array( $body ) ) {
			return new JsonResponse( [ 'error' => 1, 'message' => 'Malformed body' ], 400 );
		}

		// Quand un secret est partagé, le corps est signé — soit dans l'entête
		// « Authorization », soit dans le champ `token`. Le corps signé fait
		// foi : celui qui l'accompagne pourrait dire autre chose.
		if ( $jwt->isEnabled() ) {
			$signed = $jwt->decode( preg_replace( '/^Bearer\s+/i', '', (string) $request->headers->get( 'Authorization', '' ) ) )
					  ?: $jwt->decode( (string) ( $body[ 'token' ] ?? '' ) );

			if ( !$signed ) {
				return new JsonResponse( [ 'error' => 1, 'message' => 'Invalid signature' ], 403 );
			}

			$body = isset( $signed[ 'payload' ] ) && is_array( $signed[ 'payload' ] ) ? $signed[ 'payload' ] : $signed;
		}

		$status = (int) ( $body[ 'status' ] ?? 0 );

		if ( !in_array( $status, [ 2, 6 ], TRUE ) ) {
			return new JsonResponse( [ 'error' => 0 ] );
		}

		/**
		 * @var \App\Entity\Document $document
		 */
		$document = $manager->getRepository( Document::class )
							->findOneBy( [ 'id' => $claims[ 'document' ] ] );

		if ( !$document ) {
			return new JsonResponse( [ 'error' => 1, 'message' => 'The document does not exist' ], 404 );
		}

		$url = (string) ( $body[ 'url' ] ?? '' );

		if ( $url === '' ) {
			return new JsonResponse( [ 'error' => 1, 'message' => 'No file to fetch' ], 400 );
		}

		$saved = $saver->save(
				$document,
				$url,
				(string) ( $body[ 'filetype' ] ?? '' ),
				$this->author( $manager, $body )
		);

		return $saved
				? new JsonResponse( [ 'error' => 0 ] )
				: new JsonResponse( [ 'error' => 1, 'message' => 'Could not store the edited file' ], 500 );
	}

	/**
	 * Celui qui tenait le clavier. Le serveur de documents nous rend les
	 * identifiants que nous lui avions donnés, c'est-à-dire les nôtres.
	 *
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param array                                $body
	 *
	 * @return \App\Entity\User|null
	 */
	private function author ( EntityManagerInterface $manager, array $body ): ?User {
		$users = $body[ 'users' ] ?? [];

		if ( !is_array( $users ) || empty( $users[ 0 ] ) ) {
			return NULL;
		}

		return $manager->getRepository( User::class )
					   ->findOneBy( [ 'id' => (int) $users[ 0 ] ] );
	}

	/**
	 * @param \Doctrine\ORM\EntityManagerInterface $manager
	 * @param string                               $groupSlug
	 * @param int                                  $documentId
	 *
	 * @return \App\Entity\Document
	 */
	private function document ( EntityManagerInterface $manager, string $groupSlug, $documentId ): Document {
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

		return $document;
	}
}
