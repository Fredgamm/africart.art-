/* AFRICART PWA — restitution des scripts différés.
 *
 * Les balises `type="afk/delay"` attendent le premier geste du
 * visiteur — ou l'échéance du délai de secours, pour qu'un visiteur
 * immobile finisse servi lui aussi.
 *
 * Cas particulier : un visiteur qui a déjà traduit le site (cookie
 * googtrans) est servi immédiatement — sa langue ne doit pas attendre
 * son geste.
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_DELAY || {};
	var fait = false;

	var GESTES = [ 'pointerdown', 'pointermove', 'touchstart', 'keydown', 'wheel', 'scroll' ];

	function restitue() {
		if ( fait ) {
			return;
		}
		fait = true;

		GESTES.forEach( function ( g ) {
			window.removeEventListener( g, restitue );
		} );

		var balises = document.querySelectorAll( 'script[type="afk/delay"]' );

		for ( var i = 0; i < balises.length; i++ ) {
			var ancienne = balises[ i ];
			var neuve = document.createElement( 'script' );

			// Les attributs d'origine reprennent leur place — sauf notre
			// type de mise en attente et l'adresse remisée.
			for ( var j = 0; j < ancienne.attributes.length; j++ ) {
				var a = ancienne.attributes[ j ];
				if ( 'type' === a.name || 'data-afk-src' === a.name ) {
					continue;
				}
				neuve.setAttribute( a.name, a.value );
			}

			neuve.src = ancienne.getAttribute( 'data-afk-src' );
			// Ordre d'exécution du document préservé : un script inséré
			// dynamiquement est asynchrone par défaut.
			neuve.async = false;

			ancienne.parentNode.replaceChild( neuve, ancienne );
		}
	}

	// Visiteur déjà traduit : servi sans attendre son geste.
	if ( -1 !== document.cookie.indexOf( 'googtrans' ) ) {
		restitue();
		return;
	}

	GESTES.forEach( function ( g ) {
		window.addEventListener( g, restitue, { passive: true } );
	} );

	window.setTimeout( restitue, ( CFG.timeout || 6 ) * 1000 );
} )();
