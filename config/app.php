<?php
declare(strict_types=1);

// Format .env : NOM=valeur, guillemets facultatifs, commentaires sur leur ligne.
function load_environment(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $lineNumber => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $match)) {
            throw new RuntimeException('Ligne .env invalide : ' . ($lineNumber + 1));
        }
        $value = trim($match[2]);
        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($match[1]) === false) {
            putenv($match[1] . '=' . $value);
        }
    }
}

function env_bool(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    return match (strtolower($value)) {
        'true' => true,
        'false' => false,
        default => throw new RuntimeException("$name doit valoir true ou false"),
    };
}

function app_config(): array
{
    load_environment(dirname(__DIR__) . '/.env');
    $file = getenv('PERMISSIONS_FILE');
    $policy = $file ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
    $config = [
        'secret' => getenv('SECRET_KEY') ?: '',
        'mysql_host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
        'mysql_port' => getenv('MYSQL_PORT') ?: '3306',
        'mysql_database' => getenv('MYSQL_DATABASE') ?: 'anrt_qualite',
        'mysql_user' => getenv('MYSQL_USER') ?: 'anrt_app',
        'mysql_password' => getenv('MYSQL_PASSWORD') ?: '',
        'mysql_ssl_ca' => getenv('MYSQL_SSL_CA') ?: null,
        'timezone' => getenv('APP_TIMEZONE') ?: 'Africa/Casablanca',
        'cookie_secure' => env_bool('SESSION_COOKIE_SECURE', true),
        'session_path' => getenv('SESSION_PATH') ?: dirname(__DIR__) . '/instance/sessions',
        'session_seconds' => 1800,
        'demo' => env_bool('DEMO_MODE'),
        'login_identifier' => getenv('LOGIN_IDENTIFIER') ?: '',
        'initial_rules_confirmed' => env_bool('NC_INITIAL_RULES_CONFIRMED'),
        'deadline_required' => env_bool('NC_DEADLINE_REQUIRED'),
        'permissions' => $policy,
    ];
    if (strlen($config['secret']) < 32) {
        throw new RuntimeException('SECRET_KEY doit contenir au moins 32 caractères aléatoires.');
    }
    new DateTimeZone($config['timezone']);
    foreach (['mysql_host', 'mysql_database'] as $name) {
        if (strpbrk($config[$name], ";\r\n\0") !== false) {
            throw new RuntimeException('Paramètre MySQL invalide.');
        }
    }
    if (!ctype_digit($config['mysql_port']) || (int) $config['mysql_port'] < 1 || (int) $config['mysql_port'] > 65535) {
        throw new RuntimeException('Port MySQL invalide.');
    }
    if ($config['demo']) {
        $config['permissions'] = demo_policy();
        $config['login_identifier'] = 'matricule';
    }
    if (!in_array($config['login_identifier'], ['', 'matricule'], true)) {
        throw new RuntimeException('Seule la connexion par matricule est implémentée.');
    }
    validate_policy($config['permissions']);
    return $config;
}
