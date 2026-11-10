<?php
/**
 * Image de partage dédiée aux réseaux sociaux.
 *
 * Constat : partager une œuvre ne montrait l'aperçu qu'en attendant
 * plusieurs secondes, et pas du tout sur Messenger et Facebook.
 *
 * Deux causes, toutes deux liées au fichier proposé :
 *
 *   1. Son poids. Les robots des messageries s'accordent quelques
 *      secondes pour récupérer l'image ; au-delà ils renoncent et
 *      n'affichent rien. Les photos d'œuvres pèsent 400 à 600 Ko.
 *
 *   2. Ses proportions. Les réseaux attendent un format paysage
 *      d'environ 1200 × 630. Une photo presque carrée de 1790 × 1900
 *      est mal recadrée, voire écartée.
 *
 * Ce module produit donc, à côté de chaque œuvre, une image d'aperçu
 * aux bonnes proportions et nettement plus légère. L'œuvre y est
 * inscrite **en entier**, sur un fond aux couleurs de la charte :
 * pour un répertoire d'œuvres, montrer un fragment recadré
 * trahirait le travail de l'artiste.
 *
 * L'image est produite à l'avance et enregistrée parmi les
 * déclinaisons du média. Le robot la trouve déjà prête : plus rien à
 * attendre au moment du partage.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Share_Image {

	/** Nom de la déclinaison, tel qu'enregistré dans les métadonnées. */
	const SIZE = 'afk_share';

	/** Proportions attendues par les réseaux sociaux. */
	const WIDTH  = 1200;
	const HEIGHT = 630;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Déclarée pour que WordPress sache la nommer et la retrouver.
		add_image_size( self::SIZE, self::WIDTH, self::HEIGHT, true );

		// Rank Math retient la première déclinaison exploitable : on
		// place la nôtre en tête.
		add_filter( 'rank_math/opengraph/image_sizes', array( $this, 'prefer_share_size' ) );

		// Une œuvre déposée reçoit son image d'aperçu.
		add_action( 'wp_generate_attachment_metadata', array( $this, 'on_new_attachment' ), 90, 2 );
	}

	/**
	 * Place notre déclinaison en tête des choix de Rank Math.
	 *
	 * @param array $sizes Déclinaisons envisagées.
	 * @return array
	 */
	public function prefer_share_size( $sizes ) {
		if ( ! Africart_PWA_Settings::is_social_enabled() ) {
			return $sizes;
		}
		array_unshift( $sizes, self::SIZE );
		return $sizes;
	}

	/**
	 * Produit l'image d'aperçu d'un média fraîchement déposé.
	 *
	 * @param array $metadata      Métadonnées du média.
	 * @param int   $attachment_id Identifiant du média.
	 * @return array Métadonnées, enrichies de la déclinaison.
	 */
	public function on_new_attachment( $metadata, $attachment_id ) {
		if ( ! Africart_PWA_Settings::is_social_enabled() ) {
			return $metadata;
		}
		return self::generate( $attachment_id, $metadata );
	}

	/* ------------------------------------------------------------------
	 * Production
	 * ---------------------------------------------------------------- */

	/**
	 * Produit l'image d'aperçu d'un média.
	 *
	 * @param int        $attachment_id Identifiant du média.
	 * @param array|null $metadata      Métadonnées, si déjà chargées.
	 * @return array|false Métadonnées mises à jour, ou false en cas d'échec.
	 */
	public static function generate( $attachment_id, $metadata = null ) {
		$source = get_attached_file( $attachment_id );
		if ( ! $source || ! file_exists( $source ) ) {
			return false;
		}

		$ext = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return false;
		}

		$metadata = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $attachment_id );
		$metadata = is_array( $metadata ) ? $metadata : array();

		$nom    = self::filename_for( $source );
		$cible  = dirname( $source ) . '/' . $nom;
		$reussi = self::render( $source, $cible );

		if ( ! $reussi ) {
			return false;
		}

		// Enregistrée parmi les déclinaisons : WordPress et les outils de
		// référencement la retrouvent alors comme n'importe quelle autre.
		$metadata['sizes'][ self::SIZE ] = array(
			'file'      => $nom,
			'width'     => self::WIDTH,
			'height'    => self::HEIGHT,
			'mime-type' => 'image/jpeg',
			'filesize'  => filesize( $cible ),
		);

		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $metadata;
	}

	/**
	 * Nom de fichier de la déclinaison.
	 *
	 * @param string $source Chemin du fichier d'origine.
	 * @return string
	 */
	private static function filename_for( $source ) {
		$base = pathinfo( $source, PATHINFO_FILENAME );
		return sprintf( '%s-%dx%d-share.jpg', $base, self::WIDTH, self::HEIGHT );
	}

	/**
	 * Compose l'image : l'œuvre entière, centrée sur un fond uni.
	 *
	 * @param string $source Chemin de l'original.
	 * @param string $cible  Chemin de destination.
	 * @return bool Succès.
	 */
	private static function render( $source, $cible ) {
		$infos = @getimagesize( $source );
		if ( ! $infos ) {
			return false;
		}

		list( $largeur_src, $hauteur_src ) = $infos;
		if ( $largeur_src < 1 || $hauteur_src < 1 ) {
			return false;
		}

		$ext    = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		$source_img = ( 'png' === $ext ) ? @imagecreatefrompng( $source ) : @imagecreatefromjpeg( $source );
		if ( ! $source_img ) {
			return false;
		}

		$toile = imagecreatetruecolor( self::WIDTH, self::HEIGHT );

		// Fond aux couleurs de la charte, pour que les œuvres de format
		// inhabituel restent présentables.
		$fond = self::background_color();
		imagefilledrectangle(
			$toile,
			0,
			0,
			self::WIDTH,
			self::HEIGHT,
			imagecolorallocate( $toile, $fond[0], $fond[1], $fond[2] )
		);

		// L'œuvre est inscrite en entier : on prend le rapport le plus
		// contraignant plutôt que de recadrer.
		$rapport  = min( self::WIDTH / $largeur_src, self::HEIGHT / $hauteur_src );
		$largeur  = (int) round( $largeur_src * $rapport );
		$hauteur  = (int) round( $hauteur_src * $rapport );
		$decale_x = (int) round( ( self::WIDTH - $largeur ) / 2 );
		$decale_y = (int) round( ( self::HEIGHT - $hauteur ) / 2 );

		imagecopyresampled(
			$toile,
			$source_img,
			$decale_x,
			$decale_y,
			0,
			0,
			$largeur,
			$hauteur,
			$largeur_src,
			$hauteur_src
		);

		// Qualité 80 : l'aperçu s'affiche en petit, et la légèreté prime
		// puisque c'est elle qui conditionne l'affichage.
		$ok = imagejpeg( $toile, $cible, 80 );

		imagedestroy( $toile );
		imagedestroy( $source_img );

		return (bool) $ok;
	}

	/**
	 * Couleur de fond, tirée des réglages.
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	private static function background_color() {
		$hex = ltrim( (string) Africart_PWA_Settings::get( 'background_color' ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return array( 23, 28, 60 ); // Navy de la charte.
		}
		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/* ------------------------------------------------------------------
	 * Rattrapage
	 * ---------------------------------------------------------------- */

	/**
	 * Produit les images d'aperçu manquantes, par lots.
	 *
	 * @param int $size Nombre d'œuvres à traiter.
	 * @return array{done:int,skipped:int,remaining:int}
	 */
	public static function backfill( $size = 20 ) {
		$query = new WP_Query(
			array(
				'post_type'      => Africart_PWA_Social_Meta::post_type(),
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'fields'         => 'ids',
				'posts_per_page' => max( 1, (int) $size ),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_afk_share_done',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$done    = 0;
		$skipped = 0;

		foreach ( $query->posts as $post_id ) {
			$attachment_id = get_post_thumbnail_id( $post_id );

			if ( $attachment_id && self::generate( $attachment_id ) ) {
				++$done;
			} else {
				++$skipped;
			}

			// Marquée traitée dans les deux cas : une œuvre sans visuel
			// exploitable ne doit pas bloquer indéfiniment le rattrapage.
			update_post_meta( $post_id, '_afk_share_done', 1 );
		}

		return array(
			'done'      => $done,
			'skipped'   => $skipped,
			'remaining' => self::count_remaining(),
		);
	}

	/**
	 * Nombre d'œuvres n'ayant pas encore d'image d'aperçu.
	 *
	 * @return int
	 */
	public static function count_remaining() {
		$query = new WP_Query(
			array(
				'post_type'      => Africart_PWA_Social_Meta::post_type(),
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_afk_share_done',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}
}
