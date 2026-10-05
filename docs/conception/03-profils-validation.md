# Profils, permissions et validation

## Constats et orientations

Le [script actuel](../../database/002_seed_referentiels.sql) définit
`ADMINISTRATEUR`, `PILOTE_PROCESSUS`, `RESPONSABLE` et `CONSULTATION`.
Les trois profils désormais envisagés sont utilisateur simple, responsable et
super administrateur. D05 doit définir leur correspondance avant toute
modification des codes ou affectations.

Le modèle `acces_profil` autorise plusieurs profils pour un compte. Ce constat
ne confirme pas que le cumul doit être autorisé dans l'application.
`processus_responsable` décrit une affectation à un processus ; il ne prouve
pas qu'un responsable de dossier dispose de tous les droits sur ce processus.

## Matrice de travail — D05/D06

Les indications ci-dessous sont des **propositions**, pas des permissions
approuvées. « À confirmer » signifie que le droit n'est pas présumé accordé.

| Opération | Utilisateur simple | Responsable | Super administrateur |
| --- | --- | --- | --- |
| Se connecter et se déconnecter | Oui, compte valide | Oui, compte valide | Oui, compte valide |
| Créer une NC | Proposé | Proposé | À confirmer |
| Consulter une NC | Ses déclarations, proposé | Dossiers affectés, proposé | Visibilité métier à confirmer |
| Voir les dossiers d'un service/processus | À confirmer | À confirmer | À confirmer |
| Modifier la déclaration après création | À confirmer : champs et période | À confirmer : périmètre et champs | À confirmer |
| Renseigner le traitement et les résultats | À confirmer | Dossiers affectés, proposé | À confirmer |
| Choisir/changer le responsable ou l'échéance | À confirmer | À confirmer | À confirmer |
| Demander une vérification/clôture | À confirmer | Proposé | À confirmer |
| Valider à un niveau | Seulement si habilité selon D07 à D10 | Seulement si habilité selon D07 à D10 | Aucun droit automatique |
| Clôturer, ajourner, annuler, rouvrir | À confirmer | À confirmer | À confirmer |
| Administrer les référentiels | Aucun droit proposé | Aucun droit proposé | Proposé selon D14 |
| Administrer comptes, profils et affectations | Aucun droit proposé | Aucun droit proposé | Proposé selon source RH et D14 |
| Consulter l'audit | Historique de ses dossiers, proposé | Historique de son périmètre, proposé | Périmètre d'audit à confirmer |

Le contrôle d'une permission porte sur le compte, l'opération, le dossier et
son état. La visibilité des boutons suit cette décision ; l'application doit
aussi vérifier l'autorisation lors de l'opération elle-même.

## Distinctions à conserver

- **Profil applicatif** : ensemble de fonctions accessibles.
- **Périmètre** : dossiers sur lesquels ces fonctions peuvent être exercées.
- **Responsabilité** : affectation à un dossier ou à un processus.
- **Habilitation de validation** : autorisation d'intervenir à une étape et un niveau donnés.

Ces notions ne doivent pas être déduites automatiquement les unes des autres.
Le cumul de profils, la priorité entre droits et les exceptions doivent être
explicités dans D05/D06.

## Ce que le schéma permet réellement

`personnel_validation` contient une personne concernée (`matricule`), un
validateur (`mat_val`) et un niveau (`niv_val`, `VARCHAR(20)`). Il n'existe ni
contrainte limitant les niveaux à 1/2/3, ni contrainte d'unicité du triplet,
ni lien vers une NC/action, ni décision datée sur un dossier.

La table `validations` contient des décisions pour `demandeurs`, avec motif,
validateur et date. Elle doit rester distinguée de la validation des dossiers
qualité. Aucune réutilisation directe n'est proposée.

## Circuit de validation proposé — D07 à D10

1. Identifier l'objet et l'étape à valider : déclaration, traitement ou clôture.
2. Déterminer le circuit applicable et les personnes habilitées.
3. Présenter au validateur le dossier et les éléments à examiner.
4. Enregistrer une décision datée, attribuée et motivée lorsque nécessaire.
5. Poursuivre le circuit si les conditions du niveau suivant sont réunies.
6. En cas de correction, conserver la décision et appliquer la règle de retour
   et de revalidation choisie par la responsable.

Exemple à discuter : niveau 1, puis niveau 2, puis niveau 3. Cet exemple n'est
pas le circuit retenu. Les variantes parallèle, conditionnelle, à un seul
niveau ou sans validation à l'enregistrement restent possibles.

Les décisions possibles proposées sont « approuver », « demander une
correction » et « refuser ». Leur liste définitive et leurs effets dépendent
de D07/D11. Elles ne sont pas des statuts de NC.

## Stockage conceptuel complémentaire, sans migration

Si le circuit est retenu, concevoir les informations suivantes avant de créer
des tables. Les noms ci-dessous désignent des concepts, pas des tables existantes.

| Concept | Informations à conserver |
| --- | --- |
| Instance de validation | Dossier concerné, étape métier, circuit applicable, date de lancement et état du circuit. |
| Étape du circuit | Niveau, ordre ou dépendances si applicables, validateur(s) désigné(s), état de l'étape. |
| Décision | Étape, acteur effectif, décision, motif, date et contenu/version du dossier examiné. |
| Retour et nouvelle présentation | Motif de correction, rattachement au cycle précédent, éléments modifiés et nouvelle décision. |

Une instance est rattachée à un dossier et comporte les étapes nécessaires.
Une étape possède un historique de décisions ; une correction ne doit pas
écraser la décision précédente. Le détail des cardinalités dépend du nombre de
validateurs par niveau et des délégations retenues.

Les futures références au personnel doivent conserver `VARCHAR(60)` et la
collation des matricules ; les liens vers une NC/action doivent respecter
leurs identifiants `INT UNSIGNED`. Si `niv_val` est repris, conserver sa taille
`VARCHAR(20)` ; ne pas le convertir silencieusement en entier. L'ordre du
circuit, s'il existe, est une règle distincte du libellé du niveau.

## Cas à trancher avant activation

| Cas | Décision |
| --- | --- |
| Une même personne est déclarant et validateur | D10 : autoriser ou interdire l'auto-validation. |
| Une personne peut intervenir à plusieurs niveaux | D09 : distinguer habilitation générale et cumul sur le même dossier. |
| Plusieurs validateurs sont affectés au même niveau | D08 : un seul suffit, tous sont requis ou règle alternative. |
| Aucun validateur n'est disponible | D08/D10 : attente, délégation ou réaffectation ; aucune approbation implicite. |
| Le dossier change après approbation | D10 : déterminer quelles décisions restent valides et ce qui doit être réexaminé. |
| La configuration du circuit change en cours de dossier | D08/D10 : conserver le circuit lancé ou appliquer une nouvelle configuration avec trace. |

Tant que ces règles ne sont pas fixées, la première version ne doit pas afficher
un dossier « validé » à partir de sa seule affectation dans `personnel_validation`.
