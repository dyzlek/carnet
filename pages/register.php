<?php
defined('ROOT') || exit; // pas d'accès direct
$errors = [];
$name = $email = '';
$first = users_count() === 0;
// Invitation d'un ami : jeton dans le lien (?invite=) ou le champ caché
$token = (string)($_POST['invite'] ?? $_GET['invite'] ?? '');
$invite = $token !== '' ? invite_by_token($token) : null;
$badInvite = $token !== '' && !$invite;
if (is_post()) {
    $name = (string)($_POST['name'] ?? '');
    $email = (string)($_POST['email'] ?? '');
    $errors = register($name, $email, (string)($_POST['password'] ?? ''), (string)($_POST['confirm'] ?? ''), $invite ? $token : null);
    if (!$errors) {
        flash($invite ? 'Compte créé. Tu es maintenant ami avec ' . $invite['inviter_name'] . '.' : ($first ? 'Compte créé. Tes séances et programmes existants y sont rattachés.' : 'Compte créé. Tes programmes Jambes, Dos & Biceps et Push sont prêts.'));
        redirect('./');
    }
}
$title = 'Créer un compte';
if (!registration_open() && !$invite):
?>
<div class="auth">
  <p class="eyebrow">Carnet d'entraînement</p>
  <h1 class="page-title">Inscriptions fermées</h1>
  <?php if ($badInvite): ?><div class="form-error" role="alert"><?= icon('x') ?>Ce lien d'invitation n'est plus valable : il a expiré ou a déjà été utilisé. Demande à ton ami d'en générer un nouveau.</div><?php endif; ?>
  <p class="lede">Les nouveaux comptes sont désactivés sur ce carnet. Demande à l'administrateur de les ouvrir
    (Mon compte → Administration → Ouvrir les inscriptions).</p>
  <p class="auth-alt">Déjà un compte ? <a href="?p=login">Se connecter</a></p>
</div>
<?php return; endif; ?>
<div class="auth">
  <p class="eyebrow">Carnet d'entraînement</p>
  <h1 class="page-title">Créer un compte</h1>
  <?php if ($invite): ?>
    <div class="invite-banner"><?= avatar($invite['inviter_name'], 'lg') ?><p><strong><?= h($invite['inviter_name']) ?></strong> t'invite sur Carnet.<br><span class="muted small">Vous serez amis dès ton inscription.</span></p></div>
  <?php elseif ($badInvite): ?>
    <div class="form-error" role="alert"><?= icon('x') ?>Ce lien d'invitation n'est plus valable : il a expiré ou a déjà été utilisé. Tu peux quand même créer un compte si les inscriptions sont ouvertes.</div>
  <?php endif; ?>
  <?php if ($first): ?>
    <p class="lede">Premier compte de l'app : il récupère les séances, programmes et mesures déjà enregistrés.</p>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="form-error" role="alert"><?= icon('x') ?><ul class="plain"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" class="form-grid" novalidate>
    <?php if ($invite): ?><input type="hidden" name="invite" value="<?= h($token) ?>"><?php endif; ?>
    <label>Prénom ou pseudo <input name="name" value="<?= h($name) ?>" autocomplete="nickname" maxlength="60" required autofocus></label>
    <label>E-mail <input type="email" name="email" value="<?= h($email) ?>" autocomplete="email" required></label>
    <label>Mot de passe <span class="hint"><?= PASSWORD_MIN ?> caractères minimum</span>
      <input type="password" name="password" autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" required></label>
    <label>Confirmer le mot de passe <input type="password" name="confirm" autocomplete="new-password" required></label>
    <button class="btn big">Créer mon compte</button>
  </form>

  <?php if (!$first): ?><p class="auth-alt">Déjà un compte ? <a href="?p=login<?= $invite ? '&amp;invite=' . h($token) : '' ?>">Se connecter</a></p><?php endif; ?>
</div>
