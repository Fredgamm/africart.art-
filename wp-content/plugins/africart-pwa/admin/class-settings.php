<?php
/**
 * Réglages admin — personnalisation de tous les attributs de la PWA.
 *
 * Menu principal « AFRICART PWA », une page par module : Identité,
 * Apparence, Icônes & visuels, Installation, Performance, Partage & SEO,
 * Notifications, Langues, Hors-ligne & avancé.
 *
 * Toutes les pages partagent une seule option, `afk_pwa_settings`. Le
 * découpage impose donc une précaution : chaque page déclare les clés
 * qui lui appartiennent (voir pages()) et l'enregistrement ne touche
 * qu'à celles-ci — sans quoi valider une page effacerait les réglages
 * de toutes les autres.
 *
 * Les modules lisent via Africart_PWA_Settings::get(), qui retombe sur
 * des valeurs par défaut saines si un champ est vide.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Settings {

	const OPTION = 'afk_pwa_settings';
	const PAGE   = 'africart-pwa';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_reset' ) );
		add_action( 'admin_notices', array( $this, 'reset_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Aperçu des partages : lit la page d'un contenu et en extrait
		// les balises que les réseaux sociaux liront.
		add_action( 'wp_ajax_afk_share_preview', array( $this, 'ajax_share_preview' ) );
		// Correction du nom de site chez Rank Math, sur clic explicite.
		add_action( 'wp_ajax_afk_fix_site_name', array( $this, 'ajax_fix_site_name' ) );
		// Clé d'envoi : sa propre requête, hors du formulaire des réglages.
		add_action( 'wp_ajax_afk_save_push_key', array( $this, 'ajax_save_push_key' ) );

		// Un changement de réglages modifie le <head> de toutes les pages
		// (couleurs, icônes, manifest) : on demande à LiteSpeed — actif en
		// production — de purger son cache de pages, sans quoi les visiteurs
		// garderaient l'ancien rendu jusqu'à expiration. Sans écouteur,
		// cette action ne fait rien : aucune dépendance n'est créée.
		add_action( 'update_option_' . self::OPTION, array( $this, 'purge_page_cache' ) );
	}

	/**
	 * Demande la purge du cache de pages, si un outil en fournit une.
	 */
	public function purge_page_cache() {
		do_action( 'litespeed_purge_all' );
	}

	/* ------------------------------------------------------------------
	 * Lecture des réglages (utilisée par tous les modules)
	 * ---------------------------------------------------------------- */

	/**
	 * Retourne la valeur d'un réglage, ou sa valeur par défaut si vide.
	 *
	 * @param string $key      Clé du réglage.
	 * @param mixed  $fallback Valeur si ni réglage ni défaut.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$opts = get_option( self::OPTION, array() );
		if ( isset( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
			return $opts[ $key ];
		}
		$defaults = self::defaults();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : $fallback;
	}

	/**
	 * Valeurs par défaut.
	 *
	 * Aucune valeur n'est écrite ici : elles vivent toutes dans
	 * `defaults/factory-settings.json`, lisible et modifiable sans
	 * toucher au code. Voir `defaults/README.md`.
	 *
	 * @return array
	 */
	public static function defaults() {
		return Africart_PWA_Factory_Defaults::all();
	}

	/* ------------------------------------------------------------------
	 * Accesseurs du module Performance
	 *
	 * Regroupés ici pour que les classes du module n'aient pas à
	 * connaître les clés de réglage ni leur format.
	 * ---------------------------------------------------------------- */

	/**
	 * La conversion des images est-elle active ?
	 *
	 * @return bool
	 */
	public static function is_conversion_enabled() {
		return '1' === self::get( 'perf_conversion' );
	}

	/**
	 * Les variantes doivent-elles être proposées dans les pages ?
	 *
	 * @return bool
	 */
	public static function is_rewrite_enabled() {
		return '1' === self::get( 'perf_rewrite' );
	}

	/**
	 * Le chargement des fonds hors écran est-il différé ?
	 *
	 * @return bool
	 */
	public static function is_lazy_enabled() {
		return '1' === self::get( 'perf_lazy' );
	}

	/**
	 * Nombre de fonds chargés immédiatement, avant tout report.
	 *
	 * @return int
	 */
	public static function lazy_eager_count() {
		return max( 0, (int) self::get( 'perf_lazy_eager' ) );
	}

	/**
	 * Formats à produire, par ordre de préférence, en ne gardant que
	 * ceux que le serveur sait réellement encoder.
	 *
	 * @return string[]
	 */
	public static function enabled_formats() {
		$formats = array();

		foreach ( array( 'avif', 'webp' ) as $format ) {
			if ( '1' !== self::get( 'perf_' . $format ) ) {
				continue;
			}
			if ( class_exists( 'Africart_PWA_Image_Converter' ) && ! Africart_PWA_Image_Converter::supports( $format ) ) {
				continue;
			}
			$formats[] = $format;
		}

		return $formats;
	}

	/**
	 * L'optimisation du chargement des polices est-elle active ?
	 *
	 * @return bool
	 */
	public static function is_fonts_enabled() {
		return '1' === self::get( 'perf_fonts' );
	}

	/**
	 * Qualité d'encodage d'un format.
	 *
	 * @param string $format 'avif' ou 'webp'.
	 * @return int
	 */
	public static function quality_for( $format ) {
		$value = (int) self::get( 'perf_' . $format . '_quality' );
		return $value > 0 ? min( 100, $value ) : ( 'avif' === $format ? 55 : 82 );
	}

	/* ------------------------------------------------------------------
	 * Menu, enregistrement, assets
	 * ---------------------------------------------------------------- */


	public function register_settings() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
		register_setting(
			self::PAGE,
			Africart_PWA_I18n::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize_i18n' ) )
		);
	}

	public function enqueue_assets( $hook ) {
		// Le menu principal produit plusieurs identifiants d'ecran
		// (toplevel_page_..., africart-pwa_page_...) : on les reconnait
		// tous par leur racine commune.
		if ( ! $this->is_settings_screen( $hook ) ) {
			return;
		}
		// Les couleurs se saisissent avec le sélecteur natif du navigateur,
		// pas avec celui de WordPress : sa feuille de style et son script
		// n'ont donc pas à être chargés.
		wp_enqueue_style( 'afk-pwa-settings', AFK_PWA_URL . 'admin/settings.css', array(), afk_pwa_asset_version( 'admin/settings.css' ) );
		wp_enqueue_media();
		wp_enqueue_script(
			'afk-pwa-settings',
			AFK_PWA_URL . 'admin/settings.js',
			array( 'jquery' ),
			afk_pwa_asset_version( 'admin/settings.js' ),
			true
		);

		// Adressage de la notification de test. wp_add_inline_script plutôt
		// que wp_localize_script : ce dernier convertit tout en chaîne.
		wp_add_inline_script(
			'afk-pwa-settings',
			'window.afkSettings = ' . wp_json_encode(
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'pushNonce'    => wp_create_nonce( 'afk_push_test' ),
					'sending'      => __( 'Envoi…', 'africart-pwa' ),
					'failed'       => __( 'Échec de l’envoi.', 'africart-pwa' ),
					'shareNonce'   => wp_create_nonce( 'afk_share_preview' ),
					'shareLoading' => __( 'Lecture de la page…', 'africart-pwa' ),
					'shareFailed'  => __( 'Impossible de lire la page.', 'africart-pwa' ),
					'fixNameNonce' => wp_create_nonce( 'afk_fix_site_name' ),
					'keyNonce'     => wp_create_nonce( 'afk_save_push_key' ),
					'testNonce'    => wp_create_nonce( 'afk_push_test_publish' ),
					'keySaving'    => __( 'Enregistrement…', 'africart-pwa' ),
				)
			) . ';',
			'before'
		);
	}

	/* ------------------------------------------------------------------
	 * Sanitisation
	 * ---------------------------------------------------------------- */

	public function sanitize( $input ) {
		// Les reglages sont desormais repartis sur plusieurs pages, mais
		// partagent une seule option. Sans fusion, enregistrer une page
		// effacerait les reglages de toutes les autres.
		$existant = get_option( self::OPTION, array() );
		$existant = is_array( $existant ) ? $existant : array();

		if ( ! is_array( $input ) ) {
			return $existant;
		}

		$page  = isset( $input['_afk_page'] ) ? sanitize_key( $input['_afk_page'] ) : '';
		$pages = self::pages();
		unset( $input['_afk_page'] );

		$out = array();

		$text_keys = array( 'app_name', 'short_name', 'description', 'lang', 'languages', 'categories', 'shortcut_name', 'offline_title', 'offline_button', 'install_title', 'install_message', 'install_button', 'install_ios', 'install_inapp', 'install_samsung', 'os_app_id', 'os_safari_id', 'privacy_url', 'push_title', 'push_choose', 'push_privacy', 'push_unsub', 'push_manage', 'push_prompt', 'push_accept', 'push_refuse', 'seo_post_type', 'stats_gtm_id', 'stats_ga4_id', 'consent_title', 'consent_accept', 'consent_refuse' );
		foreach ( $text_keys as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $input[ $k ] );
			}
		}

		// ⚠️ Une adresse, ou rien du tout.
		//
		// Une saisie approximative — un nom, deux adresses séparées par une
		// virgule — serait acceptée par `sanitize_text_field` et ferait
		// échouer l'envoi en silence : l'équipe cesserait d'être prévenue
		// sans que personne ne sache pourquoi. On préfère ignorer une
		// adresse invalide et retomber sur le destinataire du thème.
		if ( isset( $input['art_notifs_email'] ) ) {
			$adresse = sanitize_email( wp_unslash( $input['art_notifs_email'] ) );
			$out['art_notifs_email'] = is_email( $adresse ) ? $adresse : '';
		}

		// Textes du module Œuvres. Nettoyés comme du texte simple : ils
		// s'affichent dans des nœuds de texte, jamais interprétés.
		foreach ( array( 'art_etat_draft', 'art_etat_review', 'art_etat_published', 'art_etat_rejected', 'art_etat_unpublished', 'art_sync_attente', 'art_sync_encours', 'art_sync_incomplet', 'art_sync_conflit', 'art_sync_erreur', 'art_hors_ligne', 'art_injoignable', 'art_vide', 'art_form_avis', 'art_form_absent', 'art_form_depot', 'art_form_enfile', 'art_form_partira', 'art_form_enregistre', 'art_form_titre_dabord', 'art_form_sortie', 'art_form_encours', 'art_form_maj', 'art_form_absente' ) as $cle ) {
			if ( isset( $input[ $cle ] ) ) {
				$out[ $cle ] = sanitize_text_field( $input[ $cle ] );
			}
		}

		if ( isset( $input['consent_text'] ) ) {
			$out['consent_text'] = sanitize_textarea_field( $input['consent_text'] );
		}

		if ( isset( $input['offline_message'] ) ) {
			$out['offline_message'] = sanitize_textarea_field( $input['offline_message'] );
		}

		// Scripts a differer : un fragment d'adresse par ligne.
		if ( isset( $input['perf_delay_urls'] ) ) {
			$out['perf_delay_urls'] = sanitize_textarea_field( $input['perf_delay_urls'] );
		}

		// Listes déroulantes à valeurs fermées : une valeur inconnue vaut
		// « suivre le site », jamais une écriture au hasard.
		if ( isset( $input['art_statut_creation'] ) ) {
			$choix = sanitize_key( $input['art_statut_creation'] );
			$out['art_statut_creation'] = in_array( $choix, array( 'draft', 'pending', 'publish' ), true ) ? $choix : '';
		}

		if ( isset( $input['art_suppression'] ) ) {
			$choix = sanitize_text_field( $input['art_suppression'] );
			$out['art_suppression'] = in_array( $choix, array( '0', '1' ), true ) ? $choix : '';
		}

		$color_keys = array( 'theme_color', 'background_color', 'accent_color' );
		foreach ( $color_keys as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$c = sanitize_hex_color( $input[ $k ] );
				$out[ $k ] = $c ? $c : '';
			}
		}

		$url_keys = array( 'start_url', 'icon_192', 'icon_512', 'icon_maskable', 'apple_touch_icon', 'screenshot_wide', 'screenshot_mobile', 'shortcut_url', 'shortcut_icon', 'install_gif_android', 'install_gif_ios', 'install_gif_inapp' );
		foreach ( $url_keys as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = esc_url_raw( trim( $input[ $k ] ) );
			}
		}

		$int_keys = array( 'screenshot_wide_w', 'screenshot_wide_h', 'screenshot_mobile_w', 'screenshot_mobile_h', 'banner_delay', 'banner_dismiss_days', 'banner_gif_height', 'perf_avif_quality', 'perf_webp_quality', 'perf_lazy_eager', 'perf_delay_timeout', 'os_popup_delay', 'os_popup_days', 'consent_days' );
		foreach ( $int_keys as $k ) {
			if ( ! isset( $input[ $k ] ) ) {
				continue;
			}
			// Champ laissé vide : on enregistre une chaîne vide, pas 0, pour
			// que get() retombe sur la valeur par défaut. Sans ça, une hauteur
			// de visuel « vide » deviendrait 0 px et masquerait le GIF.
			$out[ $k ] = ( '' === trim( (string) $input[ $k ] ) ) ? '' : absint( $input[ $k ] );
		}

		if ( isset( $input['banner_position'] ) ) {
			$out['banner_position'] = 'top' === $input['banner_position'] ? 'top' : 'bottom';
		}

		// Correspondance des champs d'œuvre : nettoyage délégué au module.
		if ( isset( $input[ Africart_PWA_Social_Meta::MAP_KEY ] ) ) {
			$out[ Africart_PWA_Social_Meta::MAP_KEY ] = Africart_PWA_Social_Meta::sanitize_map( $input[ Africart_PWA_Social_Meta::MAP_KEY ] );
		}

		// Centres d'intérêt des notifications : nettoyage délégué au module.
		if ( isset( $input[ Africart_PWA_OneSignal::CATEGORIES_KEY ] ) ) {
			$out[ Africart_PWA_OneSignal::CATEGORIES_KEY ] = Africart_PWA_OneSignal::sanitize_categories( $input[ Africart_PWA_OneSignal::CATEGORIES_KEY ] );
		}

		// Déclencheurs d'envoi : nettoyage délégué au module.
		if ( isset( $input[ Africart_PWA_Push_Sender::TRIGGERS_KEY ] ) ) {
			$out[ Africart_PWA_Push_Sender::TRIGGERS_KEY ] = Africart_PWA_Push_Sender::sanitize_triggers( $input[ Africart_PWA_Push_Sender::TRIGGERS_KEY ] );
		}

		// La clé d'envoi ne passe plus par ce formulaire : elle a sa propre
		// requête (voir ajax_save_push_key). Une valeur reçue ici viendrait
		// d'une version antérieure du formulaire — on l'ignore.
		unset( $input['push_key'] );

		// Fonds CSS déclarés (sélecteur + image) : nettoyage délégué au
		// module, qui connaît la structure de ces lignes.
		if ( isset( $input[ Africart_PWA_CSS_Backgrounds::OPTION_KEY ] ) ) {
			$out[ Africart_PWA_CSS_Backgrounds::OPTION_KEY ] = Africart_PWA_CSS_Backgrounds::sanitize( $input[ Africart_PWA_CSS_Backgrounds::OPTION_KEY ] );
		}

		// Une case decochee n'est pas transmise par le navigateur : on ne
		// peut donc la remettre a zero que si sa page a bien ete soumise.
		//
		// ⚠️ On ecrit « 0 », et non une chaine vide.
		//
		// Le vide a un sens ici : `get()` le lit comme « rien de choisi »
		// et rend la valeur d'usine. Une bascule dont l'usine vaut « 1 »
		// etait donc IMPOSSIBLE a eteindre : on la decochait, on
		// enregistrait, elle revenait cochee — et rien ne l'expliquait.
		// Seize des vingt et une bascules etaient dans ce cas, dont le
		// service worker, les notifications et toute la performance.
		//
		// « 0 » est une valeur choisie, qui l'emporte sur l'usine. « 0 »
		// reste faux pour PHP : rien de ce qui teste ce reglage ne change.
		$toggles = isset( $pages[ $page ]['toggles'] ) ? $pages[ $page ]['toggles'] : array();
		foreach ( $toggles as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? '0' : '1';
		}

		$display_allowed = array( 'standalone', 'fullscreen', 'minimal-ui', 'browser' );
		if ( isset( $input['display'] ) ) {
			$out['display'] = in_array( $input['display'], $display_allowed, true ) ? $input['display'] : 'standalone';
		}

		$popup_allowed = array( 'both', 'pwa', 'browser' );
		if ( isset( $input['os_popup_context'] ) ) {
			$out['os_popup_context'] = in_array( $input['os_popup_context'], $popup_allowed, true )
				? $input['os_popup_context']
				: 'both';
		}

		$barre_allowed = array( 'default', 'black', 'black-translucent' );
		if ( isset( $input['ios_status_bar'] ) ) {
			$out['ios_status_bar'] = in_array( $input['ios_status_bar'], $barre_allowed, true )
				? $input['ios_status_bar']
				: 'default';
		}

		$orientation_allowed = array( 'portrait-primary', 'portrait', 'landscape', 'any', 'natural' );
		if ( isset( $input['orientation'] ) ) {
			$out['orientation'] = in_array( $input['orientation'], $orientation_allowed, true ) ? $input['orientation'] : 'portrait-primary';
		}

		return array_merge( $existant, $out );
	}

	/**
	 * Sanitise les textes multilingues : [lang][clé] => texte.
	 *
	 * @param mixed $input Données du formulaire.
	 * @return array
	 */
	public function sanitize_i18n( $input ) {
		$out = array();
		if ( ! is_array( $input ) ) {
			return $out;
		}
		$keys = array_keys( Africart_PWA_I18n::translatable_keys() );
		foreach ( $input as $lang => $texts ) {
			$lang = strtolower( sanitize_key( $lang ) );
			if ( ! is_array( $texts ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( isset( $texts[ $key ] ) && '' !== trim( $texts[ $key ] ) ) {
					$out[ $lang ][ $key ] = ( 'offline_message' === $key )
						? sanitize_textarea_field( $texts[ $key ] )
						: sanitize_text_field( $texts[ $key ] );
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Rendu de la page
	 * ---------------------------------------------------------------- */

	/* ------------------------------------------------------------------
	 * Menu principal et pages
	 *
	 * Chaque module a sa page : l'écran reste lisible à mesure que le
	 * plugin s'étoffe, et l'adresse de chaque réglage est partageable.
	 * ---------------------------------------------------------------- */

	/**
	 * Définition des pages : titre de menu, titre de page, et clés de
	 * réglage que la page possède.
	 *
	 * Les clés servent à l'enregistrement : une page ne modifie que ce
	 * qui lui appartient, le reste des réglages est préservé intact.
	 *
	 * @return array
	 */
	public static function pages() {
		return array(
			'identity'  => array(
				'menu'     => __( 'Identité', 'africart-pwa' ),
				'title'    => __( 'Identité de l’application', 'africart-pwa' ),
				'keys'     => array( 'app_name', 'short_name', 'description', 'categories', 'shortcut_name', 'shortcut_url', 'shortcut_icon' ),
				'toggles'  => array(),
			),
			'look'      => array(
				'menu'     => __( 'Apparence', 'africart-pwa' ),
				'title'    => __( 'Apparence et comportement', 'africart-pwa' ),
				'keys'     => array( 'theme_color', 'background_color', 'accent_color', 'display', 'orientation', 'start_url', 'ios_status_bar' ),
				'toggles'  => array(),
			),
			'images'    => array(
				'menu'     => __( 'Icônes & visuels', 'africart-pwa' ),
				'title'    => __( 'Icônes et visuels', 'africart-pwa' ),
				'keys'     => array( 'icon_192', 'icon_512', 'icon_maskable', 'apple_touch_icon', 'screenshot_wide', 'screenshot_wide_w', 'screenshot_wide_h', 'screenshot_mobile', 'screenshot_mobile_w', 'screenshot_mobile_h' ),
				'toggles'  => array(),
			),
			'install'   => array(
				'menu'     => __( 'Installation', 'africart-pwa' ),
				'title'    => __( 'Installation de l’application', 'africart-pwa' ),
				'keys'     => array( 'banner_position', 'banner_delay', 'banner_dismiss_days', 'install_gif_android', 'install_gif_ios', 'install_gif_inapp', 'banner_gif_height', 'install_title', 'install_message', 'install_button', 'install_ios', 'install_inapp', 'install_samsung' ),
				'toggles'  => array( 'banner_enabled', 'banner_ios', 'banner_inapp' ),
			),
			'perf'      => array(
				'menu'     => __( 'Performance', 'africart-pwa' ),
				'title'    => __( 'Performance', 'africart-pwa' ),
				'keys'     => array( 'perf_avif_quality', 'perf_webp_quality', 'perf_lazy_eager', 'perf_delay_timeout', 'perf_delay_urls', 'perf_css_backgrounds' ),
				'toggles'  => array( 'perf_conversion', 'perf_rewrite', 'perf_avif', 'perf_webp', 'perf_fonts', 'perf_lazy', 'perf_delay' ),
			),
			'seo'       => array(
				'menu'     => __( 'Partage & SEO', 'africart-pwa' ),
				'title'    => __( 'Partage sur les réseaux et référencement', 'africart-pwa' ),
				'keys'     => array( 'seo_post_type', 'seo_field_map' ),
				'toggles'  => array( 'seo_enabled' ),
			),
			'push'      => array(
				'menu'     => __( 'Notifications', 'africart-pwa' ),
				'title'    => __( 'Notifications push', 'africart-pwa' ),
				'keys'     => array( 'os_app_id', 'os_safari_id', 'os_popup_delay', 'os_popup_days', 'os_popup_context', 'privacy_url', 'os_categories', 'os_triggers', 'push_title', 'push_choose', 'push_prompt', 'push_accept', 'push_refuse' ),
				'toggles'  => array( 'os_enabled', 'os_popup', 'os_bell' ),
			),
			'artworks'  => array(
				'menu'     => __( 'Œuvres', 'africart-pwa' ),
				'title'    => __( 'Dépôt des œuvres', 'africart-pwa' ),
				'keys'     => array( 'art_notifs_email', 'art_statut_creation', 'art_suppression', 'art_etat_draft', 'art_etat_review', 'art_etat_published', 'art_etat_rejected', 'art_etat_unpublished', 'art_sync_attente', 'art_sync_encours', 'art_sync_incomplet', 'art_sync_conflit', 'art_sync_erreur', 'art_hors_ligne', 'art_injoignable', 'art_vide', 'art_form_avis', 'art_form_absent', 'art_form_depot', 'art_form_enfile', 'art_form_partira', 'art_form_enregistre', 'art_form_titre_dabord', 'art_form_sortie', 'art_form_encours' ),
				'toggles'  => array( 'art_form_toujours', 'art_notifs' ),
			),
			'stats'     => array(
				'menu'     => __( 'Statistiques', 'africart-pwa' ),
				'title'    => __( 'Statistiques et suivi', 'africart-pwa' ),
				'keys'     => array( 'stats_gtm_id', 'stats_ga4_id', 'consent_title', 'consent_text', 'consent_accept', 'consent_refuse', 'consent_days' ),
				'toggles'  => array( 'stats_enabled', 'stats_inject', 'stats_consent', 'consent_enabled' ),
			),
			'languages' => array(
				'menu'     => __( 'Langues', 'africart-pwa' ),
				'title'    => __( 'Langues et traductions', 'africart-pwa' ),
				'keys'     => array( 'lang', 'languages' ),
				'toggles'  => array(),
			),
			// ⚠️ Une page sans réglage : elle documente, elle n'enregistre
			// rien. `lecture` retire le formulaire, le bouton d'envoi et le
			// retour aux valeurs d'usine — proposer « Enregistrer » sur un
			// écran qui n'a rien à enregistrer laisse croire qu'on a oublié
			// de le faire.
			'codes'     => array(
				'menu'     => __( 'Codes courts', 'africart-pwa' ),
				'title'    => __( 'Codes courts', 'africart-pwa' ),
				'keys'     => array(),
				'toggles'  => array(),
				'lecture'  => true,
			),
			'advanced'  => array(
				'menu'     => __( 'Hors-ligne & avancé', 'africart-pwa' ),
				'title'    => __( 'Mode hors-ligne et réglages avancés', 'africart-pwa' ),
				'keys'     => array( 'offline_title', 'offline_message', 'offline_button' ),
				'toggles'  => array( 'sw_enabled' ),
			),
		);
	}

	/**
	 * Crée le menu principal et ses pages.
	 */
	public function add_menu() {
		$pages = self::pages();
		$icone = 'dashicons-smartphone';

		add_menu_page(
			__( 'AFRICART PWA', 'africart-pwa' ),
			__( 'AFRICART PWA', 'africart-pwa' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_identity_page' ),
			$icone,
			58 // Juste sous Apparence, avec les autres outils de site.
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page(
				self::PAGE,
				$page['title'],
				$page['menu'],
				'manage_options',
				'identity' === $slug ? self::PAGE : self::PAGE . '-' . $slug,
				array( $this, 'render_' . $slug . '_page' )
			);
		}
	}

	/**
	 * Les pages de réglage sont-elles à l'écran ?
	 *
	 * @param string $hook Identifiant de l'écran courant.
	 * @return bool
	 */
	private function is_settings_screen( $hook ) {
		return false !== strpos( $hook, self::PAGE );
	}


	/* ------------------------------------------------------------------
	 * Rendu des pages
	 * ---------------------------------------------------------------- */

	/**
	 * Page « identity ».
	 */
	public function render_identity_page() {
		$this->render_shell( 'identity' );
	}

	/**
	 * Page « look ».
	 */
	public function render_look_page() {
		$this->render_shell( 'look' );
	}

	/**
	 * Page « images ».
	 */
	public function render_images_page() {
		$this->render_shell( 'images' );
	}

	/**
	 * Page « install ».
	 */
	public function render_install_page() {
		$this->render_shell( 'install' );
	}

	/**
	 * Page « perf ».
	 */
	public function render_perf_page() {
		$this->render_shell( 'perf' );
	}

	/**
	 * Page « push ».
	 */
	public function render_push_page() {
		// Les abonnés ne sont pas un réglage : ils partagent seulement le
		// sujet. Un onglet plutôt qu'une entrée de menu — le menu du
		// plugin en compte déjà neuf, et « Abonnés » seul n'y disait pas
		// de quoi il s'agissait.
		if ( 'abonnes' === self::onglet_push() ) {
			Africart_PWA_Subscribers::instance()->render();
			return;
		}

		$this->render_shell( 'push' );
	}

	/**
	 * Onglet courant de l'écran Notifications.
	 *
	 * @return string `reglages` ou `abonnes`.
	 */
	public static function onglet_push() {
		// Simple lecture d'affichage, sans effet de bord : le nonce n'a
		// pas lieu d'être ici.
		$onglet = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 'abonnes' === $onglet ? 'abonnes' : 'reglages';
	}

	/**
	 * Barre d'onglets de l'écran Notifications.
	 *
	 * Partagée par les deux écrans, pour que la barre soit rigoureusement
	 * la même — un onglet qui bouge d'un pixel entre deux vues se
	 * remarque immédiatement.
	 *
	 * @param string $courant Onglet actif.
	 */
	public static function render_push_tabs( $courant ) {
		$onglets = array(
			'reglages' => __( 'Paramétrage', 'africart-pwa' ),
			'abonnes'  => __( 'Abonnés', 'africart-pwa' ),
		);

		$base = admin_url( 'admin.php?page=' . self::PAGE . '-push' );
		?>
		<h2 class="nav-tab-wrapper afk-onglets">
			<?php foreach ( $onglets as $cle => $intitule ) : ?>
				<a class="nav-tab<?php echo $courant === $cle ? ' nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( 'reglages' === $cle ? $base : add_query_arg( 'tab', $cle, $base ) ); ?>">
					<?php echo esc_html( $intitule ); ?>
				</a>
			<?php endforeach; ?>
		</h2>
		<?php
	}

	/**
	 * Page « œuvres ».
	 */
	public function render_artworks_page() {
		$this->render_shell( 'artworks' );
	}

	/**
	 * Page « codes courts ».
	 */
	public function render_codes_page() {
		$this->render_shell( 'codes' );
	}

	/**
	 * Page « statistiques ».
	 */
	public function render_stats_page() {
		$this->render_shell( 'stats' );
	}

	/**
	 * Page « seo ».
	 */
	public function render_seo_page() {
		$this->render_shell( 'seo' );
	}

	/**
	 * Page « languages ».
	 */
	public function render_languages_page() {
		$this->render_shell( 'languages' );
	}

	/**
	 * Page « advanced ».
	 */
	public function render_advanced_page() {
		$this->render_shell( 'advanced' );
	}

	/**
	 * Ossature commune : en-tête, formulaire, contenu de la page
	 * demandée, barre d'enregistrement.
	 *
	 * @param string $page Identifiant de la page.
	 */
	/* ------------------------------------------------------------------
	 * Retour aux valeurs d'usine
	 * ---------------------------------------------------------------- */

	/**
	 * Adresse de réinitialisation d'un écran.
	 *
	 * @param string $page Identifiant de l'écran.
	 * @return string
	 */
	private function reset_url( $page ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'      => 'identity' === $page ? self::PAGE : self::PAGE . '-' . $page,
					'afk_reset' => $page,
				),
				admin_url( 'admin.php' )
			),
			'afk_reset_' . $page
		);
	}

	/**
	 * Rétablit les valeurs d'usine d'un écran.
	 *
	 * Les réglages de l'écran sont **retirés** de l'option, ils ne sont
	 * pas réécrits avec les valeurs du fichier : un réglage absent va
	 * chercher sa valeur d'usine à la lecture. L'écran suit donc
	 * automatiquement toute modification ultérieure du fichier, ce qu'une
	 * recopie aurait figé.
	 */
	public function maybe_reset() {
		if ( empty( $_GET['afk_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$page  = sanitize_key( wp_unslash( $_GET['afk_reset'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pages = self::pages();

		if ( ! isset( $pages[ $page ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'afk_reset_' . $page );

		$options = get_option( self::OPTION, array() );
		$options = is_array( $options ) ? $options : array();

		$concernees = array_merge(
			isset( $pages[ $page ]['keys'] ) ? $pages[ $page ]['keys'] : array(),
			isset( $pages[ $page ]['toggles'] ) ? $pages[ $page ]['toggles'] : array()
		);

		foreach ( $concernees as $cle ) {
			unset( $options[ $cle ] );
		}

		update_option( self::OPTION, $options );

		// La clé d'envoi est hors de ces réglages : la réinitialisation de
		// l'écran Notifications doit l'emporter elle aussi, sans quoi une
		// clé oubliée survivrait à une remise à zéro.
		if ( 'push' === $page && class_exists( 'Africart_PWA_Push_Sender' ) ) {
			Africart_PWA_Push_Sender::forget_legacy_key();
		}

		wp_safe_redirect(
			add_query_arg(
				'afk_reset_done',
				'1',
				remove_query_arg( array( 'afk_reset', '_wpnonce' ) )
			)
		);
		exit;
	}

	/**
	 * Confirme la réinitialisation à l'écran.
	 */
	public function reset_notice() {
		if ( empty( $_GET['afk_reset_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Valeurs d’usine rétablies pour cet écran.', 'africart-pwa' )
		);
	}

	private function render_shell( $page ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$pages = self::pages();
		if ( ! isset( $pages[ $page ] ) ) {
			return;
		}

		$d = self::defaults();

		// Chaque champ affiche la valeur **qui s'applique réellement**, et non
		// un champ vide doublé d'un indice grisé. Un sélecteur de couleur ne
		// sait de toute façon pas afficher d'indice : il paraissait vide alors
		// qu'une couleur d'usine était bien en vigueur.
		//
		// La règle reproduite ici est exactement celle de get() : une valeur
		// enregistrée non vide l'emporte, sinon la valeur d'usine s'affiche.
		// On la recopie plutôt que d'appeler get() en boucle, qui relirait
		// l'option à chacune des soixante clés.
		$enregistres = get_option( self::OPTION, array() );
		$enregistres = is_array( $enregistres ) ? $enregistres : array();

		$o = $enregistres;
		foreach ( $d as $cle => $usine ) {
			$o[ $cle ] = ( isset( $enregistres[ $cle ] ) && '' !== $enregistres[ $cle ] )
				? $enregistres[ $cle ]
				: $usine;
		}
		$apercu = $this->preview_html( $page, $o );
		?>
		<div class="wrap afk-wrap<?php echo $apercu ? ' afk-wrap-large' : ''; ?>">
			<div class="afk-header">
				<img src="<?php echo esc_url( self::get( 'icon_192' ) ); ?>" alt="" />
				<div>
					<h1><?php echo esc_html( $pages[ $page ]['title'] ); ?></h1>
					<p>
						<?php esc_html_e( 'Application web installable AFRICART.', 'africart-pwa' ); ?>
						<a href="<?php echo esc_url( home_url( '/' . AFK_PWA_MANIFEST_SLUG ) ); ?>" target="_blank"><?php esc_html_e( 'Manifest', 'africart-pwa' ); ?></a> ·
						<a href="<?php echo esc_url( home_url( '/?afk_pwa=offline' ) ); ?>" target="_blank"><?php esc_html_e( 'Page hors-ligne', 'africart-pwa' ); ?></a>
					</p>
				</div>
			</div>

			<?php
			if ( 'push' === $page ) {
				self::render_push_tabs( 'reglages' );
			}
			?>

			<?php if ( ! empty( $pages[ $page ]['lecture'] ) ) : ?>
				<div class="afk-layout">
					<div class="afk-main">
						<?php $this->render_page_content( $page, $o, $d ); ?>
					</div>
				</div>
			<?php else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[_afk_page]" value="<?php echo esc_attr( $page ); ?>" />

				<div class="afk-layout">
					<div class="afk-main">
						<?php $this->render_page_content( $page, $o, $d ); ?>

						<div class="afk-savebar">
							<?php submit_button( __( 'Enregistrer', 'africart-pwa' ), 'primary', 'submit', false ); ?>
							<p class="description"><?php esc_html_e( 'Les réglages s’appliquent immédiatement.', 'africart-pwa' ); ?></p>
							<a class="afk-reset" href="<?php echo esc_url( $this->reset_url( $page ) ); ?>"
								onclick="return confirm( '<?php echo esc_js( __( 'Rétablir les valeurs d’usine de cet écran ? Les réglages saisis ici seront perdus.', 'africart-pwa' ) ); ?>' );">
								<?php esc_html_e( 'Rétablir les valeurs d’usine', 'africart-pwa' ); ?>
							</a>
						</div>
					</div>

					<?php if ( $apercu ) : ?>
						<aside class="afk-side">
							<?php
							// Déjà échappé à la construction : c'est notre propre
							// balisage, mis en tampon pour être placé ici.
							echo $apercu; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</aside>
					<?php endif; ?>
				</div>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Contenu propre à chaque page.
	 *
	 * @param string $page Identifiant de la page.
	 * @param array  $o    Réglages enregistrés.
	 * @param array  $d    Valeurs par défaut.
	 */
	private function render_page_content( $page, $o, $d ) {
		switch ( $page ) {
			case 'codes':
				$this->render_codes_fields();
				break;
			case 'artworks':
				$this->render_artworks_fields( $o, $d );
				break;
			case 'stats':
				$this->render_stats_fields( $o, $d );
				break;
			case 'identity':
				?>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Identité de l’application', 'africart-pwa' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Ce que voit l’utilisateur en installant l’app. Champ vide = valeur par défaut (grisée).', 'africart-pwa' ); ?></p>
						<table class="form-table" role="presentation">
							<?php
							$this->text_row( 'app_name', __( 'Nom de l’application', 'africart-pwa' ), $o, $d );
							$this->text_row( 'short_name', __( 'Nom court (sous l’icône)', 'africart-pwa' ), $o, $d, 'regular-text', __( '12 caractères max conseillé.', 'africart-pwa' ) );
							$this->text_row( 'description', __( 'Description', 'africart-pwa' ), $o, $d );
							$this->text_row( 'categories', __( 'Catégories', 'africart-pwa' ), $o, $d, 'regular-text', __( 'Séparées par des virgules (ex. art, shopping).', 'africart-pwa' ) );
							?>
						</table>
					</div>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Raccourci d’application', 'africart-pwa' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Entrée du menu qui s’ouvre en maintenant l’icône installée (Android).', 'africart-pwa' ); ?></p>
						<table class="form-table" role="presentation">
							<?php
							$this->text_row( 'shortcut_name', __( 'Libellé', 'africart-pwa' ), $o, $d );
							$this->text_row( 'shortcut_url', __( 'URL ouverte', 'africart-pwa' ), $o, $d, 'regular-text code' );
							$this->media_row( 'shortcut_icon', __( 'Icône (96×96)', 'africart-pwa' ), $o, $d );
							?>
						</table>
					</div>
				<?php
				break;

			case 'look':
				?>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Couleurs', 'africart-pwa' ); ?></h2>
						<table class="form-table" role="presentation">
							<?php
							$this->color_row( 'theme_color', __( 'Couleur du thème', 'africart-pwa' ), $o, $d, __( 'Barre du navigateur et barre de titre de l’app.', 'africart-pwa' ) );
							$this->color_row( 'background_color', __( 'Fond du splash screen', 'africart-pwa' ), $o, $d, __( 'Écran affiché au lancement de l’app.', 'africart-pwa' ) );
							$this->color_row( 'accent_color', __( 'Couleur d’accent', 'africart-pwa' ), $o, $d, __( 'Boutons de la page hors-ligne et du bandeau d’installation.', 'africart-pwa' ) );
							?>
						</table>
					</div>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Comportement', 'africart-pwa' ); ?></h2>
						<table class="form-table" role="presentation">
							<?php
							$this->select_row( 'display', __( 'Mode d’affichage', 'africart-pwa' ), array(
								'standalone' => __( 'standalone — comme une app native (recommandé)', 'africart-pwa' ),
								'fullscreen' => __( 'fullscreen — plein écran total', 'africart-pwa' ),
								'minimal-ui' => __( 'minimal-ui — barre de navigation réduite', 'africart-pwa' ),
								'browser'    => __( 'browser — onglet de navigateur classique', 'africart-pwa' ),
							), $o );
							$this->select_row( 'orientation', __( 'Orientation', 'africart-pwa' ), array(
								'portrait-primary' => __( 'portrait (recommandé)', 'africart-pwa' ),
								'portrait'         => 'portrait',
								'landscape'        => 'landscape',
								'any'              => __( 'libre', 'africart-pwa' ),
								'natural'          => __( 'naturelle', 'africart-pwa' ),
							), $o );
							$this->select_row( 'ios_status_bar', __( 'Barre d’état iPhone', 'africart-pwa' ), array(
							'default'           => __( 'Réserver sa place (recommandé)', 'africart-pwa' ),
							'black'             => __( 'Noire, place réservée', 'africart-pwa' ),
							'black-translucent' => __( 'Translucide — le contenu passe dessous', 'africart-pwa' ),
						), $o );
						$this->text_row( 'start_url', __( 'URL de démarrage', 'africart-pwa' ), $o, $d, 'regular-text code', __( 'Page ouverte au lancement de l’app installée.', 'africart-pwa' ) );
							?>
						</table>
					</div>
				<?php
				break;

			case 'images':
				?>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Icônes de l’application', 'africart-pwa' ); ?></h2>
						<p class="description"><?php esc_html_e( 'PNG à fond opaque. La version « maskable » doit garder son motif dans les 80 % centraux : Android la rogne en cercle ou en carré selon le téléphone.', 'africart-pwa' ); ?></p>
						<table class="form-table" role="presentation">
							<?php
							$this->media_row( 'icon_192', __( 'Icône 192×192', 'africart-pwa' ), $o, $d );
							$this->media_row( 'icon_512', __( 'Icône 512×512', 'africart-pwa' ), $o, $d );
							$this->media_row( 'icon_maskable', __( 'Icône maskable 512×512', 'africart-pwa' ), $o, $d );
							$this->media_row( 'apple_touch_icon', __( 'Icône iOS 180×180', 'africart-pwa' ), $o, $d );
							?>
						</table>
					</div>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Screenshots de la fenêtre d’installation', 'africart-pwa' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Affichées par Chrome dans la fenêtre d’installation détaillée. Les dimensions se remplissent au choix d’une image.', 'africart-pwa' ); ?></p>
						<table class="form-table" role="presentation">
							<?php
							$this->media_row( 'screenshot_wide', __( 'Screenshot desktop (large)', 'africart-pwa' ), $o, $d, 'screenshot_wide' );
							$this->size_row( 'screenshot_wide_w', 'screenshot_wide_h', __( 'Dimensions desktop', 'africart-pwa' ), $o, $d );
							$this->media_row( 'screenshot_mobile', __( 'Screenshot mobile (portrait)', 'africart-pwa' ), $o, $d, 'screenshot_mobile' );
							$this->size_row( 'screenshot_mobile_w', 'screenshot_mobile_h', __( 'Dimensions mobile', 'africart-pwa' ), $o, $d );
							?>
						</table>
					</div>
				<?php
				break;

			case 'install':
				?>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Bandeau d’installation', 'africart-pwa' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Le bandeau invite le visiteur à installer l’application.', 'africart-pwa' ); ?><?php $this->info( __( 'Un site ne peut pas ouvrir seul la fenêtre d’installation : le navigateur exige un clic. Le bandeau sert de déclencheur — au clic sur « Installer », c’est bien la fenêtre native qui s’ouvre, sur Android comme sur ordinateur.', 'africart-pwa' ) ); ?>
						</p>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Affichage', 'africart-pwa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo $this->name_attr( 'banner_enabled' ); ?>" value="1" <?php checked( self::get( 'banner_enabled' ), '1' ); ?> />
										<?php esc_html_e( 'Afficher le bandeau d’installation', 'africart-pwa' ); ?>
									</label>
									<p class="afk-hint"><?php esc_html_e( 'Il ne s’affiche jamais si l’app est déjà installée, ni si le visiteur l’a fermé récemment.', 'africart-pwa' ); ?></p>
								</td>
							</tr>
							<?php
							$this->select_row( 'banner_position', __( 'Position', 'africart-pwa' ), array(
								'bottom' => __( 'En bas de l’écran (recommandé sur mobile)', 'africart-pwa' ),
								'top'    => __( 'En haut de l’écran', 'africart-pwa' ),
							), $o );
							$this->number_row( 'banner_delay', __( 'Délai avant affichage', 'africart-pwa' ), $o, $d, __( 'secondes après le chargement — laisse le visiteur découvrir la page d’abord.', 'africart-pwa' ) );
							$this->number_row( 'banner_dismiss_days', __( 'Ne plus afficher pendant', 'africart-pwa' ), $o, $d, __( 'jours, après une fermeture ou un refus.', 'africart-pwa' ) );
							?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Cas particuliers', 'africart-pwa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo $this->name_attr( 'banner_ios' ); ?>" value="1" <?php checked( self::get( 'banner_ios' ), '1' ); ?> />
										<?php esc_html_e( 'iPhone / iPad : afficher les instructions « Partager → Sur l’écran d’accueil »', 'africart-pwa' ); ?>
									</label>
									<p class="afk-hint"><?php esc_html_e( 'Safari ne fournit aucune fenêtre d’installation : l’ajout est forcément manuel.', 'africart-pwa' ); ?></p>
									<label>
										<input type="checkbox" name="<?php echo $this->name_attr( 'banner_inapp' ); ?>" value="1" <?php checked( self::get( 'banner_inapp' ), '1' ); ?> />
										<?php esc_html_e( 'Navigateurs Instagram / Facebook : inviter à ouvrir dans le vrai navigateur', 'africart-pwa' ); ?>
									</label>
									<p class="afk-hint"><?php esc_html_e( 'L’installation y est techniquement impossible — essentiel pour le trafic venant d’Instagram.', 'africart-pwa' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Visuels animés (GIF)', 'africart-pwa' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Une animation par plateforme, montrant le geste à effectuer.', 'africart-pwa' ); ?><?php $this->info( __( 'Le geste diffère selon l’appareil : Android ouvre une fenêtre d’installation, iPhone demande Partager puis « Sur l’écran d’accueil ». Chaque visiteur ne voit que l’animation correspondant au sien. Un champ vide affiche l’icône de l’application.', 'africart-pwa' ) ); ?>
						</p>
						<table class="form-table" role="presentation">
							<?php
							$this->media_row( 'install_gif_android', __( 'GIF Android / ordinateur', 'africart-pwa' ), $o, $d );
							$this->media_row( 'install_gif_ios', __( 'GIF iPhone / iPad', 'africart-pwa' ), $o, $d );
							$this->media_row( 'install_gif_inapp', __( 'GIF navigateur Instagram / Facebook', 'africart-pwa' ), $o, $d );
							$this->number_row( 'banner_gif_height', __( 'Hauteur maximale du visuel', 'africart-pwa' ), $o, $d, __( 'pixels', 'africart-pwa' ) );
							?>
						</table>
						<p class="afk-hint">
							<?php esc_html_e( '400 à 600 px de large suffisent ; au-delà de 2 Mo, la performance en pâtit.', 'africart-pwa' ); ?><?php $this->info( __( 'Une animation courte et recadrée sur le geste à montrer pèse peu et se comprend mieux qu’une capture d’écran complète.', 'africart-pwa' ) ); ?>
						</p>
					</div>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Textes du bandeau', 'africart-pwa' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Langue par défaut — les traductions se gèrent dans l’onglet Langues.', 'africart-pwa' ); ?></p>
						<table class="form-table" role="presentation">
							<?php
							$this->text_row( 'install_title', __( 'Titre', 'africart-pwa' ), $o, $d );
							$this->text_row( 'install_message', __( 'Message', 'africart-pwa' ), $o, $d, 'large-text' );
							$this->text_row( 'install_button', __( 'Bouton', 'africart-pwa' ), $o, $d, 'regular-text' );
							$this->text_row( 'install_ios', __( 'Message iPhone / iPad', 'africart-pwa' ), $o, $d, 'large-text' );
							$this->text_row( 'install_inapp', __( 'Message navigateur in-app', 'africart-pwa' ), $o, $d, 'large-text' );
							$this->text_row( 'install_samsung', __( 'Note Samsung Internet', 'africart-pwa' ), $o, $d, 'large-text', __( 'Affichée sous le message, uniquement dans ce navigateur : il fabrique lui-même le paquet d’installation et Play Protect affiche un avertissement à tort. Sans cette note, le visiteur abandonne.', 'africart-pwa' ) );
							?>
						</table>
					</div>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Bouton dans une page', 'africart-pwa' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Pose un bouton d’installation dans une page ou un widget Elementor.', 'africart-pwa' ); ?><?php $this->info( __( 'Le bouton reste invisible tant que l’installation n’est pas possible : application déjà installée, ou navigateur incompatible.', 'africart-pwa' ) ); ?>
						</p>
						<p><code>[africart_install]</code> &nbsp; <?php esc_html_e( 'ou avec un libellé personnalisé :', 'africart-pwa' ); ?> &nbsp; <code>[africart_install label="Installer l’app AFRICART"]</code></p>
					</div>
				<?php
				break;

			case 'perf':
				?>
					<?php $this->render_perf_tab( $o, $d ); ?>
				<?php
				break;

			case 'push':
				?>
					<?php $this->render_push_tab( $o, $d ); ?>
				<?php
				break;

			case 'seo':
				?>
					<?php $this->render_seo_tab( $o, $d ); ?>
				<?php
				break;

			case 'languages':
				?>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Langues de la PWA', 'africart-pwa' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Détectées depuis le widget de traduction du site. Saisir des codes séparés par des virgules pour forcer un autre choix, par exemple « fr, en, es ».', 'africart-pwa' ); ?></p>
						<table class="form-table" role="presentation">
							<?php $this->text_row( 'languages', __( 'Langues actives', 'africart-pwa' ), $o, $d, 'regular-text code' ); ?>
						</table>
						<?php $this->render_i18n_fields(); ?>
					</div>
				<?php
				break;

			case 'advanced':
				?>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Page hors-ligne', 'africart-pwa' ); ?></h2>
						<p class="description">
							<?php
							printf(
								/* translators: %s: langue par défaut */
								esc_html__( 'Textes de la langue par défaut (%s). Les traductions se gèrent dans l’onglet Langues.', 'africart-pwa' ),
								esc_html( Africart_PWA_I18n::lang_label( Africart_PWA_I18n::default_lang() ) )
							);
							?>
						</p>
						<table class="form-table" role="presentation">
							<?php $this->text_row( 'offline_title', __( 'Titre', 'africart-pwa' ), $o, $d ); ?>
							<tr>
								<th scope="row"><label for="afk-offline_message"><?php esc_html_e( 'Message', 'africart-pwa' ); ?></label></th>
								<td>
									<textarea id="afk-offline_message" name="<?php echo $this->name_attr( 'offline_message' ); ?>" rows="3" class="large-text" placeholder="<?php echo esc_attr( $d['offline_message'] ); ?>"><?php echo esc_textarea( $o['offline_message'] ); ?></textarea>
								</td>
							</tr>
							<?php $this->text_row( 'offline_button', __( 'Bouton', 'africart-pwa' ), $o, $d, 'small-text' ); ?>
						</table>
					</div>
					<div class="afk-card">
						<h2><?php esc_html_e( 'Service Worker', 'africart-pwa' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'État', 'africart-pwa' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="<?php echo $this->name_attr( 'sw_enabled' ); ?>" value="1" <?php checked( self::get( 'sw_enabled' ), '1' ); ?> />
										<?php esc_html_e( 'Activer le Service Worker (cache, hors-ligne, installation)', 'africart-pwa' ); ?>
									</label>
									<p class="afk-hint"><?php esc_html_e( 'À ne désactiver que pour diagnostiquer un problème.', 'africart-pwa' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				<?php
				break;

		}
	}



	/**
	 * L'optimisation du partage est-elle active ?
	 *
	 * @return bool
	 */
	public static function is_social_enabled() {
		return '1' === self::get( 'seo_enabled' );
	}

	/**
	 * Onglet Partage & SEO : image de partage des œuvres et description
	 * structurée.
	 *
	 * @param array $o Réglages enregistrés.
	 * @param array $d Valeurs par défaut.
	 */
	private function render_seo_tab( $o, $d ) {
		$counts = class_exists( 'Africart_PWA_Social_Meta' ) ? Africart_PWA_Social_Meta::count_missing() : array( 'total' => 0, 'missing' => 0 );
		?>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Image partagée sur les réseaux sociaux', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Un contenu partagé montre son propre visuel, et non une image générique.', 'africart-pwa' ); ?><?php $this->info( __( 'Le plugin renseigne l’image du contenu là où les outils de partage la cherchent — par exemple la photo d’une œuvre partagée sur WhatsApp ou Facebook.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Activation', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'seo_enabled' ); ?>" value="1" <?php checked( self::get( 'seo_enabled' ), '1' ); ?> />
							<?php esc_html_e( 'Utiliser le visuel de l’œuvre pour le partage et le référencement', 'africart-pwa' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'État', 'africart-pwa' ); ?></th>
					<td>
						<?php if ( $counts['missing'] > 0 ) : ?>
							<p>
								<strong style="color:#96762a">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %1$d sans visuel, %2$d au total */
											__( '%1$d œuvres sur %2$d n’ont pas encore de visuel de partage.', 'africart-pwa' ),
											$counts['missing'],
											$counts['total']
										)
									);
									?>
								</strong>
							</p>
							<p>
								<button type="button" class="button button-primary afk-seo-backfill">
									<?php esc_html_e( 'Corriger les œuvres existantes', 'africart-pwa' ); ?>
								</button>
								<span class="afk-seo-feedback afk-hint"></span>
							</p>
						<?php else : ?>
							<p>
								<strong style="color:#2f7d5d">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d nombre d'œuvres */
											__( 'Les %d œuvres disposent d’un visuel de partage.', 'africart-pwa' ),
											$counts['total']
										)
									);
									?>
								</strong>
							</p>
						<?php endif; ?>
						<p class="afk-hint"><?php esc_html_e( 'Les nouvelles œuvres sont traitées automatiquement à leur enregistrement.', 'africart-pwa' ); ?></p>
					</td>
				</tr>
				<?php $this->text_row( 'seo_post_type', __( 'Type de contenu des œuvres', 'africart-pwa' ), $o, $d, 'regular-text code' ); ?>
			</table>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Informations décrites aux moteurs de recherche', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Décrit chaque contenu aux moteurs de recherche.', 'africart-pwa' ); ?><?php $this->info( __( 'Les informations sont transmises sous une forme normalisée — auteur, technique, année — ce que les balises de partage ne permettent pas. Indiquez le champ qui contient chacune ; laissez vide pour ignorer.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<?php
				$map    = Africart_PWA_Social_Meta::field_map();
				$labels = Africart_PWA_Social_Meta::field_labels();
				foreach ( $labels as $key => $label ) :
					$name = esc_attr( self::OPTION . '[' . Africart_PWA_Social_Meta::MAP_KEY . '][' . $key . ']' );
					?>
					<tr>
						<th scope="row"><label for="afk-map-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<input type="text" id="afk-map-<?php echo esc_attr( $key ); ?>" name="<?php echo $name; ?>"
								value="<?php echo esc_attr( isset( $map[ $key ] ) ? $map[ $key ] : '' ); ?>"
								class="regular-text code" />
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>

		<?php
		// Le nom transmis aux réseaux appartient à l'outil de référencement.
		// On signale la divergence, et on ne corrige que sur un clic
		// explicite — jamais silencieusement. L'alerte disparaît d'elle-même
		// une fois les deux noms accordés.
		$nom_reseaux = $this->social_site_name();
		$nom_attendu = self::get( 'app_name' );

		if ( '' !== $nom_reseaux && 0 !== strcasecmp( $nom_reseaux, $nom_attendu ) ) :
			?>
			<div class="afk-card">
				<h2>
					<?php esc_html_e( 'Nom transmis aux réseaux sociaux', 'africart-pwa' ); ?>
					<?php
					$this->info(
						__( 'Ce réglage appartient à Rank Math, dont le champ « Nom du site web » n’est visible qu’avec son module SEO local — désactivé sur ce site. Le bouton écrit la correction exactement là où cet écran l’aurait fait, sans activer le module. Rien n’est modifié sans ce clic.', 'africart-pwa' )
					);
					?>
				</h2>
				<p class="description">
					<?php
					printf(
						/* translators: 1: nom transmis aux réseaux, 2: nom de l'application */
						esc_html__( 'Les réseaux sociaux reçoivent « %1$s » alors que l’application s’appelle « %2$s ».', 'africart-pwa' ),
						esc_html( $nom_reseaux ),
						esc_html( $nom_attendu )
					);
					?>
				</p>
				<p>
					<button type="button" class="button" id="afk-fix-site-name">
						<?php
						/* translators: %s nom de l'application */
						printf( esc_html__( 'Corriger en « %s »', 'africart-pwa' ), esc_html( $nom_attendu ) );
						?>
					</button>
					<span id="afk-fix-site-name-result" style="margin-left:8px"></span>
				</p>
			</div>
			<?php
		endif;
	}

	/**
	 * Onglet Notifications : raccordement à OneSignal et centres d'intérêt.
	 *
	 * @param array $o Réglages enregistrés.
	 * @param array $d Valeurs par défaut.
	 */
	/**
	 * Banc d'essai : publier un contenu de démonstration.
	 *
	 * Le type se choisit dans la liste, et l'écran dit **avant** de
	 * publier si un déclencheur le prend en charge — sans quoi on
	 * publierait pour rien, sans comprendre pourquoi rien ne part.
	 */
	private function render_push_test_card() {
		if ( ! class_exists( 'Africart_PWA_Push_Tester' ) ) {
			return;
		}

		$types  = $this->post_type_choices();
		$restes = Africart_PWA_Push_Tester::leftovers();
		?>
		<div class="afk-card">
			<h2>
				<?php esc_html_e( 'Tester la publication', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Crée un contenu de démonstration et le publie réellement : le déclencheur suit exactement le même chemin qu’une œuvre déposée par un artiste. Rien n’est simulé — un test qui emprunterait un raccourci ne prouverait rien.', 'africart-pwa' ) ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Vérifie qu’une publication déclenche bien l’envoi, sans toucher à vos contenus.', 'africart-pwa' ); ?>
			</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="afk-test-type"><?php esc_html_e( 'Type de contenu', 'africart-pwa' ); ?></label>
					</th>
					<td>
						<select id="afk-test-type">
							<?php
							foreach ( $types as $slug => $libelle ) :
								$declencheur = Africart_PWA_Push_Tester::trigger_for( $slug );
								$jeu         = Africart_PWA_Push_Tester::sample_for( $slug );
								?>
								<option value="<?php echo esc_attr( $slug ); ?>"
									data-pris="<?php echo $declencheur ? '1' : '0'; ?>"
									data-titre="<?php echo esc_attr( $jeu['title'] ); ?>"
									data-visuel="<?php echo esc_attr( $jeu['image'] && file_exists( AFK_PWA_DIR . 'assets/img/' . $jeu['image'] ) ? $jeu['image'] : '' ); ?>"
									data-cible="<?php echo esc_attr( $declencheur && $declencheur['category'] ? $declencheur['category'] : '' ); ?>">
									<?php echo esc_html( $libelle ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<p class="afk-test-etat" id="afk-test-pris" hidden></p>
						<p class="afk-hint afk-test-valeurs" id="afk-test-valeurs"></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-primary" id="afk-test-publier">
					<?php esc_html_e( 'Créer et publier un contenu de test', 'africart-pwa' ); ?>
				</button>
				<button type="button" class="button" id="afk-test-menage" <?php echo $restes ? '' : 'hidden'; ?>>
					<?php
					/* translators: %d nombre de contenus de test */
					printf( esc_html__( 'Supprimer les contenus de test (%d)', 'africart-pwa' ), (int) $restes );
					?>
				</button>
			</p>

			<div id="afk-test-resultat" hidden></div>
		</div>
		<?php
	}

	/**
	 * Blocs « envoi depuis WordPress » : clé secrète et déclencheurs.
	 *
	 * Séparés du reste car ils relèvent d'un autre mécanisme : le site
	 * cesse d'être un simple point d'abonnement pour devenir émetteur.
	 */
	private function render_push_sender_cards() {
		if ( ! class_exists( 'Africart_PWA_Push_Sender' ) ) {
			return;
		}

		$cle_posee = '' !== Africart_PWA_Push_Sender::get_key();
		$pret      = Africart_PWA_Push_Sender::is_ready();
		?>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Envoi automatique depuis le site', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Permet au site d’envoyer les notifications lui-même.', 'africart-pwa' ); ?><?php $this->info( __( 'Sans cette clé, chaque notification se compose à la main depuis le tableau de bord OneSignal.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Clé d’envoi', 'africart-pwa' ); ?></th>
					<td>
					<?php if ( Africart_PWA_Push_Sender::key_is_pinned() ) : ?>
						<p>
							<span class="dashicons dashicons-yes-alt" style="color:#2f7d5d"></span>
							<strong style="color:#2f7d5d"><?php esc_html_e( 'Clé déclarée dans wp-config.php.', 'africart-pwa' ); ?></strong>
							<?php esc_html_e( 'Le site peut envoyer les notifications lui-même.', 'africart-pwa' ); ?>
						</p>
						<p class="afk-hint">
							<?php esc_html_e( 'Pour la changer ou la retirer, modifiez la constante AFK_PWA_PUSH_KEY.', 'africart-pwa' ); ?>
						</p>
					<?php else : ?>
						<p>
							<span class="dashicons dashicons-info-outline" style="color:#996800"></span>
							<strong><?php esc_html_e( 'Aucune clé déclarée.', 'africart-pwa' ); ?></strong>
							<?php esc_html_e( 'Les notifications se composent à la main depuis le tableau de bord OneSignal.', 'africart-pwa' ); ?>
						</p>
						<p class="afk-hint">
							<?php esc_html_e( 'Pour que le site les envoie lui-même, ajoutez cette ligne dans wp-config.php, avant la ligne « C’est tout, ne touchez pas à ce qui suit » :', 'africart-pwa' ); ?>
						</p>
						<p><code>define( 'AFK_PWA_PUSH_KEY', 'os_v2_…' );</code></p>
						<p class="afk-hint">
							<?php esc_html_e( 'La clé se trouve dans OneSignal : Settings → Keys &amp; IDs → REST API Key.', 'africart-pwa' ); ?>
						</p>
					<?php endif; ?>

					<?php
					// Une clé enregistrée par une version antérieure dort encore
					// en base : on le signale et on propose de l'en retirer.
					if ( Africart_PWA_Push_Sender::has_legacy_key() ) :
						?>
						<p class="afk-hint" style="margin-top:10px">
							<strong style="color:#996800"><?php esc_html_e( 'Une clé subsiste dans la base de données', 'africart-pwa' ); ?></strong> —
							<?php esc_html_e( 'enregistrée par une version antérieure. Elle suit désormais toute sauvegarde et toute copie du site.', 'africart-pwa' ); ?>
							<button type="button" class="button-link afk-color-reset" id="afk-push-key-clear"><?php esc_html_e( 'La retirer de la base', 'africart-pwa' ); ?></button>
							<span id="afk-push-key-result" style="margin-left:8px"></span>
						</p>
					<?php endif; ?>
					</td>
				</tr>
			</table>
			<p class="afk-hint">
				<?php esc_html_e( 'Pourquoi pas de champ ici : une clé saisie dans l’administration est rangée en base, et une base voyage — sauvegardes, copies vers un environnement de test, écran « Tous les réglages » qui affiche tout en clair. Déclarée dans wp-config.php, elle reste sur son serveur.', 'africart-pwa' ); ?>
			</p>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Déclencheurs', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Une ligne par type de contenu à annoncer automatiquement.', 'africart-pwa' ); ?><?php $this->info( __( 'À la publication, les abonnés au centre d’intérêt choisi sont prévenus — une seule fois. Modifier un contenu ne renotifie jamais, et l’écran d’édition permet de sauter l’envoi au cas par cas.', 'africart-pwa' ) ); ?>
			</p>
			<?php if ( ! $pret ) : ?>
				<p class="afk-hint">
					<?php esc_html_e( 'Sans activation ni clé d’envoi, ces déclencheurs restent sans effet.', 'africart-pwa' ); ?>
				</p>
			<?php endif; ?>
			<?php
			$types        = $this->post_type_choices();
			$categories   = class_exists( 'Africart_PWA_OneSignal' ) ? Africart_PWA_OneSignal::get_categories() : array();
			$declencheurs = Africart_PWA_Push_Sender::get_triggers();
			?>
			<table class="widefat afk-repeater" id="afk-triggers">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type de contenu', 'africart-pwa' ); ?></th>
						<th>
							<?php esc_html_e( 'Destinataires', 'africart-pwa' ); ?>
							<?php $this->info( __( 'Par défaut, tous les abonnés. Choisir un centre d’intérêt ne touche que les visiteurs qui l’ont explicitement coché sur une page portant le code court [africart_notifications] — un visiteur qui a simplement accepté l’invite du navigateur n’en a coché aucun, et ne recevrait donc rien.', 'africart-pwa' ), true ); ?>
						</th>
						<th><?php esc_html_e( 'Intitulé de la notification', 'africart-pwa' ); ?></th>
						<th class="afk-repeater-action"><span class="screen-reader-text"><?php esc_html_e( 'Retirer', 'africart-pwa' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $declencheurs as $i => $ligne ) : ?>
						<?php $this->trigger_row( $i, $ligne, $types, $categories ); ?>
					<?php endforeach; ?>
					<tr class="afk-repeater-empty" <?php echo $declencheurs ? 'style="display:none"' : ''; ?>>
						<td colspan="4"><?php esc_html_e( 'Aucun déclencheur : le site n’envoie rien de lui-même.', 'africart-pwa' ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php
			// Témoin toujours transmis. Sans lui, retirer la dernière ligne
			// n'enverrait plus rien du tout et l'ancienne liste serait
			// conservée : impossible de tout supprimer. Le nettoyage écarte
			// cette entrée, qui n'est pas une ligne.
			?>
			<input type="hidden" name="<?php echo esc_attr( self::OPTION . '[' . Africart_PWA_Push_Sender::TRIGGERS_KEY . '][temoin]' ); ?>" value="1" />

			<p>
				<button type="button" class="button" id="afk-trigger-add">
					<?php esc_html_e( '+ Ajouter un déclencheur', 'africart-pwa' ); ?>
				</button>
			</p>

			<?php
			// Modèle de ligne vierge, cloné par le script. L'indice __i__ y
			// est remplacé au moment de l'insertion : sans indice distinct,
			// les lignes ajoutées s'écraseraient entre elles à l'envoi.
			?>
			<script type="text/html" id="afk-trigger-template">
				<?php $this->trigger_row( '__i__', array( 'post_type' => '', 'category' => '', 'title' => '' ), $types, $categories ); ?>
			</script>

			<?php if ( $pret ) : ?>
				<p>
					<button type="button" class="button" id="afk-push-test"><?php esc_html_e( 'Envoyer une notification de test', 'africart-pwa' ); ?></button>
					<span id="afk-push-test-result" style="margin-left:8px"></span>
				</p>
				<p class="afk-hint"><?php esc_html_e( 'Le test part à tous les abonnés, sans ciblage. À réserver à la préproduction.', 'africart-pwa' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Affiche une ligne de déclencheur.
	 *
	 * Même fonction pour les lignes enregistrées et pour le modèle cloné
	 * par le script : une seule écriture du balisage, donc aucune chance
	 * de voir les deux diverger.
	 *
	 * @param int|string $i          Indice de la ligne, ou '__i__' pour le modèle.
	 * @param array      $ligne      Valeurs de la ligne.
	 * @param array      $types      Types de contenu proposables.
	 * @param array      $categories Centres d'intérêt disponibles.
	 */
	private function trigger_row( $i, $ligne, $types, $categories ) {
		$base = esc_attr( self::OPTION . '[' . Africart_PWA_Push_Sender::TRIGGERS_KEY . '][' . $i . ']' );
		?>
		<tr class="afk-repeater-row">
			<td>
				<select name="<?php echo $base; ?>[post_type]">
					<option value=""><?php esc_html_e( '— Choisir —', 'africart-pwa' ); ?></option>
					<?php foreach ( $types as $slug => $libelle ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $ligne['post_type'], $slug ); ?>>
							<?php echo esc_html( $libelle ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="<?php echo $base; ?>[category]">
					<option value=""><?php esc_html_e( 'Tous les abonnés', 'africart-pwa' ); ?></option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo esc_attr( $cat['slug'] ); ?>" <?php selected( $ligne['category'], $cat['slug'] ); ?>>
							<?php echo esc_html( $cat['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<input type="text" name="<?php echo $base; ?>[title]" value="<?php echo esc_attr( $ligne['title'] ); ?>"
					class="large-text" placeholder="<?php esc_attr_e( 'Nouvelle œuvre sur AFRICART', 'africart-pwa' ); ?>" />
			</td>
			<td class="afk-repeater-action">
				<button type="button" class="button-link afk-repeater-remove"
					aria-label="<?php esc_attr_e( 'Retirer ce déclencheur', 'africart-pwa' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</td>
		</tr>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Aperçus
	 *
	 * Une couleur ou un texte de bandeau ne se juge pas sur un
	 * formulaire. Ces aperçus reproduisent le rendu et **suivent la
	 * saisie en direct**, sans enregistrement ni rechargement.
	 *
	 * Ce sont des reproductions, pas des captures du site : afficher le
	 * vrai rendu exigerait une requête à chaque frappe. Les écrans le
	 * disent, pour que personne ne les prenne pour une garantie.
	 * ---------------------------------------------------------------- */

	/**
	 * Retire de la base une clé enregistrée par une version antérieure.
	 *
	 * L'administration ne propose plus de saisir la clé : rangée en base,
	 * elle suivait toute sauvegarde et toute copie vers un environnement
	 * de test — c'est ainsi qu'elle s'est retrouvée sur une préproduction.
	 * Elle se déclare désormais dans wp-config.php, où elle reste sur son
	 * serveur. Ce point d'entrée ne sait donc qu'effacer.
	 */
	public function ajax_save_push_key() {
		check_ajax_referer( 'afk_save_push_key', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'africart-pwa' ) ), 403 );
		}

		Africart_PWA_Push_Sender::forget_legacy_key();

		wp_send_json_success(
			array( 'message' => __( 'Clé retirée de la base de données.', 'africart-pwa' ) )
		);
	}

	/**
	 * Aligne le nom de site de Rank Math sur celui de l'application.
	 *
	 * Le champ « Nom du site web » de Rank Math n'est visible qu'avec son
	 * module SEO local ; désactivé, la valeur dort en base sans écran
	 * pour la modifier — ce site portait encore le nom d'un autre projet.
	 *
	 * On écrit ici exactement ce que cet écran aurait écrit, et rien
	 * d'autre. Uniquement sur clic explicite : le réglage appartient à
	 * Rank Math, pas à ce plugin.
	 */
	public function ajax_fix_site_name() {
		check_ajax_referer( 'afk_fix_site_name', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'africart-pwa' ) ), 403 );
		}

		$nom = trim( (string) self::get( 'app_name' ) );
		if ( '' === $nom ) {
			wp_send_json_error( array( 'message' => __( 'Aucun nom d’application défini.', 'africart-pwa' ) ) );
		}

		$titres = get_option( 'rank-math-options-titles', array() );
		$titres = is_array( $titres ) ? $titres : array();

		// Les deux champs que l'écran SEO local de Rank Math renseigne :
		// le nom du site (og:site_name) et celui du Knowledge Graph.
		$titres['website_name']        = $nom;
		$titres['knowledgegraph_name'] = $nom;

		update_option( 'rank-math-options-titles', $titres );

		wp_send_json_success(
			array(
				/* translators: %s nouveau nom */
				'message' => sprintf( __( 'Corrigé : les réseaux recevront désormais « %s ».', 'africart-pwa' ), $nom ),
			)
		);
	}

	/**
	 * Balises de partage d'une page, pour l'aperçu des réseaux.
	 *
	 * On lit la page **réelle**, comme un réseau la lirait — même adresse,
	 * et le user-agent d'un de leurs robots, pour traverser les mêmes
	 * règles de cache. Reconstituer les balises depuis les réglages
	 * montrerait ce que le plugin croit produire, pas ce qui est produit :
	 * un aperçu qui a raison quand le site a tort ne détecte rien.
	 */
	public function ajax_share_preview() {
		check_ajax_referer( 'afk_share_preview', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'africart-pwa' ) ), 403 );
		}

		$post = get_post( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );

		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_error( array( 'message' => __( 'Contenu introuvable ou non publié.', 'africart-pwa' ) ) );
		}

		$reponse = wp_remote_get(
			get_permalink( $post ),
			array(
				'timeout'    => 20,
				'user-agent' => 'facebookexternalhit/1.1 (apercu AFRICART PWA)',
			)
		);

		if ( is_wp_error( $reponse ) ) {
			wp_send_json_error( array( 'message' => $reponse->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $reponse );
		if ( 200 !== $code ) {
			/* translators: %d code HTTP */
			wp_send_json_error( array( 'message' => sprintf( __( 'La page a répondu %d.', 'africart-pwa' ), $code ) ) );
		}

		wp_send_json_success( self::parse_share_tags( wp_remote_retrieve_body( $reponse ) ) );
	}

	/**
	 * Extrait les balises Open Graph d'une page.
	 *
	 * @param string $html HTML complet de la page.
	 * @return array title, description, image, url, site_name.
	 */
	public static function parse_share_tags( $html ) {
		$fin  = stripos( $html, '</head>' );
		$tete = false === $fin ? substr( $html, 0, 150000 ) : substr( $html, 0, $fin );

		$donnees = array(
			'title'       => '',
			'description' => '',
			'image'       => '',
			'url'         => '',
			'site_name'   => '',
		);

		// Balise par balise plutôt qu'une seule expression : l'ordre des
		// attributs property/content varie selon l'outil qui les émet.
		if ( preg_match_all( '#<meta\s[^>]*>#i', $tete, $balises ) ) {
			foreach ( $balises[0] as $balise ) {
				if ( ! preg_match( '#property\s*=\s*["\']og:([a-z_:]+)["\']#i', $balise, $prop ) ) {
					continue;
				}
				if ( ! preg_match( '#content\s*=\s*["\']([^"\']*)["\']#i', $balise, $contenu ) ) {
					continue;
				}

				$cle = str_replace( ':', '_', strtolower( $prop[1] ) );

				// La première occurrence l'emporte, comme chez les réseaux.
				if ( array_key_exists( $cle, $donnees ) && '' === $donnees[ $cle ] ) {
					$donnees[ $cle ] = wp_specialchars_decode( trim( $contenu[1] ), ENT_QUOTES );
				}
			}
		}

		// Sans balise dédiée, les réseaux retombent sur le titre du document.
		if ( '' === $donnees['title'] && preg_match( '#<title[^>]*>(.*?)</title>#is', $tete, $titre ) ) {
			$donnees['title'] = wp_specialchars_decode( trim( wp_strip_all_tags( $titre[1] ) ), ENT_QUOTES );
		}

		return $donnees;
	}

	/**
	 * Aperçu de l'écran demandé, s'il en existe un.
	 *
	 * Rendu à part du reste de la page : il occupe une colonne propre,
	 * à droite des réglages, et non une carte de plus en bas de page —
	 * un aperçu qu'il faut aller chercher ne sert à personne.
	 *
	 * @param string $page Identifiant de l'écran.
	 * @param array  $o    Valeurs affichées dans le formulaire.
	 * @return string HTML, ou chaîne vide si l'écran n'a pas d'aperçu.
	 */
	private function preview_html( $page, $o ) {
		$rendus = array(
			'look'    => 'render_look_preview',
			'install' => 'render_install_preview',
			'seo'     => 'render_seo_preview',
			'push'    => 'render_push_preview',
			'stats'   => 'render_consent_preview',
		);

		if ( ! isset( $rendus[ $page ] ) ) {
			return '';
		}

		ob_start();
		$this->{$rendus[ $page ]}( $o );

		return (string) ob_get_clean();
	}

	/**
	 * Aperçu du lancement de l'application : écran de démarrage et
	 * barre de navigateur, selon les couleurs et le mode d'affichage.
	 *
	 * @param array $o Valeurs affichées dans le formulaire.
	 */
	private function render_look_preview( $o ) {
		$icone = self::get( 'icon_192' );
		?>
		<div class="afk-card afk-preview-card">
			<h2>
				<?php esc_html_e( 'Aperçu', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Reproduction indicative, mise à jour pendant votre saisie. Le rendu exact dépend du téléphone et de la version du navigateur.', 'africart-pwa' ) ); ?>
			</h2>

			<div class="afk-preview" id="afk-preview-look">
				<figure class="afk-phone">
					<div class="afk-phone-screen" data-preview="background_color">
						<div class="afk-phone-bar" data-preview="theme_color">
							<span class="afk-phone-url" data-preview-text="start_url"><?php echo esc_html( $o['start_url'] ); ?></span>
						</div>
						<div class="afk-phone-body">
							<img class="afk-phone-icon" src="<?php echo esc_url( $icone ); ?>" alt="" />
							<p class="afk-phone-name" data-preview-text="app_name"><?php echo esc_html( $o['app_name'] ); ?></p>
							<span class="afk-phone-button" data-preview="accent_color"><?php esc_html_e( 'Bouton', 'africart-pwa' ); ?></span>
						</div>
					</div>
					<figcaption><?php esc_html_e( 'Écran de lancement', 'africart-pwa' ); ?></figcaption>
				</figure>

				<ul class="afk-preview-notes">
					<li data-note="display-standalone"><?php esc_html_e( 'Sans barre de navigateur : l’application occupe tout l’écran.', 'africart-pwa' ); ?></li>
					<li data-note="display-browser"><?php esc_html_e( 'La barre du navigateur reste visible, comme dans un onglet.', 'africart-pwa' ); ?></li>
					<li data-note="display-fullscreen"><?php esc_html_e( 'Plein écran total : même l’heure et la batterie disparaissent.', 'africart-pwa' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Aperçu de la fenêtre d'invitation aux notifications.
	 *
	 * @param array $o Valeurs affichées dans le formulaire.
	 */
	private function render_push_preview( $o ) {
		$categories = class_exists( 'Africart_PWA_OneSignal' ) ? Africart_PWA_OneSignal::get_categories() : array();
		$politique  = class_exists( 'Africart_PWA_Privacy' ) ? Africart_PWA_Privacy::policy_url() : '';
		?>
		<div class="afk-card afk-preview-card">
			<h2>
				<?php esc_html_e( 'Aperçu de la fenêtre', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Reproduction indicative, mise à jour pendant votre saisie. Les centres d’intérêt sont repliés comme chez le visiteur — dépliez-les ici pour voir ce qu’il découvrira.', 'africart-pwa' ) ); ?>
			</h2>

			<div class="afk-preview" id="afk-preview-push">
				<figure class="afk-phone afk-phone-tall">
					<div class="afk-phone-screen afk-phone-page">
						<div class="afk-phone-fake"></div>
						<div class="afk-phone-fake afk-phone-fake-court"></div>

						<div class="afk-modal-mock" data-preview="theme_color">
							<span class="afk-modal-mock-fermer">&times;</span>
							<img class="afk-modal-mock-icone" src="<?php echo esc_url( self::get( 'icon_192' ) ); ?>" alt="" />
							<p class="afk-modal-mock-titre" data-preview-text="push_title"><?php echo esc_html( $o['push_title'] ); ?></p>
							<p class="afk-modal-mock-intro" data-preview-text="push_prompt"><?php echo esc_html( $o['push_prompt'] ); ?></p>

							<?php if ( $categories ) : ?>
								<button type="button" class="afk-modal-mock-deplier" aria-expanded="false">
									<span data-preview-text="push_choose"><?php echo esc_html( $o['push_choose'] ); ?></span>
									<span class="afk-modal-mock-chevron"></span>
								</button>
								<ul class="afk-modal-mock-liste" hidden>
									<?php foreach ( $categories as $c ) : ?>
										<li><span class="afk-modal-mock-case" data-preview="accent_color"></span><?php echo esc_html( $c['label'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>

							<span class="afk-modal-mock-oui" data-preview="accent_color" data-preview-text="push_accept"><?php echo esc_html( $o['push_accept'] ); ?></span>
							<span class="afk-modal-mock-non" data-preview-text="push_refuse"><?php echo esc_html( $o['push_refuse'] ); ?></span>

							<?php if ( $politique ) : ?>
								<span class="afk-modal-mock-politique" data-preview-text="push_privacy"><?php echo esc_html( $o['push_privacy'] ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<figcaption><?php esc_html_e( 'Fenêtre d’invitation', 'africart-pwa' ); ?></figcaption>
				</figure>

				<ul class="afk-preview-notes">
					<li data-contexte-note="pwa"><?php esc_html_e( 'Proposée uniquement dans l’application installée.', 'africart-pwa' ); ?></li>
					<li data-contexte-note="both" hidden><?php esc_html_e( 'Proposée dans l’application et dans le navigateur.', 'africart-pwa' ); ?></li>
					<li data-contexte-note="browser" hidden><?php esc_html_e( 'Proposée dans le navigateur seulement.', 'africart-pwa' ); ?></li>
					<?php if ( ! $politique ) : ?>
						<li><strong style="color:#996800"><?php esc_html_e( 'Aucune page de confidentialité déclarée : le lien est masqué.', 'africart-pwa' ); ?></strong></li>
					<?php endif; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Aperçu du bandeau d'installation, tel que le visiteur le voit.
	 *
	 * @param array $o Valeurs affichées dans le formulaire.
	 */
	private function render_install_preview( $o ) {
		$visuel = $o['install_gif_android'] ? $o['install_gif_android'] : self::get( 'icon_192' );

		// La hauteur est demandée au module qui la calcule pour le vrai
		// bandeau : un aperçu qui diverge du rendu induirait en erreur.
		// Le téléphone dessiné fait 232 px de large contre 390 pour un
		// mobile courant, d'où la réduction proportionnelle.
		$hauteur = (int) round( Africart_PWA_Install_Banner::gif_height() / 2.4 );
		?>
		<div class="afk-card afk-preview-card">
			<h2>
				<?php esc_html_e( 'Aperçu du bandeau', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Reproduction indicative, mise à jour pendant votre saisie. Chaque visiteur ne voit que le parcours de son appareil : les onglets permettent de les passer en revue.', 'africart-pwa' ) ); ?>
			</h2>

			<div class="afk-mini-tabs" id="afk-plateformes">
				<button type="button" class="afk-mini-tab active" data-plateforme="android"><?php esc_html_e( 'Android / ordinateur', 'africart-pwa' ); ?></button>
				<button type="button" class="afk-mini-tab" data-plateforme="samsung"><?php esc_html_e( 'Samsung Internet', 'africart-pwa' ); ?></button>
				<button type="button" class="afk-mini-tab" data-plateforme="ios"><?php esc_html_e( 'iPhone / iPad', 'africart-pwa' ); ?></button>
				<button type="button" class="afk-mini-tab" data-plateforme="inapp"><?php esc_html_e( 'Instagram / Facebook', 'africart-pwa' ); ?></button>
			</div>

			<div class="afk-preview" id="afk-preview-install">
				<figure class="afk-phone afk-phone-tall">
					<div class="afk-phone-screen afk-phone-page">
						<div class="afk-phone-fake"></div>
						<div class="afk-phone-fake afk-phone-fake-court"></div>
						<div class="afk-phone-fake"></div>

						<?php // Le vrai bandeau est peint de la couleur du thème : l'aperçu suit la même règle. ?>
						<div class="afk-banner" data-position="<?php echo esc_attr( $o['banner_position'] ); ?>" data-preview="theme_color">
							<img class="afk-banner-visuel" src="<?php echo esc_url( $visuel ); ?>" alt=""
								style="max-height:<?php echo (int) $hauteur; ?>px" />
							<p class="afk-banner-titre" data-preview-text="install_title"><?php echo esc_html( $o['install_title'] ); ?></p>
							<?php // Le message dépend de la plateforme choisie : il est posé par le script, pas par la mécanique générique. ?>
							<p class="afk-banner-message"><?php echo esc_html( $o['install_message'] ); ?></p>
							<span class="afk-banner-bouton" data-preview="accent_color" data-preview-text="install_button"><?php echo esc_html( $o['install_button'] ); ?></span>
							<?php // Note Play Protect — visible sur l'onglet Samsung seulement, sous le bouton comme dans le vrai bandeau. Icône hors du span de texte : le script ne remplace que le texte. ?>
							<p class="afk-banner-note" hidden><span class="dashicons dashicons-info-outline"></span><span class="afk-banner-note-texte"><?php echo esc_html( $o['install_samsung'] ); ?></span></p>
						</div>
					</div>
					<figcaption><?php esc_html_e( 'Bandeau d’installation', 'africart-pwa' ); ?></figcaption>
				</figure>

				<ul class="afk-preview-notes">
					<li data-plateforme-note="android"><?php esc_html_e( 'Le bouton ouvre la fenêtre d’installation native.', 'africart-pwa' ); ?></li>
					<li data-plateforme-note="samsung" hidden><?php esc_html_e( 'Play Protect affiche un avertissement à tort dans ce navigateur : la note sous le message rassure le visiteur.', 'africart-pwa' ); ?></li>
					<li data-plateforme-note="ios" hidden><?php esc_html_e( 'Pas de fenêtre sur iPhone : le bandeau montre le geste à faire, sans bouton.', 'africart-pwa' ); ?></li>
					<li data-plateforme-note="inapp" hidden><?php esc_html_e( 'Installation impossible dans ces navigateurs : le bandeau invite à ouvrir la page ailleurs.', 'africart-pwa' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Aperçu des partages : la carte qu'un réseau social construira
	 * pour un contenu donné, canal par canal.
	 *
	 * Contrairement aux deux autres aperçus, celui-ci ne reproduit pas
	 * des réglages : il lit la page réelle du contenu choisi et affiche
	 * les balises qu'elle émet vraiment (voir ajax_share_preview).
	 *
	 * @param array $o Valeurs affichées dans le formulaire.
	 */
	private function render_seo_preview( $o ) {
		$type = ! empty( $o['seo_post_type'] ) ? $o['seo_post_type'] : 'oeuvre';

		$articles = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="afk-card afk-preview-card">
			<h2>
				<?php esc_html_e( 'Aperçu du partage', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Construit à partir de la page réelle du contenu choisi : ce sont les balises que les réseaux liront, pas une reconstitution. Les réseaux gardent toutefois leurs aperçus en mémoire — un contenu déjà partagé peut montrer une version antérieure.', 'africart-pwa' ) ); ?>
			</h2>

			<?php if ( ! $articles ) : ?>
				<p class="description"><?php esc_html_e( 'Aucun contenu publié à prévisualiser.', 'africart-pwa' ); ?></p>
			<?php else : ?>
				<div id="afk-share-preview">
					<p>
						<label class="screen-reader-text" for="afk-share-article"><?php esc_html_e( 'Contenu à prévisualiser', 'africart-pwa' ); ?></label>
						<select id="afk-share-article">
							<?php foreach ( $articles as $article ) : ?>
								<option value="<?php echo (int) $article->ID; ?>"><?php echo esc_html( get_the_title( $article ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="afk-hint"><?php esc_html_e( 'Les 30 publications les plus récentes.', 'africart-pwa' ); ?></p>

					<div class="afk-mini-tabs" id="afk-canaux">
						<button type="button" class="afk-mini-tab active" data-canal="instagram">Instagram</button>
						<button type="button" class="afk-mini-tab" data-canal="facebook">Facebook</button>
						<button type="button" class="afk-mini-tab" data-canal="whatsapp">WhatsApp</button>
						<button type="button" class="afk-mini-tab" data-canal="linkedin">LinkedIn</button>
					</div>

					<div class="afk-share-carte" data-canal="instagram">
						<img class="afk-share-image" src="" alt="" hidden />
						<div class="afk-share-texte">
							<p class="afk-share-domaine"></p>
							<p class="afk-share-titre"></p>
							<p class="afk-share-desc"></p>
						</div>
					</div>
					<p class="afk-share-etat afk-hint" aria-live="polite"></p>

					<ul class="afk-preview-notes">
						<li data-canal-note="instagram"><?php esc_html_e( 'Instagram n’affiche d’aperçu que dans les messages privés — le fil n’accepte pas de lien.', 'africart-pwa' ); ?></li>
						<li data-canal-note="facebook" hidden><?php esc_html_e( 'Facebook garde les aperçus en mémoire : son débogueur de partage force le rafraîchissement.', 'africart-pwa' ); ?></li>
						<li data-canal-note="whatsapp" hidden><?php esc_html_e( 'L’aperçu se construit au moment où le message est envoyé.', 'africart-pwa' ); ?></li>
						<li data-canal-note="linkedin" hidden><?php esc_html_e( 'LinkedIn n’affiche généralement pas la description, seulement le titre et le domaine.', 'africart-pwa' ); ?></li>
					</ul>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Nom du site tel qu'il est transmis aux réseaux sociaux.
	 *
	 * Lu chez l'outil de référencement, qui en est propriétaire. Repli sur
	 * le titre du site quand aucun outil n'est en place.
	 *
	 * @return string
	 */
	private function social_site_name() {
		$titres = get_option( 'rank-math-options-titles', array() );

		if ( is_array( $titres ) ) {
			foreach ( array( 'website_name', 'knowledgegraph_name' ) as $cle ) {
				if ( ! empty( $titres[ $cle ] ) ) {
					return (string) $titres[ $cle ];
				}
			}
		}

		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Affiche une ligne de fond déclaré en feuille de style.
	 *
	 * Comme pour les déclencheurs, la même fonction sert l'affichage et
	 * le modèle cloné par le script.
	 *
	 * @param int|string $i    Indice de la ligne, ou '__i__' pour le modèle.
	 * @param array      $fond Valeurs de la ligne : selector, image.
	 */
	private function css_background_row( $i, $fond ) {
		$base     = esc_attr( self::OPTION . '[' . Africart_PWA_CSS_Backgrounds::OPTION_KEY . '][' . $i . ']' );
		$id       = 'afk-cssbg-' . $i;
		$variants = $fond['image'] ? Africart_PWA_Image_Variants::existing_for_url( $fond['image'] ) : array();
		?>
		<tr class="afk-repeater-row">
			<td>
				<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo $base; ?>[selector]"
					value="<?php echo esc_attr( $fond['selector'] ); ?>" class="regular-text code"
					placeholder="#bandeau-accueil" />
			</td>
			<td>
				<input type="text" id="<?php echo esc_attr( $id ); ?>-img" name="<?php echo $base; ?>[image]"
					value="<?php echo esc_attr( $fond['image'] ); ?>" class="large-text code afk-media-url"
					placeholder="<?php esc_attr_e( 'URL de l’image de fond', 'africart-pwa' ); ?>" />
				<button type="button" class="button afk-media-pick" style="margin-top:4px"
					data-target="<?php echo esc_attr( $id ); ?>-img" data-size-target="">
					<?php esc_html_e( 'Choisir…', 'africart-pwa' ); ?>
				</button>
				<?php if ( $fond['image'] ) : ?>
					<p class="afk-hint">
						<?php if ( $variants ) : ?>
							<?php
							$labels = array();
							foreach ( $variants as $v ) {
								$labels[] = strtoupper( $v['format'] ) . ' ' . size_format( $v['bytes'] );
							}
							/* translators: %s liste des formats allégés disponibles */
							echo esc_html( sprintf( __( 'Versions allégées disponibles : %s', 'africart-pwa' ), implode( ' · ', $labels ) ) );
							?>
						<?php else : ?>
							<strong style="color:#96762a"><?php esc_html_e( 'Aucune version allégée pour cette image — lancez le traitement, sinon la règle ne sera pas publiée.', 'africart-pwa' ); ?></strong>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</td>
			<td class="afk-repeater-action">
				<button type="button" class="button-link afk-repeater-remove"
					aria-label="<?php esc_attr_e( 'Retirer ce fond', 'africart-pwa' ); ?>">
					<span class="dashicons dashicons-trash"></span>
				</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Types de contenu proposables comme déclencheurs.
	 *
	 * On écarte les types techniques de WordPress et d'Elementor, sans
	 * quoi la liste devient illisible.
	 *
	 * @return array<string,string> Identifiant => libellé.
	 */
	private function post_type_choices() {
		$exclus = array(
			'attachment', 'revision', 'nav_menu_item',
			'elementor_library', 'e-floating-buttons', 'elementor_font', 'elementor_icons',
			'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles',
		);
		$choix  = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, $exclus, true ) ) {
				continue;
			}
			$choix[ $type->name ] = $type->labels->singular_name ? $type->labels->singular_name : $type->name;
		}

		return $choix;
	}

	private function render_push_tab( $o, $d ) {
		$actif = class_exists( 'Africart_PWA_OneSignal' ) && Africart_PWA_OneSignal::is_enabled();
		?>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Raccordement à OneSignal', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Raccorde le site à votre compte OneSignal.', 'africart-pwa' ); ?><?php $this->info( __( 'Créez dans OneSignal une application « Web Push », puis reportez son identifiant ci-dessous. Prévoyez une application par domaine : une application déclarée pour un site en refusera un autre.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Activation', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'os_enabled' ); ?>" value="1" <?php checked( self::get( 'os_enabled' ), '1' ); ?> />
							<?php esc_html_e( 'Activer les notifications', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint">
							<?php if ( $actif ) : ?>
								<strong style="color:#2f7d5d"><?php esc_html_e( 'Raccordement actif.', 'africart-pwa' ); ?></strong>
							<?php else : ?>
								<?php esc_html_e( 'Inactif : un identifiant d’application est nécessaire, et le Service Worker doit rester activé.', 'africart-pwa' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<?php
				$this->text_row( 'os_app_id', __( 'Identifiant d’application', 'africart-pwa' ), $o, $d, 'regular-text code', __( 'Visible dans OneSignal, sous Settings → Keys & IDs.', 'africart-pwa' ) );
				$this->text_row( 'os_safari_id', __( 'Identifiant Safari (facultatif)', 'africart-pwa' ), $o, $d, 'regular-text code', __( 'Uniquement pour Safari sur ordinateur. Sur iPhone, les notifications exigent que l’application ait d’abord été installée sur l’écran d’accueil.', 'africart-pwa' ) );
				?>
			</table>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Centres d’intérêt', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Le visiteur choisit ce qui l’intéresse ; les envois peuvent être ciblés.', 'africart-pwa' ); ?><?php $this->info( __( 'Chaque centre d’intérêt devient une étiquette dans OneSignal : un message peut alors ne partir qu’aux personnes concernées. Le libellé est celui que verra le visiteur.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<?php
				$rows   = class_exists( 'Africart_PWA_OneSignal' ) ? Africart_PWA_OneSignal::get_categories() : array();
				$rows[] = array( 'slug' => '', 'label' => '' );

				foreach ( $rows as $i => $row ) :
					$base = esc_attr( self::OPTION . '[' . Africart_PWA_OneSignal::CATEGORIES_KEY . '][' . $i . ']' );
					$fid  = 'afk-oscat-' . $i;
					?>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $fid ); ?>">
								<?php echo esc_html( $row['label'] ? $row['label'] : __( 'Ajouter', 'africart-pwa' ) ); ?>
							</label>
						</th>
						<td>
							<input type="text" id="<?php echo esc_attr( $fid ); ?>" name="<?php echo $base; ?>[label]"
								value="<?php echo esc_attr( $row['label'] ); ?>" class="regular-text"
								placeholder="<?php esc_attr_e( 'Nouvelles œuvres', 'africart-pwa' ); ?>" />
							<input type="text" name="<?php echo $base; ?>[slug]"
								value="<?php echo esc_attr( $row['slug'] ); ?>" class="regular-text code"
								placeholder="oeuvres" style="margin-top:4px" />
							<p class="afk-hint"><?php esc_html_e( 'Libellé affiché, puis identifiant technique (sans espace ni accent).', 'africart-pwa' ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="afk-hint"><?php esc_html_e( 'Enregistrez pour obtenir une ligne supplémentaire. Une ligne vidée est supprimée.', 'africart-pwa' ); ?></p>
		</div>

		<?php $this->render_push_sender_cards(); ?>
		<?php $this->render_push_test_card(); ?>

		<div class="afk-card">
			<h2>
				<?php esc_html_e( 'Fenêtre d’invitation', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Notre propre fenêtre, jamais celle de OneSignal : elle s’habille comme le site et se traduit entièrement. Les centres d’intérêt y sont repliés — un visiteur qui accepte sans les déplier reçoit tout, et porte alors toutes les étiquettes.', 'africart-pwa' ) ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Invite le visiteur à s’abonner, au bout d’un moment de lecture.', 'africart-pwa' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Activation', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'os_popup' ); ?>" value="1" <?php checked( self::get( 'os_popup' ), '1' ); ?> />
							<?php esc_html_e( 'Afficher la fenêtre d’invitation', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint">
							<?php esc_html_e( 'Jamais montrée à qui est déjà abonné, a déjà refusé, ou dont le navigateur a refusé une fois.', 'africart-pwa' ); ?>
							<?php $this->info( __( 'Sur iPhone, les notifications web n’existent QUE dans une application installée sur l’écran d’accueil : la fenêtre s’y tait d’elle-même dans Safari, puisque le navigateur ne saurait pas les recevoir. Sur Android et ordinateur, les deux contextes fonctionnent.', 'africart-pwa' ) ); ?>
						</p>
					</td>
				</tr>
				<?php
				$this->select_row( 'os_popup_context', __( 'Où la proposer', 'africart-pwa' ), array(
					'pwa'     => __( 'Application installée seulement (recommandé)', 'africart-pwa' ),
					'both'    => __( 'Application installée et navigateur', 'africart-pwa' ),
					'browser' => __( 'Navigateur seulement', 'africart-pwa' ),
				), $o );
				$this->number_row( 'os_popup_delay', __( 'Délai avant affichage', 'africart-pwa' ), $o, $d, __( 'secondes', 'africart-pwa' ) );
				$this->number_row( 'os_popup_days', __( 'Silence après un refus', 'africart-pwa' ), $o, $d, __( 'jours', 'africart-pwa' ) );
				$this->text_row( 'push_title', __( 'Titre de la fenêtre', 'africart-pwa' ), $o, $d );
				$this->text_row( 'push_choose', __( 'Lien de dépliage', 'africart-pwa' ), $o, $d );
				?>
			</table>

			<h2 style="margin-top:18px">
				<?php esc_html_e( 'Cloche de gestion', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Petite icône flottante, visible des seuls abonnés. Elle rouvre la fenêtre en mode gestion : centres d’intérêt et désabonnement. C’est le seul endroit d’où le visiteur peut retirer son consentement sans dépendre d’une page — le modèle du thème n’affichant pas toujours le contenu, un code court ne suffit pas.', 'africart-pwa' ) ); ?>
			</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Affichage', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'os_bell' ); ?>" value="1" <?php checked( self::get( 'os_bell' ), '1' ); ?> />
							<?php esc_html_e( 'Afficher la cloche aux abonnés', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint">
							<strong><?php esc_html_e( 'La désactiver prive le visiteur de son seul moyen de se désabonner', 'africart-pwa' ); ?></strong> —
							<?php esc_html_e( 'à ne faire que si un autre chemin existe sur le site.', 'africart-pwa' ); ?>
						</p>
					</td>
				</tr>
				<?php
				$this->text_row( 'push_manage', __( 'Titre en mode gestion', 'africart-pwa' ), $o, $d );
				$this->text_row( 'push_unsub', __( 'Bouton de désabonnement', 'africart-pwa' ), $o, $d );
				$this->text_row(
					'privacy_url',
					__( 'Politique de confidentialité', 'africart-pwa' ),
					$o,
					$d,
					'regular-text code',
					__( 'Laissé vide, le plugin utilise la page déclarée dans Réglages → Confidentialité. Sans page déclarée, aucun lien n’est affiché — un lien mort vaudrait moins que pas de lien.', 'africart-pwa' )
				);
				?>
			</table>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Textes et emplacement', 'africart-pwa' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Langue par défaut — les traductions se gèrent dans l’onglet Langues.', 'africart-pwa' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$this->text_row( 'push_prompt', __( 'Invitation', 'africart-pwa' ), $o, $d, 'large-text' );
				$this->text_row( 'push_accept', __( 'Bouton d’abonnement', 'africart-pwa' ), $o, $d );
				$this->text_row( 'push_refuse', __( 'Bouton de refus', 'africart-pwa' ), $o, $d );
				?>
			</table>
			<p class="description">
				<?php esc_html_e( 'Pour poser le choix des centres d’intérêt dans une page :', 'africart-pwa' ); ?>
				<code>[africart_notifications]</code>
			</p>
		</div>
		<?php
	}

	/**
	 * Onglet Performance : conversion d'images et état du rattrapage.
	 *
	 * @param array $o Réglages enregistrés.
	 * @param array $d Valeurs par défaut.
	 */
	private function render_perf_tab( $o, $d ) {
		$support = class_exists( 'Africart_PWA_Image_Converter' )
			? Africart_PWA_Image_Converter::supported_formats()
			: array( 'avif' => false, 'webp' => false );

		$state = class_exists( 'Africart_PWA_Image_Queue' )
			? Africart_PWA_Image_Queue::get_state()
			: array( 'status' => 'idle', 'offset' => 0, 'total' => 0, 'converted' => 0, 'rejected' => 0, 'failed' => 0, 'saved' => 0 );
		?>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Allègement des images', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Des versions allégées sont produites à côté des originaux.', 'africart-pwa' ); ?><?php $this->info( __( 'Les originaux ne sont jamais modifiés. Chaque navigateur reçoit ensuite le format le plus léger qu’il sait lire.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Conversion', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'perf_conversion' ); ?>" value="1" <?php checked( self::get( 'perf_conversion' ), '1' ); ?> />
							<?php esc_html_e( 'Produire les versions allégées des nouvelles images', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint"><?php esc_html_e( 'La conversion se fait en arrière-plan : le dépôt d’une œuvre n’est jamais ralenti.', 'africart-pwa' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Utilisation', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'perf_rewrite' ); ?>" value="1" <?php checked( self::get( 'perf_rewrite' ), '1' ); ?> />
							<?php esc_html_e( 'Proposer les versions allégées aux visiteurs', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint"><?php esc_html_e( 'Décochez pour revenir instantanément aux images d’origine, sans rien supprimer.', 'africart-pwa' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Formats produits', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'perf_avif' ); ?>" value="1" <?php checked( self::get( 'perf_avif' ), '1' ); ?> <?php disabled( empty( $support['avif'] ) ); ?> />
							<?php esc_html_e( 'AVIF — le plus efficace', 'africart-pwa' ); ?>
							<?php if ( empty( $support['avif'] ) ) : ?>
								<strong style="color:#b8392f"><?php esc_html_e( '— non disponible sur ce serveur', 'africart-pwa' ); ?></strong>
							<?php endif; ?>
						</label><br />
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'perf_webp' ); ?>" value="1" <?php checked( self::get( 'perf_webp' ), '1' ); ?> <?php disabled( empty( $support['webp'] ) ); ?> />
							<?php esc_html_e( 'WebP — pour les navigateurs qui ne lisent pas l’AVIF', 'africart-pwa' ); ?>
							<?php if ( empty( $support['webp'] ) ) : ?>
								<strong style="color:#b8392f"><?php esc_html_e( '— non disponible sur ce serveur', 'africart-pwa' ); ?></strong>
							<?php endif; ?>
						</label>
					</td>
				</tr>
				<?php
				$this->number_row( 'perf_avif_quality', __( 'Qualité AVIF', 'africart-pwa' ), $o, $d, __( 'de 1 à 100 — 55 offre un très bon compromis', 'africart-pwa' ) );
				$this->number_row( 'perf_webp_quality', __( 'Qualité WebP', 'africart-pwa' ), $o, $d, __( 'de 1 à 100 — 82 par défaut', 'africart-pwa' ) );
				?>
			</table>
		</div>

		<div class="afk-card">
			<h2>
				<?php esc_html_e( 'Chargement différé des œuvres', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Les œuvres sont des fonds CSS : l’attribut loading="lazy" des images ne s’y applique pas, et le navigateur télécharge tous les fonds — y compris ce que personne ne verra sans défiler. Le plugin retire l’image du style et la restitue à l’approche du défilement, avec 400 px d’avance pour qu’elle soit là avant d’entrer à l’écran.', 'africart-pwa' ) ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Les œuvres hors écran ne sont téléchargées qu’au moment où le visiteur s’en approche.', 'africart-pwa' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Activation', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'perf_lazy' ); ?>" value="1" <?php checked( self::get( 'perf_lazy' ), '1' ); ?> />
							<?php esc_html_e( 'Différer les fonds hors écran', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint"><?php esc_html_e( 'Nécessite que la distribution des variantes reste active.', 'africart-pwa' ); ?></p>
					</td>
				</tr>
				<?php
				$this->number_row(
					'perf_lazy_eager',
					__( 'Fonds chargés immédiatement', 'africart-pwa' ),
					$o,
					$d,
					__( 'les premiers de la page — ceux qui peuvent être à l’écran dès l’arrivée', 'africart-pwa' )
				);
				?>
			</table>
		</div>

		<div class="afk-card">
			<h2>
				<?php esc_html_e( 'Scripts tiers différés', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Mesuré : 63 % du JavaScript de l’accueil n’est jamais exécuté, et les plus gros postes sont des services tiers — statistiques, traduction, notifications. Aucun n’a besoin de bloquer l’arrivée : leurs scripts partent au premier geste du visiteur, ou à l’échéance du délai de secours. Un visiteur déjà traduit est servi immédiatement.', 'africart-pwa' ) ); ?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Les services tiers ne se chargent qu’au premier geste du visiteur.', 'africart-pwa' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Activation', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'perf_delay' ); ?>" value="1" <?php checked( self::get( 'perf_delay' ), '1' ); ?> />
							<?php esc_html_e( 'Différer les scripts listés', 'africart-pwa' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="afk-perf_delay_urls"><?php esc_html_e( 'Scripts visés', 'africart-pwa' ); ?></label>
						<?php $this->info( __( 'Un fragment d’adresse par ligne : toute balise script dont l’adresse contient ce fragment est différée. Retirer une ligne rend le script immédiat.', 'africart-pwa' ), true ); ?>
					</th>
					<td>
						<textarea id="afk-perf_delay_urls" name="<?php echo $this->name_attr( 'perf_delay_urls' ); ?>"
							rows="4" class="large-text code"
							placeholder="<?php echo esc_attr( isset( $d['perf_delay_urls'] ) ? $d['perf_delay_urls'] : '' ); ?>"><?php echo esc_textarea( $o['perf_delay_urls'] ); ?></textarea>
					</td>
				</tr>
				<?php $this->number_row( 'perf_delay_timeout', __( 'Délai de secours', 'africart-pwa' ), $o, $d, __( 'secondes — un visiteur immobile finit servi', 'africart-pwa' ) ); ?>
			</table>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Chargement des polices', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Le texte s’affiche sans attendre le chargement des polices.', 'africart-pwa' ); ?><?php $this->info( __( 'Deux corrections sans effet sur l’apparence : le texte apparaît immédiatement au lieu de rester invisible pendant le téléchargement, et la version compressée du fichier est servie quand elle existe — environ un tiers plus légère.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Optimisation', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'perf_fonts' ); ?>" value="1" <?php checked( self::get( 'perf_fonts' ), '1' ); ?> />
							<?php esc_html_e( 'Améliorer le chargement des polices', 'africart-pwa' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Polices détectées', 'africart-pwa' ); ?></th>
					<td>
						<?php
						$fonts   = Africart_PWA_Fonts::instance()->get_status();
						$avec    = 0;
						foreach ( $fonts as $f ) {
							if ( $f['woff2'] ) {
								++$avec;
							}
						}
						if ( $fonts ) :
							?>
							<p>
								<?php
								echo esc_html(
									sprintf(
										/* translators: %1$d polices, %2$d compressées */
										__( '%1$d polices utilisées, dont %2$d disposent d’une version compressée.', 'africart-pwa' ),
										count( $fonts ),
										$avec
									)
								);
								?>
							</p>
							<?php if ( $avec < count( $fonts ) ) : ?>
								<p class="afk-hint">
									<?php esc_html_e( 'Une police sans version compressée est servie telle quelle. Pour l’alléger, déposer le .woff2 à côté du .woff d’origine.', 'africart-pwa' ); ?>
								</p>
							<?php endif; ?>
						<?php else : ?>
							<p><?php esc_html_e( 'Aucune police personnalisée détectée.', 'africart-pwa' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<div class="afk-card">
			<h2>
				<?php esc_html_e( 'Images de fond à alléger manuellement', 'africart-pwa' ); ?>
				<?php
				$this->info(
					__( 'Une image posée en fond par Elementor est déclarée dans une feuille de style, pas dans la page : rien ne permet de la repérer automatiquement. La déclarer ici publie une règle prioritaire qui sert la version allégée, sans modifier aucun fichier d’Elementor.', 'africart-pwa' )
				);
				?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Les images de fond échappent au traitement automatique. Déclarez ici celles qui pèsent le plus — typiquement le grand visuel en haut d’une page.', 'africart-pwa' ); ?>
			</p>
			<p class="afk-hint">
				<?php esc_html_e( 'Dans Elementor : sélectionnez le conteneur, onglet Avancé → Identifiant CSS. Reportez-le ici précédé d’un dièse.', 'africart-pwa' ); ?>
			</p>
			<?php $fonds = Africart_PWA_CSS_Backgrounds::get_entries(); ?>
			<table class="widefat afk-repeater" id="afk-cssbg">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Identifiant CSS', 'africart-pwa' ); ?></th>
						<th><?php esc_html_e( 'Image', 'africart-pwa' ); ?></th>
						<th class="afk-repeater-action"><span class="screen-reader-text"><?php esc_html_e( 'Retirer', 'africart-pwa' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $fonds as $i => $fond ) : ?>
						<?php $this->css_background_row( $i, $fond ); ?>
					<?php endforeach; ?>
					<tr class="afk-repeater-empty" <?php echo $fonds ? 'style="display:none"' : ''; ?>>
						<td colspan="3"><?php esc_html_e( 'Aucune image déclarée.', 'africart-pwa' ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php // Témoin : voir la note du tableau des déclencheurs. ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPTION . '[' . Africart_PWA_CSS_Backgrounds::OPTION_KEY . '][temoin]' ); ?>" value="1" />

			<p>
				<button type="button" class="button" id="afk-cssbg-add">
					<?php esc_html_e( '+ Ajouter un fond', 'africart-pwa' ); ?>
				</button>
			</p>

			<script type="text/html" id="afk-cssbg-template">
				<?php $this->css_background_row( '__i__', array( 'selector' => '', 'image' => '' ) ); ?>
			</script>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Traitement des images déjà en ligne', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Les images déposées avant l’activation se traitent une fois.', 'africart-pwa' ); ?><?php $this->info( __( 'L’opération est longue. Elle peut être interrompue puis reprise sans perte, et se lance de préférence depuis le serveur pour les gros volumes.', 'africart-pwa' ) ); ?>
			</p>
			<?php Africart_PWA_Perf_Batch::render(); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Pour de gros volumes', 'africart-pwa' ); ?></th>
					<td>
						<p class="afk-hint">
							<?php esc_html_e( 'Au-delà de quelques centaines de médias, lancez-le depuis le serveur.', 'africart-pwa' ); ?><?php $this->info( __( 'Lancé depuis le serveur, le traitement n’a aucune limite de durée et n’exige pas de garder un onglet ouvert.', 'africart-pwa' ) ); ?>
						</p>
						<p><code>wp africart images convert</code></p>
						<p class="afk-hint">
							<?php esc_html_e( 'État : wp africart images status — Suppression des versions produites : wp africart images purge', 'africart-pwa' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Blocs repliables de traduction pour chaque langue autre que la
	 * langue par défaut. Placeholder = traduction par défaut embarquée.
	 */
	private function render_i18n_fields() {
		$default_lang = Africart_PWA_I18n::default_lang();
		$langs        = Africart_PWA_I18n::get_languages();
		$keys         = Africart_PWA_I18n::translatable_keys();
		$saved        = get_option( Africart_PWA_I18n::OPTION, array() );

		?>
		<div class="afk-lang-default">
			<?php
			printf(
				/* translators: %s: langue par défaut */
				esc_html__( '%s — langue par défaut : ses textes sont ceux des onglets Identité et Hors-ligne.', 'africart-pwa' ),
				'<strong>' . esc_html( Africart_PWA_I18n::lang_label( $default_lang ) ) . '</strong>'
			);
			?>
		</div>
		<?php

		foreach ( $langs as $lang ) {
			if ( $lang === $default_lang ) {
				continue;
			}
			$has_custom = ! empty( $saved[ $lang ] );
			?>
			<details class="afk-lang-block" <?php echo $has_custom ? 'open' : ''; ?>>
				<summary><?php echo esc_html( Africart_PWA_I18n::lang_label( $lang ) ); ?></summary>
				<table class="form-table" role="presentation">
					<?php foreach ( $keys as $key => $label ) : ?>
						<?php
						$field_id    = 'afk-i18n-' . $lang . '-' . $key;
						$name        = esc_attr( Africart_PWA_I18n::OPTION . '[' . $lang . '][' . $key . ']' );
						$value       = isset( $saved[ $lang ][ $key ] ) ? $saved[ $lang ][ $key ] : '';
						$placeholder = Africart_PWA_I18n::text( $key, $lang );
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<?php if ( 'offline_message' === $key ) : ?>
									<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo $name; ?>" rows="2" class="large-text"
										placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
								<?php else : ?>
									<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo $name; ?>"
										value="<?php echo esc_attr( $value ); ?>" class="regular-text"
										placeholder="<?php echo esc_attr( $placeholder ); ?>" />
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</details>
			<?php
		}
	}

	/* ------------------------------------------------------------------
	 * Helpers de rendu
	 * ---------------------------------------------------------------- */

	private function name_attr( $key ) {
		return esc_attr( self::OPTION . '[' . $key . ']' );
	}

	/**
	 * Icône d'aide portant une explication en infobulle.
	 *
	 * Les écrans portaient de longs paragraphes qui noyaient les champs.
	 * Le détail passe ici : accessible au survol comme au clavier, absent
	 * de l'interface le reste du temps.
	 *
	 * @param string $texte  Explication.
	 * @param bool   $gauche Aligner à gauche, près du bord de l'écran.
	 */
	private function info( $texte, $gauche = false ) {
		printf(
			'<span class="afk-info%s" tabindex="0"><span class="dashicons dashicons-editor-help"></span>'
				. '<span role="tooltip">%s</span></span>',
			$gauche ? ' afk-info-left' : '',
			esc_html( $texte )
		);
	}

	/* ------------------------------------------------------------------
	 * Codes courts
	 * ---------------------------------------------------------------- */

	/**
	 * Un code court, avec ses attributs.
	 *
	 * ⚠️ Le champ est en lecture seule et se sélectionne au clic.
	 *
	 * Un `<code>` se recopie mal sur un écran tactile — on attrape le
	 * crochet ou l'espace d'à côté — et l'auteur d'une page colle alors un
	 * code court amputé, qui ne rend rien et ne dit pas pourquoi.
	 *
	 * @param string $code       Le code court, crochets compris.
	 * @param string $quoi       Ce qu'il fait.
	 * @param array  $attributs  Lignes `array( nom, defaut, explication )`.
	 * @param array  $notes      Remarques d'usage.
	 */
	private function code_court( $code, $quoi, $attributs = array(), $notes = array() ) {
		?>
		<div class="afk-card">
			<h2><code><?php echo esc_html( $code ); ?></code></h2>
			<p class="description"><?php echo esc_html( $quoi ); ?></p>

			<p>
				<label class="screen-reader-text" for="<?php echo esc_attr( 'afk-code-' . md5( $code ) ); ?>">
					<?php esc_html_e( 'Code court à copier', 'africart-pwa' ); ?>
				</label>
				<input type="text" readonly class="large-text code"
					id="<?php echo esc_attr( 'afk-code-' . md5( $code ) ); ?>"
					value="<?php echo esc_attr( $code ); ?>"
					onfocus="this.select();" />
			</p>

			<?php if ( $attributs ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Attribut', 'africart-pwa' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Par défaut', 'africart-pwa' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Ce qu’il fait', 'africart-pwa' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $attributs as $ligne ) : ?>
							<tr>
								<td><code><?php echo esc_html( $ligne[0] ); ?></code></td>
								<td>
									<?php if ( '' === $ligne[1] ) : ?>
										<em><?php esc_html_e( 'vide', 'africart-pwa' ); ?></em>
									<?php else : ?>
										<code><?php echo esc_html( $ligne[1] ); ?></code>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $ligne[2] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="afk-hint"><?php esc_html_e( 'Aucun attribut : il se pose tel quel.', 'africart-pwa' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $notes as $note ) : ?>
				<p class="afk-hint"><?php echo esc_html( $note ); ?></p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * La page « Codes courts ».
	 *
	 * ⚠️ Les valeurs par défaut sont recopiées ici à la main.
	 *
	 * Elles vivent dans les `shortcode_atts()` de chaque module, et rien
	 * ne garantit que les deux restent d'accord : en changer une sans
	 * toucher à cette page ferait mentir la documentation, ce qui est pire
	 * que de ne rien documenter. C'est le prix d'une page lisible ; à
	 * relire quand on modifie un code court.
	 */
	private function render_codes_fields() {
		?>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Où poser ces codes', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Dans un bloc « Code court » de l’éditeur WordPress, dans un widget Elementor du même nom, ou dans un modèle de thème. Ils rendent tous du HTML autonome : aucun réglage de page n’est nécessaire.', 'africart-pwa' ); ?>
			</p>
			<p class="afk-hint">
				<?php esc_html_e( 'Trois d’entre eux ne s’affichent que pour un visiteur connecté ayant le droit de déposer une œuvre : le dépôt, la liste et l’en-tête. Déconnecté, la page ne montre rien à leur place — c’est voulu, et ce n’est pas une panne.', 'africart-pwa' ); ?>
			</p>
		</div>

		<?php
		$this->code_court(
			'[' . Africart_Artwork_Form::SHORTCODE . ']',
			__( 'Le formulaire de dépôt d’une œuvre. C’est lui qui fonctionne sans réseau : la saisie est conservée sur l’appareil et part quand l’artiste le décide.', 'africart-pwa' ),
			array(),
			array(
				__( 'Dans l’éditeur Elementor, le formulaire est rendu pour de vrai mais débranché : rien n’est enregistré, mis en file ni téléversé. Un simple encadré n’aurait pas montré ce que l’artiste verra.', 'africart-pwa' ),
				__( 'Le réglage « Utiliser notre formulaire » de l’onglet Œuvres ne concerne QUE la reprise du formulaire du thème. Là où ce code court est posé, notre formulaire s’affiche de toute façon.', 'africart-pwa' ),
			)
		);

		$this->code_court(
			'[' . Africart_Artwork_Cache::SHORTCODE . ']',
			__( 'La liste « Mes œuvres » de l’artiste connecté : ce qu’il a déposé, ce qui attend d’être envoyé, et l’état de chaque œuvre côté site.', 'africart-pwa' ),
			array(),
			array(
				__( 'Elle se remplit hors connexion : la copie locale est prise sur toutes les pages du site, pas seulement ici. Le code court trouve donc toujours de quoi s’afficher.', 'africart-pwa' ),
			)
		);

		$this->code_court(
			'[' . Africart_Artwork_Header::SHORTCODE . ']',
			__( 'L’en-tête de l’application : menus du site, menu du compte, cloche de notifications. Il s’adapte au téléphone, à la tablette et au grand écran.', 'africart-pwa' ),
			array(
				array( 'menu', 'menu-header-bureau', __( 'Menu du site sur grand écran. Identifiant ou identifiant-texte du menu WordPress.', 'africart-pwa' ) ),
				array( 'menu_mobile', 'menu-mobile', __( 'Menu en accordéon, sur téléphone et tablette.', 'africart-pwa' ) ),
				array( 'profil', 'user-menu', __( 'Menu du compte sur grand écran.', 'africart-pwa' ) ),
				array( 'profil_mobile', 'user-menu-mobile', __( 'Menu du compte sur téléphone.', 'africart-pwa' ) ),
				array( 'marque', 'mobile', __( '« mobile » : le logo au centre sur téléphone seulement. « oui » : partout. « non » : jamais.', 'africart-pwa' ) ),
				array( 'logo', '', __( 'Adresse d’image ou identifiant d’attachement. À défaut, le nom du site fait office de marque.', 'africart-pwa' ) ),
				array( 'hauteur_logo', '34', __( 'Hauteur du logo, en pixels.', 'africart-pwa' ) ),
				array( 'action', '', __( 'Adresse d’un raccourci de dépôt dans la barre. Vide : aucun bouton.', 'africart-pwa' ) ),
				array( 'action_texte', '', __( 'Libellé de ce raccourci.', 'africart-pwa' ) ),
				array( 'langues', '', __( 'Un code court à rendre dans la barre, par exemple celui du traducteur du site.', 'africart-pwa' ) ),
				array( 'notifications', 'oui', __( 'Affiche la cloche des notifications du thème.', 'africart-pwa' ) ),
				array( 'collant', 'oui', __( 'L’en-tête suit le défilement de la page.', 'africart-pwa' ) ),
			),
			array(
				__( 'Sur grand écran, le logo est masqué par défaut : cet en-tête se pose sous celui du site, qui le porte déjà. Sur téléphone, l’en-tête du site disparaît et le logo reprend sa place au centre.', 'africart-pwa' ),
				__( 'Aucun raccourci de dépôt par défaut : il chevauchait le menu dès que la barre se resserrait, et « Enregistrer une œuvre » est déjà une entrée du menu du site.', 'africart-pwa' ),
			)
		);

		$this->code_court(
			'[' . Africart_Artwork_Intro::SHORTCODE . ']',
			__( 'Le mode d’emploi du dépôt : les étapes à suivre, et le bouton qui mène au formulaire.', 'africart-pwa' ),
			array(
				array( 'titre', '', __( 'Titre au-dessus des étapes. Vide par défaut : la page porte déjà le sien.', 'africart-pwa' ) ),
				array( 'formulaire', __( 'page détectée', 'africart-pwa' ), __( 'Adresse de la page de dépôt. Détectée toute seule si elle porte le code court du formulaire.', 'africart-pwa' ) ),
				array( 'bouton', __( 'Enregistrer mon œuvre', 'africart-pwa' ), __( 'Libellé du bouton.', 'africart-pwa' ) ),
				array( 'fiche', '', __( 'Lien vers la fiche papier à imprimer. Vide : l’étape ne propose pas de téléchargement.', 'africart-pwa' ) ),
				array( 'courriel', __( 'e-mail du site', 'africart-pwa' ), __( 'Adresse à laquelle renvoyer la fiche remplie.', 'africart-pwa' ) ),
			)
		);

		$this->code_court(
			'[africart_install]',
			__( 'Un bouton « Installer l’application » posé dans le contenu d’une page, en plus du bandeau automatique.', 'africart-pwa' ),
			array(
				array( 'label', __( 'libellé du réglage', 'africart-pwa' ), __( 'Texte du bouton. À défaut, celui de l’onglet Installation.', 'africart-pwa' ) ),
				array( 'class', '', __( 'Classes CSS supplémentaires, pour le mettre à la charte d’une page.', 'africart-pwa' ) ),
			),
			array(
				__( 'Le bouton reste caché tant que le navigateur ne propose pas l’installation — déjà installée, ou navigateur qui ne le permet pas. Une page qui ne l’affiche pas n’est donc pas en panne.', 'africart-pwa' ),
			)
		);

		$this->code_court(
			'[africart_installer]',
			__( 'Une page d’installation complète, faite pour être partagée par lien : elle montre le geste exact de l’appareil sur lequel elle s’ouvre.', 'africart-pwa' ),
			array(
				array( 'titre', __( 'titre du réglage', 'africart-pwa' ), __( 'Titre de la page. À défaut, celui de l’onglet Installation.', 'africart-pwa' ) ),
				array( 'message', __( 'message du réglage', 'africart-pwa' ), __( 'Phrase d’accroche sous le titre.', 'africart-pwa' ) ),
				array( 'bouton', __( 'libellé du réglage', 'africart-pwa' ), __( 'Texte du bouton d’installation.', 'africart-pwa' ) ),
				array( 'visuels', 'oui', __( '« non » retire les visuels animés et ne garde que les textes.', 'africart-pwa' ) ),
				array( 'classe', '', __( 'Classes CSS supplémentaires sur la section.', 'africart-pwa' ) ),
			),
			array(
				__( 'C’est la réponse au lien qu’on envoie : le bandeau, lui, paraît de lui-même au bon moment sur le site, mais un lien partagé arrive sur des appareils où l’installation ne se propose jamais — aucun iPhone n’ouvre de fenêtre native, et le navigateur intégré d’Instagram ne le permet pas du tout.', 'africart-pwa' ),
				__( 'La marche à suivre affichée est celle de l’appareil qui ouvre la page. Sans JavaScript, toutes restent visibles : la page demeure un mode d’emploi complet plutôt qu’un écran vide.', 'africart-pwa' ),
				__( 'Le bouton d’installation est celui de [africart_install] : un seul bouton à tenir à jour, révélé au même moment que les autres.', 'africart-pwa' ),
				__( 'Comme le bouton, cette page ne s’affiche que si l’installation est activée dans l’onglet Installation.', 'africart-pwa' ),
			)
		);

		$this->code_court(
			'[africart_notifications]',
			__( 'Les centres d’intérêt des notifications : le visiteur coche ce qu’il veut recevoir, et peut se désabonner.', 'africart-pwa' ),
			array(
				array( 'title', '', __( 'Titre au-dessus de la liste. Vide : aucun titre.', 'africart-pwa' ) ),
			),
			array(
				__( 'La liste reprend les catégories déclarées dans l’onglet Notifications. Sans catégorie, il n’y a rien à cocher.', 'africart-pwa' ),
				__( 'Le bloc reste caché tant que le visiteur n’est pas abonné.', 'africart-pwa' ),
			)
		);
	}

	/**
	 * Aperçu du bandeau de consentement.
	 *
	 * Reproduit le rendu réel — mêmes couleurs, mêmes textes, même
	 * équilibre entre les deux boutons. C'est ce dernier point qui
	 * justifie l'aperçu : on ne juge pas à la lecture d'un formulaire
	 * si un refus est aussi accessible qu'une acceptation.
	 *
	 * @param array $o Valeurs en vigueur.
	 */
	private function render_consent_preview( $o ) {
		$actif = '1' === self::get( 'consent_enabled' );
		?>
		<div class="afk-card afk-preview-card">
			<h2>
				<?php esc_html_e( 'Aperçu du bandeau', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Reproduction indicative, mise à jour pendant votre saisie.', 'africart-pwa' ) ); ?>
			</h2>

			<?php if ( ! $actif ) : ?>
				<p class="afk-hint"><?php esc_html_e( 'Le bandeau est désactivé : le visiteur ne le verra pas. L’aperçu montre ce qu’il verrait une fois activé.', 'africart-pwa' ); ?></p>
			<?php endif; ?>

			<div class="afk-preview" id="afk-preview-consent">
				<figure class="afk-phone">
					<div class="afk-phone-screen afk-phone-page">
						<div class="afk-phone-fake"></div>
						<div class="afk-phone-fake afk-phone-fake-court"></div>
						<div class="afk-phone-fake"></div>

						<div class="afk-consent-mock" data-preview="theme_color">
							<p class="afk-consent-mock-titre" data-preview-text="consent_title"><?php echo esc_html( $o['consent_title'] ); ?></p>
							<p class="afk-consent-mock-texte" data-preview-text="consent_text"><?php echo esc_html( $o['consent_text'] ); ?></p>
							<div class="afk-consent-mock-boutons">
								<span class="afk-consent-mock-btn afk-consent-mock-refus" data-preview-text="consent_refuse"><?php echo esc_html( $o['consent_refuse'] ); ?></span>
								<span class="afk-consent-mock-btn afk-consent-mock-ok" data-preview="accent_color" data-preview-text="consent_accept"><?php echo esc_html( $o['consent_accept'] ); ?></span>
							</div>
						</div>
					</div>
				</figure>
			</div>

			<p class="afk-hint">
				<?php esc_html_e( 'Tant que le visiteur n’a pas répondu, aucune mesure ne part — ni celle du plugin, ni celle du gestionnaire de balises.', 'africart-pwa' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Champs de l'écran « Dépôt des œuvres ».
	 *
	 * @param array $o Valeurs en vigueur.
	 * @param array $d Valeurs d'usine.
	 */
	/**
	 * L'état réel des notifications, lu chez le thème.
	 *
	 * ⚠️ Lu, jamais recopié.
	 *
	 * Les textes, les destinataires et l'activation de chaque canal se
	 * règlent dans l'administration du thème. En proposer une seconde
	 * saisie ici aurait fait deux sources pour une même chose, dont l'une
	 * finit toujours par mentir. On montre ce qui s'applique vraiment, et
	 * on dit où le changer.
	 *
	 * @return array Une ligne par événement.
	 */
	private function notifications_du_theme() {
		$lignes = array(
			'post:submitted' => array( 'nom' => __( 'Œuvre soumise', 'africart-pwa' ), 'canaux' => null ),
			'post:updated'   => array( 'nom' => __( 'Œuvre mise à jour', 'africart-pwa' ), 'canaux' => null ),
		);

		if ( ! function_exists( 'Voxel\\get' ) || ! class_exists( 'Africart_Artwork_Repository' ) ) {
			return $lignes;
		}

		$type      = Africart_Artwork_Repository::POST_TYPE;
		$reglages  = (array) \Voxel\get( 'events', array() );

		foreach ( $lignes as $cle => $ligne ) {
			$config = (array) ( $reglages[ 'post-types/' . $type . '/' . $cle ]['notifications']['admin'] ?? array() );

			if ( ! $config ) {
				continue;
			}

			$canaux = array();

			if ( ! empty( $config['inapp']['enabled'] ) ) {
				$canaux[] = __( 'notification', 'africart-pwa' );
			}

			if ( ! empty( $config['email']['enabled'] ) ) {
				$canaux[] = __( 'courriel', 'africart-pwa' );
			}

			$lignes[ $cle ]['canaux'] = $canaux;
		}

		return $lignes;
	}

	private function render_artworks_fields( $o, $d ) {
		$dispo = class_exists( 'Africart_Artwork_Repository' ) && Africart_Artwork_Repository::is_available();
		?>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Prévenir l’équipe des nouveaux dépôts', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Une œuvre déposée depuis l’application ne passe pas par le formulaire du site : les notifications du thème ne partaient donc plus. L’application les déclenche à sa place.', 'africart-pwa' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Notifications d’équipe', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'art_notifs' ); ?>" value="1" <?php checked( self::get( 'art_notifs' ), '1' ); ?> />
							<?php esc_html_e( 'Prévenir l’équipe quand une œuvre est déposée ou modifiée depuis l’application', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint">
							<?php esc_html_e( 'À décocher seulement si le thème se met à prévenir de lui-même : vous recevriez alors chaque message en double.', 'africart-pwa' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Adresse destinataire', 'africart-pwa' ); ?></th>
					<td>
						<input type="email" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
							name="<?php echo $this->name_attr( 'art_notifs_email' ); ?>"
							value="<?php echo esc_attr( self::get( 'art_notifs_email' ) ); ?>" />
						<p class="afk-hint">
							<?php esc_html_e( 'Laissez vide pour écrire à la personne choisie dans le thème. Une adresse ici ne détourne que les courriels déclenchés par l’application : la notification dans le site, elle, reste destinée au compte réglé chez Voxel — une notification interne ne s’envoie pas à une adresse.', 'africart-pwa' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Ce qui part aujourd’hui', 'africart-pwa' ); ?></th>
					<td>
						<table class="widefat striped" style="max-width:520px">
							<tbody>
								<?php foreach ( $this->notifications_du_theme() as $ligne ) : ?>
									<tr>
										<td><?php echo esc_html( $ligne['nom'] ); ?></td>
										<td>
											<?php if ( null === $ligne['canaux'] ) : ?>
												<em><?php esc_html_e( 'non réglé dans le thème', 'africart-pwa' ); ?></em>
											<?php elseif ( ! $ligne['canaux'] ) : ?>
												<em><?php esc_html_e( 'rien n’est envoyé', 'africart-pwa' ); ?></em>
											<?php else : ?>
												<?php echo esc_html( implode( ' + ', $ligne['canaux'] ) ); ?>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="afk-hint">
							<?php esc_html_e( 'Le texte des messages, les destinataires et le choix des canaux se règlent dans le thème — Voxel, onglet des événements d’application. Les régler ici aussi aurait fait deux sources pour une même chose.', 'africart-pwa' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
		?>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Ce que l’application a le droit de faire', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Ces règles gouvernent le dépôt depuis l’application. Le formulaire du site garde les siennes.', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Les deux chemins coexistent sans se contredire : ils ne servent pas le même écran. « Suivre le site » recopie le réglage du thème — c’est le comportement attendu tant que personne n’a décidé autrement.', 'africart-pwa' ) ); ?>
			</p>

			<?php if ( ! $dispo ) : ?>
				<p class="afk-hint" style="color:#b3312c">
					<strong><?php esc_html_e( 'Le thème des œuvres est indisponible.', 'africart-pwa' ); ?></strong>
					<?php esc_html_e( 'Ces réglages resteront sans effet.', 'africart-pwa' ); ?>
				</p>
			<?php else : ?>
				<?php $regles = Africart_Artwork_Repository::submission_rules(); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="afk-art_statut_creation"><?php esc_html_e( 'Une œuvre déposée arrive en', 'africart-pwa' ); ?></label></th>
						<td>
							<select id="afk-art_statut_creation" name="<?php echo $this->name_attr( 'art_statut_creation' ); ?>">
								<option value=""><?php
									/* translators: %s statut réglé dans le thème */
									printf( esc_html__( 'Suivre le site (%s)', 'africart-pwa' ), esc_html( Africart_Artwork_Rules::libelle( $regles['status'] ?? '' ) ) );
								?></option>
								<?php foreach ( Africart_Artwork_Rules::STATUTS_CREATION as $st ) : ?>
									<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $o['art_statut_creation'], $st ); ?>>
										<?php echo esc_html( Africart_Artwork_Rules::libelle( $st ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="afk-hint"><?php esc_html_e( '« En attente de validation » n’est pas « brouillon » : l’œuvre est complète et déposée, elle attend un regard. Le dire autrement ferait croire à l’artiste que son envoi n’est pas parti.', 'africart-pwa' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="afk-art_suppression"><?php esc_html_e( 'Retrait d’une œuvre', 'africart-pwa' ); ?></label></th>
						<td>
							<select id="afk-art_suppression" name="<?php echo $this->name_attr( 'art_suppression' ); ?>">
								<option value=""><?php
									printf(
										/* translators: %s comportement du thème */
										esc_html__( 'Suivre le site (%s)', 'africart-pwa' ),
										esc_html( empty( $regles['deletable'] ) ? __( 'refusé', 'africart-pwa' ) : __( 'permis', 'africart-pwa' ) )
									);
								?></option>
								<option value="1" <?php selected( $o['art_suppression'], '1' ); ?>><?php esc_html_e( 'Permis', 'africart-pwa' ); ?></option>
								<option value="0" <?php selected( $o['art_suppression'], '0' ); ?>><?php esc_html_e( 'Refusé', 'africart-pwa' ); ?></option>
							</select>
							<p class="afk-hint"><?php esc_html_e( 'Question de gouvernance du répertoire, pas de technique : laisser un contributeur effacer une entrée déjà citée ailleurs se discute. Le retrait met à la corbeille, jamais en suppression définitive.', 'africart-pwa' ); ?></p>
						</td>
					</tr>
				</table>

				<?php $ecarts = Africart_Artwork_Rules::ecarts(); ?>
				<?php if ( $ecarts ) : ?>
					<p class="afk-hint" style="color:#8a6d00">
						<strong><?php esc_html_e( 'Les deux chemins de dépôt diffèrent :', 'africart-pwa' ); ?></strong>
					</p>
					<ul style="margin:0 0 4px 18px;list-style:disc">
						<?php foreach ( $ecarts as $e ) : ?>
							<li><?php echo esc_html( $e ); ?></li>
						<?php endforeach; ?>
					</ul>
					<p class="afk-hint"><?php esc_html_e( 'Ce n’est pas une anomalie, mais un écart silencieux entre deux chemins rend un site incompréhensible six mois plus tard. Il est donc affiché.', 'africart-pwa' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Le formulaire de dépôt', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Par défaut, notre formulaire ne prend la main que sans réseau ; en ligne, celui du site reste en charge.', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Deux formulaires pour un même geste, c’est deux expériences qui divergent. Basculer sur le nôtre dans les deux cas les réunit — mais suppose d’avoir retiré le formulaire du thème de la page, sans quoi les deux s’afficheraient l’un sous l’autre.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Utiliser notre formulaire', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'art_form_toujours' ); ?>" value="1" <?php checked( self::get( 'art_form_toujours' ), '1' ); ?> />
							<?php esc_html_e( 'Aussi quand le réseau est disponible', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint">
							<?php
							/* translators: %s le shortcode à insérer */
							printf( esc_html__( 'Ce réglage ne concerne que la reprise du formulaire du thème. Si vous placez %s vous-même sur une page, notre formulaire s’y affiche de toute façon, réseau ou pas.', 'africart-pwa' ), '<code>[' . esc_html( Africart_Artwork_Form::SHORTCODE ) . ']</code>' );
							?>
						</p>
						<p class="afk-hint">
							<strong><?php esc_html_e( 'À n’activer qu’après avoir retiré le formulaire du thème de la page', 'africart-pwa' ); ?></strong>
							<?php esc_html_e( '— sans quoi les deux s’afficheraient l’un sous l’autre.', 'africart-pwa' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Ce que voit l’artiste sur chaque œuvre', 'africart-pwa' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Le premier groupe dit où en est l’œuvre côté site ; le second, où en est son envoi depuis cet appareil. Deux choses différentes, et les confondre ferait croire qu’une œuvre en validation n’est pas partie.', 'africart-pwa' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$this->text_row( 'art_etat_draft', __( 'État : brouillon', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_etat_review', __( 'État : en attente de validation', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_etat_published', __( 'État : publiée', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_etat_rejected', __( 'État : refusée', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_etat_unpublished', __( 'État : retirée', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_sync_attente', __( 'Envoi : en attente', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_sync_encours', __( 'Envoi : en cours', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_sync_incomplet', __( 'Envoi : à compléter', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_sync_conflit', __( 'Envoi : conflit', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_sync_erreur', __( 'Envoi : échec', 'africart-pwa' ), $o, $d, 'regular-text' );
				?>
			</table>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Messages hors connexion', 'africart-pwa' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Le %s est remplacé par la date de la dernière synchronisation. « Injoignable » et « hors connexion » sont distingués : le premier arrive avec du réseau mais un serveur muet, et annoncer une absence de réseau enverrait l’artiste vérifier son forfait.', 'africart-pwa' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$this->text_row( 'art_hors_ligne', __( 'Bandeau hors connexion', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_injoignable', __( 'Bandeau site injoignable', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_vide', __( 'Bandeau sans aucune œuvre', 'africart-pwa' ), $o, $d, 'regular-text' );
				?>
			</table>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Formulaire de dépôt', 'africart-pwa' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Le %s du message d’enregistrement est remplacé par l’heure.', 'africart-pwa' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				// ⚠️ « Formulaire : bandeau » et « Saisie en cours » ont été
				// retirés de cet écran, pas oubliés. Le premier était un
				// texte fixe annonçant « hors connexion » à des artistes
				// connectés ; le second clignotait sous les doigts. Les deux
				// sont remplacés par la bande d'état, qui mesure au lieu de
				// deviner. Laisser leurs champs ici aurait fait régler des
				// messages que plus personne n'affiche.
				$this->text_row( 'art_form_absent', __( 'Formulaire : non chargé', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_form_depot', __( 'Formulaire : bouton de dépôt', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_form_enfile', __( 'Formulaire : bouton après dépôt', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_form_partira', __( 'Formulaire : message après dépôt', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_form_enregistre', __( 'Formulaire : saisie enregistrée', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_form_titre_dabord', __( 'Formulaire : titre requis pour conserver', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row( 'art_form_sortie', __( 'Formulaire : enregistrer et revenir', 'africart-pwa' ), $o, $d, 'regular-text' );
				?>
			</table>
		</div>

		<?php
	}

	/**
	 * Champs de l'écran « Statistiques ».
	 *
	 * @param array $o Valeurs en vigueur.
	 * @param array $d Valeurs d'usine.
	 */
	private function render_stats_fields( $o, $d ) {
		$conteneur = Africart_PWA_Analytics::container_id();
		?>
		<div class="afk-card">
			<h2><?php esc_html_e( 'Connexion au gestionnaire de balises', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Le plugin n’envoie rien à Google. Il annonce des faits — bandeau affiché, installation faite, abonnement pris — et le gestionnaire de balises décide ce qu’il en fait.', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Ce découpage permet de changer d’outil de mesure sans toucher au site : seuls les déclencheurs du gestionnaire changent. Tant qu’aucun conteneur n’est détecté, les événements partent vers l’outil déjà en place — rien ne s’interrompt pendant la bascule.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Mesurer les usages', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'stats_enabled' ); ?>" value="1" <?php checked( self::get( 'stats_enabled' ), '1' ); ?> />
							<?php esc_html_e( 'Annoncer les événements du plugin', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint"><?php esc_html_e( 'Décoché, le plugin n’émet plus rien. Les autres mesures du site ne sont pas affectées.', 'africart-pwa' ); ?></p>
					</td>
				</tr>
				<?php
				$this->text_row(
					'stats_gtm_id',
					__( 'Conteneur Google Tag Manager', 'africart-pwa' ),
					$o,
					$d,
					'regular-text code',
					__( 'De la forme GTM-XXXXXX. Un identifiant mal formé est ignoré plutôt que posé : une balise approximative casserait la page sans rien dire.', 'africart-pwa' )
				);
				$this->text_row(
					'stats_ga4_id',
					__( 'Flux GA4 (pour mémoire)', 'africart-pwa' ),
					$o,
					$d,
					'regular-text code',
					__( 'De la forme G-XXXXXXX. Le plugin ne s’en sert pas : c’est le gestionnaire de balises qui porte la balise GA4. Le noter ici évite d’avoir à le rechercher.', 'africart-pwa' )
				);
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Poser le conteneur', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'stats_inject' ); ?>" value="1" <?php checked( self::get( 'stats_inject' ), '1' ); ?> />
							<?php esc_html_e( 'Le plugin insère lui-même le conteneur dans les pages', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint">
							<strong><?php esc_html_e( 'À n’activer que si aucune autre extension ne le pose déjà.', 'africart-pwa' ); ?></strong>
							<?php esc_html_e( 'Deux conteneurs identiques sur une page comptent chaque visite deux fois, sans le moindre avertissement.', 'africart-pwa' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Consentement', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'stats_consent' ); ?>" value="1" <?php checked( self::get( 'stats_consent' ), '1' ); ?> />
							<?php esc_html_e( 'Ces événements décrivent un comportement de visiteur', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint"><?php esc_html_e( 'À cocher si un consentement est recueilli — par le bandeau ci-dessous, ou par une autre extension. Sans cela, la conformité obtenue côté notifications serait contredite côté mesure.', 'africart-pwa' ); ?></p>
						<?php if ( '1' === self::get( 'stats_consent' ) && '1' !== self::get( 'consent_enabled' ) ) : ?>
							<p class="afk-hint" style="color:#8a6d00">
								<strong><?php esc_html_e( 'Le consentement n’est pas recueilli ici.', 'africart-pwa' ); ?></strong>
								<?php esc_html_e( 'Le bandeau ci-dessous est désactivé : c’est donc au gestionnaire de balises de conditionner ses balises, lui seul sachant ce qui a été accordé. Le plugin, lui, continue d’annoncer ses événements.', 'africart-pwa' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php
			$flux = trim( (string) $o['stats_ga4_id'] );
			if ( '' !== $flux && ! preg_match( '/^G-[A-Z0-9]{6,}$/i', $flux ) ) :
				?>
				<p class="afk-hint" style="color:#8a6d00">
					<strong><?php esc_html_e( 'Ceci ne ressemble pas à un flux GA4.', 'africart-pwa' ); ?></strong>
					<?php esc_html_e( 'Un flux commence par G-. Ce champ n’est qu’un pense-bête : rien ne s’appuie dessus, mais un identifiant de conteneur recopié ici prêterait à confusion.', 'africart-pwa' ); ?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== trim( (string) $o['stats_gtm_id'] ) && '' === $conteneur ) : ?>
				<p class="afk-hint" style="color:#b32d2e">
					<strong><?php esc_html_e( 'Identifiant non reconnu.', 'africart-pwa' ); ?></strong>
					<?php esc_html_e( 'Il n’est pas de la forme GTM-XXXXXX : le conteneur ne sera pas posé.', 'africart-pwa' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Bandeau de consentement', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Recueille l’accord du visiteur avant toute mesure, et transmet sa réponse au gestionnaire de balises.', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Tant que le visiteur n’a pas répondu, la mesure est refusée par défaut : c’est l’état que Google appelle « consentement refusé », et les balises s’y conforment d’elles-mêmes. Aucun réglage n’est à faire dans le gestionnaire pour cela.', 'africart-pwa' ) ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Afficher le bandeau', 'africart-pwa' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo $this->name_attr( 'consent_enabled' ); ?>" value="1" <?php checked( self::get( 'consent_enabled' ), '1' ); ?> />
							<?php esc_html_e( 'Demander son accord au visiteur', 'africart-pwa' ); ?>
						</label>
						<p class="afk-hint">
							<strong><?php esc_html_e( 'À n’activer que si aucune autre extension ne demande déjà ce consentement.', 'africart-pwa' ); ?></strong>
							<?php esc_html_e( 'Deux bandeaux se contrediraient, et le visiteur ne saurait plus lequel fait foi.', 'africart-pwa' ); ?>
						</p>
					</td>
				</tr>
				<?php
				$this->text_row( 'consent_title', __( 'Titre', 'africart-pwa' ), $o, $d );
				?>
				<tr>
					<th scope="row"><label for="afk-consent_text"><?php esc_html_e( 'Explication', 'africart-pwa' ); ?></label></th>
					<td>
						<textarea id="afk-consent_text" name="<?php echo $this->name_attr( 'consent_text' ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $o['consent_text'] ); ?></textarea>
						<p class="afk-hint"><?php esc_html_e( 'Dire ce qui est mesuré et pourquoi. Une formule vague ne vaut pas consentement éclairé.', 'africart-pwa' ); ?></p>
					</td>
				</tr>
				<?php
				$this->text_row( 'consent_accept', __( 'Bouton d’acceptation', 'africart-pwa' ), $o, $d, 'regular-text' );
				$this->text_row(
					'consent_refuse',
					__( 'Bouton de refus', 'africart-pwa' ),
					$o,
					$d,
					'regular-text',
					__( 'Les deux boutons ont le même poids visuel, volontairement. Un refus plus discret que l’acceptation vicierait le consentement, et c’est le premier reproche fait aux bandeaux existants.', 'africart-pwa' )
				);
				$this->text_row(
					'consent_days',
					__( 'Durée du choix (jours)', 'africart-pwa' ),
					$o,
					$d,
					'small-text',
					__( 'Au-delà, la question est reposée. Treize mois est le maximum usuellement retenu.', 'africart-pwa' )
				);
				?>
			</table>
		</div>

		<div class="afk-card">
			<h2><?php esc_html_e( 'Les événements annoncés', 'africart-pwa' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Créez dans le gestionnaire un déclencheur « Événement personnalisé » portant exactement ce nom, puis une balise GA4 qui s’y rattache.', 'africart-pwa' ); ?>
				<?php $this->info( __( 'Le nom se recopie tel quel, sans préfixe ni suffixe. Les paramètres se récupèrent par une variable de couche de données du même nom.', 'africart-pwa' ) ); ?>
			</p>
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Nom de l’événement', 'africart-pwa' ); ?></th>
						<th><?php esc_html_e( 'Ce qu’il signale', 'africart-pwa' ); ?></th>
						<th><?php esc_html_e( 'Paramètres', 'africart-pwa' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Africart_PWA_Analytics::events() as $nom => $e ) : ?>
						<tr>
							<td><code><?php echo esc_html( $nom ); ?></code></td>
							<td><?php echo esc_html( $e['quoi'] ); ?></td>
							<td><code><?php echo esc_html( $e['params'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="afk-hint">
				<?php esc_html_e( 'Les notifications envoyées restent comptées par OneSignal, qui le fait nativement. Seules les ouvertures remontent utilement ici.', 'africart-pwa' ); ?>
			</p>
		</div>
		<?php
	}

	private function text_row( $key, $label, $o, $d, $class = 'regular-text', $hint = '' ) {
		?>
		<tr>
			<th scope="row">
				<label for="afk-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<?php if ( $hint ) { $this->info( $hint, true ); } ?>
			</th>
			<td>
				<input type="text" id="afk-<?php echo esc_attr( $key ); ?>" name="<?php echo $this->name_attr( $key ); ?>"
					value="<?php echo esc_attr( $o[ $key ] ); ?>" class="<?php echo esc_attr( $class ); ?>"
					placeholder="<?php echo esc_attr( isset( $d[ $key ] ) ? $d[ $key ] : '' ); ?>" />
			</td>
		</tr>
		<?php
	}

	private function number_row( $key, $label, $o, $d, $suffix = '' ) {
		?>
		<tr>
			<th scope="row"><label for="afk-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="number" min="0" id="afk-<?php echo esc_attr( $key ); ?>" name="<?php echo $this->name_attr( $key ); ?>"
					value="<?php echo esc_attr( $o[ $key ] ); ?>" class="small-text"
					placeholder="<?php echo esc_attr( isset( $d[ $key ] ) ? $d[ $key ] : '' ); ?>" />
				<?php if ( $suffix ) : ?><span class="afk-hint"><?php echo esc_html( $suffix ); ?></span><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function color_row( $key, $label, $o, $d, $hint = '' ) {
		?>
		<tr>
			<th scope="row">
				<label for="afk-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
				<?php if ( $hint ) { $this->info( $hint, true ); } ?>
			</th>
			<td>
				<?php
				// Le sélecteur natif n'accepte qu'un code hexadécimal complet ;
				// une valeur inattendue le ferait retomber sur le noir sans
				// prévenir. On ne le pré-remplit donc qu'à bon escient.
				$hex = preg_match( '/^#[0-9a-f]{6}$/i', (string) $o[ $key ] ) ? $o[ $key ] : '#000000';
				?>
				<span class="afk-color-field">
					<input type="color" class="afk-color-swatch"
						value="<?php echo esc_attr( $hex ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s libellé du réglage */ __( 'Choisir la couleur : %s', 'africart-pwa' ), $label ) ); ?>" />
					<input type="text" id="afk-<?php echo esc_attr( $key ); ?>" name="<?php echo $this->name_attr( $key ); ?>"
						value="<?php echo esc_attr( $o[ $key ] ); ?>" class="afk-color code"
						data-default-color="<?php echo esc_attr( $d[ $key ] ); ?>"
						placeholder="<?php echo esc_attr( isset( $d[ $key ] ) ? $d[ $key ] : '' ); ?>"
						spellcheck="false" />
					<button type="button" class="button-link afk-color-reset"
						data-default="<?php echo esc_attr( $d[ $key ] ); ?>">
						<?php esc_html_e( 'Valeur d’usine', 'africart-pwa' ); ?>
					</button>
				</span>
			</td>
		</tr>
		<?php
	}

	private function select_row( $key, $label, $choices, $o ) {
		$current = self::get( $key );
		?>
		<tr>
			<th scope="row"><label for="afk-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="afk-<?php echo esc_attr( $key ); ?>" name="<?php echo $this->name_attr( $key ); ?>">
					<?php foreach ( $choices as $value => $text ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	private function media_row( $key, $label, $o, $d, $size_target = '' ) {
		$preview = self::get( $key );
		?>
		<tr>
			<th scope="row"><label for="afk-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" id="afk-<?php echo esc_attr( $key ); ?>" name="<?php echo $this->name_attr( $key ); ?>"
					value="<?php echo esc_attr( $o[ $key ] ); ?>" class="regular-text code afk-media-url"
					placeholder="<?php echo esc_attr( isset( $d[ $key ] ) ? $d[ $key ] : '' ); ?>" />
				<button type="button" class="button afk-media-pick" data-target="afk-<?php echo esc_attr( $key ); ?>"
					data-size-target="<?php echo esc_attr( $size_target ); ?>"><?php esc_html_e( 'Choisir…', 'africart-pwa' ); ?></button>
				<span class="afk-media-preview"><img id="afk-preview-<?php echo esc_attr( $key ); ?>" src="<?php echo esc_url( $preview ); ?>" alt="" /></span>
			</td>
		</tr>
		<?php
	}

	private function size_row( $key_w, $key_h, $label, $o, $d ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="number" id="afk-<?php echo esc_attr( $key_w ); ?>" name="<?php echo $this->name_attr( $key_w ); ?>"
					value="<?php echo esc_attr( $o[ $key_w ] ); ?>" class="small-text" placeholder="<?php echo esc_attr( $d[ $key_w ] ); ?>" />
				×
				<input type="number" id="afk-<?php echo esc_attr( $key_h ); ?>" name="<?php echo $this->name_attr( $key_h ); ?>"
					value="<?php echo esc_attr( $o[ $key_h ] ); ?>" class="small-text" placeholder="<?php echo esc_attr( $d[ $key_h ] ); ?>" />
				px
			</td>
		</tr>
		<?php
	}
}
