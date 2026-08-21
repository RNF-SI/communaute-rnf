---
description: Reprend une issue après un retour de test KO, en partant du dernier commentaire
argument-hint: <numéro d'issue>
allowed-tools: Bash(gh:*), Bash(git:*), Bash(npx jest:*), Bash(npm run:*), Edit, Read, Write, AskUserQuestion
---

Issue à reprendre : #$ARGUMENTS

Contexte :
!`gh issue view $ARGUMENTS --json title,body,labels,comments`

1. Le DERNIER commentaire de retour est ta LISTE DE TRAVAIL. Décompose-le en
   points distincts (« ce qui ne va pas » + « ce qu'il reste à corriger » sont
   souvent plusieurs items). Liste-les explicitement avant de commencer.
   Pour chaque point, décide : à corriger ici / déjà couvert / hors périmètre
   de cette issue (= nouvelle issue à créer, pas à noyer ici).
   Les anciennes notes « ↳ Fait » disent ce qui a déjà été tenté — ne refais pas
   l'ancien, traite les points encore ouverts.
2. Si le nouveau symptôme n'est pas reproductible ou la description est trop
   floue, NE corrige pas au hasard : pose-moi la question dans le terminal
   (AskUserQuestion) ; si le doute persiste, commente une demande de
   précision/étapes sur l'issue et ARRÊTE.
3. Sinon, corrige le symptôme décrit, sur `develop` (`git checkout develop && git pull`).
   Respecte le CLAUDE.md, et fais passer tout texte affiché par `translations/`,
   dans les deux langues.
4. METS À JOUR LA VISITE GUIDÉE SI C'EST NÉCESSAIRE (#39) — c'est au deuxième
   passage qu'elle décroche le plus souvent, parce qu'on renomme ce qu'on avait
   posé au premier. `src/Service/GuidedTour.php` pour les étapes,
   `pages.tour.steps.*` des deux fichiers de traduction pour le texte : sélecteur
   renommé → `target` (et `open`) ; route renommée → `route` ; élément déplacé ou
   retiré → étape déplacée ou retirée ; libellé changé → formulation. Une étape
   dont la cible n'existe plus ne casse rien de visible : elle s'affiche au
   centre, muette — c'est pour ça qu'il faut regarder. Si tu touches aux étapes,
   `tests/Controller/GuidedTourTest.php` et `assets/js/__tests__/tour.test.js`
   suivent.
5. Écris ou complète un test qui couvre CE cas précis (celui qui a régressé) —
   c'est ce qui évite un 3e aller-retour. JavaScript : `npx jest --findRelatedTests <fichiers>`.
   PHP : le test s'écrit ici mais ne s'exécute pas (projet PHP 7.3, extension
   `dom` absente) — donne la commande `./bin/phpunit …` et dis « écrit, non
   exécuté, CI ».
6. Si tu as touché `assets/` :
   `NODE_OPTIONS=--openssl-legacy-provider npm run build && git add public/build`.
7. Commit atomique : titre français à l'infinitif décrivant le nouveau symptôme
   corrigé, corps qui dit pourquoi la première correction ne suffisait pas.
8. Commente le résumé sur l'issue, garde le label `à tester`. Ne ferme pas.
8bis. Si un point du commentaire dépasse le périmètre de l'issue (demande
      nouvelle, sans rapport avec le bug d'origine), NE le code pas ici :
      signale-le et propose d'ouvrir une issue dédiée.
9. Récap : ce qui était KO, ce qui a été changé, visite guidée (mise à jour /
   rien à faire), test ajouté (passé ici / pour la CI).
