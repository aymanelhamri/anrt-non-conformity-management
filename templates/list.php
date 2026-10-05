<?php $title = 'Non-conformités · ANRT'; ?>
<div class="page-heading">
    <div><p class="eyebrow">SUIVI QUALITÉ</p><h1>Non-conformités</h1><p class="muted">Retrouvez les dossiers de votre périmètre.</p></div>
    <?php if ($mayCreate): ?><a class="button primary" href="/non-conformites/nouvelle">+ Nouvelle non-conformité</a><?php endif; ?>
</div>
<form class="card filters" method="get">
    <div><label for="reference">Référence</label>
        <input id="reference" name="reference" maxlength="50" placeholder="NC-SI-…" value="<?= e($filters['reference']) ?>"></div>
    <div><label for="processus_id">Processus</label>
        <select id="processus_id" name="processus_id">
            <option value="">Tous les processus</option>
            <?php foreach ($options['processus'] as $process): ?>
                <option value="<?= e($process['id']) ?>" <?= $filters['processus_id'] === (string) $process['id'] ? 'selected' : '' ?>><?= e($process['code'] . ' · ' . $process['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div><label for="statut_code">Statut</label>
        <select id="statut_code" name="statut_code">
            <option value="">Tous les statuts</option>
            <?php foreach ($options['statuts'] as $status): ?>
                <option value="<?= e($status['code']) ?>" <?= $filters['statut_code'] === $status['code'] ? 'selected' : '' ?>><?= e($status['libelle']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div><label for="responsable_matricule">Matricule du responsable</label>
        <input id="responsable_matricule" name="responsable_matricule" maxlength="60" value="<?= e($filters['responsable_matricule']) ?>"></div>
    <div class="filter-actions"><button class="button primary" type="submit">Filtrer</button><a class="button quiet" href="/non-conformites">Réinitialiser</a></div>
</form>
<section class="card list-card">
    <div class="list-summary"><strong><?= e($total) ?> dossier<?= $total !== 1 ? 's' : '' ?></strong><span class="muted">Page <?= e($page) ?> sur <?= e($pages) ?></span></div>
    <?php if ($rows): ?>
        <div class="table-scroll"><table>
            <thead><tr><th>Référence</th><th>Processus / Type</th><th>Responsable</th><th>Création</th><th>Échéance</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a class="reference" href="/non-conformites/<?= e($row['id']) ?>"><?= e($row['reference']) ?></a></td>
                    <td><?= e($row['processus']) ?><small><?= e($row['type_nc']) ?></small></td>
                    <td><?= e($row['responsable'] ?: $row['responsable_matricule']) ?></td>
                    <td><?= e(display_date($row['date_creation'])) ?></td>
                    <td><?= e(display_date($row['date_echeance'])) ?></td>
                    <td><span class="badge <?= e(strtolower($row['statut_code'])) ?>"><?= e(['OUVERTE' => 'Ouverte', 'CLOTUREE' => 'Clôturée', 'AJOURNEE' => 'Ajournée', 'ANNULEE' => 'Annulée'][$row['statut_code']] ?? $row['statut_code']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <div class="empty"><h2>Aucune non-conformité à afficher</h2><p class="muted">Aucun dossier de votre périmètre ne correspond à ces critères.</p></div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Pagination">
            <?php if ($page > 1): ?><a class="button quiet" href="<?= e(page_url($filters, $page - 1)) ?>">← Précédente</a><?php endif; ?>
            <span>Page <?= e($page) ?> / <?= e($pages) ?></span>
            <?php if ($page < $pages): ?><a class="button quiet" href="<?= e(page_url($filters, $page + 1)) ?>">Suivante →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
