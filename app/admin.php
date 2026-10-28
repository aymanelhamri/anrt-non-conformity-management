<?php
declare(strict_types=1);

// Ces lectures sont appelées seulement après can_admin() dans app/web.php.
// Les mots de passe et hashes des comptes ne font partie d'aucune requête d'affichage.
const ADMIN_EVENTS = [
    'CREATION' => 'Création de non-conformité',
    'MODIFICATION' => 'Modification de non-conformité',
    'VALIDATION' => 'Validation de non-conformité',
    'CONNEXION' => 'Connexion réussie',
    'CONNEXION_REFUSEE' => 'Connexion refusée',
    'DECONNEXION' => 'Déconnexion',
];

function admin_filters(array $input): array
{
    if (array_diff(array_keys($input), ['matricule', 'operation', 'page', 'nc_id'])) {
        throw new HttpError(400, 'Filtre inconnu.');
    }
    foreach ($input as $value) {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new HttpError(400, 'Filtre invalide.');
        }
    }
    $matricule = $input['matricule'] ?? '';
    $operation = $input['operation'] ?? '';
    $page = uint($input['page'] ?? '1');
    $ncId = ($input['nc_id'] ?? '') !== '' ? uint($input['nc_id']) : null;
    if (mb_strlen($matricule, 'UTF-8') > 60 || $page === null
        || ($operation !== '' && !isset(ADMIN_EVENTS[$operation]))
        || (($input['nc_id'] ?? '') !== '' && $ncId === null)) {
        throw new HttpError(400, 'Valeur de filtre invalide.');
    }
    return ['matricule' => $matricule, 'operation' => $operation, 'page' => $page, 'nc_id' => $ncId];
}

function admin_pagination(int $total, int $requestedPage): array
{
    $pages = max(1, (int) ceil($total / 20));
    $page = min($requestedPage, $pages);
    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * 20];
}

function admin_page_url(string $path, array $filters, int $page): string
{
    return $path . '?' . http_build_query([...$filters, 'page' => $page]);
}

// Les horodatages de MySQL sont lus en UTC et présentés dans le fuseau de l'application.
function admin_timestamp(?string $value, array $config): string
{
    return $value === null ? '—' : (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($config['timezone']))->format('d/m/Y H:i:s');
}

function admin_accounts(PDO $pdo): array
{
    return query($pdo, "SELECT a.matricule, p.nom_prenom, a.Date_Deb, a.Date_Fin,
        (a.Date_Deb<=CURRENT_TIMESTAMP AND (a.Date_Fin IS NULL OR a.Date_Fin>CURRENT_TIMESTAMP)) AS compte_actif,
        (SELECT GROUP_CONCAT(ap.profil_code ORDER BY ap.profil_code SEPARATOR ', ')
            FROM acces_profil ap WHERE ap.matricule=a.matricule) AS profils,
        (SELECT COUNT(*) FROM non_conformites n WHERE n.enregistree_par_matricule=a.matricule) AS declarations,
        (SELECT MAX(j.created_at) FROM journal_audit j
            WHERE j.acteur_matricule=a.matricule AND j.entite='acces' AND j.operation='CONNEXION') AS derniere_connexion
        FROM acces a JOIN pers p ON p.matricule=a.matricule
        ORDER BY a.matricule")->fetchAll();
}

function admin_summary(PDO $pdo): array
{
    return [
        'accounts' => (int) query($pdo, 'SELECT COUNT(*) FROM acces')->fetchColumn(),
        'active_accounts' => (int) query($pdo, 'SELECT COUNT(*) FROM acces
            WHERE Date_Deb<=CURRENT_TIMESTAMP AND (Date_Fin IS NULL OR Date_Fin>CURRENT_TIMESTAMP)')->fetchColumn(),
        'nc' => (int) query($pdo, 'SELECT COUNT(*) FROM non_conformites')->fetchColumn(),
        'events' => (int) query($pdo, 'SELECT COUNT(*) FROM journal_audit')->fetchColumn(),
        'refused' => (int) query($pdo, "SELECT COUNT(*) FROM journal_audit WHERE operation='CONNEXION_REFUSEE'")->fetchColumn(),
    ];
}

function admin_ncs(PDO $pdo, array $filters): array
{
    $where = $filters['matricule'] !== '' ? 'WHERE n.enregistree_par_matricule=?' : '';
    $parameters = $filters['matricule'] !== '' ? [$filters['matricule']] : [];
    $total = (int) query($pdo, "SELECT COUNT(*) FROM non_conformites n $where", $parameters)->fetchColumn();
    $pagination = admin_pagination($total, $filters['page']);
    $rows = query($pdo, "SELECT n.id, n.reference, n.enregistree_par_matricule, n.responsable_matricule,
        n.date_creation, n.date_echeance, n.statut_code, pr.code AS processus_code,
        auteur.nom_prenom AS declarant_nom, responsable.nom_prenom AS responsable_nom
        FROM non_conformites n JOIN processus pr ON pr.id=n.processus_id
        JOIN pers auteur ON auteur.matricule=n.enregistree_par_matricule
        JOIN pers responsable ON responsable.matricule=n.responsable_matricule
        $where ORDER BY n.id DESC LIMIT ? OFFSET ?", [...$parameters, 20, $pagination['offset']])->fetchAll();
    return [...$pagination, 'rows' => $rows];
}

function admin_nc(PDO $pdo, int $id): ?array
{
    $row = query($pdo, 'SELECT n.*, pr.code AS processus_code, pr.nom AS processus_nom,
        t.libelle AS type_libelle, ns.libelle AS nature_libelle,
        auteur.nom_prenom AS declarant_nom, responsable.nom_prenom AS responsable_nom
        FROM non_conformites n JOIN processus pr ON pr.id=n.processus_id
        JOIN types_nc t ON t.id=n.type_nc_id JOIN natures_service ns ON ns.id=n.nature_service_id
        JOIN pers auteur ON auteur.matricule=n.enregistree_par_matricule
        JOIN pers responsable ON responsable.matricule=n.responsable_matricule
        WHERE n.id=?', [$id])->fetch();
    return $row ?: null;
}

function admin_logs(PDO $pdo, array $filters): array
{
    $clauses = $parameters = [];
    if ($filters['matricule'] !== '') {
        // Inclure aussi les tentatives d'un matricule inconnu, dont l'acteur est nul.
        $clauses[] = "(j.acteur_matricule=? OR (j.entite='acces' AND j.entite_id=?))";
        array_push($parameters, $filters['matricule'], $filters['matricule']);
    }
    if ($filters['operation'] !== '') {
        $clauses[] = 'j.operation=?';
        $parameters[] = $filters['operation'];
    }
    if ($filters['nc_id'] !== null) {
        $clauses[] = "j.entite='non_conformites' AND j.entite_id=?";
        $parameters[] = (string) $filters['nc_id'];
    }
    $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
    $total = (int) query($pdo, "SELECT COUNT(*) FROM journal_audit j $where", $parameters)->fetchColumn();
    $pagination = admin_pagination($total, $filters['page']);
    $rows = query($pdo, "SELECT j.id, j.acteur_matricule, j.operation, j.entite, j.entite_id,
        j.nouvelles_valeurs, j.adresse_ip, j.created_at, p.nom_prenom AS acteur_nom, n.reference
        FROM journal_audit j LEFT JOIN pers p ON p.matricule=j.acteur_matricule
        LEFT JOIN non_conformites n ON j.entite='non_conformites'
            AND j.entite_id=CAST(n.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
        $where ORDER BY j.id DESC LIMIT ? OFFSET ?", [...$parameters, 20, $pagination['offset']])->fetchAll();
    return [...$pagination, 'rows' => $rows];
}

// Afficher uniquement les informations utiles d'un audit. Les clés techniques
// de soumission, hashes, cookies et autres champs internes ne sont jamais rendus.
function admin_log_details(array $entry): array
{
    $snapshot = json_decode($entry['nouvelles_valeurs'] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot)) { return []; }
    $labels = $entry['entite'] === 'acces'
        ? ['matricule' => 'Matricule saisi', 'motif' => 'Motif']
        : ['reference' => 'Référence', 'processus_nom' => 'Processus', 'type_libelle' => 'Type', 'nature_libelle' => 'Nature de service',
            'description' => 'Description', 'traitement' => 'Traitement',
            'responsable_matricule' => 'Responsable', 'date_creation' => 'Date de création',
            'date_echeance' => 'Échéance', 'statut_code' => 'Statut'];
    $details = [];
    foreach ($labels as $field => $label) {
        if (isset($snapshot[$field]) && is_scalar($snapshot[$field])) {
            $details[$label] = (string) $snapshot[$field];
        }
    }
    if ($entry['entite'] === 'non_conformites' && is_scalar($snapshot['_validation']['niveau'] ?? null)) {
        $details['Niveau de validation'] = (string) $snapshot['_validation']['niveau'];
    }
    return $details;
}
