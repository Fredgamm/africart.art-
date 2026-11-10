# africart.art — code maison

Ce dépôt contient uniquement le code développé pour africart.art :

| Dossier | Rôle |
|---|---|
| `wp-content/themes/voxel-child/` | Thème enfant de Voxel |
| `wp-content/plugins/africart-accueil/` | Sections de la page d'accueil (shortcodes, police, formulaire œuvre) |
| `wp-content/plugins/africart-pwa/` | Application web installable (manifest, service worker, notifications) |

Le cœur WordPress, `wp-config.php`, les médias (`uploads`), le thème Voxel et les extensions du commerce ne sont pas versionnés : ils se gèrent depuis l'admin WordPress.

## Travailler

1. Développer sur la copie locale (Local WP) ;
2. créer une branche par tâche, puis ouvrir une pull request vers `main` ;
3. à la fusion dans `main`, GitHub Actions déploie automatiquement sur la prod (`.github/workflows/deploy-prod.yml`).

Seuls les fichiers modifiés sont envoyés, par FTPS. Rien n'est supprimé sur le serveur en dehors des fichiers venant du dépôt.

## Secrets GitHub nécessaires

`FTP_USERNAME`, `FTP_PASSWORD`, `FTP_WP_CONTENT` (voir l'en-tête du workflow). Aucun mot de passe ne doit être écrit dans un fichier du dépôt.
