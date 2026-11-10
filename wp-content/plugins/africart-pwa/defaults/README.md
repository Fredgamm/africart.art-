# Valeurs d'usine

`factory-settings.json` contient **toutes** les valeurs par défaut du plugin.
Aucune n'est écrite dans le code : un réglage vide va chercher sa valeur ici, et
nulle part ailleurs.

## Pourquoi un fichier plutôt que du code

Trois raisons, dans l'ordre d'importance :

1. **Elles se relisent et se corrigent sans toucher au code.** Un webmaster peut
   vérifier ou ajuster une valeur d'usine sans savoir lire du PHP.
2. **Elles ne dépendent plus du site.** Le nom de l'application valait autrefois
   « nom du site » — ce qui donnait *Runabac* sur la préproduction, dont la base
   a été copiée depuis un autre projet. Une valeur d'usine ne se laisse pas
   contaminer par l'état de l'installation.
3. **Une réinitialisation redevient possible et sûre**, puisqu'il existe une
   référence explicite vers laquelle revenir.

## Jetons

Six valeurs ne peuvent pas être figées : elles dépendent de l'installation. Elles
s'écrivent entre doubles accolades et sont remplacées à la lecture.

| Jeton | Remplacé par |
| --- | --- |
| `{{plugin_url}}` | Adresse du dossier du plugin, barre oblique finale comprise |
| `{{home_url}}` | Adresse d'accueil du site, barre oblique finale comprise |
| `{{site_name}}` | Titre du site |
| `{{site_description}}` | Slogan du site |
| `{{site_lang}}` | Langue principale détectée |
| `{{site_languages}}` | Langues du site, séparées par des virgules |

Les trois derniers ne sont **volontairement pas utilisés** dans les valeurs
actuelles : ils restent disponibles, mais l'identité de l'application ne doit pas
dépendre de ce qui est saisi dans les réglages de WordPress. Ils sont là pour un
usage ponctuel, pas comme solution de repli.

Un jeton inconnu est laissé tel quel plutôt que remplacé par du vide : l'anomalie
se voit immédiatement à l'écran au lieu de produire une adresse tronquée.

## Structure

Les valeurs sont **regroupées par écran d'administration** — le regroupement sert
uniquement la lisibilité, il est aplati au chargement. Une clé peut donc être
déplacée d'un groupe à l'autre sans conséquence.

Les entrées commençant par `_` sont des annotations et sont ignorées.

## Modifier une valeur

1. Éditer `factory-settings.json` en respectant la syntaxe JSON (pas de commentaire,
   pas de virgule après le dernier élément).
2. Le changement ne touche **que les réglages laissés vides**. Un réglage saisi
   dans l'administration garde la main — et comme les écrans arrivent pré-remplis,
   **enregistrer un écran fige ses valeurs** : il ne suivra plus ce fichier
   jusqu'à la prochaine réinitialisation.
3. Pour qu'un écran reparte des valeurs d'usine, utiliser le bouton
   **« Rétablir les valeurs d'usine »** en bas de cet écran.

⚠️ **Un fichier JSON invalide priverait le plugin de toute valeur par défaut.**
Le chargeur le détecte, écrit l'erreur dans le journal de WordPress et affiche un
avertissement dans l'administration — mais les réglages non renseignés
resteraient vides d'ici la correction.
