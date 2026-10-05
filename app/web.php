<?php
declare(strict_types=1);

class HttpError extends RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function display_date(?string $value): string
{
    return $value ? (new DateTimeImmutable($value))->format('d/m/Y') : 'Non renseignée';
}

function display_time(string $value, array $config): string
{
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($config['timezone']))->format('d/m/Y H:i');
}

function redirect(string $url, int $status = 303): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

function security_headers(array $config): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    if ($config['cookie_secure']) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function render(string $view, array $data, array $config, ?array $user = null): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    try {
        require dirname(__DIR__) . '/templates/' . $view . '.php';
        $content = ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        throw $error;
    }
    require dirname(__DIR__) . '/templates/base.php';
}

function form_string(array $values, string $key, string $default = ''): string
{
    $value = $values[$key] ?? $default;
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new HttpError(400, 'Le formulaire ou les filtres contiennent une valeur invalide.');
    }
    return $value;
}

function decode_form(string $body): array
{
    // PHP écrase normalement les champs répétés : les refuser avant ce décodage.
    $values = [];
    if ($body === '') {
        return $values;
    }
    foreach (explode('&', $body) as $pair) {
        [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
        $key = urldecode($key);
        $value = urldecode($value);
        if (!preg_match('/^[a-z_]+$/D', $key) || array_key_exists($key, $values)
            || !mb_check_encoding($value, 'UTF-8')) {
            throw new HttpError(400, 'Le formulaire contient des champs invalides ou répétés.');
        }
        $values[$key] = $value;
    }
    return $values;
}

function read_post(): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 600000) {
        throw new HttpError(413, 'Le formulaire est trop volumineux.');
    }
    $type = strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? 'application/x-www-form-urlencoded')[0]);
    if ($type !== 'application/x-www-form-urlencoded') {
        throw new HttpError(415, 'Ce format de formulaire est refusé.');
    }
    $body = file_get_contents('php://input', false, null, 0, 600001);
    if ($body === false || strlen($body) > 600000) {
        throw new HttpError(413, 'Le formulaire est trop volumineux.');
    }
    return decode_form($body);
}

function page_url(array $filters, int $page): string
{
    return '/non-conformites?' . http_build_query([...array_filter($filters, static fn(string $v): bool => $v !== ''), 'page' => $page]);
}

function handle_request(array $config, ?PDO $pdo = null): void
{
    $user = null;
    security_headers($config);
    try {
        start_session($config);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $post = $method === 'POST' ? read_post() : [];
        if ($method === 'POST') {
            check_csrf($post);
        }
        $allowed = match ($path) {
            '/connexion', '/non-conformites/nouvelle' => ['GET', 'HEAD', 'POST'],
            '/deconnexion' => ['POST'],
            default => ['GET', 'HEAD'],
        };
        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new HttpError(405, 'Méthode non autorisée.');
        }
        if ($path === '/') {
            redirect('/non-conformites', 302);
        }
        if ($path === '/connexion') {
            $error = null;
            $identifier = $post['matricule'] ?? '';
            if ($method === 'POST') {
                if ($config['login_identifier'] !== 'matricule') {
                    http_response_code(503);
                    $error = "La convention de connexion doit être configurée par l'administrateur.";
                } elseif (!allow_login($config, $identifier, $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
                    http_response_code(429);
                    $error = 'Trop de tentatives. Réessayez dans dix minutes.';
                } else {
                    $password = $post['password'] ?? '';
                    $authenticated = null;
                    if ($identifier !== '' && mb_strlen($identifier, 'UTF-8') <= 60 && strlen($password) <= 1024) {
                        $pdo ??= database($config);
                        $authenticated = authenticate($pdo, $identifier, $password);
                    }
                    if ($authenticated) {
                        sign_in($authenticated);
                        redirect('/non-conformites');
                    }
                    http_response_code(401);
                    $error = 'Identifiants incorrects ou compte hors période de validité.';
                }
            }
            render('login', compact('error', 'identifier'), $config);
            return;
        }
        if ($path === '/deconnexion') {
            sign_out($config);
            redirect('/connexion');
        }
        $detailId = preg_match('#^/non-conformites/([0-9]+)$#D', (string) $path, $match) ? uint($match[1]) : null;
        if (!in_array($path, ['/non-conformites', '/non-conformites/nouvelle'], true) && $detailId === null) {
            throw new HttpError(404, 'Dossier ou page introuvable.');
        }
        $matricule = $_SESSION['matricule'] ?? null;
        if ($matricule) {
            $pdo ??= database($config);
            $user = load_user($pdo, $matricule);
        }
        if (!$user) {
            sign_out($config);
            redirect('/connexion', 302);
        }
        if ($path === '/non-conformites') {
            [$scope] = scope_sql($user, $config);
            if ($scope === '0=1') {
                throw new HttpError(403, "Aucun droit de consultation n'est configuré pour votre compte.");
            }
            $page = uint(form_string($_GET, 'page', '1'));
            if ($page === null) {
                throw new HttpError(400, 'Numéro de page invalide.');
            }
            $filters = [];
            foreach (['reference', 'processus_id', 'statut_code', 'responsable_matricule'] as $field) {
                $filters[$field] = form_string($_GET, $field);
            }
            $result = list_nc($pdo, $config, $user, $filters, $page);
            $options = nc_choices($pdo, $config, $user, true);
            $mayCreate = can_create($user, $config);
            render('list', [...$result, ...compact('filters', 'options', 'mayCreate')], $config, $user);
            return;
        }
        if ($path === '/non-conformites/nouvelle') {
            if (!can_create($user, $config)) {
                throw new HttpError(403, 'Votre compte ne peut pas créer de non-conformité.');
            }
            if (!$config['demo'] && !$config['initial_rules_confirmed']) {
                throw new HttpError(403, 'La création attend la confirmation des règles de saisie initiale.');
            }
            $values = [];
            foreach (NC_FIELDS as $field) {
                $values[$field] = $post[$field] ?? '';
            }
            $errors = [];
            $submission = $method === 'POST' ? ($post['submission_token'] ?? '') : new_submission($user, $config);
            if ($method === 'POST') {
                try {
                    if (array_diff(array_keys($post), [...NC_FIELDS, 'csrf_token', 'submission_token'])) {
                        throw new FormError(['form' => 'Le formulaire contient des champs non autorisés.'], 400);
                    }
                    [$id, $replayed] = create_nc($pdo, $config, $user, $values, $submission, $_SERVER['REMOTE_ADDR'] ?? null);
                    $_SESSION['flash'] = $replayed ? 'Enregistrement retrouvé : votre non-conformité avait déjà été créée.' : 'Votre non-conformité a été enregistrée.';
                    redirect('/non-conformites/' . $id);
                } catch (FormError $error) {
                    $errors = $error->errors;
                    http_response_code($error->status);
                } catch (PDOException $error) {
                    error_log('Création MySQL non confirmée (' . get_class($error) . ')');
                    $errors['form'] = "L'enregistrement n'a pas pu être confirmé. Conservez ce formulaire et réessayez avec les mêmes données pour retrouver le résultat sans doublon.";
                    http_response_code(503);
                }
            }
            try {
                $options = nc_choices($pdo, $config, $user);
            } catch (PDOException) {
                $options = ['processus' => [], 'types_nc' => [], 'natures_service' => [], 'responsables' => []];
                $errors['form'] ??= 'Les listes de choix sont indisponibles. Votre saisie est conservée ; réessayez avec ce formulaire.';
                http_response_code(503);
            }
            $today = business_today($config);
            render('new', compact('values', 'errors', 'submission', 'options', 'today'), $config, $user);
            return;
        }
        [$nc, $audit] = detail_nc($pdo, $config, $user, $detailId);
        if ($nc === null) {
            throw new HttpError(404, 'Dossier introuvable ou non autorisé.');
        }
        render('detail', compact('nc', 'audit'), $config, $user);
    } catch (FormError $error) {
        http_response_code($error->status);
        render('error', ['message' => implode(' ', $error->errors)], $config, $user);
    } catch (HttpError $error) {
        http_response_code($error->status);
        render('error', ['message' => $error->getMessage()], $config, $user);
    } catch (Throwable $error) {
        // Ne jamais journaliser la requête SQL, ses valeurs ou les identifiants.
        error_log('Service indisponible (' . get_class($error) . ')');
        http_response_code(503);
        render('error', ['message' => 'Le service est indisponible. Réessayez dans quelques instants.'], $config, $user);
    }
}
