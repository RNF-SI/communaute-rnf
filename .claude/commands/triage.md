---
description: Analyse plusieurs issues et propose des lots par fichiers probablement touchés
argument-hint: <numéros d'issues séparés par espace>
allowed-tools: Bash(gh:*), Read
---

Issues à analyser : $ARGUMENTS

Pour chacune : lis-la (`gh issue view <n>`), puis devine les fichiers impactés
(en t'appuyant sur l'architecture décrite dans CLAUDE.md : entités, services,
voters, gabarits Twig, `assets/js`, `assets/css`, `translations/`).

Signale au passage les issues qui, si elles sont corrigées, obligeront à
retoucher la VISITE GUIDÉE (#39) : tout ce qui renomme un sélecteur ou une
route, déplace un élément de l'entête ou d'un onglet de groupe, ajoute ou
retire une fonctionnalité visible. Ces issues traînent deux fichiers de plus —
`src/Service/GuidedTour.php` et `pages.tour.steps.*` des traductions — donc
elles se regroupent entre elles.

Rends UNIQUEMENT un plan (ne corrige rien) :
- Lots « mêmes fichiers » → à faire en SÉQUENTIEL dans une même session
  (ex : renommages qui touchent le même fichier de traduction, ou le même
  gabarit de groupe).
- Issues indépendantes (fichiers disjoints) → PARALLÉLISABLES en worktrees.
- Issues à isoler (bac C probable, label `discussion`) → à ne pas automatiser.

Format : tableau lot / issues / fichiers estimés / visite guidée (oui-non) /
séquentiel-ou-parallèle.
