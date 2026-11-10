<?php
/**
 * Shortcodes des sections de la page d'accueil.
 * Chaque section est autonome : on peut les réordonner ou en retirer une dans Elementor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Enveloppe commune : classe racine + variables de couleur. */
function afk_acc_wrap( $slug, $inner, $extra_class = '' ) {
	afk_acc_enqueue();
	return '<div class="afk-acc afk-acc-' . esc_attr( $slug ) . ( $extra_class ? ' ' . esc_attr( $extra_class ) : '' ) . '">' . $inner . '</div>';
}

/** Fond + contour + queue de la bulle, à placer dans un élément .afk-acc-bulle. */
function afk_acc_tail() {
	return '<span class="afk-acc-bulle-bg" aria-hidden="true"></span>'
		. '<span class="afk-acc-bulle-frame" aria-hidden="true"><i class="t"></i><i class="l"></i><i class="r"></i><i class="bl"></i><i class="br"></i>'
		. '<i class="q"><svg viewBox="0 0 74 44" focusable="false"><path d="M0 2.5H10L68 40" fill="none" stroke="currentColor" stroke-width="5" stroke-linejoin="miter" stroke-linecap="butt"/></svg></i></span>';
}

function afk_acc_arrow_svg( $size = 24, $stroke = 'currentColor' ) {
	return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $stroke ) . '" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14"/><path d="M13 6l6 6-6 6"/></svg>';
}

/* ---------------------------------------------------------------------------
 * [afk_hero] : grand titre + recherche en bulle + suggestions
 * ------------------------------------------------------------------------- */
add_shortcode( 'afk_hero', function () {
	$uid = 'afk-acc-q-' . wp_rand( 100, 99999 );
	ob_start();
	?>
	<section class="afk-acc-section afk-acc-hero-in">
		<h1 class="afk-acc-h1"><?php echo afk_acc_kses( afk_acc_opt( 'hero_title' ) ); ?></h1>
		<div class="afk-acc-hero-row">
			<div class="afk-acc-search" data-afk-search>
				<form class="afk-acc-bulle" role="search" method="get" action="<?php echo esc_url( afk_acc_url( 'url_oeuvres' ) ); ?>">
					<input type="hidden" name="type" value="<?php echo esc_attr( AFK_ACC_POST_TYPE ); ?>">
					<label class="afk-acc-sr" for="<?php echo esc_attr( $uid ); ?>">Rechercher une œuvre ou un artiste</label>
					<input id="<?php echo esc_attr( $uid ); ?>" class="afk-acc-search-input" type="search" name="keywords" autocomplete="off"
						placeholder="<?php echo esc_attr( wp_strip_all_tags( afk_acc_opt( 'hero_placeholder' ) ) ); ?>"
						data-placeholder-court="<?php echo esc_attr( wp_strip_all_tags( afk_acc_opt( 'hero_placeholder_court' ) ) ); ?>"
						role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-list">
					<button type="submit" class="afk-acc-search-btn" aria-label="Rechercher" style="color:#fff"><?php echo afk_acc_arrow_svg( 30, 'currentColor' ); // phpcs:ignore ?></button>
					<?php echo afk_acc_tail(); // phpcs:ignore ?>
				</form>
				<div class="afk-acc-sugg" id="<?php echo esc_attr( $uid ); ?>-list" role="listbox" aria-label="Suggestions" hidden></div>
			</div>
			<p class="afk-acc-hero-text"><?php echo afk_acc_kses( afk_acc_opt( is_user_logged_in() ? 'hero_text_connected' : 'hero_text' ) ); ?></p>
		</div>
	</section>
	<?php
	return afk_acc_wrap( 'hero', ob_get_clean() );
} );

/* ---------------------------------------------------------------------------
 * [afk_mur nombre="6"] : mur d'œuvres bord à bord, largeurs selon le format réel
 * ------------------------------------------------------------------------- */
add_shortcode( 'afk_mur', function ( $atts ) {
	$a     = shortcode_atts( array( 'nombre' => 6 ), $atts, 'afk_mur' );
	$n     = max( 3, min( 10, (int) $a['nombre'] ) );
	$items = afk_acc_pick_diverse( afk_acc_get_oeuvres( $n, 60 ), $n );
	if ( ! $items ) {
		return '';
	}
	ob_start();
	?>
	<section class="afk-acc-wall" aria-label="Une sélection d’œuvres">
		<?php foreach ( $items as $o ) : ?>
			<a class="afk-acc-wall-item" href="<?php echo esc_url( $o['url'] ); ?>" style="flex-grow:<?php echo esc_attr( $o['ratio'] ); ?>">
				<?php
				echo wp_get_attachment_image(
					$o['img'],
					'large',
					false,
					array(
						'class'   => 'afk-acc-wall-img',
						'alt'     => trim( $o['title'] . ( $o['artist'] ? ', ' . $o['artist'] : '' ) ),
						'loading' => 'eager',
						'sizes'   => '(max-width: 767px) 70vw, 25vw',
					)
				);
				?>
				<span class="afk-acc-wall-cap" aria-hidden="true">
					<span class="afk-acc-wall-title"><?php echo esc_html( $o['title'] ); ?></span>
					<span class="afk-acc-wall-artist"><?php echo esc_html( $o['artist'] ); ?></span>
				</span>
			</a>
		<?php endforeach; ?>
	</section>
	<?php
	return afk_acc_wrap( 'mur', ob_get_clean() );
} );

/* ---------------------------------------------------------------------------
 * [afk_index nombre="8"] : index numéroté, l'image change au survol / focus
 * ------------------------------------------------------------------------- */
add_shortcode( 'afk_index', function ( $atts ) {
	$a     = shortcode_atts( array( 'nombre' => 8 ), $atts, 'afk_index' );
	$items = afk_acc_get_oeuvres( (int) $a['nombre'], (int) $a['nombre'] );
	$items = array_slice( $items, 0, max( 1, (int) $a['nombre'] ) );
	if ( ! $items ) {
		return '';
	}
	$first = $items[0];
	ob_start();
	?>
	<section class="afk-acc-section afk-acc-index-in" data-afk-index>
		<div class="afk-acc-index-list">
			<div class="afk-acc-index-head">
				<h2 class="afk-acc-h2"><?php echo afk_acc_kses( afk_acc_opt( 'index_title' ) ); ?></h2>
				<a class="afk-acc-link" href="<?php echo esc_url( afk_acc_url( 'url_oeuvres' ) ); ?>">Toutes les œuvres →</a>
			</div>
			<ol class="afk-acc-rows">
				<?php
				foreach ( $items as $i => $o ) :
					$src    = wp_get_attachment_image_src( $o['img'], 'large' );
					$srcset = wp_get_attachment_image_srcset( $o['img'], 'large' );
					$thumb  = wp_get_attachment_image_src( $o['img'], 'thumbnail' );
					?>
					<li>
						<a class="afk-acc-row<?php echo 0 === $i ? ' is-active' : ''; ?>" href="<?php echo esc_url( $o['url'] ); ?>"
							data-src="<?php echo esc_url( $src ? $src[0] : '' ); ?>"
							data-srcset="<?php echo esc_attr( $srcset ? $srcset : '' ); ?>"
							data-title="<?php echo esc_attr( $o['title'] ); ?>"
							data-artist="<?php echo esc_attr( $o['artist'] ); ?>">
							<span class="afk-acc-row-num"><?php echo esc_html( str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
							<?php if ( $thumb ) : ?>
								<img class="afk-acc-row-thumb" src="<?php echo esc_url( $thumb[0] ); ?>" alt="" width="56" height="56" loading="lazy">
							<?php endif; ?>
							<span class="afk-acc-row-title"><?php echo esc_html( $o['title'] ); ?></span>
							<span class="afk-acc-row-artist"><?php echo esc_html( $o['artist'] ); ?></span>
							<span class="afk-acc-row-arrow" aria-hidden="true">→</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( ! is_user_logged_in() ) : // Inutile pour les inscrits. ?>
				<p class="afk-acc-index-note"><?php echo afk_acc_kses( afk_acc_opt( 'index_note' ) ); ?> <a class="afk-acc-link" href="<?php echo esc_url( afk_acc_url( 'url_inscription' ) ); ?>">Créer mon compte gratuit →</a></p>
			<?php endif; ?>
		</div>
		<figure class="afk-acc-index-preview" aria-hidden="true">
			<?php
			echo wp_get_attachment_image(
				$first['img'],
				'large',
				false,
				array(
					'class'   => 'afk-acc-preview-img',
					'alt'     => '',
					'loading' => 'lazy',
					'sizes'   => '(max-width: 1023px) 0px, 40vw',
				)
			);
			?>
			<figcaption><span class="afk-acc-preview-title"><?php echo esc_html( $first['title'] ); ?></span><span class="afk-acc-preview-artist"><?php echo esc_html( $first['artist'] ); ?></span></figcaption>
		</figure>
	</section>
	<?php
	return afk_acc_wrap( 'index', ob_get_clean() );
} );

/* ---------------------------------------------------------------------------
 * [afk_referencer] : bandeau bleu + 3 étapes
 * ------------------------------------------------------------------------- */
add_shortcode( 'afk_referencer', function () {
	ob_start();
	?>
	<section class="afk-acc-section afk-acc-ref-in">
		<p class="afk-acc-ref-title"><?php echo afk_acc_kses( afk_acc_opt( 'ref_title' ) ); ?></p>
		<ol class="afk-acc-steps">
			<li>Créez votre compte</li>
			<li aria-hidden="true" class="afk-acc-steps-line"></li>
			<li>Enregistrez vos œuvres</li>
			<li aria-hidden="true" class="afk-acc-steps-line"></li>
			<li>Validation sous une semaine</li>
		</ol>
		<div class="afk-acc-ref-actions">
			<a class="afk-acc-btn-accent" href="<?php echo esc_url( afk_acc_url( 'url_enregistrer' ) ); ?>">Enregistrer une œuvre</a>
			<a class="afk-acc-link-light" href="<?php echo esc_url( afk_acc_url( 'url_comment' ) ); ?>">Comment ça marche →</a>
		</div>
		<p class="afk-acc-ref-text"><?php echo afk_acc_kses( afk_acc_opt( 'ref_text' ) ); ?></p>
	</section>
	<?php
	return afk_acc_wrap( 'referencer', ob_get_clean() );
} );

/* ---------------------------------------------------------------------------
 * [afk_page_artiste] : l'adresse africart.art/nom-d-artiste dans une bulle
 * ------------------------------------------------------------------------- */
add_shortcode( 'afk_page_artiste', function () {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	ob_start();
	?>
	<section class="afk-acc-section afk-acc-artiste-in">
		<div class="afk-acc-artiste-text">
			<h2 class="afk-acc-h2 afk-acc-h2-sm"><?php echo afk_acc_kses( afk_acc_opt( 'artist_title' ) ); ?></h2>
			<p><?php echo afk_acc_kses( afk_acc_opt( 'artist_text' ) ); ?></p>
		</div>
		<div class="afk-acc-bulle afk-acc-bulle-url" aria-label="Adresse type d’une page artiste"><?php echo esc_html( $host ); ?>/<span class="afk-acc-url-slug">nom-d-artiste</span><?php echo afk_acc_tail(); // phpcs:ignore ?></div>
	</section>
	<?php
	return afk_acc_wrap( 'artiste', ob_get_clean() );
} );

/* ---------------------------------------------------------------------------
 * [afk_apropos] : SABATI, African History, ABAC (option)
 * ------------------------------------------------------------------------- */
add_shortcode( 'afk_apropos', function () {
	$cols = array(
		array( 'Pourquoi AFRICART', 'Porté par SABATI, structure associative sans but lucratif, pour donner de la visibilité à l’art contemporain d’Afrique et lutter contre les copies.', afk_acc_url( 'url_pourquoi' ), 'En savoir plus →' ),
		array( 'African History', 'Plateforme partenaire : photothèque et librairie en ligne, des archives rares sur l’Afrique et son histoire.', afk_acc_url( 'url_africanhist' ), 'Découvrir →' ),
	);
	if ( (int) afk_acc_opt( 'show_abac' ) ) {
		$cols[] = array( 'ABAC', 'Pour les artistes qui souhaitent mettre en vente leurs œuvres référencées.', afk_acc_url( 'url_abac' ), 'En savoir plus →' );
	}
	ob_start();
	?>
	<section class="afk-acc-section afk-acc-apropos-in">
		<?php foreach ( $cols as $c ) : ?>
			<div class="afk-acc-col">
				<h3><?php echo esc_html( $c[0] ); ?></h3>
				<p><?php echo esc_html( $c[1] ); ?></p>
				<a class="afk-acc-link" href="<?php echo esc_url( $c[2] ); ?>"><?php echo esc_html( $c[3] ); ?></a>
			</div>
		<?php endforeach; ?>
	</section>
	<?php
	return afk_acc_wrap( 'apropos', ob_get_clean() );
} );
