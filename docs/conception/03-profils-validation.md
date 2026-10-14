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

`personnel_validation` est conservée dans le modèle RH. Aucun circuit de
validation de dossier n'est défini dans ce premier parcours.
