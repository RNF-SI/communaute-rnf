# Guide de résolution des problèmes de permissions en production

Ce document décrit les étapes nécessaires pour résoudre les problèmes d'affichage des images de profil et autres erreurs de permissions lors du déploiement de l'application Communauté RNF en production.

## Problème identifié

L'application utilise un pool PHP-FPM dédié (`communaute`) qui s'exécute avec l'utilisateur `geonatureadmin`, mais les fichiers et répertoires créés par défaut appartiennent à `www-data`. Cela provoque des erreurs de permissions lors de :
- L'upload et l'affichage des images de profil
- La génération des thumbnails par LiipImagineBundle
- L'écriture dans les index de recherche TNTSearch

## Solution : Alignement des permissions

### 1. Identifier l'utilisateur PHP-FPM

Vérifiez d'abord sous quel utilisateur s'exécute votre pool PHP-FPM :

```bash
# Voir les processus PHP-FPM actifs
ps aux | grep php-fpm | grep -v grep

# Vérifier la configuration du pool
sudo grep -E "^(user|group)" /etc/php/7.3/fpm/pool.d/communaute.conf
```

Dans notre cas : `user = geonatureadmin` et `group = geonatureadmin`

### 2. Corriger les permissions des répertoires

#### Répertoires de fichiers uploadés

```bash
# Changer le propriétaire pour l'utilisateur PHP-FPM
sudo chown -R geonatureadmin:www-data /var/www/html/communaute/var/files/

# Définir les permissions appropriées
sudo chmod -R 755 /var/www/html/communaute/var/files/
sudo find /var/www/html/communaute/var/files/ -type d -exec chmod 755 {} \;
sudo find /var/www/html/communaute/var/files/ -type f -exec chmod 644 {} \;
```

#### Répertoires de cache des images

```bash
# Changer le propriétaire du cache
sudo chown -R geonatureadmin:www-data /var/www/html/communaute/public/media/cache/

# Créer la structure nécessaire pour LiipImagine
sudo -u geonatureadmin mkdir -p /var/www/html/communaute/public/media/cache/resolve/{avatar,profile,logo,cover,cover_teaser,article_cover}

# Définir les permissions
sudo chmod -R 775 /var/www/html/communaute/public/media/cache/
```

#### Index de recherche TNTSearch

```bash
# Corriger les permissions des index
sudo chown -R geonatureadmin:www-data /var/www/html/communaute/public/media/cache/indexes/
sudo chmod -R 775 /var/www/html/communaute/public/media/cache/indexes/
sudo chmod 664 /var/www/html/communaute/public/media/cache/indexes/*.index
```

### 3. Configuration PHP-FPM

Un problème critique identifié : la directive `allow_url_fopen` était désactivée dans le pool PHP-FPM.

```bash
# Éditer la configuration du pool
sudo nano /etc/php/7.3/fpm/pool.d/communaute.conf

# Modifier ou commenter cette ligne :
# php_admin_flag[allow_url_fopen] = off
# Remplacer par :
php_admin_flag[allow_url_fopen] = on

# Redémarrer PHP-FPM
sudo systemctl restart php7.3-fpm
```

### 4. Appliquer le bit setgid (optionnel mais recommandé)

Pour éviter les problèmes futurs, appliquez le bit setgid pour que les nouveaux fichiers héritent du groupe :

```bash
sudo chmod g+s /var/www/html/communaute/var/files/users
sudo chmod g+s /var/www/html/communaute/var/files/groups
sudo chmod g+s /var/www/html/communaute/public/media/cache/indexes
```

### 5. Nettoyer les caches

Après avoir corrigé les permissions :

```bash
# Vider le cache Symfony
sudo -u geonatureadmin php /var/www/html/communaute/bin/console cache:clear --env=prod

# Nettoyer le cache des images
sudo -u geonatureadmin php /var/www/html/communaute/bin/console liip:imagine:cache:remove --env=prod

# Reconstruire les index de recherche si nécessaire
sudo -u geonatureadmin php /var/www/html/communaute/bin/console search:reindex:all --env=prod
```

## Scripts de diagnostic

Deux scripts ont été créés pour faciliter le diagnostic des problèmes :

### check_image_permissions.php

Ce script vérifie :
- La configuration PHP (allow_url_fopen, extensions)
- Les permissions des répertoires critiques
- L'utilisateur et le groupe des processus PHP

Utilisation :
```bash
sudo -u geonatureadmin php /var/www/html/communaute/check_image_permissions.php
```

### check_liip_imagine.php

Ce script vérifie spécifiquement :
- La structure des répertoires de cache LiipImagine
- Les handlers d'images disponibles (Imagick, GD)
- La capacité d'écriture dans les répertoires de cache

Utilisation :
```bash
sudo -u geonatureadmin php /var/www/html/communaute/check_liip_imagine.php
```

## Résumé des points clés

1. **Utilisateur PHP-FPM** : S'assurer que tous les fichiers appartiennent à l'utilisateur qui exécute PHP-FPM (dans notre cas `geonatureadmin`)
2. **Groupe www-data** : Utiliser `www-data` comme groupe pour permettre l'accès par le serveur web
3. **Permissions** : 
   - Répertoires : 755 ou 775
   - Fichiers : 644 ou 664
4. **allow_url_fopen** : Doit être activé dans la configuration PHP-FPM pour le traitement des images
5. **Structure des répertoires** : Créer tous les sous-répertoires nécessaires avant utilisation

## Commande de vérification rapide

Pour vérifier rapidement si tout est correctement configuré :

```bash
# Tester l'écriture dans tous les répertoires critiques
for dir in /var/www/html/communaute/var/files/users /var/www/html/communaute/public/media/cache/resolve /var/www/html/communaute/public/media/cache/indexes; do
    echo "Testing $dir:"
    sudo -u geonatureadmin touch "$dir/test.txt" 2>&1 && echo "  ✓ Write OK" && sudo rm "$dir/test.txt" || echo "  ✗ Write FAILED"
done
```

Cette commande teste la capacité d'écriture de l'utilisateur PHP-FPM dans tous les répertoires critiques.