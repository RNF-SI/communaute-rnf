<?php

namespace App\Form;

use App\Entity\Document;
use App\Entity\DocumentFolder;
use App\Entity\DocumentTag;
use App\Repository\DocumentTagRepository;
use App\Service\FileManager;
use App\Service\FileMimeManager;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Contracts\Translation\TranslatorInterface;

class DocumentType extends AbstractType {
	private $fileManager;

	/**
	 * DocumentType constructor.
	 *
	 * @param \App\Service\FileManager $fileManager
	 */
	private $translator;

	public function __construct ( FileManager $fileManager, TranslatorInterface $translator ) {
		$this->fileManager = $fileManager;
		$this->translator  = $translator;
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm ( FormBuilderInterface $builder, array $options ) {
		$maxFileSize = $this->fileManager->fileUploadMaxSize( '50M' );

		/**
		 * @var Document $document
		 */
		$document = $builder->getData();

		// Un document sans fichier n'a pas de raison d'être : au dépôt, le
		// fichier est exigé. À la modification, son absence veut dire « garde
		// celui qui est déjà là », et rien d'autre. (#40)
		$fileConstraints = [
				new File( [
						'maxSize'          => $maxFileSize,
						'mimeTypes'        => array_merge(
								FileMimeManager::getMimes( FileMimeManager::DOCUMENTS ),
								FileMimeManager::getMimes( FileMimeManager::PDF ),
								FileMimeManager::getMimes( FileMimeManager::IMAGES ),
								FileMimeManager::getMimes( FileMimeManager::ARCHIVES )
								),
						'mimeTypesMessage' => 'filetype_incorrect',
				] ),
		];

		// Le fichier n'est plus exigé par le navigateur : un lien peut en tenir
		// lieu (#42). C'est le serveur qui refuse un dépôt sans l'un ni
		// l'autre — il le faisait déjà, l'attribut HTML ne tenant que dans le
		// navigateur et une requête vidée par PHP n'en portant pas trace.
		$builder
				->add( 'filefile', FileType::class, [
						'required'    => FALSE,
						'mapped'      => FALSE,
						'attr'        => [ 'data-max-size' => $this->fileManager->formatSize( $maxFileSize ) ],
						'constraints' => $fileConstraints,
				] )
				->add( 'url', UrlType::class, [
						'required'         => FALSE,
						'default_protocol' => 'https',
						'attr'             => [ 'maxlength' => 2048 ],
						'constraints'      => [
								// Ni `javascript:`, ni `file:`, ni `data:` : le lien
								// s'ouvre d'un clic depuis la fiche.
								new Url( [ 'protocols' => [ 'http', 'https' ], 'message' => 'document_url_invalid' ] ),
								new Length( [ 'max' => 2048 ] ),
						],
				] )
				->add( 'folderTitle', TextType::class, [
						// Le chemin complet, pour qu'un sous-dossier soit
						// désignable et reconnaissable. (#8)
						'data'     => !empty( $document->getFolder() ) ? $document->getFolder()->getPath() : '',
						'required' => FALSE,
						'mapped'   => FALSE,
						'attr'     => [ 'data-list' => empty( $options[ 'folders' ] )
								? ''
								: implode( ', ', array_map( function ( DocumentFolder $folder ) {
									return $folder->getPath();
								}, $options[ 'folders' ] ) ),
						],
				] )
				->add( 'title', TextType::class, [
						'required' => FALSE,
						'attr'     => [ 'maxlength' => 100 ],
				] )
				->add( 'description', TextareaType::class, [
						'required' => FALSE,
						'attr'     => [ 'maxlength' => 500, 'rows' => 3 ],
				] )
				->add( 'tags', EntityType::class, [
						// Une liste fermée, tenue par les administrateurs : on
						// choisit dedans, on n'y ajoute pas au passage. (#26)
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
				->add( 'submit', SubmitType::class );

		// Un fichier ou un lien : il faut l'un des deux, et pas les deux au
		// dépôt. À la modification, déposer un fichier remplace le lien, et
		// donner un lien remplace le fichier (le contrôleur s'en charge) ;
		// seul compte qu'il reste quelque chose. (#42)
		// L'adresse d'avant la soumission : à la modification, on n'interdit
		// que de vider un lien. Un document hérité sans fichier (il en existe)
		// doit rester modifiable sans qu'on lui en demande un. (#40)
		$originalUrl = $document instanceof Document ? $document->getUrl() : NULL;

		$builder->addEventListener( FormEvents::POST_SUBMIT, function ( FormEvent $event ) use ( $options, $originalUrl ) {
			$form     = $event->getForm();
			$document = $form->getData();
			$upload   = $form->get( 'filefile' )->getData();
			$url      = $document instanceof Document ? $document->getUrl() : NULL;

			if ( $options[ 'require_file' ] && !empty( $upload ) && !empty( $url ) ) {
				$form->get( 'url' )->addError( new FormError(
						$this->translator->trans( 'document_file_and_link', [], 'validators' )
				) );

				return;
			}

			$existing = $document instanceof Document && !empty( $document->getFile() );
			$emptied  = !empty( $originalUrl ) && !$existing;

			if ( empty( $upload ) && empty( $url ) && ( $options[ 'require_file' ] || $emptied ) ) {
				$form->get( 'filefile' )->addError( new FormError(
						$this->translator->trans( 'file_required', [], 'validators' )
				) );
			}
		} );
	}

	/**
	 * {@inheritdoc}
	 */
	public function configureOptions ( OptionsResolver $resolver ) {
		$resolver->setDefaults( [
				'attr'         => [],
				'data_class'   => Document::class,
				'folders'      => '',
				'require_file' => FALSE,
		] );
		$resolver->setAllowedTypes( 'require_file', 'bool' );
	}
}
