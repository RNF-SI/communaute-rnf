---
description: Traite un lot d'issues « mêmes fichiers » en séquentiel dans une seule session (un commit par issue)
argument-hint: <numéros d'issues du lot, ex: "33 34 39">
allowed-tools: Bash(gh:*), Bash(git:*), Bash(npx jest:*), Bash(npm run:*), Edit, Read, Write, AskUserQuestion
---

Lot à traiter : #$ARGUMENTS

Ces issues ont été regroupées (via /triage) parce qu'elles touchent les MÊMES
fichiers. On les traite donc en SÉQUENTIEL dans une seule session pour charger le
contexte une fois, mais chaque issue garde son propre commit et sa propre trace.

Contexte préchargé (tout le lot) :
!`for n in $ARGUMENTS; do echo "===== #$n ====="; gh issue view $n --json title,body,labels,comments --jq '"TITRE: \(.title)\nLABELS: \([.labels[].name]|join(", "))\nBODY:\n\(.body)\nCOMMENTAIRES: \(.comments|length)"'; echo; done`

Procédure :

1. PRÉPARE la session, une seule fois :
   - `git checkout develop && git pull` (on travaille directement sur `develop`).
   - Ouvre les captures des issues qui n'ont qu'une image (télécharge-les et
     regarde-les) — beaucoup de ces retours n'ont pas de texte.
   - Identifie les fichiers communs du lot et lis-les MAINTENANT (contexte partagé).

2. CLASSE chaque issue du lot dans un bac (comme /fix-issue) :
   - A = assertion vérifiable (texte/traduction, classe/style, ordre, route,
     droit accordé ou refusé, calcul).
   - B = visuel/perceptuel, à valider à l'œil.
   - C = ambigu, trop large, ou label `discussion`.
   Pour chaque issue, prends en compte le DERNIER commentaire de retour de test
   s'il existe : c'est lui qui définit le travail à faire maintenant
   (l'historique = contexte).

3. ÉTABLIS l'ordre de traitement à l'intérieur du lot :
   - Range les issues pour que les corrections ne se marchent pas dessus (ex :
     une refonte de gabarit AVANT un simple renommage dans ce gabarit).
   - Signale les dépendances entre issues du lot (« 34 doit passer avant 39 parce que… »).
   - Sors les issues bac C du flux : NE les corrige pas.

4. Pour CHAQUE issue A/B, dans l'ordre, en boucle :
   a. Si non trivial, annonce en une ligne le plan (fichiers + approche) avant de coder.
   b. Applique la correction. Respecte le CLAUDE.md : services pour la logique,
      voters pour les droits, deux visibilités de groupe et pas quatre, Quill et
      non CKEditor, uploads via les `*FileManager`, et tout texte affiché dans
      `translations/` — dans les DEUX langues.
   c. VISITE GUIDÉE, si c'est nécessaire (#39) : la visite est déclarée dans
      `src/Service/GuidedTour.php` et rédigée sous `pages.tour.steps.*` des deux
      fichiers de traduction, et elle ne se plaint jamais — une étape dont la
      cible a disparu s'affiche au centre, muette. Sélecteur renommé → `target`
      (et `open`) ; route renommée → `route` ; fonctionnalité ajoutée, déplacée
      ou retirée → étape ajoutée, déplacée ou retirée, traductions comprises ;
      libellé changé → formulation de l'étape. Si tu touches aux étapes,
      `tests/Controller/GuidedTourTest.php` et `assets/js/__tests__/tour.test.js`
      suivent. Un lot qui touche tous aux mêmes gabarits est justement le cas où
      la visite décroche sans bruit : vérifie une fois par issue, pas une fois
      pour le lot.
   d. Tests, périmètre concerné UNIQUEMENT :
      - JavaScript : `npx jest --findRelatedTests <fichiers>` (si A → test d'abord).
      - PHP : écris/maj le test, mais **il ne tourne pas ici** (projet PHP 7.3,
        extension `dom` absente : ni `bin/console` ni `./bin/phpunit`). Donne la
        commande et dis « écrit, non exécuté — CI » ; ne prétends pas l'avoir vu
        passer.
      - Si B → pas de test auto ; ajoute l'étape de validation manuelle au bon
        endroit de `docs/recette.md`, numéro d'issue entre parenthèses.
   e. Si l'issue a touché `assets/`, recompile avant de commiter :
      `NODE_OPTIONS=--openssl-legacy-provider npm run build && git add public/build`
      (`public/build/` est versé dans le dépôt, Node est absent des serveurs).
   f. Commit ATOMIQUE, un par issue : titre français à l'infinitif disant ce que
      le changement fait pour l'utilisateur, corps qui explique le pourquoi.
      Ne regroupe JAMAIS plusieurs issues dans un même commit.
   g. OBLIGATOIRE — ne passe PAS à l'issue suivante sans ces deux actions, et
      vérifie qu'elles ont abouti :
      - commente le résumé sur l'issue : `gh issue comment <n> --body "..."` ;
      - pose le label `à tester` : `gh issue edit <n> --add-label "à tester"`
        (à créer une fois s'il manque :
        `gh label create "à tester" --color "0e8a16" --description "Corrigé, en attente de validation"`).
      Si une des deux échoue, corrige et relance avant de continuer.
   h. Ne FERME JAMAIS l'issue — c'est le mainteneur qui valide et ferme.

5. Pour CHAQUE issue bac C : ne corrige pas. POSE-MOI d'abord tes questions
   directement dans le terminal (outil AskUserQuestion) au lieu de trancher seul
   ou de commenter l'issue sans me consulter.
   - Si mes réponses lèvent l'ambiguïté → reclasse l'issue en A/B et traite-la
     dans le flux (étape 4).
   - Si l'ambiguïté persiste (vraie décision produit, hors de ta portée) → alors
     SEULEMENT commente tes questions précises (`gh issue comment <n>`), pose le
     label `discussion` (`gh issue edit <n> --add-label "discussion"`), et passe
     à la suivante.

6. À la fin du lot, repasse sur les fichiers communs touchés : un seul
   `npx jest --findRelatedTests` sur l'ensemble, et la liste des tests PHP à
   lancer en CI. Vérifie qu'aucune correction n'en a cassé une autre du même lot,
   et que la visite guidée tient encore debout après toutes les modifications
   (les `target` du lot, relus une dernière fois).

7. Termine par un RÉCAP du lot sous forme de tableau :
   | Issue | Bac | Fichiers | Visite guidée | Commit | Test | Commenté (oui/non) | Statut (fait / discussion / manuel) |
   La colonne « Commenté » doit être `oui` pour toute issue A/B traitée (étape 4g) :
   une case `non` signale un travail non terminé. Puis liste les points de
   validation manuelle (bac B) et les questions posées (bac C), et ARRÊTE pour que
   je fasse le point avant le lot suivant.
