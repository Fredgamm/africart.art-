<?php
/**
 * Fonds CSS déclarés en feuille de style.
 *
 * Certaines images de fond ne figurent pas dans le HTML mais dans les
 * feuilles de style produites par Elementor (`uploads/elementor/css/`).
 * Le réécrivain HTML ne peut donc pas les atteindre — c'est le cas de
 * l'image du bandeau d'accueil, qui commande pourtant l'affichage
 * principal de la page.
 *
 * Plutôt que de réécrire les fichiers d'Elementor — qu'il régénère à
 * chaque modification, et qu'écraserait toute mise à jour — on publie
 * une règle de plus haute priorité visant un identifiant posé sur le
 * conteneur. Un sélecteur d'identifiant l'emporte sur les sélecteurs de
 * classe d'Elementor : sa déclaration gagne sans qu'aucun de ses
 * fichiers ne soit modifié.
 *
 * Chaque entrée associe un sélecteur à une image. Le plugin produit :
 *   - une déclaration `image-set()` de priorité supérieure ;
 *   - un préchargement de la variante la plus efficace, qui fait
 *     démarrer le téléchargement sans attendre l'analyse du CSS.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_CSS_Backgrounds {

	/** Réglage portant la liste des fonds déclarés. */
	const OPTION_KEY = 'perf_css_backgrounds';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priorité 4 : avant les styles du thème, pour que le
		// préchargement parte au plus tôt.
		add_action( 'wp_head', array( $this, 'print_preloads' ), 4 );
		// Priorité 99 : après les feuilles d'Elementor, pour gagner la
		// cascade même à sélecteur de spécificité égale.
		add_action( 'wp_head', array( $this, 'print_overrides' ), 99 );
	}

	/* ------------------------------------------------------------------
	 * Données
	 * ---------------------------------------------------------------- */

	/**
	 * Entrées configurées, nettoyées des lignes vides.
	 *
	 * @return array<int,array{selector:string,image:string}>
	 */
	public static function get_entries() {
		$raw = Africart_PWA_Settings::get( self::OPTION_KEY );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$entries = array();
		foreach ( $raw as $row ) {
			if ( empty( $row['selector'] ) || empty( $row['image'] ) ) {
				continue;
			}
			$entries[] = array(
				'selector' => (string) $row['selector'],
				'image'    => (string) $row['image'],
			);
		}
		return $entries;
	}

	/**
	 * Entrées effectivement exploitables : celles dont l'image possède
	 * au moins une variante sur le disque.
	 *
	 * Une entrée sans variante n'est pas publiée : déclarer une image
	 * absente ferait disparaître le fond (comportement vérifié en test).
	 *
	 * @return array<int,array{selector:string,image:string,variants:array}>
	 */
	private function get_usable_entries() {
		if ( ! Africart_PWA_Settings::is_rewrite_enabled() ) {
			return array();
		}

		$usable = array();
		foreach ( self::get_entries() as $entry ) {
			$variants = Africart_PWA_Image_Variants::existing_for_url( $entry['image'] );
			if ( ! $variants ) {
				continue;
			}
			$entry['variants'] = $variants;

			// Déclinaison pour petits écrans : mesuré sur le bandeau, le
			// téléchargement de l'image pleine taille coûte 1,4 s de LCP
			// mobile alors qu'elle s'affiche en ~390 px de large.
			$entry['mobile']          = self::mobile_version( $entry['image'] );
			$entry['mobile_variants'] = $entry['mobile']
				? Africart_PWA_Image_Variants::existing_for_url( $entry['mobile'] )
				: array();

			$usable[] = $entry;
		}
		return $usable;
	}

	/**
	 * Déclinaison « medium_large » (largeur 768) d'une image, si
	 * WordPress l'a produite.
	 *
	 * On cherche le fichier frère `nom-768x*.ext` : c'est la convention
	 * de nommage des tailles intermédiaires, et la seule information
	 * fiable — les métadonnées du média peuvent manquer, le fichier
	 * sur le disque ne ment pas.
	 *
	 * @param string $url URL de l'image d'origine.
	 * @return string URL de la déclinaison, ou '' si elle n'existe pas.
	 */
	public static function mobile_version( $url ) {
		$chemin = Africart_PWA_Image_Variants::url_to_path( $url );
		if ( ! $chemin || ! file_exists( $chemin ) ) {
			return '';
		}

		$ext  = pathinfo( $chemin, PATHINFO_EXTENSION );
		$base = substr( $chemin, 0, -1 * ( strlen( $ext ) + 1 ) );

		$freres = glob( $base . '-768x*.' . $ext );
		if ( ! $freres ) {
			return '';
		}

		// Même dossier, donc même préfixe d'URL : seule la fin change.
		return substr( $url, 0, strrpos( $url, '/' ) + 1 ) . rawurlencode( basename( $freres[0] ) );
	}

	/* ------------------------------------------------------------------
	 * Sortie
	 * ---------------------------------------------------------------- */

	/**
	 * Précharge la variante la plus efficace de chaque fond déclaré.
	 *
	 * Le type est annoncé : un navigateur qui ne sait pas lire le format
	 * ignore simplement la consigne, sans téléchargement inutile.
	 */
	public function print_preloads() {
		if ( ! is_front_page() && ! is_home() ) {
			// Le préchargement n'a de sens que là où l'image est
			// effectivement affichée dès l'ouverture.
			return;
		}

		foreach ( $this->get_usable_entries() as $entry ) {
			$best = $entry['variants'][0];

			// Sans déclinaison mobile : un seul préchargement, pour tous.
			if ( ! $entry['mobile_variants'] ) {
				printf(
					'<link rel="preload" as="image" href="%s" type="%s" fetchpriority="high">' . "\n",
					esc_url( $best['url'] ),
					esc_attr( $best['mime'] )
				);
				continue;
			}

			// Avec déclinaison : chaque gabarit précharge la sienne — sans
			// l'attribut media, un téléphone téléchargerait les deux.
			$petit = $entry['mobile_variants'][0];
			printf(
				'<link rel="preload" as="image" href="%s" type="%s" media="(max-width:767px)" fetchpriority="high">' . "\n",
				esc_url( $petit['url'] ),
				esc_attr( $petit['mime'] )
			);
			printf(
				'<link rel="preload" as="image" href="%s" type="%s" media="(min-width:768px)" fetchpriority="high">' . "\n",
				esc_url( $best['url'] ),
				esc_attr( $best['mime'] )
			);
		}
	}

	/**
	 * Publie les déclarations de fond de priorité supérieure.
	 */
	public function print_overrides() {
		$entries = $this->get_usable_entries();
		if ( ! $entries ) {
			return;
		}

		$rules = array();

		foreach ( $entries as $entry ) {
			$set = $this->build_image_set( $entry['image'], $entry['variants'] );
			if ( '' === $set ) {
				continue;
			}
			// La première déclaration reste le repli des navigateurs qui
			// ne comprennent pas image-set().
			$rules[] = sprintf(
				'%s{background-image:url("%s");%s}',
				$entry['selector'],
				esc_url_raw( $entry['image'] ),
				$set
			);

			// Petits écrans : la déclinaison 768 remplace la pleine taille.
			// Publiée APRÈS la règle de base, elle gagne à spécificité
			// égale dès que le media s'applique.
			if ( $entry['mobile_variants'] ) {
				$set_mobile = $this->build_image_set( $entry['mobile'], $entry['mobile_variants'] );
				if ( '' !== $set_mobile ) {
					$rules[] = sprintf(
						'@media (max-width:767px){%s{background-image:url("%s");%s}}',
						$entry['selector'],
						esc_url_raw( $entry['mobile'] ),
						$set_mobile
					);
				}
			}
		}

		if ( ! $rules ) {
			return;
		}

		printf(
			"<style id=\"afk-pwa-backgrounds\">%s</style>\n",
			implode( '', $rules )
		);
	}

	/**
	 * Construit la déclaration `image-set()`.
	 *
	 * L'ordre EST la priorité : le navigateur retient la première entrée
	 * dont il sait lire le type.
	 *
	 * @param string $original URL de l'image d'origine.
	 * @param array  $variants Variantes existantes, les plus efficaces d'abord.
	 * @return string
	 */
	private function build_image_set( $original, $variants ) {
		$entries = array();

		foreach ( $variants as $variant ) {
			if ( empty( $variant['url'] ) ) {
				continue;
			}
			$entries[] = sprintf( 'url("%s") type("%s")', esc_url_raw( $variant['url'] ), $variant['mime'] );
		}

		if ( ! $entries ) {
			return '';
		}

		$ext       = strtolower( pathinfo( wp_parse_url( $original, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$mime      = 'png' === $ext ? 'image/png' : 'image/jpeg';
		$entries[] = sprintf( 'url("%s") type("%s")', esc_url_raw( $original ), $mime );

		return sprintf( 'background-image:image-set(%s);', implode( ',', $entries ) );
	}

	/* ------------------------------------------------------------------
	 * Nettoyage des réglages
	 * ---------------------------------------------------------------- */

	/**
	 * Nettoie la liste soumise depuis l'écran de réglages.
	 *
	 * @param mixed $input Valeur brute du formulaire.
	 * @return array
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$clean = array();
		foreach ( $input as $row ) {
			// Écarte le témoin de présence du formulaire, qui n'est pas une
			// ligne mais une simple valeur. Sans ce témoin, retirer la
			// dernière ligne n'enverrait rien et l'ancienne liste
			// subsisterait : impossible de tout supprimer.
			if ( ! is_array( $row ) ) {
				continue;
			}

			$selector = isset( $row['selector'] ) ? trim( sanitize_text_field( $row['selector'] ) ) : '';
			$image    = isset( $row['image'] ) ? esc_url_raw( trim( $row['image'] ) ) : '';

			if ( '' === $selector || '' === $image ) {
				continue;
			}

			// Un sélecteur ne contient ni accolade, ni point-virgule, ni
			// chevron ouvrant : on n'ouvre la porte ni à l'injection de
			// règles ni à une sortie du bloc <style>. Le chevron fermant
			// reste permis — c'est le combinateur d'enfant, nécessaire
			// pour viser les couches motion-effects d'Elementor.
			if ( preg_match( '/[{};<]/', $selector ) ) {
				continue;
			}

			$clean[] = array(
				'selector' => $selector,
				'image'    => $image,
			);
		}

		return $clean;
	}
}
