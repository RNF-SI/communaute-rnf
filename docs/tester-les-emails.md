# Tester les e-mails

## Ce qui part, et par quel chemin

| E-mail | Service | Transport |
|---|---|---|
| Message de discussion | `DiscussionSender` | `BulkTransport` → **API Postmark en direct** |
| Résumé quotidien, demande d'adhésion, mot de passe oublié, inscription | `EmailSender` | Swift_Mailer → `MAILER_URL` |

C'est la distinction qui compte : le premier **court-circuite la configuration
Symfony**. Il n'obéit ni à `MAILER_URL`, ni à `disable_delivery`, ni à
`delivery_addresses`. Sa seule échappatoire est un `POSTMARK_BULK_TOKEN` vide,
auquel cas il ne fait rien du tout, silencieusement.

En dev et en test, `postmark_bulk` est donc remplacé par
`App\Postmark\LocalBulkTransport`, qui remet les messages au mailer configuré.
Tout passe alors par le même chemin, et tout devient lisible.

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
