<?php
/**
 * Plugin Name:       AFRICART PWA
 * Plugin URI:        https://africart.art/
 * Description:       Transforme africart.art (WordPress / thème Voxel) en application web installable : manifest, service worker, mode hors connexion, allègement des images et des polices, visuel de partage par œuvre, invitation à installer, notifications push. Documentation de maintenance dans DOCUMENTATION.md. Réf. AFK-PWA-2026.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Falicrea
 * Author URI:        https://falicrea.net
 * License:           GPL-2.0-or-later
 * Text Domain:       africart-pwa
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Constantes
 * ---------------------------------------------------------------------- */
define( 'AFK_PWA_VERSION', '0.1.0' );
define( 'AFK_PWA_FILE', __FILE__ );
define( 'AFK_PWA_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFK_PWA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Slugs des endpoints servis à la RACINE du site (indispensable pour le
 * scope « / » du Service Worker et pour un manifest à la racine).
 *   https://africart.art/africart-pwa-sw.js
 *   https://africart.art/africart-manifest.json
 */
define( 'AFK_PWA_SW_SLUG', 'africart-pwa-sw.js' );
define( 'AFK_PWA_MANIFEST_SLUG', 'africart-manifest.json' );

/**
 * Numéro de version d'un fichier du plugin, pour le cache du navigateur.
 *
 * La version du plugin ne convient pas : elle ne change qu'aux publications,
 * alors qu'un script mis en ligne entre-temps doit parvenir aux navigateurs.
 * Le symptôme est trompeur — la fonctionnalité paraît absente alors que le
 * fichier est bien en place sur le serveur, et vider le cache du site n'y
 * change rien puisque le fichier est retenu par le **navigateur**.
 *
 * On utilise donc la date de modification du fichier : toute mise en ligne
 * casse le cache d'elle-même, sans intervention.
 *
 * @param string $relatif Chemin du fichier, relatif au dossier du plugin.
 * @return string Version à passer à wp_enqueue_script/style.
 */
function afk_pwa_asset_version( $relatif ) {
	$chemin = AFK_PWA_DIR . ltrim( $relatif, '/' );
	$date   = file_exists( $chemin ) ? filemtime( $chemin ) : 0;

	return $date ? (string) $date : AFK_PWA_VERSION;
}

/* -------------------------------------------------------------------------
 * Palette : voir `defaults/factory-settings.json`.
 *
 * ⚠️ Trois constantes vivaient ici, annoncées « charte AFRICART », et
 * portaient en réalité la palette du gabarit d'origine. Aucune n'était
 * lue nulle part : les valeurs d'usine se décident dans le fichier
 * ci-dessus. Deux sources pour une même chose, dont une fausse et
 * muette — la corriger ne changeait rien à l'écran, et le croire
 * suffisait à refermer le sujet.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Chargement
 * ---------------------------------------------------------------------- */
// Valeurs d'usine : chargées avant la classe de réglages, qui les lit.
require_once AFK_PWA_DIR . 'includes/class-factory-defaults.php';
require_once AFK_PWA_DIR . 'admin/class-settings.php';
require_once AFK_PWA_DIR . 'admin/class-perf-batch.php';
require_once AFK_PWA_DIR . 'admin/class-subscribers.php';
require_once AFK_PWA_DIR . 'includes/class-i18n.php';
require_once AFK_PWA_DIR . 'includes/class-manifest.php';
require_once AFK_PWA_DIR . 'includes/class-service-worker.php';
require_once AFK_PWA_DIR . 'includes/class-offline.php';
require_once AFK_PWA_DIR . 'includes/class-install-banner.php';
require_once AFK_PWA_DIR . 'includes/class-onesignal.php';
require_once AFK_PWA_DIR . 'includes/class-push-sender.php';
require_once AFK_PWA_DIR . 'includes/class-push-tags.php';
require_once AFK_PWA_DIR . 'includes/class-privacy.php';
require_once AFK_PWA_DIR . 'includes/class-push-tester.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-repository.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-rest.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-media.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-cache.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-form.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-intro.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-header.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-rules.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-session.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-validator.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-writer.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-events.php';
require_once AFK_PWA_DIR . 'includes/artworks/class-artwork-parity.php';
require_once AFK_PWA_DIR . 'includes/class-analytics.php';
require_once AFK_PWA_DIR . 'includes/class-social-meta.php';
require_once AFK_PWA_DIR . 'includes/class-share-image.php';

// Allègement des pages : images et polices.
require_once AFK_PWA_DIR . 'includes/perf/class-image-converter.php';
require_once AFK_PWA_DIR . 'includes/perf/class-image-variants.php';
require_once AFK_PWA_DIR . 'includes/perf/class-image-queue.php';
require_once AFK_PWA_DIR . 'includes/perf/class-image-rewriter.php';
require_once AFK_PWA_DIR . 'includes/perf/class-script-delay.php';
require_once AFK_PWA_DIR . 'includes/perf/class-css-backgrounds.php';
require_once AFK_PWA_DIR . 'includes/perf/class-fonts.php';

/**
 * Instancie les modules du plugin une fois WordPress prêt.
 * La classe Settings est chargée partout (les modules front lisent les
 * réglages via Africart_PWA_Settings::get), ses hooks admin ne
 * s'enregistrent que dans l'admin.
 */
function afk_pwa_bootstrap() {
	if ( is_admin() ) {
		Africart_PWA_Settings::instance();
		// Onglet Abonnes de l'ecran Notifications : lecture seule.
		Africart_PWA_Subscribers::instance();
	}
	// Enregistré sans condition : les requêtes du navigateur pendant le
	// rattrapage passent par admin-ajax.php, et la classe se contente
	// d'écouter une action — le coût est nul sur le front.
	Africart_PWA_Perf_Batch::instance();
	Africart_PWA_Manifest::instance();
	Africart_PWA_Service_Worker::instance();
	Africart_PWA_Offline::instance();
	Africart_PWA_Install_Banner::instance();
	Africart_PWA_OneSignal::instance();
	// Envoi automatique : toujours instancie, l'administration et le cron
	// en ont besoin meme quand aucune cle n'est encore enregistree.
	Africart_PWA_Push_Sender::instance();
	// Centres d'interet ecrits par le serveur : le SDK ne les transmet pas.
	Africart_PWA_Push_Tags::instance();
	// Conformite RGPD : texte de politique, lien, desabonnement.
	Africart_PWA_Privacy::instance();
	// Banc d'essai de la publication (ecran Notifications).
	Africart_PWA_Push_Tester::instance();
	// Mesure d'audience : annonce des faits dans le dataLayer.
	// Une session d'artiste dure un an : sans cela il ne peut pas deposer
	// sans reseau. Le filtre doit etre pose sans condition — la duree se
	// decide pendant la connexion, sur une page que ce module ne sert pas.
	Africart_Artwork_Events::brancher();
	Africart_Artwork_Session::instance();

	// Oeuvres hors connexion : espace REST propre, qui enveloppe Voxel.
	Africart_Artwork_REST::instance();
	Africart_Artwork_Media::instance();
	Africart_Artwork_Cache::instance();
	Africart_Artwork_Form::instance();
	Africart_Artwork_Intro::instance();
	Africart_Artwork_Header::instance();
	Africart_PWA_Analytics::instance();
	Africart_PWA_Social_Meta::instance();
	Africart_PWA_Share_Image::instance();

	// Performance : la file d'attente écoute les dépôts, le réécrivain
	// sert les variantes. Les deux se désactivent séparément dans les
	// réglages, sans jamais toucher aux fichiers d'origine.
	Africart_PWA_Image_Queue::instance();
	Africart_PWA_Image_Rewriter::instance();
	Africart_PWA_Script_Delay::instance();
	Africart_PWA_CSS_Backgrounds::instance();
	Africart_PWA_Fonts::instance();
}
add_action( 'plugins_loaded', 'afk_pwa_bootstrap' );

/**
 * Commandes en ligne pour le rattrapage des images existantes.
 * Le traitement porte sur plusieurs milliers de fichiers : il ne peut
 * pas passer par le navigateur.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AFK_PWA_DIR . 'includes/perf/class-cli.php';
	WP_CLI::add_command( 'africart images', 'Africart_PWA_CLI' );
}

/* -------------------------------------------------------------------------
 * Activation / désactivation
 * Les endpoints racine reposent sur des règles de réécriture : il faut
 * les enregistrer PUIS vider les permaliens à l'activation.
 * ---------------------------------------------------------------------- */
function afk_pwa_activate() {
	require_once AFK_PWA_DIR . 'includes/class-manifest.php';
	require_once AFK_PWA_DIR . 'includes/class-service-worker.php';

	Africart_PWA_Manifest::instance()->add_rewrite_rules();
	Africart_PWA_Service_Worker::instance()->add_rewrite_rules();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'afk_pwa_activate' );

function afk_pwa_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'afk_pwa_deactivate' );
