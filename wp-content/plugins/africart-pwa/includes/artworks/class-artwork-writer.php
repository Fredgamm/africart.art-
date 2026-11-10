<?php
/**
 * Écrivain d'œuvres — le chemin d'écriture qui nous appartient.
 *
 * ⚠️ Il n'appelle **pas** les objets de champ de Voxel. C'est tout
 * l'objet du lot : dépendre du thème pour lire est acceptable, en
 * dépendre pour écrire nous prive de trois choses dont nous avons
 * besoin — choisir le statut, autoriser la suppression, écrire sans
 * formulaire donc sans réseau.
 *
 * En contrepartie, il doit produire **exactement** ce que Voxel
 * produirait. C'est ce que mesure `class-artwork-parity.php`, écrit
 * avant celui-ci précisément pour ne pas être taillé sur mesure.
 *
 * Les formats sont ceux relevés dans le thème, fichier par fichier :
 *
 *   texte, e-mail, date, select   valeur brute
 *   image, fichier                `join( ',', $ids )`
 *   lieu, choix multiple          `wp_json_encode`
 *   taxonomie                     termes assignés, aucune méta
 *   titre                         `post_title` — la ligne d'écriture de
 *                                 méta est commentée dans le thème
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Writer {

	/**
	 * Types de champs stockés en JSON.
	 *
	 * Relevé dans `location-field.php` et `multiselect-field.php` :
	 * `update_post_meta( ..., wp_slash( wp_json_encode( $value ) ) )`.
	 */
	const TYPES_JSON = array( 'location', 'multiselect', 'recurring-date', 'repeater' );

	/**
	 * Types de champs stockés en liste d'identifiants séparés par des
	 * virgules — relevé dans `file-field.php` : `join( ',', $file_ids )`.
	 */
	const TYPES_MEDIAS = array( 'image', 'file' );

	/* ------------------------------------------------------------------
	 * Écriture
	 * ---------------------------------------------------------------- */

	/**
	 * Crée une œuvre.
	 *
	 * @param array  $valeurs  Valeurs, dans la forme de transport du référentiel.
	 * @param string $statut   Statut voulu. **Le nôtre**, pas celui que le
	 *                         thème impose : c'est le premier blocage levé.
	 * @param string $marqueur Métadonnée technique à poser, si besoin.
	 * @return int|WP_Error
	 */
	public static function create( $valeurs, $statut = 'draft', $marqueur = '' ) {
		if ( ! Africart_Artwork_Repository::is_available() ) {
			return new WP_Error( 'afk_theme_absent', __( 'Le thème des œuvres est indisponible.', 'africart-pwa' ) );
		}

		$description = self::cle_de_type( 'description' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => Africart_Artwork_Repository::POST_TYPE,
				'post_content' => $description ? (string) ( $valeurs[ $description ] ?? '' ) : '',
				'post_title'  => (string) ( $valeurs['title'] ?? '' ),
				'post_name'   => sanitize_title( (string) ( $valeurs['title'] ?? '' ) ),
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( '' !== $marqueur ) {
			update_post_meta( $post_id, $marqueur, 'africart' );
		}

		self::ecrire_champs( $post_id, $valeurs );

		// Le statut n'est posé qu'APRÈS les champs.
		//
		// ⚠️ Constaté au banc de parité : le passage de statut déclenche
		// chez Voxel la pose de l'image mise en avant depuis le champ
		// `cover`. L'appliquer avant que `cover` n'existe la laisserait
		// vide, et l'œuvre s'afficherait sans visuel partout où le thème
		// se sert de la vignette.
		if ( 'draft' !== $statut ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => $statut ) );
		}

		self::reindexer( $post_id );

		return (int) $post_id;
	}

	/**
	 * Modifie une œuvre existante.
	 *
	 * @param int         $post_id Œuvre.
	 * @param array       $valeurs Valeurs à écrire.
	 * @param string|null $statut  Nouveau statut, ou null pour le conserver.
	 * @return int|WP_Error
	 */
	public static function update( $post_id, $valeurs, $statut = null ) {
		$post = get_post( (int) $post_id );

		if ( ! $post || Africart_Artwork_Repository::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'afk_introuvable', __( 'Œuvre introuvable.', 'africart-pwa' ) );
		}

		$entete = array( 'ID' => (int) $post_id );

		if ( isset( $valeurs['title'] ) ) {
			$entete['post_title'] = (string) $valeurs['title'];
		}

		$description = self::cle_de_type( 'description' );

		if ( $description && isset( $valeurs[ $description ] ) ) {
			$entete['post_content'] = (string) $valeurs[ $description ];
		}

		if ( null !== $statut ) {
			$entete['post_status'] = $statut;
		}

		self::ecrire_champs( $post_id, $valeurs );

		if ( count( $entete ) > 1 ) {
			wp_update_post( $entete );
		}

		self::reindexer( $post_id );

		return (int) $post_id;
	}

	/* ------------------------------------------------------------------
	 * Champs
	 * ---------------------------------------------------------------- */

	/**
	 * Écrit les champs porteurs de données.
	 *
	 * @param int   $post_id Œuvre.
	 * @param array $valeurs Valeurs.
	 */
	private static function ecrire_champs( $post_id, $valeurs ) {
		foreach ( Africart_Artwork_Repository::fields() as $cle => $champ ) {
			if ( ! array_key_exists( $cle, $valeurs ) ) {
				continue;
			}

			$valeur = $valeurs[ $cle ];
			$type   = $champ['type'];

			// Le titre vit dans `post_title`. Le thème a d'ailleurs
			// commenté sa propre écriture de méta : en poser une ici
			// créerait une clé que rien ne lit et que rien ne nettoie.
			if ( 'title' === $type ) {
				continue;
			}

			// ⚠️ La description non plus n'est pas une méta : le thème
			// l'écrit dans `post_content`. Constaté au banc de parité —
			// nous déposions une méta que rien ne lit, et le corps de
			// l'œuvre restait vide sur la fiche publique.
			if ( 'description' === $type ) {
				continue;
			}

			if ( 'taxonomy' === $type ) {
				self::ecrire_taxonomie( $post_id, $cle, $valeur );
				continue;
			}

			$encodee = self::encoder( $type, $valeur );

			if ( null === $encodee || '' === $encodee ) {
				// Voxel efface la méta plutôt que d'y laisser une chaîne
				// vide. Reproduire l'absence, et non le vide, évite
				// qu'une requête sur « champ existe » ne rende des œuvres
				// dont le champ n'est pas renseigné.
				delete_post_meta( $post_id, $cle );
				continue;
			}

			update_post_meta( $post_id, $cle, $encodee );
		}
	}

	/**
	 * Met une valeur à la forme que Voxel dépose en base.
	 *
	 * @param string $type   Type de champ.
	 * @param mixed  $valeur Valeur en forme de transport.
	 * @return string|null
	 */
	private static function encoder( $type, $valeur ) {
		if ( null === $valeur ) {
			return null;
		}

		if ( in_array( $type, self::TYPES_MEDIAS, true ) ) {
			return self::encoder_medias( $valeur );
		}

		if ( in_array( $type, self::TYPES_JSON, true ) ) {
			// ⚠️ Un tableau plein de valeurs nulles n'est pas un tableau
			// vide pour PHP, mais c'en est un pour le thème : sa méthode
			// `update()` teste `empty( $value )` sur ce que rend son
			// assainissement, qui vaut `null`. Constaté au banc — nous
			// déposions `{"address":null,"latitude":null,…}` là où
			// Voxel efface la méta.
			if ( ! is_array( $valeur ) || ! self::a_du_contenu( $valeur ) ) {
				return null;
			}
			return wp_slash( (string) wp_json_encode( $valeur ) );
		}

		if ( is_array( $valeur ) ) {
			return $valeur ? wp_slash( (string) wp_json_encode( $valeur ) ) : null;
		}

		return wp_slash( (string) $valeur );
	}

	/**
	 * Liste d'identifiants de médias, séparés par des virgules.
	 *
	 * ⚠️ La forme de lecture n'est pas la forme d'écriture. Un champ
	 * image se **lit** en descripteurs — `{ source, file_id, ... }` — et
	 * s'**écrit** en identifiants. Recopier la lecture telle quelle
	 * déposerait du JSON là où le thème attend « 5256,5257 », et la
	 * galerie resterait vide sans la moindre erreur.
	 *
	 * @param mixed $valeur Descripteurs, ou identifiants.
	 * @return string|null
	 */
	private static function encoder_medias( $valeur ) {
		$ids = array();

		foreach ( (array) $valeur as $item ) {
			if ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
				continue;
			}

			if ( is_array( $item ) && isset( $item['file_id'] ) ) {
				$ids[] = (int) $item['file_id'];
				continue;
			}

			if ( is_array( $item ) && isset( $item['id'] ) ) {
				$ids[] = (int) $item['id'];
			}
		}

		$ids = array_values( array_filter( $ids ) );

		return $ids ? implode( ',', $ids ) : null;
	}

	/**
	 * Assigne les termes d'une taxonomie.
	 *
	 * Les valeurs transportées sont des slugs — c'est la forme que le
	 * référentiel produit et que le thème accepte. `wp_set_post_terms`
	 * attend des identifiants ou des slugs selon le dernier paramètre :
	 * on lui passe les slugs, il résout.
	 *
	 * @param int    $post_id Œuvre.
	 * @param string $cle     Clé du champ, qui porte le nom de la taxonomie.
	 * @param mixed  $valeur  Slugs.
	 */
	private static function ecrire_taxonomie( $post_id, $cle, $valeur ) {
		$taxonomies = get_object_taxonomies( Africart_Artwork_Repository::POST_TYPE );
		$taxonomie  = in_array( $cle, $taxonomies, true ) ? $cle : ( $taxonomies[0] ?? '' );

		if ( '' === $taxonomie ) {
			return;
		}

		$slugs = array_values( array_filter( array_map( 'strval', (array) $valeur ) ) );

		if ( ! $slugs ) {
			wp_set_post_terms( $post_id, array(), $taxonomie );
			return;
		}

		$ids = array();

		foreach ( $slugs as $slug ) {
			$terme = get_term_by( 'slug', $slug, $taxonomie );

			if ( $terme && ! is_wp_error( $terme ) ) {
				$ids[] = (int) $terme->term_id;
			}
		}

		wp_set_post_terms( $post_id, $ids, $taxonomie );
	}

	/**
	 * Clé du premier champ d'un type donné.
	 *
	 * Le titre et la description ne vivent pas dans des métas, et leurs
	 * clés sont fixées par le thème, pas par nous.
	 *
	 * @param string $type Type recherché.
	 * @return string Clé, ou chaîne vide.
	 */
	private static function cle_de_type( $type ) {
		foreach ( Africart_Artwork_Repository::fields() as $cle => $champ ) {
			if ( $champ['type'] === $type ) {
				return $cle;
			}
		}

		return '';
	}

	/**
	 * Un tableau porte-t-il la moindre valeur utile ?
	 *
	 * @param array $valeur Tableau.
	 * @return bool
	 */
	private static function a_du_contenu( $valeur ) {
		foreach ( $valeur as $v ) {
			if ( is_array( $v ) ) {
				if ( self::a_du_contenu( $v ) ) {
					return true;
				}
				continue;
			}

			if ( null !== $v && '' !== $v && false !== $v ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------------------
	 * Index de recherche
	 * ---------------------------------------------------------------- */

	/**
	 * Remet l'œuvre dans l'index du thème.
	 *
	 * ⚠️ Sans cet appel, une œuvre écrite par nos soins **existe,
	 * s'ouvre par son lien, et disparaît de la recherche du site**. Aucune
	 * erreur nulle part : c'est la panne la plus difficile à imputer,
	 * parce que tout paraît correct sauf le résultat.
	 *
	 * Le thème n'indexe que certains statuts. Un brouillon n'entre pas
	 * dans l'index, et c'est normal — il ne doit pas apparaître dans une
	 * recherche publique.
	 *
	 * @param int $post_id Œuvre.
	 */
	private static function reindexer( $post_id ) {
		if ( ! class_exists( '\Voxel\Post' ) ) {
			return;
		}

		$objet = \Voxel\Post::force_get( (int) $post_id );

		if ( ! $objet ) {
			return;
		}

		try {
			// ⚠️ Indexer sans condition mettait les brouillons dans la
			// recherche publique — constaté au banc, notre écriture était
			// indexée là où celle du thème ne l'était pas. C'est le thème
			// qui sait quels statuts sont indexables.
			if ( method_exists( $objet, 'should_index' ) && ! $objet->should_index() ) {
				$objet->unindex();
				return;
			}

			$objet->index();
		} catch ( \Throwable $e ) {
			// Une indexation qui échoue ne doit pas perdre l'écriture.
			// Elle se rattrape au prochain enregistrement.
			return;
		}
	}
}
