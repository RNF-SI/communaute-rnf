---
description: Traite une issue GitHub de bout en bout (classe, corrige, teste, commente)
argument-hint: <numéro d'issue>
allowed-tools: Bash(gh:*), Bash(git:*), Bash(npx jest:*), Bash(npm run:*), Edit, Read, Write, AskUserQuestion
---

Issue à traiter : #$ARGUMENTS

Contexte préchargé :
!`gh issue view $ARGUMENTS --json title,body,labels,comments`

Procédure :

1. CLASSE l'issue dans un bac :
   - A = comportement avec assertion vérifiable (texte/traduction, classe ou
     style CSS, ordre d'éléments, route, droit accordé ou refusé par un voter,
     valeur calculée, forme d'une réponse).
   - B = visuel / perceptuel, à valider à l'œil.
   - C = ambigu, trop large, ou label `discussion`.

1bis. IDENTIFIE le dernier commentaire de retour de test (le plus récent, signé
      par le mainteneur / KO de validation). C'est LUI qui définit le travail à
      faire MAINTENANT. L'historique sert de contexte (ce qui a déjà été tenté),
      pas de cahier des charges. Si le dernier commentaire contredit un ancien
      « ↳ Fait », c'est le dernier qui gagne : la correction précédente est
      incomplète ou a régressé.

2. Si C → NE corrige PAS tout de suite. POSE-MOI d'abord tes questions
   directement dans le terminal (outil AskUserQuestion) au lieu de trancher seul
   ou de commenter l'issue sans me consulter.
   - Si mes réponses lèvent l'ambiguïté → reclasse l'issue en A/B et reprends à
     l'étape 3.
   - Si l'ambiguïté persiste (vraie décision produit, hors de ta portée) → alors
     SEULEMENT commente tes questions précises sur l'issue
     (`gh issue comment $ARGUMENTS --body "..."`), pose le label `discussion`
     (`gh issue edit $ARGUMENTS --add-label "discussion"`), puis ARRÊTE.

3. Sinon, travaille directement sur `develop`. Assure-toi d'être à jour
   avant de commencer : `git checkout develop && git pull`.

4. Si la correction n'est pas triviale, expose d'abord un plan court
   (fichiers visés + approche) avant de coder. Respecte les conventions du
   CLAUDE.md : services pour la logique, voters pour les droits, deux
   visibilités de groupe et pas quatre, tags de documents en vocabulaire
   fermé, Quill et non CKEditor, uploads via les `*FileManager`.

5. Applique la correction. Tout texte affiché passe par `translations/`, dans
   les DEUX langues (`messages.fr.yml` et `messages.en.yml`).

6. METTRE À JOUR LA VISITE GUIDÉE SI C'EST NÉCESSAIRE (#39).
   La visite est déclarée dans `src/Service/GuidedTour.php` et rédigée sous
   `pages.tour.steps.*` des deux fichiers de traduction. Elle se règle sur le
   code sans que le code le sache : rien ne casse bruyamment, une étape dont
   la cible a disparu s'affiche au centre, muette. Vérifie donc à chaque fois :
   - un sélecteur CSS renommé ou supprimé → grep-le dans `GuidedTour.php` et
     corrige le `target` de l'étape (et `open` s'il s'agit d'un dépliant) ;
   - une route renommée ou supprimée → corrige `route` ;
   - une fonctionnalité ajoutée, déplacée ou retirée (entête, onglets d'un
     groupe, bibliothèque, paramètres) → ajoute, déplace ou retire l'étape, et
     sa traduction dans les deux langues, `link` compris quand elle change de
     page ;
   - un libellé d'interface changé → la formulation de l'étape qui le cite.
   Si tu touches aux étapes, `tests/Controller/GuidedTourTest.php` et
   `assets/js/__tests__/tour.test.js` doivent suivre. Si rien n'est à faire,
   dis-le explicitement dans le récap plutôt que de rester silencieux.

7. Tests, en ne lançant QUE le périmètre concerné :
   - JavaScript : `npx jest --findRelatedTests <fichiers modifiés>`
     (si A et front → écris/maj le test d'abord).
   - PHP : écris/maj le test dans `tests/` (si A), mais **il ne tourne pas
     ici** : le projet est en PHP 7.3 et l'extension `dom` manque sur cette
     machine, donc ni `bin/console` ni `./bin/phpunit`. Donne la commande à
     lancer (`./bin/phpunit tests/Chemin/DuTest.php`) et dis dans le récap que
     le test est écrit mais non exécuté — c'est la CI CircleCI qui l'exécute.
     Ne prétends jamais l'avoir vu passer.
   - Si B → pas de test auto ; ajoute l'étape de validation manuelle au bon
     endroit de `docs/recette.md` (numéro d'issue entre parenthèses, comme le
     reste du fichier).

8. Si tu as touché à `assets/`, recompile AVANT de commiter — `public/build/`
   est versé dans le dépôt parce que Node est absent des serveurs :
   `NODE_OPTIONS=--openssl-legacy-provider npm run build && git add public/build`

9. Commit atomique — un seul pour cette issue. Convention du dépôt, pas de
   conventional commits : titre en français, à l'infinitif, qui dit ce que le
   changement fait pour l'utilisateur (« ne plus perdre le filtre en changeant
   de page »), et un corps qui explique le pourquoi et les pièges. Préfixe
   `issue #N : ` si la lisibilité de l'historique le demande.

10. OBLIGATOIRE — ne conclus JAMAIS sans ces deux actions, et vérifie qu'elles
    ont bien réussi avant de passer à l'étape 11 :
    a. Commente le résumé de la correction sur l'issue :
       `gh issue comment $ARGUMENTS --body "..."`
    b. Pose le label `à tester` :
       `gh issue edit $ARGUMENTS --add-label "à tester"`
       (s'il n'existe pas encore :
       `gh label create "à tester" --color "0e8a16" --description "Corrigé, en attente de validation"`)
    Si l'une des deux commandes échoue, corrige et relance — ne termine pas tant
    qu'elles n'ont pas abouti.

11. Ne FERME JAMAIS l'issue — c'est moi qui valide et ferme.

12. Termine par un récap compact : bac (A/B/C), fichiers touchés, visite guidée
    (mise à jour / rien à faire), test auto (passé ici / écrit pour la CI /
    aucun), assets recompilés ou non, étapes manuelles s'il y en a, et confirme
    explicitement que le commentaire de l'étape 10 a bien été posté (l'omettre =
    travail non fini).
