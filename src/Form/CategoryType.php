<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Ajouter une thématique au vocabulaire des groupes. (#23)
 */
class CategoryType extends AbstractType {
	/**
	 * {@inheritdoc}
	 */
	public function buildForm ( FormBuilderInterface $builder, array $options ) {
		$builder
				// Non mappé : le contrôleur contrôle l'unicité avant de créer
				// quoi que ce soit, comme pour les étiquettes de documents.
				->add( 'name', TextType::class, [
						'mapped' => FALSE,
						'attr'   => [ 'maxlength' => 60 ],
				] )
				->add( 'submit', SubmitType::class );
	}

	/**
	 * {@inheritdoc}
	 */
	public function configureOptions ( OptionsResolver $resolver ) {
		$resolver->setDefaults( [
				'attr' => [],
		] );
	}
}
