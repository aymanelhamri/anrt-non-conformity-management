# Revue du projet — 5 octobre 2026

Le dépôt a été relu : README, quatre scripts SQL et guide de base, modèle de
données, tous les documents de conception, configuration, modules Python,
templates, ressources statiques et tests. L'implémentation est en **Python/Flask**,
avec PyMySQL ; aucun fichier PHP, PDO ou `composer.json` n'existe dans le dépôt.
La revue conserve cette architecture et tous les scripts SQL.

## 1. Ce qui était déjà bon

- SQL explicite et paramétré, sans ORM, migration automatique ni table générique
  d'utilisateurs. Les matricules restent des chaînes de 60 caractères maximum.
- Connexion par matricule unique ; `acces.nom` n'est pas supposé unique.
  Argon2id et bcrypt remplacent ici le rôle de PHP `password_verify`.
- Conditions SQL `Date_Deb <= CURRENT_TIMESTAMP` et `Date_Fin > CURRENT_TIMESTAMP`
  ou NULL ; relecture du compte et de ses profils à chaque accès protégé.
- Sessions opaques, changement d'identifiant à la connexion, révocation serveur
  à la déconnexion, CSRF sur tous les POST, échappement HTML et cookies protégés.
- Aucun droit métier sans configuration ; CONSULTATION n'a pas de création
  dans la politique de démonstration. Les accès directs sont contrôlés côté serveur.
- Formulaire conforme au SQL : référentiels actifs, responsable autorisé,
  textes non vides et limités en octets UTF-8, date valide, aucun champ automatique
  laissé au choix du client, aucune valeur fictive ni troncature.
- Une connexion pour `BEGIN → generer_reference → INSERT NC → INSERT audit → COMMIT`.
  Un échec appelle le rollback ; le compteur supérieur à 999 est refusé.
- Clé signée de soumission, verrou MySQL et récupération dans l'audit pour les
  réessais du même formulaire. Cette logique a une raison fonctionnelle précise.

Ces constats portent sur le code relu et les tests locaux. Ils ne certifient
pas l'exécution SQL sur une base MySQL réelle.

### Correspondance avec le modèle existant

| Table | Utilisation dans cette version |
| --- | --- |
| `pers` | Identité, déclarant et responsable ; seules les données nécessaires sont sélectionnées. |
| `acces` | Hash, matricule, `Date_Deb` et `Date_Fin`. |
| `acces_profil`, `profils_acces` | Lecture des affectations ; la clé étrangère garantit les codes de profils. |
| `processus_responsable` | Périmètre processus et affectation, seulement si configurés. |
| `direction` | Conservée ; aucun périmètre de service n'est inventé. |
| `personnel_validation` | Conservée ; aucun circuit ou avis de validation n'est déduit de ses lignes. |
| `non_conformites` | Les colonnes de l'INSERT correspondent exactement au script 001. |
| `compteurs_reference` | Modifiée par la procédure, puis lue pour contrôler la limite de 999. |
| `journal_audit` | État initial et clé de réessai dans le JSON existant ; aucun hash de mot de passe. |

Les colonnes de suivi non saisies restent à leurs valeurs SQL par défaut.
Le booléen d'action corrective ne devient pas une décision métier examinée.
La vue `v_personnel` ne vérifie pas le début futur ; l'authentification vérifie
directement `acces`. La procédure utilise un numéro sur trois caractères ;
l'application bloque le dépassement sans altérer le SQL.

## 2. Problèmes trouvés

| Problème | Pourquoi c'est un problème |
| --- | --- |
| Connexions SQLite non fermées explicitement | Le contexte SQLite valide/annule, mais ne ferme pas. Défaut reproduit : connexion utilisable après sortie et fichier temporaire bloqué sous Windows. |
| Choix inutiles chargés sur la liste | Types, natures et personnel d'affectation étaient lus sans être affichés ; requêtes et dépendances superflues. |
| Documentation de conception devenue ambiguë | Plusieurs passages affirmaient encore qu'aucune application ou aucun écran n'était implémenté. |
| Repérage du code insuffisant pour une première lecture | La documentation technique était présente, mais il manquait le guide court et la section README demandés. |
| Couverture locale incomplète | Le parcours HTTP combiné et certains cas de sessions et de réessai n'étaient pas exercés ensemble. |

## 3. Modifications effectuées

| Fichier | Correction | Impact |
| --- | --- | --- |
| `anrt/sessions.py` | Fermeture dans `finally`, après le contexte de commit/rollback. | Libération certaine de la connexion, y compris après erreur. |
| `anrt/repository.py` | `choices(..., for_list=True)` charge processus et statuts ; le formulaire charge ses propres choix. | Moins de requêtes et de lectures de personnel inutiles. |
| `anrt/routes.py` | Utilisation du paramètre explicite `for_list` et adaptation des listes de secours du formulaire. | Même affichage, permissions conservées. |
| README et documentation | Carte du code, guide stagiaire et état réel des écrans/tests. | Présentation plus simple, sans transformer les propositions métier en décisions. |
| Tests | Ajout des scénarios manquants et régression SQLite. | Vérification locale des corrections et protections existantes. |

## 4. Simplifications effectuées

Suppression des chargements inutiles dans `choices()` et nom plus explicite de
son option. Les fichiers courts gardent une responsabilité claire : connexion,
configuration, permissions ou validation. Il n'existe ni chaîne d'interfaces,
ni classes de service intermédiaires, ni couches d'ORM à supprimer.

Les deux classes de session répondent au contrat de Flask. La séparation entre
routes, lectures SQL et transaction de création aide à suivre le parcours.
Le stockage de sessions révocables et la protection des réessais sont conservés :
les supprimer réduirait les garanties demandées. Aucune dépendance n'a été ajoutée.

## 5. Fichiers modifiés ou ajoutés

- Application : `anrt/sessions.py`, `anrt/repository.py`, `anrt/routes.py`.
- Tests existants : `tests/test_http.py`, `tests/test_security.py`, `tests/test_service.py`.
- Test ajouté : `tests/test_sessions.py`.
- Documentation actualisée : `README.md`, `docs/implementation.md`, `docs/verification.md`,
  `docs/conception/README.md`, `01-cadrage.md`, `04-ecrans-donnees.md`, `05-lots-recette.md`.
- Documents ajoutés : `docs/guide-stagiaire.md`, `docs/revue.md`.

## 6. Tests réellement exécutés

Les commandes, résultats et limites sont consignés dans
[verification.md](verification.md). Les tests locaux utilisent une vraie base
SQLite pour les sessions et des doubles pour MySQL.

Ont été exécutés : tests existants, parcours HTTP connexion → liste → formulaire
→ erreur de validation → soumission valide → confirmation/fiche → déconnexion,
accès sans authentification, refus des quatre profils sans permissions confirmées,
hashes Argon2id/bcrypt y compris le préfixe PHP `$2y$`, CSRF, expiration et
révocation des sessions, rollback SQLite, orchestration du rollback de création,
réessai identique et conflit de contenu, liens de pagination et rendu des filtres.

Une vérification HTTP avec serveur local réel a aussi exercé la connexion,
les fichiers CSS/JS, la redirection sans authentification et le refus d'un POST
sans CSRF. Le contrôle de connexion MySQL a été tenté : échec de connexion,
`OperationalError` 2003. Cela n'est pas un test réussi de la base.

## 7. Ce qui reste non testé

- Exécution des scripts, validité réelle des comptes futurs/expirés et clés étrangères MySQL.
- Création/audit/rollback atomiques, procédure et compteur sur MySQL.
- Double soumission simultanée, créations concurrentes et réponse perdue après commit.
- Périmètres SQL réels, filtres et pagination sur des données MySQL.
- Parcours complet avec comptes réels et vérification interactive du navigateur,
  notamment JavaScript, double clic et présentation responsive.

Les 13 cas MySQL opt-in ont été ignorés. Aucun client/service MySQL disponible
n'a été trouvé ; le moteur Docker ne répond pas. PHP et Composer ne sont pas
présents et ne s'appliquent pas à ce dépôt. Aucun schéma métier n'a été modifié.

Le réessai dépend d'un serveur MySQL d'écriture unique et de la conservation des
audits de création. La recherche JSON n'est pas indexée. Les sessions SQLite
conviennent aux workers d'une machine partageant le fichier local. Ces limites,
déjà documentées, ne justifient pas d'ajouter des services pour le prototype.

## 8. Décisions métier toujours ouvertes

Le registre [D01 à D14](conception/06-decisions.md) reste ouvert. Pour ce parcours,
confirmer surtout le jalon D02, le sens du traitement/responsable D03, les dates
D04, les profils/cumuls/périmètres D05/D06, la validation initiale D07, les
références et réessais D13, ainsi que l'identifiant RH et l'exploitation D14.
D08 à D12 concernent les validations, transitions, clôture et actions ultérieures.
La politique de démonstration ne vaut pas approbation métier.

## 9. Arborescence finale

```text
.
├── wsgi.py
├── README.md, .env.example, .gitignore
├── requirements.txt, requirements-dev.txt, requirements-lock.txt, pytest.ini
├── anrt/
│   ├── __init__.py, config.py, db.py
│   ├── auth.py, sessions.py, permissions.py
│   ├── routes.py, repository.py, validation.py, service.py, cli.py
│   ├── templates/  base, login, list, new, detail, error (.html)
│   └── static/     app.css, app.js
├── config/         permissions.closed.json, permissions.example.json
├── database/       README.md, 001_schema.sql, 002_seed_referentiels.sql,
│                   003_procedures.sql, 004_views.sql
├── docs/
│   ├── modele-donnees.md, implementation.md, verification.md
│   ├── guide-stagiaire.md, revue.md
│   └── conception/ README.md et documents 01 à 06
└── tests/          conftest.py, test_http.py, test_security.py,
                   test_validation.py, test_service.py, test_sessions.py, test_mysql.py
```

`.venv/`, `instance/`, caches et `.env` sont locaux et ignorés par Git.

## 10. Comprendre le parcours principal en cinq minutes

Lire le [guide stagiaire](guide-stagiaire.md), puis les fonctions `login()`,
`nc_list()`, `nc_new()` et `nc_detail()` de `routes.py`. Ouvrir `create_nc()`
pour repérer validation, procédure, INSERT et audit. Terminer avec `logout()`.

| Étape | Entrées et contrôles | SQL et résultat |
| --- | --- | --- |
| Connexion | Matricule, mot de passe, CSRF, limitation des tentatives, convention configurée. | Hash dans `acces`, validité et profils ; rotation de session puis 303 vers la liste, sinon erreur générique. |
| Liste | Session revalidée, périmètre de lecture, filtres et page valides. | COUNT puis SELECT sur le même snapshot ; 20 lignes/page, `list.html` ; 403 si aucun droit. |
| Nouvelle NC | Droit de création et règles initiales confirmées, ou démonstration explicite. | Choix actifs et responsables autorisés ; formulaire avec clé signée. |
| Validation | Champs autorisés et non répétés, IDs unsigned, textes non vides, capacité TEXT, matricule, date. | Vérification SQL des référentiels et du responsable ; erreurs 400/422/403 avec saisie conservée dans la route. |
| Enregistrement | Compte/profils revérifiés ; même clé et contenu après succès = réessai. | Même connexion pour compteur, NC et audit ; commit ou rollback ; conflit 409, erreur MySQL 503 sans succès annoncé. |
| Confirmation | Uniquement après résultat de création confirmé ou retrouvé. | Flash et redirection 303 vers la fiche. |
| Fiche | Session et périmètre du dossier, droit d'audit distinct. | SELECT scoped et audit autorisé ; `detail.html`, même 404 pour absence et refus. |
| Déconnexion | POST avec CSRF. | Suppression de session SQLite, cookie révoqué et 303 vers la connexion. |

Une indisponibilité MySQL avant la revalidation du compte renvoie la page
d'erreur générale 503 ; la conservation de saisie rendue par `nc_new()` concerne
les erreurs survenant après cette revalidation. Une réponse perdue reste un
résultat inconnu jusqu'au réessai avec le même formulaire.
