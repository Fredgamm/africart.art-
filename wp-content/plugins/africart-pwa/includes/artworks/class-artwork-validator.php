<?php
/**
 * Validation d'une œuvre avant écriture.
 *
 * ⚠️ Pourquoi déléguer la règle au thème alors que le lot consiste à
 * nous en affranchir : parce que **valider et écrire ne sont pas la même
 * chose**. Écrire est un mécanisme, et nous le voulons à nous.
 * Déterminer ce qui fait une œuvre valide est un jugement sur le modèle
 * de données — quels champs sont obligatoires, lesquels s'affichent sous
 * condition — et ce jugement est déclaré dans l'éditeur du thème, par
 * la personne qui a conçu le formulaire.
 *
 * Redéclarer ces vingt champs obligatoires chez nous créerait deux
 * vérités. Le jour où quelqu'un rend un champ facultatif dans l'éditeur,
 * notre copie continuerait de le réclamer, et personne ne saurait
 * pourquoi.
 *
 * Nous possédons donc **l'invocation et le compte rendu** — quand on
 * valide, et comment on l'explique — et le thème garde la règle.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Validator {

	/**
	 * Vérifie un jeu de valeurs.
	 *
	 * @param array $valeurs   Valeurs en forme de transport.
	 * @param bool  $brouillon Un brouillon n'a pas à être complet : on
	 *                         n'enregistrerait rien si l'on exigeait vingt
	 *                         champs pour poser une idée.
	 * @return array<string,string> Erreurs, indexées par clé de champ.
	 */
	public static function check( $valeurs, $brouillon = false ) {
		if ( ! Africart_Artwork_Repository::is_available() ) {
			return array( '_' => __( 'Le thème des œuvres est indisponible.', 'africart-pwa' ) );
		}

		$type    = \Voxel\Post_Type::get( Africart_Artwork_Repository::POST_TYPE );
		$erreurs = array();
		$propres = array();

		// 1. Assainissement d'abord, sur tous les champs : la logique
		// conditionnelle se juge sur les valeurs nettoyées, pas sur ce
		// qui est arrivé.
		foreach ( $type->get_fields() as $champ ) {
			$cle = $champ->get_key();

			// ⚠️ Un champ réservé garde sa place à `null` au lieu d'être
			// sauté. L'étape suivante lit `$propres[ $cle ]` pour chaque
			// champ du thème : une clé absente y produisait un avertissement
			// PHP à chaque dépôt — constaté au banc.
			if ( ! Africart_Artwork_Repository::ecriture_autorisee( $champ ) ) {
				$propres[ $cle ] = null;
				continue;
			}

			try {
				$propres[ $cle ] = array_key_exists( $cle, $valeurs )
					? $champ->sanitize( $valeurs[ $cle ] )
					: null;
			} catch ( \Throwable $e ) {
				$erreurs[ $cle ] = $e->getMessage();
				$propres[ $cle ] = null;
			}
		}

		// 2. Les champs masqués par une condition ne sont pas exigibles.
		// Réclamer « Dénomination sociale » à un artiste qui a coché
		// « particulier » serait absurde, et le formulaire du site ne le
		// fait pas non plus.
		$champs = $type->get_fields();

		foreach ( $champs as $cle => $champ ) {
			try {
				if ( ! $champ->passes_conditional_logic( $propres ) ) {
					unset( $champs[ $cle ] );
				}
			} catch ( \Throwable $e ) {
				// Une condition qui ne sait pas se juger laisse le champ
				// en place : mieux vaut demander une fois de trop que
				// d'enregistrer une œuvre incomplète.
				continue;
			}
		}

		// 3. Validation proprement dite.
		foreach ( $champs as $champ ) {
			if ( in_array( $champ->get_type(), Africart_Artwork_Repository::TYPES_DECORATIFS, true ) ) {
				continue;
			}

			// Un champ réservé n'est pas exigible de qui ne le voit pas :
			// le réclamer bloquerait un dépôt sur une question jamais posée.
			if ( ! Africart_Artwork_Repository::ecriture_autorisee( $champ ) ) {
				continue;
			}

			$cle = $champ->get_key();

			try {
				if ( $brouillon ) {
					$champ->check_validity_bypass_required( $propres[ $cle ] );
				} else {
					$champ->check_validity( $propres[ $cle ] );
				}
			} catch ( \Throwable $e ) {
				$erreurs[ $cle ] = $e->getMessage();
			}
		}

		return $erreurs;
	}

	/**
	 * Valeurs assainies par le thème.
	 *
	 * L'écrivain a besoin de la forme nettoyée, pas de ce qui est arrivé
	 * du réseau. Un champ de texte riche non filtré laisserait passer du
	 * balisage que la fiche publique afficherait tel quel.
	 *
	 * @param array $valeurs Valeurs en forme de transport.
	 * @return array
	 */
	public static function sanitize( $valeurs ) {
		if ( ! Africart_Artwork_Repository::is_available() ) {
			return $valeurs;
		}

		$type    = \Voxel\Post_Type::get( Africart_Artwork_Repository::POST_TYPE );
		$propres = array();

		foreach ( $type->get_fields() as $champ ) {
			$cle = $champ->get_key();

			if ( ! array_key_exists( $cle, $valeurs ) ) {
				continue;
			}

			// ⚠️ Un champ que cette personne n'a pas le droit de voir n'est
			// pas écrit, même s'il arrive dans la requête.
			//
			// Le schéma masque « Raison du rejet » à l'artiste, mais la
			// route, elle, parcourait **tous** les champs du thème : une
			// requête forgée — ou un ancien brouillon gardé sur l'appareil
			// avant ce filtrage — écrivait le motif de son propre refus,
			// qui s'affiche ensuite dans « mes œuvres ». Protéger le
			// formulaire sans protéger la route ne protège rien.
			if ( ! Africart_Artwork_Repository::ecriture_autorisee( $champ ) ) {
				continue;
			}

			try {
				$propres[ $cle ] = $champ->sanitize( $valeurs[ $cle ] );
			} catch ( \Throwable $e ) {
				// Une valeur qui refuse d'être nettoyée n'est pas écrite :
				// la laisser passer brute serait pire que l'omettre.
				continue;
			}
		}

		return $propres;
	}
}
