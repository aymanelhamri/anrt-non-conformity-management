<div class="page-heading"><div><p class="eyebrow">ADMINISTRATION</p><h1><?= e($heading) ?></h1><p class="muted">Consultation des comptes, des déclarations et de leur activité.</p></div></div>
<nav class="admin-nav" aria-label="Administration">
    <?php foreach (['overview' => ['/administration', 'Vue d’ensemble'], 'accounts' => ['/administration/comptes', 'Comptes'], 'nc' => ['/administration/non-conformites', 'Non-conformités'], 'logs' => ['/administration/journal', 'Journal d’activité']] as $key => [$url, $label]): ?>
        <a href="<?= e($url) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
