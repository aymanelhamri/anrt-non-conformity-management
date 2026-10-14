<?php
declare(strict_types=1);

function demo_policy(): array
{
    // Recette technique seulement ; aucun droit confirmé par cette démonstration.
    return [
        'ADMINISTRATEUR' => ['create' => true, 'assign' => 'all'],
        'RESPONSABLE' => ['create' => true, 'assign' => 'self'],
    ];
}

function validate_policy(mixed $policy): void
{
    if (!is_array($policy)) {
        throw new RuntimeException('Les permissions doivent être un objet JSON.');
    }
    foreach ($policy as $profile => $rule) {
        if (!is_string($profile) || strlen($profile) > 30 || !is_array($rule)
            || array_diff(array_keys($rule), ['create', 'assign'])) {
            throw new RuntimeException('Profil ou permission invalide.');
        }
        if (array_key_exists('create', $rule) && !is_bool($rule['create'])) {
            throw new RuntimeException('create doit être un booléen.');
        }
        if (!in_array($rule['assign'] ?? null, [null, 'self', 'all', 'process'], true)) {
            throw new RuntimeException("Périmètre d'affectation inconnu.");
        }
    }
}

function user_rules(array $user, array $config): array
{
    $rules = [];
    foreach ($user['profiles'] as $profile) {
        if (isset($config['permissions'][$profile])) {
            $rules[] = $config['permissions'][$profile];
        }
    }
    return $rules;
}

function can_create(array $user, array $config): bool
{
    foreach (user_rules($user, $config) as $rule) {
        if (($rule['create'] ?? false) === true) {
            return true;
        }
    }
    return false;
}

function assignment_sql(array $user, array $config, ?int $processId = null): array
{
    $scopes = array_column(user_rules($user, $config), 'assign');
    if (in_array('all', $scopes, true)) {
        return ['1=1', []];
    }
    $clauses = $parameters = [];
    if (in_array('self', $scopes, true)) {
        $clauses[] = 'p.matricule=?';
        $parameters[] = $user['matricule'];
    }
    if (in_array('process', $scopes, true)) {
        $clauses[] = 'EXISTS (SELECT 1 FROM processus_responsable target
            JOIN processus_responsable mine ON mine.processus_id=target.processus_id
            WHERE target.matricule=p.matricule AND mine.matricule=?'
            . ($processId !== null ? ' AND mine.processus_id=?' : '') . ')';
        $parameters[] = $user['matricule'];
        if ($processId !== null) {
            $parameters[] = $processId;
        }
    }
    return [$clauses ? '(' . implode(' OR ', $clauses) . ')' : '0=1', $parameters];
}
