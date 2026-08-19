# Communauté RNF

Plateforme collaborative pour animer la communauté des réserves naturelles de France et leurs commissions.

## Description

La Communauté RNF est une plateforme web collaborative dédiée au réseau des Réserves Naturelles de France (RNF). Elle facilite les échanges, le partage d'expériences et la coordination entre tous les acteurs du réseau des réserves naturelles :

- Gestionnaires de réserves naturelles
- Membres des commissions scientifiques, éducatives et techniques

La plateforme permet de créer des groupes thématiques, partager des ressources, organiser des discussions et mutualiser les expertises au sein du réseau RNF.

## Technologies

- **Backend** : Symfony 4.4 LTS, PHP 7.3+, MySQL/MariaDB
- **Frontend** : Webpack Encore, SCSS, ES6 JavaScript
- **Search** : TNTSearch pour la recherche full-text
- **Maps** : Leaflet pour la géolocalisation
- **Email** : Postmark pour les notifications

## Installation

### Prérequis

- PHP 7.3+ avec extensions (mysql, gd, intl, zip, xml)
- Composer
- Node.js et npm
- MySQL 5.7+ ou MariaDB 10.3+

## Configuration

### Variables d'environnement obligatoires

Avant de procéder à l'installation, vous **devez** configurer ces variables dans votre fichier `.env.local` :

- `APP_ENV` - Environnement (dev/prod)
- `DATABASE_URL` - Connexion à la base de données MySQL
- `DATABASE_PREFIX` - Préfixe des tables (recommandé: communaute_rnf_)
- `COMMUNITY_SLUG` - **OBLIGATOIRE** - Nom du groupe communautaire principal
- `APP_SECRET` - Clé secrète pour Symfony

**Exemple minimal de configuration obligatoire :**

```bash
APP_ENV=dev
APP_SECRET=your-32-character-secret-key-here
DATABASE_URL=mysql://username:password@127.0.0.1:3306/communaute_rnf
DATABASE_PREFIX=communaute_rnf_
COMMUNITY_SLUG=communaute
```

### Variables d'environnement complètes (.env.local)

Pour une installation complète avec toutes les fonctionnalités :

```bash
# ===== OBLIGATOIRE =====
# Environnement
APP_ENV=prod  # ou dev pour le développement
APP_SECRET=your-32-character-secret-key-here

# Base de données
DATABASE_URL=mysql://username:password@127.0.0.1:3306/communaute_rnf
DATABASE_PREFIX=communaute_rnf_

# Groupe communautaire (OBLIGATOIRE)
COMMUNITY_SLUG=communaute

# ===== OPTIONNEL =====
# Platform name
PLATEFORM_NAME="Communauté RNF"

# Defines platform groups
PLATEFORM_GROUP_SLUG=

# Defines mandatory information pages
# e.g
# PLATFORM_CHARTER_PAGE_SLUG=charte-de-la-plateforme
# PLATFORM_CHARTER_PAGE_GROUP_SLUG=${COMMUNITY_SLUG}
# TERMS_OF_USE_PAGE_SLUG=mentions-legales
# TERMS_OF_USE_PAGE_GROUP_SLUG=${PLATEFORM_GROUP_SLUG}
# GROUP_CREATION_HELP_PAGE_SLUG=qui-peut-creer-des-groupes
# GROUP_CREATION_HELP_PAGE_GROUP_SLUG=${PLATEFORM_GROUP_SLUG}
# RESOURCES_PAGE_GROUP_SLUG=${COMMUNITY_SLUG}
# RESOURCES_PAGE_SLUG=ressources
PLATFORM_CHARTER_PAGE_SLUG=
PLATFORM_CHARTER_PAGE_GROUP_SLUG=
TERMS_OF_USE_PAGE_SLUG=
TERMS_OF_USE_PAGE_GROUP_SLUG=
GROUP_CREATION_HELP_PAGE_SLUG=
GROUP_CREATION_HELP_PAGE_GROUP_SLUG=
RESOURCES_PAGE_SLUG=
RESOURCES_PAGE_GROUP_SLUG=

# Email (Postmark recommandé)
MAILER_URL=postmark+api://YOUR_API_KEY@default
# Defines Postmark credentials
POSTMARK_SENDER=noreply@votre-domaine.fr
POSTMARK_SERVER_TOKEN=your-server-token
POSTMARK_INBOUND_KEY=
POSTMARK_LIST_DOMAIN=list.communaute-rnf.fr
POSTMARK_BULK_TOKEN=

# Sécurité
SECURE_SCHEME=https  # Force HTTPS en production
TRUSTED_PROXIES=127.0.0.1  # Si derrière un proxy

# Authentification RNF (optionnelle)
RNF_AUTH_API_ENDPOINT=https://geonature.reserves-naturelles.org
RNF_AUTH_ID_APPLICATION=14
RNF_AUTH_APP_CODE=COMM_RNF

# Analytics feature flag
ANALYTICS_ENABLED=false

# TNTSearch parameters
INDEX_DIR='public/media/cache/indexes/'

# Admin module parameters
ASSET_DIR='public/media/layout/'

# Google Recaptcha
GOOGLE_RECAPTCHA_SITE_KEY=your-site-key
GOOGLE_RECAPTCHA_SECRET_KEY=your-secret-key
```

### Installation Backend

Clone the repository, install _composer_ then run:

```bash
composer install
```

Copy .env to .env.local and configure the settings (see Configuration section above)

If necessary, create the DB:

```bash
php bin/console doctrine:database:create
```

Create the tables:

```bash
php bin/console doctrine:migrations:migrate
```

Initialize platform configuration:

```bash
# Copier la configuration par défaut (OBLIGATOIRE pour le déploiement)
cp config/platform/default.config.yaml config/platform/config.yaml
```

**Note importante** : Cette étape est **obligatoire** lors du déploiement en production. Le fichier `config.yaml` contient la configuration des menus et autres paramètres de la plateforme.

### Installation Frontend

```bash
npm install
npm run build  # Pour la production
# OU
npm run watch  # Pour le développement avec auto-reload
```

## Données par défaut

### Compétences utilisateur

Default skills are defined as slugs in the Command _src/Command/ImportSkillsCommand.php_

To import default Skills, run:

```bash
php bin/console import:skills
```

Eventually, additional skills can be directly added in the database.

Skills translations in the different languages are managed via Symfony translations via the specific _skills_ domain.
ex: _translations/skills.fr.yml_

## General group

If the _env_ variable _COMMUNITY_SLUG_ is defined, the corresponding group will be de facto the "general" group, and every user registered will be by default a member of this group.

## Fixtures

Fill the platform with _Lorem Ipsum_:

```bash
php bin/console doctrine:fixtures:load
```

## Administration

### Gestion des utilisateurs

```bash
# Activer un utilisateur
php bin/console user:activate <user-email>

# Désactiver un utilisateur
php bin/console user:deactivate <user-email>

# Donner les droits administrateur plateforme (ROLE_ADMIN)
php bin/console user:set-admin <user-email>

# Retirer les droits administrateur plateforme
php bin/console user:unset-admin <user-email>
```

### Différences entre les rôles d'administration

- **ROLE_ADMIN** : Administrateur plateforme (accès admin global, gestion utilisateurs, analytics)
- **Admin communauté** : Administrateur du groupe communautaire (validation groupes, modération)
- **Admin groupe** : Administrateur d'un groupe spécifique (gestion membres, contenu du groupe)

Pour faire d'un utilisateur un admin de communauté, ajoutez-le comme admin du groupe défini par `COMMUNITY_SLUG`.

### Moteur de recherche

```bash
# Réindexer toutes les données
php bin/console search:reindex:all

# Réindexer un type spécifique
php bin/console search:reindex <type>
# Types disponibles : pages, discussions_messages, articles, documents, groups, members
```

### Données géographiques

```bash
# Récupérer les coordonnées géographiques depuis ville/code postal
php bin/console app:update-coordinates

# Convertir les coordonnées en identifiants NUTS
php bin/console app:update-nuts-id
```

## Tests

```bash
# Lancer les tests (inclut setup base de données)
npm run test
```

## Développement

### Commandes utiles

```bash
# Clear cache Symfony
php bin/console cache:clear

# Mise à jour du schéma de base de données
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate

# Build frontend pour développement
npm run watch

# Build frontend pour production
npm run build
```

## Déploiement en production

### Résolution des problèmes de permissions

Si vous rencontrez des problèmes d'affichage des images de profil ou des erreurs de permissions en production, consultez le guide détaillé : [DEPLOYMENT_PERMISSIONS_FIX.md](DEPLOYMENT_PERMISSIONS_FIX.md)

Ce guide couvre notamment :
- La configuration correcte des permissions pour PHP-FPM
- La résolution des problèmes d'upload et d'affichage d'images
- La configuration des index de recherche TNTSearch
- Les scripts de diagnostic disponibles

### Structure du projet

```
src/
├── Controller/        # Contrôleurs Symfony
├── Entity/           # Entités Doctrine
├── Service/          # Services métier
├── Security/         # Voters et authentification
└── Command/          # Commandes console

templates/            # Templates Twig
assets/              # Sources frontend (JS/SCSS)
public/              # Assets compilés et uploads
```

## FAQ

### Comment forcer HTTPS ?

Ajouter `SECURE_SCHEME=https` dans votre `.env.local`

### Comment gérer les proxies ?

Ajouter `TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8` dans votre `.env.local`

Ou `TRUST_ALL=1` pour faire confiance à tous les headers `X-Forwarded-*`.

### Problème de connexion base de données ?

Vérifiez que le `DATABASE_PREFIX` est bien défini et que l'utilisateur MySQL a les droits sur la base.

## Documentation interne

- [`docs/tester-les-emails.md`](docs/tester-les-emails.md) — par quel chemin part
  chaque e-mail selon l'environnement, et comment les observer en local. **Deux
  transports coexistent avec deux jetons Postmark différents** : à lire avant de
  toucher à l'envoi.
- [`docs/delivrabilite-emails.md`](docs/delivrabilite-emails.md) — SPF, DKIM,
  DMARC : ce que le DNS doit dire pour que les e-mails arrivent.
- [`docs/donnees-reelles.md`](docs/donnees-reelles.md) — jeux de données pour le
  développement, comptes de test, et import anonymisé d'une copie de production.
