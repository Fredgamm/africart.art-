<?php
/**
 * La session de l'artiste, qui doit durer.
 *
 * ⚠️ Le défaut de WordPress est incompatible avec l'usage hors connexion.
 *
 * Un cookie d'authentification vit deux jours, quatorze si l'artiste a
 * pensé à cocher « se souvenir de moi ». Passé ce délai il faut se
 * reconnecter — et se reconnecter demande du réseau. Or c'est précisément
 * sans réseau que cette application sert : l'artiste ouvre l'appli en
 * brousse, remplit son formulaire, et découvre au dernier écran qu'il
 * doit d'abord se connecter. Il ne le peut pas. Sa saisie reste sur
 * l'appareil, mais lui a perdu vingt minutes et la confiance.
 *
 * Constaté au test client : « Je dois me connecter à mon compte pour
 * enregistrer une œuvre, or je ne peux me connecter que si j'ai une
 * connexion internet. »
 *
 * La session dure donc un an. Ce n'est pas une négligence, c'est un
 * arbitrage : sur un téléphone personnel qui sert à déposer des œuvres,
 * le risque d'une session longue est bien moindre que la certitude d'un
 * artiste bloqué sans réseau.
 *
 * ⚠️ Sauf pour ceux qui peuvent modifier le travail des autres.
 *
 * Administrateurs, éditeurs, secrétariat : une session d'un an sur un
 * compte qui peut publier, dépublier et supprimer les œuvres de tous
 * serait une autre affaire — un ordinateur partagé, un téléphone perdu,
 * et le site est ouvert pendant douze mois. Ceux-là gardent la durée de
 * WordPress. Ils travaillent au bureau, avec du réseau.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Session {

	/**
	 * Durée d'une session d'artiste.
	 *
	 * Ajustable par le filtre `afk_pwa_duree_session` : c'est une
	 * politique, elle peut changer sans toucher au code.
	 */
	const DUREE = YEAR_IN_SECONDS;

	/** @var Africart_Artwork_Session|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_filter( 'auth_cookie_expiration', array( $this, 'duree' ), 20, 3 );
	}

	/**
	 * Combien de temps le cookie d'authentification reste valable.
	 *
	 * @param int  $duree      Durée retenue par WordPress.
	 * @param int  $user_id    Compte concerné.
	 * @param bool $se_souvenir L'artiste a-t-il coché « se souvenir de moi ».
	 * @return int
	 */
	public function duree( $duree, $user_id, $se_souvenir ) {
		if ( ! self::eligible( $user_id ) ) {
			return $duree;
		}

		/**
		 * Durée d'une session d'artiste.
		 *
		 * @param int  $duree       Durée voulue, en secondes.
		 * @param int  $user_id     Compte concerné.
		 * @param bool $se_souvenir Case « se souvenir de moi ».
		 */
		$voulue = (int) apply_filters( 'afk_pwa_duree_session', self::DUREE, $user_id, $se_souvenir );

		// ⚠️ Jamais plus court que ce que WordPress avait décidé. Le but
		// est d'allonger, pas de raccourcir : un filtre mal réglé ne doit
		// pas déconnecter des artistes plus tôt qu'aujourd'hui.
		return max( (int) $duree, $voulue );
	}

	/**
	 * Ce compte a-t-il droit à une session longue ?
	 *
	 * ⚠️ La question porte sur les **pouvoirs**, pas sur le rôle. Ce site
	 * a des rôles sur mesure ; énumérer « administrator, editor » aurait
	 * laissé passer le prochain rôle créé pour le secrétariat.
	 *
	 * @param int $user_id Compte.
	 * @return bool
	 */
	public static function eligible( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return false;
		}

		foreach ( array( 'edit_others_posts', 'manage_options', 'delete_others_posts' ) as $pouvoir ) {
			if ( user_can( $user_id, $pouvoir ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ce qu'il reste à courir, pour la route `/session`.
	 *
	 * @param int $user_id Compte.
	 * @return int Secondes.
	 */
	public static function duree_pour( $user_id ) {
		if ( ! self::eligible( $user_id ) ) {
			return 0;
		}

		return (int) apply_filters( 'afk_pwa_duree_session', self::DUREE, $user_id, true );
	}
}
