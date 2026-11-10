<?php
/**
 * Distribution des variantes dans les pages.
 *
 * Responsabilité unique : proposer au navigateur les variantes qui
 * existent, sans jamais modifier ce qui est affiché.
 *
 * Pourquoi réécrire le HTML plutôt que négocier côté serveur : le test
 * mené sur l'hébergement de production a montré que son cache ignore
 * l'en-tête `Vary`. Servir plusieurs contenus sur une même adresse y
 * aboutit à livrer un AVIF à un navigateur qui ne sait pas le lire.
 * Chaque format a donc sa propre adresse, et c'est le navigateur — et
 * lui seul — qui choisit.
 *
 * Les œuvres du site sont posées en fond CSS (`background-image`) et non
 * en balise `<img>` : le mécanisme retenu est `image-set()`, déclaré
 * après une déclaration de repli que les navigateurs anciens conservent.
 *
 * ⚠️ Règle absolue, vérifiée en test : ne déclarer QUE les variantes
 * présentes sur le disque. Un navigateur qui retient une variante
 * absente n'affiche rien — il ne se rabat pas sur l'entrée suivante.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Image_Rewriter {

	private static $instance = null;

	/**
	 * Rang du fond courant dans la page — sert au report du chargement :
	 * les premiers restent immédiats, les suivants attendent l'approche.
	 *
	 * @var int
	 */
	private $rang_fond = 0;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_lazy_script' ) );
		// Priorité 5 : au plus tôt dans le pied de page, avant tout script
		// tiers susceptible d'échouer et d'interrompre l'exécution.
		add_action( 'wp_footer', array( $this, 'print_lazy_script' ), 5 );
	}

	/**
	 * Script de restitution des fonds différés.
	 *
	 * Chargé seulement quand le report est actif : sans lui, les fonds
	 * sortis des styles ne s'afficheraient jamais.
	 */
	public function enqueue_lazy_script() {
		if ( is_admin() || ! Africart_PWA_Settings::is_rewrite_enabled() || ! Africart_PWA_Settings::is_lazy_enabled() ) {
			return;
		}

		// Squelette de chargement : un fond en attente affiche un reflet
		// plutôt qu'un vide. L'attribut data-afk-bg sert de crochet, sa
		// disparition emporte le squelette.
		wp_enqueue_style(
			'afk-pwa-lazy-bg',
			AFK_PWA_URL . 'assets/css/lazy-bg.css',
			array(),
			afk_pwa_asset_version( 'assets/css/lazy-bg.css' )
		);
	}

	/**
	 * Script de restitution des fonds différés, écrit DANS la page.
	 *
	 * ⚠️ Volontairement en ligne, jamais en fichier externe. Ce module
	 * retire les images du style : si le script qui les rend n'arrive
	 * pas, elles ne s'affichent **jamais**. Or un fichier externe peut
	 * ne pas arriver — constaté sur l'hébergement, dont le pare-feu
	 * répond 429 à la rafale de requêtes d'un chargement de page, feuilles
	 * de style et scripts compris.
	 *
	 * Une fonctionnalité qui cache des images ne doit dépendre de rien.
	 * Écrit dans la page, ce code arrive avec elle ou pas du tout.
	 */
	public function print_lazy_script() {
		if ( is_admin() || ! Africart_PWA_Settings::is_rewrite_enabled() || ! Africart_PWA_Settings::is_lazy_enabled() ) {
			return;
		}

		$js = file_get_contents( AFK_PWA_DIR . 'assets/js/lazy-bg.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $js ) {
			return;
		}

		printf( "<script id=\"afk-pwa-lazy-bg\">%s</script>\n", $js ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ------------------------------------------------------------------
	 * Mise en tampon de la page
	 * ---------------------------------------------------------------- */

	/**
	 * La page courante doit-elle être réécrite ?
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
		// Les endpoints du plugin servent du JSON ou du JavaScript.
		if ( get_query_var( 'afk_pwa' ) ) {
			return false;
		}
		if ( ! Africart_PWA_Settings::is_rewrite_enabled() ) {
			return false;
		}

		/**
		 * Permet d'exclure ponctuellement une page de la réécriture.
		 *
		 * @param bool $run Réécrire cette page.
		 */
		return (bool) apply_filters( 'afk_pwa_rewrite_images', true );
	}

	/**
	 * Ouvre le tampon de sortie.
	 */
	public function start_buffer() {
		if ( ! $this->should_run() ) {
			return;
		}
		ob_start( array( $this, 'rewrite' ) );
	}

	/**
	 * Point d'entrée du filtrage. Toute anomalie renvoie le HTML
	 * d'origine : une page non optimisée vaut mieux qu'une page cassée.
	 *
	 * @param string $html HTML complet de la page.
	 * @return string
	 */
	public function rewrite( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		// Ne traiter que du HTML : un flux binaire ou du JSON passe outre.
		if ( false === stripos( ltrim( $html ), '<' ) ) {
			return $html;
		}

		$this->rang_fond = 0;

		$result = $this->rewrite_css_backgrounds( $html );

		return is_string( $result ) && '' !== $result ? $result : $html;
	}

	/* ------------------------------------------------------------------
	 * Fonds CSS — le cas de la quasi-totalité des œuvres
	 * ---------------------------------------------------------------- */

	/**
	 * Ajoute une déclaration `image-set()` à chaque attribut de style
	 * portant une image de fond convertible.
	 *
	 * @param string $html HTML de la page.
	 * @return string
	 */
	private function rewrite_css_backgrounds( $html ) {
		// On ne cible que les attributs style délimités par des guillemets
		// doubles — le cas produit par Elementor et Voxel. Un attribut
		// délimité autrement est laissé intact plutôt que risqué.
		$pattern = '/style\s*=\s*"([^"]*background-image\s*:[^"]*)"/i';

		$out = preg_replace_callback(
			$pattern,
			array( $this, 'rewrite_style_attribute' ),
			$html
		);

		// preg_replace_callback renvoie null en cas d'erreur (dépassement
		// de la limite de retour arrière sur une très grande page).
		return null === $out ? $html : $out;
	}

	/**
	 * Réécrit un attribut de style isolé.
	 *
	 * @param array $matches Résultat de la correspondance.
	 * @return string L'attribut complet, réécrit ou inchangé.
	 */
	private function rewrite_style_attribute( $matches ) {
		$original_attr = $matches[0];

		// On travaille sur du CSS lisible, pas sur des entités HTML.
		$css = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );

		// Déjà traité (page mise en cache par un tiers, double filtrage…).
		if ( false !== stripos( $css, 'image-set(' ) ) {
			return $original_attr;
		}

		if ( ! preg_match( '/background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $css, $url_match ) ) {
			return $original_attr;
		}

		$url        = trim( $url_match[1] );
		$candidates = Africart_PWA_Image_Variants::existing_for_url( $url );

		// La déclaration de repli reste en place : les navigateurs qui ne
		// comprennent pas image-set() ignorent la seconde et gardent la
		// première. Ceux qui la comprennent la font gagner.
		$declaration = $candidates ? $this->build_image_set( $url, $candidates ) : '';
		$new_css     = '' === $declaration ? $css : rtrim( $css, "; \t\n\r" ) . ';' . $declaration;

		++$this->rang_fond;

		// Report du chargement : au-delà du quota immédiat, les
		// déclarations d'image sortent du style et attendent dans un
		// attribut que le script les restitue à l'approche. Le navigateur
		// ne sait pas différer un fond CSS — il télécharge tout, y
		// compris ce que personne ne verra sans défiler. Un fond sans
		// variante allégée profite lui aussi du report.
		if ( $this->should_defer() ) {
			$differees = array();

			$reste = preg_replace_callback(
				'/background-image\s*:[^;]*(?:;|$)/i',
				function ( $m ) use ( &$differees ) {
					$differees[] = rtrim( trim( $m[0] ), ';' );
					return '';
				},
				$new_css
			);

			if ( $differees && null !== $reste ) {
				return 'style="' . esc_attr( trim( $reste ) ) . '"'
					. ' data-afk-bg="' . esc_attr( implode( ';', $differees ) ) . '"';
			}
		}

		if ( '' === $declaration ) {
			return $original_attr;
		}

		return 'style="' . esc_attr( $new_css ) . '"';
	}

	/**
	 * Le fond courant doit-il être différé ?
	 *
	 * Les premiers fonds de la page restent immédiats : ce sont eux qui
	 * peuvent être à l'écran dès l'arrivée, et retarder l'élément le plus
	 * visible dégraderait précisément la métrique qu'on cherche à gagner.
	 *
	 * @return bool
	 */
	private function should_defer() {
		return Africart_PWA_Settings::is_lazy_enabled()
			&& $this->rang_fond > Africart_PWA_Settings::lazy_eager_count();
	}

	/**
	 * Construit la déclaration `image-set()`.
	 *
	 * L'ordre des entrées EST l'ordre de priorité : le navigateur retient
	 * la première dont il sait lire le type.
	 *
	 * @param string $original_url URL de l'original.
	 * @param array  $candidates   Variantes existantes, les plus efficaces d'abord.
	 * @return string Déclaration CSS, ou '' si rien à proposer.
	 */
	private function build_image_set( $original_url, $candidates ) {
		$entries = array();

		foreach ( $candidates as $variant ) {
			if ( empty( $variant['url'] ) ) {
				continue;
			}
			$entries[] = sprintf( "url('%s') type('%s')", $variant['url'], $variant['mime'] );
		}

		if ( ! $entries ) {
			return '';
		}

		// L'original ferme toujours la liste : c'est le dernier recours.
		$entries[] = sprintf( "url('%s') type('%s')", $original_url, $this->mime_of( $original_url ) );

		return sprintf( 'background-image:image-set(%s);', implode( ',', $entries ) );
	}

	/**
	 * Type MIME d'après l'extension d'une URL.
	 *
	 * @param string $url URL du fichier.
	 * @return string
	 */
	private function mime_of( $url ) {
		$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		return 'png' === $ext ? 'image/png' : 'image/jpeg';
	}
}
