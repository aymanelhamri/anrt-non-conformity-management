# Conception de l'application ANRT Qualité

Version de travail du 5 octobre 2026, à examiner avec la responsable avant le
développement. Ce dossier est une spécification proposée, pas une validation
des règles métier ni la description d'une application déjà opérationnelle.

## Lecture et livrables

| Document | Objet |
| --- | --- |
| [01 — Cadrage et existant](01-cadrage.md) | Objectif, besoins, périmètre et capacités réellement présentes. |
| [02 — Processus de non-conformité](02-processus-non-conformite.md) | Acteurs, étapes, exceptions, statuts et traçabilité. |
| [03 — Profils et validation](03-profils-validation.md) | Matrice de permissions et conception du circuit de validation. |
| [04 — Écrans et données](04-ecrans-donnees.md) | Navigation, contenu des écrans, types SQL et responsabilités techniques. |
| [05 — Lots et recette](05-lots-recette.md) | Premier développement proposé et critères d'acceptation. |
| [06 — Décisions à confirmer](06-decisions.md) | Questions, impacts et réponses attendues de la responsable. |

## Convention de lecture

- **Constat** : information présente dans les fichiers du dépôt.
- **Orientation connue** : information communiquée par le porteur du projet.
- **Proposition** : solution de travail qui doit être examinée.
- **À confirmer** : règle non établie, identifiée par une décision `Dxx`.

Les identifiants `Bxx` désignent les besoins, `Fxx` les fonctions, `Rxx` les
règles proposées et `CAxx` les critères de recette. Les références entre
documents permettent de relier une fonction à son besoin et à sa vérification.

## Parcours retenu pour la conception

**Proposition — D01 :** commencer par enregistrer et consulter une non-conformité
(NC), puis étendre le parcours à son traitement, à sa vérification et à sa
clôture. Les actions restent des objets distincts pouvant être associés aux NC.
Le choix NC/action/les deux reste ouvert.

Le traitement et le responsable sont obligatoires dans le schéma actuel. Un
parcours de déclaration sans ces informations dépend d'une décision métier
(D03), puis d'une adaptation du modèle explicitement revue si elle est nécessaire.

Les scripts SQL existants restent la référence pour les types et longueurs.
Ils ne sont ni modifiés ni exécutés par cette conception. Les besoins de stockage
supplémentaires, notamment pour les validations métier, sont identifiés sans
introduire de migration anticipée.

## Préparation de la revue

1. Choisir le premier objet métier et le périmètre du deuxième jalon (D01, D02).
2. Définir les données disponibles dès l'enregistrement (D03, D04).
3. Fixer les permissions et les règles de validation (D05 à D10).
4. Définir les conditions de clôture et le lien avec les actions (D11, D12).
5. Compléter les décisions de référence et de préparation des données (D13, D14).

Les réponses peuvent être consignées dans la colonne « Réponse / date » du
[registre des décisions](06-decisions.md). Aucune case vide ne vaut accord.
