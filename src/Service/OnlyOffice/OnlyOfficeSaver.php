<?php

namespace App\Service\OnlyOffice;

use App\Entity\Document;
use App\Entity\File;
use App\Entity\LogEvent;
use App\Entity\User;
use App\Service\FileManager;
use App\Service\FileMimeManager;
use App\Service\UsergroupFileManager;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Ce que le serveur de documents rend quand une séance d'édition se termine. (#43)
 *
 * Il n'envoie pas le fichier : il envoie l'adresse d'un fichier chez lui, à
 * charge pour nous d'aller le chercher. Deux conséquences à ne pas perdre de
 * vue. La première : **la plateforme doit pouvoir joindre le serveur de
 * documents**, et pas seulement l'inverse — une installation où seul le
 * navigateur le voit enregistre zéro modification, en silence. La seconde :
 * tant que ce fichier n'est pas récupéré, il n'y a rien à enregistrer, et il
 * ne faut surtout pas répondre « c'est fait ».
 *
 * L'enregistrement suit le remplacement de fichier déjà en place à l'édition
 * d'une fiche (#41) : un nouveau `File` est écrit, le document pointe dessus,
 * et l'ancien n'est effacé qu'ensuite — si l'écriture avait échoué on aurait
 * perdu les deux.
 */
class OnlyOfficeSaver {
	/**
	 * Au-delà, on refuse plutôt que de charger le fichier en mémoire : c'est
	 * déjà bien au-dessus de ce qu'un traitement de texte produit.
	 */
	private const MAX_BYTES = 104857600;

	/**
	 * @var \Doctrine\ORM\EntityManagerInterface
	 */
	private $manager;

	/**
	 * @var \App\Service\FileManager
	 */
	private $files;

	/**
	 * @var \Symfony\Contracts\HttpClient\HttpClientInterface
	 */
	private $client;

	/**
	 * @var \Psr\Log\LoggerInterface|null
	 */
	private $logger;

	/**
	 * @var \App\Service\OnlyOffice\OnlyOfficeService
	 */
	private $onlyoffice;

	public function __construct (
			EntityManagerInterface $manager,
			FileManager $files,
			HttpClientInterface $client,
			OnlyOfficeService $onlyoffice,
			?LoggerInterface $logger = NULL
	) {
		$this->manager    = $manager;
		$this->files      = $files;
		$this->client     = $client;
		$this->onlyoffice = $onlyoffice;
		$this->logger     = $logger;
	}

	/**
	 * @param \App\Entity\Document  $document
	 * @param string                $url      l'adresse de la version modifiée, chez le serveur de documents
	 * @param string                $filetype l'extension de ce qu'il rend, qui peut différer de l'original
	 * @param \App\Entity\User|null $author   celui qui tenait le clavier, si on l'a reconnu
	 *
	 * @return bool
	 */
	public function save ( Document $document, string $url, string $filetype, ?User $author = NULL ): bool {
		$previous = $document->getFile();
		$group    = $document->getUsergroup();

		if ( !$previous || !$group ) {
			return FALSE;
		}

		// L'adresse annoncée porte le nom public du serveur de documents ; on
		// n'en garde que le chemin, et on le repose sur l'adresse par laquelle
		// la plateforme sait le joindre.
		$url = $this->onlyoffice->fetchUrl( $url );

		try {
			$response = $this->client->request( 'GET', $url, [ 'timeout' => 60 ] );

			if ( $response->getStatusCode() !== 200 ) {
				$this->log( sprintf( 'OnlyOffice : le fichier modifié répond %d', $response->getStatusCode() ) );

				return FALSE;
			}

			$content = $response->getContent();
		} catch ( Throwable $e ) {
			// Injoignable, refusé, expiré : dans tous les cas il n'y a rien à
			// enregistrer, et il ne faut pas prétendre le contraire.
			$this->log( 'OnlyOffice : impossible de récupérer le fichier modifié — ' . $e->getMessage() );

			return FALSE;
		}

		// Un corps vide vaut un échec. L'écrire remplacerait un document par
		// zéro octet, ce qu'aucune séance d'édition ne produit.
		if ( $content === '' || strlen( $content ) > self::MAX_BYTES ) {
			$this->log( sprintf( 'OnlyOffice : version modifiée inutilisable (%d octets)', strlen( $content ) ) );

			return FALSE;
		}

		$name = $this->nameFor( $previous->getName(), $filetype );

		/**
		 * @var \App\Service\UsergroupFileManager $groupFiles
		 */
		$groupFiles = $this->files->getManager( File::USERGROUP_FILES );
		$path       = $groupFiles->writeFile( $name, $group, $content );

		if ( $path === FALSE ) {
			$this->log( 'OnlyOffice : écriture du fichier refusée' );

			return FALSE;
		}

		$file = new File();
		$file->setFilesystem( File::USERGROUP_FILES );
		$file->setUser( $author ?: $previous->getUser() );
		$file->setUsergroup( $group );
		$file->setName( $name );
		$file->setPath( $path );
		$file->setType( FileMimeManager::mimeForExtension( FileMimeManager::extension( $name ) ) );
		$file->setSize( strlen( $content ) );

		$this->manager->persist( $file );

		$document->setFile( $file );

		$log = new LogEvent();
		$log->setType( LogEvent::DOCUMENT_EDIT );
		$log->setUser( $author ?: $document->getUser() );
		$log->setUsergroup( $group );
		$log->setCreatedAt( new DateTime() );
		$log->setData( [ 'document' => $document->getId(), 'title' => $document->getTitle() ] );

		$this->manager->persist( $log );
		$this->manager->flush();

		// L'ancien n'est plus référencé par rien, et seulement maintenant.
		$this->files->deleteFile( $previous );
		$this->manager->remove( $previous );
		$this->manager->flush();

		return TRUE;
	}

	/**
	 * Le nom de la version enregistrée.
	 *
	 * L'éditeur peut rendre un autre format que celui reçu — un `.odt` ouvert
	 * puis enregistré en `.docx`, selon le réglage du serveur de documents.
	 * Garder l'ancienne extension donnerait un fichier qui ment sur son
	 * contenu ; on suit donc ce qu'il annonce, et on ne garde que le nom.
	 *
	 * @param string $previousName
	 * @param string $filetype
	 *
	 * @return string
	 */
	private function nameFor ( string $previousName, string $filetype ): string {
		$filetype = preg_replace( '/[^a-z0-9]/', '', strtolower( $filetype ) );
		$current  = FileMimeManager::extension( $previousName );

		if ( ( $filetype === '' ) || ( $filetype === $current ) ) {
			return $previousName;
		}

		return pathinfo( $previousName, PATHINFO_FILENAME ) . '.' . $filetype;
	}

	/**
	 * @param string $message
	 */
	private function log ( string $message ) {
		if ( $this->logger ) {
			$this->logger->error( $message );
		}
	}
}
