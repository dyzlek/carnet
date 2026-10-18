<?php
defined('ROOT') || exit; // pas d'accès direct
// Activités cardio / sport : types, durées, allures, calories, objectif hebdomadaire.

/*
 * clé => [libellé, icône, MET (intensité modérée), distance utile ?, affichage du rythme]
 * rythme : 'pace' (min/km), 'speed' (km/h), 'pace500' (rameur, /500 m), 'pace100' (natation, /100 m), null
 * MET : valeurs moyennes du Compendium of Physical Activities (Ainsworth et al.).
 */
const ACTIVITY_TYPES = [
    'marche'      => ['Marche', 'act-walk', 3.5, true, 'pace'],
    'course'      => ['Course', 'act-run', 9.8, true, 'pace'],
    'tapis'       => ['Tapis de course', 'act-treadmill', 9.0, true, 'pace'],
    'velo'        => ['Vélo', 'act-bike', 7.5, true, 'speed'],
    'velo_appart' => ["Vélo d'appartement", 'act-bike', 6.8, true, 'speed'],
    'rameur'      => ['Rameur', 'act-row', 7.0, true, 'pace500'],
    'natation'    => ['Natation', 'act-swim', 7.0, true, 'pace100'],
    'randonnee'   => ['Randonnée', 'act-hike', 6.0, true, 'pace'],
    'elliptique'  => ['Elliptique', 'act-pulse', 5.0, false, null],
    'stepper'     => ['Stepper / escaliers', 'act-stairs', 9.0, false, null],
    'corde'       => ['Corde à sauter', 'act-rope', 11.0, false, null],
    'hiit'        => ['HIIT', 'act-bolt', 8.0, false, null],
    'sport'       => ['Sport collectif', 'act-ball', 7.0, false, null],
    'yoga'        => ['Yoga / mobilité', 'act-yoga', 2.5, false, null],
    'autre'       => ['Autre', 'plus', 5.0, false, null],
];

const INTENSITIES = [1 => 'Léger', 2 => 'Modéré', 3 => 'Intense'];
const INTENSITY_MET = [1 => 0.75, 2 => 1.0, 3 => 1.35];
// Objectif OMS : 150 min d'activité modérée / semaine ; une minute intense compte double, une minute légère pour moitié.
const ACTIVE_GOAL_MIN = 150;
const INTENSITY_WEIGHT = [1 => 0.5, 2 => 1.0, 3 => 2.0];

function activity_type(string $key): array
{
    return ACTIVITY_TYPES[$key] ?? ACTIVITY_TYPES['autre'];
}

/** "45" → 45 min ; "1:05:30" → h:m:s ; "38:20" → m:s ; "1h05" → 1 h 05. Retourne des secondes ou null. */
function parse_duration(?string $v): ?int
{
    $v = strtolower(trim(str_replace(',', '.', (string)$v)));
    if ($v === '') return null;
    if (preg_match('/^(\d+)\s*h\s*(\d{1,2})?\s*(?:min|m)?$/', $v, $m)) return (int)$m[1] * 3600 + (int)($m[2] ?? 0) * 60;
    if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $v, $m)) return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
    if (preg_match('/^(\d+):(\d{1,2})$/', $v, $m)) return (int)$m[1] * 60 + (int)$m[2];
    if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:min|m)?$/', $v, $m)) return (int)round((float)$m[1] * 60);
    return null;
}

/** 3930 → "1 h 05" ; 2300 → "38:20" (sous l'heure, avec secondes si présentes) ; 2700 → "45 min". */
function fmt_duration_s(?int $s): string
{
    if (!$s) return '–';
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    if ($h) return $h . ' h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
    if ($sec) return $m . ':' . str_pad((string)$sec, 2, '0', STR_PAD_LEFT);
    return $m . ' min';
}

/** Durée pour un champ de formulaire (inverse de parse_duration). */
function duration_in(?int $s): string
{
    if (!$s) return '';
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    if ($h) return $h . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$sec, 2, '0', STR_PAD_LEFT);
    return $sec ? $m . ':' . str_pad((string)$sec, 2, '0', STR_PAD_LEFT) : (string)$m;
}

function fmt_clock_s(float $s): string
{
    $s = (int)round($s);
    return intdiv($s, 60) . ':' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT);
}

/** Allure ou vitesse selon le sport : "5:12 /km", "24,5 km/h", "2:05 /500 m", "2:10 /100 m". */
function activity_rhythm(array $a): ?string
{
    $km = (float)($a['distance_km'] ?? 0);
    $s = (int)$a['duration_s'];
    if ($km <= 0 || $s <= 0) return null;
    return match (activity_type($a['type'])[4]) {
        'pace' => fmt_clock_s($s / $km) . ' /km',
        'speed' => fmt_num($km / ($s / 3600)) . ' km/h',
        'pace500' => fmt_clock_s($s / ($km * 2)) . ' /500 m',
        'pace100' => fmt_clock_s($s / ($km * 10)) . ' /100 m',
        default => null,
    };
}

/** Calories : celles saisies, sinon estimation MET × poids × heures (poids = dernière pesée, 75 kg par défaut). */
function activity_kcal(array $a, ?float $weight): array
{
    if (!empty($a['calories'])) return [(int)$a['calories'], false];
    $met = activity_type($a['type'])[2] * (INTENSITY_MET[(int)$a['intensity']] ?? 1);
    return [(int)round($met * ($weight ?: 75) * ((int)$a['duration_s'] / 3600)), true];
}

function current_weight(): ?float
{
    $w = q('SELECT weight FROM bodyweight WHERE user_id = ? ORDER BY date DESC LIMIT 1', [uid()])->fetchColumn();
    return $w === false ? null : (float)$w;
}

function owned_activity(int $id): ?array
{
    return q('SELECT * FROM activities WHERE id = ? AND user_id = ?', [$id, uid()])->fetch() ?: null;
}

/** Totaux sur [from, to[ : nombre, durée, distance, minutes actives pondérées. */
function activity_totals(string $from, string $to): array
{
    $rows = q('SELECT * FROM activities WHERE user_id = ? AND started_at >= ? AND started_at < ?', [uid(), $from, $to])->fetchAll();
    $t = ['count' => count($rows), 'seconds' => 0, 'km' => 0.0, 'active_min' => 0.0, 'kcal' => 0];
    $w = current_weight();
    foreach ($rows as $a) {
        $t['seconds'] += (int)$a['duration_s'];
        $t['km'] += (float)$a['distance_km'];
        $t['active_min'] += $a['duration_s'] / 60 * (INTENSITY_WEIGHT[(int)$a['intensity']] ?? 1);
        $t['kcal'] += activity_kcal($a, $w)[0];
    }
    return $t;
}

/** Valide et normalise un formulaire d'activité. Retourne [données, erreurs]. */
function activity_from_post(array $p): array
{
    $errors = [];
    $type = (string)($p['type'] ?? '');
    if (!isset(ACTIVITY_TYPES[$type])) $errors[] = "Choisis un type d'activité.";
    $date = (string)($p['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $date)) $errors[] = 'Date invalide.';
    $duration = parse_duration($p['duration'] ?? null);
    if (!$duration || $duration > 24 * 3600) $errors[] = 'Durée invalide (ex : 45, 38:20 ou 1:05:00).';
    $km = parse_num($p['distance'] ?? null);
    if ($km !== null && ($km < 0 || $km > 1000)) $errors[] = 'Distance invalide.';
    $intensity = (int)($p['intensity'] ?? 2);
    if (!isset(INTENSITIES[$intensity])) $intensity = 2;
    $int = fn($k, $max) => (($n = parse_num($p[$k] ?? null)) !== null && $n >= 0 && $n <= $max) ? (int)round($n) : null;
    return [[
        'type' => $type,
        'started_at' => str_replace('T', ' ', $date) . ':00',
        'duration_s' => $duration,
        'distance_km' => $km ?: null,
        'intensity' => $intensity,
        'calories' => $int('calories', 10000),
        'avg_hr' => $int('avg_hr', 250),
        'elevation_m' => $int('elevation', 10000),
        'notes' => trim((string)($p['notes'] ?? '')) ?: null,
    ], $errors];
}

/** Formulaire d'ajout / modification. */
function activity_form(?array $a, string $submit): string
{
    $type = $a['type'] ?? ($_GET['type'] ?? 'marche');
    $date = $a ? date('Y-m-d\TH:i', strtotime($a['started_at'])) : date('Y-m-d\TH:i');
    $intensity = (int)($a['intensity'] ?? 2);
    $html = '<form method="post" class="act-form">'
        . '<input type="hidden" name="action" value="save">'
        . '<fieldset class="type-pick"><legend>Activité</legend>';
    foreach (ACTIVITY_TYPES as $k => [$label, $ic, , $dist]) {
        $html .= '<label class="type-chip"><input type="radio" name="type" value="' . $k . '" data-distance="' . ($dist ? 1 : 0) . '"'
            . ($k === $type ? ' checked' : '') . ' required>' . icon($ic) . '<span>' . h($label) . '</span></label>';
    }
    $html .= '</fieldset><div class="act-fields">'
        . '<label>Date et heure <input type="datetime-local" name="date" value="' . h($date) . '" required></label>'
        . '<label>Durée <span class="hint">45 · 38:20 · 1:05:00</span><input name="duration" inputmode="text" autocomplete="off" required value="' . h(duration_in($a['duration_s'] ?? null)) . '" placeholder="45"></label>'
        . '<label class="dist-field">Distance (km) <input name="distance" inputmode="decimal" value="' . h(num_in($a['distance_km'] ?? null)) . '" placeholder="5,0"></label>'
        . '<fieldset class="seg-field"><legend>Intensité</legend><div class="seg">';
    foreach (INTENSITIES as $k => $l) {
        $html .= '<label><input type="radio" name="intensity" value="' . $k . '"' . ($k === $intensity ? ' checked' : '') . '><span>' . $l . '</span></label>';
    }
    $html .= '</div></fieldset>'
        . '<label>FC moyenne <span class="hint">bpm, optionnel</span><input name="avg_hr" inputmode="numeric" value="' . h($a['avg_hr'] ?? '') . '"></label>'
        . '<label>Dénivelé + <span class="hint">m, optionnel</span><input name="elevation" inputmode="numeric" value="' . h($a['elevation_m'] ?? '') . '"></label>'
        . '<label>Calories <span class="hint">sinon estimées</span><input name="calories" inputmode="numeric" value="' . h($a['calories'] ?? '') . '"></label>'
        . '<label class="full">Notes <input name="notes" value="' . h($a['notes'] ?? '') . '" placeholder="Parcours, sensations…"></label>'
        . '</div><button class="btn big">' . icon('check') . h($submit) . '</button></form>';
    return $html;
}
