# Mise en préproduction

36 commits depuis `0843336`, une quinzaine d'issues. Rien n'a encore tourné
ailleurs qu'en local. Ce document sert à déployer dans le bon ordre, puis à
vérifier ce qui ne peut l'être qu'en ligne.

## 1. Avant de déployer

### Le DNS, d'abord

Voir [`delivrabilite-emails.md`](delivrabilite-emails.md). Trois enregistrements
sur `rnfrance.org`, valeurs fournies par Postmark :

- [ ] `include:spf.mtasv.net` ajouté au SPF **existant** (un seul SPF par domaine)
- [ ] clé DKIM publiée sur `pm._domainkey`
- [ ] CNAME `pm-bounces` pour le Return-Path
- [ ] domaine vérifié dans Postmark

Tant que ce n'est pas fait, DMARC est en `p=quarantine` et **tout ce que la
plateforme envoie est mis en quarantaine**.

### L'environnement

- [ ] **Droits des index de recherche.** TNTSearch écrit dans des fichiers
      SQLite sous `public/media/cache/indexes/`. Toute commande console lancée
      à la main les recrée avec les droits de celui qui la lance ; si ce n'est
      pas l'utilisateur du serveur web, **la première connexion d'un membre
      échoue** sur « attempt to write a readonly database », car créer un
      compte déclenche l'indexation. À poser une fois pour toutes :

      ```bash
      sudo chown -R DEPLOYEUR:UTILISATEUR_WEB public/media/cache var/
      sudo apt-get install -y acl
      sudo setfacl -R -m g:UTILISATEUR_WEB:rwX -m d:g:UTILISATEUR_WEB:rwX public/media/cache var/
      ```

      **Le `d:` est le point important** : il pose une règle par défaut, si bien
      que tout fichier créé ensuite dans ces répertoires est inscriptible par le
      serveur web, quel que soit l'utilisateur qui l'a créé et quel que soit son
      umask.

      ⚠️ Un `chmod g+w` ne suffit pas : il ne vaut que pour les fichiers
      existants, et la réindexation suivante recrée les index en `644`. Le bit
      `setgid` ne suffit pas non plus — il fait hériter le groupe, pas le droit
      d'écriture. Sans ACL par défaut, **la panne revient après chaque
      `search:reindex:all`**, et se manifeste au pire endroit : la première
      connexion d'un membre.
- [ ] **`imagick`** installé — LiipImagine est configuré dessus, l'application
      ne démarre pas sans
- [ ] **version de Node** vérifiée : `npm run build` casse sur Node ≥ 17
      (`ERR_OSSL_EVP_UNSUPPORTED`), or `clevercloud/post_build.sh` le lance à
      chaque déploiement
- [ ] `config/platform/config.yaml` présent, copié depuis `default.config.yaml`

### Les variables

Dans le `.env.local` de la préproduction :

```bash
SITE_HOST=preprod.communaute.rnfrance.org   # NOUVEAU — sans lui, les liens des
                                            # e-mails envoyés par la tâche
                                            # planifiée pointent vers localhost
SECURE_SCHEME=https

TEST_ACCOUNTS_EMAIL=recette@rnfrance.org    # NOUVEAU — sans lui, les six comptes
                                            # de test restent en @example.org et
                                            # ne reçoivent aucun e-mail

POSTMARK_SENDER=noreply@rnfrance.org        # vide = les demandes d'adhésion
POSTMARK_SERVER_TOKEN=…                     # échouent (cf. #4)
POSTMARK_BULK_TOKEN=…                       # vide = aucun e-mail de discussion,
                                            # silencieusement
```

### Volume : ce que ça représente vraiment

Sur la volumétrie réelle de la production — 122 comptes, 58 groupes, 8,3 membres
par groupe en moyenne :

| | Volume |
|---|---|
| Un message de discussion | ~7 e-mails |
| Résumé quotidien | **au plus 122 e-mails par jour**, quelle que soit l'activité |
| Pire des cas mensuel | ~3 700 e-mails |

Le résumé est un **plafond, pas une augmentation** : quelle que soit la quantité
de contenu publié, un membre reçoit au plus un e-mail par jour. Ce n'est pas le
volume qui menace le service d'envoi.

⚠️ **Ce qui le menace, ce sont les rebonds.** Une copie anonymisée n'a que des
adresses en `@example.org`, qui n'acceptent rien : chaque envoi produit un rebond
dur, et Postmark suspend un compte dont le taux de rebond monte — la production
tomberait avec la préproduction.

`App\Service\MailGuard` refuse désormais ces adresses **en production, et donc
en préproduction** : example.com/net/org, `localhost`, et les suffixes `.test`,
`.invalid`, `.local`, `.example`. En dev et en test rien n'est refusé, un
collecteur local sert précisément à ça. L'accident est donc structurellement
impossible, mais deux précautions restent utiles :

```yaml
# config/packages/prod/swiftmailer.yaml, préproduction seulement
swiftmailer:
    delivery_addresses: ['toi@rnfrance.org']
```

### Vérifier l'environnement en une commande

Une fois le code déployé, sur le serveur :

```bash
php bin/console app:preflight
```

Elle contrôle tout ce dont l'absence provoque une panne **silencieuse** plutôt
qu'une erreur : l'extension `imagick`, les migrations restantes, `SITE_HOST`,
les trois jetons Postmark, `config/platform/config.yaml`, les répertoires de
fichiers, et la présence des assets compilés — donc que `npm run build` n'a pas
échoué faute d'une version de Node compatible.

Elle sort en erreur sur ce qui empêche l'application de fonctionner, et signale
en « attention » ce qui la laisse tourner en silence.

## 2. Déployer

`clevercloud/post_build.sh` enchaîne migrations, cache, `import:skills` et build
front. **Dix migrations** vont s'appliquer :

| Migration | Effet |
|---|---|
| `Version20260818151610` | `inbound_message_id` sur les messages — idempotence du webhook (#12) |
| `Version20260819072259` | `description` sur les documents (#7) |
| `Version20260819073810` | `edited_at` sur les messages (#19) |
| `Version20260819075409` | `notifications_settings` sur les comptes (#34) |
| `Version20260819075543` | table `notifications` (#34) |
| `Version20260820085500` | `phone` et `email_visible` sur les comptes (#27) |
| `Version20260820120000` | **retire** `edition_restricted` des pages — colonne jamais écrite (#33) |
| `Version20260820130000` | `job_title`, `organisation`, `reserves` sur les comptes (#30) |
| `Version20260820140000` | tables `document_tags` et `documents_tags` (#26) |
| `Version20260820160000` | `tour_seen_at` sur les comptes (#39) |

Après déploiement, une fois seulement :

```bash
# L'index des membres change de colonnes : sans ça, la recherche par fonction
# ne trouve rien tant qu'un profil n'a pas été réenregistré. (#30)
php bin/console search:reindex:all
```

### À faire à la main, une fois

- **Créer les étiquettes de documents** dans Administration → Étiquettes (#26).
  Les fixtures les créent en dev, pas en production. Sans elles, ni le
  formulaire de dépôt ni le filtre ne proposent quoi que ce soit.
- **Renseigner `RNF_EXPORT_TOKEN`** si l'on veut que les réserves suivies
  viennent de GeoNature (#28). Sans jeton, le champ reste saisi à la main.

### Avant de supprimer la colonne `bio`

Elle est retirée du profil et de la fiche annuaire (#30) mais reste en base :
elle contient du texte que des gens ont écrit. Compter ce qu'elle garde encore
avant de décider :

```sql
SELECT COUNT(*) FROM communaute_rnf_users WHERE bio IS NOT NULL AND bio <> '';
```

## 3. Recette

Comptes de test : voir [`donnees-reelles.md`](donnees-reelles.md). Sur une
préproduction alimentée par une copie de production, utiliser des comptes réels
conservés avec `--keep-email`.

### Ce qui ne se vérifie qu'en ligne

| # | Scénario | Attendu |
|---|---|---|
| #9 | Se connecter par le SSO RNF | On arrive sur **« mes groupes »**, pas sur l'accueil |
| — | Cliquer un lien d'e-mail vers une discussion **sans être connecté**, puis se connecter | On arrive **sur la discussion**, pas sur « mes groupes » |
| #12 | Répondre par e-mail à une notification de discussion | La réponse apparaît dans la discussion. **N'a jamais fonctionné avant ce lot** |
| #12 | Répondre deux fois de suite au même e-mail | Un seul message, pas deux |
| #14 | Recevoir un e-mail et regarder l'en-tête | SPF, DKIM et DMARC en `pass` |
| #14 | Cliquer « se désabonner » depuis le client mail | Les e-mails s'arrêtent, les notifications restent |

### Ce qui a été vérifié en local mais mérite un coup d'œil

| # | Scénario | Attendu |
|---|---|---|
| #10 | Modifier sa photo, son lieu, ses compétences, enregistrer | Tout est conservé — y compris si le géocodage échoue |
| #16 | Ouvrir le champ compétences sans rien taper | Les 40 compétences s'affichent |
| #29 | Chercher « pâturage » dans les compétences | La compétence existe |
| #13 | Ouvrir une fiche annuaire | L'adresse est lisible, le bouton « copier » fonctionne |
| #21 | Menu profil → « Mes discussions » | Les discussions de tous les groupes, la plus active en tête |
| #25 | Déposer un fichier de 30 Mo | Accepté (vérifier aussi `client_max_body_size` côté nginx) |
| #7 | Décrire un document, puis le chercher par un mot de la description | Il remonte |
| #19 | Modifier son propre message | « modifié il y a … » s'affiche ; le message d'un autre n'est pas modifiable |
| #36 | Ouvrir l'édition du profil avec un compte SSO | Nom et nom affiché grisés, avec l'explication |
| #4 | Demander à rejoindre un groupe privé | La demande apparaît chez l'animateur **même si l'e-mail échoue** |
| #34 | Créer une page dans un groupe | Les autres membres ont une notification, pas l'auteur |
| #34 | Régler une catégorie sur « aucune », publier | Plus de notification pour cette catégorie |
| #34 | Couper la catégorie discussions, puis « suivre » une discussion | On est notifié de celle-là seulement |

### En dernier, et seulement une fois le DNS vérifié

```bash
php bin/console app:mail:check                       # le DNS autorise-t-il l'envoi ?
php bin/console app:notifications:digest --dry-run   # ce qui partirait
```

La première doit être verte **avant** la seconde : un résumé envoyé alors que
SPF et DKIM échouent abîme la réputation du domaine bien au-delà de la
plateforme.

Puis, quand le résultat est satisfaisant, planifier :

```cron
30 7 * * * cd /chemin/vers/communaute-rnf && php bin/console app:notifications:digest
```

**Une seule ligne, tous les jours** — y compris pour le résumé hebdomadaire
(#38). La commande sait qui est abonné à quoi : elle sert les abonnés au
quotidien chaque matin, et passe les abonnés hebdomadaires six jours sur sept
pour ne les servir que le lundi. Leurs notifications attendent, elles ne sont
pas perdues. Ne pas ajouter de seconde ligne hebdomadaire : elle enverrait le
résumé deux fois.

Pour voir ce que le lundi emporterait, sans attendre lundi :

```bash
php bin/console app:notifications:digest --day=2026-08-24 --dry-run
```

⚠️ **C'est le seul geste irréversible du lot.** Un envoi groupé avec DMARC en
échec, ou vers des adresses anonymisées, abîme la réputation de `rnfrance.org`
bien au-delà de la plateforme.

## 5. Retour d'expérience — ce qui a réellement coincé

Premier déploiement en préproduction, le 19 août 2026. Rien de ce qui suit
n'était prévisible depuis un poste de développement ; tout se reproduira sur la
production si on ne s'y prépare pas.

### Droits d'écriture — trois fois de suite

| Symptôme | Cause | Remède |
|---|---|---|
| `SQLSTATE[HY000]: General error: 8 attempt to write a readonly database` **à la connexion** | Créer un compte déclenche l'indexation. TNTSearch écrit dans des fichiers SQLite créés par la dernière commande console lancée à la main, donc appartenant à celui qui l'a lancée et non au serveur web. | ACL par défaut, voir §1 |
| `chmod: Operation not permitted` | Propriété mêlée : certains fichiers appartiennent au serveur web (journaux SQLite `-wal`/`-shm`, cache Symfony), et on ne peut modifier que ce qu'on possède. | `sudo chown -R`, puis ACL |
| La panne revient après chaque `search:reindex:all` | `chmod g+w` ne vaut que pour l'existant ; le bit `setgid` fait hériter le groupe mais **pas** le droit d'écriture. | ACL **par défaut** (`setfacl -d`) |

C'est le point le plus coûteux du déploiement, et il se manifeste au pire endroit
possible : **la première connexion d'un membre**. Une réindexation lancée un jour
de maintenance suffirait à bloquer toutes les nouvelles inscriptions sans que
rien d'autre ne semble cassé.

### Rattacher un serveur existant au dépôt

La préproduction avait été fabriquée en **copiant** les fichiers de production :
un répertoire `.git` vide, aucun distant, et des fichiers appartenant au serveur
web. Marche à suivre :

1. sauvegarder `.env.local`, `config/platform/config.yaml`, `var/files/` et la base ;
2. `sudo chown -R DEPLOYEUR:UTILISATEUR_WEB .` — sans quoi git refuse d'opérer
   (« dubious ownership ») et ne peut de toute façon rien écrire ;
3. `rm -rf .git`, puis `git init`, `git remote add`, `git fetch` ;
4. `git reset --mixed <commit que le serveur porte réellement>` — il ne touche
   pas aux fichiers, et `git status` révèle alors les seules vraies
   modifications faites à la main sur le serveur ;
5. les examiner, puis `git checkout -B develop origin/develop`.

⚠️ Un `chmod -R g+rX` sur l'arborescence pose le bit d'exécution sur des fichiers
qui le portaient déjà pour leur propriétaire — git voit alors des dizaines
d'images « modifiées ». Sans conséquence, mais déroutant : `git diff --summary`
distingue un `mode change` d'une vraie différence.

### Ni composer ni node sur le serveur

Conséquence de la copie. Deux constats :

- **composer était inutile** : `composer.lock` n'ayant pas changé, il n'y avait
  rien à installer, et l'autoloader PSR-4 trouve les nouvelles classes sans être
  régénéré. Se vérifie en une commande :
  ```bash
  php -r 'require "vendor/autoload.php"; var_dump(class_exists("App\Service\MailGuard"));'
  ```
- **node était indispensable** et absent. Les assets ont été compilés sur un
  poste de développement puis copiés :
  ```bash
  NODE_OPTIONS=--openssl-legacy-provider npm run build
  rsync -avz --delete public/build/ UTILISATEUR@SERVEUR:/chemin/public/build/
  ```
  À trancher pour la production : installer node sur le serveur, ou verser
  `public/build/` dans le dépôt.

### Lire les journaux sans se tromper

Une erreur dans `var/log/prod.log` peut être **antérieure** à la commande qu'on
vient de lancer. Une table manquante y était signalée alors que les migrations
étaient passées : l'erreur datait d'une visite faite avant. Toujours vider le
journal avant de vérifier :

```bash
: > var/log/prod.log
# puis naviguer, et seulement ensuite :
grep -E "request\.(CRITICAL|ERROR)" var/log/prod.log | tail -5
```

Avec `APP_DEBUG=1`, le journal enfle très vite et noie les vraies erreurs sous le
bavardage des évènements.

### Charger des données de test sur une base peuplée

`doctrine:fixtures:load` échoue sur une clé étrangère : la purge supprime les
comptes avant les fichiers qui les référencent. Il faut repartir d'un schéma
vide — `doctrine:database:drop --force`, `create`, `migrations:migrate`, puis les
fixtures. Voir [`donnees-reelles.md`](donnees-reelles.md).

### Les comptes de test ne peuvent pas se connecter en ligne

Le pare-feu ne branche **que** le SSO RNF ; la connexion par formulaire n'est
plus câblée. Les six comptes des fixtures et leur mot de passe ne servent qu'en
local. Sur un serveur, seul un vrai compte GeoNature entre — et il faut lui
redonner ses droits après chaque rechargement des fixtures :

```bash
php bin/console user:set-admin adresse@rnfrance.org
```

Corollaire : **recetter les notifications demande deux personnes**, puisqu'on
n'est jamais notifié de ses propres actions.

### Points laissés ouverts

- `preg_match(): Compilation failed: length of lookbehind assertion is not
  limited` à chaque vidage de cache — incompatibilité entre le routeur de
  Symfony 4.4 et une version récente de PCRE. Sans effet constaté, mais à
  surveiller : si une page renvoie un 404 inattendu, c'est la première piste.
- `POSTMARK_INBOUND_KEY` vide en préproduction. Si elle l'est aussi en
  production, c'est une seconde cause au fait que la réponse par e-mail n'a
  jamais fonctionné, en plus de la règle de sécurité corrigée dans #12.
- `APP_DEBUG=1` avec `APP_ENV=prod`. En plus d'exposer les traces, le mode debug
  active `strict_variables` dans Twig, qui transforme une valeur absente en
  erreur fatale — c'est ce qui rendait #3 visible aux membres. `app:preflight`
  le signale désormais.

## 4. Si quelque chose ne va pas

### Deux autres pièges de la préproduction

- **Ne jamais planifier la tâche du résumé sur la préproduction.** Si les deux
  environnements l'exécutent, les destinataires reçoivent tout en double. La
  lancer à la main, avec `--dry-run` d'abord.
- **Attention aux comptes conservés avec `--keep-email`.** Ce sont les seules
  adresses réelles d'une copie anonymisée : elles passent le garde-fou et
  recevront donc pour de bon ce que la préproduction envoie.
- **Les six comptes de test ne reçoivent rien par défaut.** Leurs adresses en
  `@example.org` ne mènent nulle part et sont refusées avant envoi. Pour
  recetter les e-mails, renseigner `TEST_ACCOUNTS_EMAIL` dans le `.env.local` de
  la préproduction puis recharger les fixtures : les comptes prennent alors une
  adresse étiquetée sur une boîte réelle. Voir
  [`donnees-reelles.md`](donnees-reelles.md).

| Symptôme | Première chose à regarder |
|---|---|
| Aucun e-mail de discussion, aucune erreur | `POSTMARK_BULK_TOKEN` — vide, le transport ne fait rien silencieusement |
| Aucune demande d'adhésion, aucune erreur | `POSTMARK_SENDER` et `POSTMARK_SERVER_TOKEN` — autre transport, autre jeton |
| Liens des e-mails vers `localhost` | `SITE_HOST` absent du `.env.local` |
| Les images ne s'affichent pas | permissions de `var/files`, voir `DEPLOYMENT_PERMISSIONS_FIX.md` |
| « attempt to write a readonly database » à la connexion | index de recherche non inscriptibles par le serveur web. Voir ci-dessous |
| Le build front échoue au déploiement | version de Node trop récente pour webpack 4 |
| Les réponses par e-mail n'arrivent pas | l'URL `/ws/list/inbound/{clé}` doit être publiquement joignable |
| Un compte de test ne reçoit rien en préproduction | son adresse est en `@example.org` : `MailGuard` la refuse. Renseigner `TEST_ACCOUNTS_EMAIL` et recharger les fixtures |

## 6. Déployer en PRODUCTION — sans perdre de données

⚠️ **Les commandes des sections précédentes ne s'appliquent pas toutes.** La mise
en préproduction repartait d'une base vide ; la production, elle, porte les
données du réseau. Trois commandes sont à proscrire absolument :

| À ne JAMAIS lancer en production | Effet |
|---|---|
| `doctrine:database:drop` | supprime la base entière |
| `doctrine:fixtures:load` | **vide** la base avant de la remplir de données inventées |
| `doctrine:schema:update --force` | modifie le schéma hors migrations, sans trace ni retour arrière |

`AppFixtures` refuse désormais de s'exécuter en environnement « prod » sans un
`ALLOW_FIXTURES=1` explicite. **Ne posez jamais cette variable sur la
production** : elle n'a de sens que sur une préproduction dont on assume la
perte des données.

### La séquence, sans destruction

```bash
cd /chemin/vers/communaute-rnf

# 1. SAUVEGARDE — d'abord, toujours
mysqldump -u USER -p BASE | gzip > ~/avant-deploiement-$(date +%Y%m%d-%H%M).sql.gz
tar czf ~/avant-deploiement-files.tgz var/files/
cp .env.local config/platform/config.yaml ~/

# 2. Récupérer le code
git pull origin develop

# 3. Migrations — elles ajoutent des colonnes et une table, sans rien supprimer
php bin/console doctrine:migrations:migrate --no-interaction

# 4. Assets : compilés ailleurs puis copiés, ou npm run build si node est présent

# 5. Finalisation
php bin/console cache:clear
php bin/console import:skills          # ne crée que ce qui manque, ne supprime rien
php bin/console search:reindex:all     # reconstruit les index, ne touche pas aux données

# 6. Contrôle
php bin/console app:preflight
```

### Ce que font réellement les huit migrations

Aucune ne supprime de donnée. Elles ajoutent :

| Migration | Effet |
|---|---|
| `Version20260818151610` | colonne `inbound_message_id` sur les messages |
| `Version20260819072259` | colonne `description` sur les documents |
| `Version20260819073810` | colonne `edited_at` sur les messages |
| `Version20260819075409` | colonne `notifications_settings` sur les comptes |
| `Version20260819075543` | **table** `notifications` |
| `Version20260819110800` | colonnes `deleted_at` et `archived_at` |
| `Version20260819111534` | colonne `is_important` sur les pages |
| `Version20260819125414` | colonne `parent_id` sur les dossiers de documents |

Toutes réversibles par `doctrine:migrations:migrate prev`, mais **la sauvegarde
reste le vrai filet** : un retour arrière de migration ne restitue pas une donnée
qu'un défaut applicatif aurait effacée entre-temps.

### Les préférences de notification ne sont pas migrées, et c'est voulu

Les réglages par groupe vivent dans une colonne JSON qui existait déjà. La
lecture interprète l'ancien indicateur `unsubscribed` comme un refus sur les
quatre catégories : **personne n'est réabonné de force**, et aucune donnée n'est
réécrite. Rien à annuler si l'on revient en arrière.

### Avant d'allumer les e-mails

L'ordre importe, et le dernier point est irréversible :

1. les trois enregistrements DNS de [`delivrabilite-emails.md`](delivrabilite-emails.md) ;
2. vérifier `POSTMARK_SENDER`, `POSTMARK_SERVER_TOKEN`, `POSTMARK_BULK_TOKEN` et
   `POSTMARK_INBOUND_KEY` — cette dernière était vide en préproduction ;
3. `app:notifications:digest --dry-run` pour voir ce qui partirait ;
4. **et seulement alors** planifier la tâche quotidienne.
