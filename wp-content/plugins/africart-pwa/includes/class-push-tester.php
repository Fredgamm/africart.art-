<?php
/**
 * Banc d'essai de la publication.
 *
 * Vérifier qu'un déclencheur fonctionne demandait jusqu'ici de publier
 * un vrai contenu, puis de le supprimer — geste risqué sur un site
 * vivant, et impossible à répéter sereinement.
 *
 * Cet outil crée un contenu de démonstration, le publie — ce qui
 * déclenche l'envoi comme une vraie publication — et rend compte de ce
 * qui s'est réellement passé. Le contenu créé est signalé, avec son
 * lien : rien n'est supprimé en douce.
 *
 * ⚠️ Il **ne simule rien**. C'est une vraie publication, qui suit le
 * même chemin qu'une œuvre déposée par un artiste. Un test qui
 * emprunterait un raccourci ne prouverait rien.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Push_Tester {

	/** Marqueur posé sur les contenus créés par cet outil. */
	const META = '_afk_test_content';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_afk_push_publish_test', array( $this, 'ajax_publish' ) );
		add_action( 'wp_ajax_afk_push_cleanup_test', array( $this, 'ajax_cleanup' ) );
	}

	/* ------------------------------------------------------------------
	 * Contenus de démonstration
	 * ---------------------------------------------------------------- */

	/**
	 * Valeurs proposées pour un type de contenu.
	 *
	 * Les types propres au site ont leur jeu d'essai — titre crédible et
	 * visuel fourni avec le plugin — pour que le test ressemble à une
	 * vraie publication. Les autres reçoivent un jeu générique : mieux
	 * vaut un test possible qu'un type refusé.
	 *
	 * @param string $post_type Type de contenu.
	 * @return array{title:string,content:string,image:string}
	 */
	public static function sample_for( $post_type ) {
		$jeux = array(
			'oeuvre'  => array(
				'title'   => __( 'Sans titre — essai de notification', 'africart-pwa' ),
				'content' => __( 'Contenu de démonstration créé depuis les réglages AFRICART PWA, pour vérifier l’envoi automatique des notifications. Il peut être supprimé sans conséquence.', 'africart-pwa' ),
				'image'   => 'oeuvre.jpg',
			),
			'profile' => array(
				'title'   => __( 'Artiste de démonstration', 'africart-pwa' ),
				'content' => __( 'Profil de démonstration créé depuis les réglages AFRICART PWA, pour vérifier l’envoi automatique des notifications. Il peut être supprimé sans conséquence.', 'africart-pwa' ),
				'image'   => 'artiste.jpg',
			),
		);

		if ( isset( $jeux[ $post_type ] ) ) {
			return $jeux[ $post_type ];
		}

		$objet = get_post_type_object( $post_type );
		$nom   = ( $objet && $objet->labels->singular_name ) ? $objet->labels->singular_name : $post_type;

		return array(
			/* translators: %s nom du type de contenu */
			'title'   => sprintf( __( '%s de démonstration', 'africart-pwa' ), $nom ),
			'content' => __( 'Contenu de démonstration créé depuis les réglages AFRICART PWA. Il peut être supprimé sans conséquence.', 'africart-pwa' ),
			'image'   => '',
		);
	}

	/**
	 * Le type est-il pris en charge par un déclencheur ?
	 *
	 * @param string $post_type Type de contenu.
	 * @return array|null Le déclencheur, ou null.
	 */
	public static function trigger_for( $post_type ) {
		foreach ( Africart_PWA_Push_Sender::get_triggers() as $declencheur ) {
			if ( $declencheur['post_type'] === $post_type ) {
				return $declencheur;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------
	 * Publication d'essai
	 * ---------------------------------------------------------------- */

	/**
	 * Crée puis publie un contenu de démonstration.
	 */
	public function ajax_publish() {
		check_ajax_referer( 'afk_push_test_publish', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'africart-pwa' ) ), 403 );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';

		if ( ! $post_type || ! post_type_exists( $post_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Type de contenu inconnu.', 'africart-pwa' ) ) );
		}

		if ( ! self::trigger_for( $post_type ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Aucun déclencheur n’est configuré pour ce type de contenu : publier n’enverrait rien.', 'africart-pwa' ),
					'sans'    => true,
				)
			);
		}

		$jeu = self::sample_for( $post_type );

		// Brouillon d'abord : c'est le PASSAGE en publié qui déclenche,
		// et on veut que l'image soit en place avant l'envoi — elle
		// accompagne la notification.
		$id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => $jeu['title'] . ' — ' . wp_date( 'H:i:s' ),
				'post_content' => $jeu['content'],
				'post_status'  => 'draft',
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}

		update_post_meta( $id, self::META, '1' );

		$image = $jeu['image'] ? $this->attach_sample_image( $id, $jeu['image'] ) : 0;

		// Publication : le déclencheur part maintenant.
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );

		// ⚠️ Le même relais que le dépôt réel.
		//
		// Cet essai crée un contenu directement, sans passer par la route
		// de dépôt : les notifications d'équipe ne partaient donc pas, et
		// l'essai ne prouvait que la moitié de la chaîne. Il emprunte
		// maintenant le chemin qu'emprunte une vraie soumission —
		// brouillon puis publication, ce qui vaut « soumise » — et
		// permet donc de vérifier l'adresse destinataire pour de bon.
		$equipe = false;

		if ( class_exists( 'Africart_Artwork_Events' )
			&& class_exists( 'Africart_Artwork_Repository' )
			&& Africart_Artwork_Repository::POST_TYPE === $post_type ) {
			Africart_Artwork_Events::modification( $id, 'draft', 'publish' );
			$equipe = true;
		}

		$etat = get_post_meta( $id, Africart_PWA_Push_Sender::SENT_META, true );

		$messages = array(
			'ok'                => __( 'Notification envoyée.', 'africart-pwa' ),
			'sans-destinataire' => __( 'Notification partie, mais aucun abonné ne correspondait au ciblage.', 'africart-pwa' ),
			'echec'             => __( 'L’envoi a échoué — vérifiez la clé et l’identifiant d’application.', 'africart-pwa' ),
		);

		wp_send_json_success(
			array(
				'message'  => isset( $messages[ $etat ] ) ? $messages[ $etat ] : __( 'Aucun envoi n’a été tenté.', 'africart-pwa' ),
				'etat'     => $etat ? $etat : 'aucun',
				'equipe'   => $equipe,
				'id'       => $id,
				'titre'    => get_the_title( $id ),
				'lien'     => get_permalink( $id ),
				'edition'  => get_edit_post_link( $id, 'raw' ),
				'image'    => $image ? wp_get_attachment_image_url( $image, 'thumbnail' ) : '',
			)
		);
	}

	/**
	 * Attache une image fournie avec le plugin au contenu d'essai.
	 *
	 * L'image est copiée dans la médiathèque : le visuel accompagne la
	 * notification, et le partage social en a besoin. Le fichier
	 * d'origine reste intact dans le plugin.
	 *
	 * @param int    $post_id Contenu créé.
	 * @param string $fichier Nom du fichier dans assets/img/.
	 * @return int Identifiant du média, ou 0.
	 */
	private function attach_sample_image( $post_id, $fichier ) {
		$source = AFK_PWA_DIR . 'assets/img/' . $fichier;

		if ( ! file_exists( $source ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( 'afk-test-' . $post_id . '-' . $fichier, null, file_get_contents( $source ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => get_the_title( $post_id ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		set_post_thumbnail( $post_id, $id );
		update_post_meta( $id, self::META, '1' );

		return $id;
	}

	/* ------------------------------------------------------------------
	 * Ménage
	 * ---------------------------------------------------------------- */

	/**
	 * Supprime tous les contenus créés par cet outil.
	 *
	 * Repérés par leur marqueur, jamais par leur titre : un contenu
	 * légitime pourrait porter le même nom.
	 */
	public function ajax_cleanup() {
		check_ajax_referer( 'afk_push_test_publish', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'africart-pwa' ) ), 403 );
		}

		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		wp_send_json_success(
			array(
				/* translators: %d nombre d'éléments supprimés */
				'message' => sprintf( _n( '%d élément de test supprimé.', '%d éléments de test supprimés.', count( $ids ), 'africart-pwa' ), count( $ids ) ),
			)
		);
	}

	/**
	 * Nombre de contenus d'essai encore présents.
	 *
	 * @return int
	 */
	public static function leftovers() {
		$ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return count( $ids );
	}
}
