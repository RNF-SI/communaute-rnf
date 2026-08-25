# Les commandes de la plateforme

Toutes les commandes `bin/console` propres à ce projet, ce qu'elles font, et
surtout **ce qu'elles écrivent**. Les commandes Symfony et Doctrine standard
(`cache:clear`, `doctrine:migrations:migrate`…) ne sont pas reprises ici.

Un test (`tests/Command/CommandsDocumentedTest.php`) vérifie que cette page les
cite toutes : une commande ajoutée sans être documentée fait échouer la suite.

## D'un coup d'œil

| Commande | Écrit ? | Quand |
|---|---|---|
| [`app:preflight`](#apppreflight) | non | après chaque déploiement |
| [`app:mail:check`](#appmailcheck) | non, sauf `--to` | après chaque modification du DNS |
| [`app:notifications:digest`](#appnotificationsdigest) | oui | tous les jours, par tâche planifiée |
| [`app:rnf:inspect`](#apprnfinspect) | non | pour comprendre ce que GeoNature répond |
| [`app:rnf:sync-reserves`](#apprnfsync-reserves) | oui | la nuit, par tâche planifiée |
| [`import:skills`](#importskills) | oui | après modification de la liste des compétences |
| [`search:reindex:all`](#searchreindexall) | oui (index) | après un changement de colonnes indexées |
| [`search:reindex`](#searchreindex) | oui (index) | pour rebâtir un seul index |
| [`user:set-admin`](#usersetadmin) / [`user:unset-admin`](#userunsetadmin) | oui | à la main |
| [`app:db:anonymize`](#appdbanonymize) | **oui, irréversible** | sur une copie, jamais en production |
| [`app:update-coordinates`](#appupdatecoordinates) / [`app:update-nuts-id`](#appupdatenuts-id) | oui | ponctuel |

---

## Contrôler un environnement

### `app:preflight`

Vérifie, sur la machine qui fera tourner la plateforme, tout ce dont l'absence
provoque une panne **silencieuse** plutôt qu'une erreur : extension manquante,
jeton vide, hôte de routeur absent, répertoire non inscriptible, migrations en
retard, durée de session trop courte.

N'écrit rien. Sort en code 1 s'il reste une vérification bloquante en échec.

```bash
php bin/console app:preflight
```

### `app:mail:check`

Répond à « est-ce que les e-mails marchent ? », ce qui n'est pas la même
question que « les jetons sont-ils renseignés ? ». Lit le **DNS du domaine
d'envoi** (SPF, DKIM, Return-Path, DMARC) et la configuration des deux chemins
d'envoi.

```bash
php bin/console app:mail:check
php bin/console app:mail:check --to=vous@rnfrance.org
```

| Option | Effet |
|---|---|
| `--to=adresse` | envoie **un vrai message par chacun des deux chemins**, et dit lequel a abouti |

Sans `--to`, n'écrit et n'envoie rien. Sort en code 1 s'il manque un
enregistrement DNS.

Les deux chemins portent **deux jetons Postmark différents** : l'un peut
fonctionner pendant que l'autre est muet. C'est pour cela que `--to` en envoie
deux.

Voir [`delivrabilite-emails.md`](delivrabilite-emails.md) et
[`dns-a-faire.md`](dns-a-faire.md).

---

## Notifications

### `app:notifications:digest`

Envoie le résumé des notifications en attente. **À lancer une fois par jour,
tous les jours** — c'est la commande qui décide du jour : elle emporte chaque
matin ce qui est réglé sur le quotidien, et attend le lundi pour ce qui est
réglé sur l'hebdomadaire.

Depuis #38, le rythme est porté par **chaque notification**, et non plus par
son destinataire : une même personne peut suivre une commission au quotidien
et ses documents à la semaine. Un lundi, les deux tiennent dans le même
e-mail. Ce qui est réglé sur l'e-mail immédiat ne passe pas par ici : c'est
parti au moment de la publication.

```bash
php bin/console app:notifications:digest --dry-run
php bin/console app:notifications:digest --day=2026-08-24 --dry-run
```

| Option | Effet |
|---|---|
| `--dry-run` | dit ce qui partirait, n'envoie rien et ne marque rien |
| `--day=AAAA-MM-JJ` | se place un autre jour — pour voir ce qu'un lundi enverrait |
| `--only=adresse` | n'écrit qu'à ce compte, et détaille ce qu'il a en attente |
| `--keep` | ne marque rien comme envoyé : le même résumé repart au lancement suivant. **Exige `--only`** |

#### Éprouver l'hebdomadaire sans attendre lundi

Trois obstacles, et une option chacun : l'hebdomadaire ne part **qu'un jour sur
sept**, une préproduction porte souvent une **copie anonymisée** dont toutes les
adresses rebondissent, et un résumé envoyé est **consommé** — le relancer
n'envoie plus rien.

```bash
# 1. Ce qui attend, et ce qu'un lundi emporterait. N'envoie rien.
php bin/console app:notifications:digest --day=2026-08-31 --dry-run

# 2. Le vrai e-mail, à un seul compte, un lundi, sans rien consommer.
php bin/console app:notifications:digest --day=2026-08-31 --only=vous@rnfrance.org --keep
```

Chaque ligne dit ce qui part et ce qui reste :

```
#42 vous@rnfrance.org : 3 notifications (daily 2, weekly 1), 4 en attente d'un lundi
```

Ces options font travailler **la commande elle-même**, pas une démonstration à
côté : une seconde mécanique d'envoi finirait par diverger de celle qui part la
nuit, et l'essai dirait alors le contraire de la production.

Pour qu'il y ait de l'hebdomadaire à voir, il en faut en attente : régler une
catégorie sur « résumé hebdomadaire » dans `/user/parameters/edit`, puis publier
une page ou une actualité dans le groupe concerné. Voir
[`tester-les-emails.md`](tester-les-emails.md).

⚠️ **`--keep` n'est pas une option de production.** Sans `--only` la commande la
refuse — le même résumé repartirait à tout le monde le lendemain, et le
surlendemain.

⚠️ **Une seule ligne de cron, quotidienne.** Ajouter une seconde ligne
hebdomadaire enverrait le résumé deux fois.

⚠️ **Ne pas activer la tâche planifiée tant que `app:mail:check` n'est pas
vert.** Un envoi groupé avec SPF et DKIM en échec abîme la réputation du
domaine bien au-delà de la plateforme.

---

## GeoNature

### `app:rnf:inspect`

Diagnostic, **n'écrit rien**. Affiche ce que GeoNature dit d'un compte : les
champs que le SSO nous donne à chaque connexion — dont `roleOPNLInfo`, stocké
et lu nulle part — et la réponse brute de l'export « Liens
utilisateurs-réserves », colonnes comprises.

```bash
php bin/console app:rnf:inspect --email=quelquun@rnfrance.org
php bin/console app:rnf:inspect --role-id=4242
```

| Option | Effet |
|---|---|
| `--email=adresse` | un compte local ; affiche aussi ce que le SSO en a stocké |
| `--role-id=n` | un identifiant GeoNature, sans compte local |

Le schéma de l'export n'étant publié nulle part, c'est la seule façon honnête
de connaître ses colonnes. Demande `RNF_EXPORT_TOKEN`.

### `app:rnf:sync-reserves`

Remplit le champ « Réserve(s) suivie(s) » des comptes venant du SSO, depuis
l'export GeoNature. Une commande nocturne plutôt qu'un appel à la connexion :
les rattachements ne bougent pas d'un jour à l'autre.

```bash
php bin/console app:rnf:sync-reserves --dry-run
php bin/console app:rnf:sync-reserves --role-id=4242
```

| Option | Effet |
|---|---|
| `--dry-run` | dit ce qui changerait, n'écrit rien |
| `--role-id=n` | ne traite que ce compte GeoNature |

Sans `RNF_EXPORT_TOKEN`, la commande le dit et ne fait rien — elle ne vide
jamais un champ. Un compte sans identité GeoNature n'est jamais touché.

---

## Contenu et comptes

### `import:skills`

Crée les compétences proposées dans les profils, à partir de
`ImportSkillsCommand::SLUGS`. Lancée automatiquement au déploiement.

```bash
php bin/console import:skills
```

⚠️ Elle **crée** seulement : elle ne renomme ni ne supprime. Retirer une
compétence de la liste demande de reprendre à la main les profils qui
l'utilisent.

### `search:reindex:all`

Rebâtit tous les index de recherche.

```bash
php bin/console search:reindex:all
```

À lancer après tout changement des colonnes indexées — sans quoi la recherche
trouve ou ne trouve pas selon qu'une entité a été réenregistrée depuis.

### `search:reindex`

Rebâtit un seul index.

```bash
php bin/console search:reindex documents
```

Noms d'index : `pages`, `discussions_messages`, `articles`, `documents`,
`groups`, `members`.

### `user:set-admin` / `user:unset-admin`

Donne ou retire `ROLE_ADMIN` à un compte, par son adresse.

```bash
php bin/console user:set-admin adresse@rnfrance.org
php bin/console user:unset-admin adresse@rnfrance.org
```

Il n'existe **pas** de commande d'activation ou de désactivation de compte.

---

## Données

### `app:db:anonymize`

Remplace les données personnelles de tous les comptes par des données
inventées : nom, adresse, téléphone, fonction, structure, réserves,
biographie, mots de passe, jetons.

Le contenu libre — discussions, pages, actualités, noms de documents — est
laissé tel quel et peut encore nommer des gens ; la commande le dit en
terminant.

**Les messages privés font exception** : leur texte est réécrit, ainsi que les
copies portées par les signalements. Une discussion de groupe a été lue par
tout un groupe ; un message privé ne l'a été que par deux personnes, et une
copie de production traîne sur des postes et des préproductions. Ce qui reste
d'une conversation sur une copie : qui a parlé à qui, quand, combien de fois —
de quoi éprouver la messagerie, rien de plus. Un message que quelqu'un avait
effacé le reste.

```bash
php bin/console app:db:anonymize --dry-run
php bin/console app:db:anonymize --force
```

| Option | Effet |
|---|---|
| `--dry-run` | dit ce qui serait modifié |
| `--force` | exécute réellement |

🚨 **Irréversible, et à ne lancer que sur une copie.** Sur la base de
production, elle détruirait l'annuaire. Voir
[`donnees-reelles.md`](donnees-reelles.md).

### `app:update-coordinates` / `app:update-nuts-id`

Recalculent les coordonnées géographiques et le code de région européenne des
comptes, à partir de la ville et du code postal. Ponctuel, après un import ou
un changement de source géographique.

```bash
php bin/console app:update-coordinates
php bin/console app:update-nuts-id
```
