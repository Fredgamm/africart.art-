# AFRICART PWA — manuel de maintenance

Ce document s'adresse à la personne qui reprendra ce code. Il explique
**pourquoi** les choses sont faites ainsi — le code dit déjà *comment*.

Réf. offre **AFK-PWA-2026** · Éditeur **Falicrea** · <contact@falicrea.net>

---

## 1. Le principe qui gouverne tout le plugin

Le site AFRICART tourne sur WordPress avec le thème **Voxel**, **Elementor**,
**Rank Math** et **LiteSpeed**. Chacun de ces outils fait déjà une partie du
travail.

> **Règle unique : ne jamais concurrencer l'existant, alimenter ses mécanismes.**

Concrètement, à chaque fois qu'une fonction manquait, deux voies s'offraient :
produire notre propre sortie en doublon, ou fournir à l'outil en place ce qui
lui manquait pour bien faire. **La seconde a toujours été retenue.**

Trois exemples, qui sont aussi les trois chantiers les plus délicats :

| Besoin | Ce qu'on aurait pu faire | Ce qui a été fait |
| --- | --- | --- |
| Image de partage par œuvre | Émettre nos balises `og:image` | Renseigner l'image mise en avant, que Rank Math lit nativement |
| Format d'aperçu adapté | Écraser la balise de Rank Math | Ajouter une déclinaison d'image et la placer en tête de **ses** choix |
| Images allégées | Réécrire les fichiers CSS d'Elementor | Publier une règle de priorité supérieure, sans toucher aux siens |

**Conséquence pratique : désactiver ce plugin rend le site à son état
antérieur, sans résidu.** C'est la propriété à préserver en priorité lors de
toute évolution.

---

## 2. Organisation des fichiers

```text
africart-pwa/
├── africart-pwa.php              Amorçage : constantes, chargement, activation
│
├── admin/
│   ├── class-settings.php        Menu, pages, enregistrement des réglages
│   ├── class-perf-batch.php      Points AJAX des traitements par lots
│   ├── settings.js               Barres de progression, médiathèque, couleurs
│   └── settings.css              Habillage des écrans de réglages
│
├── defaults/
│   ├── factory-settings.json     Valeurs d'usine de TOUS les réglages
│   └── README.md                 Jetons, structure, comment modifier
│
├── includes/
│   ├── class-factory-defaults.php Chargement des valeurs d'usine
│   ├── class-i18n.php            Langues et textes traduits
│   ├── class-manifest.php        Manifest de l'application
│   ├── class-service-worker.php  Service worker servi à la racine
│   ├── class-offline.php         Page hors connexion
│   ├── class-install-banner.php  Invitation à installer l'application
│   ├── class-onesignal.php       Notifications push
│   ├── class-push-sender.php     Envoi automatique à la publication
│   ├── class-social-meta.php     Visuel de partage et données structurées
│   ├── class-share-image.php     Image d'aperçu dédiée aux réseaux
│   │
│   └── perf/                     Allègement des pages
│       ├── class-image-converter.php   Conversion AVIF / WebP
│       ├── class-image-variants.php    Nommage et existence des variantes
│       ├── class-image-queue.php       Quand convertir, et le décompte
│       ├── class-image-rewriter.php    Proposer les variantes dans les pages
│       ├── class-css-backgrounds.php   Fonds déclarés en feuille de style
│       ├── class-fonts.php             Chargement des polices
│       └── class-cli.php               Commandes en ligne
│
└── assets/
    ├── sw/service-worker.js      Cache, hors-ligne, notifications
    ├── js/install.js             Fenêtre d'installation native
    ├── js/onesignal.js           Raccordement des notifications
    ├── css/install.css           Bandeau et préférences de notification
    ├── icons/                    Icônes de l'application
    └── screenshots/              Captures de la fenêtre d'installation
```

**Chaque classe a une responsabilité unique.** Le module Performance en est
l'illustration : le convertisseur ignore WordPress, le registre des variantes
ignore la conversion, la file d'attente ignore la manière de convertir. Cette
séparation permet de remplacer une pièce sans toucher aux autres.

---

## 3. Les décisions structurantes

Ces choix ne sont pas évidents à la lecture du code. Les remettre en cause sans
connaître leur raison casserait des choses.

### 3.1 Un seul service worker, et c'est le nôtre

Un navigateur n'accepte **qu'un seul service worker par portée**. Or
l'installation de l'application *et* les notifications le réclament tous les
deux.

Laisser OneSignal enregistrer le sien remplacerait le nôtre : l'installation et
le mode hors connexion cesseraient de fonctionner. **Notre service worker reste
donc le seul enregistré et importe le SDK de OneSignal** (`assets/sw/service-worker.js`),
tandis que le SDK côté page reçoit le chemin de notre fichier.

⚠️ Ne jamais activer l'enregistrement automatique du service worker de
OneSignal, ni d'une autre extension de notifications.

### 3.2 Les œuvres sont des fonds CSS, pas des images

Voxel et Elementor rendent chaque vignette avec un fond en style direct :

```html
<div style="background-image: url('…/oeuvre.jpg')" class="elementor-…">
```

La page d'accueil ne contient que **trois** balises `<img>` — le logo. Trois
conséquences :

- `<picture>` et `srcset` sont **inapplicables** aux œuvres ;
- les outils qui réécrivent des attributs d'image ne les atteignent pas ;
- les audits de performance affirment à tort que les images sont bien
  dimensionnées : ils n'examinent que les balises `<img>`.

D'où le recours à `image-set()` en CSS (voir 3.3).

### 3.3 Ne déclarer que les variantes réellement présentes

**Règle absolue, vérifiée en test.** Le navigateur retient la première entrée
d'un `image-set()` dont il sait lire le type, puis **ne se rabat pas** si le
fichier renvoie une erreur : le fond reste vide, l'image est perdue.

```css
/* La 1re ligne protège des navigateurs anciens.
   Elle NE protège PAS d'un fichier manquant. */
background-image: url("oeuvre.jpg");
background-image: image-set(
  url("oeuvre.jpg.avif") type("image/avif"),   /* seulement si le fichier existe */
  url("oeuvre.jpg.webp") type("image/webp"),   /* idem */
  url("oeuvre.jpg")      type("image/jpeg")
);
```

L'ordre de déclaration **est** l'ordre de priorité — vérifié en inversant les
entrées : le navigateur suit notre ordre, il n'a pas de préférence propre.

C'est pourquoi `Africart_PWA_Image_Variants::existing()` vérifie la présence sur
le disque avant toute déclaration, et pourquoi une variante refusée (parce que
pas plus légère) ne doit **jamais** figurer dans la liste.

### 3.4 Le cache de l'hébergeur ignore les en-têtes de négociation

Première architecture envisagée : une seule adresse par image, le serveur
choisissant le format selon ce que le navigateur déclare accepter. Le mécanisme
fonctionne — testé — **mais le cache de l'hébergeur n'en tient pas compte** et
servirait un AVIF à un navigateur incapable de le lire.

**Chaque format a donc sa propre adresse**, et le choix se fait dans le
navigateur. C'est aussi ce qui permet à la page de rester parfaitement
cacheable : une seule page pour tous les visiteurs.

### 3.5 Les réglages sont répartis sur plusieurs pages, mais partagent une option

Depuis le passage au menu principal, chaque module a sa page. Toutes écrivent
pourtant dans la **même** option `afk_pwa_settings`.

Sans précaution, valider une page effacerait les réglages de toutes les autres.
Pire pour les cases à cocher : un navigateur **ne transmet pas** une case
décochée, donc une simple fusion les figerait à jamais.

La parade, dans `Africart_PWA_Settings::sanitize()` :

1. chaque page déclare ses clés dans `pages()` ;
2. un champ caché `_afk_page` indique la page soumise ;
3. l'enregistrement fusionne avec l'existant et **ne remet à zéro que les cases
   de la page réellement soumise**.

⚠️ **En ajoutant un réglage, l'inscrire dans `pages()`** — sinon il sera perdu
au premier enregistrement d'une autre page.

### 3.6 Les valeurs d'usine sont des données, pas du code

Toutes les valeurs par défaut vivent dans **`defaults/factory-settings.json`**.
`Africart_PWA_Settings::defaults()` ne fait que déléguer à
`Africart_PWA_Factory_Defaults::all()` — **il ne reste aucune valeur écrite en
dur dans le code**, et c'est délibéré.

Trois problèmes constatés l'ont motivé :

1. **Illisibilité.** Elles étaient noyées dans une méthode de 90 lignes que seul
   quelqu'un lisant le PHP pouvait vérifier.
2. **Contamination par le site.** `app_name` valait « nom du site », ce qui
   affichait **Runabac** sur la préproduction — dont la base a été copiée depuis
   un autre projet. Une valeur d'usine ne doit pas dépendre de l'état de
   l'installation.
3. **Aucune remise à zéro possible**, faute de référence explicite.

Six jetons (`{{plugin_url}}`, `{{home_url}}`…) couvrent ce qui ne peut pas être
figé. Un jeton inconnu est **laissé tel quel** plutôt que remplacé par du vide :
l'anomalie se voit à l'écran au lieu de produire une adresse tronquée.

Le lien **« Rétablir les valeurs d'usine »** de chaque écran **retire** les
réglages de l'option au lieu d'y recopier les valeurs du fichier. La nuance
compte : l'écran suit ainsi toute modification ultérieure du fichier, qu'une
recopie aurait figée. L'écran Notifications efface en outre la clé d'envoi, qui
vit hors de ces réglages — sans quoi une clé oubliée survivrait à une remise à
zéro.

> ⚠️ Le cache de `all()` est indispensable : `get()` est appelé des dizaines de
> fois par page, et sans lui chaque appel relirait le fichier.

**Les écrans affichent la valeur qui s'applique réellement**, pas la valeur
enregistrée. Auparavant un réglage jamais saisi donnait un champ vide, la valeur
d'usine n'apparaissant qu'en indice grisé — et un sélecteur de couleur ne sait
pas afficher d'indice : il paraissait vide alors qu'une couleur était bien en
vigueur.

`render_shell()` reproduit donc la règle de `get()` pour construire les valeurs
affichées. La recopie est volontaire : appeler `get()` en boucle relirait
l'option à chacune des soixante clés.

> ⚠️ **Conséquence à connaître** : puisque les champs sont pré-remplis, le
> premier enregistrement d'un écran inscrit ces valeurs explicitement dans
> l'option. Cet écran ne suit alors plus le fichier d'usine — c'est précisément
> ce que le lien « Rétablir les valeurs d'usine » défait.

Cinq champs restent vides, et c'est voulu :

| Champ | Raison |
| --- | --- |
| `install_gif_android` | Android ouvre une vraie fenêtre d'installation, il n'y a pas de geste à montrer |
| `install_gif_inapp` | L'installation y est impossible ; le message invite à ouvrir ailleurs |
| `os_app_id`, `os_safari_id` | Propres au compte OneSignal, ils ne peuvent pas avoir de valeur d'usine |
| `os_auto_prompt` | Une demande surgissant dès l'arrivée est massivement refusée, et un refus est quasi définitif |

Un champ de visuel vide affiche l'icône de l'application : c'est le comportement
voulu, pas une valeur oubliée.

### 3.7 Les listes répétables suivent toutes le même mécanisme

Deux réglages sont des listes de longueur libre : les **déclencheurs de
notification** et les **fonds déclarés en feuille de style**. Les lignes s'y
ajoutent et s'y retirent sans enregistrement intermédiaire.

`initRepeater()` (dans `settings.js`) sert les deux, paramétré par le champ qui
porte l'indice. Côté PHP, une même fonction rend l'affichage **et** le modèle
cloné par le script — `trigger_row()`, `css_background_row()` — pour que les deux
ne divergent jamais.

Deux détails sans lesquels ces champs paraîtraient fonctionner tout en perdant
des données :

- **L'indice d'une nouvelle ligne est le plus grand indice présent + 1**, pas le
  nombre de lignes : après une suppression, un décompte réattribuerait un indice
  déjà pris et les deux lignes s'écraseraient à l'envoi.
- **Un témoin caché est toujours transmis.** Un formulaire n'envoie rien pour un
  champ absent : sans ce témoin, retirer la dernière ligne laisserait l'ancienne
  liste en place et il serait impossible de tout supprimer. Les fonctions de
  nettoyage écartent ce témoin en refusant toute entrée qui n'est pas un tableau.

⚠️ **En ajoutant une liste répétable**, reprendre ces trois éléments : le témoin,
le modèle `__i__`, et le rejet du témoin au nettoyage.

### 3.8 Ne jamais bloquer le dépôt d'une œuvre

L'encodage AVIF coûte environ **2 secondes par fichier**, et WordPress produit
une dizaine de déclinaisons par média. Convertir pendant l'envoi figerait le
formulaire une quinzaine de secondes — inacceptable pour un artiste qui
enregistre son œuvre.

La conversion est donc **mise en file d'attente** et traitée juste après. Tant
qu'une variante n'existe pas, l'original est servi : l'affichage n'est jamais
cassé, seulement moins optimisé quelques secondes.

---

## 4. Les modules, un par un

### 4.1 Cœur de l'application

| Fichier | Rôle |
| --- | --- |
| `class-manifest.php` | Sert `/africart-manifest.json`, injecte les balises du `<head>` |
| `class-service-worker.php` | Sert `/africart-pwa-sw.js` avec sa configuration |
| `class-offline.php` | Sert `/?afk_pwa=offline` |

Les deux premiers passent par des **règles de réécriture** pour être servis à la
racine du site : c'est indispensable pour que le service worker obtienne la
portée `/`.

⚠️ Si ces adresses renvoient une erreur 404 après une modification, il faut
**réenregistrer les permaliens** (Réglages → Permaliens → Enregistrer).

⚠️ Ces trois modules s'exécutent en priorité `0` sur `template_redirect`, **avant**
la redirection canonique de WordPress — sans quoi elle ajouterait une barre
oblique finale et casserait les adresses.

### 4.2 Langues (`class-i18n.php`)

Le site traduit ses pages avec le widget Google Translate, qui mémorise la
langue dans le cookie `googtrans`. Le module lit ce cookie et sert les textes de
l'application dans la bonne langue.

Les langues sont **détectées dans la configuration du widget** : si le site en
ajoute une, l'application suit. Le réglage « Langues actives » permet de forcer
la liste.

Trois niveaux pour chaque texte : traduction saisie dans l'admin → traduction
embarquée (en, es) → repli sur le français.

**La page hors connexion embarque les textes de toutes les langues** et bascule
côté navigateur : elle est mise en cache une seule fois et doit rester correcte
sans réseau.

### 4.3 Installation (`class-install-banner.php`)

Un site **ne peut pas** ouvrir seul la fenêtre d'installation : le navigateur
exige un geste de l'utilisateur. On capte l'événement `beforeinstallprompt`, on
affiche notre bandeau, et le clic déclenche la fenêtre **native**.

Trois parcours, car ils sont réellement différents :

- **Android / ordinateur** : fenêtre native ;
- **iPhone / iPad** : Safari n'en propose aucune, on affiche les instructions ;
- **Navigateurs Instagram, Facebook…** : l'installation y est **impossible**, on
  invite à ouvrir dans le vrai navigateur. Essentiel : c'est par là qu'arrive le
  public visé.

Un visuel animé distinct par plateforme, le geste n'étant pas le même.

**Samsung Internet** : à l'installation, Play Protect affiche « Appli non
sécurisée bloquée » — le paquet fabriqué par Samsung n'est pas reconnu par
Google. Problème du navigateur, constaté sur toutes les PWA (Progressier
redirige carrément vers Chrome depuis mars 2026), hors de notre portée.
« Installer quand même » fonctionne ; le bandeau affiche une note dédiée
(`install_samsung`, traduite) dans ce navigateur seulement, pour que le
visiteur n'abandonne pas devant l'avertissement.

**Mesure des installations et des lancements** — quatre événements partent vers
l'outil de statistiques déjà en place (Site Kit / Google Analytics) :
`pwa_install_click`, `pwa_install_accepted` / `pwa_install_dismissed`,
`pwa_installed`, et `pwa_launch` (une fois par session, pas à chaque page).

Jamais par un paramètre d'URL : mesuré en production, **tout paramètre fait
contourner le cache de bordure** — un `?utm_source=pwa` sur l'URL de démarrage
ferait payer 1 à 2 s à chaque lancement de l'app au lieu de 70 ms. L'URL de
démarrage est donc **propre**, et la détection se fait en JavaScript
(`display-mode: standalone`). Le pied de page standard de gtag met les appels
en file (`dataLayer`) : l'événement part même quand le script — différé au
premier geste — n'est pas encore chargé.

La hauteur du visuel est exposée par **`gif_height()`** au lieu d'être calculée
sur place : l'aperçu de l'administration l'appelle aussi. Un aperçu qui diverge
du rendu réel est pire que pas d'aperçu du tout.

> D'anciennes installations portent un `0` en base pour cette hauteur, hérité
> d'une version où un champ vidé s'enregistrait ainsi. `gif_height()` le ramène à
> la valeur d'usine — sans quoi le visuel serait invisible.

### 4.4 Barre d'état iOS et zone protégée

Constaté sur iPhone : le haut de la page passait **sous l'heure et la
batterie**. La cause était notre propre méta
`apple-mobile-web-app-status-bar-style`, réglée sur `black-translucent` — ce
mode demande explicitement à iOS d'étendre le contenu sous la barre d'état.

Le réglage est désormais **au choix** (écran Apparence), valeur d'usine
`default` : iOS réserve la place de la barre, et rien ne se cache dessous.

Choisir `black-translucent` reste possible pour un rendu bord à bord. Le
plugin ajoute alors ce qu'il faut pour que ce mode soit vivable :

- une marge haute `env(safe-area-inset-top)` sur le corps de page, **et
  seulement en mode application** — dans un onglet, la barre d'état
  n'appartient pas à la page ;
- `viewport-fit=cover` ajouté au viewport **en JavaScript**. C'est le piège :
  sans cet attribut, `env(safe-area-inset-*)` vaut **zéro** et le correctif CSS
  n'a aucun effet. Le thème écrivant son propre viewport, on le complète plutôt
  que de le remplacer.

### 4.5 Aperçus de l'administration

Quatre écrans montrent le résultat des réglages, mis à jour **pendant la
saisie** : l'écran de lancement (couleurs, nom, mode d'affichage), le bandeau
d'installation (textes, position, visuel, par plateforme), la carte de partage
(par canal, lue sur la page réelle) et la fenêtre d'invitation aux
notifications — dont les centres d'intérêt se déplient comme chez le visiteur,
et qui signale l'absence de page de confidentialité.

Ce sont des **reproductions en CSS, pas des captures du site** : afficher le vrai
rendu demanderait une requête à chaque frappe. Les écrans le disent, pour que
personne n'y voie une garantie.

L'aperçu occupe une **colonne à droite des réglages**, dans l'espace laissé libre
par les tables de formulaire — étroites par nature. Il suit le défilement, pour
rester lisible pendant qu'on modifie un champ situé plus bas.

Trois contraintes non évidentes tiennent cette mise en page :

- `preview_html()` met l'aperçu **en tampon** avant de le placer : les écrans
  produisent leurs cartes dans un seul flux, il fallait l'en extraire ;
- l'aperçu vient **après** les réglages dans le document, pour que la lecture au
  clavier commence par le formulaire ;
- il reste **à l'intérieur du `<form>`**, faute de quoi la saisie ne s'y
  refléterait pas — l'écouteur est posé sur l'ensemble de la page.

Sous 1 300 px, deux colonnes deviennent illisibles : l'aperçu repasse sous les
réglages et cesse de suivre le défilement.

**L'aperçu du bandeau se parcourt par plateforme** — Android/ordinateur,
iPhone/iPad, navigateurs Instagram/Facebook — chaque onglet montrant l'animation
et le message de son parcours ; le bouton n'apparaît que là où la fenêtre
native peut s'ouvrir.

**L'aperçu des partages est d'une autre nature.** Il ne reproduit pas des
réglages : le serveur **lit la page réelle** du contenu choisi (avec le
user-agent d'un robot de réseau social, pour traverser les mêmes règles de
cache) et en extrait les balises Open Graph. Reconstituer les balises depuis
les réglages montrerait ce que le plugin *croit* produire, pas ce qui est
produit — un aperçu qui a raison quand le site a tort ne détecte rien.

Quatre canaux — Instagram, Facebook, WhatsApp, LinkedIn — habillent la même
carte via `data-canal` : mêmes quatre éléments (image, domaine, titre,
description), compositions différentes. LinkedIn masque la description,
Facebook place le domaine en majuscules au-dessus du titre, Instagram prend la
forme d'un message privé — le fil n'accepte pas de lien.

> Les réseaux gardent leurs aperçus en mémoire : un contenu déjà partagé peut
> montrer une version antérieure chez eux, même quand notre aperçu est juste.
> Le débogueur de partage de Facebook force le rafraîchissement.

Le lien entre un réglage et l'aperçu est déclaré **dans le balisage**, pas dans le
script :

| Attribut | Effet |
| --- | --- |
| `data-preview="clé"` | La couleur de fond de l'élément suit ce réglage |
| `data-preview-text="clé"` | Le texte de l'élément suit ce réglage |

Ajouter un élément à un aperçu ne demande donc aucune modification de
`settings.js`. Un champ vidé retombe sur son indice grisé, c'est-à-dire sur la
valeur d'usine — exactement ce que verra le visiteur.

### 4.5 Performance (`includes/perf/`)

Voir les décisions 3.2, 3.3, 3.4 et 3.8.

**Chargement différé des fonds** (`perf_lazy`) — les œuvres étant des fonds
CSS, `loading="lazy"` ne s'y applique pas : le navigateur télécharge tous les
fonds, y compris hors écran. Le réécrivain sort la déclaration
`background-image` du style (attribut `data-afk-bg`) et `lazy-bg.js` la
restitue à l'approche — marge de 400 px, valable aussi horizontalement : une
diapositive de carrousel hors champ ne charge son image qu'à l'approche de son
tour. Les N premiers fonds de la page (`perf_lazy_eager`, usine : 4) restent
immédiats pour ne pas retarder ce qui est visible dès l'arrivée.

Mesuré à l'activation (médiane de 3 passes Lighthouse mobile, préproduction) :
score 62 → 67, LCP 8,6 s → 6,6 s sur l'accueil ; `/oeuvres/` passe de ~1,3 Mo
à 344 Ko d'images à l'arrivée.

Pendant l'attente, l'élément affiche un **squelette** — fond neutre parcouru
d'un reflet (`lazy-bg.css`) : l'attribut `data-afk-bg` sert de crochet, sa
disparition à la restitution emporte le squelette. Aucune classe à poser, et
les `!important` ne vivent que pendant l'attente.

> ⚠️ Sans JavaScript, un fond différé ne s'affiche jamais — le site (Elementor,
> Voxel) exige déjà JavaScript partout, le compromis est assumé. Et si le
> script venait à manquer, seuls les fonds au-delà du quota seraient touchés.

**Déclinaison mobile des fonds déclarés** — pour chaque entrée de fond CSS, le
module cherche le fichier frère `nom-768x*.ext` (la taille intermédiaire que
WordPress produit) et, s'il existe avec ses variantes : la règle publiée gagne
un bloc `@media (max-width:767px)` servant la déclinaison, et chaque gabarit
précharge la sienne — l'attribut `media` du preload évite qu'un téléphone
télécharge les deux. Mesuré sur le bandeau : 256 → 124 Ko en AVIF mobile,
téléchargement de l'élément LCP raccourci d'un quart.

**Scripts tiers différés** (`perf_delay`, `class-script-delay.php`) — mesuré :
63 % du JavaScript de l'accueil n'est jamais exécuté, et les plus gros postes
sont des tiers (gtag 424 Ko de source, Google Translate 260 Ko, SDK OneSignal
138 Ko). Leurs balises deviennent `type="afk/delay"` dans le tampon de sortie —
un type inconnu, ni téléchargé ni exécuté — et `delay.js` les restitue au
premier geste, ou à l'échéance d'un délai de secours.

Trois précautions qui rendent le report sûr :

- gtag met ses événements en file (`dataLayer`) : la page vue se mesure malgré
  le retard ;
- notre raccordement OneSignal passe par `OneSignalDeferred`, la file
  officielle : l'initialisation part à l'arrivée du SDK ;
- un visiteur **déjà traduit** (cookie `googtrans`) est servi immédiatement —
  sa langue ne doit pas attendre son geste.

Les scripts visés se règlent depuis l'écran Performance, un fragment d'adresse
par ligne. Mesuré à l'activation : score 79 → 86, LCP 4,1 → 3,1 s (médianes de
3, accueil préprod mobile).

**Règles codées en dur, issues de mesures :**

- l'original n'est **jamais** modifié ni supprimé ;
- une variante **pas plus légère** que l'original est supprimée et refusée — le
  cas s'est produit sur des JPEG déjà bien compressés.

**Polices** : les déclarations d'Elementor sont republiées après les siennes,
avec `font-display: swap` et le fichier `.woff2` quand il existe. Le serveur ne
sait pas produire de `.woff2` — les fichiers sont déposés à la main à côté des
`.woff`. Sans `.woff2`, la police est servie telle quelle, rien n'est dégradé.

### 4.6 Partage et référencement

`class-social-meta.php` — Les œuvres n'ont pas d'image mise en avant : leur
visuel est dans un champ du thème. On la renseigne depuis ce champ, ce qui suffit
à ce que Rank Math, les flux et tout outil futur trouvent la bonne image.

**Nom transmis aux réseaux** — l'écran compare le nom de site de Rank Math
(`og:site_name`) au nom de l'application, et n'affiche une carte qu'en cas de
divergence. Particularité vérifiée : le champ « Nom du site web » de Rank Math
n'est visible qu'avec son module **SEO local**, désactivé sur ce site — la
valeur dort en base sans écran pour la modifier. La carte porte donc un bouton
qui écrit la correction (`website_name` + `knowledgegraph_name`) exactement là
où cet écran l'aurait fait, **sur clic explicite seulement** : le réglage
appartient à Rank Math, jamais de correction silencieuse.

Une image déjà définie n'est **jamais** remplacée.

`class-share-image.php` — Les robots des messageries renoncent au bout de
quelques secondes et attendent un format paysage. On produit donc **à l'avance**
une image d'aperçu de 1200 × 630, environ 60 Ko.

L'œuvre y est inscrite **en entier** sur un fond de la charte, jamais recadrée :
pour un répertoire d'œuvres, montrer un fragment trahirait le travail de
l'artiste. C'est le `min()` dans `render()` qui garantit ce comportement — le
remplacer par `max()` produirait un recadrage.

### 4.7 Mesure d'audience (`class-analytics.php`, `assets/js/mesure.js`)

Le plugin **ne mesure rien pour son compte** et n'envoie rien à Google. Il
annonce des faits dans le `dataLayer` — bandeau affiché, installation faite,
abonnement pris — et laisse le gestionnaire de balises décider. C'est le seul
découpage qui permette de changer d'outil de mesure sans toucher au site.

> ⚠️ **`gtag.js` ne lit pas les événements de GTM, et GTM ne lit pas les appels
> `gtag`.** Les deux partagent le tableau `dataLayer` mais pas son contenu :
> `gtag` y écrit des arguments positionnels (`['event', nom, params]`), GTM y
> attend un objet nommé (`{event: nom, …}`). Émettre la mauvaise forme ne
> produit **aucune erreur** : l'événement disparaît, et on cherche longtemps du
> côté du déclencheur.

D'où une détection à l'exécution. Elle ne peut pas reposer sur
`window.google_tag_manager`, que `gtag.js` renseigne aussi avec sa propre clé
`GT-…` : seul un identifiant commençant par **`GTM-`** atteste d'un conteneur
capable de lire nos objets. Vérifié — sans conteneur, `gtag.js` pousse quand
même `gtm.dom` et `gtm.load`.

Conséquence pratique : un site qui passe de `gtag` à GTM n'a rien à changer, et
rien ne s'interrompt pendant la bascule.

| Réglage | Rôle |
| --- | --- |
| `stats_enabled` | Éteint, le plugin n'émet plus rien ; les autres mesures du site ne sont pas affectées |
| `stats_gtm_id` | Validé sur `/^GTM-[A-Z0-9]{4,}$/` — un identifiant approximatif est **ignoré** plutôt que posé |
| `stats_inject` | À n'activer que si aucune autre extension ne pose le conteneur : deux conteneurs identiques comptent chaque visite deux fois, sans avertissement |
| `stats_consent` | Signale que les balises doivent être conditionnées au consentement |

Neuf événements, listés dans l'écran de réglages — cette liste **est** la
documentation, elle sert à construire les déclencheurs et il n'existe pas
d'autre endroit où la lire.

L'origine sociale (`afk_source_social`) se déduit d'abord du navigateur intégré,
qui ne peut pas être usurpé par un lien, puis du référent. Émise **une fois par
session** : compter à chaque page mesurerait la navigation, pas l'acquisition.
L'émetteur est chargé dans l'en-tête pour cette raison — vérifié, l'événement
part avant même `gtag.js`, retenu par la file du `dataLayer`.

#### Bandeau de consentement

Aucun dispositif n'existait sur le site — vérifié : ni extension de
consentement, ni mode consentement. GA4 se chargeait donc sans accord.

**Le refus est l'état initial.** Pas « en attente », pas « toléré le temps du
choix » : refusé. C'est aussi ce que le mode consentement de Google permet
d'exprimer, et les balises posées dans le gestionnaire s'y conforment sans
réglage supplémentaire.

> ⚠️ La déclaration `consent default` doit être le **premier** script de la
> page — d'où `wp_head` priorité 0, avant le conteneur. Le mode consentement
> fonctionne par antériorité : une balise chargée avant cette déclaration n'en
> tient aucun compte. Le symptôme est indétectable à l'œil, le bandeau
> s'affiche et tout paraît correct, mais la mesure est déjà partie.

Deux pièges rencontrés et corrigés, tous deux invisibles à la lecture :

| Piège | Ce qui se passait | La parade |
| --- | --- | --- |
| `mesure.js` est chargé dans l'en-tête, `consentement.js` en pied de page | Au moment où l'origine sociale s'annonce, `afkConsentOK` n'existe pas encore. Tester sa présence avant de l'interroger laissait passer l'événement — **vérifié : il partait malgré un refus** | L'absence de la fonction vaut **refus**, pas dispense. L'origine est annoncée après l'accord, jamais avant |
| Les deux bandeaux occupent le même bas d'écran | L'invitation à installer se superposait à la question du consentement | L'installation patiente sur l'événement `afkConsentTranche`, sans rien perdre |

Les deux boutons partagent la largeur à parts strictement égales (`flex: 1 1 0`,
mesuré 162 × 42 px chacun). Ce n'est pas une question de goût : un refus plus
discret que l'acceptation vicie le consentement et suffit à rendre le bandeau
inopposable.

### 4.8 Notifications

Voir la décision 3.1.

La demande d'autorisation **ne surgit jamais d'elle-même**. Elle part de la
fenêtre d'invitation, qui explique d'abord — une invite nue dès l'arrivée est
massivement refusée, et un refus est quasi définitif, le navigateur ne
reproposant plus rien.

> ⚠️ L'ancien mécanisme de demande automatique (`os_auto_prompt`,
> `os_prompt_delay`) a été **retiré**. Il faisait double emploi avec la
> fenêtre, et les deux actifs ensemble auraient servi au visiteur l'invite nue
> puis la fenêtre, à quelques secondes d'intervalle. Ne pas le réintroduire :
> la fenêtre fait la même chose, en mieux.

Chaque centre d'intérêt devient une étiquette OneSignal. Une catégorie décochée
est mise à « 0 » plutôt que supprimée, pour distinguer un refus d'une absence de
choix.

#### Les étiquettes sont écrites par le serveur, pas par le SDK

`User.addTags()` ne les transmet pas. Constaté sur le site : **seize
abonnements, aucune étiquette** côté OneSignal — alors que `getTags()` les
rendait fidèlement dans le navigateur, depuis son cache local.

La cause n'était pas dans le code : le forfait **plafonne à trois étiquettes par
abonné**. Nous en envoyions quatre, une par centre d'intérêt. OneSignal rejette
alors la requête **entière** (`400 — App is limited to a maximum of 3 tags on a
given player`), et le SDK avale l'erreur sans rien dire.

Le symptôme est le pire qui soit : **tout paraît fonctionner**. Les cases se
rouvrent cochées, la fenêtre confirme l'enregistrement — et décocher un centre
d'intérêt ne change rien aux notifications reçues, puisque le ciblage porte sur
des étiquettes qui n'existent pas.

Deux conséquences, dans `class-push-tags.php` :

| | |
| --- | --- |
| Qui écrit | **WordPress**, par l'API, avec la clé qui ne quitte jamais le serveur. Le navigateur ne transmet que son identifiant d'abonnement et les cases cochées |
| Combien | `MAX_TAGS = 3`. Au-delà, `write()` refuse avec un message clair plutôt que de laisser OneSignal rejeter en silence |

Une étiquette par centre d'intérêt reste le choix retenu malgré la contrainte :
c'est la seule forme que l'interface OneSignal sait lire, et donc la seule qui
permette d'y **construire des segments à la main**. Un encodage compact en
tiendrait davantage, au prix de valeurs illisibles dans le tableau de bord.

#### Le ciblage est formulé à l'envers

Reçoivent tous ceux qui n'ont **pas refusé** :

```php
tag oeuvres != 0   OR   tag oeuvres not_exists
```

⚠️ Viser `tag = 1` paraît plus naturel et ne marche pas. Un abonné qui a accepté
par l'invite du navigateur, sans jamais ouvrir la fenêtre de choix, ne porte
aucune étiquette : il serait exclu de tout. C'est le cas de la quasi-totalité
des abonnés. **Absence de choix n'est pas refus.**

Les deux conditions sont nécessaires : `!= 0` ne retient pas les fiches
dépourvues de l'étiquette, d'où `not_exists` en alternative.

Vérifié : 13 destinataires pour « œuvres », 12 pour « artistes » après avoir posé
`artistes: "0"` sur un abonné — un de moins, exactement.

#### Onglet « Abonnés » (`admin/class-subscribers.php`)

L'écran Notifications porte deux onglets : **Paramétrage** et **Abonnés**. Les
abonnés ne sont pas un réglage — ils partagent seulement le sujet, d'où un
onglet plutôt qu'une dixième entrée de menu, où « Abonnés » seul n'aurait pas
dit de quoi il s'agissait.

La barre est rendue par `Africart_PWA_Settings::render_push_tabs()`, appelée par
les deux vues : une barre commune, plutôt que deux qui divergeraient d'un pixel.

Le tableau de bord OneSignal sait dire *combien* d'abonnés existent, jamais
*qui*. Sur un site où les artistes ont un compte, c'est l'information qui
manque.

L'écran **ne stocke rien** : il lit les abonnements chez OneSignal — paginés
par 300, gardés cinq minutes en mémoire courte — et les rapproche des comptes
du site. Un bouton *Actualiser* vide cette mémoire.

Quatre chiffres : abonnements, joignables, rattachés à un compte, et *ont
choisi* — ceux qui ont réellement ouvert la fenêtre de préférences. L'écart
entre les deux derniers dit l'essentiel : la quasi-totalité des abonnés n'a
jamais rien choisi, ce qui est précisément pourquoi le ciblage doit compter
l'absence de choix comme une acceptation.

La portée par centre d'intérêt applique la **même règle que l'envoi** — sans
quoi l'écran annoncerait un nombre de destinataires que la campagne ne
retrouverait pas.

Un centre d'intérêt refusé s'affiche **barré**, pas effacé : la différence avec
« jamais choisi » doit rester lisible d'un coup d'œil.

Les segments OneSignal sont listés à titre indicatif. Le site ne s'en sert pas
pour ses envois automatiques : il cible par centre d'intérêt, ce qui reste juste
même quand un abonné n'a rien choisi — un segment fondé sur une étiquette
exclurait tout le monde.

> ⚠️ Sur le forfait gratuit, les segments fondés sur des *événements de message*
> (« a cliqué une notification ») sont refusés : `Message event cannot exceed
> the data retention of 0 days`. Les segments fondés sur la dernière session,
> eux, fonctionnent — c'est ce qu'est déjà `Active Subscriptions`.

#### Rapprochement avec les comptes du site

Un abonné connecté se fait connaître (`afk_push_link`). Le lien est enregistré
des deux côtés : méta utilisateur `_afk_push_subs` — qui alimente
l'administration et survit à une panne de l'API — et `external_user_id` chez
OneSignal, qui permet de viser un membre précis depuis le tableau de bord.

Un même compte porte plusieurs abonnements : ordinateur, téléphone, application
installée. La méta est donc une liste.

#### Une seule fenêtre, deux usages

La fenêtre n'est pas réservée à l'invitation. Elle est **émise dès que les
notifications sont actives**, et non plus seulement quand l'affichage
spontané est demandé — car un second chemin l'ouvre : la cloche.

| | Mode invitation | Mode gestion (classe `afk-push-gestion`) |
| --- | --- | --- |
| Déclenché par | Le délai d'affichage | Le clic sur la cloche |
| Titre | « Restez informé » | « Vos notifications » |
| Introduction | Visible | Masquée — le visiteur sait déjà de quoi il retourne |
| Centres d'intérêt | Repliés | **Dépliés**, et cochés d'après les étiquettes réelles |
| Bouton | « M'abonner » → demande l'autorisation | « Enregistrer mes choix » → écrit les étiquettes, sans rien redemander |
| Désabonnement | Masqué | Visible |
| Fermer | Mémorise un refus | **Ne mémorise rien** — regarder ses réglages n'est pas refuser |

Le découpage du code suit cette dualité :

- `bindModal()` branche **sans condition** la croix, « Plus tard », le fond,
  Échap, le dépliage et le bouton principal.
- `bindPopup()` ne décide que du *faut-il l'ouvrir d'elle-même, et quand*.
- `bindCloche()` bascule la fenêtre en mode gestion, puis l'ouvre.

> ⚠️ Ce découpage n'est pas cosmétique. Tant que les fermetures étaient
> branchées dans `bindPopup()`, elles disparaissaient avec lui dès que le
> contexte interdisait l'invitation spontanée — réglage « application
> uniquement », consulté depuis un navigateur ordinaire. La cloche ouvrait
> alors une fenêtre **sans aucune issue**.

#### La cloche flottante

Un abonné n'avait aucun moyen de revenir sur son choix sans passer par les
réglages du navigateur. Une pastille discrète en bas à gauche — 38 px,
opacité `.55`, au-dessus de la zone protégée — le lui rend.

Elle n'apparaît **que pour les abonnés** (`User.PushSubscription.optedIn`), et
suit l'événement `change` : s'abonner la fait paraître, se désabonner la fait
disparaître, sans rechargement.

> ⚠️ Son écouteur de clic est posé **immédiatement**, pas dans
> `auSDK().then(...)`. Le SDK OneSignal est un script tiers différé au premier
> geste : attendre son arrivée pour brancher le clic laissait la cloche visible
> mais inerte. Le SDK est réclamé *dans* le gestionnaire, où l'attente est sans
> conséquence.

La désactiver dans les réglages prive le visiteur de son seul chemin de
désabonnement — l'administration le signale explicitement.

#### Envoi automatique (`class-push-sender.php`)

Le site peut prévenir lui-même les abonnés à chaque publication, sans passer par
le tableau de bord OneSignal.

**Les déclencheurs ne sont pas codés en dur.** Quatre besoins ont été exprimés —
œuvres, artistes, expositions, promotions — mais seuls les deux premiers
correspondent à un type de contenu existant. Un déclencheur est donc une ligne de
réglage associant *un type de contenu*, *un centre d'intérêt* et *un intitulé* :
créer un type « exposition » suffira à le rendre notifiable, sans toucher au code.

Trois garde-fous, chacun répondant à un incident prévisible :

| Garde-fou | Rôle |
| --- | --- |
| Déclenchement sur `transition_post_status` | Une **publication**, jamais une modification |
| Marqueur `_afk_push_sent` | Un contenu n'est notifié **qu'une fois**, même après un échec |
| Case `_afk_push_skip` | La rédaction peut sauter l'envoi d'un contenu précis |

Le marqueur est posé **même quand l'envoi échoue** : sans cela, la moindre
correction ultérieure relancerait une tentative, et une panne durable
inonderait les abonnés.

Les déclencheurs se saisissent dans une liste répétable — voir la décision 3.7.

**Où ranger la clé d'envoi — trois emplacements, un recommandé.**

**La clé se déclare uniquement dans `wp-config.php`** :

```php
define( 'AFK_PWA_PUSH_KEY', 'os_v2_…' );
```

L'administration n'offre **aucun champ de saisie** — seulement l'état : clé
déclarée, ou marche à suivre pour la déclarer.

Pourquoi ce classement, appris à nos dépens : **une clé rangée en base voyage
avec la base**. Copier la production vers une préproduction l'y a emportée, et
la page « Tous les réglages » de WordPress — qui affiche toutes les options en
clair — l'a ensuite exposée. Une sauvegarde de base la contient également.

Le champ a d'ailleurs été retiré pour une seconde raison, tout aussi concrète :
la clé transitait par le formulaire des réglages. Le pare-feu applicatif de l'hébergement
inspecte le contenu des envois, et un jeton de cent caractères noyé parmi
soixante champs déclenchait son écran de contrôle — l'enregistrement se perdait
alors en chemin. Le serveur héberge aussi la production : la même chose s'y
serait produite.

`has_legacy_key()` signale une clé qu'une version antérieure aurait laissée en
base, et `forget_legacy_key()` l'en retire — c'est tout ce que sait faire le
point d'entrée AJAX qui subsiste. **Aucun code du plugin n'écrit plus de clé en
base.**

> ⚠️ **La clé d'envoi (*REST API Key*) est secrète** — elle autorise l'envoi à
> tous les abonnés. Elle est conservée dans l'option **`afk_pwa_push_key`**,
> distincte des réglages : ceux-ci sont lus en de nombreux endroits et
> pourraient l'exposer par mégarde. Elle est enregistrée sans `autoload`,
> jamais transmise au navigateur, et l'écran d'administration n'affiche que des
> points — recevoir ces points signifie « champ non modifié ».

L'API n'ayant aucun moyen de valider une clé sans s'en servir, l'écran propose
un **envoi de test**, qui distingue trois issues : clé refusée, clé acceptée
mais aucun abonné, ou envoi effectif.

**La fenêtre d'invitation est la nôtre, jamais celle de OneSignal.** Le SDK
propose ses propres fenêtres — elles s'habillent mal, se traduisent à moitié et
imposent leur moment. Il offre pourtant tout ce qu'il faut pour faire mieux :
`requestPermission()` déclenche l'invite du navigateur depuis **notre** bouton,
`addTags()` pose les centres d'intérêt.

Les centres d'intérêt y sont **repliés**, et **cochés d'origine** : un visiteur
qui accepte sans les déplier reçoit tout, et porte alors **toutes** les
étiquettes. Sans elles, un envoi ciblé plus tard ne l'atteindrait jamais —
c'est exactement le piège rencontré (7 abonnés, 0 étiquette, aucun destinataire).

> ⚠️ **La fenêtre ne dépend pas du SDK.** Elle s'affiche et se déplie seule ;
> le SDK n'est réclamé qu'au clic sur « M'abonner ». Raison : c'est un script
> tiers, différé au premier geste par le module de report — et il pourrait
> aussi ne jamais arriver. Une interface qui l'attendrait resterait inerte,
> ce qui a été constaté avant correction.

Elle se tait dans quatre cas : abonné déjà, refus mémorisé, navigateur ayant
déjà refusé — un refus de navigateur est quasi définitif, insister n'agace que
le visiteur — et **hors du contexte choisi**.

**Où la proposer** (`os_popup_context`, usine : `pwa`) : l'application
installée seulement. Deux raisons, la première éditoriale : on n'ajoute pas une
fenêtre de plus à un navigateur qui en voit déjà beaucoup, et on ne sollicite
que des visiteurs déjà engagés — ceux qui ont fait la démarche d'installer. La
seconde est technique : sur iPhone, les notifications web n'existent **que**
dans une application posée sur l'écran d'accueil ; les proposer dans Safari y
serait une promesse intenable. `both` et `browser` restent disponibles pour qui
veut élargir — sur Android et ordinateur, l'onglet fonctionne très bien.

> ⚠️ **`Slidedown.promptPush()` n'est pas l'invite du navigateur** — c'est la
> fenêtre de OneSignal, qui exige d'être configurée dans leur tableau de bord.
> Sans cette configuration elle n'affiche rien, et le code en concluait à tort
> que les notifications étaient bloquées. L'appel correct est
> `Notifications.requestPermission()`. Le SDK est réclamé **dès l'affichage**
> de la fenêtre, jamais au clic : un `await` intercalé ferait perdre le geste
> de l'utilisateur, que les navigateurs exigent pour afficher leur invite.

**Banc d'essai** (`class-push-tester.php`) — l'écran Notifications permet de
publier un contenu de démonstration pour vérifier un déclencheur, sans toucher
aux contenus réels. Le type se choisit dans une liste, et l'écran dit **avant**
de publier s'il est pris en charge : publier sans déclencheur n'enverrait rien,
et rien n'expliquerait ce silence.

Deux jeux de valeurs sont fournis avec le plugin — `oeuvre.jpg` et
`artiste.jpg`, posés en image à la une pour que la notification et le partage
social aient leur visuel. Les autres types reçoivent un jeu générique sans
image : mieux vaut un test possible qu'un type refusé.

> ⚠️ Le banc **ne simule rien** : il publie réellement, et le déclencheur suit
> le chemin d'une œuvre déposée par un artiste. Un test qui emprunterait un
> raccourci ne prouverait rien. Les contenus créés portent le marqueur
> `_afk_test_content` et se suppriment d'un bouton — repérés par ce marqueur,
> jamais par leur titre, qu'un contenu légitime pourrait partager.

**Le segment par défaut a changé de nom.** Un envoi sans ciblage vise
`included_segments`. Le nom historique — `Subscribed Users` — **n'existe plus**
dans les applications récentes, remplacé par `Total Subscriptions`.

> ⚠️ Viser un segment inexistant ne provoque **aucune erreur** : l'API répond
> succès, avec zéro destinataire. Le symptôme est trompeur — les notifications
> partent parfaitement depuis le tableau de bord OneSignal, mais jamais depuis
> le site, et le message « aucun abonné à prévenir » paraît désigner
> l'abonnement alors que celui-ci fonctionne.

Le plugin envoie donc **les deux noms** : OneSignal retient celui qui existe et
ignore l'autre, sans doublon pour l'abonné. Il fonctionne ainsi sur une
application ancienne comme sur une récente — utile, chaque domaine ayant la
sienne.

Pour connaître les segments d'une application :
`GET /api/v1/apps/{app_id}/segments`.

OneSignal a changé la forme de son en-tête d'authentification (`Basic` pour les
anciennes clés, `Key` pour les récentes). Le module tente la forme récente et
retombe sur l'autre en cas de refus, plutôt que d'imposer un réglage de plus.

---

## 4.9 Œuvres hors connexion (`includes/artworks/`)

Le module qui permet à un artiste de consulter ses œuvres sans réseau.

### Envelopper, jamais contourner

`Africart_Artwork_Repository` est le **seul** fichier du plugin qui connaisse
Voxel. Tout le reste passe par lui : si le thème change, c'est là et nulle part
ailleurs qu'il faut reprendre.

> ⚠️ Forcer `show_in_rest` sur le type de contenu prend une ligne et donne
> aussitôt une API qui répond. Elle écrirait des œuvres **réelles en base et
> absentes du site** — ni validées, ni indexées, ni reliées à leur artiste.
> Le pire des symptômes : celui qui ressemble à un succès.

### Ce que le thème impose, et qu'on ne décide pas

| Constat | Relevé | Conséquence |
| --- | --- | --- |
| `submissions.status` = `pending` | Réglage Voxel | Une œuvre déposée part **en validation**, pas en ligne. `REVIEW` n'est pas `DRAFT` |
| `submissions.deletable` = `false` | Réglage Voxel | L'artiste **ne peut pas supprimer** ses œuvres |
| 36 champs porteurs sur 45 déclarés | `get_fields()` | Le formulaire se construit depuis `/schema`, jamais d'une liste écrite en dur |
| Les droits ne sont pas ceux de WordPress | Mesuré sur une œuvre réelle | `current_user_can( 'edit_post' )` rend **faux** là où `\Voxel\Post::current_user_can_edit()` rend **vrai** |

### Non-ingérence côté site

La page « Vos œuvres » **existe déjà** — elle est dans le menu utilisateur du
thème et rendue par le widget de flux de Voxel. En construire une seconde
donnerait deux listes concurrentes, dont l'une serait toujours en retard.

En ligne, le script **ne change rien à l'écran** : il tient seulement le cache à
jour. Sans réseau, il reprend la main sur le seul conteneur `.post-feed-grid`,
et laisse le reste de la page au thème.

Le script est chargé sur **toutes** les pages, pas seulement celle du flux : la
consultation hors connexion suppose une copie déjà prise, et l'attendre au
moment où le réseau manque serait trop tard.

> ⚠️ Deux situations que le bandeau distingue, et que confondre tromperait :
> *hors connexion* quand l'appareil n'a pas de réseau, *site injoignable* quand
> il en a mais que le serveur ne répond pas. Le second cas est fréquent en
> mobilité — barres affichées, rien qui passe — et annoncer une absence de
> réseau enverrait l'artiste vérifier son forfait plutôt que patienter.

### L'écrivain, et le banc qui le juge

`class-artwork-writer.php` écrit les œuvres **sans passer par les objets de
champ du thème**. Dépendre de Voxel pour lire est acceptable ; en dépendre pour
écrire nous priverait de choisir le statut, d'autoriser la suppression, et
d'écrire sans formulaire — donc sans réseau.

En contrepartie, l'écrivain doit produire **exactement** ce que Voxel produit.
C'est ce que mesure `class-artwork-parity.php`.

> ⚠️ Le banc a été écrit **avant** l'écrivain, et ce n'est pas un détail
> d'ordonnancement. Un contrôle construit après coup est taillé pour valider ce
> qu'on vient d'écrire : on y compare ce qu'on sait déjà égal, et on omet
> précisément ce qu'on a oublié.

Il a trouvé cinq divergences, dont une **avant même que l'écrivain n'existe** :

| Ce qu'il a révélé | Ce qui se serait passé sans lui |
| --- | --- |
| Un champ de taxonomie se **lit** en objets `\Voxel\Term` et s'**écrit** en slugs | L'API rendait des structures internes du thème, et l'écriture les refusait |
| La `description` s'écrit dans `post_content`, pas dans une méta | Une méta que rien ne lit, et le corps de la fiche vide |
| Un lieu vide se **supprime**, il ne s'écrit pas en JSON de valeurs nulles | Une méta présente là où le thème n'en met aucune |
| Indexer sans condition met les brouillons dans la recherche publique | Des brouillons visibles dans les résultats du site |
| Le champ description écrit par `$wpdb->update()` direct, **sans vider le cache** | Toute relecture juste après une écriture Voxel rend l'ancienne valeur |

Le dernier dépasse le banc : **tout code qui relit une œuvre juste après une
écriture Voxel s'y heurte**. D'où le `clean_post_cache()` avant chaque relecture.

### Formats d'écriture, relevés fichier par fichier

| Texte, e-mail, date, select | Valeur brute |
| --- | --- |
| Image, fichier | `join( ',', $ids )` — **la lecture rend des descripteurs, pas des identifiants** |
| Lieu, choix multiple | `wp_json_encode`, ou suppression si vide |
| Taxonomie | Termes assignés, aucune méta |
| Titre, description | `post_title` et `post_content` |

### Deux pièges d'ordonnancement

Le statut se pose **après** les champs : changer de statut déclenche chez Voxel
la pose de l'image mise en avant depuis `cover`, et l'appliquer avant que
`cover` n'existe la laisserait vide.

La réindexation vient **en dernier**. Sans elle, une œuvre écrite existe,
s'ouvre par son lien, et disparaît de la recherche du site — sans erreur nulle
part.

### État mesuré

Parité **0 écart** en brouillon et en publié : 24 métas, 1 194 caractères de
contenu, taxonomie et indexation identiques. Le banc crée ses propres œuvres,
les marque, les désindexe et les supprime ; aucune œuvre réelle n'est touchée.

### Les champs à liste fermée

Le schéma porte désormais les valeurs proposées : les 5 choix du statut de
déposant, les 4 termes d'art, et les listes Oui/Non. Lues par
`get_model_value()`, qui est **public** — accéder aux propriétés internes du
champ par réflexion marcherait aujourd'hui et casserait à la première mise à
jour du thème, sans prévenir.

> ⚠️ Un choix vide figure toujours en tête. Sans lui, le premier de la liste
> serait retenu par défaut, et l'artiste déposerait une valeur qu'il n'a jamais
> choisie — « Artiste » pour un galeriste, par exemple.

La taxonomie fait exception à la collecte : elle s'écrit en **liste** de slugs,
même pour un seul terme. Transmettre la chaîne seule ferait échouer l'assignation
sans message — l'œuvre existerait, sans sa catégorie, et rien ne l'aurait
signalé.

### Téléversement des visuels

`class-artwork-media.php` expose `POST /africart/v1/medias`.

> ⚠️ Pourquoi pas `/wp/v2/media` : **un artiste n'a pas le droit
> `upload_files`**. Mesuré — un abonné rend `false`. La route native le
> refuserait, et c'est d'ailleurs pourquoi le thème a sa propre médiathèque.

Le téléversement se fait au nom du site, **après notre propre contrôle** : peut
déposer un visuel qui peut déposer une œuvre. **Aucune capacité WordPress n'est
accordée**, même temporairement — élargir un droit le temps d'une requête est le
genre de raccourci dont on ne retrouve plus la trace six mois plus tard.

#### Cinq contrôles, et un seul qui compte vraiment

| 1 | L'artiste a le droit de déposer une œuvre |
| --- | --- |
| 2 | Il n'a pas dépassé 40 visuels dans l'heure |
| 3 | Le fichier tient dans 12 Mo |
| 4 | Extension et type déclaré concordent, et sont une image |
| **5** | **Le contenu *est* une image** — `getimagesize()` |

Les quatre premiers se contournent en renommant un fichier. Le cinquième est le
seul qui arrête un script déposé sous le nom `photo.jpg`.

`image/svg+xml` est absent de la liste et doit le rester : un SVG est un document
capable de porter du script.

#### Les contrôles sont isolés de la route

`verifier()` est publique et ne touche pas aux superglobales.
`is_uploaded_file()` ne passe que pour un envoi HTTP réel, ce qui rendrait ces
règles intestables autrement qu'en production — **un contrôle de sécurité qu'on
ne peut pas éprouver n'en est pas un.**

#### Ce que l'attaque a donné

Neuf tentatives, toutes conformes. Deux cas **acceptés** ont été vérifiés
plutôt que supposés inoffensifs :

| Nom annoncé | Nom réellement stocké |
| --- | --- |
| `piege.php.jpg` | `piege.php_.jpg` |
| `../../wp-config.php.jpg` | `wp-config.php_.jpg` |
| `../../../etc/passwd.jpg` | `etcpasswd.jpg` |

Tous restent dans le dossier des dépôts : aucune traversée n'est possible, et
l'extension exécutable est désamorcée par WordPress lui-même.

### La logique conditionnelle

Cinq champs du formulaire ne s'affichent que sous condition. Sans elle, le
formulaire réclamerait « Dénomination sociale » à un particulier — et son dépôt
échouerait sur un champ qui n'aurait jamais dû lui être présenté.

**La sémantique est celle du thème**, relevée dans `passes_conditional_logic()` :
**OU entre les groupes, ET à l'intérieur d'un groupe**. L'inverser masquerait
exactement les champs qu'il faut montrer.

`text:contains` compare **sans tenir compte de la casse** — le thème applique
`strtolower` des deux côtés. Une des conditions du site porte « particulier » en
minuscule ; elle fonctionne donc, et s'en écarter aurait masqué un champ que le
formulaire du site affiche.

| Statut déclaré | Champs qui apparaissent |
| --- | --- |
| Artiste, Agent/représentant | Aucun |
| Particulier | Prix, lieu et année d'acquisition |
| Galerie, Autre | Les mêmes, plus la dénomination sociale |

> ⚠️ Le thème propose vingt-cinq types de conditions. Exporter celles qu'on ne
> sait pas rejouer serait pire que de les ignorer : le formulaire les évaluerait
> de travers et masquerait un champ obligatoire, dont l'absence ferait échouer le
> dépôt **sans cause visible**. Une condition non reconnue rend donc le champ
> **toujours visible** — montrer un champ inutile gêne, en masquer un
> obligatoire bloque.

**Un champ masqué n'est pas transmis.** Garder la réponse d'un particulier au
champ « Dénomination sociale » après qu'il s'est déclaré galerie déposerait une
donnée qu'il croit effacée.

### Le garde-fou : ce qui n'est pas connu est refusé

`GERES` est une liste **fermée** de types que le formulaire sait rendre. Un type
absent n'est pas approximé en champ texte : il est annoncé comme non pris en
charge, avec renvoi vers le formulaire du site.

> ⚠️ C'est la règle qui rend la reprise du formulaire tenable. Rendre de travers
> un type qu'on ne connaît pas ferait écrire des valeurs de travers, **sans
> erreur** — et l'artiste ne le découvrirait qu'en consultant sa fiche publique.
> Y ajouter un type veut dire : savoir le rendre **et** savoir l'écrire.

### Deux registres d'état, jamais confondus

| L'état de **publication** | Où en est l'œuvre **sur le site** — brouillon, en validation, en ligne, refusée, retirée |
| --- | --- |
| L'état d'**envoi** | Où en est sa transmission **depuis cet appareil** — en attente, à départager, impossible |

Une œuvre peut très bien être *en ligne* et porter une modification qui n'est pas
encore partie. Les réunir dans une seule pastille ferait croire qu'une œuvre « en
validation » n'est pas partie, ou qu'une œuvre « en attente d'envoi » a été
refusée.

La seconde pastille n'apparaît **que** s'il y a quelque chose à signaler.
L'afficher en permanence banaliserait le signal.

### Les créations locales sont visibles

> ⚠️ Une œuvre déposée hors connexion n'existe **ni sur le serveur, ni dans le
> cache des œuvres** : elle n'est que dans la file. Sans traitement particulier,
> l'artiste ne la verrait **nulle part** après l'avoir déposée. Il la croirait
> perdue, et recommencerait — créant le doublon que l'idempotence sert justement
> à éviter.

Elles s'affichent donc en tête de liste : ce sont elles dont l'artiste doute, et
les enfouir en bas ne le rassure pas.

### Les textes sont réglables et traduisibles

Dix-huit textes — les cinq états de publication, les trois d'envoi, les bandeaux
et les messages du formulaire — vivent dans les réglages, comme ceux du bandeau
d'installation et des notifications.

> ⚠️ Les laisser écrits dans le JavaScript aurait créé deux régimes dans le même
> plugin, et un site bilingue aurait affiché du français au milieu de l'anglais
> sans que personne puisse le corriger.

Les `%s` sont remplacés : par la date de dernière synchronisation dans les
bandeaux, par l'heure dans le message d'enregistrement.

### La déconnexion efface ce que l'appareil garde

Un visiteur non connecté n'est pas simplement ignoré.

> ⚠️ Les pages réchauffées ont été récupérées **pour un artiste connecté** :
> elles contiennent ses œuvres, et le cache local aussi. Les laisser après sa
> déconnexion les rendrait consultables hors connexion par la personne suivante
> sur le même appareil.

Un script minuscule cherche donc une trace — et n'en trouve aucune chez
l'immense majorité des visiteurs, auquel cas il s'arrête là. S'il en trouve, il
efface la base locale, la réserve du formulaire, et demande au service worker
d'oublier les pages du module.

### La base locale a un seul propriétaire

`oeuvres-db.js` détient le nom, la version et la structure. Rien d'autre
n'ouvre la base.

> ⚠️ Ce fichier répare un défaut réel, livré en phase 2. Le cache de
> consultation ouvrait la base en version 1 et le formulaire en version 2 — et
> les deux se chargent sur la page de dépôt. IndexedDB refuse une ouverture en
> version inférieure à celle qui existe : le second à s'exécuter recevait une
> `VersionError`, qu'un `catch` transformait en « aucune œuvre en cache ». La
> consultation hors connexion cessait de fonctionner **sur toutes les pages**,
> sans erreur visible. On aurait cherché du côté du réseau ou du service worker.

Ajouter un magasin veut désormais dire : monter le numéro d'un cran, et ajouter
sa création dans `structure()`. Celle-ci **crée ce qui manque sans toucher à
l'existant** — supprimer et refaire effacerait précisément ce que le module sert
à ne pas perdre.

### La file de synchronisation

Trois magasins : `oeuvres` (le cache), `brouillons` (la saisie en cours), `file`
(ce qui doit partir).

#### Le compactage

| Situation | Ce qui part |
| --- | --- |
| Création puis deux modifications | **Une** création, portant la dernière saisie |
| Retrait après une création jamais partie | **Rien** — l'œuvre n'existe que sur l'appareil |
| Retrait sur une œuvre existante | Le seul retrait ; les modifications qui précèdent sont abandonnées |
| Deux œuvres différentes | Les deux, indépendamment |

Envoyer des modifications à un contenu qu'on s'apprête à mettre à la corbeille
serait du trafic pour rien, et deux fois plus d'occasions d'échouer. Une création
n'est en revanche jamais absorbée : sans elle, la modification qui suit n'aurait
aucune œuvre à modifier.

#### Trois pièges tenus

| Piège | Ce qui se passerait | La parade |
| --- | --- | --- |
| **Le jeton expire pendant que l'application dort** | Une œuvre saisie le soir part le lendemain avec un `nonce` périmé ; le serveur répond 403 et **toute la file se bloque** sur une erreur qui n'en est pas une | Renouvellement avant chaque vidage, **et** une seconde tentative sur `rest_cookie_invalid_nonce` |
| **`SYNCING` sans échéance** | Un onglet fermé au mauvais instant laisse une œuvre « en cours d'envoi » à vie | Bail daté, repris passé deux minutes |
| **La synchronisation en arrière-plan n'existe pas dans Safari** | Rien ne partirait jamais pour une partie du public, sans erreur ni trace | Trois déclencheurs que tous les navigateurs ont : `online`, retour au premier plan, chargement de page |

Un `4xx` autre que `409` n'est pas rejoué : la demande est mauvaise, la retenter
à l'identique donnerait le même refus. Une panne passagère, elle, repasse en
attente.

L'association identifiant local ↔ identifiant WordPress se fait au retour d'une
création réussie. **Sans elle, la modification suivante repartirait en création,
donc en doublon.**

### État mesuré

| Cohabitation des quatre scripts | Version 3, trois magasins, cache et file lisibles |
| --- | --- |
| Hors connexion | Rien ne part, **zéro appel réseau** |
| Retour du réseau | `GET session` puis `POST oeuvres`, œuvre rangée en cache avec son identifiant |
| Jeton refusé | `POST` → 403 → renouvellement → `POST` → succès |
| Bail expiré | Repris et envoyé |

### La bascule : un seul formulaire

Par défaut, notre formulaire ne prend la main que sans réseau. Le réglage
*Utiliser notre formulaire — aussi quand le réseau est disponible* le rend seul
maître, en ligne comme hors connexion.

> ⚠️ Notre formulaire a son **propre point d'ancrage**,
> `[africart_oeuvre_form]`, et il passe avant celui du thème. C'est
> indispensable dès lors que le widget Voxel peut être retiré de la page : tant
> que la détection reposait sur `ts-create-post`, enlever ce widget aurait fait
> disparaître **notre** formulaire en même temps que le sien, et la page de dépôt
> serait devenue blanche sans erreur nulle part.

Ordre des gestes, à respecter : poser le shortcode, retirer le widget du thème,
**puis** cocher le réglage. Dans un autre ordre, les deux formulaires
s'afficheraient l'un sous l'autre.

### Les visuels

Le champ photo n'apparaît **qu'avec du réseau**. Sans lui, il reste annoncé comme
différé — un fichier ne peut pas partir hors connexion.

> ⚠️ Le téléversement part **dès la sélection**, pas à l'envoi du formulaire.
> Attendre l'envoi ferait partir plusieurs mégaoctets au moment précis où
> l'artiste attend une réponse, et un échec réseau lui ferait tout recommencer,
> saisie comprise.

Ce qui est retenu, ce sont les **identifiants** rendus par le serveur, jamais les
fichiers : c'est la forme que l'écriture attend. Un refus affiche le message du
serveur tel quel — c'est lui qui sait pourquoi il refuse, le paraphraser
mentirait.

### Le parcours de dépôt, en entier

> ⚠️ Le menu du site ne pointe **pas** sur le formulaire. Il mène à
> `/enregistrez-votre-oeuvre/`, une page d'explications qui y renvoie. Ne garder
> que le formulaire laissait le parcours coupé en son milieu : l'artiste cliquait
> « Enregistrer une œuvre » et tombait sur la page hors connexion, sans jamais
> atteindre l'écran qui, lui, fonctionnait.

La découverte suit donc ce que l'artiste **suit réellement** : une entrée de
menu, menant à une page qui renvoie au formulaire.

« Toute page qui mène au formulaire » serait trop large — sur ce site, sept y
renvoient, dont l'accueil. Deux garde-fous : les pages portant un flux public
d'œuvres sont écartées, et le nombre d'étapes est **borné à quatre**, sans quoi
ajouter des liens dans le menu ferait grossir sans fin ce qu'un artiste
télécharge à sa première visite.

La liste est oubliée à l'enregistrement de toute page : un lien ajouté
aujourd'hui ne doit pas attendre demain pour être suivi.

### Modifier une œuvre hors connexion

Depuis la liste, une carte mène à l'éditeur — pas à la fiche publique, qui n'est
de toute façon pas consultable sans réseau.

> ⚠️ L'identifiant passe par le **fragment** (`#oeuvre=154`), jamais par la
> requête. Une adresse portant un `?` ne correspond plus à l'entrée mise en cache
> par le service worker, et la page ne s'ouvrirait pas du tout. Le fragment, lui,
> ne part jamais au serveur.

**Une modification en cours l'emporte sur la version du serveur.** Reprendre
celle du serveur effacerait sous les doigts ce que l'artiste a saisi et pas
encore envoyé.

L'empreinte détenue **au moment de l'ouverture** part avec la modification.
Sans elle, un changement fait entre-temps sur le site serait écrasé en silence —
c'est le `409` côté serveur qui l'arrête.

### Le détail des œuvres est rapatrié à part

La liste ne porte pas les valeurs des champs : deux cents œuvres avec leurs
trente-six champs feraient une réponse que le réseau mobile ne pardonnerait pas.

> ⚠️ Mais sans ces valeurs, **aucune modification hors connexion n'est
> possible** — le formulaire s'ouvrirait vide. Le détail est donc rapatrié
> séparément, une œuvre à la fois, seulement pour celles qui ne l'ont pas encore,
> et plafonné à quarante.

Une œuvre jamais rapatriée le dit franchement plutôt que d'ouvrir un formulaire
vide qui écraserait son contenu au premier enregistrement.

### Le formulaire hors connexion

Même principe de non-ingérence que pour la liste : **en ligne, le script ne
change rien**. Le formulaire du site existe, il est complet, et en superposer un
second créerait deux expériences divergentes pour un même geste. Nous ne prenons
la main que sans réseau, sur le seul conteneur `.ts-create-post`.

Le formulaire se construit depuis `/schema`, jamais d'une liste écrite en dur.
Il est mis en réserve à chaque visite en ligne : **sans réseau, il n'y a plus
personne pour le rendre**, et un formulaire sans schéma n'existe pas.

> ⚠️ Si le schéma n'a jamais été chargé sur l'appareil, le module **le dit** au
> lieu d'inventer un formulaire. Des clés devinées écriraient à côté, sans
> erreur — et l'artiste croirait avoir déposé son œuvre.

**Les étapes viennent du schéma.** Elles sont des champs comme les autres chez
le thème — de type `ui-step` — mais ne portent aucune donnée : elles sont donc
écartées de `fields()` et rendues à part. La structure n'est pas la donnée. Sans
elles, trente-six champs formeraient une liste que personne ne remplit jusqu'au
bout ; avec elles, cinq écrans comme sur le site.

**Les champs non traités hors connexion sont montrés et expliqués**, jamais omis
en silence : photos et lieu portent la mention « à ajouter au retour du réseau ».
Un champ absent laisserait croire qu'il n'existe pas, et l'artiste découvrirait
son œuvre incomplète après coup.

L'enregistrement automatique attend **une seconde de silence** : écrire à chaque
frappe solliciterait le stockage sans rien apporter et ferait clignoter le
message d'état sous les doigts. Quitter la page court-circuite la temporisation
— rien ne doit se perdre.

> ⚠️ La base IndexedDB est partagée avec le cache de consultation. Une montée de
> version doit donc **créer ce qui manque sans toucher à l'existant** :
> supprimer et refaire effacerait les œuvres déjà en cache.

### Deux pièges d'affichage, trouvés au banc

| Piège | Ce qui se passait | La parade |
| --- | --- | --- |
| Ajouter une astérisque aux champs obligatoires | Plusieurs libellés du thème en portent déjà une — « Nom de l'artiste? * ». On obtenait « * * », qui ne signale plus rien | N'ajouter la marque que si le libellé n'en porte pas |
| Une page d'essai sans balise `viewport` | Chrome met en page sur 980 px : toute capture mobile ment sur la largeur, et on corrige un débordement qui n'existe pas | Le banc porte la balise, comme le site |

### Validation : la règle au thème, le compte rendu à nous

`class-artwork-validator.php` délègue chaque règle aux objets de champ du thème,
et c'est délibéré malgré l'objet du lot.

**Valider et écrire ne sont pas la même chose.** Écrire est un mécanisme, et
nous le voulons à nous. Déterminer ce qui fait une œuvre valide — quels champs
sont obligatoires, lesquels s'affichent sous condition — est un jugement sur le
modèle de données, déclaré dans l'éditeur du thème par la personne qui a conçu
le formulaire.

> ⚠️ Redéclarer ces dix-sept champs obligatoires chez nous créerait deux vérités.
> Le jour où quelqu'un rend un champ facultatif dans l'éditeur, notre copie
> continuerait de le réclamer, et personne ne saurait pourquoi.

Nous possédons **l'invocation et le compte rendu** : quand on valide, et comment
on l'explique. Les messages rendus sont ceux du thème — deux formulations
différentes pour une même règle sèmeraient le doute.

Un brouillon échappe à l'exigence de complétude : on n'enregistrerait rien si
l'on réclamait vingt champs pour poser une idée.

### Les règles de dépôt deviennent les nôtres

`class-artwork-rules.php` lève les deux blocages relevés en phase 0 — le statut
imposé et la suppression interdite — **sans toucher au thème**. Les deux jeux de
règles coexistent parce qu'ils ne servent pas le même écran.

| Réglage | Par défaut | Ce qu'il permet |
| --- | --- | --- |
| `art_statut_creation` | Suivre le site (`pending`) | Déposer en brouillon, en validation, ou directement en ligne |
| `art_suppression` | Suivre le site (refusé) | Autoriser le retrait, qui met à la corbeille — jamais en suppression définitive |

> ⚠️ « Suivre le site » n'est pas un repli paresseux. Un module qui change le
> comportement du site à la seconde où on l'active serait une mauvaise surprise :
> il faut un geste explicite pour s'en écarter.

Quand les deux chemins divergent, **l'écran de réglages le dit en clair** — « Dépôt
en Brouillon depuis l'application, En validation depuis le formulaire du site ».
Un écart silencieux entre deux chemins de dépôt est exactement ce qui rend un
site incompréhensible six mois plus tard.

### Écriture par l'API

| Route | Ce qu'elle fait |
| --- | --- |
| `POST /oeuvres` | Dépose. `localId` assure l'idempotence |
| `PUT /oeuvres/{id}` | `enregistrer`, `deposer` ou `retirer` — un seul chemin d'écriture |

Trois actions sur une seule route, et non une route par verbe : multiplier les
chemins d'écriture multiplierait les endroits où oublier la réindexation.

> ⚠️ **L'idempotence n'est pas facultative.** Une coupure réseau *après* que
> WordPress a créé l'œuvre mais *avant* que la réponse n'arrive ferait
> recommencer l'application. Sans `localId`, chaque tentative produirait un
> doublon, et l'artiste retrouverait trois fois la même œuvre sans comprendre.

Le contrôle de concurrence se fait **avant toute écriture** : `hashAttendu` porte
l'empreinte que l'application détenait quand l'artiste a commencé à modifier. Si
elle ne correspond plus, la réponse est `409` accompagnée de la version du
serveur — écraser silencieusement serait la pire des issues.

Un refus de validation rend `422` **avec le détail champ par champ**. Un `422`
nu obligerait l'artiste à deviner lequel des trente-six champs pose problème.

### État mesuré

Du point de vue d'un artiste réel, jamais d'un administrateur :

| Dépôt incomplet | `422`, deux champs nommés avec les messages du thème |
| --- | --- |
| Le même en brouillon | Accepté |
| Dépôt complet | Créé en validation, indexé |
| Même `localId` | Même œuvre rendue, **aucun doublon** |
| Empreinte périmée | `409` |
| Empreinte à jour | `200`, modification appliquée |
| Retrait, règle du site | `403` |
| Retrait, règle assouplie | Corbeille, et **désindexé** |
| Œuvre d'un autre artiste | `403` |
| Visiteur non connecté | `401` |

### L'empreinte, et pourquoi la date ne suffit pas

`post_modified` bouge à **chaque** enregistrement, y compris ceux d'un autre
plugin. Comparer les seules dates signalerait des conflits là où pas un
caractère n'a changé — et l'artiste apprendrait à cliquer « garder ma version »
sans lire. L'empreinte ne porte que sur les champs modifiables depuis
l'application. Vérifié : inchangée après une simple touche de date.

### Stockage

IndexedDB, et non `localStorage` : ce dernier est synchrone, plafonné à quelques
mégaoctets et ne retient que du texte.

`?since=` ne rapporte que les modifications ; la liste `connus` sert à repérer
les disparitions — une suppression ne se signale par aucune date.

---

## 5. Commandes en ligne

```bash
wp africart images status     # capacités du serveur, formats actifs, avancement
wp africart images convert    # rattrapage, reprend où il s'est arrêté
wp africart images purge      # supprime les variantes, conserve les originaux
```

Options utiles : `--batch=<n>`, `--limit=<n>`, `--restart`, `--force`.

Les traitements sont aussi pilotables depuis l'administration, mais **au-delà de
quelques centaines de médias, préférer la ligne de commande** : aucune limite de
durée, pas d'onglet à garder ouvert.

---

## 6. Ajouter quelque chose

**Un réglage** → l'ajouter dans `Africart_PWA_Settings::defaults()`, **puis dans
`pages()`** (clé simple, ou `toggles` si c'est une case à cocher), enfin dans le
nettoyage de `sanitize()` selon son type.

**Un module** → une classe par responsabilité dans `includes/`, chargée et
instanciée dans `africart-pwa.php`. Lui donner un interrupteur dans les réglages
et vérifier qu'il ne fait rien lorsqu'il est désactivé.

**Un texte traduisible** → l'ajouter dans `Africart_PWA_I18n::translatable_keys()`
et dans `builtin()` pour l'anglais et l'espagnol.

---

## 7. Pièges vérifiés, à ne pas réintroduire

| Piège | Ce qui se passe | La parade |
| --- | --- | --- |
| Viser le segment OneSignal `Subscribed Users` | Aucune erreur, mais zéro destinataire : ce nom n'existe plus dans les applications récentes | Envoyer aussi `Total Subscriptions` ; interroger `/apps/{id}/segments` en cas de doute |
| Déclarer une variante absente | Image totalement invisible | Toujours vérifier la présence sur le disque |
| `wp_localize_script` pour des nombres | Tout devient texte ; `"0"` est *vrai* en JavaScript | `wp_add_inline_script` + `wp_json_encode` |
| Champ numérique laissé vide | Enregistré `0`, pas « valeur par défaut » | Enregistrer une chaîne vide |
| Servir avant `template_redirect` priorité 0 | Redirection canonique, adresse cassée | Priorité `0` |
| Modifier les fichiers CSS d'Elementor | Écrasés à la prochaine régénération | Publier une règle plus prioritaire |
| Déclarer un fond CSS sans couvrir les `::before` d'Elementor | Les deux formats se téléchargent — la règle publiée gagne sur l'élément, mais le `::before` d'Elementor tire toujours l'original | Chaque entrée liste toutes les cibles : base, couche motion-effects, et leurs `::before` |
| Oublier un réglage dans `pages()` | Perdu au premier enregistrement d'une autre page | L'y inscrire |
| Classe dédiée sur un champ, sans script qui s'y greffe | Le champ paraît spécial mais reste inerte — les couleurs n'étaient qu'une saisie hexadécimale, sans aperçu ni sélecteur | Sélecteur natif `type="color"`, qui ne dépend d'aucune bibliothèque |
| URL d'icône stable + 7 jours de cache HTTP | L'app installée garde l'ancien logo : la vérification périodique du manifest revoit la même URL et le serveur ressert les vieux octets | Les URL d'images du manifest sont versionnées par date de fichier (`versionne()`) |
| Cache de bordure o2switch non purgeable | Une page capturée au mauvais moment (déploiement en cours) est resservie telle quelle — vérifié : un fossile sans aucune trace du plugin | Paramètre d'URL pour contourner en test ; purge à demander à o2switch ; déployer en deux temps (fichier temporaire puis renommage) |
| Deux fichiers qui ouvrent la même base IndexedDB avec leur propre version | Le second reçoit une `VersionError` ; un `catch` la transforme en « rien en cache », et la fonctionnalité s'éteint en silence | Un seul propriétaire pour la base : nom, version et structure au même endroit |
| Suivre `prefers-color-scheme` pour un composant inséré dans la page du thème | Carte sombre sur fond clair, bandeau illisible — constaté au banc d'essai | Ce qui fait autorité est la page qui accueille, jamais le réglage de l'appareil |
| Deviner la page d'un thème à son adresse | Elle peut être renommée, déplacée, dupliquée | Détecter le **widget** qu'elle contient |
| Poser une étiquette par centre d'intérêt sans compter | Au-delà du plafond du forfait (3), OneSignal rejette la requête **entière** : plus aucune étiquette n'est écrite, et le SDK avale l'erreur | `MAX_TAGS`, vérifié avant écriture ; l'administration signale le dépassement |
| Cibler `tag = 1` | Exclut tous ceux qui n'ont jamais ouvert la fenêtre de choix — la quasi-totalité des abonnés. Zéro destinataire, sans erreur | `!= 0` **OU** `not_exists` : absence de choix n'est pas refus |
| Attendre que le conteneur GTM soit charge pour choisir la forme | Tout ce qui s'emet tot part en forme gtag et n'atteint jamais GTM — l'origine sociale, mesuree a l'arrivee, en premier | Le reglage fait foi : conteneur declare = forme GTM, meme avant chargement. GTM rejoue l'historique du `dataLayer` a son demarrage |
| Deduire << consentement requis >> d'une declaration sans verifier qui le recueille | Cocher la case sans activer de bandeau bloquait toute mesure definitivement : aucun visiteur n'avait le moyen de repondre | Le verrou du navigateur ne se ferme que si NOUS tenons la cle, c'est-a-dire si notre bandeau est affiche |
| Pousser un événement `gtag` en espérant que GTM le voie | L'événement disparaît sans erreur : les deux formats partagent `dataLayer` mais pas sa grammaire | Détecter une clé `GTM-` dans `window.google_tag_manager`, et n'émettre qu'une seule des deux formes |
| Lire une variable hors de sa portée dans un appel de mesure | `ReferenceError` au clic — la mesure casse la fonctionnalité qu'elle observe | Retenir le contexte dans une variable du module ; compiler les scripts en mode strict pour le détecter |
| Brancher un comportement dans `auSDK().then(...)` | L'élément s'affiche mais reste inerte : le SDK OneSignal est différé au premier geste, l'écouteur arrive trop tard ou jamais | Poser l'écouteur **immédiatement** ; réclamer le SDK *dans* le gestionnaire (`var os = await auSDK()`) |
| Brancher les fermetures d'une fenêtre dans le code qui décide de l'afficher | Une fenêtre ouverte par un **autre** chemin — la cloche — n'a plus ni croix, ni Échap, ni « Plus tard » : elle est sans issue | `bindModal()` branche sans condition ; `bindPopup()` ne décide que du « faut-il l'ouvrir, et quand » |
| Version figée sur un script ou une feuille de style | La correction ne parvient jamais au navigateur, qui garde sa copie — la fonctionnalité paraît absente alors que le fichier est bien en ligne | `afk_pwa_asset_version()`, qui suit la date du fichier |

> ⚠️ Le dernier est le plus trompeur : **vider le cache du site n'y change rien**,
> puisque le fichier est retenu par le navigateur du visiteur. Le symptôme —
> « le bouton ne fait rien » — oriente vers le code alors que le code est juste.
> Ne jamais passer `AFK_PWA_VERSION` à `wp_enqueue_script` ou `wp_enqueue_style` :
> elle ne change qu'aux publications.

---

## 8. Environnements

| | Production | Préproduction |
| --- | --- | --- |
| Adresse | `africart.art` | `360.falicrea.net` |
| Particularité | Cache actif (≈ 70 ms) | Sans cache (≈ 2 s) |

⚠️ **Sur la préproduction, seul le poids des pages est un indicateur fiable.**
Les temps varient du simple au double d'une mesure à l'autre : toujours faire
trois passes et prendre la médiane, jamais conclure sur une mesure unique.

⚠️ Le cache de l'hébergeur de production **ignore les paramètres de suivi** :
tout visiteur venant d'Instagram ou de Facebook (`?fbclid=…`) contourne le cache
et attend 1 à 2 secondes. Correction à demander à l'hébergeur.
