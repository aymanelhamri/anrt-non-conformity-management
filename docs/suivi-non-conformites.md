# Suivre et modifier les non-conformités

Après connexion, ouvrir **Mes non-conformités** dans le menu. La liste affiche
la référence, le responsable, la création, l'échéance et le statut. Elle peut être
filtrée par statut et comporte 20 dossiers par page.

Un compte voit les dossiers qu'il a déclarés, ceux dont il est responsable et
ceux dont le déclarant lui est affecté dans `personnel_validation`. L'accès
administrateur conserve la vue globale. Modifier le matricule ou l'identifiant
dans l'URL ne permet pas de consulter les dossiers d'un autre compte.

## Modification

Cliquer **Consulter**, puis **Modifier**, ou utiliser le lien de modification
dans la liste. Le formulaire reprend les informations enregistrées.

Les champs modifiables sont le type, la nature du service, la description, le
traitement, le responsable autorisé et l'échéance. Le processus, la référence,
le déclarant, la date de création et le statut sont conservés. Une échéance
dépassée reste acceptée si elle n'est pas antérieure à la création du dossier.

En démonstration, un responsable peut modifier ses déclarations et les dossiers
qui lui sont affectés ; l'administrateur peut modifier tous les dossiers non
validés et non terminés. Hors démonstration, configurer explicitement `edit: "related"` ou
`edit: "all"` dans la politique JSON. Sans cette permission, la modification
est refusée.

Un dossier clôturé, annulé, marqué final dans `statuts`, ou possédant une date de
clôture ne peut plus être modifié, y compris par l'administrateur. Le contrôle
est refait sur le serveur au moment de l'enregistrement. Cette évolution
n'ajoute pas d'action de clôture ni de changement libre de statut.

Dès la **première validation**, la modification est également bloquée, pour
tous les comptes, même administrateurs. Dans la liste et la fiche, le bouton
**Modifier** reste visible et désactivé, avec le message « Non-conformité
validée : modification impossible. ». Un formulaire ouvert avant la validation
est refusé à l'envoi. Les validations déjà présentes dans l'historique sont
prises en compte ; un changement ultérieur d'affectation RH ne rouvre pas le dossier.

Un verrou MySQL protège l'écriture. Un jeton de version empêche deux formulaires
de s'écraser : le second reçoit un conflit HTTP 409 si le dossier a changé.
L'historique conserve le contenu avant et après chaque modification.

## Validation RH

Les liens de `personnel_validation` sont lus par **matricule du déclarant** :
`matricule` désigne le déclarant, `mat_val` le validateur, `niv_val` son niveau.
Seul un compte valide désigné par cette table peut valider la version affichée.
Le rôle administrateur seul ne suffit pas. Un validateur sans droit de création
peut consulter et valider les dossiers qui lui sont confiés.

Pour la démonstration locale, l'affectation conseillée et installée est :

| Déclarant | Validateur | Niveau |
| --- | --- | --- |
| 00020 — Amine Demo | 00001 — Admin Demo | 1 |
| 00021 — Sara Demo | 00001 — Admin Demo | 1 |
| 00022 — Youssef Demo | 00001 — Admin Demo | 1 |

La commande `php bin/configure-demo-validators.php` prépare ces trois liens
uniquement dans `anrt_auth_local`, vérifie les identités fictives et refuse
d'écraser une affectation différente. Elle ne valide aucun dossier.
Les affectations réelles doivent être renseignées selon les responsables
désignés par Mme Nacira.

La validation est tracée dans `journal_audit` avec le validateur, le niveau
et la version du contenu. Elle ne clôture pas la NC, mais bloque toute modification.
Tant qu'aucune validation n'a eu lieu, le dossier reste modifiable et seule sa
version actuelle peut être validée. Répéter
la validation du même niveau et de la même version ne crée pas de doublon.
Si plusieurs niveaux RH sont renseignés, chaque désignation peut valider ;
aucun ordre entre niveaux n'est imposé sans règle métier confirmée.

## Notifications dans l'application

Le menu **Notifications** ouvre une boîte personnelle avec le nombre de
notifications non lues et une action **Marquer comme lue**. Les notifications
sont stockées dans la table MySQL existante `notifications`.
Dans le menu de toutes les pages connectées, une pastille affiche
**Notifications (X)** : `X` correspond aux notifications non lues du compte.
Le nombre est recalculé à chaque page et diminue après le marquage comme lue.
À zéro, la pastille devient discrète ; elle est rouge lorsqu'il reste des notifications.

Chaque création, modification et validation informe le déclarant, le responsable,
les validateurs RH et les administrateurs actifs. Une réaffectation informe aussi
l'ancien responsable. Chaque destinataire ne reçoit qu'une notification par
événement, même s'il cumule plusieurs rôles. Les boîtes de notification restent
strictement séparées par matricule de session.

L'écriture du dossier, l'audit et les notifications partagent une transaction :
une panne de notifications annule l'opération. Aucun e-mail n'est envoyé.
Les notifications commencent avec cette évolution ; les anciennes créations ne
sont pas notifiées rétroactivement.

## Fichiers et installation

- `app/nc_suivi.php` : périmètre des dossiers, modification, validation et versions.
- `app/notifications.php` : destinataires, enregistrement et boîte personnelle.
- `app/web.php` : routes, session, CSRF et formulaires.
- `templates/nc_list.php`, `templates/new.php`, `templates/nc_detail.php` : écrans.

La structure SQL reste identique. Le compte technique doit disposer de `SELECT`,
des droits existants de création/audit et d'exécution de `generer_reference`, ainsi
que de ces droits supplémentaires sur la base de l'application :

```sql
GRANT UPDATE (type_nc_id, nature_service_id, description, traitement,
              responsable_matricule, date_echeance)
ON anrt_qualite.non_conformites TO 'anrt_app'@'127.0.0.1';
GRANT INSERT, UPDATE (lue_le)
ON anrt_qualite.notifications TO 'anrt_app'@'127.0.0.1';
```

Adapter le nom de base et le compte technique à l'installation.
Pour la base locale déjà préparée, la commande
`php bin/enable-nc-followup-local.php` applique seulement ces droits à
`anrt_local` sur `anrt_auth_local`. Elle a été exécutée sur ce poste.

## Recette isolée

`TEST_NC_SUIVI_MYSQL=1 php tests/nc_suivi_mysql.php` utilise les accès de recette
`TEST_MYSQL_HOST`, `TEST_MYSQL_PORT`, `TEST_MYSQL_USER`, `TEST_MYSQL_PASSWORD`.
Elle crée et conserve une nouvelle base `anrt_recette_<aléatoire>`, puis démarre
un serveur HTTP avec un compte MySQL limité aux droits applicatifs. Ce compte
technique temporaire est supprimé à la fin. Les dossiers de la base locale
ne sont pas modifiés par cette recette.
