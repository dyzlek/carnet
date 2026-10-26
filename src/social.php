<?php
defined('ROOT') || exit; // pas d'accès direct
// Amis « à la Strava » : demandes, invitations, fil d'activité, kudos, commentaires, profils.
// Confidentialité (user_settings.share_level) : 'none' = invisible ; 'summary' = séances + records ;
// 'full' = summary + détail des exercices/charges + stats muscles. Jamais de poids corporel ni de mensurations.
// Chaque fonction contrôle elle-même l'accès : on ne se fie pas à l'appelant.

const SHARE_LEVELS = ['none', 'summary', 'full'];
const INVITE_DAYS = 7;
const COMMENT_MAX = 500;

// --- Amitiés ---------------------------------------------------------------

/** Identifiants des amis acceptés (de $uid, par défaut le compte connecté). */
function friend_ids(?int $uid = null): array
{
    $uid ??= uid();
    $rows = q("SELECT CASE WHEN requester_id = ? THEN addressee_id ELSE requester_id END AS fid
               FROM friendships WHERE status = 'accepted' AND (requester_id = ? OR addressee_id = ?)", [$uid, $uid, $uid])->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

function are_friends(int $a, int $b): bool
{
    return $a !== $b && (bool)q("SELECT 1 FROM friendships WHERE status = 'accepted'
        AND ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?))", [$a, $b, $b, $a])->fetchColumn();
}

/** Demande d'ami par e-mail exact ou nom exact. null = ok, sinon message d'erreur (neutre si le compte est introuvable). */
function friend_request(string $emailOrName): ?string
{
    $me = uid();
    $s = trim($emailOrName);
    if ($s === '') return "Indique l'e-mail ou le nom de ton ami.";
    $notFound = "Impossible d'envoyer la demande : vérifie l'e-mail ou le nom exact, ou envoie-lui un lien d'invitation.";

    if (str_contains($s, '@')) {
        $ids = q('SELECT id FROM users WHERE email = ?', [normalize_email($s)])->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $ids = q('SELECT id FROM users WHERE LOWER(name) = LOWER(?) LIMIT 2', [$s])->fetchAll(PDO::FETCH_COLUMN);
    }
    if (count($ids) !== 1) return $notFound; // inconnu ou nom ambigu : même message
    $other = (int)$ids[0];
    if ($other === $me) return 'Tu ne peux pas t\'ajouter toi-même.';

    $f = q('SELECT id, requester_id, status FROM friendships
            WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)', [$me, $other, $other, $me])->fetch();
    if ($f) {
        if ($f['status'] === 'accepted') return 'Vous êtes déjà amis.';
        if ((int)$f['requester_id'] === $me) return 'Demande déjà envoyée.';
        q("UPDATE friendships SET status = 'accepted' WHERE id = ?", [$f['id']]); // il m'avait déjà invité : on accepte
        return null;
    }
    q('INSERT INTO friendships (requester_id, addressee_id, status, created_at) VALUES (?, ?, ?, ?)', [$me, $other, 'pending', now()]);
    return null;
}

/** Accepte ou refuse une demande reçue (seul le destinataire peut répondre). */
function friend_respond(int $friendshipId, bool $accept): void
{
    $me = uid();
    if ($accept) {
        q("UPDATE friendships SET status = 'accepted' WHERE id = ? AND addressee_id = ? AND status = 'pending'", [$friendshipId, $me]);
    } else {
        q("DELETE FROM friendships WHERE id = ? AND addressee_id = ? AND status = 'pending'", [$friendshipId, $me]);
    }
}

/** Supprime l'amitié (ou annule la demande en attente) avec $otherUid, dans un sens ou dans l'autre. */
function friend_remove(int $otherUid): void
{
    $me = uid();
    q('DELETE FROM friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)', [$me, $otherUid, $otherUid, $me]);
}

/** Amis acceptés : id, name, last_workout_at (null si rien de visible : aucune séance, ou partage coupé). */
function friends_list(): array
{
    $out = [];
    foreach (friend_ids() as $fid) {
        $u = q('SELECT id, name FROM users WHERE id = ?', [$fid])->fetch();
        if (!$u) continue;
        $last = null;
        if (share_level($fid) !== 'none') {
            $v = q('SELECT MAX(started_at) FROM workouts WHERE user_id = ? AND ended_at IS NOT NULL', [$fid])->fetchColumn();
            $last = $v === false ? null : $v;
        }
        $out[] = ['id' => (int)$u['id'], 'name' => $u['name'], 'last_workout_at' => $last];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Demandes reçues en attente : id (de la demande), user_id, name, created_at. */
function pending_incoming(): array
{
    return q("SELECT f.id, f.requester_id AS user_id, u.name, f.created_at FROM friendships f JOIN users u ON u.id = f.requester_id
              WHERE f.addressee_id = ? AND f.status = 'pending' ORDER BY f.created_at DESC, f.id DESC", [uid()])->fetchAll();
}

/** Demandes envoyées en attente : id (de la demande), user_id, name, created_at. */
function pending_outgoing(): array
{
    return q("SELECT f.id, f.addressee_id AS user_id, u.name, f.created_at FROM friendships f JOIN users u ON u.id = f.addressee_id
              WHERE f.requester_id = ? AND f.status = 'pending' ORDER BY f.created_at DESC, f.id DESC", [uid()])->fetchAll();
}

// --- Invitations -----------------------------------------------------------

/** Crée un lien d'invitation (usage unique, valable INVITE_DAYS jours) et renvoie son jeton (64 hex). */
function create_invite(): string
{
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO invites (token, inviter_id, created_at, expires_at) VALUES (?, ?, ?, ?)',
        [$token, uid(), now(), date('Y-m-d H:i:s', time() + INVITE_DAYS * 86400)]);
    return $token;
}

/** Invitation valide (ni expirée ni utilisée) : token, inviter_id, inviter_name, expires_at ; sinon null. */
function invite_by_token(string $t): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/', $t)) return null;
    $r = q('SELECT i.token, i.inviter_id, u.name AS inviter_name, i.expires_at
            FROM invites i JOIN users u ON u.id = i.inviter_id
            WHERE i.token = ? AND i.used_by IS NULL AND i.expires_at > ?', [$t, now()])->fetch();
    return $r ?: null;
}

/** Marque l'invitation utilisée et crée l'amitié acceptée entre l'inviteur et le nouveau compte. */
function consume_invite(string $token, int $newUserId): void
{
    // UPDATE conditionnel : deux inscriptions simultanées ne peuvent pas utiliser le même jeton
    $st = q('UPDATE invites SET used_by = ? WHERE token = ? AND used_by IS NULL AND expires_at > ?', [$newUserId, $token, now()]);
    if ($st->rowCount() !== 1) return;
    $inviter = (int)q('SELECT inviter_id FROM invites WHERE token = ?', [$token])->fetchColumn();
    if (!$inviter || $inviter === $newUserId) return;
    if (are_friends($inviter, $newUserId)) return;
    q('DELETE FROM friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)', [$inviter, $newUserId, $newUserId, $inviter]);
    q('INSERT INTO friendships (requester_id, addressee_id, status, created_at) VALUES (?, ?, ?, ?)', [$inviter, $newUserId, 'accepted', now()]);
}

/**
 * Un utilisateur DÉJÀ connecté ouvre un lien d'invitation. Renvoie ['status' => ..., 'name' => inviteur] avec status :
 * 'ok' (amitié créée), 'already' (déjà amis), 'self' (son propre lien), 'invalid' (expiré / utilisé / inconnu).
 * Le jeton est consommé (comme à l'inscription) : le lien est annoncé « à usage unique », et un lien resté dans
 * un message ne doit pas pouvoir ajouter n'importe quel compte connecté qui l'ouvre ensuite.
 */
function accept_invite(string $token): array
{
    $me = uid();
    $inv = invite_by_token($token);
    if (!$inv) return ['status' => 'invalid', 'name' => ''];
    $out = ['status' => 'ok', 'name' => $inv['inviter_name']];
    if ($inv['inviter_id'] === $me) return ['status' => 'self'] + $out;
    if (are_friends($inv['inviter_id'], $me)) return ['status' => 'already'] + $out; // pas de consommation inutile
    consume_invite($token, $me);
    return are_friends($inv['inviter_id'], $me) ? $out : ['status' => 'invalid', 'name' => ''];
}

// --- Confidentialité -------------------------------------------------------

/** Niveau de partage de $uid (par défaut le compte connecté) : 'none' | 'summary' (défaut) | 'full'. */
function share_level(?int $uid = null): string
{
    $v = q("SELECT value FROM user_settings WHERE user_id = ? AND `key` = 'share_level'", [$uid ?? uid()])->fetchColumn();
    return in_array($v, SHARE_LEVELS, true) ? $v : 'summary';
}

function set_share_level(string $level): void
{
    if (!in_array($level, SHARE_LEVELS, true)) return;
    set_setting('share_level', $level);
}

// --- Séances partagées -----------------------------------------------------

/**
 * Séances terminées avec leurs totaux, kudos et commentaires. $cond = fragment WHERE sur w (séances) et u (users),
 * à construire avec des placeholders uniquement. Aucun contrôle d'accès ici : réservé aux fonctions de ce fichier.
 */
function social_workouts(string $cond, array $params, int $limit): array
{
    $me = uid();
    $limit = max(1, min(200, $limit));
    $rows = q("SELECT w.id, w.user_id, u.name AS user_name, w.name, w.started_at, w.ended_at,
        (SELECT COUNT(DISTINCT we.id) FROM workout_exercises we JOIN sets s ON s.workout_exercise_id = we.id
            WHERE we.workout_id = w.id AND s.reps > 0) AS exercises,
        (SELECT COUNT(*) FROM sets s JOIN workout_exercises we ON we.id = s.workout_exercise_id
            WHERE we.workout_id = w.id AND s.reps > 0) AS sets_done,
        (SELECT COALESCE(SUM(s.weight * s.reps), 0) FROM sets s JOIN workout_exercises we ON we.id = s.workout_exercise_id
            WHERE we.workout_id = w.id AND s.reps > 0) AS volume,
        (SELECT COUNT(*) FROM workout_reactions r WHERE r.workout_id = w.id) AS kudos,
        (SELECT COUNT(*) FROM workout_reactions r WHERE r.workout_id = w.id AND r.user_id = ?) AS my_kudos,
        (SELECT COUNT(*) FROM workout_comments c WHERE c.workout_id = w.id) AS comments
        FROM workouts w JOIN users u ON u.id = w.user_id
        WHERE w.ended_at IS NOT NULL AND ($cond)
        ORDER BY w.started_at DESC, w.id DESC LIMIT $limit", array_merge([$me], $params))->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['user_id'] = (int)$r['user_id'];
        $r['duration_s'] = max(0, strtotime($r['ended_at']) - strtotime($r['started_at']));
        $r['exercises'] = (int)$r['exercises'];
        $r['sets_done'] = (int)$r['sets_done'];
        $r['volume'] = (float)$r['volume'];
        $r['kudos'] = (int)$r['kudos'];
        $r['i_kudoed'] = (int)$r['my_kudos'] > 0;
        $r['comments'] = (int)$r['comments'];
        unset($r['my_kudos']);
        $r['prs'] = workout_prs($r);
        // Séance d'un ami qui ne partage pas en « full » : juste « Record » + valeur, sans l'ancienne marque (détail privé)
        if ($r['user_id'] !== $me && share_level($r['user_id']) !== 'full') {
            $r['prs'] = array_map(fn($p) => ['name' => $p['name'], 'what' => 'Record', 'value' => $p['value']], $r['prs']);
        }
    }
    return $rows;
}

/** Placeholders « ?,?,? » pour une liste d'identifiants. */
function in_marks(array $ids): string
{
    return implode(',', array_fill(0, count($ids), '?'));
}

/** Identifiants dont on peut voir les séances : soi-même + amis avec share_level != 'none'. */
function visible_user_ids(): array
{
    $ids = [uid()];
    foreach (friend_ids() as $fid) if (share_level($fid) !== 'none') $ids[] = $fid;
    return $ids;
}

/** Fil d'activité : séances terminées de soi et des amis (partage actif), plus récentes d'abord. $before = started_at max exclu (pagination). */
function feed(int $limit = 30, ?string $before = null): array
{
    $ids = visible_user_ids();
    $cond = 'w.user_id IN (' . in_marks($ids) . ')';
    $params = $ids;
    if ($before !== null && $before !== '') {
        $cond .= ' AND w.started_at < ?';
        $params[] = $before;
    }
    return social_workouts($cond, $params, $limit);
}

/**
 * Séance à moi ou à un ami (partage != 'none'), sinon null. Ajoute 'exercises' (détail des séries) seulement
 * si c'est la mienne ou si l'ami partage en 'full' ; sinon 'exercises' vaut le nombre d'exercices et 'detail' est null.
 */
function friend_workout(int $workoutId): ?array
{
    $me = uid();
    $owner = q('SELECT user_id FROM workouts WHERE id = ? AND ended_at IS NOT NULL', [$workoutId])->fetchColumn();
    if ($owner === false || $owner === null) return null;
    $owner = (int)$owner;
    if ($owner !== $me && (!are_friends($me, $owner) || share_level($owner) === 'none')) return null;

    $w = social_workouts('w.id = ?', [$workoutId], 1)[0] ?? null;
    if (!$w) return null;
    $w['detail'] = null;
    if ($owner === $me || share_level($owner) === 'full') {
        $w['detail'] = [];
        $wes = q('SELECT we.id, we.exercise_id, e.name, e.name_fr FROM workout_exercises we JOIN exercises e ON e.id = we.exercise_id
                  WHERE we.workout_id = ? ORDER BY we.position, we.id', [$workoutId])->fetchAll();
        foreach ($wes as $we) {
            $sets = done_sets((int)$we['id']);
            if (!$sets) continue;
            $w['detail'][] = ['name' => ex_name($we), 'sets' => array_map(
                fn($s) => ['weight' => $s['weight'] === null ? null : (float)$s['weight'], 'reps' => (int)$s['reps']], $sets)];
        }
    }
    return $w;
}

// --- Kudos et commentaires -------------------------------------------------

/** Ajoute / retire mon kudos. Renvoie ['count' => int, 'mine' => bool] (count = -1 et mine = false si accès refusé). */
function toggle_kudos(int $workoutId): array
{
    if (!friend_workout($workoutId)) return ['count' => -1, 'mine' => false];
    $me = uid();
    $has = q('SELECT 1 FROM workout_reactions WHERE workout_id = ? AND user_id = ?', [$workoutId, $me])->fetchColumn();
    if ($has) {
        q('DELETE FROM workout_reactions WHERE workout_id = ? AND user_id = ?', [$workoutId, $me]);
    } else {
        q(insert_or('IGNORE') . ' INTO workout_reactions (workout_id, user_id, created_at) VALUES (?, ?, ?)', [$workoutId, $me, now()]);
    }
    $count = (int)q('SELECT COUNT(*) FROM workout_reactions WHERE workout_id = ?', [$workoutId])->fetchColumn();
    return ['count' => $count, 'mine' => !$has];
}

/** null = ok, sinon message d'erreur. */
function add_comment(int $workoutId, string $body): ?string
{
    $body = trim($body);
    $len = (int)preg_match_all("/./us", $body); // sans mbstring (absent de certains serveurs)
    if ($len < 1) return 'Écris un commentaire.';
    if ($len > COMMENT_MAX) return 'Commentaire trop long (' . COMMENT_MAX . ' caractères max).';
    if (!friend_workout($workoutId)) return 'Séance introuvable.';
    q('INSERT INTO workout_comments (workout_id, user_id, body, created_at) VALUES (?, ?, ?, ?)', [$workoutId, uid(), $body, now()]);
    return null;
}

/** Commentaires d'une séance visible (id, user_id, user_name, body, created_at, can_delete) ; [] si accès refusé. */
function workout_comments(int $workoutId): array
{
    if (!friend_workout($workoutId)) return [];
    $me = uid();
    $owner = (int)q('SELECT user_id FROM workouts WHERE id = ?', [$workoutId])->fetchColumn();
    $rows = q('SELECT c.id, c.user_id, u.name AS user_name, c.body, c.created_at
               FROM workout_comments c JOIN users u ON u.id = c.user_id
               WHERE c.workout_id = ? ORDER BY c.created_at, c.id', [$workoutId])->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['user_id'] = (int)$r['user_id'];
        $r['can_delete'] = $r['user_id'] === $me || $owner === $me;
    }
    return $rows;
}

/** Supprime un commentaire : seulement son auteur ou le propriétaire de la séance. */
function delete_comment(int $id): void
{
    $me = uid();
    q('DELETE FROM workout_comments WHERE id = ? AND (user_id = ? OR workout_id IN (SELECT id FROM workouts WHERE user_id = ?))', [$id, $me, $me]);
}

// --- Profil d'un ami -------------------------------------------------------

/** Profil d'un ami ; null si ce n'est pas un ami. Avec partage 'none' : seulement id, name, share_level. */
function friend_profile(int $friendId): ?array
{
    if (!are_friends(uid(), $friendId)) return null;
    $name = q('SELECT name FROM users WHERE id = ?', [$friendId])->fetchColumn();
    if ($name === false) return null;
    $level = share_level($friendId);
    $p = ['id' => $friendId, 'name' => $name, 'share_level' => $level];
    if ($level === 'none') return $p;

    [$from, $to] = period(30);
    $r = q('SELECT COUNT(DISTINCT w.id) AS n, COALESCE(SUM(s.weight * s.reps), 0) AS volume
            FROM workouts w LEFT JOIN workout_exercises we ON we.workout_id = w.id
            LEFT JOIN sets s ON s.workout_exercise_id = we.id AND s.reps > 0
            WHERE w.user_id = ? AND w.ended_at IS NOT NULL AND w.started_at >= ? AND w.started_at < ?', [$friendId, $from, $to])->fetch();
    $p['workouts_30d'] = (int)$r['n'];
    $p['volume_30d'] = (float)$r['volume'];
    $p['recent_workouts'] = social_workouts('w.user_id = ?', [$friendId], 10);
    $p['prs'] = [];
    foreach (array_slice($p['recent_workouts'], 0, 10) as $w) {
        foreach ($w['prs'] as $pr) {
            $p['prs'][] = $pr + ['date' => $w['started_at'], 'workout_id' => $w['id']];
            if (count($p['prs']) >= 5) break 2;
        }
    }
    if ($level === 'full') $p['muscles'] = muscle_stats($from, $to, $friendId);
    return $p;
}
