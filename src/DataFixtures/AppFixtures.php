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
use App\Entity\Notification;
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
			'admin@example.org'      => [
					'name'      => 'Alice Admin',
					'siteAdmin' => TRUE,
					'role'      => 'admin',
					// Visite déjà vue : elle ne se relancera pas d'elle-même,
					// seul le lien des paramètres la ramène. (#39)
					'tourSeen'  => TRUE,
					// Fiche complète et joignable : le cas le plus courant.
					'profile'   => [
							'jobTitle'     => 'Responsable de l’animation du réseau',
							'organisation' => 'Réserves Naturelles de France',
							'reserves'     => '',
							'phone'        => '01 23 45 67 89',
							'emailVisible' => TRUE,
					],
			],
			'referent@example.org'   => [
					'name'      => 'Rémi Référent',
					'siteAdmin' => FALSE,
					'role'      => 'admin',
					// Le cas d'un gestionnaire de terrain : plusieurs réserves,
					// joignable par e-mail seulement.
					'profile'   => [
							'jobTitle'     => 'Conservateur de réserve naturelle',
							'organisation' => 'Conservatoire d’espaces naturels',
							'reserves'     => 'RN de la Bassée, RN du Marais',
							'phone'        => '',
							'emailVisible' => TRUE,
					],
			],
			'membre@example.org'     => [
					'name'      => 'Manon Membre',
					'siteAdmin' => FALSE,
					'role'      => 'user',
					// Adresse retirée et pas de téléphone : la fiche sans
					// aucune coordonnée, à ne pas prendre pour une panne. (#27)
					'profile'   => [
							'jobTitle'     => 'Chargée de mission scientifique',
							'organisation' => 'Parc naturel régional',
							'reserves'     => 'RN du Marais',
							'phone'        => '',
							'emailVisible' => FALSE,
					],
			],
			'candidat@example.org'   => [
					'name'      => 'Camille Candidate',
					'siteAdmin' => FALSE,
					'role'      => 'pending',
					// Fiche vide : ce que voit un compte qui n'a rien rempli.
					'profile'   => [],
			],
			'banni@example.org'      => [
					'name'      => 'Bruno Banni',
					'siteAdmin' => FALSE,
					'role'      => 'banned',
					'profile'   => [],
			],
			// Le seul compte marqué comme venant du SSO : sans lui, on ne peut
			// éprouver ni l'identité verrouillée de #36, ni les réserves
			// tenues par GeoNature de #28. Il se connecte comme les autres
			// avec le mot de passe « test ».
			'sso@example.org'        => [
					'name'      => 'Sophie SSO',
					'siteAdmin' => FALSE,
					'role'      => 'user',
					'rnfIdRole' => 4242,
					'profile'   => [
							'jobTitle'     => 'Conservatrice de réserve naturelle',
							'organisation' => 'Office national des forêts',
							'reserves'     => 'RN de la Bassée',
							'phone'        => '',
							'emailVisible' => TRUE,
					],
			],
			'exterieur@example.org'  => [
					'name'      => 'Éric Extérieur',
					'siteAdmin' => FALSE,
					'role'      => 'none',
					// Téléphone publié mais adresse retirée : l'autre moitié
					// du choix laissé à chacun. (#27)
					'profile'   => [
							'jobTitle'     => 'Garde technicien',
							'organisation' => '',
							'reserves'     => 'RN de la Bassée',
							'phone'        => '06 12 34 56 78',
							'emailVisible' => FALSE,
					],
			],
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

			$user->setPresentation( mb_substr( NetworkContent::pick( NetworkContent::PRESENTATIONS, $i ), 0, 32 ) );
			$user->setCity( $faker->city() );

			// Ce que l'annuaire montre d'abord : sans ces trois champs, les
			// fiches se ressemblent toutes et on ne voit pas ce qu'ils
			// apportent. (#30)
			$user->setJobTitle( NetworkContent::pick( NetworkContent::JOB_TITLES, $i ) );
			$user->setOrganisation( NetworkContent::pick( NetworkContent::ORGANISATIONS, $i ) );

			// Une personne sur trois suit deux réserves : c'est fréquent dans
			// le réseau, et ça éprouve l'affichage d'une liste.
			$user->setReserves( ( $i % 3 === 0 )
					? NetworkContent::pick( NetworkContent::RESERVES, $i ) . ', ' . NetworkContent::pick( NetworkContent::RESERVES, $i + 7 )
					: NetworkContent::pick( NetworkContent::RESERVES, $i ) );

			// Un annuaire où tout le monde publie tout ne montre pas ce que
			// le choix change. Un compte sur trois donne son téléphone, un
			// sur cinq retire son adresse. (#27)
			if ( rand( 0, 2 ) === 0 ) {
				$user->setPhone( $faker->phoneNumber() );
			}

			$user->setEmailVisible( rand( 0, 4 ) > 0 );

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

			// Chaque compte nommé porte une situation d'annuaire différente,
			// pour que les cas de #27 et #30 soient tous visibles sans avoir
			// à remplir un profil à la main. (#27, #30)
			$profile = isset( $account[ 'profile' ] ) ? $account[ 'profile' ] : [];

			$user->setJobTitle( isset( $profile[ 'jobTitle' ] ) ? $profile[ 'jobTitle' ] : NULL );
			$user->setOrganisation( isset( $profile[ 'organisation' ] ) ? $profile[ 'organisation' ] : NULL );
			$user->setReserves( isset( $profile[ 'reserves' ] ) ? $profile[ 'reserves' ] : NULL );
			$user->setPhone( isset( $profile[ 'phone' ] ) ? $profile[ 'phone' ] : NULL );
			$user->setEmailVisible( !isset( $profile[ 'emailVisible' ] ) || $profile[ 'emailVisible' ] );

			// Un compte l'a déjà vue, un autre non : sans les deux, on ne
			// peut éprouver ni le lancement automatique ni le lien de
			// rejeu dans les paramètres. (#39)
			$user->setTourSeenAt( empty( $account[ 'tourSeen' ] ) ? NULL : new \DateTime( '-1 month' ) );

			// Marqué comme venant de GeoNature : le nom devient non modifiable
			// (#36), et les réserves aussi dès qu'un jeton d'export est
			// configuré (#28).
			$user->setRnfIdRole( isset( $account[ 'rnfIdRole' ] ) ? $account[ 'rnfIdRole' ] : NULL );

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
		foreach ( NetworkContent::COMMISSIONS as $name => $description ) {
			$category = new Category();
			$category->setName( $name );
			$category->setDescription( $description );

			$manager->persist( $category );

			$categories[ $name ] = $category;
		}
		$manager->flush();

		/**
		 * GROUPS
		 */
		$groups = [];
		foreach ( NetworkContent::GROUPS as $i => $definition ) {
			$group = new Usergroup();
			$group->setName( $definition[ 'name' ] );

			$group->setSlug( $this->slugGenerator->generateSlug( $group->getName(), Usergroup::class, 'slug' ) );
			$group->setDescription( $definition[ 'description' ] );
			$group->setPresentation( NetworkContent::body( [
					$definition[ 'description' ],
					'Ce groupe est ouvert à toute personne du réseau que le sujet concerne, quelle que soit sa structure. Les échanges se font dans l’onglet Discussions ; ce qui doit rester accessible dans deux ans a sa place dans une page ou dans les documents.',
					'Les documents de référence sont rangés par dossier et portent des étiquettes, ce qui permet de les retrouver depuis n’importe quel groupe.',
			] ) );

			// Un groupe sur quatre est privé : assez pour éprouver les
			// demandes d'adhésion, assez peu pour qu'un visiteur non connecté
			// voie tout de même de quoi se faire une idée.
			$group->setVisibility( ( $i % 4 === 3 ) ? Usergroup::PRIVATE : Usergroup::PUBLIC );
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

			// La commission dont relève ce groupe, pas une au hasard : le
			// filtre par commission doit ramener quelque chose de cohérent.
			if ( isset( $categories[ $definition[ 'commission' ] ] ) ) {
				$group->addCategory( $categories[ $definition[ 'commission' ] ] );
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

			for ( $j = 0, $n = rand( 3, 5 ); $j < $n; $j++ ) {
				$definitionPage = NetworkContent::pick( NetworkContent::PAGES, $i + $j );

				$page = new Page();
				$page->setTitle( $definitionPage[ 'title' ] );
				$page->setSlug( $this->slugGenerator->generateSlug( $page->getTitle(), Page::class, 'slug' ) );

				$page->setUsergroup( $group );
				$page->setAuthor( $users[ rand( 0, count( $users ) - 1 ) ] );
				$page->setBody( NetworkContent::body( $definitionPage[ 'body' ] ) );

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
			for ( $j = 0, $n = rand( 3, 5 ); $j < $n; $j++ ) {
				$openedAt = $faker->dateTimeBetween( '-1 year', '-1 month' );
				$thread   = NetworkContent::pick( NetworkContent::DISCUSSIONS, ( $i * 3 ) + $j );

				$discussion = new Discussion();
				$discussion->setUuid( Uuid::uuid4() );
				$discussion->setTitle( mb_substr( $thread[ 'title' ], 0, 100 ) );
				$discussion->setUsergroup( $group );
				$discussion->setAuthor( $members[ array_rand( $members ) ] );
				$discussion->setCreatedAt( $openedAt );

				$manager->persist( $discussion );

				$lastMessageAt = $openedAt;

				// Une question, puis les réponses des collègues : les auteurs
				// tournent, sans quoi on croirait à un monologue.
				foreach ( $thread[ 'messages' ] as $k => $written ) {
					$lastMessageAt = $faker->dateTimeBetween( $lastMessageAt, 'now' );

					$message = new DiscussionMessage();
					$message->setDiscussion( $discussion );
					$message->setAuthor( $members[ ( $j + $k ) % count( $members ) ] );
					$message->setBody( '<p>' . $written . '</p>' );
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
			for ( $j = 0, $n = rand( 2, 4 ); $j < $n; $j++ ) {
				$news = NetworkContent::pick( NetworkContent::ARTICLES, ( $i * 2 ) + $j );

				$article = new Article();
				$article->setTitle( mb_substr( $news[ 'title' ], 0, 100 ) );
				$article->setSlug( $this->slugGenerator->generateSlug( $article->getTitle(), Article::class, 'slug' ) );
				$article->setUsergroup( $group );
				$article->setAuthor( $members[ array_rand( $members ) ] );
				$article->setBody( NetworkContent::body( $news[ 'body' ] ) );
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
			// Deux dossiers par groupe, pour que la liste ne soit pas un tas.
			$folders = [];

			foreach ( [ 0, 1 ] as $rank ) {
				$folder = new DocumentFolder();
				$folder->setUsergroup( $group );
				$folder->setTitle( NetworkContent::pick( NetworkContent::FOLDERS, $i + $rank ) );

				$manager->persist( $folder );

				$folders[] = $folder;
			}

			$manager->flush();

			for ( $j = 0, $n = rand( 5, 9 ); $j < $n; $j++ ) {
				$reference = NetworkContent::pick( NetworkContent::DOCUMENTS, ( $i * 5 ) + $j );

				$document = new Document();
				$document->setTitle( mb_substr( $reference[ 'title' ], 0, 100 ) );

				// Une partie seulement est rangée : un dossier vaut mieux
				// qu'aucun, mais tout ranger ne ressemble à aucune réalité.
				if ( $j % 3 !== 2 ) {
					$document->setFolder( $folders[ $j % count( $folders ) ] );
				}

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
				if ( $j % 4 !== 3 ) {
					$document->setDescription( $reference[ 'description' ] );
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
		$this->buildCommunityGroup(
				$manager,
				array_merge( $users, array_values( $named ) ),
				[
						$named[ 'admin@example.org' ],
						$named[ 'referent@example.org' ],
						$named[ 'membre@example.org' ],
				],
				$documentTags
		);

		/**
		 * REFERENCE GROUPS
		 *
		 * Two groups with a stable slug, where every named account sits in a
		 * known state. Everything that has to be tried by hand — moderation,
		 * membership requests, notifications — happens here rather than in a
		 * randomly generated group whose composition changes at each load.
		 */
		$this->buildReferenceGroups( $manager, $named, $categories, $documentTags );
	}

	/**
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\User[]                  $members
	 */
	/**
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\Usergroup               $group
	 * @param \App\Entity\User[]                  $authors
	 * @param \App\Entity\DocumentTag[]           $documentTags
	 */
	private function fillCommunityGroup ( ObjectManager $manager, Usergroup $group, array $authors, array $documentTags ) {
		$authors = array_values( $authors );

		if ( empty( $authors ) ) {
			return;
		}

		/**
		 * PAGES
		 *
		 * La première est mise en avant : c'est celle qu'on veut voir en
		 * arrivant, et le seul endroit de la plateforme où l'on explique à
		 * quoi elle sert.
		 */
		foreach ( NetworkContent::COMMUNITY_PAGES as $rank => $definition ) {
			$page = new Page();
			$page->setTitle( $definition[ 'title' ] );
			$page->setSlug( $this->slugGenerator->generateSlug( $definition[ 'title' ], Page::class, 'slug' ) );
			$page->setUsergroup( $group );
			$page->setAuthor( $authors[ $rank % count( $authors ) ] );
			$page->setBody( NetworkContent::body( $definition[ 'body' ] ) );
			$page->setCreatedAt( new \DateTime( sprintf( '-%d days', 60 - $rank ) ) );
			$page->setIsImportant( $rank === 0 );

			$manager->persist( $page );
		}

		/**
		 * DISCUSSIONS
		 */
		foreach ( NetworkContent::COMMUNITY_DISCUSSIONS as $rank => $thread ) {
			$openedAt = new \DateTime( sprintf( '-%d days', 50 - ( $rank * 7 ) ) );

			$discussion = new Discussion();
			$discussion->setUuid( Uuid::uuid4() );
			$discussion->setTitle( mb_substr( $thread[ 'title' ], 0, 100 ) );
			$discussion->setUsergroup( $group );
			$discussion->setAuthor( $authors[ $rank % count( $authors ) ] );
			$discussion->setCreatedAt( $openedAt );

			$manager->persist( $discussion );

			$writtenAt = clone $openedAt;

			foreach ( $thread[ 'messages' ] as $index => $written ) {
				$writtenAt = ( clone $writtenAt )->modify( sprintf( '+%d hours', 6 + ( $index * 5 ) ) );

				$message = new DiscussionMessage();
				$message->setDiscussion( $discussion );
				$message->setAuthor( $authors[ ( $rank + $index ) % count( $authors ) ] );
				$message->setBody( '<p>' . $written . '</p>' );
				$message->setCreatedAt( $writtenAt );

				$manager->persist( $message );
				$discussion->addMessage( $message );
			}

			$discussion->setActiveAt( $writtenAt );
		}

		/**
		 * ACTUALITÉS
		 */
		foreach ( NetworkContent::COMMUNITY_ARTICLES as $rank => $news ) {
			$article = new Article();
			$article->setTitle( mb_substr( $news[ 'title' ], 0, 100 ) );
			$article->setSlug( $this->slugGenerator->generateSlug( $news[ 'title' ], Article::class, 'slug' ) );
			$article->setUsergroup( $group );
			$article->setAuthor( $authors[ $rank % count( $authors ) ] );
			$article->setBody( NetworkContent::body( $news[ 'body' ] ) );
			$article->setCreatedAt( new \DateTime( sprintf( '-%d days', 40 - ( $rank * 6 ) ) ) );

			$manager->persist( $article );
		}

		/**
		 * DOCUMENTS
		 */
		$folder = new DocumentFolder();
		$folder->setUsergroup( $group );
		$folder->setTitle( 'Documents de référence' );
		$manager->persist( $folder );

		$manager->flush();

		foreach ( NetworkContent::COMMUNITY_DOCUMENTS as $rank => $reference ) {
			$document = new Document();
			$document->setTitle( mb_substr( $reference[ 'title' ], 0, 100 ) );
			$document->setSlug( $this->slugGenerator->generateSlug( $reference[ 'title' ], Document::class, 'slug' ) );
			$document->setDescription( $reference[ 'description' ] );
			$document->setUsergroup( $group );
			$document->setUser( $authors[ $rank % count( $authors ) ] );
			$document->setCreatedAt( new \DateTime( sprintf( '-%d days', 45 - ( $rank * 4 ) ) ) );

			// Les deux premiers sont rangés et étiquetés : de quoi éprouver
			// dossier et filtre dès le groupe où l'on arrive.
			if ( $rank < 4 ) {
				$document->setFolder( $folder );
			}

			if ( !empty( $documentTags ) && ( $rank % 2 === 0 ) ) {
				$document->addTag( $documentTags[ $rank % count( $documentTags ) ] );
			}

			$manager->persist( $document );
		}

		$manager->flush();
	}

	private function buildCommunityGroup ( ObjectManager $manager, array $members, array $named = [], array $documentTags = [] ) {
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

		// Le groupe où tout le monde arrive était vide, ce qui donnait une
		// première impression de plateforme déserte. Les comptes nommés en
		// sont les auteurs : on reconnaît qui a écrit quoi.
		$this->fillCommunityGroup(
				$manager,
				$group,
				!empty( $named ) ? $named : array_slice( $members, 0, 3 ),
				$documentTags
		);
	}

	/**
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\User[]                  $named
	 * @param \App\Entity\Category[]              $categories
	 */
	private function buildReferenceGroups ( ObjectManager $manager, array $named, array $categories, array $documentTags = [] ) {
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

			// Les catégories sont indexées par nom de commission depuis que le
			// contenu est celui du réseau : les groupes de référence relèvent
			// de la première, quelle qu'elle soit.
			$firstCommission = reset( $categories );

			if ( $firstCommission ) {
				$group->addCategory( $firstCommission );
			}

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

			$this->fillReferenceGroup( $manager, $group, $named, $faker, $documentTags );
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
	private function fillReferenceGroup ( ObjectManager $manager, Usergroup $group, array $named, $faker, array $documentTags = [] ) {
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

		// Le deuxième message nomme quelqu'un : sans une mention posée
		// d'avance, on ne voit ni le lien vers l'annuaire ni la notification
		// qu'elle déclenche. (#37)
		$bodies = [
				'<p>Bonjour à tous,</p><p>Nous préparons la rencontre annuelle et il nous manque deux retours d’expérience pour boucler le programme. Si vous avez mené un chantier qui n’a pas donné ce que vous attendiez, c’est exactement ce qui intéresse les collègues.</p>',
				'<p>@' . $member->getName() . ' peux-tu regarder ce point ? Tu avais suivi le dossier l’an dernier.</p>',
				'<p>Je peux présenter notre chantier de restauration de mare : trois ans, deux échecs, et ce qu’on a fini par comprendre. Une demi-heure suffirait.</p>',
		];

		foreach ( [ $referent, $referent, $member ] as $index => $author ) {
			$message = new DiscussionMessage();
			$message->setDiscussion( $discussion );
			$message->setAuthor( $author );
			$message->setBody( $bodies[ $index ] );
			$message->setCreatedAt( new \DateTime( sprintf( '-%d hours', 72 - ( $index * 24 ) ) ) );
			$manager->persist( $message );
			$discussion->addMessage( $message );
		}

		// Les notifications ne naissent que du passage par NotificationSender,
		// que les fixtures ne déclenchent pas : sans celles-ci, la page des
		// notifications est vide au premier chargement et il n'y a rien à
		// recetter. Seulement dans le groupe de référence — trois fois les
		// mêmes, dont certaines vers un groupe dont Manon n'est pas membre,
		// ne rendraient service à personne. (#34, #37)
		if ( $group->getSlug() === self::REFERENCE_GROUP ) {
			// L'adresse est écrite à la main plutôt que routée : les fixtures
			// n'ont pas de contexte de requête, et ce chemin est celui de la
			// route group_discussion_index.
			$discussionUrl = sprintf( '/groups/%s/discussions/%s', $group->getSlug(), $discussion->getUuid() );

			$mention = new Notification();
			$mention->setRecipient( $member );
			$mention->setAuthor( $referent );
			$mention->setUsergroup( $group );
			$mention->setType( Notification::DISCUSSION_MENTION );
			$mention->setTitle( $discussion->getTitle() );
			$mention->setUrl( $discussionUrl );
			$mention->setCreatedAt( new \DateTime( '-2 hours' ) );
			$mention->setByEmail( FALSE );
			$manager->persist( $mention );

			// Une notification déjà lue, pour que la distinction se voie.
			$lue = new Notification();
			$lue->setRecipient( $member );
			$lue->setAuthor( $referent );
			$lue->setUsergroup( $group );
			$lue->setType( Notification::DISCUSSION_MESSAGE );
			$lue->setTitle( $discussion->getTitle() );
			$lue->setUrl( $discussionUrl );
			$lue->setCreatedAt( new \DateTime( '-3 days' ) );
			$lue->setByEmail( FALSE );
			$lue->setReadAt( new \DateTime( '-2 days' ) );
			$manager->persist( $lue );
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
		$important->setBody( NetworkContent::body( [
				'Cette page rassemble ce qu’il faut savoir avant de participer aux échanges du groupe : à qui s’adresser, où déposer un document, et ce qui relève d’une discussion plutôt que d’une page.',
				'Elle est mise en avant par les animateurs, ce qui la place en tête de la liste des pages. C’est le moyen de faire ressortir l’information qui compte au milieu des autres.',
		] ) );
		$important->setCreatedAt( new \DateTime() );
		$important->setIsImportant( TRUE );
		$manager->persist( $important );

		$page = new Page();
		$page->setTitle( 'Page de test' );
		$page->setSlug( $this->slugGenerator->generateSlug( 'Page de test ' . $group->getSlug() ) );
		$page->setUsergroup( $group );
		$page->setAuthor( $referent );
		$page->setBody( NetworkContent::body( NetworkContent::pick( NetworkContent::PAGES, 1 )[ 'body' ] ) );
		$page->setCreatedAt( new \DateTime() );
		$manager->persist( $page );

		// Rédigée par un membre ordinaire : c'est ce qui permet d'éprouver la
		// règle de #33 — son auteur la modifie, un autre membre ne peut pas,
		// un animateur le peut.
		$deMembre = new Page();
		$deMembre->setTitle( 'Page rédigée par un membre' );
		$deMembre->setSlug( $this->slugGenerator->generateSlug( 'Page redigee par un membre ' . $group->getSlug() ) );
		$deMembre->setUsergroup( $group );
		$deMembre->setAuthor( $member );
		$deMembre->setBody( NetworkContent::body( [
				'Je note ici ce que j’ai retenu de la formation de la semaine dernière, tant que c’est frais.',
				'Le point qui m’a le plus servi : commencer par écrire ce qu’on veut pouvoir dire dans dix ans, et seulement ensuite choisir le protocole. Nous faisions l’inverse.',
		] ) );
		$deMembre->setCreatedAt( new \DateTime() );
		$manager->persist( $deMembre );

		$article = new Article();
		$article->setTitle( 'Actualité de test' );
		$article->setSlug( $this->slugGenerator->generateSlug( 'Actualite de test ' . $group->getSlug(), Article::class, 'slug' ) );
		$article->setUsergroup( $group );
		$article->setAuthor( $referent );
		$article->setBody( NetworkContent::body( NetworkContent::pick( NetworkContent::ARTICLES, 0 )[ 'body' ] ) );
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

		// Étiqueté et rangé dans un sous-dossier : les deux axes de classement
		// se croisent, c'est tout l'intérêt de les avoir séparés. (#26)
		//
		// Choisies par leur nom : la liste a été mélangée pour les documents
		// tirés au hasard, sa position n'a plus rien de stable.
		foreach ( $documentTags as $tag ) {
			if ( in_array( $tag->getName(), [ 'Grand public', 'Cycle 1' ], TRUE ) ) {
				$classe->addTag( $tag );
			}
		}

		$manager->persist( $classe );

		$document = new Document();
		$document->setTitle( 'Document de test' );
		$document->setSlug( $this->slugGenerator->generateSlug( 'Document de test ' . $group->getSlug(), Document::class, 'slug' ) );
		$document->setDescription( 'Un document décrit, pour éprouver l’affichage et la recherche.' );
		$document->setUsergroup( $group );
		$document->setUser( $member );
		$document->setCreatedAt( new \DateTime() );

		// Déposé par un membre ordinaire, et sans étiquette : la fiche que
		// seul son déposant modifie (#33), et le document que le filtre par
		// étiquette laisse de côté (#26).
		$manager->persist( $document );

		$manager->flush();

		// Une page et un message qui renvoient vers un document : sans eux, la
		// fiche du document affiche « personne n'en a encore parlé » et on ne
		// voit pas ce que la navigation apporte. Le lien ne peut être écrit
		// qu'ici, une fois le document enregistré et son identifiant connu.
		// (#32)
		$lien = sprintf( '/groups/%s/documents/%d', $group->getSlug(), $classe->getId() );

		$page->setBody( sprintf(
				'<p>%s</p><p>Le détail se trouve dans <a href="%s">%s</a>.</p>',
				'Le compte rendu de la dernière réunion est en ligne, avec les décisions prises et les points laissés ouverts.',
				$lien,
				$classe->getTitle()
		) );

		$renvoi = new DiscussionMessage();
		$renvoi->setDiscussion( $discussion );
		$renvoi->setAuthor( $member );
		$renvoi->setBody( sprintf(
				'<p>J’ai relu <a href="%s">%s</a>, deux remarques.</p>',
				$lien,
				$classe->getTitle()
		) );
		$renvoi->setCreatedAt( new \DateTime( '-30 minutes' ) );
		$manager->persist( $renvoi );
		$discussion->addMessage( $renvoi );

		$manager->flush();
	}
}
