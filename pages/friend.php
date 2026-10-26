<?php
defined('ROOT') || exit; // pas d'accès direct
// Profil d'un ami (?id=) ou détail d'une séance partagée (?workout=), avec kudos et commentaires.
$fid = (int)($_GET['id'] ?? 0);
$wid = (int)($_GET['workout'] ?? 0);
$title = 'Amis';
$commentError = null;

function not_found_friend(): void
{
    http_response_code(404);
    echo '<div class="page-head"><div><p class="eyebrow">Amis</p><h1 class="page-title">Introuvable</h1></div></div>'
        . '<section class="card"><p class="muted">Cette page n\'existe pas, ou elle n\'est pas partagée avec toi.</p>'
        . '<a class="btn ghost" href="?p=friends">' . icon('arrow-left') . 'Retour aux amis</a></section>';
}

if ($wid) {
    $w = friend_workout($wid);
    if (!$w) { not_found_friend(); return; }
    $mine = $w['user_id'] === uid();

    if (is_post()) {
        switch ($_POST['action'] ?? '') {
            case 'comment':
                $commentError = add_comment($wid, (string)($_POST['body'] ?? ''));
                if ($commentError === null) redirect("?p=friend&workout=$wid#comments");
                break;
            case 'delete_comment':
                delete_comment((int)($_POST['id'] ?? 0));
                redirect("?p=friend&workout=$wid#comments");
        }
    }
    $comments = workout_comments($wid);
    $title = $w['name'];
    ?>
<a class="back" href="<?= $mine ? '?p=friends&amp;tab=feed' : '?p=friend&amp;id=' . $w['user_id'] ?>"><?= icon('arrow-left') ?><?= $mine ? 'Fil d\'activité' : h($w['user_name']) ?></a>
<div class="workout-head">
  <div>
    <p class="eyebrow"><?= h($mine ? 'Toi' : $w['user_name']) ?> · <?= h(fmt_date($w['started_at'], true, true)) ?> · <?= h(fmt_duration($w['started_at'], $w['ended_at'])) ?></p>
    <h1 class="page-title"><?= h($w['name']) ?></h1>
  </div>
  <?php if ($mine): ?><a class="btn ghost" href="?p=workout&amp;id=<?= $w['id'] ?>">Ouvrir dans mon carnet<?= icon('arrow-right') ?></a><?php endif; ?>
</div>

<div class="stats-row">
  <div class="stat"><span class="stat-v"><?= $w['sets_done'] ?></span><span class="stat-l">séries</span></div>
  <div class="stat"><span class="stat-v"><?= $w['exercises'] ?></span><span class="stat-l">exercices</span></div>
  <div class="stat"><span class="stat-v"><?= fmt_num($w['volume'] / 1000, 2) ?></span><span class="stat-l">tonnes soulevées</span></div>
</div>

<?php if ($w['prs']): ?>
  <section class="card pr-card">
    <h2><?= icon('trophy') ?> Records battus</h2>
    <ul class="plain">
      <?php foreach ($w['prs'] as $p): ?>
        <li><strong><?= h($p['name']) ?></strong> — <?= h($p['what']) ?> <span class="accent"><?= h($p['value']) ?></span> <span class="muted">(avant : <?= h($p['before']) ?>)</span></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<div class="grid-2">
  <section class="card">
    <h2>Exercices</h2>
    <?php if ($w['detail'] === null): ?>
      <p class="muted"><?= h($w['user_name']) ?> ne partage que le résumé de ses séances : le détail des exercices et des charges reste privé.</p>
    <?php elseif (!$w['detail']): ?>
      <p class="empty">Aucune série enregistrée.</p>
    <?php else: ?>
      <div class="sh-list">
        <?php foreach ($w['detail'] as $ex): ?>
          <div class="sh-ex">
            <h3><?= h($ex['name']) ?></h3>
            <ol class="plain sh-sets">
              <?php foreach ($ex['sets'] as $i => $s): ?>
                <li><span class="set-n"><?= $i + 1 ?></span><span class="mono"><?= $s['weight'] !== null ? h(fmt_num($s['weight'])) . ' kg × ' : '' ?><?= (int)$s['reps'] ?><?= $s['weight'] === null ? ' rép.' : '' ?></span></li>
              <?php endforeach; ?>
            </ol>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" id="comments">
    <div class="card-head">
      <h2>Réactions</h2>
      <?= $mine ? '<span class="kudos-static">' . icon('heart') . (int)$w['kudos'] . ' kudos</span>' : kudos_button($w) ?>
    </div>
    <?php if (!$comments): ?><p class="empty">Pas encore de commentaire.</p><?php endif; ?>
    <ul class="plain comments">
      <?php foreach ($comments as $c): ?>
        <li class="comment">
          <?= avatar($c['user_name']) ?>
          <div class="comment-main">
            <p class="comment-meta"><strong><?= h($c['user_id'] === uid() ? 'Toi' : $c['user_name']) ?></strong> <span class="muted small">· <?= h(ago($c['created_at'])) ?></span></p>
            <p class="comment-body"><?= nl2br(h($c['body'])) ?></p>
          </div>
          <?php if ($c['can_delete']): ?>
            <form method="post" data-confirm="Supprimer ce commentaire ?">
              <input type="hidden" name="action" value="delete_comment">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button class="icon-btn" aria-label="Supprimer le commentaire"><?= icon('trash') ?></button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($commentError): ?><div class="form-error" role="alert"><?= icon('x') ?><?= h($commentError) ?></div><?php endif; ?>
    <form method="post" class="form-grid comment-form">
      <input type="hidden" name="action" value="comment">
      <label>Ajouter un commentaire <textarea name="body" rows="2" maxlength="<?= COMMENT_MAX ?>" required><?= h($commentError ? ($_POST['body'] ?? '') : '') ?></textarea></label>
      <button class="btn"><?= icon('message') ?>Publier</button>
    </form>
  </section>
</div>
<?php
    return;
}

// --- Profil -----------------------------------------------------------------
$p = friend_profile($fid);
if (!$p) { not_found_friend(); return; }

if (is_post() && ($_POST['action'] ?? '') === 'remove') {
    friend_remove($fid);
    flash($p['name'] . ' a été retiré de tes amis.');
    redirect('?p=friends&tab=friends');
}
$title = $p['name'];
$level = $p['share_level'];
?>
<a class="back" href="?p=friends&amp;tab=friends"><?= icon('arrow-left') ?>Amis</a>
<div class="page-head">
  <div class="profile-id">
    <?= avatar($p['name'], 'xl') ?>
    <div>
      <p class="eyebrow">Ami<?= $level === 'full' ? ' · partage détaillé' : ($level === 'summary' ? ' · partage du résumé' : '') ?></p>
      <h1 class="page-title"><?= h($p['name']) ?></h1>
    </div>
  </div>
  <form method="post" data-confirm="Retirer <?= h($p['name']) ?> de tes amis ?">
    <input type="hidden" name="action" value="remove">
    <button class="btn danger"><?= icon('x') ?>Retirer</button>
  </form>
</div>

<?php if ($level === 'none'): ?>
  <section class="card">
    <h2>Rien de partagé</h2>
    <p class="muted"><?= h($p['name']) ?> ne partage pas ses séances pour le moment. Vous restez amis : ses séances apparaîtront ici et dans ton fil dès qu'il ou elle activera le partage.</p>
  </section>
<?php else: ?>
  <div class="tiles tiles-3">
    <div class="tile"><span class="tile-l">Séances · 30 j</span><span class="tile-v"><?= $p['workouts_30d'] ?></span></div>
    <div class="tile"><span class="tile-l">Volume · 30 j</span><span class="tile-v"><?= fmt_num($p['volume_30d'] / 1000, 1) ?><small> t</small></span></div>
    <div class="tile"><span class="tile-l">Records récents</span><span class="tile-v"><?= count($p['prs']) ?></span></div>
  </div>

  <div class="grid-2">
    <section>
      <h2>Séances récentes</h2>
      <?php if (!$p['recent_workouts']): ?><p class="empty">Aucune séance terminée pour l'instant.</p><?php endif; ?>
      <div class="feed">
        <?php foreach ($p['recent_workouts'] as $w) echo workout_card($w, false); ?>
      </div>
    </section>

    <div class="stack">
      <section class="card">
        <h2><?= icon('trophy') ?> Records récents</h2>
        <?php if (!$p['prs']): ?><p class="muted">Aucun record récent.</p><?php endif; ?>
        <ul class="plain">
          <?php foreach ($p['prs'] as $r): ?>
            <li class="row-between"><span><strong><?= h($r['name']) ?></strong><br><span class="muted small"><?= h($r['what']) ?> · <?= h(fmt_date($r['date'])) ?></span></span>
              <span class="accent"><?= h($r['value']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </section>

      <?php if ($level === 'full'): ?>
        <section class="card">
          <h2>Muscles · 30 jours</h2>
          <?= muscle_bars($p['muscles'], 30, 8) ?>
        </section>
      <?php else: ?>
        <section class="card">
          <h2>Muscles</h2>
          <p class="muted"><?= h($p['name']) ?> ne partage que le résumé : pas de détail des exercices ni des muscles travaillés.</p>
        </section>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
