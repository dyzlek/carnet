# Mettre Carnet en ligne

Testé sur Apache 2.4 + PHP 8.2 (installation dans un sous-dossier `/carnet/`) : fichiers sensibles bloqués,
comptes et données cloisonnés, en-têtes de sécurité présents.

## Prérequis

- PHP **8.1 ou plus** avec `pdo_sqlite` (présent chez quasiment tous les hébergeurs) **ou** `pdo_mysql` et une base
  MySQL / MariaDB (serveur d'école, d'IUT…) : voir « Avec une base MySQL » ci-dessous.
- Apache 2.4 (les `.htaccess` fournis protègent tout) **ou** nginx (voir plus bas).
- **HTTPS** (Let's Encrypt, gratuit chez la plupart des hébergeurs). Sans HTTPS, les mots de passe passent en clair. HTTPS est aussi requis pour installer l'app sur le téléphone et l'utiliser hors ligne.

## Avec une base MySQL (serveur de l'IUT, pas de SQLite)

Si le site affiche « L'extension PHP pdo_sqlite n'est pas disponible sur ce serveur », utilise la base MySQL fournie
(visible dans phpMyAdmin) :

1. Envoie les fichiers de Carnet comme d'habitude (étapes 1 et 2 ci-dessous).
2. Sur le serveur, à côté de `index.php`, copie `config.example.php` en **`config.php`** et remplis-le :
   `DB_HOST` = le « Serveur » affiché en haut de phpMyAdmin (souvent `localhost`), `DB_NAME` = le nom de ta base
   (colonne de gauche), `DB_USER` / `DB_PASS` = tes identifiants MySQL.
   Écris le mot de passe **uniquement dans ce fichier sur le serveur** : il n'est jamais envoyé sur GitHub.
3. Ouvre le site : les tables sont créées automatiquement dans ta base et les 1 324 exercices importés (quelques secondes).
   Elles s'appellent toutes `carnet_…` (`carnet_users`, `carnet_sets`…) : Carnet peut partager la base avec d'autres
   projets (WordPress, PrestaShop…) sans toucher à leurs tables. Préfixe modifiable avec `DB_PREFIX` dans `config.php`.
   Le dossier `data/` doit rester accessible en écriture (sessions de connexion).
4. Crée ton compte (étape 3).

Pour vérifier en SSH : `php tools/check.php https://…/` indique « base de données : MySQL » et compte les exercices.

## 1. Préparer le paquet (sur ton PC)

```
php -d extension=zip tools/build.php
```

Crée `dist/carnet-AAAAMMJJ-HHMM.zip` sans les notes de dev, sauvegardes, sessions ni ta base locale.
Pour emporter tes données actuelles (ton compte, tes séances…) : `php -d extension=zip tools/build.php --with-db`
(ce zip contient ta base : ne le partage pas).

## 2. Envoyer

Décompresse le zip et envoie le dossier `carnet/` (FTP / SFTP / gestionnaire de fichiers) :

- à la racine de ton site → `https://ton-site.fr/` (mets le contenu de `carnet/` dans `www/` ou `public_html/`) ;
- ou dans un sous-dossier → `https://ton-site.fr/carnet/`.

Le dossier `data/` doit être **accessible en écriture** par PHP (droits 755 ou 775 selon l'hébergeur ;
jamais 777 si tu peux l'éviter). Au premier passage, la base `data/muscu.sqlite` est créée et les
1 324 exercices sont importés (quelques secondes).

## 3. Créer ton compte tout de suite

Ouvre le site : la page « Créer un compte » s'affiche. **Le premier compte devient administrateur.**
Ensuite les inscriptions sont **fermées par défaut** : pour inviter quelqu'un, *Mon compte → Administration →
Ouvrir les inscriptions*, puis referme-les.

## 4. Vérifier

En SSH (ou via la console PHP de l'hébergeur) depuis le dossier de l'app :

```
php tools/check.php https://ton-site.fr/carnet/
```

Il vérifie PHP, les droits, la base, puis **depuis l'extérieur** que `data/muscu.sqlite`, `src/`, `pages/`,
les sessions, etc. renvoient bien 403/404, que les en-têtes de sécurité sont là, que HTTPS est actif et que
`http://` redirige vers `https://`. Sans SSH : ouvre `https://ton-site.fr/carnet/data/muscu.sqlite` dans ton
navigateur, ça doit afficher **Forbidden** ou **Not Found**, jamais un téléchargement.

## nginx

Les `.htaccess` sont ignorés par nginx. À ajouter dans le bloc `server` (adapter le chemin si sous-dossier) :

```nginx
root /var/www/carnet;
index index.php;

location ~ ^/(data|src|pages|tools|dist|\.claude)(/|$) { deny all; return 404; }
location ~ /\.(?!well-known/) { deny all; return 404; }
location ~ \.(md|bat|sh|sqlite|sqlite-wal|sqlite-shm|log|ini)$ { deny all; return 404; }
location = /router.php { deny all; return 404; }
# Service worker (app installable / hors ligne) : toujours revalidé
location = /sw.js { add_header Cache-Control "no-cache"; }

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}
location ~* \.(css|js|woff2|svg)$ { expires 30d; }
```

Encore mieux (VPS) : garder les données **hors de la racine web** avec la variable d'environnement
`CARNET_DATA=/var/lib/carnet` (dans le pool PHP-FPM : `env[CARNET_DATA] = /var/lib/carnet`), en y copiant `data/exercises.json`.

## Options (variables d'environnement)

| Variable | Effet |
|---|---|
| `CARNET_DATA=/chemin` | dossier des données (base, sessions, journal d'erreurs) ailleurs |
| `CARNET_TRUST_PROXY=1` | derrière Cloudflare / un reverse proxy qui fait le HTTPS : cookies `Secure` + HSTS via `X-Forwarded-Proto` |
| `CARNET_DEBUG=1` | affiche les erreurs PHP — **jamais en production** |

## Sauvegardes

Tout est dans `data/muscu.sqlite`. Sauvegarde-le régulièrement (copie du fichier, idéalement quand personne
n'écrit, ou `sqlite3 data/muscu.sqlite ".backup save.sqlite"` par une tâche cron). Chaque utilisateur peut
aussi exporter ses propres données en JSON depuis *Mon compte*.

## Mot de passe oublié

Pas d'envoi d'e-mail : en SSH, `php tools/reset-password.php ton@email.fr` génère un nouveau mot de passe
(affiché une fois), à changer ensuite dans *Mon compte*.

## Mettre à jour l'app

Refais un paquet **sans** `--with-db` et remplace les fichiers sur le serveur **sauf le dossier `data/`**
(il contient la base). Les migrations de la base s'appliquent toutes seules au premier chargement.

## Erreurs

Rien n'est affiché aux visiteurs ; tout part dans `data/php-errors.log` (non accessible depuis le web).

## Ce qui reste chargé depuis l'extérieur

Seulement les images / GIF d'exercices, depuis `raw.githubusercontent.com` (dataset hasaneyldrm/exercises-dataset).
Polices et Chart.js sont hébergés avec l'app (aucun appel à Google Fonts ni à un CDN).
