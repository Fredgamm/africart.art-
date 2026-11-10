/* AFRICART PWA — notifications push.
 *
 * Point essentiel : OneSignal reçoit ici le chemin de NOTRE service
 * worker. Sans cela il enregistrerait le sien à la racine, ce qui
 * remplacerait celui de la PWA et casserait l'installation ainsi que le
 * mode hors connexion — un navigateur n'acceptant qu'un seul service
 * worker par portée.
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_ONESIGNAL || {};
	var T = CFG.texts || {};

	if ( ! CFG.appId ) {
		return;
	}

	window.OneSignalDeferred = window.OneSignalDeferred || [];

	window.OneSignalDeferred.push( async function ( OneSignal ) {
		try {
			await OneSignal.init( {
				appId: CFG.appId,
				safari_web_id: CFG.safariId || undefined,
				// Notre service worker prend en charge les notifications.
				serviceWorkerPath: CFG.swPath,
				serviceWorkerParam: { scope: CFG.swScope || '/' },
				// La demande d'autorisation est déclenchée par le site, au
				// moment choisi — jamais dès l'arrivée sur la page.
				autoResubscribe: true,
				notifyButton: { enable: false }
			} );

			bindPreferences( OneSignal );

			// Rend l'API accessible aux autres scripts du site.
			window.afkPush = {
				ask: function () { return askPermission( OneSignal ); },
				setCategories: function ( slugs ) { return applyTags( OneSignal, slugs ); }
			};
		} catch ( e ) {
			// Une erreur d'initialisation ne doit jamais empêcher le reste
			// de la page de fonctionner.
			if ( window.console && window.console.warn ) {
				window.console.warn( 'AFRICART : notifications indisponibles', e );
			}
		}
	} );

	/**
	 * Demande l'autorisation d'envoyer des notifications.
	 */
	async function askPermission( OneSignal ) {
		if ( OneSignal.Notifications.permission ) {
			return true; // Déjà accordée.
		}

		// Le navigateur a dit non une fois : il ne redemandera pas, et
		// insister n'affiche rien. Seul le visiteur peut revenir dessus
		// dans les réglages de son navigateur.
		if ( 'denied' === window.Notification.permission ) {
			return false;
		}

		// L'invite NATIVE du navigateur.
		//
		// ⚠️ Surtout pas `Slidedown.promptPush()` : c'est la fenêtre de
		// OneSignal, qui exige d'être configurée dans leur tableau de
		// bord. Sans cette configuration elle n'affiche rien — et le
		// code en concluait à tort que les notifications étaient
		// bloquées. Constaté à l'usage.
		await OneSignal.Notifications.requestPermission();

		if ( ! OneSignal.Notifications.permission ) {
			return false;
		}

		// L'autorisation ne vaut pas abonnement : sur un appareil qui
		// s'était désabonné, il faut le redemander explicitement.
		try {
			if ( OneSignal.User && OneSignal.User.PushSubscription
				&& false === OneSignal.User.PushSubscription.optedIn ) {
				await OneSignal.User.PushSubscription.optIn();
			}
		} catch ( e ) { /* l'autorisation est acquise, c'est l'essentiel */ }

		return true;
	}

	/**
	 * Applique les centres d'intérêt choisis sous forme d'étiquettes.
	 * Une catégorie décochée est explicitement remise à « 0 » plutôt que
	 * supprimée : le tableau de bord peut ainsi distinguer un refus d'une
	 * absence de choix.
	 */
	/**
	 * Retire le consentement.
	 *
	 * Le règlement demande qu'on puisse le retirer aussi simplement
	 * qu'on l'a donné. `optOut()` coupe l'abonnement chez OneSignal —
	 * l'autorisation du navigateur, elle, n'appartient qu'au visiteur et
	 * ne se révoque que dans ses propres réglages. On ne fait donc pas
	 * semblant de la retirer.
	 */
	async function desabonner( OneSignal ) {
		await OneSignal.User.PushSubscription.optOut();
		return true;
	}

	/**
	 * Identifiant de l'abonnement courant.
	 *
	 * Il n'existe qu'une fois l'abonnement créé — c'est-à-dire après
	 * l'autorisation ET l'enregistrement auprès du service de push.
	 * Demandé trop tôt, il vaut `null` : on patiente brièvement.
	 */
	async function idAbonnement( OneSignal ) {
		for ( var essai = 0; essai < 12; essai++ ) {
			try {
				var id = OneSignal.User.PushSubscription.id;
				if ( id ) {
					return id;
				}
			} catch ( e ) { /* le modèle n'est pas encore prêt */ }
			await new Promise( function ( r ) { window.setTimeout( r, 500 ); } );
		}
		return null;
	}

	/** Annonce un fait a l'outil de mesure, s'il est en place. */
	function mesure( nom, params ) {
		if ( window.afkMesure ) {
			window.afkMesure( nom, params );
		}
	}

	/** Appel au site, en `application/x-www-form-urlencoded`. */
	async function auSite( action, donnees ) {
		if ( ! CFG.ajax || ! CFG.nonce ) {
			return null;
		}
		var corps = new URLSearchParams( Object.assign( {
			action: action,
			nonce: CFG.nonce
		}, donnees ) );

		var r = await window.fetch( CFG.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: corps.toString()
		} );
		return r.json();
	}

	/**
	 * Enregistre les centres d'intérêt.
	 *
	 * ⚠️ C'est **WordPress** qui écrit chez OneSignal, pas le SDK.
	 * `User.addTags()` ne fait que remplir un cache local dont la
	 * synchronisation, ici, n'aboutit jamais : constaté sur le site,
	 * seize abonnements et aucune étiquette côté OneSignal, alors que
	 * `getTags()` les rendait fidèlement dans le navigateur. Le ciblage
	 * portait donc sur des étiquettes inexistantes — décocher un centre
	 * d'intérêt ne changeait rien aux messages reçus.
	 *
	 * L'appel au SDK est conservé : il tient le cache local à jour, et
	 * c'est lui que la cloche relit pour rouvrir les bonnes cases.
	 * Mais il n'est plus la source de vérité.
	 */
	async function applyTags( OneSignal, actives ) {
		var tags = {};
		( CFG.categories || [] ).forEach( function ( category ) {
			tags[ category.slug ] = actives.indexOf( category.slug ) !== -1 ? '1' : '0';
		} );

		try {
			await OneSignal.User.addTags( tags );
		} catch ( e ) { /* le cache local n'est pas critique */ }

		var id = await idAbonnement( OneSignal );

		if ( ! id ) {
			throw new Error( 'abonnement introuvable' );
		}

		var reponse = await auSite( 'afk_push_tags', {
			sub: id,
			tags: JSON.stringify( tags )
		} );

		if ( reponse && reponse.data && reponse.data.message ) {
			throw new Error( reponse.data.message );
		}

		if ( reponse && false === reponse.success ) {
			throw new Error( 'enregistrement refuse' );
		}

		return tags;
	}

	/**
	 * Rattache l'abonnement au compte connecté.
	 *
	 * Sans cela, l'administration ne peut afficher que des identifiants
	 * anonymes. Silencieux et sans conséquence : un visiteur non
	 * connecté, ou non abonné, n'envoie rien.
	 */
	async function rapproche( OneSignal ) {
		if ( ! CFG.logged ) {
			return;
		}
		try {
			if ( ! OneSignal.User.PushSubscription.optedIn ) {
				return;
			}
			var id = await idAbonnement( OneSignal );
			if ( id ) {
				await auSite( 'afk_push_link', { sub: id } );
			}
		} catch ( e ) { /* le rapprochement est un confort, pas un dû */ }
	}

	/* ------------------------------------------------------------------
	 * Fenêtre d'invitation
	 *
	 * Notre propre fenêtre, jamais celle de OneSignal : elle s'habille
	 * comme le site, se traduit entièrement, et choisit son moment.
	 * ---------------------------------------------------------------- */

	var CLE_REFUS = 'afkPushDismissed';

	/** L'application est-elle lancée depuis l'écran d'accueil ? */
	function estApplication() {
		return ( window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches )
			|| true === window.navigator.standalone;
	}

	/**
	 * Le contexte courant est-il celui choisi dans les réglages ?
	 *
	 * Utile surtout pour iPhone : les notifications web n'y existent QUE
	 * dans une application posée sur l'écran d'accueil. Proposer
	 * l'abonnement dans Safari y serait une promesse intenable — mais
	 * sur Android et sur ordinateur, l'onglet fonctionne très bien.
	 */
	function contexteAutorise() {
		var ou = CFG.popupWhere || 'both';

		if ( 'pwa' === ou ) {
			return estApplication();
		}
		if ( 'browser' === ou ) {
			return ! estApplication();
		}
		return true;
	}

	function refusMemorise() {
		try {
			var jusqua = window.localStorage.getItem( CLE_REFUS );
			return jusqua && Date.now() < parseInt( jusqua, 10 );
		} catch ( e ) {
			return false;
		}
	}

	function memoriseRefus() {
		try {
			var jours = CFG.popupDays || 30;
			window.localStorage.setItem( CLE_REFUS, String( Date.now() + jours * 86400000 ) );
		} catch ( e ) { /* stockage indisponible : sans gravité */ }
	}

	/**
	 * Le SDK, quand il sera là.
	 *
	 * La fenêtre s'affiche et se déplie **sans lui** : le SDK est un
	 * script tiers, différé au premier geste du visiteur, et il pourrait
	 * aussi ne jamais arriver. Une interface qui l'attendrait resterait
	 * inerte. On ne le réclame donc qu'au clic sur « M'abonner » — un
	 * clic étant justement le geste qui l'a fait charger.
	 */
	function auSDK() {
		return new Promise( function ( resoudre ) {
			window.OneSignalDeferred = window.OneSignalDeferred || [];
			window.OneSignalDeferred.push( function ( OneSignal ) { resoudre( OneSignal ); } );
		} );
	}

	// Le SDK est réclamé d'avance quand la fenêtre va s'afficher d'elle-
	// même, pour être prêt au clic. La cloche, elle, l'a déjà obtenu.
	var sdkPret = null;

	/**
	 * Branche les commandes de la fenêtre.
	 *
	 * ⚠️ Sans condition de contexte. La fenêtre n'appartient pas au seul
	 * affichage automatique : la cloche l'ouvre aussi, y compris là où
	 * l'invitation spontanée est interdite (navigateur simple quand le
	 * réglage la réserve à l'application). Brancher les fermetures dans
	 * `bindPopup` laissait alors une fenêtre sans issue.
	 */
	function bindModal() {
		var modal = document.getElementById( 'afk-push-modal' );

		if ( ! modal ) {
			return null;
		}

		var etat    = modal.querySelector( '.afk-push-modal-etat' );
		var deplier = modal.querySelector( '.afk-push-deplier' );
		var liste   = modal.querySelector( '.afk-push-liste' );

		function ferme( memoriser ) {
			modal.hidden = true;
			document.removeEventListener( 'keydown', surEchap );
			if ( memoriser ) {
				memoriseRefus();
			}
		}

		function surEchap( e ) {
			if ( 'Escape' === e.key ) {
				ferme( true );
			}
		}

		// Section dépliable des centres d'intérêt.
		if ( deplier && liste ) {
			deplier.addEventListener( 'click', function () {
				var ouvert = 'true' === deplier.getAttribute( 'aria-expanded' );
				deplier.setAttribute( 'aria-expanded', ouvert ? 'false' : 'true' );
				liste.hidden = ouvert;
			} );
		}

		// Croix, « Plus tard », clic sur le fond.
		//
		// Le refus n'est mémorisé qu'en mode invitation : refermer l'écran
		// de gestion, ouvert délibérément, ne vaut pas refus — sinon la
		// fenêtre cesserait de se proposer à qui a simplement regardé ses
		// réglages.
		var fermetures = modal.querySelectorAll( '[data-afk-push-close]' );
		for ( var i = 0; i < fermetures.length; i++ ) {
			fermetures[ i ].addEventListener( 'click', function () {
				ferme( ! modal.classList.contains( 'afk-push-gestion' ) );
			} );
		}

		var oui = modal.querySelector( '[data-afk-push-accept]' );
		if ( oui ) {
			oui.addEventListener( 'click', async function () {
				// Les cases sont cochées d'origine : un visiteur qui accepte
				// sans déplier reçoit tout, et porte alors TOUTES les
				// étiquettes. Sans elles, un envoi ciblé plus tard ne
				// l'atteindrait jamais — c'est le piège qu'on a rencontré.
				var choisis = [];
				var cases = modal.querySelectorAll( '.afk-push-tag:checked' );
				for ( var j = 0; j < cases.length; j++ ) {
					choisis.push( cases[ j ].value );
				}

				oui.disabled = true;

				// En mode gestion, l'autorisation est déjà accordée : on
				// enregistre les choix, sans redemander quoi que ce soit.
				if ( modal.classList.contains( 'afk-push-gestion' ) ) {
					try {
						var os = await auSDK();
						await applyTags( os, choisis );
						show( etat, T.saved );
						window.setTimeout( function () { ferme( false ); }, 1200 );
					} catch ( e ) {
						show( etat, T.blocked );
					}
					oui.disabled = false;
					return;
				}

				try {
					// Le SDK a été réclamé dès l'affichage : il est là.
					// L'attendre ICI ferait perdre le geste de
					// l'utilisateur, que les navigateurs exigent pour
					// afficher leur invite.
					var OneSignal = sdkPret || await auSDK();
					var accorde = await askPermission( OneSignal );
					if ( ! accorde ) {
						mesure( 'afk_push_refused' );
						show( etat, T.blocked );
						oui.disabled = false;
						return;
					}
					await applyTags( OneSignal, choisis );
					mesure( 'afk_push_subscribe', { centres: choisis.join( ',' ) } );
					show( etat, T.saved );
					window.setTimeout( function () { ferme( false ); }, 1200 );
				} catch ( e ) {
					show( etat, T.blocked );
					oui.disabled = false;
				}
			} );
		}

		return { modal: modal, ferme: ferme, surEchap: surEchap };
	}

	/**
	 * Décide de l'affichage spontané de la fenêtre.
	 *
	 * Ne branche plus rien : uniquement le « faut-il l'ouvrir, et quand ».
	 */
	function bindPopup( commandes ) {
		if ( ! commandes || ! CFG.popup || ! ( 'Notification' in window ) ) {
			return;
		}

		if ( ! contexteAutorise() ) {
			return;
		}

		// Déjà abonné, déjà refusé, ou navigateur qui a dit non une fois :
		// dans les trois cas, on se tait. Un refus de navigateur est quasi
		// définitif, insister ne sert qu'à agacer.
		if ( 'default' !== Notification.permission || refusMemorise() ) {
			return;
		}

		// Le SDK est mis en route dès maintenant, pour être prêt au clic.
		auSDK().then( function ( OneSignal ) { sdkPret = OneSignal; } );

		var boite = commandes.modal.querySelector( '.afk-push-modal-boite' );

		window.setTimeout( function () {
			commandes.modal.hidden = false;
			mesure( 'afk_push_prompt', { mode: 'invitation' } );
			document.addEventListener( 'keydown', commandes.surEchap );
			if ( boite ) {
				boite.focus();
			}
		}, ( CFG.popupDelay || 25 ) * 1000 );
	}

	/**
	 * Branche les cases à cocher posées par le shortcode.
	 */
	function bindPreferences( OneSignal ) {
		var blocks = document.querySelectorAll( '.afk-push' );
		if ( ! blocks.length ) {
			return;
		}

		for ( var i = 0; i < blocks.length; i++ ) {
			( function ( block ) {
				// Le bloc reste masqué tant qu'on ne sait pas si le
				// navigateur sait faire.
				if ( ! ( 'Notification' in window ) ) {
					return;
				}
				block.hidden = false;

				var feedback = block.querySelector( '.afk-push-feedback' );
				var save = block.querySelector( '.afk-push-save' );
				var unsub = block.querySelector( '.afk-push-unsub' );

				if ( ! save ) {
					return;
				}

				// Le bouton de désabonnement n'a de sens que pour qui est
				// abonné : l'afficher autrement serait une fausse piste.
				function majEtat() {
					var abonne = false;
					try {
						abonne = true === OneSignal.User.PushSubscription.optedIn;
					} catch ( e ) { /* SDK pas encore prêt */ }

					if ( unsub ) {
						unsub.hidden = ! abonne;
					}
				}

				majEtat();

				if ( unsub ) {
					unsub.addEventListener( 'click', async function () {
						unsub.disabled = true;
						try {
							await desabonner( OneSignal );
							mesure( 'afk_push_unsubscribe' );
							show( feedback, T.unsubOk );
							majEtat();
						} catch ( e ) {
							show( feedback, T.blocked );
						}
						unsub.disabled = false;
					} );
				}

				save.addEventListener( 'click', async function () {
					var actives = [];
					var boxes = block.querySelectorAll( '.afk-push-tag:checked' );
					for ( var j = 0; j < boxes.length; j++ ) {
						actives.push( boxes[ j ].value );
					}

					try {
						var granted = await askPermission( OneSignal );
						if ( ! granted ) {
							show( feedback, T.blocked );
							return;
						}
						await applyTags( OneSignal, actives );
						show( feedback, T.saved );
						majEtat();
					} catch ( e ) {
						show( feedback, T.blocked );
					}
				} );
			} )( blocks[ i ] );
		}
	}

	function show( el, message ) {
		if ( ! el ) {
			return;
		}
		el.textContent = message || '';
		el.hidden = ! message;
	}
	/* ------------------------------------------------------------------
	 * Cloche flottante — gestion de l'abonnement
	 *
	 * Elle n'apparaît qu'aux abonnés, et rouvre la fenêtre en mode
	 * « gestion » : centres d'intérêt dépliés et cochés selon les choix
	 * en cours, bouton d'enregistrement, et retrait du consentement.
	 * ---------------------------------------------------------------- */

	function bindCloche( commandes ) {
		var cloche = document.getElementById( 'afk-push-cloche' );
		var modal  = document.getElementById( 'afk-push-modal' );

		if ( ! cloche || ! modal || false === CFG.bell || ! ( 'Notification' in window ) ) {
			return;
		}

		var etat    = modal.querySelector( '.afk-push-modal-etat' );
		var titre   = modal.querySelector( '.afk-push-modal-titre' );
		var intro   = modal.querySelector( '.afk-push-modal-intro' );
		var oui     = modal.querySelector( '[data-afk-push-accept]' );
		var desabo  = modal.querySelector( '[data-afk-push-unsub]' );
		var deplier = modal.querySelector( '.afk-push-deplier' );
		var liste   = modal.querySelector( '.afk-push-liste' );

		// L'abonnement peut naître à tout moment : on surveille plutôt
		// que de décider une fois pour toutes au chargement.
		function majCloche( OneSignal ) {
			var abonne = false;
			try {
				abonne = true === OneSignal.User.PushSubscription.optedIn;
			} catch ( e ) { /* pas encore prêt */ }
			cloche.hidden = ! abonne;
		}

		// ⚠️ L'écouteur est posé TOUT DE SUITE, pas dans la promesse du
		// SDK. Celui-ci est un script tiers, différé au premier geste :
		// attendre son arrivée pour brancher le clic laissait la cloche
		// visible mais inerte. Même leçon que pour la fenêtre.
		cloche.addEventListener( 'click', async function () {
			var OneSignal = await auSDK();

			// Mode gestion : la fenêtre change de propos.
			modal.classList.add( 'afk-push-gestion' );

			if ( titre && T.manage ) {
				titre.textContent = T.manage;
			}
			if ( intro ) {
				intro.hidden = true;
			}
			if ( oui && T.save ) {
				oui.textContent = T.save;
				oui.disabled = false;
			}
			if ( desabo ) {
				desabo.hidden = false;
			}

			// Les centres d'intérêt sont dépliés : c'est ce qu'on vient
			// gérer, les cacher n'aurait aucun sens.
			if ( deplier && liste ) {
				deplier.hidden = true;
				liste.hidden = false;
			}

			// Cases cochées selon les choix réellement en vigueur.
			try {
				var actuels = await OneSignal.User.getTags();
				var cases = modal.querySelectorAll( '.afk-push-tag' );
				for ( var i = 0; i < cases.length; i++ ) {
					cases[ i ].checked = '0' !== actuels[ cases[ i ].value ];
				}
			} catch ( e ) { /* on garde l'état affiché */ }

			show( etat, '' );
			modal.hidden = false;
			mesure( 'afk_push_prompt', { mode: 'gestion' } );

			// Echap referme, comme partout ailleurs.
			if ( commandes ) {
				document.addEventListener( 'keydown', commandes.surEchap );
			}
		} );

		// La visibilité, elle, dépend de l'abonnement : on la met à jour
		// dès que le SDK peut nous le dire, puis à chaque changement.
		auSDK().then( function ( OneSignal ) {
			majCloche( OneSignal );
			rapproche( OneSignal );

			try {
				OneSignal.User.PushSubscription.addEventListener( 'change', function () {
					majCloche( OneSignal );
				} );
			} catch ( e ) { /* version sans événement : le chargement suffit */ }
		} );

		if ( desabo ) {
			desabo.addEventListener( 'click', async function () {
				desabo.disabled = true;
				try {
					var OneSignal = await auSDK();
					await desabonner( OneSignal );
							mesure( 'afk_push_unsubscribe' );
					show( etat, T.unsubOk );
					majCloche( OneSignal );
					window.setTimeout( function () { modal.hidden = true; }, 1400 );
				} catch ( e ) {
					show( etat, T.blocked );
				}
				desabo.disabled = false;
			} );
		}
	}

	// La fenêtre vit sa vie, indépendamment du SDK.
	function demarrer() {
		// Les commandes de la fenêtre d'abord : l'affichage automatique
		// comme la cloche s'appuient dessus.
		var commandes = bindModal();
		bindPopup( commandes );
		bindCloche( commandes );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
} )();
