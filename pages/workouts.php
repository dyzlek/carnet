<?php
defined('ROOT') || exit; // pas d'accès direct
$title = 'Séances';
$rows = q('SELECT w.*,
             (SELECT COUNT(*) FROM sets s JOIN workout_exercises we ON we.id = s.workout_exercise_id WHERE we.workout_id = w.id AND s.reps > 0) AS nsets,
             (SELECT COALESCE(SUM(s.weight * s.reps), 0) FROM sets s JOIN workout_exercises we ON we.id = s.workout_exercise_id WHERE we.workout_id = w.id AND s.reps > 0) AS volume
           FROM workouts w WHERE w.user_id = ? ORDER BY w.started_at DESC LIMIT 200', [uid()])->fetchAll();

// Calendrier des 16 dernières semaines (lundi → dimanche)
$weeks = 16;
$days = [];
foreach (q('SELECT substr(started_at, 1, 10) AS d, COUNT(*) AS n FROM workouts WHERE user_id = ? GROUP BY d', [uid()]) as $r) $days[$r['d']] = (int)$r['n'];
$actDays = [];
foreach (q('SELECT substr(started_at, 1, 10) AS d, COUNT(*) AS n FROM activities WHERE user_id = ? GROUP BY d', [uid()]) as $r) $actDays[$r['d']] = (int)$r['n'];
$monday = strtotime('monday this week', strtotime('today'));
$start = $monday - ($weeks - 1) * 7 * 86400;

// Muscles principaux par séance
$muscleBy = [];
foreach (q("SELECT we.workout_id, em.muscle, COUNT(*) AS n FROM workout_exercises we
            JOIN exercise_muscles em ON em.exercise_id = we.exercise_id AND em.role = 'primary'
            JOIN sets s ON s.workout_exercise_id = we.id AND s.reps > 0
            JOIN workouts w ON w.id = we.workout_id AND w.user_id = ?
            GROUP BY we.workout_id, em.muscle ORDER BY n DESC", [uid()]) as $r) {
    $muscleBy[$r['workout_id']][] = $r['muscle'];
}
?>
<div class="page-head">
  <h1 class="page-title">Séances</h1>
  <a class="btn" href="?p=start"><?= icon('plus') ?>Nouvelle séance</a>
</div>

<section class="card">
  <div class="card-head"><h2>Régularité <span class="muted small">· 16 dernières semaines</span></h2>
    <span class="legend"><span class="lg cal-key on"></span>musculation<span class="lg cal-key act"></span>activité</span></div>
  <div class="cal" role="img" aria-label="Calendrier des séances">
    <?php for ($wk = 0; $wk < $weeks; $wk++): ?>
      <div class="cal-col">
        <?php for ($d = 0; $d < 7; $d++):
            $t = $start + ($wk * 7 + $d) * 86400;
            $key = date('Y-m-d', $t);
            $n = $days[$key] ?? 0;
            $future = $t > time(); ?>
          <?php $ac = $actDays[$key] ?? 0; ?>
          <span class="cal-day<?= $n ? ' on' : '' ?><?= $ac ? ' act' : '' ?><?= $future ? ' future' : '' ?><?= $key === date('Y-m-d') ? ' today' : '' ?>" title="<?= h(fmt_date($key)) ?><?= $n ? ' · musculation' : '' ?><?= $ac ? ' · activité' : '' ?>"></span>
        <?php endfor; ?>
      </div>
    <?php endfor; ?>
  </div>
</section>

<?php if (!$rows): ?>
  <p class="empty">Aucune séance pour l'instant. <a href="?p=start">Lance ta première séance</a></p>
<?php endif; ?>

<ul class="list">
  <?php foreach ($rows as $w): ?>
    <li>
      <a class="list-item" href="?p=workout&id=<?= (int)$w['id'] ?>">
        <div class="li-date">
          <span class="li-day"><?= date('j', strtotime($w['started_at'])) ?></span>
          <span class="li-month"><?= h(MOIS[(int)date('n', strtotime($w['started_at'])) - 1]) ?></span>
        </div>
        <div class="li-main">
          <strong><?= h($w['name']) ?></strong>
          <?php if (!$w['ended_at']): ?><span class="tag live">en cours</span><?php endif; ?>
          <span class="muted small"><?= h(JOURS_LONG[(int)date('w', strtotime($w['started_at']))]) ?> · <?= h(fmt_duration($w['started_at'], $w['ended_at'])) ?>
            · <?= (int)$w['nsets'] ?> séries · <?= fmt_num($w['volume'] / 1000, 2) ?> t</span>
          <?php if (!empty($muscleBy[$w['id']])): ?>
            <span class="chips"><?php foreach (array_slice($muscleBy[$w['id']], 0, 5) as $m): ?><span class="chip"><?= h(muscle_label($m)) ?></span><?php endforeach; ?></span>
          <?php endif; ?>
        </div>
        <?= icon('chevron-right', 'icon chev') ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
