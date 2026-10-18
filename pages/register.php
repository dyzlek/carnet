<?php
defined('ROOT') || exit; // pas d'accès direct
$errors = [];
$name = $email = '';
$first = users_count() === 0;
if (is_post()) {
    $name = (string)($_POST['name'] ?? '');
    $email = (string)($_POST['email'] ?? '');
    $errors = register($name, $email, (string)($_POST['password'] ?? ''), (string)($_POST['confirm'] ?? ''));
    if (!$errors) {
        flash($first ? 'Compte créé. Tes séances et programmes existants y sont rattachés.' : 'Compte créé. Tes programmes Jambes, Dos & Biceps et Push sont prêts.');
        redirect('./');
    }
}
$title = 'Créer un compte';
if (!registration_open()):
?>
<div class="auth">
  <p class="eyebrow">Carnet d'entraînement</p>
  <h1 class="page-title">Inscriptions fermées</h1>
  <p class="lede">Les nouveaux comptes sont désactivés sur ce carnet. Demande à l'administrateur de les ouvrir.</p>
  <p class="auth-alt"><a href="?p=login">Se connecter</a></p>
</div>
<?php return; endif; ?>
<div class="auth">
  <p class="eyebrow">Carnet d'entraînement</p>
  <h1 class="page-title">Créer un compte</h1>
  <?php if ($first): ?>
    <p class="lede">Premier compte de l'app : il récupère les séances, programmes et mesures déjà enregistrés.</p>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="form-error" role="alert"><?= icon('x') ?><ul class="plain"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="form-grid" novalidate>
    <label>Prénom ou pseudo <input name="name" value="<?= h($name) ?>" autocomplete="nickname" maxlength="60" required autofocus></label>
    <label>E-mail <input type="email" name="email" value="<?= h($email) ?>" autocomplete="email" required></label>
    <label>Mot de passe <span class="hint"><?= PASSWORD_MIN ?> caractères minimum</span>
      <input type="password" name="password" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" required></label>
    <label>Confirmer le mot de passe <input type="password" name="confirm" autocomplete="new-password" required></label>
    <button class="btn big">Créer mon compte</button>
  </form>

  <?php if (!$first): ?><p class="auth-alt">Déjà un compte ? <a href="?p=login">Se connecter</a></p><?php endif; ?>
</div>
