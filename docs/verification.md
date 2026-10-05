# Vérifications PHP du 5 octobre 2026

Environnement de vérification : Windows, **PHP 8.5.11 64 bits**, Composer 2.10.3.
Les exécutables officiels ont été téléchargés dans `.tools/`, puis leurs
empreintes SHA-256 ont été vérifiées. Ce dossier est ignoré par Git.

Les anciens résultats Flask/Python sont dans l'historique Git ; ils ne constituent
pas des résultats de tests PHP. Les contrôles ci-dessous portent sur le portage.

| Commande / scénario | Résultat réellement obtenu |
| --- | --- |
| `php -l` sur les 22 fichiers PHP | Aucune erreur de syntaxe. |
| `composer validate --strict` | Manifest et verrou valides. |
| `composer test` | **63 tests PHP locaux réussis**, puis **36 contrôles HTTP avec PDO instrumenté** et **13 contrôles HTTP via le point d'entrée public**, sans échec. |
| `composer test:mysql` | Exécuté sans activation : message explicite indiquant que les 13 cas MySQL n'ont pas été exécutés. |
| `php bin/console.php check-db` | Tenté avec clé temporaire : échec de connexion MySQL, code 2002, sortie 1. |
| `composer install --no-plugins --no-scripts` | Prérequis compatibles, aucune dépendance tierce à installer. Les filtres réseau Composer sont inaccessibles dans le bac à sable ; aucune analyse de sécurité réseau n'est déclarée. |
| `git diff --check` et espaces finaux des nouveaux fichiers | Vérifiés, sans erreur. |
| Comparaison des ressources CSS et JS | Copies identiques aux ressources précédentes. |
| Comparaison des scripts SQL | Aucun changement. |

Les tests PHP vérifient IDs unsigned, zéros des matricules, longueurs Unicode et
octets UTF-8, textes requis, dates invalides/antérieures, obligation d'échéance,
`password_verify` Argon2id/bcrypt y compris les préfixes existants `$2a$`/`$2b$`,
politiques fermées, périmètres, CSRF, signature de soumission, champs répétés,
limites persistantes de connexion, orchestration de transaction, audit et réessais.

Les tests HTTP démarrent puis arrêtent de vrais serveurs PHP locaux. Ils exercent
connexion → liste → nouvelle NC → validation invalide → resoumission → création
→ confirmation/fiche/audit → réessai → déconnexion, ainsi que rotation et
expiration effectives des sessions et refus de réutilisation d'un cookie révoqué.
Ils vérifient aussi les refus de permissions, les POST protégés, les fichiers
privés inaccessibles, les ressources et les pages sans authentification.

**Les opérations métier du parcours authentifié utilisent un PDO instrumenté.**
Cela vérifie l'exécution PHP et les appels SQL, pas leur stockage réel. La
validation des comptes futurs/expirés n'a pas été exécutée sur MySQL.

## Recette restant à exécuter

Les 13 cas de `tests/mysql.php` sont portés depuis la suite précédente et restent
opt-in. Ils créent des bases neuves au nom aléatoire, y appliquent les scripts,
préparent leurs comptes, puis vérifient validité, création, audit, rollback,
concurrence, perte de réponse après commit, plafond 999, références inexistantes,
référentiel inactif, affectation interdite, périmètres, filtres et pagination.
Aucune base existante n'est vidée ou supprimée ; les bases de recette sont conservées.

Aucun serveur MySQL disponible n'a été trouvé dans cet environnement. Les tests
réels de procédure, contraintes, atomicité et concurrence restent donc non exécutés.
Le rendu interactif, le comportement JavaScript et la présentation responsive
restent également à vérifier dans un navigateur.

Voir [l'installation](implementation.md), le [guide stagiaire](guide-stagiaire.md)
et le [rapport de correction](revue.md).
