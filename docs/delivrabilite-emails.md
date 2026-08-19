# Délivrabilité des e-mails

Ce que la plateforme envoie, et ce que le DNS doit dire pour que ça arrive.

## Diagnostic du 19 août 2026

Les e-mails partent via **Postmark**, depuis le domaine `rnfrance.org`
(`POSTMARK_LIST_DOMAIN`, et `POSTMARK_SENDER` pour les e-mails transactionnels).

État constaté des enregistrements DNS :

| Enregistrement | Valeur constatée | Verdict |
|---|---|---|
| SPF `rnfrance.org` | `v=spf1 include:_spf.oktey.com include:spf.hornetsecurity.com include:spf.mandrillapp.com include:servers.mcsv.net ip4:… ~all` | ❌ **Postmark absent** |
| DKIM Postmark | `pm._domainkey.rnfrance.org` inexistant (seul `mail._domainkey` répond, il vient d'un autre service) | ❌ **absent** |
| Return-Path | `pm-bounces.rnfrance.org` inexistant | ❌ absent |
| DMARC `rnfrance.org` | `v=DMARC1; p=quarantine` | ⚠️ actif, en quarantaine |

**Conclusion.** Tout e-mail envoyé par la plateforme échoue SPF *et* DKIM, donc
échoue DMARC. La politique publiée demandant la mise en quarantaine, les
serveurs destinataires font exactement ce qu'on leur demande. C'est
littéralement le symptôme signalé : « ton message est allé directement en
quarantaine de mon gestionnaire de mails ».

Ce n'est pas un problème de contenu ni de réputation : c'est une autorisation
manquante.

## Ce qu'il faut ajouter au DNS

Les valeurs exactes sont fournies par le tableau de bord Postmark, onglet
**Sender Signatures → Domains**. L'ordre compte peu, mais les trois sont à faire.

### 1. Autoriser Postmark dans SPF

Ajouter `include:spf.mtasv.net` à l'enregistrement existant, **sans en créer un
second** : un domaine ne doit publier qu'un seul SPF.

```
v=spf1 include:spf.mtasv.net include:_spf.oktey.com include:spf.hornetsecurity.com include:spf.mandrillapp.com include:servers.mcsv.net ip4:212.83.185.127 ip4:188.165.104.13 ip4:188.165.104.33 ip4:62.210.196.146 ~all
```

⚠️ SPF est limité à **dix résolutions DNS**. L'enregistrement actuel en compte
déjà quatre `include:` ; en ajouter un cinquième reste dans les clous, mais il
faudra vérifier qu'`_spf.oktey.com` et les autres n'en imbriquent pas trop.
Un dépassement fait échouer SPF pour tout le monde, y compris la messagerie
principale.

### 2. Publier la clé DKIM de Postmark

Postmark fournit un enregistrement TXT à placer sur `pm._domainkey.rnfrance.org`.
C'est le plus important des trois : DKIM survit aux transferts, là où SPF casse
dès qu'un message est réexpédié.

### 3. Return-Path personnalisé

Un CNAME `pm-bounces.rnfrance.org` vers la valeur donnée par Postmark. Il aligne
le domaine d'enveloppe sur le domaine visible, ce qui satisfait l'alignement
strict de SPF.

### Ensuite seulement

Une fois les trois en place et vérifiés dans Postmark, passer DMARC en
observation le temps de contrôler, avec une adresse de rapport :

```
v=DMARC1; p=quarantine; rua=mailto:dmarc@rnfrance.org; pct=100
```

Les rapports agrégés diront si un envoi échoue encore, et lequel.

## Ce qui a été fait côté application

- **Chaque e-mail porte désormais une version texte** en plus de la version
  HTML. Un message HTML seul est un signal de spam classique.
- **En-têtes `List-Unsubscribe` et `List-Unsubscribe-Post`** sur les e-mails de
  discussion et sur le résumé quotidien. Gmail et Yahoo l'exigent des expéditeurs
  de masse depuis 2024 ; sans lui, un envoi régulier vers 120 destinataires est
  traité comme du publipostage non conforme.
- **Une adresse de désabonnement joignable sans être connecté**,
  `/user/notifications/unsubscribe/{jeton}`, qui répond aussi en POST pour le
  désabonnement en un clic.
- **Le jeton de désabonnement a été durci.** Il valait `sha256(id + mot de passe)` ;
  les comptes venant du SSO n'ayant pas de mot de passe, il se réduisait au
  SHA-256 d'un identifiant de compte — devinable, donc suffisant pour désabonner
  quelqu'un d'autre. Il est maintenant signé avec le secret de l'application.
- **`Precedence: bulk` et `Auto-Submitted`** sur le résumé, pour que les
  répondeurs automatiques ne rebondissent pas dessus.

## À surveiller après correction

Le résumé quotidien introduit un expéditeur régulier vers tout le réseau. Tant
que les trois enregistrements ne sont pas en place, **ne pas activer la tâche
planifiée** : elle transformerait un problème visible en volume de plaintes.
