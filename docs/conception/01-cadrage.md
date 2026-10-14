# Besoins et périmètre du formulaire

## Objectif et existant

Le projet de stage vise à digitaliser la gestion des non-conformités et des
actions d'amélioration de l'ANRT. Le premier parcours de travail retenu est
l'enregistrement d'une non-conformité. L'administration était une autre option
proposée par la superviseure ; le choix et le jalon doivent être confirmés.

Le dépôt contient un schéma MySQL et des référentiels issus du fichier Excel.
Les tables `pers`, `direction`, `acces` et `personnel_validation` réutilisent
la structure RH, avec le matricule comme identifiant commun. Le
[modèle expliqué](../modele-donnees.md) présente ces relations.
La présence des scripts ne prouve ni leur installation ni l'import de données.

## Besoins et fonctions

| Besoin | Fonction du premier parcours |
| --- | --- |
| Attribuer la déclaration à une personne | Se connecter avec un compte valide. |
| Enregistrer un constat de non-conformité | Saisir qualification, description et données requises par le schéma. |
| Corriger une saisie refusée | Afficher les erreurs et conserver les champs du formulaire. |
| Identifier l'enregistrement | Générer une référence et confirmer la réussite après création. |
| Éviter un double enregistrement lors d'un réessai | Retrouver le résultat du même formulaire déjà enregistré. |
| Terminer ou recommencer | Se déconnecter ou ouvrir une nouvelle déclaration. |

L'audit de création reste une trace technique en base. La confirmation affiche
uniquement la référence de la déclaration tout juste créée dans la session.

## Points à confirmer

Le responsable et le traitement sont obligatoires dans le SQL : leur sens et
leur auteur doivent être précisés avant d'en faire une règle métier approuvée.
L'échéance est nullable dans le schéma ; son obligation reste à décider.
Les profils autorisés et le choix du responsable restent également à confirmer.
Voir les [questions du formulaire](06-decisions.md).

Le projet global mentionne aussi les actions, les validations, les notifications
et le pilotage. Leur processus et leur mise en œuvre ne sont pas définis ici.
