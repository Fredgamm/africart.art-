<?php
/**
 * Pilotage du rattrapage des images depuis l'administration.
 *
 * Responsabilité unique : exposer la file d'attente à l'écran de
 * réglages. Le traitement lui-même appartient à Africart_PWA_Image_Queue.
 *
 * Le travail est découpé en très petits lots parce qu'un encodage AVIF
 * coûte environ 2 secondes et qu'un média compte une dizaine de
 * déclinaisons : une requête par média reste sous les limites de temps
 * d'exécution du serveur. C'est le navigateur qui enchaîne les
 * requêtes, ce qui permet d'afficher une progression réelle et de
 * s'arrêter à tout moment.
 *
 * La progression étant enregistrée en base, fermer la page n'annule
 * rien : la reprise repart du dernier média traité.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Perf_Batch {

	const ACTION = 'afk_pwa_batch';
	const NONCE  = 'afk_pwa_batch_nonce';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_afk_pwa_seo_backfill', array( $this, 'handle_seo_backfill' ) );
	}

	/* ------------------------------------------------------------------
	 * Point d'entrée AJAX
	 * ---------------------------------------------------------------- */

	/**
	 * Traite une commande du navigateur : démarrage, avancement d'un
	 * lot, arrêt, ou simple consultation.
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'africart-pwa' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$command = isset( $_POST['command'] ) ? sanitize_key( wp_unslash( $_POST['command'] ) ) : 'state';
		$size    = isset( $_POST['size'] ) ? max( 1, min( 5, (int) $_POST['size'] ) ) : 1;

		switch ( $command ) {
			case 'start':
				if ( ! Africart_PWA_Settings::enabled_formats() ) {
					wp_send_json_error(
						array( 'message' => __( 'Aucun format actif : vérifiez les réglages plus haut.', 'africart-pwa' ) ),
						400
					);
				}
				$state = Africart_PWA_Image_Queue::start_batch();
				break;

			case 'resume':
				$state = Africart_PWA_Image_Queue::get_state();
				if ( 'done' !== $state['status'] ) {
					$state['status'] = 'running';
					update_option( Africart_PWA_Image_Queue::STATE_OPTION, $state, false );
				}
				break;

			case 'step':
				$state = Africart_PWA_Image_Queue::process_batch( $size );
				break;

			case 'stop':
				Africart_PWA_Image_Queue::stop_batch();
				$state = Africart_PWA_Image_Queue::get_state();
				break;

			case 'state':
			default:
				$state = Africart_PWA_Image_Queue::get_state();
				break;
		}

		wp_send_json_success( self::format_state( $state ) );
	}

	/**
	 * Met l'état en forme pour l'affichage.
	 *
	 * @param array $state État brut.
	 * @return array
	 */
	public static function format_state( $state ) {
		$total   = max( 0, (int) $state['total'] );
		$offset  = max( 0, (int) $state['offset'] );
		$percent = $total > 0 ? min( 100, round( $offset * 100 / $total, 1 ) ) : 0;

		return array(
			'status'      => $state['status'],
			'offset'      => $offset,
			'total'       => $total,
			'percent'     => $percent,
			'converted'   => (int) $state['converted'],
			'rejected'    => (int) $state['rejected'],
			'failed'      => (int) $state['failed'],
			'saved'       => (int) $state['saved'],
			'savedHuman'  => size_format( (int) $state['saved'] ),
			'summary'     => sprintf(
				/* translators: %1$d versions, %2$d écartées, %3$d échecs, %4$s poids */
				__( '%1$d versions produites · %2$d écartées · %3$d échecs · %4$s économisés', 'africart-pwa' ),
				(int) $state['converted'],
				(int) $state['rejected'],
				(int) $state['failed'],
				size_format( (int) $state['saved'] )
			),
		);
	}

	/**
	 * Affecte le visuel de partage aux oeuvres qui en sont depourvues,
	 * par lots pour rester sous les limites de temps d'execution.
	 */
	public function handle_seo_backfill() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'africart-pwa' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$result = Africart_PWA_Social_Meta::backfill( 40 );
		$result['message'] = sprintf(
			/* translators: %1$d corrigees, %2$d sans visuel, %3$d restantes */
			__( '%1$d corrigees, %2$d sans visuel exploitable, %3$d restantes.', 'africart-pwa' ),
			$result['done'], $result['skipped'], $result['remaining']
		);

		wp_send_json_success( $result );
	}

	/* ------------------------------------------------------------------
	 * Interface
	 * ---------------------------------------------------------------- */

	/**
	 * Affiche la barre de progression et ses commandes.
	 */
	public static function render() {
		$state    = self::format_state( Africart_PWA_Image_Queue::get_state() );
		$restant  = $state['total'] - $state['offset'];
		$en_cours = 'running' === $state['status'];
		$fini     = 'done' === $state['status'];
		?>
		<div class="afk-batch"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>"
			data-action="<?php echo esc_attr( self::ACTION ); ?>">

			<div class="afk-batch-bar" role="progressbar"
				aria-valuemin="0" aria-valuemax="100"
				aria-valuenow="<?php echo esc_attr( $state['percent'] ); ?>">
				<span class="afk-batch-fill" style="width:<?php echo esc_attr( $state['percent'] ); ?>%"></span>
			</div>

			<p class="afk-batch-count">
				<strong class="afk-batch-done"><?php echo esc_html( $state['offset'] ); ?></strong>
				<span class="afk-batch-sep">/</span>
				<span class="afk-batch-total"><?php echo esc_html( $state['total'] ); ?></span>
				<?php esc_html_e( 'médias', 'africart-pwa' ); ?>
				<span class="afk-batch-state">
					<?php
					if ( $fini ) {
						esc_html_e( '— terminé', 'africart-pwa' );
					} elseif ( $state['offset'] > 0 ) {
						/* translators: %d nombre de médias restants */
						echo esc_html( sprintf( __( '— %d restants', 'africart-pwa' ), $restant ) );
					}
					?>
				</span>
			</p>

			<p class="afk-batch-summary afk-hint"><?php echo esc_html( $state['summary'] ); ?></p>

			<p class="afk-batch-actions">
				<button type="button" class="button button-primary afk-batch-start">
					<?php
					if ( $state['offset'] > 0 && ! $fini ) {
						esc_html_e( 'Reprendre le traitement', 'africart-pwa' );
					} else {
						esc_html_e( 'Lancer le traitement', 'africart-pwa' );
					}
					?>
				</button>
				<button type="button" class="button afk-batch-stop" <?php disabled( ! $en_cours ); ?>>
					<?php esc_html_e( 'Arrêter', 'africart-pwa' ); ?>
				</button>
				<?php if ( $state['offset'] > 0 ) : ?>
					<button type="button" class="button-link afk-batch-restart">
						<?php esc_html_e( 'Tout reprendre depuis le début', 'africart-pwa' ); ?>
					</button>
				<?php endif; ?>
			</p>

			<p class="afk-batch-note afk-hint">
				<?php esc_html_e( 'Le traitement avance tant que cette page reste ouverte. Vous pouvez la fermer : la progression est conservée et reprendra là où elle s’est arrêtée.', 'africart-pwa' ); ?>
			</p>

			<p class="afk-batch-error" role="alert" hidden></p>
		</div>
		<?php
	}
}
