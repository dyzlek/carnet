<?php
defined('ROOT') || exit; // pas d'accès direct
// Programmes fournis par l'utilisateur. Chacun n'est créé qu'une fois (voir seed_programs) :
// si tu le supprimes ou le modifies dans l'app, il n'est pas recréé ni écrasé.

/*
 * rows : [ordre, id dataset, nom perso, type, muscle cible, séries, reps min, reps max,
 *          repos min (s), repos max (s), RPE min, RPE max, consigne, surcharge séries, surcharge reps, +kg min, +kg max]
 * muscles : surcharge des muscles du dataset [id => [principaux, secondaires]]
 */
const PROGRAMS = [
    [
        'name' => 'Jambes',
        'notes' => 'Presse lourde puis 2 supersets antagonistes et finition mollets.',
        'muscles' => [
            '0739' => [['quadriceps', 'fessiers'], ['ischios', 'adducteurs']],
            '0597' => [['abducteurs'], ['fessiers']],
        ],
        'rows' => [
            ['1',  '0739', 'Presse horizontale', 'Exercice principal', 'Quadriceps / Fessiers', 4, 8, 12, 120, 120, null, null, "Pieds largeur d'épaules.", 4, 12, 5, 5],
            ['2A', '0585', 'Leg extension', 'Superset 1 (antagoniste)', 'Quadriceps (isolation)', 4, 10, 15, 0, 15, null, null, 'Pause 1 s en haut.', 4, 15, 2.5, 5],
            ['2B', '0586', 'Leg curl allongé', 'Superset 1 (antagoniste)', 'Ischio-jambiers (isolation)', 4, 10, 15, 90, 90, null, null, 'Phase excentrique contrôlée 2 s, bassin plaqué.', 4, 15, 2.5, 5],
            ['3A', '0598', 'Machine adducteurs', 'Superset 2 (antagoniste)', 'Adducteurs', 3, 12, 15, 0, 15, null, null, 'Amplitude maximale sans à-coups.', 3, 15, 2.5, 5],
            ['3B', '0597', 'Machine abducteurs', 'Superset 2 (antagoniste)', 'Abducteurs / Moyen fessier', 3, 12, 15, 75, 75, null, null, 'Buste légèrement penché en avant.', 3, 15, 2.5, 5],
            ['4',  '1391', 'Mollets à la presse', 'Finition mollets', 'Triceps sural (mollets)', 4, 12, 15, 60, 60, null, null, "Extension maximale sur la pointe des pieds + pause d'étirement 1 s en bas.", 4, 15, 5, 5],
        ],
    ],
    [
        'name' => 'Dos & Biceps',
        'notes' => 'Tirage vertical / horizontal + isolation.',
        'muscles' => [
            '0861' => [['haut_dos', 'trapezes'], ['dorsaux', 'biceps']],
            '0238' => [['dorsaux'], ['epaules']],
            '0602' => [['epaules', 'haut_dos'], []],
            '0313' => [['biceps', 'avant_bras'], []],
        ],
        'rows' => [
            ['1', '0198', 'Tirage vertical poitrine', 'Polyarticulaire', 'Grands dorsaux (largeur)', 4, 8, 12, 120, 120, 8, 9,
                'Prise légèrement plus large que les épaules. Abaisse les épaules avant de tirer les coudes vers les hanches.', 4, 12, 2.5, 5],
            ['2', '0861', 'Tirage horizontal assis', 'Polyarticulaire', 'Milieu du dos / Rhomboïdes / Trapèzes', 4, 8, 12, 120, 120, 8, 9,
                "Dos droit, laisse les omoplates s'ouvrir légèrement au retour puis resserre-les fort en tirant.", 4, 12, 2.5, 5],
            ['3', '0238', 'Pull-over à la poulie haute', 'Isolation dos', 'Grands dorsaux (sans les biceps)', 3, 12, 15, 90, 90, 8, 8,
                'Bras presque tendus (coudes déverrouillés), amène la barre/corde aux cuisses, buste penché à 45°.', 3, 15, 2.5, 2.5],
            ['4', '0602', 'Oiseau machine', 'Isolation épaules', 'Deltoïde postérieur & haut du dos', 3, 12, 15, 75, 75, 8, 9,
                'Garde les épaules basses pour ne pas solliciter les trapèzes supérieurs. Retour contrôlé (2 s).', 3, 15, 2.5, 2.5],
            ['5', '0318', 'Curl incliné aux haltères', 'Isolation biceps', 'Biceps (chef long, étirement max)', 3, 10, 12, 90, 90, 8, 9,
                'Banc à 45–60°. Garde les coudes fixes légèrement en arrière du buste pendant toute la montée.', 3, 12, 1, 2],
            ['6', '0313', 'Curl marteau', 'Isolation biceps', 'Brachial & avant-bras (épaisseur)', 3, 10, 15, 75, 75, 9, 9,
                'Prise neutre (pouces vers le haut), sans balancer le buste, courte pause en haut.', 3, 15, 1, 2],
        ],
    ],
    [
        'name' => 'Push',
        'notes' => 'Pectoraux, épaules (devant / côté) & triceps.',
        'muscles' => [
            '0596' => [['pectoraux'], ['epaules']],
            '0405' => [['epaules'], ['triceps']],
            '0334' => [['epaules'], []],
        ],
        'rows' => [
            ['1', '0289', 'Développé couché', 'Polyarticulaire', 'Pectoraux (faisceau moyen) / Triceps', 4, 8, 12, 120, 120, 8, 9,
                'Omoplates resserrées et basses, coudes ouverts à 45–60°.', 4, 12, 2, 5],
            ['2', '0314', 'Développé incliné aux haltères', 'Polyarticulaire', "Haut des pectoraux & avant d'épaule", 4, 8, 12, 120, 120, 8, 9,
                'Banc incliné à 30° (1 à 2 crans max). Contrôle la descente sans rebond.', 4, 12, 2, 5],
            ['3', '0596', 'Écartés à la machine (pec deck)', 'Isolation pecs', 'Pectoraux (isolation sans triceps)', 3, 12, 15, 90, 90, 8, 9,
                'Coudes légèrement fléchis et fixes, pause 1 s mains jointes en contraction.', 3, 15, 2.5, 5],
            ['4', '0405', 'Développé militaire assis', 'Polyarticulaire / iso', "Avant d'épaule (deltoïde antérieur)", 3, 10, 12, 90, 90, 8, 9,
                'Dos bien calé contre le dossier (~75–80°), descendre au niveau du menton sans cambrer.', 3, 12, 1, 2.5],
            ['5', '0334', 'Élévations latérales', 'Isolation épaules', "Côté de l'épaule (deltoïde moyen)", 4, 12, 15, 75, 75, 8, 9,
                "Monter les coudes à hauteur d'épaules légèrement vers l'avant, sans hausser les trapèzes.", 4, 15, 1, 2],
            ['6', '0200', 'Extension triceps poulie haute', 'Isolation triceps', 'Triceps (chefs latéral et médial)', 3, 10, 15, 75, 75, 8, 9,
                'Coudes verrouillés le long des côtes, écarter la corde en bas (1 s de contraction).', 3, 15, 2.5, 2.5],
            ['7', '0194', 'Extension triceps overhead à la poulie', 'Isolation triceps', 'Triceps (chef long, étirement max)', 3, 10, 15, 75, 75, 9, 9,
                'Dos à la poulie, bras au-dessus de la tête pour étirer la longue portion du triceps.', 3, 15, 2.5, 2.5],
        ],
    ],
];

/** Ajuste une seule fois le catalogue partagé (noms français, muscles) pour les exercices des programmes. */
function tune_catalog(PDO $pdo): void
{
    if ($pdo->query("SELECT 1 FROM settings WHERE `key` = 'catalog_tuned'")->fetchColumn()) return;
    $pdo->beginTransaction();
    $up = $pdo->prepare('UPDATE exercises SET name_fr = ? WHERE id = ? AND name_fr IS NULL');
    $del = $pdo->prepare('DELETE FROM exercise_muscles WHERE exercise_id = ?');
    $ins = $pdo->prepare('INSERT INTO exercise_muscles (exercise_id, muscle, role) VALUES (?,?,?)');
    foreach (PROGRAMS as $p) {
        foreach ($p['rows'] as $r) $up->execute([$r[2], $r[1]]);
        foreach ($p['muscles'] as $id => [$primary, $secondary]) {
            $del->execute([$id]);
            foreach ($primary as $m) $ins->execute([$id, $m, 'primary']);
            foreach ($secondary as $m) $ins->execute([$id, $m, 'secondary']);
        }
    }
    $pdo->exec(insert_or('REPLACE') . " INTO settings (`key`, value) VALUES ('catalog_tuned', '1')");
    $pdo->commit();
}

/** Crée pour ce compte les programmes qu'il n'a pas encore reçus (une seule fois chacun). */
function seed_programs(PDO $pdo, int $uid): void
{
    tune_catalog($pdo);
    $get = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND `key` = 'seeded_programs'");
    $get->execute([$uid]);
    $done = json_decode((string)($get->fetchColumn() ?: '[]'), true) ?: [];
    $changed = false;

    foreach (PROGRAMS as $p) {
        if (in_array($p['name'], $done, true)) continue;
        $ids = array_column($p['rows'], 1);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT COUNT(DISTINCT id) FROM exercises WHERE id IN ($in)");
        $st->execute($ids);
        if ((int)$st->fetchColumn() !== count(array_unique($ids))) continue; // dataset absent

        $pdo->beginTransaction();
        $existing = $pdo->prepare('SELECT id FROM templates WHERE name = ? AND user_id = ?');
        $existing->execute([$p['name'], $uid]);
        $tid = $existing->fetchColumn();
        if ($tid) {
            // Programme déjà là (version précédente) : on complète seulement les champs vides.
            $fill = $pdo->prepare('UPDATE template_exercises SET focus = COALESCE(focus, ?), rpe_min = COALESCE(rpe_min, ?), rpe_max = COALESCE(rpe_max, ?)
                                   WHERE template_id = ? AND exercise_id = ?');
            foreach ($p['rows'] as $r) $fill->execute([$r[4], $r[10], $r[11], $tid, $r[1]]);
        } else {
            $pdo->prepare('INSERT INTO templates (user_id, name, notes) VALUES (?, ?, ?)')->execute([$uid, $p['name'], $p['notes']]);
            $tid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO template_exercises
                (template_id, position, label, exercise_id, kind, focus, sets, reps_min, reps_max, rest_min, rest_max, rpe_min, rpe_max,
                 notes, overload_sets, overload_reps, inc_min, inc_max)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($p['rows'] as $i => $r) {
                [$label, $ex, , $kind, $focus, $sets, $rmin, $rmax, $restMin, $restMax, $rpeMin, $rpeMax, $notes, $os, $or, $iMin, $iMax] = $r;
                $ins->execute([$tid, $i + 1, $label, $ex, $kind, $focus, $sets, $rmin, $rmax, $restMin, $restMax, $rpeMin, $rpeMax, $notes, $os, $or, $iMin, $iMax]);
            }
            ss_from_labels('template', $tid);
        }
        $pdo->commit();
        $done[] = $p['name'];
        $changed = true;
    }

    if ($changed) {
        $pdo->prepare(upsert_sql('user_settings', ['user_id', 'key', 'value'], ['user_id', 'key'], ['value']))
            ->execute([$uid, 'seeded_programs', json_encode($done, JSON_UNESCAPED_UNICODE)]);
    }
}
