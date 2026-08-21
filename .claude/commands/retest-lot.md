---
description: Reprend un lot d'issues après retour de test KO, chacune depuis son dernier commentaire (un commit par issue)
argument-hint: <numéros d'issues du lot, ex: "26 32 33 34">
allowed-tools: Bash(gh:*), Bash(git:*), Bash(npx jest:*), Bash(npm run:*), Edit, Read, Write, AskUserQuestion
---

Lot à reprendre après retour KO : #$ARGUMENTS

Ces issues ont été repassées en « à corriger » par le mainteneur. On les reprend
en SÉQUENTIEL dans une seule session : pour CHAQUE issue, c'est le DERNIER
commentaire (PAS le body) qui définit le travail à faire maintenant. Chaque issue
garde son propre commit et sa propre trace.

Contexte préchargé (dernier commentaire de chaque issue) :
!`for n in $ARGUMENTS; do echo "===== #$n ====="; gh issue view $n --json title,labels,comments --jq '"TITRE: \(.title)\nLABELS: \([.labels[].name]|join(", "))\nDERNIER COMMENTAIRE:\n\(.comments[-1].author.login // "—"): \(.comments[-1].body // "(aucun commentaire)")"'; echo; done`

Procédure :

1. PRÉPARE la session, une seule fois :
   - `git checkout develop && git pull`.
   - Pour chaque issue, télécharge et regarde les captures du dernier commentaire
     (beaucoup de retours sont surtout des images).
   - Identifie les fichiers visés par chaque issue et lis-les MAINTENANT.

2. Pour CHAQUE issue, DÉCOMPOSE son dernier commentaire en points distincts
   (« ce qui ne va pas » + « ce qu'il reste à corriger » = souvent plusieurs items).
   Liste-les explicitement. Pour chaque point, classe : à corriger ici / déjà
   couvert / hors périmètre (= nouvelle issue à ouvrir, pas à noyer ici). Les notes
   « ↳ Fait » disent ce qui a déjà été tenté — ne refais pas l'ancien, traite les
   points encore ouverts.

3. ÉTABLIS l'ordre de traitement à l'intérieur du lot :
   - Range les issues pour que les corrections ne se marchent pas dessus (fichiers
     partagés → séquence, jamais d'entrelacement).
   - Signale les dépendances entre issues du lot (« 32 avant 26 parce que… »).
   - Sors du flux les issues dont le retour est trop flou / non reproductible
     (elles partent à l'étape 5).

4. Pour CHAQUE issue, dans l'ordre, en boucle :
   a. Annonce en une ligne les points retenus (issus de l'étape 2) avant de coder.
   b. Corrige le symptôme décrit. Respecte le CLAUDE.md (services, voters, deux
      visibilités de groupe, Quill, `*FileManager`), et fais passer tout texte
      affiché par `translations/`, dans les DEUX langues.
   c. VISITE GUIDÉE, si c'est nécessaire (#39) : c'est au deuxième passage qu'elle
      décroche, parce qu'on renomme ce qu'on avait posé au premier. Étapes dans
      `src/Service/GuidedTour.php`, texte sous `pages.tour.steps.*` des deux
      fichiers de traduction. Sélecteur renommé → `target` (et `open`) ; route
      renommée → `route` ; élément déplacé ou retiré → étape déplacée ou retirée ;
      libellé changé → formulation. Une étape dont la cible a disparu ne casse
      rien de visible : elle s'affiche au centre, muette. Si tu touches aux
      étapes, `tests/Controller/GuidedTourTest.php` et
      `assets/js/__tests__/tour.test.js` suivent.
   d. Écris ou complète un test qui couvre CE cas précis (celui qui a régressé) —
      c'est ce qui évite un 3e aller-retour.
   e. Lance UNIQUEMENT les tests concernés :
      - JavaScript : `npx jest --findRelatedTests <fichiers>`.
      - PHP : il ne tourne pas ici (projet PHP 7.3, extension `dom` absente) —
        donne la commande `./bin/phpunit …` et note « écrit, non exécuté, CI ».
   f. Si l'issue a touché `assets/` :
      `NODE_OPTIONS=--openssl-legacy-provider npm run build && git add public/build`.
   g. Commit ATOMIQUE, un par issue : titre français à l'infinitif décrivant le
      nouveau symptôme corrigé, corps qui dit pourquoi la correction précédente
      ne suffisait pas. Ne regroupe JAMAIS plusieurs issues dans un même commit.
   h. OBLIGATOIRE — ne passe PAS à l'issue suivante sans ces deux actions, et
      vérifie qu'elles ont abouti :
      - commente le résumé de la correction sur l'issue :
        `gh issue comment <n> --body "..."` ;
      - fais repasser le label de `à corriger` à `à tester` :
        `gh issue edit <n> --remove-label "à corriger" --add-label "à tester"`.
      Si une commande échoue, corrige et relance avant de continuer.
   i. Ne FERME JAMAIS l'issue — c'est le mainteneur qui valide et ferme.
   j. Pour tout point HORS PÉRIMÈTRE repéré à l'étape 2 : ne le code pas ici.
      Ouvre une issue dédiée (`gh issue create`) et référence-la dans le
      commentaire de l'issue courante.

5. Si le retour d'une issue est trop flou / non reproductible : NE corrige pas au
   hasard. POSE-MOI d'abord la question directement dans le terminal (outil
   AskUserQuestion) au lieu de deviner.
   - Si ma réponse lève le doute → reprends l'issue dans le flux (étape 4).
   - Sinon → commente une demande de précision/étapes sur l'issue, LAISSE le label
     `à corriger` en place, et passe à la suivante.

6. À la fin du lot : une passe `npx jest --findRelatedTests` groupée sur les
   fichiers touchés, la liste des tests PHP à lancer en CI, et une relecture des
   `target` de la visite guidée touchés par le lot — vérifier qu'aucune correction
   n'en a cassé une autre.

7. Termine par un RÉCAP du lot sous forme de tableau :
   | Issue | Ce qui était KO | Fichiers | Visite guidée | Commit | Test | Commenté (oui/non) | Hors-périmètre → issue |
   La colonne « Commenté » doit être `oui` pour toute issue traitée (étape 4h) :
   une case `non` signale un travail non terminé. Puis liste les questions restées
   en suspens (étape 5) et les issues dérivées créées (étape 4j), et ARRÊTE pour
   que je fasse le point avant la suite.
