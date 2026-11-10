<?php
/**
 * Partage sur les réseaux sociaux et données structurées.
 *
 * Constat de départ : le site partageait un visuel générique pour
 * TOUTES les œuvres. Un lien envoyé sur WhatsApp, Facebook ou Instagram
 * affichait la même bannière, jamais l'œuvre — pour un répertoire
 * d'œuvres, c'est l'inverse de l'effet recherché.
 *
 * La cause : les œuvres n'ont pas d'image mise en avant WordPress. Leur
 * visuel est stocké dans un champ propre au thème (`cover`), que les
 * outils de référencement ne connaissent pas.
 *
 * Plutôt que d'émettre des balises concurrentes de celles de Rank Math,
 * ce module renseigne l'image mise en avant à partir de ce champ. Rank
 * Math la reprend alors nativement — de même que les flux, les
 * extensions de partage et tout outil futur. Une correction en amont
 * plutôt qu'une rustine en aval.
 *
 * Il ajoute par ailleurs une description structurée de chaque œuvre
 * (type VisualArtwork : auteur, technique, dimensions, année, lieu),
 * que les moteurs comprennent et que les balises de partage ne
 * transportent pas.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Social_Meta {

	/** Réglage portant la correspondance des champs. */
	const MAP_KEY = 'seo_field_map';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// À l'enregistrement d'une œuvre, l'image de partage suit.
		add_action( 'save_post', array( $this, 'sync_featured_image' ), 20, 2 );
		// Description structurée de l'œuvre.
		//
		// Elle est publiée dans son propre bloc plutôt que greffée à
		// celle de l'outil de référencement : ce dernier a les extraits
		// enrichis désactivés pour les œuvres, et son réglage pourrait
		// changer sans préavis. Plusieurs blocs de données structurées
		// sur une page sont parfaitement admis.
		add_action( 'wp_head', array( $this, 'print_artwork_schema' ), 20 );
	}

	/* ------------------------------------------------------------------
	 * Réglages
	 * ---------------------------------------------------------------- */

	/**
	 * Type de contenu des œuvres.
	 *
	 * @return string
	 */
	public static function post_type() {
		$value = trim( (string) Africart_PWA_Settings::get( 'seo_post_type' ) );
		return $value ? $value : 'oeuvre';
	}

	/**
	 * Correspondance entre les informations d'une œuvre et les champs
	 * qui les contiennent. Les valeurs par défaut correspondent au
	 * paramétrage actuel du site ; elles sont modifiables si la
	 * configuration du thème évolue.
	 *
	 * @return array<string,string>
	 */
	public static function field_map() {
		$defaults = array(
			'cover'      => 'cover',
			'artist'     => 'text',
			'year'       => 'text-4',
			'medium'     => 'text-2',
			'dimensions' => 'text-3',
			'city'       => 'text-6',
			'country'    => 'text-5',
		);

		$saved = Africart_PWA_Settings::get( self::MAP_KEY );
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		foreach ( $defaults as $key => $default ) {
			if ( ! empty( $saved[ $key ] ) ) {
				$defaults[ $key ] = (string) $saved[ $key ];
			}
		}

		return $defaults;
	}

	/**
	 * Libellés des informations, pour l'écran de réglages.
	 *
	 * @return array<string,string>
	 */
	public static function field_labels() {
		return array(
			'cover'      => __( 'Visuel de l’œuvre', 'africart-pwa' ),
			'artist'     => __( 'Artiste', 'africart-pwa' ),
			'year'       => __( 'Année', 'africart-pwa' ),
			'medium'     => __( 'Technique', 'africart-pwa' ),
			'dimensions' => __( 'Dimensions', 'africart-pwa' ),
			'city'       => __( 'Ville', 'africart-pwa' ),
			'country'    => __( 'Pays', 'africart-pwa' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Image de partage
	 * ---------------------------------------------------------------- */

	/**
	 * Renseigne l'image mise en avant depuis le champ du thème.
	 *
	 * Une image déjà définie n'est jamais remplacée : si quelqu'un en a
	 * choisi une volontairement, elle prime.
	 *
	 * @param int     $post_id Identifiant du contenu.
	 * @param WP_Post $post    Contenu enregistré.
	 * @return bool Vrai si une image a été affectée.
	 */
	public function sync_featured_image( $post_id, $post = null ) {
		if ( ! Africart_PWA_Settings::is_social_enabled() ) {
			return false;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || self::post_type() !== $post->post_type ) {
			return false;
		}

		return self::apply_featured_image( $post_id );
	}

	/**
	 * Applique l'image de partage à une œuvre.
	 *
	 * @param int $post_id Identifiant de l'œuvre.
	 * @return bool Vrai si une image a été affectée.
	 */
	public static function apply_featured_image( $post_id ) {
		if ( get_post_thumbnail_id( $post_id ) ) {
			return false; // Déjà pourvue.
		}

		$map   = self::field_map();
		$cover = get_post_meta( $post_id, $map['cover'], true );

		// Le champ peut contenir un identifiant seul ou une liste.
		if ( is_string( $cover ) && false !== strpos( $cover, ',' ) ) {
			$parts = array_filter( array_map( 'trim', explode( ',', $cover ) ) );
			$cover = reset( $parts );
		}

		if ( ! $cover || ! is_numeric( $cover ) ) {
			return false;
		}

		$attachment_id = (int) $cover;
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		return true;
	}

	/* ------------------------------------------------------------------
	 * Description structurée
	 * ---------------------------------------------------------------- */

	/**
	 * Publie la description structurée de l'œuvre affichée.
	 */
	public function print_artwork_schema() {
		$artwork = $this->build_artwork_schema();
		if ( ! $artwork ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $artwork, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Construit la description structurée de l'œuvre affichée.
	 *
	 * @return array|null Description, ou null si la page n'est pas une œuvre.
	 */
	private function build_artwork_schema() {
		if ( ! Africart_PWA_Settings::is_social_enabled() || ! is_singular( self::post_type() ) ) {
			return null;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return null;
		}

		$map    = self::field_map();
		$get    = function ( $key ) use ( $post_id, $map ) {
			return isset( $map[ $key ] ) ? trim( (string) get_post_meta( $post_id, $map[ $key ], true ) ) : '';
		};

		$artwork = array(
			'@context' => 'https://schema.org',
			'@type'    => 'VisualArtwork',
			'@id'      => get_permalink( $post_id ) . '#artwork',
			'name'     => get_the_title( $post_id ),
			'url'      => get_permalink( $post_id ),
		);

		$image = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $image ) {
			$artwork['image'] = $image;
		}

		$artist = $get( 'artist' );
		if ( $artist ) {
			$artwork['creator'] = array(
				'@type' => 'Person',
				'name'  => $artist,
			);
		}

		$year = $get( 'year' );
		if ( $year ) {
			$artwork['dateCreated'] = $year;
		}

		$medium = $get( 'medium' );
		if ( $medium ) {
			$artwork['artMedium'] = $medium;
		}

		$dimensions = $get( 'dimensions' );
		if ( $dimensions ) {
			// Conservées telles que saisies : les formats sont trop
			// variables (« 1,20/1,10 », « 50 x 60 cm »…) pour être
			// interprétés sans risque d'erreur.
			$artwork['size'] = $dimensions;
		}

		$lieu = array_filter( array( $get( 'city' ), $get( 'country' ) ) );
		if ( $lieu ) {
			$artwork['locationCreated'] = array(
				'@type' => 'Place',
				'name'  => implode( ', ', $lieu ),
			);
		}

		return $artwork;
	}

	/* ------------------------------------------------------------------
	 * Rattrapage des œuvres existantes
	 * ---------------------------------------------------------------- */

	/**
	 * Nombre d'œuvres dépourvues d'image de partage.
	 *
	 * @return array{total:int,missing:int}
	 */
	public static function count_missing() {
		$total = new WP_Query(
			array(
				'post_type'      => self::post_type(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);

		$missing = new WP_Query(
			array(
				'post_type'      => self::post_type(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array(
			'total'   => (int) $total->found_posts,
			'missing' => (int) $missing->found_posts,
		);
	}

	/**
	 * Affecte l'image de partage à un lot d'œuvres qui en sont dépourvues.
	 *
	 * @param int $size Nombre d'œuvres à traiter.
	 * @return array{done:int,skipped:int,remaining:int}
	 */
	public static function backfill( $size = 50 ) {
		$query = new WP_Query(
			array(
				'post_type'      => self::post_type(),
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'fields'         => 'ids',
				'posts_per_page' => max( 1, (int) $size ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$done    = 0;
		$skipped = 0;

		foreach ( $query->posts as $post_id ) {
			if ( self::apply_featured_image( $post_id ) ) {
				++$done;
			} else {
				// Aucune image exploitable : on marque pour ne pas
				// retraiter indéfiniment cette œuvre.
				update_post_meta( $post_id, '_afk_no_cover', 1 );
				++$skipped;
			}
		}

		$counts = self::count_missing();

		return array(
			'done'      => $done,
			'skipped'   => $skipped,
			'remaining' => $counts['missing'],
		);
	}

	/**
	 * Nettoie la correspondance des champs soumise depuis l'admin.
	 *
	 * @param mixed $input Valeur brute.
	 * @return array
	 */
	public static function sanitize_map( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$clean = array();
		foreach ( self::field_labels() as $key => $label ) {
			if ( ! empty( $input[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		return $clean;
	}
}
