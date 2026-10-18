<?php
defined('ROOT') || exit; // pas d'accès direct
// Accès aux données et calculs (dernière perf, surcharge, stats muscles, records…).

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Exercice visible par le compte : catalogue commun ou exercice perso de ce compte. */
function exercise(string $id): ?array
{
    return q('SELECT * FROM exercises WHERE id = ? AND (owner_id IS NULL OR owner_id = ?)', [$id, uid()])->fetch() ?: null;
}

// --- Contrôles d'appartenance : renvoient la ligne seulement si elle est au compte connecté. ---

function owned_workout(int $id): ?array
{
    return q('SELECT * FROM workouts WHERE id = ? AND user_id = ?', [$id, uid()])->fetch() ?: null;
}

function owned_workout_exercise(int $id): ?array
{
    return q('SELECT we.* FROM workout_exercises we JOIN workouts w ON w.id = we.workout_id
              WHERE we.id = ? AND w.user_id = ?', [$id, uid()])->fetch() ?: null;
}

function owned_set(int $id): ?array
{
    return q('SELECT s.* FROM sets s JOIN workout_exercises we ON we.id = s.workout_exercise_id JOIN workouts w ON w.id = we.workout_id
              WHERE s.id = ? AND w.user_id = ?', [$id, uid()])->fetch() ?: null;
}

/** ['primary' => [...], 'secondary' => [...]] */
function exercise_muscles(string $id): array
{
    $out = ['primary' => [], 'secondary' => []];
    foreach (q('SELECT muscle, role FROM exercise_muscles WHERE exercise_id = ?', [$id]) as $r) {
        $out[$r['role']][] = $r['muscle'];
    }
    return $out;
}

function muscles_text(array $keys): string
{
    return implode(', ', array_map('muscle_label', $keys));
}

/** Estimation du 1RM (Epley). */
function e1rm(?float $w, ?int $r): float
{
    if (!$w || !$r) return 0.0;
    return $r === 1 ? $w : $w * (1 + $r / 30);
}

/** Séries réalisées (reps > 0) d'un workout_exercise. */
function done_sets(int $weId): array
{
    return q('SELECT * FROM sets WHERE workout_exercise_id = ? AND reps > 0 ORDER BY set_number', [$weId])->fetchAll();
}

/** Dernière séance (avant $before) où l'exercice a été fait : date + séries. */
function last_performance(string $exerciseId, string $before): ?array
{
    $we = q('SELECT we.id, w.id AS workout_id, w.started_at, w.name
             FROM workout_exercises we JOIN workouts w ON w.id = we.workout_id
             WHERE we.exercise_id = ? AND w.started_at < ? AND w.user_id = ?
               AND EXISTS (SELECT 1 FROM sets s WHERE s.workout_exercise_id = we.id AND s.reps > 0)
             ORDER BY w.started_at DESC, we.id DESC LIMIT 1', [$exerciseId, $before, uid()])->fetch();
    if (!$we) return null;
    $we['sets'] = done_sets((int)$we['id']);
    return $we;
}

function sets_text(array $sets): string
{
    return implode(' · ', array_map(fn($s) => fmt_num($s['weight']) . '×' . $s['reps'], $sets));
}

/**
 * Règle de surcharge progressive : si N séries à X reps ont été validées à la charge max
 * de la dernière séance, on propose +inc kg.
 */
function overload_status(?array $te, ?array $last): ?array
{
    if (!$te || empty($te['overload_sets']) || empty($te['overload_reps'])) return null;
    $rule = ['need' => (int)$te['overload_sets'], 'reps' => (int)$te['overload_reps'],
             'inc_min' => (float)$te['inc_min'], 'inc_max' => (float)$te['inc_max']];
    if (!$last || !$last['sets']) return $rule + ['validated' => false, 'ok' => 0, 'top' => null];

    $top = max(array_map(fn($s) => (float)$s['weight'], $last['sets']));
    $ok = count(array_filter($last['sets'], fn($s) => (float)$s['weight'] >= $top && (int)$s['reps'] >= $rule['reps']));
    return $rule + [
        'validated' => $ok >= $rule['need'],
        'ok' => $ok,
        'top' => $top,
        'next_min' => $top + $rule['inc_min'],
        'next_max' => $top + $rule['inc_max'],
    ];
}

function template(int $id): ?array
{
    return q('SELECT * FROM templates WHERE id = ? AND user_id = ?', [$id, uid()])->fetch() ?: null;
}

function template_exercises(int $templateId): array
{
    return q('SELECT te.*, e.name, e.name_fr, e.image, e.equipment
              FROM template_exercises te JOIN exercises e ON e.id = te.exercise_id
              WHERE te.template_id = ? ORDER BY te.position, te.id', [$templateId])->fetchAll();
}

/** Crée une séance (vide ou depuis un programme) avec les charges pré-remplies. */
function start_workout(?int $templateId): int
{
    $pdo = db();
    $tpl = $templateId ? template($templateId) : null;
    $start = now();
    $pdo->beginTransaction();
    q('INSERT INTO workouts (user_id, template_id, name, started_at) VALUES (?, ?, ?, ?)',
        [uid(), $tpl['id'] ?? null, $tpl['name'] ?? 'Séance libre', $start]);
    $wid = (int)$pdo->lastInsertId();

    if ($tpl) {
        foreach (template_exercises((int)$tpl['id']) as $i => $te) {
            q('INSERT INTO workout_exercises (workout_id, exercise_id, template_exercise_id, position, label, superset_group) VALUES (?,?,?,?,?,?)',
                [$wid, $te['exercise_id'], $te['id'], $i + 1, $te['label'], $te['superset_group']]);
            $weId = (int)$pdo->lastInsertId();
            $last = last_performance($te['exercise_id'], $start);
            $status = overload_status($te, $last);
            for ($n = 1; $n <= max(1, (int)$te['sets']); $n++) {
                $weight = null;
                if ($status && $status['validated']) {
                    $weight = $status['next_min'];
                } elseif ($last) {
                    $prev = $last['sets'][$n - 1] ?? end($last['sets']);
                    $weight = $prev['weight'];
                }
                q('INSERT INTO sets (workout_exercise_id, set_number, weight) VALUES (?,?,?)', [$weId, $n, $weight]);
            }
        }
        ss_renumber('workout', $wid);
    }
    $pdo->commit();
    return $wid;
}

/** Ajoute un exercice à une séance, avec autant de séries que la dernière fois (3 par défaut). */
function add_exercise_to_workout(int $workoutId, string $exerciseId): int
{
    $w = owned_workout($workoutId);
    if (!$w || !exercise($exerciseId)) throw new RuntimeException('Séance ou exercice introuvable');
    $pos = (int)q('SELECT COALESCE(MAX(position), 0) FROM workout_exercises WHERE workout_id = ?', [$workoutId])->fetchColumn();
    q('INSERT INTO workout_exercises (workout_id, exercise_id, position) VALUES (?,?,?)', [$workoutId, $exerciseId, $pos + 1]);
    $weId = (int)db()->lastInsertId();
    ss_renumber('workout', $workoutId);
    $last = last_performance($exerciseId, $w['started_at']);
    $n = $last ? count($last['sets']) : 3;
    for ($i = 1; $i <= $n; $i++) {
        q('INSERT INTO sets (workout_exercise_id, set_number, weight) VALUES (?,?,?)',
            [$weId, $i, $last['sets'][$i - 1]['weight'] ?? null]);
    }
    return $weId;
}

/** Stats globales d'une séance. */
function workout_totals(int $workoutId): array
{
    $r = q('SELECT COUNT(s.id) AS sets, COALESCE(SUM(s.weight * s.reps), 0) AS volume, COUNT(DISTINCT we.id) AS exercises
            FROM workout_exercises we LEFT JOIN sets s ON s.workout_exercise_id = we.id AND s.reps > 0
            WHERE we.workout_id = ?', [$workoutId])->fetch();
    return ['sets' => (int)$r['sets'], 'volume' => (float)$r['volume'], 'exercises' => (int)$r['exercises']];
}

/**
 * Séries par muscle sur une période. Muscle principal = 1 série, secondaire = 0,5.
 * Retour : [muscle => ['sets', 'volume', 'workouts' => [ids], 'last']] trié par séries.
 */
function muscle_stats(string $from, string $to): array
{
    $rows = q('SELECT em.muscle, em.role, s.weight, s.reps, w.id AS wid, w.started_at
               FROM sets s
               JOIN workout_exercises we ON we.id = s.workout_exercise_id
               JOIN workouts w ON w.id = we.workout_id
               JOIN exercise_muscles em ON em.exercise_id = we.exercise_id
               WHERE s.reps > 0 AND w.user_id = ? AND w.started_at >= ? AND w.started_at < ?', [uid(), $from, $to]);
    $out = [];
    foreach ($rows as $r) {
        $f = $r['role'] === 'primary' ? 1.0 : 0.5;
        $m = $r['muscle'];
        $out[$m] ??= ['sets' => 0.0, 'direct' => 0, 'volume' => 0.0, 'workouts' => [], 'last' => null];
        $out[$m]['sets'] += $f;
        if ($f === 1.0) $out[$m]['direct']++;
        $out[$m]['volume'] += $f * (float)$r['weight'] * (int)$r['reps'];
        $out[$m]['workouts'][$r['wid']] = true;
        if ($out[$m]['last'] === null || $r['started_at'] > $out[$m]['last']) $out[$m]['last'] = $r['started_at'];
    }
    uasort($out, fn($a, $b) => $b['sets'] <=> $a['sets']);
    return $out;
}

/** Bornes [début, fin[ des $days derniers jours (aujourd'hui inclus), décalées de $offset périodes. */
function period(int $days, int $offset = 0): array
{
    $end = strtotime('tomorrow') - $offset * $days * 86400;
    return [date('Y-m-d 00:00:00', $end - $days * 86400), date('Y-m-d 00:00:00', $end)];
}

/** Dernière date à laquelle chaque muscle a été travaillé (en principal). */
function muscle_last_trained(): array
{
    $out = [];
    foreach (q("SELECT em.muscle, MAX(w.started_at) AS last
                FROM exercise_muscles em
                JOIN workout_exercises we ON we.exercise_id = em.exercise_id
                JOIN workouts w ON w.id = we.workout_id
                WHERE em.role = 'primary' AND w.user_id = ? AND EXISTS (SELECT 1 FROM sets s WHERE s.workout_exercise_id = we.id AND s.reps > 0)
                GROUP BY em.muscle", [uid()]) as $r) {
        $out[$r['muscle']] = $r['last'];
    }
    return $out;
}

/** Historique d'un exercice, séance par séance (plus récente en premier). */
function exercise_history(string $exerciseId): array
{
    $rows = q('SELECT we.id, w.id AS workout_id, w.started_at, w.name
               FROM workout_exercises we JOIN workouts w ON w.id = we.workout_id
               WHERE we.exercise_id = ? AND w.user_id = ? ORDER BY w.started_at DESC, we.id DESC', [$exerciseId, uid()])->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $sets = done_sets((int)$r['id']);
        if (!$sets) continue;
        $r['sets'] = $sets;
        $r['top'] = max(array_map(fn($s) => (float)$s['weight'], $sets));
        $r['e1rm'] = max(array_map(fn($s) => e1rm((float)$s['weight'], (int)$s['reps']), $sets));
        $r['volume'] = array_sum(array_map(fn($s) => (float)$s['weight'] * (int)$s['reps'], $sets));
        $r['reps'] = array_sum(array_map(fn($s) => (int)$s['reps'], $sets));
        $out[] = $r;
    }
    return $out;
}

/** Records d'un exercice à partir de son historique. */
function exercise_records(array $history): array
{
    $rec = ['top' => null, 'e1rm' => null, 'volume' => null, 'reps' => null];
    foreach ($history as $h) {
        foreach ($h['sets'] as $s) {
            $w = (float)$s['weight'];
            if (!$rec['top'] || $w > $rec['top']['value'] || ($w == $rec['top']['value'] && $s['reps'] > $rec['top']['reps'])) {
                $rec['top'] = ['value' => $w, 'reps' => (int)$s['reps'], 'date' => $h['started_at']];
            }
            if (!$rec['reps'] || $s['reps'] > $rec['reps']['value']) {
                $rec['reps'] = ['value' => (int)$s['reps'], 'weight' => $w, 'date' => $h['started_at']];
            }
        }
        if (!$rec['e1rm'] || $h['e1rm'] > $rec['e1rm']['value']) $rec['e1rm'] = ['value' => $h['e1rm'], 'date' => $h['started_at']];
        if (!$rec['volume'] || $h['volume'] > $rec['volume']['value']) $rec['volume'] = ['value' => $h['volume'], 'date' => $h['started_at']];
    }
    return $rec;
}

/** Records battus pendant une séance (charge max ou 1RM estimé supérieur à tout l'historique antérieur). */
function workout_prs(array $workout): array
{
    $prs = [];
    $wes = q('SELECT we.id, we.exercise_id, e.name, e.name_fr FROM workout_exercises we JOIN exercises e ON e.id = we.exercise_id
              WHERE we.workout_id = ?', [$workout['id']])->fetchAll();
    foreach ($wes as $we) {
        $sets = done_sets((int)$we['id']);
        if (!$sets) continue;
        $prev = q('SELECT MAX(s.weight) AS top, MAX(s.weight * (1 + s.reps / 30.0)) AS e1
                   FROM sets s JOIN workout_exercises x ON x.id = s.workout_exercise_id JOIN workouts w ON w.id = x.workout_id
                   WHERE x.exercise_id = ? AND s.reps > 0 AND w.started_at < ? AND w.user_id = ?', [$we['exercise_id'], $workout['started_at'], uid()])->fetch();
        if ($prev['top'] === null) continue; // première fois : pas un record
        $top = max(array_map(fn($s) => (float)$s['weight'], $sets));
        $e1 = max(array_map(fn($s) => e1rm((float)$s['weight'], (int)$s['reps']), $sets));
        if ($top > (float)$prev['top']) {
            $prs[] = ['name' => ex_name($we), 'what' => 'Charge max', 'value' => fmt_num($top) . ' kg', 'before' => fmt_num($prev['top']) . ' kg'];
        } elseif ($e1 > (float)$prev['e1'] + 0.01) {
            $prs[] = ['name' => ex_name($we), 'what' => '1RM estimé', 'value' => fmt_num($e1) . ' kg', 'before' => fmt_num($prev['e1']) . ' kg'];
        }
    }
    return $prs;
}

/** Records récents toutes séances confondues (pour l'accueil). */
function recent_prs(int $limit = 5): array
{
    $out = [];
    foreach (q('SELECT * FROM workouts WHERE ended_at IS NOT NULL AND user_id = ? ORDER BY started_at DESC LIMIT 10', [uid()])->fetchAll() as $w) {
        foreach (workout_prs($w) as $p) {
            $out[] = $p + ['date' => $w['started_at'], 'workout_id' => $w['id']];
            if (count($out) >= $limit) return $out;
        }
    }
    return $out;
}

function bodyweight_series(int $days = 3650): array
{
    return q('SELECT * FROM bodyweight WHERE user_id = ? AND date >= ? ORDER BY date', [uid(), date('Y-m-d', strtotime("-$days days"))])->fetchAll();
}

/** Recherche d'exercices (multi-mots, nom anglais ou français), les plus utilisés d'abord. */
function search_exercises(string $text, ?string $muscle = null, ?string $equipment = null, ?string $bodyPart = null, int $limit = 40, int $offset = 0, ?int &$total = null): array
{
    $where = ['(e.owner_id IS NULL OR e.owner_id = ?)'];
    $params = [uid()];
    foreach (preg_split('/\s+/', trim($text)) as $word) {
        if ($word === '') continue;
        $where[] = '(e.name LIKE ? OR e.name_fr LIKE ?)';
        $params[] = "%$word%";
        $params[] = "%$word%";
    }
    if ($muscle) {
        $where[] = "e.id IN (SELECT exercise_id FROM exercise_muscles WHERE muscle = ? AND role = 'primary')";
        $params[] = $muscle;
    }
    if ($equipment) { $where[] = 'e.equipment = ?'; $params[] = $equipment; }
    if ($bodyPart) { $where[] = 'e.body_part = ?'; $params[] = $bodyPart; }
    $w = implode(' AND ', $where);
    $total = (int)q("SELECT COUNT(*) FROM exercises e WHERE $w", $params)->fetchColumn();
    return q("SELECT e.*, COALESCE(u.c, 0) AS used
              FROM exercises e LEFT JOIN (SELECT we.exercise_id, COUNT(*) AS c FROM workout_exercises we
                                          JOIN workouts w ON w.id = we.workout_id WHERE w.user_id = ? GROUP BY we.exercise_id) u ON u.exercise_id = e.id
              WHERE $w ORDER BY used DESC, e.is_custom DESC, (e.name_fr IS NULL), e.name LIMIT $limit OFFSET $offset", array_merge([uid()], $params))->fetchAll();
}

/** Note de réglage machine de l'utilisateur pour un exercice (« Siège cran 4 »). */
function exercise_note(string $exerciseId): string
{
    return (string)q('SELECT note FROM exercise_notes WHERE user_id = ? AND exercise_id = ?', [uid(), $exerciseId])->fetchColumn();
}

function save_exercise_note(string $exerciseId, string $note): void
{
    $note = preg_match('/^.{0,300}/su', trim($note), $m) ? $m[0] : ''; // 300 caractères max (mbstring n'est pas toujours là)
    if ($note === '') {
        q('DELETE FROM exercise_notes WHERE user_id = ? AND exercise_id = ?', [uid(), $exerciseId]);
        return;
    }
    q("INSERT INTO exercise_notes (user_id, exercise_id, note) VALUES (?, ?, ?)
       ON CONFLICT(user_id, exercise_id) DO UPDATE SET note = excluded.note, updated_at = datetime('now', 'localtime')",
      [uid(), $exerciseId, $note]);
}

/** Repos entre séries (s) d'un exercice de séance ; 15 s au minimum pour un « 0 » de superset. */
function rest_between(array $we): int
{
    $r = $we['rest_max'] !== null ? (int)$we['rest_max'] : 90;
    return $r === 0 ? 15 : $r;
}

/** Repos avant l'exercice suivant (transition) : réglé dans le programme, sinon le repos entre séries. */
function rest_after(array $we): int
{
    return $we['rest_after'] !== null ? (int)$we['rest_after'] : max(rest_between($we), 90);
}

/**
 * Alternatives pour remplacer un exercice : mêmes muscles principaux, ceux déjà pratiqués d'abord,
 * puis même matériel. $text filtre par nom comme la recherche normale.
 */
function replacement_candidates(string $exerciseId, string $text = '', int $limit = 40): array
{
    $ex = exercise($exerciseId);
    $prim = exercise_muscles($exerciseId)['primary'];
    $rows = [];
    foreach ($prim ?: [null] as $m) {
        foreach (search_exercises($text, $m, null, null, 200) as $r) $rows[$r['id']] = $r;
    }
    unset($rows[$exerciseId]);
    $rows = array_values($rows);
    usort($rows, fn($a, $b) => [(int)$b['used'] > 0, $b['equipment'] === $ex['equipment'], (int)$b['used']]
                            <=> [(int)$a['used'] > 0, $a['equipment'] === $ex['equipment'], (int)$a['used']]);
    return array_slice($rows, 0, $limit);
}

/**
 * Remplace l'exercice d'une séance en gardant sa place, son superset et la cible du programme.
 * Les séries pas encore faites reprennent les charges de la dernière fois sur le nouvel exercice.
 */
function replace_workout_exercise(array $we, string $exerciseId, bool $updateTemplate): void
{
    $w = owned_workout((int)$we['workout_id']);
    q('UPDATE workout_exercises SET exercise_id = ? WHERE id = ?', [$exerciseId, $we['id']]);
    $last = last_performance($exerciseId, $w['started_at']);
    $sets = q('SELECT id, set_number FROM sets WHERE workout_exercise_id = ? AND done = 0 ORDER BY set_number', [$we['id']])->fetchAll();
    foreach ($sets as $s) {
        $prev = $last ? ($last['sets'][$s['set_number'] - 1] ?? end($last['sets'])) : null;
        q('UPDATE sets SET weight = ?, reps = NULL WHERE id = ?', [$prev['weight'] ?? null, $s['id']]);
    }
    if ($updateTemplate && $we['template_exercise_id']) {
        q('UPDATE template_exercises SET exercise_id = ? WHERE id = ? AND template_id IN (SELECT id FROM templates WHERE user_id = ?)',
          [$exerciseId, $we['template_exercise_id'], uid()]);
    }
}
