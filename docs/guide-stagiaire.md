# Comprendre le projet en cinq minutes

L'application actuelle est en Python/Flask. PyMySQL joue ici le rôle de PDO :
ouvrir une connexion, envoyer des requêtes paramétrées et gérer la transaction.
Jinja produit les pages HTML. Le SQL du dossier `database/` reste la référence.

## 1. Où commence l'application ?

`wsgi.py` appelle `create_app()` dans `anrt/__init__.py`. Cette fonction charge
la configuration, les sessions, les protections HTTP et les routes. Elle ne
crée aucune table MySQL. `.env.example` décrit la configuration ; les valeurs
réelles et les secrets restent dans `.env`, ignoré par Git.

## 2. Suivre une requête

```text
Navigateur → routes.py → contrôles → lecture ou création MySQL → template HTML
```

`routes.py` est le meilleur point de départ pour comprendre le parcours :

| Adresse | Fonction | Suite du parcours |
| --- | --- | --- |
| `/connexion` | `login()` | `auth.authenticate()` vérifie le hash et les dates ; succès → liste. |
| `/non-conformites` | `nc_list()` | `repository.list_nc()` applique droits, filtres et pagination ; `list.html` affiche. |
| `/non-conformites/nouvelle` | `nc_new()` | GET affiche `new.html` ; POST appelle `service.create_nc()`. |
| `/non-conformites/<id>` | `nc_detail()` | `repository.detail_nc()` contrôle le périmètre ; `detail.html` affiche. |
| `/deconnexion` | `logout()` | POST révoque la session et retourne à la connexion. |

Les noms de fichiers sont tous sous `anrt/` ; les pages sont dans `templates/`.
Il n'y a pas d'écran de validation par un encadrant : « validation » dans le
premier parcours signifie contrôler la saisie côté serveur. Le circuit métier
de validation reste à décider avec D07 à D10.

## 3. Comprendre la création

Dans `service.create_nc()`, lire les instructions dans cet ordre :

1. Vérifier les permissions et la clé du formulaire.
2. Ouvrir MySQL avec `db.connect()` et verrouiller cette clé de soumission.
3. Commencer la transaction ; revérifier le compte et ses profils.
4. Retrouver un éventuel succès antérieur dans l'audit, pour éviter un doublon.
5. Appeler `validation.validate_form()` : IDs, textes, matricule et échéance.
6. Vérifier en SQL les référentiels actifs et le responsable autorisé.
7. Appeler `generer_reference`, puis vérifier la limite de 999.
8. Insérer la NC avec le déclarant connecté, la date serveur et `OUVERTE`.
9. Insérer son état initial dans `journal_audit`, puis faire le commit.

Les étapes transactionnelles utilisent **une seule connexion**. Une erreur
provoque un rollback. Le verrou est libéré et la connexion fermée dans `finally`.
Le navigateur reçoit une redirection vers la fiche après succès.

La clé de soumission est un identifiant signé du formulaire. Avec la même clé
et les mêmes données, un réessai retrouve le dossier. Avec un contenu différent
après succès, il reçoit un conflit. Un nouveau formulaire a une nouvelle clé.
Ce mécanisme sert aussi après une réponse perdue ; le bouton désactivé en
JavaScript ne suffit pas à protéger l'enregistrement.

## 4. Les quatre protections à retenir

- `auth.py` : matricule conservé comme chaîne, hash Argon2id/bcrypt, compte
  valide uniquement si `Date_Deb` est passée et `Date_Fin` non atteinte ou nulle.
- `sessions.py` : cookie opaque, rotation à la connexion, révocation à la
  déconnexion, expiration après 30 minutes d'inactivité ; stockage SQLite local.
- `permissions.py` : refus par défaut et même périmètre SQL pour liste et fiche.
  Masquer un bouton ne remplace pas le contrôle côté serveur.
- `validation.py` : aucun texte obligatoire vide, aucun dépassement de longueur,
  aucune échéance antérieure à la création ; pas de troncature ni valeur fictive.

Les requêtes utilisent des paramètres `%s` pour les données reçues. Les templates
échappent le HTML. Tous les POST vérifient un jeton CSRF, y compris la déconnexion.

`pers`, `acces` et `acces_profil` suffisent pour ce parcours. `profils_acces`
garantit les codes par clé étrangère. `direction` et `personnel_validation`
restent dans le modèle RH, sans inventer un filtre de service ni une approbation.
SQLite stocke des sessions techniques, pas une nouvelle table d'utilisateurs.

## 5. Vérifier et présenter

Depuis la racine, sous PowerShell :

```powershell
.\.venv\Scripts\python.exe -m pytest -q
.\.venv\Scripts\python.exe -m flask --app anrt:create_app check-db
```

La deuxième commande nécessite la configuration de `.env` et un MySQL disponible.
Suivre [l'installation](implementation.md) pour préparer une démonstration.
La démonstration nécessite des comptes de recette réels ; aucun compte n'est
créé au démarrage. En dehors de ce mode, les règles et permissions doivent être
explicitement configurées après leur confirmation.

Phrase de présentation : « Cette version permet de se connecter, déclarer et
consulter une non-conformité selon ses droits. La référence, la création et
l'audit sont coordonnés dans une transaction. Les validations métier, la clôture
et les notifications appartiennent aux lots suivants. »

Consulter les [vérifications](verification.md) avant de dire qu'un scénario est
testé sur MySQL. Les réponses métier restent dans le
[registre D01 à D14](conception/06-decisions.md).
