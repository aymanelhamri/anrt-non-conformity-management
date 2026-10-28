# Vérifications du formulaire PHP

## Blocage après validation et badge de notifications — 28 octobre 2026

La première validation enregistrée bloque désormais toute modification, même
pour un administrateur ou avec un formulaire déjà ouvert. La liste et la fiche
affichent un bouton **Modifier** désactivé avec un message. La règle se fonde sur
l'historique des validations et reste appliquée après une réaffectation.
Le menu affiche **Notifications (X)** avec le nombre personnel de non-lues,
recalculé à chaque page et après lecture.

Vérifications exécutées : **78 tests PHP**, **56 contrôles HTTP instrumentés**
et **20 contrôles HTTP publics** réussis. La recette isolée du suivi a réussi
**78 contrôles MySQL/HTTP réels** avec des droits applicatifs limités. Elle
vérifie aussi les boutons désactivés pour le déclarant et l'administrateur,
les GET/POST forcés, le refus dans la fonction transactionnelle, la conservation
du contenu validé, les compteurs séparés, la décrémentation après lecture et
l'absence de double décrémentation en relisant une notification.
Base de recette conservée : `anrt_recette_e34cf43f5d04069e1fb8d407e75f1719`.
Les NC de `anrt_auth_local` ne sont pas modifiées par cette recette.

## Suivi, modification, validation RH et notifications — 28 octobre 2026

Le suivi affiche les dossiers déclarés, affectés ou à valider, avec leur statut.
La modification est refusée pour tout dossier terminé. La validation dépend des
liens RH du déclarant et reste distincte de la clôture. Les événements métier
créent des notifications personnelles dans la transaction de leur audit.

| Vérification exécutée | Résultat |
| --- | --- |
| Syntaxe des 44 fichiers PHP | Aucune erreur. |
| `composer validate --strict` | Manifest valide. |
| `composer test` | **78 tests PHP**, **56 contrôles HTTP instrumentés**, **20 contrôles HTTP publics**, tous réussis. |
| `TEST_NC_SUIVI_MYSQL=1 php tests/nc_suivi_mysql.php` | **64 contrôles MySQL/HTTP réels réussis**, dans une base neuve et avec un compte technique aux droits limités. |
| Recettes existantes d'administration et d'authentification | **58** et **16** contrôles MySQL/HTTP réussis. |
| `TEST_MYSQL=1 php tests/mysql.php` | **12 tests MySQL réels réussis**, dont créations simultanées, double soumission et rollback. |
| Application locale sur le port 5000 | **13 contrôles réussis** : connexion, liste, détail, formulaire de modification, notifications, bouton RH et déconnexion. Aucune NC modifiée par ces contrôles. |

La recette de suivi vérifie les accès des déclarants, responsables, validateurs
sans droit de création et administrateurs ; les dossiers masqués aux autres
comptes ; les filtres ; les champs protégés ; le CSRF ; les anciennes/nouvelles
valeurs ; l'échappement HTML ; le refus des formulaires périmés ; la validation
de chaque version sans doublon ; la séparation des notifications et leur lecture.
Une panne de notifications forcée provoque le rollback de la modification et
de son audit. Une clôture entre l'ouverture du formulaire et l'envoi bloque
l'écriture. Les dossiers annulés sont également protégés.

Un contrôle des privilèges a révélé qu'un verrou exclusif sur toutes les tables
jointes nécessitait des droits trop larges. La requête verrouille désormais
la NC en écriture et ses référentiels en lecture. La recette a ensuite réussi
avec les seuls droits applicatifs, également activés sur `anrt_auth_local`.

Les trois NC locales existantes sont conservées. Trois liens RH de démonstration
ont été installés : `00020`, `00021`, `00022` sont validés par `00001`, niveau 1.
Ces liens ne constituent aucune validation de dossier. Les nouvelles
notifications commencent à l'activation ; aucun historique n'a été reconstitué.
La dernière base isolée utilisée pour la recette du suivi est
`anrt_recette_fa9580a07bd9e9e3ddd4eec6fedbac2f`, conservée pour inspection.

Les tests exercent HTTP et MySQL ; aucun contrôle visuel interactif ou du
JavaScript dans un navigateur n'est annoncé. Voir le
[guide de suivi](suivi-non-conformites.md).

## Administration et activité des comptes — 28 octobre 2026

Le compte fictif `00001` a été ajouté avec le profil `ADMINISTRATEUR`, sans
modifier les comptes existants. Les vues d'administration consultent les
comptes, toutes les NC et les événements de `journal_audit`. Les connexions
réussies, refusées et les déconnexions sont désormais journalisées ; les
anciennes connexions ne sont pas reconstituées.

| Vérification exécutée | Résultat |
| --- | --- |
| `composer test` | **74 tests PHP**, **52 contrôles HTTP instrumentés**, **16 contrôles HTTP publics**, tous réussis. |
| `TEST_ADMIN_MYSQL=1 php tests/admin_mysql.php` | **58 contrôles d'administration avec MySQL et HTTP réels réussis**. |
| `TEST_AUTH_MYSQL=1 php tests/auth_mysql.php` | **16 contrôles d'authentification réelle réussis**. |
| `TEST_SIMULATION_MYSQL=1 php tests/parcours_mysql.php` | **31 contrôles du parcours complet réussis** ; nouvelle NC fictive conservée `NC-SI-002-26`, id `3`. |
| Connexion au site local sur le port 5000 avec `00001` | Redirection vers l'administration ; comptes, NC et journal filtré en HTTP 200, puis déconnexion confirmée. |

La recette vérifie notamment le refus HTTP 403 pour un responsable, l'absence
de menu administrateur pour ce profil, les refus sans session, le journal
filtré par compte et événement, les audits de NC antérieurs, les détails des
déclarations, le refus des POST administratifs, les filtres malformés, la
pagination et les protections contre l'injection SQL et HTML. Les vues et les
événements de connexion n'exposent ni mots de passe, ni hashes, ni cookies,
ni clés internes de soumission.

La comparaison des identifiants d'audit avec les NC a été corrigée après un
échec de collation observé sur MySQL réel, puis les 58 contrôles ont passé.
La recette d'administration ne crée aucune NC ; le rejeu séparé du parcours
complet en conserve une nouvelle. Les tests HTTP utilisent des serveurs PHP
réels ; aucune validation visuelle interactive dans un navigateur n'est annoncée.

Voir le [guide d'administration](administration.md).

## Parcours complet sur MySQL réel — 28 octobre 2026

`TEST_SIMULATION_MYSQL=1 php tests/parcours_mysql.php` a réussi **31 contrôles**
via le point d'entrée public du site sur `127.0.0.1:5000` et MySQL réel.
Le compte fictif `00020` a enregistré la NC **`NC-SI-001-26`**, identifiant `1`,
avec un audit de création, identifiant `1`. La déclaration reste dans la base
`anrt_auth_local` pour inspection.

Les contrôles couvrent la connexion et la rotation de session, les erreurs de
saisie sans écriture, les référentiels et le responsable autorisé, la sauvegarde
des champs et accents, la référence et l'audit persistés, la confirmation,
le renvoi sans doublon, le refus d'un contenu différent après succès, la
nouvelle déclaration et la déconnexion avec révocation du cookie.

Le nombre de NC et d'audits ainsi que le compteur SI/2026 sont chacun passés de
0 à 1 ; les réessais et saisies refusées ne les ont pas modifiés.
L'exécution sans activation a également été vérifiée : elle s'arrête sans créer
de déclaration. La syntaxe PHP du script est valide.

Le [compte rendu de simulation](simulation-complete.md) décrit le cas, les
résultats et les commandes pour consulter les données. Ces vérifications HTTP
ne remplacent pas une recette visuelle/JavaScript ou les 12 tests MySQL distincts
de concurrence et d'échecs forcés, qui restent non exécutés.

## Authentification MySQL locale — 28 octobre 2026

Une instance MySQL 8.4.13 portable a été préparée sur `127.0.0.1:3307` avec
la base neuve `anrt_auth_local`. Elle reprend les quatre scripts SQL du projet
et trois comptes fictifs : deux actifs, un expiré. L'application utilise un
compte MySQL distinct avec des droits limités. Les paramètres privés restent
dans `.env` et `instance/`, ignorés par Git.

| Vérification exécutée | Résultat |
| --- | --- |
| `php bin/console.php check-db` | Connexion MySQL 8.4.13, session UTC, procédure et statut présents. |
| `TEST_AUTH_MYSQL=1 php tests/auth_mysql.php` | **16 contrôles avec vrais PDO/MySQL et le point d'entrée public réussis**. |
| `composer test` | **69 tests PHP**, **45 contrôles HTTP instrumentés** et **14 contrôles HTTP publics réussis**. |
| `composer validate --strict` | Manifest et verrou valides. |
| `bin/local.ps1 stop`, puis `start` | Arrêt et redémarrage confirmés ; MySQL reconnecté et page `/connexion` en HTTP 200. |

Après ces contrôles, la base conserve trois personnes et trois accès ;
`non_conformites` et `journal_audit` restent vides.

La recette MySQL d'authentification vérifie les deux comptes actifs et leurs
profils, la conservation des zéros du matricule, le refus d'un mot de passe
incorrect et du compte expiré, le contrôle CSRF, la rotation de session,
l'accès au formulaire avec l'identité issue de MySQL, la déconnexion et le
refus d'un cookie révoqué. Elle isole ses sessions et n'écrit aucune donnée
métier. Les droits sont les permissions techniques explicites du mode démo.

Ces résultats ne couvrent pas les 12 cas de création/transactions/concurrence
de `tests/mysql.php`, qui n'ont pas été exécutés pendant cette intervention,
ni une vérification interactive dans un navigateur. Voir le
[guide local](authentification-locale.md) pour démarrer l'instance et se connecter.

## Parcours simplifié — 14 octobre 2026

Ces contrôles portent sur le parcours actuel : connexion → formulaire NC →
enregistrement → confirmation de la référence dans la session → nouvelle
déclaration ou déconnexion. Ils ont été exécutés avec les outils de `.tools/`.

| Commande / scénario | Résultat réellement obtenu |
| --- | --- |
| `composer validate --strict` | Manifest et verrou valides. |
| `composer test` | **69 tests PHP locaux**, **45 contrôles HTTP avec PDO instrumenté** et **14 contrôles HTTP via le point d'entrée public**, tous réussis. |
| `php -l` sur les 22 fichiers PHP | Aucune erreur de syntaxe. |
| `git diff --check` | Aucune erreur ; avis de conversion LF/CRLF Windows uniquement. |
| Comparaison des scripts SQL et de `docs/modele-donnees.md` | Aucun changement. |

Les contrôles HTTP vérifient notamment la confirmation limitée à la création
liée à la session, son indisponibilité avant création ou après reconnexion,
et le refus des anciennes routes de consultation, même pour un compte connecté.
Ils exécutent de vrais serveurs PHP locaux, avec un PDO instrumenté pour les
opérations métier : ils ne prouvent pas leur stockage réel ni l'atomicité MySQL.

Les listes de l'aperçu reprennent les 12 processus, 8 types de NC et 16 natures
de service du script SQL existant. Les tests vérifient les libellés, le refus
des choix inexistants et la référence du processus choisi dans la confirmation,
l'audit et les réessais. Le responsable reste explicitement un compte de test.

Les **12 cas MySQL** adaptés au premier parcours sont préparés mais n'ont pas
été exécutés. La procédure, les contraintes, les transactions et la concurrence
restent à vérifier sur un serveur de recette. Aucun contrôle interactif dans
un navigateur n'a été effectué ; le rendu et le comportement JavaScript restent
à vérifier.

## Historique — ancienne version élargie du 5 octobre 2026

Les résultats ci-dessous sont conservés comme compte rendu historique. Ils
concernent l'ancienne version avec liste, filtres, pagination, fiche et audit
visible. **Ils ne constituent pas des preuves de vérification du parcours
simplifié actuel.**

Environnement de vérification : Windows, **PHP 8.5.11 64 bits**, Composer 2.10.3.
Les exécutables officiels ont été téléchargés dans `.tools/`, puis leurs
empreintes SHA-256 ont été vérifiées. Ce dossier est ignoré par Git.

Les anciens résultats Flask/Python sont dans l'historique Git ; ils ne constituent
pas des résultats de tests PHP. Les contrôles ci-dessous portaient sur cette
ancienne version PHP.

| Commande / scénario | Résultat réellement obtenu |
| --- | --- |
| `php -l` sur les 22 fichiers PHP | Aucune erreur de syntaxe. |
| `composer validate --strict` | Manifest et verrou valides. |
| `composer test` | **63 tests PHP locaux réussis**, puis **36 contrôles HTTP avec PDO instrumenté** et **13 contrôles HTTP via le point d'entrée public**, sans échec. |
| `composer test:mysql` | Exécuté sans activation : message explicite indiquant que les 13 cas MySQL n'ont pas été exécutés. |
| `php bin/console.php check-db` | Tenté avec clé temporaire : échec de connexion MySQL, code 2002, sortie 1. |
| `composer install --no-plugins --no-scripts` | Prérequis compatibles, aucune dépendance tierce à installer. Les filtres réseau Composer sont inaccessibles dans le bac à sable ; aucune analyse de sécurité réseau n'est déclarée. |
| `git diff --check` et espaces finaux des nouveaux fichiers | Vérifiés, sans erreur. |
| Comparaison des ressources CSS et JS | Copies identiques aux ressources précédentes. |
| Comparaison des scripts SQL | Aucun changement. |

Les tests PHP vérifient IDs unsigned, zéros des matricules, longueurs Unicode et
octets UTF-8, textes requis, dates invalides/antérieures, obligation d'échéance,
`password_verify` Argon2id/bcrypt y compris les préfixes existants `$2a$`/`$2b$`,
politiques fermées, périmètres, CSRF, signature de soumission, champs répétés,
limites persistantes de connexion, orchestration de transaction, audit et réessais.

Les tests HTTP démarrent puis arrêtent de vrais serveurs PHP locaux. Ils exercent
connexion → liste → nouvelle NC → validation invalide → resoumission → création
→ confirmation/fiche/audit → réessai → déconnexion, ainsi que rotation et
expiration effectives des sessions et refus de réutilisation d'un cookie révoqué.
Ils vérifient aussi les refus de permissions, les POST protégés, les fichiers
privés inaccessibles, les ressources et les pages sans authentification.

**Les opérations métier du parcours authentifié utilisent un PDO instrumenté.**
Cela vérifie l'exécution PHP et les appels SQL, pas leur stockage réel. La
validation des comptes futurs/expirés n'a pas été exécutée sur MySQL.

### Recette non exécutée à cette date

Les 13 cas de `tests/mysql.php` sont portés depuis la suite précédente et restent
opt-in. Ils créent des bases neuves au nom aléatoire, y appliquent les scripts,
préparent leurs comptes, puis vérifient validité, création, audit, rollback,
concurrence, perte de réponse après commit, plafond 999, références inexistantes,
référentiel inactif, affectation interdite, périmètres, filtres et pagination.
Aucune base existante n'est vidée ou supprimée ; les bases de recette sont conservées.

Aucun serveur MySQL disponible n'a été trouvé dans cet environnement. Les tests
réels de procédure, contraintes, atomicité et concurrence restent donc non exécutés.
Le rendu interactif, le comportement JavaScript et la présentation responsive
restent également à vérifier dans un navigateur.

Voir le [guide d'installation actuel](implementation.md).
