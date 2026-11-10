/**
 * La base locale des œuvres — un seul propriétaire.
 *
 * ⚠️ Ce fichier existe pour réparer un défaut, et pour qu'il ne
 * revienne pas. Le cache de consultation ouvrait la base en version 1,
 * le formulaire en version 2, et les deux se chargent sur la page de
 * dépôt. IndexedDB refuse une ouverture en version inférieure à celle
 * qui existe : le second à s'exécuter recevait une `VersionError`.
 *
 * Le symptôme était le pire possible — un `catch` la transformait en
 * « aucune œuvre en cache », et la consultation hors connexion cessait
 * de fonctionner **sur toutes les pages**, sans la moindre erreur
 * visible. On aurait cherché du côté du réseau, ou du service worker.
 *
 * La version et la structure vivent donc **ici et nulle part ailleurs**.
 * Ajouter un magasin veut dire : monter le numéro d'un cran, et ajouter
 * sa création dans `structure()`.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var NOM     = 'afk-oeuvres';
	var VERSION = 4;

	/**
	 * Les magasins, et ce qui les identifie.
	 *
	 * ⚠️ La montée de version doit **créer ce qui manque sans toucher à
	 * l'existant**. Supprimer et refaire effacerait les œuvres déjà en
	 * cache et les brouillons non synchronisés — c'est-à-dire tout ce que
	 * ce module sert à ne pas perdre.
	 */
	function structure( base ) {
		if ( ! base.objectStoreNames.contains( 'oeuvres' ) ) {
			base.createObjectStore( 'oeuvres', { keyPath: 'wpPostId' } );
		}

		if ( ! base.objectStoreNames.contains( 'brouillons' ) ) {
			base.createObjectStore( 'brouillons', { keyPath: 'localId' } );
		}

		if ( ! base.objectStoreNames.contains( 'medias' ) ) {
			// Les fichiers en attente d'envoi. Ils y restent le temps que
			// le réseau revienne, et pas une seconde de plus.
			base.createObjectStore( 'medias', { keyPath: 'id' } );
		}

		if ( ! base.objectStoreNames.contains( 'file' ) ) {
			// Clé auto-incrémentée : l'ordre d'arrivée fait foi, et deux
			// actions sur la même œuvre doivent pouvoir coexister le temps
			// que la file les regroupe.
			var file = base.createObjectStore( 'file', { keyPath: 'id', autoIncrement: true } );
			file.createIndex( 'cible', 'cible', { unique: false } );
		}
	}

	var ouverture = null;

	function ouvrir() {
		if ( ouverture ) {
			return ouverture;
		}

		ouverture = new Promise( function ( resoudre, rejeter ) {
			var demande = window.indexedDB.open( NOM, VERSION );

			demande.onupgradeneeded = function ( e ) {
				structure( e.target.result );
			};

			demande.onsuccess = function ( e ) { resoudre( e.target.result ); };
			demande.onerror   = function () { rejeter( demande.error ); };
			demande.onblocked = function () {
				// Un autre onglet retient l'ancienne version. On ne force
				// rien : forcer fermerait sa base sous ses pieds.
				rejeter( new Error( 'base retenue par un autre onglet' ) );
			};
		} );

		return ouverture;
	}

	/**
	 * Exécute une opération sur un magasin.
	 *
	 * @param {string}   nom       Magasin.
	 * @param {string}   mode      `readonly` ou `readwrite`.
	 * @param {Function} operation Reçoit le magasin, rend une requête.
	 * @return {Promise}
	 */
	function dans( nom, mode, operation ) {
		return ouvrir().then( function ( base ) {
			return new Promise( function ( resoudre, rejeter ) {
				var t = base.transaction( nom, mode );
				var m = t.objectStore( nom );
				var d = operation( m );

				t.oncomplete = function () {
					resoudre( d && 'result' in d ? d.result : true );
				};
				t.onerror = function () { rejeter( t.error ); };
				t.onabort = function () { rejeter( t.error ); };
			} );
		} );
	}

	/**
	 * Côté d'un aperçu conservé sur l'appareil, en pixels.
	 *
	 * ⚠️ On ne garde pas la photo d'origine. Douze visuels de huit
	 * mégaoctets rempliraient le quota du navigateur à eux seuls, et le
	 * navigateur, lui, vide sans prévenir — en emportant les brouillons
	 * avec. Un aperçu de quelques dizaines de kilooctets suffit à une
	 * vignette de cent pixels.
	 */
	var APERCU_COTE = 480;

	/**
	 * Fabrique une version réduite d'une image.
	 *
	 * ⚠️ Rend `null` quand le navigateur ne sait pas décoder le fichier —
	 * et surtout pas l'original. S'il ne peut pas le lire pour le
	 * réduire, il ne pourra pas davantage l'afficher : garder ses octets
	 * remplacerait la vignette par une image cassée, là où l'adresse du
	 * serveur aurait très bien fonctionné. Constaté au banc.
	 *
	 * Si seule la compression finale échoue, en revanche, on garde
	 * l'original : là, l'image est bel et bien décodable.
	 */
	async function apercu( fichier ) {
		var image;

		try {
			image = await window.createImageBitmap( fichier );
		} catch ( e ) {
			return null;
		}

		try {
			var cote  = Math.max( image.width, image.height );

			var f     = cote > APERCU_COTE ? APERCU_COTE / cote : 1;
			var large = Math.max( 1, Math.round( image.width * f ) );
			var haute = Math.max( 1, Math.round( image.height * f ) );

			var toile = document.createElement( 'canvas' );
			toile.width  = large;
			toile.height = haute;
			toile.getContext( '2d' ).drawImage( image, 0, 0, large, haute );

			if ( image.close ) {
				image.close();
			}

			var petit = await new Promise( function ( r ) {
				toile.toBlob( r, 'image/jpeg', 0.72 );
			} );

			return petit || fichier;
		} catch ( e ) {
			return fichier || null;
		}
	}

	/* ------------------------------------------------------------------
	 * Élagage
	 *
	 * ⚠️ Une réserve sans bornes n'est pas gardée plus longtemps : elle
	 * est **vidée d'un coup**. Passé son quota, le navigateur supprime
	 * l'origine entière, sans prévenir et sans choisir — brouillons non
	 * envoyés compris. Se donner des limites, c'est décider soi-même ce
	 * qu'on perd, et ne jamais perdre l'irremplaçable.
	 *
	 * ⚠️ On évince le **plus ancien**, pas le dernier arrivé. La pile
	 * grandit par le haut et se coupe par le bas : ce que l'artiste vient
	 * de consulter ou de déposer est précisément ce qu'il va rouvrir.
	 * Jeter le dernier entré viderait le cache de ce qui sert.
	 * ---------------------------------------------------------------- */

	/**
	 * Au-delà, une photo que plus rien ne réclame est tenue pour perdue.
	 *
	 * ⚠️ Il faut ce délai, et il faut qu'il soit long.
	 *
	 * Une photo choisie avant que l'œuvre n'ait un titre n'est réclamée
	 * par personne : le brouillon ne s'écrit pas tant qu'il n'y a pas de
	 * titre, et la file ne la connaît qu'au dépôt. Elle est pourtant
	 * parfaitement légitime, et la couper serait effacer sous les doigts
	 * ce que l'artiste vient de photographier.
	 *
	 * Vingt-quatre heures laissent le temps de finir une saisie
	 * commencée la veille au soir sur un terrain sans réseau.
	 */
	var GRACE_PHOTO = 24 * 60 * 60 * 1000;

	var LIMITES = {
		// Les œuvres du site : rapatriables à volonté.
		oeuvres: { nombre: 150, jours: 90 },
		// Les aperçus de visuels : reconstructibles depuis le serveur.
		medias:  { nombre: 120, jours: 30 }
	};

	/**
	 * Ce qui ne doit jamais être élagué.
	 *
	 * ⚠️ Deux magasins n'apparaissent pas ici, et c'est délibéré :
	 * `brouillons` et `file` n'existent **que** sur cet appareil. Les
	 * borner reviendrait à jeter le travail d'un artiste pour faire de la
	 * place à une copie de ce que le serveur détient déjà.
	 */
	async function aGarder() {
		var oeuvres = {};
		var medias  = {};

		try {
			var file = await dans( 'file', 'readonly', function ( m ) { return m.getAll(); } ) || [];

			file.forEach( function ( a ) {
				oeuvres[ String( a.cible ) ] = true;

				// ⚠️ Et les photos que cette action doit envoyer.
				//
				// Seuls les brouillons protégeaient leurs visuels. Une
				// œuvre déposée hors connexion vit pourtant dans la file,
				// pas dans un brouillon : ses photos n'étaient retenues
				// que par le brouillon voisin, et le jour où l'artiste en
				// changeait une, l'ancienne n'était plus réclamée par
				// personne. L'élagage l'emportait, et l'envoi restait
				// bloqué sur « Photo à reprendre » — un fichier que
				// l'application avait effacé elle-même.
				var champs = ( a.charge && a.charge.champs ) || {};

				Object.keys( champs ).forEach( function ( cle ) {
					if ( ! Array.isArray( champs[ cle ] ) ) {
						return;
					}

					champs[ cle ].forEach( function ( v ) {
						medias[ String( v ) ] = true;
					} );
				} );
			} );

			var brouillons = await dans( 'brouillons', 'readonly', function ( m ) { return m.getAll(); } ) || [];

			brouillons.forEach( function ( b ) {
				oeuvres[ String( b.localId ) ] = true;

				var champs = b.champs || {};

				Object.keys( champs ).forEach( function ( cle ) {
					if ( ! Array.isArray( champs[ cle ] ) ) {
						return;
					}

					champs[ cle ].forEach( function ( v ) {
						medias[ String( v ) ] = true;
					} );
				} );
			} );
		} catch ( e ) { /* réserve illisible : on n'élaguera rien */ }

		return { oeuvres: oeuvres, medias: medias };
	}

	/**
	 * Les visuels que quelque chose réclame encore.
	 *
	 * ⚠️ Lisible du dehors, et c'est le point : le message flottant
	 * comptait les photos « gardées ici » en regardant la réserve
	 * entière. Une photo retirée d'un formulaire y restait, et l'artiste
	 * lisait « 1 photo gardée ici » pour toujours, sur un compte dont
	 * tout avait été envoyé. Signalé au test.
	 *
	 * @return {Promise<Object>} Un ensemble d'identifiants, en clés.
	 */
	async function reclames() {
		var garder = await aGarder();

		return garder.medias;
	}

	/**
	 * Ramène chaque magasin sous ses limites.
	 *
	 * ⚠️ Uniquement avec du réseau. Élaguer hors connexion supprimerait
	 * ce qu'on ne peut pas retélécharger, au moment précis où c'est la
	 * seule copie disponible — l'artiste verrait sa liste se vider en
	 * plein terrain.
	 *
	 * @return {Promise<Object>} Ce qui a été retiré, par magasin.
	 */
	async function elaguer() {
		var bilan = { oeuvres: 0, medias: 0 };

		if ( ! window.navigator.onLine ) {
			return bilan;
		}

		var garder = await aGarder();

		// ⚠️ Une entrée sans date de mise en réserve est datée d'aujourd'hui,
		// jamais traitée comme ancienne.
		//
		// Toutes les œuvres mises en cache avant l'existence de ce champ en
		// sont dépourvues. Les compter pour « vieilles de toujours » vidait
		// la réserve entière au premier élagage — constaté au banc, sur une
		// copie qui datait pourtant de la minute précédente. On ne sait pas
		// quand elles sont arrivées : on ne s'en sert donc pas contre elles.
		await dater( 'oeuvres', '_cacheLe' );
		await dater( 'medias', 'depuis' );

		bilan.oeuvres = await elagueMagasin( 'oeuvres', LIMITES.oeuvres, function ( o ) {
			return garder.oeuvres[ String( o.wpPostId ) ] || garder.oeuvres[ String( o.localId ) ];
		}, function ( o ) {
			return o._cacheLe || 0;
		}, function ( o ) {
			return o.wpPostId;
		} );

		// ⚠️ Les correspondances « identifiant provisoire → identifiant du
		// site » vivent moins longtemps que les aperçus : elles ne servent
		// qu'à finir un envoi et à rafraîchir un formulaire resté ouvert.
		// Les garder trente jours encombrerait la réserve pour rien.
		bilan.medias += await elagueMagasin( 'medias', { nombre: 60, jours: 7 }, function ( m ) {
			return ! m.remplace;
		}, function ( m ) {
			return m.depuis || 0;
		}, function ( m ) {
			return m.id;
		} );

		// ⚠️ Les photos que plus personne ne réclame, et elles seules.
		//
		// Une photo en attente n'est pas un cache : c'est le fichier
		// lui-même, et il n'existe nulle part ailleurs. La passe générale
		// le protège donc sans condition — c'était juste, et insuffisant :
		// une photo retirée d'un formulaire n'attend plus rien, et restait
		// pourtant sur l'appareil à vie. Elle s'y faisait annoncer
		// « 1 photo gardée ici » sur un compte dont tout avait été envoyé,
		// sans aucun geste pour l'effacer. Signalé au test.
		//
		// Cette passe-ci ne vise QUE ces abandonnées : ni nombre ni âge à
		// respecter, elles n'ont plus de raison d'être. Le délai de grâce
		// couvre le seul cas où rien ne réclame encore une photo
		// légitime : celle qu'on choisit avant d'avoir donné un titre.
		bilan.medias += await elagueMagasin( 'medias', { nombre: 0, jours: 0 }, function ( m ) {
			if ( ! m.blob ) {
				return true;
			}

			if ( garder.medias[ String( m.id ) ] ) {
				return true;
			}

			return ( Date.now() - ( m.depuis || 0 ) ) < GRACE_PHOTO;
		}, function ( m ) {
			return m.depuis || 0;
		}, function ( m ) {
			return m.id;
		} );

		bilan.medias += await elagueMagasin( 'medias', LIMITES.medias, function ( m ) {
			// Un fichier qui attend son envoi n'est pas un cache : c'est la
			// photo elle-même, et elle n'existe nulle part ailleurs. Les
			// abandonnées viennent d'être ramassées juste au-dessus, comme
			// les correspondances au-dessus d'elles.
			return !! m.blob || !! m.remplace || garder.medias[ String( m.id ) ];
		}, function ( m ) {
			return m.depuis || 0;
		}, function ( m ) {
			return m.id;
		} );

		return bilan;
	}

	/** Donne une date de mise en réserve à ce qui n'en a pas. */
	async function dater( nom, champ ) {
		var tout = await dans( nom, 'readonly', function ( m ) { return m.getAll(); } )
			.catch( function () { return []; } );

		var maintenant = Date.now();

		for ( var i = 0; i < ( tout || [] ).length; i++ ) {
			if ( tout[ i ][ champ ] ) {
				continue;
			}

			tout[ i ][ champ ] = maintenant;

			await dans( nom, 'readwrite', function ( m ) {
				return m.put( tout[ i ] );
			} ).catch( function () {} );
		}
	}

	async function elagueMagasin( nom, limite, protege, dateDe, cleDe ) {
		var tout = await dans( nom, 'readonly', function ( m ) { return m.getAll(); } ).catch( function () { return []; } );

		if ( ! tout || ! tout.length ) {
			return 0;
		}

		var seuil    = Date.now() - ( limite.jours * 24 * 60 * 60 * 1000 );
		var candidats = tout.filter( function ( e ) { return ! protege( e ); } );

		// Du plus récent au plus ancien : on garde le haut de la pile.
		candidats.sort( function ( a, b ) { return dateDe( b ) - dateDe( a ); } );

		var aJeter = [];

		candidats.forEach( function ( e, rang ) {
			if ( rang >= limite.nombre || dateDe( e ) < seuil ) {
				aJeter.push( cleDe( e ) );
			}
		} );

		for ( var i = 0; i < aJeter.length; i++ ) {
			await dans( nom, 'readwrite', function ( m ) { return m.delete( aJeter[ i ] ); } ).catch( function () {} );
		}

		return aJeter.length;
	}

	window.afkOeuvresDB = {
		nom: NOM,
		version: VERSION,
		ouvrir: ouvrir,
		dans: dans,

		apercu: apercu,

		/**
		 * Le schéma du formulaire, tel qu'il est en réserve.
		 *
		 * ⚠️ Un seul point de lecture. Il était recopié dans trois
		 * fichiers, avec trois valeurs de repli différentes : le jour où
		 * la clé change, deux d'entre eux continuent de chercher l'ancienne
		 * et rendent un formulaire vide sans rien dire.
		 */
		schema: function () {
			try {
				var s = JSON.parse( window.localStorage.getItem( 'afkOeuvreSchema' ) || 'null' );
				return s && s.champs ? s : { champs: [], etapes: [] };
			} catch ( e ) {
				return { champs: [], etapes: [] };
			}
		},
		elaguer: elaguer,
		reclames: reclames,
		limites: LIMITES,
		graceDesPhotos: GRACE_PHOTO,

		lire: function ( magasin, cle ) {
			return dans( magasin, 'readonly', function ( m ) { return m.get( cle ); } )
				.catch( function () { return null; } );
		},

		tout: function ( magasin ) {
			return dans( magasin, 'readonly', function ( m ) { return m.getAll(); } )
				.then( function ( r ) { return r || []; } )
				.catch( function () { return []; } );
		},

		/**
		 * Écrit une valeur, et date sa mise en réserve.
		 *
		 * ⚠️ `_cacheLe` est la date à laquelle **nous** avons pris cette
		 * copie, pas celle de la dernière modification côté serveur. C'est
		 * elle que l'élagage lit : sans elle, il ne saurait pas distinguer
		 * une œuvre rapatriée ce matin d'une autre qui traîne depuis un an,
		 * et couperait au hasard.
		 */
		ecrire: function ( magasin, valeur ) {
			if ( 'oeuvres' === magasin && valeur && 'object' === typeof valeur ) {
				valeur._cacheLe = Date.now();
			}

			return dans( magasin, 'readwrite', function ( m ) { return m.put( valeur ); } )
				.catch( function () { return null; } );
		},

		/**
		 * Remonte une œuvre en haut de la pile.
		 *
		 * Consulter une œuvre, c'est dire qu'elle compte. Sans ce signal,
		 * l'élagage jetterait celle qu'on vient d'ouvrir au seul motif
		 * qu'elle a été rapatriée il y a longtemps.
		 */
		toucher: function ( wpPostId ) {
			return dans( 'oeuvres', 'readwrite', function ( m ) {
				var q = m.get( wpPostId );

				q.onsuccess = function () {
					if ( q.result ) {
						q.result._cacheLe = Date.now();
						m.put( q.result );
					}
				};

				return q;
			} ).catch( function () { return null; } );
		},

		effacer: function ( magasin, cle ) {
			return dans( magasin, 'readwrite', function ( m ) { return m.delete( cle ); } )
				.catch( function () { return null; } );
		}
	};
}() );
