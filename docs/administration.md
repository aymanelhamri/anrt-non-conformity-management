# Administration et journal des comptes

Le compte local **`00001` — Admin Demo** dispose du profil `ADMINISTRATEUR`.
Son mot de passe de démonstration est conservé dans [compteDemo](../compteDemo).
Se connecter sur <http://127.0.0.1:5000/connexion> ouvre directement
<http://127.0.0.1:5000/administration>.

L'espace propose quatre vues :

- **Vue d'ensemble** : comptes, comptes actifs, nombre de NC et événements.
- **Comptes** : matricule, nom, profils, période de validité, dernière connexion
  connue, nombre de déclarations et lien vers le journal de chaque compte.
- **Non-conformités** : toutes les déclarations, avec un filtre par déclarant,
  une pagination et une fiche détaillée accessible depuis la référence.
- **Journal d'activité** : créations, modifications et validations de NC, connexions réussies, connexions
  refusées et déconnexions, filtrables par matricule et type d'événement.

Les lectures globales sont réservées aux utilisateurs disposant du droit
explicite `admin_view`. Un compte responsable reçoit HTTP 403 même s'il entre
l'adresse d'administration directement ou fournit le matricule d'un admin
dans les paramètres. Les pages d'administration sont en consultation ; elles
n'ajoutent pas d'option de création de comptes.
La fiche propose aussi un lien vers le [suivi du dossier](suivi-non-conformites.md),
où les modifications avant validation ou clôture et les validations RH sont contrôlées séparément.

## Données conservées dans le journal

La table existante `journal_audit` est réutilisée sans modification du schéma.
Les créations de NC restent associées à `entite='non_conformites'`. Les
événements de connexion utilisent `entite='acces'` et les opérations
`CONNEXION`, `CONNEXION_REFUSEE`, `DECONNEXION`.

Chaque événement indique la date, le compte ou le matricule tenté, l'opération
et l'adresse IP. Les refus peuvent préciser un motif général. Le journal ne
reçoit aucun mot de passe, hash ou identifiant de session. Le compte MySQL
utilisé par l'application possède déjà les droits `SELECT` et `INSERT`
nécessaires ; le compte administrateur du site n'utilise pas MySQL root.

Les audits de création antérieurs restent visibles. **Les anciennes connexions
ne peuvent pas être reconstituées** : leur suivi commence avec cette extension.
Une dernière connexion affichée « — » signifie qu'aucune connexion réussie
n'a encore été journalisée pour ce compte.

Le journal affiche les horaires dans le fuseau `APP_TIMEZONE`. Les données
textuelles sont échappées avant affichage ; les détails techniques internes
des soumissions sont exclus de la vue.

## Permissions hors démonstration

En mode local de démonstration, le profil `ADMINISTRATEUR` dispose de
`admin_view=true`, contrairement au profil `RESPONSABLE`. Les comptes portant
déjà le profil administrateur bénéficient aussi de ce droit.

Hors démonstration, ajouter explicitement cette permission au fichier JSON
désigné par `PERMISSIONS_FILE`, par exemple :

```json
{
  "ADMINISTRATEUR": {"create": true, "assign": "all", "admin_view": true, "edit": "all"},
  "RESPONSABLE": {"create": true, "assign": "self", "edit": "related"}
}
```

Sans permission configurée, la consultation globale est refusée. Le compte,
sa période de validité et ses profils sont relus dans MySQL à chaque requête
protégée. L'accès administrateur ne contourne pas le contrôle de validité.

## Vérifications

```powershell
.\.tools\php\php.exe .tools\composer.phar test
$env:TEST_ADMIN_MYSQL = '1'
.\.tools\php\php.exe tests/admin_mysql.php
Remove-Item Env:TEST_ADMIN_MYSQL
```

La recette MySQL utilise les comptes `00001`, `00020` et `00021` et un serveur
PHP temporaire avec ses propres sessions. Elle vérifie les accès autorisés et
refusés, les fiches de NC, les filtres, les audits antérieurs, la pagination,
l'échappement HTML et l'absence de secrets. Elle produit des événements de
connexion dans le journal mais ne crée ni compte ni non-conformité.

Les fichiers commentés principaux sont `app/admin.php` pour les consultations,
`app/auth.php` pour la journalisation et `app/permissions.php` pour les droits.
