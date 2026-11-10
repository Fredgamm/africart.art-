<?php
/**
 * Écran « Abonnés ».
 *
 * Le tableau de bord OneSignal sait dire *combien* d'abonnés existent,
 * jamais *qui*. Pour un site où les artistes ont un compte, c'est
 * l'information qui manque : savoir qu'un membre est joignable, sur
 * quel appareil, et ce qu'il a accepté de recevoir.
 *
 * Cet écran ne stocke rien. Il lit les abonnements chez OneSignal, et
 * les rapproche des comptes du site par le lien posé à l'abonnement
 * (voir `class-push-tags.php`). Un abonnement sans compte reste
 * anonyme — c'est le cas le plus fréquent, et parfaitement normal :
 * s'abonner n'oblige pas à se connecter.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Subscribers {

	/** Liste des abonnements. */
	const ENDPOINT = 'https://onesignal.com/api/v1/players';

	/** Liste des segments. */
	const SEGMENTS = 'https://api.onesignal.com/apps/';

	/** Mémoire courte : l'API est lente et limitée en fréquence. */
	const CACHE = 'afk_pwa_subscribers';

	/** Durée de la mémoire courte, en secondes. */
	const CACHE_TTL = 300;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_afk_push_refresh', array( $this, 'refresh' ) );
	}

	/**
	 * Adresse de l'écran.
	 *
	 * Second onglet de l'écran Notifications, et non entrée de menu :
	 * les abonnés ne sont pas un réglage, ils partagent seulement le
	 * sujet.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . Africart_PWA_Settings::PAGE . '-push&tab=abonnes' );
	}

	/** Vide la mémoire courte et revient à l'écran. */
	public function refresh() {
		check_admin_referer( 'afk_push_refresh' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Droits insuffisants.', 'africart-pwa' ) );
		}

		delete_transient( self::CACHE );

		wp_safe_redirect( self::url() );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Lecture
	 * ---------------------------------------------------------------- */

	/**
	 * Abonnements enregistrés chez OneSignal.
	 *
	 * Paginé par tranches de 300 — la limite de l'API. La boucle
	 * s'arrête d'elle-même, et un plafond dur évite qu'une réponse
	 * inattendue ne la fasse tourner sans fin.
	 *
	 * @return array|WP_Error
	 */
	public static function fetch() {
		$memoire = get_transient( self::CACHE );

		if ( is_array( $memoire ) ) {
			return $memoire;
		}

		$cle = Africart_PWA_Push_Sender::get_key();
		$app = Africart_PWA_OneSignal::app_id();

		if ( '' === $cle || '' === $app ) {
			return new WP_Error( 'no_key', __( 'Clé d’envoi ou identifiant d’application manquant.', 'africart-pwa' ) );
		}

		$tous   = array();
		$offset = 0;

		for ( $page = 0; $page < 20; $page++ ) {
			$reponse = wp_remote_get(
				add_query_arg(
					array(
						'app_id' => $app,
						'limit'  => 300,
						'offset' => $offset,
					),
					self::ENDPOINT
				),
				array(
					'timeout' => 25,
					'headers' => array( 'Authorization' => 'Key ' . $cle ),
				)
			);

			if ( is_wp_error( $reponse ) ) {
				return $reponse;
			}

			$code = (int) wp_remote_retrieve_response_code( $reponse );

			if ( 200 !== $code ) {
				return new WP_Error(
					'http',
					/* translators: %d code de réponse HTTP */
					sprintf( __( 'OneSignal a répondu %d.', 'africart-pwa' ), $code )
				);
			}

			$corps = json_decode( wp_remote_retrieve_body( $reponse ), true );

			if ( empty( $corps['players'] ) ) {
				break;
			}

			$tous    = array_merge( $tous, $corps['players'] );
			$offset += count( $corps['players'] );

			if ( $offset >= (int) ( $corps['total_count'] ?? 0 ) ) {
				break;
			}
		}

		set_transient( self::CACHE, $tous, self::CACHE_TTL );

		return $tous;
	}

	/**
	 * Segments définis dans l'application OneSignal.
	 *
	 * @return array Noms, ou tableau vide si l'appel échoue.
	 */
	public static function segments() {
		$cle = Africart_PWA_Push_Sender::get_key();
		$app = Africart_PWA_OneSignal::app_id();

		if ( '' === $cle || '' === $app ) {
			return array();
		}

		$reponse = wp_remote_get(
			self::SEGMENTS . rawurlencode( $app ) . '/segments',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Key ' . $cle ),
			)
		);

		if ( is_wp_error( $reponse ) ) {
			return array();
		}

		$corps = json_decode( wp_remote_retrieve_body( $reponse ), true );

		return isset( $corps['segments'] ) ? $corps['segments'] : array();
	}

	/* ------------------------------------------------------------------
	 * Affichage
	 * ---------------------------------------------------------------- */

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$abonnements = self::fetch();
		$liens       = Africart_PWA_Push_Tags::known_links();
		$centres     = Africart_PWA_OneSignal::get_categories();
		?>
		<div class="wrap afk-wrap">
			<div class="afk-header">
				<img src="<?php echo esc_url( Africart_PWA_Settings::get( 'icon_192' ) ); ?>" alt="" />
				<div>
					<h1><?php esc_html_e( 'Notifications push', 'africart-pwa' ); ?></h1>
					<p><?php esc_html_e( 'Lu chez OneSignal, rapproché des comptes du site. Rien n’est enregistré ici.', 'africart-pwa' ); ?></p>
				</div>
			</div>

			<?php Africart_PWA_Settings::render_push_tabs( 'abonnes' ); ?>

			<?php
			if ( is_wp_error( $abonnements ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html( $abonnements->get_error_message() )
				);
				echo '</div>';
				return;
			}

			$this->render_resume( $abonnements, $liens, $centres );
			$this->render_segments();
			$this->render_table( $abonnements, $liens, $centres );
			?>
		</div>
		<?php
	}

	/**
	 * Chiffres d'ensemble.
	 *
	 * @param array $abonnements Abonnements.
	 * @param array $liens       Abonnement => compte.
	 * @param array $centres     Centres d'intérêt déclarés.
	 */
	private function render_resume( $abonnements, $liens, $centres ) {
		$actifs   = 0;
		$rattache = 0;
		$choisi   = 0;
		$par      = array_fill_keys( wp_list_pluck( $centres, 'slug' ), 0 );

		foreach ( $abonnements as $a ) {
			if ( empty( $a['invalid_identifier'] ) ) {
				$actifs++;
			}
			if ( self::compte( $a, $liens ) ) {
				$rattache++;
			}
			if ( ! empty( $a['tags'] ) ) {
				$choisi++;
			}
			foreach ( $par as $slug => $n ) {
				// Absence d'étiquette = pas de refus : l'abonné est compté
				// comme destinataire, exactement comme le fait le ciblage.
				if ( ! isset( $a['tags'][ $slug ] ) || '0' !== (string) $a['tags'][ $slug ] ) {
					$par[ $slug ]++;
				}
			}
		}

		$cartes = array(
			array( __( 'Abonnements', 'africart-pwa' ), count( $abonnements ), __( 'appareils enregistrés', 'africart-pwa' ) ),
			array( __( 'Joignables', 'africart-pwa' ), $actifs, __( 'abonnements encore valides', 'africart-pwa' ) ),
			array( __( 'Rattachés à un compte', 'africart-pwa' ), $rattache, __( 'les autres sont anonymes', 'africart-pwa' ) ),
			array( __( 'Ont choisi', 'africart-pwa' ), $choisi, __( 'ont ouvert la fenêtre de préférences', 'africart-pwa' ) ),
		);
		?>
		<div class="afk-stats">
			<?php foreach ( $cartes as $carte ) : ?>
				<div class="afk-stat">
					<span class="afk-stat-n"><?php echo esc_html( (string) $carte[1] ); ?></span>
					<strong><?php echo esc_html( $carte[0] ); ?></strong>
					<span class="afk-stat-note"><?php echo esc_html( $carte[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $centres ) : ?>
			<p class="afk-portee">
				<?php esc_html_e( 'Portée par centre d’intérêt :', 'africart-pwa' ); ?>
				<?php foreach ( $centres as $c ) : ?>
					<span class="afk-pill"><?php echo esc_html( $c['label'] ); ?> — <strong><?php echo esc_html( (string) $par[ $c['slug'] ] ); ?></strong></span>
				<?php endforeach; ?>
				<em><?php esc_html_e( 'Un abonné qui n’a jamais ouvert la fenêtre de choix est compté partout : ne rien avoir choisi n’est pas avoir refusé.', 'africart-pwa' ); ?></em>
			</p>
		<?php endif; ?>
		<?php
	}

	/** Segments existants chez OneSignal. */
	private function render_segments() {
		$segments = self::segments();

		if ( ! $segments ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Segments OneSignal', 'africart-pwa' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Définis dans le tableau de bord OneSignal. Le site ne les utilise pas pour ses envois automatiques : il cible par centre d’intérêt, ce qui reste juste même quand un abonné n’a rien choisi.', 'africart-pwa' ); ?>
		</p>
		<p>
			<?php foreach ( $segments as $s ) : ?>
				<span class="afk-pill"><?php echo esc_html( $s['name'] ); ?></span>
			<?php endforeach; ?>
		</p>
		<?php
	}

	/**
	 * Le tableau des abonnements.
	 *
	 * @param array $abonnements Abonnements.
	 * @param array $liens       Abonnement => compte.
	 * @param array $centres     Centres d'intérêt déclarés.
	 */
	private function render_table( $abonnements, $liens, $centres ) {
		// Les plus récents d'abord : c'est ce qu'on vient vérifier après
		// un test ou une campagne.
		usort(
			$abonnements,
			static function ( $a, $b ) {
				return (int) ( $b['last_active'] ?? 0 ) <=> (int) ( $a['last_active'] ?? 0 );
			}
		);
		?>
		<h2 style="margin-top:24px">
			<?php esc_html_e( 'Détail', 'africart-pwa' ); ?>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=afk_push_refresh' ), 'afk_push_refresh' ) ); ?>">
				<?php esc_html_e( 'Actualiser', 'africart-pwa' ); ?>
			</a>
		</h2>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Abonné', 'africart-pwa' ); ?></th>
					<th><?php esc_html_e( 'Appareil', 'africart-pwa' ); ?></th>
					<th><?php esc_html_e( 'Centres d’intérêt', 'africart-pwa' ); ?></th>
					<th><?php esc_html_e( 'Dernière visite', 'africart-pwa' ); ?></th>
					<th><?php esc_html_e( 'État', 'africart-pwa' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $abonnements ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Aucun abonnement pour le moment.', 'africart-pwa' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $abonnements as $a ) : ?>
				<?php
				$user   = self::compte( $a, $liens );
				$valide = empty( $a['invalid_identifier'] );
				?>
				<tr>
					<td>
						<?php if ( $user ) : ?>
							<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><strong><?php echo esc_html( $user->display_name ); ?></strong></a><br />
							<span class="afk-muted"><?php echo esc_html( $user->user_email ); ?></span>
						<?php else : ?>
							<em class="afk-muted"><?php esc_html_e( 'Visiteur non connecté', 'africart-pwa' ); ?></em>
						<?php endif; ?>
						<br /><code class="afk-muted"><?php echo esc_html( substr( (string) $a['id'], 0, 8 ) ); ?>…</code>
					</td>
					<td>
						<?php echo esc_html( self::appareil( $a ) ); ?><br />
						<span class="afk-muted"><?php echo esc_html( (string) ( $a['device_os'] ?? '' ) ); ?></span>
					</td>
					<td><?php echo wp_kses_post( self::interets( $a, $centres ) ); ?></td>
					<td>
						<?php
						echo empty( $a['last_active'] )
							? '—'
							: esc_html( wp_date( 'j M Y H:i', (int) $a['last_active'] ) );
						?>
					</td>
					<td>
						<?php if ( $valide ) : ?>
							<span class="afk-ok"><?php esc_html_e( 'Joignable', 'africart-pwa' ); ?></span>
						<?php else : ?>
							<span class="afk-ko"><?php esc_html_e( 'Injoignable', 'africart-pwa' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Petits utilitaires d'affichage
	 * ---------------------------------------------------------------- */

	/**
	 * Compte WordPress derrière un abonnement.
	 *
	 * Deux sources, dans cet ordre : la méta posée par le site — sûre,
	 * puisque écrite pendant une session authentifiée — puis
	 * l'`external_user_id` renvoyé par OneSignal, utile si la méta a été
	 * perdue lors d'une restauration de base.
	 *
	 * @param array $abonnement Fiche OneSignal.
	 * @param array $liens      Abonnement => identifiant de compte.
	 * @return WP_User|null
	 */
	private static function compte( $abonnement, $liens ) {
		$id = isset( $liens[ $abonnement['id'] ] ) ? $liens[ $abonnement['id'] ] : 0;

		if ( ! $id && ! empty( $abonnement['external_user_id'] ) && ctype_digit( (string) $abonnement['external_user_id'] ) ) {
			$id = (int) $abonnement['external_user_id'];
		}

		if ( ! $id ) {
			return null;
		}

		$user = get_user_by( 'id', $id );

		return $user ? $user : null;
	}

	/**
	 * Nom lisible de l'appareil.
	 *
	 * `device_model` porte le moteur du navigateur (« Win32 »,
	 * « Linux armv81 »), ce qui ne dit pas grand-chose. On préfère le
	 * type d'abonnement, qui distingue au moins la plateforme.
	 *
	 * @param array $a Fiche OneSignal.
	 * @return string
	 */
	private static function appareil( $a ) {
		$types = array(
			0  => 'iOS',
			1  => 'Android',
			5  => 'Chrome',
			7  => 'Safari',
			8  => 'Firefox',
			9  => 'macOS',
			11 => __( 'Courriel', 'africart-pwa' ),
			14 => 'Edge',
		);

		$type   = isset( $a['device_type'] ) ? (int) $a['device_type'] : -1;
		$nom    = isset( $types[ $type ] ) ? $types[ $type ] : __( 'Navigateur', 'africart-pwa' );
		$modele = trim( (string) ( $a['device_model'] ?? '' ) );

		return $modele ? $nom . ' · ' . $modele : $nom;
	}

	/**
	 * Centres d'intérêt d'un abonnement, tels qu'ils s'appliquent.
	 *
	 * @param array $a       Fiche OneSignal.
	 * @param array $centres Centres déclarés.
	 * @return string HTML.
	 */
	private static function interets( $a, $centres ) {
		if ( ! $centres ) {
			return '<span class="afk-muted">—</span>';
		}

		if ( empty( $a['tags'] ) ) {
			return '<span class="afk-muted">' . esc_html__( 'Aucun choix — reçoit tout', 'africart-pwa' ) . '</span>';
		}

		$sortie = '';

		foreach ( $centres as $c ) {
			$refuse  = isset( $a['tags'][ $c['slug'] ] ) && '0' === (string) $a['tags'][ $c['slug'] ];
			$sortie .= sprintf(
				'<span class="afk-pill%s">%s</span> ',
				$refuse ? ' afk-pill-off' : '',
				esc_html( $c['label'] )
			);
		}

		return $sortie;
	}
}
