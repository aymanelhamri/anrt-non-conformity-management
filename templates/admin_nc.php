<?php $title = 'Non-conformités · Administration ANRT'; $heading = 'Toutes les non-conformités'; require __DIR__ . '/admin_nav.php'; ?>
<form method="get" class="card admin-filters">
    <div><label for="account-filter">Matricule du déclarant</label><input id="account-filter" name="matricule" value="<?= e($filters['matricule']) ?>" maxlength="60" list="account-choices" placeholder="Tous les comptes"><datalist id="account-choices"><?php foreach ($accounts as $account): ?><option value="<?= e($account['matricule']) ?>"><?= e($account['nom_prenom']) ?></option><?php endforeach; ?></datalist></div>
    <button class="button primary" type="submit">Filtrer</button><a class="button quiet" href="/administration/non-conformites">Réinitialiser</a>
</form>
<section class="card admin-table-card">
    <div class="admin-table-wrap"><table class="admin-table">
        <caption>Déclarations de tous les processus</caption>
        <thead><tr><th>Référence</th><th>Déclarant</th><th>Responsable</th><th>Création</th><th>Échéance</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $nc): ?>
            <tr><td><a href="/administration/non-conformites/<?= e($nc['id']) ?>"><strong><?= e($nc['reference']) ?></strong></a><small class="admin-subtext"><?= e($nc['processus_code']) ?></small></td>
                <td><?= e($nc['declarant_nom'] ?: $nc['enregistree_par_matricule']) ?><small class="admin-subtext"><?= e($nc['enregistree_par_matricule']) ?></small></td>
                <td><?= e($nc['responsable_nom'] ?: $nc['responsable_matricule']) ?><small class="admin-subtext"><?= e($nc['responsable_matricule']) ?></small></td>
                <td><?= e(display_date($nc['date_creation'])) ?></td><td><?= e(display_date($nc['date_echeance'])) ?></td><td><span class="admin-badge"><?= e($nc['statut_code']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$result['rows']): ?><tr><td colspan="6">Aucune non-conformité pour ce filtre.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php $paginationPath = '/administration/non-conformites'; require __DIR__ . '/admin_pagination.php'; ?>
</section>
