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
- **File / Upload** — managed uploads
- **LogEvent**, **AppLink / AppLinkGroup**, **Skill**

### Authentication (`src/Security/`)
Two parallel mechanisms:
- **Form login** — email/password via `LoginFormAuthenticator`, `UserChecker`.
- **RNF external auth** — single sign-on against the GeoNature API (`RnfAuthService`, `RnfAuthenticatorGuard`, `RnfUserProvider`, `RnfAuthController`), configured via `RNF_AUTH_*` env vars.

### Authorization
Voter-based, per resource type: `GroupVoter`, `GroupArticleVoter`, `GroupDiscussionVoter`, `GroupDocumentVoter`, `GroupFileVoter`, `GroupPageVoter`, `UserVoter`.

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

### Notification settings (#34, #40)
What a member hears about is read from **two sources, in this order**: what
the group itself says (`UsergroupMembership::getOwnNotificationLevel` — which
reads a pre-#34 `unsubscribed` flag as « aucune notification » on all four
categories, so the settings page shows a muted group as muted), then the
member's general setting (`User::getDefaultNotificationLevel`, stored in the
`notificationsSettings` JSON alongside `emails` — no column of its own). Get
that order wrong and you either resubscribe people who had opted out, or
silently override a choice they made on purpose.

**Le rythme est dans le niveau, pas à côté (#40).** `NotificationLevel` porte
cinq valeurs — `none`, `app`, `immediate`, `daily`, `weekly` — de sorte qu'une
seule liste dit à la fois s'il y a un e-mail et quand il part, catégorie par
catégorie et groupe par groupe. Le défaut est `daily`. Avant #40, la valeur
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
discussion à chaud (`DiscussionSender`, avec son `Reply-To`), et depuis #40 le
contenu à chaud — page, actualité, document — par `ContentSender`, qui emprunte
le même transport en lot que les discussions mais l'adresse d'expédition de la
plateforme. `NotificationSender` marque `emailedAt` sur ce qui vient de partir
pour que le résumé ne le reprenne pas ; si le transport refuse, rien n'est
marqué et le résumé rattrape.

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
