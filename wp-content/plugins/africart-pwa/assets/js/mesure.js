/**
 * Émetteur de mesure — un seul point de sortie pour tout le plugin.
 *
 * ⚠️ Le piège que ce fichier existe pour éviter : `dataLayer` est
 * partagé par gtag.js et par Google Tag Manager, mais **son contenu ne
 * l'est pas**. gtag y écrit des arguments positionnels
 * (`['event', nom, params]`), GTM y attend un objet nommé
 * (`{event: nom, ...params}`). Émettre la mauvaise forme ne produit
 * aucune erreur : l'événement disparaît, tout simplement — et on peut
 * chercher longtemps du côté du déclencheur.
 *
 * On détecte donc lequel des deux est en place, à l'exécution. Un site
 * qui passe de gtag à GTM n'a rien à changer ici, et rien ne s'arrête
 * pendant la bascule.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_MESURE || {};

	/**
	 * Un vrai conteneur GTM est-il chargé ?
	 *
	 * `window.google_tag_manager` ne suffit pas : gtag.js le renseigne
	 * aussi, avec sa propre clé `GT-…` ou `G-…`. Seul un identifiant
	 * `GTM-…` atteste d'un conteneur capable de lire nos objets.
	 */
	function conteneurGTM() {
		// ⚠️ Un conteneur déclaré dans les réglages fait foi, même s'il
		// n'est pas encore chargé. GTM rejoue tout l'historique du
		// `dataLayer` à son démarrage : un objet déposé avant lui sera
		// bien vu. Attendre son enregistrement, en revanche, faisait
		// partir en forme gtag tout ce qui s'émet tôt — l'origine
		// sociale, mesurée à l'arrivée, n'atteignait jamais GTM.
		if ( CFG.gtm ) {
			return true;
		}

		var reg = window.google_tag_manager;

		if ( ! reg ) {
			return false;
		}

		for ( var cle in reg ) {
			if ( 0 === String( cle ).indexOf( 'GTM-' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Annonce un fait.
	 *
	 * Ne renvoie rien et n'échoue jamais : une mesure absente est un
	 * moindre mal, une page cassée par la mesure serait une faute.
	 *
	 * @param {string} nom    Nom de l'événement, préfixé `afk_`.
	 * @param {Object} params Paramètres facultatifs.
	 */
	function mesure( nom, params ) {
		if ( ! CFG.actif ) {
			return;
		}

		// Consentement requis et pas encore donné : on ne mesure pas.
		// Ni « on met de côté pour plus tard » — un événement retenu
		// puis émis après coup mesurerait un comportement observé sans
		// accord.
		//
		// ⚠️ L'absence de `afkConsentOK` vaut refus, elle ne vaut PAS
		// dispense. Ce fichier est chargé dans l'en-tête et celui du
		// consentement en pied de page : tester la présence de la
		// fonction avant de l'interroger laissait passer tout ce qui
		// s'émet tôt — l'origine sociale, mesurée à l'arrivée. Vérifié :
		// un événement partait malgré un refus.
		if ( CFG.consent && ( ! window.afkConsentOK || ! window.afkConsentOK() ) ) {
			return;
		}

		try {
			window.dataLayer = window.dataLayer || [];

			if ( conteneurGTM() ) {
				// Forme GTM : un objet, dont `event` porte le nom. C'est
				// lui que déclenche un « Événement personnalisé ».
				var charge = { event: nom };

				for ( var k in ( params || {} ) ) {
					if ( Object.prototype.hasOwnProperty.call( params, k ) ) {
						charge[ k ] = params[ k ];
					}
				}

				window.dataLayer.push( charge );
				return;
			}

			// Repli gtag. La file d'attente de gtag garantit que
			// l'événement part même si le script est encore en route —
			// utile, puisque nous différons les scripts tiers.
			var g = window.gtag || function () {
				window.dataLayer.push( arguments );
			};

			g( 'event', nom, params || {} );
		} catch ( e ) { /* la mesure ne doit jamais rien casser */ }
	}

	/**
	 * D'où vient ce visiteur ?
	 *
	 * L'offre demande de suivre l'origine Instagram. Deux indices, dans
	 * cet ordre : le navigateur intégré de l'application — le plus sûr,
	 * il ne peut pas être usurpé par un lien — puis le référent.
	 *
	 * Émis une seule fois par session : compter à chaque page mesurerait
	 * la navigation, pas l'acquisition.
	 */
	function origine() {
		var reseaux = [
			[ /Instagram/i, 'instagram' ],
			[ /FBAN|FBAV|FB_IAB/i, 'facebook' ],
			[ /Messenger/i, 'messenger' ],
			[ /WhatsApp/i, 'whatsapp' ],
			[ /LinkedInApp/i, 'linkedin' ],
			[ /TikTok/i, 'tiktok' ]
		];

		var ua = navigator.userAgent || '';
		var trouve = '';

		for ( var i = 0; i < reseaux.length; i++ ) {
			if ( reseaux[ i ][ 0 ].test( ua ) ) {
				trouve = reseaux[ i ][ 1 ];
				break;
			}
		}

		if ( ! trouve && document.referrer ) {
			var hotes = {
				'instagram.com': 'instagram',
				'facebook.com': 'facebook',
				'l.facebook.com': 'facebook',
				'whatsapp.com': 'whatsapp',
				'linkedin.com': 'linkedin',
				't.co': 'twitter'
			};

			for ( var hote in hotes ) {
				if ( document.referrer.indexOf( hote ) !== -1 ) {
					trouve = hotes[ hote ];
					break;
				}
			}
		}

		if ( ! trouve ) {
			return;
		}

		try {
			if ( window.sessionStorage.getItem( 'afkSource' ) ) {
				return;
			}
			window.sessionStorage.setItem( 'afkSource', trouve );
		} catch ( e ) { /* navigation privée : on mesurera à chaque page */ }

		mesure( 'afk_source_social', { reseau: trouve } );
	}

	// Point d'entrée unique, appelé par les autres scripts du plugin.
	window.afkMesure = mesure;

	if ( ! CFG.actif ) {
		return;
	}

	if ( CFG.consent ) {
		// L'origine est retenue en mémoire de session dès l'arrivée, mais
		// n'est annoncée qu'une fois l'accord donné. Rien n'est perdu, et
		// rien ne part avant l'heure.
		document.addEventListener( 'afkConsentTranche', origine );

		// Accord donné lors d'une visite précédente : le bandeau ne
		// s'affiche pas, et l'événement de tranchage n'aura pas lieu.
		document.addEventListener( 'DOMContentLoaded', function () {
			if ( window.afkConsentOK && window.afkConsentOK() ) {
				origine();
			}
		} );

		return;
	}

	origine();
}() );
