<?php
/**
 * Mesure d'audience — branchement à Google Tag Manager.
 *
 * Le plugin ne mesure rien pour son compte et n'envoie rien à Google
 * directement. Il **annonce des faits** dans le `dataLayer` — bandeau
 * affiché, installation faite, abonnement pris — et laisse le
 * gestionnaire de balises décider ce qu'il en fait. C'est le seul
 * découpage qui permette de changer d'outil de mesure sans toucher au
 * site.
 *
 * ⚠️ Le piège qui gouverne ce fichier : **gtag.js ne lit pas les
 * événements de GTM, et GTM ne lit pas les appels gtag**. Les deux
 * partagent le tableau `dataLayer` mais pas son contenu — gtag y écrit
 * des arguments positionnels, GTM y attend des objets nommés. Émettre
 * la mauvaise forme ne produit aucune erreur : l'événement disparaît,
 * simplement.
 *
 * D'où la détection à l'exécution, côté navigateur : un conteneur GTM
 * présent reçoit des objets, sinon on retombe sur gtag. Rien ne
 * s'interrompt pendant la bascule de l'un à l'autre.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Analytics {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 5 );

		// ⚠️ L'état par défaut du consentement doit précéder TOUTE balise,
		// la nôtre comme celles des autres extensions. Priorité 0, avant
		// le conteneur : posé après, il arriverait trop tard et les
		// balises auraient déjà mesuré.
		add_action( 'wp_head', array( $this, 'print_consent_default' ), 0 );

		// Le conteneur, si le plugin en a la charge. Priorité haute dans
		// l'en-tête : GTM doit exister avant les scripts qui l'alimentent.
		add_action( 'wp_head', array( $this, 'print_container' ), 1 );

		// Le bandeau lui-même, en pied de page : il n'a pas à retarder
		// l'affichage de la page.
		add_action( 'wp_footer', array( $this, 'print_consent_banner' ), 20 );
		add_action( 'wp_body_open', array( $this, 'print_noscript' ), 1 );
	}

	/* ------------------------------------------------------------------
	 * État
	 * ---------------------------------------------------------------- */

	/** La mesure est-elle active ? */
	public static function is_enabled() {
		return '1' === Africart_PWA_Settings::get( 'stats_enabled' );
	}

	/**
	 * Identifiant du conteneur GTM, s'il est correctement formé.
	 *
	 * Un identifiant approximatif casserait la page sans rien dire :
	 * on ne pose le conteneur que si la forme est celle attendue.
	 *
	 * @return string `GTM-XXXXXX`, ou chaîne vide.
	 */
	public static function container_id() {
		$id = strtoupper( trim( (string) Africart_PWA_Settings::get( 'stats_gtm_id' ) ) );

		return preg_match( '/^GTM-[A-Z0-9]{4,}$/', $id ) ? $id : '';
	}

	/** Le plugin doit-il poser lui-même le conteneur ? */
	public static function should_inject() {
		return self::is_enabled()
			&& '1' === Africart_PWA_Settings::get( 'stats_inject' )
			&& '' !== self::container_id();
	}

	/**
	 * La mesure attend-elle un consentement explicite ?
	 *
	 * Vrai dès que le bandeau est affiché : demander l'accord puis
	 * mesurer sans l'attendre n'aurait aucun sens.
	 *
	 * @return bool
	 */
	public static function waits_consent() {
		return '1' === Africart_PWA_Settings::get( 'stats_consent' )
			|| self::shows_banner();
	}

	/** Le bandeau de consentement doit-il s'afficher ? */
	public static function shows_banner() {
		return '1' === Africart_PWA_Settings::get( 'consent_enabled' );
	}

	/* ------------------------------------------------------------------
	 * Consentement
	 * ---------------------------------------------------------------- */

	/**
	 * Déclare l'état par défaut du consentement.
	 *
	 * ⚠️ Ce bloc doit être le **premier** script de la page. Le mode
	 * consentement de Google fonctionne par antériorité : une balise
	 * chargée avant cette déclaration n'en tient aucun compte et mesure
	 * comme si l'accord était acquis. Le symptôme est indétectable à
	 * l'œil — le bandeau s'affiche, tout paraît correct, et la mesure
	 * est déjà partie.
	 *
	 * Le refus est l'état initial. Pas « en attente » : refusé.
	 */
	public function print_consent_default() {
		if ( is_admin() || ! self::shows_banner() ) {
			return;
		}
		?>
		<!-- AFRICART PWA · consentement par défaut -->
		<script>
		window.dataLayer = window.dataLayer || [];
		function gtag(){dataLayer.push(arguments);}
		gtag('consent', 'default', {
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied',
			analytics_storage: 'denied',
			wait_for_update: 500
		});
		</script>
		<?php
	}

	/**
	 * Le bandeau, masqué jusqu'à ce que le script décide de le montrer.
	 *
	 * Émis dans le balisage plutôt que construit en JavaScript : le
	 * texte est ainsi traduisible, lisible par les outils
	 * d'accessibilité, et présent même si le script tarde.
	 */
	public function print_consent_banner() {
		if ( is_admin() || ! self::shows_banner() ) {
			return;
		}

		$politique = class_exists( 'Africart_PWA_Privacy' ) ? Africart_PWA_Privacy::policy_url() : '';
		?>
		<div class="afk-consent" id="afk-consent" role="dialog" aria-live="polite"
			aria-label="<?php echo esc_attr( Africart_PWA_Settings::get( 'consent_title' ) ); ?>" hidden>
			<div class="afk-consent-texte">
				<p class="afk-consent-titre"><?php echo esc_html( Africart_PWA_Settings::get( 'consent_title' ) ); ?></p>
				<p class="afk-consent-message">
					<?php echo esc_html( Africart_PWA_Settings::get( 'consent_text' ) ); ?>
					<?php if ( $politique ) : ?>
						<a href="<?php echo esc_url( $politique ); ?>"><?php esc_html_e( 'En savoir plus', 'africart-pwa' ); ?></a>
					<?php endif; ?>
				</p>
			</div>
			<div class="afk-consent-boutons">
				<button type="button" class="afk-consent-btn afk-consent-non" data-afk-consent-non>
					<?php echo esc_html( Africart_PWA_Settings::get( 'consent_refuse' ) ); ?>
				</button>
				<button type="button" class="afk-consent-btn afk-consent-oui" data-afk-consent-oui>
					<?php echo esc_html( Africart_PWA_Settings::get( 'consent_accept' ) ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Conteneur
	 * ---------------------------------------------------------------- */

	/**
	 * Pose le conteneur GTM.
	 *
	 * ⚠️ À n'activer que si **aucune autre extension ne le pose déjà**.
	 * Deux conteneurs identiques sur une page comptent chaque visite
	 * deux fois, sans le moindre avertissement.
	 */
	public function print_container() {
		if ( is_admin() || ! self::should_inject() ) {
			return;
		}

		$id = self::container_id();
		?>
		<!-- AFRICART PWA · Google Tag Manager -->
		<script>
		(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
		var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
		j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
		})(window,document,'script','dataLayer','<?php echo esc_js( $id ); ?>');
		</script>
		<?php
	}

	/**
	 * Repli sans JavaScript.
	 *
	 * Il ne mesure presque rien d'utile, mais Google le réclame et son
	 * absence est relevée par les outils de vérification.
	 */
	public function print_noscript() {
		if ( is_admin() || ! self::should_inject() ) {
			return;
		}
		?>
		<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( self::container_id() ); ?>"
			height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Émetteur
	 * ---------------------------------------------------------------- */

	/**
	 * Charge l'émetteur partagé.
	 *
	 * Il est enregistré même quand la mesure est éteinte : les autres
	 * scripts l'appellent sans condition, et une fonction absente les
	 * ferait échouer là où ils devraient simplement ne rien mesurer.
	 */
	public function enqueue() {
		if ( is_admin() ) {
			return;
		}

		wp_register_script(
			'afk-pwa-mesure',
			AFK_PWA_URL . 'assets/js/mesure.js',
			array(),
			afk_pwa_asset_version( 'assets/js/mesure.js' ),
			false // Dans l'en-tête : l'origine sociale se mesure à l'arrivée.
		);

		wp_add_inline_script(
			'afk-pwa-mesure',
			'window.AFK_PWA_MESURE = ' . wp_json_encode( self::config() ) . ';',
			'before'
		);

		wp_enqueue_script( 'afk-pwa-mesure' );

		if ( ! self::shows_banner() ) {
			return;
		}

		wp_enqueue_script(
			'afk-pwa-consentement',
			AFK_PWA_URL . 'assets/js/consentement.js',
			array(),
			afk_pwa_asset_version( 'assets/js/consentement.js' ),
			true
		);

		wp_add_inline_script(
			'afk-pwa-consentement',
			'window.AFK_PWA_CONSENT = ' . wp_json_encode(
				array( 'jours' => max( 1, (int) Africart_PWA_Settings::get( 'consent_days' ) ) )
			) . ';',
			'before'
		);
	}

	/**
	 * Configuration transmise au navigateur.
	 *
	 * @return array
	 */
	public static function config() {
		return array(
			'actif'    => self::is_enabled(),
			'prefixe'  => 'afk_',
			// ⚠️ Le verrou du navigateur ne se ferme que si NOUS tenons la
			// clé — c'est-à-dire si notre bandeau est affiché.
			//
			// Le déduire de `stats_consent` seul était un piège : cocher
			// « consentement requis » sans activer le bandeau bloquait
			// toute mesure définitivement, puisque aucun visiteur n'avait
			// alors le moyen de répondre. Constaté en préproduction.
			//
			// Quand le consentement est recueilli ailleurs — extension
			// dédiée, bandeau du thème — c'est au gestionnaire de balises
			// de conditionner ses balises : lui seul sait ce qui a été
			// accordé.
			'consent'  => self::shows_banner(),
			// Utile au débogage : l'administration affiche ce que le
			// navigateur a réellement reçu.
			'gtm'      => self::container_id(),
		);
	}

	/**
	 * Événements émis par le plugin, pour l'écran de réglages.
	 *
	 * Cette liste **est** la documentation : elle sert à construire les
	 * déclencheurs dans le gestionnaire de balises, et il n'existe pas
	 * d'autre endroit où la lire.
	 *
	 * @return array<string,array{quoi:string,params:string}>
	 */
	public static function events() {
		return array(
			'afk_install_prompt'    => array(
				'quoi'   => __( 'Le bandeau d’installation s’affiche', 'africart-pwa' ),
				'params' => 'contexte',
			),
			'afk_install_click'     => array(
				'quoi'   => __( 'Clic sur « Installer »', 'africart-pwa' ),
				'params' => 'contexte',
			),
			'afk_install_done'      => array(
				'quoi'   => __( 'Installation confirmée par le système', 'africart-pwa' ),
				'params' => 'contexte',
			),
			'afk_app_launch'        => array(
				'quoi'   => __( 'Ouverture depuis l’icône, hors navigateur', 'africart-pwa' ),
				'params' => '—',
			),
			'afk_source_social'     => array(
				'quoi'   => __( 'Arrivée depuis un réseau social', 'africart-pwa' ),
				'params' => 'reseau',
			),
			'afk_push_prompt'       => array(
				'quoi'   => __( 'La fenêtre d’abonnement s’ouvre', 'africart-pwa' ),
				'params' => 'mode',
			),
			'afk_push_subscribe'    => array(
				'quoi'   => __( 'Abonnement accepté', 'africart-pwa' ),
				'params' => 'centres',
			),
			'afk_push_refused'      => array(
				'quoi'   => __( 'Autorisation refusée par le navigateur', 'africart-pwa' ),
				'params' => '—',
			),
			'afk_push_unsubscribe'  => array(
				'quoi'   => __( 'Désabonnement', 'africart-pwa' ),
				'params' => '—',
			),
		);
	}
}
