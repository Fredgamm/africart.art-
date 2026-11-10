/**
 * Journal de diagnostic — ce que l'appareil a réellement fait.
 *
 * ⚠️ Écrit pour un cas précis : « Dernier envoi refusé : Aucun fichier
 * reçu », sur iPhone, par intermittence. Le message vient du serveur, qui
 * dit vrai — il n'a rien reçu — mais ne dit pas pourquoi, et rien côté
 * appareil ne permettait de trancher entre une photo devenue illisible,
 * un corps de requête rejeté, et un réseau coupé en plein transfert.
 *
 * Trois principes :
 *
 *   — il ENREGISTRE toujours, il n'AFFICHE que sur demande. Un défaut qui
 *     n'arrive qu'une fois sur dix ne se reproduit pas sur commande :
 *     attendre l'ouverture du panneau pour commencer à noter aurait
 *     oblige l'artiste à provoquer le défaut une seconde fois ;
 *   — il survit au rechargement. Une page qui se recharge — ou un
 *     onglet que le téléphone recycle sous la pression mémoire, ce que
 *     fait iOS sans prévenir — emporterait tout ;
 *   — il se copie d'un geste. Le diagnostic ne sert que s'il arrive
 *     jusqu'à quelqu'un qui peut le lire.
 *
 * Il s'ouvre en ajoutant `?afk_debug=1` à l'adresse. Le panneau reste
 * ensuite accessible le temps de la session de navigation, par un bouton
 * discret : l'artiste peut donc naviguer, reproduire le défaut, puis
 * revenir le lire.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var CLE      = 'afkJournal';
	var CLE_VU   = 'afkJournalOuvert';
	var MAX      = 240;
	var journal  = [];
	var enAttente = null;
	var panneau  = null;

	/* ------------------------------------------------------------------
	 * La réserve
	 * ---------------------------------------------------------------- */

	function relire() {
		try {
			var brut = window.localStorage.getItem( CLE );

			if ( brut ) {
				journal = JSON.parse( brut ) || [];
			}
		} catch ( e ) {
			journal = [];
		}
	}

	/**
	 * ⚠️ Différée, et regroupée.
	 *
	 * Écrire à chaque ligne aurait fait de l'outil de diagnostic une cause
	 * de lenteur — et donc une cause de défauts à lui seul, sur l'appareil
	 * même qu'on essaie d'observer.
	 */
	function ranger() {
		if ( enAttente ) {
			return;
		}

		enAttente = window.setTimeout( function () {
			enAttente = null;

			try {
				window.localStorage.setItem( CLE, JSON.stringify( journal ) );
			} catch ( e ) {
				// Réserve pleine : le journal cède la place au dépôt, qui
				// compte davantage. On garde ce qu'on peut en mémoire.
				journal = journal.slice( -40 );
			}
		}, 400 );
	}

	/* ------------------------------------------------------------------
	 * Écrire
	 * ---------------------------------------------------------------- */

	/**
	 * Note un fait.
	 *
	 * @param {string} sujet   Court, en majuscules : « PHOTO », « ENVOI ».
	 * @param {Object} donnees Ce qu'on sait. Tout est aplati en texte.
	 */
	function note( sujet, donnees ) {
		journal.push( {
			t: new Date().toISOString().slice( 11, 23 ),
			s: String( sujet ),
			d: lisible( donnees )
		} );

		if ( journal.length > MAX ) {
			journal = journal.slice( -MAX );
		}

		ranger();

		if ( panneau ) {
			dessine();
		}
	}

	/**
	 * ⚠️ Aplati ici, pas à l'affichage.
	 *
	 * Une erreur, un `Blob`, un nœud du document ne survivent pas à
	 * `JSON.stringify` : on aurait rangé « {} » et perdu précisément ce
	 * qu'on cherchait à voir.
	 */
	function lisible( v ) {
		if ( null === v || undefined === v ) {
			return '';
		}

		if ( 'object' !== typeof v ) {
			return String( v );
		}

		if ( v instanceof Error ) {
			return v.name + ' : ' + v.message;
		}

		var bouts = [];

		Object.keys( v ).forEach( function ( cle ) {
			var val = v[ cle ];

			if ( val && 'object' === typeof val && ! ( val instanceof Error ) ) {
				val = lisible( val );
			} else if ( val instanceof Error ) {
				val = val.name + ' : ' + val.message;
			}

			bouts.push( cle + '=' + String( val ) );
		} );

		return bouts.join( ' ' );
	}

	/* ------------------------------------------------------------------
	 * Ce qu'on veut savoir d'un fichier
	 * ---------------------------------------------------------------- */

	/**
	 * L'état réel d'une photo gardée sur l'appareil.
	 *
	 * ⚠️ Elle est LUE, pas seulement décrite.
	 *
	 * Sur iOS, un fichier choisi dans l'appareil photo n'est pas copié : ce
	 * qu'on garde est une référence vers un fichier temporaire que le
	 * système efface quand il veut. L'objet survit — il annonce toujours sa
	 * taille et son nom — mais ses octets ne sont plus là. Décrire l'objet
	 * aurait donc affiché une photo parfaitement saine. Il faut en demander
	 * un octet pour savoir.
	 *
	 * @param {Blob} blob Le fichier à examiner.
	 * @return {Promise<Object>} Ce qu'on a pu en tirer.
	 */
	async function ausculte( blob ) {
		if ( ! blob ) {
			return { present: 'non' };
		}

		var vu = {
			present: 'oui',
			genre: ( blob instanceof window.File ) ? 'File' : 'Blob',
			type: blob.type || '(sans type)',
			annonce: blob.size
		};

		try {
			// Un seul octet suffit à savoir si la source répond encore.
			var bout = await blob.slice( 0, 1 ).arrayBuffer();
			vu.lisible = bout && bout.byteLength ? 'oui' : 'vide';
		} catch ( e ) {
			vu.lisible = 'NON (' + ( e && e.name ? e.name : 'erreur' ) + ')';
		}

		return vu;
	}

	/* ------------------------------------------------------------------
	 * Le panneau
	 * ---------------------------------------------------------------- */

	/**
	 * Le genre de lien, quand le navigateur veut bien le dire.
	 *
	 * ⚠️ « En ligne » ne dit rien du CHEMIN emprunté, et c'est le chemin
	 * qui défait les corps d'une requête chez l'un et pas chez l'autre.
	 * Safari n'expose pas `connection` : l'écrire noir sur blanc évite de
	 * chercher plus tard pourquoi la ligne manque.
	 */
	function typeDeLien() {
		var c = window.navigator.connection
			|| window.navigator.mozConnection
			|| window.navigator.webkitConnection;

		if ( ! c ) {
			return 'genre inconnu (Safari ne le dit pas)';
		}

		return [
			c.effectiveType || '?',
			c.type || '',
			c.saveData ? 'économie de données' : ''
		].filter( Boolean ).join( ' ' );
	}

	function contexte() {
		var lignes = [
			'appareil : ' + window.navigator.userAgent,
			'écran : ' + window.innerWidth + '×' + window.innerHeight,
			'réseau : ' + ( window.navigator.onLine ? 'en ligne' : 'hors connexion' )
				+ ' — ' + typeDeLien(),
			'installée : ' + ( window.matchMedia( '(display-mode: standalone)' ).matches
				|| window.navigator.standalone ? 'oui' : 'non' ),
			'adresse : ' + window.location.pathname
		];

		return lignes.join( '\n' );
	}

	function texteEntier() {
		var lignes = journal.map( function ( e ) {
			return e.t + '  ' + e.s + '  ' + e.d;
		} );

		return contexte() + '\n\n' + lignes.join( '\n' );
	}

	function dessine() {
		if ( ! panneau ) {
			return;
		}

		var corps = panneau.querySelector( '.afk-journal-corps' );
		corps.textContent = texteEntier();
		corps.scrollTop = corps.scrollHeight;

		panneau.querySelector( '.afk-journal-compte' ).textContent =
			journal.length + ' ligne' + ( journal.length > 1 ? 's' : '' );
	}

	function ouvrir() {
		if ( panneau ) {
			panneau.hidden = false;
			dessine();
			return;
		}

		panneau = document.createElement( 'div' );
		panneau.className = 'afk-journal';
		panneau.setAttribute( 'role', 'dialog' );
		panneau.setAttribute( 'aria-label', 'Journal de diagnostic' );

		var tete = document.createElement( 'div' );
		tete.className = 'afk-journal-tete';

		var titre = document.createElement( 'strong' );
		titre.textContent = 'Journal';
		tete.appendChild( titre );

		var compte = document.createElement( 'span' );
		compte.className = 'afk-journal-compte';
		tete.appendChild( compte );

		tete.appendChild( bouton( 'Copier', async function ( b ) {
			var texte = texteEntier();
			var dit = function ( m ) {
				b.textContent = m;
				window.setTimeout( function () { b.textContent = 'Copier'; }, 1800 );
			};

			try {
				await window.navigator.clipboard.writeText( texte );
				dit( 'Copié' );
				return;
			} catch ( e ) {
				// Le presse-papiers est refusé hors contexte sûr, et sur
				// certains iOS même après un geste. Le repli sélectionne le
				// texte : l'artiste fait « Copier » lui-même, ce qui marche
				// partout.
			}

			var zone = panneau.querySelector( '.afk-journal-corps' );
			var plage = document.createRange();
			plage.selectNodeContents( zone );

			var choix = window.getSelection();
			choix.removeAllRanges();
			choix.addRange( plage );

			dit( 'Sélectionné — faites « Copier »' );
		} ) );

		tete.appendChild( bouton( 'Vider', function () {
			journal = [];

			try {
				window.localStorage.removeItem( CLE );
			} catch ( e ) { /* rien à vider */ }

			dessine();
		} ) );

		tete.appendChild( bouton( 'Fermer', function () {
			panneau.hidden = true;
		} ) );

		panneau.appendChild( tete );

		var corps = document.createElement( 'pre' );
		corps.className = 'afk-journal-corps';
		panneau.appendChild( corps );

		document.body.appendChild( panneau );
		dessine();
	}

	function bouton( texte, faire ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'afk-journal-bouton';
		b.textContent = texte;
		b.addEventListener( 'click', function () { faire( b ); } );

		return b;
	}

	/** La poignée qui rouvre le panneau, une fois le diagnostic demandé. */
	function poignee() {
		var p = document.createElement( 'button' );
		p.type = 'button';
		p.className = 'afk-journal-poignee';
		p.textContent = '🐞';
		p.setAttribute( 'aria-label', 'Ouvrir le journal de diagnostic' );
		p.addEventListener( 'click', ouvrir );
		document.body.appendChild( p );
	}

	/* ------------------------------------------------------------------
	 * Mise en route
	 * ---------------------------------------------------------------- */

	relire();

	// ⚠️ Ce qui casse tout seul se note tout seul. Une erreur de script
	// avortait le dépôt sans laisser la moindre trace une fois la page
	// rechargée — et c'est exactement ce qu'on cherche.
	window.addEventListener( 'error', function ( e ) {
		note( 'ERREUR', {
			message: e.message,
			ou: ( e.filename || '' ).split( '/' ).pop() + ':' + e.lineno
		} );
	} );

	window.addEventListener( 'unhandledrejection', function ( e ) {
		note( 'REJET', { raison: ( e.reason && e.reason.message ) || String( e.reason ) } );
	} );

	window.addEventListener( 'pagehide', function () {
		if ( enAttente ) {
			window.clearTimeout( enAttente );
			enAttente = null;
		}

		try {
			window.localStorage.setItem( CLE, JSON.stringify( journal ) );
		} catch ( e ) { /* tant pis */ }
	} );

	var demande = -1 !== window.location.search.indexOf( 'afk_debug=1' );

	if ( demande ) {
		try {
			window.sessionStorage.setItem( CLE_VU, '1' );
		} catch ( e ) { /* la poignée ne suivra pas d'une page à l'autre */ }
	}

	var actif = demande;

	if ( ! actif ) {
		try {
			actif = '1' === window.sessionStorage.getItem( CLE_VU );
		} catch ( e ) { /* pas de session : le paramètre reste seul maître */ }
	}

	function demarrer() {
		if ( ! actif ) {
			return;
		}

		poignee();

		if ( demande ) {
			ouvrir();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}

	window.afkJournal = {
		note: note,
		ausculte: ausculte,
		ouvrir: ouvrir,
		texte: texteEntier
	};
}() );
