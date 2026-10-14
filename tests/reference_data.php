<?php
declare(strict_types=1);

/** Référentiels de l'aperçu, sans exécuter le SQL ni copier les libellés. */
function preview_reference_data(): array
{
    $source = file_get_contents(dirname(__DIR__) . '/database/002_seed_referentiels.sql');
    if ($source === false) {
        throw new RuntimeException("Le fichier des référentiels de l'aperçu est introuvable.");
    }
    return parse_preview_reference_data($source);
}

function parse_preview_reference_data(string $source): array
{
    $tables = [
        'processus' => ['code', 'nom'],
        'types_nc' => ['libelle'],
        'natures_service' => ['libelle'],
    ];
    $result = [];
    foreach ($tables as $table => $columns) {
        $blockPattern = '~^\s*INSERT\s+INTO\s+' . preg_quote($table, '~')
            . '\s*\(\s*' . implode('\s*,\s*', $columns) . '\s*\)\s+VALUES\s*'
            . '(.*?)\s*ON\s+DUPLICATE\s+KEY\s+UPDATE\b~msu';
        if (preg_match_all($blockPattern, $source, $blocks) !== 1) {
            throw new RuntimeException("Bloc SQL absent ou ambigu pour le référentiel $table.");
        }
        $tuplePattern = '\(\s*' . implode('\s*,\s*', array_fill(0, count($columns), "'((?:''|[^'])*)'")) . '\s*\)';
        $values = trim($blocks[1][0]);
        if (preg_match('~\A' . $tuplePattern . '(?:\s*,\s*' . $tuplePattern . ')*\z~su', $values) !== 1) {
            throw new RuntimeException("Valeurs SQL non prises en charge pour le référentiel $table.");
        }
        preg_match_all('~' . $tuplePattern . '~su', $values, $tuples, PREG_SET_ORDER);
        $result[$table] = [];
        foreach ($tuples as $index => $tuple) {
            // Identifiants d'aperçu : ordre d'insertion dans une base vierge.
            $row = ['id' => $index + 1, 'actif' => true];
            foreach ($columns as $position => $column) {
                $row[$column] = str_replace("''", "'", $tuple[$position + 1]);
            }
            $result[$table][] = $row;
        }
    }
    return $result;
}
