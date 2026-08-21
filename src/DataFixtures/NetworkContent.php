<?php

namespace App\DataFixtures;

/**
 * De quoi remplir une plateforme d'essai avec ce que le réseau y met
 * réellement : des commissions, des groupes de travail, des protocoles, des
 * plans de gestion, des questions de gestionnaires à d'autres gestionnaires.
 *
 * Les fixtures produisaient jusqu'ici du faux latin. On pouvait y vérifier
 * qu'un titre s'affiche, pas comprendre à quoi sert la plateforme : « Aut
 * quia rerum » ne dit rien de ce qu'un groupe contient, et une recette faite
 * là-dessus ne ressemble à rien de ce que le réseau verra.
 *
 * Tout ce qui suit est **inventé mais plausible** : les noms de commissions
 * s'inspirent de celles de RNF, les réserves citées existent, les protocoles
 * aussi. Aucune donnée réelle n'y figure — ni personne, ni compte rendu, ni
 * décision.
 */
final class NetworkContent {
	/**
	 * Les thématiques qui structurent le réseau. Servent de catégories, donc
	 * de filtre sur la liste des groupes.
	 *
	 * @var array<string, string> nom => description
	 */
	public const COMMISSIONS = [
			'Commission scientifique'          => 'Coordonne les suivis naturalistes, les protocoles communs et la valorisation des données produites par les réserves.',
			'Commission éducation'             => 'Éducation à l’environnement, accueil des scolaires, formation des animateurs et des bénévoles.',
			'Commission patrimoine géologique'  => 'Réserves géologiques, conservation des sites et des collections, médiation autour du patrimoine minéral et fossilifère.',
			'Commission milieux aquatiques'    => 'Cours d’eau, zones humides, tourbières : hydrologie, continuité écologique, restauration.',
			'Commission marine et littoral'    => 'Réserves marines et littorales, suivis subaquatiques, conciliation des usages en mer.',
			'Commission agriculture'           => 'Pastoralisme, fauche, conventions avec les exploitants, gestion des milieux ouverts.',
			'Commission forêt'                 => 'Réserves forestières, îlots de sénescence, dendromicrohabitats, sylviculture et libre évolution.',
			'Commission police de la nature'   => 'Missions de police, commissionnement des agents, relations avec l’OFB et les parquets.',
			'Commission communication'         => 'Communication du réseau, éditions, réseaux sociaux, relations presse.',
	];

	/**
	 * Groupes de travail et ateliers. Chacun porte un nom, ce qu'il fait, et
	 * la commission dont il relève.
	 *
	 * @var array<int, array{name: string, description: string, commission: string}>
	 */
	public const GROUPS = [
			[
					'name'        => 'Atelier plans de gestion',
					'description' => 'Élaboration, évaluation et renouvellement des plans de gestion. Méthode, calendrier, articulation avec les financeurs.',
					'commission'  => 'Commission scientifique',
			],
			[
					'name'        => 'Espèces exotiques envahissantes',
					'description' => 'Veille, méthodes de lutte, retours d’expérience sur les chantiers d’arrachage et de régulation.',
					'commission'  => 'Commission scientifique',
			],
			[
					'name'        => 'Suivis avifaune',
					'description' => 'Protocoles STOC, baguage, comptages hivernaux, suivi des espèces à enjeu de conservation.',
					'commission'  => 'Commission scientifique',
			],
			[
					'name'        => 'Réseau chiroptères',
					'description' => 'Suivi des colonies, gîtes d’hibernation, détection acoustique, prise en compte dans les travaux.',
					'commission'  => 'Commission scientifique',
			],
			[
					'name'        => 'Groupe tourbières',
					'description' => 'Restauration hydrologique, suivi de la végétation turfigène, stockage du carbone.',
					'commission'  => 'Commission milieux aquatiques',
			],
			[
					'name'        => 'Continuité écologique',
					'description' => 'Effacement d’ouvrages, franchissabilité, suivi des poissons migrateurs.',
					'commission'  => 'Commission milieux aquatiques',
			],
			[
					'name'        => 'Cartographie et SIG',
					'description' => 'Référentiels, QGIS, saisie de terrain, cartes de gestion et rendus pour les plans de gestion.',
					'commission'  => 'Commission scientifique',
			],
			[
					'name'        => 'Fréquentation et accueil du public',
					'description' => 'Comptage des visiteurs, aménagements, signalétique, conciliation entre accueil et tranquillité des espèces.',
					'commission'  => 'Commission éducation',
			],
			[
					'name'        => 'Animation scolaire',
					'description' => 'Séquences pédagogiques par cycle, malles, formation des enseignants, aires éducatives.',
					'commission'  => 'Commission éducation',
			],
			[
					'name'        => 'Pastoralisme et milieux ouverts',
					'description' => 'Conventions de pâturage, races rustiques, chargement, suivi de l’effet du troupeau sur la flore.',
					'commission'  => 'Commission agriculture',
			],
			[
					'name'        => 'Forêts en libre évolution',
					'description' => 'Îlots de sénescence, bois mort, dendromicrohabitats, protocoles de suivi à long terme.',
					'commission'  => 'Commission forêt',
			],
			[
					'name'        => 'Suivis subaquatiques',
					'description' => 'Plongée scientifique, herbiers, peuplements de poissons, cantonnements de pêche.',
					'commission'  => 'Commission marine et littoral',
			],
			[
					'name'        => 'Changement climatique',
					'description' => 'Effets observés, indicateurs, adaptation des plans de gestion, sentinelles du climat.',
					'commission'  => 'Commission scientifique',
			],
			[
					'name'        => 'Patrimoine géologique',
					'description' => 'Inventaire, conservation des sites et des collections, lutte contre le pillage.',
					'commission'  => 'Commission patrimoine géologique',
			],
			[
					'name'        => 'Police et réglementation',
					'description' => 'Rédaction des procès-verbaux, commissionnement, relations avec les parquets et l’OFB.',
					'commission'  => 'Commission police de la nature',
			],
			[
					'name'        => 'Financements et mécénat',
					'description' => 'Appels à projets, LIFE, mécénat d’entreprise, montage et suivi financier.',
					'commission'  => 'Commission communication',
			],
			[
					'name'        => 'Communication et éditions',
					'description' => 'Lettre du réseau, réseaux sociaux, expositions, relations avec la presse locale.',
					'commission'  => 'Commission communication',
			],
			[
					'name'        => 'Nouveaux conservateurs',
					'description' => 'Entraide et parrainage pour celles et ceux qui prennent leurs fonctions dans une réserve.',
					'commission'  => 'Commission scientifique',
			],
	];

	/**
	 * Des échanges tels qu'ils se déroulent : une question posée par quelqu'un
	 * qui bute sur quelque chose, une ou deux réponses de collègues.
	 *
	 * @var array<int, array{title: string, messages: string[]}>
	 */
	public const DISCUSSIONS = [
			[
					'title'    => 'Quel protocole pour le suivi des odonates ?',
					'messages' => [
							'Bonjour à tous,</p><p>Nous reprenons le suivi des libellules sur nos mares après trois ans d’interruption. Le protocole que nous utilisions comptait les exuvies sur des transects fixes, mais l’agent qui l’avait mis en place est parti et la méthode n’a jamais été écrite.</p><p>Est-ce que certains d’entre vous ont un protocole odonates formalisé qu’ils accepteraient de partager ? Nous cherchons quelque chose de tenable avec une demi-journée par mois d’avril à août.',
							'Bonjour,</p><p>Nous suivons le protocole STELI depuis 2019, avec des passages toutes les trois semaines. C’est un peu plus lourd que ce que vous décrivez, mais les données remontent directement à l’OPIE et l’effort d’analyse est fait pour nous.</p><p>Je peux vous envoyer notre fiche de terrain, elle tient sur une page.',
							'Nous avons fait le choix inverse : exuvies uniquement, sur cinq transects de 50 m. C’est moins riche mais beaucoup plus reproductible d’un observateur à l’autre — et ça, sur dix ans, ça compte plus que la finesse du protocole.',
					],
			],
			[
					'title'    => 'Renouvellement du plan de gestion : par où commencer ?',
					'messages' => [
							'Notre plan de gestion arrive à échéance fin 2027. Nous n’avons jamais mené un renouvellement complet et je mesure mal ce que ça représente en charge de travail.</p><p>Combien de temps faut-il prévoir, et à quel moment associer le comité consultatif ? Tout retour d’expérience m’intéresse.',
							'Compte deux ans, sincèrement. La partie diagnostic est la plus longue, surtout si les suivis n’ont pas été saisis au fil de l’eau.</p><p>Notre conseil : associer le comité consultatif dès le diagnostic, pas au moment de valider les objectifs. Sinon on se retrouve à défendre des choix déjà écrits, et ça se passe mal.',
					],
			],
			[
					'title'    => 'Retour d’expérience sur l’arrachage de la renouée',
					'messages' => [
							'Trois ans de chantiers d’arrachage manuel sur nos berges, et le bilan est mitigé : la renouée recule là où nous sommes passés quatre fois par an, elle revient partout ailleurs.</p><p>Quelqu’un a-t-il essayé le bâchage sur des surfaces importantes ? Le coût nous fait hésiter.',
							'Bâchage sur 800 m² chez nous, depuis 2023. Ça marche, mais il faut compter trois saisons complètes avant de retirer la bâche, et surveiller les bords où les rhizomes ressortent.</p><p>Le vrai gain, c’est qu’on libère du temps d’agent : une visite par mois au lieu de quatre chantiers.',
							'Attention à la gestion des déchets verts : chez nous le prestataire a transporté les résidus vers une plateforme de compostage classique, et nous avons essaimé la renouée sur trois nouveaux sites. Cette erreur nous a coûté deux ans.',
					],
			],
			[
					'title'    => 'Comptage des visiteurs : quel matériel ?',
					'messages' => [
							'Nous voudrions objectiver la fréquentation de nos sentiers, aujourd’hui estimée « au doigt mouillé ». Éco-compteurs, boîtiers pyroélectriques, comptage manuel : qu’utilisez-vous, et à quel coût ?',
							'Éco-compteurs sur deux entrées depuis 2021. Fiables, mais il faut prévoir le remplacement des piles deux fois par an et un abonnement pour la remontée des données.</p><p>Point d’attention : ils comptent les passages, pas les personnes. Un aller-retour fait deux. Nous avons mis six mois à comprendre pourquoi nos chiffres semblaient doubler.',
					],
			],
			[
					'title'    => 'Convention de pâturage : quelles clauses prévoir ?',
					'messages' => [
							'Nous préparons une convention avec un éleveur pour le pâturage de nos pelouses sèches. C’est une première pour nous.</p><p>Quelles clauses vous semblent indispensables ? Notamment sur les dates d’entrée et de sortie, le chargement, et ce qui se passe si l’exploitant cesse son activité.',
							'Les trois clauses que nous ne signons plus sans : dates d’entrée et de sortie révisables chaque année selon la phénologie, chargement plafonné en UGB/ha, et interdiction du traitement antiparasitaire à l’ivermectine dans le mois précédant l’entrée sur le site.</p><p>Cette dernière est celle qu’on oublie, et c’est celle qui fait le plus de dégâts sur les coprophages.',
							'J’ajouterais une clause de sortie anticipée en cas de sécheresse. Nous l’avons ajoutée après 2022, où nous avons dû négocier dans l’urgence avec un troupeau déjà sur place et plus rien à manger.',
					],
			],
			[
					'title'    => 'Saisie des données naturalistes : quel outil ?',
					'messages' => [
							'Nous saisissons encore sous tableur, et la reprise de dix ans de données pour le plan de gestion s’annonce douloureuse.</p><p>Qui utilise GeoNature au quotidien ? Est-ce tenable pour une équipe de trois personnes sans informaticien ?',
							'Nous l’utilisons depuis 2022, équipe de quatre. C’est tenable, mais il faut accepter de dépendre d’un prestataire pour les montées de version.</p><p>Le gain est réel sur la saisie de terrain avec l’application mobile : plus de double saisie le soir.',
					],
			],
			[
					'title'    => 'Îlot de sénescence : convaincre le propriétaire',
					'messages' => [
							'Une parcelle forestière privée jouxte la réserve et présente de très beaux gros bois. Le propriétaire n’est pas hostile mais s’inquiète de « laisser perdre » du bois.</p><p>Quels arguments ont fonctionné chez vous ? Existe-t-il des dispositifs d’indemnisation mobilisables ?',
							'Le contrat Natura 2000 « bois sénescents » indemnise l’immobilisation pour trente ans, sur la base d’un barème à l’arbre. C’est ce qui a débloqué deux dossiers chez nous.</p><p>L’argument qui porte le mieux reste la visite de terrain : montrer un arbre à cavités occupé, ça vaut tous les tableaux.',
					],
			],
			[
					'title'    => 'Sécheresse 2026 : quels effets observés sur vos sites ?',
					'messages' => [
							'Nous avons relevé un assèchement complet de deux mares permanentes cet été, du jamais vu depuis l’ouverture des suivis.</p><p>Constatez-vous la même chose ailleurs ? L’idée serait de rassembler ces observations plutôt que de les laisser dans nos rapports d’activité respectifs.',
							'Même constat sur nos annexes hydrauliques, en eau toute l’année jusqu’en 2020.</p><p>Je suis très favorable à une compilation à l’échelle du réseau. Une page partagée où chacun dépose ses observations suffirait pour commencer.',
							'Attention à bien distinguer effet du climat et effet des prélèvements en amont. Chez nous les deux jouent, et l’attribution est délicate. Il faudrait au minimum joindre les chroniques piézométriques.',
					],
			],
			[
					'title'    => 'Procès-verbal pour circulation motorisée : vos pratiques',
					'messages' => [
							'Nous relevons de plus en plus de passages de quads. Nos agents sont commissionnés mais rédigent peu de procès-verbaux, faute d’être à l’aise avec la procédure.</p><p>Comment procédez-vous ? Formation interne, appui de l’OFB, modèles de rédaction ?',
							'Nous avons organisé une journée avec le parquet local. Voir le substitut expliquer ce qu’il attend d’un procès-verbal a plus fait pour la confiance des agents que trois formations réglementaires.',
					],
			],
			[
					'title'    => 'Aire éducative : retours des premières années',
					'messages' => [
							'Nous démarrons une aire terrestre éducative avec une classe de CM1-CM2 à la rentrée. Les collègues qui en portent une depuis plusieurs années : qu’est-ce que vous feriez différemment ?',
							'Ce que je referais différemment : associer l’équipe enseignante **avant** de choisir le site. Nous avions un beau site, difficile d’accès, et la moitié des séances est partie en transport.</p><p>Et prévoir la suite : les enfants de CM2 partent au collège, il faut un relais pour que le projet ne s’arrête pas chaque année.',
					],
			],
			[
					'title'    => 'Remplacement d’un passage à gué : quelles autorisations ?',
					'messages' => [
							'Le gué qui dessert la partie amont de la réserve est emporté à chaque crue. Nous envisageons une passerelle, mais les démarches réglementaires nous semblent hors de proportion.</p><p>Quelqu’un a-t-il mené un projet similaire récemment ?',
					],
			],
			[
					'title'    => 'Séminaire annuel : propositions d’ateliers',
					'messages' => [
							'Le séminaire se tiendra en octobre. Nous ouvrons l’appel à propositions d’ateliers : format d’une demi-journée, une vingtaine de participants.</p><p>Déposez vos propositions dans ce fil, nous ferons le tri fin juin.',
							'Nous proposons un atelier « premiers pas en libre évolution » : ce qu’on arrête de faire, comment l’expliquer au comité consultatif, et comment suivre ce qui se passe ensuite.',
							'Une proposition sur la conciliation accueil du public / tranquillité de l’avifaune, à partir de trois cas concrets où il a fallu fermer un sentier.',
					],
			],
	];

	/**
	 * Pages : ce qui doit rester, par opposition à ce qui se discute.
	 *
	 * @var array<int, array{title: string, body: string[]}>
	 */
	public const PAGES = [
			[
					'title' => 'Comment fonctionne ce groupe',
					'body'  => [
							'Ce groupe rassemble les personnes du réseau qui travaillent sur ce sujet, quelle que soit leur structure. Il n’y a pas de condition d’entrée : si le thème vous concerne, vous y avez votre place.',
							'Les échanges se font dans l’onglet Discussions. Chaque message part par courriel aux membres qui suivent le groupe, et l’on peut répondre directement depuis sa boîte.',
							'Les documents de référence — protocoles, comptes rendus, modèles — sont déposés dans l’onglet Documents et rangés par dossier. Ce qui doit rester accessible dans deux ans a sa place ici, sur une page, plutôt qu’au fil d’une discussion.',
					],
			],
			[
					'title' => 'Protocole de suivi commun — version 2026',
					'body'  => [
							'Cette page décrit la version en vigueur du protocole partagé. Elle est mise à jour après chaque réunion annuelle ; les versions précédentes restent disponibles dans le dossier Archives.',
							'**Période d’observation.** Six passages entre avril et septembre, espacés d’au moins quinze jours. Un passage annulé pour cause de météo se rattrape dans les dix jours.',
							'**Conditions.** Température supérieure à 13 °C, vent inférieur à 30 km/h, absence de pluie. Ces seuils ne sont pas indicatifs : une donnée acquise hors conditions n’est pas comparable aux autres et fausse les tendances.',
							'**Saisie.** Dans les quinze jours suivant le passage. Au-delà, l’expérience montre que les carnets de terrain restent dans les sacs.',
					],
			],
			[
					'title' => 'Compte rendu de la réunion annuelle',
					'body'  => [
							'La réunion s’est tenue sur deux jours et a rassemblé une trentaine de participants représentant une vingtaine de réserves.',
							'**Points validés.** La reconduction du protocole commun sans modification, la constitution d’un sous-groupe sur la question des données historiques, et le principe d’une rencontre de terrain au printemps prochain.',
							'**Points laissés ouverts.** Le financement du temps d’analyse, qui repose aujourd’hui sur le bénévolat de trois personnes. Ce point sera porté devant le conseil d’administration.',
					],
			],
			[
					'title' => 'Modèles et documents types',
					'body'  => [
							'Les modèles rassemblés ici ont été relus par plusieurs réserves et peuvent être repris tels quels : convention de pâturage, fiche de terrain, trame de rapport d’activité, courrier type au comité consultatif.',
							'Si vous les adaptez, pensez à déposer votre version dans les documents du groupe : c’est en comparant les adaptations que la trame commune s’améliore.',
					],
			],
			[
					'title' => 'Qui contacter, et pour quoi',
					'body'  => [
							'Pour une question technique sur le protocole, le plus simple reste d’ouvrir une discussion : la réponse profite à tout le monde et reste consultable.',
							'Pour une demande qui ne peut pas être publique — une difficulté avec un partenaire, une situation individuelle — l’annuaire donne les coordonnées de chacun, pour celles et ceux qui ont choisi de les publier.',
					],
			],
			[
					'title' => 'Les erreurs qu’on a tous faites une fois',
					'body'  => [
							'Cette page rassemble les pièges signalés par les membres du groupe. Elle n’a pas vocation à être exhaustive, seulement à éviter que chacun les découvre à son tour.',
							'**Transporter des déchets verts d’une station de renouée sans précaution.** On essaime l’espèce sur les sites traversés. Le retour en arrière prend des années.',
							'**Poser un éco-compteur sans réfléchir aux allers-retours.** Il compte les passages, pas les personnes : les chiffres semblent doubler sans raison.',
							'**Valider les objectifs d’un plan de gestion avant d’avoir associé le comité consultatif.** On se retrouve à défendre des choix déjà écrits, et la confiance se perd pour la durée du plan.',
					],
			],
	];

	/**
	 * Actualités : ce qu'on annonce.
	 *
	 * @var array<int, array{title: string, body: string[]}>
	 */
	public const ARTICLES = [
			[
					'title' => 'Le séminaire annuel se tiendra en octobre',
					'body'  => [
							'La date est fixée : le séminaire du réseau se tiendra sur trois jours en octobre, avec une journée de terrain le deuxième jour.',
							'L’appel à propositions d’ateliers est ouvert jusqu’à fin juin. Les propositions se déposent dans la discussion prévue à cet effet.',
					],
			],
			[
					'title' => 'Nouvelle version du protocole partagé',
					'body'  => [
							'La version 2026 du protocole est en ligne. Deux changements seulement, mais ils modifient la façon de remplir la fiche de terrain : le seuil de température passe à 13 °C, et l’intervalle minimal entre deux passages est porté à quinze jours.',
							'Les données acquises selon la version précédente restent exploitables ; la correspondance entre les deux versions est décrite dans la page du protocole.',
					],
			],
			[
					'title' => 'Une formation « premiers pas en police de la nature »',
					'body'  => [
							'Trois sessions sont programmées cette année, chacune limitée à douze participants, avec une demi-journée en présence d’un magistrat.',
							'La formation s’adresse en priorité aux agents nouvellement commissionnés, mais reste ouverte à celles et ceux qui souhaitent reprendre la procédure depuis le début.',
					],
			],
			[
					'title' => 'Bilan des comptages hivernaux',
					'body'  => [
							'Les comptages de cet hiver ont mobilisé une soixantaine d’observateurs sur l’ensemble des sites du réseau.',
							'Les effectifs sont stables sur la plupart des espèces suivies, avec deux exceptions notables qui font l’objet d’une analyse en cours. Le rapport complet sera déposé dans les documents du groupe.',
					],
			],
			[
					'title' => 'Un appel à projets pour la restauration des zones humides',
					'body'  => [
							'L’agence de l’eau ouvre un appel à projets dédié à la restauration hydrologique, avec un taux d’aide pouvant atteindre 70 % pour les travaux.',
							'Le dépôt se fait en deux temps, avec une note d’intention à remettre avant l’été. Les collègues qui ont déposé l’an dernier proposent de relire les notes d’intention de ceux qui se lancent.',
					],
			],
			[
					'title' => 'Deux nouvelles réserves rejoignent le réseau',
					'body'  => [
							'Deux réserves naturelles régionales ont rejoint le réseau ce trimestre. Leurs équipes sont invitées à se présenter dans le groupe des nouveaux conservateurs.',
							'Un parrainage est proposé à chaque nouvelle équipe : un contact identifié dans une réserve comparable, à qui poser les questions qu’on n’ose pas poser en réunion.',
					],
			],
	];

	/**
	 * Documents : titre et description. Aucun fichier n'est attaché — les
	 * fixtures n'ont pas à écrire dans le stockage.
	 *
	 * @var array<int, array{title: string, description: string}>
	 */
	public const DOCUMENTS = [
			[
					'title'       => 'Protocole de suivi commun — version 2026',
					'description' => 'Version en vigueur, applicable à partir de la saison prochaine. Remplace la version 2021.',
			],
			[
					'title'       => 'Fiche de terrain recto-verso',
					'description' => 'À imprimer sur papier résistant. Tient dans une poche, conçue pour être remplie debout.',
			],
			[
					'title'       => 'Compte rendu de la réunion annuelle',
					'description' => 'Relevé de décisions et points laissés ouverts. Validé par les participants.',
			],
			[
					'title'       => 'Modèle de convention de pâturage',
					'description' => 'Trame relue par trois réserves et par un juriste. Les clauses en commentaire signalent ce qui se négocie et ce qui ne se négocie pas.',
			],
			[
					'title'       => 'Plan de gestion 2026-2035',
					'description' => 'Document complet, diagnostic compris. Le résumé pour le comité consultatif est déposé séparément.',
			],
			[
					'title'       => 'Résumé du plan de gestion pour le comité consultatif',
					'description' => 'Douze pages, sans jargon. C’est cette version qui est envoyée aux élus et aux partenaires.',
			],
			[
					'title'       => 'Trame de rapport d’activité annuel',
					'description' => 'Structure commune permettant de comparer les rapports d’une réserve à l’autre.',
			],
			[
					'title'       => 'Guide d’identification des exuvies d’odonates',
					'description' => 'Clé simplifiée pour les espèces les plus fréquentes en plaine. Ne remplace pas la clé de référence pour les cas douteux.',
			],
			[
					'title'       => 'Bilan des comptages hivernaux',
					'description' => 'Effectifs par site et par espèce, avec les séries depuis 2010.',
			],
			[
					'title'       => 'Cartographie des habitats — couche SIG',
					'description' => 'Format GeoPackage, projection Lambert 93. La légende et la table de correspondance EUNIS sont incluses.',
			],
			[
					'title'       => 'Note technique sur l’arrachage de la renouée',
					'description' => 'Retour de trois chantiers, avec les coûts réels et ce qui n’a pas fonctionné.',
			],
			[
					'title'       => 'Séquence pédagogique cycle 3 — la mare',
					'description' => 'Trois séances d’une heure trente, matériel listé, fiches élèves incluses.',
			],
			[
					'title'       => 'Modèle de procès-verbal',
					'description' => 'Trame commentée, relue avec le parquet. À adapter à la situation constatée.',
			],
			[
					'title'       => 'Chroniques piézométriques 2015-2026',
					'description' => 'Relevés mensuels sur les six piézomètres du site. Tableur, une feuille par ouvrage.',
			],
			[
					'title'       => 'Affiche de sensibilisation — dérangement de l’avifaune',
					'description' => 'Format A2, à imprimer. Les fichiers sources sont disponibles sur demande.',
			],
			[
					'title'       => 'Inventaire des dendromicrohabitats',
					'description' => 'Relevés sur les placettes permanentes, méthode Larrieu et Cabanettes.',
			],
	];

	/**
	 * Dossiers de rangement des documents.
	 *
	 * @var string[]
	 */
	public const FOLDERS = [
			'Protocoles',
			'Comptes rendus',
			'Modèles et trames',
			'Cartographie',
			'Documents pédagogiques',
			'Archives',
	];

	/**
	 * Fonctions telles qu'elles s'écrivent dans le réseau.
	 *
	 * @var string[]
	 */
	public const JOB_TITLES = [
			'Conservateur de réserve naturelle',
			'Conservatrice de réserve naturelle',
			'Garde technicien',
			'Garde technicienne',
			'Chargé de mission scientifique',
			'Chargée de mission scientifique',
			'Animateur nature',
			'Animatrice nature',
			'Responsable de pôle biodiversité',
			'Chargé d’études naturaliste',
			'Chargée d’études naturaliste',
			'Technicien de gestion des milieux',
			'Technicienne de gestion des milieux',
			'Coordinateur de réseau',
			'Coordinatrice de réseau',
			'Chargé de mission éducation à l’environnement',
			'Chargée de mission éducation à l’environnement',
			'Directeur de structure gestionnaire',
			'Directrice de structure gestionnaire',
			'Chargé de communication',
			'Chargée de communication',
	];

	/**
	 * Structures gestionnaires, telles qu'on en trouve dans le réseau.
	 *
	 * @var string[]
	 */
	public const ORGANISATIONS = [
			'Conservatoire d’espaces naturels',
			'Parc naturel régional',
			'Ligue pour la protection des oiseaux',
			'Office national des forêts',
			'Syndicat mixte de gestion',
			'Communauté de communes',
			'Département',
			'Fédération des chasseurs',
			'Association de protection de la nature',
			'Conservatoire du littoral',
			'Établissement public territorial de bassin',
			'Muséum d’histoire naturelle',
	];

	/**
	 * Réserves naturelles, telles qu'elles apparaissent dans un annuaire.
	 *
	 * @var string[]
	 */
	public const RESERVES = [
			'RN de la Bassée',
			'RN du Marais d’Orx',
			'RN de la Tourbière de Mathon',
			'RN de Sainte-Victoire',
			'RN des Coussouls de Crau',
			'RN de la Baie de Somme',
			'RN du Lac de Grand-Lieu',
			'RN de Chastreix-Sancy',
			'RN des Gorges de l’Ardèche',
			'RN de la Petite Camargue alsacienne',
			'RN du Vallon de Valcluse',
			'RN de la Pointe de Givet',
			'RN des Hauts de Chartreuse',
			'RN de l’Étang du Cousseau',
			'RN de la Massane',
	];

	/**
	 * La phrase de présentation, limitée à 32 caractères par l'entité.
	 *
	 * @var string[]
	 */
	public const PRESENTATIONS = [
			'Botaniste',
			'Ornithologue',
			'Gestion des milieux ouverts',
			'Zones humides',
			'Éducation à l’environnement',
			'Cartographie et SIG',
			'Entomologie',
			'Police de la nature',
			'Chiroptères',
			'Herpétologie',
			'Forêt et bois mort',
			'Milieux marins',
			'Géologie',
			'Animation de réseau',
	];

	/**
	 * Un corps de page prêt à l'emploi, à partir de paragraphes.
	 *
	 * @param string[] $paragraphs
	 *
	 * @return string
	 */
	public static function body ( array $paragraphs ) {
		return '<p>' . implode( '</p><p>', $paragraphs ) . '</p>';
	}

	/**
	 * L'entrée n° $index d'une liste, en repartant du début une fois la fin
	 * atteinte. Permet de parcourir un contenu sans jamais tomber à court, et
	 * sans hasard : deux chargements donnent la même chose.
	 *
	 * @param array $list
	 * @param int   $index
	 *
	 * @return mixed
	 */
	public static function pick ( array $list, $index ) {
		$values = array_values( $list );

		return $values[ $index % count( $values ) ];
	}
}
