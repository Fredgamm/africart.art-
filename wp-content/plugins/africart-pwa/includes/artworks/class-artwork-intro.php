<?php
/**
 * La page qui mène au dépôt.
 *
 * ⚠️ Cet écran n'est pas décoratif : c'est celui que le menu du site
 * atteint, et le seul endroit où l'on explique quoi faire quand le
 * dépôt en ligne ne passe pas. Sa version d'origine était un bloc de
 * texte où le bouton principal était un mot souligné au milieu d'une
 * phrase — « ACCESSIBLE ICI ! » — et la méthode de secours une liste de
 * quatre lignes serrées, sans hiérarchie.
 *
 * ⚠️ Rien n'est deviné du site : l'adresse du formulaire est celle que
 * le module a déjà détectée, la fiche et le courriel sont passés en
 * attributs. Coder en dur une adresse ici la ferait survivre à la
 * refonte de la page, et personne ne saurait où la corriger.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Intro {

	const SHORTCODE = 'africart_depot_intro';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * La feuille de style, sur les pages qui portent le bloc.
	 *
	 * ⚠️ Enfilée aussi depuis le code court lui-même : le crochet ne voit
	 * pas un bloc posé dans un modèle Elementor ou un widget de barre
	 * latérale, et le bloc sortirait alors sans ses styles.
	 */
	public function enqueue() {
		if ( is_admin() || ! $this->page_porte_le_bloc() ) {
			return;
		}

		$this->charger();
	}

	private function charger() {
		wp_enqueue_style(
			'afk-pwa-oeuvres',
			AFK_PWA_URL . 'assets/css/oeuvres.css',
			array(),
			afk_pwa_asset_version( 'assets/css/oeuvres.css' )
		);
	}

	private function page_porte_le_bloc() {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post( get_the_ID() );

		if ( ! $post ) {
			return false;
		}

		if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			return true;
		}

		$structure = get_post_meta( $post->ID, '_elementor_data', true );

		return is_string( $structure ) && false !== strpos( $structure, self::SHORTCODE );
	}

	/**
	 * Rend le bloc.
	 *
	 * @param array $atts Attributs du code court.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$this->charger();

		$a = shortcode_atts(
			array(
				// Vide par défaut : la page porte déjà son titre, rendu par
				// le thème. En poser un second ferait doublon.
				'titre'      => '',
				// Détectée, pas devinée : c'est la page qui porte réellement
				// le formulaire de dépôt.
				'formulaire' => class_exists( 'Africart_Artwork_Cache' ) ? Africart_Artwork_Cache::page_formulaire() : '',
				'bouton'     => __( 'Enregistrer mon œuvre', 'africart-pwa' ),
				'fiche'      => '',
				'courriel'   => (string) get_option( 'admin_email' ),
			),
			$atts,
			self::SHORTCODE
		);

		$etapes = array(
			array(
				'texte' => __( 'Imprimer la fiche et la remplir en majuscules.', 'africart-pwa' ),
				'lien'  => $a['fiche'],
				'libelle_lien' => __( 'Télécharger la fiche', 'africart-pwa' ),
			),
			array(
				'texte' => __( 'Scanner ou photographier lisiblement la fiche, une fois remplie et signée.', 'africart-pwa' ),
			),
			array(
				'texte' => __( 'Prendre au moins une bonne photo de l’œuvre.', 'africart-pwa' ),
			),
			array(
				'texte' => __( 'Nous envoyer les photos de la fiche et de l’œuvre par courriel.', 'africart-pwa' ),
				'lien'  => $a['courriel'] ? 'mailto:' . $a['courriel'] : '',
				'libelle_lien' => $a['courriel'],
			),
		);

		ob_start();
		?>
		<div class="afk-depot">

			<?php if ( $a['titre'] ) : ?>
				<h2 class="afk-depot-titre"><?php echo esc_html( $a['titre'] ); ?></h2>
			<?php endif; ?>

			<div class="afk-depot-voie">
				<p class="afk-depot-chapeau">
					<?php esc_html_e( 'Le plus simple est de déposer votre œuvre directement en ligne. Le formulaire vous guide étape par étape, et conserve votre saisie même si le réseau vient à manquer.', 'africart-pwa' ); ?>
				</p>

				<?php if ( $a['formulaire'] ) : ?>
					<a class="afk-depot-action" href="<?php echo esc_url( $a['formulaire'] ); ?>">
						<?php echo esc_html( $a['bouton'] ); ?>
						<span class="afk-depot-fleche" aria-hidden="true">&rarr;</span>
					</a>
				<?php endif; ?>

				<ul class="afk-depot-atouts">
					<li><?php esc_html_e( 'Fonctionne sans connexion', 'africart-pwa' ); ?></li>
					<li><?php esc_html_e( 'Saisie conservée sur votre appareil', 'africart-pwa' ); ?></li>
					<li><?php esc_html_e( 'Photos envoyées au retour du réseau', 'africart-pwa' ); ?></li>
				</ul>
			</div>

			<div class="afk-depot-secours">
				<p class="afk-depot-secours-titre">
					<?php esc_html_e( 'Vous préférez le papier, ou le formulaire ne s’ouvre pas ?', 'africart-pwa' ); ?>
				</p>

				<ol class="afk-depot-etapes">
					<?php foreach ( $etapes as $rang => $etape ) : ?>
						<li>
							<span class="afk-depot-rang" aria-hidden="true"><?php echo (int) ( $rang + 1 ); ?></span>
							<span class="afk-depot-texte">
								<?php echo esc_html( $etape['texte'] ); ?>
								<?php if ( ! empty( $etape['lien'] ) ) : ?>
									<a class="afk-depot-lien" href="<?php echo esc_url( $etape['lien'] ); ?>"><?php
										echo esc_html( $etape['libelle_lien'] );
									?></a>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>

		</div>
		<?php

		return (string) ob_get_clean();
	}
}
