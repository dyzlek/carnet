<?php
// Petites actions AJAX (enregistrement auto des séries, recherche d'exercices…).
// Chaque action vérifie que la donnée visée appartient au compte connecté.
require __DIR__ . '/src/bootstrap.php';

if (!current_user()) json_out(['error' => 'Session expirée, reconnecte-toi.'], 401);

$action = $_GET['action'] ?? '';
$in = $_POST;
if (!$in && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
}
if (is_post() && !csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['_csrf'] ?? null))) {
    json_out(['error' => 'Jeton de sécurité invalide, recharge la page.'], 403);
}

function need($row)
{
    if (!$row) json_out(['error' => 'Introuvable'], 404);
    return $row;
}

try {
    switch ($action) {
        case 'set_update':
            $field = $in['field'] ?? '';
            if (!in_array($field, ['weight', 'reps', 'done'], true)) json_out(['error' => 'champ invalide'], 400);
            $set = need(owned_set((int)($in['id'] ?? 0)));
            $v = $in['value'] ?? null;
            $value = match ($field) {
                'weight' => parse_num($v),
                'reps' => ($n = parse_num($v)) === null ? null : (int)round($n),
                'done' => $v ? 1 : 0,
            };
            q("UPDATE sets SET $field = ? WHERE id = ?", [$value, $set['id']]);
            json_out(['ok' => true, 'value' => $value]);

        case 'set_add':
            $we = need(owned_workout_exercise((int)($in['we'] ?? 0)));
            $last = q('SELECT * FROM sets WHERE workout_exercise_id = ? ORDER BY set_number DESC LIMIT 1', [$we['id']])->fetch();
            q('INSERT INTO sets (workout_exercise_id, set_number, weight) VALUES (?,?,?)',
                [$we['id'], ($last['set_number'] ?? 0) + 1, $last['weight'] ?? null]);
            $set = q('SELECT * FROM sets WHERE id = ?', [db()->lastInsertId()])->fetch();
            json_out(['ok' => true, 'html' => set_row($set, null, null)]);

        case 'set_delete':
            $s = need(owned_set((int)($in['id'] ?? 0)));
            q('DELETE FROM sets WHERE id = ?', [$s['id']]);
            // renumérote les séries restantes
            $i = 1;
            foreach (q('SELECT id FROM sets WHERE workout_exercise_id = ? ORDER BY set_number', [$s['workout_exercise_id']])->fetchAll() as $r) {
                q('UPDATE sets SET set_number = ? WHERE id = ?', [$i++, $r['id']]);
            }
            json_out(['ok' => true]);

        case 'exercise_info':
            // Pour la visionneuse d'animation : nom, GIF, muscles, consignes.
            $e = need(exercise((string)($_GET['id'] ?? '')));
            $m = exercise_muscles($e['id']);
            $steps = json_decode($e['steps_fr'] ?? '[]', true) ?: json_decode($e['steps_en'] ?? '[]', true) ?: [];
            json_out([
                'id' => $e['id'],
                'name' => ex_name($e),
                'original' => $e['name_fr'] && !$e['is_custom'] ? ucfirst($e['name']) : null,
                'gif' => media_url($e['gif']),
                'image' => media_url($e['image']),
                'equipment' => equipment_label($e['equipment']),
                'primary' => array_map('muscle_label', $m['primary']),
                'secondary' => array_map('muscle_label', $m['secondary']),
                'steps' => $steps,
            ]);

        case 'search':
            $rows = search_exercises((string)($_GET['q'] ?? ''), ($_GET['muscle'] ?? '') ?: null, null, null, 40);
            json_out(array_map(fn($e) => [
                'id' => $e['id'],
                'name' => ex_name($e),
                'sub' => ($e['name_fr'] ? ucfirst($e['name']) . ' · ' : '') . equipment_label($e['equipment']),
                'img' => media_url($e['image']),
                'used' => (int)$e['used'],
            ], $rows));

        case 'replace_suggestions':
            // Alternatives pour « Remplacer » (machine occupée) : mêmes muscles, puis même matériel
            $we = need(owned_workout_exercise((int)($_GET['we'] ?? 0)));
            json_out(array_map(fn($e) => [
                'id' => $e['id'],
                'name' => ex_name($e),
                'sub' => equipment_label($e['equipment']) . ' · ' . muscles_text(exercise_muscles($e['id'])['primary']),
                'img' => media_url($e['image']),
                'used' => (int)$e['used'],
            ], replacement_candidates($we['exercise_id'], (string)($_GET['q'] ?? ''))));

        case 'workout_replace_exercise':
            $we = need(owned_workout_exercise((int)($in['we'] ?? 0)));
            need(exercise((string)($in['exercise'] ?? '')));
            replace_workout_exercise($we, (string)$in['exercise'], !empty($in['template']));
            json_out(['ok' => true]);

        case 'exercise_note':
            // Réglages machine : propres à l'utilisateur, affichés à chaque séance
            $ex = need(exercise((string)($in['exercise'] ?? '')));
            save_exercise_note($ex['id'], (string)($in['note'] ?? ''));
            json_out(['ok' => true]);

        case 'workout_add_exercise':
            need(owned_workout((int)($in['workout'] ?? 0)));
            need(exercise((string)($in['exercise'] ?? '')));
            $weId = add_exercise_to_workout((int)$in['workout'], (string)$in['exercise']);
            json_out(['ok' => true, 'we' => $weId]);

        case 'workout_remove_exercise':
            $we = need(owned_workout_exercise((int)($in['we'] ?? 0)));
            q('DELETE FROM workout_exercises WHERE id = ?', [$we['id']]);
            ss_renumber('workout', (int)$we['workout_id']);
            json_out(['ok' => true]);

        case 'workout_move_exercise':
            $we = need(owned_workout_exercise((int)($in['we'] ?? 0)));
            ss_move('workout', (int)$we['workout_id'], (int)$we['id'], (int)($in['dir'] ?? 1) < 0 ? -1 : 1);
            json_out(['ok' => true]);

        case 'workout_reorder':
            // Nouvel ordre après un glisser-déposer
            $w = need(owned_workout((int)($in['workout'] ?? 0)));
            if (!ss_reorder('workout', (int)$w['id'], (array)($in['order'] ?? []))) json_out(['error' => 'Ordre invalide, recharge la page.'], 400);
            json_out(['ok' => true]);

        case 'workout_superset_toggle':
            // Lie l'exercice au suivant en superset, ou coupe le groupe à cet endroit
            $we = need(owned_workout_exercise((int)($in['we'] ?? 0)));
            ss_toggle('workout', (int)$we['workout_id'], (int)$we['id']);
            json_out(['ok' => true]);

        case 'workout_notes':
            $w = need(owned_workout((int)($in['id'] ?? 0)));
            q('UPDATE workouts SET notes = ? WHERE id = ?', [trim((string)($in['notes'] ?? '')) ?: null, $w['id']]);
            json_out(['ok' => true]);

        case 'template_add_exercise':
            $t = need(template((int)($in['template'] ?? 0)));
            $ex = need(exercise((string)($in['exercise'] ?? '')));
            $pos = (int)q('SELECT COALESCE(MAX(position), 0) FROM template_exercises WHERE template_id = ?', [$t['id']])->fetchColumn();
            q('INSERT INTO template_exercises (template_id, position, label, exercise_id, kind, sets, reps_min, reps_max, rest_min, rest_max)
               VALUES (?,?,?,?,?,?,?,?,?,?)', [$t['id'], $pos + 1, (string)($pos + 1), $ex['id'], 'Exercice', 3, 8, 12, 90, 90]);
            ss_renumber('template', (int)$t['id']);
            json_out(['ok' => true]);

        case 'export':
            // Export des données du compte uniquement (JSON lisible, réimportable plus tard).
            $uid = uid();
            $workouts = q('SELECT * FROM workouts WHERE user_id = ? ORDER BY started_at', [$uid])->fetchAll();
            foreach ($workouts as &$w) {
                $w['exercises'] = q('SELECT we.*, e.name, e.name_fr FROM workout_exercises we JOIN exercises e ON e.id = we.exercise_id
                                     WHERE we.workout_id = ? ORDER BY we.position', [$w['id']])->fetchAll();
                foreach ($w['exercises'] as &$we) {
                    $we['sets'] = q('SELECT set_number, weight, reps, rpe, done FROM sets WHERE workout_exercise_id = ? ORDER BY set_number', [$we['id']])->fetchAll();
                }
                unset($we);
            }
            unset($w);
            $templates = q('SELECT * FROM templates WHERE user_id = ?', [$uid])->fetchAll();
            foreach ($templates as &$t) $t['exercises'] = template_exercises((int)$t['id']);
            unset($t);
            $data = [
                'exported_at' => date('c'),
                'account' => current_user(),
                'workouts' => $workouts,
                'templates' => $templates,
                'bodyweight' => q('SELECT date, weight, body_fat, notes FROM bodyweight WHERE user_id = ? ORDER BY date', [$uid])->fetchAll(),
                'measurements' => q('SELECT date, site, value FROM measurements WHERE user_id = ? ORDER BY date', [$uid])->fetchAll(),
                'activities' => q('SELECT type, started_at, duration_s, distance_km, intensity, calories, avg_hr, elevation_m, notes FROM activities WHERE user_id = ? ORDER BY started_at', [$uid])->fetchAll(),
                'custom_exercises' => q('SELECT id, name, equipment FROM exercises WHERE owner_id = ?', [$uid])->fetchAll(),
                'settings' => q('SELECT `key`, value FROM user_settings WHERE user_id = ?', [$uid])->fetchAll(PDO::FETCH_KEY_PAIR),
            ];
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="carnet-' . date('Y-m-d') . '.json"');
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;

        default:
            json_out(['error' => 'action inconnue'], 404);
    }
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage());
    json_out(['error' => 'Erreur serveur'], 500);
}
