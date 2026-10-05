# Cadrage, besoins et existant

## Objectif

Centraliser les non-conformités et les actions d'amélioration de l'ANRT,
identifier les responsabilités, suivre les échéances et conserver la trace
des opérations. Le projet est réalisé dans le cadre d'un stage.

## État constaté

L'examen porte sur les fichiers du dépôt. Il ne prouve ni l'installation d'une
base MySQL, ni l'exécution réussie des scripts, ni l'import de données réelles.

| Source | Ce qui existe |
| --- | --- |
| [Présentation](../../README.md) | Objectif général et fonctionnalités attendues. |
| [Schéma](../../database/001_schema.sql) | Base `anrt_qualite`, MySQL 8.0+, InnoDB, `utf8mb4`, tables, clés étrangères, index et contraintes de dates. |
| [Référentiels](../../database/002_seed_referentiels.sql) | Instructions de chargement de 12 processus, 10 origines d'action, 8 types de NC, 16 natures de service, statuts et évaluations. |
| [Procédure](../../database/003_procedures.sql) | Génération de références FA/NC par processus et année, à utiliser avec l'insertion dans la même transaction. |
| [Vues](../../database/004_views.sql) | Personnel et dossiers en retard dont le statut n'est pas final. |
| [Documentation SQL](../../database/README.md) | Ordre d'installation et usage des tables RH et des références. |
| [Modèle expliqué](../modele-donnees.md) | Réutilisation des tables du personnel ; selon cette documentation, aucun historique métier importé depuis l'Excel fourni. |

Le premier parcours connexion, enregistrement et consultation est désormais
implémenté en Python/Flask, avec permissions côté serveur et audit de création.
Voir le [fonctionnement livré](../implementation.md) et les
[vérifications exécutées](../verification.md). Les décisions métier restent
ouvertes. Les notifications, transitions et validations métier ne sont pas
implémentées.

## Modèle de données disponible

| Ensemble | Tables | Fonction du modèle |
| --- | --- | --- |
| Personnel | `pers`, `direction`, `acces` | Identité, organisation et compte, avec le matricule comme identifiant commun. |
| Profils | `profils_acces`, `acces_profil` | Affectation d'un ou plusieurs profils à un compte ; aucune permission détaillée stockée. |
| Affectations | `processus_responsable`, `personnel_validation` | Responsables de processus et relations collaborateur/validateur/niveau. |
| Référentiels | `processus`, `origines_action`, `types_nc`, `natures_service`, `statuts`, `evaluations_efficacite`, `verifications_traitement` | Listes de qualification et de suivi. |
| Métier | `actions`, `non_conformites`, `nc_action` | Actions et NC ; association plusieurs-à-plusieurs. |
| Demandes d'accès | `demandeurs`, `validations` | Demandes et décisions acceptées/refusées/en attente. Ces décisions ne portent pas sur les NC. |
| Suivi | `notifications`, `journal_audit`, `compteurs_reference` | Stockage prévu pour notifications, historique et numérotation. |

## Orientations connues

- La responsable demande de définir les besoins et leur enchaînement avant le développement.
- Les types et longueurs doivent rester cohérents avec la base existante.
- Trois profils sont envisagés : utilisateur simple, responsable, super administrateur.
- Les validateurs peuvent avoir des niveaux, par exemple 1, 2 et 3 ; le cumul reste à confirmer.
- Le premier objet métier et le périmètre du deuxième jalon ne sont pas encore décidés.

## Besoins et fonctions proposés

| Besoin | Résultat attendu | Fonction associée | Lot proposé |
| --- | --- | --- | --- |
| B01 — Accéder avec son identité | Chaque opération est attribuée au compte connecté. | F01 — Connexion et contrôle de validité du compte. | A |
| B02 — Déclarer un problème | Une NC est enregistrée avec sa qualification. | F02 — Formulaire et contrôles de saisie. | A |
| B03 — Identifier un dossier | Une référence unique permet de le retrouver. | F03 — Génération transactionnelle de référence. | A |
| B04 — Consulter selon ses droits | L'utilisateur voit uniquement son périmètre autorisé. | F04 — Liste, filtres et fiche. | A |
| B05 — Conserver l'historique | Les opérations et leurs auteurs sont consultables. | F05 — Audit de création, puis des opérations des lots suivants. | A puis B/C |
| B06 — Organiser et suivre le traitement | Le responsable renseigne la prise en charge et les résultats. | F06 — Traitement, affectation et transitions autorisées. | B |
| B07 — Contrôler les décisions | Les validations sont exécutées selon un circuit explicite. | F07 — Circuit et historique de validation par dossier. | B, ou A si obligatoire à l'enregistrement |
| B08 — Traiter les causes | Une action corrective peut être associée à une NC. | F08 — Gestion des actions et des liens NC/action. | C |
| B09 — Maintenir les données de fonctionnement | Les listes, comptes et affectations restent utilisables. | F09 — Administration selon la source RH retenue. | Selon D02/D14 |
| B10 — Piloter les échéances | Les retards sont visibles et les destinataires sont informés. | F10 — Suivi des retards, notifications et tableau de bord. | C |

Les lots A/B/C sont une proposition d'ordre de développement. Ils ne désignent
pas automatiquement les jalons demandés par la responsable.

## Points de cohérence à résoudre

- Les quatre profils préchargés diffèrent des trois profils envisagés (D05).
- Le traitement et le responsable sont obligatoires à l'insertion d'une NC (D03).
- Les niveaux ne sont pas limités à 1/2/3 par le schéma ; plusieurs affectations et doublons sont techniquement possibles (D08/D09).
- Il manque un suivi des décisions de validation des NC et actions par dossier (D07).
- Les contraintes SQL ne contrôlent pas les habilitations, les transitions ou les conditions de clôture (D06/D11).
- Les demandes d'accès ne créent pas automatiquement de personne ou de compte ; aucun mécanisme de provisioning n'est implémenté (D14).
