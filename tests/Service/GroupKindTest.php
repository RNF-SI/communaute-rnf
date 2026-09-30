<?php

namespace App\Tests\Service;

use App\Service\GroupKind;
use PHPUnit\Framework\TestCase;

/**
 * Issue #42 (2) — ce qu'est un groupe se lit dans son nom.
 */
class GroupKindTest extends TestCase {
	/**
	 * @return array[]
	 */
	public function names () {
		return [
				[ 'Commission scientifique', GroupKind::COMMISSION ],
				[ 'commission éducation', GroupKind::COMMISSION ],
				[ 'Pôle zones humides', GroupKind::POLE ],
				[ 'POLE littoral', GroupKind::POLE ],
				[ 'Groupe de travail forêt', GroupKind::GROUPE ],
				[ 'GT gestion', GroupKind::GROUPE ],
				[ 'Groupe-projet dunes', GroupKind::GROUPE ],
				[ 'Atelier plans de gestion', GroupKind::ATELIER ],
				[ '  Pôle   espèces', GroupKind::POLE ],
				// Le premier mot seulement : un mot ailleurs dans le nom ne compte pas.
				[ 'Suivis avifaune du pôle nord', NULL ],
				[ 'Commissionnement', NULL ],
				[ 'Espèces exotiques envahissantes', NULL ],
				[ '', NULL ],
		];
	}

	/**
	 * @dataProvider names
	 *
	 * @param string      $name
	 * @param string|null $kind
	 */
	public function testTheFirstWordDecides ( $name, $kind ) {
		$this->assertSame( $kind, GroupKind::of( $name ) );
	}
}
