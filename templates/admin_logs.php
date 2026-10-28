<?php $title = 'Journal d’activité · Administration ANRT'; $heading = 'Journal d’activité'; require __DIR__ . '/admin_nav.php'; ?>
<p class="muted">Les créations déjà enregistrées sont conservées. Les connexions et déconnexions sont tracées depuis l’activation du suivi. Horaires : <?= e($config['timezone']) ?>.</p>
<form method="get" class="card admin-filters">
    <div><label for="account-filter">Matricule</label><input id="account-filter" name="matricule" value="<?= e($filters['matricule']) ?>" maxlength="60" list="account-choices" placeholder="Tous les comptes"><datalist id="account-choices"><?php foreach ($accounts as $account): ?><option value="<?= e($account['matricule']) ?>"><?= e($account['nom_prenom']) ?></option><?php endforeach; ?></datalist></div>
    <div><label for="operation-filter">Événement</label><select id="operation-filter" name="operation"><option value="">Tous les événements</option><?php foreach (ADMIN_EVENTS as $operation => $label): ?><option value="<?= e($operation) ?>" <?= $filters['operation'] === $operation ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <?php if ($filters['nc_id'] !== null): ?><input type="hidden" name="nc_id" value="<?= e($filters['nc_id']) ?>"><?php endif; ?>
    <button class="button primary" type="submit">Filtrer</button><a class="button quiet" href="/administration/journal">Réinitialiser</a>
</form>
<section class="card admin-table-card">
    <div class="admin-table-wrap"><table class="admin-table">
        <caption>Événements des comptes et des non-conformités</caption>
        <thead><tr><th>Date et heure</th><th>Compte</th><th>Événement</th><th>Objet</th><th>Adresse IP</th><th>Détails</th></tr></thead>
        <tbody>
        <?php foreach ($result['rows'] as $entry): $actorMatricule = $entry['acteur_matricule'] ?? ($entry['entite'] === 'acces' ? $entry['entite_id'] : null); $details = admin_log_details($entry); ?>
            <tr><td class="admin-nowrap"><?= e(admin_timestamp($entry['created_at'], $config)) ?></td>
                <td><?= e($entry['acteur_nom'] ?: ($actorMatricule !== null ? 'Compte ' . $actorMatricule : 'Compte supprimé')) ?><?php if ($actorMatricule !== null): ?><small class="admin-subtext"><?= e($actorMatricule) ?></small><?php endif; ?></td>
                <td><span class="admin-badge <?= $entry['operation'] === 'CONNEXION_REFUSEE' ? 'inactive' : '' ?>"><?= e(ADMIN_EVENTS[$entry['operation']] ?? $entry['operation']) ?></span></td>
                <td><?php if ($entry['reference']): ?><a href="/administration/non-conformites/<?= e($entry['entite_id']) ?>"><?= e($entry['reference']) ?></a><?php else: ?>Accès au compte<?php endif; ?></td>
                <td><?= e($entry['adresse_ip'] ?: '—') ?></td>
                <td><?php if ($details): ?><details><summary>Voir les détails</summary><dl class="admin-log-details"><?php foreach ($details as $label => $value): ?><dt><?= e($label) ?></dt><dd class="admin-text"><?= e($value) ?></dd><?php endforeach; ?></dl></details><?php else: ?>—<?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$result['rows']): ?><tr><td colspan="6">Aucun événement pour ce filtre.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php $paginationPath = '/administration/journal'; require __DIR__ . '/admin_pagination.php'; ?>
</section>
