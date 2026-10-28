<?php
declare(strict_types=1);

// Appelée DANS la transaction de création, modification ou validation : soit
// le dossier, l'audit et les notifications sont tous enregistrés, soit rien ne l'est.
function notify_nc(PDO $pdo, array $config, array $actor, array $nc, string $event, ?array $before = null): void
{
    $labels = ['CREATION' => 'enregistrée', 'MODIFICATION' => 'modifiée', 'VALIDATION' => 'validée'];
    if (!isset($labels[$event])) { throw new InvalidArgumentException('Événement inconnu.'); }
    // Informer le déclarant, les responsables (ancien et nouveau), les validateurs
    // RH du déclarant et les administrateurs actifs. Un destinataire ne reçoit
    // qu'une notification, même s'il remplit plusieurs de ces rôles.
    $recipients = [$nc['enregistree_par_matricule'], $nc['responsable_matricule']];
    if ($before) { $recipients[] = $before['responsable_matricule']; }
    foreach (query($pdo, 'SELECT DISTINCT mat_val FROM personnel_validation WHERE matricule=?', [$nc['enregistree_par_matricule']])->fetchAll() as $row) {
        $recipients[] = $row['mat_val'];
    }
    $adminProfiles = [];
    foreach ($config['permissions'] as $profile => $rule) {
        if (($rule['admin_view'] ?? false) === true) { $adminProfiles[] = $profile; }
    }
    if ($adminProfiles) {
        $slots = implode(',', array_fill(0, count($adminProfiles), '?'));
        foreach (query($pdo, "SELECT DISTINCT a.matricule FROM acces a JOIN acces_profil ap ON ap.matricule=a.matricule
            WHERE a.Date_Deb<=CURRENT_TIMESTAMP AND (a.Date_Fin IS NULL OR a.Date_Fin>CURRENT_TIMESTAMP)
            AND ap.profil_code IN ($slots)", $adminProfiles)->fetchAll() as $row) { $recipients[] = $row['matricule']; }
    }
    $title = $nc['reference'] . ' · ' . ucfirst($labels[$event]);
    $message = 'La non-conformité ' . $nc['reference'] . ' a été ' . $labels[$event] . ' par '
        . ($actor['nom_prenom'] ?: $actor['matricule']) . '. Statut : ' . $nc['statut_code'] . '.';
    foreach (array_unique($recipients, SORT_STRING) as $recipient) {
        query($pdo, 'INSERT INTO notifications (destinataire_matricule, non_conformite_id, type_notification, titre, message)
            VALUES (?,?,?,?,?)', [$recipient, (int) $nc['id'], 'NC_' . $event, $title, $message]);
    }
}

// Compteur personnel commun à toutes les pages, sans exposer les autres boîtes.
function notification_unread_count(PDO $pdo, array $user): int
{
    return (int) query($pdo, 'SELECT COUNT(*) FROM notifications WHERE destinataire_matricule=? AND lue_le IS NULL', [$user['matricule']])->fetchColumn();
}

function notification_list(PDO $pdo, array $user, array $config, int $page): array
{
    // Le matricule vient toujours de la session, jamais d'un filtre de l'URL.
    $total = (int) query($pdo, 'SELECT COUNT(*) FROM notifications WHERE destinataire_matricule=?', [$user['matricule']])->fetchColumn();
    $unread = $user['unread_notifications'] ?? notification_unread_count($pdo, $user);
    $pagination = admin_pagination($total, $page);
    [$scope, $parameters] = nc_scope($user, $config);
    $rows = query($pdo, "SELECT f.*, n.reference, ($scope) AS dossier_visible FROM notifications f
        LEFT JOIN non_conformites n ON n.id=f.non_conformite_id
        WHERE f.destinataire_matricule=? ORDER BY f.id DESC LIMIT ? OFFSET ?",
        [...$parameters, $user['matricule'], 20, $pagination['offset']])->fetchAll();
    return [...$pagination, 'unread' => $unread, 'rows' => $rows];
}

function read_notification(PDO $pdo, array $user, string $id): void
{
    // BIGINT : conserver le texte de l'identifiant au lieu de risquer un débordement.
    if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)
        || !query($pdo, 'SELECT id FROM notifications WHERE id=? AND destinataire_matricule=?', [$id, $user['matricule']])->fetch()) {
        throw new HttpError(404, 'Notification introuvable.');
    }
    query($pdo, 'UPDATE notifications SET lue_le=COALESCE(lue_le,CURRENT_TIMESTAMP) WHERE id=? AND destinataire_matricule=?', [$id, $user['matricule']]);
}
