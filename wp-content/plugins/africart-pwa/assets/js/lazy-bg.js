/* AFRICART PWA — restitution des fonds différés.
 *
 * Le réécrivain côté serveur sort les déclarations `background-image`
 * des styles en ligne et les range dans `data-afk-bg` : le navigateur
 * ne sait pas différer un fond CSS, il télécharge tout, y compris ce
 * que personne ne verra sans défiler.
 *
 * Ce script rend chaque fond à l'approche du défilement — marge de
 * 400 px, pour que l'image soit là avant d'entrer à l'écran. Les
 * carrousels en profitent aussi : une diapositive masquée par son
 * conteneur n'« intersecte » pas, son image n'est chargée qu'à
 * l'affichage.
 */
( function () {
	'use strict';

	function applique( el ) {
		var declarations = el.getAttribute( 'data-afk-bg' );

		if ( ! declarations ) {
			return;
		}

		el.removeAttribute( 'data-afk-bg' );
		// Les déclarations reprennent leur place, dans leur ordre d'origine
		// (repli d'abord, image-set ensuite) : le navigateur choisit comme
		// si elles n'étaient jamais parties.
		el.style.cssText += ';' + declarations;
	}

	function init() {
		var elements = document.querySelectorAll( '[data-afk-bg]' );

		if ( ! elements.length ) {
			return;
		}

		// Navigateur sans IntersectionObserver : tout restituer d'emblée.
		// Une image chargée trop tôt vaut mieux qu'une image jamais chargée.
		if ( ! ( 'IntersectionObserver' in window ) ) {
			for ( var i = 0; i < elements.length; i++ ) {
				applique( elements[ i ] );
			}
			return;
		}

		var observer = new IntersectionObserver(
			function ( entrees ) {
				for ( var i = 0; i < entrees.length; i++ ) {
					if ( entrees[ i ].isIntersecting ) {
						applique( entrees[ i ].target );
						observer.unobserve( entrees[ i ].target );
					}
				}
			},
			{ rootMargin: '400px' }
		);

		for ( var j = 0; j < elements.length; j++ ) {
			observer.observe( elements[ j ] );
		}

		// Filet de sécurité : au bout de dix secondes, tout fond encore en
		// attente est rendu quoi qu'il arrive. Un conteneur masqué par un
		// carrousel, une mise en page qui ne défile pas, un observateur
		// perturbé — aucune de ces situations ne doit laisser une image
		// invisible pour toujours.
		window.setTimeout( function () {
			var restants = document.querySelectorAll( '[data-afk-bg]' );
			for ( var k = 0; k < restants.length; k++ ) {
				applique( restants[ k ] );
			}
		}, 10000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
