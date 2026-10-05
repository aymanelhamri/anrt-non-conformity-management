<?php
$title = 'Nouvelle non-conformité · ANRT';
$fieldError = static function (string $name) use ($errors): string {
    return isset($errors[$name]) ? '<p class="field-error" id="' . e($name) . '-error">' . e($errors[$name]) . '</p>' : '';
};
?>
<a class="back" href="/non-conformites">← Non-conformités</a>
<div class="page-heading"><div><p class="eyebrow">NOUVELLE DÉCLARATION</p><h1>Enregistrer une non-conformité</h1><p class="muted">Les champs marqués d’un * sont obligatoires.</p></div></div>
<?php if ($errors): ?>
    <div class="notice error" role="alert"><strong>Votre déclaration n’a pas pu être confirmée.</strong><p><?= e($errors['form'] ?? 'Vérifiez les champs indiqués. Votre saisie a été conservée.') ?></p></div>
<?php endif; ?>
<form method="post" class="nc-form" data-nc-form>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="submission_token" value="<?= e($submission) ?>">
    <section class="card form-section">
        <h2><span class="step">1</span>Qualification</h2>
        <div class="grid three">
            <?php foreach ([
                'processus_id' => ['Processus', 'processus', 'nom'],
                'type_nc_id' => ['Type de non-conformité', 'types_nc', 'libelle'],
                'nature_service_id' => ['Nature de service', 'natures_service', 'libelle'],
            ] as $name => [$label, $table, $textField]): ?>
                <div><label for="<?= e($name) ?>"><?= e($label) ?> <span aria-hidden="true">*</span></label>
                    <select id="<?= e($name) ?>" name="<?= e($name) ?>" required <?= isset($errors[$name]) ? 'aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '' ?>>
                        <option value="">Sélectionnez…</option>
                        <?php if ($values[$name] !== '' && !in_array($values[$name], array_map('strval', array_column($options[$table], 'id')), true)): ?>
                            <option value="<?= e($values[$name]) ?>" selected>Sélection précédente indisponible</option>
                        <?php endif; ?>
                        <?php foreach ($options[$table] as $row): ?>
                            <option value="<?= e($row['id']) ?>" <?= $values[$name] === (string) $row['id'] ? 'selected' : '' ?>><?= e($row[$textField]) ?></option>
                        <?php endforeach; ?>
                    </select><?= $fieldError($name) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="card form-section">
        <h2><span class="step">2</span>Constat</h2><label for="description">Description *</label>
        <textarea id="description" name="description" rows="5" required <?= isset($errors['description']) ? 'aria-invalid="true" aria-describedby="description-error"' : '' ?> placeholder="Décrivez les faits constatés et le contexte…"><?= e($values['description']) ?></textarea>
        <?= $fieldError('description') ?>
    </section>
    <section class="card form-section">
        <h2><span class="step">3</span>Prise en charge</h2>
        <div class="grid two">
            <div><label for="responsable_matricule">Responsable *</label>
                <select id="responsable_matricule" name="responsable_matricule" required <?= isset($errors['responsable_matricule']) ? 'aria-invalid="true" aria-describedby="responsable_matricule-error"' : '' ?>>
                    <option value="">Sélectionnez…</option>
                    <?php if ($values['responsable_matricule'] !== '' && !in_array($values['responsable_matricule'], array_column($options['responsables'], 'matricule'), true)): ?>
                        <option value="<?= e($values['responsable_matricule']) ?>" selected>Sélection précédente indisponible</option>
                    <?php endif; ?>
                    <?php foreach ($options['responsables'] as $person): ?>
                        <option value="<?= e($person['matricule']) ?>" <?= $values['responsable_matricule'] === $person['matricule'] ? 'selected' : '' ?>><?= e(($person['nom_prenom'] ?: $person['matricule']) . ' · ' . $person['matricule']) ?></option>
                    <?php endforeach; ?>
                </select><?= $fieldError('responsable_matricule') ?>
                <?php if (!$options['responsables']): ?><p class="field-error">Aucun responsable autorisé n’est disponible. Contactez l’administrateur.</p><?php endif; ?>
            </div>
            <div><label for="date_echeance">Échéance<?= $config['deadline_required'] ? ' *' : '' ?></label>
                <input id="date_echeance" type="date" name="date_echeance" min="<?= e($today) ?>" value="<?= e($values['date_echeance']) ?>" <?= $config['deadline_required'] ? 'required' : '' ?> <?= isset($errors['date_echeance']) ? 'aria-invalid="true" aria-describedby="date_echeance-error"' : '' ?>>
                <?= $fieldError('date_echeance') ?>
            </div>
        </div>
        <label for="traitement">Traitement *</label>
        <textarea id="traitement" name="traitement" rows="4" required <?= isset($errors['traitement']) ? 'aria-invalid="true" aria-describedby="traitement-error"' : '' ?> placeholder="Renseignez le traitement de cette non-conformité…"><?= e($values['traitement']) ?></textarea>
        <?= $fieldError('traitement') ?>
    </section>
    <section class="creation-info">
        <span>Déclarant : <strong><?= e($user['nom_prenom'] ?: $user['matricule']) ?></strong></span>
        <span>Date de création : <strong><?= e(display_date($today)) ?></strong></span>
        <span>Statut initial : <strong>Ouverte</strong></span><span>La référence sera attribuée à l’enregistrement.</span>
    </section>
    <div class="form-actions"><a class="button quiet" href="/non-conformites" data-cancel>Annuler</a><button class="button primary" type="submit" data-submit>Enregistrer la non-conformité</button></div>
</form>
