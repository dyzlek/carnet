<?php
require __DIR__ . '/src/bootstrap.php';

const PAGES = ['dashboard', 'start', 'workout', 'workouts', 'muscles', 'exercises', 'exercise', 'templates', 'template', 'body', 'account', 'activities', 'activity', 'friends', 'friend', 'feed'];
const PUBLIC_PAGES = ['login', 'register'];

$page = $_GET['p'] ?? 'dashboard';
if (!in_array($page, array_merge(PAGES, PUBLIC_PAGES, ['logout']), true)) $page = 'dashboard';

// Tous les envois de formulaire doivent porter le jeton CSRF de la session.
if (is_post() && !csrf_valid($_POST['_csrf'] ?? null)) {
    http_response_code(400);
    flash('La page avait expiré, recommence.');
    redirect('./' . ($_GET ? '?' . http_build_query($_GET) : '')); // jamais l'URL brute (redirection ouverte « //site »)
}

if ($page === 'logout') {
    if (is_post()) log_out();
    redirect('?p=login');
}

$user = current_user();
if (!$user && !in_array($page, PUBLIC_PAGES, true)) {
    redirect(users_count() === 0 ? '?p=register' : '?p=login');
}
if ($user && in_array($page, PUBLIC_PAGES, true)) {
    // Lien d'invitation ouvert en étant déjà connecté : on devient ami avec l'inviteur plutôt que d'ignorer le lien
    $token = (string)($_GET['invite'] ?? '');
    if ($token === '') redirect('./');
    $r = accept_invite($token);
    flash(match ($r['status']) {
        'ok' => 'Tu es maintenant ami avec ' . $r['name'] . '.',
        'already' => 'Tu es déjà ami avec ' . $r['name'] . '.',
        'self' => "C'est ton propre lien d'invitation : envoie-le à un ami.",
        default => "Ce lien d'invitation n'est plus valable : il a expiré ou a déjà été utilisé.",
    });
    redirect('?p=friends&tab=friends');
}

$title = 'Carnet';
$nav = $page;
$bare = in_array($page, PUBLIC_PAGES, true);
ob_start();
require __DIR__ . "/pages/$page.php";
$content = ob_get_clean();
ob_start();
require __DIR__ . '/pages/_layout.php';
echo inject_csrf(ob_get_clean());
