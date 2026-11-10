/**
 * « Mes œuvres », rendu par nous — en ligne comme hors connexion.
 *
 * ⚠️ Jusqu'ici, la liste des œuvres n'existait qu'en secours : le thème
 * rendait la page, et ce module ne prenait la main **que** sans réseau.
 * Deux expériences pour un même écran, dont une que personne ne voit
 * jamais en temps normal — donc que personne ne teste, et qui diverge.
 *
 * Poser `[africart_mes_oeuvres]` est la demande explicite d'avoir notre
 * liste ici, toujours. Elle lit la copie locale, la rafraîchit quand le
 * réseau le permet, et n'échoue jamais faute de réseau.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_OEUVRES || {};
	var DB  = window.afkOeuvresDB;

	if ( ! DB ) {
		return;
	}

	var T = CFG.textes || {};

	/**
	 * Une phrase visible, surchargeable depuis les réglages.
	 *
	 * ⚠️ Tout le reste du module tire ses textes de l'administration, et
	 * le site est trilingue — les drapeaux sont dans son menu. Ces
	 * phrases-ci étaient écrites en dur : un visiteur anglophone aurait
	 * lu « Hors connexion » au milieu de son anglais, sans que personne
	 * puisse le corriger.
	 *
	 * Elles restent en français **par défaut**, mais passent désormais
	 * par un seul point de lecture : poser `art_txt_<clé>` dans les
	 * réglages suffit à en changer une, sans toucher au code.
	 */
	function mot( cle, defaut ) {
		var t = ( CFG.textes || {} )[ 'txt_' + cle ];

		return t || defaut;
	}


	/** Le module de cache, qui sait déjà dessiner une carte. */
	function socle() {
		return window.afkOeuvres || null;
	}

	/* ------------------------------------------------------------------
	 * Aperçu d'une œuvre
	 * ---------------------------------------------------------------- */

	/** Le schéma, pour nommer les champs de la fiche. Lu par le socle. */
	function schema() {
		return DB.schema();
	}

	/** Types dont la valeur est un visuel, montré comme tel. */
	var VISUELS = [ 'image', 'file' ];

	/**
	 * L'image à montrer pour un identifiant de média.
	 *
	 * ⚠️ Ce qui est sur l'appareil passe avant ce qui est sur le réseau :
	 * l'adresse du serveur ne mène nulle part sans connexion, et c'est
	 * précisément là qu'on a besoin de voir ses propres photos.
	 */
	async function apercuDe( id, ephemeres, table ) {
		var trace = await DB.lire( 'medias', id );

		// ⚠️ IndexedDB distingue 42 de « 42 ». Les visuels envoyés depuis
		// cet appareil sont rangés sous le nombre rendu par le site, ceux
		// d'une œuvre reçue sous ce que porte le champ. Chercher les deux
		// coûte une lecture et évite une image manquante sans explication.
		if ( ! trace && 'number' === typeof id ) {
			trace = await DB.lire( 'medias', String( id ) );
		} else if ( ! trace && /^[0-9]+$/.test( String( id ) ) ) {
			trace = await DB.lire( 'medias', parseInt( id, 10 ) );
		}

		var local = trace && ( trace.blob || trace.apercu );

		if ( local ) {
			var u = window.URL.createObjectURL( local );
			ephemeres.push( u );
			return u;
		}

		if ( trace && trace.url ) {
			return trace.url;
		}

		// ⚠️ Rien en réserve : l'adresse que porte l'œuvre elle-même.
		//
		// Sans cela, une œuvre reçue d'AFRICART s'affichait sans visuel
		// tant que le rapatriement n'avait pas eu lieu — c'est-à-dire au
		// premier coup d'œil, celui qui compte.
		var ou = ( table || {} )[ String( id ) ];

		return ( ou && ( ou.apercu || ou.url ) ) || '';
	}

	function texteDe( valeur ) {
		if ( Array.isArray( valeur ) ) {
			return valeur.join( ', ' );
		}

		return null === valeur || undefined === valeur ? '' : String( valeur );
	}

	/**
	 * Ouvre la fiche d'une œuvre, telle qu'elle est sur cet appareil.
	 *
	 * ⚠️ Une fiche **locale**, et non un lien vers la page publique. Sans
	 * réseau, ce lien mène à l'écran hors connexion : l'artiste croirait
	 * son œuvre perdue alors qu'elle est là, complète, sous ses yeux.
	 */
	async function ouvrirFiche( o ) {
		var ephemeres = [];

		// Consulter, c'est se servir : l'œuvre remonte en haut de la pile
		// et ne sera pas la première coupée.
		if ( o.wpPostId ) {
			DB.toucher( o.wpPostId );
		}

		var voile = document.createElement( 'div' );
		voile.className = 'afk-fiche';
		voile.setAttribute( 'role', 'dialog' );
		voile.setAttribute( 'aria-modal', 'true' );
		voile.setAttribute( 'aria-label', o.titre || 'Aperçu de l’œuvre' );

		var boite = document.createElement( 'div' );
		boite.className = 'afk-fiche-boite';

		function fermer() {
			ephemeres.forEach( function ( u ) {
				try {
					window.URL.revokeObjectURL( u );
				} catch ( e ) { /* déjà révoquée */ }
			} );

			voile.remove();
			document.removeEventListener( 'keydown', auClavier );
		}

		function auClavier( e ) {
			if ( 'Escape' === e.key ) {
				fermer();
			}
		}

		var fermeture = document.createElement( 'button' );
		fermeture.type = 'button';
		fermeture.className = 'afk-fiche-fermer';
		fermeture.setAttribute( 'aria-label', 'Fermer' );
		fermeture.textContent = '×';
		fermeture.addEventListener( 'click', fermer );
		boite.appendChild( fermeture );

		// ⚠️ Le contenu se reconstruit, la boîte non.
		//
		// L'aperçu se contentait de ce que la liste porte, et la liste ne
		// porte pas les valeurs des champs : sur une œuvre dont le détail
		// n'avait pas encore été rapatrié, l'artiste ouvrait une fiche qui
		// lui répondait « pas encore sur cet appareil » alors qu'il avait
		// du réseau. On dessine donc ce qu'on a, puis on redessine avec ce
		// que le site répond.
		var contenu = document.createElement( 'div' );
		contenu.className = 'afk-fiche-contenu';
		boite.appendChild( contenu );

		/**
		 * Dessine la fiche.
		 *
		 * ⚠️ Structurée par étapes du dépôt, et non en une seule liste.
		 *
		 * Les trente-six champs se suivaient à plat : le nom de l'artiste,
		 * l'année, la technique, la description, puis le téléphone et
		 * l'adresse électronique, sans rien pour dire qu'on avait changé de
		 * sujet. Sur un téléphone, cela fait un mur qu'on parcourt sans
		 * jamais trouver ce qu'on cherche.
		 *
		 * L'ordre et les intitulés sont ceux du formulaire de dépôt : ce
		 * sont les mêmes sections, dans le même ordre, avec les mêmes mots.
		 * L'artiste retrouve ce qu'il a rempli là où il l'a rempli.
		 */
		async function remplir( o ) {
			var anciennes = ephemeres;
			ephemeres = [];

			var zone = document.createElement( 'div' );
			var s    = socle();

			/* ── L'en-tête : qui, et où ça en est ────────────────────── */

			var entete = document.createElement( 'header' );
			entete.className = 'afk-fiche-entete';

			var titre = document.createElement( 'h2' );
			titre.className = 'afk-fiche-titre';
			titre.textContent = o.titre || '(sans titre)';
			entete.appendChild( titre );

			var reperes = document.createElement( 'div' );
			reperes.className = 'afk-fiche-reperes';

			// ⚠️ L'état d'envoi de **cette** œuvre, pas celui de la file.
			// Le message flottant parle de l'ensemble ; ouvert sur une
			// fiche, l'artiste veut savoir si c'est celle-ci qui part.
			//
			// ⚠️ Lu AVANT la pastille, et non après : sur une création
			// jamais partie, c'est lui qui décide entre « Jamais envoyée »
			// et « Saisie en cours ». La fiche annonçait sinon autre chose
			// que la carte d'où l'on venait de cliquer.
			var sien = await envoiDe( o );

			if ( s ) {
				var etat = document.createElement( 'span' );
				etat.className = 'afk-oeuvre-etat afk-etat-' + String( o.publicationStatus || '' ).toLowerCase();
				etat.textContent = s.libelle( o.publicationStatus, sien );
				reperes.appendChild( etat );
			}

			if ( sien ) {
				var etatEnvoi = document.createElement( 'p' );
				etatEnvoi.className = 'afk-fiche-envoi afk-envoi-' + sien.toLowerCase();

				if ( 'SYNCING' === sien ) {
					var tourne = document.createElement( 'span' );
					tourne.className = 'afk-rondelle';
					tourne.setAttribute( 'aria-hidden', 'true' );
					etatEnvoi.appendChild( tourne );
				}

				etatEnvoi.appendChild( document.createTextNode(
					'SYNCING' === sien
						? mot( 'fiche_envoi', 'Envoi en cours — restez sur cette page.' )
						: ( s ? s.libelleEnvoi( sien ) : 'En attente d’envoi' )
				) );

				reperes.appendChild( etatEnvoi );
			}

			if ( reperes.children.length ) {
				entete.appendChild( reperes );
			}

			zone.appendChild( entete );

			/* ── Les visuels : un grand, les autres en bande ────────── */

			var champs  = o.champs || {};
			var connus  = schema().champs || [];
			var visuels = [];

			for ( var i = 0; i < connus.length; i++ ) {
				if ( VISUELS.indexOf( connus[ i ].type ) === -1 ) {
					continue;
				}

				var ids = champs[ connus[ i ].key ];

				if ( ! Array.isArray( ids ) ) {
					continue;
				}

				for ( var j = 0; j < ids.length; j++ ) {
					var src = await apercuDe( ids[ j ], ephemeres, o.medias );

					if ( src ) {
						visuels.push( src );
					}
				}
			}

			// Repli sur la vignette du serveur : une œuvre détaillée avant
			// que les aperçus n'existent n'a rien d'autre sous la main.
			if ( ! visuels.length && o.vignette ) {
				visuels.push( o.vignette );
			}

			if ( visuels.length ) {
				var galerie = document.createElement( 'figure' );
				galerie.className = 'afk-fiche-galerie';

				var grande = document.createElement( 'img' );
				grande.className = 'afk-fiche-grande';
				grande.src = visuels[ 0 ];
				grande.alt = o.titre ? ( 'Visuel de « ' + o.titre + ' »' ) : '';
				galerie.appendChild( grande );

				// ⚠️ Une bande de vignettes plutôt que des images empilées.
				//
				// Les visuels se suivaient à pleine largeur : trois photos
				// et il fallait faire défiler toute la fiche avant
				// d'atteindre le moindre texte. La première se voit en
				// grand, les autres se choisissent.
				if ( visuels.length > 1 ) {
					var bande = document.createElement( 'div' );
					bande.className = 'afk-fiche-bande';

					visuels.forEach( function ( src, rang ) {
						var vignette = document.createElement( 'button' );
						vignette.type = 'button';
						vignette.className = 'afk-fiche-vignette';
						vignette.setAttribute( 'aria-label', 'Visuel ' + ( rang + 1 ) + ' sur ' + visuels.length );

						if ( 0 === rang ) {
							vignette.classList.add( 'afk-fiche-vignette-active' );
						}

						var mini = document.createElement( 'img' );
						mini.src = src;
						mini.alt = '';
						mini.loading = 'lazy';
						vignette.appendChild( mini );

						vignette.addEventListener( 'click', function () {
							grande.src = src;
							bande.querySelectorAll( '.afk-fiche-vignette' ).forEach( function ( v ) {
								v.classList.remove( 'afk-fiche-vignette-active' );
							} );
							vignette.classList.add( 'afk-fiche-vignette-active' );
						} );

						bande.appendChild( vignette );
					} );

					galerie.appendChild( bande );
				}

				zone.appendChild( galerie );

				// ⚠️ Deux colonnes sur grand écran, mais seulement s'il y
				// a de quoi remplir la première. Sans visuel, les champs
				// se retrouveraient poussés à droite d'un vide.
				zone.classList.add( 'afk-fiche-avec-visuel' );
			}

			/* ── Les champs, groupés comme au dépôt ────────────── */

			var etapes = schema().etapes || [];
			var rangs  = {};

			etapes.forEach( function ( e, rang ) { rangs[ e.key ] = rang; } );

			// Les champs sans étape connue passent en dernier, ensemble,
			// plutôt que de disparaître.
			var groupes = {};

			connus.forEach( function ( champ ) {
				if ( VISUELS.indexOf( champ.type ) !== -1 ) {
					return;
				}

				var valeur = texteDe( champs[ champ.key ] ).trim();

				// Un champ vide n'apprend rien et allonge la fiche.
				if ( ! valeur ) {
					return;
				}

				var cle = champ.step && rangs.hasOwnProperty( champ.step ) ? champ.step : '';

				groupes[ cle ] = groupes[ cle ] || [];
				groupes[ cle ].push( { champ: champ, valeur: valeur } );
			} );

			var ordre   = etapes.map( function ( e ) { return e.key; } ).concat( [ '' ] );
			var remplis = 0;

			ordre.forEach( function ( cle ) {
				var lot = groupes[ cle ];

				if ( ! lot || ! lot.length ) {
					return;
				}

				remplis++;

				var section = document.createElement( 'section' );
				section.className = 'afk-fiche-bloc';

				var intitule = '';

				etapes.forEach( function ( e ) {
					if ( e.key === cle ) { intitule = String( e.label || '' ).trim(); }
				} );

				// Une étape sans intitulé n'en invente pas : le groupe se
				// lit alors à sa seule séparation.
				if ( intitule ) {
					var h = document.createElement( 'h3' );
					h.className = 'afk-fiche-section';
					h.textContent = intitule;
					section.appendChild( h );
				}

				var liste = document.createElement( 'dl' );
				liste.className = 'afk-fiche-champs';

				lot.forEach( function ( entree ) {
					var dt = document.createElement( 'dt' );
					dt.textContent = String( entree.champ.label || entree.champ.key ).replace( /[\s*:]+$/, '' );

					var dd = document.createElement( 'dd' );
					dd.textContent = entree.valeur;

					// ⚠️ Une description de dix lignes dans une colonne de
					// deux cents pixels se lit un mot par ligne. Les textes
					// longs prennent toute la largeur.
					if ( entree.valeur.length > 90 || 'texteditor' === entree.champ.type ) {
						dt.classList.add( 'afk-fiche-large' );
						dd.classList.add( 'afk-fiche-large' );
					}

					liste.appendChild( dt );
					liste.appendChild( dd );
				} );

				section.appendChild( liste );
				zone.appendChild( section );
			} );

			if ( ! remplis ) {
				var rien = document.createElement( 'p' );
				rien.className = 'afk-fiche-vide';
				rien.textContent = mot( 'fiche_vide', 'Le détail de cette œuvre n’est pas encore sur cet appareil. Ouvrez-la une fois avec du réseau.' );
				zone.appendChild( rien );
			}

			/* ── Le pied ───────────────────────────────── */

			var pied = document.createElement( 'div' );
			pied.className = 'afk-fiche-pied';

			// Voir la page publique : seulement quand elle existe vraiment.
			// Une œuvre en attente de validation n'a rien à montrer là-bas.
			if ( o.lien && 'PUBLISHED' === o.publicationStatus ) {
				var voir = document.createElement( 'a' );
				voir.className = 'afk-form-bouton afk-form-secondaire';
				voir.href = o.lien;
				voir.textContent = mot( 'fiche_voir', 'Voir sur AFRICART' );
				pied.appendChild( voir );
			}

			if ( CFG.editeur ) {
				var modifier = document.createElement( 'a' );
				modifier.className = 'afk-form-bouton afk-form-primaire';
				modifier.href = adresseEditeur( o );
				modifier.textContent = mot( 'liste_modifier', 'Modifier' );
				pied.appendChild( modifier );
			}

			zone.appendChild( pied );

			// Poser d'abord, révoquer ensuite : l'inverse laisserait un
			// instant d'images cassées à l'écran.
			contenu.textContent = '';
			contenu.appendChild( zone );

			anciennes.forEach( function ( u ) {
				try {
					window.URL.revokeObjectURL( u );
				} catch ( e ) { /* déjà révoquée */ }
			} );
		}

		await remplir( o );

		voile.appendChild( boite );

		// Le voile ferme, mais pas la boîte : un clic sur la fiche
		// elle-même ne doit pas la faire disparaître.
		voile.addEventListener( 'click', function ( e ) {
			if ( e.target === voile ) {
				fermer();
			}
		} );

		document.addEventListener( 'keydown', auClavier );
		document.body.appendChild( voile );
		fermeture.focus();

		// ⚠️ Avec du réseau, on va chercher la version du site.
		//
		// Hors connexion, rien ne change : la copie locale est tout ce
		// qu'on a, et c'est déjà ce qui s'affiche. La fiche reste ouverte
		// pendant la requête — attendre le réseau avant d'ouvrir ferait un
		// écran mort à celui dont le réseau est mauvais, c'est-à-dire le
		// public visé.
		if ( ! o.wpPostId || ! window.navigator.onLine || ! window.afkOeuvres || ! window.afkOeuvres.detail ) {
			return;
		}

		contenu.setAttribute( 'aria-busy', 'true' );

		try {
			var frais = await window.afkOeuvres.detail( o.wpPostId );

			// Refermée entre-temps : on ne redessine pas dans le vide.
			if ( frais && voile.isConnected ) {
				await remplir( frais );
			}
		} catch ( e ) { /* le site n'a pas répondu : la copie locale reste */ }

		contenu.removeAttribute( 'aria-busy' );
	}

	/** Ce que la file retient pour cette œuvre, ou rien. */
	async function envoiDe( o ) {
		// ⚠️ Le même jugement que les cartes, et non l'état brut de la
		// file.
		//
		// Celui-ci ne connaît que `PENDING`, `SYNCING` et consorts : il
		// ignore ce qui bloque — un champ manquant, une photo perdue — et
		// ignore tout des saisies qui ne sont pas encore en file. La fiche
		// annonçait donc autre chose que la carte d'où l'on venait de
		// cliquer, sur la même œuvre au même instant.
		if ( window.afkSync && window.afkSync.etatsParCible ) {
			var etats = await window.afkSync.etatsParCible();
			var mien  = ( o.wpPostId && etats[ String( o.wpPostId ) ] )
				|| ( o.localId && etats[ String( o.localId ) ] );

			if ( mien ) {
				return mien;
			}
		}

		var file = await DB.tout( 'file' );

		var sienne = file.filter( function ( a ) {
			return ( o.wpPostId && String( a.cible ) === String( o.wpPostId ) )
				|| ( o.localId && String( a.cible ) === String( o.localId ) );
		} )[ 0 ];

		return sienne ? sienne.etat : '';
	}

	/**
	 * Où mène « Modifier ».
	 *
	 * ⚠️ Le paramètre passe par le **fragment** et non par la requête :
	 * une adresse avec « ? » ne correspond plus à l'entrée mise en cache
	 * par le service worker, et la page ne s'ouvrirait pas du tout.
	 */
	function adresseEditeur( o ) {
		return CFG.editeur + ( o.wpPostId
			? '#oeuvre=' + o.wpPostId
			: '#brouillon=' + encodeURIComponent( o.localId || '' ) );
	}

	/* ------------------------------------------------------------------
	 * La liste
	 * ---------------------------------------------------------------- */

	/* ------------------------------------------------------------------
	 * Suppression d'un brouillon
	 * ---------------------------------------------------------------- */

	/**
	 * Pose une question à laquelle on ne répond pas par mégarde.
	 *
	 * ⚠️ Pas de `window.confirm` : sur téléphone il s'affiche hors du
	 * contexte, ne dit pas ce qu'on s'apprête à perdre, et certains
	 * navigateurs en mode application le suppriment purement et
	 * simplement — le geste serait alors exécuté sans question.
	 *
	 * @return {Promise<boolean>}
	 */
	function demander( titre, detail, action ) {
		// ⚠️ La question vit dans le socle : le formulaire en a besoin lui
		// aussi, et deux implémentations d'une même boîte finiraient par
		// diverger — l'une reprenant le focus, l'autre non.
		if ( window.afkDemander ) {
			return window.afkDemander( titre, detail, action );
		}

		// Sans le socle, mieux vaut ne rien détruire que de détruire sans
		// question.
		return Promise.resolve( false );
	}

	/**
	 * Jette un brouillon qui n'est jamais parti.
	 *
	 * ⚠️ Refusé tant qu'un envoi est **en cours** pour lui. À cet
	 * instant, l'œuvre est peut-être déjà créée côté serveur : effacer
	 * la trace locale laisserait une œuvre orpheline sur le site, que
	 * l'artiste ne pourrait plus retrouver depuis cet appareil.
	 *
	 * Le reste part avec : les actions en file qui le visent, et les
	 * photos gardées ici pour lui — qui, sans cela, occuperaient le
	 * stockage du téléphone jusqu'à l'élagage, pour une œuvre qui
	 * n'existe plus.
	 */
	async function supprimer( o ) {
		var file = await DB.tout( 'file' );

		var siennes = file.filter( function ( a ) {
			return String( a.cible ) === String( o.localId );
		} );

		if ( siennes.some( function ( a ) { return 'SYNCING' === a.etat; } ) ) {
			await demander(
				'Envoi en cours',
				'Ce brouillon est en train de partir. Attendez qu’il aboutisse : il apparaîtra alors dans vos œuvres, et vous pourrez le retirer de là.',
				'J’ai compris'
			);
			return false;
		}

		var oui = await demander(
			'Supprimer ce brouillon ?',
			'« ' + ( o.titre || 'Sans titre' ) + ' » n’a jamais été envoyé au site. Cette suppression ne peut pas être annulée.',
			mot( 'liste_supprimer', 'Supprimer' )
		);

		if ( ! oui ) {
			return false;
		}

		for ( var i = 0; i < siennes.length; i++ ) {
			await DB.effacer( 'file', siennes[ i ].id );
		}

		var champs = o.champs || {};

		for ( var cle in champs ) {
			if ( ! Object.prototype.hasOwnProperty.call( champs, cle ) || ! Array.isArray( champs[ cle ] ) ) {
				continue;
			}

			for ( var j = 0; j < champs[ cle ].length; j++ ) {
				var v = champs[ cle ][ j ];

				if ( 'string' === typeof v && 0 === v.indexOf( 'local:' ) ) {
					await DB.effacer( 'medias', v );
				}
			}
		}

		await DB.effacer( 'brouillons', o.localId );

		// ⚠️ Si c'était le brouillon en cours, on efface aussi son
		// identifiant : sans cela, rouvrir le formulaire recréerait une
		// entrée sous la même clé, et le brouillon supprimé ressusciterait
		// vide à la première frappe.
		try {
			if ( window.localStorage.getItem( 'afkOeuvreBrouillonCourant' ) === o.localId ) {
				window.localStorage.removeItem( 'afkOeuvreBrouillonCourant' );
			}
		} catch ( e ) { /* sans mémoire, rien à nettoyer */ }

		if ( window.afkSync ) {
			window.afkSync.annoncer();
		}

		return true;
	}

	/**
	 * Les adresses d'aperçu vivantes de la liste.
	 *
	 * ⚠️ Révoquées à chaque redessin. Une `blob:` oubliée retient l'image
	 * entière en mémoire, et cette liste se redessine à chaque mouvement
	 * de la file.
	 */
	var ephemeresListe = [];

	function oublieLesApercus() {
		ephemeresListe.forEach( function ( u ) {
			try {
				window.URL.revokeObjectURL( u );
			} catch ( e ) { /* déjà révoquée */ }
		} );
		ephemeresListe = [];
	}

	/**
	 * Met le contenu en place, puis seulement alors libère l'ancien.
	 *
	 * ⚠️ L'ordre compte. Révoquer d'abord, c'était couper les images du
	 * rendu encore affiché : le temps de lire la base, l'artiste voyait
	 * ses photos se casser une à une. On échange, puis on libère.
	 */
	function poserLeContenu( cible, contenu, neufs ) {
		cible.innerHTML = '';
		cible.appendChild( contenu );

		oublieLesApercus();
		ephemeresListe = neufs;
	}

	/**
	 * La vignette d'une œuvre, prise sur l'appareil quand on l'a.
	 *
	 * ⚠️ Sans cela, la liste hors connexion était une rangée de cases
	 * grises : l'adresse du serveur ne mène nulle part, et une œuvre sans
	 * image ne se reconnaît pas. Or c'est bien à sa photo qu'un artiste
	 * reconnaît son œuvre, pas à son titre.
	 */
	async function vignetteLocale( o, ephemeres ) {
		var champs = o.champs || {};
		var connus = schema().champs || [];

		for ( var i = 0; i < connus.length; i++ ) {
			if ( VISUELS.indexOf( connus[ i ].type ) === -1 ) {
				continue;
			}

			var ids = champs[ connus[ i ].key ];

			if ( ! Array.isArray( ids ) || ! ids.length ) {
				continue;
			}

			var src = await apercuDe( ids[ 0 ], ephemeres, o.medias );

			if ( src ) {
				return src;
			}
		}

		return '';
	}

	/**
	 * L'icône de corbeille.
	 *
	 * Dessinée ici plutôt que chargée : une icône de dix-huit pixels ne
	 * vaut pas une requête réseau, et surtout pas une requête qui
	 * échouerait hors connexion — précisément là où cet écran sert.
	 */
	/**
	 * La tuile « Enregistrer une nouvelle œuvre », en tête de liste.
	 *
	 * ⚠️ Le seul chemin pour déposer passait par le menu du site.
	 *
	 * Au test client : « est-il possible de faire en sorte que ce bouton
	 * soit accessible sans devoir recliquer sur enregistrer une œuvre et
	 * commencer à remplir les premières infos du formulaire ». Celui qui
	 * vient de consulter ses œuvres et veut en ajouter une devait
	 * ressortir de la liste, rouvrir le menu, retrouver l'entrée. Le geste
	 * le plus fréquent de l'application était le mieux caché.
	 *
	 * ⚠️ De la taille d'une œuvre, dans la même grille. Un bouton posé
	 * au-dessus aurait été un élément de plus à sauter du regard ; une
	 * case de même format se lit comme « la place de la prochaine ».
	 */
	
	/**
	 * Envoie une œuvre, et elle seule.
	 *
	 * ⚠️ Au test client : « je trouve ça frustrant de n'avoir que le
	 * bouton envoyer maintenant ». Le message flottant envoyait tout d'un
	 * coup, sans qu'on puisse relire ni choisir. Une œuvre qu'on hésite à
	 * montrer partait avec les autres.
	 *
	 * Deux situations, un seul geste :
	 *
	 * - une action attend déjà dans la file — l'artiste a appuyé sur
	 *   « Déposer » hors connexion : on la fait partir ;
	 * - une modification est enregistrée sans avoir été mise en file —
	 *   « Enregistrer et revenir » sans réseau : on la met en file, puis
	 *   on la fait partir.
	 *
	 * ⚠️ Jamais une saisie incomplète. Le site la refuserait, la carte
	 * passerait en « Envoi refusé », et l'artiste croirait à une panne
	 * pour un champ oublié. On l'emmène le remplir.
	 *
	 * @return {string} Ce qui s'est passé, pour l'affichage.
	 */
	async function envoyerUne( o, bouton ) {
		if ( ! window.afkSync ) {
			return 'sans-file';
		}

		var cible = o.wpPostId ? String( o.wpPostId ) : String( o.localId || '' );

		if ( ! cible ) {
			return 'sans-cible';
		}

		var file = await DB.tout( 'file' );

		var sienne = file.filter( function ( a ) {
			return String( a.cible ) === cible;
		} )[ 0 ];

		if ( ! sienne ) {
			// Pas d'action en file : c'est une modification enregistrée
			// sur l'appareil. On la met en file à partir du brouillon.
			if ( ! o.wpPostId ) {
				// Une création jamais déposée passe par le formulaire :
				// c'est là que sa validité se vérifie, écran par écran.
				return 'passer-par-le-formulaire';
			}

			var brouillon = await DB.lire( 'brouillons', 'oeuvre-' + o.wpPostId );

			if ( ! brouillon || ! brouillon.champs ) {
				return 'rien-a-envoyer';
			}

			var manque = window.afkSync.manquants
				? window.afkSync.manquants( { champs: brouillon.champs } )
				: [];

			if ( manque && manque.length ) {
				return 'incomplet';
			}

			await window.afkSync.ajouter( 'enregistrer', cible, {
				wpPostId: o.wpPostId,
				champs: brouillon.champs,
				hashAttendu: o.hash || ''
			} );

			return 'envoyee';
		}

		if ( bouton ) {
			bouton.disabled = true;
		}

		var bilan = await window.afkSync.vider( cible );

		if ( bouton ) {
			bouton.disabled = false;
		}

		return bilan && bilan.envoyees > 0 ? 'envoyee' : ( bilan.raison || 'rien-a-envoyer' );
	}

	function tuileDeDepot() {
		var lien = document.createElement( 'a' );
		// ⚠️ Sa propre classe, pas celle d'une œuvre.
		//
		// Elle portait aussi `afk-oeuvre` pour hériter du cadre. Tout ce
		// qui parcourt les cartes la prenait alors pour une œuvre et
		// cherchait un titre qu'elle n'a pas. Elle reprend les mêmes
		// mesures dans la feuille de style : quelques lignes contre une
		// confusion permanente.
		lien.className = 'afk-oeuvre-neuve';
		lien.href = CFG.editeur || '#';

		var croix = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		croix.setAttribute( 'viewBox', '0 0 24 24' );
		croix.setAttribute( 'width', '30' );
		croix.setAttribute( 'height', '30' );
		croix.setAttribute( 'aria-hidden', 'true' );
		croix.setAttribute( 'focusable', 'false' );

		var trace = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		trace.setAttribute( 'd', 'M12 5v14M5 12h14' );
		trace.setAttribute( 'fill', 'none' );
		trace.setAttribute( 'stroke', 'currentColor' );
		trace.setAttribute( 'stroke-width', '2' );
		trace.setAttribute( 'stroke-linecap', 'round' );
		croix.appendChild( trace );

		var rond = document.createElement( 'span' );
		rond.className = 'afk-oeuvre-neuve-rond';
		rond.appendChild( croix );

		var dit = document.createElement( 'span' );
		dit.className = 'afk-oeuvre-neuve-texte';
		dit.textContent = mot( 'liste_deposer', 'Enregistrer une nouvelle œuvre' );

		lien.appendChild( rond );
		lien.appendChild( dit );

		return lien;
	}

	function corbeille() {
		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'width', '18' );
		svg.setAttribute( 'height', '18' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );

		var trace = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
		trace.setAttribute( 'd', 'M4 7h16M10 4h4M9 7v12M15 7v12M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13' );
		trace.setAttribute( 'fill', 'none' );
		trace.setAttribute( 'stroke', 'currentColor' );
		trace.setAttribute( 'stroke-width', '1.7' );
		trace.setAttribute( 'stroke-linecap', 'round' );
		trace.setAttribute( 'stroke-linejoin', 'round' );

		svg.appendChild( trace );

		return svg;
	}

	/** Ajoute les deux gestes sous une carte. */
	function actions( article, o, envoi ) {
		var barre = document.createElement( 'div' );
		barre.className = 'afk-oeuvre-actions';

		var apercu = document.createElement( 'button' );
		apercu.type = 'button';
		apercu.className = 'afk-oeuvre-action';
		apercu.textContent = 'Aperçu';
		apercu.addEventListener( 'click', function () { ouvrirFiche( o ); } );
		barre.appendChild( apercu );

		if ( CFG.editeur ) {
			var modifier = document.createElement( 'a' );
			modifier.className = 'afk-oeuvre-action afk-oeuvre-action-forte';
			modifier.href = adresseEditeur( o );
			modifier.textContent = mot( 'liste_modifier', 'Modifier' );
			barre.appendChild( modifier );
		}

		// ⚠️ Envoyer celle-ci, et elle seule.
		//
		// N'apparaît que sur une œuvre qui a réellement quelque chose à
		// envoyer et que rien ne bloque : posé partout, il aurait promis
		// un envoi sur des œuvres déjà à jour.
		// ⚠️ L'état vient du dessin de la carte, pas de l'œuvre.
		//
		// Il n'est posé sur l'objet que pour les créations locales ; une
		// œuvre du site le reçoit à part. Le lire sur `o` laissait donc le
		// bouton absent précisément là où il sert le plus.
		var etatEnvoi = envoi || o._envoi || '';

		// `RETENTE` en fait partie : elle peut repartir telle quelle, et
		// c'est précisément le geste qu'on attend.
		if ( 'ATTENTE' === etatEnvoi || 'NON_ENVOYE' === etatEnvoi || 'RETENTE' === etatEnvoi ) {
			var envoyer = document.createElement( 'button' );
			envoyer.type = 'button';
			envoyer.className = 'afk-oeuvre-action afk-oeuvre-action-envoi';
			envoyer.textContent = mot( 'liste_envoyer', 'Envoyer' );

			envoyer.addEventListener( 'click', async function ( e ) {
				e.preventDefault();
				e.stopPropagation();

				if ( ! window.navigator.onLine ) {
					envoyer.textContent = mot( 'liste_hors_ligne', 'Sans réseau' );
					window.setTimeout( function () {
						envoyer.textContent = mot( 'liste_envoyer', 'Envoyer' );
					}, 2200 );
					return;
				}

				envoyer.textContent = mot( 'liste_envoi', 'Envoi…' );

				var issue = await envoyerUne( o, envoyer );

				if ( 'incomplet' === issue || 'passer-par-le-formulaire' === issue ) {
					// Le formulaire dira quel champ manque, écran par
					// écran. Le dire ici demanderait de reconstruire toute
					// sa logique de validation dans une carte.
					window.location.href = adresseEditeur( o );
					return;
				}

				envoyer.textContent = mot( 'liste_envoyer', 'Envoyer' );
				rendre( document.querySelector( '[data-afk-liste]' ) );
			} );

			barre.appendChild( envoyer );
		}

		// ⚠️ Seulement pour ce qui n'est jamais parti. Une œuvre du site se
		// retire depuis le site, avec ses règles et sa corbeille ; effacer
		// ici la copie locale ne supprimerait rien et la ferait revenir à
		// la synchronisation suivante.
		if ( ! o.wpPostId && o.localId ) {
			var jeter = document.createElement( 'button' );
			jeter.type = 'button';
			jeter.className = 'afk-oeuvre-action afk-oeuvre-action-jeter';

			// ⚠️ Une icône, pas un mot.
			//
			// À trois libellés, la rangée débordait de la carte et
			// « Supprimer » sortait du cadre — constaté sur le site. La
			// corbeille se reconnaît sans être lue, et le nom complet reste
			// accessible : `aria-label` pour les lecteurs d'écran, `title`
			// pour l'infobulle.
			var nomGeste = mot( 'liste_supprimer', 'Supprimer' )
				+ ' « ' + ( o.titre || 'sans titre' ) + ' »';

			jeter.setAttribute( 'aria-label', nomGeste );
			jeter.title = nomGeste;
			jeter.appendChild( corbeille() );
			jeter.addEventListener( 'click', async function () {
				if ( await supprimer( o ) ) {
					rendre( document.querySelector( '[data-afk-liste]' ) );
				}
			} );
			barre.appendChild( jeter );
		}

		article.appendChild( barre );

		var renoncer = article.querySelector( '[data-afk-abandon]' );

		if ( renoncer ) {
			renoncer.addEventListener( 'click', async function ( e ) {
				// ⚠️ Ces boutons vivent DANS le lien de la carte. Sans
				// cela, le clic remontait jusqu'à lui et ouvrait l'œuvre :
				// la question ne s'affichait jamais. Constaté au banc.
				e.preventDefault();
				e.stopPropagation();

				var oui = await demander(
					'Abandonner cette modification ?',
					'Les changements que vous avez faits sur « ' + ( o.titre || 'cette œuvre' )
						+ ' » depuis cet appareil seront écartés. L’œuvre publiée sur AFRICART '
						+ 'n’est pas touchée : c’est elle que vous retrouverez ici.',
					'Abandonner'
				);

				if ( ! oui || ! window.afkSync || ! window.afkSync.abandonner ) {
					return;
				}

				await window.afkSync.abandonner( o.wpPostId );

				// La version du site reprend sa place, tout de suite.
				if ( window.navigator.onLine && window.afkOeuvres ) {
					try { await window.afkOeuvres.rafraichir( true ); } catch ( e ) { /* la copie locale suffit */ }
				}

				rendre( document.querySelector( '[data-afk-liste]' ) );
			} );
		}

		// La ligne d'état est aussi le chemin vers ce qu'il faut faire.
		var geste = article.querySelector( '[data-afk-geste]' );

		if ( geste ) {
			geste.addEventListener( 'click', function ( e ) {
				// Même raison : la ligne d'état est un bouton posé dans le
				// lien de la carte, et son geste doit primer sur lui.
				e.preventDefault();
				e.stopPropagation();

				if ( 'comparer' === geste.getAttribute( 'data-afk-geste' ) && window.afkConflit ) {
					window.afkConflit.ouvrir();
					return;
				}

				if ( CFG.editeur ) {
					window.location.href = adresseEditeur( o );
				}
			} );
		}
	}

	/**
	 * Rassemble ce qu'il y a à montrer.
	 *
	 * Trois sources, et les trois comptent : les œuvres du serveur mises
	 * en cache, ce qui attend de partir, et les créations qui n'ont jamais
	 * quitté l'appareil.
	 */
	async function rassembler() {
		var s = socle();

		if ( ! s ) {
			return { oeuvres: [], envois: {}, locales: [] };
		}

		var oeuvres = await s.toutes();

		// ⚠️ Les états viennent de la file, qui seule sait ce qui bloque.
		// L'ancien calcul se contentait de recopier l'état brut de
		// l'action : une œuvre dont la photo a disparu s'annonçait « en
		// attente », et cette attente n'aurait jamais pris fin.
		var envois = window.afkSync && window.afkSync.etatsParCible
			? await window.afkSync.etatsParCible()
			: await s.envois();
		var locales = [];

		var brouillons = await DB.tout( 'brouillons' );

		// ⚠️ Une modification non envoyée l'emporte sur le titre du site.
		//
		// La carte affichait celui d'AFRICART. L'artiste venait de le
		// corriger, la ligne d'état disait bien « Modifications non
		// envoyées », mais le titre restait l'ancien : deux informations
		// contradictoires sur la même carte, et c'est le titre qu'on
		// regarde. Il croyait sa correction perdue.
		//
		// Seul le titre est repris. Le statut de publication, lui, reste
		// celui du site : ce n'est pas parce qu'on a corrigé chez soi que
		// l'œuvre a changé d'état là-bas.
		brouillons.forEach( function ( b ) {
			var cle = String( b.localId || '' );

			if ( b.envoye || 0 !== cle.indexOf( 'oeuvre-' ) ) {
				return;
			}

			var vise = cle.slice( 7 );
			var titre = b.champs && ( b.champs.title || b.champs.titre );

			if ( ! titre ) {
				return;
			}

			oeuvres.forEach( function ( o ) {
				if ( String( o.wpPostId ) === vise ) {
					o.titre = titre;
				}
			} );
		} );

		brouillons.forEach( function ( b ) {
			// Un brouillon rattaché à une œuvre déjà sur le site n'est pas
			// une création : c'est une modification en cours, et la carte
			// de l'œuvre la porte déjà.
			if ( ! b.localId || 0 === String( b.localId ).indexOf( 'oeuvre-' ) ) {
				return;
			}

			if ( oeuvres.some( function ( o ) { return o.localId === b.localId; } ) ) {
				return;
			}

			locales.push( {
				localId: b.localId,
				// La date de la dernière frappe : c'est elle qui range la
				// liste, la plus récente en tête.
				_quand: b.majLe || 0,
				titre: ( b.champs && ( b.champs.title || b.champs.titre ) ) || '(brouillon sans titre)',
				// ⚠️ `LOCAL`, et non `DRAFT` : « Brouillon » désigne un
				// statut **du site**, et cette œuvre n'y est jamais allée.
				// Lui prêter le vocabulaire d'AFRICART laissait croire
				// qu'elle s'y trouvait déjà.
				publicationStatus: 'LOCAL',
				champs: b.champs || {},
				_envoi: envois[ b.localId ] || ''
			} );
		} );

		// ⚠️ Une action en file dont l'œuvre n'apparaît nulle part.
		//
		// Le message annonçait « 3 à corriger » et aucune carte ne portait
		// le moindre signe : ces actions visaient des œuvres disparues de
		// la liste — un brouillon supprimé, une création dont la trace
		// locale s'est perdue. L'artiste devait ouvrir chaque œuvre une par
		// une pour ne rien trouver. Un problème qu'on annonce sans pouvoir
		// le montrer ne se corrige jamais.
		var connus = {};

		oeuvres.forEach( function ( o ) {
			connus[ String( o.wpPostId ) ] = true;

			if ( o.localId ) {
				connus[ String( o.localId ) ] = true;
			}
		} );

		locales.forEach( function ( o ) { connus[ String( o.localId ) ] = true; } );

		var file = await DB.tout( 'file' );

		file.forEach( function ( a ) {
			var cible = String( a.cible );

			if ( connus[ cible ] ) {
				return;
			}

			connus[ cible ] = true;

			var champs = ( a.charge && a.charge.champs ) || {};

			locales.push( {
				localId: cible,
				_quand: a.depuis || 0,
				wpPostId: a.charge && a.charge.wpPostId,
				titre: champs.title || champs.titre || '(œuvre sans titre)',
				publicationStatus: a.charge && a.charge.wpPostId ? 'DRAFT' : 'LOCAL',
				champs: champs,
				_envoi: envois[ cible ] || a.etat
			} );
		} );

		/**
		 * ⚠️ La plus récente en premier.
		 *
		 * La réserve locale rend ce qu'elle contient dans l'ordre de ses
		 * clés : par identifiant croissant côté site, par ordre d'écriture
		 * pour les brouillons. Autrement dit, du plus ancien au plus
		 * récent. L'artiste qui venait d'enregistrer une œuvre la cherchait
		 * donc tout en bas d'une liste qui s'allonge — alors que c'est
		 * celle-là, précisément, dont il veut vérifier le sort.
		 *
		 * Les créations jamais parties gardent leur place en tête de liste :
		 * elles restent le groupe dont on doute. C'est l'ordre À
		 * L'INTÉRIEUR de chaque groupe qui change.
		 *
		 * Une œuvre du site se date de sa dernière modification là-bas, et
		 * non de sa mise en cache : deux appareils qui synchronisent au même
		 * moment doivent afficher le même ordre.
		 */
		function quand( o ) {
			if ( o._quand ) {
				return o._quand;
			}

			var d = Date.parse( o.updatedAtServer || '' );

			return isNaN( d ) ? ( o._cacheLe || 0 ) : d;
		}

		function recenteDabord( a, b ) {
			return quand( b ) - quand( a );
		}

		locales.sort( recenteDabord );
		oeuvres.sort( recenteDabord );

		return { oeuvres: oeuvres, envois: envois, locales: locales };
	}

	/**
	 * Un rendu à la fois, et un seul rappel en réserve.
	 *
	 * ⚠️ `rendre` attend la base entre le vidage du conteneur et le
	 * remplissage. Depuis que la file annonce **chaque** œuvre qui part,
	 * ces annonces arrivent en rafale : un second rendu démarrait pendant
	 * qu'un premier attendait, révoquait les adresses `blob:` des images
	 * déjà posées — d'où les photos cassées — puis ajoutait sa propre
	 * grille à côté de l'autre — d'où la liste en double. Constaté sur le
	 * site.
	 *
	 * Les demandes reçues pendant un rendu se résument à une seule : ce
	 * qu'on veut, c'est l'état final, pas chaque étape intermédiaire.
	 */
	var enRendu   = false;
	var aRefaire  = false;

	/**
	 * ⚠️ Jamais sous le doigt.
	 *
	 * Le clic n'est pas remis au moment où le doigt se pose : il l'est au
	 * relâchement, et à l'élément visé AU MOMENT OÙ IL S'EST POSÉ. Un
	 * redessin entre les deux remplace cet élément — cette liste se
	 * reconstruit entière, chaque carte et chaque bouton — et le clic ne
	 * part alors nulle part. L'artiste appuyait sur « Envoyer », rien ne
	 * se passait, il appuyait une seconde fois. Constaté sur iPhone 13,
	 * où l'écart entre les deux est le plus large.
	 *
	 * Les annonces de la file arrivent en rafale pendant qu'on lit une
	 * carte : la probabilité que l'une tombe pile entre le doigt posé et
	 * le doigt levé n'a rien de théorique.
	 *
	 * Le redessin attend donc que la main soit partie. Il ne se perd pas :
	 * il est retenu, et repart juste après.
	 */
	var doigtPose = false;
	var enAttente = null;
	var relache   = null;

	function auDoigt( e ) {
		// Seulement dans la liste : un doigt posé ailleurs sur la page ne
		// doit pas retenir son rafraîchissement.
		if ( ! e.target || ! e.target.closest || ! e.target.closest( '[data-afk-liste]' ) ) {
			return;
		}

		doigtPose = true;
		window.clearTimeout( relache );
	}

	function auRelachement() {
		if ( ! doigtPose ) {
			return;
		}

		doigtPose = false;
		window.clearTimeout( relache );

		// ⚠️ Pas tout de suite : le clic arrive APRÈS le relâchement, et
		// redessiner dans l'intervalle nous ramènerait au défaut qu'on
		// vient de corriger. Un tiers de seconde suffit largement, et ne
		// se voit pas.
		relache = window.setTimeout( function () {
			var quoi = enAttente;
			enAttente = null;

			if ( quoi ) {
				rendre( quoi );
			}
		}, 350 );
	}

	// `pointer*` couvre le doigt comme la souris. `touchend` en renfort :
	// un geste interrompu par le navigateur — un défilement qui prend la
	// main — n'émet pas toujours `pointerup`.
	document.addEventListener( 'pointerdown', auDoigt, true );
	document.addEventListener( 'pointerup', auRelachement, true );
	document.addEventListener( 'pointercancel', auRelachement, true );
	document.addEventListener( 'touchend', auRelachement, true );
	document.addEventListener( 'touchcancel', auRelachement, true );

	async function rendre( cible ) {
		if ( doigtPose ) {
			enAttente = cible;
			return;
		}

		if ( enRendu ) {
			aRefaire = true;
			return;
		}

		enRendu = true;

		try {
			do {
				aRefaire = false;
				await dessiner( cible );
			} while ( aRefaire );
		} finally {
			enRendu = false;
			replacerLaPage( cible );
		}
	}

	/**
	 * Rend sa place à ce qui entoure la liste, une fois celle-ci posée.
	 *
	 * ⚠️ Cette liste arrive APRÈS tout le monde.
	 *
	 * Elle se construit en lisant la réserve locale, c'est-à-dire une ou
	 * deux secondes après que la page s'est arrêtée de bouger. Un
	 * conteneur qui avait mesuré sa hauteur d'ici là l'a fait sur du
	 * vide, et personne ne recommence tout seul.
	 *
	 * ⚠️ C'est la HAUTEUR qu'il faut rendre, pas le débordement.
	 *
	 * J'ai d'abord leve le `overflow: hidden` de la chaine, en croyant que
	 * le rognage etait le mal. C'etait la moitie du probleme, et la
	 * mauvaise : la boite restait trop courte, son contenu s'en echappait
	 * desormais visiblement, et le pied de page — pose la ou finit la
	 * boite — passait par-dessus. « Coupe » etait devenu « recouvert ».
	 * Signale au test, deux fois.
	 *
	 * On mesure donc, et l'on ne corrige que ce qui est mesurablement
	 * faux : un ancetre dont le contenu depasse la boite se voit rendre
	 * une hauteur libre. Le pied redescend alors de lui-meme, puisqu'il
	 * suit le flux.
	 */
	function replacerLaPage( cible ) {
		var noeud = cible && cible.parentElement;

		while ( noeud && noeud !== document.body && noeud !== document.documentElement ) {
			desserrer( noeud );
			noeud = noeud.parentElement;
		}

		// ⚠️ Au tour d'après. Émis dans le même souffle que la pose des
		// cartes, l'événement partait avant que le navigateur n'ait
		// recalculé quoi que ce soit, et les scripts qui l'écoutent
		// mesuraient encore l'ancienne hauteur.
		window.requestAnimationFrame( function () {
			window.dispatchEvent( new Event( 'resize' ) );
		} );
	}

	/**
	 * Rend sa hauteur à un conteneur trop court pour ce qu'il contient.
	 *
	 * ⚠️ On ne touche qu'à ce qui est mesurablement faux : `scrollHeight`
	 * plus grand que `clientHeight` dit que la boite ne contient pas son
	 * contenu, et rien d'autre ne le dit. Desserrer au vu d'une regle CSS
	 * aurait defait des mises en page parfaitement justes.
	 *
	 * ⚠️ Trois exceptions, et chacune protege quelque chose :
	 *
	 * — `auto` et `scroll` designent une zone defilante voulue. La rendre
	 *   libre supprimerait le defilement que quelqu'un a demande ;
	 * — un element `fixed` ou `sticky` tire sa hauteur de son role, pas de
	 *   son contenu ;
	 * — la fenetre modale d'arbitrage est a nous, et elle DOIT rester
	 *   bornee.
	 *
	 * ⚠️ Et `important` sur la pose : les hauteurs des constructeurs de
	 * pages arrivent par variables et par regles specifiques, qu'un style
	 * en ligne ordinaire ne bat pas toujours.
	 */
	function desserrer( noeud ) {
		var style = window.getComputedStyle( noeud );

		if ( 'fixed' === style.position || 'sticky' === style.position ) {
			return;
		}

		if ( noeud.closest( '.afk-fiche' ) ) {
			return;
		}

		var defilant = [ 'auto', 'scroll' ].indexOf( style.overflowY ) !== -1;

		// La boite contient-elle vraiment ce qu'elle porte ?
		if ( noeud.scrollHeight <= noeud.clientHeight + 2 ) {
			return;
		}

		if ( defilant ) {
			return;
		}

		noeud.style.setProperty( 'height', 'auto', 'important' );
		noeud.style.setProperty( 'max-height', 'none', 'important' );

		// Une fois la hauteur rendue, le rognage n'a plus rien à couper —
		// mais on le lève aussi, car un `hidden` seul suffit à masquer ce
		// qui déborde d'un flottant ou d'une marge négative.
		if ( 'hidden' === style.overflowX || 'hidden' === style.overflowY ) {
			noeud.style.setProperty( 'overflow', 'visible', 'important' );
		}
	}

	async function dessiner( cible ) {
		var s = socle();

		if ( ! s ) {
			return;
		}

		var tout = await rassembler();

		// ⚠️ Le conteneur n'est touché qu'à la toute fin, une fois la
		// grille complète. Le vider d'abord laissait la page blanche
		// pendant la lecture de la base, et rendait visible un état
		// intermédiaire que personne n'a demandé.
		var neufs = [];

		var grille = document.createElement( 'div' );
		grille.className = 'afk-oeuvres-grille';

		// ⚠️ Y compris — et surtout — quand il n'y a rien.
		//
		// « Aucune œuvre enregistrée sur cet appareil » était une impasse :
		// l'écran annonçait le vide sans offrir de le remplir. Celui qui
		// arrive là veut précisément déposer.
		if ( CFG.editeur ) {
			grille.appendChild( tuileDeDepot() );
		}

		if ( ! tout.oeuvres.length && ! tout.locales.length ) {
			var vide = document.createElement( 'p' );
			vide.className = 'afk-oeuvres-avis';
			vide.textContent = T.vide || 'Aucune œuvre enregistrée sur cet appareil.';

			var ensemble = document.createElement( 'div' );
			ensemble.appendChild( vide );
			ensemble.appendChild( grille );

			poserLeContenu( cible, ensemble, neufs );
			return;
		}

		// Les créations jamais parties d'abord : ce sont elles dont
		// l'artiste doute, et les enfouir en bas de liste ne le rassure pas.
		// ⚠️ Une copie, jamais l'objet du magasin : y poser une adresse
		// `blob:` la ferait enregistrer en base, où elle serait morte dès
		// la page suivante.
		async function poser( o, envoi ) {
			var vue = Object.assign( {}, o );

			if ( ! vue.vignette ) {
				vue.vignette = await vignetteLocale( o, neufs );
			}

			var article = s.carte( vue, envoi );
			actions( article, o, envoi );
			grille.appendChild( article );
		}

		for ( var i = 0; i < tout.locales.length; i++ ) {
			await poser( tout.locales[ i ], tout.locales[ i ]._envoi );
		}

		for ( var j = 0; j < tout.oeuvres.length; j++ ) {
			var o = tout.oeuvres[ j ];
			await poser( o, tout.envois[ String( o.wpPostId ) ] || tout.envois[ o.localId ] );
		}

		poserLeContenu( cible, grille, neufs );
	}

	/* ------------------------------------------------------------------
	 * Mise en route
	 * ---------------------------------------------------------------- */

	var cible = null;

	async function demarrer() {
		cible = document.querySelector( '[data-afk-liste]' );

		if ( ! cible ) {
			return;
		}

		// ⚠️ On dessine d'abord depuis la copie locale, avant toute
		// requête. Attendre le réseau laisserait un écran blanc à celui
		// qui n'en a pas — c'est-à-dire exactement le public visé.
		await rendre( cible );

		if ( ! window.navigator.onLine || ! window.afkOeuvres ) {
			return;
		}

		try {
			await window.afkOeuvres.rafraichir();
			await rendre( cible );
		} catch ( e ) { /* la copie locale reste affichée */ }
	}

	/** Redessine quand la file bouge : un envoi parti change une pastille. */
	document.addEventListener( 'afkFile', function () {
		if ( cible ) {
			rendre( cible );
		}
	} );

	// ⚠️ Et quand le site a dit quelque chose de neuf.
	//
	// Une œuvre validée pendant que l'artiste avait l'onglet ailleurs
	// arrivait bien dans la copie locale, mais rien ne redessinait :
	// l'écran continuait d'annoncer « En attente de validation » sur une
	// œuvre déjà en ligne.
	document.addEventListener( 'afkOeuvresMaj', function () {
		if ( cible ) {
			rendre( cible );
		}
	} );

	window.addEventListener( 'online', function () {
		if ( cible ) {
			demarrer();
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
