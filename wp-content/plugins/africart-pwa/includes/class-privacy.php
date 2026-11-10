<?php
/**
 * Conformité au règlement général sur la protection des données.
 *
 * Ce que le plugin met en jeu, et à quel titre :
 *
 *   - **Notifications push.** S'abonner crée un identifiant d'appareil
 *     chez OneSignal. C'est une donnée à caractère personnel, et son
 *     traitement repose sur le **consentement** — d'où une fenêtre qui
 *     explique avant de demander, jamais d'invite automatique, et un
 *     moyen de se désabonner aussi simple que de s'abonner.
 *   - **Centres d'intérêt.** Étiquettes posées sur l'abonnement, sur
 *     choix explicite du visiteur.
 *   - **Mesure d'audience.** Les événements d'installation partent vers
 *     l'outil déjà en place sur le site ; le plugin n'en installe aucun
 *     et n'en collecte aucune donnée pour son compte.
 *
 * Ce que le plugin **ne fait pas**, et qui compte tout autant :
 * aucune donnée personnelle n'est écrite dans la base WordPress. Rien
 * à exporter ni à effacer côté site — le désabonnement se joue entre le
 * navigateur et OneSignal.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_PWA_Privacy {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Adresse de la politique de confidentialité.
	 *
	 * Celle déclarée dans les réglages du plugin l'emporte ; sinon on
	 * prend la page que WordPress connaît déjà. Aucune valeur inventée :
	 * un lien mort vaut moins que pas de lien.
	 *
	 * @return string Adresse, ou chaîne vide si aucune page n'existe.
	 */
	public static function policy_url() {
		$saisie = trim( (string) Africart_PWA_Settings::get( 'privacy_url' ) );

		if ( '' !== $saisie ) {
			return $saisie;
		}

		return (string) get_privacy_policy_url();
	}

	/**
	 * Texte proposé pour la politique de confidentialité.
	 *
	 * WordPress rassemble ces contributions dans Réglages →
	 * Confidentialité : le responsable du site y trouve un texte prêt à
	 * relire et à intégrer, plutôt qu'une page blanche.
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$contenu =
			'<h3>' . esc_html__( 'Notifications', 'africart-pwa' ) . '</h3>' .
			'<p>' . esc_html__( 'Si vous acceptez de recevoir nos notifications, votre navigateur crée un identifiant d’abonnement transmis à notre prestataire OneSignal. Cet identifiant ne contient ni votre nom, ni votre adresse électronique : il désigne un appareil, pas une personne identifiée.', 'africart-pwa' ) . '</p>' .
			'<p>' . esc_html__( 'Les centres d’intérêt que vous cochez éventuellement sont enregistrés avec cet abonnement, afin de ne vous adresser que ce qui vous concerne.', 'africart-pwa' ) . '</p>' .
			'<p><strong>' . esc_html__( 'Base légale :', 'africart-pwa' ) . '</strong> ' . esc_html__( 'votre consentement, que vous pouvez retirer à tout moment — depuis le bouton de désabonnement présent sur le site, ou dans les réglages de notification de votre navigateur.', 'africart-pwa' ) . '</p>' .
			'<p><strong>' . esc_html__( 'Destinataire :', 'africart-pwa' ) . '</strong> ' . esc_html__( 'OneSignal, qui assure l’acheminement technique des messages.', 'africart-pwa' ) . '</p>' .
			'<p><strong>' . esc_html__( 'Durée :', 'africart-pwa' ) . '</strong> ' . esc_html__( 'l’abonnement est conservé tant que vous ne vous en désinscrivez pas. Désinstaller l’application ou effacer les données du site y met également fin.', 'africart-pwa' ) . '</p>' .

			'<h3>' . esc_html__( 'Application installable', 'africart-pwa' ) . '</h3>' .
			'<p>' . esc_html__( 'L’installation de l’application sur votre écran d’accueil ne transmet aucune donnée personnelle. Les pages consultées sont conservées sur votre appareil pour rester lisibles hors connexion ; elles n’en sortent pas.', 'africart-pwa' ) . '</p>';

		wp_add_privacy_policy_content( 'AFRICART PWA', wp_kses_post( wpautop( $contenu ) ) );
	}
}
