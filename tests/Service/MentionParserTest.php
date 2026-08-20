<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\Usergroup;
use App\Repository\UserRepository;
use App\Service\MentionParser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Issue #37 — reconnaître qui a été nommé dans un message, et seulement qui
 * l'a été.
 */
class MentionParserTest extends TestCase {
	/**
	 * @param int    $id
	 * @param string $name
	 * @param string $displayName
	 *
	 * @return \App\Entity\User
	 */
	private function user ( $id, $name, $displayName = NULL ) {
		$user = new User();
		$user->setName( $name );
		$user->setDisplayName( $displayName );

		$property = new ReflectionProperty( User::class, 'id' );
		$property->setAccessible( TRUE );
		$property->setValue( $user, $id );

		return $user;
	}

	/**
	 * A parser whose group answers with the given members, whatever is asked.
	 *
	 * @param \App\Entity\User[] $members
	 *
	 * @return \App\Service\MentionParser
	 */
	private function parser ( array $members ) {
		$repository = $this->createMock( UserRepository::class );
		$repository->method( 'findMentionable' )
				   ->willReturnCallback( function ( Usergroup $group, array $names ) use ( $members ) {
					   $found = [];

					   foreach ( $members as $member ) {
						   foreach ( $names as $name ) {
							   // The database compares without case nor
							   // accents; so does this stand-in.
							   if ( $this->same( $name, $member->getName() )
									|| $this->same( $name, $member->getDisplayName() ) ) {
								   $found[] = $member;
								   continue 2;
							   }
						   }
					   }

					   return $found;
				   } );

		$manager = $this->createMock( EntityManagerInterface::class );
		$manager->method( 'getRepository' )->willReturn( $repository );

		$router = $this->createMock( UrlGeneratorInterface::class );
		$router->method( 'generate' )
			   ->willReturnCallback( function ( $route, $parameters = [] ) {
				   return '/members/' . $parameters[ 'user_id' ];
			   } );

		return new MentionParser( $manager, $router );
	}

	/**
	 * @param string $left
	 * @param string $right
	 *
	 * @return bool
	 */
	private function same ( $left, $right ) {
		$fold = function ( $value ) {
			return strtr( mb_strtolower( trim( (string) $value ) ), [ 'é' => 'e', 'è' => 'e', 'ê' => 'e' ] );
		};

		return ( $right !== NULL ) && ( $fold( $left ) === $fold( $right ) ) && ( trim( (string) $right ) !== '' );
	}

	/**
	 * @return \App\Entity\Usergroup
	 */
	private function group () {
		$group = new Usergroup();
		$group->setName( 'Test group' );
		$group->setSlug( 'test-group' );

		return $group;
	}

	/**
	 * @param \App\Entity\User[] $found
	 *
	 * @return int[]
	 */
	private function ids ( array $found ) {
		return array_map( function ( User $user ) {
			return $user->getId();
		}, $found );
	}

	public function testAFullNameIsRecognised () {
		$jeanne = $this->user( 1, 'Jeanne Reserve' );

		$found = $this->parser( [ $jeanne ] )->find( '<p>Bonjour @Jeanne Reserve, une idée ?</p>', $this->group() );

		$this->assertEquals( [ 1 ], $this->ids( $found ) );
	}

	public function testTheLongestNameWins () {
		$jeanne = $this->user( 1, 'Jeanne' );
		$marais = $this->user( 2, 'Jeanne Reserve' );

		$found = $this->parser( [ $jeanne, $marais ] )->find( '<p>@Jeanne Reserve ?</p>', $this->group() );

		$this->assertEquals(
				[ 2 ],
				$this->ids( $found ),
				'Assert « @Jeanne Reserve » does not read as « @Jeanne » followed by a word'
		);
	}

	public function testCaseAndAccentsDoNotMatter () {
		$found = $this->parser( [ $this->user( 1, 'Jeanne Réserve' ) ] )
					  ->find( '<p>@jeanne reserve</p>', $this->group() );

		$this->assertEquals( [ 1 ], $this->ids( $found ) );
	}

	public function testANameFollowedByPunctuationIsRecognised () {
		$found = $this->parser( [ $this->user( 1, 'Jeanne Reserve' ) ] )
					  ->find( '<p>Merci @Jeanne Reserve.</p>', $this->group() );

		$this->assertEquals( [ 1 ], $this->ids( $found ) );
	}

	public function testAnEmailAddressIsNotAMention () {
		$found = $this->parser( [ $this->user( 1, 'Jeanne Reserve' ) ] )
					  ->find( '<p>Écrivez à contact@Jeanne Reserve</p>', $this->group() );

		$this->assertEquals(
				[],
				$this->ids( $found ),
				'Assert an address written in a message does not warn anybody'
		);
	}

	public function testSomebodyOutsideTheGroupIsNotMentioned () {
		$found = $this->parser( [] )->find( '<p>@Paul Ailleurs</p>', $this->group() );

		$this->assertEquals( [], $this->ids( $found ) );
	}

	public function testTheAuthorIsNeverMentioned () {
		$jeanne = $this->user( 1, 'Jeanne Reserve' );

		$found = $this->parser( [ $jeanne ] )
					  ->find( '<p>note pour @Jeanne Reserve</p>', $this->group(), $jeanne );

		$this->assertEquals(
				[],
				$this->ids( $found ),
				'Assert writing your own name does not warn you about yourself'
		);
	}

	public function testEachMemberIsFoundOnce () {
		$found = $this->parser( [ $this->user( 1, 'Jeanne Reserve' ) ] )
					  ->find( '<p>@Jeanne Reserve et encore @Jeanne Reserve</p>', $this->group() );

		$this->assertEquals( [ 1 ], $this->ids( $found ) );
	}

	public function testWithoutAGroupNobodyIsMentioned () {
		$this->assertEquals( [], $this->parser( [ $this->user( 1, 'Jeanne Reserve' ) ] )->find( '@Jeanne Reserve' ) );
	}

	public function testAMentionBecomesALink () {
		$rendered = $this->parser( [ $this->user( 7, 'Jeanne Reserve' ) ] )
						 ->render( '<p>Bonjour @Jeanne Reserve, une idée ?</p>', $this->group() );

		$this->assertEquals(
				'<p>Bonjour <a class="mention" href="/members/7">@Jeanne Reserve</a>, une idée ?</p>',
				$rendered
		);
	}

	public function testWhatFollowsTheNameIsLeftAlone () {
		$rendered = $this->parser( [ $this->user( 7, 'Jeanne' ) ] )
						 ->render( '<p>@Jeanne merci beaucoup</p>', $this->group() );

		$this->assertEquals(
				'<p><a class="mention" href="/members/7">@Jeanne</a> merci beaucoup</p>',
				$rendered
		);
	}

	public function testAnUnknownNameIsLeftAsText () {
		$body = '<p>@Personne Inconnue</p>';

		$this->assertEquals( $body, $this->parser( [] )->render( $body, $this->group() ) );
	}

	public function testAnAttributeIsNeverRewritten () {
		$body = '<p><a href="mailto:contact@Jeanne Reserve">écrire</a> @Jeanne Reserve</p>';

		$rendered = $this->parser( [ $this->user( 7, 'Jeanne Reserve' ) ] )->render( $body, $this->group() );

		$this->assertStringContainsString(
				'href="mailto:contact@Jeanne Reserve"',
				$rendered,
				'Assert a mention is never looked for inside markup'
		);
		$this->assertStringContainsString( '<a class="mention" href="/members/7">@Jeanne Reserve</a>', $rendered );
	}

	public function testADisplayNameAlsoAnswers () {
		$found = $this->parser( [ $this->user( 1, 'Jeanne Reserve', 'Jeanne R.' ) ] )
					  ->find( '<p>@Jeanne R. peux-tu regarder ?</p>', $this->group() );

		$this->assertEquals( [ 1 ], $this->ids( $found ) );
	}

	public function testAMessageWithoutAnyAtCostsNothing () {
		$manager = $this->createMock( EntityManagerInterface::class );
		$manager->expects( $this->never() )->method( 'getRepository' );

		$parser = new MentionParser( $manager, $this->createMock( UrlGeneratorInterface::class ) );

		$this->assertEquals( '<p>Bonjour</p>', $parser->render( '<p>Bonjour</p>', $this->group() ) );
	}
}
