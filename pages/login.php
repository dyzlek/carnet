<?php
defined('ROOT') || exit; // pas d'accès direct
$error = null;
$email = '';
if (is_post()) {
    $email = (string)($_POST['email'] ?? '');
    $error = attempt_login($email, (string)($_POST['password'] ?? ''));
    if ($error === null) redirect('./');
}
$title = 'Connexion';
$token = (string)($_GET['invite'] ?? '');
$invQs = preg_match('/^[0-9a-f]{64}$/', $token) ? '&amp;invite=' . $token : ''; // le lien d'invitation survit au détour par la connexion
?>
<div class="auth">
  <p class="eyebrow">Carnet d'entraînement</p>
  <h1 class="page-title">Connexion</h1>

  <?php if ($error): ?><div class="form-error" role="alert"><?= icon('x') ?><?= h($error) ?></div><?php endif; ?>

  <form method="post" class="form-grid" novalidate>
    <label>E-mail <input type="email" name="email" value="<?= h($email) ?>" autocomplete="username" required autofocus></label>
    <label>Mot de passe <input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn big">Se connecter</button>
  </form>

  <p class="auth-alt">Pas encore de compte ? <a href="?p=register<?= $invQs ?>">Créer un compte</a>
    <?php if (!registration_open()): ?><br><span class="small">Les inscriptions sont fermées pour l'instant : l'administrateur peut les ouvrir dans Mon compte.</span><?php endif; ?></p>
</div>
