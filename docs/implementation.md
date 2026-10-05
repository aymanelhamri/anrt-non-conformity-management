# Installation et fonctionnement PHP

Le parcours existant a été porté de Flask vers **PHP/PDO**, conformément à la
technologie demandée. Les URLs, le SQL, les écrans et les règles de sécurité
sont conservés. Le code Python remplacé a été retiré ; son historique reste
dans Git. Aucun script SQL n'a été modifié.

## Organisation

```text
public/index.php              point d'entrée et routeur local
public/assets/                CSS et JavaScript existants
app/bootstrap.php             chargement des fonctions et configuration
app/web.php                   parcours HTTP et rendu
app/database.php              PDO et requêtes paramétrées
app/auth.php                  password_verify, sessions, CSRF, limitation
app/permissions.php           opérations et périmètres
app/non_conformites.php        validation, lectures SQL, transaction et audit
config/app.php                configuration et lecture de .env
config/permissions.*.json     refus par défaut et exemple non approuvé
templates/                    connexion, liste, formulaire, fiche, erreurs
bin/console.php               check-db et hash-password
tests/                        tests PHP, HTTP et recette MySQL opt-in
database/                     scripts SQL inchangés
```

Les fonctions sont chargées directement ; aucun ORM, conteneur de services ou
framework. Composer vérifie les prérequis et lance les tests, sans paquet tiers.

## Préparer PHP et la configuration

Utiliser PHP 8.3+ **64 bits**, avec `pdo_mysql` et `mbstring` activés dans `php.ini`.
Argon2id doit être disponible pour produire les nouveaux hashes ; la lecture
des hashes bcrypt existants reste supportée, y compris ceux de la version Python.

```powershell
php -v
php -m
composer validate --strict
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Reporter la clé dans `SECRET_KEY`, puis renseigner MySQL. Le format de `.env`
est `NOM=valeur` : guillemets extérieurs facultatifs, commentaires sur leur propre
ligne, sans interpolation de variables. Les variables du processus priment.
Le fichier `.env`, les sessions et les outils locaux ne doivent pas être committés.

PHP et Composer ont été téléchargés pour la vérification dans `.tools/`, ignoré
par Git. Sur cette machine, utiliser `.\.tools\php\php.exe` et
`.\.tools\php\php.exe .tools\composer.phar` si PHP/Composer ne sont pas dans le PATH.
Ces exécutables ne font pas partie de l'application distribuée.

## Installer MySQL et préparer les comptes

Sur un serveur de recette dédié, appliquer les quatre scripts dans l'ordre du
[guide SQL](../database/README.md). L'application ne crée ni ne migre la base.
MySQL 8.0.16+ permet les contraintes CHECK et les verrous `FOR SHARE` utilisés.

Le compte applicatif reçoit `SELECT` sur les tables consultées, `INSERT` sur
`non_conformites` et `journal_audit`, et `EXECUTE` sur `generer_reference`.
Le définisseur de la procédure doit pouvoir lire/modifier les compteurs.
Aucun droit applicatif CREATE/DROP/ALTER n'est nécessaire.

Réutiliser `pers`, `acces`, `acces_profil` et les affectations approuvées.
`direction` et `personnel_validation` sont conservées, sans inventer un périmètre
de service ou une approbation. Aucun compte n'est créé au démarrage.

Générer un hash sans exposer le mot de passe en argument ni l'afficher :

```powershell
$passwordCredential = Get-Credential -UserName 'compte_recette' -Message 'Mot de passe du compte à préparer'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$passwordCredential.GetNetworkCredential().Password | php bin/console.php hash-password
Remove-Variable passwordCredential
```

La commande lit uniquement un mot de passe pipé, puis affiche son hash Argon2id.
Insérer le hash par le canal d'administration autorisé dans `acces.passe` ;
préparer aussi la période de validité et les profils. Ne jamais enregistrer un
mot de passe en clair. Les dates TIMESTAMP sont importées avec conversion UTC.

```powershell
php bin/console.php check-db
```

Ce contrôle lit la version, le fuseau SQL, la présence de la procédure et du
statut `OUVERTE`. Il n'écrit aucune donnée métier.

## Lancer et configurer les droits

Pour une démonstration locale seulement :

```dotenv
DEMO_MODE=true
SESSION_COOKIE_SECURE=false
```

```powershell
php -S 127.0.0.1:5000 -t public public/index.php
```

Le mode démo utilise le matricule et les règles initiales conformes au SQL ;
son bandeau rappelle qu'elles ne sont pas approuvées. MySQL et des comptes de
recette restent nécessaires.

| Profil SQL | Création | Lecture | Audit | Affectation |
| --- | --- | --- | --- | --- |
| ADMINISTRATEUR | Oui | Tous | Tous | Personnel existant |
| RESPONSABLE | Oui | Ses déclarations et affectations | Même périmètre | Lui-même |
| PILOTE_PROCESSUS | Non | Processus explicitement affectés | Même périmètre | Aucune |
| CONSULTATION | Non | Ses déclarations et affectations | Non | Aucune |

Hors démo : `DEMO_MODE=false`, `LOGIN_IDENTIFIER=matricule` après D14,
`PERMISSIONS_FILE` vers des droits approuvés, puis `NC_INITIAL_RULES_CONFIRMED=true`
après D03/D04/D07. Sans ces confirmations, la création reste refusée.
`NC_DEADLINE_REQUIRED` règle l'obligation d'échéance après D04.

Les permissions `read`/`audit` portent sur `own`, `assigned`, `process` ou `all`.
`assign` vaut `self`, `process` ou `all`. Aucun lien de personnel n'accorde un
droit implicitement. Le cumul réunit les droits explicitement configurés ; D05
doit confirmer ce choix. La liste et la fiche utilisent le même périmètre SQL.

En exploitation, servir **uniquement `public/`** derrière HTTPS, avec
`SESSION_COOKIE_SECURE=true`. Pour Apache, le fichier `.htaccess` fournit les
réécritures si `mod_rewrite` et AllowOverride sont activés. Le serveur PHP intégré
sert aux vérifications locales. Protéger les fichiers privés avec les droits du
compte de service et conserver les erreurs détaillées hors des réponses web.

## Transaction et réessais

`create_nc()` reçoit une connexion PDO non persistante. Avec cette même connexion :

```text
GET_LOCK sur la clé du formulaire
BEGIN
  revérifier le compte et ses profils
  retrouver un succès antérieur éventuel
  valider les champs et les référentiels
  CALL generer_reference, puis vider les jeux de résultats PDO
  vérifier le compteur <= 999
  INSERT non_conformites
  INSERT journal_audit
COMMIT
RELEASE_LOCK
```

Une exception annule la transaction encore active. La fiche de confirmation
est demandée par redirection 303 après succès. Les valeurs contrôlées par le
serveur sont le déclarant, la date, la référence, le statut `OUVERTE` et l'audit.
L'échéance facultative respecte la contrainte SQL ; les textes dépassant 65 535
octets UTF-8 et les matricules dépassant 60 caractères sont refusés sans troncature.

La clé signée est liée au matricule. Le JSON d'audit conserve son empreinte et
celle du contenu. Un réessai avec même clé/contenu retrouve le dossier ; un
contenu différent après succès reçoit 409. Deux formulaires distincts peuvent
créer deux déclarations identiques : aucune règle métier de dédoublonnage n'est inventée.

Après une perte de réponse pendant le commit, le résultat est inconnu ; conserver
le formulaire et réessayer. Cette protection suppose un serveur MySQL d'écriture
unique et la conservation de l'audit. La recherche JSON n'est pas indexée.
Le format au-delà de 999 reste D13 : l'application refuse le dépassement.

## Sessions et sécurité

Les sessions PHP natives sont stockées dans `instance/sessions/`, ou dans le
dossier privé `SESSION_PATH`. Cookies HttpOnly, SameSite=Lax, Secure par défaut ;
mode strict, rotation à la connexion, suppression serveur à la déconnexion,
expiration explicite après 30 minutes d'inactivité. Le cookie est renouvelé sur
les requêtes de l'application. Le verrou natif sérialise les requêtes d'une session.

Les limites de connexion sont persistées dans un fichier JSON privé verrouillé
par `flock` : 8 tentatives par IP/matricule, 30 par IP sur 10 minutes. Le frontal
doit gérer son propre débit ; l'application ne fait pas confiance aux en-têtes
IP clients. Les workers d'une machine partagent le dossier privé ; plusieurs
machines nécessitent un stockage commun adapté avant déploiement.

Tous les POST vérifient le CSRF. Le formulaire refuse champs protégés, tableaux
et champs répétés avant que PHP ne les écrase. Les contenus sont échappés ;
les erreurs SQL et les secrets ne sont pas affichés ou journalisés.

La migration demande une reconnexion et de nouveaux formulaires : les sessions
et clés Flask ne sont pas reconnues en PHP. Les dossiers et audits MySQL existants
restent lisibles. Les éventuels fichiers locaux de l'ancienne version ne sont pas utilisés.

## Tests et décisions restantes

```powershell
composer test
composer test:mysql
```

Les tests locaux vérifient PHP, les sessions natives et le HTTP, avec un PDO
instrumenté pour les opérations métier. Ils ne prouvent pas l'atomicité MySQL.
Pour les 13 tests MySQL, fournir un serveur et un compte de recette autorisé à
créer des bases ; chaque cas crée sa propre base neuve, conservée pour inspection.
Aucune base existante n'est vidée ou supprimée.

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

Les [résultats](verification.md) distinguent ce qui a été exécuté et ce qui reste
à faire. Le [registre D01 à D14](conception/06-decisions.md) reste ouvert : aucun
circuit de validation, transition, clôture, action corrective ou envoi de notification
n'est présenté comme livré dans ce premier parcours.

Références : [PDO et procédures](https://www.php.net/manual/en/pdo.prepared-statements.php),
[nextRowset](https://www.php.net/manual/en/pdostatement.nextrowset.php),
[sécurité des sessions PHP](https://www.php.net/manual/en/session.security.ini.php).
