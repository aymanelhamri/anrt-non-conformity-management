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
