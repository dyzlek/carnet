<?php
defined('ROOT') || exit; // pas d'accès direct
if (is_post() && ($_POST['action'] ?? '') === 'create') {
    $name = trim((string)$_POST['name']);
    if ($name !== '') {
        $id = 'c' . substr(md5(uniqid('', true)), 0, 8);
        q('INSERT INTO exercises (id, owner_id, name, name_fr, body_part, equipment, is_custom, steps_fr, steps_en) VALUES (?,?,?,?,?,?,1,?,?)',
            [$id, uid(), $name, $name, 'custom', ($_POST['equipment'] ?? '') ?: 'other', '[]', '[]']);
        $primary = (array)($_POST['primary'] ?? []);
        foreach ($primary as $m) if (isset(MUSCLES[$m])) q(insert_or('IGNORE') . ' INTO exercise_muscles VALUES (?,?,?)', [$id, $m, 'primary']);
        foreach ((array)($_POST['secondary'] ?? []) as $m) {
            if (isset(MUSCLES[$m]) && !in_array($m, $primary, true)) q(insert_or('IGNORE') . ' INTO exercise_muscles VALUES (?,?,?)', [$id, $m, 'secondary']);
        }
        flash('Exercice créé.');
        redirect('?p=exercise&id=' . $id);
    }
}

$title = 'Exercices';
$text = trim((string)($_GET['q'] ?? ''));
$muscle = ($_GET['muscle'] ?? '') ?: null;
$equipment = ($_GET['equipment'] ?? '') ?: null;
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 48;
$total = 0;
$rows = search_exercises($text, $muscle, $equipment, null, $per, ($page - 1) * $per, $total);
$equipments = q('SELECT equipment, COUNT(*) AS n FROM exercises WHERE owner_id IS NULL OR owner_id = ? GROUP BY equipment ORDER BY n DESC', [uid()])->fetchAll();
$pages = (int)ceil($total / $per);
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['p' => 'exercises', 'q' => $text, 'muscle' => $muscle, 'equipment' => $equipment], $over)));
?>
<div class="page-head">
  <h1 class="page-title">Exercices <span class="muted small"><?= $total ?></span></h1>
  <button type="button" class="btn ghost" data-open-dialog="new-ex"><?= icon('plus') ?>Créer un exercice</button>
</div>

<form class="filters" method="get">
  <input type="hidden" name="p" value="exercises">
  <input type="search" name="q" value="<?= h($text) ?>" placeholder="Rechercher (anglais ou nom perso)…">
  <select name="muscle" aria-label="Muscle ciblé">
    <option value="">Tous les muscles</option>
    <?php foreach (MUSCLES as $k => [$l]): ?><option value="<?= $k ?>" <?= $muscle === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
  </select>
  <select name="equipment" aria-label="Matériel">
    <option value="">Tout le matériel</option>
    <?php foreach ($equipments as $e): ?><option value="<?= h($e['equipment']) ?>" <?= $equipment === $e['equipment'] ? 'selected' : '' ?>><?= h(equipment_label($e['equipment'])) ?> (<?= $e['n'] ?>)</option><?php endforeach; ?>
  </select>
  <button class="btn">Filtrer</button>
</form>

<div class="ex-grid">
  <?php foreach ($rows as $e): ?>
    <div class="ex-tile">
      <?= thumb_zoom($e, $e['id'], 'ex-img') ?>
      <a class="ex-link" href="?p=exercise&id=<?= h($e['id']) ?>">
        <span class="ex-name"><?= h(ex_name($e)) ?></span>
        <span class="muted small"><?= h(equipment_label($e['equipment'])) ?><?= $e['used'] ? ' · <span class="accent">' . (int)$e['used'] . '× fait</span>' : '' ?></span>
      </a>
    </div>
  <?php endforeach; ?>
</div>
<?php if (!$rows): ?><p class="empty">Aucun exercice ne correspond.</p><?php endif; ?>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a class="btn ghost sm" href="<?= h($qs(['page' => $page - 1])) ?>"><?= icon('arrow-left') ?>Préc.</a><?php endif; ?>
    <span class="muted">Page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn ghost sm" href="<?= h($qs(['page' => $page + 1])) ?>">Suiv.<?= icon('arrow-right') ?></a><?php endif; ?>
  </nav>
<?php endif; ?>

<p class="muted small credit">Exercices : <a href="https://github.com/hasaneyldrm/exercises-dataset" target="_blank" rel="noopener">hasaneyldrm/exercises-dataset</a> · images © Gym visual.</p>

<dialog id="new-ex" class="modal">
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="create">
    <h2>Nouvel exercice</h2>
    <label>Nom <input name="name" required placeholder="ex : Hip thrust machine"></label>
    <label>Matériel
      <select name="equipment">
        <?php foreach (EQUIPMENT_FR as $k => $l): ?><option value="<?= h($k) ?>" <?= $k === 'leverage machine' ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      </select>
    </label>
    <fieldset><legend>Muscles principaux</legend>
      <div class="checks"><?php foreach (MUSCLES as $k => [$l]): ?><label><input type="checkbox" name="primary[]" value="<?= $k ?>"> <?= h($l) ?></label><?php endforeach; ?></div>
    </fieldset>
    <fieldset><legend>Muscles secondaires</legend>
      <div class="checks"><?php foreach (MUSCLES as $k => [$l]): ?><label><input type="checkbox" name="secondary[]" value="<?= $k ?>"> <?= h($l) ?></label><?php endforeach; ?></div>
    </fieldset>
    <div class="row-between">
      <button type="button" class="btn ghost" data-close-dialog>Annuler</button>
      <button class="btn">Créer</button>
    </div>
  </form>
</dialog>
