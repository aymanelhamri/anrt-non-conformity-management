# ANRT — gestion des non-conformités

Application web de stage en **PHP 8.3+ et PDO MySQL**, pour enregistrer et
consulter les non-conformités avec contrôle des accès et audit de création.

Le parcours livré est : connexion → liste → nouvelle NC → contrôles serveur
→ enregistrement → confirmation et fiche → déconnexion. Les décisions métier
restent à confirmer ; les droits non configurés sont refusés par défaut.

## Base de données et conception

Les [scripts SQL](database/README.md) restent la référence et sont inchangés.
Le [modèle expliqué](docs/modele-donnees.md) réutilise les tables RH existantes,
sans créer de table générique d'utilisateurs.

Le [dossier de conception](docs/conception/README.md) décrit le processus, les
profils, les écrans, les critères de recette et les décisions D01 à D14.
Il distingue les contraintes SQL des propositions à valider avec l'encadrante.

## Démarrer

PHP 64 bits, extensions `pdo_mysql` et `mbstring`, Composer et MySQL 8.0.16+
sont nécessaires. Aucun framework ou paquet PHP tiers n'est utilisé.

```powershell
composer install
Copy-Item .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Configurer `.env` avec la clé générée et les paramètres MySQL. Installer les
scripts SQL dans une base de recette, puis préparer ses comptes et leurs hashes.
Voir le [guide d'installation](docs/implementation.md) pour les permissions et
le mode de démonstration explicite.

```powershell
php bin/console.php check-db
php -S 127.0.0.1:5000 -t public public/index.php
```

Ouvrir `http://127.0.0.1:5000`. Pour cette démonstration HTTP locale, fixer
`SESSION_COOKIE_SECURE=false`. En exploitation, utiliser HTTPS et le dossier
`public/` comme seule racine web.

## Comprendre le projet rapidement

| Étape | Où regarder |
| --- | --- |
| 1. Point d'entrée | `public/index.php` charge `app/bootstrap.php`, puis `app/web.php`. |
| 2. Base de données | `database/001_schema.sql` est la référence ; `app/database.php` ouvre PDO MySQL. |
| 3. Authentification | `app/auth.php` utilise `password_verify`, les dates de compte et les sessions PHP natives. |
| 4. Permissions | `app/permissions.php` contrôle opérations et périmètres ; aucun droit métier par défaut. |
| 5. Liste des NC | `list_nc()` dans `app/non_conformites.php`, affichée par `templates/list.php`. |
| 6. Création NC | `app/web.php` reçoit le formulaire ; `validate_nc()` contrôle les champs. |
| 7. Transaction | `create_nc()` utilise une seule connexion PDO pour la référence, la NC et l'audit. |
| 8. Audit | Le même `create_nc()` insère dans `journal_audit` avant le commit. |
| 9. Fiche détaillée | `detail_nc()` applique le périmètre ; `templates/detail.php` affiche la fiche. |

Le [guide stagiaire](docs/guide-stagiaire.md) suit ce parcours en cinq minutes.
La [revue](docs/revue.md) explique le portage et ses limites.

## Vérifier

```powershell
composer validate --strict
composer test
composer test:mysql
```

Les tests MySQL nécessitent une activation explicite ; ils créent des bases de
recette neuves. Les [résultats réellement exécutés](docs/verification.md)
distinguent les tests PHP/HTTP locaux de la recette MySQL encore à faire.
