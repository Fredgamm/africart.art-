/**
 * Cache local des œuvres de l'artiste, et consultation hors connexion.
 *
 * ⚠️ Principe de non-ingérence : **en ligne, ce script ne change rien à
 * l'écran**. La page « Vos œuvres » existe déjà, elle est rendue par le
 * thème, et un second affichage par-dessus le sien serait à la fois
 * redondant et fatalement décalé. Nous ne faisons alors que tenir le
 * cache à jour, en silence.
 *
 * Ce n'est que **sans réseau** que nous reprenons la main, et sur un seul
 * conteneur : la grille du flux. Le reste de la page — en-tête, menu,
 * recherche — reste celui du thème.
 *
 * Le stockage est IndexedDB et non `localStorage` : ce dernier est
 * synchrone, plafonné à quelques mégaoctets, et ne retient que du texte.
 * Trois raisons rédhibitoires dès qu'il s'agit d'œuvres.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_OEUVRES || {};

	if ( ! CFG.base ) {
		return;
	}

	var DB = window.afkOeuvresDB;

	if ( ! DB ) {
		return;
	}

	var MAGASIN  = 'oeuvres';
	var CLE_SYNC = 'afkOeuvresSync';

	/* ------------------------------------------------------------------
	 * Stockage
	 *
	 * ⚠️ Ce fichier n'ouvre plus la base lui-même. Il le faisait en
	 * version 1 pendant que le formulaire l'ouvrait en version 2, et
	 * IndexedDB refuse une ouverture en version inférieure : le second à
	 * s'exécuter recevait une `VersionError` que le `catch` transformait
	 * en « aucune œuvre en cache ». La consultation hors connexion
	 * cessait de fonctionner sans la moindre erreur visible.
	 *
	 * La base a désormais un seul propriétaire : `oeuvres-db.js`.
	 * ---------------------------------------------------------------- */

	function toutes() {
		return DB.tout( MAGASIN );
	}

	function enregistre( oeuvres, disparues ) {
		var maintenant = Date.now();

		return DB.dans( MAGASIN, 'readwrite', function ( m ) {
			oeuvres.forEach( function ( o ) {
				// Datée comme toute mise en réserve : c'est ce que
				// l'élagage lit pour savoir quoi couper en premier.
				o._cacheLe = maintenant;
				m.put( o );
			} );

			// Nettoyage : une œuvre retirée côté serveur ne doit pas
			// survivre indéfiniment dans le cache de l'appareil.
			disparues.forEach( function ( id ) { m.delete( id ); } );

			return null;
		} ).then( function () { return true; } ).catch( function () { return false; } );
	}

	/* ------------------------------------------------------------------
	 * Synchronisation descendante
	 * ---------------------------------------------------------------- */

	function derniereSync() {
		try {
			return window.localStorage.getItem( CLE_SYNC ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function noteSync( horodatage ) {
		try {
			window.localStorage.setItem( CLE_SYNC, horodatage );
		} catch ( e ) { /* navigation privée : on repartira de zéro */ }
	}

	/**
	 * Rapatrie ce qui a changé depuis la dernière fois.
	 *
	 * `since` évite de retélécharger deux cents œuvres à chaque visite.
	 * La liste complète des identifiants connus sert, elle, à repérer ce
	 * qui a disparu — une suppression ne se signale par aucune date.
	 */
	/**
	 * Une lecture du site, avec un jeton qui n'est pas périmé.
	 *
	 * ⚠️ `CFG.nonce` voyage dans la page. Servie depuis un cache — le
	 * navigateur, un proxy, l'hébergeur — elle porte un jeton mort, et
	 * toutes ces lectures répondaient 403. Le rapatriement échouait, le
	 * `catch` de la mise en route l'avalait, et « Vos œuvres » restait
	 * désespérément vide. Constaté sur le site.
	 *
	 * La file sait renouveler un jeton sans reconnexion : on lui demande,
	 * et on rejoue **une** fois.
	 */
	async function lire( url ) {
		function entetes() {
			var frais = window.afkSync && window.afkSync.jeton ? window.afkSync.jeton() : '';

			return { 'X-WP-Nonce': frais || CFG.nonce };
		}

		var r = await window.fetch( url, { credentials: 'same-origin', headers: entetes() } );

		if ( 403 !== r.status || ! window.afkSync || ! window.afkSync.renouvelerJeton ) {
			return r;
		}

		var corps = null;

		try {
			corps = await r.clone().json();
		} catch ( e ) { /* une réponse illisible reste un refus */ }

		if ( ! corps || 'rest_cookie_invalid_nonce' !== corps.code ) {
			return r;
		}

		if ( ! await window.afkSync.renouvelerJeton() ) {
			return r;
		}

		return window.fetch( url, { credentials: 'same-origin', headers: entetes() } );
	}

	async function rafraichir( complet ) {
		var depuis = complet ? '' : derniereSync();
		var url    = CFG.base + 'oeuvres' + ( depuis ? '?since=' + encodeURIComponent( depuis ) : '' );

		var reponse = await lire( url );

		if ( ! reponse.ok ) {
			throw new Error( 'HTTP ' + reponse.status );
		}

		var donnees = await reponse.json();
		var connus  = donnees.connus || [];
		var locales = await toutes();

		var disparues = locales
			.map( function ( o ) { return o.wpPostId; } )
			.filter( function ( id ) { return connus.indexOf( id ) === -1; } );

		// ⚠️ La liste ne porte pas le détail : elle ne doit pas l'effacer.
		//
		// Chaque œuvre reçue remplaçait la copie locale entière. Or la
		// liste ne transporte ni les valeurs des champs ni l'adresse des
		// visuels — délibérément, deux cents œuvres avec leurs trente-six
		// champs feraient une réponse que le réseau mobile ne pardonne
		// pas. Le détail rapatrié à grand-peine disparaissait donc au
		// premier rafraîchissement, et l'œuvre redevenait consultable
		// seulement avec du réseau.
		//
		// Il n'est conservé que si l'empreinte n'a pas bougé : sinon les
		// valeurs gardées seraient périmées, ce qui est pire que pas de
		// valeurs du tout.
		var connues = {};

		locales.forEach( function ( o ) { connues[ String( o.wpPostId ) ] = o; } );

		( donnees.oeuvres || [] ).forEach( function ( o ) {
			var avant = connues[ String( o.wpPostId ) ];

			if ( ! avant || o.champs || ! avant.champs || avant.hash !== o.hash ) {
				return;
			}

			o.champs = avant.champs;

			if ( avant.medias ) {
				o.medias = avant.medias;
			}
		} );

		await enregistre( donnees.oeuvres || [], disparues );
		noteSync( donnees.serveur );

		// ⚠️ Réconciliation, et elle n'est pas théorique.
		//
		// L'horodatage de dernière synchronisation vit dans
		// `localStorage`, les œuvres dans IndexedDB. Les deux se vident
		// séparément — nettoyage du navigateur, éviction pour cause de
		// quota, déconnexion. Avec un horodatage récent et une réserve
		// vide, le serveur ne renvoyait plus que « ce qui a changé
		// depuis », c'est-à-dire rien, et la liste restait
		// **définitivement** privée des œuvres déjà publiées. Constaté sur
		// le site.
		//
		// Le serveur dit pourtant ce qu'il détient : il suffit de
		// comparer, et de tout redemander une fois si l'on manque quelque
		// chose. `complet` empêche la boucle.
		if ( ! complet ) {
			var apres = await toutes();
			var ids   = apres.map( function ( o ) { return o.wpPostId; } );

			var manquantes = connus.filter( function ( id ) {
				return ids.indexOf( id ) === -1;
			} );

			// ⚠️ Une œuvre présente peut être périmée.
			//
			// `?since=` repose sur la date de modification, et le site en
			// change le statut sans toujours la toucher : une œuvre
			// validée depuis WordPress restait « En attente de validation »
			// sur l'appareil jusqu'à ce que l'artiste modifie autre chose.
			// Constaté sur le site, œuvre 5463, publiée et affichée en
			// attente.
			//
			// Le serveur dit maintenant l'état de chacune. Un seul écart
			// suffit à tout redemander : c'est une requête, contre un
			// artiste qui croit son œuvre encore en relecture.
			var etats = donnees.etats || {};

			var perimees = apres.filter( function ( o ) {
				var vu = etats[ String( o.wpPostId ) ];

				return vu && vu !== o.publicationStatus;
			} );

			if ( manquantes.length || perimees.length ) {
				return rafraichir( true );
			}
		}

		// Le cache suit la vie des œuvres : ce qui vient d'arriver est
		// daté, ce qui a disparu est retiré, et l'on repasse sous les
		// limites dans la foulée — jamais à un moment choisi au hasard.
		var elague = await DB.elaguer();

		// ⚠️ Prévenir ce qui affiche. Sans cela, la liste rafraîchie au
		// retour sur l'onglet gardait à l'écran ce qu'elle avait dessiné à
		// l'ouverture : la copie locale était juste, l'écran non.
		if ( ( donnees.oeuvres || [] ).length || disparues.length ) {
			document.dispatchEvent( new CustomEvent( 'afkOeuvresMaj', {
				detail: { recues: ( donnees.oeuvres || [] ).length, retirees: disparues.length }
			} ) );
		}

		return {
			recues: ( donnees.oeuvres || [] ).length,
			retirees: disparues.length,
			elaguees: elague.oeuvres + elague.medias
		};
	}

	/**
	 * Rapatrie le détail des œuvres, pour pouvoir les modifier sans réseau.
	 *
	 * ⚠️ La liste ne porte pas les valeurs des champs, délibérément :
	 * deux cents œuvres avec leurs trente-six champs feraient une réponse
	 * que le réseau mobile ne pardonnerait pas. Mais sans ces valeurs,
	 * **aucune modification hors connexion n'est possible** — le
	 * formulaire s'ouvrirait vide.
	 *
	 * Le détail est donc rapatrié à part, une œuvre à la fois, et
	 * seulement pour celles qui ne l'ont pas encore. Un artiste en a
	 * quelques-unes ; le plafond protège des comptes qui en ont des
	 * centaines.
	 */
	async function detaillerLesOeuvres() {
		var PLAFOND = 40;
		var oeuvres = await toutes();
		var faites  = 0;

		for ( var i = 0; i < oeuvres.length && faites < PLAFOND; i++ ) {
			var o = oeuvres[ i ];

			if ( ! o.wpPostId || o.champs ) {
				continue;
			}

			try {
				var r = await lire( CFG.base + 'oeuvres/' + o.wpPostId );

				if ( ! r.ok ) {
					continue;
				}

				var detail = await r.json();

				await DB.ecrire( MAGASIN, detail );
				await rapatrieLesVisuels( detail );

				faites++;
			} catch ( e ) {
				// Réseau perdu en cours de route : on reprendra plus tard.
				break;
			}
		}

		return faites;
	}

	/**
	 * Rapatrie les visuels d'une œuvre pour qu'ils s'affichent sans réseau.
	 *
	 * ⚠️ Les photos prises sur cet appareil étaient les seules à survivre
	 * à une coupure. Elles ont laissé leurs octets en passant ; celles qui
	 * viennent du site n'avaient jamais été téléchargées. Ouverte sur un
	 * second téléphone — ou après un nettoyage du navigateur — une œuvre
	 * s'affichait donc sans aucun visuel, alors que ses trente-six champs
	 * étaient là. Constaté en vidant la réserve locale à la main.
	 *
	 * C'est la version réduite qui est gardée, jamais l'originale : sur un
	 * forfait mobile, rapatrier des photos de plusieurs mégaoctets pour
	 * les afficher dans une fiche serait une dépense qu'on n'a pas
	 * demandée à l'artiste.
	 *
	 * Ce qui échoue est passé sous silence : un visuel manquant n'est pas
	 * une raison de perdre les autres, ni de faire échouer la lecture.
	 *
	 * @param {Object} oeuvre L'œuvre, avec sa table `medias`.
	 * @return {number} Visuels rapatriés.
	 */
	async function rapatrieLesVisuels( oeuvre ) {
		var table = ( oeuvre && oeuvre.medias ) || {};
		var cles  = Object.keys( table );

		if ( ! cles.length || ! window.navigator.onLine ) {
			return 0;
		}

		var faits = 0;

		for ( var i = 0; i < cles.length && faits < 12; i++ ) {
			// ⚠️ Le même type de clé que dans les champs de l'œuvre.
			//
			// IndexedDB distingue 42 de « 42 » : rangé sous la chaîne, le
			// visuel serait introuvable pour la fiche, qui cherche par le
			// nombre lu dans les valeurs. Rien n'aurait signalé l'écart —
			// juste une œuvre sans image, à nouveau.
			var id = /^[0-9]+$/.test( cles[ i ] ) ? parseInt( cles[ i ], 10 ) : cles[ i ];
			var ou = table[ cles[ i ] ] || {};

			if ( ! ou.apercu ) {
				continue;
			}

			// Déjà sous la main : ni requête ni écriture. Le passage se
			// contente de rafraîchir la date pour que l'élagage ne les
			// coupe pas juste après les avoir cherchés.
			var deja = await DB.lire( 'medias', id );

			if ( deja && deja.apercu ) {
				deja.depuis = Date.now();
				await DB.ecrire( 'medias', deja );
				continue;
			}

			try {
				var r = await window.fetch( ou.apercu, { credentials: 'same-origin' } );

				if ( ! r.ok ) {
					continue;
				}

				await DB.ecrire( 'medias', {
					id: id,
					url: ou.url || ou.apercu,
					nom: ou.nom || '',
					apercu: await r.blob(),
					depuis: Date.now()
				} );

				faits++;
			} catch ( e ) {
				// Réseau perdu en cours de route : on reprendra au prochain
				// passage, et les visuels déjà pris restent.
				break;
			}
		}

		return faits;
	}

	/**
	 * Le détail d'une œuvre, tel que le site le détient à l'instant.
	 *
	 * ⚠️ La copie locale peut être vieille de plusieurs jours, ou n'avoir
	 * jamais existé : `detaillerLesOeuvres()` ne rapatrie que ce qui manque
	 * et s'arrête à quarante. L'aperçu d'une œuvre modifiée depuis un autre
	 * appareil, ou jamais détaillée, montrait donc des valeurs périmées —
	 * ou rien du tout — à quelqu'un qui avait du réseau.
	 *
	 * Ce qui revient est écrit dans la réserve au passage : la prochaine
	 * consultation hors connexion en profite.
	 *
	 * @param {number} wpPostId Œuvre.
	 * @return {Object|null} L'œuvre à jour, ou la copie locale si le site
	 *                       n'a pas répondu.
	 */
	async function detail( wpPostId ) {
		var id = parseInt( wpPostId, 10 );

		if ( ! id ) {
			return null;
		}

		try {
			var r = await lire( CFG.base + 'oeuvres/' + id );

			if ( ! r.ok ) {
				return DB.lire( MAGASIN, id );
			}

			var oeuvre = await r.json();

			await DB.ecrire( MAGASIN, oeuvre );
			await rapatrieLesVisuels( oeuvre );

			return oeuvre;
		} catch ( e ) {
			return DB.lire( MAGASIN, id );
		}
	}

	/**
	 * Demande au service worker de garder les pages du module.
	 *
	 * ⚠️ Sans cela, la consultation hors connexion exigeait d'avoir
	 * ouvert chaque page au moins une fois avec du reseau — un prealable
	 * qu'aucun artiste ne connait, et dont l'oubli ressemble a une panne.
	 *
	 * La demande part d'ici, et non de l'installation du service worker :
	 * cette page-ci sait que l'artiste est connecte. Rechauffer trop tot
	 * mettrait en cache une invitation a se connecter, qui s'afficherait
	 * ensuite hors connexion a la place des oeuvres.
	 */
	function rechauffePages() {
		if ( ! CFG.pages || ! CFG.pages.length ) {
			return;
		}

		if ( ! navigator.serviceWorker || ! navigator.serviceWorker.controller ) {
			return;
		}

		navigator.serviceWorker.controller.postMessage( {
			type: 'afk-rechauffe',
			urls: CFG.pages
		} );
	}

	/**
	 * Met le schema du formulaire en reserve.
	 *
	 * Fait depuis TOUTES les pages, et non depuis la seule page de depot :
	 * un artiste qui ne l'a jamais ouverte avec du reseau ne pourrait
	 * rien saisir hors connexion, et le module n'aurait plus qu'a le lui
	 * annoncer.
	 */
	async function reserveSchema() {
		try {
			var reponse = await lire( CFG.base + 'schema' );

			if ( ! reponse.ok ) {
				return;
			}

			window.localStorage.setItem( 'afkOeuvreSchema', JSON.stringify( await reponse.json() ) );
		} catch ( e ) { /* on gardera celui de la derniere fois */ }
	}

	/* ------------------------------------------------------------------
	 * Reprise en main, hors connexion
	 * ---------------------------------------------------------------- */

	var T = CFG.textes || {};

	/**
	 * Deux registres d'état, et il ne faut pas les confondre.
	 *
	 * ⚠️ L'état de publication dit où en est l'œuvre **sur le site** ;
	 * l'état d'envoi dit où en est sa transmission **depuis cet
	 * appareil**. Une œuvre peut très bien être en ligne et porter une
	 * modification qui n'est pas encore partie.
	 *
	 * Les afficher dans une seule pastille ferait croire qu'une œuvre
	 * « en validation » n'est pas partie, ou qu'une œuvre « en attente
	 * d'envoi » a été refusée.
	 */
	/**
	 * Le nom de l'état de publication.
	 *
	 * ⚠️ `envoi` n'entre en ligne de compte que pour une œuvre qui n'est
	 * jamais partie. Elle n'a pas d'état sur AFRICART — il n'y a rien à
	 * dire de là-bas — et la pastille peut donc porter où en est la
	 * saisie. Sur une œuvre du site, elle continue de dire ce que le site
	 * en fait : « En attente de validation » reste vrai même pendant
	 * qu'on la corrige ici, et le remplacer effacerait la seule chose que
	 * l'artiste ne peut pas deviner.
	 */
	function libelle( etat, envoi ) {
		if ( 'LOCAL' === etat && 'EN_COURS' === envoi ) {
			return T.etat_en_cours || 'Saisie en cours';
		}

		var table = {
			// ⚠️ Une œuvre jamais envoyée n'a pas de statut sur le site :
			// lui prêter « Brouillon » laissait croire qu'elle s'y trouve.
			LOCAL:       T.etat_local || 'Jamais envoyée',
			DRAFT:       T.etat_draft,
			REVIEW:      T.etat_review,
			PUBLISHED:   T.etat_published,
			REJECTED:    T.etat_rejected,
			UNPUBLISHED: T.etat_unpublished
		};

		return table[ etat ] || etat || '';
	}

	/**
	 * Ce que dit l'état de synchronisation, et s'il appelle un geste.
	 *
	 * ⚠️ Chaque entrée porte son verbe. « En attente » se subit ; « À
	 * compléter » se fait. Une carte qui annonce un problème sans dire
	 * quoi en faire laisse l'artiste devant un mur.
	 */
	var SYNCHRO = {
		SYNCING:    { texte: 'Envoi en cours',        rondelle: true },
		ATTENTE:    { texte: 'Pas encore envoyée',    ton: 'attente' },
		NON_ENVOYE: { texte: 'Modifications non envoyées', ton: 'attente' },
		// ⚠️ Une saisie inachevée n'est pas une saisie qui attend.
		//
		// Les deux disaient « Modifications non envoyées », ce qui laissait
		// croire qu'il suffisait d'appuyer sur « Envoyer » — alors qu'il
		// manque un champ obligatoire et que rien ne partira. L'artiste
		// appuyait, il ne se passait rien, et le message ne changeait pas.
		EN_COURS:   { texte: 'Modification en cours', ton: 'attente', geste: 'editer' },
		// ⚠️ « Photo à reprendre » ne disait pas ce qui s'était passé.
		//
		// L'artiste voyait ses photos dans le formulaire et l'application
		// lui disait de les reprendre : il ne pouvait qu'en conclure que
		// le message se trompait. Il faut dire que c'est le FICHIER qui
		// n'est plus sur l'appareil, ce qui est une autre affaire — et que
		// la reprendre est le seul remède.
		PHOTO:      { texte: 'Photo perdue sur l’appareil — à reprendre', ton: 'grave', geste: 'editer' },
		INCOMPLET:  { texte: 'À compléter',           ton: 'grave', geste: 'editer' },
		// ⚠️ Un échec passé, pas un refus. Elle repartira telle quelle :
		// c'est « réessayer » qu'il faut dire, et non « corriger », qui
		// enverrait l'artiste chercher une faute qu'il n'a pas commise.
		RETENTE:    { texte: 'Dernier envoi échoué — à réessayer', ton: 'attente' },
		CONFLICT:   { texte: 'Deux versions',         ton: 'grave', geste: 'comparer' },
		ERROR:      { texte: 'Envoi refusé',          ton: 'grave', geste: 'editer' }
	};

	function libelleEnvoi( etat ) {
		// ⚠️ `SYNCING` avait le libellé de `PENDING` : « Envoi en attente »
		// s'affichait sur une œuvre en train de partir, à côté d'une
		// rondelle qui tournait. Deux signaux contradictoires sur la même
		// pastille — et c'est le texte qu'on croit.
		var table = {
			PENDING:   T.sync_attente,
			SYNCING:   T.sync_encours || 'Envoi en cours',
			INCOMPLET: T.sync_incomplet || 'À compléter',
			CONFLICT:  T.sync_conflit,
			ERROR:     T.sync_erreur
		};

		if ( table[ etat ] ) {
			return table[ etat ];
		}

		// ⚠️ Le vocabulaire des cartes en renfort.
		//
		// Cette table-ci ne connaît que les états bruts de la file. La
		// fiche de détail, elle, reçoit le jugement des cartes —
		// « Pas encore envoyée », « Photo perdue », « À réessayer » — et
		// n'en trouvait aucun ici : la ligne d'état d'envoi s'affichait
		// VIDE sur les cinq cas les plus courants. L'artiste ouvrait une
		// œuvre pour savoir où elle en était, et lisait une ligne blanche.
		return ( SYNCHRO[ etat ] && SYNCHRO[ etat ].texte ) || '';
	}

	/**
	 * La rondelle d'attente.
	 *
	 * ⚠️ Dessinée en CSS sur un élément vide, et marquée `aria-hidden` :
	 * une animation décorative n'a rien à dire à un lecteur d'écran, qui
	 * lit déjà le libellé de la pastille à côté.
	 */
	function rondelle() {
		var r = document.createElement( 'span' );
		r.className = 'afk-rondelle';
		r.setAttribute( 'aria-hidden', 'true' );

		return r;
	}

	function carte( o, envoi ) {
		var article = document.createElement( 'article' );
		article.className = 'afk-oeuvre';

		var lien = document.createElement( 'a' );
		lien.className = 'afk-oeuvre-lien';

		// ⚠️ Hors connexion, la fiche publique n'est pas consultable :
		// l'ouvrir mènerait à la page hors-ligne. La carte mène donc à
		// l'éditeur, ce qui est de toute façon ce qu'on vient y faire.
		//
		// Le paramètre passe par le **fragment** et non par la requête :
		// une adresse avec « ? » ne correspond plus à l'entrée mise en
		// cache par le service worker, et la page ne s'ouvrirait pas du
		// tout. Le fragment, lui, ne part jamais au serveur.
		if ( CFG.editeur ) {
			lien.href = CFG.editeur + ( o.wpPostId
				? '#oeuvre=' + o.wpPostId
				: '#brouillon=' + encodeURIComponent( o.localId || '' ) );
		} else {
			lien.href = o.lien || '#';
		}

		if ( o.vignette ) {
			var img = document.createElement( 'img' );
			img.className = 'afk-oeuvre-image';
			img.src = o.vignette;
			img.alt = '';
			img.loading = 'lazy';
			lien.appendChild( img );
		} else {
			// ⚠️ Un dessin, et non un rectangle gris.
			//
			// Une case vide de la couleur du fond se lit comme une image
			// qui n'a pas fini de charger : l'artiste attendait qu'elle
			// arrive. Un cadre avec un motif dit autre chose — il n'y a
			// pas de photo, et c'est un état, pas un chargement.
			//
			// Dessiné ici plutôt que chargé : une image de remplacement
			// qui échouerait hors connexion, précisément là où cet écran
			// sert, remplacerait un vide par un vide cassé.
			lien.appendChild( sansVisuel() );
		}

		var corps = document.createElement( 'div' );
		corps.className = 'afk-oeuvre-corps';

		var titre = document.createElement( 'h3' );
		titre.className = 'afk-oeuvre-titre';
		titre.textContent = o.titre || '(sans titre)';
		corps.appendChild( titre );

		// ⚠️ Une seule pastille, et elle ne parle que du site.
		//
		// Deux pastilles de même forme — « Brouillon » et « Envoi en
		// attente » — se lisaient comme deux valeurs d'une même chose,
		// alors qu'elles répondent à deux questions sans rapport : où en
		// est l'œuvre sur AFRICART, et où en est-elle sur cet appareil.
		// La seconde descend d'un cran, sous une autre forme.
		var pastilles = document.createElement( 'div' );
		pastilles.className = 'afk-oeuvre-pastilles';

		var etat = document.createElement( 'span' );
		etat.className = 'afk-oeuvre-etat afk-etat-' + String( o.publicationStatus || '' ).toLowerCase();
		etat.textContent = libelle( o.publicationStatus, envoi );
		pastilles.appendChild( etat );

		corps.appendChild( pastilles );

		// ⚠️ L'état de l'appareil, sous une autre forme et seulement quand
		// il y a quelque chose à dire. Une œuvre à jour ne porte rien : le
		// silence est le signe qu'il n'y a rien à faire.
		var dit = SYNCHRO[ envoi ];

		// ⚠️ Une création jamais partie le dit déjà sur l'axe du site :
		// « Jamais envoyée » suivi de « Modifications non envoyées »
		// répétait la même chose en deux formulations différentes, ce qui
		// donne à croire qu'il s'agit de deux problèmes.
		// ⚠️ Une création jamais partie le dit déjà sur l'axe du site :
		// « Jamais envoyée » ou « Saisie en cours » suivi de la même chose
		// en dessous répétait un seul fait en deux formulations, ce qui
		// donne à croire qu'il s'agit de deux problèmes.
		if ( 'LOCAL' === o.publicationStatus
			&& ( 'NON_ENVOYE' === envoi || 'EN_COURS' === envoi ) ) {
			dit = null;
		}

		if ( dit ) {
			var ligne = document.createElement( dit.geste ? 'button' : 'p' );
			ligne.className = 'afk-oeuvre-sync afk-sync-' + ( dit.ton || 'marche' );

			if ( dit.geste ) {
				ligne.type = 'button';
				ligne.setAttribute( 'data-afk-geste', dit.geste );
			}

			if ( dit.rondelle ) {
				// ⚠️ Une classe, plutôt qu'un `:has()` côté style.
				//
				// `:has()` n'est arrivé qu'avec Safari 15.4 : sur un iPhone
				// resté en deçà, la règle entière est ignorée et la puce
				// s'affichait à côté de la rondelle qui devait la
				// remplacer. Le module s'en tient à des classes posées ici
				// — c'est la règle qu'on s'était donnée, et celle-ci y
				// avait échappé.
				ligne.classList.add( 'afk-oeuvre-sync-rondelle' );
				ligne.appendChild( rondelle() );
				article.setAttribute( 'aria-busy', 'true' );
			}

			ligne.appendChild( document.createTextNode(
				( T[ 'sync_' + String( envoi ).toLowerCase() ] ) || dit.texte
			) );

			corps.appendChild( ligne );

			// ⚠️ Une sortie, à côté du reproche.
			//
			// « Photo à reprendre » sur une œuvre déjà en ligne n'appelait
			// aucun geste possible : le fichier avait disparu de
			// l'appareil, la modification ne partirait jamais, et le
			// compte des « à corriger » ne redescendait plus. L'artiste
			// n'avait pas tort de parler d'un cache à vider — c'était bien
			// cela, et rien ne le permettait.
			//
			// Seulement sur une œuvre du site : renoncer y écarte une
			// demande locale sans rien perdre, puisque la version de
			// là-bas redescendra. Sur une création jamais partie, ce serait
			// une suppression déguisée — la corbeille est là pour ça.
			if ( 'grave' === dit.ton && o.wpPostId ) {
				var renoncer = document.createElement( 'button' );
				renoncer.type = 'button';
				renoncer.className = 'afk-oeuvre-renoncer';
				renoncer.setAttribute( 'data-afk-abandon', String( o.wpPostId ) );
				renoncer.textContent = T.sync_renoncer || 'Abandonner cette modification';
				corps.appendChild( renoncer );
			}
		}

		lien.appendChild( corps );
		article.appendChild( lien );

		return article;
	}

	/**
	 * Le cadre qui remplace une photo absente.
	 *
	 * Un cadre, une montagne, un soleil : le pictogramme universel de
	 * l'image, que personne n'a besoin d'apprendre.
	 */
	function sansVisuel() {
		var boite = document.createElement( 'span' );
		boite.className = 'afk-oeuvre-image afk-oeuvre-image-vide';

		var svg = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svg.setAttribute( 'viewBox', '0 0 48 48' );
		svg.setAttribute( 'width', '44' );
		svg.setAttribute( 'height', '44' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );

		[
			// Le cadre.
			{ d: 'M7 11h34a2 2 0 0 1 2 2v22a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V13a2 2 0 0 1 2-2z', plein: 'none' },
			// La montagne et sa cime.
			{ d: 'M5 31l10-9 8 7 6-5 14 12', plein: 'none' },
			// Le soleil.
			{ d: 'M32 18.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0z', plein: 'none' }
		].forEach( function ( part ) {
			var trace = document.createElementNS( 'http://www.w3.org/2000/svg', 'path' );
			trace.setAttribute( 'd', part.d );
			trace.setAttribute( 'fill', part.plein );
			trace.setAttribute( 'stroke', 'currentColor' );
			trace.setAttribute( 'stroke-width', '2' );
			trace.setAttribute( 'stroke-linecap', 'round' );
			trace.setAttribute( 'stroke-linejoin', 'round' );
			svg.appendChild( trace );
		} );

		boite.appendChild( svg );

		var dit = document.createElement( 'span' );
		dit.className = 'afk-oeuvre-image-mot';
		dit.textContent = T.liste_sans_photo || 'Sans photo';
		boite.appendChild( dit );

		return boite;
	}

	function dateLisible( iso ) {
		if ( ! iso ) {
			return '';
		}
		try {
			return new Date( iso ).toLocaleString( CFG.langue || 'fr-FR', {
				day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit'
			} );
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Remplace la grille du thème par les œuvres en cache.
	 *
	 * Uniquement la grille. Toucher davantage reviendrait à reconstruire
	 * une page que le thème sait déjà rendre.
	 */
	/**
	 * Ce qui attend de partir, par œuvre.
	 *
	 * @return {Promise<Object>} clé = identifiant, valeur = état d'envoi.
	 */
	/**
	 * L'état d'envoi de chaque œuvre, du point de vue de la file.
	 *
	 * ⚠️ Les états bruts de la file ne sont pas ceux des cartes.
	 *
	 * Cette liste-ci posait `PENDING` sur la carte, qui ne connaît pas ce
	 * mot : elle n'affichait donc **aucune** ligne d'envoi. Une œuvre dont
	 * la modification dormait sur l'appareil paraissait parfaitement à
	 * jour, sur l'écran même où l'artiste vient vérifier ce qui est parti.
	 * Seul `CONFLICT`, qui porte le même nom des deux côtés, passait.
	 *
	 * La file sait traduire — photo manquante, champ manquant, dernier
	 * envoi échoué — et « mes œuvres » lui demande déjà. Les deux listes
	 * disent désormais la même chose, dans les mêmes mots.
	 */
	function envoisEnAttente() {
		if ( window.afkSync && window.afkSync.etatsParCible ) {
			return window.afkSync.etatsParCible().catch( function () { return {}; } );
		}

		return Promise.resolve( {} );
	}

	function prendreLaMain( oeuvres, raison, envois, locales ) {
		var grille = document.querySelector( '.post-feed-grid' );

		if ( ! grille ) {
			return false;
		}

		var flux = grille.closest( '.ts-post-feed' ) || grille.parentNode;

		// Bandeau d'explication : sans lui, l'artiste croirait consulter
		// l'état réel du site alors qu'il regarde une copie.
		if ( ! document.querySelector( '.afk-oeuvres-avis' ) ) {
			// ⚠️ Deux situations distinctes, et les confondre trompe le
			// lecteur : « hors connexion » quand l'appareil n'a pas de
			// réseau, « site injoignable » quand il en a mais que le
			// serveur ne répond pas. Le second cas est fréquent en
			// mobilité — barres affichées, rien qui passe — et annoncer
			// une absence de réseau enverrait l'artiste vérifier son
			// forfait plutôt que patienter.
			var texte;

			if ( ! oeuvres.length ) {
				texte = T.vide || '';
			} else {
				var modele = ( 'injoignable' === raison ? T.injoignable : T.hors_ligne ) || '';
				texte = modele.replace( '%s', dateLisible( derniereSync() ) );
			}

			var avis = document.createElement( 'p' );
			avis.className = 'afk-oeuvres-avis';
			avis.textContent = texte;
			flux.parentNode.insertBefore( avis, flux );
		}

		grille.innerHTML = '';
		grille.classList.add( 'afk-oeuvres-grille' );

		// Les créations jamais parties d'abord : ce sont elles dont
		// l'artiste doute, et les enfouir en bas de liste ne le rassure pas.
		( locales || [] ).forEach( function ( o ) {
			grille.appendChild( carte( o, o._envoi ) );
		} );

		oeuvres.forEach( function ( o ) {
			var cle = envois && ( envois[ String( o.wpPostId ) ] || envois[ o.localId ] );
			grille.appendChild( carte( o, cle ) );
		} );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Mise en route
	 * ---------------------------------------------------------------- */

	async function demarrer() {
		// Toujours tenir le cache à jour quand le réseau le permet, même
		// si l'artiste n'est pas sur la page de ses œuvres : la
		// consultation hors connexion suppose une copie déjà prise.
		if ( window.navigator.onLine ) {
			try {
				await rafraichir();
			} catch ( e ) {
				// Le réseau se disait présent mais n'a pas répondu : on
				// bascule comme s'il était absent.
				if ( CFG.feed ) {
					await afficherCache( 'injoignable' );
				}
				return;
			}

			// Tout est en place pour le jour ou le reseau manquera : les
			// oeuvres, la coquille des pages, et le formulaire.
			rechauffePages();
			reserveSchema();
			detaillerLesOeuvres();

			// En ligne, le thème a rendu la page. On se tait.
			return;
		}

		if ( CFG.feed ) {
			await afficherCache( 'hors-ligne' );
		}
	}

	/**
	 * Assemble ce qui doit s'afficher sans réseau.
	 *
	 * Trois sources : les œuvres du serveur mises en cache, ce qui attend
	 * de partir, et les créations qui n'ont jamais quitté l'appareil.
	 */
	async function afficherCache( raison ) {
		var oeuvres = await toutes();
		var envois  = await envoisEnAttente();
		var locales = [];

		if ( window.afkOeuvresDB ) {
			var file = await window.afkOeuvresDB.tout( 'file' );

			file.forEach( function ( a ) {
				if ( 'creer' !== a.type ) {
					return;
				}

				var champs = ( a.charge && a.charge.champs ) || {};

				// ⚠️ `LOCAL`, pas `DRAFT` : elle n'a pas d'état sur
				// AFRICART, personne là-bas ne l'a jamais vue. La dire
				// « Brouillon » ici alors que « mes œuvres » la dit
				// « Jamais envoyée » laissait croire à deux œuvres.
				//
				// Et l'état d'envoi vient de la file traduite, comme
				// pour les autres cartes : l'état brut ne veut rien
				// dire à la pastille, qui l'ignorait en silence.
				locales.push( {
					wpPostId: 0,
					localId: a.cible,
					titre: champs.title || '',
					publicationStatus: 'LOCAL',
					lien: '#',
					vignette: '',
					_envoi: envois[ String( a.cible ) ] || ''
				} );
			} );
		}

		prendreLaMain( oeuvres, raison, envois, locales );
	}

	// Exposé pour la suite du module — file d'attente, écran de création,
	// et notre propre liste, qui rend les mêmes cartes plutôt que d'en
	// redéfinir de semblables à côté.
	window.afkOeuvres = {
		toutes: toutes,
		detail: detail,
		visuels: rapatrieLesVisuels,
		rafraichir: rafraichir,
		derniereSync: derniereSync,
		carte: carte,
		libelle: libelle,
		libelleEnvoi: libelleEnvoi,
		dateLisible: dateLisible,
		envois: envoisEnAttente,
		editeur: function () { return CFG.editeur || ''; }
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}

	// Le réseau revient : on rattrape le retard sans attendre un
	// rechargement, que l'artiste n'a aucune raison de provoquer.
	window.addEventListener( 'online', function () {
		rafraichir().catch( function () {} );
	} );

	// ⚠️ Et au retour sur l'onglet.
	//
	// La validation d'une œuvre ne se décide pas sur cet appareil : elle
	// arrive pendant que l'artiste regarde ailleurs. Sans ce réveil, sa
	// liste gardait l'état du dernier chargement — parfois vieux d'une
	// journée — et il n'avait aucune raison de recharger une page qui,
	// pour lui, était déjà à jour.
	//
	// Une minute de garde : revenir trois fois sur l'onglet en dix
	// secondes ne justifie pas trois requêtes.
	var dernierReveil = 0;

	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden || ! window.navigator.onLine ) {
			return;
		}

		if ( Date.now() - dernierReveil < 60 * 1000 ) {
			return;
		}

		dernierReveil = Date.now();
		rafraichir().catch( function () {} );
	} );
}() );
