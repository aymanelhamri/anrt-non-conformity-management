<?php
declare(strict_types=1);

require_once __DIR__ . '/reference_data.php';

/** Connexion instrumentée : vérifie les appels PDO, jamais l'atomicité MySQL. */
class FakeDatabase extends PDO
{
    public array $calls = [];
    public array $transactions = [];
    public bool $active = false;
    public bool $failAudit = false;
    public bool $loseCommitResponse = false;
    public bool $accountValid = true;
    public bool $referencesValid = true;
    public int $number = 1;
    public ?array $previous = null;
    public ?array $audit = null;
    public array $authLogs = [];
    public array $notifications = [];
    public ?array $insert = null;
    public array $user = ['matricule' => '00017', 'nom_prenom' => 'Compte test'];
    public array $profiles = ['RESPONSABLE'];
    public ?string $store;
    private array $referentials;
    private ?string $reference = null;

    public function __construct(?string $store = null)
    {
        $this->referentials = preview_reference_data();
        $this->store = $store;
        if ($store && is_file($store)) {
            $state = json_decode(file_get_contents($store), true, 512, JSON_THROW_ON_ERROR);
            $this->insert = $state['insert'];
            $this->audit = $state['audit'];
            $this->notifications = $state['notifications'] ?? [];
            $this->previous = ['entite_id' => '41', 'nouvelles_valeurs' => $this->audit[2]];
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        $this->transactions[] = 'begin';
        return $this->active = true;
    }

    public function commit(): bool
    {
        $this->transactions[] = 'commit';
        $this->active = false;
        if ($this->store) {
            file_put_contents($this->store, json_encode(['insert' => $this->insert, 'audit' => $this->audit, 'notifications' => $this->notifications], JSON_THROW_ON_ERROR));
        }
        if ($this->loseCommitResponse) {
            throw new PDOException('Réponse perdue après commit');
        }
        return true;
    }

    public function rollBack(): bool
    {
        $this->transactions[] = 'rollback';
        $this->active = false;
        return true;
    }

    public function inTransaction(): bool { return $this->active; }
    public function lastInsertId(?string $name = null): string|false { return '41'; }

    private function reference_row(string $table, mixed $id): ?array
    {
        foreach ($this->referentials[$table] as $row) {
            if ((string) $row['id'] === (string) $id && $row['actif']) {
                return $row;
            }
        }
        return null;
    }

    public function results(string $sql, array $values): array
    {
        $this->calls[] = [$sql, $values];
        if ($sql === 'SELECT COUNT(*) FROM notifications WHERE destinataire_matricule=? AND lue_le IS NULL') {
            return [[count(array_filter($this->notifications, fn(array $row): bool => $row[0] === $values[0] && ($row[5] ?? null) === null))]];
        }
        if (str_contains($sql, 'GET_LOCK')) { return [[1]]; }
        if (str_contains($sql, 'RELEASE_LOCK')) { return [[1]]; }
        if (str_contains($sql, 'SELECT passe')) { return [['passe' => password_hash('Test-secret', PASSWORD_BCRYPT)]]; }
        if (str_starts_with($sql, 'SELECT matricule FROM pers WHERE matricule=?')) {
            return ($values[0] ?? null) === $this->user['matricule'] ? [['matricule' => $this->user['matricule']]] : [];
        }
        if (str_contains($sql, "VALUES (?,?,'acces',?,?,?)")) {
            if ($this->failAudit) { throw new PDOException('Erreur audit injectée'); }
            $this->authLogs[] = $values;
            return [];
        }
        if (str_contains($sql, 'SELECT DISTINCT a.matricule FROM acces a JOIN acces_profil')) { return []; }
        if (str_contains($sql, 'FROM acces a')) { return $this->accountValid ? [$this->user] : []; }
        if (str_contains($sql, 'FROM acces_profil')) { return array_map(fn(string $p): array => ['profil_code' => $p], $this->profiles); }
        if (str_contains($sql, 'SELECT entite_id')) {
            $key = $this->previous ? json_decode($this->previous['nouvelles_valeurs'], true)['_submission']['key'] : null;
            return $key === $values[1] ? [$this->previous] : [];
        }
        if (preg_match('/^SELECT id(?:, code, nom|, libelle)? FROM (processus|types_nc|natures_service) WHERE /D', $sql, $match)) {
            if (str_contains($sql, 'WHERE id=?')) {
                $row = $this->referencesValid ? $this->reference_row($match[1], $values[0]) : null;
                return $row ? [$row] : [];
            }
            if (str_contains($sql, 'WHERE actif=TRUE ORDER BY id')) {
                return $this->referentials[$match[1]];
            }
        }
        if (str_contains($sql, 'SELECT p.matricule')) {
            if (!$this->referencesValid || str_contains($sql, '0=1') || str_contains($sql, 'processus_responsable')) {
                return [];
            }
            if (str_contains($sql, 'WHERE p.matricule=?') && ($values[0] ?? null) !== $this->user['matricule']) {
                return [];
            }
            return [$this->user];
        }
        if (str_contains($sql, 'CALL generer_reference')) {
            $process = $this->reference_row('processus', $values[1]);
            if (!$process || !$this->referencesValid) {
                throw new PDOException('Processus inexistant ou inactif');
            }
            $this->reference = sprintf('%s-%s-%03d-%s', $values[0], $process['code'], $this->number, substr($values[2], 2, 2));
            return [];
        }
        if (str_contains($sql, 'SELECT derniere_valeur')) { return [[$this->number]]; }
        if (str_contains($sql, 'SELECT @anrt_reference')) { return [[$this->reference]]; }
        if (str_contains($sql, 'INSERT INTO non_conformites')) { $this->insert = $values; return []; }
        if (str_contains($sql, 'SELECT * FROM non_conformites')) { return [[
            'id' => 41, 'reference' => $this->insert[0], 'description' => $this->insert[5],
            'enregistree_par_matricule' => $this->insert[4], 'responsable_matricule' => $this->insert[7], 'statut_code' => 'OUVERTE',
        ]]; }
        if (str_contains($sql, 'SELECT DISTINCT mat_val FROM personnel_validation')) { return []; }
        if (str_contains($sql, 'INSERT INTO notifications')) { $this->notifications[] = $values; return []; }
        if (str_contains($sql, 'INSERT INTO journal_audit')) {
            if ($this->failAudit) { throw new PDOException('Erreur audit injectée'); }
            $this->audit = $values;
            return [];
        }
        throw new RuntimeException('SQL non pris en charge par le double de test.');
    }
}

class FakeStatement extends PDOStatement
{
    private array $parameters = [];
    private array $rows = [];
    public function __construct(private FakeDatabase $pdo, private string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->parameters[$param - 1] = $value;
        return true;
    }
    public function execute(?array $params = null): bool
    {
        $this->rows = $this->pdo->results($this->sql, $params ?? array_values($this->parameters));
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->rows) ?? false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : array_values($row)[$column];
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $mode === PDO::FETCH_COLUMN ? array_map(fn(array $r): mixed => array_values($r)[0], $this->rows) : $this->rows;
    }
    public function nextRowset(): bool { return false; }
    public function closeCursor(): bool { return true; }
}
