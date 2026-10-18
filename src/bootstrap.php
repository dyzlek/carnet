<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
// CARNET_DATA permet de pointer vers un autre dossier de données (tests sur une copie).
define('DATA_DIR', getenv('CARNET_DATA') ?: ROOT . '/data');
require __DIR__ . '/config.php'; // base de données (config.php à la racine, sinon SQLite)
// Les images / GIF du dataset sont servis directement depuis GitHub (1 324 fichiers, inutile de les copier).
define('DATASET_MEDIA', 'https://raw.githubusercontent.com/hasaneyldrm/exercises-dataset/main/');

date_default_timezone_set('Europe/Paris');
require __DIR__ . '/security.php';
setup_errors();
send_security_headers();

require __DIR__ . '/muscles.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/programs.php';
require __DIR__ . '/repo.php';
require __DIR__ . '/supersets.php';
require __DIR__ . '/activities.php';
require __DIR__ . '/body_shapes.php';
require __DIR__ . '/ui.php';
require __DIR__ . '/auth.php';

start_session();
