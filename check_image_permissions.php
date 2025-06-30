<?php
/**
 * Script de diagnostic pour les problèmes d'affichage des images de profil
 * À exécuter sur le serveur de production
 */

echo "=== Diagnostic des permissions et configuration ===\n\n";

// Vérifier la configuration PHP
echo "1. Configuration PHP:\n";
echo "   - allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
echo "   - PHP version: " . PHP_VERSION . "\n";
echo "   - SAPI: " . PHP_SAPI . "\n";
echo "   - User: " . get_current_user() . "\n";
echo "   - Process owner: " . posix_getpwuid(posix_geteuid())['name'] . "\n\n";

// Vérifier les extensions requises
echo "2. Extensions PHP:\n";
$extensions = ['imagick', 'gd', 'exif'];
foreach ($extensions as $ext) {
    echo "   - $ext: " . (extension_loaded($ext) ? 'LOADED' : 'NOT LOADED') . "\n";
}
echo "\n";

// Vérifier les répertoires
echo "3. Vérification des répertoires:\n";
$dirs = [
    'var/files/users',
    'var/files/groups',
    'public/media/cache',
    'public/media/cache/avatar',
    'public/media/cache/profile',
    'public/media/cache/indexes'
];

foreach ($dirs as $dir) {
    if (file_exists($dir)) {
        $perms = fileperms($dir);
        $owner = fileowner($dir);
        $group = filegroup($dir);
        $ownerInfo = posix_getpwuid($owner);
        $groupInfo = posix_getgrgid($group);
        
        echo "   - $dir:\n";
        echo "     Exists: YES\n";
        echo "     Permissions: " . sprintf('%o', $perms & 0777) . "\n";
        echo "     Owner: " . $ownerInfo['name'] . " ($owner)\n";
        echo "     Group: " . $groupInfo['name'] . " ($group)\n";
        echo "     Writable: " . (is_writable($dir) ? 'YES' : 'NO') . "\n";
    } else {
        echo "   - $dir: NOT FOUND\n";
    }
}
echo "\n";

// Vérifier un fichier image spécifique si fourni en paramètre
if ($argc > 1) {
    $imagePath = $argv[1];
    echo "4. Vérification du fichier spécifique: $imagePath\n";
    
    if (file_exists($imagePath)) {
        $perms = fileperms($imagePath);
        $owner = fileowner($imagePath);
        $group = filegroup($imagePath);
        $ownerInfo = posix_getpwuid($owner);
        $groupInfo = posix_getgrgid($group);
        
        echo "   Exists: YES\n";
        echo "   Permissions: " . sprintf('%o', $perms & 0777) . "\n";
        echo "   Owner: " . $ownerInfo['name'] . " ($owner)\n";
        echo "   Group: " . $groupInfo['name'] . " ($group)\n";
        echo "   Readable: " . (is_readable($imagePath) ? 'YES' : 'NO') . "\n";
        echo "   Size: " . filesize($imagePath) . " bytes\n";
        
        // Tenter de lire les métadonnées EXIF
        if (extension_loaded('exif') && exif_imagetype($imagePath) !== false) {
            echo "   EXIF readable: YES\n";
        } else {
            echo "   EXIF readable: NO\n";
        }
    } else {
        echo "   File NOT FOUND\n";
    }
}

echo "\n=== Recommandations ===\n";
echo "1. S'assurer que www-data (ou l'utilisateur PHP-FPM) a les droits en lecture sur var/files/\n";
echo "2. S'assurer que www-data a les droits en écriture sur public/media/cache/\n";
echo "3. Exécuter: sudo chown -R www-data:www-data var/files/ public/media/cache/\n";
echo "4. Exécuter: sudo chmod -R 755 var/files/ && sudo chmod -R 775 public/media/cache/\n";
echo "5. Vérifier la configuration PHP-FPM pour allow_url_fopen\n";