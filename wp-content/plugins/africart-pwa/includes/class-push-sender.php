<?php
/**
 * Envoi automatique des notifications depuis WordPress.
 *
 * Le socle des notifications permet à un visiteur de s'abonner. Ce
 * module fait l'inverse : il déclenche l'envoi quand un contenu est
 * publié, sans passer par le tableau de bord OneSignal.
 *
 * Quatre besoins ont été exprimés : nouvelles œuvres, nouveaux
 * artistes, expositions, promotions et événements. Seuls les deux
 * premiers correspondent aujourd'hui à un type de contenu du site — les
 * deux autres restent à créer. Les déclencheurs sont donc **définis
 * dans les réglages** plutôt que codés en dur : le jour où un type
 * « exposition » existera, il suffira d'ajouter une ligne.
 *
 * ⚠️ **La clé d'envoi est secrète.** Elle autorise l'envoi à tous les
 * abonnés. Elle est conservée dans une option distincte, jamais
 * chargée automatiquement, jamais renvoyée à l'écran ni transmise au
 * navigateur — l'administration n'affiche que son état.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Push_Sender {

	/** Option portant la clé d'envoi, isolée du reste des réglages. */
	const KEY_OPTION = 'afk_pwa_push_key';

	/**
	 * Constante de wp-config.php, prioritaire sur la base.
	 *
	 * C'est l'emplacement recommandé. Une clé rangée en base **voyage
	 * avec la base** : constaté ici, elle s'est retrouvée sur une
	 * préproduction lors d'une copie de production, puis exposée dans
	 * la page « Tous les réglages » qui affiche tout en clair.
	 *
	 * Déclarée dans wp-config.php, elle reste sur son serveur : absente
	 * des sauvegardes de base, des copies vers un environnement de test,
	 * et de tout écran de l'administration.
	 */
	const KEY_CONSTANT = 'AFK_PWA_PUSH_KEY';

	/** Réglage portant la liste des déclencheurs. */
	const TRIGGERS_KEY = 'os_triggers';

	/** Marqueur posé sur un contenu déjà notifié. */
	const SENT_META = '_afk_push_sent';

	/** Case permettant à la rédaction de ne pas notifier un contenu. */
	const SKIP_META = '_afk_push_skip';

	/** Point d'entrée de l'API OneSignal. */
	const ENDPOINT = 'https://onesignal.com/api/v1/notifications';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Au passage en « publié », et à ce moment seulement : une simple
		// modification ne doit jamais renotifier.
		add_action( 'transition_post_status', array( $this, 'on_publish' ), 20, 3 );

		// Case « ne pas notifier », dans l'écran d'édition.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );

		// Bouton de test des réglages.
		add_action( 'wp_ajax_afk_push_test', array( $this, 'ajax_test' ) );
	}

	/**
	 * Envoie une notification de test et rend compte du résultat.
	 *
	 * C'est le seul moyen de vérifier la clé : elle ne se valide pas
	 * autrement qu'en s'en servant.
	 */
	public function ajax_test() {
		check_ajax_referer( 'afk_push_test', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'africart-pwa' ) ), 403 );
		}

		$texte = __( 'Test de notification depuis le site.', 'africart-pwa' );

		$resultat = self::send(
			array(
				'headings' => array( 'en' => 'AFRICART' ),
				'contents' => array( 'en' => $texte ),
				'url'      => home_url( '/' ),
				'category' => '', // Sans ciblage : le test doit partir quoi qu'il arrive.
			)
		);

		if ( is_wp_error( $resultat ) ) {
			wp_send_json_error( array( 'message' => $resultat->get_error_message() ) );
		}

		$destinataires = isset( $resultat['recipients'] ) ? (int) $resultat['recipients'] : 0;

		if ( 0 === $destinataires ) {
			wp_send_json_success(
				array( 'message' => __( 'Clé acceptée, mais aucun abonné à prévenir pour l’instant.', 'africart-pwa' ) )
			);
		}

		wp_send_json_success(
			array(
				/* translators: %d nombre d'abonnés */
				'message' => sprintf( _n( 'Envoyée à %d abonné.', 'Envoyée à %d abonnés.', $destinataires, 'africart-pwa' ), $destinataires ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Clé d'envoi
	 * ---------------------------------------------------------------- */

	/**
	 * Clé d'envoi en vigueur.
	 *
	 * La constante l'emporte sur la base — même règle que WP_HOME et
	 * consorts : ce qui est déclaré dans wp-config.php fait autorité.
	 *
	 * @return string
	 */
	public static function get_key() {
		if ( self::key_is_pinned() ) {
			return (string) constant( self::KEY_CONSTANT );
		}

		return (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * La clé est-elle déclarée dans wp-config.php ?
	 *
	 * Dans ce cas l'administration n'en propose pas la saisie : elle
	 * n'aurait aucun effet, et laisser croire le contraire serait pire
	 * que ne rien afficher.
	 *
	 * @return bool
	 */
	public static function key_is_pinned() {
		return defined( self::KEY_CONSTANT ) && '' !== trim( (string) constant( self::KEY_CONSTANT ) );
	}

	/**
	 * Une clé dort-elle encore en base ?
	 *
	 * Les versions antérieures proposaient un champ dans l'administration.
	 * Ce champ a disparu — une clé rangée en base suit les sauvegardes et
	 * les copies vers un environnement de test — mais la valeur, elle,
	 * peut subsister. L'écran la signale et propose de l'effacer.
	 *
	 * @return bool
	 */
	public static function has_legacy_key() {
		return '' !== (string) get_option( self::KEY_OPTION, '' );
	}

	/**
	 * Retire la clé héritée de la base.
	 *
	 * Aucune écriture en sens inverse : la clé ne s'enregistre plus que
	 * dans wp-config.php.
	 */
	public static function forget_legacy_key() {
		delete_option( self::KEY_OPTION );
	}

	/**
	 * L'envoi depuis WordPress est-il possible ?
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return Africart_PWA_OneSignal::is_enabled() && '' !== self::get_key();
	}

	/* ------------------------------------------------------------------
	 * Déclencheurs
	 * ---------------------------------------------------------------- */

	/**
	 * Déclencheurs configurés.
	 *
	 * @return array<int,array{post_type:string,category:string,title:string}>
	 */
	public static function get_triggers() {
		$brut = Africart_PWA_Settings::get( self::TRIGGERS_KEY );
		if ( ! is_array( $brut ) ) {
			return array();
		}

		$liste = array();
		foreach ( $brut as $ligne ) {
			// Le centre d'intérêt est FACULTATIF : sans lui, la notification
			// part à tous les abonnés. C'est même le cas le plus courant —
			// un visiteur qui accepte l'invite native du navigateur n'a
			// choisi aucun centre d'intérêt, et n'en portera donc aucune
			// étiquette. Exiger un ciblage revenait à n'envoyer à personne.
			if ( empty( $ligne['post_type'] ) ) {
				continue;
			}
			$liste[] = array(
				'post_type' => (string) $ligne['post_type'],
				'category'  => (string) $ligne['category'],
				'title'     => isset( $ligne['title'] ) ? (string) $ligne['title'] : '',
			);
		}
		return $liste;
	}

	/**
	 * Déclencheur correspondant à un type de contenu.
	 *
	 * @param string $post_type Type de contenu.
	 * @return array|null
	 */
	private function trigger_for( $post_type ) {
		foreach ( self::get_triggers() as $declencheur ) {
			if ( $declencheur['post_type'] === $post_type ) {
				return $declencheur;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------
	 * Déclenchement à la publication
	 * ---------------------------------------------------------------- */

	/**
	 * Notifie au passage en « publié ».
	 *
	 * @param string  $nouveau Nouveau statut.
	 * @param string  $ancien  Statut précédent.
	 * @param WP_Post $post    Contenu concerné.
	 */
	public function on_publish( $nouveau, $ancien, $post ) {
		if ( 'publish' !== $nouveau || 'publish' === $ancien ) {
			return; // Publication seulement, pas une simple modification.
		}
		if ( ! self::is_ready() || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, self::SENT_META, true ) ) {
			return; // Déjà notifié : on ne renotifie jamais.
		}
		if ( get_post_meta( $post->ID, self::SKIP_META, true ) ) {
			return; // La rédaction a demandé de ne pas notifier.
		}

		$declencheur = $this->trigger_for( $post->post_type );
		if ( ! $declencheur ) {
			return;
		}

		$resultat = self::send_for_post( $post, $declencheur );

		// Trois issues distinctes, et la troisième compte : un envoi peut
		// « réussir » sans atteindre personne — ciblage sur un centre
		// d'intérêt que nul abonné ne porte. Le confondre avec un succès
		// laisserait croire la notification partie.
		if ( is_wp_error( $resultat ) ) {
			$etat = 'echec';
		} elseif ( empty( $resultat['recipients'] ) && ! isset( $resultat['id'] ) ) {
			$etat = 'sans-destinataire';
		} else {
			$etat = 'ok';
		}

		// Le marqueur est posé quelle que soit l'issue : sans cela, la
		// moindre modification ultérieure relancerait un envoi.
		update_post_meta( $post->ID, self::SENT_META, $etat );
	}

	/* ------------------------------------------------------------------
	 * Envoi
	 * ---------------------------------------------------------------- */

	/**
	 * Envoie la notification correspondant à un contenu.
	 *
	 * @param WP_Post $post        Contenu concerné.
	 * @param array   $declencheur Déclencheur retenu.
	 * @return array|WP_Error Réponse de l'API, ou erreur.
	 */
	public static function send_for_post( $post, $declencheur ) {
		// Relecture depuis la base avant de construire le lien.
		//
		// ⚠️ L'objet transmis par `transition_post_status` n'est pas
		// toujours à jour : publié depuis un brouillon sans titre, il
		// porte encore un `post_name` vide, et `get_permalink()` rend
		// alors la forme de repli `?post_type=oeuvre&p=123` au lieu de
		// l'adresse définitive de la fiche. Le lien fonctionne, mais il
		// est laid et ne correspond pas à celui que le site publie
		// partout ailleurs.
		$frais = get_post( $post->ID );
		$post  = $frais ? $frais : $post;

		$titre = $declencheur['title'] ? $declencheur['title'] : __( 'Nouveauté sur AFRICART', 'africart-pwa' );

		// Le titre du contenu forme le corps du message : c'est
		// l'information utile, le reste n'est qu'un intitulé.
		$contenus = array();
		foreach ( Africart_PWA_I18n::get_languages() as $langue ) {
			$contenus[ $langue ] = wp_strip_all_tags( get_the_title( $post ) );
		}

		$entetes = array();
		foreach ( Africart_PWA_I18n::get_languages() as $langue ) {
			$entetes[ $langue ] = $titre;
		}

		return self::send(
			array(
				'headings'   => $entetes,
				'contents'   => $contenus,
				// La fiche du contenu, jamais l'accueil : on notifie une
				// nouveauté précise, la montrer coûte un clic de plus si le
				// lien n'y mène pas directement.
				//
				// Aucun contrôle d'accès ici, délibérément. Savoir si le
				// visiteur a le droit de voir cette fiche est l'affaire du
				// thème, qui décide au moment de l'affichage — le refaire
				// à l'envoi le dédoublerait, avec le risque de diverger.
				'url'        => get_permalink( $post ),
				'big_picture' => self::picture_for( $post ),
				'category'   => $declencheur['category'],
			)
		);
	}

	/**
	 * Visuel accompagnant la notification.
	 *
	 * On réutilise l'image d'aperçu produite pour les réseaux sociaux :
	 * elle est légère et au bon format, exactement ce qu'il faut ici.
	 *
	 * @param WP_Post $post Contenu concerné.
	 * @return string URL, ou chaîne vide.
	 */
	private static function picture_for( $post ) {
		$attachment_id = get_post_thumbnail_id( $post );
		if ( ! $attachment_id ) {
			return '';
		}
		$image = wp_get_attachment_image_src( $attachment_id, Africart_PWA_Share_Image::SIZE );
		if ( $image ) {
			return $image[0];
		}
		return (string) wp_get_attachment_url( $attachment_id );
	}

	/**
	 * Transmet une notification à OneSignal.
	 *
	 * @param array $message {
	 *     @type array  $headings  Intitulé par langue.
	 *     @type array  $contents  Message par langue.
	 *     @type string $url       Adresse ouverte au clic.
	 *     @type string $big_picture Visuel, facultatif.
	 *     @type string $category  Centre d'intérêt ciblé, ou '' pour tous.
	 * }
	 * @return array|WP_Error Réponse décodée, ou erreur explicite.
	 */
	public static function send( $message ) {
		$cle = self::get_key();
		if ( '' === $cle ) {
			return new WP_Error( 'no_key', __( 'Aucune clé d’envoi enregistrée.', 'africart-pwa' ) );
		}

		$corps = array(
			'app_id'    => Africart_PWA_OneSignal::app_id(),
			'headings'  => $message['headings'],
			'contents'  => $message['contents'],
		);

		if ( ! empty( $message['url'] ) ) {
			$corps['url'] = $message['url'];
		}
		if ( ! empty( $message['big_picture'] ) ) {
			// Android affiche le visuel en grand ; les autres l'ignorent.
			$corps['big_picture'] = $message['big_picture'];
			$corps['chrome_web_image'] = $message['big_picture'];
		}

		$filtres = ! empty( $message['category'] )
			? Africart_PWA_Push_Tags::filters_for( $message['category'] )
			: null;

		if ( $filtres ) {
			// Ciblage par centre d'intérêt — voir class-push-tags.php pour
			// la formulation exacte, qui n'est pas celle qu'on attendrait.
			$corps['filters'] = $filtres;
		} else {
			// Sans ciblage : tous les abonnés.
			//
			// ⚠️ Le nom du segment par défaut a changé chez OneSignal. Les
			// applications récentes n'ont plus « Subscribed Users » mais
			// « Total Subscriptions » — et viser un segment inexistant ne
			// produit aucune erreur, seulement zéro destinataire. Le
			// symptôme est trompeur : les notifications partent très bien
			// depuis le tableau de bord, mais jamais depuis le site.
			//
			// On envoie donc les deux noms : OneSignal retient celui qui
			// existe et ignore l'autre, sans doublon pour l'abonné.
			$corps['included_segments'] = array( 'Total Subscriptions', 'Subscribed Users' );
		}

		$reponse = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					// Les clés récentes se présentent avec « Key », les
					// anciennes avec « Basic ». On tente la forme récente
					// puis on retombe sur l'autre si elle est refusée.
					'Authorization' => 'Key ' . $cle,
				),
				'body'    => wp_json_encode( $corps ),
			)
		);

		$resultat = self::interpret( $reponse );

		if ( is_wp_error( $resultat ) && 'unauthorized' === $resultat->get_error_code() ) {
			$reponse = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => 20,
					'headers' => array(
						'Content-Type'  => 'application/json; charset=utf-8',
						'Authorization' => 'Basic ' . $cle,
					),
					'body'    => wp_json_encode( $corps ),
				)
			);
			$resultat = self::interpret( $reponse );
		}

		return $resultat;
	}

	/**
	 * Traduit la réponse de l'API en résultat exploitable.
	 *
	 * @param array|WP_Error $reponse Réponse HTTP.
	 * @return array|WP_Error
	 */
	private static function interpret( $reponse ) {
		if ( is_wp_error( $reponse ) ) {
			return $reponse;
		}

		$code    = wp_remote_retrieve_response_code( $reponse );
		$decodee = json_decode( wp_remote_retrieve_body( $reponse ), true );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'unauthorized', __( 'Clé d’envoi refusée par OneSignal.', 'africart-pwa' ) );
		}

		if ( ! empty( $decodee['errors'] ) ) {
			$erreurs = is_array( $decodee['errors'] ) ? $decodee['errors'] : array( $decodee['errors'] );
			// « All included players are not subscribed » signifie que
			// personne ne correspond au ciblage : ce n'est pas une panne.
			$texte = implode( ' ', array_map( 'strval', $erreurs ) );
			if ( false !== stripos( $texte, 'not subscribed' ) ) {
				return array( 'recipients' => 0, 'message' => $texte );
			}
			return new WP_Error( 'onesignal', $texte );
		}

		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d code HTTP */
			return new WP_Error( 'http', sprintf( __( 'OneSignal a répondu %d.', 'africart-pwa' ), $code ) );
		}

		return is_array( $decodee ) ? $decodee : array();
	}

	/* ------------------------------------------------------------------
	 * Case « ne pas notifier », dans l'écran d'édition
	 * ---------------------------------------------------------------- */

	/**
	 * Ajoute la case aux types de contenu concernés par un déclencheur.
	 */
	public function add_meta_box() {
		foreach ( self::get_triggers() as $declencheur ) {
			add_meta_box(
				'afk-push',
				__( 'Notification AFRICART', 'africart-pwa' ),
				array( $this, 'render_meta_box' ),
				$declencheur['post_type'],
				'side',
				'default'
			);
		}
	}

	/**
	 * Affiche la case.
	 *
	 * @param WP_Post $post Contenu en cours d'édition.
	 */
	public function render_meta_box( $post ) {
		$deja  = get_post_meta( $post->ID, self::SENT_META, true );
		$passe = get_post_meta( $post->ID, self::SKIP_META, true );

		wp_nonce_field( 'afk_push_meta', 'afk_push_nonce' );

		if ( $deja ) {
			if ( 'ok' === $deja ) {
				$message = __( 'Notification déjà envoyée pour ce contenu.', 'africart-pwa' );
			} elseif ( 'sans-destinataire' === $deja ) {
				$message = __( 'Notification partie, mais aucun abonné ne correspondait au centre d’intérêt visé.', 'africart-pwa' );
			} else {
				$message = __( 'Une notification a été tentée sans succès pour ce contenu.', 'africart-pwa' );
			}
			printf( '<p>%s</p>', esc_html( $message ) );
			return;
		}

		printf(
			'<label><input type="checkbox" name="afk_push_skip" value="1" %s /> %s</label>',
			checked( $passe, '1', false ),
			esc_html__( 'Ne pas notifier à la publication', 'africart-pwa' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Les abonnés au centre d’intérêt correspondant sont prévenus une seule fois, au moment de la publication.', 'africart-pwa' )
		);
	}

	/**
	 * Enregistre la case.
	 *
	 * @param int $post_id Contenu enregistré.
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['afk_push_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['afk_push_nonce'] ) ), 'afk_push_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['afk_push_skip'] ) ) {
			delete_post_meta( $post_id, self::SKIP_META );
		} else {
			update_post_meta( $post_id, self::SKIP_META, '1' );
		}
	}

	/* ------------------------------------------------------------------
	 * Nettoyage des réglages
	 * ---------------------------------------------------------------- */

	/**
	 * Nettoie la liste des déclencheurs soumise depuis l'admin.
	 *
	 * @param mixed $entree Valeur brute.
	 * @return array
	 */
	public static function sanitize_triggers( $entree ) {
		if ( ! is_array( $entree ) ) {
			return array();
		}

		$propre = array();
		foreach ( $entree as $ligne ) {
			// Écarte le témoin de présence du formulaire, qui n'est pas une
			// ligne mais une simple valeur.
			if ( ! is_array( $ligne ) ) {
				continue;
			}

			$post_type = isset( $ligne['post_type'] ) ? sanitize_key( $ligne['post_type'] ) : '';
			$category  = isset( $ligne['category'] ) ? sanitize_key( $ligne['category'] ) : '';

			if ( '' === $post_type || '' === $category ) {
				continue;
			}

			$propre[] = array(
				'post_type' => $post_type,
				'category'  => $category,
				'title'     => isset( $ligne['title'] ) ? sanitize_text_field( $ligne['title'] ) : '',
			);
		}
		return $propre;
	}
}
