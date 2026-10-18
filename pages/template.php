<?php
defined('ROOT') || exit; // pas d'accès direct
$id = (int)($_GET['id'] ?? 0);
$t = template($id);
if (!$t) {
    echo '<p>Programme introuvable. <a href="?p=templates">Retour</a></p>';
    return;
}

if (is_post()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        q('DELETE FROM templates WHERE id = ?', [$id]);
        flash('Programme supprimé.');
        redirect('?p=templates');
    }
    if ($action === 'save') {
        q('UPDATE templates SET name = ?, notes = ? WHERE id = ?', [trim((string)$_POST['name']) ?: $t['name'], trim((string)$_POST['notes']) ?: null, $id]);
        $int = fn($v) => ($n = parse_num($v)) === null ? null : (int)round($n);
        foreach ((array)($_POST['rows'] ?? []) as $rid => $r) {
            if (!empty($r['delete'])) {
                q('DELETE FROM template_exercises WHERE id = ? AND template_id = ?', [(int)$rid, $id]);
                continue;
            }
            q('UPDATE template_exercises SET kind = ?, focus = ?, sets = ?, reps_min = ?, reps_max = ?, rest_min = ?, rest_max = ?, rest_after = ?, rpe_min = ?, rpe_max = ?,
                      notes = ?, overload_sets = ?, overload_reps = ?, inc_min = ?, inc_max = ?
               WHERE id = ? AND template_id = ?', [
                trim((string)$r['kind']) ?: null, trim((string)($r['focus'] ?? '')) ?: null,
                max(1, $int($r['sets']) ?? 3), $int($r['reps_min']), $int($r['reps_max']),
                parse_secs($r['rest_min'] ?? null), parse_secs($r['rest_max'] ?? null), parse_secs($r['rest_after'] ?? null), parse_num($r['rpe_min'] ?? null), parse_num($r['rpe_max'] ?? null), trim((string)$r['notes']) ?: null,
                $int($r['overload_sets']), $int($r['overload_reps']), parse_num($r['inc_min']), parse_num($r['inc_max']),
                (int)$rid, $id,
            ]);
        }
        // Glisser-déposer ou bouton de jonction : on enregistre d'abord le formulaire, puis l'ordre / le superset
        $order = json_decode((string)($_POST['order'] ?? ''), true);
        if (!is_array($order) || !ss_reorder('template', $id, $order)) ss_renumber('template', $id);
        $focus = (int)(($_POST['moved'] ?? '') ?: ($_POST['ss_toggle'] ?? 0));
        if (isset($_POST['ss_toggle'])) ss_toggle('template', $id, $focus);
        if (!$focus) flash('Programme enregistré.');
        redirect("?p=template&id=$id" . ($focus ? "#ex-$focus" : ''));
    }
    redirect("?p=template&id=$id");
}

$title = $t['name'];
$rows = template_exercises($id);
?>
<a class="back" href="?p=templates"><?= icon('arrow-left') ?>Programmes</a>
<form method="post" class="tpl-form">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="order" value="">
  <input type="hidden" name="moved" value="">
  <button class="sr-only" tabindex="-1" aria-hidden="true">Enregistrer</button><?php /* bouton par défaut : Entrée enregistre, ne lie pas un superset */ ?>
  <div class="form-grid two">
    <label>Nom <input name="name" value="<?= h($t['name']) ?>" required></label>
    <label>Description <input name="notes" value="<?= h($t['notes']) ?>"></label>
  </div>

  <p class="muted small">Ordre : maintiens la poignée à gauche d'un exercice et fais-le glisser (celle d'un superset déplace tout le groupe). Superset : bouton « Lier en superset » entre deux exercices (2A / 2B, tri-set, circuit). Repos en secondes ou en minutes (« 90 » ou « 1:30 ») ; « Repos après l'exercice » = transition avant le suivant (par défaut : le repos entre séries, 1:30 minimum). Surcharge : si <i>N</i> séries à <i>X</i> reps sont validées à la charge max, l'app propose +min à +max kg à la séance suivante.</p>

  <div class="tpl-rows">
    <?php foreach ($rows as $i => $r):
        $k = (int)$r['id'];
        $g = $r['superset_group'];
        $prevG = $rows[$i - 1]['superset_group'] ?? null;
        $nextG = $rows[$i + 1]['superset_group'] ?? null;
        $ssFirst = $g !== null && $g !== $prevG;
        $ssLast = $g !== null && $g !== $nextG;
    ?>
      <?php if ($ssFirst): $members = array_values(array_filter($rows, fn($x) => $x['superset_group'] === $g)); ?>
        <p class="ss-head" data-ss-head="<?= (int)$g ?>"><?= drag_handle('Déplacer le superset') ?><?= icon('link') ?><?= h(ss_title(count($members), max(array_map(fn($x) => (int)$x['sets'], $members)))) ?></p>
      <?php endif; ?>
      <fieldset class="tpl-row card<?= $g !== null ? ' in-superset' : '' ?><?= $ssFirst ? ' ss-first' : '' ?><?= $ssLast ? ' ss-last' : '' ?>"
                id="ex-<?= $k ?>" data-id="<?= $k ?>"<?= $g !== null ? ' data-ss="' . (int)$g . '"' : '' ?>>
        <legend><?= drag_handle("Déplacer l'exercice") ?><span class="badge"><?= h($r['label']) ?></span> <?= thumb_zoom($r, $r['exercise_id']) ?> <a href="?p=exercise&id=<?= h($r['exercise_id']) ?>"><?= h(ex_name($r)) ?></a></legend>
        <div class="tpl-fields">
          <label class="wide">Type <input name="rows[<?= $k ?>][kind]" value="<?= h($r['kind']) ?>" placeholder="Polyarticulaire, Superset 1…"></label>
          <label class="wide">Muscle cible <input name="rows[<?= $k ?>][focus]" value="<?= h($r['focus']) ?>" placeholder="Grands dorsaux (largeur)…"></label>
          <label>Séries <input name="rows[<?= $k ?>][sets]" value="<?= (int)$r['sets'] ?>" inputmode="numeric"></label>
          <label>Reps min <input name="rows[<?= $k ?>][reps_min]" value="<?= h($r['reps_min']) ?>" inputmode="numeric"></label>
          <label>Reps max <input name="rows[<?= $k ?>][reps_max]" value="<?= h($r['reps_max']) ?>" inputmode="numeric"></label>
          <label>Repos min <input name="rows[<?= $k ?>][rest_min]" value="<?= h(secs_in($r['rest_min'])) ?>" placeholder="1:30" inputmode="decimal"></label>
          <label>Repos max <input name="rows[<?= $k ?>][rest_max]" value="<?= h(secs_in($r['rest_max'])) ?>" placeholder="1:30" inputmode="decimal"></label>
          <label>Repos après l'exercice <input name="rows[<?= $k ?>][rest_after]" value="<?= h(secs_in($r['rest_after'])) ?>" placeholder="<?= h(secs_in(max((int)($r['rest_max'] ?? 90), 90))) ?>" inputmode="decimal"></label>
          <label>RPE min <input name="rows[<?= $k ?>][rpe_min]" value="<?= h(num_in($r['rpe_min'])) ?>" inputmode="decimal"></label>
          <label>RPE max <input name="rows[<?= $k ?>][rpe_max]" value="<?= h(num_in($r['rpe_max'])) ?>" inputmode="decimal"></label>
          <label class="full">Consigne <input name="rows[<?= $k ?>][notes]" value="<?= h($r['notes']) ?>"></label>
          <label>Surcharge : séries <input name="rows[<?= $k ?>][overload_sets]" value="<?= h($r['overload_sets']) ?>" inputmode="numeric"></label>
          <label>× reps <input name="rows[<?= $k ?>][overload_reps]" value="<?= h($r['overload_reps']) ?>" inputmode="numeric"></label>
          <label>+ kg min <input name="rows[<?= $k ?>][inc_min]" value="<?= h(num_in($r['inc_min'])) ?>" inputmode="decimal"></label>
          <label>+ kg max <input name="rows[<?= $k ?>][inc_max]" value="<?= h(num_in($r['inc_max'])) ?>" inputmode="decimal"></label>
          <label class="check-del"><input type="checkbox" name="rows[<?= $k ?>][delete]" value="1"> Retirer</label>
        </div>
      </fieldset>
      <?php if (isset($rows[$i + 1])) echo ss_joint($g !== null && $g === $nextG, 'name="ss_toggle" value="' . $k . '"', $ssLast ? rest_after($r) : null); ?>
    <?php endforeach; ?>
  </div>

  <div class="btn-row">
    <button type="button" class="btn ghost" data-pick="template" data-id="<?= $id ?>"><?= icon('plus') ?>Ajouter un exercice</button>
    <button class="btn">Enregistrer</button>
  </div>
</form>

<form method="post" class="danger-zone" data-confirm="Supprimer ce programme ? (les séances passées sont conservées)">
  <input type="hidden" name="action" value="delete">
  <button class="btn danger sm">Supprimer le programme</button>
</form>
