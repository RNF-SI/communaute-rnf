# Les enregistrements DNS à publier

**Note à transmettre à qui administre les domaines `rnfrance.org` et
`reserves-naturelles.org`.**

## Deux domaines, pas un

C'est le point qui a fait perdre du temps : **la plateforme écrit depuis deux
domaines différents**, selon le type de message.

| Ce qui part | Depuis | Variable |
|---|---|---|
| Résumé, demande d'adhésion, mot de passe oublié | `si@rnfrance.org` | `POSTMARK_SENDER` |
| **Page, actualité, document, message privé** — à chaud | `si@rnfrance.org` | `POSTMARK_SENDER` |
| **Messages de discussion** | `noreply@lists.reserves-naturelles.org` | `POSTMARK_LIST_DOMAIN` |

Les deux doivent être autorisés. Un seul des deux configuré donne **une moitié
d'e-mails qui arrive** — le pire cas pour diagnostiquer, parce qu'on conclut
que « les e-mails marchent ». C'est exactement ce qui s'est produit : le
message de contrôle « discussion » arrivait, celui d'un message privé non, et
les deux passent pourtant par le même jeton Postmark.

## Avant le DNS : l'adresse doit être confirmée dans Postmark

**Deux autorisations distinctes, et celle-ci vient en premier.** Le DNS dit aux
*destinataires* que Postmark a le droit d'écrire au nom du domaine. La
*Sender Signature* dit à **Postmark** qu'il a le droit d'employer cette adresse
en `From`. Sans elle, rien ne part du tout : le message est refusé à l'entrée,
et le DNS n'a pas son mot à dire.

Constaté sur la préproduction, par `app:mail:check --to=…` :

```
ÉCHEC  Contenu et messages privés  si@rnfrance.org
       The 'From' address you supplied (si@rnfrance.org) is not a Sender
       Signature on your account. (ErrorCode 400)
```

À faire dans Postmark, **Sender Signatures** : ajouter `si@rnfrance.org` et
confirmer le lien reçu — ou, mieux, **vérifier le domaine `rnfrance.org`**, ce
qui autorise d'un coup toutes ses adresses et pose en même temps la clé DKIM
de l'étape 2. Le second chemin fait donc les deux travaux à la fois.

⚠️ **Le compte Postmark de la préproduction et celui de la production.** Une
signature confirmée sur l'un ne l'est pas sur l'autre : si les deux serveurs
sont distincts, la vérification est à refaire des deux côtés.

## Le problème, en trois phrases

La plateforme envoie ses e-mails par **Postmark**. Aujourd'hui, ni l'un ni
l'autre des deux domaines ne dit qu'il autorise Postmark à envoyer en son nom :
pour les serveurs qui reçoivent ces messages, ce sont des e-mails qui se
réclament d'un domaine sans preuve.

`rnfrance.org` publie en outre une consigne — DMARC `p=quarantine` — qui
demande explicitement de mettre en quarantaine tout message dans ce cas. Les
destinataires font donc exactement ce qu'on leur demande.

**Ce n'est pas un problème de contenu, ni de réputation, ni de la plateforme :
c'est une autorisation manquante.**

## État constaté

Relevé par `php bin/console app:mail:check`, qui lit les deux domaines :

### `rnfrance.org` — transactionnel

| Enregistrement | État |
|---|---|
| SPF | ❌ présent, mais sans `include:spf.mtasv.net` |
| DKIM `pm._domainkey` | ❌ absent |
| Return-Path `pm-bounces` | ❌ absent |
| DMARC | ⚠️ `p=quarantine`, alors que rien n'authentifie |

### `lists.reserves-naturelles.org` — discussions

| Enregistrement | État |
|---|---|
| SPF | ❌ **aucun enregistrement** |
| DKIM `pm._domainkey` | ❌ absent |
| Return-Path `pm-bounces` | ✅ `pm.mtasv.net` — déjà fait |
| DMARC | ⚠️ aucune politique publiée |

Le Return-Path du second est en place : quelqu'un a commencé le travail sur ce
domaine et s'est arrêté là.

## Qui fait quoi

| Étape | Qui | Où |
|---|---|---|
| 1. Récupérer les valeurs | RNF, compte Postmark | tableau de bord Postmark, **pour chacun des deux domaines** |
| 2. Publier | l'administrateur du domaine | **IONOS** pour `rnfrance.org` (zone sur `ns*.ui-dns.*`) ; à vérifier pour `reserves-naturelles.org` |
| 3. Vérifier | RNF | `php bin/console app:mail:check` |

⚠️ **Les valeurs ne s'inventent pas.** La clé DKIM et la cible du Return-Path
sont générées par Postmark **pour chaque domaine**. Celles de `rnfrance.org` ne
valent pas pour `lists.reserves-naturelles.org`.

## Étape 1 — récupérer les valeurs dans Postmark

Se connecter à Postmark, puis :

**Sender Signatures → Domains**, puis **chacun des deux domaines** :
`rnfrance.org` et `lists.reserves-naturelles.org`.

La page affiche deux blocs, **DKIM** et **Return-Path**, chacun avec un
enregistrement à publier. Les recopier (ou faire une capture) pour l'étape 2.

Si le domaine n'y figure pas encore, l'ajouter avec **Add Domain**.

## Étape 2 — publier les enregistrements

Dans l'espace client du domaine concerné. Pour `rnfrance.org` : IONOS,
**Domaines → `rnfrance.org` → DNS**. Pour `lists.reserves-naturelles.org`, le
sous-domaine se gère dans la zone `reserves-naturelles.org`.

**Les trois enregistrements ci-dessous sont à faire pour les deux domaines**,
avec les valeurs propres à chacun.

### a. Autoriser Postmark dans SPF

⚠️ **Ne pas créer un second enregistrement SPF.** Un domaine ne doit en publier
qu'un seul ; deux font échouer SPF pour *tout* le domaine, la messagerie
principale comprise. Il faut **modifier celui qui existe**.

**Sur `lists.reserves-naturelles.org`, il n'y en a aucun** : il faut donc en
créer un, et un seul :

```
v=spf1 include:spf.mtasv.net ~all
```

**Sur `rnfrance.org`, il en existe un** qu'il faut modifier. Valeur actuelle :

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

La commande affiche **un tableau par domaine**. Toutes les lignes des deux
tableaux doivent être vertes : tant qu'une ligne est en `ÉCHEC`, les e-mails de
ce chemin-là peuvent partir sans arriver.

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
