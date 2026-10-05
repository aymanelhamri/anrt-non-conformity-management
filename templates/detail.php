<?php $title = $nc['reference'] . ' · ANRT'; ?>
<a class="back" href="/non-conformites">← Non-conformités</a>
<div class="page-heading">
    <div><p class="eyebrow">FICHE DE NON-CONFORMITÉ</p><h1><?= e($nc['reference']) ?></h1>
        <p class="muted">Créée le <?= e(display_date($nc['date_creation'])) ?> par <?= e($nc['declarant'] ?: $nc['enregistree_par_matricule']) ?></p></div>
    <span class="badge <?= e(strtolower($nc['statut_code'])) ?>"><?= e($nc['statut']) ?></span>
</div>
<div class="detail-layout">
    <div>
        <section class="card form-section"><h2>Qualification</h2><dl class="grid three">
            <div><dt>Processus</dt><dd><?= e($nc['processus_code'] . ' · ' . $nc['processus']) ?></dd></div>
            <div><dt>Type de non-conformité</dt><dd><?= e($nc['type_nc']) ?></dd></div>
            <div><dt>Nature de service</dt><dd><?= e($nc['nature_service']) ?></dd></div>
        </dl></section>
        <section class="card form-section"><h2>Constat</h2><p class="business-text"><?= e($nc['description']) ?></p></section>
        <section class="card form-section"><h2>Traitement</h2><p class="business-text"><?= e($nc['traitement']) ?></p></section>
    </div>
    <aside>
        <section class="card form-section"><h2>Suivi du dossier</h2><dl>
            <dt>Responsable</dt><dd><?= e($nc['responsable'] ?: $nc['responsable_matricule']) ?></dd>
            <dt>Échéance</dt><dd><?= e(display_date($nc['date_echeance'])) ?></dd>
            <dt>Création</dt><dd><?= e(display_date($nc['date_creation'])) ?></dd>
            <dt>Dernière mise à jour</dt><dd><?= e(display_time($nc['updated_at'], $config)) ?></dd>
        </dl></section>
        <?php if ($audit): ?>
            <section class="card form-section"><h2>Historique</h2><ol class="timeline">
                <?php foreach ($audit as $event): ?>
                    <li><strong><?= e($event['operation'] === 'CREATION' ? 'Enregistrement' : $event['operation']) ?></strong>
                        <p><?= e($event['acteur'] ?: ($event['acteur_matricule'] ?: 'Acteur indisponible')) ?></p>
                        <small><?= e(display_time($event['created_at'], $config)) ?></small></li>
                <?php endforeach; ?>
            </ol></section>
        <?php endif; ?>
    </aside>
</div>
