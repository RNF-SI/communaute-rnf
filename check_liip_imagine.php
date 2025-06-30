<?php
/**
 * Script de diagnostic pour LiipImagineBundle
 * À exécuter sur le serveur de production
 */

echo "=== Diagnostic LiipImagineBundle ===\n\n";

// 1. Vérifier les chemins
echo "1. Vérification des chemins:\n";
$paths = [
    'Source files' => '/var/www/html/communaute/var/files/users',
    'Cache directory' => '/var/www/html/communaute/public/media/cache',
    'Web root' => '/var/www/html/communaute/public'
];

foreach ($paths as $label => $path) {
    echo "   $label: ";
    if (file_exists($path)) {
        $owner = posix_getpwuid(fileowner($path))['name'];
        $group = posix_getgrgid(filegroup($path))['name'];
        $perms = decoct(fileperms($path) & 0777);
        echo "EXISTS (owner: $owner, group: $group, perms: $perms)\n";
    } else {
        echo "NOT FOUND\n";
    }
}

// 2. Vérifier un fichier source spécifique
echo "\n2. Vérification d'un fichier source exemple:\n";
$sourceFile = '/var/www/html/communaute/var/files/users/user-3/placette_logo_72x72-3.png';
if (file_exists($sourceFile)) {
    echo "   File exists: YES\n";
    echo "   Readable by current user: " . (is_readable($sourceFile) ? 'YES' : 'NO') . "\n";
    
    // Tester la lecture en tant que geonatureadmin
    $cmd = "sudo -u geonatureadmin test -r $sourceFile && echo 'YES' || echo 'NO'";
    echo "   Readable by geonatureadmin: " . trim(shell_exec($cmd)) . "\n";
    
    // Vérifier le type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $sourceFile);
    finfo_close($finfo);
    echo "   MIME type: $mime\n";
    echo "   File size: " . filesize($sourceFile) . " bytes\n";
} else {
    echo "   Source file NOT FOUND\n";
}

// 3. Vérifier la configuration PHP pour les images
echo "\n3. Configuration PHP pour le traitement d'images:\n";
echo "   memory_limit: " . ini_get('memory_limit') . "\n";
echo "   max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "   post_max_size: " . ini_get('post_max_size') . "\n";
echo "   upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";

// 4. Vérifier les handlers d'images disponibles
echo "\n4. Handlers d'images:\n";
if (extension_loaded('imagick')) {
    $imagick = new Imagick();
    $formats = $imagick->queryFormats('PNG');
    echo "   Imagick: LOADED (PNG support: " . (in_array('PNG', $formats) ? 'YES' : 'NO') . ")\n";
} else {
    echo "   Imagick: NOT LOADED\n";
}

if (extension_loaded('gd')) {
    $gd_info = gd_info();
    echo "   GD: LOADED (PNG support: " . ($gd_info['PNG Support'] ? 'YES' : 'NO') . ")\n";
} else {
    echo "   GD: NOT LOADED\n";
}

// 5. Vérifier la structure du cache
echo "\n5. Structure du cache LiipImagine:\n";
$cacheStructure = [
    '/var/www/html/communaute/public/media/cache/resolve',
    '/var/www/html/communaute/public/media/cache/resolve/avatar',
    '/var/www/html/communaute/public/media/cache/resolve/profile'
];

foreach ($cacheStructure as $dir) {
    if (file_exists($dir)) {
        $owner = posix_getpwuid(fileowner($dir))['name'];
        $writable = is_writable($dir) ? 'YES' : 'NO';
        echo "   $dir: EXISTS (owner: $owner, writable: $writable)\n";
    } else {
        echo "   $dir: NOT EXISTS\n";
    }
}

// 6. Test de création de répertoire
echo "\n6. Test de création dans le cache:\n";
$testDir = '/var/www/html/communaute/public/media/cache/resolve/test_' . time();
$created = @mkdir($testDir, 0755, true);
if ($created) {
    echo "   Création de répertoire: SUCCESS\n";
    rmdir($testDir);
} else {
    echo "   Création de répertoire: FAILED - " . error_get_last()['message'] . "\n";
}

echo "\n=== Recommandations ===\n";
echo "1. Créer la structure de cache si manquante:\n";
echo "   sudo -u geonatureadmin mkdir -p /var/www/html/communaute/public/media/cache/resolve/{avatar,profile,logo,cover,cover_teaser,article_cover}\n";
echo "2. S'assurer que geonatureadmin possède tout le cache:\n";
echo "   sudo chown -R geonatureadmin:www-data /var/www/html/communaute/public/media/cache/\n";
echo "3. Permissions correctes:\n";
echo "   sudo chmod -R 775 /var/www/html/communaute/public/media/cache/\n";
echo "4. Vider le cache LiipImagine:\n";
echo "   sudo -u geonatureadmin php /var/www/html/communaute/bin/console liip:imagine:cache:remove --env=prod\n";
echo "5. Vérifier les logs Apache pour plus de détails sur l'erreur 500\n";