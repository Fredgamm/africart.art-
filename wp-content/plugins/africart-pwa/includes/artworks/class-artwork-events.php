<?php
/**
 * Les notifications du thème, déclenchées depuis nos dépôts.
 *
 * ⚠️ Le dépôt ne passe plus par le formulaire du thème.
 *
 * Voxel émet ses événements — « une œuvre a été soumise », « une œuvre a
 * été mise à jour » — depuis SON contrôleur de soumission. Nos dépôts
 * arrivent par une route REST : ce contrôleur n'est jamais appelé, et
 * l'équipe cessait donc d'être prévenue. Les courriels qu'elle recevait
 * avant se sont tus sans que rien ne le signale, ce qui est la pire
 * façon de perdre une notification.
 *
 * ⚠️ On DÉCLENCHE ses événements, on ne réécrit pas ses courriels.
 *
 * Réécrire l'envoi aurait figé chez nous des gabarits que le client
 * modifie dans l'administration du thème : le jour où il change le sujet
 * d'un message, notre copie continuerait d'envoyer l'ancien. En passant
 * par l'événement, tout suit — le texte, les destinataires, la
 * notification dans l'application, l'activation ou non de chaque canal.
 *
 * La règle de déclenchement est celle de Voxel, recopiée depuis
 * `app/controllers/frontend/create-post/submission-controller.php` :
 *
 *   — création : `post:created`, puis `post:submitted` si l'œuvre part
 *     directement en ligne ou en validation ;
 *   — modification : `post:submitted` quand un brouillon est soumis pour
 *     la première fois, `post:updated` dans tous les autres cas.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Events {

	/** Les statuts qui valent « soumise », au sens de Voxel. */
	const SOUMISE = array( 'publish', 'pending' );

	/**
	 * Une œuvre vient d'être créée.
	 *
	 * @param int    $post_id Œuvre.
	 * @param string $statut  Statut obtenu à l'issue de la création.
	 */
	public static function creation( $post_id, $statut ) {
		self::emettre_tout( self::decide( 'creation', '', (string) $statut ), $post_id );
	}

	/**
	 * Une œuvre vient d'être modifiée.
	 *
	 * ⚠️ Le statut d'AVANT décide, pas celui d'après seul.
	 *
	 * Un brouillon qui part en validation est une soumission : c'est la
	 * première fois que l'équipe voit cette œuvre, et c'est le courriel
	 * « une nouvelle œuvre a été soumise » qu'elle doit recevoir. La même
	 * œuvre modifiée une fois en ligne n'est qu'une mise à jour. Les
	 * confondre ferait annoncer comme nouvelle une œuvre déjà lue, ou
	 * l'inverse.
	 *
	 * @param int    $post_id Œuvre.
	 * @param string $avant   Statut avant l'écriture.
	 * @param string $apres   Statut après l'écriture.
	 */
	public static function modification( $post_id, $avant, $apres ) {
		self::emettre_tout( self::decide( 'modification', (string) $avant, (string) $apres ), $post_id );
	}

	/**
	 * Quels événements, pour ce passage-là.
	 *
	 * ⚠️ La décision est séparée de l'envoi, et c'est délibéré.
	 *
	 * Elle recopie une règle qui vit chez le thème : elle doit pouvoir
	 * être vérifiée cas par cas — brouillon soumis, œuvre déjà en ligne
	 * modifiée, création directe — sans écrire un seul courriel à
	 * l'équipe. Mêlée à l'émission, toute vérification aurait supposé
	 * d'envoyer pour de vrai, donc de ne jamais vérifier.
	 *
	 * @param string $cas   « creation » ou « modification ».
	 * @param string $avant Statut avant l'écriture, vide à la création.
	 * @param string $apres Statut après l'écriture.
	 * @return string[] Classes d'événements, dans l'ordre d'émission.
	 */
	public static function decide( $cas, $avant, $apres ) {
		if ( 'creation' === $cas ) {
			$liste = array( '\Voxel\Events\Posts\Post_Created_Event' );

			if ( in_array( $apres, self::SOUMISE, true ) ) {
				$liste[] = '\Voxel\Events\Posts\Post_Submitted_Event';
			}

			return $liste;
		}

		if ( 'draft' === $avant && in_array( $apres, self::SOUMISE, true ) ) {
			return array( '\Voxel\Events\Posts\Post_Submitted_Event' );
		}

		return array( '\Voxel\Events\Posts\Post_Updated_Event' );
	}

	/**
	 * Émet une suite d'événements sur la même œuvre.
	 *
	 * @param string[] $classes Classes d'événements.
	 * @param int      $post_id Œuvre.
	 */
	private static function emettre_tout( $classes, $post_id ) {
		if ( ! self::actives() ) {
			return;
		}

		$type = self::type();

		if ( ! $type ) {
			return;
		}

		foreach ( $classes as $classe ) {
			self::emettre( $classe, $type, $post_id );
		}
	}

	/**
	 * Branche la réécriture du destinataire.
	 *
	 * ⚠️ Le courriel ne part PAS pendant l'émission.
	 *
	 * Voxel empile les messages puis les confie à une requête de fond :
	 * `wp_mail` n'est appelé que plus tard, dans un autre processus.
	 * Poser un filtre sur `wp_mail` le temps de déclencher l'événement —
	 * ce que j'ai d'abord fait — n'interceptait donc rien du tout, en
	 * silence. C'est en l'essayant pour de vrai que ça s'est vu.
	 *
	 * Le thème offre le bon point d'accroche : `voxel/app-events/email`,
	 * appliqué à chaque message au moment où il est composé.
	 */
	public static function brancher() {
		add_filter( 'voxel/app-events/email', array( __CLASS__, 'adresser' ), 10, 4 );
	}

	/**
	 * Réécrit le destinataire de NOS deux événements.
	 *
	 * ⚠️ Ces deux-là seulement, et seulement pour l'équipe.
	 *
	 * Le filtre voit passer tous les messages du thème — commandes,
	 * avis, réclamations. Détourner sans discernement enverrait à cette
	 * adresse des courriels qui ne la concernent pas. On vérifie donc
	 * l'événement ET le destinataire.
	 *
	 * ⚠️ Sans regarder qui a déclenché : une œuvre soumise depuis le
	 * formulaire du site doit arriver à la même adresse que celle
	 * déposée depuis l'application. C'est l'ŒUVRE qui décide de qui
	 * s'en occupe, pas le chemin qu'elle a pris.
	 *
	 * @param array  $courriel    Le message composé.
	 * @param object $evenement   L'événement du thème.
	 * @param array  $notification Réglages du canal.
	 * @param string $destination « post_author », « admin »…
	 * @return array
	 */
	public static function adresser( $courriel, $evenement, $notification = array(), $destination = '' ) {
		if ( 'admin' !== $destination ) {
			return $courriel;
		}

		$vers = self::adresse();

		if ( '' === $vers ) {
			return $courriel;
		}

		if ( ! is_object( $evenement ) || ! method_exists( $evenement, 'get_key' ) ) {
			return $courriel;
		}

		if ( ! in_array( $evenement->get_key(), self::nos_evenements(), true ) ) {
			return $courriel;
		}

		$courriel['recipient'] = $vers;

		return $courriel;
	}

	/**
	 * Les clés des événements que ce module relaie.
	 *
	 * @return string[]
	 */
	private static function nos_evenements() {
		if ( ! class_exists( 'Africart_Artwork_Repository' ) ) {
			return array();
		}

		$type = Africart_Artwork_Repository::POST_TYPE;

		return array(
			'post-types/' . $type . '/post:submitted',
			'post-types/' . $type . '/post:updated',
		);
	}

	/**
	 * L'adresse à qui écrire, si le réglage en désigne une.
	 *
	 * @return string Vide : on garde le destinataire du thème.
	 */
	private static function adresse() {
		if ( ! class_exists( 'Africart_PWA_Settings' ) ) {
			return '';
		}

		$vers = (string) Africart_PWA_Settings::get( 'art_notifs_email' );

		return is_email( $vers ) ? $vers : '';
	}

	/**
	 * Le relais est-il en service ?
	 *
	 * ⚠️ Le réglage ne gouverne QUE le relais, jamais le contenu.
	 *
	 * Ce qui part, à qui, et par quel canal se décide dans le thème. Cet
	 * interrupteur-ci répond à une seule question : est-ce à nous de
	 * déclencher ? Il n'a de raison d'être que le jour où le thème se
	 * remettrait à prévenir de lui-même — l'équipe recevrait alors chaque
	 * message en double, et il faut pouvoir se taire sans toucher aux
	 * réglages de Voxel.
	 *
	 * @return bool
	 */
	private static function actives() {
		if ( ! class_exists( 'Africart_PWA_Settings' ) ) {
			return true;
		}

		return '1' === (string) Africart_PWA_Settings::get( 'art_notifs' );
	}

	/**
	 * Le type de contenu du thème, s'il est là.
	 *
	 * @return object|null
	 */
	private static function type() {
		if ( ! class_exists( '\Voxel\Post_Type' ) || ! class_exists( '\Voxel\Events\Base_Event' ) ) {
			return null;
		}

		return \Voxel\Post_Type::get( Africart_Artwork_Repository::POST_TYPE );
	}

	/**
	 * Émet un événement du thème.
	 *
	 * ⚠️ Une notification qui échoue ne doit JAMAIS faire échouer le
	 * dépôt.
	 *
	 * L'œuvre est déjà écrite quand on arrive ici. Laisser une erreur
	 * remonter rendrait à l'artiste un envoi en échec pour une œuvre
	 * pourtant bien reçue — il la renverrait, et l'équipe en aurait deux.
	 * Voxel intercepte déjà les exceptions et les journalise ; on couvre
	 * aussi les erreurs fatales, qu'une évolution du thème pourrait
	 * introduire du jour au lendemain.
	 *
	 * @param string $classe  Classe de l'événement.
	 * @param object $type    Type de contenu.
	 * @param int    $post_id Œuvre.
	 */
	private static function emettre( $classe, $type, $post_id ) {
		if ( ! class_exists( $classe ) ) {
			return;
		}

		try {
			( new $classe( $type ) )->dispatch( (int) $post_id );
		} catch ( \Throwable $e ) {
			error_log( '[AFRICART] notification ' . $classe . ' : ' . $e->getMessage() ); // phpcs:ignore
		}
	}
}
