# AFRICART PWA

Transforme **africart.art** en application web installable, allège ses pages et
optimise son partage sur les réseaux sociaux.

Réf. offre **AFK-PWA-2026** · Client : Association Sabati
Éditeur : **Falicrea** — <contact@falicrea.net>

> 📘 **Pour reprendre ou faire évoluer ce code, lire [DOCUMENTATION.md](DOCUMENTATION.md).**
> Ce fichier-ci décrit *ce que fait* le plugin ; l'autre explique *pourquoi il
> est écrit ainsi* — décisions structurantes, pièges vérifiés, points de
> vigilance.

---

## Ce que fait le plugin

| Fonction | État |
| --- | --- |
| Application installable (manifest, service worker, hors connexion) | ✅ |
| Invitation à installer, adaptée à Android, iPhone et navigateurs in-app | ✅ |
| Textes en français, anglais et espagnol | ✅ |
| Allègement des images (AVIF / WebP) | ✅ |
| Chargement optimisé des polices | ✅ |
| Visuel de partage propre à chaque œuvre | ✅ |
| Description structurée des œuvres pour les moteurs | ✅ |
| Notifications push, envoyées automatiquement à la publication | ✅ |

---

## Installation

1. Déposer le dossier dans `wp-content/plugins/africart-pwa/`.
2. Activer **AFRICART PWA** dans les extensions.
3. Vérifier que ces adresses répondent :
   - `/africart-manifest.json`
   - `/africart-pwa-sw.js`
   - `/?afk_pwa=offline`

> En cas d'erreur 404 sur les deux premières : Réglages → Permaliens →
> **Enregistrer**, pour réenregistrer les règles de réécriture.

**Prérequis** : WordPress 6.0+, PHP 7.4+, HTTPS obligatoire, et la bibliothèque
GD ou Imagick avec la prise en charge d'AVIF et WebP pour l'allègement des
images (l'écran Performance indique ce que le serveur sait faire).

---

## Réglages

Menu **AFRICART PWA** dans la barre latérale de l'administration, une page par
module :

| Page | Contenu |
| --- | --- |
| **Identité** | Nom de l'application, description, raccourci |
| **Apparence** | Couleurs, mode d'affichage, orientation |
| **Icônes & visuels** | Icônes, captures de la fenêtre d'installation |
| **Installation** | Bandeau, visuels animés par plateforme, textes |
| **Performance** | Allègement des images, polices, fonds CSS, rattrapage |
| **Partage & SEO** | Visuel de partage des œuvres, champs décrits aux moteurs |
| **Notifications** | Raccordement OneSignal, centres d'intérêt, envoi automatique |
| **Langues** | Langues actives et traductions |
| **Hors-ligne & avancé** | Page hors connexion, service worker |

Un champ laissé vide reprend sa **valeur d'usine**, affichée en gris. Ces valeurs
sont regroupées dans [`defaults/factory-settings.json`](defaults/) — aucune n'est
écrite dans le code, elles se relisent et se corrigent sans savoir programmer.

Chaque écran porte en bas un lien **« Rétablir les valeurs d'usine »**, qui ne
touche qu'à cet écran.

---

## Codes courts

| Code | Effet |
| --- | --- |
| `[africart_install]` | Bouton d'installation, masqué si elle n'est pas possible |
| `[africart_notifications]` | Choix des centres d'intérêt des notifications |

---

## Commandes en ligne

```bash
wp africart images status     # état du serveur et avancement
wp africart images convert    # allègement des images, reprenable
wp africart images purge      # supprime les versions allégées
```

---

## Points de vigilance

- **Le service worker doit rester unique.** N'activez aucune extension
  enregistrant le sien : l'installation de l'application cesserait de
  fonctionner. Les notifications passent par le nôtre.
- **Les originaux ne sont jamais modifiés.** Désactiver un module rend le site à
  son état antérieur.
- **Après un changement d'icônes ou de couleurs**, Android et ordinateur se
  mettent à jour seuls (environ un jour après le prochain lancement de l'app,
  les adresses d'icônes étant versionnées). Sur iPhone, l'icône installée n'est
  jamais relue : retirer puis remettre l'application.
- **Les réseaux sociaux gardent les aperçus en mémoire.** Pour vérifier un
  partage, utiliser le débogueur de Facebook ou tester une œuvre jamais
  partagée.

---

## Structure

```text
africart-pwa/
├── africart-pwa.php     Amorçage
├── admin/               Écrans de réglages
├── includes/            Modules fonctionnels
│   └── perf/            Allègement des images et des polices
└── assets/              Service worker, scripts, styles, images
```

Détail et rôle de chaque classe : voir [DOCUMENTATION.md](DOCUMENTATION.md).
