<?php
defined('ROOT') || exit; // pas d'accès direct
// Comptes : session, inscription, connexion, CSRF, anti force brute.

const SESSION_DAYS = 30;
const LOGIN_MAX_FAILS = 8;       // essais ratés autorisés…
const LOGIN_WINDOW = 15 * 60;    // …sur 15 minutes, par adresse e-mail
const PASSWORD_MIN = 8;

function start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)(SESSION_DAYS * 86400));
    ini_set('session.use_strict_mode', '1');
    session_name('carnet_sid');
    session_set_cookie_params([
        'lifetime' => SESSION_DAYS * 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => is_https(),
    ]);
    session_start();
}

function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($refresh) $user = false; // changement de compte en cours de requête (tests)
    if ($user !== false) return $user;
    $id = $_SESSION['uid'] ?? null;
    $user = $id ? (q('SELECT id, email, name, created_at FROM users WHERE id = ?', [$id])->fetch() ?: null) : null;
    if (!$user) unset($_SESSION['uid']);
    return $user;
}

/** Identifiant du compte connecté (les pages protégées garantissent qu'il existe). */
function uid(): int
{
    $u = current_user();
    if (!$u) throw new RuntimeException('Non connecté');
    return (int)$u['id'];
}

function users_count(): int
{
    return (int)q('SELECT COUNT(*) FROM users')->fetchColumn();
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/** Inscriptions : toujours possibles pour le tout premier compte, ensuite selon le choix de l'administrateur (fermées par défaut). */
function registration_open(): bool
{
    if (users_count() === 0) return true;
    return q("SELECT value FROM settings WHERE `key` = 'registration'")->fetchColumn() === 'open';
}

function set_registration(bool $open): void
{
    q(upsert_sql('settings', ['key', 'value'], ['key'], ['value']), ['registration', $open ? 'open' : 'closed']);
}

/**
 * Retourne une liste d'erreurs (vide = compte créé et connecté).
 * Un jeton d'invitation valide permet de s'inscrire même quand les inscriptions sont fermées.
 */
function register(string $name, string $email, string $password, string $confirm, ?string $inviteToken = null): array
{
    $name = trim($name);
    $email = normalize_email($email);
    $invite = $inviteToken !== null && $inviteToken !== '' ? invite_by_token($inviteToken) : null;
    if (!$invite && !registration_open()) return ['Les inscriptions sont fermées.'];
    $errors = [];
    if ($name === '' || strlen($name) > 60) $errors[] = 'Indique un prénom ou pseudo (60 caractères max).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Adresse e-mail invalide.';
    if (strlen($password) < PASSWORD_MIN) $errors[] = 'Le mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.';
    if ($password !== $confirm) $errors[] = 'Les deux mots de passe ne sont pas identiques.';
    if (!$errors && q('SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn()) {
        $errors[] = 'Un compte existe déjà avec cette adresse.';
    }
    if ($errors) return $errors;

    $pdo = db();
    q('INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)', [$email, $name, password_hash($password, PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    if (users_count() === 1) claim_orphans($pdo, $uid);
    seed_programs($pdo, $uid);
    if ($invite) consume_invite($invite['token'], $uid);
    log_in($uid);
    return [];
}

/** Le premier compte récupère les données créées avant l'arrivée des comptes. */
function claim_orphans(PDO $pdo, int $uid): void
{
    $pdo->beginTransaction();
    foreach (['templates', 'workouts', 'bodyweight', 'measurements'] as $t) {
        $pdo->prepare("UPDATE $t SET user_id = ? WHERE user_id IS NULL")->execute([$uid]);
    }
    $pdo->prepare('UPDATE exercises SET owner_id = ? WHERE is_custom = 1 AND owner_id IS NULL')->execute([$uid]);
    $pdo->prepare(insert_or('IGNORE') . " INTO user_settings (user_id, `key`, value)
                   SELECT ?, `key`, value FROM settings WHERE `key` IN ('height', 'goal_weight', 'seeded_programs')")->execute([$uid]);
    $pdo->commit();
}

function login_blocked(string $email): bool
{
    $n = (int)q('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND at > ?', [$email, time() - LOGIN_WINDOW])->fetchColumn();
    return $n >= LOGIN_MAX_FAILS;
}

/** null = connecté ; sinon message d'erreur. */
function attempt_login(string $email, string $password): ?string
{
    $email = normalize_email($email);
    if (login_blocked($email)) return 'Trop de tentatives. Réessaie dans quelques minutes.';
    $u = q('SELECT id, password_hash FROM users WHERE email = ?', [$email])->fetch();
    // Même coût de calcul que le compte existe ou non (pas d'indice sur les adresses inscrites).
    $hash = $u['password_hash'] ?? password_hash('compte-inexistant', PASSWORD_DEFAULT);
    if (!password_verify($password, $hash) || !$u) {
        q('INSERT INTO login_attempts (email, at) VALUES (?, ?)', [$email, time()]);
        usleep(400000);
        return 'E-mail ou mot de passe incorrect.';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    q('DELETE FROM login_attempts WHERE email = ? OR at < ?', [$email, time() - LOGIN_WINDOW]);
    log_in((int)$u['id']);
    return null;
}

function log_in(int $uid): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $uid]);
}

function log_out(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Le premier compte créé administre le catalogue commun (noms et muscles des exercices). */
function is_admin(): bool
{
    $u = current_user();
    return $u && (int)$u['id'] === (int)q('SELECT MIN(id) FROM users')->fetchColumn();
}

/** Peut renommer / changer les muscles de cet exercice : le sien, ou n'importe lequel pour l'admin. */
function can_edit_exercise(array $e): bool
{
    return ($e['owner_id'] !== null && (int)$e['owner_id'] === uid()) || is_admin();
}

// --- CSRF -----------------------------------------------------------------

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

/** Ajoute le jeton CSRF à chaque formulaire POST du HTML rendu. */
function inject_csrf(string $html): string
{
    $field = '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
    return preg_replace('#(<form\b[^>]*\bmethod="post"[^>]*>)#i', '$1' . $field, $html);
}

/** Supprime le compte et toutes ses données. */
function delete_account(int $uid): void
{
    $pdo = db();
    $pdo->beginTransaction();
    q('DELETE FROM workouts WHERE user_id = ?', [$uid]);      // séances → exercices de séance → séries (cascade)
    q('DELETE FROM templates WHERE user_id = ?', [$uid]);
    q('DELETE FROM exercises WHERE owner_id = ?', [$uid]);
    q('DELETE FROM users WHERE id = ?', [$uid]);              // pesées, mensurations, réglages, amitiés, invitations, kudos, commentaires (cascade)
    $pdo->commit();
}
