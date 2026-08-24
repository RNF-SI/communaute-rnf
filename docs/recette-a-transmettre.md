# Recette de la Communauté RNF — préproduction

Bonjour,

La préproduction porte 27 changements livrés ces derniers jours : 30 issues
attendent d'être validées. Voici de quoi t'y retrouver.

**Adresse** : *(à compléter)*
**Ce qu'on cherche** : ce qui ne fonctionne pas comme décrit ci-dessous. Une
capture d'écran et le nom de la page suffisent.

---

## Se connecter

Deux comptes, dans **deux navigateurs différents** (ou une fenêtre privée) : on
n'est jamais notifié de ses propres actions, il en faut donc deux pour éprouver
les notifications.

| Compte | Identifiant GeoNature | Rôle sur la plateforme |
|---|---|---|
| Administrateur | `comm-admin` | administration + animateur du groupe de test |
| Membre | `comm-membre` | membre simple |

Mots de passe : demander à Antoine.

Ce sont des comptes du **GeoNature de test**, pas de la production.

> ⚠️ Les e-mails de ces comptes arrivent dans la boîte d'Antoine. Si tu veux
> recevoir les tiens, crée-toi un compte dans le GeoNature de test à ta propre
> adresse — tu partiras alors d'un profil vide, ce qui est aussi un cas
> intéressant à éprouver.

**À la première connexion** : une visite guidée doit se lancer d'elle-même.
Elle ne reste pas sur place — elle traverse une vingtaine d'étapes et huit
pages, entoure à chaque fois l'élément dont elle parle, et le bouton annonce
où il emmène (« Voir tous les groupes », « Ouvrir la bibliothèque du
groupe »…). C'est le premier point à valider : va jusqu'au bout, puis
vérifie qu'elle ne se relance plus.

---

## 1. L'annuaire — 5 minutes

Menu **Annuaire**. Six fiches y montrent chacune une situation différente.
Il n'y a rien à saisir : tout est déjà là.

| Fiche | Ce qui doit s'afficher |
|---|---|
| **Rémi Référent** | Fonction, structure et réserves sous le nom ; adresse e-mail + bouton « Contacter » |
| **Manon Membre** | Fonction et structure, mais **aucune coordonnée** — avec une phrase qui le dit, pas une zone vide |
| **Éric Extérieur** | Un téléphone cliquable, **pas d'adresse e-mail** |
| **Camille Candidate** | Fiche vide — le cas « profil jamais rempli » |

Puis, sur ton propre profil (**Mes paramètres → Modifier mon profil**) :

- il y a **Fonction**, **Structure**, **Réserve(s) suivie(s)**, **Téléphone** et
  une case « Afficher mon adresse e-mail » ;
- il n'y a **plus** de champ « Bio » ;
- la liste des compétences s'affiche **d'emblée**, sans avoir à taper ;
- décoche « Afficher mon adresse », enregistre, puis regarde ta fiche depuis
  l'autre navigateur : l'adresse **et** le bouton « Contacter » ont disparu.

Enfin, cherche **« Conservatrice »** dans l'annuaire : des fiches doivent
remonter. On peut désormais trouver quelqu'un par son métier.

---

## 2. Un groupe — 5 minutes

Ouvre **Groupe de test**.

- Chaque onglet porte une **note discrète disant qui peut modifier quoi**.
- Onglet **Pages** : « Page importante de test » est mise en avant, en tête.
- La page « Page rédigée par un membre » est modifiable par son auteur et par
  un animateur — **pas** par un autre membre. À éprouver avec les deux comptes.
- Liste des groupes : la hiérarchie commission → groupes apparaît, et un filtre
  par commission fonctionne.

---

## 3. Les documents — 10 minutes

Onglet **Documents** du groupe de test.

- Une arborescence de dossiers, dont un **sous-dossier**.
- **Clique le titre d'un document** : une fiche s'ouvre — description,
  étiquettes, dossier, qui l'a déposé, date, taille. C'est nouveau.
- Sur cette fiche, « **Ce qui en a été dit** » liste une page **et** une
  discussion qui renvoient vers ce document.
- Le bouton « **En discuter** » ouvre un sujet déjà prérempli, avec le lien
  vers le document.
- De retour sur la liste, le **téléchargement reste à un clic**, à côté du titre.
- Colonne de gauche, filtre « **Étiquette** » : coche « Cycle 1 », la liste se
  réduit. Coche-en deux : les documents portant **l'une ou l'autre** remontent.
- Dépose un document : description et étiquettes sont proposées. Un fichier de
  **plus de 10 Mo** doit passer.

---

## 4. Les discussions — 10 minutes

- Ouvre « Discussion de test » : le 2ᵉ message nomme Manon Membre, et
  « **@Manon Membre** » est un **lien** vers sa fiche.
- Écris un message et tape « **@** » : une liste de noms du groupe s'ouvre.
  Choisis-en un, le nom s'insère.
- Ton propre message est **modifiable et supprimable**.
- En tant qu'animateur, tu peux supprimer le message d'un autre.
- Sur un sujet dont tu es l'auteur : « **Renommer le sujet** ».
- Menu profil : une entrée « **Mes discussions** ».
- L'éditeur de texte est allégé : **neuf groupes de boutons**, plus d'exposant
  ni de sens d'écriture de droite à gauche.

---

## 5. Les notifications — **à deux navigateurs**

C'est la partie la plus délicate, et la plus utile.

| À faire | Attendu |
|---|---|
| Notifications de Manon Membre | Une **mention non lue** et une notification **déjà lue**, visuellement distinctes |
| Depuis l'autre compte, créer une page dans un groupe commun | Tu es notifié, **l'autre ne l'est pas** |
| Régler une catégorie sur « aucune », puis publier depuis l'autre compte | Plus de notification pour cette catégorie |
| Couper la catégorie discussions, puis « suivre » une discussion précise | Notifié de celle-là seulement |
| **Mes paramètres → n'importe quelle liste** | **Cinq choix** : aucune, plateforme seulement, e-mail immédiat, résumé quotidien, résumé hebdomadaire le lundi |
| Régler « Discussions » sur **e-mail immédiat** et « Documents » sur **hebdomadaire** | Chaque type de contenu suit son propre rythme |
| À ta première connexion après la mise à jour | Un bandeau explique ce qui a changé et mène aux réglages ; il ne revient pas ensuite |
| Depuis l'autre compte, te mentionner dans une discussion que tu as **mise en sourdine** | Tu es **quand même** notifié |
| Poster un message de discussion | L'autre le reçoit **une seule fois**, toi **pas du tout** |

> ⚠️ Les e-mails peuvent arriver dans les **indésirables** : trois
> enregistrements DNS manquent encore sur `rnfrance.org`. C'est connu, ce n'est
> pas un défaut de la plateforme.

---

## 6. La messagerie — **à deux navigateurs**, 15 minutes

Menu du compte → **Messages**. La page s'ouvre en deux colonnes : la liste des
conversations à gauche, celle qu'on a ouverte à droite. Sur mobile, l'une sous
l'autre.

Cette partie demande les deux comptes : une messagerie ne se regarde pas seul.

### Écrire, et être prévenu

| À faire | Attendu |
|---|---|
| **Administrateur** : annuaire → fiche du membre → **Écrire** | Le formulaire s'ouvre avec cette personne déjà cochée |
| Écrire un message et envoyer | La conversation s'ouvre avec le message dedans |
| **Membre** : regarder l'en-tête | Un compteur à côté de **Messages** |
| Ouvrir la conversation | Une barre « Nouveaux messages », et le compteur retombe à zéro |
| Répondre | L'administrateur voit à son tour le compteur |
| **Administrateur** : retourner sur la fiche du membre → **Écrire** | On retombe **dans la conversation existante**, pas sur un formulaire vierge |

### Les tags

| À faire | Attendu |
|---|---|
| Dans un message, taper `@` puis les premières lettres d'un nom | Une liste propose des membres ; en choisir un insère « @Prénom Nom » **en texte** |
| Taper `#` puis `Guide` | La liste propose « Guide des suivis partagés », avec son groupe en indication |
| Taper `#` puis `Groupe de test` | Le groupe est proposé lui aussi : personnes, contenus et groupes se taguent pareil |
| Envoyer, puis relire | Les tags sont devenus des liens colorés, chacun avec une pastille qui dit son type, et ils mènent là où ils disent |
| Écrire un tag **à la main**, sans passer par la liste | Il est reconnu pareil : rien ne dépend de la liste de suggestions |
| Citer `@` quelqu'un qui n'est pas dans la conversation | Un message prévient que la citation ne l'atteint pas — il faut l'ajouter pour qu'il lise |

### Le point le plus important : un tag ne donne accès à rien

Le groupe « **Groupe privé de test** » est privé. L'administrateur peut y
entrer, le membre non.

1. **Administrateur** : dans un message au membre, taper `#Note de cadrage du
   bureau` (la liste doit le proposer) et envoyer.
2. **Administrateur** : le tag est un **lien**, et il ouvre le document.
3. **Membre**, dans le même message : le tag est **grisé et non cliquable**.
   Le titre reste lisible — il est dans la phrase — mais il ne mène nulle part.

C'est voulu, et c'est ce qu'il faut signaler si ce n'est pas le cas : un tag ne
donne jamais accès à ce qu'on n'a pas le droit de lire.

### Le reste

| À faire | Attendu |
|---|---|
| **Ajouter quelqu'un** à la conversation (chercher un nom, cocher) | Il lit **tout** le fil, y compris ce qui précède son arrivée — l'écran le dit avant de valider |
| Modifier son propre message | « modifié » apparaît à côté de la date |
| Supprimer son propre message | Sa place reste dans le fil, le texte devient « Message supprimé » |
| Essayer de modifier le message de l'autre | Aucun bouton : il n'y a pas d'animateur dans une conversation privée |
| **Archiver** une conversation, puis y faire écrire l'autre | Elle ressort de l'onglet « Archivées » : ranger n'est pas se désabonner |
| **Quitter** une conversation | Elle disparaît de la liste, les messages restent chez l'autre, et il peut réécrire |
| Réglages → décocher **Accepter de recevoir des messages privés** | Sur cette fiche, vue par l'autre compte, le bouton « Écrire » disparaît. Ce n'est pas une panne |
| Mais la conversation déjà ouverte | Elle s'ouvre encore et on peut y répondre : fermer sa boîte n'interrompt pas ce qui est en cours |
| Filtrer la liste avec le nom de l'autre personne | La conversation remonte, même si ce nom n'est dans aucun message |
| Réglages → réglage général des notifications | Une **cinquième ligne**, « Messages privés » — et **aucune ligne « messages » sous les groupes** |
| Sous un message **reçu** → **Signaler** | Un écran montre **ce qui sera transmis** : ce message et les quelques messages qui le précèdent. Rien d'autre ne part, et l'autre n'est pas prévenu |

---

## 7. L'administration — 5 minutes

Avec le compte administrateur : **Administration → Étiquettes**.

- Six étiquettes, dont « Grand public » et « Cycle 1 ».
- On peut en ajouter une, mais **pas deux fois la même**.
- En retirer une la retire aussi des documents qui la portaient.

Puis **Administration → Signalements**.

- Un signalement **à traiter** et un **déjà traité**, dans deux onglets.
- Chacun montre une **copie** du message et de ce qui le précédait.
- **Aucun lien n'ouvre la conversation** : l'équipe voit ce qui lui a été
  transmis, et rien de plus. C'est la promesse faite aux membres.
- « Marquer comme traité » le fait passer dans l'autre onglet ; on peut le
  rouvrir.

---

## Comment signaler

Pour chaque anomalie : **la page**, **ce que tu as fait**, **ce que tu
attendais**, **ce qui s'est passé**. Une capture aide beaucoup.

Si une page renvoie une erreur, Antoine peut retrouver la trace exacte dans les
journaux du serveur — signale-lui l'heure approximative.

Merci !
