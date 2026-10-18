<?php
defined('ROOT') || exit; // pas d'accès direct
// Supersets : des exercices consécutifs qui partagent un même superset_group s'enchaînent sans repos.
// Le groupe est stocké dans la colonne superset_group ; positions et libellés (1, 2A, 2B, 3…) en sont déduits.

/** Séance ou programme : table des exercices et colonne du parent. */
const SS_SCOPES = [
    'workout' => ['workout_exercises', 'workout_id'],
    'template' => ['template_exercises', 'template_id'],
];

/** Exercices d'une séance / d'un programme dans l'ordre, avec leur groupe. */
function ss_rows(string $scope, int $parent): array
{
    [$table, $col] = SS_SCOPES[$scope];
    $rows = q("SELECT id, label, superset_group FROM $table WHERE $col = ? ORDER BY position, id", [$parent])->fetchAll();
    foreach ($rows as &$r) $r['superset_group'] = $r['superset_group'] === null ? null : (int)$r['superset_group'];
    return $rows;
}

/** Découpe en blocs : un exercice seul, ou plusieurs exercices consécutifs du même groupe. */
function ss_blocks(array $rows): array
{
    $blocks = [];
    foreach ($rows as $r) {
        $g = $r['superset_group'];
        $last = count($blocks) - 1;
        if ($g !== null && $last >= 0 && $blocks[$last][0]['superset_group'] === $g) $blocks[$last][] = $r;
        else $blocks[] = [$r];
    }
    return $blocks;
}

/**
 * Réécrit positions, groupes et libellés dans l'ordre donné.
 * Un groupe d'un seul exercice est dissous ; chaque groupe reçoit comme clé son numéro de bloc.
 */
function ss_write(string $scope, array $rows): void
{
    [$table] = SS_SCOPES[$scope];
    $pos = 0;
    foreach (ss_blocks($rows) as $n => $block) {
        $solo = count($block) === 1;
        foreach ($block as $j => $r) {
            q("UPDATE $table SET position = ?, label = ?, superset_group = ? WHERE id = ?", [
                ++$pos, $solo ? (string)($n + 1) : ($n + 1) . chr(65 + min($j, 25)), $solo ? null : $n + 1, $r['id'],
            ]);
        }
    }
}

function ss_renumber(string $scope, int $parent): void
{
    ss_write($scope, ss_rows($scope, $parent));
}

/** Reconstruit les groupes à partir d'anciens libellés « 2A / 2B » (migration, programmes fournis). */
function ss_from_labels(string $scope, int $parent): void
{
    $rows = ss_rows($scope, $parent);
    foreach ($rows as &$r) {
        $r['superset_group'] = preg_match('/^(\d+)[A-Z]$/i', trim((string)$r['label']), $m) ? (int)$m[1] : null;
    }
    ss_write($scope, $rows);
}

/**
 * Jonction entre l'exercice $rowId et le suivant : les lie en superset s'ils sont séparés,
 * sinon coupe le groupe à cet endroit.
 */
function ss_toggle(string $scope, int $parent, int $rowId): void
{
    $rows = ss_rows($scope, $parent);
    $i = array_search($rowId, array_column($rows, 'id'));
    if ($i === false || !isset($rows[$i + 1])) return;
    $ga = $rows[$i]['superset_group'];
    $gb = $rows[$i + 1]['superset_group'];
    $fresh = max(array_merge([0], array_column($rows, 'superset_group'))) + 1;

    if ($ga !== null && $ga === $gb) {
        // Délier : la suite du groupe forme un nouveau groupe (dissous s'il ne reste qu'un exercice)
        for ($k = $i + 1; isset($rows[$k]) && $rows[$k]['superset_group'] === $ga; $k++) $rows[$k]['superset_group'] = $fresh;
    } else {
        // Lier : les deux blocs fusionnent
        $key = $ga ?? $gb ?? $fresh;
        foreach ($rows as $k => $r) {
            if ($k === $i || $k === $i + 1 || ($gb !== null && $r['superset_group'] === $gb)) $rows[$k]['superset_group'] = $key;
        }
    }
    ss_write($scope, $rows);
}

/**
 * Monte (-1) ou descend (+1) un exercice. Dans un superset, il change de place avec son voisin du groupe ;
 * au bord du groupe (ou seul), c'est tout le bloc qui passe devant / derrière le bloc voisin.
 */
function ss_move(string $scope, int $parent, int $rowId, int $dir): void
{
    $blocks = ss_blocks(ss_rows($scope, $parent));
    foreach ($blocks as $b => $block) {
        $j = array_search($rowId, array_column($block, 'id'));
        if ($j === false) continue;
        $k = $j + $dir;
        if (count($block) > 1 && isset($block[$k])) {
            [$blocks[$b][$j], $blocks[$b][$k]] = [$block[$k], $block[$j]];
        } elseif (isset($blocks[$b + $dir])) {
            [$blocks[$b], $blocks[$b + $dir]] = [$blocks[$b + $dir], $block];
        }
        break;
    }
    // Clés provisoires distinctes par bloc pour que deux blocs devenus voisins ne fusionnent pas
    $rows = [];
    foreach ($blocks as $b => $block) {
        foreach ($block as $r) $rows[] = ['superset_group' => count($block) > 1 ? $b + 1 : null] + $r;
    }
    ss_write($scope, $rows);
}

/**
 * Ordre envoyé par le glisser-déposer : [{id, g}, …] avec g = groupe (ou null).
 * Refusé s'il ne contient pas exactement les exercices de la séance / du programme.
 */
function ss_reorder(string $scope, int $parent, array $order): bool
{
    $rows = [];
    foreach ($order as $o) {
        if (!is_array($o)) return false;
        $g = $o['g'] ?? null;
        $rows[] = ['id' => (int)($o['id'] ?? 0), 'superset_group' => is_numeric($g) ? (int)$g : null];
    }
    $want = array_map('intval', array_column(ss_rows($scope, $parent), 'id'));
    $got = array_column($rows, 'id');
    sort($want);
    sort($got);
    if ($got !== $want) return false;
    ss_write($scope, $rows);
    return true;
}

/** Résumé d'un superset pour l'en-tête du groupe : « Superset · 2 exercices · 4 tours ». */
function ss_title(int $count, int $rounds): string
{
    $kind = match (true) { $count >= 4 => 'Circuit', $count === 3 => 'Tri-set', default => 'Superset' };
    return $kind . ' · ' . $count . ' exercices' . ($rounds > 0 ? ' · ' . $rounds . ' tour' . ($rounds > 1 ? 's' : '') : '');
}
