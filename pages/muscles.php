<?php
defined('ROOT') || exit; // pas d'accès direct
$title = 'Muscles';
$days = (int)($_GET['days'] ?? 7);
if (!in_array($days, [7, 14, 30, 90], true)) $days = 7;

[$from, $to] = period($days);
[$pFrom, $pTo] = period($days, 1);
$stats = muscle_stats($from, $to);
$prev = muscle_stats($pFrom, $pTo);
$lastTrained = muscle_last_trained();
$nWorkouts = (int)q('SELECT COUNT(*) FROM workouts WHERE user_id = ? AND started_at >= ? AND started_at < ?', [uid(), $from, $to])->fetchColumn();

$chartLabels = $chartSets = $chartPrev = [];
foreach ($stats as $m => $s) {
    if ($m === 'cardio') continue;
    $chartLabels[] = muscle_label($m);
    $chartSets[] = round($s['sets'], 1);
    $chartPrev[] = round($prev[$m]['sets'] ?? 0, 1);
}

// Muscles pas (ou peu) travaillés sur la période
$neglected = [];
foreach (MUSCLES as $k => [$label, $region]) {
    if (in_array($k, ['cardio', 'cou', 'coiffe', 'dentele', 'flechisseurs'], true)) continue;
    if (($stats[$k]['direct'] ?? 0) === 0) $neglected[$k] = $lastTrained[$k] ?? null;
}
uasort($neglected, fn($a, $b) => strcmp((string)$a, (string)$b));
?>
<div class="page-head">
  <h1 class="page-title">Muscles travaillés</h1>
  <div class="seg" role="tablist">
    <?php foreach ([7, 14, 30, 90] as $d): ?>
      <a href="?p=muscles&days=<?= $d ?>" class="<?= $d === $days ? 'is-active' : '' ?>"><?= $d ?> j</a>
    <?php endforeach; ?>
  </div>
</div>
<p class="muted">Du <?= h(fmt_date($from, false)) ?> au <?= h(fmt_date(date('Y-m-d', strtotime($to) - 1), false)) ?> · <?= $nWorkouts ?> séance<?= $nWorkouts > 1 ? 's' : '' ?>.</p>

<section class="card body-card">
  <div class="body-layout">
    <?= body_map($stats, $days) ?>
    <div class="body-side-panel">
      <h2>Séries par muscle</h2>
      <?= muscle_bars($stats, $days) ?>
      <?= zone_legend() ?>
      <p class="muted small band-note"><span class="band-swatch"></span>Bande claire : zone conseillée (10–20 séries / semaine<?= $days !== 7 ? ', ramenée à ' . $days . ' jours' : '' ?>).</p>
    </div>
  </div>
</section>

<details class="card">
  <summary>Tous les groupes musculaires</summary>
  <?= muscle_heat($stats, $days, true) ?>
</details>

<?php if ($chartLabels): ?>
<section class="card">
  <h2>Comparaison <span class="muted small">· <?= $days ?> derniers jours vs les <?= $days ?> précédents</span></h2>
  <div class="chart-box" style="height:<?= max(220, count($chartLabels) * 30 + 60) ?>px">
    <canvas data-chart='<?= h(json_encode([
        'type' => 'bar',
        'horizontal' => true,
        'labels' => $chartLabels,
        'datasets' => [
            ['label' => "$days derniers jours", 'data' => $chartSets, 'role' => 'main'],
            ['label' => 'Période précédente', 'data' => $chartPrev, 'role' => 'ghost'],
        ],
    ], JSON_UNESCAPED_UNICODE)) ?>'></canvas>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <h2>Détail</h2>
  <?php if (!$stats): ?>
    <p class="empty">Aucune série enregistrée sur la période.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Muscle</th><th class="num">Séries</th><th class="num">Directes</th><th class="num">Évol.</th><th class="num">Volume</th><th class="num">Séances</th><th>Dernière fois</th></tr></thead>
      <tbody>
        <?php foreach ($stats as $m => $s):
            $p = $prev[$m]['sets'] ?? 0;
            $diff = $s['sets'] - $p;
            $zone = volume_zone($s['sets'] * 7 / $days); ?>
          <tr>
            <td><span class="zone-dot z-<?= $zone ?>"></span><a href="?p=exercises&muscle=<?= h($m) ?>"><?= h(muscle_label($m)) ?></a></td>
            <td class="num"><b><?= fmt_num($s['sets']) ?></b></td>
            <td class="num"><?= $s['direct'] ?></td>
            <td class="num <?= $diff > 0 ? 'up' : ($diff < 0 ? 'down' : '') ?>"><?= $diff > 0 ? '+' : '' ?><?= fmt_num($diff) ?></td>
            <td class="num"><?= fmt_num($s['volume'], 0) ?> kg</td>
            <td class="num"><?= count($s['workouts']) ?></td>
            <td><?= h(ago($s['last'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($neglected): ?>
<section class="card">
  <h2>Pas travaillés en direct sur <?= $days ?> jours</h2>
  <div class="chips">
    <?php foreach ($neglected as $m => $last): ?>
      <a class="chip warn" href="?p=exercises&muscle=<?= h($m) ?>"><?= h(muscle_label($m)) ?> <span class="muted">· <?= h(ago($last)) ?></span></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
