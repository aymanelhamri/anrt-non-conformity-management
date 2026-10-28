<section class="card form-section">
    <h2>Informations de la déclaration</h2>
    <dl class="admin-detail-grid">
        <?php foreach ([
            'Processus' => $nc['processus_code'] . ' · ' . $nc['processus_nom'], 'Type' => $nc['type_libelle'],
            'Nature du service' => $nc['nature_libelle'], 'Statut' => $nc['statut_libelle'] ?? $nc['statut_code'],
            'Déclarant' => ($nc['declarant_nom'] ?: $nc['enregistree_par_matricule']) . ' · ' . $nc['enregistree_par_matricule'],
            'Responsable' => ($nc['responsable_nom'] ?: $nc['responsable_matricule']) . ' · ' . $nc['responsable_matricule'],
            'Création' => display_date($nc['date_creation']), 'Échéance' => display_date($nc['date_echeance']),
            'Clôture' => display_date($nc['date_cloture']), 'Action corrective nécessaire' => (int) $nc['action_corrective_necessaire'] === 1 ? 'Oui' : 'Non',
            'Vérification du traitement' => $nc['verification_traitement_code'] ?: 'Non renseignée',
            'Évaluation de l’efficacité' => $nc['evaluation_efficacite_code'] ?: 'Non renseignée',
        ] as $label => $value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
    </dl>
</section>
<?php foreach (['description' => 'Description', 'traitement' => 'Traitement', 'analyse_causes' => 'Analyse des causes'] as $field => $label): ?>
    <section class="card form-section"><h2><?= e($label) ?></h2><p class="admin-text"><?= e($nc[$field] ?: 'Non renseignée') ?></p></section>
<?php endforeach; ?>
