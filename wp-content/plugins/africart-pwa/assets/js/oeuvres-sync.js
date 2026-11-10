/**
 * File de synchronisation des œuvres.
 *
 * Ce qu'un artiste fait sans réseau doit partir au retour du réseau, une
 * fois et une seule, dans l'ordre, et sans jamais écraser une version
 * plus récente. C'est tout l'objet de ce fichier.
 *
 * ⚠️ Trois pièges le gouvernent, chacun rencontré ou anticipé :
 *
 *   - **Le jeton expire pendant que l'application dort.** Une œuvre
 *     saisie le soir et envoyée le lendemain part avec un `nonce`
 *     périmé ; le serveur répond 403, et toute la file se bloque sur une
 *     erreur qui n'en est pas une. On renouvelle donc avant chaque
 *     vidage, et on retente une fois.
 *   - **`SYNCING` sans échéance ne se termine jamais.** Un onglet fermé
 *     au mauvais instant laisse une œuvre « en cours d'envoi » à vie.
 *     D'où un bail daté, repris passé deux minutes.
 *   - **La synchronisation en arrière-plan n'existe pas sur iPhone.**
 *     S'y fier reviendrait à ne jamais rien envoyer pour une partie du
 *     public, sans erreur ni trace. On s'appuie donc sur trois signaux
 *     que tous les navigateurs ont.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_OEUVRES || window.AFK_PWA_FORM || {};
	var DB  = window.afkOeuvresDB;

	// ⚠️ Rien ne part d'un éditeur. Laisser la file tourner dans Elementor
	// enverrait au site ce que l'auteur tape pour voir à quoi ça ressemble.
	if ( ! CFG.base || ! DB || CFG.apercu ) {
		return;
	}

	/** Durée du bail d'envoi, en millisecondes. */
	var BAIL = 120000;

	/** Jeton courant. Renouvelé avant chaque vidage. */
	var jeton = CFG.nonce;

	/* ------------------------------------------------------------------
	 * Alimentation
	 * ---------------------------------------------------------------- */

	/**
	 * Met une action en file.
	 *
	 * `cible` identifie l'œuvre : `wpPostId` si elle existe déjà,
	 * `localId` sinon. C'est cette clé qui permet de regrouper — et de
	 * ne pas mélanger deux œuvres différentes.
	 *
	 * @param {string} type    `creer`, `enregistrer`, `deposer`, `retirer`.
	 * @param {string} cible   Identifiant de l'œuvre.
	 * @param {Object} charge  Données à transmettre.
	 * @return {Promise}
	 */
	/**
	 * Met une action en file, et la fait partir si le réseau est là.
	 *
	 * ⚠️ La file n'avait que trois déclencheurs : le retour du réseau, le
	 * changement d'onglet, et le chargement de page. Un dépôt fait en
	 * ligne n'en active aucun — l'œuvre restait donc en attente sous les
	 * yeux d'un artiste parfaitement connecté, jusqu'à ce qu'il change
	 * d'onglet ou revienne le lendemain. Constaté sur le site.
	 *
	 * Mettre en file **est** la demande d'envoyer. Le seul cas où l'on
	 * attend, c'est l'absence de réseau.
	 *
	 * @return {Promise<{envoi: Object|null}>} Le bilan du vidage, s'il a eu lieu.
	 */
	function ajouter( type, cible, charge ) {
		return ajoute( type, cible, charge ).then( function () {
			annonceFile();

			if ( ! window.navigator.onLine ) {
				return { envoi: null };
			}

			return vider().then( function ( bilan ) {
				return { envoi: bilan };
			} );
		} );
	}

	function ajoute( type, cible, charge ) {
		return DB.ecrire( 'file', {
			type: type,
			cible: String( cible ),
			charge: charge || {},
			etat: 'PENDING',
			depuis: Date.now(),
			bail: 0,
			erreur: null,
			essais: 0
		} );
	}

	/* ------------------------------------------------------------------
	 * Compactage
	 * ---------------------------------------------------------------- */

	/**
	 * Réduit la file à ce qui doit réellement partir.
	 *
	 * Trois modifications successives de la même œuvre ne valent qu'un
	 * envoi — c'est la dernière qui compte. Et un retrait annule tout ce
	 * qui le précède pour cette œuvre : envoyer des modifications à un
	 * contenu qu'on s'apprête à mettre à la corbeille serait du trafic
	 * pour rien, et deux fois plus d'occasions d'échouer.
	 *
	 * ⚠️ Une création n'est jamais absorbée. Sans elle, la modification
	 * qui suit n'aurait aucune œuvre à modifier.
	 *
	 * @param {Array} file Actions en attente.
	 * @return {Object} `{ garder: [], jeter: [] }`
	 */
	function compacter( file ) {
		var garder = [];
		var jeter  = [];
		var parCible = {};

		file.forEach( function ( a ) {
			parCible[ a.cible ] = parCible[ a.cible ] || [];
			parCible[ a.cible ].push( a );
		} );

		Object.keys( parCible ).forEach( function ( cible ) {
			var actions = parCible[ cible ];
			var retrait = null;

			actions.forEach( function ( a ) {
				if ( 'retirer' === a.type ) {
					retrait = a;
				}
			} );

			if ( retrait ) {
				var creation = actions.filter( function ( a ) { return 'creer' === a.type; } )[ 0 ];

				// Retirer une œuvre jamais partie ne demande aucun envoi :
				// elle n'existe que sur cet appareil.
				if ( creation ) {
					actions.forEach( function ( a ) { jeter.push( a ); } );
					return;
				}

				actions.forEach( function ( a ) {
					if ( a === retrait ) { garder.push( a ); } else { jeter.push( a ); }
				} );
				return;
			}

			var creation = null;
			var derniere = null;

			// ⚠️ L'intention de déposer survit au compactage.
			//
			// « La dernière l'emporte » suffisait tant que toutes les
			// actions se valaient. Ce n'est plus vrai : `deposer` demande
			// une relecture, `enregistrer` non. Une modification arrivée
			// après un dépôt — au retour d'un arbitrage de conflit, par
			// exemple — aurait absorbé le dépôt et remis l'œuvre en
			// brouillon, sans que rien ne le signale.
			//
			// Les valeurs restent celles de la dernière saisie ; c'est le
			// verbe qui est retenu.
			var depot = false;

			actions.forEach( function ( a ) {
				if ( 'creer' === a.type ) {
					creation = a;
				} else {
					if ( 'deposer' === a.type ) { depot = true; }
					if ( derniere ) { jeter.push( derniere ); }
					derniere = a;
				}
			} );

			if ( derniere && depot ) {
				derniere.type = 'deposer';
			}

			if ( creation ) {
				// La dernière saisie l'emporte, mais c'est la création qui
				// part : l'œuvre n'existe pas encore côté serveur.
				if ( derniere ) {
					creation.charge = derniere.charge;
					jeter.push( derniere );
				}
				garder.push( creation );
				return;
			}

			if ( derniere ) {
				garder.push( derniere );
			}
		} );

		return { garder: garder, jeter: jeter };
	}

	/* ------------------------------------------------------------------
	 * Envoi
	 * ---------------------------------------------------------------- */

	/**
	 * Durée au-delà de laquelle on cesse d'attendre une réponse.
	 *
	 * ⚠️ Deux durées, parce que deux natures de requête. Une écriture
	 * d'œuvre pèse quelques kilooctets ; un visuel, plusieurs mégaoctets
	 * sur un réseau mobile. Leur imposer le même plafond revenait soit à
	 * abandonner des photos qui seraient passées, soit à faire attendre
	 * une demi-minute pour une requête qui aurait dû répondre en deux
	 * secondes.
	 *
	 * ⚠️ `fetch` n'a pas de délai par défaut : un réseau qui s'éteint en
	 * plein transfert laisse la promesse pendante **pour toujours**. Sur
	 * un téléphone en mobilité — le cas d'usage de ce module — c'est la
	 * situation ordinaire, pas l'exception.
	 */
	var DELAI = 15000;

	/** Le dépôt d'un visuel a droit à davantage : il pèse. */
	var DELAI_VISUEL = 60000;

	/** `fetch`, mais qui finit toujours par répondre. */
	function joindre( url, options, delai ) {
		options = options || {};

		if ( ! window.AbortController ) {
			return window.fetch( url, options );
		}

		var arret = new window.AbortController();
		var minuteur = window.setTimeout( function () { arret.abort(); }, delai || DELAI );

		options.signal = arret.signal;

		return window.fetch( url, options ).finally( function () {
			window.clearTimeout( minuteur );
		} );
	}

	async function appel( chemin, options ) {
		var reponse = await joindre( CFG.base + chemin, Object.assign( {
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': jeton
			}
		}, options || {} ) );

		var corps = null;

		try {
			corps = await reponse.json();
		} catch ( e ) { /* une réponse vide reste une réponse */ }

		return { code: reponse.status, corps: corps };
	}

	/**
	 * Vrai tant que la session tient. Consulté par l'interface.
	 *
	 * ⚠️ `joignable` est distinct de `navigator.onLine`, et il le faut.
	 * Le navigateur répond « en ligne » dès qu'une interface réseau
	 * existe — un wifi d'hôtel sans internet, une 4G à zéro barre. Se
	 * fier à lui laissait l'artiste saisir une œuvre entière en croyant
	 * être connecté.
	 */
	var session = { valide: true, expire: '', joignable: true };

	/**
	 * Renouvelle le jeton, et entretient la session.
	 *
	 * ⚠️ Un 401 ne se traite pas comme une panne : c'est une session
	 * perdue, et rejouer indéfiniment n'y changera rien. Le travail
	 * **n'est jamais jeté** — il attend que l'artiste se reconnecte, et
	 * repart tout seul ensuite.
	 */
	async function renouveler() {
		try {
			var r = await appel( 'session', { method: 'GET' } );

			if ( 200 === r.code && r.corps && r.corps.nonce ) {
				jeton = r.corps.nonce;
				session.valide    = true;
				session.joignable = true;
				session.expire    = r.corps.expire || '';
				annonceSession();
				programmeLaVerification();
				return true;
			}

			// ⚠️ Un 403 pour jeton périmé n'est PAS une session perdue.
			//
			// La configuration voyage dans la page : servie depuis un cache,
			// elle porte un jeton mort. Le site répondait alors 403, et nous
			// annoncions « Session expirée » à un artiste dont le menu
			// affichait le nom. Constaté sur l'accueil du site.
			//
			// On demande donc un jeton frais — le cookie, lui, est toujours
			// bon — et on ne conclut à la perte que si cela échoue aussi.
			if ( 403 === r.code && r.corps && 'rest_cookie_invalid_nonce' === r.corps.code ) {
				if ( await rafraichirLeJeton() ) {
					return renouveler();
				}
			}

			if ( 401 === r.code || 403 === r.code ) {
				session.valide    = false;
				session.joignable = true;
				annonceSession();
				return false;
			}
		} catch ( e ) {
			// Le site n'a pas répondu. Ce n'est ni une session perdue ni
			// forcément une absence de réseau : c'est ce qu'on sait, et
			// c'est tout ce qu'on annonce.
			session.joignable = false;
			annonceSession();
		}

		return false;
	}

	/**
	 * Demande un jeton REST frais, sans reconnexion.
	 *
	 * ⚠️ Une seule tentative, et jamais en boucle : si le cookie est
	 * réellement mort, cette adresse répond aussi par un refus, et
	 * insister ne ferait que retarder l'annonce honnête.
	 */
	var jetonDemande = false;

	async function rafraichirLeJeton() {
		if ( jetonDemande || ! CFG.jetons ) {
			return false;
		}

		jetonDemande = true;

		try {
			var r = await joindre( CFG.jetons, { credentials: 'same-origin' } );

			if ( ! r.ok ) {
				// ⚠️ On rouvre la porte : sans cela, un seul échec passager
				// interdisait toute nouvelle demande de jeton pour la vie
				// de la page, et la session paraissait perdue pour de bon.
				jetonDemande = false;
				return false;
			}

			var frais = ( await r.text() ).trim();

			if ( ! frais || frais.length > 64 ) {
				return false;
			}

			jeton = frais;
			jetonDemande = false;

			return true;
		} catch ( e ) {
			jetonDemande = false;
			return false;
		}
	}

	/** Fait savoir à la page où en est la session. */
	function annonceSession() {
		document.dispatchEvent( new CustomEvent( 'afkSession', { detail: session } ) );
	}

	/**
	 * Vérifie la session sans attendre le prochain envoi.
	 *
	 * ⚠️ L'entretien ordinaire est espacé de trente minutes, et ne se
	 * déclenche qu'à l'occasion d'un vidage. Remplir un formulaire de
	 * trente-six champs prend plus longtemps que ça : la session pouvait
	 * mourir en pleine saisie, et l'artiste ne l'apprenait qu'au dépôt.
	 */
	function verifier() {
		dernierEntretien = Date.now();
		return renouveler();
	}

	/** Le compte à rebours en cours, s'il y en a un. */
	var minuteurSession = null;

	/**
	 * Programme un contrôle un peu avant la fin annoncée.
	 *
	 * Le serveur dit quand le cookie meurt ; on n'a donc pas à sonder au
	 * hasard. Le contrôle a lieu **avant** l'échéance : prévenir dix
	 * minutes trop tôt vaut infiniment mieux qu'une minute trop tard.
	 */
	var AVANCE = 10 * 60 * 1000;

	function programmeLaVerification() {
		window.clearTimeout( minuteurSession );

		if ( ! session.expire ) {
			return;
		}

		var reste = new Date( session.expire ).getTime() - Date.now();

		if ( isNaN( reste ) ) {
			return;
		}

		// ⚠️ Plafonné à vingt-quatre jours. `setTimeout` compte sur 32
		// bits signés : au-delà, le délai déborde et se déclenche
		// **immédiatement** — une session de quatorze jours aurait ainsi
		// été annoncée expirée à la seconde du chargement.
		var dans = Math.min( Math.max( reste - AVANCE, 15000 ), 24 * 24 * 60 * 60 * 1000 );

		minuteurSession = window.setTimeout( function () {
			verifier().then( programmeLaVerification );
		}, dans );
	}

	/**
	 * Envoie les photos en attente et remplace leurs identifiants.
	 *
	 * ⚠️ Rien ne part tant qu'une photo n'a pas trouvé son identifiant
	 * réel. Transmettre `local:…` au serveur créerait une œuvre **sans
	 * visuel** — et comme la photo principale est obligatoire, elle
	 * serait refusée ou, pire, acceptée en brouillon amputé.
	 *
	 * @return {true|Object} `true`, ou `{ erreur }`.
	 */
	async function resoudreLesPhotos( action ) {
		var champs = ( action.charge && action.charge.champs ) || {};
		var cles = Object.keys( champs );

		for ( var i = 0; i < cles.length; i++ ) {
			var valeur = champs[ cles[ i ] ];

			if ( ! Array.isArray( valeur ) ) {
				continue;
			}

			for ( var j = 0; j < valeur.length; j++ ) {
				var v = valeur[ j ];

				if ( 'string' !== typeof v || 0 !== v.indexOf( 'local:' ) ) {
					continue;
				}

				var media = await DB.lire( 'medias', v );

				// ⚠️ Déjà envoyée. La correspondance survit à l'envoi, et
				// c'est délibéré : le formulaire resté ouvert porte encore
				// l'identifiant provisoire, et un second enregistrement le
				// présenterait à nouveau. Sans cette trace, il tomberait sur
				// « photo introuvable » pour une photo bel et bien déposée.
				if ( media && media.remplace ) {
					valeur[ j ] = media.remplace;
					continue;
				}

				if ( ! media || ! media.blob ) {
					// La photo a disparu du stockage — navigateur vidé.
					// Mieux vaut le dire que d'envoyer une œuvre amputée.
					return { erreur: 'Une photo en attente est introuvable sur cet appareil.' };
				}

				var envoi = await envoieMedia( media );

				if ( envoi.erreur ) {
					return envoi;
				}

				valeur[ j ] = envoi.id;

				// Le fichier d'origine part du stockage — il pèse ; la
				// correspondance et l'aperçu restent, et ne pèsent presque
				// rien.
				await DB.ecrire( 'medias', { id: v, remplace: envoi.id, depuis: Date.now() } );
				document.dispatchEvent( new CustomEvent( 'afkPhotoResolue', {
					detail: { local: v, id: envoi.id }
				} ) );
			}
		}

		return true;
	}

	/**
	 * ⚠️ L'élagage des photos vivait ici, avec ses propres durées.
	 *
	 * Il est passé dans le socle, `oeuvres-db.js`, avec une limite de
	 * nombre en plus de la limite d'âge — et surtout au même endroit que
	 * celui des œuvres. Deux règles concurrentes sur les mêmes magasins,
	 * dans deux fichiers, c'est une divergence programmée.
	 */

	/** Dépose un fichier gardé sur l'appareil. */
	async function envoieMedia( media ) {
		if ( ! CFG.medias ) {
			return { erreur: 'Dépôt de visuel indisponible.' };
		}

		var corps = new FormData();
		corps.append( 'fichier', media.blob, media.nom || 'photo.jpg' );

		// ⚠️ On regarde le fichier JUSTE AVANT de le confier au réseau.
		//
		// « Aucun fichier reçu » est ce que dit le serveur, et il dit vrai.
		// Ce qu'il ne peut pas dire, c'est si la photo était encore lisible
		// sur l'appareil au moment du départ : sur iOS, un fichier choisi
		// dans l'appareil photo n'est pas copié — on n'en garde qu'une
		// référence, que le système peut vider quand il veut. L'objet
		// survit et annonce toujours sa taille ; ses octets, non.
		if ( window.afkJournal ) {
			window.afkJournal.note( 'PHOTO A ENVOYER', {
				id: media.id,
				nom: media.nom || '(sans nom)',
				gardee_depuis_min: media.depuis
					? Math.round( ( Date.now() - media.depuis ) / 60000 )
					: '?'
			} );

			window.afkJournal.note( 'PHOTO ETAT',
				await window.afkJournal.ausculte( media.blob ) );
		}

		try {
			var r = await joindre( CFG.medias, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': jeton },
				body: corps
			}, DELAI_VISUEL );

			var d = await r.json();

			if ( window.afkJournal ) {
				window.afkJournal.note( 'PHOTO REPONSE', {
					statut: r.status,
					message: ( d && d.message ) || '',
					// Le serveur joint ce qu'il a vu arriver : sans cela, on
					// ne sait pas distinguer un corps rejeté d'un fichier vide.
					vu: ( d && d.data && d.data.vu ) || '',
					id: ( d && d.id ) || ''
				} );
			}

			// ⚠️ Le corps ne passe pas : on renvoie les octets nus.
			//
			// Un iPhone envoyait sa photo et le serveur ne recevait rien —
			// aucun champ, aucune longueur — alors que la photo était
			// intacte sur l'appareil, vérifiée juste avant le départ. Trois
			// tentatives, trois fois le même vide, et sur ce téléphone-là
			// seulement : quelque chose sur SON trajet réseau défait le
			// corps `multipart`, que ni elle ni nous ne pouvons corriger.
			//
			// Un corps simple traverse moins de mains. On ne le tente
			// qu'après un refus pour corps manquant, une seule fois, et
			// il aboutit sur les mêmes contrôles : rien ne change pour qui
			// passait déjà.
			if ( ! r.ok && d && 'afk_sans_fichier' === d.code ) {
				return await envoieMediaBrut( media, d );
			}

			if ( ! r.ok ) {
				return { erreur: ( d && d.message ) || 'Photo refusée.' };
			}

			// ⚠️ L'aperçu suit la photo. Elle vient d'être envoyée depuis
			// cet appareil : on tient encore ses octets, et c'est la seule
			// occasion de garder de quoi l'afficher au prochain passage
			// sans réseau.
			if ( d.id ) {
				await DB.ecrire( 'medias', {
					id: d.id,
					url: d.url || '',
					apercu: await DB.apercu( media.blob ),
					depuis: Date.now()
				} );
			}

			return { id: d.id };
		} catch ( e ) {
			if ( window.afkJournal ) {
				window.afkJournal.note( 'PHOTO ECHEC RESEAU', e );
			}

			return { erreur: 'Envoi de la photo impossible.' };
		}
	}

	/**
	 * La même photo, envoyée en octets nus.
	 *
	 * Voir la note ci-dessus : la voie de secours quand le corps
	 * `multipart` n'arrive pas entier. Le nom passe par l'adresse, le type
	 * par l'en-tête, et les octets sont le corps — rien à recomposer, donc
	 * rien à perdre en chemin.
	 *
	 * @param {Object} media   L'entrée de la réserve.
	 * @param {Object} premier Ce que le serveur a répondu la première fois.
	 * @return {Promise<Object>} `{ id }` ou `{ erreur }`.
	 */
	async function envoieMediaBrut( media, premier ) {
		var nom = media.nom || 'photo.jpg';

		if ( window.afkJournal ) {
			window.afkJournal.note( 'PHOTO SECOURS', {
				pourquoi: ( premier && premier.data && premier.data.vu ) || 'corps absent',
				poids: media.blob ? media.blob.size : 0
			} );
		}

		try {
			var r = await joindre( CFG.medias + '?nom=' + encodeURIComponent( nom ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': jeton,
					'Content-Type': media.type || media.blob.type || 'application/octet-stream'
				},
				body: media.blob
			}, DELAI_VISUEL );

			var d = await r.json();

			if ( window.afkJournal ) {
				window.afkJournal.note( 'PHOTO SECOURS REPONSE', {
					statut: r.status,
					message: ( d && d.message ) || '',
					vu: ( d && d.data && d.data.vu ) || '',
					voie: ( d && d.voie ) || '',
					id: ( d && d.id ) || ''
				} );
			}

			if ( ! r.ok ) {
				return { erreur: ( d && d.message ) || 'Photo refusée.' };
			}

			if ( d.id ) {
				await DB.ecrire( 'medias', {
					id: d.id,
					url: d.url || '',
					apercu: await DB.apercu( media.blob ),
					depuis: Date.now()
				} );
			}

			return { id: d.id };
		} catch ( e ) {
			if ( window.afkJournal ) {
				window.afkJournal.note( 'PHOTO SECOURS ECHEC', e );
			}

			return { erreur: 'Envoi de la photo impossible.' };
		}
	}

	/**
	 * Envoie une action.
	 *
	 * @return {Object} `{ ok, conflit, definitif, corps }`
	 */
	async function envoyer( action ) {
		// Les photos d'abord. Une œuvre sans son visuel n'a pas à partir.
		var photos = await resoudreLesPhotos( action );

		if ( true !== photos ) {
			return { ok: false, corps: { message: photos.erreur } };
		}

		var chemin;
		var options;

		if ( 'creer' === action.type ) {
			chemin  = 'oeuvres';
			options = { method: 'POST', body: JSON.stringify( action.charge ) };
		} else {
			chemin  = 'oeuvres/' + action.charge.wpPostId;
			options = {
				method: 'PUT',
				body: JSON.stringify( Object.assign( { action: action.type }, action.charge ) )
			};
		}

		var r;

		// ⚠️ Un échec de transport ne remonte pas plus haut.
		//
		// Sans cela, une requête coupée par le délai levait une exception
		// qui traversait la boucle de vidage : le `catch` général arrêtait
		// **tout**, et les actions suivantes n'étaient même pas tentées.
		// Une œuvre injoignable en emportait trois autres. Constaté au banc.
		try {
			r = await appel( chemin, options );
		} catch ( e ) {
			return { ok: false, transport: true, corps: { message: 'Le site n’a pas répondu à temps.' } };
		}

		// Jeton refusé : on renouvelle et on retente **une** fois. Retenter
		// sans fin sur un refus d'authentification tournerait en rond.
		if ( 403 === r.code && r.corps && 'rest_cookie_invalid_nonce' === r.corps.code ) {
			if ( await renouveler() ) {
				try {
					r = await appel( chemin, options );
				} catch ( e ) {
					return { ok: false, transport: true, corps: { message: 'Le site n’a pas répondu à temps.' } };
				}
			}
		}

		if ( r.code >= 200 && r.code < 300 ) {
			return { ok: true, corps: r.corps };
		}

		if ( 409 === r.code ) {
			return { ok: false, conflit: true, corps: r.corps };
		}

		// 4xx autre que 409 : la demande est mauvaise, la retenter à
		// l'identique donnera le même refus. On la marque sans la rejouer.
		var definitif = r.code >= 400 && r.code < 500;

		return { ok: false, definitif: definitif, corps: r.corps };
	}

	/* ------------------------------------------------------------------
	 * Vidage
	 * ---------------------------------------------------------------- */

	/**
	 * Quand le vidage en cours a commencé, ou zéro.
	 *
	 * ⚠️ Une date, et non un booléen — c'est toute la différence entre un
	 * verrou et une impasse.
	 *
	 * Le booléen n'avait aucune échéance : il suffisait qu'une seule
	 * requête ne réponde jamais — un réseau mobile qui s'éteint en plein
	 * transfert ne renvoie ni succès ni erreur, `fetch` attend
	 * indéfiniment — pour que `enCours` reste vrai jusqu'à la fermeture de
	 * l'onglet. Tout appel suivant sortait aussitôt sur « déjà en cours » :
	 * le bouton « Envoyer maintenant » ne faisait plus rien, et le retour
	 * du réseau ne déclenchait plus aucun envoi. Sans un mot, puisque
	 * cette sortie est silencieuse. Constaté sur le site.
	 */
	var depuisQuand = 0;

	/** Au-delà, un vidage est tenu pour mort et un autre peut partir. */
	var VERROU = 3 * 60 * 1000;

	function enCoursVraiment() {
		return depuisQuand > 0 && ( Date.now() - depuisQuand ) < VERROU;
	}

	/** Intervalle entre deux entretiens de session, en millisecondes. */
	var ENTRETIEN = 30 * 60 * 1000;

	var dernierEntretien = 0;

	/**
	 * Garde la session en vie quand il n'y a rien à envoyer.
	 *
	 * ⚠️ Sans cela, la session ne serait vérifiée qu'au moment d'un envoi
	 * — c'est-à-dire trop tard. L'artiste saisirait une œuvre entière
	 * avant d'apprendre qu'il fallait se reconnecter, et le cookie ne
	 * serait jamais prolongé chez qui consulte souvent sans déposer.
	 */
	async function entretenir() {
		if ( Date.now() - dernierEntretien < ENTRETIEN ) {
			return;
		}

		dernierEntretien = Date.now();
		await renouveler();
	}

	/**
	 * Vide la file, ou seulement ce qui concerne une œuvre.
	 *
	 * ⚠️ Le paramètre est facultatif, et son absence garde le sens
	 * d'origine : tout part. Une œuvre nommée restreint la sélection, et
	 * rien d'autre — même verrou, même compactage, même jeton, même
	 * élagage. Un second chemin d'envoi aurait fini par diverger de
	 * celui-ci, et c'est le genre d'écart qu'on ne découvre qu'en
	 * production.
	 *
	 * @param {string} [cible] Œuvre à envoyer seule.
	 */
	async function vider( cible ) {
		if ( enCoursVraiment() || ! window.navigator.onLine ) {
			derniereRaison = enCoursVraiment() ? 'deja-en-cours' : 'hors-ligne';
			annonceFile();
			return { envoyees: 0, raison: derniereRaison };
		}

		depuisQuand = Date.now();
		annonceFile();

		try {
			var file = await DB.tout( 'file' );

			// Un bail expiré revient en attente : l'envoi précédent n'a
			// jamais abouti, et rien ne l'aurait repris.
			var maintenant = Date.now();

			for ( var i = 0; i < file.length; i++ ) {
				if ( 'SYNCING' === file[ i ].etat && maintenant - file[ i ].bail > BAIL ) {
					file[ i ].etat = 'PENDING';
					file[ i ].bail = 0;
					await DB.ecrire( 'file', file[ i ] );
				}
			}

			// ⚠️ On ne tente pas ce qui sera refusé.
			//
			// Envoyer une saisie incomplète, c'est provoquer un 400, marquer
			// l'action en échec et faire croire à une panne — alors qu'il
			// manque simplement un champ que l'artiste peut remplir. Elle
			// reste en file, et l'écran l'invite à la compléter.
			var aTraiter = [];

			for ( var t = 0; t < file.length; t++ ) {
				if ( cible && String( file[ t ].cible ) !== String( cible ) ) {
					continue;
				}

				if ( 'PENDING' === file[ t ].etat && '' === await blocage( file[ t ] ) ) {
					aTraiter.push( file[ t ] );
				}
			}

			if ( ! aTraiter.length ) {
				await entretenir();
				derniereRaison = 'rien-a-envoyer';
				return { envoyees: 0, raison: derniereRaison };
			}

			var tri = compacter( aTraiter );

			for ( var j = 0; j < tri.jeter.length; j++ ) {
				await DB.effacer( 'file', tri.jeter[ j ].id );
			}

			if ( ! await renouveler() ) {
				// ⚠️ Rien n'est marqué en échec ici. Une session perdue
				// n'est pas une demande fautive : la remettre en `ERROR`
				// donnerait à l'artiste l'impression d'avoir perdu son
				// travail, alors qu'il suffit de se reconnecter.
				derniereRaison = session.valide ? 'reseau' : 'session-perdue';

				return {
					envoyees: 0,
					raison: derniereRaison,
					enAttente: aTraiter.length
				};
			}

			// ⚠️ L'élagage vit désormais dans le socle, avec les limites de
			// nombre et d'âge. Le garder ici, en double et sans plafond de
			// nombre, aurait laissé deux règles concurrentes sur le même
			// magasin — et personne pour dire laquelle fait foi.
			await DB.elaguer();

			var envoyees = 0;
			var conflits = 0;
			var echecs   = 0;

			for ( var k = 0; k < tri.garder.length; k++ ) {
				var action = tri.garder[ k ];

				action.etat = 'SYNCING';
				action.bail = Date.now();
				await DB.ecrire( 'file', action );

				// ⚠️ Annoncé à chaque œuvre, et pas seulement au début et à
				// la fin du vidage. Sans cela, une file de dix œuvres
				// n'affichait qu'un seul « envoi en cours » global : rien
				// ne disait laquelle partait, et l'artiste voyait dix
				// cartes identiques attendre sans savoir où en était la
				// sienne.
				annonceFile();

				var resultat = await envoyer( action );

				// ⚠️ Le transport a lâché : on s'arrête là.
				//
				// Les actions suivantes rencontreraient le même silence, et
				// chacune attendrait son propre délai avant d'y renoncer —
				// trois œuvres en file, c'est trois quarts de minute pendant
				// lesquels la file est verrouillée et « Envoyer maintenant »
				// ne répond plus. Elles restent en attente et repartiront au
				// prochain signal, ce qui est exactement leur raison d'être.
				if ( resultat.transport ) {
					action.etat = 'PENDING';
					action.bail = 0;
					await DB.ecrire( 'file', action );
					derniereRaison = 'reseau';
					break;
				}

				if ( resultat.ok ) {
					await DB.effacer( 'file', action.id );
					await appliquer( action, resultat.corps );
					envoyees++;
					continue;
				}

				action.essais = ( action.essais || 0 ) + 1;
				action.bail   = 0;
				action.erreur = ( resultat.corps && resultat.corps.message ) || 'echec';

				if ( resultat.conflit ) {
					action.etat = 'CONFLICT';
					conflits++;
				} else if ( resultat.definitif ) {
					action.etat = 'ERROR';
					echecs++;
				} else {
					// Panne passagère : on repassera.
					action.etat = 'PENDING';
					echecs++;
				}

				await DB.ecrire( 'file', action );
			}

			derniereRaison = echecs || conflits ? 'incomplet' : 'envoye';

			return { envoyees: envoyees, conflits: conflits, echecs: echecs };
		} catch ( e ) {
			derniereRaison = 'erreur';
			return { envoyees: 0, raison: 'erreur', message: String( e.message || e ) };
		} finally {
			depuisQuand = 0;

			// ⚠️ Dans le `finally`, et pas après chaque `return` : c'est le
			// seul endroit par lequel toutes les sorties passent, y
			// compris celles d'un échec. Un écran resté sur « envoi en
			// cours » après une erreur ferait attendre indéfiniment.
			annonceFile();
		}
	}

	/**
	 * Range le résultat d'un envoi réussi.
	 *
	 * ⚠️ L'association identifiant local ↔ identifiant WordPress se fait
	 * ici, et elle est indispensable : sans elle, la modification
	 * suivante ne saurait pas quelle œuvre viser côté serveur, et
	 * repartirait en création — donc en doublon.
	 */
	async function appliquer( action, corps ) {
		var oeuvre = corps && corps.oeuvre;

		if ( ! oeuvre || ! oeuvre.wpPostId ) {
			return;
		}

		await DB.ecrire( 'oeuvres', oeuvre );

		if ( 'creer' === action.type && action.charge.localId ) {
			var brouillon = await DB.lire( 'brouillons', action.charge.localId );

			if ( brouillon ) {
				brouillon.wpPostId = oeuvre.wpPostId;
				brouillon.envoye   = true;
				await DB.ecrire( 'brouillons', brouillon );
			}
		}

		// ⚠️ Le brouillon d'une modification part avec elle.
		//
		// Il n'était jamais retiré : la carte annonçait « Modifications non
		// envoyées » pour une œuvre qui venait pourtant d'arriver sur le
		// site, et cette mention ne disparaissait plus jamais. L'artiste
		// refaisait le parcours en croyant que rien n'était passé.
		//
		// Ce qu'il contenait vient d'être envoyé et nous revient dans
		// `oeuvre` : le garder ne conserverait rien de plus.
		if ( 'creer' !== action.type ) {
			await DB.effacer( 'brouillons', 'oeuvre-' + oeuvre.wpPostId );
		}
	}

	/* ------------------------------------------------------------------
	 * Déclenchement
	 * ---------------------------------------------------------------- */

	/**
	 * Pourquoi le dernier vidage s'est arrêté.
	 *
	 * ⚠️ « Hors connexion » et « site injoignable » ne se confondent pas.
	 * Le second est fréquent en mobilité — barres affichées, rien qui
	 * passe — et annoncer une absence de réseau enverrait l'artiste
	 * vérifier son forfait plutôt que patienter.
	 */
	var derniereRaison = '';

	/**
	 * Ce qui manque à une action pour pouvoir partir.
	 *
	 * ⚠️ Un champ obligatoire n'est compté manquant que si sa clé est
	 * **présente et vide**. Absente, on n'en conclut rien : le formulaire
	 * écarte les champs masqués par la logique conditionnelle, et leur
	 * clé ne figure alors pas dans la saisie. Les réclamer bloquerait un
	 * envoi parfaitement valable sur une question jamais posée — un faux
	 * signal coûte ici plus cher que le silence.
	 *
	 * @param {Object} charge La charge de l'action.
	 * @return {Array<string>} Les libellés manquants.
	 */
	function manquants( charge ) {
		var champs = ( charge && charge.champs ) || {};
		var connus = DB.schema ? ( DB.schema().champs || [] ) : [];
		var trous  = [];

		connus.forEach( function ( champ ) {
			if ( ! champ.required ) {
				return;
			}

			if ( ! Object.prototype.hasOwnProperty.call( champs, champ.key ) ) {
				return;
			}

			var v = champs[ champ.key ];
			var vide = Array.isArray( v )
				? ! v.length
				: ! String( null === v || undefined === v ? '' : v ).trim();

			if ( vide ) {
				trous.push( String( champ.label || champ.key ).replace( /[\s*:]+$/, '' ) );
			}
		} );

		return trous;
	}

	/**
	 * Ce qui empêche une action de partir, ou rien.
	 *
	 * ⚠️ Les champs obligatoires ne suffisaient pas comme critère.
	 *
	 * Une action peut référencer une photo par un identifiant provisoire
	 * `local:` dont le fichier a disparu du stockage — navigateur vidé,
	 * quota atteint. L'envoi s'arrêtait alors **avant la première
	 * requête**, l'action retombait en attente, et le message continuait
	 * d'annoncer « 1 envoi prêt » avec un bouton qui ne pouvait rien
	 * faire. L'artiste cliquait, voyait passer une requête de session, et
	 * rien d'autre — indéfiniment. Constaté sur le site.
	 *
	 * @return {Promise<string>} `''`, `'champs'` ou `'photo'`.
	 */
	/**
	 * La saisie en cours peut-elle remplacer une demande devenue caduque ?
	 *
	 * ⚠️ Une action en file est une PHOTOGRAPHIE des valeurs au moment du
	 * dépôt. Le brouillon, lui, suit l'artiste. Les deux divergent dès
	 * qu'on rouvre le formulaire — et quand la photographie désigne un
	 * visuel qui n'est plus sur l'appareil, l'envoi se bloquait
	 * définitivement sur « Photo à reprendre », alors que le formulaire
	 * affichait deux photos parfaitement valides.
	 *
	 * L'artiste n'y comprenait rien, et il avait raison : ce qu'il voit
	 * est juste, c'est ce que la file retient qui est périmé.
	 *
	 * On remplace donc la charge par la saisie enregistrée, à trois
	 * conditions : le brouillon existe, il n'a pas déjà été envoyé, et ses
	 * propres visuels sont, eux, bien là. Sans quoi on échangerait une
	 * impasse contre une autre.
	 *
	 * @return {boolean} Vrai si la charge a été remplacée.
	 */
	async function rattraperDepuisLaSaisie( action ) {
		var cle = String( action.cible );

		// La création se reconnaît à son identifiant local ; la
		// modification, au préfixe qu'on pose sur l'œuvre du site.
		var brouillon = await DB.lire( 'brouillons', cle );

		if ( ! brouillon ) {
			brouillon = await DB.lire( 'brouillons', 'oeuvre-' + cle );
		}

		if ( ! brouillon || brouillon.envoye || ! brouillon.champs ) {
			return false;
		}

		var neuve = Object.assign( {}, action.charge, { champs: brouillon.champs } );

		if ( manquants( neuve ).length || 'photo' === await photosPerdues( neuve ) ) {
			return false;
		}

		action.charge = neuve;
		await DB.ecrire( 'file', action );

		return true;
	}

	/**
	 * Un visuel de cette charge manque-t-il à l'appel ?
	 *
	 * @return {string} `'photo'` ou `''`.
	 */
	async function photosPerdues( charge ) {
		var champs = ( charge && charge.champs ) || {};
		var cles   = Object.keys( champs );

		for ( var i = 0; i < cles.length; i++ ) {
			var valeur = champs[ cles[ i ] ];

			if ( ! Array.isArray( valeur ) ) {
				continue;
			}

			for ( var j = 0; j < valeur.length; j++ ) {
				var v = valeur[ j ];

				if ( 'string' !== typeof v || 0 !== v.indexOf( 'local:' ) ) {
					continue;
				}

				var trace = await DB.lire( 'medias', v );

				if ( ! trace || ( ! trace.blob && ! trace.remplace ) ) {
					return 'photo';
				}
			}
		}

		return '';
	}

	async function blocage( action ) {
		if ( manquants( action.charge ).length ) {
			return 'champs';
		}

		if ( '' === await photosPerdues( action.charge ) ) {
			return '';
		}

		// ⚠️ Avant de déclarer l'impasse, on regarde la saisie en cours :
		// elle est souvent saine quand la demande ne l'est plus.
		if ( action.id && await rattraperDepuisLaSaisie( action ) ) {
			return manquants( action.charge ).length ? 'champs' : '';
		}

		return 'photo';
	}

	function etat() {
		return DB.tout( 'file' ).then( function ( file ) {
			var compte  = { PENDING: 0, SYNCING: 0, CONFLICT: 0, ERROR: 0 };
			var message = '';

			// ⚠️ « En attente » et « prêt à partir » ne sont pas la même
			// chose. Annoncer « 2 envois en attente » avec un bouton, pour
			// des saisies incomplètes que le serveur refusera, c'est
			// promettre un envoi qui n'aura pas lieu.
			var prets       = 0;
			var incomplets  = 0;
			var sansPhoto   = 0;
			// Prêtes, mais dont la dernière tentative a échoué.
			var aReessayer  = 0;
			var aCompleter  = '';

			file.forEach( function ( a ) {
				compte[ a.etat ] = ( compte[ a.etat ] || 0 ) + 1;

				// Le motif du serveur, pas une paraphrase : c'est lui qui
				// sait pourquoi il a refusé.
				if ( ! message && a.erreur && ( 'ERROR' === a.etat || 'CONFLICT' === a.etat ) ) {
					message = a.erreur;
				}
			} );

			// ⚠️ Le jugement de disponibilité lit le magasin des médias : il
			// est donc asynchrone, et se fait après le décompte plutôt que
			// pendant. Quelques actions, quelques lectures — le coût est
			// sans commune mesure avec un bouton qui ment.
			return ( async function () {
				for ( var i = 0; i < file.length; i++ ) {
					var a = file[ i ];

					if ( 'PENDING' !== a.etat && 'SYNCING' !== a.etat ) {
						continue;
					}

					var pourquoi = await blocage( a );

					if ( 'champs' === pourquoi ) {
						incomplets++;
					} else if ( 'photo' === pourquoi ) {
						sansPhoto++;
					} else {
						// ⚠️ Une tentative ratée n'est pas une œuvre à corriger.
						//
						// Elle était comptée « à corriger » du seul fait qu'un
						// envoi précédent avait échoué, alors que plus rien ne
						// la bloque. Le message annonçait donc « 1 à corriger —
						// ouvrez les œuvres signalées » pendant que la carte,
						// elle, ne signalait rien : l'artiste cherchait une
						// œuvre marquée qui n'existait pas, et n'avait aucun
						// geste à faire. Signalé au test client.
						//
						// Elle est prête : l'envoi la reprendra telle quelle. Le
						// motif du dernier échec se dit à part — c'est une
						// explication, pas un obstacle.
						prets++;

						if ( a.erreur ) {
							aReessayer++;

							if ( ! message ) {
								message = a.erreur;
							}
						}

						continue;
					}

					if ( ! aCompleter ) {
						aCompleter = String( a.cible );
					}
				}

				return {
					total: file.length,
					par: compte,
					message: message,
					raison: derniereRaison,
					prets: prets,
					incomplets: incomplets,
					sansPhoto: sansPhoto,
					aReessayer: aReessayer,
					aCompleter: aCompleter
				};
			}() );
		} ).catch( function () {
			return {
				total: 0,
				par: { PENDING: 0, SYNCING: 0, CONFLICT: 0, ERROR: 0 },
				message: '',
				raison: derniereRaison,
				prets: 0,
				incomplets: 0,
				sansPhoto: 0,
				aReessayer: 0,
				aCompleter: ''
			};
		} );
	}

	/**
	 * Renonce à ce qui attend pour une œuvre.
	 *
	 * ⚠️ Une impasse doit avoir une sortie.
	 *
	 * Une modification dont la photo a disparu de l'appareil ne peut plus
	 * aboutir : le fichier n'existe nulle part, le site ne le recevra
	 * jamais. Elle restait pourtant en file, comptée parmi les « à
	 * corriger », sans que rien ne permette de s'en défaire — l'artiste
	 * voyait un reproche permanent sur une œuvre déjà en ligne, et le
	 * seul remède aurait été de vider le stockage du navigateur.
	 *
	 * Renoncer efface la demande locale, jamais l'œuvre : celle du site
	 * reste intacte et redescendra telle quelle à la synchronisation
	 * suivante. C'est pourquoi ce geste ne s'offre que sur une œuvre qui
	 * existe déjà là-bas.
	 *
	 * @param {string} cible Œuvre visée, telle qu'elle figure dans la file.
	 * @return {Object} `{ actions, brouillon }` — ce qui a été écarté.
	 */
	async function abandonner( cible ) {
		var vise  = String( cible );
		var jetes = 0;

		var file = await DB.tout( 'file' );

		for ( var i = 0; i < file.length; i++ ) {
			// ⚠️ Jamais un envoi déjà parti : sa réponse est en chemin, et
			// l'effacer ici ferait perdre l'œuvre créée à l'autre bout.
			if ( String( file[ i ].cible ) !== vise || 'SYNCING' === file[ i ].etat ) {
				continue;
			}

			await DB.effacer( 'file', file[ i ].id );
			jetes++;
		}

		var brouillon = false;

		try {
			await DB.effacer( 'brouillons', 'oeuvre-' + vise );
			brouillon = true;
		} catch ( e ) { /* il n'y en avait pas */ }

		annonceFile();

		return { actions: jetes, brouillon: brouillon };
	}

	/**
	 * L'état de synchronisation de chaque œuvre, une par une.
	 *
	 * ⚠️ Deux axes, et les confondre rendait les cartes illisibles :
	 * « Brouillon » dit où en est l'œuvre **sur le site**, et n'a rien à
	 * voir avec « Envoi en attente », qui dit où elle en est **sur cet
	 * appareil**. Affichés côte à côte, de même forme et de même taille,
	 * ils se lisaient comme deux valeurs d'une même chose.
	 *
	 * Ici, seul le second axe. Et il dit ce qui **bloque** quand quelque
	 * chose bloque : une carte annonçant « en attente » pour une œuvre
	 * dont la photo a disparu attend en vain.
	 *
	 * @return {Promise<Object>} `{ cible: état }`.
	 */
	async function etatsParCible() {
		var par = {};

		try {
			var file = await DB.tout( 'file' );

			for ( var i = 0; i < file.length; i++ ) {
				var a = file[ i ];
				var cle = String( a.cible );

				if ( 'CONFLICT' === a.etat || 'ERROR' === a.etat || 'SYNCING' === a.etat ) {
					par[ cle ] = a.etat;
					continue;
				}

				var pourquoi = await blocage( a );

				if ( 'photo' === pourquoi ) {
					par[ cle ] = 'PHOTO';
					continue;
				}

				if ( 'champs' === pourquoi ) {
					par[ cle ] = 'INCOMPLET';
					continue;
				}

				// ⚠️ Rien ne la bloque, mais la dernière tentative a échoué :
				// la carte doit le dire, sinon le message flottant parle d'une
				// œuvre que rien ne signale.
				par[ cle ] = a.erreur ? 'RETENTE' : 'ATTENTE';
			}

			// ⚠️ Une saisie modifiée mais jamais déposée n'est pas dans la
			// file — et n'était donc signalée nulle part. L'artiste voyait
			// une œuvre d'apparence synchronisée alors que ses corrections
			// dormaient sur l'appareil.
			var brouillons = await DB.tout( 'brouillons' );

			brouillons.forEach( function ( b ) {
				var cle = String( b.localId || '' );

				// Déjà parti : ce n'est plus une modification en attente.
				if ( ! cle || b.envoye || par[ cle ] ) {
					return;
				}

				// `oeuvre-154` désigne la modification de l'œuvre 154.
				var sur = 0 === cle.indexOf( 'oeuvre-' ) ? cle.slice( 7 ) : cle;

				if ( par[ sur ] ) {
					return;
				}

				// ⚠️ Une saisie inachevée n'est pas une saisie qui attend.
				//
				// Les deux s'annonçaient « Modifications non envoyées », ce
				// qui laissait croire qu'il suffisait d'appuyer sur
				// « Envoyer » — alors qu'il manque un champ obligatoire et
				// que rien ne partira. L'artiste appuyait, il ne se passait
				// rien, et le message ne changeait pas.
				par[ sur ] = manquants( { champs: b.champs } ).length
					? 'EN_COURS'
					: 'NON_ENVOYE';
			} );
		} catch ( e ) { /* réserve illisible : on n'affirme rien */ }

		return par;
	}

	/** Combien de photos attendent encore leur tour sur cet appareil. */
	/**
	 * Combien de photos attendent vraiment leur envoi.
	 *
	 * ⚠️ Réclamées, ou toutes fraîches. Pas « toutes celles qui ont des
	 * octets ».
	 *
	 * Ce compte portait sur la réserve entière : une photo retirée d'un
	 * formulaire y restait, et le message annonçait « 1 photo gardée ici »
	 * sur un compte dont tout avait pourtant été envoyé. Rien ne pouvait
	 * faire redescendre ce nombre — ni l'envoi, ni l'élagage, qui la
	 * protégeait de son côté. Signalé au test.
	 *
	 * Une photo compte si un brouillon ou une action en file la réclame,
	 * ou si elle vient d'être choisie : à cet instant elle n'est encore
	 * réclamée par personne — le brouillon ne s'écrit pas tant que
	 * l'œuvre n'a pas de titre — et il serait absurde de la taire.
	 */
	function photosEnAttente() {
		return Promise.all( [ DB.tout( 'medias' ), DB.reclames ? DB.reclames() : {} ] )
			.then( function ( r ) {
				var tout     = r[ 0 ] || [];
				var reclames = r[ 1 ] || {};
				var grace    = DB.graceDesPhotos || ( 24 * 60 * 60 * 1000 );

				return tout.filter( function ( m ) {
					if ( ! m.blob ) {
						return false;
					}

					return reclames[ String( m.id ) ]
						|| ( Date.now() - ( m.depuis || 0 ) ) < grace;
				} ).length;
			} ).catch( function () { return 0; } );
	}

	/**
	 * Fait savoir à la page où en est la file.
	 *
	 * ⚠️ Annoncé plutôt qu'interrogé. Un écran qui sonde toutes les
	 * secondes réveille la base pour rien et reste malgré tout en retard
	 * d'une seconde sur ce qui vient de se passer.
	 */
	function annonceFile() {
		Promise.all( [ etat(), photosEnAttente() ] ).then( function ( r ) {
			document.dispatchEvent( new CustomEvent( 'afkFile', {
				detail: { file: r[ 0 ], photos: r[ 1 ] }
			} ) );
		} );
	}

	window.afkSync = {
		abandonner: abandonner,
		ajouter: ajouter,
		vider: vider,
		etat: etat,
		compacter: compacter,
		photos: photosEnAttente,
		manquants: manquants,
		annoncer: annonceFile,
		verifier: verifier,
		etatsParCible: etatsParCible,

		/**
		 * Le jeton REST vivant, et de quoi le renouveler.
		 *
		 * ⚠️ Exposés parce que la file n'est pas seule à parler au site :
		 * le cache des œuvres et l'écran de conflit ont leurs propres
		 * requêtes. Ils lisaient le jeton **de la page**, qui meurt avec
		 * elle dès qu'un cache la ressert — et le rapatriement des œuvres
		 * échouait alors en 403, sans rien dire. La liste restait vide.
		 */
		jeton: function () { return jeton; },
		renouvelerJeton: rafraichirLeJeton,

		/**
		 * Le site vient de ne pas répondre, ailleurs qu'ici.
		 *
		 * ⚠️ Le téléversement d'une photo ne passe pas par cette file, et
		 * c'est pourtant lui qui découvre la coupure en premier : il est
		 * le seul à parler au serveur pendant la saisie. Sans ce signal,
		 * l'échec restait une phrase rouge sous un champ, et l'état
		 * général affichait toujours que tout allait bien.
		 */
		injoignable: function () {
			session.joignable = false;
			annonceSession();
		},
		session: function () { return session; }
	};

	/* ------------------------------------------------------------------
	 * ⚠️ Aucun envoi automatique. C'est un choix, pas un oubli.
	 *
	 * Le module vidait sa file au retour du réseau, au retour sur
	 * l'onglet et au chargement de page. Décision du client : rien ne
	 * part sans un geste — l'artiste lance l'envoi quand il constate que
	 * le réseau est là et que des œuvres attendent.
	 *
	 * ⚠️ Conséquence assumée, et il faut la connaître : **une œuvre
	 * déposée ne rejoindra jamais le site toute seule**. Elle reste sur
	 * l'appareil jusqu'au clic. Le message qui l'annonce n'est donc plus
	 * refermable tant qu'il reste quelque chose à envoyer : ce serait
	 * masquer le seul chemin qui mène au site.
	 *
	 * Ce qui se fait encore sans clic, et pourquoi :
	 *   - les changements de réseau **rafraîchissent l'affichage**, sans
	 *     rien envoyer, pour que le bouton paraisse au bon moment ;
	 *   - le retour sur l'onglet vérifie une session perdue, parce que
	 *     l'artiste vient probablement de se reconnecter à côté ;
	 *   - déposer une œuvre l'envoie, parce que presser « Déposer
	 *     l'œuvre » **est** le geste d'envoyer.
	 * ---------------------------------------------------------------- */

	window.addEventListener( 'online', function () { annonceFile(); } );
	window.addEventListener( 'offline', function () { annonceFile(); } );

	document.addEventListener( 'visibilitychange', function () {
		if ( 'visible' !== document.visibilityState ) {
			return;
		}

		if ( ! session.valide ) {
			verifier().then( annonceFile );
			return;
		}

		annonceFile();
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () { annonceFile(); } );
	} else {
		annonceFile();
	}
}() );
