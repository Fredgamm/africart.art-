<?php
/**
 * Registre des variantes d'images.
 *
 * Responsabilité unique : savoir où se trouve la variante d'un fichier
 * et si elle existe réellement. Ne convertit rien, ne réécrit rien.
 *
 * Convention de nommage : l'extension du format est ajoutée APRÈS
 * l'extension d'origine.
 *
 *     oeuvre.jpg  ->  oeuvre.jpg.avif  /  oeuvre.jpg.webp
 *
 * Ce choix évite la collision entre « photo.jpg » et « photo.png », qui
 * produiraient sinon le même « photo.avif », et permet de remonter à
 * l'original par simple suppression du suffixe.
 *
 * ⚠️ Point critique vérifié en test : un navigateur qui choisit une
 * variante absente n'affiche RIEN — il ne se rabat pas sur l'entrée
 * suivante. Toute déclaration doit donc s'appuyer sur `existing()`,
 * jamais sur une hypothèse.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Image_Variants {

	/**
	 * Cache par requête : chemin -> variantes existantes.
	 * La réécriture HTML interroge les mêmes fichiers en boucle.
	 *
	 * @var array<string,array>
	 */
	private static $cache = array();

	/**
	 * Racine des téléversements, mise en cache.
	 *
	 * @var array|null
	 */
	private static $uploads = null;

	/* ------------------------------------------------------------------
	 * Chemins et URLs
	 * ---------------------------------------------------------------- */

	/**
	 * Chemin de la variante d'un fichier.
	 *
	 * @param string $source_path Chemin absolu de l'original.
	 * @param string $format      'avif' ou 'webp'.
	 * @return string
	 */
	public static function variant_path( $source_path, $format ) {
		return $source_path . '.' . $format;
	}

	/**
	 * Informations sur le dossier de téléversement.
	 *
	 * @return array
	 */
	private static function uploads() {
		if ( null === self::$uploads ) {
			self::$uploads = wp_get_upload_dir();
		}
		return self::$uploads;
	}

	/**
	 * Convertit une URL de média en chemin absolu, si elle appartient
	 * bien à ce site. Retourne une chaîne vide sinon (URL externe,
	 * données en ligne, autre domaine…).
	 *
	 * @param string $url URL à traduire.
	 * @return string Chemin absolu, ou '' si hors périmètre.
	 */
	public static function url_to_path( $url ) {
		$uploads = self::uploads();
		$baseurl = $uploads['baseurl'];
		$basedir = $uploads['basedir'];

		// On compare sans le protocole : le site peut être servi en http
		// comme en https, et les URLs stockées mélangent parfois les deux.
		$url_no_scheme  = preg_replace( '#^https?:#', '', $url );
		$base_no_scheme = preg_replace( '#^https?:#', '', $baseurl );

		if ( 0 !== strpos( $url_no_scheme, $base_no_scheme ) ) {
			return '';
		}

		$relative = ltrim( substr( $url_no_scheme, strlen( $base_no_scheme ) ), '/' );

		// Une URL peut porter une chaîne de requête ou une ancre.
		$relative = strtok( $relative, '?#' );

		// Défense : aucun retour en arrière dans l'arborescence.
		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}

		return $basedir . '/' . $relative;
	}

	/**
	 * Convertit un chemin absolu en URL.
	 *
	 * @param string $path Chemin absolu.
	 * @return string URL, ou '' si hors du dossier de téléversement.
	 */
	public static function path_to_url( $path ) {
		$uploads = self::uploads();
		if ( 0 !== strpos( $path, $uploads['basedir'] ) ) {
			return '';
		}
		return $uploads['baseurl'] . substr( $path, strlen( $uploads['basedir'] ) );
	}

	/* ------------------------------------------------------------------
	 * Existence
	 * ---------------------------------------------------------------- */

	/**
	 * Variantes réellement présentes sur le disque, par ordre de
	 * préférence (la plus efficace d'abord).
	 *
	 * @param string $source_path Chemin absolu de l'original.
	 * @return array<int,array{format:string,path:string,url:string,mime:string,bytes:int}>
	 */
	public static function existing( $source_path ) {
		if ( isset( self::$cache[ $source_path ] ) ) {
			return self::$cache[ $source_path ];
		}

		$found = array();

		if ( $source_path && file_exists( $source_path ) ) {
			foreach ( Africart_PWA_Image_Converter::FORMATS as $format ) {
				$path = self::variant_path( $source_path, $format );
				if ( ! file_exists( $path ) ) {
					continue;
				}
				$found[] = array(
					'format' => $format,
					'path'   => $path,
					'url'    => self::path_to_url( $path ),
					'mime'   => 'image/' . $format,
					'bytes'  => filesize( $path ),
				);
			}
		}

		self::$cache[ $source_path ] = $found;
		return $found;
	}

	/**
	 * Variantes existantes à partir d'une URL.
	 *
	 * @param string $url URL de l'original.
	 * @return array Même structure que existing().
	 */
	public static function existing_for_url( $url ) {
		$path = self::url_to_path( $url );
		if ( '' === $path ) {
			return array();
		}
		return self::existing( $path );
	}

	/**
	 * Supprime les variantes d'un fichier (purge, ou suppression du média).
	 *
	 * @param string $source_path Chemin absolu de l'original.
	 * @return int Nombre de fichiers supprimés.
	 */
	public static function delete_for( $source_path ) {
		$deleted = 0;
		foreach ( Africart_PWA_Image_Converter::FORMATS as $format ) {
			$path = self::variant_path( $source_path, $format );
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
				++$deleted;
			}
		}
		unset( self::$cache[ $source_path ] );
		return $deleted;
	}

	/**
	 * Tous les fichiers d'un média : l'original et ses déclinaisons
	 * générées par WordPress (miniature, moyenne, large…).
	 *
	 * Chaque déclinaison est un fichier distinct qui mérite sa propre
	 * variante : c'est elle qui sera servie sur les petits écrans.
	 *
	 * @param int $attachment_id Identifiant du média.
	 * @return string[] Chemins absolus.
	 */
	public static function files_of_attachment( $attachment_id ) {
		$original = get_attached_file( $attachment_id );
		if ( ! $original || ! file_exists( $original ) ) {
			return array();
		}

		$files = array( $original );
		$dir   = dirname( $original );
		$meta  = wp_get_attachment_metadata( $attachment_id );

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$path = $dir . '/' . $size['file'];
				if ( file_exists( $path ) ) {
					$files[] = $path;
				}
			}
		}

		return array_values( array_unique( $files ) );
	}
}
