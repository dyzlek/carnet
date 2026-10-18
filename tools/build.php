<?php
// Prépare le paquet à envoyer sur le serveur : dist/carnet-AAAAMMJJ-HHMM.zip
//   php -d extension=zip tools/build.php              → sans ta base (nouvelle installation, tu recrées ton compte en ligne)
//   php -d extension=zip tools/build.php --with-db    → avec ta base actuelle (comptes, séances, activités…)
// Jamais inclus : notes de dev (.claude), sauvegardes, sessions, journaux d'erreurs, start.bat.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!class_exists('ZipArchive')) exit("Extension zip absente : lance avec  php -d extension=zip tools/build.php\n");

$root = dirname(__DIR__);
$withDb = in_array('--with-db', $argv, true);
$include = ['.htaccess', 'index.php', 'api.php', 'router.php', 'robots.txt', 'manifest.json', 'sw.js', 'config.example.php', 'README.md', 'DEPLOIEMENT.md', 'THIRD_PARTY.md',
            'assets', 'src', 'pages', 'tools', 'data/.htaccess', 'data/exercises.json'];
$exclude = '#(^|/)(\.claude|dist|backups|sessions)(/|$)|\.log$|\.sqlite-(wal|shm)$#';

if ($withDb) {
    // Intègre les écritures en attente du journal WAL dans le fichier principal avant de le copier.
    $pdo = new PDO('sqlite:' . $root . '/data/muscu.sqlite');
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo = null;
    $include[] = 'data/muscu.sqlite';
}

@mkdir($root . '/dist');
$zipPath = $root . '/dist/carnet-' . date('Ymd-Hi') . ($withDb ? '-avec-base' : '') . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) exit("Impossible de créer $zipPath\n");

$count = 0;
$add = function (string $rel) use ($zip, $root, $exclude, &$count) {
    if (preg_match($exclude, $rel)) return;
    $zip->addFile($root . '/' . $rel, 'carnet/' . $rel);
    $count++;
};
foreach ($include as $item) {
    $path = $root . '/' . $item;
    if (is_file($path)) { $add($item); continue; }
    if (!is_dir($path)) { echo "  (absent : $item)\n"; continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) $add(str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)));
    }
}
$zip->close();
printf("%d fichiers → %s (%.1f Mo)%s\n", $count, $zipPath, filesize($zipPath) / 1048576, $withDb ? "\nContient ta base : garde ce fichier privé." : '');
