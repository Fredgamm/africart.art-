<?php
/**
 * Orchestration des conversions.
 *
 * Responsabilité unique : décider QUAND convertir, et tenir le compte.
 * La conversion elle-même appartient au convertisseur.
 *
 * Deux chemins d'entrée :
 *
 *   1. Au dépôt d'une œuvre — la conversion est programmée en tâche de
 *      fond. L'encodage AVIF prend environ 2 secondes par fichier et
 *      WordPress produit une dizaine de déclinaisons : bloquer le
 *      formulaire d'envoi serait inacceptable pour un artiste.
 *
 *   2. En rattrapage — les médias déjà en ligne sont traités par lots,
 *      avec une progression persistante. Le traitement est
 *      interruptible et reprend là où il s'est arrêté.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Image_Queue {

	/** Tâche de fond déclenchée après un dépôt. */
	const CRON_HOOK = 'afk_pwa_convert_attachment';

	/** Option portant l'état du rattrapage. */
	const STATE_OPTION = 'afk_pwa_batch_state';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Après que WordPress a produit toutes les déclinaisons.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_new_attachment' ), 99, 2 );
		add_action( self::CRON_HOOK, array( $this, 'convert_attachment' ) );
		// Un média supprimé emporte ses variantes.
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
	}

	/* ------------------------------------------------------------------
	 * Chemin 1 — au dépôt
	 * ---------------------------------------------------------------- */

	/**
	 * Programme la conversion d'un média fraîchement déposé.
	 *
	 * @param array $metadata      Métadonnées produites par WordPress.
	 * @param int   $attachment_id Identifiant du média.
	 * @return array Les métadonnées, inchangées.
	 */
	public function on_new_attachment( $metadata, $attachment_id ) {
		if ( ! Africart_PWA_Settings::is_conversion_enabled() ) {
			return $metadata;
		}

		// Programmée à quelques secondes, le temps que la requête de
		// dépôt se termine et rende la main à l'utilisateur.
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( (int) $attachment_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( (int) $attachment_id ) );
		}

		return $metadata;
	}

	/**
	 * Supprime les variantes d'un média supprimé.
	 *
	 * @param int $attachment_id Identifiant du média.
	 */
	public function on_delete_attachment( $attachment_id ) {
		foreach ( Africart_PWA_Image_Variants::files_of_attachment( $attachment_id ) as $file ) {
			Africart_PWA_Image_Variants::delete_for( $file );
		}
	}

	/* ------------------------------------------------------------------
	 * Conversion d'un média (original + toutes ses déclinaisons)
	 * ---------------------------------------------------------------- */

	/**
	 * Convertit tous les fichiers d'un média.
	 *
	 * @param int  $attachment_id Identifiant du média.
	 * @param bool $force         Reconvertir même si la variante existe.
	 * @return array Compteurs : converted, rejected, failed, skipped, saved.
	 */
	public function convert_attachment( $attachment_id, $force = false ) {
		$stats = array(
			'converted' => 0,
			'rejected'  => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'saved'     => 0,
		);

		$formats = Africart_PWA_Settings::enabled_formats();
		if ( ! $formats ) {
			return $stats;
		}

		foreach ( Africart_PWA_Image_Variants::files_of_attachment( $attachment_id ) as $file ) {
			$file_stats = $this->convert_file( $file, $formats, $force );
			foreach ( $file_stats as $key => $value ) {
				$stats[ $key ] += $value;
			}
		}

		return $stats;
	}

	/**
	 * Convertit un fichier dans tous les formats demandés.
	 *
	 * @param string   $file    Chemin absolu.
	 * @param string[] $formats Formats à produire.
	 * @param bool     $force   Reconvertir même si présent.
	 * @return array Compteurs.
	 */
	public function convert_file( $file, $formats, $force = false ) {
		$stats = array(
			'converted' => 0,
			'rejected'  => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'saved'     => 0,
		);

		if ( ! Africart_PWA_Image_Converter::is_convertible( $file ) ) {
			++$stats['skipped'];
			return $stats;
		}

		foreach ( $formats as $format ) {
			$target = Africart_PWA_Image_Variants::variant_path( $file, $format );

			if ( ! $force && file_exists( $target ) ) {
				++$stats['skipped'];
				continue;
			}

			$quality = Africart_PWA_Settings::quality_for( $format );
			$result  = Africart_PWA_Image_Converter::convert( $file, $format, $quality );

			if ( is_wp_error( $result ) ) {
				// « Pas plus légère » est un refus normal, pas une panne :
				// on le compte à part pour ne pas alarmer inutilement.
				if ( 'not_smaller' === $result->get_error_code() ) {
					++$stats['rejected'];
				} else {
					++$stats['failed'];
				}
				continue;
			}

			++$stats['converted'];
			$stats['saved'] += $result['saved'];
		}

		return $stats;
	}

	/* ------------------------------------------------------------------
	 * Chemin 2 — rattrapage de l'existant
	 * ---------------------------------------------------------------- */

	/**
	 * État courant du rattrapage.
	 *
	 * @return array
	 */
	public static function get_state() {
		$defaults = array(
			'status'    => 'idle', // idle | running | done
			'offset'    => 0,
			'total'     => 0,
			'converted' => 0,
			'rejected'  => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'saved'     => 0,
			'updated'   => 0,
		);
		return wp_parse_args( get_option( self::STATE_OPTION, array() ), $defaults );
	}

	/**
	 * Enregistre l'état du rattrapage.
	 *
	 * @param array $state État complet.
	 */
	private static function save_state( $state ) {
		$state['updated'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * (Re)démarre un rattrapage depuis le début.
	 *
	 * @return array Le nouvel état.
	 */
	public static function start_batch() {
		$state              = self::get_state();
		$state['status']    = 'running';
		$state['offset']    = 0;
		$state['total']     = self::count_images();
		$state['converted'] = 0;
		$state['rejected']  = 0;
		$state['failed']    = 0;
		$state['skipped']   = 0;
		$state['saved']     = 0;
		self::save_state( $state );
		return $state;
	}

	/**
	 * Interrompt le rattrapage. La progression est conservée.
	 */
	public static function stop_batch() {
		$state           = self::get_state();
		$state['status'] = 'idle';
		self::save_state( $state );
	}

	/**
	 * Nombre total de médias images de la bibliothèque.
	 *
	 * @return int
	 */
	public static function count_images() {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Traite un lot de médias et fait progresser l'état.
	 *
	 * @param int  $size  Nombre de médias à traiter.
	 * @param bool $force Reconvertir même si les variantes existent.
	 * @return array L'état après traitement.
	 */
	public static function process_batch( $size = 10, $force = false ) {
		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'fields'         => 'ids',
				'posts_per_page' => max( 1, (int) $size ),
				'offset'         => (int) $state['offset'],
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		if ( ! $query->posts ) {
			$state['status'] = 'done';
			self::save_state( $state );
			return $state;
		}

		$queue = self::instance();
		foreach ( $query->posts as $attachment_id ) {
			$stats = $queue->convert_attachment( $attachment_id, $force );
			foreach ( array( 'converted', 'rejected', 'failed', 'skipped', 'saved' ) as $key ) {
				$state[ $key ] += $stats[ $key ];
			}
			++$state['offset'];
		}

		if ( $state['offset'] >= $state['total'] ) {
			$state['status'] = 'done';
		}

		self::save_state( $state );
		return $state;
	}
}
