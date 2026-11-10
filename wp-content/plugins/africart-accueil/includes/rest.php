<?php
/**
 * Suggestions de recherche : GET /wp-json/afk-acc/v1/suggest?q=dol
 * Public, lecture seule. Ne renvoie que des infos déjà visibles publiquement
 * (titre, artiste, vignette, lien) : les fiches complètes restent réservées aux inscrits.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route(
		'afk-acc/v1',
		'/suggest',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => array(
				'q' => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
			'callback'            => 'afk_acc_rest_suggest',
		)
	);
} );

function afk_acc_rest_suggest( WP_REST_Request $req ) {
	$q = trim( wp_unslash( (string) $req->get_param( 'q' ) ) );
	$q = function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 60 ) : substr( $q, 0, 60 );

	$len = function_exists( 'mb_strlen' ) ? mb_strlen( $q ) : strlen( $q );
	if ( $len < 3 ) {
		return afk_acc_rest_response( array( 'countries' => array(), 'artists' => array(), 'works' => array() ) );
	}

	$ckey = 'afk_acc_s_' . md5( strtolower( $q ) );
	$hit  = get_transient( $ckey );
	if ( false !== $hit ) {
		return afk_acc_rest_response( $hit );
	}

	global $wpdb;
	$like   = '%' . $wpdb->esc_like( $q ) . '%';
	$artist = afk_acc_meta_artist();

	// Collation MySQL *_ci : insensible à la casse et, en utf8mb4_unicode_ci, aux accents.
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.ID, m.meta_value AS artist
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = %s )
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			   AND ( p.post_title LIKE %s OR m.meta_value LIKE %s )
			 ORDER BY ( p.post_title LIKE %s ) DESC, p.post_date DESC
			 LIMIT 40",
			$artist,
			AFK_ACC_POST_TYPE,
			$like,
			$like,
			$like
		)
	);

	$artists = array();
	$works   = array();
	foreach ( (array) $rows as $r ) {
		$name = trim( (string) $r->artist );
		if ( '' !== $name && false !== stripos( remove_accents( $name ), remove_accents( $q ) ) ) {
			$k = strtolower( remove_accents( $name ) );
			if ( ! isset( $artists[ $k ] ) ) {
				$artists[ $k ] = array( 'name' => $name );
			}
		}
		if ( count( $works ) < 3 ) {
			$o = afk_acc_format_oeuvre( (int) $r->ID );
			$t = $o['img'] ? wp_get_attachment_image_src( $o['img'], 'thumbnail' ) : false;
			$works[] = array(
				'title'  => $o['title'],
				'artist' => $o['artist'],
				'url'    => $o['url'],
				'thumb'  => $t ? $t[0] : '',
			);
		}
	}

	// Nombre d'œuvres par artiste (2 artistes max affichés).
	$artists = array_slice( array_values( $artists ), 0, 2 );
	foreach ( $artists as &$a ) {
		$a['count'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = %s )
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND m.meta_value = %s",
				$artist,
				AFK_ACC_POST_TYPE,
				$a['name']
			)
		);
		$a['url'] = add_query_arg(
			array(
				'type'     => AFK_ACC_POST_TYPE,
				'keywords' => rawurlencode( $a['name'] ),
			),
			afk_acc_url( 'url_oeuvres' )
		);
	}
	unset( $a );

	// Pays (champ « Pays d'origine de l'artiste ») : le mot doit commencer comme la saisie,
	// pour que « mali » propose le Mali sans proposer la Somalie.
	$country_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT MIN(TRIM(m.meta_value)) AS name, COUNT(*) AS n
			 FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON ( p.ID = m.post_id )
			 WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'
			   AND ( TRIM(m.meta_value) LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s )
			 GROUP BY LOWER(TRIM(m.meta_value))
			 ORDER BY n DESC
			 LIMIT 2",
			afk_acc_meta_country(),
			AFK_ACC_POST_TYPE,
			$wpdb->esc_like( $q ) . '%',
			'% ' . $wpdb->esc_like( $q ) . '%',
			'%-' . $wpdb->esc_like( $q ) . '%'
		)
	);
	$countries = array();
	foreach ( (array) $country_rows as $c ) {
		$name = trim( (string) $c->name );
		if ( '' === $name ) {
			continue;
		}
		// Majuscule initiale si la saisie était tout en minuscules (« mali » → « Mali »).
		if ( function_exists( 'mb_strtoupper' ) && $name === mb_strtolower( $name ) ) {
			$name = mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
		}
		$countries[] = array(
			'name'  => $name,
			'count' => (int) $c->n,
			'url'   => add_query_arg(
				array(
					'type'                        => AFK_ACC_POST_TYPE,
					afk_acc_country_filter_key() => rawurlencode( $name ),
				),
				afk_acc_url( 'url_oeuvres' )
			),
		);
	}

	$data = array(
		'countries' => $countries,
		'artists'   => $artists,
		'works'     => $works,
	);
	set_transient( $ckey, $data, 10 * MINUTE_IN_SECONDS );
	return afk_acc_rest_response( $data );
}

/** Paramètre d'URL du filtre Voxel « Par pays d'origine ou année de création » sur /oeuvres/. */
function afk_acc_country_filter_key() {
	return apply_filters( 'afk_acc_country_filter_key', 'keywords-2' );
}

function afk_acc_rest_response( $data ) {
	$res = new WP_REST_Response( $data, 200 );
	$res->header( 'Cache-Control', 'public, max-age=300' );
	return $res;
}
