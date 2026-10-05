# Revue et correction de la technologie — 5 octobre 2026

## 1. Ce qui était déjà bon

Le parcours, les requêtes paramétrées, les contrôles de compte, les permissions
fermées par défaut, les champs NC et la transaction avec audit avaient une base
cohérente avec le SQL. Les écrans et ressources CSS/JS pouvaient être conservés.

## 2. Problèmes trouvés

**La technologie ne respectait pas la demande PHP.** La version précédente était
en Flask/Python et la première revue avait conservé ce choix à tort. Les tests
Python ne permettaient donc pas de vérifier une application PHP.

Le portage devait aussi tenir compte de trois particularités PHP : les champs
POST répétés sont normalement écrasés, les dates invalides peuvent être normalisées
et les procédures PDO peuvent laisser plusieurs jeux de résultats ouverts.
Un contrôle a aussi reproduit la troncature native bcrypt à l'octet NUL :
ces mots de passe sont maintenant refusés explicitement, comme les dépassements
de 72 octets, pour éviter d'accepter une saisie différente par troncature.

## 3. Modifications effectuées

| Fichier | Modification et impact |
| --- | --- |
| `public/index.php`, `app/bootstrap.php`, `app/web.php` | Point d'entrée PHP et parcours HTTP avec les mêmes URLs. Seul `public/` est servi. |
| `app/database.php` | PDO MySQL, exceptions, requêtes natives préparées, paramètres typés, UTC et mode strict. |
| `app/auth.php` | `password_verify`, validité SQL du compte, sessions PHP natives, rotation, révocation, expiration, CSRF et limite de connexion persistante. |
| `app/permissions.php` | Même politique fermée et mêmes périmètres SQL ; aucun droit de création supposé pour CONSULTATION. |
| `app/non_conformites.php` | Validation, liste, fiche et création portées ; une connexion PDO pour la procédure, la NC et l'audit. |
| `templates/*.php` | Portage des pages avec échappement explicite ; présentation et contenus conservés. |
| `bin/console.php` | Vérification DB en lecture et génération Argon2id par entrée standard protégée. |
| `composer.json`, `composer.lock` | Prérequis et commandes de tests PHP, sans paquet tiers. |

Le formulaire refuse les clés inconnues, répétées ou sous forme de tableaux.
Les dates passent un contrôle de format et de retour à l'identique. Après CALL,
les jeux de résultats PDO sont vidés avant les requêtes suivantes.

## 4. Simplifications effectuées

Validation, lectures et transaction NC sont regroupées dans un seul fichier
fonctionnel, `app/non_conformites.php`. Les sessions natives remplacent le
stockage de sessions Flask/SQLite. Les limites de connexion utilisent un fichier
privé verrouillé, sans ajouter de serveur ou bibliothèque.

Aucune classe de service ou interface métier n'a été introduite. Les deux
classes applicatives sont de simples exceptions transportant les erreurs.
La clé de soumission et le verrou MySQL restent nécessaires aux réessais demandés.

## 5. Fichiers modifiés

- Ajoutés : `app/*.php`, `public/index.php`, `public/.htaccess`, `templates/*.php`,
  `config/app.php`, `bin/console.php`, `composer.json`, `composer.lock`, tests PHP.
- Réutilisés à l'identique : `public/assets/app.css`, `public/assets/app.js`.
- Actualisés : `.env.example`, `.gitignore`, README et guides de conception,
  d'installation, de revue, de vérification et de prise en main.
- Retirés : code Flask, templates Jinja, tests Python, fichiers requirements,
  `pytest.ini` et `wsgi.py`. Ils restent accessibles dans l'historique Git.
- Conservés sans modification : les quatre scripts SQL, le modèle de données,
  les fichiers de permissions et le registre des décisions métier.

## 6. Tests réellement exécutés

Voir les commandes et résultats dans [verification.md](verification.md) :
syntaxe PHP, validation Composer, tests PHP locaux et scénarios HTTP sur serveur
PHP. Le parcours authentifié utilise un PDO instrumenté ; les sessions natives,
le décodage HTTP et les templates sont réellement exécutés.

## 7. Ce qui reste non testé

Les 13 cas MySQL préparés restent non exécutés faute de serveur disponible :
comptes futurs/expirés réels, contraintes, références, atomicité, concurrence,
rollback d'audit, réessai après commit, périmètres SQL, filtres et pagination.
Le rendu interactif dans un navigateur et le double clic JavaScript restent à
vérifier. Un PDO instrumenté ne prouve pas l'atomicité de la base.

Le réessai requiert un serveur MySQL d'écriture unique et la conservation des
audits de création. Le stockage local des sessions/limites doit être partagé
entre workers de la même machine ; un déploiement multi-machine demande une
solution adaptée. Ces limites ne sont pas masquées par le portage.

## 8. Décisions métier ouvertes

Le [registre D01 à D14](conception/06-decisions.md) reste ouvert. Confirmer
notamment traitement/responsable D03, échéance/date D04, profils/cumul/périmètres
D05/D06, validation initiale D07, références/réessais D13, comptes et exploitation
D14. Les fonctionnalités de validation, clôture, actions et notifications restent
des extensions ; la démo technique n'approuve aucune règle métier.

## 9. Arborescence finale

```text
.
├── public/          index.php, .htaccess, assets/app.css, assets/app.js
├── app/             bootstrap.php, web.php, database.php, auth.php,
│                    permissions.php, non_conformites.php
├── config/          app.php et permissions.closed/example.json
├── templates/       base.php, login.php, list.php, new.php, detail.php, error.php
├── bin/             console.php
├── tests/           run.php, FakeDatabase.php, http.php, http_router.php,
│                    mysql.php, mysql_support.php, mysql_worker.php
├── database/        README et scripts 001 à 004 inchangés
├── docs/            conception/, modele-donnees.md, implementation.md,
│                    guide-stagiaire.md, verification.md, revue.md
└── README.md, .env.example, .gitignore, composer.json, composer.lock
```

## 10. Comprendre le parcours en cinq minutes

Lire le [guide stagiaire](guide-stagiaire.md), puis `handle_request()` dans
`app/web.php`. Ouvrir `create_nc()` dans `app/non_conformites.php` pour repérer
validation, procédure, INSERT et audit. Les droits sont dans `permissions.php`
et l'identité/session dans `auth.php`.

La confirmation est une redirection 303 vers la fiche après commit. Une NC
absente ou hors périmètre reçoit le même 404. Les erreurs de saisie ou de création
après revalidation du compte conservent le formulaire et sa clé. Si MySQL est
indisponible avant cette revalidation, une page générale 503 est affichée.
