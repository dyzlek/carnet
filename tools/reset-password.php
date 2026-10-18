<?php
// Réinitialise le mot de passe d'un compte (en SSH sur le serveur, pas de « mot de passe oublié » par e-mail).
//   php tools/reset-password.php ton@email.fr
// Un mot de passe aléatoire est généré et affiché une seule fois ; change-le ensuite dans « Mon compte ».
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (empty($argv[1])) exit("Usage : php tools/reset-password.php email\n");

$root = dirname(__DIR__);
define('ROOT', $root);
define('DATA_DIR', getenv('CARNET_DATA') ?: $root . '/data');
define('DATASET_MEDIA', '');
foreach (['security', 'muscles', 'helpers', 'db', 'programs', 'repo'] as $f) require "$root/src/$f.php";

$email = strtolower(trim($argv[1]));
$user = q('SELECT id, name FROM users WHERE email = ?', [$email])->fetch();
if (!$user) exit("Aucun compte avec l'adresse $email\n");

$password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=');
q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
q('DELETE FROM login_attempts WHERE email = ?', [$email]);
echo "Mot de passe de {$user['name']} réinitialisé.\nNouveau mot de passe (affiché une seule fois) : $password\n";
