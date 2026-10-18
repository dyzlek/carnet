<?php
defined('ROOT') || exit; // pas d'accès direct
$errors = [];
$draft = null;
if (is_post() && ($_POST['action'] ?? '') === 'save') {
    [$data, $errors] = activity_from_post($_POST);
    if (!$errors) {
        q('INSERT INTO activities (user_id, type, started_at, duration_s, distance_km, intensity, calories, avg_hr, elevation_m, notes)
           VALUES (?,?,?,?,?,?,?,?,?,?)', [uid(), $data['type'], $data['started_at'], $data['duration_s'], $data['distance_km'],
            $data['intensity'], $data['calories'], $data['avg_hr'], $data['elevation_m'], $data['notes']]);
        flash(activity_type($data['type'])[0] . ' enregistrée.');
        redirect('?p=activities');
    }
    $draft = $data;
}

$title = 'Activités';
[$from, $to] = period(7);
[$pFrom, $pTo] = period(7, 1);
$week = activity_totals($from, $to);
$prev = activity_totals($pFrom, $pTo);
$weight = current_weight();
$goalPct = min(100, round($week['active_min'] / ACTIVE_GOAL_MIN * 100));

// Minutes actives par semaine (lundi → dimanche), 12 dernières semaines
$weeks = [];
$monday = strtotime('monday this week');
for ($i = 11; $i >= 0; $i--) {
    $start = $monday - $i * 7 * 86400;
    $weeks[] = ['label' => 'S' . (int)date('W', $start), 'from' => date('Y-m-d 00:00:00', $start), 'to' => date('Y-m-d 00:00:00', $start + 7 * 86400)];
}
$all12 = q('SELECT started_at, duration_s, intensity FROM activities WHERE user_id = ? AND started_at >= ?', [uid(), $weeks[0]['from']])->fetchAll();
$weekMin = array_fill(0, 12, 0.0);
foreach ($all12 as $a) {
    foreach ($weeks as $i => $w) {
        if ($a['started_at'] >= $w['from'] && $a['started_at'] < $w['to']) {
            $weekMin[$i] += $a['duration_s'] / 60 * (INTENSITY_WEIGHT[(int)$a['intensity']] ?? 1);
        }
    }
}

// Bilan par type sur 30 jours
[$f30, $t30] = period(30);
$byType = [];
foreach (q('SELECT * FROM activities WHERE user_id = ? AND started_at >= ? AND started_at < ?', [uid(), $f30, $t30])->fetchAll() as $a) {
    $t = &$byType[$a['type']];
    $t ??= ['n' => 0, 's' => 0, 'km' => 0.0, 'best' => null];
    $t['n']++;
    $t['s'] += (int)$a['duration_s'];
    $t['km'] += (float)$a['distance_km'];
    if ($a['distance_km'] > 0) {
        $secPerKm = $a['duration_s'] / $a['distance_km'];
        if ($t['best'] === null || $secPerKm < $t['best']['spk']) $t['best'] = ['spk' => $secPerKm, 'a' => $a];
    }
    unset($t);
}
uasort($byType, fn($a, $b) => $b['s'] <=> $a['s']);

$history = q('SELECT * FROM activities WHERE user_id = ? ORDER BY started_at DESC LIMIT 200', [uid()])->fetchAll();
$byMonth = [];
foreach ($history as $a) $byMonth[substr($a['started_at'], 0, 7)][] = $a;

function delta_text(float $now, float $before, string $unit): string
{
    if ($before <= 0) return '';
    $d = $now - $before;
    return '<span class="delta ' . ($d >= 0 ? 'up' : 'down') . '">' . icon($d >= 0 ? 'arrow-up' : 'arrow-down') . fmt_num(abs($d), 0) . ' ' . $unit . '</span>';
}
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Cardio &amp; sport · 7 derniers jours</p>
    <h1 class="page-title">Activités</h1>
  </div>
  <a class="btn" href="#ajout" data-open-details="ajout"><?= icon('plus') ?>Ajouter une activité</a>
</div>

<div class="tiles">
  <div class="tile"><span class="tile-l">Durée</span><span class="tile-v"><?= h(fmt_duration_s($week['seconds'] ?: null)) ?></span><?= delta_text($week['seconds'] / 60, $prev['seconds'] / 60, 'min') ?></div>
  <div class="tile"><span class="tile-l">Distance</span><span class="tile-v"><?= fmt_num($week['km']) ?><small> km</small></span><?= delta_text($week['km'], $prev['km'], 'km') ?></div>
  <div class="tile"><span class="tile-l">Activités</span><span class="tile-v"><?= $week['count'] ?></span></div>
  <div class="tile"><span class="tile-l">Calories</span><span class="tile-v"><?= fmt_num($week['kcal'], 0) ?><small> kcal</small></span><span class="small muted">estimées si non saisies</span></div>
</div>

<details class="card add-card" id="ajout" <?= ($errors || !$history || isset($_GET['add'])) ? 'open' : '' ?>>
  <summary><?= icon('plus') ?> Ajouter une activité</summary>
  <?php if ($errors): ?>
    <div class="form-error" role="alert"><?= icon('x') ?><ul class="plain"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?= activity_form($draft, 'Enregistrer') ?>
</details>

<div class="grid-2">
  <section class="card">
    <div class="card-head">
      <h2>Minutes actives · 12 semaines</h2>
      <span class="muted small">objectif <?= ACTIVE_GOAL_MIN ?> min / sem.</span>
    </div>
    <div class="chart-box">
      <canvas data-chart='<?= h(json_encode([
          'type' => 'bar',
          'labels' => array_column($weeks, 'label'),
          'datasets' => [
              ['label' => 'Minutes actives', 'data' => array_map(fn($m) => round($m), $weekMin), 'role' => 'main'],
              ['label' => 'Objectif', 'data' => array_fill(0, 12, ACTIVE_GOAL_MIN), 'role' => 'goal', 'type' => 'line'],
          ],
      ], JSON_UNESCAPED_UNICODE)) ?>'></canvas>
    </div>
  </section>

  <section class="card goal-card">
    <h2>Objectif santé</h2>
    <p class="goal-num"><span class="mono"><?= fmt_num($week['active_min'], 0) ?></span> <span class="muted">/ <?= ACTIVE_GOAL_MIN ?> min</span></p>
    <div class="goal-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $goalPct ?>"><span style="width:<?= $goalPct ?>%"></span></div>
    <p class="muted small">Recommandation OMS : au moins <?= ACTIVE_GOAL_MIN ?> min d'activité modérée par semaine.
      Une minute intense compte double, une minute légère pour moitié. Calculé sur les 7 derniers jours.</p>
    <?php if ($week['active_min'] >= ACTIVE_GOAL_MIN): ?>
      <p class="goal-ok"><?= icon('check') ?>Objectif atteint cette semaine.</p>
    <?php elseif ($week['active_min'] > 0): ?>
      <p class="muted">Encore <b class="mono"><?= fmt_num(ACTIVE_GOAL_MIN - $week['active_min'], 0) ?> min</b>, par exemple <?= fmt_num(ceil((ACTIVE_GOAL_MIN - $week['active_min']) / 30), 0) ?> marche(s) rapide(s) de 30 min.</p>
    <?php endif; ?>
  </section>
</div>

<?php if ($byType): ?>
<section class="card">
  <h2>Par activité · 30 jours</h2>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Activité</th><th class="num">Séances</th><th class="num">Durée</th><th class="num">Distance</th><th class="num">Meilleur rythme</th></tr></thead>
      <tbody>
      <?php foreach ($byType as $k => $t): [$label, $ic] = activity_type($k); ?>
        <tr>
          <td><span class="act-name"><?= icon($ic) ?><?= h($label) ?></span></td>
          <td class="num"><?= $t['n'] ?></td>
          <td class="num"><?= h(fmt_duration_s($t['s'])) ?></td>
          <td class="num"><?= $t['km'] > 0 ? fmt_num($t['km']) . ' km' : '–' ?></td>
          <td class="num"><?= $t['best'] ? h(activity_rhythm($t['best']['a']) ?? '–') : '–' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<h2 class="section-title">Historique</h2>
<?php if (!$history): ?>
  <p class="empty">Aucune activité pour l'instant : ajoute ta première marche, course ou sortie vélo ci-dessus.</p>
<?php endif; ?>
<?php foreach ($byMonth as $ym => $items): $t = strtotime($ym . '-01'); ?>
  <h3 class="month-title"><?= h(ucfirst(MOIS_LONG[(int)date('n', $t) - 1])) ?> <?= date('Y', $t) ?>
    <span class="muted small mono"><?= count($items) ?> · <?= h(fmt_duration_s(array_sum(array_column($items, 'duration_s')))) ?></span></h3>
  <ul class="list">
    <?php foreach ($items as $a): [$label, $ic] = activity_type($a['type']); [$kcal, $est] = activity_kcal($a, $weight); $rh = activity_rhythm($a); ?>
      <li>
        <a class="list-item act-item" href="?p=activity&id=<?= (int)$a['id'] ?>">
          <span class="act-icon i-<?= (int)$a['intensity'] ?>"><?= icon($ic) ?></span>
          <span class="li-main">
            <strong><?= h($label) ?> <span class="muted small"><?= h(fmt_date($a['started_at'], true, true)) ?></span></strong>
            <span class="act-stats mono">
              <span><?= icon('clock') ?><?= h(fmt_duration_s((int)$a['duration_s'])) ?></span>
              <?php if ($a['distance_km']): ?><span><?= icon('route') ?><?= fmt_num($a['distance_km'], 2) ?> km</span><?php endif; ?>
              <?php if ($rh): ?><span><?= h($rh) ?></span><?php endif; ?>
              <span><?= icon('flame') ?><?= $est ? '≈' : '' ?><?= $kcal ?> kcal</span>
              <?php if ($a['avg_hr']): ?><span><?= (int)$a['avg_hr'] ?> bpm</span><?php endif; ?>
            </span>
            <?php if ($a['notes']): ?><span class="muted small"><?= h($a['notes']) ?></span><?php endif; ?>
          </span>
          <span class="tag"><?= h(INTENSITIES[(int)$a['intensity']]) ?></span>
          <?= icon('chevron-right', 'icon chev') ?>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endforeach; ?>
