# ANRT — gestion des non-conformités

Application web développée dans le cadre d’un stage à l’ANRT pour digitaliser
la gestion des non-conformités, des actions d’amélioration et leur suivi.

Le projet centralise les non-conformités, les processus, les types, les natures
de service, les responsables, les échéances et les actions correctives. Il doit
également couvrir les validations, les notifications, les tableaux de bord et
la traçabilité des opérations.

## Base de données

Le schéma MySQL initial est disponible dans le dossier
[database](database/README.md). Il intègre les tables du personnel proposées
par la superviseure et les référentiels extraits du fichier Excel existant.

Une explication fonctionnelle et le diagramme des relations se trouvent dans
[docs/modele-donnees.md](docs/modele-donnees.md).

## Conception avant développement

Le [dossier de conception](docs/conception/README.md) décrit les besoins, le
parcours d'une non-conformité, les profils et validations, les écrans, les
données et les critères de recette. Il distingue les constats du dépôt des
propositions et des décisions à confirmer par la responsable.

Le premier parcours proposé est l'enregistrement et la consultation d'une
non-conformité. Ce choix et le périmètre du deuxième jalon restent à confirmer.
Le dépôt comprend désormais une première implémentation Python/Flask du parcours
connexion → liste → création → confirmation et fiche. Les scripts SQL initiaux
restent inchangés. Les droits métier sont refusés par défaut ; une démonstration
explicitement activée permet d'exercer les propositions en attente de validation.

L'[installation et le fonctionnement livré](docs/implementation.md) décrivent
l'arborescence, la configuration MySQL, les permissions, le lancement sous
PowerShell, la recette et les décisions restantes. Les
[résultats de vérification](docs/verification.md) distinguent les tests exécutés
des contrôles MySQL restant à effectuer.

## Comprendre le projet rapidement

Le projet actuel utilise **Python/Flask et PyMySQL**. Il ne contient pas de
code PHP, de PDO ni de `composer.json`.

| Étape | Où regarder |
| --- | --- |
| 1. Point d'entrée | `wsgi.py` appelle `create_app()` dans `anrt/__init__.py`. |
| 2. Base de données | `database/001_schema.sql` est la référence ; `anrt/db.py` ouvre MySQL. |
| 3. Authentification | `anrt/auth.py` vérifie le matricule, le hash et les dates de validité ; `anrt/sessions.py` gère la session. |
| 4. Permissions | `anrt/permissions.py` contrôle les opérations et les périmètres ; aucun droit métier par défaut. |
| 5. Liste des NC | `nc_list()` dans `anrt/routes.py` appelle `list_nc()` dans `anrt/repository.py`, puis `list.html`. |
| 6. Création NC | `nc_new()` dans `anrt/routes.py` reçoit le formulaire ; `anrt/validation.py` contrôle les champs. |
| 7. Transaction | `create_nc()` dans `anrt/service.py` utilise une connexion pour référence, NC et audit. |
| 8. Audit | Le même `create_nc()` insère dans `journal_audit` avant le commit. |
| 9. Fiche détaillée | `nc_detail()` appelle `detail_nc()` dans `anrt/repository.py`, puis `detail.html`. |

Pour suivre le parcours en cinq minutes : [guide stagiaire](docs/guide-stagiaire.md).
Les constats, corrections et limites figurent dans la [revue du projet](docs/revue.md).
