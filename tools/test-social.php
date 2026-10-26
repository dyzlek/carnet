<?php
// Test jetable de la couche « amis » (src/social.php), sur une base SQLite temporaire :
//   php -d extension=pdo_sqlite tools/test-social.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$tmp = sys_get_temp_dir() . '/carnet-test-social-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
// le catalogue d'exercices est importé depuis exercises.json (les programmes fournis en ont besoin)
copy(dirname(__DIR__) . '/data/exercises.json', "$tmp/exercises.json");
putenv("CARNET_DATA=$tmp");
ob_start(); // session_start / en-têtes en CLI
require dirname(__DIR__) . '/src/bootstrap.php';
ob_end_clean();

$fails = 0;
function check(string $label, bool $ok): void
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}
function as_user(int $id): void { $_SESSION['uid'] = $id; current_user(true); }
/** Séance terminée avec une série (poids, reps) ; renvoie son id. */
function make_workout(int $user, string $name, string $start, float $w, int $reps): int
{
    $pdo = db();
    $ex = $pdo->query('SELECT id FROM exercises LIMIT 1')->fetchColumn();
    if (!$ex) { // catalogue absent de la base de test : un exercice minimal
        $ex = 'T001';
        $pdo->prepare('INSERT INTO exercises (id, name) VALUES (?, ?)')->execute([$ex, 'Test press']);
    }
    q('INSERT INTO workouts (user_id, name, started_at, ended_at) VALUES (?, ?, ?, ?)', [$user, $name, $start, date('Y-m-d H:i:s', strtotime($start) + 3600)]);
    $wid = (int)$pdo->lastInsertId();
    q('INSERT INTO workout_exercises (workout_id, exercise_id, position) VALUES (?, ?, 0)', [$wid, $ex]);
    $weId = (int)$pdo->lastInsertId();
    q('INSERT INTO sets (workout_exercise_id, set_number, weight, reps, done) VALUES (?, 1, ?, ?, 1)', [$weId, $w, $reps]);
    return $wid;
}

echo "Inscriptions\n";
$_SESSION = [];
check('compte A', register('Alice', 'alice@test.fr', 'motdepasse1', 'motdepasse1') === []);
$A = (int)$_SESSION['uid'];
as_user($A);
check('inscriptions fermées sans jeton', register('Bob', 'bob@test.fr', 'motdepasse1', 'motdepasse1') === ['Les inscriptions sont fermées.']);

echo "Invitation\n";
$tok = create_invite();
check('jeton 64 hex', (bool)preg_match('/^[0-9a-f]{64}$/', $tok));
check('invite_by_token valide', (invite_by_token($tok)['inviter_id'] ?? 0) === $A);
check('jeton bidon refusé', invite_by_token(str_repeat('0', 64)) === null && invite_by_token('abc') === null);
check('inscription B via invitation (fermées)', register('Bob', 'bob@test.fr', 'motdepasse1', 'motdepasse1', $tok) === []);
$B = (int)$_SESSION['uid'];
as_user($B);
check('B et A amis directement', are_friends($A, $B) && friend_ids() === [$A]);
check('jeton à usage unique', invite_by_token($tok) === null);
check('2e inscription avec jeton usé refusée', register('Eve', 'eve@test.fr', 'motdepasse1', 'motdepasse1', $tok) === ['Les inscriptions sont fermées.']);
as_user($A);
q('UPDATE invites SET expires_at = ? WHERE token = ?', ['2000-01-01 00:00:00', $tok]);
$tok2 = create_invite();
q('UPDATE invites SET expires_at = ? WHERE token = ?', ['2000-01-01 00:00:00', $tok2]);
check('jeton expiré refusé', invite_by_token($tok2) === null);

set_registration(true);
check('compte C', register('Carl', 'carl@test.fr', 'motdepasse1', 'motdepasse1') === []);
$C = (int)$_SESSION['uid'];
as_user($C);

echo "Invitation ouverte en étant connecté\n";
as_user($A);
$tokA = create_invite();
check('son propre lien : self, jeton intact', accept_invite($tokA)['status'] === 'self' && invite_by_token($tokA) !== null);
check('jeton invalide : invalid', accept_invite(str_repeat('0', 64))['status'] === 'invalid' && accept_invite('abc')['status'] === 'invalid');
as_user($B);
check('déjà amis : already, jeton conservé', accept_invite($tokA)['status'] === 'already' && invite_by_token($tokA) !== null);
as_user($C);
$r = accept_invite($tokA);
check('C connecté : ami avec Alice (ok + nom)', $r['status'] === 'ok' && $r['name'] === 'Alice' && are_friends($A, $C));
check('jeton consommé', invite_by_token($tokA) === null && accept_invite($tokA)['status'] === 'invalid');
friend_remove($A);
check('amitié retirée pour la suite', !are_friends($A, $C));

echo "Demandes d'ami\n";
check('refus de soi-même', friend_request('carl@test.fr') !== null);
$m1 = friend_request('inconnu@test.fr');
$m2 = friend_request('Personne');
check('inconnu : message neutre identique (e-mail / nom)', $m1 !== null && $m1 === $m2);
check('demande à Alice par e-mail', friend_request('ALICE@test.fr') === null);
check('doublon refusé', friend_request('alice@test.fr') !== null);
check('sortante visible', count(pending_outgoing()) === 1 && pending_outgoing()[0]['user_id'] === $A);
check('pas amis tant que pending', !are_friends($A, $C));
as_user($A);
$in = pending_incoming();
check('entrante visible chez A', count($in) === 1 && $in[0]['user_id'] === $C);
as_user($B);
friend_respond($in[0]['id'], true); // B n'est pas le destinataire
check('un tiers ne peut pas répondre', !are_friends($A, $C));
as_user($A);
friend_respond($in[0]['id'], true);
check('A accepte', are_friends($A, $C) && count(pending_incoming()) === 0);
friend_remove($C);
check('friend_remove', !are_friends($A, $C));
as_user($A);
check('A demande à Carl (par nom)', friend_request('carl') === null);
as_user($C);
check('Carl redemande à Alice → acceptation directe', friend_request('alice@test.fr') === null && are_friends($A, $C));
friend_remove($A);
as_user($A);
friend_remove($C);

echo "Fil, kudos, commentaires\n";
$wA = make_workout($A, 'Pecs A', '2026-01-10 10:00:00', 100, 5);
$wA2 = make_workout($A, 'Pecs A2', '2026-01-17 10:00:00', 110, 5); // record
$wB = make_workout($B, 'Dos B', '2026-01-12 10:00:00', 60, 8);
$wC = make_workout($C, 'Jambes C', '2026-01-13 10:00:00', 80, 10);
as_user($A);
$f = feed();
check('fil A : A + B, pas C (non ami)', array_column($f, 'id') === [$wA2, $wB, $wA]);
check('totaux', $f[0]['sets_done'] === 1 && $f[0]['volume'] === 550.0 && $f[0]['duration_s'] === 3600 && $f[0]['exercises'] === 1);
check('record détecté dans le fil', count($f[0]['prs']) === 1);
check('pagination before', array_column(feed(30, '2026-01-12 10:00:00'), 'id') === [$wA]);
check('limit', count(feed(1)) === 1);
check('séance de C invisible', friend_workout($wC) === null);
check('kudos refusé sur non-ami', toggle_kudos($wC)['count'] === -1);
check('commentaire refusé sur non-ami', add_comment($wC, 'yo') !== null && workout_comments($wC) === []);
check('profil d\'un non-ami = null', friend_profile($C) === null);

as_user($B);
$k = toggle_kudos($wA2);
check('kudos B sur A', $k === ['count' => 1, 'mine' => true]);
check('kudos retiré', toggle_kudos($wA2) === ['count' => 0, 'mine' => false]);
toggle_kudos($wA2);
check('commentaire vide / trop long refusés', add_comment($wA2, '   ') !== null && add_comment($wA2, str_repeat('é', 501)) !== null);
check('commentaire ok', add_comment($wA2, 'Bravo !') === null);
as_user($A);
$fa = feed()[0];
check('compteurs kudos/commentaires', $fa['kudos'] === 1 && $fa['comments'] === 1 && $fa['i_kudoed'] === false);
$cm = workout_comments($wA2);
check('A (propriétaire) peut supprimer', count($cm) === 1 && $cm[0]['can_delete'] === true);

echo "Niveaux de partage\n";
check('défaut summary', share_level() === 'summary');
as_user($B);
$fw = friend_workout($wA2);
check('summary : pas de détail', $fw !== null && $fw['detail'] === null);
$p = friend_profile($A);
check('summary : record sans ancienne valeur', $fw['prs'] === [['name' => $fw['prs'][0]['name'], 'what' => 'Record', 'value' => '110 kg']]);
check('summary : fil et profil idem', !isset(feed()[0]['prs'][0]['before']) && !isset(friend_profile($A)['prs'][0]['before']));
$p = friend_profile($A);
check('profil summary', $p['share_level'] === 'summary' && $p['workouts_30d'] >= 0 && !isset($p['muscles']) && count($p['recent_workouts']) === 2);
as_user($A);
set_share_level('full');
as_user($B);
$fw = friend_workout($wA2);
check('full : record avec « avant »', ($fw['prs'][0]['before'] ?? null) === '100 kg' && $fw['prs'][0]['what'] === 'Charge max');
check('full : détail', is_array($fw['detail']) && $fw['detail'][0]['sets'][0]['weight'] === 110.0);
check('profil full : muscles', array_key_exists('muscles', friend_profile($A)));
as_user($A);
set_share_level('none');
as_user($B);
check('none : séance invisible', friend_workout($wA2) === null);
check('none : absent du fil de B', !in_array($wA2, array_column(feed(), 'id'), true));
check('none : profil limité au nom', array_keys(friend_profile($A)) === ['id', 'name', 'share_level']);
check('none : kudos refusé', toggle_kudos($wA2)['count'] === -1);
check('none : last_workout_at masqué', friends_list()[0]['last_workout_at'] === null);
check('none : A voit toujours ses propres séances', (function () use ($A, $wA2) { as_user($A); return friend_workout($wA2) !== null; })());
set_share_level('bidon');
check('niveau invalide ignoré', share_level() === 'none');
set_share_level('summary');

echo "Suppression de commentaires\n";
as_user($C);
check('C (non ami) ne supprime rien', (function () use ($cm) { delete_comment($cm[0]['id']); return (int)q('SELECT COUNT(*) FROM workout_comments')->fetchColumn() === 1; })());
as_user($B);
add_comment($wA2, 'Deuxième');
$all = workout_comments($wA2);
check('B ne peut pas supprimer le commentaire d\'un autre que lui', (function () use ($all) {
    foreach ($all as $c) if ($c['user_id'] === (int)$_SESSION['uid']) return $c['can_delete'] === true;
    return false;
})());
as_user($A);
foreach (workout_comments($wA2) as $c) delete_comment($c['id']);
check('propriétaire supprime tout', workout_comments($wA2) === []);

echo "Suppression de compte\n";
as_user($B);
add_comment($wA2, 'avant départ');
delete_account($B);
check('amitiés / kudos / commentaires de B effacés', (int)q('SELECT COUNT(*) FROM friendships WHERE requester_id = ? OR addressee_id = ?', [$B, $B])->fetchColumn() === 0
    && (int)q('SELECT COUNT(*) FROM workout_reactions WHERE user_id = ?', [$B])->fetchColumn() === 0
    && (int)q('SELECT COUNT(*) FROM workout_comments WHERE user_id = ?', [$B])->fetchColumn() === 0);

// ménage : le dossier temporaire (la base SQLite ne vit que là)
// ménage : le dossier temporaire (la base SQLite ne vit que là) ; SQLite garde le fichier ouvert tant que PDO vit, donc on tente en fin de script
register_shutdown_function(function () use ($tmp) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($tmp);
});

echo $fails === 0 ? "\nTout est OK.\n" : "\n$fails échec(s).\n";
exit($fails === 0 ? 0 : 1);
