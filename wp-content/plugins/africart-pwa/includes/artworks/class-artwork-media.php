<?php
/**
 * Téléversement des visuels d'œuvre.
 *
 * ⚠️ Pourquoi une route à nous plutôt que `/wp/v2/media` : **un artiste
 * n'a pas le droit `upload_files`**. Mesuré sur le site — un abonné rend
 * `false`. La route native de WordPress le refuserait donc, et le thème
 * a d'ailleurs sa propre médiathèque pour la même raison.
 *
 * Le téléversement se fait donc au nom du site, **après notre propre
 * contrôle** : peut déposer un visuel qui peut déposer une œuvre. Aucun
 * droit WordPress n'est accordé, même temporairement — élargir une
 * capacité le temps d'une requête est le genre de raccourci dont on ne
 * retrouve plus la trace six mois plus tard.
 *
 * Une route qui accepte des fichiers est la surface la plus exposée d'un
 * site. Cinq vérifications, dans cet ordre, et chacune peut refuser :
 *
 *   1. l'artiste a le droit de déposer une œuvre ;
 *   2. il n'a pas déjà téléversé au-delà du raisonnable ;
 *   3. le fichier tient dans la limite ;
 *   4. son extension et son type déclaré concordent, et sont une image ;
 *   5. le contenu **est** réellement une image, pas un fichier déguisé.
 *
 * La cinquième est la seule qui compte vraiment : les quatre premières
 * se contournent en renommant un fichier.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Media {

	/**
	 * Types d'images acceptés.
	 *
	 * Volontairement restreint. `image/svg+xml` en est absent et doit le
	 * rester : un SVG est un document capable de porter du script, et
	 * l'accepter d'un contributeur ouvrirait une faille dans la
	 * médiathèque.
	 */
	const TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
	);

	/** Taille maximale acceptée, en octets. */
	const TAILLE_MAX = 12582912; // 12 Mo

	/** Téléversements autorisés par heure et par artiste. */
	const PAR_HEURE = 40;

	/** Métadonnée marquant un média déposé par l'application. */
	const META_SOURCE = '_afk_media_source';

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
			Africart_Artwork_REST::NAMESPACE_REST,
			'/medias',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'televerser' ),
				'permission_callback' => array( $this, 'autorise' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Contrôle 1 — le droit
	 * ---------------------------------------------------------------- */

	/**
	 * Qui peut déposer une œuvre peut déposer son visuel.
	 *
	 * Le droit est celui du thème, jamais une capacité WordPress : le
	 * type de contenu déclare `capability_type: page`, et un artiste n'a
	 * aucune capacité sur les pages.
	 */
	public function autorise() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'afk_non_connecte',
				__( 'Connectez-vous pour déposer un visuel.', 'africart-pwa' ),
				array( 'status' => 401 )
			);
		}

		if ( ! class_exists( 'Africart_Artwork_Repository' ) || ! Africart_Artwork_Repository::can_create() ) {
			return new WP_Error(
				'afk_interdit',
				__( 'Votre compte ne permet pas de déposer un visuel.', 'africart-pwa' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Téléversement
	 * ---------------------------------------------------------------- */

	public function televerser( $requete ) {
		$fichiers = $requete->get_file_params();
		$fichier  = isset( $fichiers['fichier'] ) ? $fichiers['fichier'] : null;

		// ⚠️ La voie de secours : les octets bruts, sans `$_FILES`.
		//
		// Un iPhone envoyait sa photo et le serveur ne recevait RIEN :
		// `CONTENT_LENGTH` à zéro, aucun champ, aucune variable de
		// formulaire — alors que la photo était intacte sur l'appareil,
		// vérifiée octet par octet juste avant le départ. Trois tentatives,
		// trois fois le même vide, et sur ce téléphone-là seulement.
		//
		// Un corps `multipart/form-data` traverse plus de mains qu'un corps
		// simple : le navigateur le compose, l'hébergeur le passe au SAPI,
		// PHP le découpe — et il suffit qu'un maillon perde la longueur
		// déclarée pour que PHP renonce à l'analyser sans rien dire. On ne
		// saura peut-être jamais lequel, sur SON réseau à elle.
		//
		// Cette voie-ci n'en dépend pas : les octets arrivent tels quels,
		// lus dans le flux d'entrée. Elle ne sert qu'après un échec — le
		// chemin ordinaire, lui, ne change pas d'un iota.
		if ( ! $fichier ) {
			$brut = $requete->get_body();

			if ( '' !== $brut ) {
				return $this->televerser_brut( $requete, $brut );
			}
		}

		if ( ! $fichier || ! isset( $fichier['tmp_name'] ) || ! is_uploaded_file( $fichier['tmp_name'] ) ) {
			// ⚠️ Dire CE QU'ON A VU, et pas seulement qu'on n'a rien vu.
			//
			// « Aucun fichier reçu » est exact et sans valeur pour qui
			// cherche la cause : un corps vide, un corps rejeté en bloc par
			// PHP parce qu'il dépassait la limite, et un fichier arrivé à
			// zéro octet donnent le même message. Les trois se distinguent
			// pourtant d'un coup d'œil ici, et l'appareil n'a aucun moyen
			// de les départager tout seul.
			//
			// Rien de sensible : des tailles, des noms de champs et deux
			// réglages de PHP.
			$vu = array(
				'corps'   => (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ),
				'champs'  => implode( ',', array_keys( $fichiers ) ) ?: '(aucun)',
				'erreur'  => isset( $fichier['error'] ) ? (int) $fichier['error'] : -1,
				'taille'  => isset( $fichier['size'] ) ? (int) $fichier['size'] : -1,
				'limite'  => ini_get( 'post_max_size' ),
				'posts'   => count( (array) $_POST ),
				// ⚠️ Ce qui départage vraiment.
				//
				// Un corps `multipart` est consommé par PHP quand il
				// l'analyse : le flux d'entrée est alors vide, et c'est
				// normal. S'il reste des octets ICI alors que `$_FILES`
				// est vide, c'est que PHP a REÇU le corps sans l'analyser
				// — la longueur déclarée lui manquait. S'il n'y en a pas
				// non plus, le corps s'est perdu avant d'arriver.
				'flux'    => strlen( (string) $requete->get_body() ),
				'genre'   => (string) ( $_SERVER['CONTENT_TYPE'] ?? '(aucun)' ),
				'decoupe' => (string) ( $_SERVER['HTTP_TRANSFER_ENCODING'] ?? '(aucun)' ),
				// Un relais sur le trajet — opérateur, VPN, Private Relay.
				'relais'  => isset( $_SERVER['HTTP_VIA'] ) || isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? 'oui' : 'non',
			);

			$dit = array();

			foreach ( $vu as $cle => $valeur ) {
				$dit[] = $cle . '=' . $valeur;
			}

			return new WP_Error(
				'afk_sans_fichier',
				__( 'Aucun fichier reçu.', 'africart-pwa' ),
				array(
					'status' => 400,
					'vu'     => implode( ' ', $dit ),
				)
			);
		}

		$refus = self::verifier(
			$fichier['tmp_name'],
			(string) ( $fichier['name'] ?? '' ),
			(int) ( $fichier['size'] ?? 0 ),
			get_current_user_id()
		);

		if ( is_wp_error( $refus ) ) {
			return $refus;
		}

		$dimensions = @getimagesize( $fichier['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// `test_form` à faux : la requête ne vient pas d'un formulaire
		// d'administration, et WordPress y chercherait un champ absent.
		$depose = wp_handle_upload(
			$fichier,
			array(
				'test_form' => false,
				'mimes'     => self::TYPES,
			)
		);

		if ( isset( $depose['error'] ) ) {
			return new WP_Error( 'afk_depot_refuse', $depose['error'], array( 'status' => 400 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $depose['type'],
				'post_title'     => sanitize_text_field( pathinfo( $depose['file'], PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
				// L'artiste reste l'auteur de son visuel, même si le
				// téléversement s'est fait au nom du site.
				'post_author'    => get_current_user_id(),
			),
			$depose['file'],
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			// Le fichier est déjà sur le disque : le laisser serait
			// accumuler des orphelins que rien ne nettoie.
			@unlink( $depose['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $attachment_id;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $depose['file'] )
		);

		update_post_meta( $attachment_id, self::META_SOURCE, 'application' );

		return rest_ensure_response(
			array(
				'id'        => (int) $attachment_id,
				'url'       => wp_get_attachment_image_url( $attachment_id, 'medium' ),
				'nom'       => basename( $depose['file'] ),
				'largeur'   => (int) $dimensions[0],
				'hauteur'   => (int) $dimensions[1],
			)
		);
	}

	/**
	 * Le même dépôt, à partir des octets bruts du corps de la requête.
	 *
	 * ⚠️ Mêmes contrôles, exactement les mêmes.
	 *
	 * Une seconde porte d'entrée est une seconde surface d'attaque dès
	 * qu'elle a ses propres règles. Celle-ci n'en a aucune : elle écrit le
	 * corps dans un fichier temporaire et repasse par `verifier()` — débit,
	 * taille, type réel lu dans les octets — puis par le dépôt de
	 * WordPress. Ce qui change est le CHEMIN des octets, jamais ce qu'on
	 * accepte.
	 *
	 * Le nom vient de la requête et ne sert qu'à l'extension : c'est
	 * `verifier()` qui tranche sur le contenu, et `wp_handle_sideload()`
	 * qui assainit le nom.
	 *
	 * @param WP_REST_Request $requete La requête.
	 * @param string          $brut    Les octets de l'image.
	 * @return WP_REST_Response|WP_Error
	 */
	private function televerser_brut( $requete, $brut ) {
		$nom = (string) $requete->get_param( 'nom' );
		$nom = $nom ? sanitize_file_name( $nom ) : 'photo.jpg';

		$temporaire = wp_tempnam( $nom );

		if ( ! $temporaire ) {
			return new WP_Error(
				'afk_sans_tampon',
				__( 'Le serveur n’a pas pu recevoir la photo.', 'africart-pwa' ),
				array( 'status' => 500 )
			);
		}

		$ecrits = file_put_contents( $temporaire, $brut ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $ecrits ) {
			@unlink( $temporaire ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			return new WP_Error(
				'afk_sans_tampon',
				__( 'Le serveur n’a pas pu recevoir la photo.', 'africart-pwa' ),
				array( 'status' => 500 )
			);
		}

		$refus = self::verifier( $temporaire, $nom, (int) $ecrits, get_current_user_id() );

		if ( is_wp_error( $refus ) ) {
			@unlink( $temporaire ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $refus;
		}

		$dimensions = @getimagesize( $temporaire ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// ⚠️ `sideload` et non `upload` : `wp_handle_upload()` exige un
		// fichier passé par `$_FILES`, ce qui est précisément ce qui
		// manque ici. Le reste — nom assaini, type vérifié, place dans
		// l'arborescence des médias — est identique.
		$entree = array(
			'name'     => $nom,
			'tmp_name' => $temporaire,
			'size'     => (int) $ecrits,
			'error'    => 0,
		);

		$depose = wp_handle_sideload(
			$entree,
			array(
				'test_form' => false,
				'mimes'     => self::TYPES,
			)
		);

		if ( isset( $depose['error'] ) ) {
			@unlink( $temporaire ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'afk_depot_refuse', $depose['error'], array( 'status' => 400 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $depose['type'],
				'post_title'     => sanitize_text_field( pathinfo( $depose['file'], PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			$depose['file'],
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $depose['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $attachment_id;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $depose['file'] )
		);

		update_post_meta( $attachment_id, self::META_SOURCE, 'application' );

		return rest_ensure_response(
			array(
				'id'      => (int) $attachment_id,
				'url'     => wp_get_attachment_image_url( $attachment_id, 'medium' ),
				'nom'     => basename( $depose['file'] ),
				'largeur' => (int) ( $dimensions[0] ?? 0 ),
				'hauteur' => (int) ( $dimensions[1] ?? 0 ),
				// Pour le journal : savoir par où la photo est passée.
				'voie'    => 'brut',
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Contrôles
	 * ---------------------------------------------------------------- */

	/**
	 * Tout ce qui peut faire refuser un fichier.
	 *
	 * ⚠️ Isolée de la route à dessein : `is_uploaded_file()` ne passe que
	 * pour un envoi HTTP réel, ce qui rendrait ces règles intestables
	 * autrement qu'en conditions de production. Un contrôle de sécurité
	 * qu'on ne peut pas éprouver n'en est pas un.
	 *
	 * @param string $chemin  Fichier sur le disque.
	 * @param string $nom     Nom d'origine, tel qu'annoncé.
	 * @param int    $taille  Taille annoncée.
	 * @param int    $user_id Artiste.
	 * @return true|WP_Error
	 */
	public static function verifier( $chemin, $nom, $taille, $user_id ) {
		// ── 2. Le débit ────────────────────────────────────────────────
		if ( self::comptage_recent( $user_id ) >= self::PAR_HEURE ) {
			return new WP_Error(
				'afk_trop_de_depots',
				__( 'Trop de visuels déposés en peu de temps. Réessayez dans un moment.', 'africart-pwa' ),
				array( 'status' => 429 )
			);
		}

		// ── 3. La taille ───────────────────────────────────────────────
		$plafond = min( self::TAILLE_MAX, (int) wp_max_upload_size() );

		if ( $taille > $plafond ) {
			return new WP_Error(
				'afk_trop_lourd',
				sprintf(
					/* translators: %s taille maximale lisible */
					__( 'Ce visuel dépasse %s.', 'africart-pwa' ),
					size_format( $plafond )
				),
				array( 'status' => 413 )
			);
		}

		// ── 4. Extension et type déclaré ───────────────────────────────
		$controle = wp_check_filetype_and_ext( $chemin, $nom, self::TYPES );

		if ( empty( $controle['ext'] ) || empty( $controle['type'] ) ) {
			return new WP_Error(
				'afk_type_refuse',
				__( 'Seules les images JPEG, PNG, WebP et AVIF sont acceptées.', 'africart-pwa' ),
				array( 'status' => 415 )
			);
		}

		// ── 5. Le contenu est-il vraiment une image ? ──────────────────
		//
		// ⚠️ C'est la seule vérification qui compte réellement. Les
		// précédentes se contournent en renommant un fichier : un script
		// déposé sous le nom « photo.jpg » passerait le contrôle
		// d'extension sans la moindre difficulté.
		$dimensions = @getimagesize( $chemin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $dimensions || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return new WP_Error(
				'afk_pas_une_image',
				__( 'Ce fichier n’est pas une image.', 'africart-pwa' ),
				array( 'status' => 415 )
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Débit
	 * ---------------------------------------------------------------- */

	/**
	 * Visuels déposés par cet artiste depuis une heure.
	 *
	 * ⚠️ Un plafond n'est pas une précaution théorique : une route de
	 * téléversement ouverte aux contributeurs est exactement ce qu'on
	 * cherche pour remplir un disque. Quarante visuels par heure laisse
	 * largement la place à un dépôt normal — une œuvre en compte deux.
	 *
	 * @param int $user_id Artiste.
	 * @return int
	 */
	private static function comptage_recent( $user_id ) {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = 'attachment'
				   AND p.post_author = %d
				   AND p.post_date_gmt > %s",
				self::META_SOURCE,
				(int) $user_id,
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		);
	}
}
