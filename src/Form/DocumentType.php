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
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class DocumentType extends AbstractType {
	private $fileManager;

	/**
	 * DocumentType constructor.
	 *
	 * @param \App\Service\FileManager $fileManager
	 */
	public function __construct ( FileManager $fileManager ) {
		$this->fileManager = $fileManager;
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

		$builder
				->add( 'filefile', FileType::class, [
						'required'    => FALSE,
						'mapped'      => FALSE,
						'attr'        => [ 'data-max-size' => $this->fileManager->formatSize( $maxFileSize ) ],
						'constraints' => [
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
	}

	/**
	 * {@inheritdoc}
	 */
	public function configureOptions ( OptionsResolver $resolver ) {
		$resolver->setDefaults( [
				'attr'       => [],
				'data_class' => Document::class,
				'folders'    => '',
		] );
	}
}
