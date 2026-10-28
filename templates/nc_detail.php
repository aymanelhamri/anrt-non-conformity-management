<?php $title = $nc['reference'] . ' · ANRT'; ?>
<div class="page-heading"><div><p class="eyebrow">NON-CONFORMITÉ</p><h1><?= e($nc['reference']) ?></h1><span class="admin-badge"><?= e($nc['statut_libelle']) ?></span></div></div>
<div class="admin-links admin-detail-actions"><a class="button quiet" href="/non-conformites">Retour à la liste</a><?php require __DIR__ . '/nc_edit_action.php'; ?></div>
<?php if ($notice): ?><p class="notice success" role="status"><?= e($notice) ?></p><?php endif; ?>
<?php if ($errors): ?><p class="notice error" role="alert"><?= e($errors['form'] ?? 'Enregistrement impossible.') ?></p><?php endif; ?>
<?php if (nc_closed($nc)): ?><p class="notice">Ce dossier est terminé. La modification et la validation sont fermées.</p><?php endif; ?>
<?php require __DIR__ . '/nc_information.php'; ?>
<section class="card form-section">
    <h2>Validations de la version actuelle</h2>
    <p class="muted">Les validateurs sont ceux du déclarant dans le référentiel RH. Dès la première validation, le dossier ne peut plus être modifié. La validation ne clôture pas le dossier.</p>
    <?php if (!$validators): ?><p>Aucun validateur RH n’est renseigné pour ce déclarant. Contactez l’administrateur.</p><?php endif; ?>
    <?php foreach ($validators as $validator): ?>
        <div class="nc-validation">
            <p><strong><?= e($validator['nom_valideur'] ?: $validator['mat_val']) ?></strong> · Niveau <?= e($validator['niv_val']) ?> · <span class="admin-badge <?= $validator['validated_at'] ? 'active' : '' ?>"><?= $validator['validated_at'] ? 'Validée le ' . e(admin_timestamp($validator['validated_at'], $config)) : 'À valider' ?></span></p>
            <?php if (!$validator['validated_at'] && $validator['mat_val'] === $user['matricule'] && !nc_closed($nc)): ?>
                <form method="post" action="/non-conformites/<?= e($nc['id']) ?>/valider">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="version_token" value="<?= e(nc_version($nc, $user, $config)) ?>"><input type="hidden" name="assignment_id" value="<?= e($validator['num']) ?>">
                    <button class="button primary" type="submit">Valider cette version · Niveau <?= e($validator['niv_val']) ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
<section class="card form-section">
    <h2>Historique du dossier</h2>
    <?php if (!$history): ?><p>Aucun événement enregistré.</p><?php endif; ?>
    <?php foreach ($history as $entry): ?>
        <details class="nc-history"><summary><?= e(admin_timestamp($entry['created_at'], $config)) ?> · <?= e(ADMIN_EVENTS[$entry['operation']] ?? $entry['operation']) ?> · <?= e($entry['acteur_nom'] ?: $entry['acteur_matricule']) ?></summary>
            <?php foreach (['anciennes_valeurs' => 'Avant', 'nouvelles_valeurs' => 'Après'] as $key => $label): ?>
                <?php if ($entry[$key]): $details = admin_log_details(['entite' => 'non_conformites', 'nouvelles_valeurs' => $entry[$key]]); ?>
                    <h3><?= e($label) ?></h3><dl class="admin-log-details"><?php foreach ($details as $field => $value): ?><dt><?= e($field) ?></dt><dd class="admin-text"><?= e($value) ?></dd><?php endforeach; ?></dl>
                <?php endif; ?>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>
</section>
