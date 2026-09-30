<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Discussion;
use App\Entity\Document;
use App\Entity\Page;
use App\Entity\Usergroup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class UrlManager {
	private $manager;

	/**
	 * @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface
	 */
	private $urlGenerator;

	public function __construct ( EntityManagerInterface $manager, UrlGeneratorInterface $urlGenerator ) {
		$this->manager      = $manager;
		$this->urlGenerator = $urlGenerator;
	}

	public function userUrlFromId ( $id ) {
		return $this->urlGenerator->generate( 'member', [ 'user_id' => $id ] );
	}

	public function usergroupUrlFromId ( $id ) {
		$group = $this->manager->getRepository( Usergroup::class )->findOneBy( [ 'id' => $id ] );

		if ( empty( $group ) ) {
			return '';
		}

		return $this->urlGenerator->generate( 'group_index', [ 'groupSlug' => $group->getSlug() ] );
	}

	public function pageUrlFromId ( $id ) {
		$page = $this->manager->getRepository( Page::class )->findOneBy( [ 'id' => $id ] );

		if ( empty( $page ) || empty( $page->getUsergroup() ) || empty( $page->getSlug() ) ) {
			return '';
		}

		return $this->urlGenerator->generate( 'group_page_index', [
				'groupSlug' => $page->getUsergroup()->getSlug(),
				'pageSlug'  => $page->getSlug(),
		] );
	}

	public function discussionUrlFromId ( $id ) {
		$discussion = $this->manager->getRepository( Discussion::class )->findOneBy( [ 'id' => $id ] );

		if ( empty( $discussion ) ) {
			return '';
		}

		return $this->urlGenerator->generate( 'group_discussion_index', [
				'groupSlug'      => $discussion->getUsergroup()->getSlug(),
				'discussionUuid' => $discussion->getUuid(),
		] );
	}

	public function articleUrlFromId ( $id ) {
		$article = $this->manager->getRepository( Article::class )->findOneBy( [ 'id' => $id ] );

		if ( empty( $article ) || empty( $article->getUsergroup() ) || empty( $article->getSlug() ) ) {
			return '';
		}

		return $this->urlGenerator->generate( 'group_article_index', [
				'groupSlug'   => $article->getUsergroup()->getSlug(),
				'articleSlug' => $article->getSlug(),
		] );
	}

	public function documentUrlFromId ( $id ) {
		$document = $this->manager->getRepository( Document::class )->findOneBy( [ 'id' => $id ] );

		if ( empty( $document ) || empty( $document->getUsergroup() ) ) {
			return '';
		}

		// La fiche, pas le fichier : c'est l'adresse d'un document depuis #32,
		// et un document sans fichier y dit ce qu'il en est au lieu d'une
		// erreur. (#42)
		return $this->urlGenerator->generate( 'group_document_index', [
				'groupSlug'  => $document->getUsergroup()->getSlug(),
				'documentId' => $id,
		] );
	}
}
