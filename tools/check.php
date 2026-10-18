<?php
// Vérifie qu'une installation de Carnet est prête et sûre.
//   php tools/check.php                         → prérequis PHP, droits d'écriture, base
//   php tools/check.php https://ton-site.fr/    → + test depuis l'extérieur : fichiers sensibles bloqués, en-têtes, HTTPS
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$base = $argv[1] ?? null;
$fails = 0;
$warns = 0;
$line = function (string $status, string $msg) use (&$fails, &$warns) {
    if ($status === 'FAIL') $fails++;
    if ($status === 'WARN') $warns++;
    echo str_pad("[$status]", 7) . $msg . PHP_EOL;
};

echo "== Prérequis serveur" . PHP_EOL;
$line(version_compare(PHP_VERSION, '8.1.0', '>=') ? 'OK' : 'FAIL', 'PHP ' . PHP_VERSION . ' (8.1 minimum)');
foreach (['pdo_sqlite', 'json', 'session'] as $ext) {
    $line(extension_loaded($ext) ? 'OK' : 'FAIL', "extension $ext");
}
$root = dirname(__DIR__);
$data = getenv('CARNET_DATA') ?: $root . '/data';
$line(is_dir($data) && is_writable($data) ? 'OK' : 'FAIL', "dossier de données accessible en écriture : $data");
$line(is_file($data . '/exercises.json') || is_file($data . '/muscu.sqlite') ? 'OK' : 'FAIL', 'data/exercises.json présent (nécessaire au premier lancement)');
$line(is_file($root . '/.htaccess') ? 'OK' : 'WARN', '.htaccess présent (Apache) — sous nginx, voir DEPLOIEMENT.md');
$line(is_file($data . '/.htaccess') ? 'OK' : 'WARN', 'data/.htaccess présent');
$line(ini_get('display_errors') && getenv('CARNET_DEBUG') !== '1' ? 'WARN' : 'OK', 'display_errors (forcé à Off par l\'app de toute façon)');

if (extension_loaded('pdo_sqlite')) {
    try {
        define('ROOT', $root);
        define('DATA_DIR', $data);
        define('DATASET_MEDIA', '');
        require $root . '/src/security.php';
        require $root . '/src/muscles.php';
        require $root . '/src/helpers.php';
        require $root . '/src/db.php';
        require $root . '/src/programs.php';
        require $root . '/src/repo.php';
        $pdo = db();
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        $line('OK', 'base SQLite ouverte, schéma v' . $v . ', ' . $pdo->query('SELECT COUNT(*) FROM exercises')->fetchColumn() . ' exercices, '
            . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . ' compte(s)');
        $line($pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok' ? 'OK' : 'FAIL', 'intégrité de la base');
        $reg = $pdo->query("SELECT value FROM settings WHERE key = 'registration'")->fetchColumn();
        $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $line($users > 0 && $reg === 'open' ? 'WARN' : 'OK', $users === 0 ? 'aucun compte : crée le tien tout de suite après la mise en ligne'
            : 'inscriptions ' . ($reg === 'open' ? 'OUVERTES (pense à les refermer)' : 'fermées'));
    } catch (Throwable $e) {
        $line('FAIL', 'base : ' . $e->getMessage());
    }
}

if ($base) {
    $base = rtrim($base, '/') . '/';
    echo PHP_EOL . "== Test depuis l'extérieur : $base" . PHP_EOL;
    $get = function (string $url) {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10, 'header' => "User-Agent: carnet-check\r\n"],
                                      'ssl' => ['verify_peer' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $headers = $http_response_header ?? [];
        $code = 0;
        if ($headers && preg_match('#HTTP/\S+\s+(\d{3})#', $headers[0], $m)) $code = (int)$m[1];
        $h = [];
        foreach (array_slice($headers, 1) as $hl) {
            if (str_contains($hl, ':')) { [$k, $v] = explode(':', $hl, 2); $h[strtolower(trim($k))] = trim($v); }
        }
        return [$code, $h, (string)$body];
    };
    $secret = ['data/muscu.sqlite', 'data/exercises.json', 'data/php-errors.log', 'data/sessions/', 'data/backups/', 'src/db.php', 'src/auth.php',
               'pages/login.php', 'tools/check.php', '.claude/JOURNAL.md', '.htaccess', 'README.md', 'DEPLOIEMENT.md', 'router.php', 'start.bat', '.git/config', '.env'];
    foreach ($secret as $path) {
        [$code] = $get($base . $path);
        $line(in_array($code, [403, 404], true) ? 'OK' : 'FAIL', "bloqué : $path → HTTP $code");
    }
    [$code, $h, $body] = $get($base . '?p=login');
    $line($code === 200 && (str_contains($body, 'Connexion') || str_contains($body, 'Créer un compte')) ? 'OK' : 'FAIL', "page de connexion → HTTP $code");
    foreach (['content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy'] as $hk) {
        $line(isset($h[$hk]) ? 'OK' : 'FAIL', "en-tête $hk");
    }
    $line(str_contains($h['set-cookie'] ?? '', 'HttpOnly') ? 'OK' : 'WARN', 'cookie de session HttpOnly');
    if (str_starts_with($base, 'https://')) {
        $line(isset($h['strict-transport-security']) ? 'OK' : 'WARN', 'HSTS (si absent derrière un proxy : CARNET_TRUST_PROXY=1)');
        $line(stripos($h['set-cookie'] ?? 'secure', 'secure') !== false ? 'OK' : 'FAIL', 'cookie de session Secure');
        [$c2, $h2] = $get(preg_replace('#^https://#', 'http://', $base) . '?p=login');
        $line(in_array($c2, [301, 302, 307, 308], true) && str_starts_with($h2['location'] ?? '', 'https://') ? 'OK' : 'WARN',
            "http:// redirige vers https:// (HTTP $c2)");
    } else {
        $line('WARN', 'site testé en http:// : active HTTPS (Let\'s Encrypt) avant de t\'y connecter depuis l\'extérieur');
    }
    foreach (['assets/app.css', 'assets/app.js', 'assets/vendor/chart.umd.min.js', 'assets/fonts/geist-latin.woff2'] as $asset) {
        [$code] = $get($base . $asset);
        $line($code === 200 ? 'OK' : 'FAIL', "fichier public : $asset → HTTP $code");
    }
}

echo PHP_EOL . ($fails ? "$fails problème(s) bloquant(s)" : 'Aucun problème bloquant') . ($warns ? ", $warns avertissement(s)" : '') . PHP_EOL;
exit($fails ? 1 : 0);
