<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Usergroup;
use App\Repository\CategoryRepository;
use App\Service\FileManager;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Security\Core\Security;
use App\Service\UserGroupRelation;

class UsergroupType extends AbstractType {
	private $fileManager;
	private $security;
	private $userGroupRelation;

	/**
	 * DocumentType constructor.
	 *
	 * @param \App\Service\FileManager $fileManager
	 * @param \Symfony\Component\Security\Core\Security $security
	 * @param \App\Service\UserGroupRelation $userGroupRelation
	 */
	public function __construct ( FileManager $fileManager, Security $security, UserGroupRelation $userGroupRelation ) {
		$this->fileManager = $fileManager;
		$this->security = $security;
		$this->userGroupRelation = $userGroupRelation;
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm ( FormBuilderInterface $builder, array $options ) {
		$maxFileSize = $this->fileManager->fileUploadMaxSize( '5M' );

		$builder
				->add( 'name', TextType::class )
				->add( 'logofile', FileType::class, [
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
				->add( 'coverfile', FileType::class, [
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
				->add( 'description', TextareaType::class )
				->add( 'presentation', TextareaType::class, [
						'required' => FALSE,
				] )
				->add( 'visibility', ChoiceType::class, [
						'required'    => TRUE,
						'expanded'    => TRUE,
						'multiple'    => FALSE,
						'placeholder' => FALSE,
						'choices'     => [
								'pages.group.status.' . Usergroup::PUBLIC  => Usergroup::PUBLIC,
								'pages.group.status.' . Usergroup::PRIVATE => Usergroup::PRIVATE,
						],
				] )
				// La thématique dit de quoi le groupe parle ; la commission qui
				// le chapeaute dit de qui il dépend. Les deux servent à trier
				// la liste des groupes, et ne se recouvrent pas. Vocabulaire
				// fermé, tenu par les administrateurs. (#23)
				->add( 'categories', EntityType::class, [
						'class'         => Category::class,
						'required'      => FALSE,
						'expanded'      => TRUE,
						'multiple'      => TRUE,
						'choice_label'  => 'name',
						'query_builder' => function ( CategoryRepository $repository ) {
							return $repository->createQueryBuilder( 'c' )
											  ->orderBy( 'c.name', 'ASC' );
						},
				] );

		// Ajouter les champs de hiérarchie uniquement pour les administrateurs de la communauté
		$user = $this->security->getUser();
		if ( $user && $this->userGroupRelation->isCommunityAdmin( $user ) ) {
			$currentGroup = $options['data'] ?? null;
			
			$builder
				->add( 'parents', EntityType::class, [
					'class'        => Usergroup::class,
					'choice_label' => 'name',
					'multiple'     => true,
					'expanded'     => true,  // Utiliser des cases à cocher comme dans la page admin
					'required'     => false,
					'query_builder' => function ( $repository ) use ( $currentGroup ) {
						$qb = $repository->createQueryBuilder( 'g' )
								->where( 'g.isActive = :active' )
								->setParameter( 'active', true )
								->orderBy( 'g.name', 'ASC' );
						
						// Exclure le groupe actuel pour éviter l'auto-référence
						if ( $currentGroup && $currentGroup->getId() ) {
							$qb->andWhere( 'g.id != :currentGroup' )
							   ->setParameter( 'currentGroup', $currentGroup->getId() );
						}
						
						return $qb;
					},
					'attr' => [
						'class' => 'form-checkboxes-list',
						'data-help' => 'Sélectionnez les groupes parents de ce groupe'
					]
				] )
				->add( 'children', EntityType::class, [
					'class'        => Usergroup::class,
					'choice_label' => 'name',
					'multiple'     => true,
					'expanded'     => true,  // Utiliser des cases à cocher comme dans la page admin
					'required'     => false,
					'query_builder' => function ( $repository ) use ( $currentGroup ) {
						$qb = $repository->createQueryBuilder( 'g' )
								->where( 'g.isActive = :active' )
								->setParameter( 'active', true )
								->orderBy( 'g.name', 'ASC' );
						
						// Exclure le groupe actuel pour éviter l'auto-référence
						if ( $currentGroup && $currentGroup->getId() ) {
							$qb->andWhere( 'g.id != :currentGroup' )
							   ->setParameter( 'currentGroup', $currentGroup->getId() );
						}
						
						return $qb;
					},
					'attr' => [
						'class' => 'form-checkboxes-list',
						'data-help' => 'Sélectionnez les sous-groupes de ce groupe'
					]
				] );
		}

		$builder->add( 'submit', SubmitType::class );
	}

	/**
	 * {@inheritdoc}
	 */
	public function configureOptions ( OptionsResolver $resolver ) {
		$resolver->setDefaults( [
				'attr'       => [],
				'data_class' => Usergroup::class,
		] );
	}
}
