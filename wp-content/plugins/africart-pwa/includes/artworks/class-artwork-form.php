<?php
/**
 * Formulaire de dépôt hors connexion — branchement côté site.
 *
 * ⚠️ Comme pour la liste, ce module **n'ajoute aucun écran**. Le
 * formulaire de dépôt existe déjà, rendu par le widget du thème sur la
 * page « Enregistrer une œuvre ». En superposer un second créerait deux
 * expériences divergentes pour un même geste, et l'artiste ne saurait
 * plus laquelle fait foi.
 *
 * Notre formulaire ne prend la main que **sans réseau**.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Form {

	/** Widget du thème qui rend le formulaire de dépôt. */
	const WIDGET_FORMULAIRE = 'ts-create-post';

	/**
	 * Notre propre point d'ancrage.
	 *
	 * ⚠️ Indispensable dès lors que le widget du thème peut être retiré
	 * de la page. Tant que la détection reposait sur `ts-create-post`,
	 * enlever ce widget aurait fait disparaître **notre** formulaire en
	 * même temps que celui de Voxel — et la page de dépôt serait devenue
	 * une page blanche, sans erreur nulle part.
	 */
	const SHORTCODE = 'africart_oeuvre_form';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
	}

	/**
	 * Rend le conteneur du formulaire.
	 *
	 * Vide : c'est le script qui le remplit, à partir du schéma. Le
	 * serveur ne peut pas le rendre lui-même — hors connexion, il n'y a
	 * personne pour le faire.
	 *
	 * @return string
	 */
	public function shortcode() {
		// ⚠️ Dans l'éditeur, le formulaire est rendu **pour de vrai**, mais
		// débranché : ni enregistrement au fil de la frappe, ni mise en
		// file, ni téléversement. Le laisser vivre tel quel sèmerait des
		// brouillons d'œuvres et des pièces jointes à chaque ouverture de
		// la page — et un simple encadré, lui, ne montrait rien de ce que
		// l'artiste verra.
		if ( $this->en_edition() ) {
			$this->charger( true );

			return '<div class="afk-oeuvre-form" data-afk-form data-afk-apercu></div>';
		}

		if ( ! is_user_logged_in() ) {
			return '';
		}

		if ( ! class_exists( 'Africart_Artwork_Repository' ) || ! Africart_Artwork_Repository::can_create() ) {
			return '';
		}

		// ⚠️ Le crochet d'enfilement juge sur la structure de la page. Il
		// ne voit donc rien quand le code court vit ailleurs : un modèle
		// Elementor, un widget de barre latérale, un bloc réutilisable,
		// ou un widget tout juste déposé et pas encore enregistré. Le
		// conteneur sortirait alors sans son script, et resterait vide.
		$this->charger();

		return '<div class="afk-oeuvre-form" data-afk-form></div>';
	}

	/**
	 * Les informations de l'artiste, prêtes à remplir ses propres champs.
	 *
	 * ⚠️ On ne rapproche que ce dont on est **certain**.
	 *
	 * Le type suffit pour l'adresse électronique et le téléphone : il n'y
	 * a qu'un champ de chaque sorte, et son sens ne fait pas de doute.
	 * Pour le nom et le prénom, deux champs texte parmi seize, seul le
	 * libellé les distingue — et il doit commencer par « Votre » : le
	 * formulaire porte aussi « Nom de l'artiste ? », qui désigne quelqu'un
	 * d'autre. Se tromper ici écrirait le nom du déposant à la place de
	 * celui de l'artiste, dans une fiche publiée.
	 *
	 * Un libellé renommé fait simplement remplir moins de champs. Jamais
	 * de travers.
	 *
	 * @return array<string,string>
	 */
	private static function profil() {
		$u = wp_get_current_user();

		if ( ! $u || ! $u->ID || ! class_exists( 'Africart_Artwork_Repository' ) ) {
			return array();
		}

		$sortie = array();

		foreach ( Africart_Artwork_Repository::fields() as $champ ) {
			$type  = isset( $champ['type'] ) ? $champ['type'] : '';
			$titre = isset( $champ['label'] ) ? $champ['label'] : '';

			if ( 'email' === $type ) {
				$sortie[ $champ['key'] ] = (string) $u->user_email;
				continue;
			}

			if ( 'phone' === $type ) {
				$tel = self::telephone( $u->ID );

				if ( '' !== $tel ) {
					$sortie[ $champ['key'] ] = $tel;
				}

				continue;
			}

			if ( 'text' !== $type ) {
				continue;
			}

			if ( preg_match( '#^\\s*votre\\s+pr[ée]nom#iu', $titre ) && $u->first_name ) {
				$sortie[ $champ['key'] ] = (string) $u->first_name;
			} elseif ( preg_match( '#^\\s*votre\\s+nom#iu', $titre ) && $u->last_name ) {
				$sortie[ $champ['key'] ] = (string) $u->last_name;
			}
		}

		return array_filter( $sortie, function ( $v ) { return '' !== trim( (string) $v ); } );
	}

	/**
	 * Le téléphone de l'artiste, s'il en a déclaré un.
	 *
	 * Cherché dans les emplacements usuels plutôt que supposé : selon la
	 * façon dont le compte a été créé, il vit sous l'une ou l'autre clé.
	 *
	 * @param int $user_id Utilisateur.
	 * @return string
	 */
	private static function telephone( $user_id ) {
		foreach ( array( 'billing_phone', 'phone', 'voxel:phone_number', 'user_phone' ) as $cle ) {
			$v = trim( (string) get_user_meta( $user_id, $cle, true ) );

			if ( '' !== $v ) {
				return $v;
			}
		}

		return '';
	}

	/**
	 * Le schéma tel que la route le rendrait.
	 *
	 * @return array
	 */
	private static function schema_complet() {
		return array(
			'champs' => array_values( Africart_Artwork_Repository::fields() ),
			'etapes' => array_values( Africart_Artwork_Repository::steps() ),
		);
	}

	/**
	 * Sommes-nous dans l'éditeur Elementor, ou dans son aperçu ?
	 *
	 * Trois situations distinctes, et les trois comptent : l'aperçu est
	 * une page normale chargée dans un cadre, l'édition est cette même
	 * page pilotée, et le rendu d'un widget passe par une requête Ajax
	 * qui n'est ni l'une ni l'autre.
	 *
	 * @return bool
	 */
	private function en_edition() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() ) {
			return true;
		}

		if ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) {
			return true;
		}

		// Le rendu d'un widget dans l'éditeur.
		return wp_doing_ajax()
			&& isset( $_REQUEST['action'] )
			&& 'elementor_ajax' === $_REQUEST['action']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Charge le formulaire pour les artistes autorisés à déposer.
	 *
	 * Un visiteur qui ne peut pas créer d'œuvre n'a rien à faire avec ce
	 * script : ce serait du poids pour rien, et une requête de schéma qui
	 * finirait en 403.
	 */
	public function enqueue() {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}

		if ( ! class_exists( 'Africart_Artwork_Repository' ) || ! Africart_Artwork_Repository::is_available() ) {
			return;
		}

		// ⚠️ Dans l'éditeur, on charge sans condition de page. Elementor
		// redessine un widget fraîchement déposé par une requête Ajax, qui
		// ne réimprime aucun script : si le nécessaire n'était pas déjà là,
		// le conteneur resterait vide jusqu'au prochain rechargement, et
		// l'auteur croirait le code court cassé.
		if ( $this->en_edition() ) {
			$this->charger( true );
			return;
		}

		if ( ! $this->page_porte_le_formulaire() || ! Africart_Artwork_Repository::can_create() ) {
			return;
		}

		$this->charger();
	}

	/**
	 * Enfile réellement les scripts du formulaire.
	 *
	 * Appelable deux fois sans dommage : les poignées sont uniques, et le
	 * garde-fou évite de réécrire la configuration en double.
	 */
	private function charger( $apercu = false ) {
		if ( wp_script_is( 'afk-pwa-oeuvre-form', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'afk-pwa-oeuvres',
			AFK_PWA_URL . 'assets/css/oeuvres.css',
			array(),
			afk_pwa_asset_version( 'assets/css/oeuvres.css' )
		);

		Africart_Artwork_Cache::enqueue_socle();

		// ⚠️ Les pays voyagent avec l'extension, jamais depuis un service
		// exterieur. Ce formulaire sert d'abord hors connexion : une liste
		// telechargee au moment de la saisie serait vide precisement quand
		// on en a besoin. Seize kilooctets, mis en cache par le navigateur
		// comme n'importe quel script.
		wp_enqueue_script(
			'afk-pwa-pays',
			AFK_PWA_URL . 'assets/js/pays.js',
			array(),
			afk_pwa_asset_version( 'assets/js/pays.js' ),
			true
		);

		wp_enqueue_script(
			'afk-pwa-oeuvre-form',
			AFK_PWA_URL . 'assets/js/oeuvre-form.js',
			array( 'afk-pwa-oeuvres-db', 'afk-pwa-oeuvres-sync', 'afk-pwa-pays' ),
			afk_pwa_asset_version( 'assets/js/oeuvre-form.js' ),
			true
		);

		// ⚠️ Sur le premier script de la chaîne. Voir la note détaillée dans
		// `Africart_Artwork_Cache::enqueue()` : attachée au formulaire, la
		// configuration était écrite après la file, qui démarrait sans.
		wp_add_inline_script(
			'afk-pwa-oeuvres-db',
			'window.AFK_PWA_FORM = ' . wp_json_encode(
				array(
					'base'   => rest_url( Africart_Artwork_REST::NAMESPACE_REST . '/' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'langue' => str_replace( '_', '-', get_locale() ),
					'textes' => Africart_Artwork_Cache::textes(),
					// Notre formulaire prend-il la main aussi en ligne ?
					'toujours' => '1' === Africart_PWA_Settings::get( 'art_form_toujours' ),
					// Où déposer les visuels, quand le réseau le permet.
					'medias'   => rest_url( Africart_Artwork_REST::NAMESPACE_REST . '/medias' ),
					// Où l'artiste retrouve ce qu'il vient de déposer.
					'liste'    => Africart_Artwork_Cache::page_liste(),
					// De quoi remplir les champs qui le concernent lui.
					'profil'   => self::profil(),
					// ⚠️ Le retour se fait **sur la page en cours**, pas sur
					// l'accueil : l'artiste qui se reconnecte veut retrouver
					// sa saisie, pas repartir de zéro.
					'connexion' => wp_login_url( home_url( add_query_arg( array() ) ) ),
					// ⚠️ De quoi renouveler un jeton REST perime sans
					// reconnexion. La configuration voyage dans la page ; une
					// page servie depuis un cache porte donc un jeton mort,
					// et le site repondait 403 a un artiste parfaitement
					// connecte. Cette adresse, elle, rend toujours un jeton
					// frais tant que le cookie tient.
					'jetons'    => admin_url( 'admin-ajax.php?action=rest-nonce' ),
					// Rien ne doit s'écrire ni partir depuis l'éditeur.
					'apercu'    => (bool) $apercu,
					// ⚠️ Le schéma est transmis en clair dans l'éditeur, au
					// lieu d'être lu dans la réserve du navigateur. Cette
					// réserve est remplie en parcourant le site ; un auteur
					// qui ouvre Elementor sans être passé par là n'aurait vu
					// qu'un formulaire vide et un message d'absence.
					'schema'    => $apercu ? self::schema_complet() : null,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * La page affichée porte-t-elle le formulaire de dépôt ?
	 *
	 * Lu dans la structure de la page plutôt que deviné à son adresse :
	 * une page se renomme, se déplace, se duplique. Ce qui la définit,
	 * c'est le widget qu'elle contient.
	 *
	 * @return bool
	 */
	private function page_porte_le_formulaire() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post ) {
			return false;
		}

		// Notre ancrage d'abord : c'est celui qui doit survivre au retrait
		// du widget du thème.
		if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			return true;
		}

		$structure = get_post_meta( $post->ID, '_elementor_data', true );

		if ( is_string( $structure ) && false !== strpos( $structure, self::SHORTCODE ) ) {
			return true;
		}

		return is_string( $structure ) && false !== strpos( $structure, self::WIDGET_FORMULAIRE );
	}
}
