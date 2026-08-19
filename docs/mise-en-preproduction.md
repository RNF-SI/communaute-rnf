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
front. **Cinq migrations** vont s'appliquer :

| Migration | Effet |
|---|---|
| `Version20260818151610` | `inbound_message_id` sur les messages — idempotence du webhook (#12) |
| `Version20260819072259` | `description` sur les documents (#7) |
| `Version20260819073810` | `edited_at` sur les messages (#19) |
| `Version20260819075409` | `notifications_settings` sur les comptes (#34) |
| `Version20260819075543` | table `notifications` (#34) |

Après déploiement, une fois seulement :

```bash
php bin/console search:reindex documents   # la description entre dans l'index (#7)
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
php bin/console app:notifications:digest --dry-run   # ce qui partirait
```

Puis, quand le résultat est satisfaisant, planifier :

```cron
30 7 * * * cd /chemin/vers/communaute-rnf && php bin/console app:notifications:digest
```

⚠️ **C'est le seul geste irréversible du lot.** Un envoi groupé avec DMARC en
échec, ou vers des adresses anonymisées, abîme la réputation de `rnfrance.org`
bien au-delà de la plateforme.

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
