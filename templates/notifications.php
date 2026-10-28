<?php $title = 'Notifications · ANRT'; ?>
<div class="page-heading"><div><p class="eyebrow">SUIVI DES DOSSIERS</p><h1>Notifications</h1><p class="muted"><?= e($result['unread']) ?> notification(s) non lue(s). Horaires : <?= e($config['timezone']) ?>.</p></div></div>
<?php if (!$result['rows']): ?><section class="card form-section"><p>Aucune notification pour le moment.</p></section><?php endif; ?>
<?php foreach ($result['rows'] as $notification): ?>
    <article class="card form-section">
        <h2><?= e($notification['titre']) ?> <?php if ($notification['lue_le'] === null): ?><span class="admin-badge active">Non lue</span><?php endif; ?></h2>
        <p class="muted"><?= e(admin_timestamp($notification['created_at'], $config)) ?></p><p><?= e($notification['message']) ?></p>
        <div class="admin-links">
            <?php if ($notification['non_conformite_id'] && $notification['dossier_visible']): ?><a class="button quiet" href="/non-conformites/<?= e($notification['non_conformite_id']) ?>">Consulter la non-conformité</a><?php endif; ?>
            <?php if ($notification['lue_le'] === null): ?><form method="post" action="/notifications/<?= e($notification['id']) ?>/lire"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="button quiet" type="submit">Marquer comme lue</button></form><?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
<?php $paginationPath = '/notifications'; require __DIR__ . '/admin_pagination.php'; ?>
