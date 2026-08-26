# Script de recette

Ce qu'il faut regarder sur une préproduction fraîchement déployée, dans
l'ordre, et **ce qui doit se passer**. Les données de test sont faites pour
ça : chaque cas y a son contraire, il n'y a rien à saisir avant de commencer.

Numéros d'issues entre parenthèses.

> Pour **transmettre la recette à quelqu'un d'autre**, il y a
> [`recette-a-transmettre.md`](recette-a-transmettre.md) : même contenu, sans
> les numéros d'issues ni les commandes serveur, et avec de quoi se connecter.

---

## 0. Deux préalables, sans quoi la recette est faussée

### Les assets — plus rien à faire

Node étant absent des serveurs, `public/build/` est désormais **versé dans le
dépôt** : un `git pull` suffit, les assets suivent le code.

En contrepartie, qui modifie `assets/` doit recompiler **avant de commiter** :

```bash
NODE_OPTIONS=--openssl-legacy-provider npm run build
git add public/build
```

Vérification après déploiement : la page d'un groupe affiche les notes de
droits (#33), mises en forme par une feuille de style récente.

### Votre compte

Deux façons d'entrer, selon ce que vous voulez essayer.

### D'abord : contre quel GeoNature ?

Une préproduction doit pointer vers l'instance de **test**, sans quoi elle
authentifie contre le GeoNature de production :

```bash
# .env.local
RNF_AUTH_API_ENDPOINT=https://geonature-test.reserves-naturelles.org
RNF_AUTH_ID_APPLICATION=…   # à vérifier : l'identifiant peut différer de celui de production
```

**La meilleure façon d'entrer** est alors d'y créer des comptes portant **les
mêmes adresses que les comptes des données de test**. À la première connexion,
`syncLocalUser` retrouve le compte local par son adresse et l'adopte : on hérite
de son profil, de ses groupes, de ses notifications — et l'on éprouve le vrai
chemin d'authentification, celui de la production. Seuls le nom et le nom
affiché sont réécrits par GeoNature, ce qui est précisément le comportement de
#36.

⚠️ L'export « Liens utilisateurs-réserves » **n'existe pas** sur l'instance de
test : `app:rnf:sync-reserves` n'y remontera rien, et le champ reste saisi à la
main. Le service le constate sans rien vider (#28).

### À défaut : la connexion par mot de passe

**Avec les comptes des données de test** — dépannage quand le SSO de test n'est
pas prêt. Ils n'existent pas dans GeoNature : il faut allumer la connexion par
mot de passe, éteinte partout par défaut.

```bash
# .env.local, préproduction seulement — JAMAIS en production
FORM_LOGIN_ENABLED=1
```

Puis `php bin/console cache:clear`, et se connecter sur `/user/login` avec
`antoine.schlegel+admin@rnfrance.org`, `+referent@`, `+membre@`… et le mot de
passe **`test`**. Les adresses portent votre étiquette parce que
`TEST_ACCOUNTS_EMAIL` était renseigné au chargement des fixtures ; sans elle,
ce sont les adresses en `@example.org`.

Un compte venu du SSO ne s'ouvre **jamais** par ce chemin, même allumé : sa
colonne de mot de passe est vide, et c'est refusé explicitement.

**Avec votre vrai compte GeoNature** — indispensable pour éprouver le SSO
lui-même. Le chargement des fixtures ayant vidé la base, il a disparu :

1. se connecter par le SSO — le compte est recréé ;
2. lui redonner ses droits :

   ```bash
   php bin/console user:set-admin votre.adresse@rnfrance.org
   ```

**Corollaire** : on n'est jamais notifié de ses propres actions. Recetter les
notifications demande **deux comptes** — deux comptes de test dans deux
navigateurs suffisent, une fois la connexion par mot de passe allumée.

---

## 1. Est-ce que ça tient debout (5 minutes)

```bash
php bin/console app:preflight     # rien de bloquant ?
php bin/console app:mail:check    # le DNS autorise-t-il l'envoi ?
```

| À faire | Attendu |
|---|---|
| Se connecter | On arrive sur **Mes groupes**, pas sur le tableau de bord (#9) |
| Première connexion | **La visite guidée se lance seule** (#39) |
| Avancer d'étape en étape | L'élément dont on parle est **entouré**, le reste de la page est dans l'ombre |
| Continuer | Elle **change de page** toute seule — groupes, un de vos groupes, sa bibliothèque, l'annuaire, votre profil, vos notifications, vos réglages — et le bouton annonce chaque fois où il emmène |
| À l'étape « Votre compte » | Le menu du compte **s'ouvre** pour montrer ce qu'il contient, et se referme en avançant |
| Au milieu, cliquer un lien de la page | La visite ne rouvre pas de force : une pastille « Reprendre la visite » attend en bas à droite |
| La fermer au 2ᵉ écran, naviguer | Elle ne se relance pas |
| Mes paramètres → « Revoir la visite guidée » | Elle repart du début |

---

## 2. L'annuaire

Les six comptes de test portent chacun une situation différente. On les
consulte depuis **Annuaire** ; inutile de s'y connecter.

| Fiche | Attendu |
|---|---|
| **Rémi Référent** | Fonction, structure **et** réserves sous le nom ; adresse e-mail visible, bouton « Contacter » (#30, #27) |
| **Manon Membre** | Fonction et structure, mais **aucune coordonnée** — et un message qui le dit, pas une zone vide (#27) |
| **Éric Extérieur** | Un téléphone cliquable, **pas d'adresse e-mail** (#27) |
| **Camille Candidate** | Fiche vide : ni fonction, ni structure — c'est le cas « profil jamais rempli » |
| **Sophie SSO** | Modifier son profil est impossible : nom et nom affiché grisés avec l'explication (#36) |

| À faire | Attendu |
|---|---|
| Chercher « Conservatrice » dans l'annuaire | Des fiches remontent — la recherche porte sur la fonction (#30) |
| Ouvrir **Modifier mon profil** | Fonction, structure, réserves, téléphone, case « Afficher mon adresse » ; **pas de champ Bio** (#30) |
| Décocher « Afficher mon adresse », enregistrer, voir sa fiche depuis un autre compte | L'adresse et le bouton « Contacter » ont disparu |
| La liste des compétences | S'affiche **d'emblée**, sans avoir à taper (#16) ; « plans de gestion (méthode CT88) » est lisible (#35) |
| Enregistrer une modification de profil | Elle est bien conservée (#10) |

---

## 3. Un groupe

Aller dans **Groupe de test**.

| À faire | Attendu |
|---|---|
| Ouvrir chaque onglet | Une note discrète dit **qui peut modifier quoi** (#33) |
| Ouvrir la même page sans être membre du groupe | La note n'apparaît pas |
| Page « Page rédigée par un membre » | Modifiable par son auteur et par un animateur ; **pas** par un autre membre (#33) |
| Onglet Pages | « Page importante de test » est mise en avant, en tête et repérée (#2) |
| Liste des groupes | Hiérarchie commission → groupes (#22), filtre par commission (#23) |
| Liste **Filtrer par thématique** | Réduit la liste ; un groupe hors de la thématique disparaît (#23) |
| Les deux listes en même temps | Elles se cumulent : une commission **et** une thématique qu'aucun de ses groupes ne porte donne une liste vide, pas l'un des deux ignoré (#23) |
| Taper une lettre dans la recherche, filtre posé | Le filtre tient : la recherche ne ramène pas les 58 groupes (#23) |
| Administration → **Thématiques** | Créer une thématique, la retrouver sur le formulaire d'un groupe, la retirer : les groupes restent, déclassés (#23) |
| Description d'un groupe | Visible de ses membres (#5) |

---

## 4. Les documents

| À faire | Attendu |
|---|---|
| Onglet Documents | Une arborescence de dossiers, dont un sous-dossier (#8) |
| Cliquer le titre d'un document | **Une fiche s'ouvre** — description, étiquettes, dossier, déposant, date (#32) |
| Sur la fiche | « Ce qui en a été dit » liste une page **et** une discussion qui y renvoient (#32) |
| Bouton « En discuter » | Un sujet s'ouvre, **prérempli**, avec le lien vers le document dans le corps (#32) |
| Retour à la liste | Le téléchargement reste à **un clic**, à côté du titre |
| Filtre « Étiquette » à gauche | Cocher « Cycle 1 » : la liste se réduit, un document sans étiquette disparaît (#26) |
| Deux étiquettes cochées | Les documents portant **l'une ou l'autre** remontent |
| Déposer un document | Description et étiquettes proposées ; la description apparaît dans la liste (#7) |
| Déposer un fichier de plus de 10 Mo | Accepté (#25) |
| Valider le dépôt **sans choisir de fichier** | Refusé, avec l'explication sous le champ ; aucune ligne n'apparaît dans la liste (#40) |
| Déposer un fichier de **plus de 50 Mo** | Refusé avec un message en haut de page — pas une page vierge, pas un document vide (#40) |
| Modifier un document sans redéposer son fichier | Accepté : le fichier reste celui d'avant (#40) |
| Modifier un document **en choisissant un autre fichier** | Le téléchargement rend le **nouveau** fichier, et le titre choisi ne change pas (#41) |

### Voir le document sans le télécharger (#43)

| À faire | Attendu |
|---|---|
| Déposer un **PDF**, ouvrir sa fiche | Le document **s'affiche dans la page**, sous le titre |
| Bouton « Télécharger » sur cette même fiche | Le fichier est **enregistré**, il ne s'ouvre pas dans un onglet |
| Déposer une **image**, ouvrir sa fiche | Elle s'affiche ; le clic ouvre l'image en grand |
| Déposer un **.docx** ou un **.odt**, ouvrir sa fiche | Pas d'aperçu — et c'est voulu : rien, plutôt qu'une page d'octets |
| Déposer un **.svg**, cliquer « Télécharger » | Le fichier est enregistré. Il ne doit **jamais** s'ouvrir dans un onglet du site |

### L'édition en ligne (#43) — seulement si un serveur de documents est configuré

`ONLYOFFICE_URL` vide, **rien de ce qui suit n'apparaît**, et c'est le
comportement attendu : la ligne « ONLYOFFICE_URL » d'`app:preflight` le dit, et
les documents se téléchargent comme avant. Passez à la section suivante.

| À faire | Attendu |
|---|---|
| `php bin/console app:preflight` | « Serveur de documents … joignable depuis la plateforme ». Sinon **rien ne sera enregistré** : inutile d'aller plus loin |
| Fiche d'un **.docx** ou **.odt** | Un bouton « Modifier en ligne » |
| Le cliquer | L'éditeur s'ouvre dans la page, avec le contenu du document |
| Modifier une phrase, puis « Retour à la fiche » | De retour sur la fiche, **télécharger** : le fichier porte la modification |
| Fiche d'un **.doc** (ancien format) | Le bouton dit « Ouvrir dans le navigateur », et la page annonce la lecture seule **avant** d'ouvrir l'éditeur |
| Avec un compte **simple membre**, sur un document déposé par quelqu'un d'autre | L'éditeur s'ouvre en **consultation** : aucune barre d'édition |
| Fiche d'un **PDF** | Pas de bouton d'édition en ligne — le PDF s'affiche déjà, il ne passe pas par le serveur de documents |

---

## 5. Les discussions

| À faire | Attendu |
|---|---|
| Ouvrir « Discussion de test » | Le 2ᵉ message nomme Manon Membre, et **« @Manon Membre » est un lien** vers sa fiche (#37) |
| Écrire un message, taper `@` | Une liste de noms du groupe s'ouvre ; choisir insère le nom (#37) |
| Son propre message | Modifiable et supprimable (#19) |
| Le message d'un autre, en tant qu'animateur | Supprimable (#19) |
| Sujet dont on est l'auteur | « Renommer le sujet » ; le titre change, aucun e-mail n'est envoyé |
| Supprimer une discussion entière | Possible pour un animateur (#20) |
| Menu profil | Entrée « Mes discussions » (#21) |
| Une page avec des listes à puces | Les puces s'affichent (#11) |
| L'éditeur de texte | **Neuf groupes de boutons**, plus d'exposant ni de sens d'écriture RTL (#32) |

---

## 5 bis. Les actualités

Onglet **Actualités** du **Groupe de test**. Il en porte trois, de deux
auteurs et de trois dates différentes : c'est ce qu'il faut pour voir autre
chose qu'une page d'actualité isolée.

| À faire | Attendu |
|---|---|
| Onglet Actualités | Trois actualités, la plus récente en tête ; celle « de l'an dernier » est en bas et n'a pas disparu |
| « Actualité rédigée par un membre » | Modifiable par son auteur (Manon) et par un animateur ; **pas** par un autre membre (#33) |
| « Actualité de test », en tant que Manon | **Non** modifiable : elle est de l'animateur (#33) |
| Écrire une actualité en tant que membre simple | Autorisé : c'est la modification qui est réservée, pas l'écriture (#33) |
| Groupe **Communauté RNF**, onglet Actualités | Huit actualités échelonnées sur plusieurs semaines, aucune datée du même jour |

---

## 6. Notifications et e-mails — **à deux**

C'est la partie qui demande deux comptes — deux comptes de test dans deux navigateurs suffisent.

| À faire | Attendu |
|---|---|
| Notifications de Manon Membre | Une **mention non lue** et une notification **déjà lue**, visuellement distinctes (#34, #37) |
| L'autre personne crée une page dans un groupe commun | Vous êtes notifié, **elle ne l'est pas** (#34) |
| Mes paramètres → **Pour tous mes groupes** | Quatre listes, une par type de contenu : c'est le réglage qui s'applique partout |
| Régler « Pages » sur « aucune » **en haut**, sans toucher aux groupes | Plus aucune notification de page, dans **tous** les groupes — rien n'a été recopié groupe par groupe |
| Déplier un groupe, y régler « Discussions » sur « aucune » | Son résumé, replié, annonce « Discussions : aucune notification » et se détache du reste |
| Dans ce groupe, « Tout remettre au réglage général » puis enregistrer | Il annonce de nouveau « comme le réglage général » |
| Avec beaucoup de groupes : taper un nom dans **Chercher un groupe** | La liste se réduit, le compte suit ; accents et majuscules sont ignorés |
| « Remettre tous mes groupes au réglage général » | Confirmation demandée, puis tous les groupes suivent de nouveau |
| Couper la catégorie discussions, puis « suivre » une discussion | Notifié de celle-là seulement (#17) |
| Mes paramètres → n'importe quelle liste | **Cinq choix** : aucune, plateforme seulement, e-mail immédiat, résumé quotidien, résumé hebdomadaire le lundi (#38) |
| Un compte qui n'a jamais rien réglé | Tout arrive dans le **résumé quotidien** : c'est le défaut depuis #38 |
| Régler « Discussions » sur **e-mail immédiat** et « Documents » sur **hebdomadaire** | Les messages arrivent un par un, les documents attendent lundi — le rythme se choisit ligne par ligne (#38) |
| Régler « Pages » sur **e-mail immédiat**, publier une page depuis l'autre compte | Un e-mail part tout de suite, et la page **ne revient pas** dans le résumé du soir (#38) |
| Première connexion après la mise à jour | Un bandeau annonce le changement, avec un lien vers les réglages ; il ne revient pas ensuite (#38) |
| L'autre personne vous mentionne dans une discussion **mise en sourdine** | Vous êtes quand même notifié (#37) |
| Poster un message de discussion | L'autre le reçoit **une seule fois** (#12), vous ne le recevez pas (#15) |
| Demander à rejoindre un groupe privé | L'animateur le voit, même si l'e-mail échoue (#4) |
| Bouton « Contacter par e-mail » | S'ouvre correctement dans le client mail (#13) |

Puis, en ligne de commande :

```bash
php bin/console app:mail:check --to=votre.adresse@rnfrance.org
php bin/console app:notifications:digest --dry-run
php bin/console app:notifications:digest --day=2026-08-24 --dry-run   # un lundi
```

⚠️ **Ne pas activer la tâche planifiée** tant que `app:mail:check` n'est pas
vert sur les quatre lignes.

---

## 6 bis. La messagerie — **à deux**

Les données de test contiennent déjà quatre conversations, pour que rien
n'oblige à en écrire une avant de regarder. Entre les comptes nommés :

| Conversation | Ce qu'elle montre |
|---|---|
| Rémi ↔ **Manon** | Un tête-à-tête, un tag vers une personne, un vers un document, un vers un groupe, un message **modifié**, et un dernier message **non lu par Manon** |
| Alice + Rémi + Manon (+ Éric, parti) | Un fil **à plusieurs**, un message **supprimé**, **archivée** par Manon |
| Rémi ↔ **Camille** | Le **tag grisé** : Camille attend à la porte du groupe privé |
| Éric → **Manon** | La **boîte fermée** d'Éric, et deux **signalements** |

Deux documents portent des titres qu'aucun autre contenu ne porte, pour que les
tags ne soient pas ambigus : « **Guide des suivis partagés** » (groupe de test,
public) et « **Note de cadrage du bureau** » (groupe privé).

| À faire | Attendu |
|---|---|
| Se connecter en **Manon Membre**, regarder l'en-tête | Un compteur à côté de **Messages** |
| En-tête → **Messages** | La page s'ouvre en deux colonnes : la liste à gauche, un texte d'invite à droite |
| Ouvrir la conversation avec Rémi | Une barre « Nouveaux messages » devant le dernier ; le compteur retombe à zéro |
| Dans ce fil | Un message porte « modifié » ; les tags sont des liens colorés avec une pastille de type |
| Onglet **Archivées** | La conversation à plusieurs s'y trouve, avec un « Message supprimé » qui garde sa place et Éric qui l'a quittée |
| Fiche d'**Éric Extérieur** | Pas de bouton « Écrire » : sa boîte est fermée |
| Mais la conversation avec Éric, dans la boîte de Manon | Elle s'ouvre et on peut y répondre — fermer sa boîte n'interrompt pas ce qui est en cours |
| Se connecter en **Rémi Référent**, ouvrir la conversation avec Camille | Les trois tags sont des **liens** : il est membre du groupe privé |
| Se connecter en **Camille Candidate**, ouvrir la même | « Groupe privé de test » et « Note de cadrage du bureau » sont **grisés et non cliquables** ; « Guide des suivis partagés » reste un lien |
| Fiche d'un membre → **Écrire** | Le formulaire s'ouvre avec cette personne déjà cochée |
| Écrire un premier message | La conversation s'ouvre, le message y est |
| Retourner sur la même fiche → **Écrire** | On retombe **dans la conversation existante**, pas sur un formulaire vierge |
| Regarder l'en-tête de l'autre compte | Un compteur à côté de **Messages** ; il retombe à zéro une fois la conversation ouverte |
| Dans un message, taper `@` puis les premières lettres d'un nom | Une liste propose des membres ; choisir insère « @Prénom Nom » **en texte** |
| Taper `#` puis le début d'un titre de document | La liste propose documents, pages, actualités, discussions et groupes, avec le groupe d'origine en indication |
| Envoyer et relire le message | Le tag est devenu un lien, avec une pastille qui dit son type |
| **Taguer un document d'un groupe privé** dont l'autre n'est pas membre | Chez lui, le titre reste visible mais **grisé et non cliquable** ; chez vous, il est cliquable |
| Écrire le tag **à la main**, sans passer par la liste | Il est reconnu pareil : rien ne dépend du JavaScript |
| Citer `@quelqu'un` qui n'est pas dans la conversation | Un message éclair prévient que la citation ne l'atteint pas |
| **Ajouter quelqu'un** à la conversation, puis regarder chez lui | Il lit **tout** le fil, y compris ce qui précède son arrivée |
| Modifier puis supprimer son propre message | « modifié » apparaît ; supprimé, sa place reste et le texte devient « Message supprimé » |
| Essayer de modifier le message de l'autre | Aucun bouton : il n'y a pas d'animateur dans une conversation privée |
| **Archiver** une conversation, puis y faire écrire l'autre | Elle ressort de l'onglet « Archivées » : ranger n'est pas se désabonner |
| **Quitter** une conversation | Elle disparaît de votre liste, vos messages restent chez l'autre, et il peut vous réécrire |
| Mes paramètres → décocher **Accepter de recevoir des messages privés** | Sur votre fiche, le bouton « Écrire » disparaît ; les conversations en cours restent ouvertes |
| Mes paramètres → réglage général | Une **cinquième ligne**, « Messages privés », réglée sur e-mail immédiat par défaut — et **aucune ligne « messages » sous les groupes** |
| Filtrer la liste avec le nom de l'autre personne | La conversation remonte, même si le mot n'est dans aucun message |

### Signaler, et ce que l'équipe voit

| À faire | Attendu |
|---|---|
| Sous un message reçu → **Signaler** | Un écran montre **ce qui sera transmis** : le message et les quelques messages qui le précèdent |
| Valider | Confirmation ; l'autre n'est pas prévenu |
| Compte administrateur → Administration → **Signalements** | Le signalement, avec les copies. **Aucun lien n'ouvre la conversation** — c'est voulu |
| Faire supprimer le message signalé par son auteur, recharger | Le signalement **garde sa copie** : il survit à ce qu'il signale |
| **Marquer comme traité** | Il passe dans l'onglet « Traités », avec qui l'a traité ; on peut le rouvrir |

---

## 7. L'administration

| À faire | Attendu |
|---|---|
| Administration → **Étiquettes** | Six étiquettes, dont « Grand public » et « Cycle 1 » ; on peut en ajouter une, pas deux fois la même (#26) |
| Retirer une étiquette | Elle disparaît aussi des documents qui la portaient |

---

## 8. Si un jeton GeoNature est configuré

```bash
php bin/console app:rnf:inspect --email=votre.adresse@rnfrance.org
php bin/console app:rnf:sync-reserves --dry-run
```

La première n'écrit rien et montre ce que GeoNature répond, `roleOPNLInfo`
compris — c'est ce qui reste à trancher sur #28.

---

## Ce qu'on ne peut pas recetter ici

- **La délivrabilité réelle** tant que les trois enregistrements DNS ne sont
  pas publiés (#14). `app:mail:check` dit où on en est.
- **Le comportement exact de la production** si `APP_DEBUG=1` : le mode debug
  active `strict_variables` dans Twig, une valeur absente y devient une erreur
  fatale au lieu d'un silence. Pour une recette représentative, mettre
  `APP_DEBUG=0`.
