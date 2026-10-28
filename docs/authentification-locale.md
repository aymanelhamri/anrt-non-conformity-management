# Authentification MySQL locale

L'environnement est préparé : MySQL 8.4.13 écoute sur `127.0.0.1:3307`, la base
`anrt_auth_local` contient des personnes entièrement fictives, et `.env`
configure la connexion de l'application. Les quatre scripts du dossier
`database/` ont été importés pour permettre aussi l'affichage du formulaire
après connexion. Ne pas relancer l'initialisation.

Depuis la racine du projet, démarrer MySQL et le serveur PHP :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\bin\local.ps1 start
```

Consulter leur état :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\bin\local.ps1 status
```

Arrêter les deux serveurs en conservant les données :

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\bin\local.ps1 stop
```

Le script utilise PHP portable dans `.tools/php/` et MySQL portable dans
`.tools/mysql-8.4.13-winx64/`. Les serveurs restent accessibles depuis ce poste
sur la boucle locale.

Ouvrir <http://127.0.0.1:5000/connexion>. Le mot de passe commun aux comptes est
**`Anrt-Test-2026!`** ; il est stocké dans MySQL sous forme de hash Argon2id.

| Matricule | Nom fictif | Profil | Résultat attendu |
| --- | --- | --- | --- |
| `00017` | Responsable Test | RESPONSABLE | Connexion acceptée |
| `00018` | Administrateur Test | ADMINISTRATEUR | Connexion acceptée |
| `00019` | Compte Expiré | RESPONSABLE | Connexion refusée |

Trois comptes responsables supplémentaires (`00020`, `00021`, `00022`) et le
nouveau compte administrateur `00001` sont décrits avec leurs identifiants dans
le fichier [compteDemo](../compteDemo). Le compte `00001` accède à
l'[espace administrateur](administration.md) et au journal des comptes.

Un mot de passe incorrect doit aussi être refusé. Conserver les zéros initiaux
des matricules. Le profil administrateur désigne ici un profil de l'application ;
la connexion MySQL de l'application utilise `anrt_local`, avec seulement les
droits de lecture, d'insertion des NC et de l'audit, et d'exécution de la
procédure de référence. Son mot de passe aléatoire et la clé de session sont
créés dans `.env` sans être affichés.

Pour vérifier la connexion MySQL :

```powershell
.\.tools\php\php.exe bin/console.php check-db
```

Pour exécuter les tests d'authentification contre cette base locale, avec MySQL
démarré :

```powershell
$env:TEST_AUTH_MYSQL = '1'
.\.tools\php\php.exe tests/auth_mysql.php
Remove-Item Env:TEST_AUTH_MYSQL
```

Ces tests vérifient les comptes et le parcours HTTP de connexion sans créer
de personne ou de NC. Les connexions et déconnexions produisent désormais des
événements dans `journal_audit`.

Le [compte rendu de simulation complète](simulation-complete.md) décrit aussi
un enregistrement réel de non-conformité avec le compte `00020`, son audit,
les réessais sans doublon et la déconnexion.

`DEMO_MODE=true` active explicitement les permissions de test existantes pour
ces profils. `NC_INITIAL_RULES_CONFIRMED=false` laisse les décisions métier en
attente. Le cookie sans option Secure permet les essais HTTP sur la boucle
locale ; cette configuration est destinée à ce poste uniquement.

L'initialiseur `bin/setup-local.php` a été utilisé une seule fois. Il exige
PHP 8.3+, PDO MySQL, mbstring et Argon2id et lit la configuration administrateur
privée dans `instance/mysql-local/setup.json`. Il refuse de remplacer une base
`anrt_auth_local`, un compte MySQL `anrt_local` ou un fichier `.env` existant.
Il ne supprime rien. En cas d'échec après création, il conserve la base pour
diagnostic. Les données, configurations et journaux dans `instance/`, les outils
dans `.tools/` et le fichier `.env` sont ignorés par Git. Ne pas utiliser ces
comptes pour des données RH réelles ou pour une installation partagée.

Provenance du serveur : [téléchargement officiel MySQL Community Server 8.4 pour Windows](https://dev.mysql.com/downloads/mysql/8.4.html?os=3).
