# Tester les e-mails

## Le plus court chemin : une commande

Sur n'importe quel environnement, préproduction comprise :

```bash
php bin/console app:mail:check
```

Elle ne lit que le DNS et la configuration, n'envoie rien, et répond à la
question qu'on se pose vraiment : **est-ce qu'un e-mail partirait, et
arriverait-il ?** Ce sont deux choses différentes — `app:preflight` dit si les
jetons sont renseignés, celle-ci dit si le domaine autorise Postmark à envoyer
en son nom.

Pour éprouver les deux chemins d'envoi pour de vrai :

```bash
php bin/console app:mail:check --to=vous@rnfrance.org
```

Deux messages partent, un par chemin, et le compte rendu dit lequel a abouti.
**Les deux chemins portent deux jetons Postmark différents** : l'un peut
fonctionner pendant que l'autre est muet, ce qui s'était produit en #4.

⚠️ En production, le garde de #14 refuse les adresses en `@example.org` : un
`--to` vers une adresse de test y sera rejeté, et le compte rendu le dira.


## Le chemin d'un e-mail selon l'environnement

C'est la chose à retenir : **la plateforme n'envoie pas ses e-mails par un seul
canal**, et le canal change selon l'environnement.

| | `dev` | `test` | `prod` (et préproduction) |
|---|---|---|---|
| Résumé quotidien, adhésion, mot de passe oublié | `MAILER_URL` | rien n'est envoyé (`disable_delivery`) | transport Postmark, jeton `POSTMARK_SERVER_TOKEN` |
| Messages de discussion | `MAILER_URL` *(via `LocalBulkTransport`)* | `MAILER_URL` *(via `LocalBulkTransport`)* | **API Postmark en direct**, jeton `POSTMARK_BULK_TOKEN` |

Ce qui se joue derrière :

- En **production**, deux transports coexistent, chacun avec **son jeton**.
  L'un peut fonctionner pendant que l'autre est muet — c'est exactement ce qui
  s'est produit dans #4, où les e-mails de discussion arrivaient alors que les
  demandes d'adhésion ne partaient pas.

  ⚠️ **`POSTMARK_BULK_TOKEN` est un jeton de *serveur* Postmark, comme
  `POSTMARK_SERVER_TOKEN`** — pas un jeton d'un autre type qu'il faudrait aller
  chercher ailleurs. `BulkTransport` l'envoie dans l'en-tête
  `X-Postmark-Server-Token`, et choisit le flux par
  `X-PM-Message-Stream: broadcast`.

  **Les deux variables peuvent donc porter la même valeur**, dès lors que le
  serveur Postmark a un flux *Broadcast* — c'est le cas par défaut de tout
  serveur. Deux serveurs distincts se justifient si l'on veut isoler la
  réputation des envois de masse de celle des e-mails transactionnels ; sur une
  préproduction, le même jeton suffit et évite un aller-retour.
- En **dev et en test**, `postmark_bulk` est remplacé par
  `App\Postmark\LocalBulkTransport` (voir `config/services_dev.yaml` et
  `config/services_test.yaml`), qui remet les messages au mailer configuré. Sans
  ce remplacement, les messages de discussion échapperaient à toute observation.
- Le transport de production **n'obéit à aucun réglage Symfony**. Ni
  `MAILER_URL`, ni `disable_delivery`, ni `delivery_addresses` ne le concernent.
  Sa seule échappatoire est un `POSTMARK_BULK_TOKEN` vide, auquel cas il ne fait
  rien, silencieusement et sans erreur.

Conséquence pratique : **un e-mail de discussion qui ne part pas en production
ne lève aucune alerte.** Si les messages cessent d'arriver, commencer par
vérifier ce jeton.

## Ce qui part, et par quel chemin

| E-mail | Service | Transport |
|---|---|---|
| Message de discussion | `DiscussionSender` | `BulkTransport` → **API Postmark en direct** |
| Résumé quotidien, demande d'adhésion, mot de passe oublié, inscription | `EmailSender` | Swift_Mailer → `MAILER_URL` |

(Rappel du tableau ci-dessus : en dev et en test, le premier passe lui aussi par
`MAILER_URL`, grâce au transport de remplacement.)

## En local : un collecteur, jamais Postmark

**Ne branche pas Postmark en local.** Deux raisons, et la seconde est la plus
sérieuse :

1. Tu enverrais de vrais e-mails à de vraies personnes en cliquant dans une
   interface de développement.
2. Les jeux de données locaux — fixtures comme copie anonymisée — n'ont que des
   adresses en `@example.org`, qui n'existent pas. Chaque envoi produit un
   **rebond dur**. Un taux de rebond élevé fait suspendre un compte Postmark ;
   tu casserais l'envoi de la production depuis ton poste.

Utilise un collecteur SMTP. [Mailpit](https://mailpit.axllent.org/) fait le
travail et affiche les deux versions d'un message, HTML et texte, ce qui est
exactement ce qu'il faut pour vérifier le travail de #14 :

```bash
docker run -d --name mailpit -p 1025:1025 -p 8025:8025 axllent/mailpit
```

Puis dans `.env.local` :

```bash
MAILER_URL=smtp://127.0.0.1:1025
POSTMARK_SENDER=noreply@example.org
POSTMARK_LIST_DOMAIN=example.org
POSTMARK_SERVER_TOKEN=
POSTMARK_BULK_TOKEN=
```

Les messages s'affichent sur http://localhost:8025. À vérifier :

- la présence des **deux parties**, HTML et texte ;
- les en-têtes **`List-Unsubscribe`** et **`List-Unsubscribe-Post`** ;
- le **`Reply-To`** des messages de discussion, de la forme
  `groupe+uuid@domaine`.

Pour envoyer un résumé quotidien à la demande :

```bash
php bin/console app:notifications:digest
```

## Le résumé hebdomadaire

C'est celui qu'on ne peut pas simplement « lancer pour voir » : il ne part
**qu'un jour sur sept**, il est **consommé** dès qu'il est parti, et il s'envoie
à **tout le monde à la fois** — sur une préproduction anonymisée, autant de
rebonds durs que de comptes. Trois options y répondent, et elles font travailler
la vraie commande plutôt qu'une démonstration à côté.

**1. Avoir de l'hebdomadaire en attente.** Rien ne se voit s'il n'y a rien à
voir — et c'est de loin la raison la plus fréquente d'un résumé qui « ne part
pas ».

Les fixtures s'en chargent : **Manon Membre** (`membre@…`) reçoit au chargement
une notification en attente de résumé **quotidien** et une en attente de résumé
**hebdomadaire**. Il n'y a donc rien à préparer sur une préproduction
fraîchement chargée. Les trois autres notifications des fixtures, elles, sont
posées sans e-mail : elles garnissent la page des notifications et ne partent
jamais.

À la main, pour un autre compte :

1. dans `/user/parameters/edit`, régler une catégorie — « Documents », par
   exemple — sur **résumé hebdomadaire**, pour un groupe ou en réglage général ;
2. faire publier dans ce groupe le contenu correspondant, avec **un autre
   compte** : personne n'est notifié de ce qu'il publie lui-même, et publier
   seul ne produit donc rien du tout.

⚠️ **Les comptes de test ne reçoivent que si `TEST_ACCOUNTS_EMAIL` était posée
au chargement des fixtures.** Sans elle leurs adresses restent en
`@example.org`, que `MailGuard` refuse avant l'envoi. C'est au chargement que
les adresses sont figées : poser la variable après ne change rien, il faut
recharger.

**Qui peut recevoir quoi.** Les six comptes nommés sont tous actifs et tous
membres du groupe **communauté** — donc tous notifiables par ce qui y paraît.
Dans les trois groupes de référence, en revanche :

| Compte | `groupe-de-test`, `commission-de-test`, `groupe-prive-de-test` |
|---|---|
| `+admin`, `+referent` | animateurs — mais auteurs le plus souvent, donc non notifiés de leurs propres dépôts |
| `+membre` | membre simple : **le compte à regarder** |
| `+candidat` | en attente sur le groupe privé — jamais notifié |
| `+banni` | banni des trois — jamais notifié |
| `+exterieur` | membre d'aucun — jamais notifié |

**2. Regarder ce qui attend, sans rien envoyer.** `--day` se place un lundi —
n'importe quel lundi, passé ou à venir :

```bash
php bin/console app:notifications:digest --day=2026-08-31 --dry-run
```

Chaque ligne dit ce qui partirait ce jour-là et ce qui resterait :

```
#42 vous@rnfrance.org : 3 notifications (daily 2, weekly 1), 4 en attente d'un lundi
```

Le même appel un mardi doit montrer l'hebdomadaire **retenu**, pas disparu :
c'est la moitié du contrôle.

Si la commande annonce **0 destinataire**, elle dit maintenant pourquoi, et
c'est le plus souvent qu'il n'y a rien à résumer — pas que l'envoi est en panne.
Trois verdicts possibles :

| Ce qui s'affiche | Ce que ça veut dire |
|---|---|
| `RIEN N'A ÉTÉ PUBLIÉ` | aucune notification n'existe. Reprendre l'étape 1 : publier **avec un autre compte** |
| `CE SONT LES RÉGLAGES` | des notifications existent, mais aucune ne doit partir par e-mail — niveau « aucune » / « plateforme seulement », immédiat déjà parti, ou refus général |
| `TOUT EST DÉJÀ PARTI` | le résumé a été envoyé, et ne l'est qu'une fois. Republier, ou employer `--only --keep` qui ne consomme rien |

Aucun des trois n'est un problème de transport : pour cela, `app:mail:check`.

**3. Recevoir le vrai e-mail, à une seule adresse :**

```bash
php bin/console app:notifications:digest --day=2026-08-31 --only=antoine.schlegel+membre@rnfrance.org --keep
```

- `--only` restreint l'envoi à ce compte. **C'est la protection à ne pas
  oublier en préproduction** : sans elle, le résumé part à tous les comptes de
  la copie, et une copie anonymisée n'a que des adresses en `@example.org`.
- `--keep` ne marque rien comme envoyé : on relance autant de fois qu'on veut,
  après avoir corrigé un gabarit par exemple. Sans `--only`, la commande refuse
  cette option — le même résumé repartirait à tout le monde le lendemain.

Puis, dans l'e-mail reçu : un seul message pour plusieurs notifications, un
titre par groupe, le quotidien **et** l'hebdomadaire réunis puisqu'on s'est
placé un lundi, et les en-têtes `List-Unsubscribe`.

⚠️ Un `--only` vers une adresse en `@example.org` sera refusé par le garde de
#14 en production : viser une vraie boîte.

## En préproduction : Postmark, mais pas n'importe comment

⚠️ **Si la préproduction porte une copie anonymisée, toutes les adresses sont
en `@example.org`.** Lancer le résumé quotidien sur 120 comptes produirait 120
rebonds durs d'un coup, et Postmark suspend un compte pour bien moins que ça.
La production tomberait avec.

Trois façons de s'en prémunir, à combiner :

1. **Ne viser qu'un compte**, ce qui ne demande aucune configuration :
   `app:notifications:digest --only=votre.adresse@rnfrance.org`. C'est le premier
   réflexe, et le seul qui protège aussi d'une erreur de manipulation.
2. **Rediriger tout vers une seule adresse réelle.** Dans
   `config/packages/prod/swiftmailer.yaml` de la préproduction :

   ```yaml
   swiftmailer:
       delivery_addresses: ['toi@rnfrance.org']
   ```

   Attention : ça ne couvre pas les messages de discussion, qui passent par le
   transport Postmark en direct. Y mettre un `POSTMARK_BULK_TOKEN` vide tant que
   la question n'est pas tranchée.

3. **Utiliser un serveur Postmark distinct** pour la préproduction, avec ses
   propres jetons. Les rebonds n'affectent alors pas la réputation du serveur de
   production.

## Ce qui ne se teste qu'en ligne

Trois choses ne peuvent pas être vérifiées en local, quel que soit l'outil :

- **La délivrabilité** (#14) : SPF, DKIM, DMARC ne se jouent qu'entre le vrai
  domaine et un vrai destinataire.
- **La réception des réponses par e-mail** (#12) : Postmark doit pouvoir
  atteindre `/ws/list/inbound/{clé}` sur une URL publique.
- **Le rendu chez les destinataires**, qui dépend de leur messagerie.
