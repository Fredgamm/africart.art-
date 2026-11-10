<?php
/**
 * Conversion d'un fichier image vers AVIF / WebP.
 *
 * Responsabilité unique : produire (ou refuser de produire) une variante
 * d'un fichier donné. Cette classe ne connaît ni WordPress, ni la
 * médiathèque, ni le HTML — uniquement des chemins de fichiers.
 *
 * Deux règles non négociables :
 *   1. L'original n'est jamais modifié ni supprimé.
 *   2. Une variante qui n'est pas plus légère que l'original est
 *      supprimée et déclarée refusée. Le cas est fréquent sur les JPEG
 *      déjà bien compressés (mesuré : +3 % sur certaines œuvres), et une
 *      variante plus lourde n'aurait aucun intérêt à être servie.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Image_Converter {

	/** Formats gérés, par ordre de préférence (le plus efficace d'abord). */
	const FORMATS = array( 'avif', 'webp' );

	/** Extensions sources acceptées. */
	const SOURCES = array( 'jpg', 'jpeg', 'png' );

	/**
	 * Capacités du serveur, calculées une fois par requête.
	 *
	 * @var array<string,bool>|null
	 */
	private static $support = null;

	/* ------------------------------------------------------------------
	 * Capacités du serveur
	 * ---------------------------------------------------------------- */

	/**
	 * Quels formats ce serveur sait-il produire ?
	 *
	 * @return array<string,bool> ex. array( 'avif' => true, 'webp' => true )
	 */
	public static function supported_formats() {
		if ( null !== self::$support ) {
			return self::$support;
		}

		$support = array( 'avif' => false, 'webp' => false );

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$formats = array_map( 'strtoupper', ( new Imagick() )->queryFormats() );
				$support['avif'] = in_array( 'AVIF', $formats, true );
				$support['webp'] = in_array( 'WEBP', $formats, true );
			} catch ( Exception $e ) {
				// Imagick présent mais inutilisable : on retombera sur GD.
				unset( $e );
			}
		}

		if ( function_exists( 'gd_info' ) ) {
			$gd = gd_info();
			$support['avif'] = $support['avif'] || ( ! empty( $gd['AVIF Support'] ) && function_exists( 'imageavif' ) );
			$support['webp'] = $support['webp'] || ( ! empty( $gd['WebP Support'] ) && function_exists( 'imagewebp' ) );
		}

		self::$support = $support;
		return $support;
	}

	/**
	 * Le serveur sait-il produire ce format ?
	 *
	 * @param string $format 'avif' ou 'webp'.
	 * @return bool
	 */
	public static function supports( $format ) {
		$s = self::supported_formats();
		return ! empty( $s[ $format ] );
	}

	/**
	 * Le fichier est-il un candidat à la conversion ?
	 *
	 * @param string $path Chemin absolu.
	 * @return bool
	 */
	public static function is_convertible( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return false;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::SOURCES, true );
	}

	/* ------------------------------------------------------------------
	 * Conversion
	 * ---------------------------------------------------------------- */

	/**
	 * Produit la variante d'un fichier.
	 *
	 * @param string $source  Chemin absolu du fichier d'origine.
	 * @param string $format  'avif' ou 'webp'.
	 * @param int    $quality Qualité 1-100.
	 * @return array|WP_Error {
	 *     @type string $path    Chemin de la variante produite.
	 *     @type int    $bytes   Poids de la variante.
	 *     @type int    $saved   Octets économisés par rapport à l'original.
	 * }
	 *     WP_Error avec le code 'not_smaller' si la variante a été refusée
	 *     parce qu'elle n'était pas plus légère.
	 */
	public static function convert( $source, $format, $quality ) {
		if ( ! in_array( $format, self::FORMATS, true ) ) {
			return new WP_Error( 'bad_format', sprintf( 'Format non géré : %s', $format ) );
		}
		if ( ! self::supports( $format ) ) {
			return new WP_Error( 'unsupported', sprintf( 'Le serveur ne sait pas produire du %s.', $format ) );
		}
		if ( ! self::is_convertible( $source ) ) {
			return new WP_Error( 'bad_source', 'Fichier source illisible ou de type non géré.' );
		}

		$target      = Africart_PWA_Image_Variants::variant_path( $source, $format );
		$source_size = filesize( $source );
		$quality     = max( 1, min( 100, (int) $quality ) );

		// Imagick d'abord (meilleure qualité à poids égal), GD en secours.
		$done = self::convert_with_imagick( $source, $target, $format, $quality );
		if ( ! $done ) {
			$done = self::convert_with_gd( $source, $target, $format, $quality );
		}

		if ( ! $done || ! file_exists( $target ) ) {
			return new WP_Error( 'convert_failed', 'La conversion a échoué.' );
		}

		$target_size = filesize( $target );

		// Règle n°2 : pas plus légère, pas conservée.
		if ( $target_size >= $source_size ) {
			wp_delete_file( $target );
			return new WP_Error(
				'not_smaller',
				sprintf( 'Variante %s refusée : %d octets contre %d pour l\'original.', $format, $target_size, $source_size )
			);
		}

		return array(
			'path'  => $target,
			'bytes' => $target_size,
			'saved' => $source_size - $target_size,
		);
	}

	/**
	 * Conversion via Imagick.
	 *
	 * @param string $source  Chemin source.
	 * @param string $target  Chemin cible.
	 * @param string $format  Format visé.
	 * @param int    $quality Qualité.
	 * @return bool Succès.
	 */
	private static function convert_with_imagick( $source, $target, $format, $quality ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$im = new Imagick( $source );
			$im->setImageFormat( $format );
			$im->setImageCompressionQuality( $quality );
			// Les photos d'œuvres n'ont pas besoin de leurs métadonnées EXIF
			// pour l'affichage : les retirer allège encore un peu.
			$im->stripImage();
			$ok = $im->writeImage( $target );
			$im->clear();
			$im->destroy();
			return (bool) $ok;
		} catch ( Exception $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Conversion via GD.
	 *
	 * @param string $source  Chemin source.
	 * @param string $target  Chemin cible.
	 * @param string $format  Format visé.
	 * @param int    $quality Qualité.
	 * @return bool Succès.
	 */
	private static function convert_with_gd( $source, $target, $format, $quality ) {
		$ext = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );

		switch ( $ext ) {
			case 'png':
				$image = @imagecreatefrompng( $source );
				break;
			case 'jpg':
			case 'jpeg':
			default:
				$image = @imagecreatefromjpeg( $source );
				break;
		}

		if ( ! $image ) {
			return false;
		}

		// La transparence des PNG doit survivre à la conversion.
		if ( 'png' === $ext ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
		}

		$ok = false;
		if ( 'avif' === $format && function_exists( 'imageavif' ) ) {
			$ok = @imageavif( $image, $target, $quality );
		} elseif ( 'webp' === $format && function_exists( 'imagewebp' ) ) {
			$ok = @imagewebp( $image, $target, $quality );
		}

		imagedestroy( $image );
		return (bool) $ok;
	}
}
