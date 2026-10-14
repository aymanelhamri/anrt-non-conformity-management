# ANRT — gestion des non-conformités

Application web de stage en **PHP 8.3+ et PDO MySQL**, pour digitaliser la
gestion des non-conformités à l'ANRT.

Le parcours actuel est limité à : **connexion → formulaire de non-conformité
→ contrôles serveur → enregistrement → confirmation de la référence**.
La confirmation concerne uniquement la déclaration qui vient d'être créée
dans la session. L'utilisateur peut ensuite effectuer une autre déclaration
ou se déconnecter.

La création conserve un audit technique dans la base et protège les réessais
du même formulaire contre les doubles enregistrements. Les droits non
configurés sont refusés par défaut.

Le projet global prévoit aussi les actions d'amélioration, les validations,
les notifications, les tableaux de bord et le suivi. Leur fonctionnement
et leur ordre de développement restent à définir avec la superviseure.

## Conception et base de données

Le [dossier de conception](docs/conception/README.md) décrit les besoins,
l'enchaînement, les champs et les écrans de ce premier parcours. Le choix
du formulaire NC est une proposition de travail ; il ne vaut pas approbation
du périmètre des jalons par la superviseure.

Les [scripts SQL](database/README.md) et le [modèle expliqué](docs/modele-donnees.md)
réutilisent les tables RH existantes, avec le matricule comme identifiant.
Les scripts SQL antérieurs sont conservés sans modification.

## Démarrer

PHP 64 bits, extensions `pdo_mysql` et `mbstring`, Composer et MySQL 8.0.16+
sont nécessaires. Aucun paquet PHP tiers n'est utilisé.

```powershell
composer install
Copy-Item .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Renseigner la clé générée et les paramètres MySQL dans `.env`, puis préparer
une base et des comptes de recette selon le [guide d'installation](docs/implementation.md).

```powershell
php bin/console.php check-db
php -S 127.0.0.1:5000 -t public public/index.php
```

Pour la démonstration HTTP locale, utiliser `DEMO_MODE=true` et
`SESSION_COOKIE_SECURE=false`. Ouvrir `http://127.0.0.1:5000`.

## Vérifier

```powershell
composer validate --strict
composer test
composer test:mysql
```

Les tests MySQL nécessitent une activation explicite et un serveur de recette.
Les [résultats de vérification](docs/verification.md) distinguent l'état actuel
des résultats historiques du 5 octobre 2026.
