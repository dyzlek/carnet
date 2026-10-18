<?php
defined('ROOT') || exit; // pas d'accès direct
$title = 'Accueil';
[$from, $to] = period(7);
[$pFrom, $pTo] = period(7, 1);
$stats = muscle_stats($from, $to);

$week = q('SELECT COUNT(DISTINCT w.id) AS n, COUNT(s.id) AS sets, COALESCE(SUM(s.weight * s.reps), 0) AS vol
           FROM workouts w LEFT JOIN workout_exercises we ON we.workout_id = w.id
           LEFT JOIN sets s ON s.workout_exercise_id = we.id AND s.reps > 0
           WHERE w.user_id = ? AND w.started_at >= ? AND w.started_at < ?', [uid(), $from, $to])->fetch();
$prevWeek = q('SELECT COUNT(s.id) AS sets, COALESCE(SUM(s.weight * s.reps), 0) AS vol
               FROM workouts w JOIN workout_exercises we ON we.workout_id = w.id
               JOIN sets s ON s.workout_exercise_id = we.id AND s.reps > 0
               WHERE w.user_id = ? AND w.started_at >= ? AND w.started_at < ?', [uid(), $pFrom, $pTo])->fetch();

$bw = q('SELECT * FROM bodyweight WHERE user_id = ? ORDER BY date DESC LIMIT 1', [uid()])->fetch();
$bw30 = $bw ? q('SELECT weight FROM bodyweight WHERE user_id = ? AND date <= ? ORDER BY date DESC LIMIT 1', [uid(), date('Y-m-d', strtotime('-30 days'))])->fetchColumn() : false;
$bwSeries = bodyweight_series(90);

$templates = q('SELECT t.*, (SELECT MAX(started_at) FROM workouts w WHERE w.template_id = t.id) AS last FROM templates t WHERE t.user_id = ? ORDER BY last DESC, name', [uid()])->fetchAll();
$lastWorkouts = q('SELECT * FROM workouts WHERE user_id = ? ORDER BY started_at DESC LIMIT 4', [uid()])->fetchAll();
$prs = recent_prs(5);

function delta_badge(float $now, float $before): string
{
    if ($before <= 0) return '';
    $pct = ($now - $before) / $before * 100;
    $cls = $pct >= 0 ? 'up' : 'down';
    return '<span class="delta ' . $cls . '">' . icon($pct >= 0 ? 'arrow-up' : 'arrow-down') . fmt_num(abs($pct), 0) . ' %</span>';
}

// Programme conseillé : celui qu'on n'a pas fait depuis le plus longtemps.
$next = null;
foreach ($templates as $t) {
    if ($next === null || ($t['last'] ?? '') < ($next['last'] ?? '')) $next = $t;
}
$nextCount = $next ? (int)q('SELECT COUNT(*) FROM template_exercises WHERE template_id = ?', [$next['id']])->fetchColumn() : 0;
?>
<div class="hero">
  <div>
    <p class="eyebrow">Sem. <?= (int)date('W') ?> · <?= h(JOURS_LONG[(int)date('w')]) ?> <?= h(fmt_date(date('Y-m-d'), false)) ?></p>
    <?php if ($next): ?>
      <h1 class="page-title">Prochaine : <?= h($next['name']) ?></h1>
      <p class="lede"><?= $nextCount ?> exercices · <?= $next['last'] ? 'dernière fois ' . h(ago($next['last'])) : 'jamais faite' ?></p>
    <?php else: ?>
      <h1 class="page-title">Carnet d'entraînement</h1>
      <p class="lede">Crée un programme ou lance une séance libre.</p>
    <?php endif; ?>
  </div>
  <div class="btn-row">
    <?php if ($next): ?>
      <form method="post" action="?p=start"><input type="hidden" name="template" value="<?= (int)$next['id'] ?>">
        <button class="btn big"><?= icon('play') ?>Démarrer <?= h($next['name']) ?></button></form>
    <?php endif; ?>
    <a class="btn ghost big" href="?p=start">Autre séance</a>
  </div>
</div>

<div class="tiles">
  <div class="tile">
    <span class="tile-l">Séances · 7 j</span>
    <span class="tile-v"><?= (int)$week['n'] ?></span>
  </div>
  <div class="tile">
    <span class="tile-l">Séries · 7 j</span>
    <span class="tile-v"><?= (int)$week['sets'] ?></span>
    <?= delta_badge((float)$week['sets'], (float)$prevWeek['sets']) ?>
  </div>
  <div class="tile">
    <span class="tile-l">Volume · 7 j</span>
    <span class="tile-v"><?= fmt_num($week['vol'] / 1000, 1) ?><small> t</small></span>
    <?= delta_badge((float)$week['vol'], (float)$prevWeek['vol']) ?>
  </div>
  <a class="tile" href="?p=body">
    <span class="tile-l">Poids</span>
    <?php if ($bw): ?>
      <span class="tile-v"><?= fmt_num($bw['weight']) ?><small> kg</small></span>
      <?php if ($bw30 !== false): $d = $bw['weight'] - $bw30; ?>
        <span class="delta neutral"><?= $d > 0 ? '+' : '' ?><?= fmt_num($d) ?> kg sur 30 j</span>
      <?php endif; ?>
    <?php else: ?>
      <span class="tile-v muted">–</span><span class="more-link">Ajouter une pesée<?= icon('arrow-right') ?></span>
    <?php endif; ?>
  </a>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head">
      <h2>Muscles · 7 derniers jours</h2>
      <?= more_link('?p=muscles', 'Détail') ?>
    </div>
    <div class="body-layout is-compact">
      <?= body_map($stats, 7, true) ?>
      <div class="body-side-panel">
        <?= muscle_bars($stats, 7, 8) ?>
        <?= zone_legend() ?>
      </div>
    </div>
  </section>

  <div class="stack">
    <?php $act = activity_totals($from, $to); $actPct = min(100, round($act['active_min'] / ACTIVE_GOAL_MIN * 100));
          $lastActs = q('SELECT * FROM activities WHERE user_id = ? ORDER BY started_at DESC LIMIT 3', [uid()])->fetchAll(); ?>
    <section class="card">
      <div class="card-head"><h2>Activités · 7 j</h2><?= more_link('?p=activities', 'Tout voir') ?></div>
      <p class="goal-num sm"><span class="mono"><?= fmt_num($act['active_min'], 0) ?></span> <span class="muted">/ <?= ACTIVE_GOAL_MIN ?> min actives</span></p>
      <div class="goal-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $actPct ?>"><span style="width:<?= $actPct ?>%"></span></div>
      <ul class="plain act-mini">
        <?php foreach ($lastActs as $a): [$al, $aic] = activity_type($a['type']); ?>
          <li><a class="row-between" href="?p=activity&id=<?= (int)$a['id'] ?>">
            <span class="act-name"><?= icon($aic) ?><?= h($al) ?> <span class="muted small">· <?= h(fmt_date($a['started_at'])) ?></span></span>
            <span class="mono small"><?= h(fmt_duration_s((int)$a['duration_s'])) ?><?= $a['distance_km'] ? ' · ' . fmt_num($a['distance_km']) . ' km' : '' ?></span>
          </a></li>
        <?php endforeach; ?>
      </ul>
      <div class="quick-acts">
        <?php foreach (['marche', 'course', 'velo', 'rameur'] as $k): [$ql, $qic] = activity_type($k); ?>
          <a class="btn ghost sm" href="?p=activities&amp;add=1&amp;type=<?= $k ?>#ajout"><?= icon($qic) ?><?= h($ql) ?></a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <h2>Programmes</h2>
      <?php if (!$templates): ?><p class="muted">Aucun programme. <a href="?p=templates">En créer un</a></p><?php endif; ?>
      <?php foreach ($templates as $t): ?>
        <form method="post" action="?p=start" class="row-between">
          <input type="hidden" name="template" value="<?= (int)$t['id'] ?>">
          <span><strong><?= h($t['name']) ?></strong><br><span class="muted small">dernière fois <?= h(ago($t['last'])) ?></span></span>
          <button class="btn sm">Go</button>
        </form>
      <?php endforeach; ?>
    </section>

    <?php if ($bwSeries): ?>
    <section class="card">
      <div class="card-head"><h2>Poids · 90 j</h2><?= more_link('?p=body', 'Corps') ?></div>
      <div class="chart-box sm">
        <canvas data-chart='<?= h(json_encode([
            'type' => 'line',
            'labels' => array_map(fn($r) => fmt_short_date($r['date']), $bwSeries),
            'datasets' => [['label' => 'Poids (kg)', 'data' => array_map(fn($r) => (float)$r['weight'], $bwSeries), 'role' => 'main']],
            'compact' => true,
        ], JSON_UNESCAPED_UNICODE)) ?>'></canvas>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h2>Dernières séances</h2><?= more_link('?p=workouts', 'Tout voir') ?></div>
    <?php if (!$lastWorkouts): ?><p class="empty">Rien pour l'instant.</p><?php endif; ?>
    <ul class="plain">
      <?php foreach ($lastWorkouts as $w): $t = workout_totals((int)$w['id']); ?>
        <li><a class="row-between" href="?p=workout&id=<?= (int)$w['id'] ?>">
          <span><strong><?= h($w['name']) ?></strong> <span class="muted small">· <?= h(fmt_date($w['started_at'])) ?></span></span>
          <span class="muted small"><?= $t['sets'] ?> séries · <?= fmt_num($t['volume'] / 1000, 2) ?> t</span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="card">
    <h2><?= icon('trophy') ?> Records récents</h2>
    <?php if (!$prs): ?><p class="muted">Tes records apparaîtront ici dès que tu battras une perf.</p><?php endif; ?>
    <ul class="plain">
      <?php foreach ($prs as $p): ?>
        <li class="row-between"><span><strong><?= h($p['name']) ?></strong><br><span class="muted small"><?= h($p['what']) ?> · <?= h(fmt_date($p['date'])) ?></span></span>
          <span class="accent"><?= h($p['value']) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>
