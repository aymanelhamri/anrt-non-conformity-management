<?php $title = 'Mes non-conformités · ANRT'; ?>
<div class="page-heading"><div><p class="eyebrow">SUIVI DES DOSSIERS</p><h1><?= can_admin($user, $config) ? 'Non-conformités' : 'Mes non-conformités' ?></h1><p class="muted">Vos déclarations, les dossiers qui vous sont affectés et ceux que vous devez valider.</p></div></div>
<form method="get" class="card admin-filters">
    <div><label for="status-filter">Statut</label><select id="status-filter" name="statut"><option value="">Tous les statuts</option><?php foreach ($statuses as $status): ?><option value="<?= e($status['code']) ?>" <?= $filters['statut'] === $status['code'] ? 'selected' : '' ?>><?= e($status['libelle']) ?></option><?php endforeach; ?></select></div>
    <button class="button primary" type="submit">Filtrer</button><a class="button quiet" href="/non-conformites">Réinitialiser</a>
</form>
<section class="card admin-table-card"><div class="admin-table-wrap"><table class="admin-table">
    <caption><?= e($result['total']) ?> non-conformité(s)</caption>
    <thead><tr><th>Référence</th><th>Responsable</th><th>Création</th><th>Échéance</th><th>Statut</th><th>Actions</th></tr></thead>
    <tbody><?php foreach ($result['rows'] as $nc): ?><tr>
        <td><a href="/non-conformites/<?= e($nc['id']) ?>"><strong><?= e($nc['reference']) ?></strong></a></td>
        <td><?= e($nc['responsable_nom'] ?: $nc['responsable_matricule']) ?></td><td><?= e(display_date($nc['date_creation'])) ?></td><td><?= e(display_date($nc['date_echeance'])) ?></td>
        <td><span class="admin-badge <?= nc_closed($nc) ? 'inactive' : 'active' ?>"><?= e($nc['statut_libelle']) ?></span></td>
        <td><div class="nc-row-actions"><a href="/non-conformites/<?= e($nc['id']) ?>">Consulter</a><?php require __DIR__ . '/nc_edit_action.php'; ?></div></td>
    </tr><?php endforeach; ?>
    <?php if (!$result['rows']): ?><tr><td colspan="6">Aucune non-conformité pour ce filtre.</td></tr><?php endif; ?></tbody>
</table></div><?php $paginationPath = '/non-conformites'; require __DIR__ . '/admin_pagination.php'; ?></section>
