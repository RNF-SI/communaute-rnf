<?php

namespace App\Twig;

use App\Entity\Usergroup;
use App\Service\MentionParser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Turns the « @Prénom Nom » written in a message into links to the directory,
 * at display time. (#37)
 *
 * Nothing is rewritten in the database: a member who changes their name keeps
 * their old mentions working, and a mention that no longer matches anybody
 * stays readable as the text it always was.
 */
class MentionExtension extends AbstractExtension {
	/**
	 * @var \App\Service\MentionParser
	 */
	private $mentions;

	public function __construct ( MentionParser $mentions ) {
		$this->mentions = $mentions;
	}

	public function getFilters () {
		return [
				new TwigFilter( 'mentions', [ $this, 'render' ], [ 'is_safe' => [ 'html' ] ] ),
		];
	}

	/**
	 * @param string                     $body
	 * @param \App\Entity\Usergroup|null $group
	 *
	 * @return string
	 */
	public function render ( $body, Usergroup $group = NULL ) {
		return $this->mentions->render( $body, $group );
	}
}
