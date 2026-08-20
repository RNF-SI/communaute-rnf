<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Le seul titre de la discussion : renommer ne touche pas aux messages, et
 * les rouvrir dans un formulaire d'édition inviterait à les réécrire.
 */
class DiscussionTitleType extends AbstractType {
	/**
	 * {@inheritdoc}
	 */
	public function buildForm ( FormBuilderInterface $builder, array $options ) {
		$builder
				// Non mappé : une soumission vide ne doit pas vider le titre
				// de la discussion en mémoire avant même d'être refusée.
				->add( 'title', TextType::class, [
						'mapped' => FALSE,
						'attr'   => [ 'maxlength' => 100 ],
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
