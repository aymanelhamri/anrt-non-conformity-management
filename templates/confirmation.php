<?php $title = 'Enregistrement confirmé · ANRT'; ?>
<section class="card form-section">
    <h1>Enregistrement confirmé</h1>
    <p class="notice success" role="status"><?= e($confirmation['replayed']
        ? 'Enregistrement retrouvé : votre non-conformité avait déjà été créée.'
        : 'Votre non-conformité a été enregistrée.') ?></p>
    <p>Référence : <strong><?= e($confirmation['reference']) ?></strong></p>
    <a class="button primary" href="/non-conformites/nouvelle">Faire une autre déclaration</a>
</section>
