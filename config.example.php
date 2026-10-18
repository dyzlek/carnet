<?php
// Configuration de Carnet. Copie ce fichier en « config.php » (à côté de index.php) et remplis-le.
// Sans config.php, Carnet utilise SQLite (fichier data/muscu.sqlite) : rien à configurer.
// config.php n'est jamais envoyé sur GitHub (.gitignore) : le mot de passe reste sur le serveur.

// Base MySQL / MariaDB (par exemple celle fournie par l'IUT, visible dans phpMyAdmin)
define('DB_DRIVER', 'mysql');        // 'mysql' ou 'sqlite'
define('DB_HOST', 'localhost');      // « Serveur » affiché en haut de phpMyAdmin
define('DB_NAME', 'dylan.belledent'); // nom de la base, colonne de gauche de phpMyAdmin
define('DB_USER', 'dylan.belledent'); // identifiant MySQL (souvent le même que pour phpMyAdmin)
define('DB_PASS', '');               // mot de passe MySQL : à écrire uniquement sur le serveur
