# Registre des décisions à confirmer

Toutes les décisions ci-dessous sont **ouvertes**. Les pistes ne sont pas des
règles approuvées. La responsable précise la règle retenue, la date et, lorsque
nécessaire, les exceptions.

| ID | Question à trancher | Piste de travail / impact | Réponse / date |
| --- | --- | --- | --- |
| D01 | Le premier processus concerne-t-il une NC, une action indépendante ou les deux ? | La conception commence par une NC ; une autre réponse change le premier formulaire et le lot A. | À compléter |
| D02 | Quel est le périmètre du deuxième jalon : administration des référentiels/utilisateurs, enregistrement ou combinaison limitée ? | Préparer un parcours NC utilisable est proposé ; la numérotation officielle des jalons reste ouverte. | À compléter |
| D03 | Qui renseigne le responsable et le traitement, à quel moment ? « Traitement » désigne-t-il une proposition ou un résultat réalisé ? | Ces champs sont obligatoires dans le SQL. Une déclaration sans eux exige une revue du modèle ; aucune valeur fictive n'est prévue. | À compléter |
| D04 | L'échéance est-elle obligatoire ? La date de création peut-elle être rétroactive ? Quand le besoin d'action corrective est-il décidé ? | La base autorise une échéance nulle. Une valeur `FALSE` par défaut ne constitue pas une décision métier examinée. | À compléter |
| D05 | Comment les trois profils envisagés correspondent-ils aux quatre profils existants ? Les profils peuvent-ils se cumuler ? | Aucun renommage ni regroupement automatique ; distinguer pilote de processus et responsable de dossier. | À compléter |
| D06 | Qui peut voir, modifier, affecter, ajourner, annuler et clôturer un dossier ? Quel périmètre : ses dossiers, service, processus ou ensemble ? | La matrice de permissions est une proposition. Les droits techniques d'administration ne donnent pas automatiquement des droits de validation. | À compléter |
| D07 | Que valide-t-on : déclaration, affectation, traitement, clôture ou plusieurs étapes ? La validation est-elle obligatoire dès le premier lot ? | Un suivi de validation par dossier est nécessaire si le circuit est retenu ; la table `validations` existante ne convient pas à cet usage. | À compléter |
| D08 | Les niveaux 1/2/3 sont-ils successifs, parallèles, conditionnels ? Qui choisit les validateurs et que faire si un niveau est absent ? | Ne pas déduire l'ordre de `niv_val`, qui est une chaîne libre. Définir les cas sans validateur avant d'activer le circuit. | À compléter |
| D09 | « Plusieurs niveaux pour un collaborateur » signifie-t-il plusieurs niveaux à obtenir ou plusieurs niveaux qu'il peut valider ? La même personne peut-elle intervenir à plusieurs niveaux du même dossier ? | Le modèle permet plusieurs lignes, sans confirmer leur sens ni le cumul des habilitations. | À compléter |
| D10 | Peut-on valider son propre dossier ? Existe-t-il une délégation ? Quel effet a une correction après une décision ? | Définir indépendance, remplacements et conservation/invalidation des décisions antérieures. | À compléter |
| D11 | Quelles conditions autorisent la clôture ? Qui annule ou ajourne ? Une réouverture est-elle possible et qui peut la demander ? | Les statuts existent, les transitions et justificatifs ne sont pas imposés par la base. | À compléter |
| D12 | Une NC peut-elle être clôturée avec une action ouverte ? Une action peut-elle être indépendante ou commune à plusieurs NC ? | La relation plusieurs-à-plusieurs existe ; les conséquences métier restent ouvertes. | À compléter |
| D13 | Le numéro doit-il dépasser 999 par type/processus/année ? Quel comportement en cas de double clic, de réessai ou de modification du processus ? | `LPAD(..., 3, '0')` n'assure pas une numérotation croissante au-delà de trois chiffres. Définir le format, les limites et la stabilité de la référence. | À compléter |
| D14 | D'où proviennent personnel/comptes/affectations ? Quels éléments sont administrés ici ? Quels événements sont notifiés, à qui et par quel canal ? Qui consulte l'audit et combien de temps est-il conservé ? | Réutiliser la structure RH ; ne pas inventer un import, des comptes ou un envoi d'e-mails déjà disponibles. | À compléter |

## Validation de la conception

| Élément | État actuel | Confirmation attendue |
| --- | --- | --- |
| Premier processus et jalon | Proposition | D01, D02 |
| Formulaire initial | Conforme aux colonnes existantes, règles métier proposées | D03, D04 |
| Profils et accès | Matrice proposée | D05, D06 |
| Circuit de validation | Conception conceptuelle | D07 à D10 |
| Fin de vie du dossier et actions | Parcours proposé | D11, D12 |
| Références et exploitation | Points techniques à vérifier et choix métier | D13, D14 |

La création de ces documents ne vaut pas approbation de leur contenu. Pour
préparer un lot, seules ses décisions dépendantes doivent être résolues ; les
fonctionnalités des lots ultérieurs ne sont pas simulées dans le premier lot.
