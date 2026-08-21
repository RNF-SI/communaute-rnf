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

## En préproduction : Postmark, mais pas n'importe comment

⚠️ **Si la préproduction porte une copie anonymisée, toutes les adresses sont
en `@example.org`.** Lancer le résumé quotidien sur 120 comptes produirait 120
rebonds durs d'un coup, et Postmark suspend un compte pour bien moins que ça.
La production tomberait avec.

Deux façons de s'en prémunir, à combiner :

1. **Rediriger tout vers une seule adresse réelle.** Dans
   `config/packages/prod/swiftmailer.yaml` de la préproduction :

   ```yaml
   swiftmailer:
       delivery_addresses: ['toi@rnfrance.org']
   ```

   Attention : ça ne couvre pas les messages de discussion, qui passent par le
   transport Postmark en direct. Y mettre un `POSTMARK_BULK_TOKEN` vide tant que
   la question n'est pas tranchée.

2. **Utiliser un serveur Postmark distinct** pour la préproduction, avec ses
   propres jetons. Les rebonds n'affectent alors pas la réputation du serveur de
   production.

## Ce qui ne se teste qu'en ligne

Trois choses ne peuvent pas être vérifiées en local, quel que soit l'outil :

- **La délivrabilité** (#14) : SPF, DKIM, DMARC ne se jouent qu'entre le vrai
  domaine et un vrai destinataire.
- **La réception des réponses par e-mail** (#12) : Postmark doit pouvoir
  atteindre `/ws/list/inbound/{clé}` sur une URL publique.
- **Le rendu chez les destinataires**, qui dépend de leur messagerie.
