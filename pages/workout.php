<?php
defined('ROOT') || exit; // pas d'accès direct
$id = (int)($_GET['id'] ?? 0);
$w = owned_workout($id);
if (!$w) {
    echo '<p>Séance introuvable. <a href="?p=workouts">Retour</a></p>';
    return;
}

if (is_post()) {
    switch ($_POST['action'] ?? '') {
        case 'finish':
            // on retire les séries jamais remplies
            q('DELETE FROM sets WHERE reps IS NULL AND workout_exercise_id IN (SELECT id FROM workout_exercises WHERE workout_id = ?)', [$id]);
            q('UPDATE workouts SET ended_at = COALESCE(ended_at, ?) WHERE id = ?', [now(), $id]);
            flash('Séance enregistrée.');
            redirect("?p=workout&id=$id");
        case 'reopen':
            q('UPDATE workouts SET ended_at = NULL WHERE id = ?', [$id]);
            redirect("?p=workout&id=$id");
        case 'delete':
            q('DELETE FROM workouts WHERE id = ?', [$id]);
            flash('Séance supprimée.');
            redirect('?p=workouts');
        case 'meta':
            $name = trim((string)$_POST['name']) ?: $w['name'];
            $date = (string)($_POST['date'] ?? '');
            $start = $w['started_at'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $date)) {
                $newStart = str_replace('T', ' ', $date) . ':00';
                // décale aussi la fin pour garder la durée
                if ($w['ended_at']) {
                    $dur = strtotime($w['ended_at']) - strtotime($start);
                    q('UPDATE workouts SET ended_at = ? WHERE id = ?', [date('Y-m-d H:i:s', strtotime($newStart) + $dur), $id]);
                }
                $start = $newStart;
            }
            q('UPDATE workouts SET name = ?, started_at = ? WHERE id = ?', [$name, $start, $id]);
            redirect("?p=workout&id=$id");
    }
}

$title = $w['name'];
$finished = $w['ended_at'] !== null;
$wes = q('SELECT we.id AS we_id, we.exercise_id, we.label, we.superset_group, we.template_exercise_id,
                 e.name, e.name_fr, e.image, e.equipment,
                 te.kind, te.focus, te.rpe_min, te.rpe_max, te.sets AS t_sets, te.reps_min, te.reps_max, te.rest_min, te.rest_max, te.notes AS t_notes,
                 te.overload_sets, te.overload_reps, te.inc_min, te.inc_max
          FROM workout_exercises we
          JOIN exercises e ON e.id = we.exercise_id
          LEFT JOIN template_exercises te ON te.id = we.template_exercise_id
          WHERE we.workout_id = ? ORDER BY we.position, we.id', [$id])->fetchAll();
$totals = workout_totals($id);
$prs = $finished ? workout_prs($w) : [];
?>
<div class="workout-head">
  <div>
    <p class="eyebrow"><?= h(fmt_date($w['started_at'], true, true)) ?><?= $finished ? ' · ' . h(fmt_duration($w['started_at'], $w['ended_at'])) : '' ?></p>
    <h1 class="page-title"><?= h($w['name']) ?></h1>
  </div>
  <?php if (!$finished): ?>
    <div class="timer-big" data-since="<?= h(date('c', strtotime($w['started_at']))) ?>">0:00</div>
  <?php else: ?>
    <span class="tag ok">Terminée</span>
  <?php endif; ?>
</div>

<div class="stats-row">
  <div class="stat"><span class="stat-v" id="tot-sets"><?= $totals['sets'] ?></span><span class="stat-l">séries</span></div>
  <div class="stat"><span class="stat-v"><?= $totals['exercises'] ?></span><span class="stat-l">exercices</span></div>
  <div class="stat"><span class="stat-v" id="tot-vol"><?= fmt_num($totals['volume'] / 1000, 2) ?></span><span class="stat-l">tonnes soulevées</span></div>
</div>

<?php if ($prs): ?>
  <section class="card pr-card">
    <h2><?= icon('trophy') ?> Records battus</h2>
    <ul class="plain">
      <?php foreach ($prs as $p): ?>
        <li><strong><?= h($p['name']) ?></strong> — <?= h($p['what']) ?> <span class="accent"><?= h($p['value']) ?></span> <span class="muted">(avant : <?= h($p['before']) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<div class="exlist" data-workout="<?= $id ?>">
<?php
foreach ($wes as $idx => $we):
    $sets = q('SELECT * FROM sets WHERE workout_exercise_id = ? ORDER BY set_number', [$we['we_id']])->fetchAll();
    $last = last_performance($we['exercise_id'], $w['started_at']);
    $te = $we['template_exercise_id'] ? $we : null;
    $status = overload_status($te, $last);
    $muscles = exercise_muscles($we['exercise_id']);
    $rest = $we['rest_max'] !== null ? (int)$we['rest_max'] : 90;
    if ($rest === 0) $rest = 15;
    $group = $we['superset_group'];
    $isSuper = $group !== null;
    $prevGroup = $wes[$idx - 1]['superset_group'] ?? null;
    $nextGroup = $wes[$idx + 1]['superset_group'] ?? null;
    $ssFirst = $isSuper && $group !== $prevGroup;
?>
  <?php if ($ssFirst):
      $members = array_values(array_filter($wes, fn($x) => $x['superset_group'] === $group));
      $rounds = (int)q('SELECT MAX(n) FROM (SELECT COUNT(*) AS n FROM sets WHERE workout_exercise_id IN (' . implode(',', array_map(fn($x) => (int)$x['we_id'], $members)) . ') GROUP BY workout_exercise_id)')->fetchColumn(); ?>
    <p class="ss-head" data-ss-head="<?= (int)$group ?>"><?= drag_handle('Déplacer le superset') ?><?= icon('link') ?><?= h(ss_title(count($members), $rounds)) ?><span class="muted">enchaîner sans repos, repos après le tour</span></p>
  <?php endif; ?>
  <article class="excard<?= $isSuper ? ' in-superset' : '' ?><?= $ssFirst ? ' ss-first' : '' ?><?= $isSuper && $nextGroup !== $group ? ' ss-last' : '' ?>"
           data-we="<?= (int)$we['we_id'] ?>" data-rest="<?= $rest ?>"<?= $isSuper ? ' data-ss="' . (int)$group . '"' : '' ?>>
    <header class="excard-head">
      <?= drag_handle("Déplacer l'exercice") ?>
      <?php if ($we['label']): ?><span class="badge"><?= h($we['label']) ?></span><?php endif; ?>
      <span class="excard-thumb"><?= thumb_zoom($we, $we['exercise_id']) ?></span>
      <div class="excard-title">
        <a href="?p=exercise&id=<?= h($we['exercise_id']) ?>"><h3><?= h(ex_name($we)) ?></h3></a>
        <p class="muted small">
          <?= $we['kind'] ? h($we['kind']) . ' · ' : '' ?><?= h(muscles_text($muscles['primary'])) ?>
        </p>
      </div>
      <details class="menu">
        <summary aria-label="Options"><?= icon('more') ?></summary>
        <div class="menu-pop">
          <button type="button" data-move="-1"><?= icon('arrow-up') ?>Monter</button>
          <button type="button" data-move="1"><?= icon('arrow-down') ?>Descendre</button>
          <button type="button" class="danger" data-remove><?= icon('trash') ?>Retirer l'exercice</button>
        </div>
      </details>
    </header>

    <?php if ($te): ?>
      <div class="target">
        <span><b><?= (int)$we['t_sets'] ?></b> × <b><?= h(fmt_reps($we['reps_min'], $we['reps_max'])) ?></b> reps</span>
        <span>Repos <b><?= h(fmt_rest($we['rest_min'], $we['rest_max'])) ?></b></span>
        <?php if ($we['rpe_max'] !== null): ?><span>RPE <b><?= h(fmt_rpe($we['rpe_min'], $we['rpe_max'])) ?></b></span><?php endif; ?>
        <?php if ($we['focus']): ?><span>Cible <b class="plain"><?= h($we['focus']) ?></b></span><?php endif; ?>
        <?php if ($we['t_notes']): ?><span class="target-note"><?= h($we['t_notes']) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($status): ?>
      <?php if ($status['validated']): ?>
        <div class="overload ok"><?= icon('arrow-up') ?><span>Surcharge validée la dernière fois (<?= $status['ok'] ?>×<?= $status['reps'] ?> à <?= fmt_num($status['top']) ?> kg). Passe à
          <b><?= fmt_num($status['next_min']) ?><?= $status['next_max'] != $status['next_min'] ? '–' . fmt_num($status['next_max']) : '' ?> kg</b>.</span></div>
      <?php else: ?>
        <div class="overload"><?= icon('target') ?><span>Objectif : <b><?= $status['need'] ?>×<?= $status['reps'] ?></b><?= $status['top'] ? ' à ' . fmt_num($status['top']) . ' kg' : '' ?>,
          puis +<?= fmt_num($status['inc_min']) ?><?= $status['inc_max'] != $status['inc_min'] ? '–' . fmt_num($status['inc_max']) : '' ?> kg.
          <?php if ($status['top']): ?><span class="muted"><?= $status['ok'] ?>/<?= $status['need'] ?> la dernière fois.</span><?php endif; ?></span></div>
      <?php endif; ?>
    <?php endif; ?>

    <p class="last small">
      <?php if ($last): ?>
        <span class="muted">Dernière fois</span> <a href="?p=workout&id=<?= (int)$last['workout_id'] ?>"><?= h(fmt_short_date($last['started_at'])) ?></a> :
        <b><?= h(sets_text($last['sets'])) ?></b>
      <?php else: ?>
        <span class="muted">Première fois sur cet exercice.</span>
      <?php endif; ?>
    </p>

    <div class="sets">
      <div class="set-row set-head"><span>#</span><span>Préc.</span><span>kg</span><span>Reps</span><span></span><span></span></div>
      <?php foreach ($sets as $i => $s) echo set_row($s, $last['sets'][$i] ?? null, $te); ?>
    </div>
    <button type="button" class="btn ghost sm add-set"><?= icon('plus') ?>Série</button>
  </article>
  <?php if (isset($wes[$idx + 1])) echo ss_joint($isSuper && $group === $nextGroup, 'data-ss-toggle="' . (int)$we['we_id'] . '"'); ?>
<?php endforeach; ?>
</div>

<button type="button" class="btn ghost block" data-pick="workout" data-id="<?= $id ?>"><?= icon('plus') ?>Ajouter un exercice</button>

<label class="notes">
  <span>Notes de séance</span>
  <textarea data-notes="<?= $id ?>" rows="2" placeholder="Sensations, douleurs, énergie…"><?= h($w['notes']) ?></textarea>
</label>

<div class="workout-actions">
  <?php if (!$finished): ?>
    <form method="post"><input type="hidden" name="action" value="finish"><button class="btn big"><?= icon('check') ?>Terminer la séance</button></form>
  <?php else: ?>
    <form method="post"><input type="hidden" name="action" value="reopen"><button class="btn ghost">Rouvrir la séance</button></form>
  <?php endif; ?>
</div>

<details class="card meta-edit">
  <summary>Modifier le nom / la date · supprimer</summary>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="meta">
    <label>Nom <input name="name" value="<?= h($w['name']) ?>"></label>
    <label>Début <input type="datetime-local" name="date" value="<?= h(date('Y-m-d\TH:i', strtotime($w['started_at']))) ?>"></label>
    <button class="btn">Enregistrer</button>
  </form>
  <form method="post" data-confirm="Supprimer définitivement cette séance ?">
    <input type="hidden" name="action" value="delete">
    <button class="btn danger sm"><?= icon('trash') ?>Supprimer la séance</button>
  </form>
</details>
