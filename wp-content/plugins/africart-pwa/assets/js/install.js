/* AFRICART PWA — CTA d'installation.
 *
 * Un site ne peut pas ouvrir seul la fenêtre d'installation : le navigateur
 * émet `beforeinstallprompt` quand les critères sont réunis, on met
 * l'événement de côté, on affiche notre bandeau, et `prompt()` — appelé
 * depuis un vrai clic utilisateur — ouvre la fenêtre NATIVE.
 *
 * Parcours : Android/desktop (prompt natif), iOS (instructions Partager),
 * navigateur in-app Instagram/Facebook (installation impossible).
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_INSTALL || {};
	var T = CFG.texts || {};
	var STORE_KEY = 'afkPwaInstallDismissed';

	var deferredPrompt = null;
	var banner = null;

	/* ------------------------------------------------------------------
	 * Détection du contexte
	 * ---------------------------------------------------------------- */

	function isStandalone() {
		return ( window.matchMedia && window.matchMedia( '(display-mode: standalone)' ).matches ) ||
			window.navigator.standalone === true;
	}

	function isIos() {
		var ua = window.navigator.userAgent;
		// iPadOS 13+ se présente comme un Mac : on le reconnaît au tactile.
		return /iphone|ipad|ipod/i.test( ua ) ||
			( /macintosh/i.test( ua ) && navigator.maxTouchPoints > 1 );
	}

	/**
	 * Un iPhone, mais pas Safari.
	 *
	 * ⚠️ Sur iOS, aucun autre navigateur ne peut installer une
	 * application — c'est une règle d'Apple, pas un défaut du site.
	 * Servir la marche à suivre de Safari à quelqu'un qui est dans Chrome
	 * l'envoie chercher un bouton « Partager » qui n'ouvrira jamais « Sur
	 * l'écran d'accueil ». Il cherche, ne trouve pas, et conclut que
	 * l'application ne marche pas chez lui.
	 */
	function isIosAutre() {
		return isIos() && /CriOS|FxiOS|EdgiOS|OPT\/|YaBrowser|DuckDuckGo/i.test( window.navigator.userAgent );
	}

	// Navigateurs intégrés aux applis : l'installation y est impossible.
	function isInAppBrowser() {
		var ua = window.navigator.userAgent;
		return /Instagram|FBAN|FBAV|FB_IAB|Messenger|Line\/|Twitter|TikTok|Pinterest|Snapchat|LinkedInApp/i.test( ua );
	}

	// Samsung Internet fabrique lui-même le paquet d'installation, et
	// Play Protect ne le reconnaît pas : « Appli non sécurisée bloquée »
	// s'affiche à tort. Problème du navigateur, connu et hors de notre
	// portée — mais sans un mot d'explication, le visiteur abandonne.
	function isSamsung() {
		return /SamsungBrowser/i.test( window.navigator.userAgent );
	}

	/* ------------------------------------------------------------------
	 * Mémorisation du refus
	 * ---------------------------------------------------------------- */

	function isDismissed() {
		try {
			var until = window.localStorage.getItem( STORE_KEY );
			return until && Date.now() < parseInt( until, 10 );
		} catch ( e ) {
			return false;
		}
	}

	function remember() {
		try {
			var days = CFG.dismissDays || 7;
			window.localStorage.setItem( STORE_KEY, String( Date.now() + days * 86400000 ) );
		} catch ( e ) { /* stockage indisponible : sans gravité */ }
	}

	/* ------------------------------------------------------------------
	 * Construction du bandeau
	 * ---------------------------------------------------------------- */

	function build( mode ) {
		var el = document.createElement( 'div' );
		el.className = 'afk-install-banner afk-install-' + ( CFG.position || 'bottom' );
		el.setAttribute( 'role', 'dialog' );
		el.setAttribute( 'aria-label', T.title || 'Installation' );

		// Visuel : le GIF de la plateforme courante s'il est configuré,
		// sinon l'icône de l'application.
		var gif = ( CFG.gifs || {} )[ mode ] || '';
		var visual;

		if ( gif ) {
			el.classList.add( 'has-gif' );
			visual = '<img class="afk-install-gif" src="' + gif + '" alt="" />';
		} else {
			visual = CFG.icon
				? '<img class="afk-install-icon" src="' + CFG.icon + '" alt="" />'
				: '';
		}

		// Message adapté au contexte.
		var message = T.message;
		var action = '<button type="button" class="afk-install-go">' + esc( T.button ) + '</button>';
		var note = '';

		if ( 'ios' === mode ) {
			message = T.ios;
			action = ''; // iOS : instructions seulement, pas de prompt possible.
		} else if ( 'inapp' === mode ) {
			message = T.inapp;
			action = '';
		} else if ( isSamsung() && T.samsung ) {
			// Prévenir AVANT le clic : l'avertissement Play Protect propre à
			// Samsung arrive après, et sans explication il fait fuir.
			// L'icône est embarquée : aucune police d'icônes n'est chargée
			// sur le site public.
			note = '<span class="afk-install-note" role="note">' +
				'<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">' +
					'<path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1zm0 3.2a1 1 0 1 1 0 2 1 1 0 0 1 0-2zM9 12H7V7.5h2z"/>' +
				'</svg>' +
				'<span>' + esc( T.samsung ) + '</span>' +
			'</span>';
		}

		// La note occupe sa propre ligne, sous la rangée principale :
		// glissée dans la colonne du texte, elle s'y écrasait sur sept
		// lignes illisibles.
		if ( note ) {
			el.classList.add( 'has-note' );
		}

		el.innerHTML =
			visual +
			'<div class="afk-install-body">' +
				'<strong class="afk-install-title">' + esc( T.title ) + '</strong>' +
				'<span class="afk-install-text">' + esc( message ) + '</span>' +
			'</div>' +
			note +
			action +
			'<button type="button" class="afk-install-close" aria-label="' + esc( T.close ) + '">&times;</button>';

		// Hauteur du visuel : ignorée si absente ou nulle, la valeur CSS par
		// défaut prend alors le relais (un 0 masquerait le GIF).
		var gifHeight = parseInt( CFG.gifHeight, 10 );
		if ( gif && gifHeight > 0 ) {
			el.style.setProperty( '--afk-gif-height', gifHeight + 'px' );
		}

		if ( CFG.accentColor ) {
			el.style.setProperty( '--afk-accent', CFG.accentColor );
		}
		if ( CFG.themeColor ) {
			el.style.setProperty( '--afk-theme', CFG.themeColor );
		}

		el.querySelector( '.afk-install-close' ).addEventListener( 'click', function () {
			hide();
			remember();
		} );

		var go = el.querySelector( '.afk-install-go' );
		if ( go ) {
			go.addEventListener( 'click', triggerNativePrompt );
		}

		document.body.appendChild( el );
		// Laisse le navigateur peindre avant l'animation d'entrée.
		requestAnimationFrame( function () {
			el.classList.add( 'is-visible' );
		} );

		return el;
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : s;
		return d.innerHTML;
	}

	// Contexte du bandeau affiche — android, ios, inapp, samsung. Retenu
	// ici parce que la mesure du clic en a besoin, et qu'elle se produit
	// bien apres l'affichage.
	var contexte = '';

	function show( mode ) {
		if ( banner || isStandalone() || isDismissed() ) {
			return;
		}

		// ⚠️ Jamais par-dessus la page qui propose déjà l'installation.
		//
		// Cette page-là ne parle que de cela, en grand, avec le geste de
		// l'appareil et son visuel. Y superposer le bandeau posait une
		// invitation sur une invitation — et masquait le bas de la page
		// avec ce qu'elle venait d'expliquer.
		if ( document.querySelector( '[data-afk-installer]' ) ) {
			return;
		}

		// ⚠️ Les deux bandeaux occupent le même bas d'écran, et se
		// superposaient. La priorité ne se discute pas : on ne peut pas
		// inviter à installer par-dessus une question à laquelle le
		// visiteur n'a pas encore répondu. On patiente, sans rien perdre.
		if ( window.afkConsentEnAttente && window.afkConsentEnAttente() ) {
			document.addEventListener( 'afkConsentTranche', function () {
				show( mode );
			}, { once: true } );
			return;
		}
		var delay = CFG.delay || 0;
		window.setTimeout( function () {
			if ( ! banner ) {
				contexte = mode || 'android';
				banner = build( mode );
				mesure( 'afk_install_prompt', { contexte: contexte } );
				placeLesAutres();
			}
		}, delay * 1000 );
	}

	/**
	 * La place que ce bandeau prend au bas de l'écran.
	 *
	 * ⚠️ Deux surfaces flottantes s'ancrent au même bas d'écran : celle-ci
	 * et le message d'état du dépôt. Elles se recouvraient — celle-ci
	 * passe devant — et l'artiste perdait l'information qui le concernait
	 * vraiment : ses œuvres pas encore parties, ou sa session expirée,
	 * masquées par une invitation à installer l'application.
	 *
	 * Le consentement, lui, fait patienter celui-ci : il pose une question
	 * à laquelle il faut répondre. Un message d'état n'est pas une
	 * question, il peut durer toute une session hors connexion — le faire
	 * attendre reviendrait à ne jamais proposer l'installation à qui en
	 * aurait le plus besoin. Ils se rangent donc l'un au-dessus de
	 * l'autre.
	 *
	 * La mesure passe par une variable de style : le message d'état n'a
	 * pas à connaître ce module, ni l'inverse.
	 */
	function placeLesAutres() {
		var racine = document.documentElement;

		if ( ! banner || ! banner.classList.contains( 'afk-install-bottom' ) ) {
			racine.style.removeProperty( '--afk-banniere' );
			return;
		}

		var haut = Math.round( banner.getBoundingClientRect().height );

		// 14 px : l'écart que ce bandeau garde lui-même avec le bord.
		racine.style.setProperty( '--afk-banniere', ( haut + 14 ) + 'px' );
	}

	// Le texte se replie autrement quand l'écran tourne : la hauteur
	// change, et la place réservée avec elle.
	window.addEventListener( 'resize', function () {
		if ( banner ) {
			placeLesAutres();
		}
	} );

	function hide() {
		if ( ! banner ) {
			return;
		}
		banner.classList.remove( 'is-visible' );
		var el = banner;
		banner = null;
		document.documentElement.style.removeProperty( '--afk-banniere' );
		window.setTimeout( function () {
			if ( el.parentNode ) {
				el.parentNode.removeChild( el );
			}
		}, 300 );
	}

	/* ------------------------------------------------------------------
	 * Mesure
	 *
	 * Un seul point de sortie pour tout le plugin : `afkMesure`, defini
	 * dans mesure.js, qui choisit entre la forme GTM et la forme gtag.
	 *
	 * Jamais par un parametre d'URL : mesure en production, tout
	 * parametre fait contourner le cache de bordure, et chaque lancement
	 * de l'app paierait 1 a 2 s au lieu de 70 ms. C'est pour cela que
	 * l'URL de demarrage reste PROPRE.
	 * ---------------------------------------------------------------- */

	function mesure( evenement, params ) {
		if ( window.afkMesure ) {
			window.afkMesure( evenement, params );
		}
	}

	/* ------------------------------------------------------------------
	 * Déclenchement de la fenêtre NATIVE
	 * ---------------------------------------------------------------- */

	function triggerNativePrompt() {
		if ( ! deferredPrompt ) {
			return;
		}

		mesure( 'afk_install_click', { contexte: contexte || 'android' } );

		deferredPrompt.prompt(); // <- fenêtre d'installation native du navigateur
		deferredPrompt.userChoice.then( function ( choice ) {
			if ( 'dismissed' === choice.outcome ) {
				remember();
			}
			mesure(
				'accepted' === choice.outcome ? 'afk_install_done' : 'afk_install_refused',
				{ contexte: contexte || 'android' }
			);
			// L'événement n'est utilisable qu'une fois.
			deferredPrompt = null;
			hide();
			hideCtaButtons();
			document.dispatchEvent( new CustomEvent( 'afkPwaInstallChoice', { detail: choice } ) );
		} );
	}

	function showCtaButtons() {
		var nodes = document.querySelectorAll( '.afk-install-cta' );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].hidden = false;
			nodes[ i ].addEventListener( 'click', triggerNativePrompt );
		}
	}

	/* ------------------------------------------------------------------
	 * La page d'installation
	 * ---------------------------------------------------------------- */

	/**
	 * Dit aux pages d'installation où se trouve ce visiteur.
	 *
	 * ⚠️ La détection ne se refait pas ailleurs.
	 *
	 * Reconnaître iPhone, navigateur intégré et application déjà installée
	 * demande une poignée de tests qui ne sont ni évidents ni stables —
	 * iPadOS se présente comme un Mac, la liste des navigateurs intégrés
	 * s'allonge à chaque saison. Les recopier dans un second script aurait
	 * garanti qu'un jour les deux ne diraient plus la même chose, et que
	 * la page montrerait le geste d'une autre plateforme.
	 *
	 * Ce module sait déjà tout cela. Il pose donc le contexte sur la page,
	 * qui n'a plus qu'à s'habiller : `data-afk-contexte`.
	 */
	function situe() {
		var ou = 'attente';

		if ( isStandalone() ) {
			ou = 'installee';
		} else if ( isInAppBrowser() ) {
			ou = 'inapp';
		} else if ( deferredPrompt ) {
			ou = 'prompt';
		} else if ( isIosAutre() ) {
			ou = 'ios-autre';
		} else if ( isIos() ) {
			ou = 'ios';
		} else if ( ! navigator.maxTouchPoints ) {
			// ⚠️ Un ordinateur n'est pas un téléphone qui attend.
			//
			// Sans cette distinction, la page affichait la marche à suivre
			// d'Android sur un écran de bureau — « touchez le bouton
			// ci-dessus » alors qu'aucun bouton n'apparaît, faute
			// d'événement d'installation. Une impasse, sur l'écran même où
			// l'on ouvre le plus souvent un lien reçu.
			//
			// Sur un ordinateur, la bonne réponse n'est pas d'installer
			// ici : c'est d'ouvrir la page sur le téléphone, là où
			// l'application sert.
			ou = 'bureau';
		}

		var pages = document.querySelectorAll( '[data-afk-installer]' );

		for ( var i = 0; i < pages.length; i++ ) {
			pages[ i ].setAttribute( 'data-afk-contexte', ou );
		}

		return ou;
	}

	function hideCtaButtons() {
		var nodes = document.querySelectorAll( '.afk-install-cta' );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].hidden = true;
		}
	}

	/* ------------------------------------------------------------------
	 * Écoute des événements navigateur
	 * ---------------------------------------------------------------- */

	// Émis par Chrome/Edge/Samsung quand la PWA est installable.
	window.addEventListener( 'beforeinstallprompt', function ( e ) {
		e.preventDefault(); // empêche l'ancienne mini-infobar
		deferredPrompt = e;
		showCtaButtons();
		situe();
		show( 'prompt' );
	} );

	// Installation effectuée : on compte, puis on nettoie.
	window.addEventListener( 'appinstalled', function () {
		mesure( 'afk_install_done', { contexte: 'systeme' } );
		deferredPrompt = null;
		hide();
		hideCtaButtons();
		situe();
		try {
			window.localStorage.removeItem( STORE_KEY );
		} catch ( e ) { /* ignore */ }
	} );

	// Lancement de l'application installée : une fois par session, pas à
	// chaque page — c'est l'ouverture de l'app qu'on compte, pas la
	// navigation à l'intérieur.
	if ( isStandalone() ) {
		var dejaCompte = false;
		try {
			dejaCompte = '1' === window.sessionStorage.getItem( 'afkPwaLaunch' );
			window.sessionStorage.setItem( 'afkPwaLaunch', '1' );
		} catch ( e ) { /* stockage indisponible : on compte quand même */ }

		if ( ! dejaCompte ) {
			mesure( 'afk_app_launch' );
		}
	}

	// iOS et navigateurs in-app n'émettent jamais beforeinstallprompt :
	// on décide à partir du contexte, une fois la page chargée.
	function fallback() {
		// Avant tout le reste : la page d'installation doit s'habiller même
		// là où aucun bandeau ne paraîtra — c'est précisément son objet.
		situe();

		if ( isStandalone() ) {
			return;
		}
		if ( isInAppBrowser() ) {
			if ( CFG.showInApp ) {
				show( 'inapp' );
			}
			return;
		}
		if ( isIos() && CFG.showOnIos ) {
			show( 'ios' );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', fallback );
	} else {
		fallback();
	}
} )();
