# Script de recette

Ce qu'il faut regarder sur une préproduction fraîchement déployée, dans
l'ordre, et **ce qui doit se passer**. Les données de test sont faites pour
ça : chaque cas y a son contraire, il n'y a rien à saisir avant de commencer.

Numéros d'issues entre parenthèses.

---

## 0. Deux préalables, sans quoi la recette est faussée

### Les assets

Node est absent du serveur : `npm run build` n'y passe pas, et **les fichiers
compilés ne sont pas mis à jour par un `git pull`**. Sans eux, les mentions et
la visite guidée ne font rien — sans la moindre erreur visible.

```bash
node -v    # sur le serveur
```

- **Node répond** : `NODE_OPTIONS=--openssl-legacy-provider npm ci && NODE_OPTIONS=--openssl-legacy-provider npm run build`
- **Node ne répond pas** : compiler sur un poste de développement puis copier

  ```bash
  NODE_OPTIONS=--openssl-legacy-provider npm run build
  rsync -avz --delete public/build/ UTILISATEUR@SERVEUR:/var/www/html/communaute/public/build/
  ```

Vérification : la page d'un groupe doit afficher les notes de droits (#33), qui
sont mises en forme par la nouvelle feuille de style.

### Votre compte

Le pare-feu ne branche **que le SSO RNF** : les six comptes de test et leur mot
de passe ne servent qu'en local. Sur un serveur, seul un vrai compte GeoNature
entre.

Le chargement des fixtures ayant vidé la base, votre compte a disparu :

1. se connecter par le SSO — le compte est recréé ;
2. lui redonner ses droits :

   ```bash
   php bin/console user:set-admin votre.adresse@rnfrance.org
   ```

**Corollaire** : on n'est jamais notifié de ses propres actions. Recetter les
notifications demande **deux personnes**, ou deux comptes GeoNature.

---

## 1. Est-ce que ça tient debout (5 minutes)

```bash
php bin/console app:preflight     # rien de bloquant ?
php bin/console app:mail:check    # le DNS autorise-t-il l'envoi ?
```

| À faire | Attendu |
|---|---|
| Se connecter | On arrive sur **Mes groupes**, pas sur le tableau de bord (#9) |
| Première connexion | **La visite guidée se lance seule**, dix étapes (#39) |
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

## 6. Notifications et e-mails — **à deux**

C'est la partie qui demande un second compte GeoNature.

| À faire | Attendu |
|---|---|
| Notifications de Manon Membre | Une **mention non lue** et une notification **déjà lue**, visuellement distinctes (#34, #37) |
| L'autre personne crée une page dans un groupe commun | Vous êtes notifié, **elle ne l'est pas** (#34) |
| Régler une catégorie sur « aucune », l'autre publie | Plus de notification pour cette catégorie |
| Couper la catégorie discussions, puis « suivre » une discussion | Notifié de celle-là seulement (#17) |
| Mes paramètres → rythme | **Trois choix** : à chaque message, résumé quotidien, **résumé hebdomadaire le lundi** (#38) |
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
