# Accès au formulaire

L'application réutilise `acces` et `acces_profil`, liés à `pers` par le matricule.
Elle vérifie le mot de passe et les dates de validité du compte.

Le SQL existant contient quatre codes de profils : `ADMINISTRATEUR`,
`PILOTE_PROCESSUS`, `RESPONSABLE` et `CONSULTATION`. Leur présence ne définit
pas à elle seule les droits du formulaire. Les profils autorisés à créer et
les personnes pouvant être choisies comme responsables restent à confirmer.

| Opération du parcours | Contrôle nécessaire |
| --- | --- |
| Ouvrir et envoyer le formulaire | Compte valide et droit de création configuré. |
| Choisir un responsable | Personnel existant dans le périmètre d'affectation configuré. |
| Afficher la confirmation | Référence de la déclaration créée dans cette session pour ce compte. |
| Se déconnecter | Invalidation de la session. |

Les contrôles sont exécutés sur le serveur. Sans permission configurée,
la création est refusée. Le mode de démonstration fournit des droits d'essai
explicites ; ils ne constituent pas une matrice métier approuvée.

L'extension du 28 octobre ajoute la consultation, la modification avant clôture
et la validation par les personnes désignées dans `personnel_validation`.
Voir les périmètres et limites dans le [guide de suivi](../suivi-non-conformites.md).
Les niveaux RH sont affichés ; aucun ordre de validation entre niveaux n'est
imposé sans règle métier confirmée.
