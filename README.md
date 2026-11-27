# AfriCompta+ Desktop (Windows `.exe` · macOS `.dmg` · Linux)

Enveloppe **Tauri v2** du panel web AfriCompta+ (PHP/MySQL). Même compte, mêmes données que le web ; WhatsApp, ventes,
stock, dettes, rapports… Langue FR/EN/ES selon le système, modifiable dans l'interface.

## Compiler (GitHub Actions)
1. Mettre ce dossier dans un dépôt GitHub (ou à la racine d'un dépôt dédié).
2. *Actions → Desktop → Run workflow* (ou pousser un tag `desktop-v3.1.0` pour publier une Release).
3. Télécharger les artefacts : `.exe` (installeur NSIS, FR/EN/ES) + `.msi`, `.dmg` universel, `.AppImage` / `.deb`.

## Changer l'adresse du panel
Éditer `APP_URL` dans `dist/index.html` (par défaut `https://wiseos.alwaysdata.net/africompta/web/index.php`).

## Notes
- Installeurs **non signés** : Windows SmartScreen → « Informations complémentaires → Exécuter quand même » ;
  macOS → clic droit sur l'app → Ouvrir. Pour signer : certificat Windows + compte Apple Developer (secrets du workflow).
- iPhone / iPad / Mac : ouvrir le panel dans Safari → *Partager → Sur l'écran d'accueil* (PWA installable).
- Icônes : `npm run icons` régénère `src-tauri/icons` depuis `app-icon.png`.
