/**
 * L'en-tête : le panneau du téléphone, le menu de profil, les sous-menus.
 *
 * ⚠️ Tout s'ouvre au CLIC, jamais au survol.
 *
 * Le menu du thème ouvrait ses sous-menus au survol. Sur un écran
 * tactile il n'y a pas de survol : le premier appui ouvrait le sous-menu
 * et le second, en dessous, tombait à côté. Les trois pages « À propos »
 * étaient inatteignables au doigt, et le menu de profil ne s'affichait
 * pas du tout — c'est ce qui a été signalé au test.
 *
 * ⚠️ Aucune dépendance au reste du module. Cet en-tête se pose sur
 * n'importe quelle page du site, y compris celles où la base locale et
 * la file d'envoi ne sont pas chargées.
 *
 * @package Africart_PWA
 */

( function () {
	'use strict';

	/**
	 * ⚠️ Chaque en-tete, et pas le premier.
	 *
	 * Le client en a pose deux sur la meme page : l'une masquee sur
	 * ordinateur par Elementor, l'autre sur telephone. Ce script prenait
	 * `querySelector` — le premier du document — et branchait donc celui
	 * qui ne s'affichait pas. Sur ordinateur, « A propos » n'ouvrait
	 * rien, le bouton du menu ne faisait rien, le menu de profil non
	 * plus : tout etait bien la, et rien n'ecoutait. Signale au test.
	 *
	 * Poser un garde contre les instances multiples aurait ete plus
	 * simple, et faux : deux en-tetes sur une page est un choix de mise
	 * en page legitime, pas une erreur a punir.
	 */
	Array.prototype.forEach.call( document.querySelectorAll( '[data-afk-entete]' ), brancher );

	function brancher( entete ) {

		/* ------------------------------------------------------------------
		 * Ce qui rogne, et qu'il faut desserrer
		 * ---------------------------------------------------------------- */

		/**
		 * ⚠️ Un ancêtre en `overflow: hidden` rend le sous-menu invisible.
		 *
		 * « À propos » ouvre une liste posée en `position: absolute` sous
		 * l'entrée. Si le conteneur qui porte le code court rogne ce qui
		 * dépasse — la règle la plus répandue des constructeurs de pages,
		 * posée contre un débordement horizontal — la liste s'ouvre bel et
		 * bien mais reste coupée hors du cadre. Rien ne le signale : le
		 * bouton a l'air mort.
		 *
		 * On lève la contrainte sur la seule chaîne qui mène à l'en-tête, et
		 * seulement pour `hidden` : `auto` et `scroll` désignent une zone
		 * défilante voulue, à laquelle on ne touche pas. Même remède, même
		 * précaution que pour la barre d'étapes du formulaire.
		 */
		( function desserrer() {
			var noeud = entete.parentElement;

			while ( noeud && noeud !== document.body && noeud !== document.documentElement ) {
				var style = window.getComputedStyle( noeud );

				if ( 'hidden' === style.overflowX || 'hidden' === style.overflowY ) {
					noeud.style.overflow = 'visible';
				}

				noeud = noeud.parentElement;
			}
		}() );

		var burger  = entete.querySelector( '[data-afk-burger]' );
		var panneau = entete.querySelector( '[data-afk-panneau]' );
		var voile   = entete.querySelector( '[data-afk-voile]' );

		/* ------------------------------------------------------------------
		 * Le panneau du téléphone
		 * ---------------------------------------------------------------- */

		function ouvrirLePanneau( ouvert ) {
			if ( ! burger || ! panneau ) {
				return;
			}

			panneau.hidden = ! ouvert;
			burger.setAttribute( 'aria-expanded', ouvert ? 'true' : 'false' );
			entete.classList.toggle( 'afk-entete-ouvert', ouvert );

			if ( voile ) {
				voile.hidden = ! ouvert;
			}

			// ⚠️ La page ne défile plus derrière le panneau. Sans cela, un
			// geste vers le bas dans le menu faisait glisser la page en
			// dessous, et l'on perdait sa place en refermant.
			document.documentElement.classList.toggle( 'afk-sans-defilement', ouvert );

			if ( ouvert ) {
				var premier = panneau.querySelector( 'a, button' );

				if ( premier ) {
					premier.focus();
				}
			}
		}

		if ( burger ) {
			burger.addEventListener( 'click', function () {
				ouvrirLePanneau( panneau.hidden );
			} );
		}

		if ( voile ) {
			voile.addEventListener( 'click', function () {
				ouvrirLePanneau( false );
				fermerLeCompte();
			} );
		}

		/* ------------------------------------------------------------------
		 * Le menu de profil
		 * ---------------------------------------------------------------- */

		var compte  = entete.querySelector( '[data-afk-compte]' );
		var bouton  = entete.querySelector( '[data-afk-compte-bouton]' );
		var tiroir  = entete.querySelector( '[data-afk-compte-tiroir]' );

		function fermerLeCompte() {
			if ( ! bouton || ! tiroir ) {
				return;
			}

			tiroir.hidden = true;
			bouton.setAttribute( 'aria-expanded', 'false' );
			entete.classList.remove( 'afk-compte-ouvert' );
			document.documentElement.classList.remove( 'afk-sans-defilement' );
		}

		if ( bouton && tiroir ) {
			bouton.addEventListener( 'click', function ( e ) {
				e.stopPropagation();

				var ouvrir = tiroir.hidden;

				// Un seul panneau à la fois : deux surfaces ouvertes l'une
				// sur l'autre ne se referment plus d'un geste sûr.
				ouvrirLePanneau( false );

				tiroir.hidden = ! ouvrir;
				bouton.setAttribute( 'aria-expanded', ouvrir ? 'true' : 'false' );
				entete.classList.toggle( 'afk-compte-ouvert', ouvrir );

				// ⚠️ Sur téléphone le tiroir monte du bas de l'écran : c'est
				// là que le pouce arrive, et une liste ancrée en haut à droite
				// s'atteint mal d'une seule main. Il se comporte alors comme
				// le panneau, voile compris.
				// ⚠️ Le meme palier que la feuille de style, 1200 : deux
				// valeurs qui divergent donneraient un tiroir ancre en bas
				// sans le voile qui va avec, ou l'inverse.
				if ( voile && window.matchMedia( '(max-width: 1199px)' ).matches ) {
					voile.hidden = ! ouvrir;
					document.documentElement.classList.toggle( 'afk-sans-defilement', ouvrir );
				}
			} );

			document.addEventListener( 'click', function ( e ) {
				if ( ! tiroir.hidden && ! compte.contains( e.target ) ) {
					fermerLeCompte();

					if ( voile ) {
						voile.hidden = panneau ? panneau.hidden : true;
					}
				}
			} );
		}

		/* ------------------------------------------------------------------
		 * La cloche des notifications
		 * ---------------------------------------------------------------- */

		/**
		 * ⚠️ Le contenu vient de Voxel, pas de nous.
		 *
		 * `?vx=1&action=notifications.list` est son propre point d'entree :
		 * il decide de ce qui est une notification, de sa date, de son
		 * image et de son lien. Reproduire cette logique nous aurait donne
		 * deux comptes d'un meme courrier, qui divergent au premier ecart.
		 *
		 * Ce qu'on refait, c'est la surface — la sienne est un composant
		 * Vue pilote par les reglages d'un widget Elementor, inatteignable
		 * depuis un code court sans embarquer la moitie du theme.
		 */
		var cloche  = entete.querySelector( '[data-afk-cloche]' );
		var sonne   = entete.querySelector( '[data-afk-cloche-bouton]' );
		var tirCl   = entete.querySelector( '[data-afk-cloche-tiroir]' );
		var listeCl = entete.querySelector( '[data-afk-cloche-liste]' );
		var chargee = false;

		function fermerLaCloche() {
			if ( ! sonne || ! tirCl ) {
				return;
			}

			tirCl.hidden = true;
			sonne.setAttribute( 'aria-expanded', 'false' );
			entete.classList.remove( 'afk-cloche-ouverte' );
		}

		function messageCloche( texte ) {
			listeCl.textContent = '';

			var p = document.createElement( 'p' );
			p.className = 'afk-cloche-attente';
			p.textContent = texte;
			listeCl.appendChild( p );
		}

		async function chargerLesNotifications() {
			if ( ! listeCl ) {
				return;
			}

			messageCloche( 'Chargement…' );

			try {
				var r = await window.fetch( listeCl.getAttribute( 'data-afk-cloche-source' ), {
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' }
				} );

				var d = await r.json();

				if ( ! d || ! d.success || ! d.list ) {
					throw new Error( 'refus' );
				}

				dessineLesNotifications( d.list );
				chargee = true;

				// Voxel remet son compteur a zero des qu'on lit la
				// premiere page : la pastille n'a plus lieu d'etre.
				var pastille = sonne.querySelector( '[data-afk-cloche-pastille]' );

				if ( pastille ) {
					pastille.remove();
				}
			} catch ( e ) {
				// ⚠️ On dit que la lecture a echoue, jamais qu'il n'y a
				// rien : annoncer une boite vide a qui a des messages est
				// pire que d'admettre qu'on n'a pas pu regarder.
				messageCloche( 'Impossible de lire vos notifications pour le moment.' );
			}
		}

		function dessineLesNotifications( liste ) {
			listeCl.textContent = '';

			if ( ! liste.length ) {
				messageCloche( 'Aucune notification.' );

				return;
			}

			liste.forEach( function ( n ) {
				var ligne = document.createElement( n.links_to ? 'a' : 'div' );
				ligne.className = 'afk-notif' + ( n.seen ? '' : ' afk-notif-neuve' );

				if ( n.links_to ) {
					ligne.href = n.links_to;
				}

				var vignette = document.createElement( 'span' );
				vignette.className = 'afk-notif-image';

				if ( n.image_url ) {
					var img = document.createElement( 'img' );
					img.src = n.image_url;
					img.alt = '';
					img.loading = 'lazy';
					vignette.appendChild( img );
				}

				var texte = document.createElement( 'span' );
				texte.className = 'afk-notif-texte';

				var sujet = document.createElement( 'span' );
				sujet.className = 'afk-notif-sujet';

				// ⚠️ `textContent` et non `innerHTML` : ce sujet vient
				// d'un evenement du site, il peut porter le titre d'une
				// oeuvre saisie par n'importe qui.
				sujet.textContent = String( n.subject || '' );

				var quand = document.createElement( 'span' );
				quand.className = 'afk-notif-date';
				quand.textContent = String( n.time || '' );

				texte.appendChild( sujet );
				texte.appendChild( quand );

				ligne.appendChild( vignette );
				ligne.appendChild( texte );
				listeCl.appendChild( ligne );
			} );
		}

		if ( sonne && tirCl ) {
			sonne.addEventListener( 'click', function ( e ) {
				e.stopPropagation();

				var ouvrir = tirCl.hidden;

				// Un seul panneau a la fois.
				ouvrirLePanneau( false );
				fermerLeCompte();

				tirCl.hidden = ! ouvrir;
				sonne.setAttribute( 'aria-expanded', ouvrir ? 'true' : 'false' );
				entete.classList.toggle( 'afk-cloche-ouverte', ouvrir );

				if ( ouvrir && ! chargee ) {
					chargerLesNotifications();
				}
			} );

			document.addEventListener( 'click', function ( e ) {
				if ( ! tirCl.hidden && ! cloche.contains( e.target ) ) {
					fermerLaCloche();
				}
			} );

			var vider = entete.querySelector( '[data-afk-cloche-vider]' );

			if ( vider ) {
				vider.addEventListener( 'click', async function ( e ) {
					e.stopPropagation();

					// ⚠️ Une question avant d'effacer : ce geste est
					// definitif et sa cible est un bouton de dix-sept
					// pixels, voisin de la liste qu'il detruit.
					var oui = window.afkDemander
						? await window.afkDemander(
							'Effacer toutes vos notifications ?',
							'Elles ne pourront pas etre retrouvees.',
							'Tout effacer' )
						: window.confirm( 'Effacer toutes vos notifications ?' );

					if ( ! oui ) {
						return;
					}

					try {
						await window.fetch( listeCl.getAttribute( 'data-afk-cloche-purge' ), {
							credentials: 'same-origin',
							headers: { 'X-Requested-With': 'XMLHttpRequest' }
						} );

						messageCloche( 'Aucune notification.' );
					} catch ( x ) {
						messageCloche( 'L’effacement n’a pas abouti.' );
					}
				} );
			}
		}

		/* ------------------------------------------------------------------
		 * Les sous-menus
		 * ---------------------------------------------------------------- */

		Array.prototype.forEach.call( entete.querySelectorAll( '.afk-entree-ouvre' ), function ( ouvre ) {
			ouvre.addEventListener( 'click', function ( e ) {
				e.stopPropagation();

				var ouvert = 'true' === ouvre.getAttribute( 'aria-expanded' );

				// Les autres se referment : deux sous-menus ouverts côte à
				// côte se chevauchent et l'on ne sait plus lequel on lit.
				Array.prototype.forEach.call( entete.querySelectorAll( '.afk-entree-ouvre' ), function ( autre ) {
					autre.setAttribute( 'aria-expanded', 'false' );
					autre.parentNode.classList.remove( 'afk-entree-ouverte' );
				} );

				if ( ! ouvert ) {
					ouvre.setAttribute( 'aria-expanded', 'true' );
					ouvre.parentNode.classList.add( 'afk-entree-ouverte' );
				}
			} );
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( entete.contains( e.target ) ) {
				return;
			}

			Array.prototype.forEach.call( entete.querySelectorAll( '.afk-entree-ouvre' ), function ( ouvre ) {
				ouvre.setAttribute( 'aria-expanded', 'false' );
				ouvre.parentNode.classList.remove( 'afk-entree-ouverte' );
			} );
		} );

		/* ------------------------------------------------------------------
		 * Sorties
		 * ---------------------------------------------------------------- */

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' !== e.key ) {
				return;
			}

			if ( panneau && ! panneau.hidden ) {
				ouvrirLePanneau( false );

				if ( burger ) {
					burger.focus();
				}
			}

			if ( tirCl && ! tirCl.hidden ) {
				fermerLaCloche();

				if ( sonne ) {
					sonne.focus();
				}
			}

			if ( tiroir && ! tiroir.hidden ) {
				fermerLeCompte();

				if ( voile ) {
					voile.hidden = panneau ? panneau.hidden : true;
				}

				if ( bouton ) {
					bouton.focus();
				}
			}
		} );

		// ⚠️ Le passage au grand écran referme tout. Un panneau ouvert sur
		// téléphone puis élargi — rotation, fenêtre redimensionnée — restait
		// affiché par-dessus la page, sans plus rien pour le fermer.
		var large = window.matchMedia( '(min-width: 1200px)' );

		function auChangement() {
			if ( large.matches ) {
				ouvrirLePanneau( false );
				fermerLeCompte();
				fermerLaCloche();
			}
		}

		if ( large.addEventListener ) {
			large.addEventListener( 'change', auChangement );
		} else if ( large.addListener ) {
			large.addListener( auChangement );
		}

		/* ------------------------------------------------------------------
		 * Ce qui attend d'être envoyé
		 * ---------------------------------------------------------------- */

		/**
		 * Une pastille sur le compte quand des œuvres attendent.
		 *
		 * ⚠️ Elle ne paraît que si la file est chargée sur cette page. Cet
		 * en-tête vit sur tout le site, y compris là où le module des œuvres
		 * n'est pas monté : compter soi-même aurait demandé d'ouvrir la base
		 * locale depuis un second endroit, et il n'y a qu'un propriétaire.
		 */
		document.addEventListener( 'afkFile', function ( e ) {
			if ( ! bouton ) {
				return;
			}

			var f = ( e.detail && e.detail.file ) || {};
			// Les prêtes comprennent celles dont un envoi a échoué : elles
			// attendent le même geste, et ne se comptent donc qu'une fois.
			var n = ( f.prets || 0 ) + ( f.incomplets || 0 ) + ( f.sansPhoto || 0 );

			var pastille = bouton.querySelector( '.afk-compte-pastille' );

			if ( ! n ) {
				if ( pastille ) {
					pastille.remove();
				}

				return;
			}

			if ( ! pastille ) {
				pastille = document.createElement( 'span' );
				pastille.className = 'afk-compte-pastille';
				bouton.appendChild( pastille );
			}

			pastille.textContent = n > 9 ? '9+' : String( n );
			pastille.setAttribute( 'aria-label', n + ' œuvre(s) à envoyer' );
		} );
	}
}() );
