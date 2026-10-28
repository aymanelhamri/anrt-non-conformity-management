<?php $title = 'Comptes · Administration ANRT'; $heading = 'Comptes utilisateurs'; require __DIR__ . '/admin_nav.php'; ?>
<section class="card admin-table-card">
    <div class="admin-table-wrap"><table class="admin-table">
        <caption>Comptes disposant d’un accès à l’application</caption>
        <thead><tr><th>Compte</th><th>Profils</th><th>Validité</th><th>Dernière connexion</th><th>Déclarations</th><th>Activité</th></tr></thead>
        <tbody>
        <?php foreach ($accounts as $account): ?>
            <tr>
                <td><strong><?= e($account['nom_prenom'] ?: $account['matricule']) ?></strong><small class="admin-subtext"><?= e($account['matricule']) ?></small></td>
                <td><?= e($account['profils'] ?: 'Aucun profil') ?></td>
                <td><span class="admin-badge <?= (int) $account['compte_actif'] === 1 ? 'active' : 'inactive' ?>"><?= (int) $account['compte_actif'] === 1 ? 'Actif' : 'Hors validité' ?></span><small class="admin-subtext">Début : <?= e(admin_timestamp($account['Date_Deb'], $config)) ?><br>Fin : <?= e(admin_timestamp($account['Date_Fin'], $config)) ?></small></td>
                <td><?= e(admin_timestamp($account['derniere_connexion'], $config)) ?></td>
                <td><a href="/administration/non-conformites?<?= e(http_build_query(['matricule' => $account['matricule']])) ?>"><?= e($account['declarations']) ?> déclaration(s)</a></td>
                <td><a class="button quiet" href="/administration/journal?<?= e(http_build_query(['matricule' => $account['matricule']])) ?>">Voir le journal</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$accounts): ?><tr><td colspan="6">Aucun compte enregistré.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
