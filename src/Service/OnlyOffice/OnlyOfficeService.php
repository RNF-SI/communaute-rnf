<?php

namespace App\Service\OnlyOffice;

use App\Entity\Document;
use App\Entity\File;
use App\Entity\User;
use App\Service\FileMimeManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * L'édition en ligne des documents bureautiques. (#43)
 *
 * Un document déposé sur la plateforme ne se modifiait qu'en le téléchargeant,
 * en l'ouvrant chez soi, et en le redéposant — trois gestes, et autant de
 * versions qui se croisent dans les boîtes e-mail. OnlyOffice ouvre le fichier
 * dans la page et le réenregistre à sa place.
 *
 * **Le serveur de documents n'est pas fourni par la plateforme.** C'est un
 * service à part, à installer à côté (voir `docs/edition-en-ligne.md`). Sans
 * `ONLYOFFICE_URL`, tout ce fichier se tait : `isEnabled()` rend FALSE, aucun
 * bouton n'apparaît, aucune route ne répond. C'est la même règle que pour
 * l'export GeoNature — une intégration qu'on n'a pas configurée ne doit pas
 * fabriquer des pages mortes.
 *
 * **Les PDF ne passent pas par lui.** Le navigateur les affiche seul, sans
 * dépendance et sans aller-retour ; et le nom du type de document PDF a changé
 * d'une version d'OnlyOffice à l'autre. Ce qui relève d'ici, ce sont les
 * traitements de texte, les tableurs et les présentations.
 */
class OnlyOfficeService {
	/**
	 * Les trois familles d'OnlyOffice, et les extensions qui en relèvent.
	 * Ouvrir un format absent de cette liste n'est pas proposé.
	 */
	private const TYPES = [
			'word'  => [ 'doc', 'docm', 'docx', 'dot', 'dotm', 'dotx', 'epub', 'fodt', 'htm', 'html', 'mht', 'odt', 'ott', 'rtf', 'txt' ],
			'cell'  => [ 'csv', 'fods', 'ods', 'ots', 'xls', 'xlsm', 'xlsx', 'xlt', 'xltm', 'xltx' ],
			'slide' => [ 'fodp', 'odp', 'otp', 'pot', 'potm', 'potx', 'pps', 'ppsm', 'ppsx', 'ppt', 'pptm', 'pptx' ],
	];

	/**
	 * Ce qu'OnlyOffice sait réenregistrer sans conversion.
	 *
	 * Les formats hérités — `.doc`, `.xls`, `.ppt` — s'ouvrent en lecture et
	 * ne figurent pas ici : les modifier reviendrait à les convertir, donc à
	 * changer le format d'un fichier sous les pieds de celui qui l'a déposé.
	 * Mieux vaut le dire : « ce document s'ouvre, il ne se modifie pas ».
	 */
	private const EDITABLE = [
			'csv', 'docm', 'docx', 'dotx', 'odp', 'ods', 'odt', 'otp', 'ots', 'ott',
			'potx', 'pptm', 'pptx', 'rtf', 'txt', 'xlsm', 'xlsx', 'xltx',
	];

	/**
	 * @var string
	 */
	private $serverUrl;

	/**
	 * @var string
	 */
	private $platformUrl;

	/**
	 * @var \App\Service\OnlyOffice\OnlyOfficeJwt
	 */
	private $jwt;

	/**
	 * @var \App\Service\OnlyOffice\OnlyOfficeToken
	 */
	private $tokens;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $router;

	public function __construct (
			OnlyOfficeJwt $jwt,
			OnlyOfficeToken $tokens,
			UrlGeneratorInterface $router,
			string $serverUrl = '',
			string $platformUrl = ''
	) {
		$this->jwt         = $jwt;
		$this->tokens      = $tokens;
		$this->router      = $router;
		$this->serverUrl   = rtrim( trim( $serverUrl ), '/' );
		$this->platformUrl = rtrim( trim( $platformUrl ), '/' );
	}

	public function isEnabled (): bool {
		return $this->serverUrl !== '';
	}

	/**
	 * L'adresse du serveur de documents, pour ce qui a besoin de la joindre —
	 * le contrôle d'environnement, en pratique.
	 *
	 * @return string
	 */
	public function serverUrl (): string {
		return $this->serverUrl;
	}

	/**
	 * L'adresse du script qui installe l'éditeur dans la page. C'est la seule
	 * ressource que le navigateur va chercher chez le serveur de documents.
	 *
	 * @return string
	 */
	public function apiUrl (): string {
		return $this->serverUrl . '/web-apps/apps/api/documents/api.js';
	}

	/**
	 * Un fichier qu'OnlyOffice sait au moins afficher.
	 *
	 * @param \App\Entity\File|null $file
	 *
	 * @return bool
	 */
	public function supports ( ?File $file ): bool {
		if ( !$this->isEnabled() || !$file ) {
			return FALSE;
		}

		return $this->documentType( $file ) !== NULL;
	}

	/**
	 * Un fichier qu'OnlyOffice sait réenregistrer dans son propre format.
	 *
	 * @param \App\Entity\File|null $file
	 *
	 * @return bool
	 */
	public function isEditable ( ?File $file ): bool {
		if ( !$this->supports( $file ) ) {
			return FALSE;
		}

		return in_array( FileMimeManager::extension( $file->getName() ), self::EDITABLE, TRUE );
	}

	/**
	 * @param \App\Entity\File $file
	 *
	 * @return string|null « word », « cell », « slide », ou NULL
	 */
	public function documentType ( File $file ): ?string {
		$extension = FileMimeManager::extension( $file->getName() );

		foreach ( self::TYPES as $type => $extensions ) {
			if ( in_array( $extension, $extensions, TRUE ) ) {
				return $type;
			}
		}

		return NULL;
	}

	/**
	 * La configuration remise à l'éditeur, signée s'il y a un secret.
	 *
	 * `callbackUrl` n'y figure **que** pour une séance d'édition : c'est
	 * l'adresse où le serveur de documents rend le fichier modifié, et elle
	 * est lisible par celui qui a la page sous les yeux.
	 *
	 * @param \App\Entity\Document $document
	 * @param \App\Entity\User     $user
	 * @param bool                 $canWrite droit de modifier, vu par le voteur
	 * @param string               $locale
	 *
	 * @return array
	 */
	public function editorConfig ( Document $document, User $user, bool $canWrite, string $locale = 'fr' ): array {
		$file = $document->getFile();

		$write = $canWrite && $this->isEditable( $file );
		$token = $this->tokens->create( $document->getId(), $write ? OnlyOfficeToken::WRITE : OnlyOfficeToken::READ );

		$config = [
				'documentType' => $this->documentType( $file ),
				'type'         => 'desktop',
				'document'     => [
						'title'       => $file->getName(),
						'fileType'    => FileMimeManager::extension( $file->getName() ),
						'key'         => $this->key( $file ),
						'url'         => $this->absolute( 'onlyoffice_content', [ 'token' => $token ] ),
						'permissions' => [
								'edit'      => $write,
								'comment'   => $write,
								'fillForms' => $write,
								'download'  => TRUE,
								'print'     => TRUE,
						],
				],
				'editorConfig' => [
						'lang'          => $locale,
						'mode'          => $write ? 'edit' : 'view',
						'user'          => [
								'id'   => (string) $user->getId(),
								'name' => $user->getName(),
						],
						'customization' => [
								'autosave'  => TRUE,
								// Le bouton « Enregistrer » de l'éditeur déclenche
								// alors un enregistrement immédiat (statut 6) au
								// lieu d'attendre la fermeture de la séance.
								'forcesave' => TRUE,
								'chat'      => FALSE,
								'comments'  => $write,
								'help'      => FALSE,
								'goback'    => [
										'url' => $this->absolute( 'group_document_index', [
												'groupSlug'  => $document->getUsergroup()->getSlug(),
												'documentId' => $document->getId(),
										] ),
								],
						],
				],
		];

		if ( $write ) {
			$config[ 'editorConfig' ][ 'callbackUrl' ] = $this->absolute( 'onlyoffice_callback', [ 'token' => $token ] );
		}

		if ( $this->jwt->isEnabled() ) {
			$config[ 'token' ] = $this->jwt->encode( $config );
		}

		return $config;
	}

	/**
	 * L'identifiant de version que le serveur de documents met en cache.
	 *
	 * Il doit changer dès que le contenu change, sans quoi l'éditeur rouvre
	 * la version précédente et l'enregistre par-dessus la nouvelle.
	 * Enregistrer crée un nouveau fichier — l'identifiant suffirait donc
	 * presque à lui seul ; le condensé du chemin et de la taille couvre le
	 * cas d'un fichier remplacé sur place.
	 *
	 * @param \App\Entity\File $file
	 *
	 * @return string
	 */
	public function key ( File $file ): string {
		return sprintf(
				'%d-%s',
				$file->getId(),
				substr( sha1( $file->getPath() . '|' . $file->getSize() ), 0, 16 )
		);
	}

	/**
	 * Une adresse absolue **telle que le serveur de documents peut l'atteindre**.
	 *
	 * Ce n'est pas toujours celle du navigateur : en développement comme dans
	 * bien des installations, le serveur de documents vit dans un conteneur
	 * pour qui « localhost » désigne lui-même. `ONLYOFFICE_PLATFORM_URL` dit
	 * alors sous quel nom la plateforme se laisse joindre depuis là-bas ;
	 * vide, on garde l'hôte de la requête en cours.
	 *
	 * @param string $route
	 * @param array  $parameters
	 *
	 * @return string
	 */
	private function absolute ( string $route, array $parameters ): string {
		if ( $this->platformUrl !== '' ) {
			return $this->platformUrl . $this->router->generate( $route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH );
		}

		return $this->router->generate( $route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL );
	}
}
