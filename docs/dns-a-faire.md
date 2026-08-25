# Les enregistrements DNS à publier

**Note à transmettre à qui administre le domaine `reserves-naturelles.org`.**

Deux enregistrements à publier sur **un seul sous-domaine**,
`lists.reserves-naturelles.org`. Rien à faire sur `rnfrance.org` : voir
« Pourquoi plus sur `rnfrance.org` » à la fin, c'est la question qu'on posera.

## Le problème, en trois phrases

La plateforme envoie ses e-mails par **Postmark**. Aujourd'hui le domaine
d'envoi ne dit nulle part qu'il autorise Postmark à écrire en son nom : pour
les serveurs qui reçoivent ces messages, ce sont des e-mails qui se réclament
d'un domaine sans preuve.

Ils partent donc, mais peuvent être classés en indésirables sans que rien ne le
signale côté plateforme.

**Ce n'est pas un problème de contenu, ni de réputation, ni de la plateforme :
c'est une autorisation manquante.**

## État constaté

Relevé le 25 août 2026 par `php bin/console app:mail:check` sur la
préproduction :

| Enregistrement | État |
|---|---|
| SPF | ❌ **aucun enregistrement** |
| DKIM `pm._domainkey` | ❌ absent |
| Return-Path `pm-bounces` | ✅ `pm.mtasv.net` — déjà fait |
| DMARC | ⚠️ aucune politique publiée — voir « Et ensuite » |

Le Return-Path est en place : quelqu'un a commencé le travail et s'est arrêté
là. Il reste **les deux lignes en ❌**.

## Qui fait quoi

| Étape | Qui | Où |
|---|---|---|
| 1. Récupérer la clé DKIM | RNF | tableau de bord Postmark |
| 2. Publier les deux enregistrements | l'administrateur du domaine | zone DNS de `reserves-naturelles.org` |
| 3. Vérifier | RNF | `php bin/console app:mail:check` |

⚠️ **La clé DKIM ne s'invente pas.** Elle est générée par Postmark pour ce
sous-domaine précis ; celle d'un autre domaine ne vaut pas.

## Étape 1 — récupérer la clé dans Postmark

Se connecter à Postmark, puis **Sender Signatures** (au niveau du compte, pas
à l'intérieur d'un serveur) → **`lists.reserves-naturelles.org`**.

La page affiche un bloc **DKIM** avec l'enregistrement à publier. Le recopier
(ou faire une capture) pour l'étape 2. Le bloc **Return-Path**, lui, est déjà
au vert — rien à en faire.

## Étape 2 — publier les deux enregistrements

Dans la zone DNS de `reserves-naturelles.org`. Les deux noms ci-dessous sont à
créer **sur le sous-domaine `lists`**.

### a. Autoriser Postmark dans SPF

Ce sous-domaine ne publie **aucun** SPF aujourd'hui. Il faut donc en créer un,
et un seul :

| | |
|---|---|
| Type | TXT |
| Nom / hôte | `lists` |
| Valeur | `v=spf1 include:spf.mtasv.net ~all` |

⚠️ **Un seul enregistrement SPF par domaine.** Deux font échouer SPF pour
*tout* le domaine. S'il en existait déjà un, il faudrait modifier celui-là et
non en ajouter un second — ici il n'y en a aucun, donc on en crée un.

Ceci ne touche pas au SPF de `reserves-naturelles.org` lui-même, qui est un
enregistrement distinct.

### b. Publier la clé DKIM

| | |
|---|---|
| Type | TXT |
| Nom / hôte | `pm._domainkey.lists` |
| Valeur | **celle donnée par Postmark** (une longue chaîne commençant par `k=rsa; p=…`) |

C'est le plus important des deux : DKIM survit aux réexpéditions, là où SPF
casse dès qu'un message est transféré.

## Étape 3 — vérifier

La propagation prend de quelques minutes à quelques heures. Ensuite, sur le
serveur de la plateforme :

```bash
php bin/console app:mail:check
```

Toutes les lignes du tableau doivent être vertes. Côté Postmark, le bloc DKIM
passe au vert lui aussi (bouton **Verify**).

## Pourquoi plus sur `rnfrance.org`

C'est la question qui sera posée, et la réponse tient en deux points.

**1. Postmark refusait d'écrire depuis `si@rnfrance.org`.** Avant d'autoriser
Postmark auprès des *destinataires* (le DNS), il faut l'autoriser auprès de
*Postmark* : c'est la **Sender Signature**, qui dit quelles adresses le compte
a le droit d'employer en `From`. Sans elle, rien ne part du tout — le message
est refusé à l'entrée, et le DNS n'a pas son mot à dire. C'était le cas :

```
ÉCHEC  Contenu et messages privés  si@rnfrance.org
       The 'From' address you supplied (si@rnfrance.org) is not a Sender
       Signature on your account. (ErrorCode 400)
```

**2. Et `rnfrance.org` ne pouvait pas y être ajouté.** Le compte Postmark
employé est celui de **Naturadapt / Tela Botanica**, hérité de la plateforme
d'origine — `naturadapt.com`, `lists.naturadapt.com`,
`lists.staging.naturadapt.com`, `reserves-naturelles.org`,
`lists.reserves-naturelles.org`. Il est plafonné à **cinq domaines**, et les
cinq sont pris.

D'où le choix fait le 25 août 2026 : **écrire depuis un domaine que ce compte
autorise déjà**, plutôt que d'en négocier un sixième. Ce qui a réglé trois
choses d'un coup — l'envoi n'est plus refusé, le travail DNS ne porte plus que
sur un domaine au lieu de deux, et le `p=quarantine` de `rnfrance.org`, qui
demandait explicitement de mettre en quarantaine tout ce qui n'authentifie pas,
cesse de s'appliquer à nos envois.

⚠️ **À garder en tête :** ce compte n'appartient pas à RNF. Le jour où RNF
voudra ses propres jetons Postmark, tout ce qui précède sera à refaire sur le
nouveau compte — signatures comprises.

## L'adresse d'expédition, par environnement

`POSTMARK_SENDER`, dans le `.env.local` de chaque machine. Elle n'a pas à être
la même partout, et **ne doit surtout pas être recopiée telle quelle** d'un
environnement à l'autre : le suffixe est ce qui permet de distinguer les envois
dans le journal Postmark.

| Environnement | Valeur |
|---|---|
| Préproduction | `communaute-staging@lists.reserves-naturelles.org` |
| Production | `communaute@lists.reserves-naturelles.org` |

`POSTMARK_LIST_DOMAIN=lists.reserves-naturelles.org` ne change pas : c'est lui
qui porte le `noreply@` des messages de discussion, et leur `Reply-To`.

## Et ensuite

Une fois les deux enregistrements en place et vérifiés :

1. Publier une politique DMARC sur le sous-domaine, avec une adresse de
   rapport, pour être prévenu si un envoi échoue encore :

   | | |
   |---|---|
   | Type | TXT |
   | Nom / hôte | `_dmarc.lists` |
   | Valeur | `v=DMARC1; p=none; rua=mailto:dmarc@rnfrance.org` |

   `p=none` d'abord : on observe avant de durcir. Passer à `p=quarantine`
   seulement une fois que les rapports montrent que tout authentifie — c'est
   l'ordre inverse qui avait mis `rnfrance.org` en difficulté.

2. **Alors, et pas avant**, activer la tâche planifiée du résumé de
   notifications (voir `mise-en-preproduction.md`). L'activer plus tôt
   transformerait un problème visible en volume de plaintes.

## Ce que ça ne change pas

La **réception** du courrier de `rnfrance.org` comme de
`reserves-naturelles.org` passe par leurs enregistrements MX et n'est pas
concernée. Aucune des modifications ci-dessus n'y touche.
