/* AFRICART PWA — Service Worker (Workbox)
 * Le préambule `self.__AFK_CONFIG = {...}` est injecté par PHP AVANT ce fichier
 * (voir class-service-worker.php). Ne pas dupliquer la config ici.
 *
 * Trois rôles :
 *   - restituer les pages déjà visitées et la page hors connexion ;
 *   - mettre en cache images, scripts et styles selon leur nature ;
 *   - recevoir les notifications, en important le SDK de OneSignal —
 *     un navigateur n'acceptant qu'un seul service worker par portée.
 */

const CFG = self.__AFK_CONFIG || {};
const VERSION = CFG.version || '0.0.0';

/* ⚠️ Des noms de cache stables, et non versionnés.
 *
 * Ils portaient la date du fichier de ce worker : chaque mise en ligne
 * ouvrait donc trois caches neufs et VIDES, en laissant les précédents
 * sur l'appareil pour toujours. Deux conséquences, l'une et l'autre
 * constatées à l'usage :
 *
 *   — l'artiste qui passait hors connexion juste après une mise en ligne
 *     n'avait plus rien en réserve : ni styles, ni scripts, ni pages ;
 *   — la réserve grossissait d'un jeu complet par mise en ligne, jusqu'à
 *     ce que le navigateur reprenne la place. Safari la reprend PAR
 *     ORIGINE, d'un bloc : le dépôt en cours partait avec le reste.
 *
 * La fraîcheur ne se joue plus sur le nom du cache mais sur la façon d'y
 * lire — le réseau garde la main partout où il est là.
 */
const CACHES = {
	statique: 'africart-static',
	images:   'africart-images',
	pages:    'africart-pages',
};
const OFFLINE_URL = CFG.offlineUrl || '/?afk_pwa=offline';

// --- Notifications push ---
// Importé AVANT tout le reste : un navigateur n'accepte qu'un seul
// service worker par portée. Plutôt que de laisser OneSignal enregistrer
// le sien — ce qui remplacerait celui-ci et casserait l'installation de
// l'application ainsi que le mode hors connexion — c'est nous qui
// chargeons son SDK dans notre propre worker.
if ( CFG.onesignal ) {
	importScripts( 'https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js' );
}

// --- Workbox (CDN Google ; à localiser dans assets/sw/ pour du 100% offline) ---
importScripts( 'https://storage.googleapis.com/workbox-cdn/releases/7.0.0/workbox-sw.js' );

if ( workbox ) {
	workbox.setConfig( { debug: false } );
	workbox.core.setCacheNameDetails( { prefix: 'africart', suffix: VERSION } );

	const { registerRoute, setCatchHandler } = workbox.routing;
	const { CacheFirst, StaleWhileRevalidate, NetworkFirst } = workbox.strategies;
	const { ExpirationPlugin } = workbox.expiration;
	const { CacheableResponsePlugin } = workbox.cacheableResponse;
	const { precacheAndRoute } = workbox.precaching;

	/* -------- App shell : pré-cache de la page offline + logo -------- */
	precacheAndRoute(
		( CFG.precache || [] ).map( function ( url ) {
			return { url: url, revision: VERSION };
		} )
	);

	/* -------- L'ADMINISTRATION N'EST PAS NOTRE AFFAIRE --------
	 *
	 * ⚠️ Ce worker interceptait TOUT ce qui venait du site, y compris
	 * wp-admin. Mesuré sur le site de recette : 21 ressources
	 * d'administration sur 21 servies depuis notre cache — feuilles de
	 * style de l'écran de connexion, jQuery, les modules de WordPress.
	 *
	 * Les consequences, constatees par le developpeur du site :
	 *
	 *   — une administration SANS AUCUN STYLE, parce qu'une feuille servie
	 *     depuis le cache ne correspondait plus a la version attendue ;
	 *   — l'editeur Elementor bloque sur « chargement », son amorce et ses
	 *     modules ne venant plus de la meme version ;
	 *   — des ecrans de reglages inaccessibles.
	 *
	 * Une application hors connexion n'a rien a faire dans le
	 * back-office : personne n'administre un site sans reseau. Le cache
	 * n'y apportait aucun benefice, et lui faisait tout ce mal.
	 *
	 * ⚠️ Le REFERENT autant que l'adresse. Une page d'administration
	 * charge des fichiers de `/wp-includes/`, qui servent AUSSI au site
	 * public : les exclure par leur adresse aurait prive le site de sa
	 * reserve. On regarde donc d'ou vient la demande.
	 */
	function estAdministration( url, request ) {
		const chemin = url.pathname;

		if ( 0 === chemin.indexOf( '/wp-admin/' ) || '/wp-login.php' === chemin ) {
			return true;
		}

		const venant = ( request && request.referrer ) || '';

		return -1 !== venant.indexOf( '/wp-admin/' ) || -1 !== venant.indexOf( '/wp-login.php' );
	}

	/* -------- Stratégies de cache, par nature de ressource -------- */

	// Images (œuvres, vignettes) — CacheFirst, 60 entrées, 30 jours.
	// Same-origin uniquement : les ressources externes (Google Translate,
	// reCAPTCHA, fonts CDN…) passent en réseau direct, sans gonfler le cache.
	registerRoute(
		function ( { request, url } ) {
			return request.destination === 'image'
				&& url.origin === self.location.origin
				&& ! estAdministration( url, request );
		},
		new CacheFirst( {
			cacheName: CACHES.images,
			plugins: [
				new CacheableResponsePlugin( { statuses: [ 0, 200 ] } ),
				new ExpirationPlugin( { maxEntries: 60, maxAgeSeconds: 30 * 24 * 60 * 60, purgeOnQuotaError: true } ),
			],
		} )
	);

	/* ⚠️ Une seule copie par fichier, quelle que soit sa version.
	 *
	 * Nos scripts et nos styles portent la date du fichier dans leur
	 * adresse — `?ver=…` — pour casser le cache du navigateur à chaque
	 * mise en ligne. Le cache du service worker les prenait au mot : il
	 * gardait une entrée de plus par version, et chaque page réclamait
	 * celle qui était inscrite dans SA propre copie mise en cache.
	 *
	 * Hors connexion, deux pages du même site affichaient donc deux
	 * versions différentes de l'application : le même message y prenait
	 * deux mises en page, et un correctif présent sur l'une manquait sur
	 * l'autre. Rien ne le signalait — l'artiste voyait simplement une
	 * application incohérente d'une page à l'autre. Signalé au test.
	 *
	 * La version reste dans la requête réseau, c'est elle qui casse le
	 * cache du navigateur. Seul le RANGEMENT ignore la version : la
	 * dernière copie téléchargée sert alors toutes les pages, y compris
	 * celles mises en cache il y a longtemps.
	 */
	const sansVersion = {
		cacheKeyWillBeUsed: async function ( { request } ) {
			const propre = new URL( request.url );
			propre.search = '';

			return propre.href;
		},
	};

	// CSS/JS/fonts — StaleWhileRevalidate (rapide + rafraîchi en arrière-plan).
	registerRoute(
		function ( { request, url } ) {
			return [ 'style', 'script', 'font' ].indexOf( request.destination ) !== -1
				&& url.origin === self.location.origin
				&& ! estAdministration( url, request );
		},
		new StaleWhileRevalidate( {
			cacheName: CACHES.statique,
			plugins: [ sansVersion ],
		} )
	);

	// Pages (navigation) — NetworkFirst pour du contenu frais, cache de secours.
	registerRoute(
		function ( { request, url } ) {
			return request.mode === 'navigate' && ! estAdministration( url, request );
		},
		new NetworkFirst( {
			cacheName: CACHES.pages,
			networkTimeoutSeconds: 3,
			plugins: [ new ExpirationPlugin( { maxEntries: 40, maxAgeSeconds: 7 * 24 * 60 * 60 } ) ],
		} )
	);

	/* -------- Fallback global : page offline AFRICART -------- */
	setCatchHandler( async ( { request, url } ) => {
		// Rendre la page hors connexion d'AFRICART a la place d'un ecran
		// d'administration serait pire que l'erreur du navigateur : on
		// croirait le site casse.
		if ( url && estAdministration( url, request ) ) {
			return Response.error();
		}

		if ( request.destination === 'document' || request.mode === 'navigate' ) {
			const cached = await caches.match( OFFLINE_URL );
			if ( cached ) { return cached; }
		}
		return Response.error();
	} );

	/* -------- Rechauffage du cache de navigation --------
	 *
	 * ⚠️ Ces pages ne sont PAS mises dans `precache`, et la nuance est
	 * decisive : `precacheAndRoute` sert la copie meme en ligne. La liste
	 * des oeuvres serait alors figee a son etat d'installation, et
	 * l'artiste ne verrait plus jamais ses nouveaux depots.
	 *
	 * On alimente donc le cache que la navigation utilise deja, en
	 * NetworkFirst : en ligne le reseau garde la main, hors connexion la
	 * page existe.
	 *
	 * La demande vient de la page, pas de l'installation du service
	 * worker : la page sait que l'artiste est connecte. Rechauffer a
	 * l'installation risquerait de mettre en cache une page d'invitation
	 * a se connecter, qui s'afficherait ensuite hors connexion. */
	self.addEventListener( 'message', function ( e ) {
		var message = e.data || {};

		if ( 'afk-rechauffe' !== message.type || ! Array.isArray( message.urls ) ) {
			return;
		}

		e.waitUntil( ( async function () {
			var cache = await caches.open( CACHES.pages );

			for ( var i = 0; i < message.urls.length; i++ ) {
				var url = message.urls[ i ];

				try {
					// Deja en cache : ne pas retelecharger a chaque page.
					if ( await cache.match( url ) ) {
						continue;
					}

					var reponse = await fetch( url, { credentials: 'same-origin' } );

					if ( reponse && reponse.ok ) {
						await cache.put( url, reponse.clone() );
					}
				} catch ( err ) {
					// Une page qui ne se charge pas n'empeche pas les autres.
					continue;
				}
			}
		} )() );
	} );

	/* -------- Oubli, a la deconnexion --------
	 *
	 * ⚠️ Les pages rechauffees ont ete recuperees pour un artiste
	 * connecte : elles contiennent SES oeuvres. Les laisser apres sa
	 * deconnexion les rendrait consultables hors connexion par la
	 * personne suivante sur le meme appareil. */
	self.addEventListener( 'message', function ( e ) {
		var message = e.data || {};

		if ( 'afk-oublie' !== message.type || ! Array.isArray( message.urls ) ) {
			return;
		}

		e.waitUntil( ( async function () {
			var cache = await caches.open( CACHES.pages );

			for ( var i = 0; i < message.urls.length; i++ ) {
				try {
					await cache.delete( message.urls[ i ] );
				} catch ( err ) {
					continue;
				}
			}
		} )() );
	} );

	self.addEventListener( 'install', function () { self.skipWaiting(); } );
	/* Les caches des versions precedentes, laisses sur l'appareil par la
	 * regle d'avant. Un jeu complet par mise en ligne, que plus rien ne
	 * lit : c'est de la place prise a ce qui sert. */
	async function menage() {
		const gardes = [ CACHES.statique, CACHES.images, CACHES.pages ];
		const noms   = await caches.keys();

		await Promise.all( noms.map( function ( nom ) {
			if ( 0 !== nom.indexOf( 'africart-' ) || gardes.indexOf( nom ) !== -1 ) {
				return null;
			}

			// Les caches de Workbox portent son propre prefixe et sa propre
			// gestion : on ne touche qu'aux notres.
			if ( nom.indexOf( 'africart-precache' ) === 0 ) {
				return null;
			}

			return caches.delete( nom );
		} ) );
	}

	/* Ce qui a deja ete mis en cache pour l'administration.
	 *
	 * ⚠️ Le garde-fou ci-dessus empeche d'en ajouter, il ne retire pas ce
	 * qui s'y trouve deja. Sans ce menage, l'administration resterait
	 * cassee sur les appareils ou le mal est fait — et il faudrait
	 * demander a chacun de vider son cache a la main, ce que personne ne
	 * sait faire. */
	async function oublierLAdministration() {
		for ( const nom of [ CACHES.statique, CACHES.images, CACHES.pages ] ) {
			try {
				const cache = await caches.open( nom );
				const clefs = await cache.keys();

				for ( let i = 0; i < clefs.length; i++ ) {
					const chemin = new URL( clefs[ i ].url ).pathname;

					if ( 0 === chemin.indexOf( '/wp-admin/' ) || '/wp-login.php' === chemin ) {
						await cache.delete( clefs[ i ] );
					}
				}
			} catch ( err ) {
				// Un cache illisible n'empeche pas de nettoyer les autres.
				continue;
			}
		}
	}

	/* Nos scripts et nos styles, ranges des l'activation plutot qu'au
	 * hasard des pages visitees. Rangés sous l'adresse SANS version, la
	 * meme que celle ou la strategie ira les lire. */
	async function garnir() {
		const cache = await caches.open( CACHES.statique );

		await Promise.all( ( CFG.assets || [] ).map( async function ( adresse ) {
			try {
				const propre = new URL( adresse, self.location.origin );
				propre.search = '';

				const reponse = await fetch( adresse, { cache: 'reload', credentials: 'same-origin' } );

				if ( reponse && reponse.ok ) {
					await cache.put( propre.href, reponse );
				}
			} catch ( err ) {
				// Un fichier qui ne vient pas n'empeche pas les autres.
			}
		} ) );
	}

	self.addEventListener( 'activate', function ( e ) {
		e.waitUntil( ( async function () {
			await self.clients.claim();

			try {
				await menage();
				await oublierLAdministration();
				await garnir();
			} catch ( err ) {
				// Ni l'un ni l'autre ne doit empecher l'activation.
			}
		} )() );
	} );
} else {
	// Workbox indisponible (hors-ligne au 1er chargement) : SW minimal inerte.
	self.addEventListener( 'fetch', function () {} );
}
