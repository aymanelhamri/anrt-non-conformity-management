<?php $editBlockReason = nc_edit_block_reason($nc); ?>
<?php if ($editBlockReason !== null): ?>
    <span class="nc-edit-unavailable">
        <button class="button quiet nc-edit-disabled" type="button" disabled aria-describedby="nc-edit-reason-<?= e($nc['id']) ?>">Modifier</button>
        <small id="nc-edit-reason-<?= e($nc['id']) ?>"><?= e($editBlockReason) ?></small>
    </span>
<?php elseif (nc_can_edit($nc, $user, $config)): ?>
    <a class="button primary" href="/non-conformites/<?= e($nc['id']) ?>/modifier">Modifier</a>
<?php endif; ?>
