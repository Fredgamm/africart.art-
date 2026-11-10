<?php
/**
 * Police BrutGrotesque (tout le site, désactivable) + CSS/JS des sections (uniquement là où un shortcode est utilisé).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @font-face sous le nom « BrutGrotesque » : c'est le nom déjà appelé par Elementor sur tout le site,
 * qui ne le trouvait pas (aucun fichier chargé) et retombait sur Helvetica/Arial.
 */
function afk_acc_font_face_css() {
	$base    = AFK_ACC_URL . 'assets/fonts/brut-grotesque-';
	$v     = '?v=' . AFK_ACC_VERSION;
	$faces = array(
		'light'   => '100 300',
		'regular' => '400',
		'medium'  => '500',
		'bold'    => '600 900', // Elementor demande aussi 600 et 800 : rattachés au gras.
	);
	$css = '';
	foreach ( $faces as $file => $w ) {
		$css .= "@font-face{font-family:'BrutGrotesque';src:url('{$base}{$file}.woff2{$v}') format('woff2'),url('{$base}{$file}.woff{$v}') format('woff');font-weight:{$w};font-style:normal;font-display:swap;}";
	}
	return $css;
}

/** La page courante contient-elle un de nos shortcodes (contenu classique ou données Elementor) ? */
function afk_acc_page_uses_shortcodes() {
	if ( ! is_singular() ) {
		return false;
	}
	$id = get_queried_object_id();
	if ( ! $id ) {
		return false;
	}
	$post = get_post( $id );
	if ( $post && false !== strpos( (string) $post->post_content, '[afk_' ) ) {
		return true;
	}
	$el = get_post_meta( $id, '_elementor_data', true );
	if ( is_array( $el ) ) {
		$el = wp_json_encode( $el );
	}
	return ( is_string( $el ) && false !== strpos( $el, '[afk_' ) );
}

add_action( 'wp_enqueue_scripts', function () {
	wp_register_style( 'afk-acc', AFK_ACC_URL . 'assets/css/accueil.css', array(), AFK_ACC_VERSION );
	wp_register_script( 'afk-acc', AFK_ACC_URL . 'assets/js/accueil.js', array(), AFK_ACC_VERSION, true );
	wp_localize_script(
		'afk-acc',
		'AFK_ACC',
		array(
			'suggest'  => esc_url_raw( rest_url( 'afk-acc/v1/suggest' ) ),
			'oeuvres'  => esc_url_raw( afk_acc_url( 'url_oeuvres' ) ),
			'offline'  => 'Recherche indisponible hors connexion.',
			'none'     => 'Aucune œuvre ne correspond à « %s »',
			'all'      => 'Tous les résultats pour « %s » →',
			'artist'   => 'artiste',
			'country'  => 'pays',
		)
	);

	if ( (int) afk_acc_opt( 'fonts_sitewide' ) ) {
		wp_register_style( 'afk-acc-fonts', false, array(), AFK_ACC_VERSION );
		wp_enqueue_style( 'afk-acc-fonts' );
		wp_add_inline_style( 'afk-acc-fonts', afk_acc_font_face_css() );
	}

	/*
	 * Correctif d'en-tête (tout le site) : le widget des drapeaux de langue (Google Language Translator,
	 * élément Elementor a4dcf30) dépasse de son conteneur 2b37f95 et crée ~40 px de défilement horizontal.
	 * Les drapeaux eux-mêmes restent dans le conteneur : on coupe seulement la boîte vide qui dépasse.
	 */
	wp_register_style( 'afk-acc-fix', false, array(), AFK_ACC_VERSION );
	wp_enqueue_style( 'afk-acc-fix' );
	wp_add_inline_style( 'afk-acc-fix', '.elementor-element-2b37f95{overflow-x:clip;}' );

	if ( afk_acc_page_uses_shortcodes() ) {
		afk_acc_enqueue();
	}
}, 20 );

/** Préchargement des deux graisses les plus utilisées, pour éviter le saut de police. */
add_action( 'wp_head', function () {
	if ( ! (int) afk_acc_opt( 'fonts_sitewide' ) && ! afk_acc_page_uses_shortcodes() ) {
		return;
	}
	foreach ( array( 'regular', 'bold' ) as $f ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( AFK_ACC_URL . 'assets/fonts/brut-grotesque-' . $f . '.woff2?v=' . AFK_ACC_VERSION )
		);
	}
}, 2 );

function afk_acc_enqueue() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	if ( ! (int) afk_acc_opt( 'fonts_sitewide' ) ) {
		wp_register_style( 'afk-acc-fonts', false, array(), AFK_ACC_VERSION );
		wp_enqueue_style( 'afk-acc-fonts' );
		wp_add_inline_style( 'afk-acc-fonts', afk_acc_font_face_css() );
	}
	wp_enqueue_style( 'afk-acc' );
	wp_add_inline_style(
		'afk-acc',
		sprintf(
			'.afk-acc{--afk-acc-primary:%1$s;--afk-acc-accent:%2$s;}',
			esc_attr( afk_acc_opt( 'primary' ) ),
			esc_attr( afk_acc_opt( 'accent' ) )
		)
	);
	wp_enqueue_script( 'afk-acc' );
}
