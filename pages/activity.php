<?php
defined('ROOT') || exit; // pas d'accès direct
$a = owned_activity((int)($_GET['id'] ?? 0));
if (!$a) {
    echo '<p>Activité introuvable. <a href="?p=activities">Retour</a></p>';
    return;
}

$errors = [];
if (is_post()) {
    if (($_POST['action'] ?? '') === 'delete') {
        q('DELETE FROM activities WHERE id = ? AND user_id = ?', [$a['id'], uid()]);
        flash('Activité supprimée.');
        redirect('?p=activities');
    }
    [$data, $errors] = activity_from_post($_POST);
    if (!$errors) {
        q('UPDATE activities SET type = ?, started_at = ?, duration_s = ?, distance_km = ?, intensity = ?, calories = ?, avg_hr = ?, elevation_m = ?, notes = ?
           WHERE id = ? AND user_id = ?', [$data['type'], $data['started_at'], $data['duration_s'], $data['distance_km'], $data['intensity'],
            $data['calories'], $data['avg_hr'], $data['elevation_m'], $data['notes'], $a['id'], uid()]);
        flash('Activité mise à jour.');
        redirect('?p=activities');
    }
    $a = array_merge($a, $data);
}

[$label, $ic] = activity_type($a['type']);
[$kcal, $est] = activity_kcal($a, current_weight());
$title = $label;
?>
<a class="back" href="?p=activities"><?= icon('arrow-left') ?>Activités</a>
<div class="page-head">
  <div>
    <p class="eyebrow"><?= h(fmt_date($a['started_at'], true, true)) ?></p>
    <h1 class="page-title act-title"><?= icon($ic) ?><?= h($label) ?></h1>
  </div>
</div>

<div class="tiles">
  <div class="tile"><span class="tile-l">Durée</span><span class="tile-v"><?= h(fmt_duration_s((int)$a['duration_s'])) ?></span></div>
  <div class="tile"><span class="tile-l">Distance</span><span class="tile-v"><?= $a['distance_km'] ? fmt_num($a['distance_km'], 2) . '<small> km</small>' : '–' ?></span></div>
  <div class="tile"><span class="tile-l">Rythme</span><span class="tile-v small-v"><?= h(activity_rhythm($a) ?? '–') ?></span></div>
  <div class="tile"><span class="tile-l">Calories</span><span class="tile-v"><?= $est ? '≈' : '' ?><?= $kcal ?><small> kcal</small></span></div>
</div>

<section class="card">
  <h2>Modifier</h2>
  <?php if ($errors): ?>
    <div class="form-error" role="alert"><?= icon('x') ?><ul class="plain"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?= activity_form($a, 'Enregistrer les modifications') ?>
</section>

<form method="post" class="danger-zone" data-confirm="Supprimer cette activité ?">
  <input type="hidden" name="action" value="delete">
  <button class="btn danger sm"><?= icon('trash') ?>Supprimer l'activité</button>
</form>
