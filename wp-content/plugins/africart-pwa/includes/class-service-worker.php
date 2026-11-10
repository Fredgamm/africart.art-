<?php
/**
 * Service worker : mise à disposition et configuration.
 *
 * Sert le Service Worker à la RACINE du site (/africart-pwa-sw.js) afin
 * d'obtenir automatiquement le scope « / » requis pour l'installabilité.
 * Le service worker s'appuie sur Workbox et importe le SDK OneSignal :
 * un seul worker au scope racine, jamais deux.
 *
 * Le fichier assets/sw/service-worker.js est renvoyé tel quel, précédé
 * d'un objet de configuration `self.__AFK_CONFIG` injecté par PHP
 * (URL offline, version de cache, liste du shell à pré-cacher).
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Service_Worker {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		// Priorité 0 : servir avant redirect_canonical (slash final).
		add_action( 'template_redirect', array( $this, 'maybe_serve_sw' ), 0 );
		add_action( 'wp_footer', array( $this, 'print_registration' ) );
	}

	/**
	 * Endpoint racine : /africart-pwa-sw.js
	 */
	public function add_rewrite_rules() {
		add_rewrite_tag( '%afk_pwa%', '([^&]+)' );
		add_rewrite_rule(
			'^' . preg_quote( AFK_PWA_SW_SLUG, '/' ) . '$',
			'index.php?afk_pwa=sw',
			'top'
		);
	}

	/**
	 * Renvoie le Service Worker (JS) avec sa configuration injectée.
	 */
	public function maybe_serve_sw() {
		if ( 'sw' !== get_query_var( 'afk_pwa' ) ) {
			return;
		}

		$sw_path = AFK_PWA_DIR . 'assets/sw/service-worker.js';
		if ( ! file_exists( $sw_path ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		// Autorise explicitement le scope racine même si servi via rewrite.
		header( 'Service-Worker-Allowed: /' );

		echo '// AFRICART PWA Service Worker — généré le ' . esc_html( gmdate( 'c' ) ) . "\n";
		echo 'self.__AFK_CONFIG = ' . wp_json_encode( $this->get_sw_config() ) . ";\n";
		echo file_get_contents( $sw_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		exit;
	}

	/**
	 * Configuration passée au Service Worker.
	 *
	 * @return array
	 */
	private function get_sw_config() {
		$config = array(
			// Nomme le cache du service worker. Suivre la date du fichier
			// plutôt que la version du plugin : une correction mise en ligne
			// doit repartir sur un cache neuf, sinon les visiteurs
			// continueraient de recevoir l'ancienne version des ressources.
			'version'     => afk_pwa_asset_version( 'assets/sw/service-worker.js' ),
			'offlineUrl'  => home_url( '/?afk_pwa=offline' ),
			'origin'      => home_url( '/' ),
			// Shell minimal pré-caché à l'installation du SW.
			'precache'    => array(
				home_url( '/?afk_pwa=offline' ),
				AFK_PWA_URL . 'assets/icons/icon-192.png',
			),
			// ⚠️ Ce sans quoi le dépôt hors connexion n'existe pas.
			//
			// Ces fichiers n'entraient en cache qu'en étant chargés par une
			// page, avec du réseau. Tant qu'une seule visite ne les avait
			// pas tous ramenés, il en manquait un — et il manquait en
			// silence : le formulaire renonce à se construire quand la
			// réserve locale n'a pas pu se charger, sans rien afficher.
			// L'artiste voyait une page de dépôt vide, sans savoir
			// pourquoi ni quoi faire. Constaté sur iPhone 13.
			//
			// Le service worker va donc les chercher lui-même à son
			// activation, sans attendre qu'une page les demande.
			'assets'      => array_map(
				function ( $relatif ) {
					return AFK_PWA_URL . $relatif . '?ver=' . afk_pwa_asset_version( $relatif );
				},
				array(
					'assets/css/oeuvres.css',
					'assets/js/journal.js',
					'assets/js/oeuvres-db.js',
					'assets/js/oeuvres-sync.js',
					'assets/js/oeuvres.js',
					'assets/js/oeuvres-liste.js',
					'assets/js/oeuvres-etat.js',
					'assets/js/oeuvres-conflit.js',
					'assets/js/oeuvre-form.js',
					'assets/js/pays.js',
					'assets/js/entete.js',
				)
			),
			// Notifications : le SDK n'est importé que si le raccordement
			// est réellement configuré, pour ne pas charger inutilement un
			// script externe dans le service worker.
			'onesignal'   => class_exists( 'Africart_PWA_OneSignal' ) && Africart_PWA_OneSignal::is_enabled(),
		);

		return apply_filters( 'afk_pwa_sw_config', $config );
	}

	/**
	 * Inscription du Service Worker côté client (front uniquement).
	 */
	public function print_registration() {
		if ( is_admin() ) {
			return;
		}
		if ( '1' !== Africart_PWA_Settings::get( 'sw_enabled' ) ) {
			return;
		}

		$sw_url = home_url( '/' . AFK_PWA_SW_SLUG );
		?>
<script>
(function () {
	if ( ! ( 'serviceWorker' in navigator ) ) { return; }
	window.addEventListener( 'load', function () {
		navigator.serviceWorker.register( <?php echo wp_json_encode( $sw_url ); ?>, { scope: '/' } )
			.catch( function ( e ) { console.warn( 'AFRICART PWA: SW registration failed', e ); } );
	} );
})();
</script>
		<?php
	}
}
