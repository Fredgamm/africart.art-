<?php
/**
 * Chargement des valeurs d'usine.
 *
 * Toutes les valeurs par défaut du plugin vivent dans
 * `defaults/factory-settings.json`. **Aucune n'est écrite dans le code** :
 * cette classe est le seul chemin qui y mène.
 *
 * Ce choix répond à trois problèmes constatés :
 *
 *   1. Les valeurs par défaut étaient dispersées dans une méthode de
 *      500 lignes, illisible pour qui ne pratique pas le PHP.
 *   2. Certaines dépendaient du site — le nom de l'application valait
 *      « nom du site », ce qui affichait *Runabac* sur la
 *      préproduction, dont la base a été copiée depuis un autre projet.
 *   3. Sans référence explicite, aucune réinitialisation n'était
 *      possible.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Factory_Defaults {

	/** Fichier des valeurs d'usine, relatif au dossier du plugin. */
	const FILE = 'defaults/factory-settings.json';

	/**
	 * Valeurs déjà chargées.
	 *
	 * `get()` est appelé des dizaines de fois par page : sans ce cache,
	 * chaque appel relirait et redécoderait le fichier.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/** Message d'erreur du dernier chargement, s'il a échoué. */
	private static $erreur = '';

	/* ------------------------------------------------------------------
	 * Lecture
	 * ---------------------------------------------------------------- */

	/**
	 * Toutes les valeurs d'usine, aplaties et jetons résolus.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		self::$cache  = array();
		self::$erreur = '';

		$chemin = AFK_PWA_DIR . self::FILE;

		if ( ! is_readable( $chemin ) ) {
			self::fail( sprintf( 'fichier introuvable ou illisible (%s)', self::FILE ) );
			return self::$cache;
		}

		$brut = file_get_contents( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( $brut, true );

		if ( ! is_array( $data ) ) {
			self::fail( sprintf( 'JSON invalide — %s', json_last_error_msg() ) );
			return self::$cache;
		}

		self::$cache = self::resolve( self::flatten( $data ) );

		return self::$cache;
	}

	/**
	 * Valeur d'usine d'un réglage.
	 *
	 * @param string $cle      Clé du réglage.
	 * @param mixed  $fallback Valeur si la clé est absente du fichier.
	 * @return mixed
	 */
	public static function get( $cle, $fallback = null ) {
		$tout = self::all();
		return array_key_exists( $cle, $tout ) ? $tout[ $cle ] : $fallback;
	}

	/**
	 * Le fichier a-t-il été chargé sans encombre ?
	 *
	 * @return bool
	 */
	public static function is_valid() {
		self::all();
		return '' === self::$erreur;
	}

	/**
	 * Message d'erreur du dernier chargement.
	 *
	 * @return string
	 */
	public static function error() {
		self::all();
		return self::$erreur;
	}

	/**
	 * Vide le cache. Utile après une modification du fichier.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/* ------------------------------------------------------------------
	 * Transformation
	 * ---------------------------------------------------------------- */

	/**
	 * Aplatit les groupes du fichier en une seule liste de clés.
	 *
	 * Le regroupement par écran ne sert que la lisibilité du fichier :
	 * déplacer une clé d'un groupe à l'autre est sans conséquence.
	 *
	 * @param array $data Contenu décodé du fichier.
	 * @return array
	 */
	private static function flatten( $data ) {
		$plat = array();

		foreach ( $data as $nom => $groupe ) {
			// Les entrées commençant par « _ » sont des annotations.
			if ( '_' === substr( (string) $nom, 0, 1 ) ) {
				continue;
			}
			if ( ! is_array( $groupe ) ) {
				continue;
			}

			foreach ( $groupe as $cle => $valeur ) {
				if ( '_' === substr( (string) $cle, 0, 1 ) ) {
					continue;
				}
				$plat[ $cle ] = $valeur;
			}
		}

		return $plat;
	}

	/**
	 * Remplace les jetons par les valeurs propres à l'installation.
	 *
	 * Un jeton inconnu est laissé tel quel : l'anomalie se voit alors à
	 * l'écran, au lieu de produire silencieusement une adresse tronquée.
	 *
	 * @param mixed $valeur Valeur, chaîne ou structure imbriquée.
	 * @return mixed
	 */
	private static function resolve( $valeur ) {
		if ( is_array( $valeur ) ) {
			return array_map( array( __CLASS__, 'resolve' ), $valeur );
		}

		if ( ! is_string( $valeur ) || false === strpos( $valeur, '{{' ) ) {
			return $valeur;
		}

		return strtr( $valeur, self::tokens() );
	}

	/**
	 * Jetons disponibles et leur valeur pour cette installation.
	 *
	 * @return array<string,string>
	 */
	private static function tokens() {
		static $jetons = null;

		if ( null !== $jetons ) {
			return $jetons;
		}

		$jetons = array(
			'{{plugin_url}}'      => AFK_PWA_URL,
			'{{home_url}}'        => trailingslashit( home_url( '/' ) ),
			'{{site_name}}'       => (string) get_bloginfo( 'name' ),
			'{{site_description}}' => (string) get_bloginfo( 'description' ),
			'{{site_lang}}'       => class_exists( 'Africart_PWA_I18n' ) ? Africart_PWA_I18n::default_lang() : 'fr',
			'{{site_languages}}'  => class_exists( 'Africart_PWA_I18n' ) ? Africart_PWA_I18n::detect_site_languages_csv() : 'fr, en, es',
		);

		return $jetons;
	}

	/* ------------------------------------------------------------------
	 * Signalement
	 * ---------------------------------------------------------------- */

	/**
	 * Retient et journalise une erreur de chargement.
	 *
	 * Un fichier illisible priverait le plugin de toute valeur par
	 * défaut : l'incident doit se voir, pas se deviner.
	 *
	 * @param string $message Description de l'anomalie.
	 */
	private static function fail( $message ) {
		self::$erreur = $message;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AFRICART PWA — valeurs d’usine : ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Avertit dans l'administration.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s<br><code>%s</code></p></div>',
			esc_html__( 'AFRICART PWA :', 'africart-pwa' ),
			esc_html__( 'les valeurs d’usine n’ont pas pu être chargées. Les réglages laissés vides resteront sans valeur.', 'africart-pwa' ),
			esc_html( self::FILE . ' — ' . self::$erreur )
		);
	}
}
