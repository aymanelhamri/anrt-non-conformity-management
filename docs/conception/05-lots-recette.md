# Recette du premier parcours

Ces vérifications concernent uniquement connexion, formulaire et confirmation.
Elles ne définissent pas le contenu des jalons officiels. D01/D02 et les règles
de saisie doivent être confirmées avec la superviseure.

Préparer une base de recette dédiée, des comptes autorisés et non autorisés,
un compte expiré et un compte à début futur, ainsi que les référentiels et
responsables nécessaires au formulaire.

| Situation | Résultat attendu |
| --- | --- |
| Connexion valide, puis déconnexion | Accès au formulaire ; session inutilisable après déconnexion. |
| Mauvais mot de passe ou compte hors validité | Aucun accès au formulaire. |
| Compte sans droit de création, y compris POST direct | Enregistrement refusé. |
| Saisie valide | Une NC, une référence et un audit technique ; confirmation de la référence. |
| Champ requis vide, valeur trop longue ou date invalide | Erreur explicite, sans troncature ; saisie conservée. |
| Référentiel inexistant/inactif ou responsable interdit | Enregistrement refusé sans création partielle. |
| Modification de la référence, du déclarant ou du statut dans la requête | Valeurs protégées refusées. |
| Échec de création ou d'audit avant le commit | Transaction annulée ; aucun succès affiché. |
| Double envoi ou réponse perdue, même formulaire et même contenu | Résultat retrouvé sans nouvelle NC. |
| Même formulaire réutilisé avec un contenu différent après succès | Refus ; ouverture d'une nouvelle déclaration nécessaire. |
| Confirmation demandée sans création liée à la session | Aucune référence d'un autre dossier exposée. |
| Nouvelle déclaration | Nouveau formulaire distinct du précédent. |

Le contrôle PHP/HTTP avec un PDO instrumenté vérifie le comportement du code,
sans prouver l'atomicité MySQL. La recette sur MySQL doit aussi vérifier
contraintes, procédure, rollback, références simultanées et plafond du compteur.
Le navigateur doit confirmer le rendu, les messages et le comportement du
bouton d'enregistrement. Les [résultats exécutés](../verification.md) restent
distincts de cette liste de vérifications attendues.
