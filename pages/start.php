<?php
defined('ROOT') || exit; // pas d'accès direct
if (is_post()) {
    $tid = (int)($_POST['template'] ?? 0);
    $wid = start_workout($tid ?: null);
    redirect('?p=workout&id=' . $wid);
}

$title = 'Nouvelle séance';
$templates = q('SELECT t.*, (SELECT COUNT(*) FROM template_exercises te WHERE te.template_id = t.id) AS n,
                       (SELECT MAX(w.started_at) FROM workouts w WHERE w.template_id = t.id) AS last
                FROM templates t WHERE t.user_id = ? ORDER BY last DESC, t.name', [uid()])->fetchAll();
?>
<h1 class="page-title">Nouvelle séance</h1>
<div class="cards">
  <?php foreach ($templates as $t): ?>
    <form method="post" class="card start-card">
      <input type="hidden" name="template" value="<?= (int)$t['id'] ?>">
      <div>
        <h3><?= h($t['name']) ?></h3>
        <p class="muted"><?= (int)$t['n'] ?> exercices · dernière fois <?= h(ago($t['last'])) ?></p>
      </div>
      <button class="btn"><?= icon('play') ?>Démarrer</button>
    </form>
  <?php endforeach; ?>
  <form method="post" class="card start-card">
    <div>
      <h3>Séance libre</h3>
      <p class="muted">Ajoute les exercices au fur et à mesure.</p>
    </div>
    <button class="btn ghost">Démarrer</button>
  </form>
</div>
