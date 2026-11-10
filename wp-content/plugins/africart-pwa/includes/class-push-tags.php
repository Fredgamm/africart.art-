<?php
/**
 * Écriture des centres d'intérêt chez OneSignal, depuis le serveur.
 *
 * ⚠️ Pourquoi ne pas laisser faire le SDK, alors qu'il expose
 * `User.addTags()` ?
 *
 * Parce qu'il ne le fait pas. Constaté sur le site : seize abonnements
 * enregistrés, **aucune étiquette** côté OneSignal — alors que
 * `getTags()` les rendait fidèlement dans le navigateur. Le SDK les
 * conserve dans son cache local et remet leur envoi à un cycle de
 * synchronisation qui, ici, n'aboutit jamais : abonnement tout juste
 * créé, page qui se ferme, script tiers différé.
 *
 * Le symptôme est le pire qui soit : **tout paraît fonctionner**. Les
 * cases se rouvrent cochées, la fenêtre confirme l'enregistrement, et
 * pourtant décocher un centre d'intérêt ne change rien aux
 * notifications reçues — le ciblage porte sur des étiquettes qui
 * n'existent pas.
 *
 * WordPress écrit donc lui-même, par l'API, avec la clé qui ne quitte
 * jamais le serveur. Le navigateur ne transmet que son identifiant
 * d'abonnement et les cases cochées.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Push_Tags {

	/** Fiche d'un abonnement. L'API « players » reste la plus directe. */
	const ENDPOINT = 'https://onesignal.com/api/v1/players/';

	/** Abonnements rattachés à un compte WordPress (méta utilisateur). */
	const USER_META = '_afk_push_subs';

	/**
	 * Nombre maximal d'étiquettes par abonné.
	 *
	 * ⚠️ Ce n'est pas une limite que nous nous imposons : c'est celle du
	 * forfait OneSignal. Le dépassement ne dégrade rien, il **rejette la
	 * requête entière** — 400 « App is limited to a maximum of 3 tags on
	 * a given player ». Le SDK avalait cette erreur sans rien dire : d'où
	 * seize abonnés sans la moindre étiquette, et un ciblage qui portait
	 * sur du vide alors que tout paraissait fonctionner.
	 *
	 * Une étiquette par centre d'intérêt reste le choix retenu, malgré la
	 * contrainte : c'est ce que l'interface OneSignal sait lire, et donc
	 * la seule forme qui permette d'y construire des segments à la main.
	 * Un encodage compact tiendrait plus de centres d'intérêt, au prix de
	 * valeurs illisibles dans le tableau de bord.
	 */
	const MAX_TAGS = 3;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Les visiteurs ne sont pas connectés : les deux crochets sont
		// nécessaires, sans quoi l'action n'existe que pour l'administration.
		add_action( 'wp_ajax_afk_push_tags', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_nopriv_afk_push_tags', array( $this, 'ajax_save' ) );

		// Rapprochement : un abonné connecté se fait connaître, pour que
		// l'administration puisse dire QUI est abonné, et pas seulement
		// combien. Sans compte, rien n'est enregistré — l'abonnement reste
		// anonyme, ce qui est le cas le plus fréquent et parfaitement
		// normal.
		add_action( 'wp_ajax_afk_push_link', array( $this, 'ajax_link' ) );
	}

	/* ------------------------------------------------------------------
	 * Rapprochement avec les comptes du site
	 * ---------------------------------------------------------------- */

	/**
	 * Rattache un abonnement au compte connecté.
	 *
	 * Aucun crochet `nopriv` : sans compte, il n'y a rien à rapprocher.
	 */
	public function ajax_link() {
		check_ajax_referer( 'afk_push_tags', 'nonce' );

		$abonnement = isset( $_POST['sub'] ) ? sanitize_text_field( wp_unslash( $_POST['sub'] ) ) : '';

		if ( ! self::est_identifiant( $abonnement ) ) {
			wp_send_json_error( array( 'message' => 'identifiant' ), 400 );
		}

		self::attach( get_current_user_id(), $abonnement );

		wp_send_json_success();
	}

	/**
	 * Enregistre le lien compte ↔ abonnement, des deux côtés.
	 *
	 * Côté WordPress, une méta utilisateur : c'est elle qui alimente la
	 * liste de l'administration, et elle survit à une panne de l'API.
	 * Côté OneSignal, l'`external_user_id` : il permet de viser un
	 * membre précis depuis le tableau de bord, et de retrouver ses
	 * appareils s'il en a plusieurs.
	 *
	 * Un même compte peut porter plusieurs abonnements — ordinateur,
	 * téléphone, application installée. La méta est donc une liste.
	 *
	 * @param int    $user_id    Compte WordPress.
	 * @param string $abonnement Identifiant OneSignal.
	 */
	public static function attach( $user_id, $abonnement ) {
		if ( ! $user_id ) {
			return;
		}

		$connus = get_user_meta( $user_id, self::USER_META, true );
		$connus = is_array( $connus ) ? $connus : array();

		if ( ! in_array( $abonnement, $connus, true ) ) {
			$connus[] = $abonnement;
			update_user_meta( $user_id, self::USER_META, $connus );
		}

		// L'échec n'est pas bloquant : le lien local suffit à la liste.
		self::push( $abonnement, array( 'external_user_id' => (string) $user_id ) );
	}

	/**
	 * Comptes rattachés, indexés par identifiant d'abonnement.
	 *
	 * Une seule requête pour toute la liste : interroger compte par
	 * compte sur une page qui en affiche cinquante serait ruineux.
	 *
	 * @return array<string,int> Abonnement => identifiant de compte.
	 */
	public static function known_links() {
		global $wpdb;

		$lignes = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::USER_META
			)
		);

		$liens = array();

		foreach ( $lignes as $ligne ) {
			$abonnements = maybe_unserialize( $ligne->meta_value );
			if ( ! is_array( $abonnements ) ) {
				continue;
			}
			foreach ( $abonnements as $abonnement ) {
				$liens[ $abonnement ] = (int) $ligne->user_id;
			}
		}

		return $liens;
	}

	/* ------------------------------------------------------------------
	 * Masque des centres d'intérêt
	 * ---------------------------------------------------------------- */

	/**
	 * Centres d'intérêt déclarés dans les réglages.
	 *
	 * @return string[] Identifiants.
	 */
	public static function slots() {
		return array_values( wp_list_pluck( Africart_PWA_OneSignal::get_categories(), 'slug' ) );
	}

	/**
	 * Conditions OneSignal retenant les abonnés d'un centre d'intérêt.
	 *
	 * Formulé à l'envers, et c'est délibéré : reçoivent tous ceux qui ne
	 * l'ont **pas refusé**.
	 *
	 * ⚠️ Viser « étiquette = 1 » paraît plus naturel et ne marche pas.
	 * Un abonné qui a accepté par l'invite du navigateur, sans jamais
	 * ouvrir la fenêtre de choix, ne porte aucune étiquette : il serait
	 * exclu de tout. C'est le cas de la quasi-totalité des abonnés
	 * actuels. Absence de choix n'est pas refus.
	 *
	 * Les deux conditions sont nécessaires : « différent de 0 » ne retient
	 * pas les fiches dépourvues de l'étiquette, d'où « n'existe pas » en
	 * alternative.
	 *
	 * @param string $slug Centre d'intérêt visé.
	 * @return array|null Conditions, ou null si le centre est inconnu.
	 */
	public static function filters_for( $slug ) {
		if ( ! in_array( $slug, self::slots(), true ) ) {
			return null;
		}

		return array(
			array(
				'field'    => 'tag',
				'key'      => $slug,
				'relation' => '!=',
				'value'    => '0',
			),
			array( 'operator' => 'OR' ),
			array(
				'field'    => 'tag',
				'key'      => $slug,
				'relation' => 'not_exists',
			),
		);
	}

	/**
	 * Le nombre de centres d'intérêt déclarés tient-il dans le forfait ?
	 *
	 * @return bool
	 */
	public static function within_plan() {
		return count( self::slots() ) <= self::MAX_TAGS;
	}

	/** L'identifiant a-t-il la forme attendue ? */
	private static function est_identifiant( $valeur ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $valeur );
	}

	/**
	 * Enregistre les centres d'intérêt d'un abonnement.
	 *
	 * Le point d'entrée est public — un visiteur non connecté doit
	 * pouvoir régler ses préférences. Trois garde-fous compensent :
	 *
	 *   1. L'identifiant doit avoir la forme d'un UUID ;
	 *   2. les clés doivent figurer parmi les centres d'intérêt déclarés
	 *      dans les réglages — impossible d'écrire une étiquette
	 *      arbitraire ;
	 *   3. les valeurs se limitent à « 0 » ou « 1 ».
	 *
	 * Le pire qu'un appel forgé puisse faire est donc de modifier les
	 * préférences d'un abonnement dont il connaîtrait déjà l'identifiant.
	 * Aucune donnée ne peut être lue, ni aucun message envoyé : la clé
	 * d'envoi reste sur le serveur.
	 */
	public function ajax_save() {
		check_ajax_referer( 'afk_push_tags', 'nonce' );

		$abonnement = isset( $_POST['sub'] ) ? sanitize_text_field( wp_unslash( $_POST['sub'] ) ) : '';

		if ( ! self::est_identifiant( $abonnement ) ) {
			wp_send_json_error( array( 'message' => 'identifiant' ), 400 );
		}

		$brut = isset( $_POST['tags'] ) ? wp_unslash( $_POST['tags'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$brut = json_decode( is_string( $brut ) ? $brut : '', true );

		if ( ! is_array( $brut ) ) {
			wp_send_json_error( array( 'message' => 'format' ), 400 );
		}

		$connus = self::slots();
		$actifs = array();

		foreach ( $brut as $cle => $valeur ) {
			$cle = sanitize_key( $cle );
			if ( in_array( $cle, $connus, true ) && '1' === (string) $valeur ) {
				$actifs[] = $cle;
			}
		}

		$resultat = self::write( $abonnement, $actifs );

		if ( is_wp_error( $resultat ) ) {
			wp_send_json_error( array( 'message' => $resultat->get_error_message() ) );
		}

		wp_send_json_success( array( 'actifs' => $actifs ) );
	}

	/**
	 * Enregistre les centres d'intérêt d'un abonnement.
	 *
	 * @param string   $abonnement Identifiant OneSignal.
	 * @param string[] $actifs     Centres d'intérêt acceptés.
	 * @return true|WP_Error
	 */
	public static function write( $abonnement, $actifs ) {
		$centres = self::slots();

		if ( count( $centres ) > self::MAX_TAGS ) {
			return new WP_Error(
				'plan_tags',
				sprintf(
					/* translators: 1: nombre déclaré, 2: maximum du forfait */
					__( '%1$d centres d’intérêt déclarés pour un maximum de %2$d : OneSignal rejetterait l’enregistrement en bloc.', 'africart-pwa' ),
					count( $centres ),
					self::MAX_TAGS
				)
			);
		}

		$tags = array();
		foreach ( $centres as $slug ) {
			$tags[ $slug ] = in_array( $slug, $actifs, true ) ? '1' : '0';
		}

		$resultat = self::push( $abonnement, array( 'tags' => $tags ) );

		// Le rapprochement se fait au passage : c'est le moment où l'on
		// tient à la fois l'identifiant et la session.
		if ( true === $resultat && is_user_logged_in() ) {
			self::attach( get_current_user_id(), $abonnement );
		}

		return $resultat;
	}

	/**
	 * Met à jour la fiche d'un abonnement chez OneSignal.
	 *
	 * @param string $abonnement Identifiant OneSignal.
	 * @param array  $champs     Champs à écrire (`tags`, `external_user_id`…).
	 * @return true|WP_Error
	 */
	private static function push( $abonnement, $champs ) {
		$cle = Africart_PWA_Push_Sender::get_key();

		if ( '' === $cle ) {
			return new WP_Error( 'no_key', __( 'Aucune clé d’envoi déclarée.', 'africart-pwa' ) );
		}

		$reponse = wp_remote_request(
			self::ENDPOINT . rawurlencode( $abonnement ),
			array(
				'method'  => 'PUT',
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Key ' . $cle,
				),
				'body'    => wp_json_encode(
					array_merge( array( 'app_id' => Africart_PWA_OneSignal::app_id() ), $champs )
				),
			)
		);

		if ( is_wp_error( $reponse ) ) {
			return $reponse;
		}

		$code = (int) wp_remote_retrieve_response_code( $reponse );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'onesignal_http',
				/* translators: %d code de réponse HTTP */
				sprintf( __( 'OneSignal a répondu %d.', 'africart-pwa' ), $code )
			);
		}

		return true;
	}
}
