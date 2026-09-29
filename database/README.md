# Base de données ANRT Qualité

La base cible MySQL 8.0+ et couvre les actions d’amélioration, les
non-conformités, les demandes d’accès et les données du personnel.

## Installation

Exécuter les scripts dans cet ordre avec un compte autorisé à créer une base :

```powershell
Get-Content -Raw -Encoding utf8 database/001_schema.sql | mysql -u root -p --default-character-set=utf8mb4
Get-Content -Raw -Encoding utf8 database/002_seed_referentiels.sql | mysql -u root -p --default-character-set=utf8mb4
Get-Content -Raw -Encoding utf8 database/003_procedures.sql | mysql -u root -p --default-character-set=utf8mb4
Get-Content -Raw -Encoding utf8 database/004_views.sql | mysql -u root -p --default-character-set=utf8mb4
```

Les scripts 1, 2 et 4 sont réexécutables. Le script 3 remplace proprement la
procédure `generer_reference`.

## Modèle du personnel

- `pers` contient la fiche RH de référence.
- `direction` contient le rattachement organisationnel d’une personne.
- `acces` contient uniquement l’identifiant, le hash du mot de passe et la
  période de validité du compte.
- `personnel_validation` décrit la chaîne de validation existante.
- `profils_acces` et `acces_profil` portent les droits applicatifs sans changer
  les quatre tables proposées par la superviseure.

Les tailles des champs `matricule` ont été harmonisées à 60 caractères pour
permettre de vraies clés étrangères. `Date_Fin` accepte `NULL` (compte sans date
de fin) : la valeur historique `0000-00-00 00:00:00` est rejetée par MySQL 8 en
mode strict. Le jeu de caractères est `utf8mb4`, mieux adapté aux accents que
`latin1`.

Le champ `acces.passe` doit toujours recevoir un hash Argon2id ou bcrypt, jamais
un mot de passe en clair.

## Références FA et NC

La procédure génère une référence atomique par type, processus et année :

```sql
START TRANSACTION;
CALL generer_reference('FA', 10, '2026-09-07', @reference);
SELECT @reference; -- par exemple FA-SI-001-26
-- INSERT INTO actions (..., reference, ...) VALUES (..., @reference, ...);
COMMIT;
```

Pour éviter un numéro consommé sans document, l’appel et l’insertion doivent
être faits sur la même connexion et dans la même transaction.

## Correspondance avec l’ancien diagramme

| Ancien élément | Nouveau lien |
| --- | --- |
| `utilisateurs` | remplacé par `pers` + `acces` + `acces_profil` |
| `responsable_id` | devient `responsable_matricule` vers `pers` |
| `validateur_id` | devient `validateur_matricule` vers `pers` |
| relation NC/action | table de liaison `nc_action` |
| listes Excel | tables de référentiel préchargées par le script 002 |

Les vues `v_actions_en_retard` et `v_nc_en_retard` sont directement utilisables
pour le tableau de bord et les notifications d’échéance.
