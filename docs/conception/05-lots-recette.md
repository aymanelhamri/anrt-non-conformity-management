# Lots de développement et critères de recette

## Règle de planification

Les lots A/B/C organisent le travail proposé ; ils ne fixent pas les jalons
officiels. D01 détermine l'objet du premier parcours et D02 le contenu du
deuxième jalon. Tous les critères ci-dessous sont **à vérifier** : aucune
application n'est encore implémentée et aucun test d'exécution SQL n'a été
réalisé dans cette conception.

## Lot A proposé : enregistrer et consulter une NC

Objectif : démontrer un parcours complet depuis la connexion jusqu'à une NC
enregistrée, référencée et consultable avec un audit de création.

| Fonction | Livraison attendue | Écrans |
| --- | --- | --- |
| F01 | Connexion, validité du compte, déconnexion et contrôle des accès. | E01 |
| F02 | Formulaire NC et contrôles cohérents avec le SQL. | E03 |
| F03 | Référence unique et création transactionnelle. | E03/E04 |
| F04 | Liste, filtres, pagination et fiche limitée au périmètre autorisé. | E02/E04 |
| F05 | Événement d'audit de création et lecture de cet historique selon droits. | E04 |

Préparer des comptes, affectations et référentiels de recette suffit au lot A,
si D02/D14 le permettent ; cela ne livre pas une administration complète.

Conditions d'entrée : D01 à D06 fixées pour les fonctions du lot, format et
réessais de D13 définis, données et identifiants de D14 disponibles. D07 doit
préciser si une validation est obligatoire à l'enregistrement. Si oui, ajouter
F07 et les décisions D08 à D10 au lot A avant de le considérer complet.

Le lot A ne propose ni modification après création, ni clôture, ni brouillon
persistant, ni gestion d'action, ni notification automatique. Ces fonctions
ne doivent pas apparaître comme opérationnelles dans les écrans de recette.

## Lot B proposé : traitement et validation

- F06 : mise à jour du traitement, affectation, échéance, résultats et transitions.
- F07 : circuit retenu, décisions, retours pour correction et historique par dossier.
- Extension F05 : audit des modifications et transitions.
- Clôture selon D11/D12, avec stratégie de concurrence pour les modifications.

Si une action liée est obligatoire pour clôturer certaines NC, le parcours de
ces NC dépend du lot actions. Il ne faut pas contourner cette condition pour
présenter le lot B comme complet.

## Lot C proposé : actions et pilotage

- F08 : actions d'amélioration/correctives et associations `nc_action`.
- F10 : retards, indicateurs et notifications selon règles confirmées.
- Extension F05 : audit des actions et associations.

F09, l'administration, se place dans le jalon choisi par D02. Si elle est
prioritaire, son lot comprend explicitement les listes et opérations retenues,
les comptes RH concernés et les permissions. Le formulaire NC est alors un
livrable distinct, sans prétendre qu'il fait partie de ce jalon.

## Préparation de la recette

Prévoir, dans un environnement dédié, des comptes de chaque profil retenu,
un compte expiré, un compte à début futur, deux personnes distinctes, un
référentiel actif et un inactif, ainsi que des dossiers dans plusieurs
périmètres. Les niveaux de validation utilisés pour les lots suivants sont
ceux confirmés ; les exemples 1/2/3 ne deviennent pas des données imposées.

## Critères du lot A

| ID | Fonction / règle | Situation et action | Résultat attendu |
| --- | --- | --- | --- |
| CA01 | F01 / R01 | Connexion d'un compte valide puis déconnexion. | Accès au périmètre autorisé ; session inutilisable après déconnexion. |
| CA02 | F01 / R01 | Identifiants incorrects, fin de validité dépassée ou début futur. | Aucune session autorisée ; message adapté. |
| CA03 | F02/F03 / R02/R06/R07 | Enregistrer une saisie valide. | Une NC `OUVERTE`, une référence unique, les données attendues et un audit de création. |
| CA04 | F02 / R02/R04 | Omettre chaque champ obligatoire ; essayer un texte vide ou composé d'espaces. | Refus explicite ; aucune NC ni consommation de compteur par une saisie rejetée avant transaction. |
| CA05 | F02 / R02 | Tester une valeur à la longueur autorisée puis au-delà, notamment un matricule de plus de 60 caractères dans la requête. | Valeur conforme traitée selon ses autres contraintes ; dépassement refusé sans troncature. |
| CA06 | F02 / R02/R03 | Envoyer un identifiant inexistant ou sélectionner une valeur devenue inactive pendant la saisie. | Refus et demande de correction ; pas d'insertion incohérente. |
| CA07 | F02 / R05 | Saisir une échéance avant la création, égale puis après ; tester l'absence. | Refus de la première ; acceptation des autres ; absence selon D04. |
| CA08 | F02 / R06 | Modifier dans la requête le déclarant, la référence ou le statut initial. | Les valeurs protégées ne sont pas acceptées comme données utilisateur. |
| CA09 | F03 / R07 | Deux créations simultanées dans le même compteur. | Deux NC avec références distinctes et audit correspondant à chacune. |
| CA10 | F03 / R07 | Provoquer un échec confirmé avant validation de la transaction. | Ni NC partielle ni audit de succès ; compteur annulé avec la transaction. |
| CA11 | F03 / R08 | Double clic, répétition de requête et réponse perdue après création. | Comportement conforme à D13 ; aucune recréation aveugle du même enregistrement. |
| CA12 | F04 / R01 | Consulter liste/fiche puis demander directement un dossier hors périmètre. | Seuls les dossiers autorisés sont exposés ; accès direct non autorisé refusé. |
| CA13 | F04 | Filtrer puis paginer, y compris une liste sans résultats. | Résultats cohérents, aucun dossier hors périmètre, état vide compréhensible. |
| CA14 | F05 / R07 | Consulter une création réussie. | Auteur, date, identifiant, valeurs initiales et référence retrouvables selon droits. |
| CA15 | F02 | Corriger une erreur de saisie ou reprendre après échec confirmé. | Champs déjà saisis conservés à l'écran ; succès affiché seulement après création réussie. |
| CA16 | F03 / D13 | Tester les numéros 999 et suivant dans un compteur de recette. | Format ou limite explicite sans collision ; ne pas accepter le comportement actuel sur la seule présence de `LPAD`. |

## Critères des lots suivants

Ces critères ne sont activés qu'après confirmation de leurs décisions et
inclusion des fonctions dans le lot concerné.

| ID | Fonction | Vérification attendue | Décisions |
| --- | --- | --- | --- |
| CA17 | F06/F05 | Une modification autorisée conserve valeurs avant/après, acteur et date ; une modification concurrente ne les écrase pas silencieusement. | D06 |
| CA18 | F06 | Les transitions autorisées passent ; les autres sont refusées. Ajournement/reprise/annulation ont les motifs retenus. | D06/D11 |
| CA19 | F07 | Seul le validateur habilité décide ; l'ordre ou les dépendances du circuit sont respectés. | D07/D08 |
| CA20 | F07 | Cumul des niveaux et auto-validation sont autorisés/refusés exactement selon la règle retenue. | D09/D10 |
| CA21 | F07 | Une demande de correction conserve la décision précédente ; la nouvelle présentation applique les règles de revalidation. | D10 |
| CA22 | F06/F07 | La clôture est bloquée tant que ses conditions ne sont pas remplies ; une clôture autorisée renseigne la date et l'audit. | D11/D12 |
| CA23 | F08 | Les associations NC/action sont cohérentes, sans doublon, et une action indépendante est permise seulement selon le périmètre retenu. | D01/D12 |
| CA24 | F09 | Seuls les administrateurs habilités modifient les données retenues ; la désactivation d'un référentiel conserve les anciens dossiers lisibles. | D02/D05/D14 |
| CA25 | F10 | Les retards correspondent à l'échéance passée et aux statuts non finaux ; notifications seulement aux destinataires et canaux retenus. | D14 |

## Définition d'un lot terminé

Un lot est terminé quand ses décisions dépendantes sont consignées, ses écrans
et règles incluses sont opérationnels, ses critères ont été exécutés avec des
résultats consignés et ses anomalies bloquantes sont résolues. La documentation
du fonctionnement livré doit alors refléter ce qui a effectivement été réalisé.

Une revue de documents ou une table SQL présente dans le dépôt ne vaut pas
recette d'une fonctionnalité.
