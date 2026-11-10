<?php
/**
 * Manifest de l'application.
 *
 * Sert un Web App Manifest dynamique à la racine du site
 * (/africart-manifest.json) et injecte les balises <link>/<meta>
 * nécessaires à l'installabilité, y compris les métas iOS (Apple ne
 * lit pas le manifest : installation guidée + splash via <link>).
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Manifest {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		// Marges de sécurité : seulement utiles au mode translucide.
		add_action( 'wp_head', array( $this, 'print_safe_area' ), 2 );
		// Priorité 0 : servir AVANT redirect_canonical (qui tourne à 10 et
		// ajouterait un slash final cassant l'endpoint).
		add_action( 'template_redirect', array( $this, 'maybe_serve_manifest' ), 0 );
		add_filter( 'redirect_canonical', array( $this, 'disable_canonical' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'print_head_tags' ), 1 );
	}

	/**
	 * Endpoint racine : /africart-manifest.json
	 */
	public function add_rewrite_rules() {
		add_rewrite_tag( '%afk_pwa%', '([^&]+)' );
		add_rewrite_rule(
			'^' . preg_quote( AFK_PWA_MANIFEST_SLUG, '/' ) . '$',
			'index.php?afk_pwa=manifest',
			'top'
		);
	}

	/**
	 * Coupe la redirection canonique (slash final) pour tous les endpoints PWA.
	 *
	 * @param string $redirect_url URL de redirection proposée.
	 * @return string|false
	 */
	public function disable_canonical( $redirect_url ) {
		if ( get_query_var( 'afk_pwa' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Génère le manifest si l'endpoint est appelé.
	 */
	public function maybe_serve_manifest() {
		if ( 'manifest' !== get_query_var( 'afk_pwa' ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $this->get_manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Décrit une capture pour le manifest.
	 *
	 * Retourne null si l'image est absente ou ses dimensions
	 * indéterminables : mieux vaut une capture de moins qu'une entrée
	 * que le navigateur écartera en signalant une erreur.
	 *
	 * @param string $cle_image  Clé de réglage de l'image.
	 * @param string $cle_width  Clé de réglage de la largeur.
	 * @param string $cle_height Clé de réglage de la hauteur.
	 * @param string $format     Valeur de form_factor, ou '' pour l'omettre.
	 * @param string $libelle    Libellé affiché.
	 * @return array|null
	 */
	private function screenshot( $cle_image, $cle_width, $cle_height, $format, $libelle ) {
		$url = Africart_PWA_Settings::get( $cle_image );
		if ( ! $url ) {
			return null;
		}

		$sizes = $this->sizes_for( $url, $cle_width, $cle_height );
		if ( '' === $sizes ) {
			return null;
		}

		$capture = array(
			'src'   => $this->versionne( $url ),
			'sizes' => $sizes,
			'type'  => $this->mime_from_url( $url ),
			'label' => $libelle,
		);

		if ( $format ) {
			$capture['form_factor'] = $format;
		}

		return $capture;
	}

	/**
	 * Dimensions d'une capture, au format « largeur x hauteur ».
	 *
	 * Les champs de dimensions sont saisis à la main dans les réglages :
	 * un champ vide y est enregistré comme 0, ce qui produirait un
	 * « 0x0 ». Le navigateur écarte alors la capture et signale une
	 * image sans taille valable.
	 *
	 * On mesure donc le fichier lui-même dès que la valeur saisie est
	 * inutilisable — le manifest reste juste quoi qu'il arrive.
	 *
	 * @param string $url        URL de l'image.
	 * @param string $cle_width  Clé de réglage de la largeur.
	 * @param string $cle_height Clé de réglage de la hauteur.
	 * @return string Dimensions, ou chaîne vide si indéterminables.
	 */
	private function sizes_for( $url, $cle_width, $cle_height ) {
		$largeur = (int) Africart_PWA_Settings::get( $cle_width );
		$hauteur = (int) Africart_PWA_Settings::get( $cle_height );

		if ( $largeur > 0 && $hauteur > 0 ) {
			return $largeur . 'x' . $hauteur;
		}

		// Valeur inexploitable : on interroge le fichier.
		$chemin = Africart_PWA_Image_Variants::url_to_path( $url );
		if ( $chemin && file_exists( $chemin ) ) {
			$mesure = @getimagesize( $chemin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $mesure && $mesure[0] > 0 && $mesure[1] > 0 ) {
				return $mesure[0] . 'x' . $mesure[1];
			}
		}

		return '';
	}

	/**
	 * Ajoute à l'URL d'une image un numéro tiré de sa date de modification.
	 *
	 * Sans lui, remplacer une icône ne met **jamais** à jour l'application
	 * installée : le serveur livre les images avec sept jours de cache, et
	 * la vérification périodique du navigateur — qui compare le manifest —
	 * y voit la même URL, donc rien à faire. L'anomalie a été constatée :
	 * un nouveau logo déployé, l'app installée gardait l'ancien.
	 *
	 * Avec le numéro, changer le fichier change l'URL ; le manifest étant
	 * servi sans cache, la vérification voit la différence et reconstruit
	 * l'icône installée (Android et ordinateur ; iOS ne relit jamais
	 * l'icône d'une app posée sur l'écran d'accueil — réinstallation).
	 *
	 * @param string $url URL de l'image.
	 * @return string URL versionnée, ou inchangée si le fichier est introuvable.
	 */
	private function versionne( $url ) {
		if ( ! $url ) {
			return $url;
		}

		// Fichier du plugin, ou de la médiathèque : deux racines possibles.
		$chemin = '';
		if ( 0 === strpos( $url, AFK_PWA_URL ) ) {
			$chemin = AFK_PWA_DIR . substr( $url, strlen( AFK_PWA_URL ) );
		} elseif ( class_exists( 'Africart_PWA_Image_Variants' ) ) {
			$chemin = Africart_PWA_Image_Variants::url_to_path( $url );
		}

		if ( ! $chemin || ! file_exists( $chemin ) ) {
			return $url;
		}

		return add_query_arg( 'v', (string) filemtime( $chemin ), $url );
	}

	/**
	 * Type MIME d'une image d'après son extension.
	 *
	 * @param string $url URL de l'image.
	 * @return string
	 */
	private function mime_from_url( $url ) {
		$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			'svg'  => 'image/svg+xml',
		);
		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'image/png';
	}

	/**
	 * Données du manifest, à partir des réglages admin (Réglages >
	 * AFRICART PWA) avec repli sur les défauts. Filtrable via
	 * `afk_pwa_manifest`.
	 *
	 * @return array
	 */
	public function get_manifest() {
		$s = 'Africart_PWA_Settings';

		// Langue active de l'utilisateur (cookie googtrans du widget de
		// traduction) : le manifest téléchargé est servi dans SA langue.
		$lang = Africart_PWA_I18n::get_current_lang();

		$categories = array_filter( array_map( 'trim', explode( ',', (string) $s::get( 'categories' ) ) ) );

		$manifest = array(
			'name'             => Africart_PWA_I18n::text( 'app_name', $lang ),
			'short_name'       => $s::get( 'short_name' ),
			'description'      => Africart_PWA_I18n::text( 'description', $lang ),
			'lang'             => $lang,
			'dir'              => 'ltr',
			'start_url'        => $s::get( 'start_url' ),
			'scope'            => '/',
			'display'          => $s::get( 'display' ),
			'orientation'      => $s::get( 'orientation' ),
			'theme_color'      => $s::get( 'theme_color' ),
			'background_color' => $s::get( 'background_color' ),
			'categories'       => array_values( $categories ),
			'icons'            => array(
				array(
					'src'     => $this->versionne( $s::get( 'icon_192' ) ),
					'sizes'   => '192x192',
					'type'    => $this->mime_from_url( $s::get( 'icon_192' ) ),
					'purpose' => 'any',
				),
				array(
					'src'     => $this->versionne( $s::get( 'icon_512' ) ),
					'sizes'   => '512x512',
					'type'    => $this->mime_from_url( $s::get( 'icon_512' ) ),
					'purpose' => 'any',
				),
				array(
					'src'     => $this->versionne( $s::get( 'icon_maskable' ) ),
					'sizes'   => '512x512',
					'type'    => $this->mime_from_url( $s::get( 'icon_maskable' ) ),
					'purpose' => 'maskable',
				),
			),
			// Raccourci (menu long-press de l'icône installée).
			'shortcuts'        => array(
				array(
					'name'  => Africart_PWA_I18n::text( 'shortcut_name', $lang ),
					'url'   => $s::get( 'shortcut_url' ),
					'icons' => array(
						array(
							'src'   => $this->versionne( $s::get( 'shortcut_icon' ) ),
							'sizes' => '96x96',
							'type'  => $this->mime_from_url( $s::get( 'shortcut_icon' ) ),
						),
					),
				),
			),
			// Captures — elles font apparaître la fenêtre d'installation
			// détaillée de Chrome. Celle du bureau exige form_factor
			// « wide », celle du mobile doit en déclarer un autre.
			'screenshots'      => array_values(
				array_filter(
					array(
						$this->screenshot( 'screenshot_wide', 'screenshot_wide_w', 'screenshot_wide_h', 'wide', __( 'Accueil AFRICART — bureau', 'africart-pwa' ) ),
						$this->screenshot( 'screenshot_mobile', 'screenshot_mobile_w', 'screenshot_mobile_h', '', __( 'Accueil AFRICART — mobile', 'africart-pwa' ) ),
					)
				)
			),
		);

		/**
		 * Permet de surcharger le manifest.
		 */
		return apply_filters( 'afk_pwa_manifest', $manifest );
	}

	/**
	 * Marges de sécurité pour le mode « barre d'état translucide ».
	 *
	 * Ce mode fait passer le contenu sous l'heure, la batterie et
	 * l'encoche. Deux conditions pour le rendre vivable, et l'une est
	 * facile à manquer :
	 *
	 *   1. le viewport doit porter `viewport-fit=cover`, sans quoi les
	 *      variables `env(safe-area-inset-*)` valent **zéro** — le
	 *      correctif CSS serait sans aucun effet. Le thème écrit son
	 *      propre viewport : on le complète en JavaScript plutôt que de
	 *      le remplacer, pour ne rien lui prendre ;
	 *   2. la page doit réserver la hauteur de la zone protégée, et
	 *      seulement une fois l'application installée — dans un onglet
	 *      de navigateur, la barre d'état n'appartient pas à la page.
	 *
	 * Rien n'est émis dans les autres modes : la place est déjà réservée
	 * par iOS, et y ajouter une marge créerait un bandeau vide.
	 */
	public function print_safe_area() {
		if ( 'black-translucent' !== Africart_PWA_Settings::get( 'ios_status_bar' ) ) {
			return;
		}
		?>
<style id="afk-pwa-safe-area">
@media (display-mode: standalone) {
	body { padding-top: env(safe-area-inset-top, 0px); box-sizing: border-box; }
	/* Les éléments fixés en haut suivent la même règle, sinon ils
	   restent, eux, sous la barre d'état. */
	.afk-install-top { top: calc(14px + env(safe-area-inset-top, 0px)); }
}
</style>
<script id="afk-pwa-safe-area-js">
(function(){
	var v = document.querySelector('meta[name="viewport"]');
	if (v && v.content.indexOf('viewport-fit') === -1) {
		v.content += ', viewport-fit=cover';
	}
})();
</script>
		<?php
	}

	/**
	 * Balises <head> : lien manifest, theme-color, et métas iOS.
	 * Valeurs personnalisables via Réglages > AFRICART PWA.
	 */
	public function print_head_tags() {
		$manifest_url = home_url( '/' . AFK_PWA_MANIFEST_SLUG );

		printf( '<link rel="manifest" href="%s">' . "\n", esc_url( $manifest_url ) );
		printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( Africart_PWA_Settings::get( 'theme_color' ) ) );

		// Balise normalisée, désormais attendue par les navigateurs.
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		// Son ancêtre propre à Apple : signalée comme obsolète par Chrome,
		// mais toujours nécessaire aux versions d'iOS encore en usage.
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";

		/*
		 * Barre d'état iOS. Le choix a un effet visible, constaté sur
		 * appareil : « black-translucent » fait passer le contenu SOUS
		 * l'heure et la batterie — le haut de la page s'y cache.
		 *
		 * La valeur d'usine est donc « default », qui réserve la place de
		 * la barre. Voir print_safe_area() pour le cas translucide.
		 */
		$barre = Africart_PWA_Settings::get( 'ios_status_bar' );
		printf( '<meta name="apple-mobile-web-app-status-bar-style" content="%s">' . "\n", esc_attr( $barre ) );
		printf( '<meta name="apple-mobile-web-app-title" content="%s">' . "\n", esc_attr( Africart_PWA_Settings::get( 'short_name' ) ) );
		printf(
			'<link rel="apple-touch-icon" href="%s">' . "\n",
			esc_url( $this->versionne( Africart_PWA_Settings::get( 'apple_touch_icon' ) ) )
		);
	}
}
