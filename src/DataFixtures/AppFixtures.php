<?php

namespace App\DataFixtures;

use App\Command\ImportSkillsCommand;
use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Discussion;
use App\Entity\DiscussionMessage;
use App\Entity\Document;
use App\Entity\DocumentFolder;
use App\Entity\DocumentTag;
use App\Entity\LogEvent;
use App\Entity\MessageReport;
use App\Entity\Notification;
use App\Entity\Page;
use App\Entity\PrivateMessage;
use App\Entity\Skill;
use App\Entity\User;
use App\Entity\Usergroup;
use App\Entity\UsergroupMembership;
use App\Notification\NotificationRhythm;
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
					//
					// Le seul compte dont la boîte est fermée. Sans lui, on ne
					// voit jamais disparaître le bouton « Écrire » — ni la
					// règle qui va avec : fermer sa boîte n'interrompt pas les
					// conversations déjà ouvertes, et il en a une.
					'profile'   => [
							'jobTitle'     => 'Garde technicien',
							'organisation' => '',
							'reserves'     => 'RN de la Bassée',
							'phone'        => '06 12 34 56 78',
							'emailVisible' => FALSE,
							'messages'     => FALSE,
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
			$user->setMessagesOpen( !isset( $profile[ 'messages' ] ) || $profile[ 'messages' ] );

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
		 *
		 * Les thématiques, et non plus les commissions : une catégorie qui
		 * répète la commission ne trie rien de plus que la hiérarchie, et le
		 * second filtre de la liste des groupes n'aurait aucune raison
		 * d'exister. (#23)
		 */
		$categories = [];
		foreach ( NetworkContent::THEMES as $name => $description ) {
			$category = new Category();
			$category->setName( $name );
			$category->setSlug( $this->slugGenerator->generateSlug( $name, Category::class, 'slug' ) );
			$category->setDescription( $description );

			$manager->persist( $category );

			$categories[ $name ] = $category;
		}
		$manager->flush();

		$themes = array_values( $categories );

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

			// Une thématique par groupe, distribuée sans hasard pour que le
			// filtre ramène toujours la même chose d'un chargement à l'autre.
			// Un groupe sur trois en porte une seconde : c'est le cas qu'il
			// faut pouvoir éprouver, un groupe qui relève de deux sujets sans
			// pour autant changer de commission. (#23)
			if ( !empty( $themes ) ) {
				$group->addCategory( $themes[ $i % count( $themes ) ] );

				if ( ( $i % 3 ) === 0 ) {
					$group->addCategory( $themes[ ( $i + 2 ) % count( $themes ) ] );
				}
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
			// Le pas est de cinq, et non de deux : deux groupes voisins
			// tombaient sur presque la même paire d'actualités, si bien qu'en
			// parcourant la plateforme on croyait à un bug d'affichage. Cinq
			// et vingt-quatre étant premiers entre eux, il faut faire tout le
			// tour de la liste avant de retomber sur le même début.
			for ( $j = 0, $n = rand( 3, 5 ); $j < $n; $j++ ) {
				$news = NetworkContent::pick( NetworkContent::ARTICLES, ( $i * 5 ) + $j );

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
		$groups = $this->buildReferenceGroups( $manager, $named, $categories, $documentTags );

		/**
		 * MESSAGERIE
		 *
		 * Des conversations privées déjà écrites, entre les comptes nommés.
		 * Une messagerie vide ne montre rien : ni le compteur de l'en-tête, ni
		 * un tag grisé, ni un signalement à traiter.
		 */
		$this->buildMessaging( $manager, $named, $groups );
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
			$page->setCreatedAt( new \DateTime( sprintf(
					'-%d days',
					4 + ( ( count( NetworkContent::COMMUNITY_PAGES ) - 1 - $rank ) * 3 )
			) ) );
			$page->setIsImportant( $rank === 0 );

			$manager->persist( $page );
		}

		/**
		 * DISCUSSIONS
		 */
		foreach ( NetworkContent::COMMUNITY_DISCUSSIONS as $rank => $thread ) {
			// Comme pour les actualités : l'écart se compte depuis la fin de
			// la liste, pour qu'en ajouter une n'envoie pas la suivante dans
			// le futur.
			$openedAt = new \DateTime( sprintf(
					'-%d days',
					5 + ( ( count( NetworkContent::COMMUNITY_DISCUSSIONS ) - 1 - $rank ) * 7 )
			) );

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
		 *
		 * La dernière écrite est la plus récente, et l'écart se compte depuis
		 * la fin de la liste : une date posée depuis le début — « 40 jours
		 * moins six par rang » — repassait dans le futur, puis fabriquait un
		 * « --2 days » que DateTime refuse, dès que la liste s'allongeait.
		 * Ainsi, en ajouter une n'oblige à rien recalculer.
		 */
		$newsCount = count( NetworkContent::COMMUNITY_ARTICLES );

		foreach ( NetworkContent::COMMUNITY_ARTICLES as $rank => $news ) {
			$article = new Article();
			$article->setTitle( mb_substr( $news[ 'title' ], 0, 100 ) );
			$article->setSlug( $this->slugGenerator->generateSlug( $news[ 'title' ], Article::class, 'slug' ) );
			$article->setUsergroup( $group );
			$article->setAuthor( $authors[ $rank % count( $authors ) ] );
			$article->setBody( NetworkContent::body( $news[ 'body' ] ) );
			$article->setCreatedAt( new \DateTime(
					sprintf( '-%d days', 2 + ( ( $newsCount - 1 - $rank ) * 6 ) )
			) );

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
			$document->setCreatedAt( new \DateTime( sprintf(
					'-%d days',
					3 + ( ( count( NetworkContent::COMMUNITY_DOCUMENTS ) - 1 - $rank ) * 4 )
			) ) );

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
	 *
	 * @return \App\Entity\Usergroup[] indexés par slug
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

			// Les groupes de référence portent la première thématique, quelle
			// qu'elle soit : la recette a besoin d'un groupe dont la
			// thématique ne change pas d'un chargement à l'autre.
			$firstTheme = reset( $categories );

			if ( $firstTheme ) {
				$group->addCategory( $firstTheme );
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

		return $built;
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

		/**
		 * ACTUALITÉS
		 *
		 * Trois, et non une seule. Une actualité isolée montre bien la page
		 * d'une actualité, mais rien de ce qui fait la liste : ni l'ordre —
		 * la plus récente en tête —, ni la règle de #33, qui ne se voit qu'en
		 * comparant deux actualités de deux auteurs différents.
		 *
		 * Celle du référent garde son titre, « Actualité de test » : c'est
		 * celui que la recette nomme.
		 */
		$article = new Article();
		$article->setTitle( 'Actualité de test' );
		$article->setSlug( $this->slugGenerator->generateSlug( 'Actualite de test ' . $group->getSlug(), Article::class, 'slug' ) );
		$article->setUsergroup( $group );
		$article->setAuthor( $referent );
		$article->setBody( NetworkContent::body( NetworkContent::pick( NetworkContent::ARTICLES, 0 )[ 'body' ] ) );
		$article->setCreatedAt( new \DateTime() );
		$manager->persist( $article );

		// Rédigée par un membre ordinaire, comme la page plus haut : son
		// auteur la modifie, un autre membre ne peut pas, un animateur le
		// peut. Sans elle, la règle de #33 ne s'éprouvait que sur les pages
		// et les documents — alors qu'elle vient des actualités. (#33)
		$deMembreArticle = new Article();
		$deMembreArticle->setTitle( 'Actualité rédigée par un membre' );
		$deMembreArticle->setSlug( $this->slugGenerator->generateSlug(
				'Actualite redigee par un membre ' . $group->getSlug(),
				Article::class,
				'slug'
		) );
		$deMembreArticle->setUsergroup( $group );
		$deMembreArticle->setAuthor( $member );
		$deMembreArticle->setBody( NetworkContent::body( [
				'Nous avons reçu la visite de deux collègues venus voir notre dispositif de suivi des mares. La journée a été plus utile pour nous que pour eux : expliquer une méthode oblige à s’apercevoir de ce qu’on n’avait jamais justifié.',
				'Si d’autres équipes veulent venir, la période la plus parlante se situe entre avril et juin. Écrivez-moi, nous nous arrangerons.',
		] ) );
		$deMembreArticle->setCreatedAt( new \DateTime( '-6 days' ) );
		$manager->persist( $deMembreArticle );

		// Datée de l'an dernier pour de bon, et non de « plusieurs semaines » :
		// la liste a un ordre, et l'on voit qu'une actualité vieillit sans
		// disparaître.
		$ancienne = new Article();
		$ancienne->setTitle( 'Actualité de l’an dernier' );
		$ancienne->setSlug( $this->slugGenerator->generateSlug(
				'Actualite de l an dernier ' . $group->getSlug(),
				Article::class,
				'slug'
		) );
		$ancienne->setUsergroup( $group );
		$ancienne->setAuthor( $referent );
		$ancienne->setBody( NetworkContent::body( NetworkContent::pick( NetworkContent::ARTICLES, 3 )[ 'body' ] ) );
		$ancienne->setCreatedAt( new \DateTime( '-13 months' ) );
		$manager->persist( $ancienne );

		// Les notifications posées plus haut parlent toutes d'une discussion :
		// la page des notifications ne montrait donc qu'une seule des quatre
		// catégories, et le réglage « actualités » n'avait rien à côté de quoi
		// se lire. (#34, #38)
		if ( $group->getSlug() === self::REFERENCE_GROUP ) {
			$parue = new Notification();
			$parue->setRecipient( $member );
			$parue->setAuthor( $referent );
			$parue->setUsergroup( $group );
			$parue->setType( Notification::ARTICLE_CREATE );
			$parue->setTitle( $article->getTitle() );
			$parue->setUrl( sprintf(
					'/groups/%s/articles/%s',
					$group->getSlug(),
					$article->getSlug()
			) );
			$parue->setCreatedAt( new \DateTime( '-1 hour' ) );
			$parue->setByEmail( FALSE );
			$manager->persist( $parue );

			/**
			 * DEUX NOTIFICATIONS QUI ATTENDENT UN RÉSUMÉ
			 *
			 * Les trois précédentes sont posées `byEmail = FALSE` : elles
			 * garnissent la page des notifications, et rien d'autre. Sur une
			 * préproduction fraîchement chargée, `app:notifications:digest`
			 * ne trouvait donc **rien**, quelle que soit la configuration
			 * d'envoi — un zéro qu'on lit comme une panne d'e-mail.
			 *
			 * Celles-ci attendent pour de bon, et à deux rythmes : la
			 * quotidienne part le jour même, l'hebdomadaire attend son lundi.
			 * C'est le couple minimal pour éprouver la commande, puisque le
			 * contrôle est autant « l'hebdomadaire est emporté un lundi » que
			 * « il est retenu les six autres jours ». (#34, #38)
			 *
			 * Elles vont à Manon, qui n'est animatrice de rien : le résumé
			 * d'un compte ordinaire est celui qu'on veut regarder.
			 */
			$quotidienne = new Notification();
			$quotidienne->setRecipient( $member );
			$quotidienne->setAuthor( $referent );
			$quotidienne->setUsergroup( $group );
			$quotidienne->setType( Notification::ARTICLE_CREATE );
			$quotidienne->setTitle( $article->getTitle() );
			$quotidienne->setUrl( sprintf(
					'/groups/%s/articles/%s',
					$group->getSlug(),
					$article->getSlug()
			) );
			$quotidienne->setCreatedAt( new \DateTime( '-4 hours' ) );
			$quotidienne->setByEmail( TRUE );
			$quotidienne->setRhythm( NotificationRhythm::DAILY );
			$manager->persist( $quotidienne );

			$hebdomadaire = new Notification();
			$hebdomadaire->setRecipient( $member );
			$hebdomadaire->setAuthor( $referent );
			$hebdomadaire->setUsergroup( $group );
			$hebdomadaire->setType( Notification::DOCUMENT_CREATE );
			$hebdomadaire->setTitle( 'Protocole de suivi des amphibiens' );
			$hebdomadaire->setUrl( sprintf( '/groups/%s/documents', $group->getSlug() ) );
			$hebdomadaire->setCreatedAt( new \DateTime( '-5 hours' ) );
			$hebdomadaire->setByEmail( TRUE );
			$hebdomadaire->setRhythm( NotificationRhythm::WEEKLY );
			$manager->persist( $hebdomadaire );
		}

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

	/**
	 * La messagerie : quatre conversations déjà écrites entre les comptes
	 * nommés.
	 *
	 * Une boîte vide ne prouve rien. Chaque cas a ici son contraire : une
	 * conversation non lue et une lue, un tête-à-tête et un fil à plusieurs,
	 * un tag qui mène quelque part et un tag grisé, un message modifié et un
	 * message supprimé, un signalement à traiter et un signalement classé.
	 *
	 * **Deux documents sont créés exprès**, avec des titres qu'aucun autre
	 * contenu ne porte. Les contenus du groupe de référence s'appellent tous
	 * « Document de test » dans les trois groupes : un « # » écrit dessus
	 * désignerait le premier trouvé, ce qui ne se raconte pas dans une
	 * recette. Ceux-ci ne laissent aucun doute — l'un est public, l'autre vit
	 * dans le groupe privé, et c'est le second qui s'affiche grisé chez qui
	 * n'y a pas droit.
	 *
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\User[]                  $named
	 * @param \App\Entity\Usergroup[]             $groups indexés par slug
	 */
	private function buildMessaging ( ObjectManager $manager, array $named, array $groups ) {
		$alice   = $named[ 'admin@example.org' ];
		$remi    = $named[ 'referent@example.org' ];
		$manon   = $named[ 'membre@example.org' ];
		$camille = $named[ 'candidat@example.org' ];
		$eric    = $named[ 'exterieur@example.org' ];

		$public  = isset( $groups[ self::REFERENCE_GROUP ] ) ? $groups[ self::REFERENCE_GROUP ] : NULL;
		$private = isset( $groups[ self::PRIVATE_GROUP ] ) ? $groups[ self::PRIVATE_GROUP ] : NULL;

		if ( !$public || !$private ) {
			return;
		}

		$guide = $this->taggableDocument(
				$manager,
				$public,
				$remi,
				'Guide des suivis partagés',
				'Lisible de tous, y compris hors connexion : un tag « # » posé dessus mène toujours quelque part.'
		);

		$note = $this->taggableDocument(
				$manager,
				$private,
				$remi,
				'Note de cadrage du bureau',
				'Déposée dans le groupe privé : un tag « # » posé dessus s’affiche grisé chez qui n’est pas membre.'
		);

		$manager->flush();

		/**
		 * A. LE TÊTE-À-TÊTE, AVEC DES TAGS ET UN MESSAGE NON LU
		 *
		 * C'est la conversation qu'on ouvre en premier : elle porte le
		 * compteur de l'en-tête chez Manon, les trois sortes de tags, et un
		 * message modifié.
		 */
		$suivi = $this->conversation( $manager, [ $remi, $manon ], '-4 days' );

		$this->privateMessage(
				$manager,
				$suivi,
				$remi,
				"Bonjour Manon,\n\nJ’ai enfin déposé #" . $guide->getTitle() . " dans le groupe. "
				. "C’est la version que nous avions relue en commission, avec les fiches de terrain en annexe.\n\n"
				. "Dis-moi si le tableau des fréquences te paraît tenable pour une équipe de trois.",
				'-4 days'
		);

		$reponse = $this->privateMessage(
				$manager,
				$suivi,
				$manon,
				"Merci @" . $remi->getName() . " ! Je l’ai parcouru ce matin.\n\n"
				. "Le tableau tient, à condition de sortir les relevés de mai — c’est le mois où nous sommes déjà "
				. "sur les comptages d’oiseaux. Je te propose de le voir jeudi.",
				'-3 days'
		);

		// Modifié après coup : le fil doit le dire, et c'est la seule façon de
		// le voir sans éditer un message à la main.
		$reponse->setEditedAt( new \DateTime( '-3 days +2 hours' ) );

		$this->privateMessage(
				$manager,
				$suivi,
				$remi,
				"Parfait pour jeudi. J’en profiterai pour te montrer ce qui se prépare dans #"
				. $public->getName() . " : deux collègues y ont posé des questions très proches des tiennes.",
				'-2 hours'
		);

		// Rémi a tout lu ; Manon s'est arrêtée avant le dernier message.
		// Sans cet écart, ni le compteur de l'en-tête ni la barre « nouveaux
		// messages » n'ont rien à montrer.
		$this->readUpTo( $suivi, $remi, '-1 hour' );
		$this->readUpTo( $suivi, $manon, '-1 day' );

		/**
		 * B. LE FIL À PLUSIEURS
		 *
		 * Quatre participants dont un parti, un message supprimé, et une
		 * conversation rangée par l'une des trois. Pas de pairKey : ce n'est
		 * plus un tête-à-tête.
		 */
		$rencontre = $this->conversation( $manager, [ $alice, $remi, $manon, $eric ], '-10 days' );

		$this->privateMessage(
				$manager,
				$rencontre,
				$alice,
				"Bonjour à toutes et tous,\n\nJe vous mets ensemble pour préparer l’atelier de la rencontre "
				. "annuelle. Il nous faut un titre, deux intervenants et une salle avant la fin du mois.",
				'-10 days'
		);

		$this->privateMessage(
				$manager,
				$rencontre,
				$eric,
				"Je peux venir présenter le comptage sur les sentiers, mais je ne pourrai pas rester la journée.",
				'-9 days'
		);

		// Supprimé : la place reste, le texte part. C'est ce qu'on ne peut pas
		// éprouver sans un message déjà supprimé, puisque supprimer le sien
		// demande de l'avoir écrit.
		$retire = $this->privateMessage( $manager, $rencontre, $remi, '', '-8 days' );
		$retire->setDeletedAt( new \DateTime( '-7 days' ) );

		$this->privateMessage(
				$manager,
				$rencontre,
				$manon,
				"Titre proposé : « Compter sans se noyer — ce que dix ans de suivis nous ont appris ».\n\n"
				. "Je m’occupe de la salle.",
				'-7 days'
		);

		$this->readUpTo( $rencontre, $alice, '-6 days' );
		$this->readUpTo( $rencontre, $remi, '-6 days' );
		$this->readUpTo( $rencontre, $manon, '-6 days' );

		// Rangée par Manon : l'onglet « Archivées » a quelque chose à montrer,
		// et la boîte de réception ne la montre plus.
		$this->archiveFor( $rencontre, $manon, '-5 days' );

		// Éric est sorti du fil. Ce qu'il y a écrit reste, et c'est le point :
		// quitter ne troue pas la conversation de ceux qui restent.
		$this->leaveFor( $rencontre, $eric, '-6 days' );

		/**
		 * C. LE TAG GRISÉ
		 *
		 * Camille attend à la porte du groupe privé : le même message affiche
		 * des liens chez Rémi et des tags grisés chez elle. C'est la seule
		 * façon de voir que le rendu dépend du lecteur.
		 */
		$accueil = $this->conversation( $manager, [ $remi, $camille ], '-2 days' );

		$this->privateMessage(
				$manager,
				$accueil,
				$remi,
				"Bonjour Camille,\n\nJ’ai bien vu votre demande pour rejoindre #" . $private->getName() . ". "
				. "Je la présente au bureau lundi ; d’ici là vous pouvez déjà lire #" . $guide->getTitle() . ", "
				. "qui est ouvert à tous.\n\n"
				. "Le document de cadrage — #" . $note->getTitle() . " — ne vous sera visible qu’une fois la "
				. "demande acceptée.",
				'-2 days'
		);

		$this->privateMessage(
				$manager,
				$accueil,
				$camille,
				"Merci beaucoup, je regarde le guide en attendant. Bonne journée.",
				'-1 day'
		);

		// Jamais ouverte par Camille : une conversation qu'on n'a pas encore
		// lue du tout, à côté de celle de Manon qu'on a lue en partie.
		$this->readUpTo( $accueil, $remi, '-1 day +1 hour' );

		/**
		 * D. LA BOÎTE FERMÉE, ET LES SIGNALEMENTS
		 *
		 * Éric a fermé sa boîte : personne ne peut lui écrire depuis sa fiche.
		 * Mais celle-ci était déjà ouverte, et Manon y répond encore — c'est
		 * la règle qu'on ne peut pas éprouver autrement.
		 */
		$litige = $this->conversation( $manager, [ $eric, $manon ], '-6 days' );

		$sec = $this->privateMessage(
				$manager,
				$litige,
				$eric,
				"Votre compte rendu ne correspond pas à ce qui a été dit en réunion. Merci de le corriger.",
				'-6 days'
		);

		$this->privateMessage(
				$manager,
				$litige,
				$manon,
				"Bonjour Éric,\n\nJe reprends volontiers le compte rendu si un point est faux — dites-moi lequel "
				. "et je le corrige aujourd’hui.",
				'-5 days'
		);

		$vif = $this->privateMessage(
				$manager,
				$litige,
				$eric,
				"Tout le paragraphe sur le pâturage. Je ne vais pas relire à votre place, c’était votre "
				. "réunion et votre travail.",
				'-4 days'
		);

		$this->readUpTo( $litige, $eric, '-4 days' );
		$this->readUpTo( $litige, $manon, '-3 days' );

		// Un signalement à traiter, avec son contexte recopié — c'est ce que
		// l'administration lit, et elle ne lit rien d'autre.
		$aTraiter = $this->report(
				$manager,
				$vif,
				$manon,
				'Le ton monte et je ne sais pas comment répondre. Je préfère que quelqu’un regarde.',
				'-3 days'
		);
		$aTraiter->setContext( [
				[
						'author' => $eric->getName(),
						'at'     => ( new \DateTime( '-6 days' ) )->format( DATE_ATOM ),
						'body'   => $sec->getBody(),
				],
				[
						'author' => $manon->getName(),
						'at'     => ( new \DateTime( '-5 days' ) )->format( DATE_ATOM ),
						'body'   => 'Bonjour Éric,' . "\n\n" . 'Je reprends volontiers le compte rendu si un point est faux.',
				],
		] );

		// Et un signalement déjà classé, sans contexte : le premier message
		// d'une conversation n'en a pas. Les deux onglets de l'écran
		// d'administration ont ainsi chacun leur ligne.
		$classe = $this->report(
				$manager,
				$sec,
				$manon,
				NULL,
				'-5 days'
		);
		$classe->setHandledAt( new \DateTime( '-4 days' ) );
		$classe->setHandledBy( $alice );

		$manager->flush();

		/**
		 * LA NOTIFICATION
		 *
		 * Comme pour les groupes, rien ne naît sans passer par
		 * NotificationSender, que les fixtures ne déclenchent pas. Sans
		 * celle-ci, le nouveau type « message:new » n'apparaît jamais dans la
		 * liste des notifications. Elle ne porte aucun groupe, et son titre
		 * est le nom de celui qui écrit : jamais un extrait du message.
		 */
		$prevenue = new Notification();
		$prevenue->setRecipient( $manon );
		$prevenue->setAuthor( $remi );
		$prevenue->setType( Notification::MESSAGE_NEW );
		$prevenue->setTitle( $remi->getName() );
		$prevenue->setUrl( '/messages?conversation=' . $suivi->getId() );
		$prevenue->setCreatedAt( new \DateTime( '-2 hours' ) );
		$prevenue->setByEmail( FALSE );

		$manager->persist( $prevenue );

		$manager->flush();
	}

	/**
	 * Un document au titre unique, posé pour qu'un « # » puisse le désigner
	 * sans ambiguïté.
	 *
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\Usergroup               $group
	 * @param \App\Entity\User                    $author
	 * @param string                              $title
	 * @param string                              $description
	 *
	 * @return \App\Entity\Document
	 */
	private function taggableDocument ( ObjectManager $manager, Usergroup $group, User $author, $title, $description ) {
		$document = new Document();
		$document->setTitle( $title );
		$document->setSlug( $this->slugGenerator->generateSlug( $title, Document::class, 'slug' ) );
		$document->setDescription( $description );
		$document->setUsergroup( $group );
		$document->setUser( $author );
		$document->setCreatedAt( new \DateTime( '-15 days' ) );

		$manager->persist( $document );

		return $document;
	}

	/**
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\User[]                  $participants
	 * @param string                              $createdAt une expression de DateTime
	 *
	 * @return \App\Entity\Conversation
	 */
	private function conversation ( ObjectManager $manager, array $participants, $createdAt ) {
		$conversation = new Conversation();
		$conversation->setCreatedAt( new \DateTime( $createdAt ) );

		// La clé n'existe que pour un tête-à-tête : c'est elle qui empêche
		// qu'écrire deux fois à la même personne ouvre un second fil.
		if ( count( $participants ) === 2 ) {
			$conversation->setPairKey( Conversation::pairKeyFor( $participants[ 0 ], $participants[ 1 ] ) );
		}

		foreach ( $participants as $participant ) {
			$link = new ConversationParticipant( $participant );
			$link->setJoinedAt( new \DateTime( $createdAt ) );

			$conversation->addParticipant( $link );

			$manager->persist( $link );
		}

		$manager->persist( $conversation );
		$manager->flush();

		return $conversation;
	}

	/**
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\Conversation            $conversation
	 * @param \App\Entity\User                    $author
	 * @param string                              $body texte brut, tags compris
	 * @param string                              $at
	 *
	 * @return \App\Entity\PrivateMessage
	 */
	private function privateMessage ( ObjectManager $manager, Conversation $conversation, User $author, $body, $at ) {
		$written = new \DateTime( $at );

		$message = new PrivateMessage();
		$message->setConversation( $conversation );
		$message->setAuthor( $author );
		$message->setBody( $body );
		$message->setCreatedAt( $written );

		$manager->persist( $message );

		// Recopiée sur la conversation, comme le fait ConversationManager :
		// c'est cette date qui trie la boîte.
		$conversation->setLastMessageAt( $written );

		$manager->flush();

		return $message;
	}

	/**
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $user
	 * @param string                   $at
	 */
	private function readUpTo ( Conversation $conversation, User $user, $at ) {
		$participant = $conversation->getParticipantFor( $user );

		if ( $participant ) {
			$participant->setLastReadAt( new \DateTime( $at ) );
		}
	}

	/**
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $user
	 * @param string                   $at
	 */
	private function archiveFor ( Conversation $conversation, User $user, $at ) {
		$participant = $conversation->getParticipantFor( $user );

		if ( $participant ) {
			$participant->setArchivedAt( new \DateTime( $at ) );
		}
	}

	/**
	 * @param \App\Entity\Conversation $conversation
	 * @param \App\Entity\User         $user
	 * @param string                   $at
	 */
	private function leaveFor ( Conversation $conversation, User $user, $at ) {
		$participant = $conversation->getParticipantFor( $user );

		if ( !$participant ) {
			return;
		}

		$participant->setLeftAt( new \DateTime( $at ) );

		// Un tête-à-tête quitté n'est plus une boîte aux lettres.
		$conversation->setPairKey( NULL );
	}

	/**
	 * @param \Doctrine\Persistence\ObjectManager $manager
	 * @param \App\Entity\PrivateMessage          $message
	 * @param \App\Entity\User                    $reporter
	 * @param string|null                         $reason
	 * @param string                              $at
	 *
	 * @return \App\Entity\MessageReport
	 */
	private function report ( ObjectManager $manager, PrivateMessage $message, User $reporter, $reason, $at ) {
		$report = new MessageReport();
		$report->setMessage( $message );
		$report->setReporter( $reporter );
		$report->setReported( $message->getAuthor() );
		$report->setReason( $reason );

		// Une copie, pas un renvoi : c'est ce qui permet à l'administration de
		// juger sans jamais ouvrir la conversation.
		$report->setExcerpt( $message->getBody() );
		$report->setConversationId( $message->getConversation() ? $message->getConversation()->getId() : NULL );
		$report->setCreatedAt( new \DateTime( $at ) );

		$manager->persist( $report );

		return $report;
	}
}
