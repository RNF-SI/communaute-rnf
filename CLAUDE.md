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
```bash
php bin/console cache:clear

# Schema changes
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate

# Admin management (no activate/deactivate commands exist)
php bin/console user:set-admin <email>
php bin/console user:unset-admin <email>

# Search index (TNTSearch)
php bin/console search:reindex:all
php bin/console search:reindex <entity>

# Geographic data
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

### Search (TNTSearch)
Full-text search via `SearchEngineManager`. Indexes are stored under `public/media/cache/indexes/` (`INDEX_DIR` env var). Reindexing is triggered automatically via event subscribers in `src/EventSubscriber/`; rebuild manually with the `search:reindex*` commands.

### Email
Swiftmailer with the Postmark transport (`MAILER_URL=postmark+api://KEY@default`, plus `POSTMARK_*` vars). Templates in `templates/emails/`.

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
