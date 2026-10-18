<?php
defined('ROOT') || exit; // pas d'accès direct
$links = [
    'dashboard' => ['Accueil', 'home'],
    'workouts'  => ['Séances', 'log'],
    'activities' => ['Activités', 'act-run'],
    'templates' => ['Programmes', 'clipboard'],
    'body'      => ['Corps', 'scale'],
];
// 5 onglets : Muscles s'ouvre depuis l'accueil, le catalogue d'exercices depuis Programmes
$navMap = ['workout' => 'workouts', 'start' => 'workouts', 'muscles' => 'dashboard', 'exercises' => 'templates', 'exercise' => 'templates',
           'template' => 'templates', 'activity' => 'activities'];
$active = $navMap[$nav] ?? $nav;
$me = current_user();
$open = $me ? q('SELECT id, name, started_at FROM workouts WHERE ended_at IS NULL AND user_id = ? ORDER BY started_at DESC LIMIT 1', [$me['id']])->fetch() : null;
$msg = flash();
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#131210" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f3efe6" media="(prefers-color-scheme: light)">
<title><?= h($title) ?> · Carnet</title>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Carnet">
<script src="assets/theme.js?v=1"></script>
<link rel="stylesheet" href="assets/fonts.css?v=1">
<link rel="stylesheet" href="assets/app.css?v=17">
<script src="assets/vendor/chart.umd.min.js?v=4.4.1" defer></script>
<script src="assets/app.js?v=11" defer></script>
</head>
<body class="page-<?= h($nav) ?><?= $bare ? ' is-bare' : '' ?>">
<header class="topbar">
  <a class="brand" href="./" aria-label="Carnet — accueil"><span class="brand-mark" aria-hidden="true"></span>Carnet</a>
  <?php if (!$bare): ?>
  <nav class="nav" aria-label="Navigation principale">
    <?php foreach ($links as $k => [$label, $ic]): ?>
      <a href="?p=<?= $k ?>" class="<?= $active === $k ? 'is-active' : '' ?>" <?= $active === $k ? 'aria-current="page"' : '' ?>>
        <?= icon($ic) ?><span><?= h($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Changer de thème">
    <?= icon('sun', 'icon i-sun') ?><?= icon('moon', 'icon i-moon') ?>
  </button>
  <?php if ($me): ?>
    <details class="user-menu">
      <summary aria-label="Mon compte"><span class="avatar" aria-hidden="true"><?= h(strtoupper(substr($me['name'], 0, 1))) ?></span><span class="user-name"><?= h($me['name']) ?></span></summary>
      <div class="menu-pop">
        <p class="menu-who"><strong><?= h($me['name']) ?></strong><br><span class="muted small"><?= h($me['email']) ?></span></p>
        <a href="?p=account"><?= icon('user') ?>Mon compte</a>
        <form method="post" action="?p=logout"><button type="submit"><?= icon('logout') ?>Se déconnecter</button></form>
      </div>
    </details>
  <?php endif; ?>
</header>

<?php if ($open && !($nav === 'workout' && (int)($_GET['id'] ?? 0) === (int)$open['id'])): ?>
  <a class="live-banner" href="?p=workout&id=<?= (int)$open['id'] ?>">
    <span class="dot" aria-hidden="true"></span>
    <span>En cours · <strong><?= h($open['name']) ?></strong></span>
    <span class="mono live-elapsed" data-since="<?= h(date('c', strtotime($open['started_at']))) ?>"></span>
    <span class="go">Reprendre <?= icon('arrow-right') ?></span>
  </a>
<?php endif; ?>

<main class="wrap">
  <?php if ($msg): ?><div class="flash" role="status"><?= icon('check') ?><?= h($msg) ?></div><?php endif; ?>
  <?= $content ?>
</main>

<?php if (!$bare): ?>
<!-- Sélecteur d'exercice (partagé) -->
<dialog id="picker" class="picker" aria-label="Choisir un exercice">
  <form method="dialog" class="picker-head">
    <input type="search" id="picker-q" placeholder="Rechercher : leg press, curl, squat…" autocomplete="off">
    <select id="picker-muscle" aria-label="Muscle">
      <option value="">Tous les muscles</option>
      <?php foreach (MUSCLES as $k => [$l]): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?>
    </select>
    <button class="icon-btn" value="cancel" aria-label="Fermer"><?= icon('x') ?></button>
  </form>
  <div id="picker-list" class="picker-list" aria-live="polite"></div>
</dialog>

<!-- Visionneuse d'animation d'exercice -->
<dialog id="viewer" class="viewer" aria-labelledby="viewer-title">
  <div class="viewer-head">
    <div>
      <h2 id="viewer-title">Exercice</h2>
      <p class="muted small" id="viewer-sub"></p>
    </div>
    <button type="button" class="icon-btn" data-viewer-close aria-label="Fermer"><?= icon('x') ?></button>
  </div>
  <div class="viewer-body">
    <div class="viewer-stage" id="viewer-stage" title="Cliquer pour zoomer">
      <img id="viewer-img" alt="" draggable="false">
      <canvas id="viewer-freeze" hidden></canvas>
      <span class="viewer-loading" id="viewer-loading">Chargement…</span>
    </div>
    <div class="viewer-tools">
      <button type="button" class="btn ghost sm" id="viewer-zoom"><?= icon('zoom-in') ?><span>Zoomer</span></button>
      <button type="button" class="btn ghost sm" id="viewer-pause"><?= icon('pause') ?><span>Pause</span></button>
      <a class="btn ghost sm" id="viewer-link" href="#">Fiche de l'exercice<?= icon('arrow-right') ?></a>
    </div>
    <div class="viewer-info">
      <div class="chips" id="viewer-muscles"></div>
      <ol class="steps" id="viewer-steps"></ol>
    </div>
  </div>
</dialog>

<!-- Minuteur de repos -->
<div id="rest" class="rest" hidden role="timer" aria-live="off">
  <div class="rest-bar"><span></span></div>
  <p class="rest-next" hidden></p>
  <div class="rest-body">
    <?= icon('timer') ?>
    <span class="rest-label">Repos</span>
    <strong class="rest-time">0:00</strong>
    <button type="button" class="btn ghost sm" data-rest="-15">−15 s</button>
    <button type="button" class="btn ghost sm" data-rest="15">+15 s</button>
    <button type="button" class="btn sm" data-rest="skip">Passer</button>
  </div>
</div>
<?php endif; ?>
</body>
</html>
