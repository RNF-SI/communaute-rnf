# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Communauté RNF is a Symfony 4.4 LTS collaborative platform for the French Natural Reserves network (Réserves Naturelles de France - RNF). Users create groups and share articles, discussions, documents, and pages around natural reserve management and commission activities. The codebase is derived from the Naturadapt platform (vendor `telabotanica/communaute-rnf`); PHP 7.3.

## Essential Commands

### Development Setup
```bash
# Backend
composer install
cp .env .env.local                      # then set DATABASE_URL, DATABASE_PREFIX, COMMUNITY_SLUG, APP_SECRET
cp config/platform/default.config.yaml config/platform/config.yaml  # required, not committed
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load   # load test data
php bin/console import:skills            # import default skills

# Frontend
npm install
npm run watch    # dev build, watch mode
npm run dev      # one-off dev build
npm run build    # production build
```

### Testing
```bash
npm run test  # migrates + loads fixtures in test env, then runs ./bin/phpunit

# Run a single test
./bin/phpunit tests/Path/To/SomeTest.php
./bin/phpunit --filter testMethodName
```
CI runs on **CircleCI** (`.circleci/config.yml`) against PHP 7.3 + MySQL 5.7, env `APP_ENV=test`.

### Console Commands
**Every project command is documented in `docs/commandes.md`** — what it does,
what it writes, and when to run it. `tests/Command/CommandsDocumentedTest.php`
fails if a command is added without a section there, and if the README points
at one that no longer exists (it did, for two).

```bash
php bin/console cache:clear
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate

# Checks — write nothing
php bin/console app:preflight            # what fails silently in this environment
php bin/console app:mail:check           # DNS of the sending domain + both mail paths
php bin/console app:rnf:inspect --email= # what GeoNature says about one account

# Scheduled
php bin/console app:notifications:digest # once a day, every day (weekly readers on Monday)
php bin/console app:rnf:sync-reserves    # nightly, fills the reserves from GeoNature

# Admin management (no activate/deactivate commands exist)
php bin/console user:set-admin <email>
php bin/console user:unset-admin <email>

# Search index (TNTSearch)
php bin/console search:reindex:all
php bin/console search:reindex <entity>

# Data
php bin/console import:skills
php bin/console app:db:anonymize --force # irreversible, on a copy only
php bin/console app:update-coordinates
php bin/console app:update-nuts-id
```

## Architecture Overview

### Core Entities (`src/Entity/`)
- **User** — central entity with profile, skills, and geographic data
- **Usergroup** + **UsergroupMembership** — groups and member/admin links
- **Content**: Article, Discussion (+ DiscussionMessage), Document (+ DocumentFolder, DocumentTag), Page (+ PageRevision), Category
- **Messagerie**: Conversation (+ ConversationParticipant), PrivateMessage, MessageReport
- **File / Upload** — managed uploads
- **LogEvent**, **AppLink / AppLinkGroup**, **Skill**

### Authentication (`src/Security/`)
Two parallel mechanisms:
- **Form login** — email/password via `LoginFormAuthenticator`, `UserChecker`.
- **RNF external auth** — single sign-on against the GeoNature API (`RnfAuthService`, `RnfAuthenticatorGuard`, `RnfUserProvider`, `RnfAuthController`), configured via `RNF_AUTH_*` env vars.

**Rester connecté, c'est la session PHP et rien d'autre.** Ni « remember me »,
ni jeton persistant : `RnfAuthService` range les données SSO dans la session, et
`RnfAuthenticatorGuard::supportsRememberMe()` rend `FALSE`. Ce que règle le bloc
`session` de `config/packages/framework.yaml` est donc, très exactement, la
durée pendant laquelle on reste connecté — rien ne rattrape son expiration.

Deux valeurs par défaut de PHP déconnectaient tout le monde toutes les vingt
minutes : `gc_maxlifetime` à 1440 secondes, et un `save_path` partagé avec les
autres sites de la machine, que le cron de Debian balaie selon le `php.ini` de
la **CLI**. La plateforme écrit maintenant dans `var/sessions/<env>` — surtout
pas dans `%kernel.cache_dir%`, où un `cache:clear` déconnecterait tout le monde
à chaque déploiement — et ramasse elle-même. `SESSION_LIFETIME` (30 jours) règle
d'un seul geste le cookie et le fichier : les laisser diverger, c'est présenter
un cookie valide pour une session déjà effacée.

`SessionCookieRefreshSubscriber` repousse l'échéance du cookie à chaque page
vue, parce que PHP ne l'émet qu'en créant la session : sans lui les trente jours
se compteraient depuis la connexion et non depuis la dernière visite. Il lit sa
portée dans `session.storage.options`, la **même source** que celle qui a posé
le cookie d'origine — deux lectures qui divergeraient fabriqueraient un second
cookie que le navigateur garderait à côté du premier.

`refreshUser()` retombe sur la base quand il n'y a pas de session SSO : c'est ce
qui fait vivre la connexion par mot de passe au-delà de la première requête. Ne
pas le « simplifier » en rendant `loadUserByUsername` seul maître.

### Authorization
Voter-based, per resource type: `GroupVoter`, `GroupArticleVoter`, `GroupDiscussionVoter`, `GroupDocumentVoter`, `GroupFileVoter`, `GroupPageVoter`, `UserVoter`, `ConversationVoter`.

A group has exactly **two** visibilities, `Usergroup::PUBLIC` and `Usergroup::PRIVATE` — the four-level scale (OPEN / MODERATE / RESTRICTED) belongs to upstream Naturadapt and has never existed here:
- **public** — readable by anyone, *including anonymous visitors*, and joinable without approval. Its documents download without a session, so treat a public group as published to the web.
- **private** — readable by its members only; joining goes through a request an animator approves.

**Two ways to hold full rights over a group.** `UsergroupMembership::ROLE_ADMIN`
makes a member an *animateur* of that group only — a commission referent who is
not RNF staff can hold it. On top of that, `GroupVoter` short-circuits for
`User::ROLE_ADMIN` (platform administrator, set with `user:set-admin`) **and for
anyone who is an animateur of the community group** (`UserGroupRelation::isCommunityAdmin`):
both are granted everything in *every* group, including private ones they are
not a member of. That is deliberate — the RNF team must be able to moderate
anywhere — but it is easy to miss when reading a single voter.

**Who may edit what inside a group** (#33). Any member may *create* a page, an
article, a document or a discussion. Editing and deleting a page, an article or
a document is then reserved to its author and to the group animators.
Discussions stay open: everyone edits or removes their own messages, animators
may remove any message or topic. The rule is spelled out to members on each tab
through `templates/components/permission-note.html.twig`.

### Service Layer (`src/Service/`)
Business logic lives in services, e.g.:
- File handling: `FileManager`, `UserFileManager`, `UsergroupFileManager`, `AppFileManager`, `FileMimeManager`
- Groups/members: `UserGroupRelation`, `UserGroupsManager`, `UsergroupMembersManager`, `Community` (the general community group)
- Messagerie : `ConversationManager`, `Tagging\TagParser`, `Tagging\TagScanner`
- Email: `EmailSender`, `DiscussionSender`
- Search: `SearchEngineManager` (TNTSearch)
- Other: `MapManager`, `AdminManager`, `UserAnonymize`, `SlugGenerator`, `HashGenerator`, `UrlManager`, `AppTextManager`

### File Storage (Gaufrette)
Uploads are NOT in `var/uploads`. KnpGaufrette adapters (`config/packages/knp_gaufrette.yaml`) map to:
- `userfiles` → `var/files/users`
- `usergroupfiles` → `var/files/groups`
- `appfiles` → `var/files`

Image variants are generated by LiipImagineBundle. Always go through the `*FileManager` services for upload handling and security checks.

### Document tags (#26)
`DocumentTag` is a **closed vocabulary** shared by the whole platform, kept by
administrators under `/administration/document-tags`. Documents pick from it
(many-to-many, join table `documents_tags`) and each group's document list
filters on it. Free-form tags were deliberately rejected: in a network this
size they split into synonyms within months. A folder says where a document is
filed, a tag says what it is.

### GeoNature directory data (#28)
The single sign-on already hands us `id_role`, `id_organisme` and
`roleOPNLInfo` at every login, and stores them on `User` — the last two were
read nowhere until now. On top of that, GeoNature publishes an export API
whose catalogue is public (`/api/exports/swagger-ressources/{id}`) even though
the data needs a token: **export 3, « Liens utilisateurs-réserves »**, is
filterable by `role_id` and is the only one carrying directory data. It gives
the reserves, not the job title, and only an id for the organisation.

`RnfReserves` reads it; `app:rnf:sync-reserves` fills `User::$reserves` from
it (a nightly command, not a call on every login — nobody wants their sign-in
to wait on a third-party API). Without `RNF_EXPORT_TOKEN` the service stays
silent and the field remains hand-written; an unreachable or refused export
never empties a profile. `app:rnf:inspect` prints what GeoNature actually
answers for one account, including the never-read `roleOPNLInfo` — the export
view's columns are not published anywhere, so looking is the only honest way
to know them.

### Document sheet (#32)
A document has its own page, `group_document_index`
(`/groups/{slug}/documents/{id}`): description, tags, folder, who added it,
download, and **what was said about it** — the discussions and pages whose
body links to that address. Those back-links are found by matching the URL in
the text (`findMentioningDocument` on the discussion and page repositories),
so there is no join table to keep in step and a link pasted by hand counts as
much as one inserted by the editor. From the sheet, « En discuter » opens a
pre-filled discussion carrying the link back.

### Voir un document, et le modifier en ligne (#43)

**Deux choses distinctes.** L'**aperçu** ne demande rien : sur la fiche, un PDF
s'affiche dans une `iframe` — le lecteur du navigateur, aucune bibliothèque
embarquée, aucune requête chez un tiers — et une image s'affiche telle quelle.
Le fichier reste servi par `group_document_get`, donc derrière
`GroupDocumentVoter` : rien n'est plus visible qu'avant.

`FileManager::getFile()` décide seul de deux choses, et nulle part ailleurs :
`nosniff` sur tout, et « inline » ou « pièce jointe ». Un SVG, un HTML ou un
XML se télécharge **toujours** (`FileMimeManager::mustDownload`) — ouvert dans
l'onglet, un SVG exécute son `<script>` dans notre origine, avec le cookie de
session du lecteur. Cela ne casse pas l'aperçu des images : une sous-ressource
ignore `Content-Disposition`. Corollaire à ne pas perdre : le bouton
« Télécharger » porte `?download=1`, sans quoi il ouvrirait le lecteur au lieu
d'enregistrer — le contraire de ce qu'il annonce.

**L'édition en ligne demande un serveur de plus**, OnlyOffice, qui n'est pas
fourni par la plateforme (`docs/edition-en-ligne.md`). Sans `ONLYOFFICE_URL`
tout se tait : aucun bouton, aucune route qui réponde — la règle de
`RNF_EXPORT_TOKEN`, une intégration non configurée ne fabrique pas de pages
mortes. **Les PDF ne passent pas par lui** : le navigateur les affiche seul, et
le nom du type « pdf » a changé d'une version d'OnlyOffice à l'autre.

**Trois liens, et c'est le troisième qui manque toujours.** Le navigateur
charge l'éditeur chez le serveur de documents ; le serveur de documents vient
chercher le fichier chez nous ; et **la plateforme va chercher la version
modifiée chez lui**. Une installation où seul le navigateur le voit enregistre
zéro modification, en silence. `app:preflight` éprouve ce lien-là — et il
éprouve l'adresse **interne**, pas la publique : contrôler celle du navigateur
dirait « joignable » là où le lien qui compte est coupé.

**Les deux adresses ne sont pas la même dès qu'il n'y a qu'une IP publique.**
Un reverse proxy distingue les services au nom d'hôte ; de l'intérieur, joindre
cette IP revient à taper sur sa propre passerelle, qui ne sait généralement pas
renvoyer le paquet — le hairpin NAT ne se fait pas. D'où `ONLYOFFICE_INTERNAL_URL`,
que `fetchUrl()` substitue à l'hôte annoncé par le serveur de documents en n'en
gardant que le chemin. Effet voulu qui vient avec : **on ne va jamais chercher
un fichier ailleurs que sur le serveur configuré** — un rappel forgé ne fait pas
sortir la plateforme de son réseau. Et `parse_url` acceptant une adresse
relative, le chemin est reforcé à commencer par une barre : recollé tel quel il
ne ferait pas un chemin, il ferait un autre hôte.

**Le serveur de documents n'a pas de session** : `/office/{token}/content` et
`/office/{token}/callback` vivent dans **leur propre pare-feu**, `security:
false`. Ce n'est pas seulement qu'il n'a pas de cookie —
`RnfAuthenticatorGuard::supports()` répond OUI à **toute** requête portant un
entête `Authorization: Bearer`, et c'est exactement ce qu'OnlyOffice envoie
pour signer ses appels. Dans le pare-feu principal, le callback partait
s'authentifier contre GeoNature, recevait une redirection vers la connexion, et
n'atteignait jamais son contrôleur : l'enregistrement était perdu sans une
ligne dans les journaux. Ces deux routes sont donc protégées par le jeton signé
qu'elles portent (`OnlyOfficeToken` — document,
droit, échéance, HMAC du secret de l'application). **Le droit est dans le
jeton**, parce que la configuration de l'éditeur est rendue dans la page, donc
lue par celui qui regarde : un lecteur reçoit un jeton de lecture seule, et la
route d'enregistrement refuse. `GroupDocumentVoter` est consulté **une fois**,
à l'ouverture de la page ; le callback ne le reconsulte pas, il n'a personne à
qui le demander.

Deux invariants à ne pas « simplifier » : on ne répond `{"error":0}` qu'après
avoir **vraiment** enregistré — le serveur de documents jette sa copie sur
cette réponse, la donner d'avance perd la séance ; et `document.key` change à
chaque version, sans quoi l'éditeur rouvre la précédente et l'écrit par-dessus
la nouvelle. L'enregistrement suit le remplacement de fichier de #41 : nouveau
`File` d'abord, ancien effacé ensuite.

**Trois pannes, et aucune ne doit être muette** (`assets/js/ui/onlyoffice.js`).
Le script du serveur de documents qui ne vient pas ; l'éditeur qui démarre et
ne finit jamais de charger — arrivé en préproduction, une extension bloquant
`Analytics.js` que le serveur servait pourtant en 200, son nom ressemblant à du
pistage ; et l'erreur qu'OnlyOffice signale lui-même. Les deux dernières
passent par `events.onDocumentReady` / `onError`, ajoutés **côté navigateur** :
ce sont des fonctions, elles ne traversent pas le jeton signé. Et tout ce que le
gabarit met en attributs est lu **avant** de construire l'éditeur —
`DocsAPI.DocEditor` ne remplit pas l'élément qu'on lui désigne, il le remplace,
et ses attributs partent avec lui. C'est la même raison qui met la hauteur sur
le cadre parent.

Les formats hérités — `.doc`, `.xls`, `.ppt` — s'ouvrent en **lecture seule** :
les réenregistrer reviendrait à les convertir sous les pieds de qui les a
déposés. La page le dit avant d'ouvrir l'éditeur, plutôt que de le laisser
découvrir dedans.

### Guided tour (#39)
`GuidedTour` declares the ordered steps; their wording lives in
`pages.tour.steps.*` of the translation files, so a formulation changes without
touching code while adding a step does not. A step may name a CSS `target`: the
tour outlines it and anchors the bubble to it, and falls back to a centred card
when the element is absent from the current page — no step depends on where the
tour was started.

**The tour walks the platform.** A step also names the `route` it is played on
(and `group => TRUE` when that route needs a group slug); when the next step
lives elsewhere, the button announces the destination — its `link` translation
— and `assets/js/ui/tour.js` navigates there with `tour=on`, keeping its place
in `sessionStorage` so it resumes on arrival. (`tour=on` and `tour=1` both
reopen the tour server-side; only `tour=1` — the settings link — wipes the
kept place and starts over.) Land somewhere the tour did not
send you and it does *not* reopen: it offers a « Reprendre la visite » pill in
the corner instead. `open` names a toggle to click first, which is how the
account menu unfolds itself for its own step and folds back when you move on.

Which group the group steps open depends on the person: their own first, the
community group as a fallback, and if there is neither, those steps disappear
rather than lead to a 404 — so the step count is not the same for everyone.

The dimming is the spotlight's own `box-shadow`, not a layer over the page:
nothing intercepts clicks, and the page stays usable during the tour.

It launches by itself while `User::$tourSeenAt` is null, and afterwards only
through the settings link (`?tour=1`). Closing it counts as having seen it.

### Messagerie

Une conversation privée entre **N participants** (`Conversation`) : le
tête-à-tête n'est que le cas N=2, et ajouter quelqu'un en cours de route ne
change que ce nombre. `Conversation::$pairKey` porte les deux identifiants d'un
tête-à-tête, triés, avec une **contrainte d'unicité en base** — c'est elle, et
non un contrôle dans le code, qui empêche qu'écrire deux fois à la même
personne ouvre deux fils entre lesquels la conversation se couperait. Elle est
effacée dès qu'un troisième entre, et quand quelqu'un quitte le fil : sans quoi
l'autre ne pourrait plus jamais réécrire.

`ConversationParticipant` porte trois dates — `lastReadAt`, `archivedAt`,
`leftAt` — plutôt que trois booléens. Un message ré-affiche la conversation
chez ceux qui l'avaient rangée : **archiver n'est pas se désabonner**. Quitter
laisse les messages en place, le fil de ceux qui restent serait troué sinon.

`PrivateMessage::$body` est du **texte**, pas du HTML : pas d'éditeur riche, pas
de pièce jointe. Ce qu'il contient, ce sont des tags.

**Qui peut écrire.** Tout membre actif, sauf à quelqu'un qui a fermé sa boîte
(`User::$messagesOpen`, réglé dans `/user/parameters/edit`). Fermer sa boîte
ferme la porte, pas les conversations déjà ouvertes. `ConversationVoter`
tranche — et il **ne court-circuite pas pour `ROLE_ADMIN`**, à la différence de
`GroupVoter`. C'est le point à ne pas « corriger » en relisant : l'équipe RNF
modère partout dans les groupes, mais une conversation privée n'est pas un
groupe.

**Signalement (`MessageReport`).** Le signalement **recopie** le message
incriminé et les quelques messages qui le précédaient, au moment où il est
fait. La page d'administration (`/administration/message-reports`) lit ces
copies et **n'interroge jamais la messagerie** : c'est la propriété à préserver
en la modifiant. Corollaire voulu : effacer le message après coup n'efface pas
ce qui a été signalé.

### Le dock, et le direct

Les conversations restent **ouvertes en bas de l'écran pendant qu'on lit autre
chose**. Le dock (`templates/components/messaging-dock.html.twig`,
`assets/js/messaging/dock.js`) **double la page `/messages`, il ne la remplace
pas** : tout ce qu'il fait s'y fait aussi, en HTML, sans une ligne de
JavaScript. C'est ce qui l'autorise à n'être que du confort — il est rendu
`hidden` et c'est le JavaScript qui l'allume, un dock mort ne valant pas mieux
qu'un dock absent. Il ne s'affiche pas sur `/messages` elle-même.

**La plateforme n'est pas une application d'une seule page.** Chaque lien
recharge tout, dock compris. Une conversation « reste » ouverte parce qu'elle
est **rouverte** : `sessionStorage` note ce qui était là, `restore()` le
remonte au chargement suivant. Corollaire à ne pas perdre en relisant : une
fenêtre **repliée** charge son fil avec `read=0`, sinon chaque navigation la
marquerait lue et les messages arrivés pendant qu'elle était réduite
disparaîtraient du compteur sans qu'on les ait regardés.

**C'est un sondage, pas une connexion ouverte** (`assets/js/messaging/live.js`,
route `messages_live`). SSE ou WebSocket immobiliseraient un processus PHP par
onglet, et la plateforme tourne derrière PHP-FPM : quinze personnes connectées
suffiraient à faire attendre la seizième. Trois précautions rendent le sondage
tenable — **un seul onglet interroge** (bail dans `localStorage`, les autres
écoutent par `BroadcastChannel`), **le rythme suit ce qu'on regarde** (3 s
devant un fil déplié, 10 s onglet actif, 60 s onglet caché, plus rien après une
demi-heure), et **la réponse ordinaire est vide** : deux `COUNT`. La liste des
conversations ne repart qu'en cas de changement, et **entière** plutôt qu'en
différences — cela répare tout seul un dock qui aurait manqué un tour.

Le curseur est un **identifiant de message, borné au flux du lecteur**
(`findSinceFor`), pas le dernier identifiant de la table : avancer sur les
messages des autres ferait sauter, un jour, celui d'une transaction validée
dans le désordre. Ne pas faire avancer le curseur depuis la réponse à un envoi
— l'onglet d'à côté, où la même conversation peut être ouverte, ne verrait
jamais le message arriver.

**Le corps d'un message part en HTML déjà rendu** (`Messaging\DockPresenter`),
jamais en texte. Ce n'est pas une facilité : le rendu d'un tag **dépend de qui
lit**, et ce tri se fait lecteur par lecteur, côté PHP. Le dock ne recompose
donc jamais un message et n'en met rien en cache.

Côté droits, **rien de neuf** : chaque route repasse par `ConversationVoter`,
qui ne court-circuite pas pour `ROLE_ADMIN`. Une messagerie qui s'ouvrirait
plus largement par une route JSON que par sa propre page n'aurait pas une
porte, elle en aurait deux.

### Ce qui attend quelqu'un (`Service\UnreadCounts`)

Trois nombres et un total, comptés **une seule fois et au même endroit** : la
pastille sur l'avatar, le détail ligne par ligne dans le menu, et ce que
renvoie `/messages/live` en sont trois lectures. Deux façons de compter
finiraient par diverger d'une unité, et c'est l'écart qu'un lecteur remarque.

**Un message privé n'est compté qu'une fois**, bien qu'il ouvre deux lignes en
base — une conversation non lue *et* une notification `message:new`. Le
compteur des notifications écarte donc ce type (`countUnreadExcept`) : sans
cela la pastille dirait « 2 » pour un seul message reçu. La page des
notifications, elle, continue de les lister — ce qu'elle montre est une
histoire, pas un compteur.

**« Mes discussions » détaille, il ne s'ajoute pas.** Rien en base ne suit la
lecture d'une discussion de groupe ; le nombre affiché est celui des
notifications de discussion en attente, c'est-à-dire un sous-ensemble de la
ligne « Notifications ». D'où son absence du total, et sa limite assumée : qui
a coupé les notifications d'un groupe ne verra pas ce groupe compter.

Chaque endroit qui porte un de ces nombres le rend **même à zéro, simplement
caché**, et se signale par `data-unread` : sans élément, `messaging/badge.js`
n'aurait rien à remplir quand le premier message arrive. Ce fichier ne calcule
aucun nombre — il recopie ce que le serveur renvoie.

### Tags dans un message (`Tagging\TagParser`)

`@Prénom Nom` désigne quelqu'un, `#Titre` un groupe, un document, une page, une
actualité ou une discussion. Comme les mentions de #37, **rien n'est stocké
d'autre que ce qui a été tapé** : le lien est refait à chaque affichage. Le
message reste lisible tel quel, aucune table de liaison ne se désynchronise, et
un tag écrit à la main vaut un tag inséré par la liste de suggestions. Ce qu'on
y perd : un contenu renommé perd son lien.

**Deux formes, et une seule décide laquelle.** Un titre nu s'arrête à la
première ponctuation interne — c'est ce qui rend « @Jeanne, tu peux ? » à sa
virgule —, si bien que « Guide : gestion » n'était adressable d'aucune façon.
`#"Guide : gestion"` dit où le titre finit. Trois paires se lisent (droite,
chevrons, courbes), aucune ne franchit une fin de ligne : un guillemet resté
ouvert avalerait le message. `TagScanner::write()` choisit la forme, et
`readable()` retire les guillemets à l'affichage — ils appartiennent au tag,
pas à la phrase. Le `@` **ne les lit pas** et ne doit pas l'apprendre : c'est
le même scanner que `MentionParser` emploie pour les notifications.

**Le rendu dépend de qui lit.** Le même message affiche un lien pour un membre
du groupe où vit le document, et un tag grisé (`.msg-tag__locked`, titre
conservé, lien retiré) pour quelqu'un qui n'y est pas — `GroupVoter::READ`,
lecteur par lecteur. Donc : ne rien mettre en cache, et ne pas s'en servir pour
fabriquer un e-mail, où il n'y aurait pas de lecteur.

L'ordre de résolution d'un `#` est fixe — groupe, document, page, actualité,
discussion (`TaggedThing::kinds()`) — pour qu'une ambiguïté se tranche pareil à
chaque affichage.

`TagScanner` est la mécanique commune : où commence un nom, où il s'arrête, ce
qu'on ignore de la casse et des accents. `MentionParser` (#37) s'en sert aussi
depuis cette version. Deux lectures qui divergeraient d'un caractère donneraient
un lien à l'affichage là où la notification n'aurait prévenu personne.

Côté navigateur, `assets/js/ui/message-tags.js` propose une liste après `@` ou
`#` en interrogeant `/messages/suggestions` — qui ne propose que ce que celui
qui écrit peut lui-même ouvrir. C'est du confort : sans JavaScript, on tape le
tag à la main et il est reconnu pareil, et le choix des destinataires passe par
une recherche serveur plutôt que par une liste déroulante.

**Le bouton « Insérer un lien »** (`assets/js/ui/tag-picker.js`,
`/messages/picker`) fait au clic ce que la frappe fait au clavier : il écrit un
tag, et rien d'autre. Pas de pièce jointe, pas de téléversement — un message
privé reste du texte. Trois différences avec la liste de suggestions, et
chacune tient à ce qu'on ne fait pas le même geste : le mot cherché peut être
n'importe où dans le titre et non seulement au début, une recherche vide est
légitime et rend les derniers contenus déposés, et les types sont parcourus
l'un après l'autre pour qu'un seul n'occupe pas le panneau. Le filtre des
droits, lui, est le même — sans quoi un panneau qu'on feuillette sans rien
taper serait un annuaire des groupes privés.

**C'est le serveur qui dit comment s'écrit un tag.** Chaque suggestion porte un
`insert` (`TagScanner::write()`), et le navigateur le recopie sans jamais
recomposer la syntaxe. S'il la connaissait, elle finirait par diverger de celle
qui la relit — et le premier titre biscornu donnerait un tag mort. Le bouton
est caché tant que le JavaScript ne l'a pas allumé : la phrase d'aide dit déjà
comment taper le tag à la main, et un bouton mort vaut moins qu'un bouton
absent. Tout son libellé vit dans le gabarit, en Twig ; le JavaScript ne
remplit que la liste.

### Notification settings (#34, #38)
**Cinq catégories, mais pas partout.** `NotificationCategory::all()` donne les
quatre qu'un groupe sait régler ; `general()` y ajoute `messages`, qui ne vit
dans aucun groupe et ne se règle qu'une fois, dans le réglage général.
Confondre les deux listes ferait apparaître sous chaque groupe un réglage
« messages » qui ne voudrait rien dire.

**Et `messages` n'envoie aucun e-mail.** Ni à chaud, ni dans le résumé :
`NotificationCategory::sendsEmail()` rend `FALSE` pour elle seule, `levelsFor()`
ne propose donc que `none` et `app`, et le défaut est `app`. Ce n'est pas un
réglage laissé à chacun mais une propriété de la catégorie — un e-mail par
message échangé fait un volume qui suit le nombre de conversations et non le
nombre de publications, pour prévenir de ce que la pastille et le dock montrent
déjà en se connectant. `notifyNewPrivateMessage()` pose `byEmail` à `FALSE` et
`rhythm` à `NULL` explicitement : le résumé ne lit que ce drapeau, et c'est là
qu'il doit se voir.

Les réglages choisis avant cette bascule sont **traduits à la lecture**
(`NotificationCategory::clamp()`, appelé des deux côtés de
`User::getDefaultNotificationLevel`/`set…`), jamais réécrits en base — même
principe que `NotificationLevel::fromLegacy()`. « Aucune notification » reste
« aucune » : ce qui est ramené, c'est la promesse d'e-mail, pas le silence
demandé.

La notification d'un message privé est la seule **sans groupe**, et son titre
est le **nom de celui qui écrit**, jamais un extrait : une notification qui
citerait un message privé le sortirait de la conversation.

What a member hears about is read from **two sources, in this order**: what
the group itself says (`UsergroupMembership::getOwnNotificationLevel` — which
reads a pre-#34 `unsubscribed` flag as « aucune notification » on all four
categories, so the settings page shows a muted group as muted), then the
member's general setting (`User::getDefaultNotificationLevel`, stored in the
`notificationsSettings` JSON alongside `emails` — no column of its own). Get
that order wrong and you either resubscribe people who had opted out, or
silently override a choice they made on purpose.

**Le rythme est dans le niveau, pas à côté (#38).** `NotificationLevel` porte
cinq valeurs — `none`, `app`, `immediate`, `daily`, `weekly` — de sorte qu'une
seule liste dit à la fois s'il y a un e-mail et quand il part, catégorie par
catégorie et groupe par groupe. Le défaut est `daily`. Avant #38, la valeur
valait `email` et le rythme vivait à part sur le membre, dans
`discussionRhythm`, pour les seules discussions ; cette valeur n'est plus
écrite mais elle est encore lue, par `NotificationLevel::fromLegacy()`, qui la
croise avec l'ancien rythme du membre — ceux qui avaient choisi gardent leur
choix, ceux qui n'avaient rien choisi basculent sur le quotidien. **Ne pas
réécrire ces réglages en base :** la traduction se fait à la lecture, et une
migration qui figerait l'ancien vocabulaire ferait mentir la page.

Le rythme retenu est copié sur chaque `Notification` (`setRhythm`) au moment
où elle est créée, et c'est lui que `app:notifications:digest` lit : deux
notifications d'une même personne ne partent plus forcément le même jour, et
un lundi ramasse le quotidien et l'hebdomadaire dans un seul e-mail.

**Trois chemins d'e-mail, pas deux.** Le résumé (`EmailSender`), le message de
discussion à chaud (`DiscussionSender`, avec son `Reply-To`), et depuis #38 le
contenu à chaud — page, actualité, document, et **rien de la messagerie** — par
`ContentSender`, qui emprunte
le même transport en lot que les discussions mais l'adresse d'expédition de la
plateforme. `NotificationSender` marque `emailedAt` sur ce qui vient de partir
pour que le résumé ne le reprenne pas ; si le transport refuse, rien n'est
marqué et le résumé rattrape.

**Un transport n'échoue pas seulement en levant.** Il rend un nombre — zéro
quand Postmark refuse le lot —, et sans jeton il ne fait rien du tout.
`ContentSender` jetait cette valeur : tout ce qu'on lui confiait était marqué
comme parti, et le résumé ne rattrapait jamais. Une préproduction au
`POSTMARK_BULK_TOKEN` vide enregistrait ainsi des envois qui n'avaient pas eu
lieu, sans que rien ne le signale. Le compte remis doit donc être **lu**, et un
lot partiellement remis vaut un lot refusé : mieux vaut recevoir deux fois
qu'aucune. `BulkTransport` rend `0` sans jeton, jamais `TRUE` — une valeur
« vraie » se lit « réussi ».

Corollaire à ne pas perdre : **le contenu à chaud emprunte le jeton *bulk***,
pas celui du résumé. Les deux peuvent diverger, et c'est le cas le plus
trompeur — le résumé arrive, les publications non.

**Et un HTTP 200 ne veut pas dire « envoyé ».** L'API par lot répond 200 en
portant un verdict *par message* — signature d'expéditeur non confirmée,
destinataire inactif. `BulkTransport` ne lisait que le code HTTP : il rendait
« tout est parti » là où Postmark venait de refuser chaque message un par un.
Il lit désormais chaque verdict et retient le premier refus (`getLastError()`),
qu'`app:mail:check` affiche.

**Et « remis au transport » ne veut pas dire envoyé.** Swiftmailer tourne en
**file mémoire** : `send()` rend le nombre de destinataires sans avoir joint
Postmark, l'envoi n'ayant lieu qu'à la fin de la requête. C'est ce qu'il faut
sur une page — personne n'attend un aller-retour — et c'est faux en ligne de
commande, où le résumé marquait `emailedAt` sur ce qui n'était que mis en file.
`Service\MailSpool` vide la file quand l'appelant a besoin de savoir, et
distingue **NULL (pas de file, l'envoi a déjà eu lieu) de 0 (rien n'est
sorti)** — les confondre ferait renvoyer chaque jour un résumé déjà parti.

**Trois chemins, mais surtout trois couples jeton + expéditeur.** Les
discussions écrivent depuis `noreply@POSTMARK_LIST_DOMAIN` — il faut bien que
le `Reply-To` revienne quelque part —, le contenu à chaud et le résumé depuis
`POSTMARK_SENDER`. Deux chemins partagent donc le jeton sans partager
l'expéditeur : un domaine autorisé chez Postmark et l'autre non, et les
discussions arrivent pendant que le contenu à chaud se fait refuser. C'est
pourquoi `app:mail:check --to` envoie **trois** messages et non deux : éprouver
les jetons ne suffit pas.

C'est exactement ce qui s'est produit le 25 août 2026, et la sortie est
documentée dans `docs/dns-a-faire.md` : le compte Postmark employé est celui de
Naturadapt, plafonné à cinq domaines, tous pris — `rnfrance.org` ne pouvait pas
y être ajouté. La plateforme écrit donc depuis `lists.reserves-naturelles.org`,
que ce compte autorise déjà. `POSTMARK_SENDER` porte une adresse **différente
par environnement** (`communaute-staging@…` en préproduction), pour qu'on les
distingue dans le journal Postmark.

Le changement de défaut ne se voit pas tout seul : la migration pose
`noticePending` sur les comptes existants, `components/notifications-notice`
l'annonce une fois, et personne ne repose le drapeau — les inscrits d'après ne
lisent jamais l'annonce d'un changement qu'ils n'ont pas connu.

The general setting is what makes the settings page usable for somebody
sitting in thirty groups: the choice is made once, and a group is only written
to when it has to *differ*. `/user/parameters/edit` shows the general setting
first, then one folded `<details>` per group whose summary says either « comme
le réglage général » or the categories it departs on. Sending things back
costs one gesture, never thirty: an empty value on a group's select clears
that category, and the `reset-groups` submit button clears every group at
once. `assets/js/user/notifications-settings.js` only adds comfort on top —
the name filter, the live summary, the per-group « tout remettre » button; the
page saves correctly with JavaScript off.

Careful when adding a form field: the page's tests drive it through the real
form (`#notif-<groupId>-<category>`, `notifications[groups][id][category]`,
`notifications[defaults][category]`).

### Search (TNTSearch)
Full-text search via `SearchEngineManager`. Indexes are stored under `public/media/cache/indexes/` (`INDEX_DIR` env var). Reindexing is triggered automatically via event subscribers in `src/EventSubscriber/`; rebuild manually with the `search:reindex*` commands.

### Email
Swiftmailer with the Postmark transport (`MAILER_URL=postmark+api://KEY@default`, plus `POSTMARK_*` vars). Templates in `templates/emails/`.

**Two sending paths, two Postmark tokens.** Transactional mail (digest, join
requests, password reset) goes through `EmailSender` → Swiftmailer →
`POSTMARK_SERVER_TOKEN`; discussion messages go through `DiscussionSender` →
`BulkTransport` → the Postmark API directly with `POSTMARK_BULK_TOKEN`. One can
work while the other is silent — an empty bulk token sends nothing and raises
nothing. `app:mail:check` exercises both, and reads the DNS of the sending
domain (SPF / DKIM / Return-Path / DMARC, see `MailDeliverability`): a token
being present says nothing about whether the domain lets Postmark send in its
name. See `docs/delivrabilite-emails.md`.

### Frontend (`assets/`)
- Webpack Encore, SCSS (`assets/css/`), ES6 modules (`assets/js/`).
- WYSIWYG is **Quill** (`assets/js/ui/wysiwyg.js`, `_quill-editor.scss`) — not CKEditor.
- Maps via **Leaflet** + markercluster; data viz via **D3** (`GroupVisualizationController`, `MapManager`).

### Design system (`assets/css/_config/`)

**Deux fichiers font autorité, et rien ne se décide ailleurs.**

`_colors.scss` sépare trois familles : la **marque** (`$brand_green`,
`$brand_straw`… — les valeurs de la charte RNF, telles quelles), le **sens**
(`$color_action`, `$color_success`, `$color_warning`, `$color_danger`,
`$color_info`, plus un fond teinté `*_surface` par état) et le **neutre**
(`$color_text`, `$color_text_muted`, `$color_canvas`, `$color_surface`,
`$color_border`, `$color_border_strong`).

La règle qui tient tout : **une couleur de marque ne porte jamais un rôle.**
Le fichier a longtemps fait l'inverse — la charte plaquée sur les noms hérités
de Naturadapt en réaffectant les variables (`$color_red: $color_senary`), si
bien que le nom disait « rouge » et la valeur donnait du turquoise. Erreurs de
formulaire, messages d'échec et bouton « Supprimer » s'affichaient en bleu-vert.
Les anciens noms (`$color_orange_red`, `$color_lime_green`…) existent toujours
et pointent maintenant sur la bonne sémantique ; rien de nouveau ne s'en sert.

Les teintes sémantiques sont les teintes de la charte **assombries jusqu'à
4,5:1 sur blanc et sur le fond de page**, à teinte et saturation constantes :
le vert descend de `#0B885D` à `#0A8159`, un écart invisible qui fait passer le
seuil. Ne pas « remettre la vraie couleur de la charte » sur du texte ou un
bouton : c'est précisément ce qui est corrigé. La charte reste exacte partout
où elle est décorative (aplats, pastilles de légende, `theme-color`).

`_tokens.scss` porte l'échelle : espacements `$space_1..10` (multiples de 4px),
rayons `$radius_sm/md/lg/full/circle`, ombres `$shadow_sm/md/lg` (trois
niveaux, deux couches chacun), `@mixin focus-ring`, `@mixin transition`,
z-index. Il **n'écrit aucune règle** — c'est `_setup/_root-tokens.scss`,
importé par app.scss seul, qui expose les mêmes valeurs en propriétés
personnalisées CSS. La séparation n'est pas cosmétique :
`groups-visualization.scss` et `map/*.scss` sont des feuilles chargées
directement par leur JavaScript, hors app.scss, et importent `_config/tokens`
pour leurs variables — y laisser un bloc `:root` le dupliquerait dans une
seconde feuille.

**L'ordre des imports dans app.scss compte.** Outils → points de rupture →
couleurs → tokens → **reset** → fontes → gabarit. Le reset n'est plus en tête :
il se sert désormais des variables (`mark`, `fieldset`).

**Le focus est traité une fois**, dans `_setup/_a11y.scss`, sur
`:focus-visible`. Ne jamais réécrire `outline: none` dans un composant — c'est
ce qui avait fait disparaître tout repère clavier de l'application. Le repli
pour les vieux navigateurs tient à la règle `:focus:not(:focus-visible)`, qu'ils
jugent invalide et ignorent ; ne pas l'entourer d'un `@supports selector(...)`,
la version de PostCSS du projet (7.x) ne sait pas l'analyser et la compilation
échoue.

**Typographie.** Texte courant en **Jost** (variable, 100–900, SIL OFL), titres
en **Arima Madurai** (400/700), servis depuis le domaine en woff2 :
`assets/fonts/`, six fichiers, 156 Ko. Ce qui précédait embarquait 1,48 Mo de
TTF, dont `GOTHIC.TTF` — les fichiers Century Gothic de Windows, propriété
Monotype, redistribués depuis `/build/fonts/`. Ne pas les réintroduire. Jost
étant variable, `font-weight: 500` ou `600` sont de vraies graisses et non des
synthèses.

Le `<link>` vers `fonts.googleapis.com` de `base.html.twig` a disparu : il
chargeait Open Sans, appelée nulle part, et déposait l'IP de chaque visiteur
chez un tiers.

## Key Configuration

### Environment Variables (`.env.local`)
```bash
APP_ENV=dev
APP_SECRET=<32-char-secret>
DATABASE_URL=mysql://user:pass@127.0.0.1:3306/communaute_rnf
DATABASE_PREFIX=communaute_rnf_     # table name prefix
COMMUNITY_SLUG=communaute           # REQUIRED — slug of the main community group
INDEX_DIR='public/media/cache/indexes/'

# Optional: MAILER_URL/POSTMARK_*, SECURE_SCHEME=https, TRUSTED_PROXIES,
# RNF_AUTH_* (external SSO), ANALYTICS_ENABLED, and PLATFORM_*/TERMS_OF_USE_* page slugs
```

### Platform Config
`config/platform/config.yaml` (copied from `default.config.yaml`, not committed) plus database-stored settings managed via the admin interface (site name, menus, homepage, links). See `README.md` for the full env reference.
