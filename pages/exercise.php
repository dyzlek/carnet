<?php
defined('ROOT') || exit; // pas d'accès direct
$id = (string)($_GET['id'] ?? '');
$e = exercise($id);
if (!$e) {
    echo '<p>Exercice introuvable. <a href="?p=exercises">Retour</a></p>';
    return;
}

if (is_post()) {
    if (!can_edit_exercise($e)) {
        flash("Seul l'administrateur peut modifier le catalogue commun.");
        redirect('?p=exercise&id=' . urlencode($id));
    }
    switch ($_POST['action'] ?? '') {
        case 'rename':
            q('UPDATE exercises SET name_fr = ? WHERE id = ?', [trim((string)$_POST['name_fr']) ?: null, $id]);
            break;
        case 'muscles':
            $primary = array_values(array_intersect((array)($_POST['primary'] ?? []), array_keys(MUSCLES)));
            $secondary = array_diff(array_intersect((array)($_POST['secondary'] ?? []), array_keys(MUSCLES)), $primary);
            q('DELETE FROM exercise_muscles WHERE exercise_id = ?', [$id]);
            foreach ($primary as $m) q('INSERT INTO exercise_muscles VALUES (?,?,?)', [$id, $m, 'primary']);
            foreach ($secondary as $m) q('INSERT INTO exercise_muscles VALUES (?,?,?)', [$id, $m, 'secondary']);
            flash('Muscles mis à jour (les stats en tiennent compte immédiatement).');
            break;
    }
    redirect('?p=exercise&id=' . urlencode($id));
}

$title = ex_name($e);
$muscles = exercise_muscles($id);
$history = exercise_history($id);
$rec = exercise_records($history);
$steps = json_decode($e['steps_fr'] ?? '[]', true) ?: json_decode($e['steps_en'] ?? '[]', true) ?: [];
$chron = array_reverse($history);
?>
<a class="back" href="javascript:history.back()"><?= icon('arrow-left') ?>Retour</a>
<div class="ex-hero">
  <?php if ($e['gif']): ?>
    <button type="button" class="zoomable ex-gif-btn" data-viewer="<?= h($e['id']) ?>" aria-label="Agrandir l'animation">
      <img class="ex-gif" src="<?= h(media_url($e['gif'])) ?>" alt="Démonstration : <?= h(ex_name($e)) ?>" width="180" height="180">
      <span class="zoom-badge" aria-hidden="true"><?= icon('zoom-in') ?></span>
    </button>
  <?php endif; ?>
  <div>
    <h1 class="page-title"><?= h(ex_name($e)) ?></h1>
    <?php if ($e['name_fr'] && !$e['is_custom']): ?><p class="muted"><?= h(ucfirst($e['name'])) ?></p><?php endif; ?>
    <div class="chips">
      <span class="chip"><?= h(equipment_label($e['equipment'])) ?></span>
      <?php foreach ($muscles['primary'] as $m): ?><span class="chip strong"><?= h(muscle_label($m)) ?></span><?php endforeach; ?>
      <?php foreach ($muscles['secondary'] as $m): ?><span class="chip"><?= h(muscle_label($m)) ?> ½</span><?php endforeach; ?>
    </div>
    <label class="ex-note">
      <span><?= icon('wrench') ?>Mes réglages</span>
      <input data-ex-note="<?= h($e['id']) ?>" value="<?= h(exercise_note($e['id'])) ?>" placeholder="Siège cran 4, dossier 2…" maxlength="300" autocomplete="off" enterkeyhint="done">
    </label>
    <?php if (can_edit_exercise($e)): ?>
    <form method="post" class="inline-form">
      <input type="hidden" name="action" value="rename">
      <input name="name_fr" value="<?= h($e['name_fr']) ?>" placeholder="Nom perso (ex : Presse horizontale)" aria-label="Nom personnalisé">
      <button class="btn ghost sm">Renommer</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="tiles">
  <div class="tile"><span class="tile-l">Charge max</span><span class="tile-v"><?= $rec['top'] ? fmt_num($rec['top']['value']) . '<small> kg</small>' : '–' ?></span>
    <?php if ($rec['top']): ?><span class="small muted">× <?= $rec['top']['reps'] ?> · <?= h(fmt_short_date($rec['top']['date'])) ?></span><?php endif; ?></div>
  <div class="tile"><span class="tile-l">1RM estimé</span><span class="tile-v"><?= $rec['e1rm'] ? fmt_num($rec['e1rm']['value']) . '<small> kg</small>' : '–' ?></span></div>
  <div class="tile"><span class="tile-l">Meilleur volume</span><span class="tile-v"><?= $rec['volume'] ? fmt_num($rec['volume']['value'], 0) . '<small> kg</small>' : '–' ?></span></div>
  <div class="tile"><span class="tile-l">Séances</span><span class="tile-v"><?= count($history) ?></span></div>
</div>

<?php if (count($chron) >= 2): ?>
<section class="card">
  <h2>Progression</h2>
  <div class="chart-box">
    <canvas data-chart='<?= h(json_encode([
        'type' => 'line',
        'labels' => array_map(fn($r) => fmt_short_date($r['started_at']), $chron),
        'datasets' => [
            ['label' => 'Charge max (kg)', 'data' => array_map(fn($r) => $r['top'], $chron), 'role' => 'main'],
            ['label' => '1RM estimé (kg)', 'data' => array_map(fn($r) => round($r['e1rm'], 1), $chron), 'role' => 'second'],
        ],
    ], JSON_UNESCAPED_UNICODE)) ?>'></canvas>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <h2>Historique</h2>
  <?php if (!$history): ?>
    <p class="empty">Jamais fait pour l'instant.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Date</th><th>Séries</th><th class="num">Max</th><th class="num">1RM est.</th><th class="num">Volume</th></tr></thead>
        <tbody>
        <?php foreach ($history as $r): ?>
          <tr>
            <td><a href="?p=workout&id=<?= (int)$r['workout_id'] ?>"><?= h(fmt_date($r['started_at'])) ?></a></td>
            <td><?= h(sets_text($r['sets'])) ?></td>
            <td class="num"><b><?= fmt_num($r['top']) ?></b></td>
            <td class="num"><?= fmt_num($r['e1rm']) ?></td>
            <td class="num"><?= fmt_num($r['volume'], 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($steps): ?>
<section class="card">
  <h2>Exécution</h2>
  <ol class="steps"><?php foreach ($steps as $s): ?><li><?= h($s) ?></li><?php endforeach; ?></ol>
</section>
<?php endif; ?>

<?php if (can_edit_exercise($e)): ?>
<details class="card">
  <summary>Modifier les muscles travaillés<?= $e['owner_id'] === null ? ' <span class="muted small">(catalogue commun à tous les comptes)</span>' : '' ?></summary>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="muscles">
    <fieldset><legend>Principaux (comptent 1 série)</legend>
      <div class="checks"><?php foreach (MUSCLES as $k => [$l]): ?><label><input type="checkbox" name="primary[]" value="<?= $k ?>" <?= in_array($k, $muscles['primary'], true) ? 'checked' : '' ?>> <?= h($l) ?></label><?php endforeach; ?></div>
    </fieldset>
    <fieldset><legend>Secondaires (comptent ½ série)</legend>
      <div class="checks"><?php foreach (MUSCLES as $k => [$l]): ?><label><input type="checkbox" name="secondary[]" value="<?= $k ?>" <?= in_array($k, $muscles['secondary'], true) ? 'checked' : '' ?>> <?= h($l) ?></label><?php endforeach; ?></div>
    </fieldset>
    <button class="btn">Enregistrer</button>
  </form>
</details>
<?php endif; ?>
