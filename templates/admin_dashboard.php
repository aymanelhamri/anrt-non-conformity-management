<?php $title = 'Administration · ANRT'; $heading = 'Vue d’ensemble'; require __DIR__ . '/admin_nav.php'; ?>
<section class="admin-stats" aria-label="Indicateurs">
    <a class="card admin-stat" href="/administration/comptes"><span>Comptes</span><strong><?= e($summary['accounts']) ?></strong><small><?= e($summary['active_accounts']) ?> actif(s)</small></a>
    <a class="card admin-stat" href="/administration/non-conformites"><span>Non-conformités</span><strong><?= e($summary['nc']) ?></strong><small>Tous les processus</small></a>
    <a class="card admin-stat" href="/administration/journal"><span>Événements</span><strong><?= e($summary['events']) ?></strong><small>Journal conservé</small></a>
    <a class="card admin-stat" href="/administration/journal?operation=CONNEXION_REFUSEE"><span>Connexions refusées</span><strong><?= e($summary['refused']) ?></strong><small>Depuis l’activation du suivi</small></a>
</section>
<section class="card form-section">
    <h2>Consulter l’activité d’un compte</h2>
    <p>Ouvrez la liste des comptes, puis son journal pour voir ses connexions, ses déconnexions et ses déclarations. Les non-conformités de tous les comptes sont consultables avec leur description, leur traitement et leur responsable.</p>
    <div class="admin-links"><a class="button primary" href="/administration/comptes">Voir les comptes</a><a class="button quiet" href="/administration/journal">Ouvrir le journal</a></div>
</section>
