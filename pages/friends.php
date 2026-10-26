<?php
defined('ROOT') || exit; // pas d'accès direct
$me = current_user();
$tabs = ['feed' => 'Fil', 'friends' => 'Amis', 'requests' => 'Demandes'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'feed';
$error = null;

/** Adresse absolue du lien d'invitation, construite depuis l'hôte courant. */
function invite_url(string $token): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) $host = 'localhost';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/?p=register&invite=' . $token;
}

if (is_post()) {
    switch ($_POST['action'] ?? '') {
        case 'add':
            $before = count(pending_outgoing());
            $error = friend_request((string)($_POST['who'] ?? ''));
            if ($error === null) {
                flash(count(pending_outgoing()) > $before ? 'Demande envoyée.' : 'Vous êtes maintenant amis.');
                redirect('?p=friends&tab=friends');
            }
            $tab = 'friends';
            break;
        case 'respond':
            $accept = ($_POST['answer'] ?? '') === 'accept';
            friend_respond((int)($_POST['id'] ?? 0), $accept);
            flash($accept ? 'Demande acceptée.' : 'Demande refusée.');
            redirect('?p=friends&tab=requests');
        case 'remove':
            $fromRequests = ($_POST['from'] ?? '') === 'requests';
            friend_remove((int)($_POST['user'] ?? 0));
            flash($fromRequests ? 'Demande annulée.' : 'Ami retiré.');
            redirect('?p=friends&tab=' . ($fromRequests ? 'requests' : 'friends'));
        case 'invite':
            $_SESSION['new_invite'] = create_invite();
            redirect('?p=friends&tab=friends#invite');
    }
}

$title = 'Amis';
$incoming = pending_incoming();
$outgoing = pending_outgoing();
$friends = friends_list();
$newInvite = $_SESSION['new_invite'] ?? null;
unset($_SESSION['new_invite']);
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Carnet entre amis</p>
    <h1 class="page-title">Amis</h1>
  </div>
  <nav class="seg" aria-label="Sections">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="?p=friends&amp;tab=<?= $k ?>" class="<?= $tab === $k ? 'is-active' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= h($label) ?><?= $k === 'requests' && $incoming ? ' (' . count($incoming) . ')' : '' ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if ($tab === 'feed'):
    $before = (string)($_GET['before'] ?? '');
    $pageSize = 20;
    $items = feed($pageSize + 1, $before !== '' && strtotime($before) ? $before : null);
    $more = count($items) > $pageSize;
    $items = array_slice($items, 0, $pageSize);
?>
  <?php if (!$items): ?>
    <section class="card">
      <h2>Rien à voir pour l'instant</h2>
      <p class="muted"><?= $friends ? 'Les séances terminées de tes amis (et les tiennes) apparaîtront ici.' : 'Ajoute des amis pour voir leurs séances ici et leur envoyer des kudos.' ?></p>
      <a class="btn ghost" href="?p=friends&amp;tab=friends"><?= icon('users') ?><?= $friends ? 'Mes amis' : 'Ajouter un ami' ?></a>
    </section>
  <?php else: ?>
    <div class="feed">
      <?php foreach ($items as $w) echo workout_card($w); ?>
    </div>
    <?php if ($more): $last = end($items); ?>
      <p class="feed-more"><a class="btn ghost" href="?p=friends&amp;tab=feed&amp;before=<?= urlencode($last['started_at']) ?>">Charger plus</a></p>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif ($tab === 'friends'): ?>
  <div class="grid-2">
    <section class="card">
      <div class="card-head"><h2>Mes amis</h2><span class="muted small"><?= count($friends) ?></span></div>
      <?php if (!$friends): ?><p class="empty">Aucun ami pour l'instant. Ajoute-en un ou envoie un lien d'invitation.</p><?php endif; ?>
      <ul class="plain">
        <?php foreach ($friends as $f): ?>
          <li class="friend-row">
            <a class="friend-main" href="?p=friend&amp;id=<?= $f['id'] ?>">
              <?= avatar($f['name'], 'lg') ?>
              <span><strong><?= h($f['name']) ?></strong><br><span class="muted small"><?= $f['last_workout_at'] ? 'dernière séance ' . h(ago($f['last_workout_at'])) : 'aucune séance visible' ?></span></span>
            </a>
            <form method="post" data-confirm="Retirer <?= h($f['name']) ?> de tes amis ?">
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="user" value="<?= $f['id'] ?>">
              <button class="btn ghost sm" aria-label="Retirer <?= h($f['name']) ?>"><?= icon('x') ?>Retirer</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <div class="stack">
      <section class="card">
        <h2>Ajouter un ami</h2>
        <?php if ($error): ?><div class="form-error" role="alert"><?= icon('x') ?><?= h($error) ?></div><?php endif; ?>
        <form method="post" class="form-grid">
          <input type="hidden" name="action" value="add">
          <label>E-mail ou pseudo exact <input name="who" value="<?= h($_POST['who'] ?? '') ?>" autocomplete="off" required></label>
          <button class="btn"><?= icon('users') ?>Envoyer la demande</button>
        </form>
      </section>

      <section class="card" id="invite">
        <h2>Inviter quelqu'un</h2>
        <p class="muted">Pour un ami qui n'a pas encore de compte : il s'inscrit avec ton lien et vous devenez amis tout de suite.
          Le lien est à usage unique et valable <?= INVITE_DAYS ?> jours.</p>
        <?php if ($newInvite): $url = invite_url($newInvite); ?>
          <div class="invite-box">
            <input type="text" id="invite-url" value="<?= h($url) ?>" readonly aria-label="Lien d'invitation">
            <button type="button" class="btn" data-copy="invite-url"><?= icon('copy') ?><span>Copier</span></button>
          </div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="invite">
          <button class="btn <?= $newInvite ? 'ghost' : '' ?>"><?= icon('link') ?><?= $newInvite ? 'Générer un autre lien' : "Générer un lien d'invitation" ?></button>
        </form>
      </section>
    </div>
  </div>

<?php else: ?>
  <div class="grid-2">
    <section class="card">
      <h2>Demandes reçues</h2>
      <?php if (!$incoming): ?><p class="empty">Aucune demande en attente.</p><?php endif; ?>
      <ul class="plain">
        <?php foreach ($incoming as $r): ?>
          <li class="friend-row">
            <span class="friend-main"><?= avatar($r['name'], 'lg') ?><span><strong><?= h($r['name']) ?></strong><br><span class="muted small"><?= h(ago($r['created_at'])) ?></span></span></span>
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
    <section class="card">
      <h2>Demandes envoyées</h2>
      <?php if (!$outgoing): ?><p class="empty">Aucune demande en attente.</p><?php endif; ?>
      <ul class="plain">
        <?php foreach ($outgoing as $r): ?>
          <li class="friend-row">
            <span class="friend-main"><?= avatar($r['name'], 'lg') ?><span><strong><?= h($r['name']) ?></strong><br><span class="muted small">en attente · <?= h(ago($r['created_at'])) ?></span></span></span>
            <form method="post">
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="from" value="requests">
              <input type="hidden" name="user" value="<?= (int)$r['user_id'] ?>">
              <button class="btn ghost sm">Annuler</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
<?php endif; ?>
