<?php

namespace App\DataFixtures;

use App\Command\ImportSkillsCommand;
use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\Document;
use App\Entity\DocumentFolder;
use App\Entity\DocumentTag;
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
use RuntimeException;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class AppFixtures extends Fixture {
	/**
	 * Accounts with a stable address, all with the password "test". Each one
	 * stands for a situation the platform has to handle.
	 */
	private const NAMED_ACCOUNTS = [
			'admin@example.org'      => [ 'name' => 'Alice Admin', 'siteAdmin' => TRUE, 'role' => 'admin' ],
			'referent@example.org'   => [ 'name' => 'Rémi Référent', 'siteAdmin' => FALSE, 'role' => 'admin' ],
			'membre@example.org'     => [ 'name' => 'Manon Membre', 'siteAdmin' => FALSE, 'role' => 'user' ],
			'candidat@example.org'   => [ 'name' => 'Camille Candidate', 'siteAdmin' => FALSE, 'role' => 'pending' ],
			'banni@example.org'      => [ 'name' => 'Bruno Banni', 'siteAdmin' => FALSE, 'role' => 'banned' ],
			'exterieur@example.org'  => [ 'name' => 'Éric Extérieur', 'siteAdmin' => FALSE, 'role' => 'none' ],
	];

	/**
	 * Slug of the group the named accounts belong to.
	 */
	private const REFERENCE_GROUP = 'groupe-de-test';

	/**
	 * Slug of a private group, to try out membership requests.
	 */
	private const PRIVATE_GROUP = 'groupe-prive-de-test';

	/**
	 * Slug of the group that stands above the two others, so that the
	 * hierarchy — and the filter that relies on it — can be tried out. The
	 * production database is built this way: commissions, then groups and
	 * pôles, then ateliers.
	 */
	private const PARENT_GROUP = 'commission-de-test';

	private $passwordEncoder;
	private $slugGenerator;
	private $testAccountsEmail;
	private $communitySlug;
	private $environment;
	private $allowed;

	public function __construct (
			UserPasswordEncoderInterface $passwordEncoder,
			SlugGenerator $slugGenerator,
			string $testAccountsEmail = '',
			string $communitySlug = 'communaute',
			string $environment = 'dev',
			string $allowFixtures = ''
	) {
		$this->passwordEncoder   = $passwordEncoder;
		$this->slugGenerator     = $slugGenerator;
		$this->testAccountsEmail = $testAccountsEmail;
		$this->communitySlug     = $communitySlug;
		$this->environment       = $environment;
		$this->allowed           = filter_var( $allowFixtures, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Charger les fixtures commence par **vider toute la base**.
	 *
	 * En dev et en test, c'est ce qu'on attend. En production, ce serait la
	 * perte de tout : comptes, groupes, discussions, documents. Une
	 * préproduction tournant elle aussi en environnement « prod », on ne peut
	 * pas se contenter de l'environnement pour distinguer les deux : il faut
	 * une autorisation explicite, que seule une préproduction porte.
	 */
	private function refuseOnProduction () {
		if ( ( $this->environment !== 'prod' ) || $this->allowed ) {
			return;
		}

		throw new RuntimeException(
				"Refus de charger les fixtures : elles VIDENT la base avant de la remplir.\n"
				. "Si cet environnement est une préproduction et que la perte des données est voulue,\n"
				. "ajoutez ALLOW_FIXTURES=1 dans le .env.local de ce serveur.\n"
				. "Ne le faites JAMAIS en production."
		);
	}

	/**
	 * Where a named account actually receives its mail.
	 *
	 * Left alone, the addresses stay on example.org: unreachable by design,
	 * and refused before sending by MailGuard. On a server where the e-mails
	 * have to be read, TEST_ACCOUNTS_EMAIL turns them into tagged addresses on
	 * a real mailbox — vous+admin@domaine.fr — which all land in the same inbox
	 * without a single bounce. (#14)
	 *
	 * @param string $email the address defined by NAMED_ACCOUNTS
	 *
	 * @return string
	 */
	private function accountAddress ( $email ) {
		if ( empty( $this->testAccountsEmail ) || ( strpos( $this->testAccountsEmail, '@' ) === FALSE ) ) {
			return $email;
		}

		list( $local, $domain ) = explode( '@', $this->testAccountsEmail, 2 );

		// "referent@example.org" becomes "vous+referent@domaine.fr"
		$tag = strstr( $email, '@', TRUE );

		return sprintf( '%s+%s@%s', $local, $tag, $domain );
	}

	public function load ( ObjectManager $manager ) {
		$this->refuseOnProduction();

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
		 * ÉTIQUETTES DES DOCUMENTS
		 *
		 * Le vocabulaire est tenu par les administrateurs et vide au départ :
		 * sans quelques entrées, ni le filtre ni l'écran d'administration ne
		 * montrent quoi que ce soit. Ceux-là reprennent la demande d'origine.
		 * (#26)
		 */
		$documentTags = [];
		foreach ( [ 'Grand public', 'Cycle 1', 'Cycle 2', 'Cycle 3', 'Cycle 4', 'Retour d’expérience' ] as $name ) {
			$tag = new DocumentTag();
			$tag->setName( $name );
			$tag->setSlug( $this->slugGenerator->generateSlug( $name, DocumentTag::class, 'slug' ) );

			$manager->persist( $tag );

			$documentTags[] = $tag;
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

			// example.org est réservé par la RFC 2606 : rien envoyé là ne peut
			// atteindre une vraie boîte. test.com, lui, appartient à quelqu'un.
			$user->setEmail( sprintf( 'test-%d@example.org', $i ) );
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
			$user->setCity( $faker->city() );

			// Ce que l'annuaire montre d'abord : sans ces trois champs, les
			// fiches se ressemblent toutes et on ne voit pas ce qu'ils
			// apportent. (#30)
			$user->setJobTitle( $faker->randomElement( [
					'Conservateur de réserve naturelle',
					'Conservatrice de réserve naturelle',
					'Garde technicien',
					'Chargée de mission scientifique',
					'Animateur nature',
					'Responsable de pôle',
			] ) );
			$user->setOrganisation( $faker->randomElement( [
					'Conservatoire d\'espaces naturels',
					'Parc naturel régional',
					'Ligue pour la protection des oiseaux',
					'Office national des forêts',
					'Syndicat mixte de gestion',
			] ) );
			$user->setReserves( 'RN ' . $faker->city() );

			$manager->persist( $user );
			$manager->flush();

			$users[] = $user;
		}

		/**
		 * NAMED ACCOUNTS
		 *
		 * One account per situation worth trying out, with a stable address and
		 * the password "test". Without them, exercising a scenario means
		 * hunting for a random account that happens to be in the right state.
		 */
		$named = [];

		foreach ( self::NAMED_ACCOUNTS as $email => $account ) {
			$user = new User();
			$user->setCreatedAt( new \DateTime() );
			$user->setName( $account[ 'name' ] );
			$user->setDisplayName( $account[ 'name' ] );
			$user->setEmail( $this->accountAddress( $email ) );
			$user->setPassword( $this->passwordEncoder->encodePassword( $user, 'test' ) );
			$user->setRoles( $account[ 'siteAdmin' ] ? [ User::ROLE_USER, User::ROLE_ADMIN ] : [ User::ROLE_USER ] );
			$user->setStatus( User::STATUS_ACTIVE );
			$user->setCountry( 'FR' );
			$user->setHasAgreedTermsOfUse( TRUE );

			$manager->persist( $user );

			$named[ $email ] = $user;

			// Deliberately left out of $users: the random groups draw from
			// that list, and a named account has to stay in the state its
			// name promises. "exterieur" is a member of nothing.
		}

		$manager->flush();

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

				// Une partie des documents est étiquetée : un filtre dont tout
				// répond ne se voit pas plus qu'un filtre dont rien ne répond.
				// (#26)
				shuffle( $documentTags );
				for ( $k = 0, $t = rand( 0, 2 ); $k < $t; $k++ ) {
					$document->addTag( $documentTags[ $k ] );
				}

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

		/**
		 * COMMUNITY GROUP
		 *
		 * Le groupe général désigné par COMMUNITY_SLUG. Toute personne qui
		 * arrive sur la plateforme y est rattachée automatiquement ; sans lui,
		 * un compte fraîchement créé n'appartient à rien et découvre une page
		 * « mes groupes » vide.
		 */
		$this->buildCommunityGroup( $manager, array_merge( $users, array_values( $named ) ) );

		/**
		 * REFERENCE GROUPS
		 *
		 * Two groups with a stable slug, where every named account sits in a
		 * known state. Everything that has to be tried by hand — moderation,
		 * membership requests, notifications — happens here rather than in a
		 * randomly generated group whose composition changes at each load.
		 */
		$this->buildReferenceGroups( $manager, $named, $categories );
	}

	/**
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\User[]                  $members
	 */
	private function buildCommunityGroup ( ObjectManager $manager, array $members ) {
		$group = new Usergroup();
		$group->setName( 'Communauté RNF' );
		$group->setSlug( $this->communitySlug );
		$group->setDescription( 'Le groupe général : tout le monde en fait partie.' );
		$group->setPresentation( '<p>Ce groupe rassemble l’ensemble du réseau. Chaque personne qui rejoint la plateforme y est rattachée automatiquement.</p>' );
		$group->setVisibility( Usergroup::PUBLIC );
		$group->setCreatedAt( new \DateTime() );
		$group->setIsActive( TRUE );

		$manager->persist( $group );
		$manager->flush();

		foreach ( $members as $user ) {
			$membership = new UsergroupMembership();
			$membership->setUsergroup( $group );
			$membership->setUser( $user );
			$membership->setRole( UsergroupMembership::ROLE_USER );
			$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
			$membership->setJoinedAt( new \DateTime() );

			$manager->persist( $membership );
			$group->addMember( $membership );
		}

		$manager->flush();
	}

	/**
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\User[]                  $named
	 * @param \App\Entity\Category[]              $categories
	 */
	private function buildReferenceGroups ( ObjectManager $manager, array $named, array $categories ) {
		$faker = Faker\Factory::create( 'fr_FR' );

		$groups = [
				self::PARENT_GROUP    => [
						'name'       => 'Commission de test',
						'visibility' => Usergroup::PUBLIC,
				],
				self::REFERENCE_GROUP => [
						'name'       => 'Groupe de test',
						'visibility' => Usergroup::PUBLIC,
				],
				self::PRIVATE_GROUP   => [
						'name'       => 'Groupe privé de test',
						'visibility' => Usergroup::PRIVATE,
				],
		];

		$built = [];

		foreach ( $groups as $slug => $definition ) {
			$group = new Usergroup();
			$group->setName( $definition[ 'name' ] );
			$group->setSlug( $slug );
			$group->setDescription( 'Groupe stable, créé par les fixtures pour les essais. Cette description doit être visible des membres comme des non-membres.' );
			$group->setPresentation( '<p>Ce groupe existe pour éprouver la plateforme à la main. Sa composition ne change pas d’un chargement à l’autre.</p>' );
			$group->setVisibility( $definition[ 'visibility' ] );
			$group->setCreatedAt( new \DateTime() );
			$group->setIsActive( TRUE );

			$manager->persist( $group );
			$manager->flush();

			$group->addCategory( $categories[ 0 ] );

			foreach ( self::NAMED_ACCOUNTS as $email => $account ) {
				if ( $account[ 'role' ] === 'none' ) {
					continue;
				}

				// The candidate only waits at the door of the private group;
				// joining a public one needs no approval.
				if ( ( $account[ 'role' ] === 'pending' ) && ( $slug !== self::PRIVATE_GROUP ) ) {
					continue;
				}

				$membership = new UsergroupMembership();
				$membership->setUsergroup( $group );
				$membership->setUser( $named[ $email ] );
				$membership->setJoinedAt( new \DateTime() );

				switch ( $account[ 'role' ] ) {
					case 'admin':
						$membership->setRole( UsergroupMembership::ROLE_ADMIN );
						$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
						break;

					case 'pending':
						$membership->setRole( UsergroupMembership::ROLE_USER );
						$membership->setStatus( UsergroupMembership::STATUS_PENDING );
						break;

					case 'banned':
						$membership->setRole( UsergroupMembership::ROLE_USER );
						$membership->setStatus( UsergroupMembership::STATUS_BANNED );
						break;

					default:
						$membership->setRole( UsergroupMembership::ROLE_USER );
						$membership->setStatus( UsergroupMembership::STATUS_MEMBER );
				}

				$manager->persist( $membership );
				$group->addMember( $membership );
			}

			$manager->flush();

			$built[ $slug ] = $group;

			$this->fillReferenceGroup( $manager, $group, $named, $faker );
		}

		// Les deux groupes de test dépendent de la commission de test.
		foreach ( [ self::REFERENCE_GROUP, self::PRIVATE_GROUP ] as $slug ) {
			$built[ $slug ]->addParent( $built[ self::PARENT_GROUP ] );
		}

		$manager->flush();
	}

	/**
	 * Content authored by the référent and by a plain member, so that both
	 * "I may edit my own message" and "I may moderate anybody" can be tried.
	 *
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\Usergroup               $group
	 * @param \App\Entity\User[]                  $named
	 * @param \Faker\Generator                     $faker
	 */
	private function fillReferenceGroup ( ObjectManager $manager, Usergroup $group, array $named, $faker ) {
		$referent = $named[ 'referent@example.org' ];
		$member   = $named[ 'membre@example.org' ];

		$discussion = new Discussion();
		$discussion->setUuid( Uuid::uuid4() );
		$discussion->setTitle( 'Discussion de test' );
		$discussion->setUsergroup( $group );
		$discussion->setAuthor( $referent );
		$discussion->setCreatedAt( new \DateTime( '-3 days' ) );
		$discussion->setActiveAt( new \DateTime( '-1 hour' ) );
		$manager->persist( $discussion );

		foreach ( [ $referent, $member, $referent ] as $index => $author ) {
			$message = new DiscussionMessage();
			$message->setDiscussion( $discussion );
			$message->setAuthor( $author );
			$message->setBody( '<p>' . $faker->sentence( 12 ) . '</p>' );
			$message->setCreatedAt( new \DateTime( sprintf( '-%d hours', 72 - ( $index * 24 ) ) ) );
			$manager->persist( $message );
			$discussion->addMessage( $message );
		}

		// A discussion without any message: the case that used to break the
		// listings. (#3)
		$empty = new Discussion();
		$empty->setUuid( Uuid::uuid4() );
		$empty->setTitle( 'Discussion sans message' );
		$empty->setUsergroup( $group );
		$empty->setAuthor( $referent );
		$empty->setCreatedAt( new \DateTime( '-2 days' ) );
		$manager->persist( $empty );

		$important = new Page();
		$important->setTitle( 'Page importante de test' );
		$important->setSlug( $this->slugGenerator->generateSlug( 'Page importante de test ' . $group->getSlug() ) );
		$important->setUsergroup( $group );
		$important->setAuthor( $referent );
		$important->setBody( '<p>' . $faker->sentence( 12 ) . '</p>' );
		$important->setCreatedAt( new \DateTime() );
		$important->setIsImportant( TRUE );
		$manager->persist( $important );

		$page = new Page();
		$page->setTitle( 'Page de test' );
		$page->setSlug( $this->slugGenerator->generateSlug( 'Page de test ' . $group->getSlug() ) );
		$page->setUsergroup( $group );
		$page->setAuthor( $referent );
		$page->setBody( '<p>' . implode( '</p><p>', $faker->paragraphs( 3, FALSE ) ) . '</p>' );
		$page->setCreatedAt( new \DateTime() );
		$manager->persist( $page );

		$article = new Article();
		$article->setTitle( 'Actualité de test' );
		$article->setSlug( $this->slugGenerator->generateSlug( 'Actualite de test ' . $group->getSlug(), Article::class, 'slug' ) );
		$article->setUsergroup( $group );
		$article->setAuthor( $referent );
		$article->setBody( '<p>' . implode( '</p><p>', $faker->paragraphs( 3, FALSE ) ) . '</p>' );
		$article->setCreatedAt( new \DateTime() );
		$manager->persist( $article );

		// Une arborescence de dossiers, pour éprouver le classement. (#8)
		$racine = new DocumentFolder();
		$racine->setUsergroup( $group );
		$racine->setTitle( 'Comptes rendus' );
		$manager->persist( $racine );

		$sousDossier = new DocumentFolder();
		$sousDossier->setUsergroup( $group );
		$sousDossier->setTitle( '2026' );
		$sousDossier->setParent( $racine );
		$manager->persist( $sousDossier );

		$classe = new Document();
		$classe->setTitle( 'Compte rendu de mars' );
		$classe->setSlug( $this->slugGenerator->generateSlug( 'Compte rendu de mars ' . $group->getSlug(), Document::class, 'slug' ) );
		$classe->setDescription( 'Rangé dans un sous-dossier, pour éprouver l’arborescence.' );
		$classe->setUsergroup( $group );
		$classe->setUser( $referent );
		$classe->setFolder( $sousDossier );
		$classe->setCreatedAt( new \DateTime() );
		$manager->persist( $classe );

		$document = new Document();
		$document->setTitle( 'Document de test' );
		$document->setSlug( $this->slugGenerator->generateSlug( 'Document de test ' . $group->getSlug(), Document::class, 'slug' ) );
		$document->setDescription( 'Un document décrit, pour éprouver l’affichage et la recherche.' );
		$document->setUsergroup( $group );
		$document->setUser( $referent );
		$document->setCreatedAt( new \DateTime() );
		$manager->persist( $document );

		$manager->flush();
	}
}
