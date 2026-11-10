<?php
/**
 * L'en-tête de l'application.
 *
 * ⚠️ Pourquoi un en-tête à nous alors que le thème en a un.
 *
 * Celui du site est construit dans Elementor et repose sur cinq menus
 * dont un seul est réellement assigné à un emplacement. Sur téléphone,
 * le menu de profil n'apparaissait pas du tout : l'artiste connecté
 * n'avait aucun chemin vers ses œuvres ni vers la déconnexion — signalé
 * au test. Corriger cela dans le thème demanderait de toucher à sa mise
 * en page, ce que ce lot s'interdit.
 *
 * Celui-ci est un code court : il se pose où le client veut, il lit les
 * MÊMES menus que le site, et il ne remplace rien tant qu'on ne l'a pas
 * placé.
 *
 * ⚠️ Trois choses ne sont jamais reprises telles quelles des menus.
 *
 * L'adresse de déconnexion, d'abord : Voxel y grave un jeton au moment
 * où l'entrée de menu est enregistrée. Servi à un autre artiste — ou au
 * même une semaine plus tard — ce jeton est mort et la déconnexion
 * échoue en silence. Elle est donc recalculée à chaque affichage.
 *
 * Les entrées de connexion, ensuite : les afficher à quelqu'un qui est
 * déjà connecté n'a pas de sens, et les cacher à qui ne l'est pas non
 * plus.
 *
 * Et l'entrée « Vos œuvres », enfin, qui pointe vers la page du module :
 * c'est nous qui savons où elle se trouve.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Header {

	const SHORTCODE = 'africart_entete';

	/** @var Africart_Artwork_Header|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'rendu' ) );
	}

	/* ------------------------------------------------------------------
	 * Rendu
	 * ---------------------------------------------------------------- */

	/**
	 * @param array $attributs Attributs du code court.
	 * @return string
	 */
	public function rendu( $attributs = array() ) {
		$a = shortcode_atts(
			array(
				// Les menus, par identifiant ou par identifiant-texte.
				'menu'          => 'menu-header-bureau',
				'menu_mobile'   => 'menu-mobile',
				'profil'        => 'user-menu',
				'profil_mobile' => 'user-menu-mobile',
				// ⚠️ La marque, sur téléphone seulement.
				//
				// Trois valeurs : `mobile` — le défaut — l'affiche au
				// centre de la barre en dessous de la tablette et la retire
				// au-dessus ; `oui` la garde partout ; `non` la supprime.
				//
				// Ce choix vient de l'usage : sur grand écran, cet en-tête
				// se pose sous celui du site, qui porte déjà le logo — le
				// répéter donnait deux fois la même image à dix centimètres
				// d'écart. Sur téléphone, l'en-tête du site disparaît et il
				// ne reste plus rien pour dire où l'on est.
				'marque'        => 'mobile',
				// Le logo : une image, un identifiant d'attachement, ou
				// rien — auquel cas le nom du site fait office de marque.
				'logo'          => '',
				'hauteur_logo'  => '34',
				// ⚠️ Aucun raccourci de dépôt par défaut.
				//
				// Il chevauchait le menu dès que la barre se resserrait, et
				// il faisait double emploi : « Enregistrer une œuvre » est
				// déjà la deuxième entrée du menu du site. Un même geste à
				// deux endroits de la même barre n'aide personne.
				//
				// L'attribut reste : `action="/create-oeuvre/"` le remet,
				// avec son libellé.
				'action'        => '',
				'action_texte'  => '',
				// ⚠️ Vide par défaut : le client pose lui-même son choix de
				// langue là où il le veut sur la page.
				//
				// L'attribut reste, et il accepte n'importe quel code court
				// du site — `[google-translator]` pour celui qui était en
				// service. Le rendre ici plutôt que de le réécrire garde
				// une seule source : le jour où l'on change de traducteur,
				// on change le réglage, pas le code. Mais rien ne s'affiche
				// tant qu'on ne l'a pas demandé.
				'langues'       => '',
				// La cloche des notifications de Voxel.
				'notifications' => 'oui',
				// L'en-tête suit-il le défilement.
				'collant'       => 'oui',
			),
			(array) $attributs,
			self::SHORTCODE
		);

		self::enqueue();

		$connecte = is_user_logged_in();

		// ⚠️ Un identifiant par instance.
		//
		// Le client en a posé deux sur la même page — l'une masquée sur
		// ordinateur, l'autre sur téléphone. Les deux panneaux portaient
		// alors le même `id`, ce qui est invalide et rend `aria-controls`
		// menteur : une synthèse vocale annonçait le panneau de l'autre
		// en-tête. Constaté sur la page d'accueil.
		static $rang = 0;

		$rang++;
		$cle = 'afk-entete-panneau-' . $rang;

		ob_start();
		?>
		<header class="afk-entete<?php echo 'oui' === $a['collant'] ? ' afk-entete-collant' : ''; ?>"
			data-afk-entete>
			<div class="afk-entete-barre<?php echo 'non' === $a['marque'] ? ' afk-entete-sans-marque' : ''; ?>">

				<?php // ⚠️ Le bouton du menu vient EN PREMIER dans le code.
				// Sur téléphone, c'est le premier élément qu'atteint la
				// tabulation et le premier que lit une synthèse vocale :
				// le placer après le logo obligeait à traverser la marque
				// pour trouver la navigation. ?>
				<button type="button" class="afk-entete-burger" data-afk-burger
					aria-expanded="false" aria-controls="<?php echo esc_attr( $cle ); ?>"
					aria-label="<?php esc_attr_e( 'Ouvrir le menu', 'africart-pwa' ); ?>">
					<span class="afk-burger-trait" aria-hidden="true"></span>
					<span class="afk-burger-trait" aria-hidden="true"></span>
					<span class="afk-burger-trait" aria-hidden="true"></span>
				</button>

				<?php if ( 'non' !== $a['marque'] ) : ?>
					<a class="afk-entete-marque<?php echo 'mobile' === $a['marque'] ? ' afk-entete-marque-mobile' : ''; ?>"
						href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<?php echo self::marque( $a ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
				<?php endif; ?>

				<nav class="afk-entete-nav" aria-label="<?php esc_attr_e( 'Navigation du site', 'africart-pwa' ); ?>">
					<?php echo self::liste( self::entrees( $a['menu'] ), 'afk-entete-menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</nav>

				<div class="afk-entete-cote">
					<?php echo self::langues( $a ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo self::action( $a ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $connecte ? self::cloche( $a ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $connecte ? self::profil( $a ) : self::connexion(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>

			<?php // Le panneau du téléphone. Il porte le menu du site ET
			// celui du profil : c'est le seul endroit où les deux tiennent
			// ensemble sur trois cent cinquante pixels. ?>
			<div class="afk-entete-panneau" id="<?php echo esc_attr( $cle ); ?>" data-afk-panneau hidden>
				<nav aria-label="<?php esc_attr_e( 'Menu', 'africart-pwa' ); ?>">
					<?php echo self::liste( self::entrees( $a['menu_mobile'] ), 'afk-panneau-menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</nav>

				<?php if ( $connecte ) : ?>
					<div class="afk-panneau-compte">
						<p class="afk-panneau-titre"><?php echo esc_html( self::nom() ); ?></p>
						<?php echo self::liste( self::entrees( $a['profil_mobile'] ), 'afk-panneau-menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>

				<?php // ⚠️ Le choix de langue descend ici sur téléphone.
				// Quatre gestes dans une barre de trois cent quatre-vingt-dix
				// pixels ne tiennent pas ; celui-ci se cherche, il ne
				// s'impose pas. La cloche, elle, reste en haut : c'est une
				// nouvelle qui arrive, et la cacher revient à ne pas la
				// donner. ?>
				<?php echo self::langues( $a ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>

			<div class="afk-entete-voile" data-afk-voile hidden></div>
		</header>
		<?php

		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Morceaux
	 * ---------------------------------------------------------------- */

	/** La marque : une image si on en a une, le nom du site sinon. */
	private static function marque( $a ) {
		$source = trim( (string) $a['logo'] );

		if ( '' === $source && get_theme_mod( 'custom_logo' ) ) {
			$source = (string) wp_get_attachment_url( (int) get_theme_mod( 'custom_logo' ) );
		}

		if ( '' !== $source && ctype_digit( $source ) ) {
			$source = (string) wp_get_attachment_url( (int) $source );
		}

		if ( '' === $source ) {
			return '<span class="afk-entete-nom">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
		}

		return sprintf(
			'<img class="afk-entete-logo" src="%s" alt="%s" style="height:%dpx">',
			esc_url( $source ),
			esc_attr( get_bloginfo( 'name' ) ),
			max( 16, (int) $a['hauteur_logo'] )
		);
	}

	/**
	 * Un geste principal à droite du menu, si le client en veut un.
	 *
	 * ⚠️ Absent par défaut. Le raccourci de dépôt chevauchait le menu dès
	 * que la barre se resserrait, et il doublait « Enregistrer une œuvre »
	 * qui est déjà dans le menu du site.
	 */
	private static function action( $a ) {
		$url = trim( (string) $a['action'] );

		// ⚠️ Rien tant qu'on ne l'a pas demandé. Il retombait sur la page
		// de dépôt et s'affichait donc toujours.
		if ( '' === $url ) {
			return '';
		}

		$texte = trim( (string) $a['action_texte'] );

		return sprintf(
			'<a class="afk-entete-action" href="%s">%s</a>',
			esc_url( $url ),
			esc_html( '' !== $texte ? $texte : __( 'Déposer une œuvre', 'africart-pwa' ) )
		);
	}

	/**
	 * Le choix de langue, rendu par le code court du site.
	 *
	 * ⚠️ Ni réécrit, ni imité. Le drapeau anglais et le drapeau espagnol
	 * de l'ancien en-tête viennent du plugin de traduction ; refaire un
	 * sélecteur à nous aurait donné deux façons de changer de langue, qui
	 * auraient fini par ne plus dire la même chose.
	 */
	private static function langues( $a ) {
		$code = trim( (string) $a['langues'] );

		if ( '' === $code ) {
			return '';
		}

		$rendu = trim( (string) do_shortcode( $code ) );

		// Un code court absent se rend lui-même, en clair : mieux vaut
		// rien qu'un « [google-translator] » affiché dans l'en-tête.
		if ( '' === $rendu || $rendu === $code ) {
			return '';
		}

		return '<div class="afk-entete-langues">' . $rendu . '</div>';
	}

	/**
	 * La cloche des notifications.
	 *
	 * ⚠️ Elle affiche les notifications de Voxel, et c'est LUI qui les
	 * fournit — par ses propres points d'entrée `notifications.list`,
	 * `notifications.open` et `notifications.clear_all`. Reproduire sa
	 * logique nous aurait donné deux comptes d'un même courrier, qui
	 * divergent au premier écart.
	 *
	 * Ce qu'on refait, c'est la surface : la sienne est un composant Vue
	 * pilote par les reglages d'un widget Elementor, inatteignable depuis
	 * un code court sans embarquer la moitie du theme.
	 */
	private static function cloche( $a ) {
		if ( 'oui' !== $a['notifications'] || ! function_exists( 'Voxel\current_user' ) ) {
			return '';
		}

		$compte = 0;

		try {
			$etat   = \Voxel\current_user()->get_notification_count();
			$compte = absint( $etat['unread'] ?? 0 );
		} catch ( \Throwable $e ) {
			return '';
		}

		ob_start();
		?>
		<div class="afk-cloche" data-afk-cloche>
			<button type="button" class="afk-cloche-bouton" data-afk-cloche-bouton
				aria-expanded="false" aria-haspopup="true"
				aria-label="<?php esc_attr_e( 'Notifications', 'africart-pwa' ); ?>">
				<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">
					<path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M13.7 21a2 2 0 0 1-3.4 0"
						fill="none" stroke="currentColor" stroke-width="1.8"
						stroke-linecap="round" stroke-linejoin="round"></path>
				</svg>
				<?php if ( $compte ) : ?>
					<span class="afk-cloche-pastille" data-afk-cloche-pastille>
						<?php echo esc_html( $compte > 9 ? '9+' : (string) $compte ); ?>
					</span>
				<?php endif; ?>
			</button>

			<div class="afk-cloche-tiroir" data-afk-cloche-tiroir hidden>
				<div class="afk-cloche-entete">
					<span><?php esc_html_e( 'Notifications', 'africart-pwa' ); ?></span>
					<button type="button" class="afk-cloche-vider" data-afk-cloche-vider
						aria-label="<?php esc_attr_e( 'Tout effacer', 'africart-pwa' ); ?>"
						title="<?php esc_attr_e( 'Tout effacer', 'africart-pwa' ); ?>">
						<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false">
							<path d="M4 7h16M10 4h4M9 7v12M15 7v12M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"
								fill="none" stroke="currentColor" stroke-width="1.7"
								stroke-linecap="round" stroke-linejoin="round"></path>
						</svg>
					</button>
				</div>

				<div class="afk-cloche-liste" data-afk-cloche-liste
					data-afk-cloche-source="<?php echo esc_url( home_url( '/?vx=1&action=notifications.list&pg=1' ) ); ?>"
					data-afk-cloche-purge="<?php echo esc_url( home_url( '/?vx=1&action=notifications.clear_all' ) ); ?>">
					<p class="afk-cloche-attente"><?php esc_html_e( 'Chargement…', 'africart-pwa' ); ?></p>
				</div>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/** Le menu de profil, replié derrière l'avatar. */
	private static function profil( $a ) {
		$entrees = self::entrees( $a['profil'] );

		ob_start();
		?>
		<div class="afk-compte" data-afk-compte>
			<button type="button" class="afk-compte-bouton" data-afk-compte-bouton
				aria-expanded="false" aria-haspopup="true"
				aria-label="<?php echo esc_attr( sprintf( /* translators: %s: nom de l'artiste */ __( 'Votre compte — %s', 'africart-pwa' ), self::nom() ) ); ?>">
				<?php echo self::visage(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span class="afk-compte-nom"><?php echo esc_html( self::nom() ); ?></span>
				<span class="afk-compte-fleche" aria-hidden="true"></span>
			</button>

			<div class="afk-compte-tiroir" data-afk-compte-tiroir hidden>
				<p class="afk-compte-entete"><?php echo esc_html( self::nom() ); ?></p>
				<?php echo self::liste( $entrees, 'afk-compte-menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Le visage de l'artiste : sa photo, ou un dessin.
	 *
	 * ⚠️ L'avatar par défaut du site est une image — `mon-compte.png`,
	 * un bonhomme blanc sur pastille bleue. Posée dans un bouton rond
	 * déjà cerclé, elle donnait un rond bleu dans un rond blanc, et cette
	 * couleur pleine tirait l'œil plus que tout le reste de la barre
	 * alors qu'elle ne dit rien.
	 *
	 * On ne peut pas retirer le fond d'un PNG. On affiche donc la photo
	 * quand l'artiste en a réellement choisi une, et un trait simple
	 * sinon — qui prend la couleur de l'en-tête au lieu d'en imposer une.
	 */
	private static function visage() {
		// ⚠️ La méta, et non `get_avatar_id()`.
		//
		// Celui-ci retombe sur l'image par défaut du type de contenu
		// « profil » quand l'artiste n'en a pas choisi : il a rendu 1301
		// — `mon-compte.png` — pour un compte dont la méta est vide, et
		// le test « a-t-il un avatar » répondait donc toujours oui.
		// Constaté sur le compte #168.
		$sien = (int) get_user_meta( get_current_user_id(), 'voxel:avatar', true );

		if ( $sien && 'attachment' !== get_post_type( $sien ) ) {
			$sien = 0;
		}

		if ( $sien ) {
			$url = wp_get_attachment_image_url( $sien, 'thumbnail' );

			if ( $url ) {
				return sprintf( '<img class="afk-compte-avatar" src="%s" alt="">', esc_url( $url ) );
			}
		}

		return '<span class="afk-compte-visage" aria-hidden="true">'
			. '<svg viewBox="0 0 24 24" width="22" height="22" focusable="false">'
			. '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4.5 20a7.5 7.5 0 0 1 15 0"'
			. ' fill="none" stroke="currentColor" stroke-width="1.8"'
			. ' stroke-linecap="round" stroke-linejoin="round"></path></svg></span>';
	}

	/** Ce qu'on montre à qui n'est pas connecté. */
	private static function connexion() {
		$url = function_exists( 'Voxel\\get_auth_url' ) ? \Voxel\get_auth_url() : wp_login_url();

		return sprintf(
			'<a class="afk-entete-connexion" href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Se connecter', 'africart-pwa' )
		);
	}

	private static function nom() {
		$u = wp_get_current_user();

		return $u && $u->display_name ? $u->display_name : __( 'Mon compte', 'africart-pwa' );
	}

	/* ------------------------------------------------------------------
	 * Menus
	 * ---------------------------------------------------------------- */

	/**
	 * Les entrées d'un menu, en arbre à un niveau.
	 *
	 * ⚠️ Lues et reconstruites plutôt que rendues par `wp_nav_menu()`.
	 * Celui-ci produit le balisage du thème, avec ses classes et ses
	 * scripts : le reprendre aurait ramené la mise en page qu'on cherche
	 * précisément à remplacer.
	 *
	 * @param string $quel Identifiant, identifiant-texte ou nom du menu.
	 * @return array
	 */
	private static function entrees( $quel ) {
		$quel = trim( (string) $quel );

		if ( '' === $quel ) {
			return array();
		}

		$menu = wp_get_nav_menu_object( ctype_digit( $quel ) ? (int) $quel : $quel );

		if ( ! $menu ) {
			return array();
		}

		$brut = wp_get_nav_menu_items( $menu->term_id );

		if ( ! $brut ) {
			return array();
		}

		$racines = array();
		$enfants = array();

		foreach ( $brut as $item ) {
			$url = self::adresse( $item );

			if ( '' === $url ) {
				continue;
			}

			$entree = array(
				'titre'   => (string) $item->title,
				'url'     => $url,
				'id'      => (int) $item->ID,
				'objet'   => (int) $item->object_id,
				'type'    => (string) $item->object,
				'enfants' => array(),
			);

			if ( (int) $item->menu_item_parent ) {
				$enfants[ (int) $item->menu_item_parent ][] = $entree;
			} else {
				$racines[ (int) $item->ID ] = $entree;
			}
		}

		foreach ( $enfants as $parent => $lot ) {
			if ( isset( $racines[ $parent ] ) ) {
				$racines[ $parent ]['enfants'] = $lot;
			}
		}

		return array_values( $racines );
	}

	/**
	 * L'adresse d'une entrée, corrigée quand elle ne peut pas être juste.
	 *
	 * @return string Vide si l'entrée n'a pas lieu d'être affichée.
	 */
	private static function adresse( $item ) {
		$url = (string) $item->url;

		// ⚠️ Le jeton de déconnexion est gravé dans l'entrée de menu au
		// moment où on l'enregistre. Il vaut pour l'artiste qui l'a
		// enregistrée, ce jour-là. Servi tel quel, il échoue sans un mot.
		if ( false !== strpos( $url, 'action=auth.logout' ) ) {
			if ( ! is_user_logged_in() ) {
				return '';
			}

			return function_exists( 'Voxel\\get_logout_url' ) ? \Voxel\get_logout_url() : wp_logout_url( home_url( '/' ) );
		}

		// Une invitation à se connecter n'a rien à dire à qui l'est déjà.
		if ( is_user_logged_in() && self::mene_a_la_connexion( $url ) ) {
			return '';
		}

		// « Vos œuvres » mène à la page du module : c'est nous qui savons
		// où elle se trouve, et le menu peut pointer ailleurs.
		if ( class_exists( 'Africart_Artwork_Cache' ) ) {
			$liste = (string) Africart_Artwork_Cache::page_liste();

			if ( '' !== $liste && self::meme_page( $url, $liste ) ) {
				return $liste;
			}
		}

		return $url;
	}

	private static function mene_a_la_connexion( $url ) {
		if ( ! function_exists( 'Voxel\\get_auth_url' ) ) {
			return false;
		}

		return self::meme_page( $url, (string) \Voxel\get_auth_url() );
	}

	/** Deux adresses désignent-elles la même page ? */
	private static function meme_page( $une, $autre ) {
		$chemin = static function ( $u ) {
			$p = (string) wp_parse_url( (string) $u, PHP_URL_PATH );

			return '' === $p ? '' : rtrim( $p, '/' );
		};

		$a = $chemin( $une );

		return '' !== $a && $a === $chemin( $autre );
	}

	/**
	 * Une liste d'entrées.
	 *
	 * @param array  $entrees Entrées.
	 * @param string $classe  Classe de la liste.
	 * @return string
	 */
	private static function liste( $entrees, $classe ) {
		if ( ! $entrees ) {
			return '';
		}

		$sortie = '<ul class="' . esc_attr( $classe ) . '">';

		foreach ( $entrees as $e ) {
			$ici = self::est_la_page_courante( $e );

			$sortie .= '<li class="afk-entree' . ( $e['enfants'] ? ' afk-entree-parent' : '' ) . '">';

			if ( $e['enfants'] ) {
				// ⚠️ Un sous-menu s'ouvre au clic, jamais au seul survol.
				// Sur un écran tactile il n'y a pas de survol : les trois
				// pages « À propos » étaient inatteignables au doigt.
				$sortie .= sprintf(
					'<button type="button" class="afk-entree-lien afk-entree-ouvre" aria-expanded="false">%s<span class="afk-entree-chevron" aria-hidden="true"></span></button>',
					esc_html( $e['titre'] )
				);

				$sortie .= '<ul class="afk-sous-menu">';

				foreach ( $e['enfants'] as $enfant ) {
					$sortie .= sprintf(
						'<li><a class="afk-entree-lien"%s href="%s">%s</a></li>',
						self::est_la_page_courante( $enfant ) ? ' aria-current="page"' : '',
						esc_url( $enfant['url'] ),
						esc_html( $enfant['titre'] )
					);
				}

				$sortie .= '</ul>';
			} else {
				$sortie .= sprintf(
					'<a class="afk-entree-lien"%s href="%s">%s</a>',
					$ici ? ' aria-current="page"' : '',
					esc_url( $e['url'] ),
					esc_html( $e['titre'] )
				);
			}

			$sortie .= '</li>';
		}

		return $sortie . '</ul>';
	}

	/** Cette entrée désigne-t-elle la page affichée ? */
	private static function est_la_page_courante( $entree ) {
		if ( is_singular() && (int) $entree['objet'] === (int) get_queried_object_id() ) {
			return true;
		}

		return self::meme_page( $entree['url'], home_url( add_query_arg( array() ) ) );
	}

	/* ------------------------------------------------------------------
	 * Ressources
	 * ---------------------------------------------------------------- */

	public static function enqueue() {
		if ( wp_script_is( 'afk-pwa-entete', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'afk-pwa-oeuvres',
			AFK_PWA_URL . 'assets/css/oeuvres.css',
			array(),
			afk_pwa_asset_version( 'assets/css/oeuvres.css' )
		);

		wp_enqueue_script(
			'afk-pwa-entete',
			AFK_PWA_URL . 'assets/js/entete.js',
			array(),
			afk_pwa_asset_version( 'assets/js/entete.js' ),
			true
		);
	}
}
