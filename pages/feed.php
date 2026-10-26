<?php
defined('ROOT') || exit; // pas d'accès direct
// Le fil d'activité vit dans l'onglet « Fil » de la page Amis.
redirect('?p=friends&tab=feed' . (isset($_GET['before']) ? '&before=' . urlencode((string)$_GET['before']) : ''));
