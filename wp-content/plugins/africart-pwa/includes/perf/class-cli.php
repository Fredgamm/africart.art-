<?php
/**
 * Commandes en ligne.
 *
 * Le rattrapage porte sur plusieurs milliers de fichiers et l'encodage
 * AVIF coûte environ 2 secondes par image : le navigateur n'est pas le
 * bon outil. Ces commandes permettent de lancer le traitement en SSH,
 * sans limite de temps d'exécution, et de le reprendre après coupure.
 *
 *     wp africart images status
 *     wp africart images convert [--batch=<n>] [--limit=<n>] [--force]
 *     wp africart images purge [--yes]
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_CLI {

	/**
	 * Affiche l'état de la conversion et les capacités du serveur.
	 *
	 * ## EXAMPLES
	 *
	 *     wp africart images status
	 *
	 * @param array $args       Arguments positionnels.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$support = Africart_PWA_Image_Converter::supported_formats();
		$state   = Africart_PWA_Image_Queue::get_state();

		WP_CLI::line( '' );
		WP_CLI::line( 'Capacités du serveur' );
		foreach ( $support as $format => $ok ) {
			WP_CLI::line( sprintf( '  %-6s %s', $format, $ok ? 'disponible' : 'INDISPONIBLE' ) );
		}

		WP_CLI::line( '' );
		WP_CLI::line( 'Réglages' );
		WP_CLI::line( sprintf( '  conversion   %s', Africart_PWA_Settings::is_conversion_enabled() ? 'activée' : 'désactivée' ) );
		WP_CLI::line( sprintf( '  réécriture   %s', Africart_PWA_Settings::is_rewrite_enabled() ? 'activée' : 'désactivée' ) );
		WP_CLI::line( sprintf( '  formats      %s', implode( ', ', Africart_PWA_Settings::enabled_formats() ) ?: '(aucun)' ) );

		WP_CLI::line( '' );
		WP_CLI::line( 'Rattrapage' );
		WP_CLI::line( sprintf( '  état         %s', $state['status'] ) );
		WP_CLI::line( sprintf( '  progression  %d / %d médias', $state['offset'], $state['total'] ) );
		WP_CLI::line( sprintf( '  converties   %d', $state['converted'] ) );
		WP_CLI::line( sprintf( '  refusées     %d  (variante pas plus légère)', $state['rejected'] ) );
		WP_CLI::line( sprintf( '  échecs       %d', $state['failed'] ) );
		WP_CLI::line( sprintf( '  économisé    %s', size_format( $state['saved'] ) ) );
		WP_CLI::line( '' );
	}

	/**
	 * Lance ou poursuit le rattrapage des images existantes.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Nombre de médias traités par lot. Par défaut : 10.
	 *
	 * [--limit=<n>]
	 * : S'arrêter après ce nombre de médias. Par défaut : tout traiter.
	 *
	 * [--restart]
	 * : Repartir du début plutôt que de reprendre la progression.
	 *
	 * [--force]
	 * : Reconvertir même les fichiers qui ont déjà une variante.
	 *
	 * ## EXAMPLES
	 *
	 *     wp africart images convert
	 *     wp africart images convert --batch=25 --limit=200
	 *     wp africart images convert --restart --force
	 *
	 * @param array $args       Arguments positionnels.
	 * @param array $assoc_args Options.
	 */
	public function convert( $args, $assoc_args ) {
		unset( $args );

		if ( ! Africart_PWA_Settings::enabled_formats() ) {
			WP_CLI::error( 'Aucun format actif. Vérifiez Réglages > AFRICART PWA > Performance.' );
		}

		$batch   = max( 1, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 10 ) );
		$limit   = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$force   = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$restart = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'restart', false );

		$state = Africart_PWA_Image_Queue::get_state();

		if ( $restart || 'done' === $state['status'] || 'idle' === $state['status'] ) {
			if ( $restart || 0 === $state['offset'] || 'done' === $state['status'] ) {
				$state = Africart_PWA_Image_Queue::start_batch();
				WP_CLI::log( sprintf( 'Rattrapage démarré : %d médias à traiter.', $state['total'] ) );
			} else {
				// Reprise d'un traitement interrompu.
				$state['status'] = 'running';
				update_option( Africart_PWA_Image_Queue::STATE_OPTION, $state, false );
				WP_CLI::log( sprintf( 'Reprise à %d / %d.', $state['offset'], $state['total'] ) );
			}
		}

		$target   = $limit > 0 ? min( $state['total'], $state['offset'] + $limit ) : $state['total'];
		$progress = WP_CLI\Utils\make_progress_bar( 'Conversion', max( 1, $target - $state['offset'] ) );
		$started  = $state['offset'];

		while ( true ) {
			$before = $state['offset'];
			$state  = Africart_PWA_Image_Queue::process_batch( $batch, $force );
			$done   = $state['offset'] - $before;

			if ( $done > 0 ) {
				$progress->tick( $done );
			}

			if ( 'running' !== $state['status'] || $done <= 0 ) {
				break;
			}
			if ( $limit > 0 && ( $state['offset'] - $started ) >= $limit ) {
				Africart_PWA_Image_Queue::stop_batch();
				break;
			}
		}

		$progress->finish();

		WP_CLI::success(
			sprintf(
				'%d converties, %d refusées (pas plus légères), %d échecs, %d ignorées — %s économisés.',
				$state['converted'],
				$state['rejected'],
				$state['failed'],
				$state['skipped'],
				size_format( $state['saved'] )
			)
		);

		if ( 'done' !== $state['status'] ) {
			WP_CLI::log( sprintf( 'Arrêt à %d / %d. Relancez la commande pour poursuivre.', $state['offset'], $state['total'] ) );
		}
	}

	/**
	 * Supprime toutes les variantes produites. Les originaux ne sont
	 * jamais touchés.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Ne pas demander confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp africart images purge --yes
	 *
	 * @param array $args       Arguments positionnels.
	 * @param array $assoc_args Options.
	 */
	public function purge( $args, $assoc_args ) {
		unset( $args );

		WP_CLI::confirm( 'Supprimer toutes les variantes AVIF et WebP ? Les originaux sont conservés.', $assoc_args );

		$deleted = 0;
		$paged   = 1;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => array( 'image/jpeg', 'image/png' ),
					'fields'         => 'ids',
					'posts_per_page' => 100,
					'paged'          => $paged,
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $attachment_id ) {
				foreach ( Africart_PWA_Image_Variants::files_of_attachment( $attachment_id ) as $file ) {
					$deleted += Africart_PWA_Image_Variants::delete_for( $file );
				}
			}

			++$paged;
		} while ( $query->posts );

		delete_option( Africart_PWA_Image_Queue::STATE_OPTION );

		WP_CLI::success( sprintf( '%d variantes supprimées. Les originaux sont intacts.', $deleted ) );
	}
}
