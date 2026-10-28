<?php
declare(strict_types=1);

// La référence, le processus qui la compose, le déclarant, les dates de création
// et de clôture et le statut sont protégés. La modification ne les remplace pas.
const NC_EDIT_FIELDS = ['type_nc_id', 'nature_service_id', 'description', 'traitement', 'responsable_matricule', 'date_echeance'];

function nc_scope(array $user, array $config): array
{
    if (can_admin($user, $config)) { return ['1=1', []]; }
    return ['(n.enregistree_par_matricule=? OR n.responsable_matricule=? OR EXISTS
        (SELECT 1 FROM personnel_validation pv WHERE pv.matricule=n.enregistree_par_matricule AND pv.mat_val=?))',
        [$user['matricule'], $user['matricule'], $user['matricule']]];
}

function nc_filters(array $input): array
{
    if (array_diff(array_keys($input), ['statut', 'page'])) { throw new HttpError(400, 'Filtre inconnu.'); }
    foreach ($input as $value) {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) { throw new HttpError(400, 'Filtre invalide.'); }
    }
    $page = uint($input['page'] ?? '1');
    $status = $input['statut'] ?? '';
    if ($page === null || !preg_match('/^[A-Z_]{0,20}$/D', $status)) { throw new HttpError(400, 'Valeur de filtre invalide.'); }
    return ['statut' => $status, 'page' => $page];
}

function nc_list(PDO $pdo, array $user, array $config, array $filters): array
{
    [$scope, $parameters] = nc_scope($user, $config);
    if ($filters['statut'] !== '') { $scope .= ' AND n.statut_code=?'; $parameters[] = $filters['statut']; }
    $pagination = admin_pagination((int) query($pdo, "SELECT COUNT(*) FROM non_conformites n WHERE $scope", $parameters)->fetchColumn(), $filters['page']);
    $rows = query($pdo, "SELECT n.*, s.libelle AS statut_libelle, s.est_final, p.nom_prenom AS responsable_nom,
        EXISTS (SELECT 1 FROM journal_audit j WHERE j.entite='non_conformites' AND j.operation='VALIDATION'
            AND j.entite_id=CAST(n.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci) AS deja_validee
        FROM non_conformites n JOIN statuts s ON s.code=n.statut_code JOIN pers p ON p.matricule=n.responsable_matricule
        WHERE $scope ORDER BY n.id DESC LIMIT ? OFFSET ?", [...$parameters, 20, $pagination['offset']])->fetchAll();
    return [...$pagination, 'rows' => $rows];
}

function nc_closed(array $nc): bool
{
    return (bool) $nc['est_final'] || $nc['statut_code'] === 'CLOTUREE' || $nc['date_cloture'] !== null;
}

function nc_can_edit(array $nc, array $user, array $config): bool
{
    if (nc_edit_block_reason($nc) !== null) { return false; }
    foreach (user_rules($user, $config) as $rule) {
        if (($rule['edit'] ?? null) === 'all' || (($rule['edit'] ?? null) === 'related'
            && in_array($user['matricule'], [$nc['enregistree_par_matricule'], $nc['responsable_matricule']], true))) { return true; }
    }
    return false;
}

// La première validation fige le dossier, même si l'affectation RH change ensuite.
// Ce message sert à la fois au bouton désactivé et au refus côté serveur.
function nc_edit_block_reason(array $nc): ?string
{
    if (nc_closed($nc)) { return 'Cette non-conformité est terminée et ne peut plus être modifiée.'; }
    return !empty($nc['deja_validee']) ? 'Non-conformité validée : modification impossible.' : null;
}

function nc_record(PDO $pdo, int $id, array $user, array $config, bool $lock = false): array
{
    [$scope, $parameters] = nc_scope($user, $config);
    $nc = query($pdo, "SELECT n.*, s.libelle AS statut_libelle, s.est_final,
        pr.code AS processus_code, pr.nom AS processus_nom, t.libelle AS type_libelle, ns.libelle AS nature_libelle,
        auteur.nom_prenom AS declarant_nom, responsable.nom_prenom AS responsable_nom
        FROM non_conformites n JOIN statuts s ON s.code=n.statut_code
        JOIN processus pr ON pr.id=n.processus_id JOIN types_nc t ON t.id=n.type_nc_id
        JOIN natures_service ns ON ns.id=n.nature_service_id JOIN pers auteur ON auteur.matricule=n.enregistree_par_matricule
        JOIN pers responsable ON responsable.matricule=n.responsable_matricule
        WHERE n.id=? AND $scope" . ($lock ? ' FOR UPDATE OF n FOR SHARE OF s, pr, t, ns, auteur, responsable' : ''), [$id, ...$parameters])->fetch();
    if (!$nc) { throw new HttpError(404, 'Non-conformité introuvable.'); }
    // L'identifiant d'audit donne une version fiable même pour deux changements
    // dans la même seconde. Les validations ne changent pas le contenu du dossier.
    $nc['revision'] = (int) (query($pdo, "SELECT id FROM journal_audit WHERE entite='non_conformites' AND entite_id=?
        AND operation IN ('CREATION','MODIFICATION') ORDER BY id DESC LIMIT 1" . ($lock ? ' FOR SHARE' : ''), [(string) $id])->fetchColumn() ?: 0);
    // Après le verrou sur la NC, une lecture verrouillée voit aussi une validation
    // qui aurait été enregistrée pendant l'attente. Un ancien formulaire reste bloqué.
    $nc['deja_validee'] = query($pdo, "SELECT id FROM journal_audit WHERE entite='non_conformites' AND entite_id=?
        AND operation='VALIDATION' ORDER BY id LIMIT 1" . ($lock ? ' FOR SHARE' : ''), [(string) $id])->fetchColumn() !== false;
    return $nc;
}

function nc_version(array $nc, array $user, array $config): string
{
    $snapshot = array_intersect_key($nc, array_flip([...NC_FIELDS, 'id', 'reference', 'enregistree_par_matricule',
        'date_creation', 'date_cloture', 'statut_code', 'updated_at', 'revision', 'est_final']));
    ksort($snapshot);
    return hash_hmac('sha256', 'nc-version|' . $user['matricule'] . '|' . json_encode($snapshot, JSON_THROW_ON_ERROR), $config['secret']);
}

function nc_history(PDO $pdo, int $id): array
{
    return query($pdo, "SELECT j.operation, j.anciennes_valeurs, j.nouvelles_valeurs, j.created_at, p.nom_prenom AS acteur_nom,
        j.acteur_matricule FROM journal_audit j LEFT JOIN pers p ON p.matricule=j.acteur_matricule
        WHERE j.entite='non_conformites' AND j.entite_id=? AND j.operation IN ('CREATION','MODIFICATION','VALIDATION')
        ORDER BY j.id DESC", [(string) $id])->fetchAll();
}

function nc_validators(PDO $pdo, array $nc): array
{
    $rows = query($pdo, 'SELECT num, mat_val, nom_valideur, niv_val FROM personnel_validation WHERE matricule=? ORDER BY num', [$nc['enregistree_par_matricule']])->fetchAll();
    $validations = query($pdo, "SELECT acteur_matricule, created_at, nouvelles_valeurs FROM journal_audit
        WHERE entite='non_conformites' AND entite_id=? AND operation='VALIDATION' ORDER BY id", [(string) $nc['id']])->fetchAll();
    foreach ($rows as &$row) {
        $row['validated_at'] = null;
        foreach ($validations as $validation) {
            $data = json_decode($validation['nouvelles_valeurs'], true, 512, JSON_THROW_ON_ERROR)['_validation'] ?? [];
            if ((int) ($data['assignment_id'] ?? 0) === (int) $row['num'] && (int) ($data['revision'] ?? -1) === $nc['revision']
                && ($data['niveau'] ?? null) === $row['niv_val']
                && $validation['acteur_matricule'] === $row['mat_val']) { $row['validated_at'] = $validation['created_at']; }
        }
    }
    unset($row);
    return $rows;
}

function nc_audit_snapshot(array $nc): array
{
    // Conserver les champs métier et leurs libellés, sans secret technique.
    return array_intersect_key($nc, array_flip([...NC_FIELDS, 'id', 'reference', 'enregistree_par_matricule',
        'date_creation', 'date_cloture', 'statut_code', 'processus_nom', 'type_libelle', 'nature_libelle']));
}

// Le verrou de ligne empêche une modification et une clôture/validation de se
// croiser. Les autorisations et le statut sont relus au moment de l'écriture.
function update_nc(PDO $pdo, array $config, array $user, int $id, array $values, string $version, ?string $address): bool
{
    $pdo->beginTransaction();
    try {
        $currentUser = load_user($pdo, $user['matricule'], true);
        if (!$currentUser) { throw new FormError(['form' => 'Votre compte ne permet plus cet enregistrement.'], 403); }
        $before = nc_record($pdo, $id, $currentUser, $config, true);
        if (!nc_can_edit($before, $currentUser, $config)) {
            throw new FormError(['form' => nc_edit_block_reason($before) ?? 'Modification non autorisée.'], 403);
        }
        if (!hash_equals(nc_version($before, $currentUser, $config), $version)) {
            throw new FormError(['form' => 'Le dossier a changé depuis l’ouverture du formulaire. Consultez sa version actuelle avant de recommencer.'], 409);
        }
        if (array_diff(array_keys($values), NC_EDIT_FIELDS)) { throw new FormError(['form' => 'Champ protégé ou inconnu.'], 400); }
        // Une échéance déjà dépassée reste modifiable : elle doit être postérieure
        // à la création d'origine, pas obligatoirement à la date d'aujourd'hui.
        $data = validate_nc([...$values, 'processus_id' => (string) $before['processus_id']], $before['date_creation'], $config['deadline_required']);
        $errors = [];
        foreach (['type_nc_id' => 'types_nc', 'nature_service_id' => 'natures_service'] as $field => $table) {
            if ((string) $data[$field] !== (string) $before[$field]) {
                $choice = query($pdo, "SELECT id, libelle FROM $table WHERE id=? AND actif=TRUE FOR SHARE", [$data[$field]])->fetch();
                if (!$choice) { $errors[$field] = 'Cette valeur est inactive ou inconnue.'; }
                else { $data[$field === 'type_nc_id' ? 'type_libelle' : 'nature_libelle'] = $choice['libelle']; }
            }
        }
        // Conserver un responsable déjà affecté reste permis ; une réaffectation
        // est soumise au même périmètre que lors de la création.
        if ($data['responsable_matricule'] !== $before['responsable_matricule']) {
            [$scope, $parameters] = assignment_sql($currentUser, $config, (int) $before['processus_id']);
            if (!query($pdo, "SELECT p.matricule FROM pers p WHERE p.matricule=? AND $scope FOR SHARE", [$data['responsable_matricule'], ...$parameters])->fetch()) { $errors['responsable_matricule'] = 'Responsable non autorisé.'; }
        }
        if ($errors) { throw new FormError($errors); }
        $changed = false;
        foreach (NC_EDIT_FIELDS as $field) { if ((string) $data[$field] !== (string) $before[$field]) { $changed = true; } }
        if (!$changed) { $pdo->rollBack(); return false; }
        query($pdo, 'UPDATE non_conformites SET type_nc_id=?, nature_service_id=?, description=?, traitement=?, responsable_matricule=?, date_echeance=? WHERE id=?',
            [...array_map(fn(string $field): mixed => $data[$field], NC_EDIT_FIELDS), $id]);
        $after = [...$before, ...$data];
        query($pdo, "INSERT INTO journal_audit (acteur_matricule, operation, entite, entite_id, anciennes_valeurs, nouvelles_valeurs, adresse_ip)
            VALUES (?,'MODIFICATION','non_conformites',?,?,?,?)", [$currentUser['matricule'], (string) $id,
                json_encode(nc_audit_snapshot($before), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                json_encode(nc_audit_snapshot($after), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $address]);
        notify_nc($pdo, $config, $currentUser, $after, 'MODIFICATION', $before);
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function validate_nc_record(PDO $pdo, array $config, array $user, int $id, int $assignment, string $version, ?string $address): bool
{
    $pdo->beginTransaction();
    try {
        $currentUser = load_user($pdo, $user['matricule'], true);
        if (!$currentUser) { throw new FormError(['form' => 'Compte non autorisé.'], 403); }
        $nc = nc_record($pdo, $id, $currentUser, $config, true);
        if (nc_closed($nc)) { throw new FormError(['form' => 'Ce dossier est terminé ; la validation est fermée.'], 403); }
        if (!hash_equals(nc_version($nc, $currentUser, $config), $version)) { throw new FormError(['form' => 'Le dossier a été modifié. Relisez sa nouvelle version avant de valider.'], 409); }
        // Le validateur vient de la chaîne RH du DÉCLARANT. Le profil admin ne
        // remplace pas cette désignation. Relire et verrouiller la ligne RH.
        $assigned = query($pdo, 'SELECT num, mat_val, niv_val FROM personnel_validation WHERE num=? AND matricule=? AND mat_val=? FOR SHARE',
            [$assignment, $nc['enregistree_par_matricule'], $currentUser['matricule']])->fetch();
        if (!$assigned) { throw new FormError(['form' => 'Vous ne faites pas partie des validateurs désignés pour ce dossier.'], 403); }
        foreach (nc_validators($pdo, $nc) as $validator) {
            if ((int) $validator['num'] === $assignment && $validator['validated_at'] !== null) { $pdo->rollBack(); return false; }
        }
        $snapshot = nc_audit_snapshot($nc);
        $snapshot['_validation'] = ['assignment_id' => $assignment, 'niveau' => $assigned['niv_val'], 'revision' => $nc['revision']];
        query($pdo, "INSERT INTO journal_audit (acteur_matricule, operation, entite, entite_id, nouvelles_valeurs, adresse_ip)
            VALUES (?,'VALIDATION','non_conformites',?,?,?)", [$currentUser['matricule'], (string) $id, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $address]);
        notify_nc($pdo, $config, $currentUser, $nc, 'VALIDATION');
        // Valider ne clôture pas la NC : aucune transition de statut n'est déduite.
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}
