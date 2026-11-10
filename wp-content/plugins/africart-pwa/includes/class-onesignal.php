<?php
/**
 * Notifications push OneSignal.
 *
 * Point d'architecture décisif : un navigateur n'accepte qu'UN SEUL
 * service worker à la racine du site. Or l'installation de la PWA et
 * les notifications le réclament tous les deux. Laisser OneSignal
 * enregistrer le sien désactiverait l'installation — et l'inverse
 * empêcherait les notifications.
 *
 * La solution retenue : notre service worker reste le seul enregistré,
 * et il importe le SDK de OneSignal. On indique donc à OneSignal
 * d'utiliser notre fichier plutôt que le sien.
 *
 * Ce module ne fait que poser le socle : chargement du SDK, rattachement
 * au service worker existant, et étiquetage par centre d'intérêt pour
 * permettre l'envoi ciblé (œuvres, artistes, expositions, promotions).
 * L'envoi des messages se pilote depuis le tableau de bord OneSignal.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_OneSignal {

	/** Réglage portant la liste des centres d'intérêt. */
	const CATEGORIES_KEY = 'os_categories';

	/** Version du SDK web de OneSignal. */
	const SDK_VERSION = 'v16';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_shortcode( 'africart_notifications', array( $this, 'shortcode' ) );
		add_action( 'wp_footer', array( $this, 'print_popup' ) );
	}

	/* ------------------------------------------------------------------
	 * État
	 * ---------------------------------------------------------------- */

	/**
	 * Les notifications sont-elles utilisables ?
	 *
	 * Sans identifiant d'application, il n'y a rien à charger : mieux
	 * vaut ne rien émettre que d'appeler un SDK qui échouera.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '1' === Africart_PWA_Settings::get( 'os_enabled' )
			&& '' !== trim( (string) Africart_PWA_Settings::get( 'os_app_id' ) )
			&& '1' === Africart_PWA_Settings::get( 'sw_enabled' );
	}

	/**
	 * Identifiant d'application OneSignal.
	 *
	 * @return string
	 */
	public static function app_id() {
		return trim( (string) Africart_PWA_Settings::get( 'os_app_id' ) );
	}

	/**
	 * Centres d'intérêt configurés.
	 *
	 * @return array<int,array{slug:string,label:string}>
	 */
	public static function get_categories() {
		$raw = Africart_PWA_Settings::get( self::CATEGORIES_KEY );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$categories = array();
		foreach ( $raw as $row ) {
			if ( empty( $row['slug'] ) || empty( $row['label'] ) ) {
				continue;
			}
			$categories[] = array(
				'slug'  => (string) $row['slug'],
				'label' => (string) $row['label'],
			);
		}
		return $categories;
	}

	/* ------------------------------------------------------------------
	 * Chargement côté visiteur
	 * ---------------------------------------------------------------- */

	/**
	 * Charge le SDK et l'initialise sur notre service worker.
	 */
	public function enqueue() {
		if ( is_admin() || ! self::is_enabled() ) {
			return;
		}

		// Même feuille que le bandeau d'installation : WordPress ne la
		// charge qu'une fois, et le bloc de préférences reste stylé même
		// si le bandeau est désactivé.
		wp_enqueue_style(
			'afk-pwa-install',
			AFK_PWA_URL . 'assets/css/install.css',
			array(),
			afk_pwa_asset_version( 'assets/css/install.css' )
		);

		wp_enqueue_script(
			'onesignal-sdk',
			sprintf( 'https://cdn.onesignal.com/sdks/web/%s/OneSignalSDK.page.js', self::SDK_VERSION ),
			array(),
			null, // Version gérée par OneSignal.
			array( 'strategy' => 'defer' )
		);

		wp_enqueue_script(
			'afk-pwa-onesignal',
			AFK_PWA_URL . 'assets/js/onesignal.js',
			array( 'onesignal-sdk', 'afk-pwa-mesure' ),
			afk_pwa_asset_version( 'assets/js/onesignal.js' ),
			true
		);

		wp_add_inline_script(
			'afk-pwa-onesignal',
			'window.AFK_PWA_ONESIGNAL = ' . wp_json_encode( $this->get_config() ) . ';',
			'before'
		);
	}

	/**
	 * Configuration transmise au navigateur.
	 *
	 * @return array
	 */
	private function get_config() {
		$lang = Africart_PWA_I18n::get_current_lang();

		return array(
			'appId'      => self::app_id(),
			'safariId'   => trim( (string) Africart_PWA_Settings::get( 'os_safari_id' ) ),
			// Notre service worker, déjà enregistré à la racine : OneSignal
			// s'y greffe au lieu d'en installer un second.
			'swPath'     => AFK_PWA_SW_SLUG,
			'swScope'    => '/',
			'categories' => self::get_categories(),
			// Les centres d'interet sont ecrits par WordPress, pas par le
			// SDK — voir class-push-tags.php.
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'afk_push_tags' ),
			'logged'     => is_user_logged_in(),
			// Fenêtre d'invitation : quand la montrer, et combien de temps
			// la taire après un refus.
			'popup'      => '1' === Africart_PWA_Settings::get( 'os_popup' ),
			'popupWhere' => Africart_PWA_Settings::get( 'os_popup_context' ),
			'bell'       => '1' === Africart_PWA_Settings::get( 'os_bell' ),
			'popupDelay' => (int) Africart_PWA_Settings::get( 'os_popup_delay' ),
			'popupDays'  => (int) Africart_PWA_Settings::get( 'os_popup_days' ),
			'lang'       => $lang,
			'texts'      => array(
				'title'   => Africart_PWA_I18n::text( 'push_title', $lang ),
				'choose'  => Africart_PWA_I18n::text( 'push_choose', $lang ),
				'unsubOk' => __( 'Vous ne recevrez plus de notifications.', 'africart-pwa' ),
				'manage'  => Africart_PWA_I18n::text( 'push_manage', $lang ),
				'save'    => __( 'Enregistrer mes choix', 'africart-pwa' ),
				'prompt'  => Africart_PWA_I18n::text( 'push_prompt', $lang ),
				'accept'  => Africart_PWA_I18n::text( 'push_accept', $lang ),
				'refuse'  => Africart_PWA_I18n::text( 'push_refuse', $lang ),
				'saved'   => __( 'Préférences enregistrées.', 'africart-pwa' ),
				'blocked' => __( 'Les notifications sont bloquées dans les réglages de votre navigateur.', 'africart-pwa' ),
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Choix des centres d'intérêt
	 * ---------------------------------------------------------------- */

	/**
	 * Fenêtre d'invitation à s'abonner.
	 *
	 * OneSignal propose ses propres fenêtres — nous n'en utilisons
	 * aucune. Elles s'habillent mal, se traduisent à moitié, et
	 * imposent leur moment. Le SDK offre tout ce qu'il faut pour faire
	 * mieux : `requestPermission()` déclenche l'invite du navigateur
	 * depuis NOTRE bouton, `addTags()` pose les centres d'intérêt.
	 *
	 * Elle ne s'affiche que dans l'application installée (réglable) :
	 * on n'ajoute pas une fenêtre de plus à un navigateur qui en voit
	 * déjà beaucoup, et on ne sollicite que des visiteurs déjà engagés,
	 * ceux qui ont fait la démarche d'installer. Sur iPhone, c'est de
	 * toute façon le seul contexte où les notifications web existent.
	 *
	 * Les centres d'intérêt sont **repliés par défaut**. Un visiteur qui
	 * accepte sans les déplier reçoit tout — c'est ce qu'il a demandé —
	 * et le script leur affecte alors toutes les étiquettes, sans quoi
	 * un envoi ciblé plus tard ne l'atteindrait jamais.
	 */
	public function print_popup() {
		// La fenêtre sort même quand l'invitation est désactivée : la
		// cloche s'en sert pour la gestion, et un abonné doit toujours
		// pouvoir se désabonner.
		if ( ! self::is_enabled() ) {
			return;
		}

		$categories = self::get_categories();
		$lang       = Africart_PWA_I18n::get_current_lang();
		?>
		<?php
		// La cloche : discrète, et réservée aux abonnés. C'est le seul
		// endroit où l'on peut retirer son consentement sans dépendre
		// d'une page — le modèle du thème n'affichant pas toujours le
		// contenu, un code court ne suffisait pas.
		?>
		<button type="button" class="afk-push-cloche" id="afk-push-cloche" hidden
			aria-label="<?php esc_attr_e( 'Gérer mes notifications', 'africart-pwa' ); ?>"
			title="<?php esc_attr_e( 'Gérer mes notifications', 'africart-pwa' ); ?>">
			<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
				<path d="M10 2a5 5 0 0 0-5 5v3.6L3.6 13a.8.8 0 0 0 .7 1.2h11.4a.8.8 0 0 0 .7-1.2L15 10.6V7a5 5 0 0 0-5-5zm0 15.5a2.2 2.2 0 0 0 2.1-1.6H7.9a2.2 2.2 0 0 0 2.1 1.6z"/>
			</svg>
		</button>

		<div class="afk-push-modal" id="afk-push-modal" role="dialog" aria-modal="true"
			aria-labelledby="afk-push-modal-titre" hidden>
			<div class="afk-push-modal-fond" data-afk-push-close></div>
			<div class="afk-push-modal-boite">
				<button type="button" class="afk-push-modal-fermer" data-afk-push-close
					aria-label="<?php esc_attr_e( 'Fermer', 'africart-pwa' ); ?>">&times;</button>

				<img class="afk-push-modal-icone" src="<?php echo esc_url( Africart_PWA_Settings::get( 'icon_192' ) ); ?>" alt="" />

				<strong class="afk-push-modal-titre" id="afk-push-modal-titre">
					<?php echo esc_html( Africart_PWA_I18n::text( 'push_title', $lang ) ); ?>
				</strong>
				<p class="afk-push-modal-intro">
					<?php echo esc_html( Africart_PWA_I18n::text( 'push_prompt', $lang ) ); ?>
				</p>

				<?php if ( $categories ) : ?>
					<div class="afk-push-choix">
						<button type="button" class="afk-push-deplier" aria-expanded="false" aria-controls="afk-push-liste">
							<span class="afk-push-deplier-texte"><?php echo esc_html( Africart_PWA_I18n::text( 'push_choose', $lang ) ); ?></span>
							<span class="afk-push-chevron" aria-hidden="true"></span>
						</button>

						<div class="afk-push-liste" id="afk-push-liste" hidden>
							<?php foreach ( $categories as $category ) : ?>
								<label class="afk-push-item">
									<input type="checkbox" class="afk-push-tag" value="<?php echo esc_attr( $category['slug'] ); ?>" checked />
									<span><?php echo esc_html( $category['label'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<button type="button" class="afk-push-modal-oui" data-afk-push-accept>
					<?php echo esc_html( Africart_PWA_I18n::text( 'push_accept', $lang ) ); ?>
				</button>
				<button type="button" class="afk-push-modal-non" data-afk-push-close>
					<?php echo esc_html( Africart_PWA_I18n::text( 'push_refuse', $lang ) ); ?>
				</button>

				<?php // Mode gestion seulement : retirer son consentement. ?>
				<button type="button" class="afk-push-modal-desabo" data-afk-push-unsub hidden>
					<?php echo esc_html( Africart_PWA_I18n::text( 'push_unsub', $lang ) ); ?>
				</button>

				<?php
				// Le visiteur doit savoir A QUOI il consent AVANT de
				// consentir : le lien précède l'acceptation, il ne la suit
				// pas. Absent si aucune page n'est déclarée — un lien mort
				// vaudrait moins que rien.
				$politique = Africart_PWA_Privacy::policy_url();
				if ( $politique ) :
					?>
					<a class="afk-push-modal-politique" href="<?php echo esc_url( $politique ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( Africart_PWA_I18n::text( 'push_privacy', $lang ) ); ?>
					</a>
				<?php endif; ?>

				<p class="afk-push-modal-etat" role="status" hidden></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Shortcode [africart_notifications] — cases à cocher permettant au
	 * visiteur de choisir ce qu'il souhaite recevoir.
	 *
	 * Chaque case correspond à une étiquette OneSignal, sur laquelle le
	 * tableau de bord peut segmenter ses envois.
	 *
	 * @param array $atts Attributs du shortcode.
	 * @return string
	 */
	public function shortcode( $atts ) {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$categories = self::get_categories();
		if ( ! $categories ) {
			return '';
		}

		$atts = shortcode_atts( array( 'title' => '' ), $atts, 'africart_notifications' );
		$lang = Africart_PWA_I18n::get_current_lang();

		ob_start();
		?>
		<div class="afk-push" hidden>
			<?php if ( $atts['title'] ) : ?>
				<strong class="afk-push-title"><?php echo esc_html( $atts['title'] ); ?></strong>
			<?php endif; ?>

			<p class="afk-push-intro"><?php echo esc_html( Africart_PWA_I18n::text( 'push_prompt', $lang ) ); ?></p>

			<div class="afk-push-list">
				<?php foreach ( $categories as $category ) : ?>
					<label class="afk-push-item">
						<input type="checkbox" class="afk-push-tag" value="<?php echo esc_attr( $category['slug'] ); ?>" />
						<span><?php echo esc_html( $category['label'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<button type="button" class="afk-push-save"><?php echo esc_html( Africart_PWA_I18n::text( 'push_accept', $lang ) ); ?></button>

			<?php
			// Retirer son consentement doit être aussi simple que de le
			// donner — le règlement l'exige, et c'est de toute façon la
			// moindre des politesses. Le bouton n'apparaît qu'à qui est
			// effectivement abonné.
			?>
			<button type="button" class="afk-push-unsub" hidden><?php echo esc_html( Africart_PWA_I18n::text( 'push_unsub', $lang ) ); ?></button>

			<p class="afk-push-feedback" role="status" hidden></p>

			<?php $politique = Africart_PWA_Privacy::policy_url(); ?>
			<?php if ( $politique ) : ?>
				<p class="afk-push-politique">
					<a href="<?php echo esc_url( $politique ); ?>" target="_blank" rel="noopener">
						<?php echo esc_html( Africart_PWA_I18n::text( 'push_privacy', $lang ) ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Nettoyage des réglages
	 * ---------------------------------------------------------------- */

	/**
	 * Nettoie la liste des centres d'intérêt soumise depuis l'admin.
	 *
	 * @param mixed $input Valeur brute.
	 * @return array
	 */
	public static function sanitize_categories( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$clean = array();
		foreach ( $input as $row ) {
			$slug  = isset( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';

			if ( '' === $slug || '' === $label ) {
				continue;
			}

			$clean[] = array(
				'slug'  => $slug,
				'label' => $label,
			);
		}

		return $clean;
	}
}
