<?php

namespace App\Form;

use App\Entity\Skill;
use App\Entity\User;
use App\Repository\SkillRepository;
use App\Service\FileManager;
use App\Service\RnfReserves;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class UserProfileType extends AbstractType {
	private $fileManager;

	private $reserves;

	/**
	 * @param \App\Service\FileManager $fileManager
	 * @param \App\Service\RnfReserves $reserves
	 */
	public function __construct ( FileManager $fileManager, RnfReserves $reserves ) {
		$this->fileManager = $fileManager;
		$this->reserves    = $reserves;
	}

	/**
	 * {@inheritdoc}
	 */
	public function buildForm ( FormBuilderInterface $builder, array $options) {
		$maxFileSize = $this->fileManager->fileUploadMaxSize( '5M' );

		/**
		 * @var \App\Entity\User $user
		 */
		$user = $builder->getData();

		// GeoNature is the reference for the identity of an account coming from
		// the single sign-on: it is rewritten at every login. Offering these
		// two fields for editing would only promise a change that does not
		// survive the next connection. (#36)
		$identityComesFromRnf = ( $user instanceof User ) && !empty( $user->getRnfIdRole() );

		// Les réserves suivies viennent de GeoNature dès que l'export est
		// configuré : les proposer à la saisie promettrait alors une
		// modification que la prochaine synchronisation écraserait. Sans
		// jeton, elles restent saisies à la main — mieux vaut une saisie
		// manuelle qu'une case vide. (#28)
		$reservesComeFromRnf = $this->reserves->feedsProfileOf( $user );

		$builder
				->add( 'name', TextType::class, [
						'disabled' => $identityComesFromRnf,
				] )
				->add( 'displayname', TextType::class, [
						'disabled' => $identityComesFromRnf,
				] )
				->add( 'avatarfile', FileType::class, [
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
				->add( 'city', TextType::class, [
						'required' => FALSE,
				] )
				->add( 'zipcode', TextType::class, [
						'required' => FALSE,
				] )
				->add( 'country', CountryType::class, [
						'required' => FALSE,
				] )
				->add( 'latitude', HiddenType::class, [
						'required' => FALSE,
				] )
				->add( 'longitude', HiddenType::class, [
						'required' => FALSE,
				] )
				->add( 'jobTitle', TextType::class, [
						'required' => FALSE,
						'attr'     => [ 'maxlength' => 100 ],
				] )
				->add( 'organisation', TextType::class, [
						'required' => FALSE,
						'attr'     => [ 'maxlength' => 150 ],
				] )
				->add( 'reserves', TextType::class, [
						'required' => FALSE,
						'disabled' => $reservesComeFromRnf,
						'attr'     => [ 'maxlength' => 255 ],
				] )
				->add( 'phone', TextType::class, [
						'required' => FALSE,
						'attr'     => [ 'maxlength' => 30 ],
				] )
				->add( 'emailVisible', CheckboxType::class, [
						'required' => FALSE,
				] )
				->add( 'presentation', TextType::class, [
						'required' => FALSE,
						'attr'     => [ 'maxlength' => 32 ],
				] )
				->add( 'skills', EntityType::class, [
						'class'                     => Skill::class,
						'required'                  => FALSE,
						'expanded'                  => TRUE,
						'multiple'                  => TRUE,
						'query_builder'             => function ( SkillRepository $repository ) {
							return $repository->createQueryBuilder( 'u' )
											  ->orderBy( 'u.slug', 'ASC' );
						},
						'choice_translation_domain' => 'skills',
						'choice_label'              => 'slug',
				] )
				->add( 'submit', SubmitType::class );
	}

	/**
	 * {@inheritdoc}
	 */
	public function configureOptions ( OptionsResolver $resolver ) {
		$resolver->setDefaults( [
				'attr'       => [],
				'data_class' => User::class,
		] );
	}
}
