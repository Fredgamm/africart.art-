<?php
/**
 * Réglages : Réglages > Accueil AFRICART.
 * Textes et couleurs modifiables sans toucher au code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function afk_acc_defaults() {
	return array(
		'primary'          => '#011638',
		'accent'           => '#C9A24E',
		'fonts_sitewide'   => 1,
		'hero_title'       => 'Répertoire universel des œuvres d’artistes africain·e·s',
		'hero_text'        => 'Des œuvres référencées par des artistes, des galeries et des collectionneurs. <strong>Inscription gratuite</strong> pour consulter chaque fiche complète.',
		'hero_text_connected' => 'Des œuvres référencées par des artistes, des galeries et des collectionneurs, à consulter librement en tant que membre.',
		'hero_placeholder' => 'Un artiste, une œuvre, un pays…',
		'hero_placeholder_court' => 'Artiste, œuvre, pays…',
		'index_title'      => 'Vient d’entrer<br>au répertoire',
		'index_note'       => 'Les fiches complètes sont réservées aux inscrits.',
		'ref_title'        => 'Artiste, galeriste ou collectionneur ? Référencez vos œuvres. <span>C’est gratuit.</span>',
		'ref_text'         => 'Œuvres uniques ou en édition limitée (50 exemplaires maximum). Le nom du propriétaire reste confidentiel. Chaque œuvre validée reçoit sa référence AFRICART.',
		'artist_title'     => 'Chaque artiste a sa page',
		'artist_text'      => 'Toutes ses œuvres réunies à une adresse simple, à partager sur les réseaux ou sur son propre site. Les galeries ont la leur aussi.',
		'show_abac'        => 0,
		'url_oeuvres'      => '/oeuvres/',
		'url_inscription'  => '/login-registration/',
		'url_enregistrer'  => '/enregistrez-votre-oeuvre/',
		'url_comment'      => '/comment-ca-marche/',
		'url_pourquoi'     => '/pourquoi-africart/',
		'url_africanhist'  => 'https://africanhistory.site/',
		'url_abac'         => 'https://abac.art/',
	);
}

function afk_acc_opt( $key ) {
	static $opts = null;
	if ( null === $opts ) {
		$opts = wp_parse_args( (array) get_option( 'afk_acc_options', array() ), afk_acc_defaults() );
	}
	return isset( $opts[ $key ] ) ? $opts[ $key ] : '';
}

/** URL interne relative → absolue ; URL externe conservée. */
function afk_acc_url( $key ) {
	$u = (string) afk_acc_opt( $key );
	if ( '' === $u ) {
		return home_url( '/' );
	}
	return ( 0 === strpos( $u, '/' ) ) ? home_url( $u ) : $u;
}

/** HTML limité autorisé dans les textes (gras, italique, span, lien, retour ligne). */
function afk_acc_kses( $html ) {
	return wp_kses(
		(string) $html,
		array(
			'strong' => array(),
			'em'     => array(),
			'span'   => array(),
			'br'     => array(),
			'a'      => array( 'href' => array() ),
		)
	);
}

add_action( 'admin_menu', function () {
	add_options_page( 'Accueil AFRICART', 'Accueil AFRICART', 'manage_options', 'afk-accueil', 'afk_acc_render_settings' );
} );

add_action( 'admin_init', function () {
	register_setting(
		'afk_acc',
		'afk_acc_options',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'afk_acc_sanitize_options',
		)
	);
} );

function afk_acc_sanitize_options( $in ) {
	$d   = afk_acc_defaults();
	$out = array();
	foreach ( $d as $k => $def ) {
		$v = isset( $in[ $k ] ) ? $in[ $k ] : '';
		if ( in_array( $k, array( 'fonts_sitewide', 'show_abac' ), true ) ) {
			$out[ $k ] = empty( $v ) ? 0 : 1;
		} elseif ( in_array( $k, array( 'primary', 'accent' ), true ) ) {
			$c         = sanitize_hex_color( $v );
			$out[ $k ] = $c ? $c : $def;
		} elseif ( 0 === strpos( $k, 'url_' ) ) {
			$v         = trim( (string) $v );
			$out[ $k ] = ( 0 === strpos( $v, '/' ) ) ? sanitize_text_field( $v ) : esc_url_raw( $v );
		} else {
			$out[ $k ] = afk_acc_kses( $v );
		}
	}
	afk_acc_flush_cache();
	return $out;
}

function afk_acc_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$fields = array(
		'primary'          => array( 'Couleur principale', 'color' ),
		'accent'           => array( 'Couleur d’accent (« C’est gratuit », bouton)', 'color' ),
		'fonts_sitewide'   => array( 'Charger BrutGrotesque sur tout le site', 'checkbox' ),
		'hero_title'       => array( 'Hero : titre', 'text' ),
		'hero_text'        => array( 'Hero : texte', 'textarea' ),
		'hero_text_connected' => array( 'Hero : texte pour les membres connectés', 'textarea' ),
		'hero_placeholder' => array( 'Hero : texte du champ de recherche', 'text' ),
		'hero_placeholder_court' => array( 'Hero : texte du champ de recherche sur mobile', 'text' ),
		'index_title'      => array( 'Index : titre', 'text' ),
		'index_note'       => array( 'Index : mention inscription', 'text' ),
		'ref_title'        => array( 'Bandeau : phrase (le <span> passe en couleur d’accent)', 'textarea' ),
		'ref_text'         => array( 'Bandeau : texte', 'textarea' ),
		'artist_title'     => array( 'Page artiste : titre', 'text' ),
		'artist_text'      => array( 'Page artiste : texte', 'textarea' ),
		'show_abac'        => array( 'Afficher ABAC dans « À propos »', 'checkbox' ),
		'url_oeuvres'      => array( 'URL : toutes les œuvres', 'text' ),
		'url_inscription'  => array( 'URL : inscription', 'text' ),
		'url_enregistrer'  => array( 'URL : enregistrer une œuvre', 'text' ),
		'url_comment'      => array( 'URL : comment ça marche', 'text' ),
		'url_pourquoi'     => array( 'URL : pourquoi AFRICART', 'text' ),
		'url_africanhist'  => array( 'URL : African History', 'text' ),
		'url_abac'         => array( 'URL : ABAC', 'text' ),
	);
	?>
	<div class="wrap">
		<h1>Accueil AFRICART</h1>
		<p>Shortcodes : <code>[afk_hero]</code> <code>[afk_mur]</code> <code>[afk_index]</code> <code>[afk_referencer]</code> <code>[afk_page_artiste]</code> <code>[afk_apropos]</code></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'afk_acc' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $k => $f ) : $v = afk_acc_opt( $k ); $name = 'afk_acc_options[' . $k . ']'; ?>
					<tr>
						<th scope="row"><label for="afk-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $f[0] ); ?></label></th>
						<td>
							<?php if ( 'textarea' === $f[1] ) : ?>
								<textarea id="afk-<?php echo esc_attr( $k ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $v ); ?></textarea>
							<?php elseif ( 'checkbox' === $f[1] ) : ?>
								<input id="afk-<?php echo esc_attr( $k ); ?>" type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( 1, (int) $v ); ?>>
							<?php elseif ( 'color' === $f[1] ) : ?>
								<input id="afk-<?php echo esc_attr( $k ); ?>" type="color" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $v ); ?>">
							<?php else : ?>
								<input id="afk-<?php echo esc_attr( $k ); ?>" type="text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $v ); ?>" class="large-text">
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
