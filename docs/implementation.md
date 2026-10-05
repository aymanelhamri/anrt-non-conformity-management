# Premier parcours implémenté

Cette livraison prépare le lot A décrit dans `conception/05-lots-recette.md`.
Elle ne vaut pas approbation des décisions métier ouvertes, ni recette MySQL
complète. Aucun script SQL existant n'a été modifié ou exécuté sur une base métier.

## Choix et arborescence

Python 3.12+, Flask, Jinja et PyMySQL permettent un serveur et des formulaires
HTML sans compilation frontend. Les requêtes explicites gardent le SQL fourni
comme référence ; aucun ORM, table utilisateurs ou migration implicite.
Argon2id est utilisé pour les nouveaux hashes ; bcrypt est accepté en lecture.
Waitress permet aussi un lancement sous Windows.

```text
anrt/
  __init__.py        fabrique Flask et protections HTTP
  config.py         configuration par environnement
  auth.py           connexion, validité, CSRF
  sessions.py       sessions révocables et limitation des connexions
  permissions.py    droits et périmètres centralisés
  validation.py     types, longueurs UTF-8, dates
  db.py             connexions MySQL paramétrées, UTC, mode strict
  repository.py     référentiels, liste, pagination et consultation
  service.py        transaction NC + compteur + audit, réessais
  routes.py         parcours HTML
  cli.py            hash-password et check-db
  templates/        connexion, liste, formulaire, fiche, erreurs
  static/           CSS responsive et protection du double clic
config/
  permissions.closed.json    refus de tous les droits métier
  permissions.example.json   exemple NON approuvé
tests/                      tests locaux et recette MySQL opt-in
database/                   scripts initiaux conservés
wsgi.py                     point d'entrée
.env.example                configuration sans secret
requirements*.txt           dépendances
```

## Installation et lancement sous PowerShell

```powershell
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements-dev.txt
Copy-Item .env.example .env
.\.venv\Scripts\python.exe -c "import secrets; print(secrets.token_hex(32))"
```

Reporter la clé générée dans `SECRET_KEY` de `.env`. Renseigner les paramètres
MySQL et conserver ce fichier hors de Git. Les variables du processus priment
sur `.env`. `requirements-lock.txt` permet de reproduire l'environnement de
vérification, y compris pytest, avec `pip install -r requirements-lock.txt`.

Sur un **serveur de recette dédié et neuf**, installer les scripts 001 à 004
dans l'ordre du [guide SQL](../database/README.md). Ils ciblent `anrt_qualite`.
Le script 003 remplace une procédure : ne pas le relancer sur une base existante
sans revue. L'application n'initialise et ne migre jamais la base au démarrage.

Utiliser un compte applicatif disposant de `SELECT` sur les tables consultées,
`INSERT` sur `non_conformites` et `journal_audit`, et `EXECUTE` sur
`generer_reference`. La procédure fournie utilise les droits de son définisseur :
celui-ci doit avoir `INSERT/UPDATE/SELECT` sur les compteurs et accès au processus.
L'application ne nécessite aucun droit de création/suppression de table.

Les comptes doivent être fournis par la source RH (`pers`, `acces`,
`acces_profil`, et affectations pertinentes). Aucun import RH n'est inventé.
Pour préparer un compte de recette, générer son hash sans passer son mot de
passe en argument ou le conserver dans un fichier :

```powershell
.\.venv\Scripts\python.exe -m flask --app anrt:create_app hash-password
```

Insérer ce hash via le canal d'administration autorisé dans `acces.passe`.
Dans une base de démonstration neuve seulement, un administrateur peut utiliser
ce modèle SQL, en remplaçant le hash et les dates :

```sql
START TRANSACTION;
INSERT INTO pers (matricule, nom_prenom) VALUES ('00017', 'Compte de recette');
INSERT INTO acces (matricule, nom, passe, Date_Deb, Date_Fin)
VALUES ('00017', 'Compte de recette', '<HASH_ARGON2ID_GENERE>', CURRENT_TIMESTAMP, NULL);
INSERT INTO acces_profil (matricule, profil_code) VALUES ('00017', 'RESPONSABLE');
COMMIT;
```

Ne pas envoyer ce modèle avec le placeholder de hash. Il ne crée ni compte
automatique ni mot de passe partagé. Les matricules restent des chaînes.

Pour exercer le parcours en démonstration locale, modifier `.env` :

```dotenv
DEMO_MODE=true
SESSION_COOKIE_SECURE=false
```

Ce mode active explicitement la politique de démonstration décrite ci-dessous,
le login par matricule, et la saisie initiale conforme au SQL. Le bandeau apparaît
sur toutes les pages. Il nécessite quand même MySQL et des comptes valides.

```powershell
.\.venv\Scripts\python.exe -m flask --app anrt:create_app check-db
.\.venv\Scripts\python.exe -m flask --app anrt:create_app run --host 127.0.0.1 --port 5000
```

Ouvrir `http://127.0.0.1:5000`. Se connecter, ouvrir la liste, créer une NC avec
ses trois référentiels, une description, un traitement et un responsable réel.
Après succès, la fiche affiche la référence et, si autorisé, l'événement de création.
La déconnexion se fait par formulaire POST protégé.

Pour un serveur derrière HTTPS : `DEMO_MODE=false`,
`SESSION_COOKIE_SECURE=true`, puis :

```powershell
.\.venv\Scripts\waitress-serve.exe --listen=127.0.0.1:8000 wsgi:app
```

Configurer le frontal HTTPS et son contrôle de débit. L'application ne fait pas
confiance aux en-têtes IP transmis par le client. Sa limitation interne s'appuie
sur l'adresse de connexion effective ; derrière un proxy, elle peut être commune
aux utilisateurs. Ne pas activer le debugger en exploitation.

## Règles confirmées et choix provisoires

| Élément | État et comportement livré |
| --- | --- |
| Structure et types | Confirmés par le SQL : matricules `VARCHAR(60)` binaires, IDs unsigned, référence 50 caractères, `TEXT` limité à 65 535 octets UTF-8, dates `DATE`. Aucun trim/troncature des données enregistrées. |
| Responsable et traitement | Obligatoires selon le schéma ; aucun substitut fictif. La création hors démo reste bloquée avant confirmation D03/D04/D07. |
| Compte | Vérification SQL de `Date_Deb <= maintenant` et `Date_Fin > maintenant` ou NULL à la connexion et à chaque opération protégée. Les profils sont rechargés à chaque requête et revérifiés dans la transaction de création. |
| Identifiant | **Proposition D14** : le matricule, clé unique et binaire. Hors démo, `LOGIN_IDENTIFIER=matricule` doit être explicitement fixé après confirmation. `acces.nom` n'est pas utilisé pour identifier un compte. |
| Champs automatiques | Déclarant issu de la session, création datée par le serveur, référence générée, `OUVERTE`, horodatages SQL. Toute soumission de champs protégés ou répétés est rejetée. |
| Date métier | **Proposition D04** : aujourd'hui dans `APP_TIMEZONE=Africa/Casablanca`, configurable ; les TIMESTAMP et comparaisons de validité utilisent UTC. Les dates de compte doivent être importées avec leur fuseau correctement converti. |
| Échéance | **Proposition D04** : facultative suivant le SQL ; `NC_DEADLINE_REQUIRED=true` permet de la rendre obligatoire. Pas de rétroactivité. |
| Profils | **Décisions D05/D06 ouvertes** : aucun droit métier par défaut. Pas de conversion des quatre codes existants en trois nouveaux profils. |
| Cumul | Les droits explicitement configurés sont réunis ; ce choix technique doit être confirmé avec D05. Un profil inconnu n'accorde rien. |
| Référence | Procédure existante, même connexion/transaction que NC et audit. Au-delà de 999, rollback et refus explicite ; extension du format en attente D13. |
| Réessais | **Proposition D13** : même clé signée et même contenu => même dossier ; même clé et contenu différent => conflit 409. Un nouveau formulaire signifie une nouvelle déclaration. |
| Action corrective | Le défaut FALSE est stocké par SQL ; aucun examen métier n'en est déduit ou affiché. |

Hors démonstration, activer les règles retenues avec
`NC_INITIAL_RULES_CONFIRMED=true` seulement après validation de D03/D04 et de
l'absence de validation obligatoire à l'enregistrement (D07). Fournir un fichier
JSON de droits approuvés via `PERMISSIONS_FILE`. L'exemple fourni est une
proposition à revoir ; le fichier fermé `{}` est le comportement par défaut.

Les permissions disponibles sont `create` (booléen), `read` et `audit` (listes de
périmètres `own`, `assigned`, `process`, `all`), et `assign` (`self`, `process`,
`all`). `process` repose uniquement sur une affectation explicite dans
`processus_responsable` ; ce lien n'accorde aucun droit sans configuration.
`assign=process` limite le responsable au processus choisi à l'enregistrement.
Un droit de création requiert aussi la consultation de ses déclarations.

La politique de **démonstration seulement** est :

| Code SQL | Création | Consultation | Audit | Responsable sélectionnable |
| --- | --- | --- | --- | --- |
| ADMINISTRATEUR | Oui | Tous | Tous | Personnel existant |
| RESPONSABLE | Oui | Déclarations et dossiers affectés | Même périmètre | Soi-même |
| PILOTE_PROCESSUS | Non | Processus explicitement affectés | Même périmètre | Aucun |
| CONSULTATION | Non | Déclarations et dossiers affectés | Non | Aucun |

Les permissions s'appliquent côté serveur à la liste, à la fiche et à la
création. Une fiche absente ou hors périmètre renvoie le même 404 afin de ne pas
révéler son existence. Les champs RH non nécessaires ne sont pas sélectionnés.

## Transactions, sessions et limites connues

Une clé signée lie chaque formulaire à son acteur. `GET_LOCK` sérialise les
soumissions de cette clé sur le même serveur MySQL, avant le début de la
transaction. L'audit stocke la clé sous forme de SHA-256 et l'empreinte du contenu
dans son JSON existant. Le service cherche un succès antérieur, revérifie les
données et les droits, appelle la procédure, contrôle le compteur, insère la NC
et son audit, puis valide. Tout échec confirmé provoque un rollback.

Une erreur de communication pendant le commit peut signifier un résultat
inconnu. Le message ne prétend pas que l'opération a échoué : le formulaire et
sa clé sont conservés. Le réessai retrouve l'audit d'un succès éventuel, même
après reconnexion ou redémarrage. Une échéance d'hier n'empêche pas de retrouver
un succès déjà validé. Le changement de contenu d'une clé déjà utilisée est
refusé. Le verrou est libéré explicitement et aussi à la fermeture de connexion.

Cette protection nécessite un **serveur MySQL d'écriture unique**. Elle ne
couvre pas une application extérieure qui ignore ce protocole, ni un déploiement
multi-primary. Le JSON d'audit n'est pas indexé pour cette recherche : une
évolution de volumétrie ou de rétention demandera une migration revue.
Conserver les audits de création est nécessaire à la récupération des réessais.
Deux formulaires avec deux clés distinctes peuvent créer deux dossiers au contenu
identique ; aucune règle de dédoublonnage métier n'est inventée.

Les sessions et limites de connexion sont dans `instance/sessions.sqlite3`,
stockage technique local distinct de la base métier. Le cookie contient seulement
un identifiant aléatoire, HttpOnly, SameSite=Lax, Secure par défaut. Il est changé
à la connexion et révoqué côté serveur à la déconnexion. L'inactivité maximale
est de 30 minutes. CSRF protège tous les POST, y compris connexion/déconnexion ;
les contenus affichés sont échappés et les ressources sont locales.

Protéger `instance/` avec les permissions du compte de service. Les workers sur
une même machine doivent partager ce fichier. Pour plusieurs machines, prévoir
un stockage de sessions partagé avant déploiement. Ne pas servir `instance/`
comme ressource web. Aucun mot de passe, hash ou requête SQL contenant des données
métier n'est écrit dans les logs de l'application.

## Vérifications et recette

```powershell
.\.venv\Scripts\python.exe -m pytest -q
.\.venv\Scripts\python.exe -m compileall -q anrt wsgi.py
git diff --check
```

Les tests locaux exercent validations, limites UTF-8, dates, matricules,
Argon2id/bcrypt, CSRF, rotation/révocation de sessions, débit de connexion,
refus par défaut, accès direct refusé à CONSULTATION, rendu des pages, échappement
HTML et maintien de la saisie après erreur. Les accès MySQL des tests HTTP sont
remplacés par des doubles : cela ne prouve pas le fonctionnement sur une vraie base.

La suite d'intégration **opt-in** nécessite MySQL 8.0.16+ et un compte autorisé à
créer des bases de recette. Elle n'utilise pas la base indiquée par `MYSQL_DATABASE` :
chaque test crée une nouvelle base `anrt_recette_<uuid>`, applique les quatre
scripts et fournit ses propres comptes temporaires. Une collision du nom fait
échouer la création. Aucune base existante n'est vidée ou supprimée ; les bases
de tests sont conservées et leurs noms sont affichés pour inspection.

```powershell
$env:TEST_MYSQL = '1'
$env:TEST_MYSQL_HOST = '127.0.0.1'
$env:TEST_MYSQL_PORT = '3306'
$env:TEST_MYSQL_USER = 'compte_recette'
$testCredential = Get-Credential -UserName 'compte_recette' -Message 'Compte MySQL de recette'
$env:TEST_MYSQL_PASSWORD = $testCredential.GetNetworkCredential().Password
.\.venv\Scripts\python.exe -m pytest -m mysql -v -s
Remove-Item Env:TEST_MYSQL_PASSWORD
Remove-Item Env:TEST_MYSQL
```

| Critères | Tests préparés | État local |
| --- | --- | --- |
| CA01/02 | HTTP, sessions et hashes ; validité réelle des comptes dans MySQL | Partie locale vérifiée ; MySQL à exécuter |
| CA03/06/09/10/14 | Création/audit, FK, inactivité, simultanéité et trigger d'échec d'audit | MySQL à exécuter |
| CA04/05/07/08 | Champs requis, octets UTF-8, unsigned, dates, champs protégés | Vérifiés localement |
| CA11 | Signature de clé et acteur ; double soumission simultanée ; perte simulée de réponse après commit | Partie locale vérifiée ; MySQL à exécuter |
| CA12/13 | Refus HTTP et rendu ; périmètre SQL, 21 dossiers, filtres et pagination | Partie locale vérifiée ; MySQL à exécuter |
| CA15 | Saisie et clé conservées après erreur, y compris indisponibilité des référentiels | Vérifié localement |
| CA16 | Numéro 999 accepté, 1000 bloqué avec compteur rollback ; contrôle de LPAD réel | MySQL à exécuter |

L'environnement inspecté possède Python ; aucun client/service MySQL n'a été
trouvé et le moteur Docker n'est pas démarré. Résultats effectivement exécutés
le 5 octobre 2026 : voir `verification.md`. Le lot A ne peut pas être déclaré
recetté tant que les décisions dépendantes et ces tests réels ne sont pas validés.

## Décisions encore nécessaires

Le périmètre NC de ce développement est demandé par l'utilisateur ; la
numérotation officielle des jalons reste D02. Restent à confirmer : D03
(sens/auteur/instant du traitement et responsable), D04 (échéance et fuseau/date),
D05/D06 (profils, cumul, périmètres et affectation), D07 (validation obligatoire
ou non dès l'enregistrement), D13 (limite 999, format et réessais), D14
(identifiant RH, préparation des comptes, exploitation et rétention d'audit).

D08 à D12 restent ouvertes pour les extensions. Aucun circuit 1→2→3,
validation de NC dans `validations`, changement de statut, clôture, action
corrective, administration complète, notification automatique ou tableau de
bord n'est livré ou présenté comme disponible.

Références techniques : [sécurité Flask](https://flask.palletsprojects.com/en/stable/web-security/),
[résultats des procédures PyMySQL](https://pymysql.readthedocs.io/en/latest/modules/cursors.html),
[LPAD MySQL](https://dev.mysql.com/doc/refman/8.4/en/string-functions.html),
[verrous nommés MySQL](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html),
[Argon2id](https://argon2-cffi.readthedocs.io/en/stable/howto.html).
