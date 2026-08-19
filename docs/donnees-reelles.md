# Travailler sur une copie des données de production

Objectif : disposer en local et sur la préproduction d'un jeu de données réaliste
— même volumétrie, mêmes groupes, mêmes discussions — **sans y transporter
l'identité des membres du réseau**.

## Ce qui est anonymisé, ce qui ne l'est pas

La commande `app:db:anonymize` remplace, pour chaque compte :

- l'adresse e-mail, par une adresse en `@example.org` (domaine réservé par la
  RFC 2606 : rien envoyé là ne peut atteindre une vraie boîte) ;
- le nom et le nom affiché, par un nom plausible tiré au sort ;
- la ville, le code postal, le pays, et les coordonnées GPS ;
- la biographie et la présentation, quand elles étaient renseignées ;
- le mot de passe, les jetons de réinitialisation et de changement d'e-mail ;
- les identifiants du compte GeoNature associé.

Sont conservés tels quels : les identifiants internes, les adhésions aux
groupes, les compétences, les dates, les rôles, et **tout le contenu rédigé** —
discussions, pages, actualités, noms de documents.

> ⚠️ Le contenu rédigé n'est pas anonymisé et **cite des personnes**. Un message
> de discussion qui commence par « Bonjour Angélique » restera tel quel. La copie
> reste donc une donnée sensible : elle ne doit pas être exposée publiquement,
> et la préproduction doit rester derrière une authentification.

Les données tirées au sort sont dérivées de l'identifiant du compte : recharger
le même dump deux fois donne les mêmes fausses identités.

## Procédure

### 1. Base de données

Récupérer un dump SQL de la production, puis :

```bash
bin/import-production-data.sh /chemin/vers/dump.sql
```

Le script recrée le schéma, charge le dump, applique les migrations que la copie
n'a pas encore, anonymise les comptes et reconstruit les index de recherche. Il
demande confirmation avant d'écraser la base, et refuse de tourner si
`APP_ENV=prod`.

**Ne jamais committer le dump.** Le garder hors du dépôt.

### 2. Garder un compte utilisable

Tous les comptes étant anonymisés, plus aucun ne correspond à un compte
GeoNature : personne ne peut se connecter par le SSO RNF. Pour garder un compte
opérationnel — typiquement le vôtre, sur la préproduction :

```bash
bin/import-production-data.sh dump.sql --keep-email vous@rnfrance.org
```

L'option est répétable. Les comptes cités gardent leur identité réelle : à
n'utiliser que pour les personnes qui ont donné leur accord.

### 3. Fichiers

Les fichiers ne sont pas dans le dump : ils vivent sur le disque, hors de la
base. Environ **520 Mo** au moment d'écrire ces lignes, répartis ainsi :

| Répertoire | Contenu | Volume |
|---|---|---|
| `var/files/groups` | documents partagés dans les groupes | ~500 Mo |
| `var/files/users` | photos de profil | ~23 Mo |
| `var/files` (racine) | visuels de la plateforme | ~2 Mo |

Depuis le serveur de production :

```bash
rsync -avz --progress \
    utilisateur@serveur:/chemin/vers/communaute-rnf/var/files/ \
    var/files/
```

Les photos de profil sont des portraits de personnes réelles : elles ne sont pas
remplacées par l'anonymisation, seul le compte auquel elles sont rattachées
change de nom. Pour ne pas les rapatrier, exclure `users/` du rsync.

Après copie, vider le cache des vignettes :

```bash
rm -rf public/media/cache/thumbnail public/media/cache/avatar
```

### 4. Vérifier

```bash
php bin/console app:db:anonymize --dry-run   # doit annoncer 0 compte à traiter
```

Et en SQL, aucun résultat attendu :

```sql
SELECT COUNT(*) FROM communaute_rnf_users WHERE email NOT LIKE '%@example.org';
SELECT COUNT(*) FROM communaute_rnf_users WHERE password <> '';
SELECT COUNT(*) FROM communaute_rnf_users WHERE rnf_id_role IS NOT NULL;
```

(À ajuster si des comptes ont été conservés avec `--keep-email`.)

## Prérequis de l'environnement

- PHP 7.3 avec `pdo_mysql`, `gd`, `intl`, `zip` et **`imagick`** — LiipImagine
  est configuré sur le pilote `imagick`, l'application ne démarre pas sans lui.
- MySQL 5.7.
- `config/platform/config.yaml`, copié depuis `default.config.yaml`.
