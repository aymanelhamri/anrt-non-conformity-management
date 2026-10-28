<?php $title = $nc['reference'] . ' · Administration ANRT'; $heading = $nc['reference']; require __DIR__ . '/admin_nav.php'; ?>
<div class="admin-links admin-detail-actions"><a class="button quiet" href="/administration/non-conformites">Toutes les déclarations</a><a class="button quiet" href="/administration/journal?<?= e(http_build_query(['nc_id' => $nc['id']])) ?>">Journal de cette déclaration</a></div>
<p><a class="button primary" href="/non-conformites/<?= e($nc['id']) ?>">Ouvrir le suivi et les modifications</a></p>
<?php require __DIR__ . '/nc_information.php'; ?>
