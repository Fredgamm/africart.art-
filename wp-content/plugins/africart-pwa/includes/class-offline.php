<?php
/**
 * Page hors connexion.
 *
 * Page affichée aux couleurs de la charte lorsqu'un visiteur demande une
 * adresse qu'il n'a jamais consultée alors qu'il n'a plus de réseau.
 * C'est le service worker qui la restitue.
 *
 * Elle est servie depuis `/?afk_pwa=offline`, donc à l'intérieur du site
 * lui-même : le service worker ne peut mettre en cache que des adresses
 * de sa propre portée.
 *
 * ⚠️ Deux contraintes commandent son écriture :
 *
 *   1. **Tout est autonome** — styles compris. Cette page s'affiche sans
 *      réseau : la moindre feuille de style externe la laisserait nue.
 *
 *   2. **Les textes de TOUTES les langues y sont embarqués.** Elle n'est
 *      mise en cache qu'une fois, dans la langue du moment ; sans cela,
 *      un visiteur anglophone verrait le texte français. Un court script
 *      lit la langue choisie et remplace les textes à l'affichage.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Offline {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_serve_offline' ), 0 );
	}

	public function maybe_serve_offline() {
		if ( 'offline' !== get_query_var( 'afk_pwa' ) && ! isset( $_GET['afk_pwa'] ) ) {
			return;
		}
		if ( 'offline' !== ( isset( $_GET['afk_pwa'] ) ? sanitize_key( wp_unslash( $_GET['afk_pwa'] ) ) : get_query_var( 'afk_pwa' ) ) ) {
			return;
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		// Cacheable par le SW (contrairement au manifest/SW en no-cache).
		header( 'Cache-Control: public, max-age=3600' );
		echo $this->get_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private function get_html() {
		$lang    = Africart_PWA_I18n::get_current_lang();
		$name    = esc_html( Africart_PWA_I18n::text( 'app_name', $lang ) );
		$theme   = esc_attr( Africart_PWA_Settings::get( 'theme_color' ) );
		$bg      = esc_attr( Africart_PWA_Settings::get( 'background_color' ) );
		$acc     = esc_attr( Africart_PWA_Settings::get( 'accent_color' ) );
		$logo    = esc_url( Africart_PWA_Settings::get( 'icon_192' ) );
		$title   = esc_html( Africart_PWA_I18n::text( 'offline_title', $lang ) );
		$message = esc_html( Africart_PWA_I18n::text( 'offline_message', $lang ) );
		$button  = esc_html( Africart_PWA_I18n::text( 'offline_button', $lang ) );
		// Tous les textes, toutes langues : la page est pré-cachée UNE fois
		// par le Service Worker — la bascule de langue doit marcher sans réseau.
		$i18n_json = wp_json_encode( Africart_PWA_I18n::all_texts() );
		$default   = esc_js( Africart_PWA_I18n::default_lang() );

		ob_start();
		?><!DOCTYPE html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="<?php echo $theme; ?>">
	<title><?php echo $name; ?> — Hors connexion</title>
	<style>
		* { box-sizing: border-box; }
		body {
			margin: 0; min-height: 100vh; display: flex; align-items: center;
			justify-content: center; text-align: center; padding: 2rem;
			background: <?php echo $bg; ?>; color: #fff;
			font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
		}
		.afk-offline { max-width: 22rem; }
		.afk-offline img { width: 96px; height: 96px; border-radius: 22%; margin-bottom: 1.5rem; }
		.afk-offline h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
		.afk-offline p { opacity: .85; line-height: 1.5; margin: 0 0 2rem; }
		.afk-offline button {
			background: <?php echo $acc; ?>; color: #fff; border: 0;
			padding: .85rem 1.75rem; border-radius: 999px; font-size: 1rem;
			font-weight: 600; cursor: pointer;
		}
		.afk-offline button:active { transform: scale(.97); }
	</style>
</head>
<body>
	<div class="afk-offline">
		<img src="<?php echo $logo; ?>" alt="<?php echo $name; ?>">
		<h1 id="afk-title"><?php echo $title; ?></h1>
		<p id="afk-message"><?php echo $message; ?></p>
		<button id="afk-button" onclick="location.reload()"><?php echo $button; ?></button>
	</div>
	<script>
		// Recharge automatiquement dès que la connexion revient.
		window.addEventListener( 'online', function () { location.reload(); } );

		// i18n hors-ligne : cette page est pré-cachée UNE fois par le Service
		// Worker. Les textes de TOUTES les langues du site sont embarqués et
		// la langue active (cookie googtrans du widget de traduction) est
		// appliquée à l'affichage — aucun réseau nécessaire.
		( function () {
			var TEXTS = <?php echo $i18n_json; ?>;
			var lang = '<?php echo $default; ?>';
			var m = document.cookie.match( /(?:^|;\s*)googtrans=([^;]+)/ );
			if ( m ) {
				var parts = decodeURIComponent( m[1] ).split( '/' ).filter( Boolean );
				var target = parts[ parts.length - 1 ];
				if ( target && TEXTS[ target ] ) { lang = target; }
			}
			var t = TEXTS[ lang ];
			if ( t ) {
				document.documentElement.lang = lang;
				document.getElementById( 'afk-title' ).textContent = t.offline_title;
				document.getElementById( 'afk-message' ).textContent = t.offline_message;
				document.getElementById( 'afk-button' ).textContent = t.offline_button;
				document.title = t.app_name + ' — ' + t.offline_title;
			}
		} )();
	</script>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
