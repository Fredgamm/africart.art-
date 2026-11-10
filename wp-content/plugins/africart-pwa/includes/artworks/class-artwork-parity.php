<?php
/**
 * Banc de parité : notre écrivain produit-il exactement ce que Voxel
 * produirait ?
 *
 * ⚠️ Ce fichier est écrit **avant** l'écrivain qu'il doit juger, et ce
 * n'est pas un détail d'ordonnancement. Un contrôle construit après coup
 * est toujours taillé pour valider ce qu'on vient d'écrire : on y
 * compare ce qu'on sait déjà égal, et on omet précisément ce qu'on a
 * oublié. Écrit d'abord, il énonce une exigence ; écrit ensuite, il
 * décrit une implémentation.
 *
 * Le principe : deux œuvres de test, mêmes valeurs en entrée, deux
 * chemins d'écriture différents, et une comparaison champ par champ de
 * ce qui s'est réellement déposé en base.
 *
 *   chemin A — les objets de champ de Voxel : `$field->update( $valeur )`
 *   chemin B — notre écrivain, qui écrit les métas directement
 *
 * Si les deux empreintes divergent, notre écrivain est infidèle, et le
 * banc dit **où**.
 *
 * ⚠️ Aucune œuvre existante n'est jamais touchée. Le banc crée ses
 * propres contenus, les marque, et les supprime. Les tests portent
 * toujours sur des brouillons : une œuvre de contrôle n'a rien à faire
 * dans la recherche du site, même une seconde.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Parity {

	/** Marqueur posé sur les contenus créés par le banc. */
	const META_TEST = '_afk_parity_test';

	/**
	 * Échecs rencontrés par le chemin de référence.
	 *
	 * Un banc qui masque les faiblesses de sa propre référence accuse
	 * l'écrivain de divergences dont il n'est pas responsable.
	 *
	 * @var string[]
	 */
	private static $incidents = array();

	/** Incidents relevés depuis le dernier appel. */
	public static function incidents() {
		$liste            = self::$incidents;
		self::$incidents  = array();
		return $liste;
	}

	/**
	 * Métas à ignorer dans la comparaison.
	 *
	 * Elles ne viennent pas du chemin d'écriture mais d'autres
	 * extensions — référencement, compteurs — et varient d'un
	 * enregistrement à l'autre sans que cela signifie une infidélité.
	 */
	const IGNOREES = array(
		'rank_math_internal_links_processed',
		'rank_math_seo_score',
		'rank_math_analytic_object_id',
		'_edit_lock',
		'_edit_last',
		self::META_TEST,
	);

	/* ------------------------------------------------------------------
	 * Jeu de valeurs
	 * ---------------------------------------------------------------- */

	/**
	 * Valeurs d'entrée, relevées sur une œuvre réelle.
	 *
	 * Fabriquer des valeurs de toutes pièces ferait passer le banc à côté
	 * de l'essentiel : ce sont les formes réelles — une liste
	 * d'identifiants de médias, un terme de taxonomie qui existe, un
	 * texte riche avec ses balises — qui révèlent les écarts d'encodage.
	 *
	 * @param int $modele Œuvre servant de modèle.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function sample_from( $modele ) {
		if ( ! Africart_Artwork_Repository::is_available() ) {
			return new WP_Error( 'afk_theme_absent', 'Voxel indisponible.' );
		}

		$post = get_post( (int) $modele );

		if ( ! $post || Africart_Artwork_Repository::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'afk_modele', 'Œuvre modèle introuvable.' );
		}

		// ⚠️ Les valeurs viennent du référentiel, pas d'une seconde
		// lecture faite ici. Une forme de transport définie à deux
		// endroits finit toujours par diverger, et le banc validerait
		// alors sa propre erreur.
		$fiche = Africart_Artwork_Repository::get( $post->ID );

		if ( ! $fiche || ! isset( $fiche['champs'] ) ) {
			return new WP_Error( 'afk_lecture', 'Lecture de l’œuvre modèle impossible.' );
		}

		$valeurs = $fiche['champs'];

		// Le titre est marqué : deux œuvres de contrôle ne doivent pas se
		// confondre dans la médiathèque ni dans la corbeille.
		$valeurs['title'] = 'CONTROLE DE PARITE — ' . wp_date( 'H:i:s' );

		return $valeurs;
	}

	/* ------------------------------------------------------------------
	 * Chemin A — les objets de champ de Voxel
	 * ---------------------------------------------------------------- */

	/**
	 * Écrit une œuvre en déléguant à Voxel, comme le fait son formulaire.
	 *
	 * Reproduit l'ordre du contrôleur de soumission : assainissement,
	 * insertion, puis `update()` champ par champ sur le contenu créé.
	 * C'est la référence à laquelle notre écrivain devra ressembler.
	 *
	 * @param array $valeurs Valeurs d'entrée.
	 * @return int|WP_Error Identifiant du contenu créé.
	 */
	public static function write_via_voxel( $valeurs ) {
		if ( ! Africart_Artwork_Repository::is_available() ) {
			return new WP_Error( 'afk_theme_absent', 'Voxel indisponible.' );
		}

		$type   = \Voxel\Post_Type::get( Africart_Artwork_Repository::POST_TYPE );
		$champs = $type->get_fields();

		// 1. Assainissement, avant toute écriture — comme le contrôleur.
		$propres = array();
		foreach ( $champs as $champ ) {
			$cle             = $champ->get_key();
			$propres[ $cle ] = isset( $valeurs[ $cle ] ) ? $champ->sanitize( $valeurs[ $cle ] ) : null;
		}

		// 2. Insertion. Toujours en brouillon : une œuvre de contrôle n'a
		// rien à faire dans la recherche du site.
		$post_id = wp_insert_post(
			array(
				'post_type'   => Africart_Artwork_Repository::POST_TYPE,
				'post_title'  => (string) ( $propres['title'] ?? '' ),
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_TEST, 'voxel' );

		// 3. Écriture champ par champ, par les objets du thème.
		$objet = \Voxel\Post::get( $post_id );

		foreach ( $champs as $champ ) {
			$champ->set_post( $objet );

			try {
				$champ->update( $propres[ $champ->get_key() ] );
			} catch ( \Throwable $e ) {
				// ⚠️ Ces échecs étaient avalés en silence, et le banc
				// comparait alors une référence incomplète à notre
				// écriture — en concluant que nous étions infidèles.
				// Ils sont désormais consignés et rendus au rapport.
				self::$incidents[] = sprintf(
					'%s (%s) : %s',
					$champ->get_key(),
					$champ->get_type(),
					$e->getMessage()
				);
			}
		}

		return (int) $post_id;
	}

	/* ------------------------------------------------------------------
	 * Chemin B — notre écrivain
	 * ---------------------------------------------------------------- */

	/**
	 * Écrit une œuvre par notre propre chemin.
	 *
	 * Tant que l'écrivain n'existe pas, le banc le dit franchement
	 * plutôt que de rendre un résultat qui ressemblerait à un succès.
	 *
	 * @param array $valeurs Valeurs d'entrée.
	 * @return int|WP_Error
	 */
	public static function write_via_africart( $valeurs ) {
		if ( ! class_exists( 'Africart_Artwork_Writer' ) ) {
			return new WP_Error(
				'afk_ecrivain_absent',
				'L’écrivain Africart n’est pas encore en place — lot B1.'
			);
		}

		return Africart_Artwork_Writer::create( $valeurs, 'draft', self::META_TEST );
	}

	/* ------------------------------------------------------------------
	 * Empreinte
	 * ---------------------------------------------------------------- */

	/**
	 * Tout ce qu'une écriture a déposé, sous une forme comparable.
	 *
	 * Les métas, mais pas seulement : le titre n'est pas une méta — la
	 * ligne est commentée dans le thème — et les taxonomies n'en sont pas
	 * non plus. Une empreinte qui n'en tiendrait pas compte laisserait
	 * passer deux infidélités faciles.
	 *
	 * L'indexation est relevée aussi : une œuvre correctement écrite mais
	 * non indexée existe, s'ouvre par son lien, et **disparaît de la
	 * recherche du site**. Sans erreur.
	 *
	 * @param int $post_id Contenu.
	 * @return array
	 */
	public static function snapshot( $post_id ) {
		global $wpdb;

		// ⚠️ Le cache d'objets doit être vidé avant de relire.
		//
		// Le champ description du thème écrit `post_content` par un
		// `$wpdb->update()` direct sur la table des contenus — sans passer
		// par WordPress, donc sans invalider son cache. `get_post()` rend
		// alors l'ancienne valeur, et le banc concluait que le chemin de
		// référence n'avait rien écrit alors qu'il avait bien écrit.
		//
		// Le piège dépasse le banc : tout code qui relit une œuvre juste
		// après une écriture Voxel s'y heurte.
		clean_post_cache( (int) $post_id );

		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return array();
		}

		$metas = array();

		foreach ( get_post_meta( $post_id ) as $cle => $valeurs ) {
			if ( in_array( $cle, self::IGNOREES, true ) ) {
				continue;
			}
			$metas[ $cle ] = $valeurs[0];
		}

		ksort( $metas );

		$termes = array();
		foreach ( get_object_taxonomies( Africart_Artwork_Repository::POST_TYPE ) as $tx ) {
			$liste = wp_get_post_terms( $post_id, $tx, array( 'fields' => 'slugs' ) );
			sort( $liste );
			$termes[ $tx ] = $liste;
		}

		$table  = $wpdb->prefix . 'voxel_index_' . Africart_Artwork_Repository::POST_TYPE;
		$indexe = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);

		return array(
			'titre'   => $post->post_title,
			// ⚠️ Ajouté après coup, et c'est un aveu utile : le banc
			// ignorait `post_content`, et les deux chemins y divergeaient
			// de 1 194 caractères sans qu'aucun écart ne soit signalé.
			// Un contrôle qui ne regarde pas partout ne prouve rien là où
			// il ne regarde pas.
			'contenu' => $post->post_content,
			'statut'  => $post->post_status,
			'auteur'  => (int) $post->post_author,
			'metas'   => $metas,
			'termes'  => $termes,
			'indexee' => $indexe > 0,
		);
	}

	/**
	 * Compare deux empreintes.
	 *
	 * Rend la liste des écarts, jamais un simple verdict : « différent »
	 * n'aide personne, « le champ gallery vaut 12,13 d'un côté et
	 * [12,13] de l'autre » se corrige en une minute.
	 *
	 * @param array $a Empreinte de référence.
	 * @param array $b Empreinte à juger.
	 * @return array<int,array{champ:string,attendu:mixed,obtenu:mixed}>
	 */
	public static function compare( $a, $b ) {
		$ecarts = array();

		foreach ( array( 'statut', 'auteur', 'contenu' ) as $cle ) {
			if ( ( $a[ $cle ] ?? null ) !== ( $b[ $cle ] ?? null ) ) {
				$ecarts[] = array(
					'champ'   => $cle,
					'attendu' => $a[ $cle ] ?? null,
					'obtenu'  => $b[ $cle ] ?? null,
				);
			}
		}

		// ⚠️ L'indexation ne se compare que sur un statut indexable.
		// Le chemin de référence du banc ne reproduit pas les crochets par
		// lesquels le thème indexe, et un brouillon n'a de toute façon
		// rien à faire dans une recherche publique. Comparer là serait
		// mesurer une lacune du banc, pas une infidélité de l'écrivain.
		$indexable = 'publish' === ( $a['statut'] ?? '' ) && 'publish' === ( $b['statut'] ?? '' );

		if ( $indexable && ( $a['indexee'] ?? null ) !== ( $b['indexee'] ?? null ) ) {
			$ecarts[] = array(
				'champ'   => 'indexee',
				'attendu' => $a['indexee'] ?? null,
				'obtenu'  => $b['indexee'] ?? null,
			);
		}

		// Le titre porte l'heure de création : deux écritures successives
		// n'ont aucune raison de partager la même seconde. On compare donc
		// sa présence, pas sa valeur.
		if ( ( '' === (string) ( $a['titre'] ?? '' ) ) !== ( '' === (string) ( $b['titre'] ?? '' ) ) ) {
			$ecarts[] = array( 'champ' => 'titre', 'attendu' => $a['titre'] ?? null, 'obtenu' => $b['titre'] ?? null );
		}

		$cles = array_unique( array_merge(
			array_keys( $a['metas'] ?? array() ),
			array_keys( $b['metas'] ?? array() )
		) );
		sort( $cles );

		foreach ( $cles as $cle ) {
			$va = $a['metas'][ $cle ] ?? null;
			$vb = $b['metas'][ $cle ] ?? null;

			if ( (string) $va !== (string) $vb ) {
				$ecarts[] = array( 'champ' => 'meta:' . $cle, 'attendu' => $va, 'obtenu' => $vb );
			}
		}

		foreach ( array_keys( $a['termes'] ?? array() ) as $tx ) {
			$ta = $a['termes'][ $tx ] ?? array();
			$tb = $b['termes'][ $tx ] ?? array();

			if ( $ta !== $tb ) {
				$ecarts[] = array(
					'champ'   => 'taxonomie:' . $tx,
					'attendu' => implode( ',', $ta ),
					'obtenu'  => implode( ',', $tb ),
				);
			}
		}

		return $ecarts;
	}

	/* ------------------------------------------------------------------
	 * Ménage
	 * ---------------------------------------------------------------- */

	/**
	 * Supprime tout ce que le banc a créé.
	 *
	 * Repérés par leur marqueur, jamais par leur titre : une œuvre
	 * légitime pourrait porter le même. Suppression définitive — une
	 * œuvre de contrôle n'a rien à faire dans la corbeille non plus.
	 *
	 * @return int Nombre de contenus supprimés.
	 */
	public static function cleanup() {
		$ids = get_posts(
			array(
				'post_type'      => Africart_Artwork_Repository::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_key'       => self::META_TEST, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		foreach ( $ids as $id ) {
			// Désindexer avant de supprimer : une ligne d'index orpheline
			// ferait apparaître un résultat de recherche sans contenu.
			if ( class_exists( '\Voxel\Post' ) ) {
				$objet = \Voxel\Post::get( $id );
				if ( $objet ) {
					$objet->unindex();
				}
			}

			wp_delete_post( $id, true );
		}

		return count( $ids );
	}

	/** Nombre de contenus de contrôle encore présents. */
	public static function leftovers() {
		return count(
			get_posts(
				array(
					'post_type'      => Africart_Artwork_Repository::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'meta_key'       => self::META_TEST, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
	}
}
