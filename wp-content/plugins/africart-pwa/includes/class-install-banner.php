<?php
/**
 * Invitation à installer l'application.
 *
 * Déclenche la fenêtre d'installation NATIVE du navigateur.
 *
 * Rappel du fonctionnement : aucun site ne peut ouvrir spontanément la
 * boîte de dialogue d'installation. Le navigateur émet l'événement
 * `beforeinstallprompt` quand les critères sont réunis (HTTPS, manifest,
 * Service Worker) ; on le met de côté, on affiche notre propre CTA, et
 * `prompt()` — appelé depuis un vrai clic — ouvre la fenêtre native.
 *
 * Trois parcours selon le contexte :
 *   - Android/desktop : bouton → fenêtre d'installation native ;
 *   - iOS Safari : pas de `beforeinstallprompt`, on affiche les
 *     instructions « Partager → Sur l'écran d'accueil » ;
 *   - navigateur in-app (Instagram, Facebook…) : installation
 *     impossible, on invite à ouvrir dans le navigateur du téléphone.
 *
 * Fournit aussi le shortcode [africart_install] pour poser un bouton
 * d'installation dans une page.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Install_Banner {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hauteur maximale du visuel animé, en pixels.
	 *
	 * Exposée plutôt que calculée sur place, pour que l'aperçu de
	 * l'administration montre exactement ce que verra le visiteur : un
	 * aperçu qui diverge du rendu est pire que pas d'aperçu du tout.
	 *
	 * Une hauteur nulle ou absurde est ramenée à la valeur d'usine.
	 * D'anciennes installations portent un `0` en base, hérité d'une
	 * version où un champ vidé s'enregistrait ainsi : s'y fier
	 * afficherait un visuel invisible.
	 *
	 * @return int
	 */
	public static function gif_height() {
		$hauteur = (int) Africart_PWA_Settings::get( 'banner_gif_height' );

		if ( $hauteur >= 40 ) {
			return $hauteur;
		}

		$usine = (int) Africart_PWA_Factory_Defaults::get( 'banner_gif_height' );

		return $usine >= 40 ? $usine : 200;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_shortcode( 'africart_install', array( $this, 'shortcode' ) );
		add_shortcode( 'africart_installer', array( $this, 'shortcode_page' ) );
	}

	/**
	 * Le bandeau est-il actif ?
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return '1' === Africart_PWA_Settings::get( 'banner_enabled' )
			&& '1' === Africart_PWA_Settings::get( 'sw_enabled' );
	}

	/**
	 * Charge les assets et passe la configuration au JS.
	 */
	public function enqueue() {
		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'afk-pwa-install',
			AFK_PWA_URL . 'assets/css/install.css',
			array(),
			afk_pwa_asset_version( 'assets/css/install.css' )
		);
		wp_enqueue_script(
			'afk-pwa-install',
			AFK_PWA_URL . 'assets/js/install.js',
			// L'emetteur de mesure doit exister avant : install.js
			// l'appelle des l'affichage du bandeau.
			array( 'afk-pwa-mesure' ),
			afk_pwa_asset_version( 'assets/js/install.js' ),
			true
		);

		// wp_localize_script transformerait tout en chaînes : un 0 deviendrait
		// « "0" », qui est truthy en JavaScript. On injecte du JSON typé.
		wp_add_inline_script(
			'afk-pwa-install',
			'window.AFK_PWA_INSTALL = ' . wp_json_encode( $this->get_config() ) . ';',
			'before'
		);
	}

	/**
	 * Configuration transmise au JS (textes dans la langue de
	 * l'utilisateur, couleurs, comportement).
	 *
	 * @return array
	 */
	private function get_config() {
		$lang = Africart_PWA_I18n::get_current_lang();

		return array(
			'texts'       => array(
				'title'   => Africart_PWA_I18n::text( 'install_title', $lang ),
				'message' => Africart_PWA_I18n::text( 'install_message', $lang ),
				'button'  => Africart_PWA_I18n::text( 'install_button', $lang ),
				'ios'     => Africart_PWA_I18n::text( 'install_ios', $lang ),
				'samsung' => Africart_PWA_I18n::text( 'install_samsung', $lang ),
				'inapp'   => Africart_PWA_I18n::text( 'install_inapp', $lang ),
				'close'   => __( 'Fermer', 'africart-pwa' ),
			),
			'icon'        => Africart_PWA_Settings::get( 'icon_192' ),
			// Un visuel animé par plateforme : le geste à montrer diffère
			// (fenêtre native sur Android, menu Partager sur iOS).
			'gifs'        => array(
				'prompt' => Africart_PWA_Settings::get( 'install_gif_android' ),
				'ios'    => Africart_PWA_Settings::get( 'install_gif_ios' ),
				'inapp'  => Africart_PWA_Settings::get( 'install_gif_inapp' ),
			),
			'gifHeight'   => self::gif_height(),
			'accentColor' => Africart_PWA_Settings::get( 'accent_color' ),
			'themeColor'  => Africart_PWA_Settings::get( 'theme_color' ),
			// Délai avant affichage, pour ne pas gêner l'arrivée sur la page.
			'delay'       => (int) Africart_PWA_Settings::get( 'banner_delay' ),
			// Nombre de jours pendant lesquels on ne le remontre pas après un refus.
			'dismissDays' => (int) Africart_PWA_Settings::get( 'banner_dismiss_days' ),
			'position'    => Africart_PWA_Settings::get( 'banner_position' ),
			'showOnIos'   => '1' === Africart_PWA_Settings::get( 'banner_ios' ),
			'showInApp'   => '1' === Africart_PWA_Settings::get( 'banner_inapp' ),
		);
	}

	/**
	 * Shortcode [africart_install] — bouton d'installation dans une page.
	 *
	 * Attributs : label (texte du bouton), class (classes CSS en plus).
	 * Le bouton reste masqué tant que l'installation n'est pas possible
	 * (déjà installée, navigateur non compatible…).
	 *
	 * @param array $atts Attributs du shortcode.
	 * @return string
	 */
	public function shortcode( $atts ) {
		if ( ! $this->is_enabled() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'label'    => '',
				'class'    => '',
				// ⚠️ Deux attributs facultatifs, et le bouton ordinaire ne
				// change pas d'un octet sans eux : les pages qui posent
				// déjà ce code court gardent exactement leur bouton.
				'surtitre' => '',
				'icone'    => '',
			),
			$atts,
			'africart_install'
		);

		$label = $atts['label']
			? $atts['label']
			: Africart_PWA_I18n::text( 'install_button', Africart_PWA_I18n::get_current_lang() );

		// Un bouton simple, tant qu'on ne demande pas la forme d'un badge.
		if ( ! $atts['surtitre'] && ! $atts['icone'] ) {
			return sprintf(
				'<button type="button" class="afk-install-cta %s" hidden>%s</button>',
				esc_attr( $atts['class'] ),
				esc_html( $label )
			);
		}

		/*
		 * La forme d'un badge de magasin d'applications : une icône, une
		 * ligne de service en petit, le nom en grand.
		 *
		 * ⚠️ La forme, jamais les marques. Reprendre le badge de Google
		 * Play ou du Microsoft Store donnerait à croire que l'application
		 * s'y trouve — elle n'y est pas, et n'a pas à y être : elle
		 * s'installe depuis le navigateur. Ce sont l'icône d'AFRICART et
		 * ses mots à elle qui remplissent le cadre.
		 */
		ob_start();
		?>
		<button type="button" class="afk-install-cta afk-install-badge <?php echo esc_attr( $atts['class'] ); ?>" hidden>
			<?php if ( $atts['icone'] ) : ?>
				<img class="afk-install-badge-icone" src="<?php echo esc_url( $atts['icone'] ); ?>" alt="" />
			<?php endif; ?>
			<span class="afk-install-badge-mots">
				<?php if ( $atts['surtitre'] ) : ?>
					<span class="afk-install-badge-sur"><?php echo esc_html( $atts['surtitre'] ); ?></span>
				<?php endif; ?>
				<span class="afk-install-badge-nom"><?php echo esc_html( $label ); ?></span>
			</span>
		</button>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * L'adresse de la page qui affiche ce code court.
	 *
	 * ⚠️ Prise de la page, jamais écrite en dur.
	 *
	 * Une adresse figée dans le code aurait envoyé le testeur du serveur
	 * de recette vers le site en production — c'est-à-dire vers une autre
	 * application que celle qu'il essaie d'installer.
	 *
	 * @return string
	 */
	private static function adresse_de_la_page() {
		$id = get_queried_object_id();

		if ( $id && 'page' === get_post_type( $id ) ) {
			return (string) get_permalink( $id );
		}

		return (string) home_url( '/' );
	}

	/**
	 * Une page d'installation, à partager par lien.
	 *
	 * ⚠️ Le bandeau ne suffit pas pour un lien qu'on envoie.
	 *
	 * Il paraît de lui-même, au bon moment, sur le site — mais un lien
	 * partagé arrive sur des appareils où l'installation ne se propose
	 * jamais : sur iPhone aucun navigateur n'émet l'événement qui
	 * déclenche la fenêtre native, et dans le navigateur intégré
	 * d'Instagram ou de Facebook l'installation est tout simplement
	 * impossible. Le destinataire aurait ouvert une page où rien ne se
	 * passe, sans savoir si c'était lui, son téléphone, ou le lien.
	 *
	 * Cette page-ci montre donc le geste EXACT de la plateforme, et le
	 * bouton natif quand il existe.
	 *
	 * ⚠️ Le bouton n'est pas réécrit ici : `[africart_install]` le rend, et
	 * c'est lui que le module révèle au bon moment. En dessiner un second
	 * aurait fait deux boutons à tenir d'accord, dont un que personne
	 * n'aurait pensé à révéler.
	 *
	 * ⚠️ Sans JavaScript, TOUTES les marches à suivre restent visibles.
	 *
	 * Elles portent chacune leur intitulé — « Sur iPhone », « Sur
	 * Android » — et la page reste donc un mode d'emploi complet. Les
	 * masquer d'abord pour n'en révéler qu'une aurait donné une page vide
	 * à qui arrive avec un script bloqué, ce qui est le cas d'une partie
	 * des navigateurs intégrés : exactement le public de ce lien.
	 *
	 * @param array $atts Attributs du code court.
	 * @return string
	 */
	public function shortcode_page( $atts ) {
		if ( ! $this->is_enabled() ) {
			return '';
		}

		$a = shortcode_atts(
			array(
				'titre'   => '',
				'message' => '',
				'bouton'  => '',
				'visuels' => 'oui',
				'classe'  => '',
			),
			$atts,
			'africart_installer'
		);

		$lang    = Africart_PWA_I18n::get_current_lang();
		$titre   = $a['titre'] ? $a['titre'] : Africart_PWA_I18n::text( 'install_title', $lang );
		$message = $a['message'] ? $a['message'] : Africart_PWA_I18n::text( 'install_message', $lang );
		$visuels = 'non' !== $a['visuels'];
		$icone   = Africart_PWA_Settings::get( 'icon_192' );

		/*
		 * ⚠️ Ces textes sont ceux du client, mot pour mot.
		 *
		 * Ils sont arrives apres un test aupres de personnes qui ne sont
		 * pas de la technique, et chaque mot y a ete pese : « cliquez »
		 * plutot que « touchez », les boutons nommes par ce qu'on VOIT et
		 * decrits entre parentheses, et un « Et voila ! » qui dit qu'on a
		 * reussi. Ne pas les reecrire pour les rendre plus courts.
		 *
		 * Deux libertes prises, et une seule :
		 *
		 *   — l'encart Android arrivait coupe — « cliquez sur les (…) » —
		 *     et je l'ai termine par le geste reel. A relire ;
		 *   — l'adresse de la page vient de la page elle-meme et non d'une
		 *     chaine ecrite en dur : sur le serveur de recette, un lien
		 *     vers africart.art aurait envoye le testeur ailleurs.
		 */
		$marches = array(
			'ios'    => array(
				'titre'  => __( 'Depuis iPhone / iPad', 'africart-pwa' ),
				'etapes' => array(
					__( 'Tout en bas de l’écran, à droite de l’adresse du site, cliquez sur le bouton « … » (trois petits points).', 'africart-pwa' ),
					__( 'Dans le menu qui s’ouvre, cliquez sur « Partagez » (un carré avec une flèche qui pointe vers le haut).', 'africart-pwa' ),
					__( 'Faites défiler la liste vers le bas, puis cliquez sur « Sur l’écran d’accueil ».', 'africart-pwa' ),
					__( 'Cliquez sur « Ajouter » en haut à droite.', 'africart-pwa' ),
					__( 'Et voilà ! L’icône AFRICART apparaît sur votre écran d’accueil, comme n’importe quelle autre application.', 'africart-pwa' ),
				),
				'visuel' => Africart_PWA_Settings::get( 'install_gif_ios' ),
				// L'animation date d'une version d'iOS ou le carre avec la
				// fleche etait directement dans la barre du bas : elle
				// numerote « 1 » ce qu'on demande en deuxieme.
				'legende' => __( 'En images, à partir de l’étape 2', 'africart-pwa' ),
				'note'   => __( 'Si vous ne voyez pas le bouton « … » à côté de l’adresse du site, faites défiler cette page vers le haut, il devrait apparaître.', 'africart-pwa' ),
			),
			'ios-autre' => array(
				'titre'  => __( 'Depuis iPhone / iPad, avec Chrome ou un autre navigateur', 'africart-pwa' ),
				'etapes' => array(
					__( 'Sur iPhone et iPad, seul Safari sait installer une application. Ce n’est pas vous : les autres navigateurs n’en ont pas le droit.', 'africart-pwa' ),
					__( 'Cliquez sur le bouton de partage de votre navigateur, en haut ou en bas de l’écran selon celui que vous utilisez.', 'africart-pwa' ),
					__( 'Choisissez « Ouvrir dans Safari ».', 'africart-pwa' ),
					__( 'Cette même page se rouvre dans Safari, et vous donnera la marche à suivre.', 'africart-pwa' ),
				),
				'visuel' => '',
				'note'   => '',
			),
			'prompt' => array(
				'titre'  => __( 'Depuis Android', 'africart-pwa' ),
				'etapes' => array(
					__( 'Cliquez sur le bouton bleu ci-dessus « Installer Africart ».', 'africart-pwa' ),
					__( 'Une nouvelle fenêtre s’ouvre : cliquez sur le bouton « Installer » en haut à droite.', 'africart-pwa' ),
					__( 'Et voilà ! L’icône AFRICART apparaît sur votre écran d’accueil, comme n’importe quelle autre application.', 'africart-pwa' ),
				),
				'visuel' => Africart_PWA_Settings::get( 'install_gif_android' ),
				'note'   => __( 'Si le bouton bleu ne fait rien apparaître : cliquez sur les « ⋮ » (trois petits points) en haut à droite de votre navigateur, puis sur « Installer l’application ».', 'africart-pwa' ),
			),
			'attente' => array(
				'titre'  => __( 'Depuis Android', 'africart-pwa' ),
				'etapes' => array(
					__( 'Cliquez sur les « ⋮ » (trois petits points) en haut à droite de votre navigateur.', 'africart-pwa' ),
					__( 'Dans la liste, cliquez sur « Installer l’application ». Selon votre téléphone, ce peut être « Ajouter à l’écran d’accueil ».', 'africart-pwa' ),
					__( 'Confirmez avec « Installer ».', 'africart-pwa' ),
					__( 'Et voilà ! L’icône AFRICART apparaît sur votre écran d’accueil, comme n’importe quelle autre application.', 'africart-pwa' ),
				),
				'visuel' => Africart_PWA_Settings::get( 'install_gif_android' ),
				'note'   => __( 'Un bouton bleu « Installer Africart » peut apparaître ci-dessus d’un instant à l’autre : il fait la même chose, en plus court.', 'africart-pwa' ),
			),
			'inapp'  => array(
				'titre'  => __( 'Depuis Instagram, Facebook ou Messenger', 'africart-pwa' ),
				'etapes' => array(
					__( 'Vous avez ouvert ce lien depuis une application : elle affiche les pages dans sa propre fenêtre, qui ne sait pas installer.', 'africart-pwa' ),
					__( 'Cliquez sur les trois points, « ⋯ » ou « ⋮ », en haut à droite de cet écran.', 'africart-pwa' ),
					__( 'Choisissez « Ouvrir dans le navigateur », « Ouvrir dans Safari » ou « Ouvrir dans Chrome ».', 'africart-pwa' ),
					__( 'Cette même page se rouvre, et vous donnera la marche à suivre pour votre appareil.', 'africart-pwa' ),
				),
				'visuel' => Africart_PWA_Settings::get( 'install_gif_inapp' ),
				'note'   => '',
			),
			'bureau' => array(
				'titre'  => __( 'Depuis un ordinateur', 'africart-pwa' ),
				'etapes' => array(
					__( 'L’application AFRICART s’installe sur un téléphone ou une tablette.', 'africart-pwa' ),
					sprintf(
						/* translators: %s l'adresse de cette page */
						__( 'Envoyez-vous le lien %s par message ou par courriel.', 'africart-pwa' ),
						self::adresse_de_la_page()
					),
					__( 'Ouvrez ce lien depuis votre téléphone et/ou votre tablette, l’application vous donnera la marche à suivre, différente selon que vous ayez un support Apple ou Android.', 'africart-pwa' ),
				),
				'visuel' => '',
				'note'   => '',
			),
		);

		ob_start();
		?>
		<section class="afk-installer <?php echo esc_attr( $a['classe'] ); ?>" data-afk-installer>
			<div class="afk-installer-dedans">

				<header class="afk-installer-tete">
					<?php if ( $icone ) : ?>
						<img class="afk-installer-icone" src="<?php echo esc_url( $icone ); ?>" alt="" />
					<?php endif; ?>
					<div>
						<h2 class="afk-installer-titre"><?php echo esc_html( $titre ); ?></h2>
						<?php if ( $message ) : ?>
							<p class="afk-installer-message"><?php echo esc_html( $message ); ?></p>
						<?php endif; ?>
					</div>
				</header>

				<?php
				// Le bouton du module, pas une copie : il n'apparaît que là
				// où le navigateur sait vraiment ouvrir la fenêtre native.
				$nom = $a['bouton']
					? $a['bouton']
					: ( Africart_PWA_Settings::get( 'short_name' ) ?: get_bloginfo( 'name' ) );

				echo do_shortcode(
					'[africart_install class="afk-installer-bouton"'
						. ' label="' . esc_attr( $nom ) . '"'
						// La ligne de service, comme sur un badge de magasin :
						// le verbe en petit, le nom en grand.
						. ' surtitre="' . esc_attr( Africart_PWA_I18n::text( 'install_button', $lang ) ) . '"'
						. ( $icone ? ' icone="' . esc_attr( $icone ) . '"' : '' )
						. ']'
				);
				?>

				<div class="afk-installer-marches">
					<?php foreach ( $marches as $cle => $marche ) : ?>
						<article class="afk-installer-marche" data-afk-si="<?php echo esc_attr( $cle ); ?>">
							<div class="afk-installer-texte">
								<h3><?php echo esc_html( $marche['titre'] ); ?></h3>
								<ol class="afk-installer-etapes">
									<?php foreach ( $marche['etapes'] as $etape ) : ?>
										<li><?php echo esc_html( $etape ); ?></li>
									<?php endforeach; ?>
								</ol>
								<?php if ( $marche['note'] ) : ?>
									<p class="afk-installer-note"><?php echo esc_html( $marche['note'] ); ?></p>
								<?php endif; ?>
							</div>

							<?php if ( $visuels && $marche['visuel'] ) : ?>
								<figure class="afk-installer-visuel">
									<?php
									// ⚠️ Une vidéo là où c'en est une. Le réglage ne
									// porte qu'une adresse : la rendre en `<img>`
									// quoi qu'il arrive affichait un cadre vide pour
									// un fichier `.mp4`, sans rien dire.
									$est_video = (bool) preg_match( '/\.(mp4|webm|mov)(\?|$)/i', $marche['visuel'] );
									?>
									<?php if ( $est_video ) : ?>
										<video src="<?php echo esc_url( $marche['visuel'] ); ?>"
											autoplay muted loop playsinline preload="metadata"></video>
									<?php else : ?>
										<img src="<?php echo esc_url( $marche['visuel'] ); ?>" alt="" loading="lazy" />
									<?php endif; ?>
									<figcaption><?php
										echo esc_html( isset( $marche['legende'] ) && $marche['legende']
											? $marche['legende']
											: __( 'Le geste, en images', 'africart-pwa' ) );
									?></figcaption>
								</figure>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>

				<p class="afk-installer-faite" data-afk-si="installee">
					<?php esc_html_e( 'L’application est déjà installée sur cet appareil. Ouvrez-la depuis votre écran d’accueil.', 'africart-pwa' ); ?>
				</p>

				<ul class="afk-installer-atouts">
					<li><?php esc_html_e( 'Enregistrez vos œuvres, même hors connexion !', 'africart-pwa' ); ?></li>
					<li><?php esc_html_e( 'Vos brouillons sont sauvegardés sur votre appareil jusqu’à leur envoi.', 'africart-pwa' ); ?></li>
					<li><?php esc_html_e( 'Vous décidez du moment de l’envoi lorsque vous êtes connecté·e.', 'africart-pwa' ); ?></li>
				</ul>

			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}
}
