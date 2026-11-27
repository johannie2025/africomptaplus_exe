# AfriCompta+ — version Windows portable (100 % hors-ligne)

## Mise en place du dépôt GitHub
```
.github/workflows/build-windows.yml
desktop/            (lanceur Go + runtime PHP)
server/             ← copier ici le CONTENU de africompta_server (admin/, api/, inc/, sql/, web/ ...)
```
Ne commitez pas `server/inc/config.local.php` (identifiants de prod) : le build le retire de toute façon.

## Compiler
GitHub → onglet **Actions** → « Build AfriCompta+ portable (Windows) » → **Run workflow**
(ou poussez un tag `v1.0.0` pour créer aussi une Release). Récupérez `AfriCompta-Portable-win64.zip` dans les artefacts.
Le job démarre MariaDB + PHP et teste l'app avant de produire le zip ; s'il échoue, les journaux sont affichés dans le log.

## Utilisation
1. Extraire le zip (chemin simple, sans accents, ex. `C:\AfriCompta`) et lancer **AfriCompta.exe**.
2. 1er lancement : la page d'installation du panel s'ouvre → créer l'administrateur.
3. **AfriCompta-Admin.bat** ouvre le panel admin : créer la licence, puis ouvrir AfriCompta.exe → « Première visite… » → clé de licence + email + mot de passe.
4. Tout est local : MariaDB (127.0.0.1 uniquement) + PHP. Aucune connexion internet requise.

Données : dossier `data\` (base, sessions, journaux) à côté de l'exe → copier le dossier = sauvegarde / déplacement sur clé USB.
Journaux : `data\logs\` (launcher, mysql, php, setup).

## Limites hors-ligne
WhatsApp (Evolution) et les fonctions IA du bot nécessitent internet : inutilisables hors-ligne (sur ces pages, la requête peut attendre jusqu'au délai d'expiration).
