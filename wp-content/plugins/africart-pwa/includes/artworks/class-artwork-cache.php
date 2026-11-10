<?php
/**
 * Consultation des œuvres hors connexion — branchement côté site.
 *
 * ⚠️ Ce module **n'ajoute aucun écran**. La page « Vos œuvres » existe
 * déjà — elle est référencée dans le menu utilisateur du thème et rendue
 * par le widget de flux de Voxel. En construire une seconde donnerait
 * deux listes concurrentes, dont l'une serait toujours en retard sur
 * l'autre.
 *
 * Le script se greffe donc sur l'existant, et ne reprend la main que
 * sans réseau, sur le seul conteneur de la grille.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Cache {

	/**
	 * Widget Voxel qui rend la liste des œuvres.
	 *
	 * Sa présence dans la page décide du chargement du script : inutile
	 * de le servir sur les cent autres pages du site.
	 */
	const WIDGET_FLUX = 'ts-post-feed';

	/**
	 * Ce qui distingue « mes œuvres » de « les œuvres du site ».
	 *
	 * ⚠️ Trois pages portent un flux d'œuvres : la liste personnelle de
	 * l'artiste, l'accueil — qui montre les dernières publiées — et le
	 * gabarit d'archive. Les traiter de la même façon faisait remplacer,
	 * hors connexion, les dernières œuvres du site par celles de
	 * l'artiste. Un visiteur y aurait vu une sélection qui n'est pas
	 * celle de la page.
	 *
	 * Le départ se lit dans la configuration du flux : seule la liste
	 * personnelle porte un filtre par statut de publication. Un artiste a
	 * besoin de voir ses brouillons et ses œuvres en validation ; le
	 * public, non.
	 */
	const FILTRE_PERSONNEL = 'post-status';

	/**
	 * Notre propre point d'ancrage pour la liste.
	 *
	 * ⚠️ Même raison que pour le formulaire : tant que la détection
	 * reposait sur le widget du thème, retirer ce widget faisait
	 * disparaître **notre** liste avec la sienne, et la page « Mes
	 * œuvres » devenait blanche sans erreur nulle part.
	 */
	const SHORTCODE = 'africart_mes_oeuvres';

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

		// Une page modifiée peut avoir gagné ou perdu un lien vers le
		// formulaire. Garder la liste une journée entière ferait attendre
		// autant avant que le parcours ne redevienne complet.
		add_action( 'save_post_page', array( __CLASS__, 'oublier_les_pages' ) );
	}

	/**
	 * Rend le conteneur de la liste.
	 *
	 * Vide : c'est le script qui le remplit, depuis la copie locale. Le
	 * serveur ne peut pas le rendre lui-même — hors connexion, il n'y a
	 * personne pour le faire.
	 *
	 * ⚠️ Contrairement au formulaire, la liste est rendue **pour de vrai**
	 * dans l'éditeur Elementor : elle ne fait que lire. Rien ne s'écrit,
	 * rien ne part, et l'auteur voit ce que verra l'artiste.
	 *
	 * @return string
	 */
	public function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		if ( ! class_exists( 'Africart_Artwork_Repository' ) || ! Africart_Artwork_Repository::is_available() ) {
			return '';
		}

		// ⚠️ Rien à enfiler ici, contrairement au formulaire : le script du
		// cache est chargé sur **toutes** les pages pour un artiste
		// connecté, parce que la consultation hors connexion suppose une
		// copie déjà prise. Le code court trouve donc toujours de quoi se
		// remplir, où qu'il soit posé.
		return '<div class="afk-oeuvres-liste" data-afk-liste></div>';
	}

	/** Force la redécouverte des pages du module. */
	public static function oublier_les_pages() {
		delete_transient( 'afk_pwa_pages_oeuvres' );
	}

	/**
	 * Charge le cache pour les artistes connectés.
	 *
	 * Un visiteur non connecté n'a pas d'œuvres : lui servir ce script
	 * serait du poids pour rien, et une requête REST qui finirait en 401.
	 */
	public function enqueue() {
		if ( is_admin() ) {
			return;
		}

		// ⚠️ Un visiteur non connecté ne se contente pas d'être ignoré :
		// s'il reste des œuvres sur cet appareil, elles appartiennent à
		// quelqu'un qui vient de se déconnecter, et resteraient
		// consultables hors connexion par la personne suivante.
		if ( ! is_user_logged_in() ) {
			$this->enqueue_oubli();
			return;
		}

		if ( ! class_exists( 'Africart_Artwork_Repository' ) || ! Africart_Artwork_Repository::is_available() ) {
			return;
		}

		$sur_la_page = $this->page_porte_le_flux();

		// Le script est chargé même hors de la page des œuvres : la
		// consultation hors connexion suppose une copie **déjà prise**.
		// L'attendre au moment où le réseau manque serait trop tard.
		wp_enqueue_style(
			'afk-pwa-oeuvres',
			AFK_PWA_URL . 'assets/css/oeuvres.css',
			array(),
			afk_pwa_asset_version( 'assets/css/oeuvres.css' )
		);

		$this->enqueue_socle();

		wp_enqueue_script(
			'afk-pwa-oeuvres',
			AFK_PWA_URL . 'assets/js/oeuvres.js',
			array( 'afk-pwa-oeuvres-db', 'afk-pwa-oeuvres-sync' ),
			afk_pwa_asset_version( 'assets/js/oeuvres.js' ),
			true
		);

		wp_enqueue_script(
			'afk-pwa-oeuvres-liste',
			AFK_PWA_URL . 'assets/js/oeuvres-liste.js',
			array( 'afk-pwa-oeuvres' ),
			afk_pwa_asset_version( 'assets/js/oeuvres-liste.js' ),
			true
		);

		// ⚠️ Posée sur le **premier** script de la chaîne, et non sur celui
		// qui porte la liste.
		//
		// WordPress imprime les scripts dans l'ordre de leurs dépendances :
		// la base, la file, puis les écrans. Une configuration attachée à
		// un écran est donc écrite APRÈS la file — qui, ne trouvant pas son
		// adresse de service, s'arrêtait à sa première ligne. `afkSync`
		// n'existait jamais sur le site, et le bouton « Déposer l'œuvre »
		// n'avait pas de gestionnaire du tout : le clic ne faisait
		// littéralement rien. Constaté en imprimant l'ordre réel du pied de
		// page sur le serveur.
		wp_add_inline_script(
			'afk-pwa-oeuvres-db',
			'window.AFK_PWA_OEUVRES = ' . wp_json_encode(
				array(
					'base'   => rest_url( Africart_Artwork_REST::NAMESPACE_REST . '/' ),
					'nonce'  => wp_create_nonce( 'wp_rest' ),
					'feed'   => $sur_la_page,
					// Les pages a garder sous la main pour le hors-connexion.
					'pages'  => self::pages_du_module(),
					// Où mènent les cartes quand on veut modifier une œuvre.
					'editeur' => self::page_formulaire(),
					// ⚠️ Et où mène « Voir les œuvres à envoyer ».
					//
					// Cette adresse ne voyageait que dans la configuration du
					// formulaire. Le message flottant, lui, s'affiche sur
					// toutes les pages du module : il annonçait « 1 œuvre à
					// envoyer » sans pouvoir dire où la trouver.
					'liste'   => self::page_liste(),
					// La file en a besoin pour envoyer les photos gardees
					// sur l'appareil avant l'oeuvre qui les porte.
					'medias'  => rest_url( Africart_Artwork_REST::NAMESPACE_REST . '/medias' ),
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
					'langue' => str_replace( '_', '-', get_locale() ),
					'textes' => self::textes(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Efface ce que l'appareil garde des œuvres.
	 *
	 * Le script ne fait rien du tout pour un visiteur ordinaire : il
	 * commence par chercher une trace, et la plupart n'en ont aucune. Le
	 * coût est donc nul là où il n'y a rien à effacer — ce qui est le cas
	 * de l'immense majorité du trafic.
	 */
	private function enqueue_oubli() {
		$pages = self::pages_du_module();

		wp_register_script( 'afk-pwa-oeuvres-oubli', false, array(), afk_pwa_asset_version( 'assets/js/oeuvres.js' ), true );
		wp_enqueue_script( 'afk-pwa-oeuvres-oubli' );

		$code = "( function () {
	var traces = [ 'afkOeuvresSync', 'afkOeuvreSchema', 'afkOeuvreBrouillonCourant' ];
	var trouve = false;

	try {
		for ( var i = 0; i < traces.length; i++ ) {
			if ( window.localStorage.getItem( traces[ i ] ) ) { trouve = true; }
		}
	} catch ( e ) { return; }

	if ( ! trouve ) { return; }

	try {
		for ( var j = 0; j < traces.length; j++ ) { window.localStorage.removeItem( traces[ j ] ); }
		window.indexedDB.deleteDatabase( 'afk-oeuvres' );
	} catch ( e ) {}

	if ( navigator.serviceWorker && navigator.serviceWorker.controller ) {
		navigator.serviceWorker.controller.postMessage( { type: 'afk-oublie', urls: PAGES } );
	}
}() );";

		wp_add_inline_script(
			'afk-pwa-oeuvres-oubli',
			str_replace( 'PAGES', wp_json_encode( $pages ), $code )
		);
	}

	/**
	 * Textes visibles, dans la langue de la page.
	 *
	 * ⚠️ Ils ne sont plus écrits dans le JavaScript. Tous les autres
	 * textes du plugin sont réglables et traduisibles depuis
	 * l'administration ; les laisser en dur ici aurait créé deux
	 * régimes — et un site bilingue aurait affiché du français au milieu
	 * de l'anglais, sans que personne puisse le corriger.
	 *
	 * @return array<string,string>
	 */
	public static function textes() {
		$lang = class_exists( 'Africart_PWA_I18n' ) ? Africart_PWA_I18n::get_current_lang() : null;
		$cles = array(
			'art_etat_draft', 'art_etat_review', 'art_etat_published',
			'art_etat_rejected', 'art_etat_unpublished',
			'art_sync_attente', 'art_sync_encours', 'art_sync_incomplet',
			'art_sync_conflit', 'art_sync_erreur',
			'art_hors_ligne', 'art_injoignable', 'art_vide',
			'art_form_avis', 'art_form_absent', 'art_form_depot',
			'art_form_enfile', 'art_form_partira', 'art_form_enregistre',
			'art_form_titre_dabord', 'art_form_sortie',
			'art_form_encours', 'art_form_maj', 'art_form_absente',
		);

		$sortie = array();

		foreach ( $cles as $cle ) {
			$court = str_replace( 'art_', '', $cle );
			$sortie[ $court ] = class_exists( 'Africart_PWA_I18n' )
				? Africart_PWA_I18n::text( $cle, $lang )
				: (string) Africart_PWA_Settings::get( $cle );
		}

		// ⚠️ Et tout réglage `art_txt_*` qui existe, sans liste à tenir ici.
		//
		// Les phrases des écrans d'état et d'arbitrage sont écrites en
		// français dans le JavaScript, faute d'une quarantaine de champs
		// d'administration que personne n'a demandés. Elles passent
		// toutes par un point unique côté navigateur : poser le réglage
		// correspondant suffit désormais à en traduire une, sans toucher
		// au code ni ajouter d'écran. Le jour où le client veut son site
		// en anglais, c'est une saisie, plus un développement.
		foreach ( (array) get_option( 'afk_pwa_settings', array() ) as $cle => $valeur ) {
			if ( 0 !== strpos( (string) $cle, 'art_txt_' ) || ! is_string( $valeur ) || '' === $valeur ) {
				continue;
			}

			$court = str_replace( 'art_', '', $cle );
			$sortie[ $court ] = class_exists( 'Africart_PWA_I18n' )
				? Africart_PWA_I18n::text( $cle, $lang )
				: $valeur;
		}

		return $sortie;
	}

	/**
	 * Adresses des pages du module.
	 *
	 * Reperees par les widgets qu'elles contiennent, jamais par leur
	 * adresse : une page se renomme, se deplace, se duplique.
	 *
	 * Le resultat est garde une journee — parcourir toutes les pages a
	 * chaque affichage serait ruineux pour un renseignement qui ne change
	 * qu'a la refonte d'un gabarit.
	 *
	 * @return string[]
	 */
	public static function pages_du_module() {
		$connu = get_transient( 'afk_pwa_pages_oeuvres' );

		if ( is_array( $connu ) ) {
			return $connu;
		}

		$adresses = array();
		$pages    = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		// Premier passage : les pages qui portent nos écrans.
		$page_formulaire = 0;

		foreach ( $pages as $id ) {
			$structure = get_post_meta( $id, '_elementor_data', true );

			if ( ! is_string( $structure ) ) {
				continue;
			}

			$liste_personnelle = ( false !== strpos( $structure, self::WIDGET_FLUX )
					&& false !== strpos( $structure, self::FILTRE_PERSONNEL ) )
				|| false !== strpos( $structure, self::SHORTCODE )
				|| has_shortcode( (string) get_post_field( 'post_content', $id ), self::SHORTCODE );

			// ⚠️ La page d'entrée du parcours, celle que le menu vise.
			//
			// Elle y entrait jusqu'ici parce qu'elle **contenait** un lien
			// écrit vers le formulaire, et que le second passage suit ces
			// liens. Depuis qu'elle porte notre bloc, ce lien est fabriqué
			// au rendu : plus rien à trouver dans la structure, et la page
			// est sortie de la réserve sans que rien ne le signale. Un
			// artiste sans réseau touchait « Enregistrer une œuvre » et
			// tombait sur l'écran hors connexion. Constaté à l'audit.
			$intro = class_exists( 'Africart_Artwork_Intro' ) && (
				false !== strpos( $structure, Africart_Artwork_Intro::SHORTCODE )
				|| has_shortcode( (string) get_post_field( 'post_content', $id ), Africart_Artwork_Intro::SHORTCODE )
			);

			$formulaire = false !== strpos( $structure, Africart_Artwork_Form::WIDGET_FORMULAIRE )
				|| false !== strpos( $structure, Africart_Artwork_Form::SHORTCODE )
				|| has_shortcode( (string) get_post_field( 'post_content', $id ), Africart_Artwork_Form::SHORTCODE );

			if ( $formulaire ) {
				$page_formulaire = $id;
			}

			// L'accueil porte lui aussi un flux d'œuvres, mais il n'est pas
			// du ressort de ce module : le garder sous la main ferait
			// télécharger la page la plus lourde du site à chaque artiste.
			if ( $liste_personnelle || $formulaire || $intro ) {
				$adresses[] = get_permalink( $id );
			}
		}

		// ⚠️ Second passage : les pages qui **mènent** au formulaire.
		//
		// Le menu du site ne pointe pas sur le formulaire, mais sur une
		// page d'explications qui y renvoie. Ne garder que le formulaire
		// laissait donc le parcours coupé en son milieu : l'artiste
		// cliquait « Enregistrer une œuvre » et tombait sur la page hors
		// connexion, sans jamais atteindre l'écran qui, lui, fonctionnait.
		if ( $page_formulaire ) {
			$adresses = array_merge( $adresses, self::etapes_du_menu( $page_formulaire, $adresses ) );
		}

		set_transient( 'afk_pwa_pages_oeuvres', $adresses, DAY_IN_SECONDS );

		return $adresses;
	}

	/**
	 * Adresse de la page portant le formulaire.
	 *
	 * @return string
	 */
	/**
	 * Adresse de la page portant la liste des œuvres.
	 *
	 * Détectée comme celle du formulaire : la page se renomme, se
	 * déplace, se duplique. Ce qui la définit, c'est le code court
	 * qu'elle contient.
	 *
	 * @return string
	 */
	public static function page_liste() {
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids' ) ) as $id ) {
			$structure = get_post_meta( $id, '_elementor_data', true );

			$porte = ( is_string( $structure ) && false !== strpos( $structure, self::SHORTCODE ) )
				|| has_shortcode( (string) get_post_field( 'post_content', $id ), self::SHORTCODE );

			if ( $porte ) {
				return (string) get_permalink( $id );
			}
		}

		return '';
	}

	public static function page_formulaire() {
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids' ) ) as $id ) {
			$structure = get_post_meta( $id, '_elementor_data', true );

			$porte = ( is_string( $structure ) && (
					false !== strpos( $structure, Africart_Artwork_Form::WIDGET_FORMULAIRE )
					|| false !== strpos( $structure, Africart_Artwork_Form::SHORTCODE )
				) )
				|| has_shortcode( (string) get_post_field( 'post_content', $id ), Africart_Artwork_Form::SHORTCODE );

			if ( $porte ) {
				return (string) get_permalink( $id );
			}
		}

		return '';
	}

	/**
	 * Pages du parcours de dépôt, atteintes par le menu.
	 *
	 * ⚠️ Le menu du site ne pointe pas sur le formulaire, mais sur une
	 * page d'explications qui y renvoie. Ne garder que le formulaire
	 * laissait le parcours coupé en son milieu : l'artiste cliquait
	 * « Enregistrer une œuvre » et tombait sur la page hors connexion,
	 * sans jamais atteindre l'écran qui, lui, fonctionnait.
	 *
	 * ⚠️ Mais « toute page qui mène au formulaire » est trop large : sur
	 * ce site, sept pages y renvoient, dont l'accueil et trois pages
	 * éditoriales. On garderait sous la main la page la plus lourde du
	 * site pour chaque artiste.
	 *
	 * Le départ se fait donc sur ce que l'artiste **suit réellement** :
	 * une entrée de menu. Et l'on écarte les pages qui portent un flux
	 * public d'œuvres — l'accueil en est une.
	 *
	 * @param int      $page_formulaire Page portant le formulaire.
	 * @param string[] $deja            Adresses déjà retenues.
	 * @return string[]
	 */
	private static function etapes_du_menu( $page_formulaire, $deja ) {
		// Borne haute. Sans elle, ajouter des liens vers le formulaire
		// dans le menu ferait grossir sans fin ce qu'un artiste télécharge
		// à sa première visite.
		$plafond = 4;

		$cible = get_permalink( $page_formulaire );
		$slug  = get_post_field( 'post_name', $page_formulaire );
		$vues  = array();

		foreach ( get_posts( array( 'post_type' => 'nav_menu_item', 'posts_per_page' => 200 ) ) as $item ) {
			$id = (int) get_post_meta( $item->ID, '_menu_item_object_id', true );

			if ( ! $id || 'page' !== get_post_meta( $item->ID, '_menu_item_object', true ) ) {
				continue;
			}

			$adresse = get_permalink( $id );

			if ( ! $adresse || in_array( $adresse, $deja, true ) || in_array( $adresse, $vues, true ) ) {
				continue;
			}

			$structure = (string) get_post_meta( $id, '_elementor_data', true );

			// Une page qui montre les œuvres du site n'est pas une étape
			// du dépôt, même si elle y renvoie.
			if ( false !== strpos( $structure, self::WIDGET_FLUX ) ) {
				continue;
			}

			$tout = (string) get_post_field( 'post_content', $id ) . $structure;

			if ( false !== strpos( $tout, $cible ) || false !== strpos( $tout, '/' . $slug . '/' ) ) {
				$vues[] = $adresse;
			}

			if ( count( $vues ) >= $plafond ) {
				break;
			}
		}

		return $vues;
	}

	/**
	 * Le socle commun : la base locale et la file d'envoi.
	 *
	 * ⚠️ Déclaré à un seul endroit et partagé. Deux modules qui
	 * chargeraient chacun leur copie de la base finiraient par en
	 * déclarer deux versions, et IndexedDB refuserait l'accès au second.
	 * C'est le défaut que ce regroupement répare.
	 */
	public static function enqueue_socle() {
		// ⚠️ Avant tout le reste, et sans dépendance.
		//
		// Il note ce que les autres font : chargé après eux, il aurait
		// manqué précisément les premières lignes — celles de la mise en
		// route, où se produisent les défauts qu'on n'arrive pas à
		// reproduire. Il est inerte tant que `?afk_debug=1` n'a pas été
		// demandé : il enregistre, il n'affiche pas.
		wp_enqueue_script(
			'afk-pwa-journal',
			AFK_PWA_URL . 'assets/js/journal.js',
			array(),
			afk_pwa_asset_version( 'assets/js/journal.js' ),
			true
		);

		wp_enqueue_script(
			'afk-pwa-oeuvres-db',
			AFK_PWA_URL . 'assets/js/oeuvres-db.js',
			array( 'afk-pwa-journal' ),
			afk_pwa_asset_version( 'assets/js/oeuvres-db.js' ),
			true
		);

		wp_enqueue_script(
			'afk-pwa-oeuvres-sync',
			AFK_PWA_URL . 'assets/js/oeuvres-sync.js',
			array( 'afk-pwa-oeuvres-db' ),
			afk_pwa_asset_version( 'assets/js/oeuvres-sync.js' ),
			true
		);

		// ⚠️ L'arbitrage des conflits fait partie du socle, comme l'état :
		// un conflit s'annonce sur la page des œuvres comme sur celle du
		// dépôt, et doit pouvoir se trancher depuis les deux.
		wp_enqueue_script(
			'afk-pwa-oeuvres-conflit',
			AFK_PWA_URL . 'assets/js/oeuvres-conflit.js',
			array( 'afk-pwa-oeuvres-db', 'afk-pwa-oeuvres-sync' ),
			afk_pwa_asset_version( 'assets/js/oeuvres-conflit.js' ),
			true
		);

		// L'état du dépôt, dit à l'écran. Il fait partie du socle et non
		// du seul formulaire : la page des œuvres doit annoncer les mêmes
		// choses, et l'artiste y passe autant de temps.
		wp_enqueue_script(
			'afk-pwa-oeuvres-etat',
			AFK_PWA_URL . 'assets/js/oeuvres-etat.js',
			array( 'afk-pwa-oeuvres-db', 'afk-pwa-oeuvres-sync' ),
			afk_pwa_asset_version( 'assets/js/oeuvres-etat.js' ),
			true
		);
	}

	/**
	 * La page affichée porte-t-elle le flux des œuvres ?
	 *
	 * Lu dans la structure Elementor plutôt que deviné à l'adresse : la
	 * page peut être renommée, déplacée, dupliquée. Ce qui la définit,
	 * c'est le widget qu'elle contient.
	 *
	 * @return bool
	 */
	private function page_porte_le_flux() {
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

		if ( ! is_string( $structure ) || '' === $structure ) {
			return false;
		}

		if ( false !== strpos( $structure, self::SHORTCODE ) ) {
			return true;
		}

		return false !== strpos( $structure, self::WIDGET_FLUX )
			&& false !== strpos( $structure, self::FILTRE_PERSONNEL );
	}
}
