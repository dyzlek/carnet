<?php
defined('ROOT') || exit; // pas d'accès direct
if (is_post() && ($_POST['action'] ?? '') === 'create') {
    q('INSERT INTO templates (user_id, name) VALUES (?, ?)', [uid(), trim((string)$_POST['name']) ?: 'Nouveau programme']);
    redirect('?p=template&id=' . db()->lastInsertId());
}

$title = 'Programmes';
$templates = q('SELECT t.*, (SELECT MAX(started_at) FROM workouts w WHERE w.template_id = t.id) AS last,
                       (SELECT COUNT(*) FROM workouts w WHERE w.template_id = t.id) AS times
                FROM templates t WHERE t.user_id = ? ORDER BY t.name', [uid()])->fetchAll();
?>
<div class="page-head">
  <h1 class="page-title">Programmes</h1>
  <form method="post" class="inline-form">
    <input type="hidden" name="action" value="create">
    <input name="name" placeholder="Nom (ex : Push, Pull…)" aria-label="Nom du programme">
    <button class="btn"><?= icon('plus') ?>Créer</button>
  </form>
</div>

<?php if (!$templates): ?><p class="empty">Aucun programme.</p><?php endif; ?>

<?php foreach ($templates as $t): $rows = template_exercises((int)$t['id']); ?>
  <section class="card">
    <div class="card-head">
      <div>
        <h2><?= h($t['name']) ?></h2>
        <p class="muted small"><?= (int)$t['times'] ?> fois · dernière <?= h(ago($t['last'])) ?></p>
      </div>
      <div class="btn-row">
        <a class="btn ghost sm" href="?p=template&id=<?= (int)$t['id'] ?>">Modifier</a>
        <form method="post" action="?p=start"><input type="hidden" name="template" value="<?= (int)$t['id'] ?>"><button class="btn sm"><?= icon('play') ?>Démarrer</button></form>
      </div>
    </div>
    <?php if ($t['notes']): ?><p class="muted"><?= h($t['notes']) ?></p><?php endif; ?>
    <div class="table-wrap">
      <table class="table prog">
        <thead><tr><th>Ordre</th><th>Exercice</th><th>Type</th><th>Muscle cible</th><th class="num">Séries</th><th class="num">Reps</th><th>Repos</th><th class="num">RPE</th><th>Consigne / surcharge</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): $m = exercise_muscles($r['exercise_id']); ?>
            <tr>
              <td><span class="badge"><?= h($r['label']) ?></span></td>
              <td><a href="?p=exercise&id=<?= h($r['exercise_id']) ?>"><?= h(ex_name($r)) ?></a></td>
              <td class="muted"><?= h($r['kind']) ?></td>
              <td><?= h($r['focus'] ?: muscles_text($m['primary'])) ?></td>
              <td class="num"><?= (int)$r['sets'] ?></td>
              <td class="num"><?= h(fmt_reps($r['reps_min'], $r['reps_max'])) ?></td>
              <td class="nowrap"><?= h(fmt_rest($r['rest_min'], $r['rest_max'])) ?></td>
              <td class="num"><?= h(fmt_rpe($r['rpe_min'], $r['rpe_max']) ?: '–') ?></td>
              <td class="small"><?= h($r['notes']) ?>
                <?php if ($r['overload_sets']): ?><span class="accent"> Si <?= (int)$r['overload_sets'] ?>×<?= (int)$r['overload_reps'] ?> : +<?= fmt_num($r['inc_min']) ?><?= $r['inc_max'] != $r['inc_min'] ? ' à ' . fmt_num($r['inc_max']) : '' ?> kg</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>
