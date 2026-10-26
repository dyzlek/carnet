<?php
defined('ROOT') || exit; // pas d'accès direct
$me = current_user();
$errors = [];

function check_password(string $password): bool
{
    $hash = q('SELECT password_hash FROM users WHERE id = ?', [uid()])->fetchColumn();
    return password_verify($password, (string)$hash);
}

if (is_post()) {
    switch ($_POST['action'] ?? '') {
        case 'profile':
            $name = trim((string)($_POST['name'] ?? ''));
            $email = normalize_email((string)($_POST['email'] ?? ''));
            if ($name === '' || strlen($name) > 60) $errors['profile'][] = 'Indique un prénom ou pseudo (60 caractères max).';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['profile'][] = 'Adresse e-mail invalide.';
            if ($email !== $me['email']) {
                if (q('SELECT 1 FROM users WHERE email = ? AND id != ?', [$email, uid()])->fetchColumn()) $errors['profile'][] = 'Cette adresse est déjà utilisée.';
                if (!check_password((string)($_POST['password'] ?? ''))) $errors['profile'][] = "Mot de passe actuel requis pour changer d'adresse.";
            }
            if (empty($errors['profile'])) {
                q('UPDATE users SET name = ?, email = ? WHERE id = ?', [$name, $email, uid()]);
                flash('Profil mis à jour.');
                redirect('?p=account');
            }
            break;

        case 'password':
            $new = (string)($_POST['new'] ?? '');
            if (!check_password((string)($_POST['current'] ?? ''))) $errors['password'][] = 'Mot de passe actuel incorrect.';
            if (strlen($new) < PASSWORD_MIN) $errors['password'][] = 'Le nouveau mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.';
            if ($new !== (string)($_POST['confirm'] ?? '')) $errors['password'][] = 'Les deux mots de passe ne sont pas identiques.';
            if (empty($errors['password'])) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), uid()]);
                log_in(uid()); // nouvel identifiant de session
                flash('Mot de passe changé.');
                redirect('?p=account');
            }
            break;

        case 'registration':
            if (is_admin()) {
                set_registration(!empty($_POST['open']));
                flash(registration_open() ? 'Inscriptions ouvertes : tout le monde peut créer un compte.' : 'Inscriptions fermées.');
            }
            redirect('?p=account');

        case 'share':
            set_share_level((string)($_POST['level'] ?? ''));
            flash('Partage mis à jour.');
            redirect('?p=account#partage');

        case 'delete':
            if (!check_password((string)($_POST['password'] ?? ''))) {
                $errors['delete'][] = 'Mot de passe incorrect.';
                break;
            }
            delete_account(uid());
            log_out();
            start_session();
            flash('Compte supprimé, ainsi que toutes ses données.');
            redirect('?p=login');
    }
}

$title = 'Mon compte';
$stats = q('SELECT
    (SELECT COUNT(*) FROM workouts WHERE user_id = ?) AS workouts,
    (SELECT COUNT(*) FROM templates WHERE user_id = ?) AS templates,
    (SELECT COUNT(*) FROM bodyweight WHERE user_id = ?) AS weighins,
    (SELECT MIN(started_at) FROM workouts WHERE user_id = ?) AS first', array_fill(0, 4, uid()))->fetch();

function form_errors(array $errors, string $key): string
{
    if (empty($errors[$key])) return '';
    $li = implode('', array_map(fn($e) => '<li>' . h($e) . '</li>', $errors[$key]));
    return '<div class="form-error" role="alert">' . icon('x') . '<ul class="plain">' . $li . '</ul></div>';
}
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Membre depuis <?= h(fmt_date($me['created_at'], false)) ?><?= is_admin() ? ' · administrateur' : '' ?></p>
    <h1 class="page-title"><?= h($me['name']) ?></h1>
  </div>
  <form method="post" action="?p=logout"><button class="btn ghost"><?= icon('logout') ?>Se déconnecter</button></form>
</div>

<div class="tiles">
  <div class="tile"><span class="tile-l">Séances</span><span class="tile-v"><?= (int)$stats['workouts'] ?></span></div>
  <div class="tile"><span class="tile-l">Programmes</span><span class="tile-v"><?= (int)$stats['templates'] ?></span></div>
  <div class="tile"><span class="tile-l">Pesées</span><span class="tile-v"><?= (int)$stats['weighins'] ?></span></div>
  <div class="tile"><span class="tile-l">Première séance</span><span class="tile-v small-v"><?= $stats['first'] ? h(fmt_short_date($stats['first'])) : '–' ?></span></div>
</div>

<div class="grid-2">
  <section class="card">
    <h2>Profil</h2>
    <?= form_errors($errors, 'profile') ?>
    <form method="post" class="form-grid">
      <input type="hidden" name="action" value="profile">
      <label>Prénom ou pseudo <input name="name" value="<?= h($_POST['name'] ?? $me['name']) ?>" maxlength="60" required></label>
      <label>E-mail <input type="email" name="email" value="<?= h($_POST['email'] ?? $me['email']) ?>" autocomplete="email" required></label>
      <label>Mot de passe actuel <span class="hint">seulement pour changer d'e-mail</span>
        <input type="password" name="password" autocomplete="current-password"></label>
      <button class="btn">Enregistrer</button>
    </form>
  </section>

  <section class="card">
    <h2><?= icon('lock') ?> Mot de passe</h2>
    <?= form_errors($errors, 'password') ?>
    <form method="post" class="form-grid">
      <input type="hidden" name="action" value="password">
      <label>Mot de passe actuel <input type="password" name="current" autocomplete="current-password" required></label>
      <label>Nouveau mot de passe <span class="hint"><?= PASSWORD_MIN ?> caractères minimum</span>
        <input type="password" name="new" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" required></label>
      <label>Confirmer <input type="password" name="confirm" autocomplete="new-password" required></label>
      <button class="btn">Changer le mot de passe</button>
    </form>
  </section>
</div>

<?php if (is_admin()): $regOpen = registration_open(); ?>
<section class="card admin-card">
  <div class="card-head">
    <h2>Administration</h2>
    <span class="tag <?= $regOpen ? 'live' : '' ?>"><?= $regOpen ? 'Inscriptions ouvertes' : 'Inscriptions fermées' ?></span>
  </div>
  <p class="muted"><?= users_count() ?> compte<?= users_count() > 1 ? 's' : '' ?> sur ce carnet. Ouvre les inscriptions le temps qu'un proche crée son compte, puis referme-les : sur un serveur public, n'importe qui pourrait sinon s'inscrire.</p>
  <form method="post">
    <input type="hidden" name="action" value="registration">
    <?php if ($regOpen): ?>
      <button class="btn ghost"><?= icon('lock') ?>Fermer les inscriptions</button>
    <?php else: ?>
      <input type="hidden" name="open" value="1">
      <button class="btn ghost"><?= icon('user') ?>Ouvrir les inscriptions</button>
    <?php endif; ?>
  </form>
</section>
<?php endif; ?>

<?php
$shareLevel = share_level();
$shareOptions = [
    'none' => ['Rien', 'Tes amis ne voient aucune de tes séances. Tu vois toujours les leurs.'],
    'summary' => ['Résumé', 'Tes séances terminées : nom, date, durée, nombre d\'exercices et de séries, volume total et records battus.'],
    'full' => ['Détaillé', 'Le résumé, plus le détail des exercices avec charges et répétitions, et les muscles travaillés sur 30 jours.'],
];
?>
<section class="card" id="partage">
  <h2><?= icon('users') ?> Partage avec mes amis</h2>
  <p class="muted">Choisis ce que tes amis peuvent voir. Ton poids et tes mensurations ne sont jamais partagés, quel que soit le niveau.</p>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="share">
    <fieldset class="share-pick">
      <legend>Niveau de partage</legend>
      <?php foreach ($shareOptions as $k => [$label, $desc]): ?>
        <label class="share-opt">
          <input type="radio" name="level" value="<?= $k ?>" <?= $shareLevel === $k ? 'checked' : '' ?>>
          <span><strong><?= h($label) ?></strong><span class="muted small"><?= h($desc) ?></span></span>
        </label>
      <?php endforeach; ?>
    </fieldset>
    <div class="btn-row">
      <button class="btn">Enregistrer</button>
      <a class="btn ghost" href="?p=friends"><?= icon('users') ?>Mes amis</a>
    </div>
  </form>
</section>

<div class="grid-2">
  <section class="card">
    <h2>Mes données</h2>
    <p class="muted">Toutes tes séances, programmes, pesées et mensurations dans un fichier JSON lisible.</p>
    <a class="btn ghost" href="api.php?action=export"><?= icon('download') ?>Exporter mes données</a>
  </section>

  <section class="card danger-card">
    <h2>Supprimer le compte</h2>
    <p class="muted">Supprime définitivement le compte et toutes ses données. Pense à exporter avant.</p>
    <?= form_errors($errors, 'delete') ?>
    <form method="post" class="inline-form" data-confirm="Supprimer définitivement ton compte et toutes tes données ?">
      <input type="hidden" name="action" value="delete">
      <input type="password" name="password" placeholder="Mot de passe" autocomplete="current-password" aria-label="Mot de passe" required>
      <button class="btn danger"><?= icon('trash') ?>Supprimer</button>
    </form>
  </section>
</div>
