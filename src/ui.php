<?php
defined('ROOT') || exit; // pas d'accès direct
// Petits morceaux d'interface réutilisés par plusieurs pages.

// Icônes au trait (grille 24, style Lucide) — jamais d'emoji dans l'interface.
const ICONS = [
    'home' => 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
    'log' => 'M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01',
    'target' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-4a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0-4a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z',
    'dumbbell' => 'M6.5 6.5v11M17.5 6.5v11M3.5 9.5v5M20.5 9.5v5M6.5 12h11',
    'clipboard' => 'M9 3.5h6v3H9zM7 5H5.5v15.5h13V5H17M9 11.5h6M9 15.5h4',
    'scale' => 'M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm3.5 6.5a5 5 0 0 1 7 0L12 13',
    'check' => 'M5 12.5l4.5 4.5L19 7.5',
    'x' => 'M6 6l12 12M18 6 6 18',
    'more' => 'M5 12h.01M12 12h.01M19 12h.01',
    'swap' => 'M4 8h13l-3-3M20 16H7l3 3',
    'wrench' => 'M7 10h3V7L6.5 3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1-3 3l-6-6a6 6 0 0 1-8-8z',
    'wifi-off' => 'M12 18h.01M9.17 15.17a4 4 0 0 1 5.66 0M6.34 12.34a8 8 0 0 1 4.2-2.2M3.5 9.5a12 12 0 0 1 4-2.6M17.66 12.34a8 8 0 0 0-1.4-1.1M20.5 9.5a12 12 0 0 0-8.5-3.5M3 3l18 18',
    'phone' => 'M7 2h10a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM11 18h2',
    'grip' => 'M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01',
    'link' => 'M9 15l6-6M11 6l.46-.54a5 5 0 0 1 7.08 7.08l-.54.46M13 18l-.4.53a5.07 5.07 0 0 1-7.12 0 4.97 4.97 0 0 1 0-7.07l.52-.46',
    'unlink' => 'M17 22v-2M9 15l6-6M11 6l.46-.54a5 5 0 0 1 7.08 7.08l-.54.46M13 18l-.4.53a5.07 5.07 0 0 1-7.12 0 4.97 4.97 0 0 1 0-7.07l.52-.46M20 17h2M2 7h2M7 2v2',
    'arrow-right' => 'M5 12h14M13 6l6 6-6 6',
    'arrow-left' => 'M19 12H5M11 6l-6 6 6 6',
    'arrow-up' => 'M12 19V5M6 11l6-6 6 6',
    'arrow-down' => 'M12 5v14M6 13l6 6 6-6',
    'chevron-right' => 'M9 6l6 6-6 6',
    'chevron-down' => 'M6 9l6 6 6-6',
    'trophy' => 'M8 4h8v5a4 4 0 0 1-8 0zM8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4M12 13v4M8.5 20.5h7M10 17h4v3.5h-4z',
    'plus' => 'M12 5v14M5 12h14',
    'play' => 'M7 5v14l11-7z',
    'trash' => 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
    'timer' => 'M10 2h4M12 14l3-3M12 22a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z',
    'sun' => 'M12 3V2M12 22v-1M3 12H2M22 12h-1M5.6 5.6l-.7-.7M19.1 19.1l-.7-.7M5.6 18.4l-.7.7M19.1 4.9l-.7.7M12 16.5a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Z',
    'moon' => 'M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z',
    'user' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 20.5a7.5 7.5 0 0 1 15 0',
    'logout' => 'M15 4h3.5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H15M10 16l4-4-4-4M14 12H4',
    'lock' => 'M6 11h12v9.5H6zM8.5 11V8a3.5 3.5 0 0 1 7 0v3',
    // Activités : tracés des icônes Tabler (MIT, voir THIRD_PARTY.md)
    'act-walk' => 'M13 4m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0 M7 21l3 -4 M16 21l-2 -4l-3 -3l1 -6 M6 12l2 -3l4 -1l3 3l3 1',
    'act-run' => 'M13 4m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0 M4 17l5 1l.75 -1.5 M15 21l0 -4l-4 -3l1 -6 M7 12l0 -3l5 -1l3 3l3 1',
    'act-bike' => 'M5 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0 M19 18m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0 M12 19l0 -4l-3 -3l5 -4l2 3l3 0 M17 5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0',
    'act-swim' => 'M16 9m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0 M6 11l4 -2l3.5 3l-1.5 2 M3 16.75a2.4 2.4 0 0 0 1 .25a2.4 2.4 0 0 0 2 -1a2.4 2.4 0 0 1 2 -1a2.4 2.4 0 0 1 2 1a2.4 2.4 0 0 0 2 1a2.4 2.4 0 0 0 2 -1a2.4 2.4 0 0 1 2 -1a2.4 2.4 0 0 1 2 1a2.4 2.4 0 0 0 2 1a2.4 2.4 0 0 0 1 -.25',
    'act-row' => 'M6.414 6.414a2 2 0 0 0 0 -2.828l-1.414 -1.414l-2.828 2.828l1.414 1.414a2 2 0 0 0 2.828 0z M17.586 17.586a2 2 0 0 0 0 2.828l1.414 1.414l2.828 -2.828l-1.414 -1.414a2 2 0 0 0 -2.828 0z M6.5 6.5l11 11 M22 2.5c-9.983 2.601 -17.627 7.952 -20 19.5c9.983 -2.601 17.627 -7.952 20 -19.5z M6.5 12.5l5 5 M12.5 6.5l5 5',
    'act-stairs' => 'M22 5h-5v5h-5v5h-5v5h-5',
    'act-hike' => 'M3 20h18l-6.921 -14.612a2.3 2.3 0 0 0 -4.158 0l-6.921 14.612z M7.5 11l2 2.5l2.5 -2.5l2 3l2.5 -2',
    'act-rope' => 'M6 14v-6a3 3 0 1 1 6 0v8a3 3 0 0 0 6 0v-6 M16 3m0 2a2 2 0 0 1 2 -2h0a2 2 0 0 1 2 2v3a2 2 0 0 1 -2 2h0a2 2 0 0 1 -2 -2z M4 14m0 2a2 2 0 0 1 2 -2h0a2 2 0 0 1 2 2v3a2 2 0 0 1 -2 2h0a2 2 0 0 1 -2 -2z',
    'act-ball' => 'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0 M12 7l4.76 3.45l-1.76 5.55h-6l-1.76 -5.55z M12 7v-4m3 13l2.5 3m-.74 -8.55l3.74 -1.45m-11.44 7.05l-2.56 2.95m.74 -8.55l-3.74 -1.45',
    'act-yoga' => 'M12 4m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0 M4 20h4l1.5 -3 M17 20l-1 -5h-5l1 -7 M4 10l4 -1l4 -1l4 1.5l4 1.5',
    'act-pulse' => 'M19.5 13.572l-7.5 7.428l-2.896 -2.868m-6.117 -8.104a5 5 0 0 1 9.013 -3.022a5 5 0 1 1 7.5 6.572 M3 13h2l2 3l2 -6l1 3h3',
    'act-treadmill' => 'M10 3a1 1 0 1 0 2 0a1 1 0 0 0 -2 0 M3 14l4 1l.5 -.5 M12 18v-3l-3 -2.923l.75 -5.077 M6 10v-2l4 -1l2.5 2.5l2.5 .5 M21 22a1 1 0 0 0 -1 -1h-16a1 1 0 0 0 -1 1 M18 21l1 -11l2 -1',
    'act-bolt' => 'M13 3l0 7l6 0l-8 11l0 -7l-6 0l8 -11',
    'route' => 'M3 19a2 2 0 1 0 4 0a2 2 0 0 0 -4 0 M19 7a2 2 0 1 0 0 -4a2 2 0 0 0 0 4z M11 19h5.5a3.5 3.5 0 0 0 0 -7h-8a3.5 3.5 0 0 1 0 -7h4.5',
    'flame' => 'M12 12c2 -2.96 0 -7 -1 -8c0 3.038 -1.773 4.741 -3 6c-1.226 1.26 -2 3.24 -2 5a6 6 0 1 0 12 0c0 -1.532 -1.056 -3.94 -2 -5c-1.786 3 -2.791 3 -4 2z',
    'clock' => 'M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0 M12 7v5l3 3',
    'zoom-in' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM20 20l-4-4M11 8v6M8 11h6',
    'zoom-out' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM20 20l-4-4M8 11h6',
    'pause' => 'M7 5h3v14H7zM14 5h3v14h-3z',
    'download' => 'M12 4v11M7 10l5 5 5-5M5 20h14',
];

function icon(string $name, string $class = 'icon'): string
{
    $d = ICONS[$name] ?? '';
    return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $d . '"/></svg>';
}

/**
 * Jonction entre deux exercices consécutifs : « Lier en superset » ou « Délier ».
 * $attrs porte la cible (data-ss-toggle en séance, name/value du formulaire dans un programme).
 */
function ss_joint(bool $linked, string $attrs, ?int $restAfter = null): string
{
    $type = str_contains($attrs, 'name=') ? 'submit' : 'button';
    $rest = $restAfter !== null
        ? '<span class="joint-rest" title="Repos avant l\'exercice suivant">' . icon('timer') . 'Repos ' . fmt_clock($restAfter) . '</span>'
        : ($linked && !str_contains($attrs, 'name=') ? '<span class="joint-rest is-chain">Sans repos</span>' : '');
    return '<div class="ss-joint' . ($linked ? ' is-linked' : '') . '">' . $rest
        . '<button type="' . $type . '" class="ss-btn" ' . $attrs . '>'
        . icon($linked ? 'unlink' : 'link') . ($linked ? 'Délier' : 'Lier en superset') . '</button></div>';
}

/** Poignée de glisser-déposer (gérée par sortable() dans app.js). */
function drag_handle(string $label): string
{
    return '<button type="button" class="drag-handle" data-drag aria-label="' . h($label) . '" title="' . h($label) . '">' . icon('grip') . '</button>';
}

/** Lien "Voir plus" avec flèche. */
function more_link(string $href, string $label): string
{
    return '<a class="more-link" href="' . h($href) . '">' . h($label) . icon('arrow-right') . '</a>';
}

/** Vignette cliquable qui ouvre l'animation en grand (visionneuse). */
function thumb_zoom(array $e, string $id, string $class = 'thumb'): string
{
    if (empty($e['image'])) return thumb($e, $class);
    return '<button type="button" class="zoomable" data-viewer="' . h($id) . '" aria-label="Voir l’animation en grand : ' . h(ex_name($e)) . '">'
        . thumb($e, $class) . '<span class="zoom-badge" aria-hidden="true">' . icon('zoom-in') . '</span></button>';
}

function thumb(array $e, string $class = 'thumb'): string
{
    $src = media_url($e['image'] ?? null);
    if (!$src) return '<span class="' . $class . ' thumb-empty">' . h(mb_initial(ex_name($e))) . '</span>';
    return '<img class="' . $class . '" src="' . h($src) . '" alt="" loading="lazy" width="56" height="56">';
}

function mb_initial(string $s): string
{
    return strtoupper(substr($s, 0, 1));
}

/** Zone de volume hebdo : none / low / good / high. */
function volume_zone(float $setsPerWeek): string
{
    return match (true) {
        $setsPerWeek <= 0 => 'none',
        $setsPerWeek < WEEKLY_LOW => 'low',
        $setsPerWeek <= WEEKLY_HIGH => 'good',
        default => 'high',
    };
}

/** Grille "carte de chaleur" des muscles, par région. */
function muscle_heat(array $stats, int $days, bool $showCardio = false): string
{
    $html = '<div class="heat">';
    foreach (REGIONS as $rk => $rlabel) {
        if ($rk === 'cardio' && !$showCardio) continue;
        $html .= '<div class="heat-region"><h4>' . h($rlabel) . '</h4><div class="heat-grid">';
        foreach (MUSCLES as $mk => [$label, $region]) {
            if ($region !== $rk) continue;
            $s = $stats[$mk]['sets'] ?? 0;
            $perWeek = $s * 7 / max(1, $days);
            $heat = min(1, $perWeek / WEEKLY_HIGH);
            $zone = volume_zone($perWeek);
            $html .= '<a class="heat-cell z-' . $zone . '" style="--heat:' . round($heat, 2) . '" href="?p=exercises&muscle=' . h($mk) . '" title="' . h($label) . ' : ' . fmt_num($s) . ' séries">'
                . '<span class="heat-name">' . h($label) . '</span>'
                . '<span class="heat-val">' . ($s ? fmt_num($s) : '–') . '</span></a>';
        }
        $html .= '</div></div>';
    }
    return $html . '</div>';
}

function zone_legend(): string
{
    return '<div class="legend"><span class="lg z-low"></span>&lt; ' . WEEKLY_LOW . ' séries/sem.'
        . '<span class="lg z-good"></span>' . WEEKLY_LOW . '–' . WEEKLY_HIGH
        . '<span class="lg z-high"></span>&gt; ' . WEEKLY_HIGH
        . '<span class="muted">· principal = 1 série, secondaire = ½</span></div>';
}

/** Une ligne de série dans une séance. */
function set_row(array $s, ?array $prev, ?array $te): string
{
    $repsPh = $prev ? (string)$prev['reps'] : ($te && $te['reps_max'] ? (string)$te['reps_max'] : '');
    $prevTxt = $prev ? fmt_num($prev['weight']) . '×' . $prev['reps'] : '–';
    $done = (int)$s['done'] === 1;
    return '<div class="set-row' . ($done ? ' is-done' : '') . '" data-set="' . (int)$s['id'] . '">'
        . '<span class="set-n">' . (int)$s['set_number'] . '</span>'
        . '<span class="set-prev" title="Séance précédente">' . h($prevTxt) . '</span>'
        . '<input class="set-in" name="weight" inputmode="decimal" autocomplete="off" aria-label="Charge (kg)" value="' . h(num_in($s['weight'])) . '" placeholder="' . h($prev ? num_in($prev['weight']) : 'kg') . '">'
        . '<input class="set-in" name="reps" inputmode="numeric" autocomplete="off" aria-label="Répétitions" value="' . h($s['reps'] ?? '') . '" placeholder="' . h($repsPh) . '">'
        . '<button type="button" class="set-check" aria-label="Série faite" aria-pressed="' . ($done ? 'true' : 'false') . '">' . icon('check') . '</button>'
        . '<button type="button" class="set-del" aria-label="Supprimer la série">' . icon('x') . '</button>'
        . '</div>';
}

function equipment_label(?string $eq): string
{
    return EQUIPMENT_FR[$eq ?? ''] ?? (string)$eq;
}

function body_part_label(?string $bp): string
{
    return BODY_PARTS_FR[$bp ?? ''] ?? (string)$bp;
}

/** Valeur (séries) d'une forme du schéma ; 'dos' = dorsaux + haut du dos (une seule zone sur le dessin). */
function body_shape_value(?string $key, array $stats): float
{
    if ($key === null) return 0;
    if ($key === 'dos') return max($stats['dorsaux']['sets'] ?? 0, $stats['haut_dos']['sets'] ?? 0);
    return $stats[$key]['sets'] ?? 0;
}

function body_shape_label(string $key, array $stats): string
{
    if ($key === 'dos') {
        return 'Dos : dorsaux ' . fmt_num($stats['dorsaux']['sets'] ?? 0) . ', haut du dos ' . fmt_num($stats['haut_dos']['sets'] ?? 0) . ' séries';
    }
    return muscle_label($key) . ' : ' . fmt_num($stats[$key]['sets'] ?? 0) . ' séries';
}

/** Silhouette face + dos, chaque muscle coloré selon son volume sur la période. */
function body_map(array $stats, int $days, bool $compact = false): string
{
    $html = '<div class="body-map' . ($compact ? ' is-compact' : '') . '">';
    foreach (['front' => 'Face', 'back' => 'Dos'] as $side => $caption) {
        $html .= '<figure class="body-side"><svg viewBox="0 0 100 200" role="img" aria-label="Muscles travaillés, vue de ' . strtolower($caption) . '">';
        foreach (BODY_SHAPES[$side] as [$key, $points]) {
            if ($key === null) {
                $html .= '<polygon class="bm-base" points="' . $points . '"/>';
                continue;
            }
            $sets = body_shape_value($key, $stats);
            $perWeek = $sets * 7 / max(1, $days);
            $zone = volume_zone($perWeek);
            $heat = round(min(1, $perWeek / WEEKLY_HIGH), 2);
            $link = $key === 'dos' ? 'dorsaux' : $key;
            $html .= '<a href="?p=exercises&amp;muscle=' . $link . '" data-m="' . $key . '">'
                . '<polygon class="bm z-' . $zone . '" style="--heat:' . $heat . '" points="' . $points . '">'
                . '<title>' . h(body_shape_label($key, $stats)) . '</title></polygon></a>';
        }
        $html .= '</svg><figcaption>' . $caption . '</figcaption></figure>';
    }
    return $html . '</div>';
}

/** Classement des muscles avec la zone conseillée (10–20 séries / semaine) rapportée à la période. */
function muscle_bars(array $stats, int $days, int $limit = 0): string
{
    $rows = array_filter($stats, fn($s, $k) => $k !== 'cardio' && $s['sets'] > 0, ARRAY_FILTER_USE_BOTH);
    if (!$rows) return '<p class="empty">Aucune série enregistrée sur la période.</p>';
    if ($limit) $rows = array_slice($rows, 0, $limit, true);
    $factor = $days / 7;
    $max = max(WEEKLY_HIGH * 1.25 * $factor, max(array_column($rows, 'sets')));
    $bandL = round(WEEKLY_LOW * $factor / $max * 100, 2);
    $bandW = round((WEEKLY_HIGH - WEEKLY_LOW) * $factor / $max * 100, 2);
    $html = '<ol class="mbars" style="--band-l:' . $bandL . '%;--band-w:' . $bandW . '%">';
    foreach ($rows as $k => $s) {
        $zone = volume_zone($s['sets'] / $factor);
        $key = in_array($k, ['dorsaux', 'haut_dos'], true) ? 'dos' : $k;
        $html .= '<li data-m="' . $key . '"><a href="?p=exercises&amp;muscle=' . $k . '">'
            . '<span class="mb-name" title="' . h(muscle_label($k)) . '">' . h(muscle_label($k)) . '</span>'
            . '<span class="mb-track"><span class="mb-fill z-' . $zone . '" style="width:' . round($s['sets'] / $max * 100, 1) . '%"></span></span>'
            . '<span class="mb-val">' . fmt_num($s['sets']) . '</span></a></li>';
    }
    return $html . '</ol>';
}
