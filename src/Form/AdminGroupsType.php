<?php

namespace App\Form;

use App\Service\FileManager;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Repository\UsergroupRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;


class AdminGroupsType extends AbstractType {
	/**
	 * AdminGroupsType constructor.
	 */
	public function __construct (FileManager $fileManager) {
		$this->fileManager = $fileManager;
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm ( FormBuilderInterface $builder, array $options ) {
		$maxFileSize = $this->fileManager->fileUploadMaxSize( '500k' );

		$builder
				->add( 'frontgroupfile', FileType::class, [
					'required'    => FALSE,
					'mapped'      => FALSE,
					'attr'        => [ 'data-max-size' => $this->fileManager->formatSize( $maxFileSize ) ],
					'constraints' => [
							new File( [
									'maxSize'          => $maxFileSize,
									'mimeTypes'        => [
											'image/png',
											'image/jpeg',
									],
									'mimeTypesMessage' => 'filetype_incorrect',
							] ),
					],
				] )
				->add( 'importantGroups', EntityType::class, [
					'class'                     => Usergroup::class,
					'required'                  => false,
					'mapped'                    => false,
					'expanded'                  => true,
					'multiple'                  => true,
					'query_builder'             => function ( UsergroupRepository $repository ) {
						return $repository->createQueryBuilder( 'u' )
											->orderBy( 'u.name', 'ASC' );
					},
					'choice_label'              => 'name',
					'data'                      => $options['important_groups'],
				] )
				->add( 'submit', SubmitType::class );
	}

	/**
	 * {@inheritdoc}
	 */
	public function configureOptions ( OptionsResolver $resolver ) {
		$resolver->setDefaults( [
			'important_groups' => [],
		] );
	}
}
