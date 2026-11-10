<?php
/**
 * Module i18n — textes de la PWA par langue, sans plugin tiers.
 *
 * Le site traduit ses pages côté client via le widget Google Translate,
 * qui mémorise la langue choisie dans le cookie `googtrans` (ex. « /fr/en »).
 * Ce module lit ce cookie pour connaître la langue active et sert les
 * textes de la PWA (manifest, page hors-ligne, bandeau…) dans cette langue.
 *
 * Trois niveaux de résolution pour chaque texte :
 *   1. traduction saisie dans Réglages > AFRICART PWA (option afk_pwa_i18n) ;
 *   2. traduction par défaut embarquée (en, es) ;
 *   3. repli sur le texte français (réglages principaux).
 *
 * La langue par défaut du site (fr) utilise directement les réglages
 * principaux — pas de doublon de saisie.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_I18n {

	const OPTION = 'afk_pwa_i18n';

	/**
	 * Langue par défaut du site : celle configurée dans le widget de
	 * traduction (option GLT), sinon la locale WordPress, sinon fr.
	 *
	 * @return string
	 */
	public static function default_lang() {
		$source = strtolower( trim( (string) get_option( 'googlelanguagetranslator_language', '' ) ) );
		if ( $source ) {
			return $source;
		}
		$locale = substr( (string) get_locale(), 0, 2 );
		return $locale ? $locale : 'fr';
	}

	/**
	 * Détection DYNAMIQUE des langues du site depuis la configuration du
	 * widget Google Language Translator (aucune liste en dur) :
	 *   - langue source  : `googlelanguagetranslator_language` ;
	 *   - langues cibles : `googlelanguagetranslator_multilanguage` si
	 *     renseignée, sinon `googlelanguagetranslator_flags_order`
	 *     (noms anglais convertis en codes).
	 * Si le site ajoute/retire une langue au widget, la PWA suit.
	 *
	 * @return string[] Codes langue, langue par défaut en premier.
	 */
	public static function detect_site_languages() {
		$langs = array( self::default_lang() );

		$targets = array();

		$multi = get_option( 'googlelanguagetranslator_multilanguage' );
		if ( is_array( $multi ) ) {
			$targets = $multi;
		} elseif ( is_string( $multi ) && '' !== trim( $multi ) ) {
			$targets = explode( ',', $multi );
		}

		if ( ! $targets ) {
			$flags   = (string) get_option( 'googlelanguagetranslator_flags_order', '' );
			$targets = array_filter( array_map( 'trim', explode( ',', $flags ) ) );
		}

		foreach ( $targets as $t ) {
			$code = self::lang_code( $t );
			if ( $code ) {
				$langs[] = $code;
			}
		}

		// Config widget introuvable : repli raisonnable.
		if ( count( $langs ) < 2 ) {
			$langs = array( self::default_lang(), 'en', 'es' );
		}

		return array_values( array_unique( $langs ) );
	}

	/**
	 * Liste détectée au format CSV (placeholder/valeur par défaut du réglage).
	 *
	 * @return string
	 */
	public static function detect_site_languages_csv() {
		return implode( ', ', self::detect_site_languages() );
	}

	/**
	 * Convertit un nom de langue GLT (« French ») ou un code en code langue.
	 *
	 * @param string $name Nom anglais ou code.
	 * @return string Code langue, ou chaîne vide si inconnu.
	 */
	private static function lang_code( $name ) {
		$name = strtolower( trim( (string) $name ) );

		if ( preg_match( '/^[a-z]{2}(-[a-z]{2,4})?$/i', $name ) ) {
			return $name; // Déjà un code.
		}

		$map = array(
			'french'               => 'fr',
			'english'              => 'en',
			'spanish'              => 'es',
			'portuguese'           => 'pt',
			'german'               => 'de',
			'italian'              => 'it',
			'arabic'               => 'ar',
			'dutch'                => 'nl',
			'russian'              => 'ru',
			'japanese'             => 'ja',
			'chinese (simplified)' => 'zh-CN',
			'chinese'              => 'zh-CN',
			'swahili'              => 'sw',
			'turkish'              => 'tr',
			'wolof'                => 'wo',
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : '';
	}

	/**
	 * Langues gérées par la PWA. Par ordre de priorité :
	 *   1. liste saisie dans Réglages > AFRICART PWA (champ « languages ») ;
	 *   2. détection dynamique depuis la config du site (voir ci-dessus).
	 *
	 * @return string[] Codes langue (BCP-47 courts).
	 */
	public static function get_languages() {
		$raw   = (string) Africart_PWA_Settings::get( 'languages' );
		$langs = array_filter( array_map( function ( $l ) {
			return strtolower( trim( $l ) );
		}, explode( ',', $raw ) ) );

		if ( ! in_array( self::default_lang(), $langs, true ) ) {
			array_unshift( $langs, self::default_lang() );
		}

		/**
		 * Filtre la liste des langues gérées par la PWA.
		 *
		 * @param string[] $langs Codes langue.
		 */
		return apply_filters( 'afk_pwa_languages', array_values( array_unique( $langs ) ) );
	}

	/**
	 * Langue active de l'utilisateur, d'après le cookie `googtrans`
	 * (« /fr/en », « /auto/es »…). Repli : langue par défaut.
	 *
	 * @return string
	 */
	public static function get_current_lang() {
		if ( isset( $_COOKIE['googtrans'] ) ) {
			$cookie = sanitize_text_field( wp_unslash( $_COOKIE['googtrans'] ) );
			$parts  = array_values( array_filter( explode( '/', $cookie ) ) );
			$target = $parts ? strtolower( end( $parts ) ) : '';

			if ( $target && in_array( $target, self::get_languages(), true ) ) {
				return $target;
			}
		}
		return self::default_lang();
	}

	/**
	 * Libellé humain d'un code langue (drapeau + nom natif) pour l'admin.
	 *
	 * @param string $code Code langue.
	 * @return string
	 */
	public static function lang_label( $code ) {
		$labels = array(
			'fr' => '🇫🇷 Français',
			'en' => '🇬🇧 English',
			'es' => '🇪🇸 Español',
			'pt' => '🇵🇹 Português',
			'de' => '🇩🇪 Deutsch',
			'it' => '🇮🇹 Italiano',
			'ar' => '🇸🇦 العربية',
			'nl' => '🇳🇱 Nederlands',
			'ru' => '🇷🇺 Русский',
			'ja' => '🇯🇵 日本語',
			'sw' => '🇹🇿 Kiswahili',
			'tr' => '🇹🇷 Türkçe',
			'wo' => '🇸🇳 Wolof',
		);
		return isset( $labels[ $code ] ) ? $labels[ $code ] : '🌐 ' . strtoupper( $code );
	}

	/**
	 * Clés de texte traduisibles => libellé affiché dans l'admin.
	 *
	 * @return array<string,string>
	 */
	public static function translatable_keys() {
		return array(
			'app_name'         => __( 'Nom de l’application', 'africart-pwa' ),
			'description'      => __( 'Description', 'africart-pwa' ),
			'shortcut_name'    => __( 'Libellé du raccourci', 'africart-pwa' ),
			'offline_title'    => __( 'Titre hors-ligne', 'africart-pwa' ),
			'offline_message'  => __( 'Message hors-ligne', 'africart-pwa' ),
			'offline_button'   => __( 'Bouton hors-ligne', 'africart-pwa' ),
			'install_title'    => __( 'Bandeau — titre', 'africart-pwa' ),
			'install_message'  => __( 'Bandeau — message', 'africart-pwa' ),
			'install_button'   => __( 'Bandeau — bouton', 'africart-pwa' ),
			'install_ios'      => __( 'Bandeau — instructions iOS', 'africart-pwa' ),
			'install_inapp'    => __( 'Bandeau — navigateur in-app', 'africart-pwa' ),
			'install_samsung'  => __( 'Bandeau — note Samsung Internet', 'africart-pwa' ),
			'push_title'       => __( 'Notifications — titre de la fenêtre', 'africart-pwa' ),
			'push_prompt'      => __( 'Notifications — invitation', 'africart-pwa' ),
			'push_choose'      => __( 'Notifications — lien « choisir »', 'africart-pwa' ),
			'push_privacy'     => __( 'Notifications — lien confidentialité', 'africart-pwa' ),
			'push_unsub'       => __( 'Notifications — bouton de désabonnement', 'africart-pwa' ),
			'push_manage'      => __( 'Notifications — titre de la gestion', 'africart-pwa' ),
			'push_accept'      => __( 'Notifications — bouton d’abonnement', 'africart-pwa' ),
			'push_refuse'      => __( 'Notifications — bouton de refus', 'africart-pwa' ),
		);
	}

	/**
	 * Traductions par défaut embarquées (utilisées si l'admin n'a rien saisi).
	 *
	 * @param string $lang Code langue.
	 * @return array<string,string>
	 */
	public static function builtin( $lang ) {
		$translations = array(
			'en' => array(
				'shortcut_name'   => 'Artworks',
				'offline_title'   => 'You are offline',
				'offline_message' => 'This page cannot be loaded right now. Check your connection — pages you have already visited remain available.',
				'offline_button'  => 'Try again',
				'install_title'   => 'Install AFRICART',
				'install_message' => 'Add the app to your home screen: faster, and works offline.',
				'install_button'  => 'Install',
				'install_ios'     => 'Tap the Share button, then “Add to Home Screen”.',
				'install_inapp'   => 'Open this page in your browser to install the app.',
				'install_samsung' => 'Samsung may show a Play Protect warning: choose “Install anyway”. The app is simply the AFRICART website.',
				'push_title'      => 'Stay informed',
				'push_choose'     => 'Choose what you want to receive',
				'push_privacy'    => 'How we handle your data',
				'push_unsub'      => 'Unsubscribe',
				'push_manage'     => 'Your notifications',
				'push_prompt'     => 'Be the first to know about newly registered artworks.',
				'push_accept'     => 'Subscribe',
				'push_refuse'     => 'Not now',
			),
			'es' => array(
				'shortcut_name'   => 'Obras',
				'offline_title'   => 'Sin conexión',
				'offline_message' => 'No se puede cargar esta página en este momento. Comprueba tu conexión: las páginas ya visitadas siguen disponibles.',
				'offline_button'  => 'Reintentar',
				'install_title'   => 'Instalar AFRICART',
				'install_message' => 'Añade la aplicación a tu pantalla de inicio: más rápida y disponible sin conexión.',
				'install_button'  => 'Instalar',
				'install_ios'     => 'Pulsa el botón Compartir y luego «Añadir a pantalla de inicio».',
				'install_inapp'   => 'Abre esta página en tu navegador para instalar la aplicación.',
				'install_samsung' => 'Samsung puede mostrar una advertencia de Play Protect: elige «Instalar de todos modos». La aplicación es simplemente el sitio AFRICART.',
				'push_title'      => 'Mantente informado',
				'push_choose'     => 'Elige lo que quieres recibir',
				'push_privacy'    => 'Cómo tratamos tus datos',
				'push_unsub'      => 'Darse de baja',
				'push_manage'     => 'Tus notificaciones',
				'push_prompt'     => 'Entérate de las nuevas obras registradas.',
				'push_accept'     => 'Suscribirme',
				'push_refuse'     => 'Ahora no',
			),
		);
		return isset( $translations[ $lang ] ) ? $translations[ $lang ] : array();
	}

	/**
	 * Texte d'une clé dans une langue (langue active si omise).
	 *
	 * @param string      $key  Clé (voir translatable_keys).
	 * @param string|null $lang Code langue ou null pour la langue active.
	 * @return string
	 */
	public static function text( $key, $lang = null ) {
		$lang = $lang ? $lang : self::get_current_lang();

		// Langue par défaut : directement les réglages principaux.
		if ( self::default_lang() === $lang ) {
			return (string) Africart_PWA_Settings::get( $key );
		}

		// 1. Traduction saisie dans l'admin.
		$opts = get_option( self::OPTION, array() );
		if ( ! empty( $opts[ $lang ][ $key ] ) ) {
			return (string) $opts[ $lang ][ $key ];
		}

		// 2. Traduction embarquée.
		$builtin = self::builtin( $lang );
		if ( ! empty( $builtin[ $key ] ) ) {
			return $builtin[ $key ];
		}

		// 3. Repli français.
		return (string) Africart_PWA_Settings::get( $key );
	}

	/**
	 * Tous les textes de toutes les langues — embarqués dans la page
	 * hors-ligne pour permettre la bascule de langue sans réseau.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function all_texts() {
		$out = array();
		foreach ( self::get_languages() as $lang ) {
			foreach ( array_keys( self::translatable_keys() ) as $key ) {
				$out[ $lang ][ $key ] = self::text( $key, $lang );
			}
		}
		return $out;
	}
}
