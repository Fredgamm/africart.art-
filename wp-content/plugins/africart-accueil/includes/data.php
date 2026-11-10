<?php
/**
 * Lecture des œuvres Voxel (lecture seule, aucune écriture en base hors cache transitoire).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function afk_acc_meta_artist() {
	return apply_filters( 'afk_acc_meta_artist', 'text' );
}
function afk_acc_meta_country() {
	return apply_filters( 'afk_acc_meta_country', 'text-5' ); // « Pays d'origine de l'artiste »
}
function afk_acc_meta_cover() {
	return apply_filters( 'afk_acc_meta_cover', 'cover' );
}

/** Premier ID d'image d'une œuvre : photo principale, puis image à la une, puis galerie. */
function afk_acc_image_id( $post_id ) {
	foreach ( array( afk_acc_meta_cover(), '_thumbnail_id', 'gallery' ) as $key ) {
		$raw = get_post_meta( $post_id, $key, true );
		if ( is_array( $raw ) ) {
			$raw = implode( ',', $raw );
		}
		foreach ( explode( ',', (string) $raw ) as $id ) {
			$id = absint( trim( $id ) );
			if ( $id && wp_attachment_is_image( $id ) ) {
				return $id;
			}
		}
	}
	return 0;
}

/** Normalise une œuvre pour l'affichage. */
function afk_acc_format_oeuvre( $post_id ) {
	$img_id = afk_acc_image_id( $post_id );
	$ratio  = 0.8;
	if ( $img_id ) {
		$meta = wp_get_attachment_metadata( $img_id );
		if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			$ratio = max( 0.4, min( 2.2, $meta['width'] / $meta['height'] ) );
		}
	}
	return array(
		'id'     => (int) $post_id,
		'title'  => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
		'artist'  => trim( (string) get_post_meta( $post_id, afk_acc_meta_artist(), true ) ),
		'country' => trim( (string) get_post_meta( $post_id, afk_acc_meta_country(), true ) ),
		'url'    => get_permalink( $post_id ),
		'img'    => $img_id,
		'ratio'  => round( $ratio, 3 ),
	);
}

/**
 * Dernières œuvres publiées ayant une image.
 * $pool > $n permet de piocher au hasard parmi les plus récentes (mur d'œuvres).
 */
function afk_acc_get_oeuvres( $n = 8, $pool = 0 ) {
	$n     = max( 1, min( 24, (int) $n ) );
	$pool  = max( $n, min( 60, (int) $pool ) );
	$key   = 'afk_acc_oeuvres_' . $pool;
	$items = get_transient( $key );

	if ( false === $items ) {
		$q = new WP_Query(
			array(
				'post_type'              => AFK_ACC_POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => $pool * 2,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		$items = array();
		foreach ( $q->posts as $pid ) {
			$o = afk_acc_format_oeuvre( $pid );
			if ( $o['img'] ) {
				$items[] = $o;
			}
			if ( count( $items ) >= $pool ) {
				break;
			}
		}
		set_transient( $key, $items, 15 * MINUTE_IN_SECONDS );
	}
	return $items;
}

/** Clé de comparaison : minuscules, sans accents ni espaces superflus. */
function afk_acc_norm( $s ) {
	return strtolower( trim( preg_replace( '/\s+/', ' ', remove_accents( (string) $s ) ) ) );
}

/**
 * Sélection variée pour le mur : un pays et un artiste différents par œuvre si possible,
 * puis on relâche la contrainte (artistes différents), puis on complète.
 */
function afk_acc_pick_diverse( $items, $n ) {
	if ( ! $items ) {
		return array();
	}
	shuffle( $items );
	$picked = array();
	$used   = array();
	$passes = array(
		function ( $o, $c, $a ) { return '' !== $o['_c'] && empty( $c[ 'c:' . $o['_c'] ] ) && empty( $a[ 'a:' . $o['_a'] ] ); },
		function ( $o, $c, $a ) { return empty( $a[ 'a:' . $o['_a'] ] ); },
		function () { return true; },
	);
	foreach ( $items as &$o ) {
		$o['_c'] = afk_acc_norm( $o['country'] );
		$o['_a'] = afk_acc_norm( $o['artist'] );
	}
	unset( $o );
	$countries = array();
	$artists   = array();
	foreach ( $passes as $ok ) {
		foreach ( $items as $o ) {
			if ( count( $picked ) >= $n ) {
				break 2;
			}
			if ( isset( $used[ $o['id'] ] ) || ! $ok( $o, $countries, $artists ) ) {
				continue;
			}
			$picked[]               = $o;
			$used[ $o['id'] ]       = true;
			$countries[ 'c:' . $o['_c'] ] = true;
			$artists[ 'a:' . $o['_a'] ]   = true;
		}
	}
	return $picked;
}

/** Vide le cache dès qu'une œuvre bouge. */
function afk_acc_flush_cache() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_afk\_acc\_%' OR option_name LIKE '\_transient\_timeout\_afk\_acc\_%'" ); // phpcs:ignore
}
add_action( 'save_post_' . AFK_ACC_POST_TYPE, 'afk_acc_flush_cache' );
// Nouvelle version de l'extension : on repart d'un cache vide (suggestions, sélections).
add_action( 'init', function () {
	if ( get_option( 'afk_acc_version' ) !== AFK_ACC_VERSION ) {
		afk_acc_flush_cache();
		update_option( 'afk_acc_version', AFK_ACC_VERSION, false );
	}
} );
add_action( 'trashed_post', 'afk_acc_flush_cache' );
add_action( 'deleted_post', 'afk_acc_flush_cache' );
add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( $post && AFK_ACC_POST_TYPE === $post->post_type && $new !== $old ) {
		afk_acc_flush_cache();
	}
}, 10, 3 );
