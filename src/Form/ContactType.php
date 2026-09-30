<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Le formulaire de contact (#45). Deux champs, et deux seulement : qui écrit
 * est déjà connu — le nom et l'adresse viennent du compte, on ne les saisit
 * pas. Les demander reviendrait à laisser écrire sous un autre nom.
 */
class ContactType extends AbstractType {
	/**
	 * {@inheritdoc}
	 */
	public function buildForm ( FormBuilderInterface $builder, array $options ) {
		$builder
				->add( 'subject', TextType::class, [
						'attr'        => [ 'maxlength' => 150 ],
						'constraints' => [
								new NotBlank( [ 'message' => 'contact_subject_required' ] ),
								new Length( [ 'max' => 150, 'maxMessage' => 'contact_subject_too_long' ] ),
						],
				] )
				->add( 'message', TextareaType::class, [
						'attr'        => [ 'rows' => 10, 'maxlength' => 5000 ],
						'constraints' => [
								new NotBlank( [ 'message' => 'contact_message_required' ] ),
								new Length( [ 'max' => 5000, 'maxMessage' => 'contact_message_too_long' ] ),
						],
				] )
				->add( 'submit', SubmitType::class );
	}

	/**
	 * {@inheritdoc}
	 */
	public function configureOptions ( OptionsResolver $resolver ) {
		$resolver->setDefaults( [
				'data_class' => NULL,
		] );
	}
}
