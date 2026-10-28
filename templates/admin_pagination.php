<nav class="admin-pagination" aria-label="Pagination">
    <span><?= e($result['total']) ?> résultat(s) · Page <?= e($result['page']) ?> sur <?= e($result['pages']) ?></span>
    <div>
        <?php if ($result['page'] > 1): ?><a class="button quiet" href="<?= e(admin_page_url($paginationPath, $filters, $result['page'] - 1)) ?>">Précédente</a><?php endif; ?>
        <?php if ($result['page'] < $result['pages']): ?><a class="button quiet" href="<?= e(admin_page_url($paginationPath, $filters, $result['page'] + 1)) ?>">Suivante</a><?php endif; ?>
    </div>
</nav>
