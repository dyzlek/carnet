<?php
defined('ROOT') || exit; // pas d'accès direct
function h($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Nombre "français" : 82,5 / 1 250. Supprime les décimales inutiles. */
function fmt_num($n, int $dec = 1): string
{
    if ($n === null || $n === '') return '–';
    $s = number_format((float)$n, $dec, ',', ' ');
    if ($dec > 0 && str_contains($s, ',')) $s = rtrim(rtrim($s, '0'), ',');
    return $s;
}

/** Nombre pour un champ de formulaire (point décimal, sans zéros inutiles). */
function num_in($n): string
{
    if ($n === null || $n === '') return '';
    $s = rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.');
    return $s;
}

function parse_num($v): ?float
{
    if ($v === null) return null;
    $v = trim(str_replace(',', '.', (string)$v));
    return $v === '' || !is_numeric($v) ? null : (float)$v;
}

const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
const JOURS_LONG = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const MOIS_LONG = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

function fmt_date(?string $d, bool $withDay = true, bool $withTime = false): string
{
    if (!$d) return '–';
    $t = strtotime($d);
    $s = ($withDay ? JOURS[(int)date('w', $t)] . ' ' : '') . date('j', $t) . ' ' . MOIS[(int)date('n', $t) - 1];
    if (date('Y', $t) !== date('Y')) $s .= ' ' . date('Y', $t);
    if ($withTime) $s .= ' · ' . date('H\hi', $t);
    return $s;
}

function fmt_short_date(?string $d): string
{
    if (!$d) return '–';
    $t = strtotime($d);
    return date('j', $t) . ' ' . MOIS[(int)date('n', $t) - 1];
}

/** "il y a 3 j" */
function ago(?string $d): string
{
    if (!$d) return 'jamais';
    $days = (int)floor((strtotime(date('Y-m-d')) - strtotime(substr($d, 0, 10))) / 86400);
    return match (true) {
        $days <= 0 => "aujourd'hui",
        $days === 1 => 'hier',
        default => "il y a $days j",
    };
}

function fmt_duration(?string $start, ?string $end): string
{
    if (!$start || !$end) return '–';
    $min = max(0, (int)round((strtotime($end) - strtotime($start)) / 60));
    return $min >= 60 ? intdiv($min, 60) . ' h ' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT) : $min . ' min';
}

function fmt_rest(?int $min, ?int $max): string
{
    if ($min === null && $max === null) return '–';
    $f = function (int $s): string {
        if ($s < 60) return $s . ' s';
        return intdiv($s, 60) . ' min' . ($s % 60 ? ' ' . ($s % 60) : '');
    };
    if ($min === $max || $min === null) return $f((int)$max);
    return $min . ' à ' . $f((int)$max);
}

function fmt_rpe($min, $max): string
{
    if ($min === null && $max === null) return '';
    $min ??= $max; $max ??= $min;
    return (float)$min === (float)$max ? fmt_num($max) : fmt_num($min) . '–' . fmt_num($max);
}

function fmt_reps(?int $min, ?int $max): string
{
    if (!$min && !$max) return '–';
    if ($min === $max || !$min) return (string)$max;
    return "$min – $max";
}

function media_url(?string $path): string
{
    if (!$path) return '';
    return preg_match('#^https?://#', $path) ? $path : DATASET_MEDIA . $path;
}

function ex_name(array $e): string
{
    $n = $e['name_fr'] ?? null;
    return $n ?: ucfirst((string)$e['name']);
}

function setting(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT value FROM user_settings WHERE user_id = ? AND key = ?');
    $st->execute([uid(), $key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $key, ?string $value): void
{
    db()->prepare('INSERT INTO user_settings (user_id, key, value) VALUES (?, ?, ?) ON CONFLICT(user_id, key) DO UPDATE SET value = excluded.value')
        ->execute([uid(), $key, $value]);
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
