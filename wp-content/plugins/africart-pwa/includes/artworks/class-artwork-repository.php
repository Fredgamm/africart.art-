<?php
/**
 * Couture entre le modèle mobile et Voxel.
 *
 * ⚠️ Ce fichier est le SEUL endroit du plugin qui connaisse Voxel. Tout
 * le reste — routes, file d'attente, écrans — passe par lui. Si le thème
 * change, c'est ici et nulle part ailleurs qu'il faut reprendre.
 *
 * Le principe qui gouverne le module : **envelopper, jamais
 * contourner**. Voxel décide de ce qu'est une œuvre valide, de son
 * statut et de ses droits. Créer un contenu par `wp_insert_post` en
 * écrivant les métas à la main produirait des œuvres réelles en base et
 * absentes du site — ni validées, ni indexées, ni reliées à leur
 * artiste. Le pire des symptômes : celui qui ressemble à un succès.
 *
 * Ce que la lecture du thème a établi :
 *
 *   - le chemin d'écriture est `voxel_ajax_create_post`, qui enchaîne
 *     assainissement, règles de visibilité, logique conditionnelle,
 *     validation, choix du statut, écriture, puis `$field->update()`
 *     champ par champ ;
 *   - les droits ne sont **pas** les capacités WordPress : mesuré,
 *     `current_user_can( 'edit_post' )` rend faux là où
 *     `\Voxel\Post::current_user_can_edit()` rend vrai ;
 *   - le statut d'une nouvelle œuvre n'appartient pas à l'artiste.
 *
 * @package Africart_PWA
 */

defined( 'ABSPATH' ) || exit;

class Africart_Artwork_Repository {

	/** Type de contenu des œuvres, tel que déclaré par le thème. */
	const POST_TYPE = 'oeuvre';

	/** Métadonnée portant l'identifiant local, pour l'idempotence. */
	const META_LOCAL_ID = '_afk_local_id';

	/**
	 * Types de champs Voxel qui ne portent aucune donnée.
	 *
	 * Ce sont des éléments de mise en page du formulaire — étapes,
	 * paragraphes d'explication, titres. Les inclure gonflerait la charge
	 * transmise et, surtout, ferait varier l'empreinte de contenu à
	 * chaque retouche de la mise en page.
	 */
	const TYPES_DECORATIFS = array( 'ui-step', 'ui-html', 'ui-heading', 'ui-image' );

	/** Voxel est-il présent, avec le type de contenu attendu ? */
	public static function is_available() {
		return class_exists( '\Voxel\Post_Type' )
			&& class_exists( '\Voxel\Post' )
			&& null !== self::post_type();
	}

	/** Le type de contenu Voxel, ou null. */
	private static function post_type() {
		if ( ! class_exists( '\Voxel\Post_Type' ) ) {
			return null;
		}

		return \Voxel\Post_Type::get( self::POST_TYPE );
	}

	/* ------------------------------------------------------------------
	 * Champs
	 * ---------------------------------------------------------------- */

	/**
	 * Champs porteurs de données, dans l'ordre du formulaire.
	 *
	 * La liste vient de Voxel, jamais d'une table écrite en dur : les
	 * clés y sont opaques (`text-14`, `texteditor-4`) et changent au
	 * moindre renommage dans l'éditeur du thème.
	 *
	 * @return array<string,array{key:string,type:string,label:string,required:bool}>
	 */
	public static function fields() {
		$type = self::post_type();

		if ( ! $type ) {
			return array();
		}

		$sortie = array();

		// ⚠️ Une étape réservée emporte ses champs.
		//
		// Écarter la seule étape ne suffisait pas : ses champs restaient
		// dans le schéma, et le formulaire, ne trouvant plus l'étape
		// annoncée, les regroupait sous un titre générique. « Raison du
		// rejet » réapparaissait ainsi à l'artiste, sous « Informations ».
		$etapes_visibles = array();

		foreach ( self::steps() as $etape ) {
			$etapes_visibles[] = $etape['key'];
		}

		foreach ( $type->get_fields() as $champ ) {
			if ( in_array( $champ->get_type(), self::TYPES_DECORATIFS, true ) ) {
				continue;
			}

			if ( ! self::visible_pour_lui( $champ ) ) {
				continue;
			}

			$etape = method_exists( $champ, 'get_step' ) ? (string) $champ->get_step() : '';

			if ( '' !== $etape && ! in_array( $etape, $etapes_visibles, true ) ) {
				continue;
			}

			$sortie[ $champ->get_key() ] = array(
				'key'      => $champ->get_key(),
				'type'     => $champ->get_type(),
				'label'    => (string) $champ->get_label(),
				'required' => method_exists( $champ, 'is_required' ) ? (bool) $champ->is_required() : false,
				// Les valeurs proposées, pour les champs qui en ont une
				// liste fermée. Sans elles, le formulaire ne peut que
				// laisser saisir du texte libre — et une valeur hors liste
				// est refusée à l'écriture, sans que l'artiste comprenne.
				'choix'    => self::choix_de( $champ ),
				// À quelles conditions ce champ s'affiche. Sans elles, le
				// formulaire réclame « Dénomination sociale » à un
				// particulier — et son dépôt échoue sur un champ qui
				// n'aurait jamais dû lui être présenté.
				'conditions' => self::conditions_de( $champ ),
				// L'étape d'appartenance. Sans elle, trente-six champs
				// forment une liste interminable que personne ne remplit
				// jusqu'au bout — le formulaire du site les répartit en
				// cinq écrans, et le nôtre doit pouvoir en faire autant.
				'step'     => method_exists( $champ, 'get_step' ) ? (string) $champ->get_step() : '',
				// De quoi rendre un champ **correct** et non seulement
				// présent : l'aide que le site affiche sous le champ, son
				// texte d'invite, ses bornes de longueur. Sans elles, notre
				// formulaire pose les mêmes questions que celui du thème,
				// mais sans rien de ce qui aide à y répondre — et laisse
				// saisir des valeurs que l'écriture refusera.
				'aide'        => (string) self::modele( $champ, 'description' ),
				'invite'      => (string) self::modele( $champ, 'placeholder' ),
				'maxi'        => self::entier( self::modele( $champ, 'maxlength' ) ),
				'mini'        => self::entier( self::modele( $champ, 'minlength' ) ),
			);
		}

		return $sortie;
	}

	/**
	 * Ce champ est-il destiné à la personne connectée ?
	 *
	 * ⚠️ Le thème sait masquer un champ selon le rôle, et il s'en sert :
	 * l'étape « Admin » et sa « Raison du rejet » ne regardent pas
	 * l'artiste. Notre schéma les rendait quand même — constaté sur le
	 * site — et un artiste pouvait renseigner le motif de son propre
	 * rejet, qui s'affiche ensuite dans « mes œuvres ».
	 *
	 * ⚠️ Le doute profite au **masquage**, ici, contrairement aux
	 * conditions d'affichage. Un champ superflu affiché gêne ; un champ
	 * réservé affiché est une fuite, et la valeur qu'il porte serait
	 * écrite au nom de quelqu'un qui n'y avait pas droit.
	 *
	 * ⚠️ Pour le SCHÉMA seulement — c'est-à-dire pour décider ce qu'on
	 * affiche. L'écriture, elle, passe par `ecriture_autorisee()`.
	 *
	 * La différence n'est pas cosmétique : cette méthode interroge
	 * `passes_visibility_rules()`, dont la réponse **dépend du contexte
	 * d'exécution**. Dans une requête REST, elle a répondu « non » pour le
	 * champ titre, qui ne porte pourtant aucune règle. Le titre était donc
	 * silencieusement écarté de chaque enregistrement : l'artiste modifiait
	 * son œuvre, le site répondait 200, et le titre restait l'ancien. Sur
	 * une création, l'œuvre naissait carrément sans titre. Constaté sur le
	 * serveur, en sondant la même fonction dans les deux contextes.
	 *
	 * @param object $champ Champ Voxel.
	 * @return bool
	 */
	public static function visible_pour_lui( $champ ) {
		// ⚠️ L'étape d'abord.
		//
		// « Raison du rejet » et « Location » n'ont **aucune règle à
		// elles** : elles ne sont protégées que par leur appartenance à
		// l'étape « Admin ». Sans ce report, le filtrage laissait passer
		// exactement les deux champs qu'il était censé retenir — constaté
		// au banc, sur le serveur.
		$etape = method_exists( $champ, 'get_step' ) ? (string) $champ->get_step() : '';

		if ( '' !== $etape && ! self::etape_visible( $etape ) ) {
			return false;
		}

		// ⚠️ Réservé à la modération : écarté **pour tout le monde**, y
		// compris pour un administrateur.
		//
		// Ce module est le parcours de dépôt d'un artiste, pas un outil de
		// modération. L'étape « Admin » et sa « Raison du rejet »
		// appartiennent à l'écran d'administration, où le contexte et
		// l'historique existent. Les proposer ici les ferait remplir au
		// milieu d'un dépôt, sans rien de ce qui permet d'en juger.
		if ( self::reserve_a_la_moderation( $champ ) ) {
			return false;
		}

		return self::regles_passees( $champ );
	}

	/**
	 * Ce champ peut-il être écrit par la personne connectée ?
	 *
	 * ⚠️ Volontairement plus étroit que `visible_pour_lui()`, et
	 * **déterministe** : uniquement les règles de rôle inscrites dans la
	 * configuration du thème, lues telles quelles. Aucune évaluation
	 * dépendant du contexte.
	 *
	 * Le pouvoir de refuser une écriture est le plus lourd que porte ce
	 * module : il ne doit reposer que sur ce qui répond pareil partout.
	 * Une règle qui change d'avis selon l'endroit d'où on l'interroge
	 * n'écarte pas ce qu'il faut — elle écarte au hasard, et en silence.
	 *
	 * La propriété de sécurité tient toujours : « Raison du rejet » reste
	 * refusée à un artiste, parce que son étape porte une règle de rôle
	 * inscrite en dur dans la configuration.
	 *
	 * @param object $champ Champ Voxel.
	 * @return bool
	 */
	public static function ecriture_autorisee( $champ ) {
		if ( self::reserve_a_la_moderation( $champ ) ) {
			return false;
		}

		$etape = method_exists( $champ, 'get_step' ) ? (string) $champ->get_step() : '';

		if ( '' === $etape ) {
			return true;
		}

		$type = self::post_type();

		if ( ! $type ) {
			return true;
		}

		foreach ( $type->get_fields() as $autre ) {
			if ( 'ui-step' === $autre->get_type() && $autre->get_key() === $etape ) {
				return ! self::reserve_a_la_moderation( $autre );
			}
		}

		return true;
	}

	/**
	 * Ce champ est-il réservé à la modération ?
	 *
	 * ⚠️ « Réservé à un rôle » ne suffit pas comme critère, et s'y fier
	 * a coûté un champ obligatoire : « Nom de l'artiste » est réservé au
	 * rôle `subscriber` — celui de l'artiste. L'écarter au motif qu'il
	 * porte une règle de rôle retirait du parcours de dépôt un champ
	 * exigé par le dépôt lui-même. Constaté au banc.
	 *
	 * Le critère est donc la **capacité**, pas le nom du rôle : un rôle
	 * qui peut modifier le contenu d'autrui est un rôle de modération, et
	 * ce qui lui est réservé n'a rien à faire dans un parcours de dépôt.
	 * On exige que **tous** les rôles nommés le soient : au moindre rôle
	 * ordinaire dans la règle, le champ reste.
	 *
	 * Lu dans `visibility_rules`, par la méthode publique que le thème
	 * emploie lui-même pour bâtir la configuration de son formulaire.
	 *
	 * @param object $champ Champ Voxel.
	 * @return bool
	 */
	private static function reserve_a_la_moderation( $champ ) {
		if ( ! method_exists( $champ, 'get_prop' ) ) {
			return false;
		}

		try {
			$groupes = $champ->get_prop( 'visibility_rules' );
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( ! is_array( $groupes ) ) {
			return false;
		}

		$roles = array();

		foreach ( $groupes as $groupe ) {
			foreach ( (array) $groupe as $regle ) {
				if ( is_array( $regle ) && isset( $regle['type'] ) && 0 === strpos( (string) $regle['type'], 'user:role' ) ) {
					$roles[] = (string) ( isset( $regle['value'] ) ? $regle['value'] : '' );
				}
			}
		}

		if ( ! $roles ) {
			return false;
		}

		foreach ( $roles as $slug ) {
			if ( ! self::role_de_moderation( $slug ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ce rôle peut-il toucher au contenu des autres ?
	 *
	 * @param string $slug Identifiant du rôle.
	 * @return bool
	 */
	private static function role_de_moderation( $slug ) {
		if ( '' === $slug ) {
			return false;
		}

		$role = get_role( $slug );

		return $role ? (bool) $role->has_cap( 'edit_others_posts' ) : false;
	}

	/**
	 * Cette étape est-elle destinée à la personne connectée ?
	 *
	 * Retenu le temps de la requête : la question se pose une fois par
	 * champ, et il y en a trente-six.
	 *
	 * @param string $cle Clé de l'étape.
	 * @return bool
	 */
	private static function etape_visible( $cle ) {
		// ⚠️ Retenu **par personne**, et pas seulement par étape.
		//
		// Une requête n'a qu'un utilisateur, mais WP-CLI, une tâche
		// planifiée ou un lot REST en changent en cours de route. Le
		// verdict rendu pour un artiste était alors resservi à
		// l'administrateur suivant, à qui on refusait son propre champ —
		// constaté au banc, sur le serveur.
		static $connues = array();

		$qui = get_current_user_id();

		if ( isset( $connues[ $qui ] ) && array_key_exists( $cle, $connues[ $qui ] ) ) {
			return $connues[ $qui ][ $cle ];
		}

		if ( ! isset( $connues[ $qui ] ) ) {
			$connues[ $qui ] = array();
		}

		$connues[ $qui ][ $cle ] = true;
		$type                    = self::post_type();

		if ( ! $type ) {
			return true;
		}

		foreach ( $type->get_fields() as $champ ) {
			if ( 'ui-step' !== $champ->get_type() || $champ->get_key() !== $cle ) {
				continue;
			}

			$connues[ $qui ][ $cle ] = ! self::reserve_a_la_moderation( $champ )
				&& self::regles_passees( $champ );
			break;
		}

		return $connues[ $qui ][ $cle ];
	}

	/**
	 * Les règles propres d'un champ, sans considérer son étape.
	 *
	 * @param object $champ Champ Voxel.
	 * @return bool
	 */
	private static function regles_passees( $champ ) {
		if ( method_exists( $champ, 'is_hidden' ) ) {
			try {
				if ( $champ->is_hidden() ) {
					return false;
				}
			} catch ( \Throwable $e ) { /* on continue avec l'autre règle */ }
		}

		if ( method_exists( $champ, 'passes_visibility_rules' ) ) {
			try {
				return (bool) $champ->passes_visibility_rules();
			} catch ( \Throwable $e ) {
				// Règle illisible : on masque. Voir plus haut.
				return false;
			}
		}

		return true;
	}

	/**
	 * Lit un modèle de champ, sans jamais faire échouer le schéma.
	 *
	 * ⚠️ Tous les champs n'ont pas tous les modèles — un champ de
	 * taxonomie n'a pas de `maxlength`. Voxel rend `null`, et c'est très
	 * bien ; mais une version future pourrait lever une exception, et le
	 * schéma entier tomberait avec elle. Le formulaire hors connexion
	 * serait alors indisponible pour un texte d'aide manquant.
	 *
	 * @param object $champ  Champ Voxel.
	 * @param string $modele Nom du modèle.
	 * @return mixed
	 */
	private static function modele( $champ, $modele ) {
		if ( ! method_exists( $champ, 'get_model_value' ) ) {
			return null;
		}

		try {
			return $champ->get_model_value( $modele );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Une borne de longueur, ou zéro si elle n'est pas posée.
	 *
	 * Zéro plutôt que `null` : le formulaire teste « si borne », et une
	 * borne à zéro n'a de toute façon aucun sens.
	 *
	 * @param mixed $valeur Valeur brute.
	 * @return int
	 */
	private static function entier( $valeur ) {
		return is_numeric( $valeur ) ? absint( $valeur ) : 0;
	}

	/**
	 * Valeurs proposées par un champ à liste fermée.
	 *
	 * ⚠️ Lues par `get_model_value()`, qui est public. Les propriétés
	 * internes du champ sont protégées : y accéder par réflexion
	 * marcherait aujourd'hui et casserait à la première mise à jour du
	 * thème, sans prévenir.
	 *
	 * @param object $champ Champ Voxel.
	 * @return array<int,array{value:string,label:string}>
	 */
	private static function choix_de( $champ ) {
		if ( ! method_exists( $champ, 'get_model_value' ) ) {
			return array();
		}

		$type = $champ->get_type();

		if ( 'select' === $type ) {
			$brut   = $champ->get_model_value( 'choices' );
			$sortie = array();

			foreach ( (array) $brut as $c ) {
				if ( ! isset( $c['value'] ) ) {
					continue;
				}
				$sortie[] = array(
					'value' => (string) $c['value'],
					'label' => (string) ( $c['label'] ?? $c['value'] ),
				);
			}

			return $sortie;
		}

		if ( 'image' === $type || 'file' === $type ) {
			// Combien de fichiers ce champ accepte. Le thème ne l'expose
			// pas toujours : à défaut, on n'impose rien et on laisse son
			// assainissement trancher — c'est lui qui fait autorité.
			$max = $champ->get_model_value( 'max_count' );

			return array( array( 'value' => 'max', 'label' => (string) (int) $max ) );
		}

		if ( 'taxonomy' === $type ) {
			$taxonomie = (string) $champ->get_model_value( 'taxonomy' );

			if ( '' === $taxonomie || ! taxonomy_exists( $taxonomie ) ) {
				return array();
			}

			$termes = get_terms(
				array(
					'taxonomy'   => $taxonomie,
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);

			if ( is_wp_error( $termes ) ) {
				return array();
			}

			$sortie = array();

			foreach ( $termes as $t ) {
				// La valeur transportée est le slug — c'est ce que
				// l'écriture attend, et ce que la lecture rend.
				$sortie[] = array( 'value' => $t->slug, 'label' => $t->name );
			}

			return $sortie;
		}

		return array();
	}

	/**
	 * Types de conditions que le formulaire sait évaluer.
	 *
	 * ⚠️ Le thème en propose vingt-cinq. Exporter celles qu'on ne sait
	 * pas rejouer serait pire que de les ignorer : le formulaire les
	 * évaluerait de travers et masquerait un champ obligatoire, dont
	 * l'absence ferait échouer le dépôt sans cause visible.
	 *
	 * Une condition non reconnue rend le champ **toujours visible** :
	 * montrer un champ inutile gêne, en masquer un obligatoire bloque.
	 */
	const CONDITIONS_CONNUES = array(
		'text:contains', 'text:equals', 'text:not_equals', 'text:empty', 'text:not_empty',
		'taxonomy:contains', 'taxonomy:not_contains', 'taxonomy:empty', 'taxonomy:not_empty',
		'number:equals', 'number:not_equals', 'number:gt', 'number:gte', 'number:lt', 'number:lte',
		'number:empty', 'number:not_empty',
		'switcher:checked', 'switcher:unchecked',
	);

	/**
	 * Conditions d'affichage d'un champ.
	 *
	 * La sémantique est celle du thème, relevée dans
	 * `passes_conditional_logic()` : **OU entre les groupes, ET à
	 * l'intérieur d'un groupe**. L'inverser masquerait exactement les
	 * champs qu'il faut montrer.
	 *
	 * @param object $champ Champ Voxel.
	 * @return array|null
	 */
	private static function conditions_de( $champ ) {
		if ( ! method_exists( $champ, 'get_conditions' ) ) {
			return null;
		}

		if ( method_exists( $champ, 'get_prop' ) && ! $champ->get_prop( 'enable-conditions' ) ) {
			return null;
		}

		$groupes = $champ->get_conditions();

		if ( empty( $groupes ) ) {
			return null;
		}

		$sortie   = array();
		$complete = true;

		foreach ( $groupes as $groupe ) {
			$regles = array();

			foreach ( (array) $groupe as $condition ) {
				if ( ! method_exists( $condition, 'get_type' ) ) {
					$complete = false;
					continue;
				}

				$type = $condition->get_type();

				if ( ! in_array( $type, self::CONDITIONS_CONNUES, true ) ) {
					$complete = false;
					continue;
				}

				$props = method_exists( $condition, 'get_props' ) ? (array) $condition->get_props() : array();

				$regles[] = array(
					'source' => (string) $condition->get_source(),
					'type'   => $type,
					'valeur' => isset( $props['value'] ) ? $props['value'] : null,
				);
			}

			if ( $regles ) {
				$sortie[] = $regles;
			}
		}

		if ( ! $sortie ) {
			return null;
		}

		return array(
			// « show » : le champ apparaît quand les conditions passent.
			// « hide » : il disparaît alors.
			'comportement' => method_exists( $champ, 'get_prop' )
				? (string) ( $champ->get_prop( 'conditions_behavior' ) ?: 'show' )
				: 'show',
			'groupes'      => $sortie,
			// Faux si une condition n'a pas pu être exportée : le
			// formulaire saura qu'il ne peut pas juger, et montrera.
			'complet'      => $complete,
		);
	}

	/**
	 * Étapes du formulaire, dans l'ordre.
	 *
	 * Les étapes sont des champs comme les autres chez Voxel — de type
	 * `ui-step` — mais elles ne portent aucune donnée. Elles sont donc
	 * écartées de `fields()` et rendues ici, séparément : la structure
	 * n'est pas la donnée.
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public static function steps() {
		$type = self::post_type();

		if ( ! $type ) {
			return array();
		}

		$etapes = array();

		foreach ( $type->get_fields() as $champ ) {
			if ( 'ui-step' !== $champ->get_type() ) {
				continue;
			}

			// ⚠️ Une étape réservée est écartée **avec** ses champs. Le
			// thème en a une, « Admin », qui porte « Raison du rejet » :
			// notre formulaire la présentait à l'artiste, qui pouvait donc
			// écrire lui-même le motif de son propre refus.
			if ( ! self::visible_pour_lui( $champ ) ) {
				continue;
			}

			$etapes[] = array(
				'key'   => $champ->get_key(),
				'label' => (string) $champ->get_label(),
			);
		}

		return $etapes;
	}

	/**
	 * Règles de soumission du thème.
	 *
	 * Elles ne sont pas décoratives : elles décident du statut d'une
	 * œuvre déposée et de la possibilité même de la supprimer.
	 * L'application doit les lire, pas les supposer.
	 *
	 * @return array
	 */
	public static function submission_rules() {
		$type = self::post_type();

		if ( ! $type ) {
			return array();
		}

		return array(
			// Les artistes peuvent-ils déposer une œuvre ?
			'enabled'       => (bool) $type->get_setting( 'submissions.enabled' ),
			// Statut d'une NOUVELLE œuvre. « pending » signifie modération :
			// la publication n'appartient pas à l'artiste.
			'status'        => (string) $type->get_setting( 'submissions.status' ),
			// Statut après modification d'une œuvre déjà publiée.
			'updateStatus'  => (string) $type->get_setting( 'submissions.update_status' ),
			// Faux sur ce site : l'artiste ne peut pas supprimer ses œuvres.
			'deletable'     => (bool) $type->get_setting( 'submissions.deletable' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Droits
	 * ---------------------------------------------------------------- */

	/**
	 * L'utilisateur courant peut-il déposer une œuvre ?
	 *
	 * ⚠️ Jamais `current_user_can()`. Le type de contenu déclare
	 * `capability_type: page`, et un artiste — simple abonné — n'a aucune
	 * capacité WordPress sur les pages. Seul Voxel sait répondre.
	 */
	public static function can_create() {
		if ( ! self::is_available() || ! is_user_logged_in() ) {
			return false;
		}

		$utilisateur = \Voxel\get_current_user();

		return $utilisateur && $utilisateur->can_create_post( self::POST_TYPE );
	}

	/**
	 * L'utilisateur courant peut-il modifier cette œuvre ?
	 *
	 * Mesuré sur une œuvre réelle : `current_user_can( 'edit_post' )` rend
	 * **faux** là où la vérification de Voxel rend **vrai**. S'en remettre
	 * à WordPress fermerait la porte à tous les artistes.
	 *
	 * @param int $post_id Identifiant de l'œuvre.
	 * @return bool
	 */
	public static function can_edit( $post_id ) {
		if ( ! self::is_available() || ! is_user_logged_in() ) {
			return false;
		}

		return (bool) \Voxel\Post::current_user_can_edit( (int) $post_id );
	}

	/* ------------------------------------------------------------------
	 * Lecture
	 * ---------------------------------------------------------------- */

	/**
	 * Œuvres appartenant à un artiste.
	 *
	 * Tous les statuts sont rendus, `pending` compris : l'artiste doit
	 * voir ce qui attend une validation, sans quoi il croirait son dépôt
	 * perdu.
	 *
	 * @param int    $user_id Artiste.
	 * @param string $depuis  Date ISO 8601 UTC ; seules les œuvres
	 *                        modifiées après sont rendues.
	 * @return array
	 */
	public static function list_for_user( $user_id, $depuis = '' ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'author'         => (int) $user_id,
			'post_status'    => array( 'publish', 'pending', 'draft', 'unpublished', 'rejected' ),
			'posts_per_page' => 200,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( '' !== $depuis ) {
			$args['date_query'] = array(
				array(
					'column'    => 'post_modified_gmt',
					'after'     => $depuis,
					'inclusive' => false,
				),
			);
		}

		$oeuvres = array();

		foreach ( get_posts( $args ) as $post ) {
			$oeuvres[] = self::to_payload( $post, false );
		}

		return $oeuvres;
	}

	/**
	 * Une œuvre, avec ses valeurs de champs.
	 *
	 * @param int $post_id Identifiant.
	 * @return array|null
	 */
	public static function get( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return self::to_payload( $post, true );
	}

	/**
	 * Met une œuvre en forme pour l'application.
	 *
	 * La liste n'embarque pas les valeurs : deux cents œuvres avec leurs
	 * quarante champs chacune feraient une réponse que le réseau mobile
	 * ne pardonnerait pas. Elles se chargent à l'ouverture d'une fiche.
	 *
	 * @param WP_Post $post    Contenu.
	 * @param bool    $valeurs Inclure les valeurs des champs.
	 * @return array
	 */
	private static function to_payload( $post, $valeurs ) {
		$charge = array(
			'wpPostId'          => (int) $post->ID,
			'titre'             => get_the_title( $post ),
			'publicationStatus' => self::statut_metier( $post->post_status ),
			'wpStatus'          => $post->post_status,
			'lien'              => get_permalink( $post ),
			'vignette'          => (string) get_the_post_thumbnail_url( $post, 'medium' ),
			'createdAtServer'   => gmdate( 'c', strtotime( $post->post_date_gmt . ' UTC' ) ),
			'updatedAtServer'   => gmdate( 'c', strtotime( $post->post_modified_gmt . ' UTC' ) ),
			// L'empreinte départage les vraies modifications des simples
			// touches de date — voir hash_of().
			'hash'              => self::hash_of( $post ),
			'localId'           => (string) get_post_meta( $post->ID, self::META_LOCAL_ID, true ),
		);

		if ( $valeurs ) {
			$charge['champs'] = self::values_of( $post );
			$charge['medias'] = self::medias_of( $charge['champs'] );
		}

		return $charge;
	}

	/**
	 * Où trouver les visuels d'une œuvre.
	 *
	 * ⚠️ Les champs image ne portent que des identifiants d'attachement.
	 * C'est suffisant sur l'appareil qui a pris la photo — il en détient
	 * encore les octets — et parfaitement inutile ailleurs : ouvert sur un
	 * second téléphone, ou après un nettoyage du navigateur, l'aperçu
	 * n'avait aucune adresse à interroger et l'œuvre s'affichait sans
	 * visuel. Constaté en vidant la réserve locale pour simuler un nouvel
	 * appareil.
	 *
	 * Deux adresses par visuel, et les deux servent : `apercu` est la
	 * version réduite, celle qu'on met en cache pour la consultation hors
	 * connexion ; `url` est l'originale, pour qui veut la voir en grand.
	 *
	 * ⚠️ Seuls les champs déclarés image ou fichier sont parcourus. Un
	 * balayage de toutes les valeurs numériques prendrait « 2050 », année
	 * de création, pour l'attachement 2050 — qui existe.
	 *
	 * @param array $valeurs Valeurs de l'œuvre, telles que transportées.
	 * @return array<string,array>
	 */
	private static function medias_of( $valeurs ) {
		$sortie = array();
		$vus    = 0;

		foreach ( self::fields() as $cle => $champ ) {
			if ( ! in_array( $champ['type'], array( 'image', 'file' ), true ) ) {
				continue;
			}

			foreach ( (array) ( $valeurs[ $cle ] ?? array() ) as $id ) {
				$id = (int) $id;

				if ( ! $id || isset( $sortie[ (string) $id ] ) || $vus >= 20 ) {
					continue;
				}

				if ( 'attachment' !== get_post_type( $id ) ) {
					continue;
				}

				$vus++;

				$sortie[ (string) $id ] = array(
					'url'    => (string) wp_get_attachment_url( $id ),
					'apercu' => self::taille_reduite( $id ),
					'nom'    => (string) get_the_title( $id ),
					'mime'   => (string) get_post_mime_type( $id ),
				);
			}
		}

		return $sortie;
	}

	/**
	 * La plus grande version réduite d'un visuel, ou rien.
	 *
	 * ⚠️ `wp_get_attachment_image_url( $id, 'large' )` rend l'ORIGINALE
	 * quand la taille demandée n'existe pas, et ne le dit pas. Sur ce
	 * site, un visuel `webp` de 2026 n'avait aucune déclinaison : la
	 * consultation hors connexion aurait rapatrié le fichier d'origine —
	 * plusieurs mégaoctets sur un forfait mobile, pour une image affichée
	 * dans une fiche de sept cents pixels. Constaté sur l'œuvre 5463.
	 *
	 * On demande donc explicitement une déclinaison existante, de la plus
	 * confortable à la plus modeste. Si le site n'en a produit aucune, on
	 * rend une chaîne vide plutôt que l'originale : l'application affichera
	 * le visuel en direct sans le mettre en cache, ce qui est exactement le
	 * bon arbitrage — voir sans réseau ne vaut pas d'épuiser le forfait.
	 *
	 * @param int $id Attachement.
	 * @return string
	 */
	private static function taille_reduite( $id ) {
		if ( ! wp_attachment_is_image( $id ) ) {
			return '';
		}

		foreach ( array( 'large', 'medium_large', 'medium' ) as $taille ) {
			$vue = image_get_intermediate_size( $id, $taille );

			if ( ! empty( $vue['url'] ) ) {
				return (string) $vue['url'];
			}
		}

		// ⚠️ Sauf si l'originale est déjà légère.
		//
		// C'est le POIDS qui décide, pas les dimensions. J'avais d'abord
		// écrit la règle sur la largeur ; sur ce site, un visuel `webp` de
		// treize kilooctets porte `0x0` en base — WordPress n'a rien
		// enregistré — et se voyait refuser pour cette seule raison. Un
		// fichier plus léger qu'une vignette était écarté du cache au nom
		// de l'économie de forfait. Constaté sur l'œuvre 5463.
		$meta  = wp_get_attachment_metadata( $id );
		$poids = (int) ( $meta['filesize'] ?? 0 );

		if ( ! $poids ) {
			$fichier = get_attached_file( $id );
			$poids   = $fichier && file_exists( $fichier ) ? (int) filesize( $fichier ) : 0;
		}

		if ( $poids && $poids <= 600 * 1024 ) {
			return (string) wp_get_attachment_url( $id );
		}

		// Lourde et sans déclinaison : l'application l'affichera en direct
		// sans la garder. Voir sans réseau ne vaut pas d'épuiser le forfait.
		return '';
	}

	/**
	 * Valeurs des champs porteurs de données.
	 *
	 * Lues par Voxel, jamais par `get_post_meta` : un champ image rend un
	 * identifiant de média, un champ taxonomie rend des termes, et leur
	 * représentation en base n'est pas leur valeur.
	 *
	 * @param WP_Post $post Contenu.
	 * @return array<string,mixed>
	 */
	private static function values_of( $post ) {
		$type = self::post_type();

		if ( ! $type ) {
			return array();
		}

		$objet   = \Voxel\Post::get( $post->ID );
		$valeurs = array();

		foreach ( $type->get_fields() as $champ ) {
			if ( in_array( $champ->get_type(), self::TYPES_DECORATIFS, true ) ) {
				continue;
			}

			$champ->set_post( $objet );

			try {
				$valeurs[ $champ->get_key() ] = self::normalise( $champ->get_value() );
			} catch ( \Throwable $e ) {
				// Un champ qui refuse de se lire ne doit pas emporter
				// toute l'œuvre : on le signale par son absence de valeur.
				$valeurs[ $champ->get_key() ] = null;
			}
		}

		return $valeurs;
	}

	/**
	 * Ramène une valeur lue à sa forme de transport.
	 *
	 * ⚠️ Chez Voxel, **lire et écrire n'ont pas la même forme**. Un champ
	 * de taxonomie se lit en objets `\Voxel\Term` et s'écrit en
	 * identifiants de termes. Rendre l'objet tel quel produisait deux
	 * défauts d'un coup : une réponse REST remplie de structures internes
	 * du thème, et une valeur que le chemin d'écriture refusait —
	 * « Object of class Voxel\Term could not be converted to string ».
	 *
	 * Le banc de parité l'a révélé avant même que l'écrivain n'existe.
	 * C'est précisément ce pour quoi il a été construit en premier.
	 *
	 * La forme de transport est donc définie **ici et nulle part
	 * ailleurs** : ce que l'API rend est exactement ce que l'écriture
	 * accepte.
	 *
	 * @param mixed $valeur Valeur telle que le thème la rend.
	 * @return mixed Valeur transportable, et réinjectable.
	 */
	private static function normalise( $valeur ) {
		if ( is_object( $valeur ) && method_exists( $valeur, 'get_slug' ) ) {
			return $valeur->get_slug();
		}

		if ( is_array( $valeur ) ) {
			return array_map( array( __CLASS__, 'normalise' ), $valeur );
		}

		return $valeur;
	}

	/* ------------------------------------------------------------------
	 * Empreinte
	 * ---------------------------------------------------------------- */

	/**
	 * Empreinte du contenu modifiable depuis l'application.
	 *
	 * ⚠️ Pourquoi une empreinte alors que WordPress date déjà chaque
	 * modification : `post_modified` bouge à **chaque** enregistrement, y
	 * compris ceux d'un autre plugin — une réindexation, une tâche
	 * planifiée, un recalcul. Comparer les seules dates signalerait des
	 * conflits là où pas un caractère n'a changé.
	 *
	 * L'artiste apprendrait alors à choisir « garder ma version » sans
	 * lire, et le jour du vrai conflit il écraserait sans le savoir.
	 *
	 * L'empreinte ne porte que sur les champs que le formulaire mobile
	 * peut modifier : une métadonnée technique touchée par un tiers ne
	 * provoque donc aucun conflit.
	 *
	 * @param WP_Post $post Contenu.
	 * @return string
	 */
	public static function hash_of( $post ) {
		$valeurs = self::values_of( $post );

		ksort( $valeurs );

		return substr( hash( 'sha256', (string) wp_json_encode( $valeurs ) ), 0, 16 );
	}

	/* ------------------------------------------------------------------
	 * Correspondance des statuts
	 * ---------------------------------------------------------------- */

	/**
	 * Statut métier correspondant à un statut WordPress.
	 *
	 * ⚠️ `pending` n'est pas un brouillon. C'est une œuvre **déposée**,
	 * complète, qui attend une validation. Les confondre ferait croire à
	 * l'artiste que son dépôt n'est pas parti — et l'inviterait à
	 * recommencer.
	 *
	 * @param string $wp_status Statut WordPress.
	 * @return string
	 */
	public static function statut_metier( $wp_status ) {
		$table = array(
			'draft'       => 'DRAFT',
			'pending'     => 'REVIEW',
			'publish'     => 'PUBLISHED',
			'unpublished' => 'UNPUBLISHED',
			'rejected'    => 'REJECTED',
			'trash'       => 'DELETED',
		);

		return isset( $table[ $wp_status ] ) ? $table[ $wp_status ] : 'UNKNOWN';
	}
}
