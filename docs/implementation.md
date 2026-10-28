# Installer et essayer le formulaire PHP

Le parcours comprend la connexion, le formulaire NC et la confirmation de la
référence enregistrée. La confirmation est liée à la déclaration tout juste
créée dans la session ; elle n'ouvre pas d'autres dossiers.

## Préparer PHP et la configuration

Utiliser PHP 8.3+ **64 bits**, avec `pdo_mysql` et `mbstring`, Composer et
MySQL 8.0.16+. Argon2id est nécessaire pour produire les nouveaux hashes.

```powershell
php -v
php -m
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Reporter la clé dans `SECRET_KEY` et renseigner les paramètres MySQL dans `.env`.
Les variables du processus priment sur ce fichier. Ne pas committer `.env`.
Si les outils locaux sont présents dans `.tools/`, utiliser
`.\.tools\php\php.exe` et `.\.tools\php\php.exe .tools\composer.phar`
à la place de `php` et `composer` lorsqu'ils ne sont pas dans le PATH.

## Préparer la base et les comptes

Dans une base de recette, appliquer les quatre scripts dans l'ordre du
[guide SQL](../database/README.md). L'application ne crée ni ne migre la base.
Réutiliser `pers`, `acces`, `acces_profil` et les affectations existantes.
Aucun compte n'est créé au démarrage.

Le compte applicatif a besoin de `SELECT` sur les tables consultées,
`INSERT` sur `non_conformites`, `journal_audit` et `notifications`, `UPDATE` sur
les six champs modifiables de `non_conformites` et sur `notifications.lue_le`,
et `EXECUTE` sur `generer_reference`. Les droits détaillés sont décrits dans le
[guide de suivi](suivi-non-conformites.md). Le définisseur de la procédure doit
pouvoir modifier les compteurs.

Générer le hash d'un mot de passe de recette sans le passer en argument :

```powershell
$passwordCredential = Get-Credential -UserName 'compte_recette' -Message 'Mot de passe du compte de recette'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$passwordCredential.GetNetworkCredential().Password | php bin/console.php hash-password
Remove-Variable passwordCredential
```

Insérer le hash obtenu dans `acces.passe` par le canal d'administration autorisé,
puis préparer les dates de validité et le profil du compte.

```powershell
php bin/console.php check-db
```

Cette commande vérifie la connexion, la version MySQL, le fuseau SQL,
la procédure et le statut `OUVERTE`, sans écrire de donnée métier.

## Lancer la démonstration

Dans `.env`, pour un essai HTTP local :

```dotenv
DEMO_MODE=true
SESSION_COOKIE_SECURE=false
```

```powershell
php -S 127.0.0.1:5000 -t public public/index.php
```

Ouvrir `http://127.0.0.1:5000`. Le mode démo utilise le matricule comme
identifiant et des permissions techniques d'essai ; il ne confirme aucune
règle métier. MySQL et les comptes de recette restent nécessaires.

L'aperçu local sans MySQL, lancé avec `tests/http_router.php`, reprend les
12 processus, 8 types de NC et 16 natures de service du script
`database/002_seed_referentiels.sql`, extrait du classeur fourni. Ces listes
ne sont pas dupliquées dans le code de démonstration. Les identifiants de
l'aperçu suivent l'ordre du script ; les identifiants de la base réelle
peuvent être différents. Le responsable reste le compte de test `00017` :
les noms et matricules réels doivent provenir des données RH autorisées.
Les enregistrements de cet aperçu restent simulés.

Hors démonstration, configurer `LOGIN_IDENTIFIER=matricule`, un
`PERMISSIONS_FILE` contenant les droits de création et d'affectation approuvés,
puis `NC_INITIAL_RULES_CONFIRMED=true` après confirmation des règles de saisie.
`NC_DEADLINE_REQUIRED` règle l'obligation d'échéance. Sans droits configurés,
la création est refusée. Servir uniquement `public/` et utiliser les cookies
sécurisés avec HTTPS en dehors de l'essai HTTP local.

## Enregistrement

Le serveur contrôle les champs, les référentiels et le responsable. Une même
transaction génère la référence et insère la NC et l'audit technique. Après
succès, une redirection mène à `/non-conformites/confirmation`, qui affiche
uniquement la référence de la déclaration créée dans la session.

Un réessai avec la même clé de formulaire et le même contenu retrouve le
résultat ; un contenu différent après succès est refusé. Cette protection
suppose un serveur MySQL d'écriture unique et la conservation de l'audit.
Deux formulaires distincts peuvent déclarer un même problème : aucune règle
métier de dédoublonnage n'est définie. Le compteur est limité à 999 tant que
son format n'a pas été revu.

## Tests

```powershell
composer validate --strict
composer test
composer test:mysql
```

Les tests PHP/HTTP utilisent un PDO instrumenté pour les opérations métier.
Ils ne prouvent pas le stockage ni l'atomicité MySQL. Pour la recette réelle,
fournir un serveur et un compte autorisé à créer des bases neuves :

```powershell
$env:TEST_MYSQL = '1'
$env:TEST_MYSQL_HOST = '127.0.0.1'
$env:TEST_MYSQL_PORT = '3306'
$env:TEST_MYSQL_USER = 'compte_recette'
$testCredential = Get-Credential -UserName 'compte_recette' -Message 'Serveur MySQL de recette'
$env:TEST_MYSQL_PASSWORD = $testCredential.GetNetworkCredential().Password
composer test:mysql
Remove-Item Env:TEST_MYSQL_PASSWORD
Remove-Item Env:TEST_MYSQL
```

Les bases de recette sont conservées pour inspection ; aucune base existante
n'est vidée ou supprimée. Les [résultats](verification.md) précisent ce qui a
réellement été exécuté. Les [questions du formulaire](conception/06-decisions.md)
restent à confirmer avec la superviseure.
