# Icônes AFRICART

Icônes composées à partir du logo iconographique AFRICART (bulle et « A »
navy), recadré sur son contenu puis centré sur un fond blanc opaque.

⚠️ **Ces fichiers doivent rester carrés et faire exactement la taille annoncée
dans le manifest.** Le navigateur compare la taille déclarée à la taille réelle
du fichier : si elles diffèrent, il écarte l'icône — et si toutes sont
écartées, **l'application n'est plus installable**. Le cas s'est produit en
choisissant directement le logo d'origine, qui est rectangulaire (1063 × 709).

Pour changer de visuel, ne pas déposer une image quelconque depuis la
médiathèque : produire d'abord les cinq fichiers ci-dessous aux dimensions
exactes.

Correspondance des fichiers :

| Fichier | Taille | Usage | `purpose` |
| --- | --- | --- | --- |
| `icon-192.png` | 192×192 | Écran d'accueil Android | any |
| `icon-512.png` | 512×512 | Splash screen / stores | any |
| `icon-maskable-512.png` | 512×512 | Icône adaptative Android — motif dans la zone de sécurité (80 % central) | maskable |
| `apple-touch-icon-180.png` | 180×180 | Écran d'accueil iOS (coins arrondis appliqués par iOS) | — |

Conseils :

- Icône **maskable** : le sujet doit tenir dans un cercle central de 80 % (les
  bords sont rognés selon la forme du lanceur Android). Tester sur
  [maskable.app](https://maskable.app).
- Fond opaque (pas de transparence) pour un rendu net sur tous les lanceurs.
- Splash screens iOS additionnels (optionnels) : à ajouter via
  `<link rel="apple-touch-startup-image">` (module Manifest).
