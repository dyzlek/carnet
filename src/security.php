<?php
defined('ROOT') || exit; // pas d'accès direct
// Réglages de production : erreurs, HTTPS, en-têtes de sécurité.
// Variables d'environnement (facultatives) :
//   CARNET_DEBUG=1        affiche les erreurs PHP (développement uniquement)
//   CARNET_TRUST_PROXY=1  derrière un proxy / CDN qui termine le HTTPS (en-tête X-Forwarded-Proto)
//   CARNET_DATA=/chemin   dossier de données ailleurs (idéalement hors de la racine web)

function is_debug(): bool
{
    return getenv('CARNET_DEBUG') === '1';
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    return getenv('CARNET_TRUST_PROXY') === '1' && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Erreurs : jamais affichées en production (elles révéleraient chemins et requêtes), journalisées dans data/. */
function setup_errors(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', is_debug() ? '1' : '0');
    ini_set('log_errors', '1');
    if (is_dir(DATA_DIR) && is_writable(DATA_DIR)) ini_set('error_log', DATA_DIR . '/php-errors.log');
    if (PHP_SAPI === 'cli') return;
    set_exception_handler(function (Throwable $e) {
        error_log('[carnet] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        if (is_debug()) {
            echo '<pre>' . htmlspecialchars((string)$e) . '</pre>';
            return;
        }
        echo '<!doctype html><meta charset="utf-8"><title>Erreur</title>'
            . '<p style="font:16px system-ui;margin:3rem auto;max-width:32rem">Une erreur est survenue. Réessaie dans un instant ; '
            . 'si ça continue, regarde <code>data/php-errors.log</code> sur le serveur.</p>';
    });
}

function send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow'); // carnet privé : pas d'indexation
    // Seules sources externes : les images / GIF des exercices (GitHub). Pas de script inline.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: https://raw.githubusercontent.com; font-src 'self'; connect-src 'self'; "
        . "object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    if (is_https()) header('Strict-Transport-Security: max-age=15552000');
}
