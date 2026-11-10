<?php
/**
 * Les règles de dépôt, telles que nous les décidons.
 *
 * ⚠️ C'est le lot qui lève les deux blocages relevés en phase 0 :
 *
 *   - `submissions.status` vaut `pending` chez Voxel : une œuvre déposée
 *     part en validation, et l'artiste n'a pas la main ;
 *   - `submissions.deletable` vaut `false` : il ne peut rien supprimer.
 *
 * Ces deux réglages restent ceux du thème, et ils continuent de
 * gouverner **son** formulaire. Notre chemin d'écriture, lui, applique
 * les nôtres. Les deux coexistent sans se contredire, parce qu'ils ne
 * servent pas le même écran.
 *
 * Par défaut, nos règles **recopient celles du thème**. Un module qui
 * change le comportement du site à la seconde où on l'active serait une
 * mauvaise surprise : il faut un geste explicite pour s'en écarter, et
 * l'écran de réglages montre l'écart quand il existe.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Rules {

	/** Statuts qu'un dépôt peut prendre à la création. */
	const STATUTS_CREATION = array( 'draft', 'pending', 'publish' );

	/**
	 * Statut d'une œuvre nouvellement déposée.
	 *
	 * « Suivre le thème » n'est pas une valeur de repli paresseuse :
	 * c'est le comportement attendu tant que personne n'a décidé
	 * autrement.
	 *
	 * @return string `draft`, `pending` ou `publish`.
	 */
	public static function statut_creation() {
		$choix = (string) Africart_PWA_Settings::get( 'art_statut_creation' );

		if ( in_array( $choix, self::STATUTS_CREATION, true ) ) {
			return $choix;
		}

		$regles = Africart_Artwork_Repository::submission_rules();
		$theme  = (string) ( $regles['status'] ?? 'pending' );

		return in_array( $theme, self::STATUTS_CREATION, true ) ? $theme : 'pending';
	}

	/**
	 * Statut après modification d'une œuvre déjà en ligne.
	 *
	 * Repasser une œuvre publiée en validation à chaque correction de
	 * faute de frappe la ferait disparaître du site le temps de la
	 * relecture. Le thème l'a d'ailleurs réglé sur `publish` ici.
	 *
	 * @param string $statut_actuel Statut avant modification.
	 * @return string
	 */
	public static function statut_modification( $statut_actuel ) {
		$regles = Africart_Artwork_Repository::submission_rules();

		if ( 'publish' === $statut_actuel ) {
			return 'pending' === ( $regles['updateStatus'] ?? '' ) ? 'pending' : 'publish';
		}

		// Un brouillon reste un brouillon tant que l'artiste ne demande
		// pas explicitement à le déposer.
		return $statut_actuel;
	}

	/**
	 * Statut produit par un dépôt explicite.
	 *
	 * ⚠️ « Déposer » et « enregistrer » ne sont pas le même geste, et
	 * c'est tout l'objet de cette méthode.
	 *
	 * L'application envoyait les deux sous le même verbe. Une œuvre
	 * déposée gardait donc le statut qu'elle avait — `draft` — et
	 * l'artiste, qui venait de la soumettre, la retrouvait affichée
	 * « Brouillon ». Rien ne distinguait plus ce qu'il avait envoyé de
	 * ce qu'il était en train d'écrire, et personne au secrétariat ne
	 * voyait arriver l'œuvre en relecture. Constaté sur le site : l'œuvre
	 * 5460, déposée, restait en brouillon dans WordPress.
	 *
	 * Un brouillon devient donc « en attente de validation ». Une œuvre
	 * refusée ou retirée y retourne : la corriger et la redéposer est
	 * précisément la façon de demander un second regard.
	 *
	 * ⚠️ Sauf une œuvre déjà en ligne. La renvoyer en validation à chaque
	 * correction la ferait disparaître du site le temps de la relecture —
	 * c'est exactement ce que `statut_modification()` évite, et le dépôt
	 * n'a aucune raison d'en décider autrement.
	 *
	 * @param string $statut_actuel Statut avant le dépôt.
	 * @return string
	 */
	public static function statut_depot( $statut_actuel ) {
		if ( 'publish' === $statut_actuel ) {
			return self::statut_modification( $statut_actuel );
		}

		return self::statut_creation();
	}

	/**
	 * L'artiste peut-il retirer ses œuvres ?
	 *
	 * ⚠️ Question de gouvernance, pas de technique. Sur un répertoire
	 * d'œuvres référencées, laisser un contributeur effacer une entrée
	 * déjà citée ailleurs se discute — c'est pourquoi le thème l'interdit
	 * et pourquoi nous ne l'autorisons pas sans un geste explicite.
	 *
	 * @return bool
	 */
	public static function suppression_autorisee() {
		$choix = (string) Africart_PWA_Settings::get( 'art_suppression' );

		if ( '1' === $choix ) {
			return true;
		}

		if ( '0' === $choix ) {
			return false;
		}

		$regles = Africart_Artwork_Repository::submission_rules();

		return ! empty( $regles['deletable'] );
	}

	/**
	 * Nos règles s'écartent-elles de celles du thème ?
	 *
	 * L'écran de réglages a besoin de le dire : un écart silencieux
	 * entre deux chemins de dépôt est exactement ce qui rend un site
	 * incompréhensible six mois plus tard.
	 *
	 * @return array<int,string> Écarts, en clair.
	 */
	public static function ecarts() {
		$regles = Africart_Artwork_Repository::submission_rules();
		$liste  = array();

		if ( self::statut_creation() !== ( $regles['status'] ?? '' ) ) {
			$liste[] = sprintf(
				/* translators: 1: statut retenu, 2: statut du thème */
				__( 'Dépôt en « %1$s » depuis l’application, « %2$s » depuis le formulaire du site.', 'africart-pwa' ),
				self::libelle( self::statut_creation() ),
				self::libelle( (string) ( $regles['status'] ?? '' ) )
			);
		}

		if ( self::suppression_autorisee() !== ! empty( $regles['deletable'] ) ) {
			$liste[] = self::suppression_autorisee()
				? __( 'Suppression permise depuis l’application, refusée par le site.', 'africart-pwa' )
				: __( 'Suppression refusée depuis l’application, permise par le site.', 'africart-pwa' );
		}

		return $liste;
	}

	/**
	 * Nom lisible d'un statut.
	 *
	 * ⚠️ « En attente » ne veut rien dire pour un artiste. « En
	 * validation » dit ce qui se passe : quelqu'un doit regarder.
	 *
	 * @param string $statut Statut WordPress.
	 * @return string
	 */
	public static function libelle( $statut ) {
		$table = array(
			'draft'       => __( 'Brouillon', 'africart-pwa' ),
			'pending'     => __( 'En attente de validation', 'africart-pwa' ),
			'publish'     => __( 'En ligne', 'africart-pwa' ),
			'rejected'    => __( 'Refusée', 'africart-pwa' ),
			'unpublished' => __( 'Retirée du site', 'africart-pwa' ),
			'trash'       => __( 'Supprimée', 'africart-pwa' ),
		);

		return isset( $table[ $statut ] ) ? $table[ $statut ] : $statut;
	}
}
