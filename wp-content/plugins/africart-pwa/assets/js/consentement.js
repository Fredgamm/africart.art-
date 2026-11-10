/**
 * Bandeau de consentement à la mesure d'audience.
 *
 * Le principe qui gouverne ce fichier : **le refus est l'état par
 * défaut**. Tant que le visiteur n'a pas répondu, la mesure est
 * refusée — pas « en attente », pas « tolérée le temps du choix ».
 * C'est le seul état défendable, et c'est aussi celui que Google
 * respecte de lui-même à travers le mode consentement : les balises
 * posées dans le gestionnaire s'y conforment sans réglage
 * supplémentaire.
 *
 * ⚠️ Les deux boutons ont le même poids visuel, délibérément. Un refus
 * plus discret que l'acceptation vicie le consentement — c'est le
 * premier reproche adressé aux bandeaux du marché, et il suffit à les
 * rendre inopposables.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_CONSENT || {};
	var CLE = 'afkConsent';

	/**
	 * Réponse déjà donnée, si elle est encore valable.
	 *
	 * @return {string} `oui`, `non`, ou chaîne vide si rien de valable.
	 */
	function reponse() {
		try {
			var brut = window.localStorage.getItem( CLE );

			if ( ! brut ) {
				return '';
			}

			var enregistre = JSON.parse( brut );
			var jours = ( CFG.jours || 180 ) * 86400000;

			// Un choix a une durée. Passé ce délai la question se repose :
			// un accord donné il y a deux ans ne dit plus rien de l'accord
			// d'aujourd'hui.
			if ( ! enregistre.date || ( Date.now() - enregistre.date ) > jours ) {
				return '';
			}

			return enregistre.choix || '';
		} catch ( e ) {
			return '';
		}
	}

	/** Enregistre la réponse et sa date. */
	function retiens( choix ) {
		try {
			window.localStorage.setItem( CLE, JSON.stringify( {
				choix: choix,
				date: Date.now()
			} ) );
		} catch ( e ) { /* navigation privée : la question se reposera */ }
	}

	/**
	 * Transmet la réponse au mode consentement de Google.
	 *
	 * Seule la mesure d'audience est concernée. Les signaux publicitaires
	 * restent refusés : le site ne fait pas de publicité, et accorder ce
	 * qu'on n'utilise pas serait demander plus que nécessaire.
	 */
	function transmets( accorde ) {
		try {
			window.dataLayer = window.dataLayer || [];

			// La forme `arguments` est ici la bonne, y compris avec le
			// gestionnaire de balises : le mode consentement se lit sous
			// cette forme, et non comme un événement nommé.
			function gtag() { window.dataLayer.push( arguments ); }

			gtag( 'consent', 'update', {
				analytics_storage: accorde ? 'granted' : 'denied'
			} );

			// Un événement nommé, pour qui préfère déclencher ses balises
			// sur le consentement plutôt que de s'en remettre au mode.
			window.dataLayer.push( {
				event: accorde ? 'afk_consent_granted' : 'afk_consent_denied'
			} );
		} catch ( e ) { /* la mesure ne doit jamais rien casser */ }
	}

	function ferme( bandeau ) {
		bandeau.hidden = true;
		enAttente = false;

		// Prevenir ceux qui patientaient — le bandeau d'installation, en
		// particulier, qui occupe le meme bas d'ecran.
		document.dispatchEvent( new CustomEvent( 'afkConsentTranche' ) );
	}

	// Vrai tant que la question est posee et sans reponse.
	var enAttente = false;

	function demarrer() {
		var bandeau = document.getElementById( 'afk-consent' );

		if ( ! bandeau ) {
			return;
		}

		var deja = reponse();

		if ( deja ) {
			// Déjà répondu : on retransmet à chaque page, car le mode
			// consentement ne survit pas au chargement.
			transmets( 'oui' === deja );
			return;
		}

		var oui = bandeau.querySelector( '[data-afk-consent-oui]' );
		var non = bandeau.querySelector( '[data-afk-consent-non]' );

		function repond( choix ) {
			retiens( choix );
			transmets( 'oui' === choix );
			ferme( bandeau );
		}

		if ( oui ) {
			oui.addEventListener( 'click', function () { repond( 'oui' ); } );
		}
		if ( non ) {
			non.addEventListener( 'click', function () { repond( 'non' ); } );
		}

		enAttente = true;
		bandeau.hidden = false;
	}

	// Consulté par l'émetteur de mesure, qui se tait sans accord.
	window.afkConsentOK = function () {
		return 'oui' === reponse();
	};

	// Consulté par le bandeau d'installation, qui occupe le même bas
	// d'écran et doit céder la place tant que la question est posée.
	window.afkConsentEnAttente = function () {
		return enAttente;
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
