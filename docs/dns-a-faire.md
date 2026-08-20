# Les trois enregistrements DNS à publier

**Note à transmettre à qui administre le domaine `rnfrance.org`.**

## Le problème, en trois phrases

La plateforme Communauté RNF envoie ses e-mails par **Postmark**, un service
d'envoi. Aujourd'hui, le domaine `rnfrance.org` ne dit nulle part que Postmark
a le droit d'envoyer en son nom : pour les serveurs qui reçoivent ces messages,
ce sont des e-mails qui se réclament de `rnfrance.org` sans preuve.

Et le domaine publie par ailleurs une consigne — DMARC `p=quarantine` — qui
demande explicitement de mettre en quarantaine tout message dans ce cas. Les
destinataires font donc exactement ce qu'on leur demande.

**Ce n'est pas un problème de contenu, ni de réputation, ni de la plateforme :
c'est une autorisation manquante.** Il faut trois enregistrements DNS.

## Qui fait quoi

| Étape | Qui | Où |
|---|---|---|
| 1. Récupérer les valeurs | RNF, compte Postmark | tableau de bord Postmark |
| 2. Publier les enregistrements | l'administrateur du domaine | **IONOS** (la zone `rnfrance.org` y est hébergée : serveurs `ns*.ui-dns.*`) |
| 3. Vérifier | RNF | `php bin/console app:mail:check` sur le serveur |

⚠️ **Les valeurs ne s'inventent pas.** La clé DKIM et la cible du Return-Path
sont générées par Postmark pour ce domaine précis. Il faut donc commencer par
l'étape 1.

## Étape 1 — récupérer les valeurs dans Postmark

Se connecter à Postmark, puis :

**Sender Signatures → Domains → `rnfrance.org`**

La page affiche deux blocs, **DKIM** et **Return-Path**, chacun avec un
enregistrement à publier. Les recopier (ou faire une capture) pour l'étape 2.

Si le domaine n'y figure pas encore, l'ajouter avec **Add Domain**.

## Étape 2 — publier les trois enregistrements chez IONOS

Dans l'espace client IONOS : **Domaines → `rnfrance.org` → DNS**.

### a. Autoriser Postmark dans SPF

⚠️ **Ne pas créer un second enregistrement SPF.** Un domaine ne doit en publier
qu'un seul ; deux font échouer SPF pour *tout* le domaine, la messagerie
principale comprise. Il faut **modifier celui qui existe**.

Enregistrement TXT existant sur `rnfrance.org` :

```
v=spf1 include:_spf.oktey.com include:spf.hornetsecurity.com include:spf.mandrillapp.com include:servers.mcsv.net ip4:212.83.185.127 ip4:188.165.104.13 ip4:188.165.104.33 ip4:62.210.196.146 ~all
```

À remplacer par (ajout de `include:spf.mtasv.net`, en gras la seule
différence) :

```
v=spf1 include:spf.mtasv.net include:_spf.oktey.com include:spf.hornetsecurity.com include:spf.mandrillapp.com include:servers.mcsv.net ip4:212.83.185.127 ip4:188.165.104.13 ip4:188.165.104.33 ip4:62.210.196.146 ~all
```

### b. Publier la clé DKIM

| | |
|---|---|
| Type | TXT |
| Nom / hôte | `pm._domainkey` |
| Valeur | **celle donnée par Postmark** (une longue chaîne commençant par `k=rsa; p=…`) |

C'est le plus important des trois : DKIM survit aux réexpéditions, là où SPF
casse dès qu'un message est transféré.

### c. Publier le Return-Path

| | |
|---|---|
| Type | CNAME |
| Nom / hôte | `pm-bounces` |
| Valeur | **celle donnée par Postmark** (de la forme `pm.mtasv.net`) |

Il aligne le domaine d'enveloppe sur le domaine visible, ce que DMARC vérifie.

## Étape 3 — vérifier

La propagation prend de quelques minutes à quelques heures. Ensuite, sur le
serveur de la plateforme :

```bash
php bin/console app:mail:check
```

Les quatre lignes doivent être vertes. Tant qu'une ligne est en `ÉCHEC`, un
e-mail peut partir sans arriver.

Côté Postmark, les blocs DKIM et Return-Path passent au vert eux aussi
(bouton **Verify**).

## Et ensuite seulement

Une fois les trois en place et vérifiés, deux choses :

1. Ajouter une adresse de rapport à DMARC, pour être prévenu si un envoi échoue
   encore :

   ```
   v=DMARC1; p=quarantine; rua=mailto:dmarc@rnfrance.org; pct=100
   ```

2. **Alors, et pas avant**, activer la tâche planifiée du résumé de
   notifications (voir `mise-en-preproduction.md`). L'activer plus tôt
   transformerait un problème visible en volume de plaintes, et abîmerait la
   réputation de `rnfrance.org` bien au-delà de la plateforme.

## Ce que ça ne change pas

La **réception** du courrier de `rnfrance.org` passe par Altospam (les
enregistrements MX) et n'est pas concernée. Aucune des trois modifications
ci-dessus ne touche à la réception.
