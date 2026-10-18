<?php
defined('ROOT') || exit; // pas d'accès direct
if (is_post()) {
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : date('Y-m-d');
    switch ($_POST['action'] ?? '') {
        case 'weight':
            $w = parse_num($_POST['weight'] ?? null);
            if ($w) {
                q(upsert_sql('bodyweight', ['user_id', 'date', 'weight', 'body_fat', 'notes'], ['user_id', 'date'], ['weight', 'body_fat', 'notes']),
                    [uid(), $date, $w, parse_num($_POST['body_fat'] ?? null), trim((string)($_POST['notes'] ?? '')) ?: null]);
                flash('Pesée enregistrée.');
            }
            break;
        case 'weight_delete':
            q('DELETE FROM bodyweight WHERE user_id = ? AND date = ?', [uid(), $_POST['date']]);
            break;
        case 'measures':
            $n = 0;
            foreach (MEASURE_SITES as $k => $label) {
                $v = parse_num($_POST['m'][$k] ?? null);
                if ($v) {
                    q(upsert_sql('measurements', ['user_id', 'date', 'site', 'value'], ['user_id', 'date', 'site'], ['value']), [uid(), $date, $k, $v]);
                    $n++;
                }
            }
            if ($n) flash("$n mensuration" . ($n > 1 ? 's' : '') . ' enregistrée' . ($n > 1 ? 's' : '') . '.');
            break;
        case 'measures_delete':
            q('DELETE FROM measurements WHERE user_id = ? AND date = ?', [uid(), $_POST['date']]);
            break;
        case 'settings':
            set_setting('height', (string)(parse_num($_POST['height'] ?? null) ?? ''));
            set_setting('goal_weight', (string)(parse_num($_POST['goal_weight'] ?? null) ?? ''));
            break;
    }
    redirect('?p=body' . (isset($_GET['site']) ? '&site=' . urlencode($_GET['site']) : ''));
}

$title = 'Corps';
$range = (int)($_GET['range'] ?? 180);
$series = bodyweight_series($range);
$all = q('SELECT * FROM bodyweight WHERE user_id = ? ORDER BY date DESC LIMIT 60', [uid()])->fetchAll();
$latest = $all[0] ?? null;
$first = q('SELECT * FROM bodyweight WHERE user_id = ? ORDER BY date ASC LIMIT 1', [uid()])->fetch();
$height = (float)setting('height', '0');
$goal = (float)setting('goal_weight', '0');
$bmi = ($latest && $height) ? $latest['weight'] / (($height / 100) ** 2) : null;

// Moyenne mobile 7 jours
$avg = [];
foreach ($series as $i => $r) {
    $t = strtotime($r['date']);
    $win = array_filter($series, fn($x) => strtotime($x['date']) <= $t && strtotime($x['date']) > $t - 7 * 86400);
    $avg[] = round(array_sum(array_column($win, 'weight')) / count($win), 2);
}

// Mensurations : tableau date => site => valeur
$mRows = q('SELECT * FROM measurements WHERE user_id = ? ORDER BY date', [uid()])->fetchAll();
$byDate = [];
foreach ($mRows as $r) $byDate[$r['date']][$r['site']] = (float)$r['value'];
$sitesUsed = array_values(array_filter(array_keys(MEASURE_SITES), fn($k) => in_array($k, array_column($mRows, 'site'), true)));
$counts = array_count_values(array_column($mRows, 'site'));
arsort($counts);
$site = $_GET['site'] ?? (array_key_first($counts) ?? 'taille');
$siteSeries = array_values(array_filter($mRows, fn($r) => $r['site'] === $site));
$lastMeasures = $byDate ? end($byDate) : [];
$firstMeasures = $byDate ? reset($byDate) : [];
?>
<h1 class="page-title">Corps</h1>

<div class="tiles">
  <div class="tile"><span class="tile-l">Poids actuel</span><span class="tile-v"><?= $latest ? fmt_num($latest['weight']) . '<small> kg</small>' : '–' ?></span>
    <?php if ($latest): ?><span class="small muted"><?= h(fmt_date($latest['date'])) ?></span><?php endif; ?></div>
  <div class="tile"><span class="tile-l">Depuis le début</span>
    <span class="tile-v"><?= ($latest && $first) ? (($d = $latest['weight'] - $first['weight']) > 0 ? '+' : '') . fmt_num($d) . '<small> kg</small>' : '–' ?></span>
    <?php if ($first): ?><span class="small muted">depuis <?= h(fmt_short_date($first['date'])) ?></span><?php endif; ?></div>
  <div class="tile"><span class="tile-l">IMC</span><span class="tile-v"><?= $bmi ? fmt_num($bmi) : '–' ?></span>
    <?php if (!$height): ?><span class="small muted">Taille à renseigner (Réglages)</span><?php endif; ?></div>
  <div class="tile"><span class="tile-l">Objectif</span><span class="tile-v"><?= $goal ? fmt_num($goal) . '<small> kg</small>' : '–' ?></span>
    <?php if ($goal && $latest): ?><span class="small muted">reste <?= fmt_num(abs($goal - $latest['weight'])) ?> kg</span><?php endif; ?></div>
</div>

<div class="grid-2">
  <section class="card">
    <h2>Nouvelle pesée</h2>
    <form method="post" class="form-grid two">
      <input type="hidden" name="action" value="weight">
      <label>Date <input type="date" name="date" value="<?= date('Y-m-d') ?>"></label>
      <label>Poids (kg) <input name="weight" inputmode="decimal" required placeholder="<?= $latest ? h(num_in($latest['weight'])) : '75,0' ?>"></label>
      <label>Masse grasse (%) <input name="body_fat" inputmode="decimal" placeholder="optionnel"></label>
      <label>Note <input name="notes" placeholder="à jeun, après séance…"></label>
      <button class="btn">Enregistrer</button>
    </form>
  </section>

  <section class="card">
    <div class="card-head">
      <h2>Évolution du poids</h2>
      <div class="seg">
        <?php foreach ([30 => '1 m', 90 => '3 m', 180 => '6 m', 365 => '1 an', 3650 => 'Tout'] as $d => $l): ?>
          <a href="?p=body&range=<?= $d ?>" class="<?= $range === $d ? 'is-active' : '' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (count($series) >= 2): ?>
      <div class="chart-box">
        <canvas data-chart='<?= h(json_encode([
            'type' => 'line',
            'labels' => array_map(fn($r) => fmt_short_date($r['date']), $series),
            'datasets' => array_values(array_filter([
                ['label' => 'Poids (kg)', 'data' => array_map(fn($r) => (float)$r['weight'], $series), 'role' => 'dots'],
                ['label' => 'Moyenne 7 j', 'data' => $avg, 'role' => 'main'],
                $goal ? ['label' => 'Objectif', 'data' => array_fill(0, count($series), $goal), 'role' => 'goal'] : null,
            ])),
        ], JSON_UNESCAPED_UNICODE)) ?>'></canvas>
      </div>
    <?php else: ?>
      <p class="empty">Ajoute au moins deux pesées pour voir la courbe.</p>
    <?php endif; ?>
  </section>
</div>

<?php if ($all): ?>
<details class="card">
  <summary>Toutes les pesées (<?= count($all) ?>)</summary>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Date</th><th class="num">Poids</th><th class="num">Écart</th><th class="num">MG %</th><th>Note</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($all as $i => $r): $prevW = $all[$i + 1]['weight'] ?? null; $d = $prevW ? $r['weight'] - $prevW : null; ?>
        <tr>
          <td><?= h(fmt_date($r['date'])) ?></td>
          <td class="num"><b><?= fmt_num($r['weight']) ?></b></td>
          <td class="num <?= $d > 0 ? 'up' : ($d < 0 ? 'down' : '') ?>"><?= $d === null ? '' : ($d > 0 ? '+' : '') . fmt_num($d) ?></td>
          <td class="num"><?= fmt_num($r['body_fat']) ?></td>
          <td class="muted small"><?= h($r['notes']) ?></td>
          <td><form method="post"><input type="hidden" name="action" value="weight_delete"><input type="hidden" name="date" value="<?= h($r['date']) ?>"><button class="icon-btn" aria-label="Supprimer"><?= icon('trash') ?></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<h2 class="section-title">Mensurations</h2>
<div class="grid-2">
  <section class="card">
    <h2>Nouvelle prise (cm)</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="action" value="measures">
      <label>Date <input type="date" name="date" value="<?= date('Y-m-d') ?>"></label>
      <div class="measure-grid">
        <?php foreach (MEASURE_SITES as $k => $label): ?>
          <label><?= h($label) ?> <input name="m[<?= $k ?>]" inputmode="decimal" placeholder="<?= isset($lastMeasures[$k]) ? h(num_in($lastMeasures[$k])) : '' ?>"></label>
        <?php endforeach; ?>
      </div>
      <button class="btn">Enregistrer</button>
    </form>
  </section>

  <section class="card">
    <div class="card-head">
      <h2>Évolution</h2>
      <form method="get"><input type="hidden" name="p" value="body">
        <select name="site" data-autosubmit aria-label="Mensuration">
          <?php foreach (MEASURE_SITES as $k => $l): ?><option value="<?= $k ?>" <?= $k === $site ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php if (count($siteSeries) >= 2): ?>
      <div class="chart-box sm">
        <canvas data-chart='<?= h(json_encode([
            'type' => 'line',
            'labels' => array_map(fn($r) => fmt_short_date($r['date']), $siteSeries),
            'datasets' => [['label' => MEASURE_SITES[$site] . ' (cm)', 'data' => array_map(fn($r) => (float)$r['value'], $siteSeries), 'role' => 'main']],
        ], JSON_UNESCAPED_UNICODE)) ?>'></canvas>
      </div>
    <?php else: ?>
      <p class="empty">Pas encore assez de mesures pour « <?= h(MEASURE_SITES[$site] ?? $site) ?> ».</p>
    <?php endif; ?>

    <?php if ($lastMeasures): ?>
      <table class="table compact">
        <thead><tr><th>Zone</th><th class="num">Début</th><th class="num">Actuel</th><th class="num">Évol.</th></tr></thead>
        <tbody>
        <?php foreach (MEASURE_SITES as $k => $l):
            $cur = null; $start = null;
            foreach ($byDate as $vals) { if (isset($vals[$k])) { $start ??= $vals[$k]; $cur = $vals[$k]; } }
            if ($cur === null) continue; $d = $cur - $start; ?>
          <tr><td><a href="?p=body&site=<?= $k ?>"><?= h($l) ?></a></td><td class="num"><?= fmt_num($start) ?></td><td class="num"><b><?= fmt_num($cur) ?></b></td>
            <td class="num <?= $d > 0 ? 'up' : ($d < 0 ? 'down' : '') ?>"><?= $d ? ($d > 0 ? '+' : '') . fmt_num($d) : '=' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>

<?php if ($byDate): ?>
<details class="card">
  <summary>Toutes les prises de mensurations</summary>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Date</th><?php foreach ($sitesUsed as $k): ?><th class="num"><?= h(MEASURE_SITES[$k]) ?></th><?php endforeach; ?><th></th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($byDate, true) as $d => $vals): ?>
        <tr><td><?= h(fmt_date($d)) ?></td>
          <?php foreach ($sitesUsed as $k): ?><td class="num"><?= isset($vals[$k]) ? fmt_num($vals[$k]) : '' ?></td><?php endforeach; ?>
          <td><form method="post" data-confirm="Supprimer les mesures de ce jour ?"><input type="hidden" name="action" value="measures_delete"><input type="hidden" name="date" value="<?= h($d) ?>"><button class="icon-btn" aria-label="Supprimer"><?= icon('trash') ?></button></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>

<details class="card">
  <summary>Réglages & sauvegarde</summary>
  <form method="post" class="form-grid two">
    <input type="hidden" name="action" value="settings">
    <label>Taille (cm) <input name="height" inputmode="decimal" value="<?= $height ? h(num_in($height)) : '' ?>"></label>
    <label>Poids objectif (kg) <input name="goal_weight" inputmode="decimal" value="<?= $goal ? h(num_in($goal)) : '' ?>"></label>
    <button class="btn">Enregistrer</button>
  </form>
  <p><a class="btn ghost sm" href="api.php?action=export"><?= icon('download') ?>Exporter mes données (.json)</a></p>
</details>
