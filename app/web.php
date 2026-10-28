<?php
declare(strict_types=1);

/*
 * Ce fichier reçoit les requêtes du navigateur et choisit la page à afficher.
 * Pour la connexion : lire le formulaire -> vérifier le compte avec app/auth.php
 * -> ouvrir la session -> rediriger vers le formulaire de non-conformité.
 * La connexion MySQL elle-même est ouverte par database() dans app/database.php.
 */

// Transporter ensemble un message et un statut HTTP, par exemple 404 « introuvable ».
class HttpError extends RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}

// Échapper une valeur avant de l'insérer dans le HTML : un texte saisi ne doit
// pas être interprété comme une balise ou un script par le navigateur.
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Présenter une date au format français ; prévoir aussi le cas d'une date absente.
function display_date(?string $value): string
{
    return $value ? (new DateTimeImmutable($value))->format('d/m/Y') : 'Non renseignée';
}

// Envoyer une redirection puis arrêter le traitement de la page courante.
// Après un POST, le statut 303 fait ouvrir la page suivante en GET : actualiser
// cette page ne renvoie donc pas automatiquement le formulaire précédent.
function redirect(string $url, int $status = 303): never
{
    header('Location: ' . $url, true, $status);
    exit;
}

// Ajouter les consignes de sécurité de la réponse : encodage, absence de mise
// en cache et limitation des scripts, styles et formulaires à l'origine du site.
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

// Afficher un template avec ses données, puis l'insérer dans templates/base.php.
// Par exemple render('login', ...) utilise templates/login.php.
function render(string $view, array $data, array $config, ?array $user = null): void
{
    // Rendre les données accessibles au template sous leurs noms de variables,
    // sans remplacer les variables qui existent déjà dans cette fonction.
    extract($data, EXTR_SKIP);
    // Capturer le HTML du template pour le placer ensuite dans la mise en page commune.
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

// Transformer le corps du formulaire, du type matricule=00020&password=...,
// en tableau PHP, en contrôlant les noms, doublons et encodages des champs.
function decode_form(string $body): array
{
    // PHP écrase normalement les champs répétés : les refuser avant ce décodage.
    $values = [];
    if ($body === '') {
        return $values;
    }
    foreach (explode('&', $body) as $pair) {
        // Séparer le nom de sa valeur, puis décoder les caractères transmis dans l'URL.
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

// Lire directement le corps du POST afin de détecter les champs répétés avant
// que PHP les regroupe. Seul le format des formulaires HTML classiques est accepté.
function read_post(): array
{
    // Vérifier la taille annoncée, puis la taille réellement lue : 600 000 octets maximum.
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

// Fonction principale appelée par public/index.php pour chaque requête.
// La connexion PDO sera ouverte au besoin si elle n'a pas été fournie à l'appel.
function handle_request(array $config, ?PDO $pdo = null): void
{
    $user = null;
    security_headers($config);
    try {
        // Retrouver la session et identifier l'action demandée : GET affiche
        // généralement une page ; POST transmet les données d'un formulaire.
        start_session($config);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // Garder uniquement le chemin : les paramètres après ? ne choisissent pas la route.
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $ncMatch = $notificationMatch = [];
        $ncRoute = preg_match('#^/non-conformites/([0-9]+)(?:/(modifier|valider))?$#D', (string) $path, $ncMatch) === 1;
        $notificationRead = preg_match('#^/notifications/([0-9]+)/lire$#D', (string) $path, $notificationMatch) === 1;
        $post = $method === 'POST' ? read_post() : [];
        // Avant tout traitement d'un formulaire, vérifier son lien avec la session.
        if ($method === 'POST') {
            check_csrf($post);
        }
        // Définir les méthodes permises pour chaque route. La déconnexion doit
        // être demandée par un formulaire POST protégé, pas par un simple lien GET.
        $allowed = $notificationRead || ($ncRoute && ($ncMatch[2] ?? '') === 'valider') ? ['POST'] : match ($path) {
            '/connexion', '/non-conformites/nouvelle' => ['GET', 'HEAD', 'POST'],
            '/deconnexion' => ['POST'],
            default => $ncRoute && ($ncMatch[2] ?? '') === 'modifier' ? ['GET', 'HEAD', 'POST'] : ['GET', 'HEAD'],
        };
        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            throw new HttpError(405, 'Méthode non autorisée.');
        }
        // L'accueil mène au suivi ; la session sera contrôlée à la requête suivante.
        if ($path === '/') {
            redirect('/non-conformites', 302);
        }
        // Route de connexion : GET affiche le formulaire, POST vérifie les identifiants.
        if ($path === '/connexion') {
            $error = null;
            // Garder le matricule saisi pour le réafficher en cas d'erreur.
            // Le mot de passe ne sera jamais transmis au template de connexion.
            $identifier = $post['matricule'] ?? '';
            if ($method === 'POST') {
                // Le mode de connexion doit être configuré, puis la limitation
                // des tentatives contrôle le couple adresse IP / matricule.
                if ($config['login_identifier'] !== 'matricule') {
                    http_response_code(503);
                    $error = "La convention de connexion doit être configurée par l'administrateur.";
                } elseif (!allow_login($config, $identifier, $_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
                    $pdo ??= database($config);
                    record_auth_event($pdo, $identifier, 'CONNEXION_REFUSEE', $_SERVER['REMOTE_ADDR'] ?? null, 'Limite de tentatives atteinte.');
                    http_response_code(429);
                    $error = 'Trop de tentatives. Réessayez dans dix minutes.';
                } else {
                    // Lire les champs envoyés et refuser les tailles hors limites
                    // avant de solliciter MySQL et la vérification du mot de passe.
                    $password = $post['password'] ?? '';
                    $authenticated = null;
                    if ($identifier !== '' && mb_strlen($identifier, 'UTF-8') <= 60 && strlen($password) <= 1024) {
                        // ??= ouvre la connexion uniquement si $pdo est encore null.
                        $pdo ??= database($config);
                        // authenticate() lit acces.passe, vérifie le hash et la
                        // validité du compte, puis renvoie son identité et ses profils.
                        $authenticated = authenticate($pdo, $identifier, $password);
                    }
                    if ($authenticated) {
                        // L'audit doit être enregistré avant d'ouvrir la session.
                        record_auth_event($pdo, $identifier, 'CONNEXION', $_SERVER['REMOTE_ADDR'] ?? null);
                        // Mémoriser le matricule dans une nouvelle session, puis
                        // ouvrir la déclaration ou l'espace administrateur selon ses droits.
                        sign_in($authenticated);
                        redirect(can_admin($authenticated, $config) ? '/administration'
                            : (can_create($authenticated, $config) ? '/non-conformites/nouvelle' : '/non-conformites'));
                    }
                    $pdo ??= database($config);
                    record_auth_event($pdo, $identifier, 'CONNEXION_REFUSEE', $_SERVER['REMOTE_ADDR'] ?? null,
                        'Identifiants incorrects ou compte hors période de validité.');
                    // Le même message couvre un mauvais mot de passe et un compte
                    // hors validité. HTTP 401 indique que la connexion est refusée.
                    http_response_code(401);
                    $error = 'Identifiants incorrects ou compte hors période de validité.';
                }
            }
            // Premier affichage ou erreur : envoyer le formulaire de connexion,
            // avec le matricule déjà saisi et l'éventuel message d'erreur.
            render('login', compact('error', 'identifier'), $config);
            return;
        }
        // Détruire la session et son cookie, puis revenir à la page de connexion.
        if ($path === '/deconnexion') {
            $signedOut = $_SESSION['matricule'] ?? null;
            // Fermer la session même si MySQL est momentanément indisponible.
            sign_out($config);
            if (is_string($signedOut)) {
                $pdo ??= database($config);
                record_auth_event($pdo, $signedOut, 'DECONNEXION', $_SERVER['REMOTE_ADDR'] ?? null);
            }
            redirect('/connexion');
        }
        $adminDetail = [];
        $adminRoute = in_array($path, ['/administration', '/administration/comptes', '/administration/non-conformites', '/administration/journal'], true)
            || preg_match('#^/administration/non-conformites/([0-9]+)$#D', (string) $path, $adminDetail) === 1;
        // Refuser les adresses qui ne correspondent ni au parcours NC ni à l'administration.
        if (!$adminRoute && !$ncRoute && !$notificationRead && !in_array($path, ['/non-conformites', '/notifications', '/non-conformites/nouvelle', '/non-conformites/confirmation'], true)) {
            throw new HttpError(404, 'Page introuvable.');
        }
        // Pour une page protégée, relire le compte et ses profils dans MySQL à
        // partir du matricule de session : un compte expiré ne doit plus passer.
        $matricule = $_SESSION['matricule'] ?? null;
        if ($matricule) {
            $pdo ??= database($config);
            $user = load_user($pdo, $matricule);
        }
        // Sans compte valide, vider la session et demander une nouvelle connexion.
        if (!$user) {
            sign_out($config);
            redirect('/connexion', 302);
        }
        // Recalculer le compteur pour ce compte à chaque page. Après une lecture
        // ou un nouvel événement, la redirection affichera le nombre actualisé.
        $user['unread_notifications'] = notification_unread_count($pdo, $user);
        // Ces consultations sont ouvertes aux comptes valides dans leur propre
        // périmètre, même sans droit de création (exemple : un validateur RH).
        if ($path === '/non-conformites') {
            $filters = nc_filters($_GET);
            $statuses = query($pdo, 'SELECT code, libelle FROM statuts ORDER BY libelle')->fetchAll();
            if ($filters['statut'] !== '' && !in_array($filters['statut'], array_column($statuses, 'code'), true)) { throw new HttpError(400, 'Statut inconnu.'); }
            render('nc_list', ['filters' => $filters, 'statuses' => $statuses, 'result' => nc_list($pdo, $user, $config, $filters)], $config, $user);
            return;
        }
        if ($path === '/notifications') {
            if (array_diff(array_keys($_GET), ['page'])) { throw new HttpError(400, 'Filtre inconnu.'); }
            $filters = nc_filters($_GET);
            render('notifications', ['filters' => ['page' => $filters['page']], 'result' => notification_list($pdo, $user, $config, $filters['page'])], $config, $user);
            return;
        }
        if ($notificationRead) {
            if (array_diff(array_keys($post), ['csrf_token'])) { throw new HttpError(400, 'Champ inconnu.'); }
            read_notification($pdo, $user, $notificationMatch[1]);
            redirect('/notifications');
        }
        if ($ncRoute) {
            $id = uint($ncMatch[1]);
            if ($id === null) { throw new HttpError(404, 'Non-conformité introuvable.'); }
            $nc = nc_record($pdo, $id, $user, $config);
            $action = $ncMatch[2] ?? '';
            $errors = [];
            if ($action === 'modifier') {
                if (!nc_can_edit($nc, $user, $config)) {
                    throw new HttpError(403, nc_edit_block_reason($nc) ?? 'Modification non autorisée.');
                }
                $values = [];
                foreach (NC_FIELDS as $field) { $values[$field] = $method === 'POST' && in_array($field, NC_EDIT_FIELDS, true) ? ($post[$field] ?? '') : (string) ($nc[$field] ?? ''); }
                // Garder le jeton initial après une erreur : un conflit ne doit
                // jamais permettre d'écraser silencieusement une version plus récente.
                $version = $method === 'POST' ? ($post['version_token'] ?? '') : nc_version($nc, $user, $config);
                if ($method === 'POST') {
                    try {
                        if (array_diff(array_keys($post), [...NC_EDIT_FIELDS, 'csrf_token', 'version_token'])) { throw new FormError(['form' => 'Champ protégé ou inconnu.'], 400); }
                        $editValues = array_intersect_key($values, array_flip(NC_EDIT_FIELDS));
                        $changed = update_nc($pdo, $config, $user, $id, $editValues, $version, $_SERVER['REMOTE_ADDR'] ?? null);
                        $_SESSION['nc_notice'] = ['id' => $id, 'message' => $changed ? 'Modifications enregistrées. Les personnes concernées ont été notifiées.' : 'Aucune modification à enregistrer.'];
                        redirect('/non-conformites/' . $id);
                    } catch (FormError $error) { $errors = $error->errors; http_response_code($error->status); }
                    catch (PDOException $error) {
                        error_log('Modification MySQL non confirmée (' . get_class($error) . ')');
                        $errors['form'] = 'Enregistrement non confirmé. Consultez le dossier avant de réessayer ; votre saisie est conservée ici.';
                        http_response_code(503);
                    }
                }
                $options = nc_choices($pdo, $config, $user);
                // Afficher aussi les valeurs d'origine devenues inactives ; les
                // conserver est permis, les choisir dans un autre dossier ne l'est pas.
                foreach (['types_nc' => ['type_nc_id', 'type_libelle'], 'natures_service' => ['nature_service_id', 'nature_libelle']] as $table => [$field, $label]) {
                    if (!in_array((string) $nc[$field], array_map('strval', array_column($options[$table], 'id')), true)) { $options[$table][] = ['id' => $nc[$field], 'libelle' => $nc[$label] . ' (inactive)']; }
                }
                if (!in_array($nc['responsable_matricule'], array_column($options['responsables'], 'matricule'), true)) { $options['responsables'][] = ['matricule' => $nc['responsable_matricule'], 'nom_prenom' => $nc['responsable_nom']]; }
                render('new', ['editing' => true, 'today' => $nc['date_creation'], ...compact('nc', 'values', 'version', 'errors', 'options')], $config, $user);
                return;
            }
            if ($action === 'valider') {
                try {
                    $assignment = uint($post['assignment_id'] ?? '');
                    if ($assignment === null || array_diff(array_keys($post), ['csrf_token', 'version_token', 'assignment_id'])) { throw new FormError(['form' => 'Formulaire de validation invalide.'], 400); }
                    $changed = validate_nc_record($pdo, $config, $user, $id, $assignment, $post['version_token'] ?? '', $_SERVER['REMOTE_ADDR'] ?? null);
                    $_SESSION['nc_notice'] = ['id' => $id, 'message' => $changed ? 'Validation enregistrée. Les personnes concernées ont été notifiées.' : 'Vous avez déjà validé cette version du dossier.'];
                    redirect('/non-conformites/' . $id);
                } catch (FormError $error) { $errors = $error->errors; http_response_code($error->status); }
                catch (PDOException $error) {
                    error_log('Validation MySQL non confirmée (' . get_class($error) . ')');
                    $errors['form'] = 'Validation non confirmée. Consultez le dossier puis réessayez si nécessaire.';
                    http_response_code(503);
                }
            }
            $notice = ($_SESSION['nc_notice']['id'] ?? null) === $id ? $_SESSION['nc_notice']['message'] : null;
            unset($_SESSION['nc_notice']);
            render('nc_detail', ['validators' => nc_validators($pdo, $nc), 'history' => nc_history($pdo, $id), ...compact('nc', 'errors', 'notice')], $config, $user);
            return;
        }
        // Vérifier les droits avant toute requête de consultation globale.
        // Un matricule fourni dans l'URL ne remplace jamais le compte en session.
        if ($adminRoute) {
            if (!can_admin($user, $config)) {
                throw new HttpError(403, "L'espace d'administration est réservé aux comptes autorisés.");
            }
            $filters = admin_filters($_GET);
            if ($path === '/administration') {
                render('admin_dashboard', ['section' => 'overview', 'summary' => admin_summary($pdo)], $config, $user);
            } elseif ($path === '/administration/comptes') {
                render('admin_accounts', ['section' => 'accounts', 'accounts' => admin_accounts($pdo)], $config, $user);
            } elseif ($path === '/administration/journal') {
                render('admin_logs', ['section' => 'logs', 'filters' => $filters,
                    'accounts' => admin_accounts($pdo), 'result' => admin_logs($pdo, $filters)], $config, $user);
            } elseif ($path === '/administration/non-conformites') {
                render('admin_nc', ['section' => 'nc', 'filters' => $filters,
                    'accounts' => admin_accounts($pdo), 'result' => admin_ncs($pdo, $filters)], $config, $user);
            } else {
                $id = uint($adminDetail[1]);
                $nc = $id !== null ? admin_nc($pdo, $id) : null;
                if (!$nc) { throw new HttpError(404, 'Non-conformité introuvable.'); }
                render('admin_nc_detail', ['section' => 'nc', 'nc' => $nc], $config, $user);
            }
            return;
        }
        // Être connecté ne suffit pas : vérifier aussi les droits de création.
        // HTTP 403 signifie que le compte est identifié mais que l'accès est refusé.
        if (!can_create($user, $config)) {
            throw new HttpError(403, 'Votre compte ne peut pas créer de non-conformité.');
        }
        // La confirmation affiche uniquement la référence créée dans cette session,
        // sans accepter un identifiant de dossier fourni dans l'adresse de la page.
        if ($path === '/non-conformites/confirmation') {
            $confirmation = $_SESSION['nc_confirmation'] ?? null;
            if (!is_array($confirmation) || !is_string($confirmation['reference'] ?? null)) {
                redirect('/non-conformites/nouvelle');
            }
            render('confirmation', compact('confirmation'), $config, $user);
            return;
        }
        if ($path === '/non-conformites/nouvelle') {
            // Hors démonstration, les règles métier doivent avoir été confirmées
            // dans la configuration avant d'autoriser la création d'une NC.
            if (!$config['demo'] && !$config['initial_rules_confirmed']) {
                throw new HttpError(403, 'La création attend la confirmation des règles de saisie initiale.');
            }
            // Ouvrir un nouveau formulaire met fin à la confirmation précédente.
            if ($method !== 'POST') {
                unset($_SESSION['nc_confirmation']);
            }
            // Ne reprendre que les champs métier attendus. Ces valeurs seront
            // réaffichées si le serveur refuse la saisie ou ne confirme pas l'enregistrement.
            $values = [];
            foreach (NC_FIELDS as $field) {
                $values[$field] = $post[$field] ?? '';
            }
            $errors = [];
            // Un formulaire possède sa propre clé d'enregistrement. Garder la même
            // clé au réessai permet de retrouver une création déjà réussie.
            $submission = $method === 'POST' ? ($post['submission_token'] ?? '') : new_submission($user, $config);
            if ($method === 'POST') {
                try {
                    // Refuser les champs ajoutés par le client, par exemple un
                    // déclarant ou un statut que le serveur doit fixer lui-même.
                    if (array_diff(array_keys($post), [...NC_FIELDS, 'csrf_token', 'submission_token'])) {
                        throw new FormError(['form' => 'Le formulaire contient des champs non autorisés.'], 400);
                    }
                    // create_nc() contrôle les données et enregistre référence,
                    // NC et audit dans une transaction. $replayed indique un réessai retrouvé.
                    [, $replayed, $reference] = create_nc($pdo, $config, $user, $values, $submission, $_SERVER['REMOTE_ADDR'] ?? null);
                    $_SESSION['nc_confirmation'] = compact('reference', 'replayed');
                    redirect('/non-conformites/confirmation');
                } catch (FormError $error) {
                    // Conserver les erreurs par champ pour expliquer quoi corriger.
                    $errors = $error->errors;
                    http_response_code($error->status);
                } catch (PDOException $error) {
                    // Une perte de réponse peut survenir après un enregistrement
                    // réussi : conserver la saisie et la clé pour permettre un réessai.
                    error_log('Création MySQL non confirmée (' . get_class($error) . ')');
                    $errors['form'] = "L'enregistrement n'a pas pu être confirmé. Conservez ce formulaire et réessayez avec les mêmes données pour retrouver le résultat sans doublon.";
                    http_response_code(503);
                }
            }
            try {
                // Charger les choix des listes et les responsables autorisés depuis MySQL.
                $options = nc_choices($pdo, $config, $user);
            } catch (PDOException) {
                // Afficher tout de même la saisie si les listes sont indisponibles.
                $options = ['processus' => [], 'types_nc' => [], 'natures_service' => [], 'responsables' => []];
                $errors['form'] ??= 'Les listes de choix sont indisponibles. Votre saisie est conservée ; réessayez avec ce formulaire.';
                http_response_code(503);
            }
            // La date affichée suit le fuseau métier configuré, puis le template
            // reçoit les valeurs, erreurs et listes nécessaires au formulaire.
            $today = business_today($config);
            render('new', compact('values', 'errors', 'submission', 'options', 'today'), $config, $user);
            return;
        }
    } catch (FormError $error) {
        // Une erreur métier remontée hors du formulaire produit une page d'erreur.
        http_response_code($error->status);
        render('error', ['message' => implode(' ', $error->errors)], $config, $user);
    } catch (HttpError $error) {
        // Afficher les erreurs HTTP prévues, telles que 400, 403, 404 ou 405.
        http_response_code($error->status);
        render('error', ['message' => $error->getMessage()], $config, $user);
    } catch (Throwable $error) {
        // Pour une panne inattendue, retourner une page 503 sans révéler de
        // requête SQL, de mot de passe ni de détail interne au navigateur.
        // Ne jamais journaliser la requête SQL, ses valeurs ou les identifiants.
        error_log('Service indisponible (' . get_class($error) . ')');
        http_response_code(503);
        render('error', ['message' => 'Le service est indisponible. Réessayez dans quelques instants.'], $config, $user);
    }
}
