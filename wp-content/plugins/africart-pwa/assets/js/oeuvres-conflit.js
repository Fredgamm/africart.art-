/**
 * Trancher un conflit de modification.
 *
 * ⚠️ Ce fichier comble le dernier vide du module. Le serveur refusait
 * déjà proprement une modification fondée sur une version périmée — il
 * répond 409 — et la file marquait l'action `CONFLICT` sans jamais
 * l'écraser. Mais l'artiste n'avait **aucun moyen de trancher** : son
 * travail restait bloqué là, indéfiniment, sans que rien ne lui dise
 * quoi faire. Un refus qu'on ne peut pas résoudre n'est pas une
 * protection, c'est une impasse.
 *
 * ⚠️ On ne choisit pas à sa place, et on ne fusionne pas. Fusionner deux
 * versions d'une œuvre champ par champ produirait une troisième version
 * que personne n'a voulue. On montre ce qui diffère, et on demande.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_OEUVRES || window.AFK_PWA_FORM || {};
	var DB  = window.afkOeuvresDB;

	if ( ! DB || CFG.apercu ) {
		return;
	}

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


	/** Types dont la valeur est une liste de visuels. */
	var VISUELS = [ 'image', 'file' ];

	function texteDe( valeur, champ ) {
		if ( Array.isArray( valeur ) ) {
			if ( VISUELS.indexOf( champ.type ) !== -1 ) {
				return valeur.length
					? valeur.length + ( valeur.length > 1 ? ' visuels' : ' visuel' )
					: 'aucun visuel';
			}

			return valeur.join( ', ' );
		}

		return null === valeur || undefined === valeur ? '' : String( valeur );
	}

	/**
	 * Ce qui diffère entre la version gardée ici et celle du site.
	 *
	 * ⚠️ Seuls les champs **présents dans la saisie** sont comparés. Une
	 * modification ne porte que sur ce que l'artiste a rempli ; réclamer
	 * une différence sur les autres montrerait des écarts qu'il n'a pas
	 * provoqués.
	 */
	function ecarts( miens, siens ) {
		var connus = DB.schema().champs || [];
		var liste  = [];

		connus.forEach( function ( champ ) {
			if ( ! Object.prototype.hasOwnProperty.call( miens, champ.key ) ) {
				return;
			}

			var a = texteDe( miens[ champ.key ], champ ).trim();
			var b = texteDe( siens[ champ.key ], champ ).trim();

			if ( a === b ) {
				return;
			}

			liste.push( {
				nom: String( champ.label || champ.key ).replace( /[\s*:]+$/, '' ),
				mien: a,
				sien: b
			} );
		} );

		return liste;
	}

	/* ------------------------------------------------------------------
	 * L'écran
	 * ---------------------------------------------------------------- */

	function boite( titre ) {
		var voile = document.createElement( 'div' );
		voile.className = 'afk-fiche afk-conflit';
		voile.setAttribute( 'role', 'dialog' );
		voile.setAttribute( 'aria-modal', 'true' );
		voile.setAttribute( 'aria-label', titre );

		var corps = document.createElement( 'div' );
		corps.className = 'afk-fiche-boite';

		function fermer() {
			voile.remove();
			document.removeEventListener( 'keydown', auClavier );
		}

		function auClavier( e ) {
			// ⚠️ Échap ferme sans rien décider. Un conflit non tranché
			// reste un conflit ; il ne se résout pas par abandon.
			if ( 'Escape' === e.key ) {
				fermer();
			}
		}

		var croix = document.createElement( 'button' );
		croix.type = 'button';
		croix.className = 'afk-fiche-fermer';
		croix.setAttribute( 'aria-label', 'Fermer' );
		croix.textContent = '×';
		croix.addEventListener( 'click', fermer );
		corps.appendChild( croix );

		var h = document.createElement( 'h2' );
		h.className = 'afk-fiche-titre';
		h.textContent = titre;
		corps.appendChild( h );

		voile.addEventListener( 'click', function ( e ) {
			if ( e.target === voile ) {
				fermer();
			}
		} );

		document.addEventListener( 'keydown', auClavier );
		voile.appendChild( corps );
		document.body.appendChild( voile );
		croix.focus();

		return { voile: voile, corps: corps, fermer: fermer };
	}

	function phrase( corps, texte ) {
		var p = document.createElement( 'p' );
		p.className = 'afk-fiche-detail';
		p.textContent = texte;
		corps.appendChild( p );

		return p;
	}

	/* ------------------------------------------------------------------
	 * Résolution
	 * ---------------------------------------------------------------- */

	/**
	 * Garde la version de l'artiste.
	 *
	 * ⚠️ On ne force rien côté serveur, et il n'existe aucun drapeau pour
	 * cela — délibérément. On réoriente simplement l'action sur
	 * l'empreinte **actuelle** : elle repart alors comme une modification
	 * ordinaire, et le verrou d'optimisme reste entier. Ajouter un
	 * « écraser quand même » aurait ouvert une porte que rien n'aurait
	 * refermée.
	 */
	async function garderLaMienne( action, fraiche ) {
		action.charge.hashAttendu = fraiche.hash || '';
		action.etat   = 'PENDING';
		action.erreur = null;
		action.bail   = 0;
		action.essais = 0;

		await DB.ecrire( 'file', action );

		if ( window.afkSync ) {
			await window.afkSync.vider();
		}
	}

	/**
	 * Garde la version du site.
	 *
	 * ⚠️ Le brouillon local part avec l'action. Le laisser ferait
	 * réapparaître la saisie abandonnée à la prochaine ouverture du
	 * formulaire, et l'artiste croirait son choix ignoré.
	 */
	async function garderLaSienne( action, fraiche ) {
		await DB.effacer( 'file', action.id );
		await DB.ecrire( 'oeuvres', fraiche );
		await DB.effacer( 'brouillons', 'oeuvre-' + fraiche.wpPostId );

		if ( window.afkSync ) {
			window.afkSync.annoncer();
		}
	}

	/** La version du site, telle qu'elle est maintenant. */
	async function versionDuSite( wpPostId ) {
		// Le jeton de la file, pas celui de la page : voir la note dans
		// `oeuvres.js`. Une page servie depuis un cache en porte un mort.
		var frais = window.afkSync && window.afkSync.jeton ? window.afkSync.jeton() : '';

		var r = await window.fetch( CFG.base + 'oeuvres/' + wpPostId, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': frais || CFG.nonce }
		} );

		if ( ! r.ok ) {
			throw new Error( 'HTTP ' + r.status );
		}

		return r.json();
	}

	/* ------------------------------------------------------------------
	 * Ouverture
	 * ---------------------------------------------------------------- */

	async function ouvrir() {
		// ⚠️ Une seule fenêtre à la fois. Une résolution laissée ouverte
		// n'empêchait pas d'en ouvrir une seconde par-dessus : deux
		// dialogues empilés, dont un qui parle d'un choix déjà fait.
		// Constaté au banc.
		Array.prototype.forEach.call( document.querySelectorAll( '.afk-conflit' ), function ( n ) {
			n.remove();
		} );

		var file = await DB.tout( 'file' );

		var action = file.filter( function ( a ) {
			return 'CONFLICT' === a.etat;
		} )[ 0 ];

		if ( ! action ) {
			return;
		}

		var wpPostId = action.charge && action.charge.wpPostId;
		var locale   = wpPostId ? await DB.lire( 'oeuvres', wpPostId ) : null;
		var nom      = ( locale && locale.titre ) || 'cette œuvre';

		var vue = boite( 'Deux versions de « ' + nom + ' »' );

		// ⚠️ Trancher exige la version du site. Sans réseau, on ne peut
		// pas la montrer — et choisir sans la voir, c'est tirer à pile ou
		// face sur son propre travail.
		if ( ! window.navigator.onLine ) {
			phrase( vue.corps, 'Cette œuvre a changé sur le site pendant que vous la modifiiez. Pour comparer les deux versions, il faut du réseau — votre modification est conservée ici en attendant, elle n’est pas perdue.' );
			return;
		}

		var attente = phrase( vue.corps, 'Lecture de la version du site…' );
		var fraiche;

		try {
			fraiche = await versionDuSite( wpPostId );
		} catch ( e ) {
			attente.textContent = mot( 'conflit_muet', 'Le site n’a pas répondu. Réessayez dans un moment : votre modification reste conservée ici.' );
			return;
		}

		attente.remove();

		var differences = ecarts( ( action.charge && action.charge.champs ) || {}, fraiche.champs || {} );

		phrase(
			vue.corps,
			differences.length
				? 'Cette œuvre a été modifiée sur le site pendant que vous la modifiiez de votre côté. Voici ce qui diffère.'
				: 'Cette œuvre a été modifiée sur le site, mais aucun des champs que vous avez saisis n’a changé. Vous pouvez envoyer votre version sans rien écraser.'
		);

		if ( differences.length ) {
			var table = document.createElement( 'div' );
			table.className = 'afk-conflit-table';

			differences.forEach( function ( d ) {
				var ligne = document.createElement( 'div' );
				ligne.className = 'afk-conflit-ligne';

				var nomChamp = document.createElement( 'p' );
				nomChamp.className = 'afk-conflit-champ';
				nomChamp.textContent = d.nom;
				ligne.appendChild( nomChamp );

				[
					{ etiquette: 'Votre version', valeur: d.mien, classe: 'afk-conflit-mien' },
					{ etiquette: 'Sur le site', valeur: d.sien, classe: 'afk-conflit-sien' }
				].forEach( function ( cote ) {
					var bloc = document.createElement( 'div' );
					bloc.className = 'afk-conflit-cote ' + cote.classe;

					var e = document.createElement( 'span' );
					e.className = 'afk-conflit-etiquette';
					e.textContent = cote.etiquette;
					bloc.appendChild( e );

					var v = document.createElement( 'span' );
					v.className = 'afk-conflit-valeur';
					v.textContent = cote.valeur || '— vide —';
					bloc.appendChild( v );

					ligne.appendChild( bloc );
				} );

				table.appendChild( ligne );
			} );

			vue.corps.appendChild( table );
		}

		var pied = document.createElement( 'div' );
		pied.className = 'afk-fiche-pied afk-fiche-pied-deux';

		var sien = document.createElement( 'button' );
		sien.type = 'button';
		sien.className = 'afk-form-bouton afk-form-secondaire';
		sien.textContent = mot( 'conflit_sien', 'Garder celle du site' );

		var mien = document.createElement( 'button' );
		mien.type = 'button';
		mien.className = 'afk-form-bouton afk-form-primaire';
		mien.textContent = mot( 'conflit_mien', 'Envoyer ma version' );

		function occupe() {
			sien.disabled = true;
			mien.disabled = true;
		}

		/**
		 * Dit ce qui vient d'être fait, sans fermer l'écran.
		 *
		 * ⚠️ La confirmation reste **là où le choix a été fait**. Fermer
		 * puis écrire un mot sur la bande d'état revenait à annoncer une
		 * décision irréversible ailleurs que sous les yeux de celui qui
		 * venait de la prendre — et la bande, redessinée à l'événement
		 * suivant, l'effaçait aussitôt.
		 */
		function conclure( phraseFinale ) {
			vue.corps.innerHTML = '';

			var h = document.createElement( 'h2' );
			h.className = 'afk-fiche-titre';
			h.textContent = mot( 'conflit_fait', 'C’est fait' );
			vue.corps.appendChild( h );

			phrase( vue.corps, phraseFinale );

			var fin = document.createElement( 'div' );
			fin.className = 'afk-fiche-pied';

			var ok = document.createElement( 'button' );
			ok.type = 'button';
			ok.className = 'afk-form-bouton afk-form-primaire';
			ok.textContent = 'Fermer';
			ok.addEventListener( 'click', vue.fermer );

			fin.appendChild( ok );
			vue.corps.appendChild( fin );
			ok.focus();
		}

		sien.addEventListener( 'click', async function () {
			occupe();
			await garderLaSienne( action, fraiche );
			conclure( 'La version du site est conservée. Votre modification a été abandonnée.' );
		} );

		mien.addEventListener( 'click', async function () {
			occupe();
			await garderLaMienne( action, fraiche );
			conclure( 'Votre version a été envoyée au site.' );
		} );

		pied.appendChild( sien );
		pied.appendChild( mien );
		vue.corps.appendChild( pied );
	}

	window.afkConflit = { ouvrir: ouvrir };
}() );
