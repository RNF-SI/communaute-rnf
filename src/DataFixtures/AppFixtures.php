<?php

namespace App\DataFixtures;

use App\Command\ImportSkillsCommand;
use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\Document;
use App\Entity\LogEvent;
use App\Entity\Page;
use App\Entity\Skill;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Service\SlugGenerator;
use Ramsey\Uuid\Uuid;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class AppFixtures extends Fixture {
	private $passwordEncoder;
	private $slugGenerator;

	public function __construct ( UserPasswordEncoderInterface $passwordEncoder, SlugGenerator $slugGenerator ) {
		$this->passwordEncoder = $passwordEncoder;
		$this->slugGenerator   = $slugGenerator;
	}

	public function load ( ObjectManager $manager ) {
		$countries        = [ 'fr_FR', 'en_GB', 'es_ES', 'en_US', 'de_DE' ];

		/**
		 * SKILLS
		 *
		 * The fixtures purge every table, import:skills included. Building the
		 * list here keeps a freshly loaded environment usable straight away.
		 */
		$skills = [];
		foreach ( ImportSkillsCommand::SLUGS as $slug ) {
			$skill = new Skill();
			$skill->setSlug( $slug );

			$manager->persist( $skill );

			$skills[] = $skill;
		}
		$manager->flush();

		/**
		 * USERS
		 */
		$users = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$country = $countries[ rand( 0, count( $countries ) - 1 ) ];
			$faker   = Faker\Factory::create( $country );

			$user = new User();
			$user->setCreatedAt( new \DateTime() );
			$name = $faker->firstName() . ' ' . $faker->lastName();
			$user->setName( $name );
			$user->setDisplayName( $name );

			$user->setEmail( sprintf( 'test-%d@test.com', $i ) );
			$user->setPassword( $this->passwordEncoder->encodePassword(
				$user,
				'test'
			) );
			$user->setRoles( [ User::ROLE_USER ] );
			$user->setStatus( User::STATUS_ACTIVE );

			$user->setCountry( substr( $country, 3, 2 ) );
			$user->setHasAgreedTermsOfUse(true);

			// A profile without skills makes the directory and its filters
			// impossible to try out.
			shuffle( $skills );
			for ( $j = 0, $n = rand( 0, 4 ); $j < $n; $j++ ) {
				$user->addSkill( $skills[ $j ] );
			}

			$user->setPresentation( mb_substr( $faker->sentence( 3 ), 0, 32 ) );
			$user->setBio( implode( ' ', $faker->paragraphs( 2 ) ) );
			$user->setCity( $faker->city() );

			$manager->persist( $user );
			$manager->flush();

			$users[] = $user;
		}

		/**
		 * A known account to sign in with locally. The form login is not wired
		 * into the firewall today, but the account is needed as soon as it is,
		 * and it makes the administration reachable in a test environment.
		 */
		$admin = new User();
		$admin->setCreatedAt( new \DateTime() );
		$admin->setName( 'Admin Test' );
		$admin->setDisplayName( 'Admin Test' );
		$admin->setEmail( 'admin@example.org' );
		$admin->setPassword( $this->passwordEncoder->encodePassword( $admin, 'test' ) );
		$admin->setRoles( [ User::ROLE_USER, User::ROLE_ADMIN ] );
		$admin->setStatus( User::STATUS_ACTIVE );
		$admin->setCountry( 'FR' );
		$admin->setHasAgreedTermsOfUse( TRUE );

		$manager->persist( $admin );
		$manager->flush();

		$users[] = $admin;

		$faker = Faker\Factory::create( 'fr_FR' );

		/**
		 * CATEGORIES
		 */
		$categories = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$category = new Category();
			$category->setName( $faker->sentence( 2 ) );
			$category->setDescription( $faker->sentence( 30 ) );

			$manager->persist( $category );
			$manager->flush();

			$categories[] = $category;
		}

		/**
		 * GROUPS
		 */
		$groups = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$group = new Usergroup();
			$group->setName( mb_convert_case( implode( ' ', $faker->words( rand( 1, 3 ) ) ), MB_CASE_TITLE ) );

			$group->setSlug( $this->slugGenerator->generateSlug( $group->getName(), Usergroup::class, 'slug' ) );
			$group->setDescription( $faker->sentence( 30 ) );
			$group->setPresentation( '<p>' . implode( '</p><p>', $faker->paragraphs( 10 ) ) . '</p>' );
			$group->setVisibility( empty( rand( 0, 1 ) ) ? Usergroup::PRIVATE : Usergroup::PUBLIC );
			$group->setCreatedAt( new \DateTime() );
			$group->setIsActive(true);

			$manager->persist( $group );
			$manager->flush();

			// Log Event

			$log = new LogEvent();
			$log->setType( LogEvent::GROUP_CREATE );
			$log->setUser( $users[0] );
			$log->setUsergroup( $group );
			$log->setCreatedAt( new \DateTime() );
			$log->setData( [ 'name' => $group->getName() ] );
			$manager->persist( $log );
			$manager->flush();

			for ( $j = 0, $n = rand( 1, 3 ); $j < $n; $j++ ) {
				$group->addCategory( $categories[ rand( 0, count( $categories ) - 1 ) ] );
			}

			$groupUsers = [];

			for ( $j = 0, $n = rand( 3, 20 ); $j < $n; $j++ ) {
				$userId = rand( 0, count( $users ) - 1 );
				if ( !in_array( $userId, $groupUsers ) ) {

					$membership = new UsergroupMembership();
					$membership->setUsergroup( $group );
					$membership->setUser( $users[ $userId ] );
					$membership->setJoinedAt( new \DateTime() );

					if ( $j < 1 ) {
						$membership->setRole( UsergroupMembership::ROLE_ADMIN );
						$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
					}
					else {
						$membership->setRole( UsergroupMembership::ROLE_USER );
						$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
					}

					$manager->persist( $membership );

					$groupUsers[] = $userId;
				}
			}

			for ( $j = 0, $n = rand( 3, 10 ); $j < $n; $j++ ) {
				$page = new Page();
				$page->setTitle( $faker->sentence( rand( 3, 10 ) ) );
				$page->setSlug( $this->slugGenerator->generateSlug( $page->getTitle() ) );

				$page->setUsergroup( $group );
				$page->setAuthor( $users[ rand( 0, count( $users ) - 1 ) ] );
				$page->setBody( '<p>' . implode( '</p><p>', $faker->paragraphs( rand( 3, 10 ), FALSE ) ) . '</p>' );

				$page->setCreatedAt( new \DateTime() );

				$manager->persist( $page );
				$manager->flush();
			}

			/**
			 * MEMBERS OF THIS GROUP
			 *
			 * Discussions, articles and documents must come from people who
			 * actually belong to the group, otherwise the listings and the
			 * access rules cannot be tried out.
			 */
			$members = array_map( function ( $userId ) use ( $users ) {
				return $users[ $userId ];
			}, $groupUsers );

			if ( empty( $members ) ) {
				$members = [ $users[ 0 ] ];
			}

			/**
			 * DISCUSSIONS
			 */
			for ( $j = 0, $n = rand( 2, 5 ); $j < $n; $j++ ) {
				$openedAt = $faker->dateTimeBetween( '-1 year', '-1 month' );

				$discussion = new Discussion();
				$discussion->setUuid( Uuid::uuid4() );
				$discussion->setTitle( mb_substr( $faker->sentence( rand( 3, 8 ) ), 0, 100 ) );
				$discussion->setUsergroup( $group );
				$discussion->setAuthor( $members[ array_rand( $members ) ] );
				$discussion->setCreatedAt( $openedAt );

				$manager->persist( $discussion );

				$lastMessageAt = $openedAt;

				for ( $k = 0, $messages = rand( 1, 8 ); $k < $messages; $k++ ) {
					$lastMessageAt = $faker->dateTimeBetween( $lastMessageAt, 'now' );

					$message = new DiscussionMessage();
					$message->setDiscussion( $discussion );
					$message->setAuthor( $members[ array_rand( $members ) ] );
					$message->setBody( '<p>' . implode( '</p><p>', $faker->paragraphs( rand( 1, 3 ), FALSE ) ) . '</p>' );
					$message->setCreatedAt( $lastMessageAt );

					$manager->persist( $message );
				}

				$discussion->setActiveAt( $lastMessageAt );

				// One discussion out of five is left empty, which is the case
				// that used to break the listings. (#3)
				if ( $j === 0 ) {
					$discussion->setActiveAt( $openedAt );
				}
			}

			/**
			 * ARTICLES
			 */
			for ( $j = 0, $n = rand( 1, 4 ); $j < $n; $j++ ) {
				$article = new Article();
				$article->setTitle( mb_substr( $faker->sentence( rand( 3, 8 ) ), 0, 100 ) );
				$article->setSlug( $this->slugGenerator->generateSlug( $article->getTitle(), Article::class, 'slug' ) );
				$article->setUsergroup( $group );
				$article->setAuthor( $members[ array_rand( $members ) ] );
				$article->setBody( '<p>' . implode( '</p><p>', $faker->paragraphs( rand( 3, 8 ), FALSE ) ) . '</p>' );
				$article->setCreatedAt( $faker->dateTimeBetween( '-1 year', 'now' ) );

				$manager->persist( $article );
			}

			/**
			 * DOCUMENTS
			 *
			 * No file is attached: writing real files would mean going through
			 * the Gaufrette storage, which fixtures have no business doing.
			 * Titles and descriptions are enough to try out the listings, the
			 * search and the descriptions of #7.
			 */
			for ( $j = 0, $n = rand( 3, 10 ); $j < $n; $j++ ) {
				$document = new Document();
				$document->setTitle( mb_substr( $faker->sentence( rand( 2, 6 ) ), 0, 100 ) );
				$document->setSlug( $this->slugGenerator->generateSlug( $document->getTitle(), Document::class, 'slug' ) );
				$document->setUsergroup( $group );
				$document->setUser( $members[ array_rand( $members ) ] );
				$document->setCreatedAt( $faker->dateTimeBetween( '-1 year', 'now' ) );

				// Not every document is described, that is the point of the
				// field being optional.
				if ( rand( 0, 2 ) > 0 ) {
					$document->setDescription( $faker->sentence( rand( 8, 20 ) ) );
				}

				$manager->persist( $document );
			}

			$manager->flush();

			$manager->persist( $group );
			$manager->flush();

			$groups[] = $group;
		}
	}
}
