# AfriCompta+ — app web autonome pour Windows (portable, hors-ligne)

Contient uniquement l'application de gestion (caisse, stock, clients, dettes, dépenses, compta OHADA, rapports…).
Le panel admin et l'API serveur ne sont **pas** embarqués : la licence est activée auprès de votre panel en ligne.

## Mise en place du dépôt GitHub
```
.github/workflows/build-windows.yml
desktop/            (lanceur Go, runtime PHP, surcharges de l'app)
server/             ← copier ici le contenu de africompta_server (inc/, sql/, web/ ; admin/ et api/ sont ignorés)
```
1. Dépôt → Settings → Secrets and variables → Actions → **Variables** → créer `PANEL_URL`
   = URL de base de votre panel, là où se trouve `/api/index.php` (sans `/api/index.php`, sans « / » final).
   Sans cette variable, l'écran d'activation demandera l'adresse du panel à l'utilisateur.
2. Ne commitez pas `server/inc/config.local.php` (le build le retire de toute façon).

## Compiler
Onglet **Actions** → « Build AfriCompta+ portable (Windows) » → **Run workflow** (ou tag `v1.0.0` pour une Release).
Résultat : `AfriCompta-Portable-win64.zip`. Le job teste l'app de bout en bout (MariaDB + PHP + activation de licence
contre un faux panel) avant de produire le zip ; en cas d'échec, les journaux s'affichent dans le log.

## Utilisation
1. Extraire le zip (chemin simple, sans accents, ex. `C:\AfriCompta`), lancer **AfriCompta.exe**.
2. 1er lancement : écran d'activation → clé de licence + email + mot de passe. **Internet requis cette seule fois** :
   l'app appelle `…/api/index.php?action=activate` de votre panel (la licence apparaît dans le panel avec l'appareil).
3. Ensuite tout fonctionne hors-ligne. Quand internet est disponible, l'app revérifie la licence toutes les 12 h
   (révocation / expiration / renouvellement appliqués localement ; sans effet hors-ligne).

Données : dossier `data\` à côté de l'exe (base, sessions, journaux). L'identifiant d'appareil envoyé au panel est
stocké dans `data\secrets.json` : copier le dossier complet sur une clé USB garde la même activation.
Journaux : `data\logs\` (launcher, mysql, php, setup, license).

## Limites hors-ligne
WhatsApp (Evolution) et l'IA du bot nécessitent internet.
