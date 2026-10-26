<?php
defined('ROOT') || exit; // pas d'accès direct
// Colonne latérale du fil (écrans larges seulement, voir .feed-side) : demandes reçues, ajout d'ami, lien d'invitation.
// Réutilise $incoming de friends.php ; les actions sont traitées par friends.php (elles redirigent vers l'onglet Amis / Demandes).
?>
<aside class="feed-side stack" aria-label="Amis">
  <?php if ($incoming): ?>
    <section class="card">
      <div class="card-head"><h2>Demandes reçues</h2><a class="more-link" href="?p=friends&amp;tab=requests"><?= count($incoming) ?></a></div>
      <ul class="plain">
        <?php foreach (array_slice($incoming, 0, 3) as $r): ?>
          <li class="friend-row">
            <span class="friend-main"><?= avatar($r['name'], 'lg') ?><strong><?= h($r['name']) ?></strong></span>
            <form method="post" class="btn-row">
              <input type="hidden" name="action" value="respond">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn sm" name="answer" value="accept"><?= icon('check') ?>Accepter</button>
              <button class="btn ghost sm" name="answer" value="refuse">Refuser</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
  <section class="card">
    <h2>Ajouter un ami</h2>
    <form method="post" class="form-grid">
      <input type="hidden" name="action" value="add">
      <label>E-mail ou pseudo exact <input name="who" autocomplete="off" required></label>
      <button class="btn"><?= icon('users') ?>Envoyer la demande</button>
    </form>
  </section>
  <section class="card">
    <h2>Inviter quelqu'un</h2>
    <p class="muted small">Un lien à usage unique pour un ami sans compte.</p>
    <form method="post">
      <input type="hidden" name="action" value="invite">
      <button class="btn ghost"><?= icon('link') ?>Générer un lien d'invitation</button>
    </form>
  </section>
</aside>
