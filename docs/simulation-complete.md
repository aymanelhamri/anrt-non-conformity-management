# Simulation complète — 28 octobre 2026

Le parcours connexion → formulaire → enregistrement → confirmation → nouvelle
déclaration → déconnexion a été exécuté contre le site local
`http://127.0.0.1:5000` et la vraie base MySQL `anrt_auth_local`.
Les requêtes passent par `public/index.php` et le compte MySQL de l'application.
Les permissions utilisées sont celles du mode de démonstration.

**Résultat : 31 contrôles réussis.** Une non-conformité fictive, sa référence
et son audit sont conservés dans MySQL pour inspection.

## Cas enregistré

| Information | Valeur |
| --- | --- |
| Déclarant et responsable | `00020` — Amine Demo |
| Profil | `RESPONSABLE` |
| Processus | SI — Système d’information |
| Type | Délai de traitement non conforme |
| Nature du service | Assignation de fréquences |
| Description | `[SIMULATION SIM-530d191b8f1928c0] Retard fictif dans le traitement d'une demande d'assignation de fréquences.` |
| Traitement | Vérifier le dossier fictif, compléter les informations et planifier son traitement. Aucune donnée réelle. |
| Date de création | 28 octobre 2026 |
| Échéance | 4 novembre 2026 |
| Statut | `OUVERTE` |
| Référence | **`NC-SI-001-26`** |
| Identifiant de la NC | `1` |
| Identifiant de l’audit | `1` |

## Étapes vérifiées

| Étape | Résultat observé |
| --- | --- |
| Accès au formulaire sans connexion | Redirection vers la connexion. |
| Connexion sans CSRF ou avec un mauvais mot de passe | Refus HTTP 400 ou 401. |
| Connexion d’Amine Demo | Acceptée, session renouvelée et identité issue de MySQL affichée. |
| Description vide, échéance passée, type inexistant ou responsable interdit | Refus HTTP 422 ; aucune écriture dans la base. |
| Modification du statut dans le POST ou absence de CSRF | Refus HTTP 400. |
| Envoi de la déclaration valide | Redirection HTTP 303 vers la confirmation. |
| Lecture des tables MySQL | Champs, accents, responsable, date et statut conformes à la saisie. |
| Confirmation | Affichage de `NC-SI-001-26`. |
| Audit | Une trace de création avec le compte `00020`, la référence et les informations de la NC. |
| Renvoi du même formulaire | Même référence retrouvée, sans doublon. |
| Même clé avec un contenu différent | Refus HTTP 409, sans nouvelle écriture. |
| Nouvelle déclaration | Formulaire vide, nouvelle clé et ancienne confirmation retirée. |
| Déconnexion | Cookie supprimé et ancien cookie inutilisable. |

Les lectures MySQL ont confirmé ces évolutions :

| Donnée | Avant | Après |
| --- | --- | --- |
| Lignes dans `non_conformites` | 0 | 1 |
| Lignes dans `journal_audit` | 0 | 1 |
| Compteur NC du processus SI pour 2026 | 0 | 1 |

## Consulter les informations enregistrées

Ouvrir MySQL depuis la racine du projet :

```powershell
.\.tools\mysql-8.4.13-winx64\bin\mysql.exe --defaults-extra-file=instance/mysql-local/admin.ini anrt_auth_local
```

Lire la déclaration et son audit :

```sql
SELECT id, reference, description, traitement, enregistree_par_matricule,
       responsable_matricule, date_creation, date_echeance, statut_code
FROM non_conformites
WHERE reference = 'NC-SI-001-26';

SELECT id, acteur_matricule, operation, entite, entite_id, created_at
FROM journal_audit
WHERE entite = 'non_conformites' AND entite_id = '1';
```

## Rejouer la simulation

Le script commenté [tests/parcours_mysql.php](../tests/parcours_mysql.php)
est réservé à la base locale et nécessite les serveurs démarrés. Chaque nouvelle
exécution crée et conserve **une nouvelle NC fictive**, avec sa propre référence.
Les identifiants du compte sont lus dans [compteDemo](../compteDemo).

```powershell
$env:TEST_SIMULATION_MYSQL = '1'
.\.tools\php\php.exe tests/parcours_mysql.php
Remove-Item Env:TEST_SIMULATION_MYSQL
```

Sans activation explicite, le script n’effectue aucune simulation. Le dernier
résultat détaillé est sauvegardé dans `instance/simulation-complete.json`, sans
mot de passe, cookie ni jeton de formulaire.

Depuis l'ajout du journal d'administration, les compteurs de la simulation
portent sur les NC et leurs audits de création ; les événements de connexion
sont comptés séparément.

Cette simulation utilise des requêtes HTTP automatisées et le vrai stockage
MySQL. Elle ne comprend pas une vérification visuelle dans un navigateur ni
l’exécution de JavaScript. Les 12 cas distincts de `tests/mysql.php`, notamment
les tests de concurrence et d’échec forcé de l’audit, restent à exécuter séparément.
