<?php
/**
 * Report des scripts tiers au premier geste du visiteur.
 *
 * Mesuré sur l'accueil : 63 % du JavaScript n'est jamais exécuté, et
 * les trois plus gros postes sont des tiers — Google Analytics
 * (424 Ko de source), Google Translate (260 Ko), SDK OneSignal
 * (138 Ko). Aucun n'a besoin de bloquer l'arrivée sur la page :
 *
 *   - Analytics : la page vue se mesure très bien avec quelques
 *     secondes de retard — le pied de page standard de gtag met les
 *     événements en file (`dataLayer`) en attendant le script ;
 *   - Translate : personne ne clique un drapeau dans la première
 *     seconde — et un visiteur DÉJÀ traduit (cookie googtrans) est
 *     servi immédiatement, sa langue ne doit pas attendre son geste ;
 *   - OneSignal : notre raccordement passe par `OneSignalDeferred`,
 *     la file officielle — l'initialisation part à l'arrivée du SDK.
 *
 * Mécanique : dans le tampon de sortie, la balise d'un script visé
 * devient `type="afk/delay"` — un type inconnu, que le navigateur ne
 * télécharge ni n'exécute. Au premier geste (ou après un délai de
 * secours), `delay.js` restitue les balises et les scripts partent.
 *
 * Les scripts visés se règlent depuis l'écran Performance : une ligne
 * par fragment d'adresse. Retirer une ligne rend le script immédiat.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Script_Delay {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priorité 2 : son tampon s'ouvre APRÈS celui du réécrivain
		// d'images (priorité 1), donc se ferme avant — chacun reçoit la
		// page entière, dans un ordre déterministe.
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_loader' ) );
	}

	/* ------------------------------------------------------------------
	 * Activation
	 * ---------------------------------------------------------------- */

	/**
	 * Le report est-il actif sur cette page ?
	 *
	 * @return bool
	 */
	private function should_run() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return false;
		}
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( get_query_var( 'afk_pwa' ) ) {
			return false;
		}
		if ( '1' !== Africart_PWA_Settings::get( 'perf_delay' ) ) {
			return false;
		}

		return (bool) self::patterns();
	}

	/**
	 * Fragments d'adresse des scripts à différer — un par ligne dans le
	 * réglage, nettoyés des vides.
	 *
	 * @return string[]
	 */
	public static function patterns() {
		$brut = (string) Africart_PWA_Settings::get( 'perf_delay_urls' );

		$lignes = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $brut ) ) );

		return array_values( $lignes );
	}

	/* ------------------------------------------------------------------
	 * Réécriture des balises
	 * ---------------------------------------------------------------- */

	/**
	 * Ouvre le tampon de sortie.
	 */
	public function start_buffer() {
		if ( ! $this->should_run() ) {
			return;
		}
		ob_start( array( $this, 'defer_scripts' ) );
	}

	/**
	 * Neutralise les balises des scripts visés.
	 *
	 * Toute anomalie rend le HTML d'origine : une page non optimisée
	 * vaut mieux qu'une page cassée.
	 *
	 * @param string $html HTML complet de la page.
	 * @return string
	 */
	public function defer_scripts( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false === stripos( ltrim( $html ), '<' ) ) {
			return $html;
		}

		$patterns = self::patterns();

		$out = preg_replace_callback(
			'#<script([^>]*?)\ssrc=("|\')([^"\']+)\2([^>]*)>#i',
			function ( $m ) use ( $patterns ) {
				foreach ( $patterns as $p ) {
					if ( false !== stripos( $m[3], $p ) ) {
						// `type` inconnu : le navigateur ignore la balise.
						// L'adresse attend dans data-afk-src ; les autres
						// attributs (id, defer…) restent en place.
						$attrs = preg_replace( '/\stype=("|\')[^"\']*\1/i', '', $m[1] . $m[4] );

						return '<script type="afk/delay" data-afk-src="' . esc_attr( $m[3] ) . '"' . $attrs . '>';
					}
				}
				return $m[0];
			},
			$html
		);

		return null === $out ? $html : $out;
	}

	/* ------------------------------------------------------------------
	 * Chargeur
	 * ---------------------------------------------------------------- */

	/**
	 * Charge le script qui restituera les balises différées.
	 */
	public function enqueue_loader() {
		if ( ! $this->should_run() ) {
			return;
		}

		wp_enqueue_script(
			'afk-pwa-delay',
			AFK_PWA_URL . 'assets/js/delay.js',
			array(),
			afk_pwa_asset_version( 'assets/js/delay.js' ),
			true
		);

		wp_add_inline_script(
			'afk-pwa-delay',
			'window.AFK_PWA_DELAY = ' . wp_json_encode(
				array(
					// Délai de secours : un visiteur immobile finit servi.
					'timeout' => max( 1, (int) Africart_PWA_Settings::get( 'perf_delay_timeout' ) ),
				)
			) . ';',
			'before'
		);
	}
}
