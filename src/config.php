<?php
defined('ROOT') || exit; // pas d'accès direct
// Base de données : config.php (voir config.example.php), sinon variables CARNET_DB_*, sinon SQLite.
if (is_file(ROOT . '/config.php')) require ROOT . '/config.php';
defined('DB_DRIVER') || define('DB_DRIVER', getenv('CARNET_DB') ?: 'sqlite');
defined('DB_HOST') || define('DB_HOST', getenv('CARNET_DB_HOST') ?: 'localhost');
defined('DB_PORT') || define('DB_PORT', (int)(getenv('CARNET_DB_PORT') ?: 3306));
defined('DB_NAME') || define('DB_NAME', getenv('CARNET_DB_NAME') ?: 'carnet');
defined('DB_USER') || define('DB_USER', getenv('CARNET_DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('CARNET_DB_PASS') ?: '');
// Préfixe des tables MySQL (base partagée avec d'autres projets). Ignoré en SQLite.
defined('DB_PREFIX') || define('DB_PREFIX', DB_DRIVER === 'mysql' ? (getenv('CARNET_DB_PREFIX') !== false ? getenv('CARNET_DB_PREFIX') : 'carnet_') : '');
