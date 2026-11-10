<?php
/**
 * Espace REST des œuvres — `africart/v1`.
 *
 * ⚠️ Pourquoi ne pas simplement activer `show_in_rest` sur le type de
 * contenu : ce serait une ligne, et l'API répondrait aussitôt. Elle
 * écrirait des œuvres **réelles en base et absentes du site** — ni
 * validées, ni indexées, ni reliées à leur artiste. Le pire des
 * symptômes : celui qui ressemble à un succès.
 *
 * Ces routes sont donc les nôtres. L'écriture passe par
 * `class-artwork-writer.php` — voir ce fichier pour les cinq divergences
 * que le banc de parité a fallu corriger avant d'y toucher.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_REST {

	const NAMESPACE_REST = 'africart/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		register_rest_route(
			self::NAMESPACE_REST,
			'/session',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'session' ),
				'permission_callback' => array( $this, 'connecte' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_REST,
			'/oeuvres',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'liste' ),
					'permission_callback' => array( $this, 'connecte' ),
					'args'                => array(
						'since' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'creer' ),
					'permission_callback' => array( $this, 'peut_creer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_REST,
			'/oeuvres/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'fiche' ),
					'permission_callback' => array( $this, 'connecte' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'modifier' ),
					'permission_callback' => array( $this, 'connecte' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_REST,
			'/schema',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'schema' ),
				'permission_callback' => array( $this, 'connecte' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Accès
	 * ---------------------------------------------------------------- */

	/** Toute route exige une session ouverte. */
	public function connecte() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'afk_non_connecte',
				__( 'Connectez-vous pour accéder à vos œuvres.', 'africart-pwa' ),
				array( 'status' => 401 )
			);
		}

		if ( ! Africart_Artwork_Repository::is_available() ) {
			return new WP_Error(
				'afk_theme_absent',
				__( 'Le thème qui gère les œuvres n’est pas disponible.', 'africart-pwa' ),
				array( 'status' => 503 )
			);
		}

		return true;
	}

	/** Le dépôt d'une œuvre demande un droit de plus que la lecture. */
	public function peut_creer( $requete ) {
		$ouvert = $this->connecte( $requete );

		if ( is_wp_error( $ouvert ) ) {
			return $ouvert;
		}

		if ( ! Africart_Artwork_Repository::can_create() ) {
			return new WP_Error(
				'afk_interdit',
				__( 'Votre compte ne permet pas de déposer une œuvre.', 'africart-pwa' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Lecture
	 * ---------------------------------------------------------------- */

	/**
	 * Renouvelle le jeton et décrit ce que l'artiste a le droit de faire.
	 *
	 * ⚠️ Cette route existe pour un défaut de conception inévitable :
	 * l'authentification WordPress par cookie exige un `nonce` valable une
	 * demi-journée. Une œuvre créée le soir et synchronisée le lendemain
	 * partirait avec un jeton périmé ; le serveur répondrait 403, et la
	 * file entière se bloquerait sur une erreur qui n'en est pas une.
	 *
	 * L'application appelle donc `/session` **avant chaque vidage de
	 * file**, et retente une fois après renouvellement si le serveur
	 * refuse le jeton.
	 */
	public function session( $requete ) {
		$utilisateur = wp_get_current_user();
		$expire      = self::prolonger();

		return rest_ensure_response(
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				// Quand la session cessera d'être valable. L'application
				// s'en sert pour prévenir **avant** la coupure, pas après.
				'expire'  => $expire,
				'artiste' => array(
					'id'  => (int) $utilisateur->ID,
					'nom' => $utilisateur->display_name,
				),
				'droits'  => array(
					'creer'     => Africart_Artwork_Repository::can_create(),
					'supprimer' => Africart_Artwork_Rules::suppression_autorisee(),
				),
				// Nos règles, pas celles du thème : ce sont elles que
				// l'application doit refléter dans ses libellés.
				'regles'  => array(
					'statutDepot' => Africart_Artwork_Rules::statut_creation(),
					'theme'       => Africart_Artwork_Repository::submission_rules(),
				),
				'serveur' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Prolonge la session si elle approche de son terme.
	 *
	 * ⚠️ Le jeton d'API se renouvelle facilement ; **le cookie de session,
	 * non**. WordPress lui donne une durée fixe — deux jours, quatorze si
	 * l'artiste a coché « se souvenir de moi » — et ne la fait pas
	 * glisser avec l'usage. Un artiste qui dépose chaque semaine se
	 * retrouve donc déconnecté sans avoir rien fait de mal, et sa file
	 * d'envoi reste bloquée sur des 401 qu'il ne comprend pas.
	 *
	 * On réémet donc le cookie quand il a passé la moitié de sa vie. La
	 * durée d'origine est déduite de ce qu'il en reste : au-delà de deux
	 * jours, c'est qu'il portait « se souvenir de moi », et la
	 * prolongation doit le respecter — pas le raccourcir.
	 *
	 * ⚠️ Uniquement pour une session **déjà valide**. Cette route ne crée
	 * jamais de session : elle entretient celle qui existe.
	 *
	 * @return string Fin de validité, en ISO 8601.
	 */
	private static function prolonger() {
		$cookie = wp_parse_auth_cookie( '', 'logged_in' );

		if ( ! $cookie || empty( $cookie['expiration'] ) ) {
			return '';
		}

		$fin    = (int) $cookie['expiration'];
		$reste  = $fin - time();
		$longue = $reste > 2 * DAY_IN_SECONDS;

		// ⚠️ La durée de l'artiste, pas celle du cœur de WordPress.
		//
		// Deux jours et quatorze jours étaient les valeurs par défaut.
		// Depuis qu'une session d'artiste dure un an — sans quoi il ne
		// peut pas déposer sans réseau —, s'y fier ferait croire la
		// session à moitié écoulée dès le premier jour, et la ferait
		// réémettre à chaque appel pour rien.
		$sienne = Africart_Artwork_Session::duree_pour( get_current_user_id() );
		$duree  = $sienne ? $sienne : ( $longue ? 14 * DAY_IN_SECONDS : 2 * DAY_IN_SECONDS );

		// Passé la moitié de sa vie, on la remet à neuf.
		if ( $reste > 0 && $reste < ( $duree / 2 ) ) {
			// `true` pour un artiste : la longue durée passe par la case
			// « se souvenir de moi », que le filtre allonge ensuite.
			wp_set_auth_cookie( get_current_user_id(), $sienne ? true : $longue );
			$fin = time() + $duree;
		}

		return gmdate( 'c', $fin );
	}

	/**
	 * Les œuvres de l'artiste connecté.
	 *
	 * `?since=` ne rapporte que ce qui a changé depuis la dernière
	 * synchronisation. Le champ `connus` liste tout ce qui existe encore,
	 * sans quoi le cache local garderait indéfiniment des œuvres retirées
	 * — une suppression ne se signale par aucune date.
	 */
	public function liste( $requete ) {
		$user_id = get_current_user_id();
		$depuis  = (string) $requete->get_param( 'since' );

		$oeuvres = Africart_Artwork_Repository::list_for_user( $user_id, $depuis );

		// ⚠️ L'état de chacune, et pas seulement son existence.
		//
		// `?since=` s'appuie sur `post_modified_gmt`, et cette date ment
		// sur deux points. `wp_publish_post()` change le statut sans y
		// toucher : une œuvre validée depuis WordPress n'apparaissait donc
		// jamais comme telle dans l'application, qui continuait d'annoncer
		// « En attente de validation » à un artiste dont l'œuvre était en
		// ligne. Et trois œuvres de ce site portent
		// `post_modified_gmt = 0000-00-00` — héritage du thème : aucune
		// modification ne les ferait jamais redescendre.
		//
		// Cette table dit l'état réel de tout ce que l'artiste possède.
		// L'application la compare à sa copie et redemande tout dès qu'un
		// écart apparaît. Quelques octets par œuvre contre un statut faux.
		$connus = array();
		$etats  = array();

		foreach ( Africart_Artwork_Repository::list_for_user( $user_id ) as $o ) {
			$connus[] = $o['wpPostId'];
			$etats[ (string) $o['wpPostId'] ] = $o['publicationStatus'];
		}

		return rest_ensure_response(
			array(
				'oeuvres' => $oeuvres,
				'connus'  => $connus,
				'etats'   => (object) $etats,
				'serveur' => gmdate( 'c' ),
			)
		);
	}

	/** Une œuvre, avec les valeurs de ses champs. */
	public function fiche( $requete ) {
		$id = (int) $requete['id'];

		if ( ! Africart_Artwork_Repository::can_edit( $id ) ) {
			return new WP_Error(
				'afk_interdit',
				__( 'Cette œuvre ne vous appartient pas.', 'africart-pwa' ),
				array( 'status' => 403 )
			);
		}

		$oeuvre = Africart_Artwork_Repository::get( $id );

		if ( ! $oeuvre ) {
			return new WP_Error(
				'afk_introuvable',
				__( 'Œuvre introuvable.', 'africart-pwa' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $oeuvre );
	}

	/**
	 * Description des champs du formulaire.
	 *
	 * Le formulaire mobile se construit à partir de cette réponse, jamais
	 * d'une liste écrite en dur : les clés du thème sont opaques et
	 * changent au moindre renommage dans son éditeur. Un formulaire figé
	 * se mettrait à écrire dans le vide, sans erreur.
	 */
	public function schema( $requete ) {
		return rest_ensure_response(
			array(
				'postType' => Africart_Artwork_Repository::POST_TYPE,
				'champs'   => array_values( Africart_Artwork_Repository::fields() ),
				'etapes'   => Africart_Artwork_Repository::steps(),
				'regles'   => Africart_Artwork_Repository::submission_rules(),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Écriture
	 * ---------------------------------------------------------------- */

	/**
	 * Dépose une œuvre.
	 *
	 * ⚠️ L'idempotence tient à `localId`, et elle n'est pas facultative.
	 * Une coupure réseau **après** que WordPress a créé l'œuvre mais
	 * **avant** que la réponse n'arrive ferait recommencer l'application.
	 * Sans cette clé, chaque tentative produirait un doublon, et l'artiste
	 * retrouverait trois fois la même œuvre sans comprendre pourquoi.
	 */
	public function creer( $requete ) {
		$local = sanitize_text_field( (string) $requete->get_param( 'localId' ) );

		if ( '' !== $local ) {
			$existante = $this->par_identifiant_local( $local );

			if ( $existante ) {
				// Déjà créée lors d'une tentative précédente : on la rend
				// telle quelle, sans rien écrire de nouveau.
				return rest_ensure_response(
					array(
						'oeuvre'  => Africart_Artwork_Repository::get( $existante ),
						'reprise' => true,
					)
				);
			}
		}

		$valeurs   = (array) $requete->get_param( 'champs' );
		$brouillon = 'draft' === $requete->get_param( 'statut' );

		$erreurs = Africart_Artwork_Validator::check( $valeurs, $brouillon );

		if ( $erreurs ) {
			return $this->refus_validation( $erreurs );
		}

		$statut = $brouillon ? 'draft' : Africart_Artwork_Rules::statut_creation();
		$propre = Africart_Artwork_Validator::sanitize( $valeurs );

		$post_id = Africart_Artwork_Writer::create( $propre, $statut );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( '' !== $local ) {
			update_post_meta( $post_id, Africart_Artwork_Repository::META_LOCAL_ID, $local );
		}

		// ⚠️ Ici, et non dans l'écrivain.
		//
		// L'écrivain sert aussi au banc de parité, qui crée des œuvres
		// d'essai : y poser le déclenchement aurait envoyé un courriel à
		// l'équipe à chaque vérification. Cette route-ci est notre
		// contrôleur de soumission — l'équivalent exact de celui du
		// thème, et le seul endroit où un artiste dépose vraiment.
		Africart_Artwork_Events::creation( $post_id, $statut );

		return rest_ensure_response(
			array(
				'oeuvre'  => Africart_Artwork_Repository::get( $post_id ),
				'reprise' => false,
			)
		);
	}

	/**
	 * Modifie une œuvre, la dépose, ou la retire.
	 *
	 * Trois actions sur une seule route. Une route par verbe métier aurait
	 * multiplié les chemins d'écriture — et donc les endroits où oublier
	 * la réindexation.
	 */
	public function modifier( $requete ) {
		$id = (int) $requete['id'];

		if ( ! Africart_Artwork_Repository::can_edit( $id ) ) {
			return new WP_Error(
				'afk_interdit',
				__( 'Cette œuvre ne vous appartient pas.', 'africart-pwa' ),
				array( 'status' => 403 )
			);
		}

		$post = get_post( $id );

		if ( ! $post || Africart_Artwork_Repository::POST_TYPE !== $post->post_type ) {
			return new WP_Error(
				'afk_introuvable',
				__( 'Œuvre introuvable.', 'africart-pwa' ),
				array( 'status' => 404 )
			);
		}

		$action = sanitize_key( (string) $requete->get_param( 'action' ) );
		$action = in_array( $action, array( 'enregistrer', 'deposer', 'retirer' ), true ) ? $action : 'enregistrer';

		// ⚠️ Le contrôle de concurrence AVANT toute écriture. L'empreinte
		// attendue est celle que l'application détenait quand l'artiste a
		// commencé à modifier. Si elle ne correspond plus, quelqu'un — ou
		// lui-même depuis un autre appareil — est passé entre-temps, et
		// écraser silencieusement serait la pire des issues.
		$attendue = sanitize_text_field( (string) $requete->get_param( 'hashAttendu' ) );

		if ( '' !== $attendue && $attendue !== Africart_Artwork_Repository::hash_of( $post ) ) {
			return new WP_Error(
				'afk_conflit',
				__( 'Cette œuvre a changé depuis votre dernière synchronisation.', 'africart-pwa' ),
				array(
					'status' => 409,
					'oeuvre' => Africart_Artwork_Repository::get( $id ),
				)
			);
		}

		if ( 'retirer' === $action ) {
			return $this->retirer( $id );
		}

		$valeurs   = (array) $requete->get_param( 'champs' );
		$brouillon = 'enregistrer' === $action && 'draft' === $post->post_status;

		$erreurs = Africart_Artwork_Validator::check( $valeurs, $brouillon );

		if ( $erreurs ) {
			return $this->refus_validation( $erreurs );
		}

		$statut = 'deposer' === $action
			? Africart_Artwork_Rules::statut_depot( $post->post_status )
			: Africart_Artwork_Rules::statut_modification( $post->post_status );

		// Le statut d'AVANT, relevé pendant qu'il est encore vrai : après
		// l'écriture, il n'y a plus moyen de savoir si cette œuvre sortait
		// d'un brouillon ou était déjà en ligne, et c'est cela qui
		// distingue une soumission d'une mise à jour.
		$avant = (string) $post->post_status;

		$propre   = Africart_Artwork_Validator::sanitize( $valeurs );
		$ecriture = Africart_Artwork_Writer::update( $id, $propre, $statut );

		if ( is_wp_error( $ecriture ) ) {
			return $ecriture;
		}

		Africart_Artwork_Events::modification( $id, $avant, (string) $statut );

		return rest_ensure_response( array( 'oeuvre' => Africart_Artwork_Repository::get( $id ) ) );
	}

	/**
	 * Met une œuvre à la corbeille.
	 *
	 * La corbeille, jamais la suppression définitive : une fausse manœuvre
	 * sur un téléphone est vite arrivée, et une œuvre citée ailleurs ne
	 * doit pas s'évaporer.
	 *
	 * @param int $id Œuvre.
	 * @return WP_REST_Response|WP_Error
	 */
	private function retirer( $id ) {
		if ( ! Africart_Artwork_Rules::suppression_autorisee() ) {
			return new WP_Error(
				'afk_suppression_interdite',
				__( 'Le retrait d’une œuvre n’est pas autorisé sur ce site.', 'africart-pwa' ),
				array( 'status' => 403 )
			);
		}

		// Désindexer avant de retirer : une ligne d'index orpheline ferait
		// apparaître un résultat de recherche sans contenu derrière.
		if ( class_exists( '\Voxel\Post' ) ) {
			$objet = \Voxel\Post::force_get( $id );

			if ( $objet ) {
				$objet->unindex();
			}
		}

		wp_trash_post( $id );

		return rest_ensure_response(
			array(
				'oeuvre' => array(
					'wpPostId'          => $id,
					'publicationStatus' => 'DELETED',
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Utilitaires
	 * ---------------------------------------------------------------- */

	/**
	 * Œuvre portant un identifiant local donné.
	 *
	 * Restreinte à l'auteur courant : deux artistes pourraient produire le
	 * même identifiant local, et rendre l'œuvre d'un autre serait une
	 * fuite.
	 *
	 * @param string $local Identifiant local.
	 * @return int Identifiant WordPress, ou 0.
	 */
	private function par_identifiant_local( $local ) {
		$trouvees = get_posts(
			array(
				'post_type'      => Africart_Artwork_Repository::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'author'         => get_current_user_id(),
				'meta_key'       => Africart_Artwork_Repository::META_LOCAL_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $local, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		return $trouvees ? (int) $trouvees[0] : 0;
	}

	/**
	 * Refus de validation, champ par champ.
	 *
	 * ⚠️ Un `422` sans détail obligerait l'artiste à deviner lequel des
	 * trente-six champs pose problème. Les messages sont ceux du thème :
	 * ce sont les mêmes que sur le formulaire du site, et deux
	 * formulations différentes pour une même règle sèmeraient le doute.
	 *
	 * @param array $erreurs Erreurs par champ.
	 * @return WP_Error
	 */
	private function refus_validation( $erreurs ) {
		return new WP_Error(
			'afk_validation',
			__( 'Certains champs doivent être corrigés.', 'africart-pwa' ),
			array(
				'status'  => 422,
				'erreurs' => $erreurs,
			)
		);
	}
}
