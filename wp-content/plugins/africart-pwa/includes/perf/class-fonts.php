<?php
/**
 * Chargement des polices.
 *
 * Responsabilité unique : améliorer la façon dont les polices déjà
 * installées sont chargées. Ce module n'ajoute aucune police et n'en
 * retire aucune.
 *
 * Deux défauts constatés sur les déclarations produites par Elementor :
 *
 *   1. `font-display: auto` — le navigateur masque le texte jusqu'à ce
 *      que la police soit téléchargée. Sur une connexion mobile, cela
 *      représente plusieurs secondes de page blanche alors que le texte
 *      est déjà disponible. `swap` l'affiche immédiatement, dans une
 *      police système, puis substitue la police finale.
 *
 *   2. Format `.woff` seul — le `.woff2` pèse environ un tiers de moins
 *      pour un rendu identique, et il est compris par tous les
 *      navigateurs en usage.
 *
 * Le module republie donc les mêmes déclarations, après celles
 * d'Elementor : à famille, graisse et style identiques, c'est la
 * dernière déclaration qui s'applique. Aucun fichier d'Elementor n'est
 * modifié, et désactiver le module rétablit l'état antérieur.
 *
 * Le `.woff2` n'est proposé que s'il existe réellement à côté du
 * `.woff` : une déclaration pointant un fichier absent ferait perdre la
 * police.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Fonts {

	private static $instance = null;

	/**
	 * Déclarations lues dans les feuilles d'Elementor, en cache.
	 *
	 * @var array|null
	 */
	private $faces = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Après les feuilles de style d'Elementor, pour gagner la cascade.
		add_action( 'wp_head', array( $this, 'print_overrides' ), 98 );
	}

	/* ------------------------------------------------------------------
	 * Lecture des déclarations existantes
	 * ---------------------------------------------------------------- */

	/**
	 * Récupère les déclarations `@font-face` des feuilles générées par
	 * Elementor. Le résultat est mis en cache une journée : ces fichiers
	 * ne changent qu'à la modification d'un modèle.
	 *
	 * @return array<int,array{family:string,weight:string,style:string,woff:string}>
	 */
	private function get_faces() {
		if ( null !== $this->faces ) {
			return $this->faces;
		}

		$cached = get_transient( 'afk_pwa_font_faces' );
		if ( is_array( $cached ) ) {
			$this->faces = $cached;
			return $this->faces;
		}

		$faces   = array();
		$uploads = wp_get_upload_dir();
		$dir     = $uploads['basedir'] . '/elementor/css';

		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*.css' ) as $file ) {
				$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false === $css || false === stripos( $css, '@font-face' ) ) {
					continue;
				}
				$faces = array_merge( $faces, $this->parse_faces( $css ) );
			}
		}

		// Dédoublonnage : la même police peut être déclarée dans
		// plusieurs feuilles.
		$unique = array();
		foreach ( $faces as $face ) {
			$key            = $face['family'] . '|' . $face['weight'] . '|' . $face['style'];
			$unique[ $key ] = $face;
		}

		$this->faces = array_values( $unique );
		set_transient( 'afk_pwa_font_faces', $this->faces, DAY_IN_SECONDS );

		return $this->faces;
	}

	/**
	 * Extrait les déclarations d'une feuille de style.
	 *
	 * @param string $css Contenu CSS.
	 * @return array
	 */
	private function parse_faces( $css ) {
		$found = array();

		if ( ! preg_match_all( '/@font-face\s*\{([^}]*)\}/i', $css, $blocks ) ) {
			return $found;
		}

		foreach ( $blocks[1] as $block ) {
			// Seules les polices servies depuis ce site nous concernent.
			if ( ! preg_match( '/url\(\s*[\'"]?([^\'")]+\.woff)[\'"]?\s*\)/i', $block, $url ) ) {
				continue;
			}
			if ( ! preg_match( '/font-family\s*:\s*[\'"]?([^\'";]+)/i', $block, $family ) ) {
				continue;
			}

			preg_match( '/font-weight\s*:\s*([^;}]+)/i', $block, $weight );
			preg_match( '/font-style\s*:\s*([^;}]+)/i', $block, $style );

			$found[] = array(
				'family' => trim( $family[1] ),
				'weight' => isset( $weight[1] ) ? trim( $weight[1] ) : 'normal',
				'style'  => isset( $style[1] ) ? trim( $style[1] ) : 'normal',
				'woff'   => trim( $url[1] ),
			);
		}

		return $found;
	}

	/* ------------------------------------------------------------------
	 * Sortie
	 * ---------------------------------------------------------------- */

	/**
	 * Republie les déclarations avec `font-display: swap` et, quand il
	 * existe, le fichier `.woff2`.
	 */
	public function print_overrides() {
		if ( ! Africart_PWA_Settings::is_fonts_enabled() ) {
			return;
		}

		$rules = array();

		foreach ( $this->get_faces() as $face ) {
			$sources = array();

			$woff2_url = $this->woff2_for( $face['woff'] );
			if ( $woff2_url ) {
				$sources[] = sprintf( "url('%s') format('woff2')", $woff2_url );
			}
			$sources[] = sprintf( "url('%s') format('woff')", $face['woff'] );

			$rules[] = sprintf(
				"@font-face{font-family:'%s';font-style:%s;font-weight:%s;font-display:swap;src:%s;}",
				esc_attr( $face['family'] ),
				esc_attr( $face['style'] ),
				esc_attr( $face['weight'] ),
				implode( ',', $sources )
			);
		}

		if ( ! $rules ) {
			return;
		}

		printf( "<style id=\"afk-pwa-fonts\">%s</style>\n", implode( '', $rules ) );
	}

	/**
	 * URL du `.woff2` correspondant, s'il est présent sur le disque.
	 *
	 * @param string $woff_url URL du fichier `.woff`.
	 * @return string URL du `.woff2`, ou '' s'il n'existe pas.
	 */
	private function woff2_for( $woff_url ) {
		$woff2_url = preg_replace( '/\.woff$/i', '.woff2', $woff_url );
		if ( $woff2_url === $woff_url ) {
			return '';
		}

		$path = Africart_PWA_Image_Variants::url_to_path( $woff2_url );
		if ( '' === $path || ! file_exists( $path ) ) {
			return '';
		}

		return $woff2_url;
	}

	/**
	 * État des polices détectées, pour l'écran de réglages.
	 *
	 * @return array<int,array{family:string,weight:string,woff2:bool,file:string}>
	 */
	public function get_status() {
		$status = array();

		foreach ( $this->get_faces() as $face ) {
			$status[] = array(
				'family' => $face['family'],
				'weight' => $face['weight'],
				'woff2'  => '' !== $this->woff2_for( $face['woff'] ),
				'file'   => basename( $face['woff'] ),
			);
		}

		return $status;
	}

	/**
	 * Oublie les déclarations mises en cache — à appeler lorsqu'Elementor
	 * régénère ses feuilles de style.
	 */
	public static function flush_cache() {
		delete_transient( 'afk_pwa_font_faces' );
	}
}
