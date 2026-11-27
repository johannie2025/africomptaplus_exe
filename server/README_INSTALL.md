# Panel licences AfriCompta+ — installation (alwaysdata)

1. **Base MySQL** : panneau alwaysdata › Bases de données › MySQL › créer `wiseos_africompta` + utilisateur.
2. **Schéma** : phpMyAdmin › importer `sql/schema.sql`.
3. **Upload** (FTP/SSH) : tout le contenu de ce dossier dans `~/www/africompta/` → https://wiseos.alwaysdata.net/africompta/
4. **Config** : créer `inc/config.local.php` :
   ```php
   <?php return ['db_host'=>'mysql-wiseos.alwaysdata.net','db_name'=>'wiseos_africompta','db_user'=>'…','db_pass'=>'…'];
   ```
   (PHP ≥ 8.1, HTTPS activé.)
5. Ouvrir `/africompta/admin/install.php` → créer l'administrateur.
6. **Supprimer `admin/install.php`**.
7. Test API : `curl https://wiseos.alwaysdata.net/africompta/api/?action=ping`

API : `POST /africompta/api/?action=activate|verify|status|deactivate` — JSON `{license_key, device_id, device_name?, app_version?}`.
Réponse : `{valid, status, message, license, server_time}`.

## IA (fournisseurs, modèles, cooldown)
1. Phpmyadmin : importer `sql/schema_ai.sql` (si le schéma principal était déjà installé).
2. Dans `inc/config.local.php` ajouter une chaîne aléatoire longue : `'app_secret' => '…'` (chiffre les clés API ; ne la changez plus ensuite).
3. Panel › **IA** : ajouter un fournisseur (OpenRouter, Groq, Gemini, OpenAI, Anthropic, Mistral, DeepSeek, Together, Cerebras ou compatible OpenAI) → « Récupérer les modèles » → cocher les modèles, marquer gratuit/payant, fixer la priorité → « Tester le routeur ».
Routage : gratuits d'abord (réglable), rotation à priorité égale, pause progressive après échec (429/quota/panne), quota journalier → pause jusqu'à minuit UTC, repli automatique sur le modèle suivant.


## Bot WhatsApp (Lot 3)
1. Importer `sql/schema_bot.sql` (déjà inclus dans `sql/schema.sql` pour une nouvelle installation).
2. Déployer une instance **Evolution API v2** (Docker) accessible en HTTPS.
3. Panel → **Bot WhatsApp** : renseigner l'URL Evolution, la clé API globale, l'URL publique de l'API
   (ex. `https://wiseos.alwaysdata.net/africompta/api`) puis « Tester Evolution ».
4. Au moins un modèle IA actif (page **IA**). Les modèles gratuits tournent avec cooldown/repli.
5. Autoriser le bot par licence (bouton « Autoriser ») ou cocher « activer par défaut ».
6. Dans l'APK : Paramètres → WhatsApp → « Lier mon WhatsApp » → scanner le QR.
Webhook (créé automatiquement par instance) : `…/api/index.php?action=webhook&t=<jeton>`.

## Panel web / PWA (Lot « Web »)
- Dossier `web/` déployé à côté de `admin/` et `api/` → `https://…/africompta/web/` (point d'entrée unique `index.php`, routeur central, modules dans `modules/`).
- Importer `sql/schema_web.sql` (inclus dans `schema.sql`) et, sur une base existante, `sql/migrate_v3.sql` (colonne `bot_accounts.source`).
- Le commerçant ouvre `…/web/`, clique « Première visite… » : **clé de licence + email + mot de passe** crée son accès (une licence = un espace).
- Fonctions : vente (POS, scan caméra), produits & stock, clients, dettes, dépenses, rapports, **WhatsApp (QR)**, paramètres boutique, **chargement du pays OHADA**.
- Langue : détection du navigateur (FR/EN/ES), sélecteur en haut, mémorisée par compte.
- Boutique pilotée par le web ⇒ le bot WhatsApp lit/écrit directement le stock et les ventes du panel (`bot_accounts.source='web'`) ; l'APK ne pousse plus son catalogue pour cette licence.
- PWA : manifest + service worker ; installable (Android/Chrome, iPhone/iPad/Mac via Safari « Sur l'écran d'accueil »).
- Dossiers `inc/`, `modules/`, `lang/` protégés par `.htaccess` (403).

## Application de bureau (.exe / .dmg / Linux)
Voir `africompta_desktop/README.md` (Tauri v2 + GitHub Actions).

## Panel web — Lot 2 (v3.2)
Importer aussi `sql/schema_web2.sql` (fournisseurs, achats, devis/proforma, comptabilité). Pour une base existante : ce fichier suffit (CREATE IF NOT EXISTS).
Nouveaux écrans : Historique (recherche + dates + CSV), Devis/Proforma (PDF, WhatsApp, conversion en facture), Fournisseurs & achats, Comptabilité simple/double OHADA (Paramètres → Comptabilité), reçus de paiement de dettes (PDF), export JSON.

## Synchronisation APK ⇄ panel web (v3.3)
Aucun import SQL : les colonnes `uid`/`updated_at` et la table `w_tombstones` sont créées automatiquement au premier sync (le compte MySQL doit pouvoir faire ALTER TABLE).
Dans l'APK : Paramètres → « Synchronisation avec le panel web » (activer, puis « Synchroniser maintenant »). Ensuite auto : à l'ouverture de l'app et après une vente (au plus toutes les 5 min).
Synchronisés : produits (stock par deltas), clients, fournisseurs, dépenses, ventes (+ paiements de dettes), achats (+ paiements fournisseurs), devis/proforma (+ conversion), écritures manuelles et comptes personnalisés, et toutes les suppressions (suppression d'une vente/achat : stock corrigé par le support qui supprime). Anti-doublon : chaque requête a un identifiant mémorisé 7 jours (w_sync_log) ; si la réponse se perd, l'APK rejoue la même requête et le serveur renvoie la réponse sans rien ré-appliquer.
Fusion à la 1re synchro : produit/client/fournisseur identique (code-barres ou nom, téléphone) = fusionné, le stock du web fait foi.

### Conflits, fusion, archivage (v3.5)
- Fusion champ par champ : un champ modifié d'un seul côté est repris ; modifié des deux côtés = conflit : la valeur du web est conservée et le conflit apparaît dans Paramètres (web) avec « Utiliser l'appli » / « Garder le web ».
- 1re synchro : produit/client/fournisseur identique des deux côtés = fusionné ; pour le stock, l'utilisateur choisit (Paramètres APK) : web, appli ou somme.
- Produit/client/fournisseur avec historique (ventes, achats, devis) : archivé (masqué) au lieu d'être supprimé, sur l'APK comme sur le web ; l'archivage est synchronisé.

## Inventaire & gestion de stock (v3.6)
- **Registre de stock** (`w_stock_moves` / `stock_moves` APK) : stock initial, entrées, sorties manuelles, + achats, ventes et inventaires physiques. Le **CMP est rejoué exactement** depuis ce registre (plus d'approximation) ; fiche de stock par produit et journal « Mouvements » (date, opération, entrée/sortie, coût, CMP, stock, valeur).
- À la première utilisation, le stock existant est converti en mouvement « Stock initial » (coût = 1er achat connu, sinon prix d'achat fiche), automatiquement, une seule fois. Nouveau produit avec stock = stock initial ; modification du stock sur la fiche = mouvement « Correction de stock ».
- **Approvisionnement** : via Fournisseurs → Achat (stock, CMP, dette, écritures 311/401). Entrées/sorties hors achat : Comptabilité → Mouvements → « + Mouvement » (casse, vol, don, retour…).
- **Écritures** : stock initial D 311 / C 101 ; entrée D 311 / C 6031 ; sortie ou perte D 6031 / C 311 ; coût des ventes (6011/311) valorisé au CMP au moment de la vente → le compte 311 correspond à l'inventaire valorisé.
- **Inventaire valorisé (CMP)** par catégorie, valeur de vente, marge latente, rapprochement avec 311 ; **Comptages** (inventaire physique) : stock ajusté + écart valorisé au CMP.
- **PDF/impression** : tous les états (inventaire, mouvements, comptages, journal…) : bouton 🖨 PDF (web) / export PDF (APK, et détail d'un comptage).
- Tables créées automatiquement (`inv_migrate`, `stk_migrate`) ou via `sql/schema_web3.sql` ; registre et comptages se synchronisent web ⇄ APK (insertion unique ; stock via deltas produits).
- Limite résiduelle : changement de stock par fusion de synchro (politique web/appli/somme) ou achat de stock saisi comme « dépense » sans quantité apparaît en « Report (stock non tracé) ».
