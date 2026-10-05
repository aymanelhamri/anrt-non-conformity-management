# Vérifications du 5 octobre 2026

Environnement : Windows, Python 3.12.10, environnement virtuel `.venv`.
Les dépendances ont été installées depuis PyPI dans cet environnement.
Les versions exactes sont dans `requirements-lock.txt`.

| Commande / constat | Résultat réellement obtenu |
| --- | --- |
| `python -m pytest -q` | 54 tests locaux réussis, 13 cas MySQL ignorés explicitement ; 5,08 secondes. |
| `python -m compileall -q anrt wsgi.py` | Réussi. |
| `python -m pip check` | Réussi : aucune dépendance incohérente. |
| `git diff --check` | Réussi ; avertissement Git de conversion LF/CRLF pour le README sous Windows. |
| Recherche MySQL | Aucun client `mysql`, aucun service MySQL/MariaDB et aucun répertoire MySQL trouvé dans Program Files. |
| `docker version` | Client présent ; moteur Docker indisponible. |
| Démarrage Flask local (debug désactivé) | Serveur démarré sur `127.0.0.1:5051`, puis arrêté après la vérification. |
| Requêtes HTTP réelles | `/connexion` : 200, contenu français attendu, `Cache-Control: no-store` ; `/static/app.css` : 200. Aucun accès métier MySQL utilisé. |

Les tests locaux utilisent Flask test_client et des doubles pour les appels
MySQL des routes. Ils vérifient des protections et le rendu HTML ; ils ne
constituent pas une recette de la procédure stockée ou des contraintes MySQL.
Six tests avec une connexion instrumentée exercent aussi l'orchestration de
création, l'audit, le rollback appelé en cas d'échec, le refus du numéro 1000,
le réessai et la revérification du compte. Ils ne prouvent pas l'atomicité réelle.

Restent à exécuter sur MySQL : application des quatre scripts sur bases neuves,
validité des comptes, création réelle, audit, échecs transactionnels,
références concurrentes, clés concurrentes, réponse perdue après commit,
limite 999, périmètres SQL, filtres et pagination réels. La suite opt-in et ses
instructions figurent dans [implementation.md](implementation.md).

Une vérification visuelle interactive dans un navigateur reste également à
effectuer. Aucune connexion à une base métier ou opération destructive n'a été
effectuée.

## Revue complémentaire du 5 octobre 2026

Les résultats ci-dessus décrivent la première livraison. Après revue et
corrections ciblées, les contrôles suivants ont réellement été exécutés :

| Commande / scénario | Résultat |
| --- | --- |
| `python -m pytest -q` | **66 tests locaux réussis, 13 cas MySQL ignorés**, 7,65 secondes. |
| `python -m compileall -q anrt tests wsgi.py` | Réussi. |
| `python -m pip check` | Réussi : aucune dépendance incohérente. |
| `git diff --check` | Réussi ; seuls les avertissements LF/CRLF Windows subsistent. |
| Défaut SQLite, avant correction | Connexion encore utilisable après sortie du contexte ; suppression du fichier temporaire bloquée par Windows. |
| Connexion SQLite, après correction | Tests de commit, rollback et fermeture réussis ; répertoire temporaire supprimé après arrêt du serveur. |
| Parcours HTTP Flask complet | Connexion, liste, formulaire, erreur de saisie, resoumission, confirmation/fiche et déconnexion réussis avec des doubles MySQL. |
| Refus des quatre profils sans permissions confirmées | Liste et création refusées côté serveur, y compris POST direct. |
| Sessions | Rotation/révocation, expiration et impossibilité de restaurer une session révoquée par une requête encore en cours. |
| Réessais | Même clé/contenu retrouvé ; contenu différent refusé 409, sans nouvel appel de procédure, avec connexion instrumentée. |
| Serveur HTTP local réel | `/connexion`, CSS et JS : 200 et `no-store` ; accès non authentifié : redirection ; POST sans CSRF : 400. Serveur arrêté. |
| `check-db` via le runner CLI Flask | Tenté avec configuration chargée et clé/session temporaires : échec `OperationalError` 2003, connexion MySQL indisponible. |
| `docker version` | Client présent ; moteur inaccessible, pipe `docker_engine` absent. |
| PHP / Composer | Aucun exécutable trouvé ; aucun code PHP ni `composer.json` dans le projet. Commandes PHP non applicables. |

Les comptes futurs/expirés, les contraintes, la procédure, la transaction réelle,
la concurrence, la perte simulée de réponse après commit, les périmètres SQL et
la pagination sur MySQL restent **non testés dans cet environnement**. Leurs cas
opt-in n'ont pas été activés. Les tests locaux de rollback de création vérifient
les appels du code ; ils ne prouvent pas l'atomicité MySQL. Le rollback SQLite
des sessions a été réellement exécuté.

La vérification HTTP locale ne remplace pas un navigateur interactif : les
interactions JavaScript, le double clic visuel et le rendu responsive restent
à vérifier. Aucun script SQL métier n'a été modifié, aucune nouvelle dépendance
n'a été ajoutée. Voir le [rapport de revue](revue.md) et le
[guide stagiaire](guide-stagiaire.md).
