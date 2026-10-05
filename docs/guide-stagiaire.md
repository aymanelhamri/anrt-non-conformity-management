# Comprendre le projet PHP en cinq minutes

PHP traite les requêtes du navigateur, PDO exécute le SQL et les templates
produisent le HTML. Aucun framework n'est nécessaire pour suivre le code.
Le SQL du dossier `database/` reste la référence.

## 1. Le point d'entrée

`public/index.php` charge `app/bootstrap.php`. Celui-ci charge les fonctions
et la configuration de `config/app.php`, puis `handle_request()` dans `app/web.php`
traite l'adresse demandée. Aucun schéma ni compte MySQL n'est créé au démarrage.

## 2. Suivre le navigateur

| Adresse | Contrôle principal | Code et affichage |
| --- | --- | --- |
| `/connexion` | Matricule, `password_verify`, dates du compte, CSRF. | `app/auth.php`, puis rotation de session et liste ; sinon `templates/login.php`. |
| `/non-conformites` | Compte revalidé et périmètre autorisé. | `list_nc()`, puis `templates/list.php`. |
| `/non-conformites/nouvelle` | Droit de création et règles initiales confirmées. | GET : `templates/new.php` ; POST : `create_nc()`. |
| `/non-conformites/<id>` | Droit de lecture sur ce dossier, droit d'audit séparé. | `detail_nc()`, puis `templates/detail.php`. |
| `/deconnexion` | POST avec CSRF. | Session détruite, cookie supprimé, retour à la connexion. |

Les fonctions NC se trouvent dans **`app/non_conformites.php`** : validation,
lectures et transaction sont regroupées pour éviter de chercher dans plusieurs couches.
Le choix du responsable ne constitue pas une approbation. La validation métier
par un encadrant reste à décider ; ici « validation » signifie contrôler les champs.

## 3. Lire la création

Dans `create_nc()`, repérer successivement :

1. Permissions et clé signée du formulaire.
2. Verrou de la clé, puis `beginTransaction()` sur la connexion PDO reçue.
3. Relecture du compte/profils et recherche d'un succès antérieur dans l'audit.
4. `validate_nc()` : IDs, textes, matricule et échéance.
5. Requêtes vérifiant les référentiels actifs et le responsable autorisé.
6. `CALL generer_reference`, puis récupération de la référence et limite de 999.
7. `INSERT non_conformites` avec déclarant connecté, date serveur et `OUVERTE`.
8. `INSERT journal_audit`, puis `commit()`.

Une erreur provoque `rollBack()` si la transaction est encore active. La fonction
libère le verrou dans `finally`. La confirmation est affichée après une
redirection vers la fiche. Les lectures SQL peuvent ouvrir une connexion par
requête HTTP ; **la création utilise une seule connexion PDO du début à la fin**.

Avec la même clé et le même contenu, une nouvelle tentative retrouve le dossier.
Une clé ayant déjà enregistré un autre contenu produit un conflit. Un nouveau
formulaire signifie une nouvelle déclaration. Le bouton JavaScript désactivé
est une aide ; la protection réelle du réessai est côté serveur.

## 4. Les fichiers de sécurité

- `app/database.php` : connexion PDO, UTF-8, UTC, mode SQL strict et requêtes préparées.
- `app/auth.php` : hash, compte valide, sessions natives, CSRF et limitation de connexion.
- `app/permissions.php` : refus par défaut, lecture et affectation selon le périmètre configuré.
- `app/non_conformites.php` : contrôle des champs et enregistrement cohérent avec le SQL.

Un matricule reste une chaîne, par exemple `00017`. `acces.nom` n'est pas utilisé
comme identifiant unique. `Date_Deb` future ou `Date_Fin` atteinte interdit
l'accès. Aucun texte n'est tronqué ; aucune valeur fictive ne remplit un champ obligatoire.

`pers`, `acces` et `acces_profil` servent au parcours. La clé étrangère vers
`profils_acces` garantit les codes. `direction` et `personnel_validation` restent
dans le modèle, sans attribuer de droits ou de validation implicitement.

## 5. Vérifier et présenter

```powershell
composer validate --strict
composer test
php bin/console.php check-db
```

La dernière commande nécessite `.env` correctement configuré et MySQL disponible.
Le [guide d'installation](implementation.md) décrit la préparation de la démo.
Lire les [résultats](verification.md) avant d'affirmer qu'un scénario a été testé
sur MySQL. Les tests avec PDO instrumenté vérifient le code, pas le stockage réel.

Phrase de présentation : « Cette version PHP permet de se connecter, déclarer
et consulter une non-conformité selon ses droits. PDO coordonne la référence,
la création et l'audit dans une transaction. Les validations métier, la clôture
et les notifications appartiennent aux lots suivants. »

Les décisions à faire confirmer sont dans le [registre D01 à D14](conception/06-decisions.md).
