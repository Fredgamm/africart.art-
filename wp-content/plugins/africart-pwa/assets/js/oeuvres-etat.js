/**
 * L'état du dépôt, dit à l'écran.
 *
 * ⚠️ Ce fichier existe parce que le module savait beaucoup de choses
 * qu'il ne disait pas. La file connaissait ses conflits, la session
 * savait qu'elle avait expiré, le stockage savait qu'il refusait
 * d'écrire — et l'artiste, lui, voyait un formulaire ordinaire. Il
 * découvrait le problème des heures plus tard, ou jamais.
 *
 * Un seul bandeau, toujours visible, qui répond à une seule question :
 * **est-ce que ce que je viens de faire est en sécurité ?**
 *
 * ⚠️ « En ligne » n'est pas une réponse à cette question, et ce module a
 * commencé par l'afficher en vert comme s'il annonçait une bonne
 * nouvelle. Personne n'ouvre un formulaire pour savoir s'il a du réseau.
 * Ce qui intéresse l'artiste, c'est **si sa saisie est enregistrée**, et
 * qu'on le prévienne **quand elle ne peut plus partir**. Le repos du
 * bandeau est donc muet : une ligne grise avec l'heure du dernier
 * enregistrement. Il ne s'allume que pour signaler.
 *
 * ⚠️ Les cas se recouvrent — on peut être hors connexion, avec une
 * session expirée, deux conflits et trois photos en attente. Les
 * afficher tous ferait un mur illisible. L'ordre de `SITUATIONS` est
 * donc un ordre de **gravité**, et la première qui s'applique gagne :
 * c'est celle qui doit être traitée en premier, et souvent celle qui
 * explique les autres.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var DB = window.afkOeuvresDB;

	// Sans file qui tourne, l'état n'aurait rien à dire — et « En ligne,
	// tout est envoyé » dans un éditeur ne renseigne personne.
	if ( ! DB || ( window.AFK_PWA_FORM || window.AFK_PWA_OEUVRES || {} ).apercu ) {
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
		// ⚠️ Configuration lue à l'appel, comme partout dans ce fichier.
		// Il n'y en a pas de copie en tête : ce module se charge avant
		// les écrans, et une copie prise au démarrage serait vide.
		var cfg = window.AFK_PWA_FORM || window.AFK_PWA_OEUVRES || {};
		var t   = ( cfg.textes || {} )[ 'txt_' + cle ];

		return t || defaut;
	}


	/**
	 * Ce qu'on sait à l'instant.
	 *
	 * `stockage` vaut `null` tant qu'on n'a pas tranché : afficher
	 * « cet appareil ne conserve rien » pendant la vérification ferait
	 * clignoter l'avertissement le plus grave au chargement de chaque
	 * page.
	 */
	var vu = {
		enLigne: window.navigator.onLine,
		session: { valide: true, expire: '', joignable: true },
		stockage: null,
		file: { total: 0, par: { PENDING: 0, SYNCING: 0, CONFLICT: 0, ERROR: 0 }, message: '', raison: '' },
		photos: 0,
		enregistre: 0,

		/**
		 * Où en est la saisie en cours, quand il y en a une.
		 *
		 * `null` en dehors du formulaire : sur la liste des œuvres, il n'y
		 * a pas de saisie, et afficher « Brouillon » y serait faux.
		 */
		saisie: null
	};

	/**
	 * Le chemin vers « Vos œuvres », quand il mène quelque part.
	 *
	 * ⚠️ Trois précautions, chacune pour une raison.
	 *
	 * Rien si l'on y est déjà : un bouton qui recharge la page qu'on
	 * regarde ressemble à une panne.
	 *
	 * Rien si l'adresse est inconnue : mieux vaut pas de bouton qu'un
	 * bouton qui ne mène nulle part.
	 *
	 * Et dans un autre onglet lorsqu'une saisie est en cours. Le
	 * brouillon est conservé, mais l'artiste ne le sait pas : voir son
	 * formulaire disparaître au milieu d'un dépôt suffit à lui faire
	 * croire qu'il a tout perdu.
	 */
	function versLaListe() {
		// ⚠️ Lue à l'appel, comme partout ici : ce module se charge avant
		// les écrans, et une copie prise au démarrage serait vide.
		var cfg = window.AFK_PWA_FORM || window.AFK_PWA_OEUVRES || {};
		var ou  = String( cfg.liste || '' ).trim();

		if ( ! ou ) {
			return null;
		}

		try {
			var vise = new URL( ou, window.location.href );

			if ( vise.pathname.replace( /\/+$/, '' ) === window.location.pathname.replace( /\/+$/, '' ) ) {
				return null;
			}
		} catch ( e ) { /* adresse relative exotique : on la garde */ }

		var enSaisie = !! vu.saisie && 'depose' !== vu.saisie.phase;

		return {
			texte: mot( 'voir_a_envoyer', 'Voir les œuvres à envoyer' ),
			url: ou,
			ailleurs: enSaisie
		};
	}

	function pluriel( n, singulier, plusieurs ) {
		return n + ' ' + ( n > 1 ? plusieurs : singulier );
	}

	/**
	 * Les situations, de la plus grave à la plus tranquille.
	 *
	 * Chacune rend `null` si elle ne s'applique pas, sinon le bandeau à
	 * afficher : un ton, un titre, un détail, et parfois un geste.
	 */
	var SITUATIONS = [

		/*
		 * ⚠️ En tête, et de loin. Sans stockage, tout le reste est un
		 * mensonge : le formulaire annonce « enregistré », la file dit
		 * « en attente », et il ne restera rien en fermant l'onglet.
		 * Navigation privée, quota atteint, cookies tiers bloqués.
		 */
		function ( e ) {
			if ( false !== e.stockage ) {
				return null;
			}

			return {
				ton: 'grave',
				titre: mot( 'stockage_titre', 'Cet appareil ne conserve pas votre saisie' ),
				detail: mot( 'stockage_detail', 'Navigation privée ou stockage saturé. Terminez votre dépôt sans fermer cette page.' )
			};
		},

		/*
		 * La session tombe pendant la saisie. Rien n'est perdu — encore
		 * faut-il le dire, sans quoi l'artiste voit ses envois échouer
		 * sans comprendre et recommence tout ailleurs.
		 */
		function ( e ) {
			if ( e.session.valide ) {
				return null;
			}

			return {
				ton: 'grave',
				titre: mot( 'session_titre', 'Session expirée' ),
				detail: ( e.file.total
					? pluriel( e.file.total, 'envoi conservé', 'envois conservés' ) + ' — ils partiront seuls après reconnexion.'
					: 'Votre saisie est conservée ici.' )
					+ ' La reconnexion s’ouvre à côté : cette page reste telle quelle.',
				lien: { texte: mot( 'session_lien', 'Se reconnecter' ), url: adresseConnexion(), ailleurs: true }
			};
		},

		/*
		 * ⚠️ Avant la coupure, pas après.
		 *
		 * Remplir trente-six champs prend plus de temps qu'on ne croit, et
		 * une session a une durée fixe que rien ne fait glisser tant qu'on
		 * ne parle pas au serveur. L'artiste pouvait donc saisir une œuvre
		 * entière sur un jeton déjà mort. Le serveur annonce l'échéance :
		 * autant s'en servir pour prévenir tant qu'il est encore temps de
		 * prolonger d'un simple clic.
		 */
		function ( e ) {
			var reste = resteDeSession( e );

			if ( null === reste || reste > PROCHE ) {
				return null;
			}

			return {
				ton: 'attente',
				titre: reste > 60000
					? 'Session bientôt expirée — ' + Math.round( reste / 60000 ) + ' min'
					: 'Session sur le point d’expirer',
				detail: 'Prolongez-la pour continuer sans interruption.',
				geste: { texte: 'Prolonger', faire: prolonger }
			};
		},

		/*
		 * Conflit : l'œuvre a changé sur le site depuis la copie locale.
		 * On ne tranche pas à la place de l'artiste — écraser une version
		 * qu'il n'a pas vue serait pire que d'attendre.
		 */
		function ( e ) {
			var n = e.file.par.CONFLICT || 0;

			if ( ! n ) {
				return null;
			}

			return {
				ton: 'grave',
				titre: pluriel( n, 'modification en conflit', 'modifications en conflit' ),
				detail: mot( 'conflit_detail', 'L’œuvre a changé sur le site depuis votre copie. Rien n’a été écrasé.' ),
				// ⚠️ Un refus qu'on ne peut pas résoudre n'est pas une
				// protection, c'est une impasse : le travail restait bloqué
				// là sans que rien ne dise quoi faire.
				geste: {
					texte: mot( 'conflit_geste', 'Comparer' ),
					faire: function () {
						if ( window.afkConflit ) {
							window.afkConflit.ouvrir();
						}
					}
				}
			};
		},

		/*
		 * Refus définitif du serveur. Son message est repris tel quel :
		 * lui seul sait ce qui manque, et le paraphraser égarerait.
		 */
		function ( e ) {
			var n = e.file.par.ERROR || 0;

			if ( ! n ) {
				return null;
			}

			return {
				ton: 'grave',
				titre: pluriel( n, 'envoi refusé', 'envois refusés' ),
				detail: e.file.message || 'Le site a refusé cet envoi.',
				geste: { texte: mot( 'reessayer', 'Réessayer' ), faire: reEssayer }
			};
		},

		/* En train de partir. */
		function ( e ) {
			if ( ! ( e.file.par.SYNCING || 0 ) ) {
				return null;
			}

			// ⚠️ On demande de rester, et on dit ce qu'il advient sinon.
			//
			// L'envoi se fait depuis cette page : la quitter interrompt la
			// requête en vol. Rien n'est perdu — l'œuvre reste en file et
			// repartira — mais l'artiste qui ferme son onglet en croyant
			// avoir fini attendrait son œuvre en ligne pour rien.
			return {
				ton: 'marche',
				titre: mot( 'envoi_cours', 'Envoi en cours…' ),
				detail: mot( 'envoi_rester', 'Restez sur cette page le temps de l’envoi. Sinon il reprendra plus tard.' ),
				rondelle: true
			};
		},

		/*
		 * Sans réseau. Deux nuances : avec ou sans travail en attente.
		 * Les confondre inquiéterait celui qui n'a rien à envoyer et
		 * rassurerait à tort celui qui a douze œuvres en file.
		 */
		function ( e ) {
			if ( e.enLigne ) {
				return null;
			}

			// ⚠️ Signalé franchement dans les deux cas, et non en gris
			// discret quand la file est vide. C'est **la** chose que
			// l'artiste a demandé qu'on lui dise : le reste de son écran
			// n'a pas changé d'aspect, rien ne lui indiquerait autrement
			// qu'il vient de sortir de couverture.
			if ( ! e.file.total ) {
				return {
					ton: 'attente',
					titre: mot( 'hors_ligne', 'Hors connexion' ),
					detail: mot( 'hors_ligne_seul', 'Votre saisie est conservée sur cet appareil.' ),
					avecEnregistrement: true
				};
			}

			return {
				ton: 'attente',
				titre: mot( 'hors_ligne', 'Hors connexion' ),
				// ⚠️ Plus de « départ automatique au retour du réseau » : il
				// n'y en a plus, et promettre ce qui n'arrivera pas est la
				// pire façon de rassurer.
				//
				// Pas d'heure d'enregistrement non plus : trois membres de
				// phrase sur un téléphone font trois lignes que plus
				// personne ne lit.
				detail: pluriel( e.file.prets || e.file.total, 'envoi en attente', 'envois en attente' )
					+ ' — à envoyer dès que le réseau revient.'
			};
		},

		/*
		 * ⚠️ Du réseau, mais le site ne répond pas. Cas fréquent en
		 * mobilité : annoncer « hors connexion » enverrait l'artiste
		 * vérifier son forfait au lieu de patienter.
		 */
		function ( e ) {
			var muet = false === e.session.joignable;

			if ( ! muet && ( 'reseau' !== e.file.raison || ! e.file.total ) ) {
				return null;
			}

			// ⚠️ Y compris sans rien en file. C'est le cas de l'artiste en
			// pleine saisie : rien n'attend encore d'être envoyé, mais il
			// doit savoir que le site ne répond plus — sinon il finit ses
			// trente-six champs avant de l'apprendre.
			return {
				ton: 'attente',
				titre: mot( 'injoignable', 'Site injoignable' ),
				detail: e.file.total
					? pluriel( e.file.total, 'envoi en attente', 'envois en attente' ) + '. Nouvelle tentative automatique.'
					: mot( 'hors_ligne_seul', 'Votre saisie est conservée sur cet appareil.' ),
				avecEnregistrement: ! e.file.total,
				geste: { texte: mot( 'reessayer', 'Réessayer' ), faire: reEssayer }
			};
		},

		/*
		 * ⚠️ La synthèse, et rien de plus.
		 *
		 * Ce message énumérait les problèmes un par un — « 3 photos
		 * introuvables », « 1 œuvre à compléter » — avec un bouton
		 * « Compléter » qui ne pouvait ouvrir qu'une seule œuvre à la
		 * fois. Un compte global assorti d'un geste individuel : l'artiste
		 * ne savait ni combien d'œuvres étaient concernées, ni laquelle il
		 * allait ouvrir.
		 *
		 * Le détail appartient aux cartes, qui en portent une chacune. Ici,
		 * l'état des données locales et le geste qui les envoie.
		 */
		function ( e ) {
			var aCorriger = ( e.file.sansPhoto || 0 )
				+ ( e.file.incomplets || 0 )
				+ ( e.file.par.CONFLICT || 0 )
				+ ( e.file.par.ERROR || 0 );

			var prets = e.file.prets || 0;

			if ( ! prets && ! aCorriger ) {
				return null;
			}

			var bouts = [];

			if ( prets ) {
				bouts.push( pluriel( prets, 'œuvre à envoyer', 'œuvres à envoyer' ) );
			}

			if ( aCorriger ) {
				bouts.push( pluriel( aCorriger, 'à corriger', 'à corriger' ) );
			}

			return {
				ton: aCorriger && ! prets ? 'grave' : 'attente',
				titre: bouts.join( ' · ' ),
				// ⚠️ Un envoi qui a échoué se dit ici, et nulle part ailleurs.
				//
				// L'œuvre est prête — rien ne la bloque, elle repartira telle
				// quelle — mais taire l'échec laisserait croire qu'elle n'est
				// jamais partie. Le motif vient du serveur : c'est lui qui
				// sait, et le paraphraser ne ferait qu'égarer.
				detail: aCorriger
					? mot( 'synthese_corriger', 'Ouvrez les œuvres signalées dans la liste pour voir ce qui manque.' )
					: ( e.file.aReessayer && e.file.message
						? 'Dernier envoi refusé : ' + e.file.message + ' Vous pouvez réessayer.'
						: mot( 'envoi_geste', 'Rien ne part tout seul : relisez-les et envoyez quand vous êtes prêt.' ) ),

				// ⚠️ Un chemin vers la liste, et il passe en premier.
				//
				// Au test client : « je trouve ça frustrant de n'avoir que
				// le bouton envoyer maintenant. Quitte à ce qu'il n'y ait
				// qu'un seul bouton, je trouverais ça mieux qu'il y ait
				// voir les œuvres à envoyer, pour avoir l'opportunité de
				// modifier ou relire les infos. »
				//
				// Elle a raison : tout envoyer d'un geste, sans avoir rien
				// relu, demande une confiance que l'application n'a pas
				// encore gagnée. Le bouton d'envoi reste — pour qui sait
				// déjà ce qu'il veut — mais il ne décide plus seul.
				lien: versLaListe(),

				// ⚠️ N'apparaît que s'il a quelque chose à faire. Proposer
				// « Envoyer » quand rien n'est prêt, c'est promettre une
				// action qui ne produira rien.
				geste: prets
					? { texte: mot( 'envoyer', 'Envoyer maintenant' ), faire: reEssayer }
					: null,
				indispensable: true
			};
		},

		/*
		 * Des photos gardées ici sans œuvre en file : la saisie est en
		 * cours, elles partiront avec elle. Le dire évite de croire
		 * qu'elles sont déjà sur le site.
		 */
		function ( e ) {
			if ( ! e.photos ) {
				return null;
			}

			return {
				ton: 'attente',
				titre: pluriel( e.photos, 'photo gardée ici', 'photos gardées ici' ),
				detail: mot( 'photos_detail', 'Elles partiront avec l’œuvre.' ),
				photosDites: true
			};
		},

		/*
		 * Rien à signaler. La bande se tait, et ne garde que l'heure du
		 * dernier enregistrement — la seule information qui compte quand
		 * tout va bien.
		 *
		 * Avant le premier enregistrement il n'y a rien à dire non plus :
		 * on rend `null`, et la bande disparaît au lieu d'occuper la
		 * place pour annoncer une absence.
		 */
		function ( e ) {
			if ( ! e.enregistre ) {
				return null;
			}

			return {
				ton: 'muet',
				titre: enregistre( e.enregistre ),
				marque: '✓',
				photosDites: true
			};
		}
	];

	/**
	 * Ce qu'il faut ajouter au détail, quelle que soit la situation.
	 *
	 * ⚠️ Les photos gardées ici ne peuvent pas être une situation parmi
	 * d'autres. Constaté au banc : hors connexion avec un envoi en file,
	 * c'est « hors connexion » qui gagnait, et deux photos en attente
	 * disparaissaient de l'écran — l'artiste les croyait parties.
	 *
	 * Elles s'ajoutent donc, au lieu de concourir.
	 */
	/**
	 * L'étiquette de la saisie en cours : brouillon, en attente, envoyée.
	 *
	 * ⚠️ Elle vit **à côté** de l'état du réseau, jamais à sa place. Les
	 * deux se combinent — « Brouillon » + « Hors connexion » est une
	 * situation parfaitement ordinaire, et l'artiste doit lire les deux
	 * moitiés de sa réponse d'un seul coup d'œil.
	 *
	 * @return {Object|null} `{ texte, ton }`, ou rien hors formulaire.
	 */
	function phase() {
		if ( ! vu.saisie ) {
			return null;
		}

		var enFile = vu.file.total > 0;
		var fache  = ( vu.file.par.ERROR || 0 ) + ( vu.file.par.CONFLICT || 0 ) > 0;

		if ( 'depose' === vu.saisie.phase ) {
			if ( fache ) {
				return { texte: 'Dépôt bloqué', ton: 'grave' };
			}

			return enFile
				? { texte: 'En attente d’envoi', ton: 'attente' }
				: { texte: 'Déposée', ton: 'bon' };
		}

		if ( 'modification' === vu.saisie.phase ) {
			return enFile
				? { texte: 'Modification en attente', ton: 'attente' }
				: { texte: 'Modification', ton: 'calme' };
		}

		// Une saisie qui n'est jamais partie reste un brouillon, qu'il y
		// ait du réseau ou non. Le dire évite de croire qu'appuyer sur
		// « Suivant » a déposé quoi que ce soit.
		return { texte: 'Brouillon', ton: 'calme' };
	}

	function complement( b ) {
		var bouts = [];

		if ( vu.photos && ! b.photosDites ) {
			bouts.push( pluriel( vu.photos, 'photo à envoyer', 'photos à envoyer' ) );
		}

		// Hors connexion, « est-ce enregistré ? » est la question qui
		// suit immédiatement « ai-je perdu le réseau ? ». Y répondre dans
		// la même phrase évite d'avoir à la chercher ailleurs.
		if ( b.avecEnregistrement && vu.enregistre ) {
			bouts.push( enregistre( vu.enregistre ) );
		}

		return bouts.join( ' · ' );
	}

	/**
	 * « Saisie enregistrée à 14 h 32 », dans le texte du site.
	 *
	 * ⚠️ Repris du réglage `art_form_enregistre` plutôt qu'écrit ici. Tous
	 * les textes visibles du module sont réglables et traduisibles depuis
	 * l'administration ; en poser un en dur créerait deux régimes, et un
	 * site bilingue afficherait du français au milieu de l'anglais.
	 */
	function enregistre( horodatage ) {
		var cfg    = window.AFK_PWA_FORM || window.AFK_PWA_OEUVRES || {};
		var modele = ( cfg.textes || {} ).form_enregistre || 'Enregistré le %s';

		return modele.replace( '%s', quand( horodatage ) );
	}

	/**
	 * Le moment de l'enregistrement : date **et** heure.
	 *
	 * ⚠️ L'heure seule ne dit pas ce qu'on croit. Une saisie reprise le
	 * lendemain affichait « Enregistré 10:28 » — dix heures vingt-huit de
	 * quel jour ? L'artiste qui doute de son dépôt a précisément besoin
	 * de cette réponse-là.
	 */
	function quand( horodatage ) {
		try {
			var langue = ( window.AFK_PWA_FORM || window.AFK_PWA_OEUVRES || {} ).langue || 'fr-FR';
			var d      = new Date( horodatage );

			// ⚠️ Sans « le » devant : la préposition appartient au libellé
			// réglable, pas à la date. En la mettant ici, un libellé
			// « Enregistré à %s » donnait « Enregistré à le 28/08/2026 » —
			// constaté au banc.
			return d.toLocaleDateString( langue, { day: '2-digit', month: '2-digit', year: 'numeric' } )
				+ ' à ' + d.toLocaleTimeString( langue, { hour: '2-digit', minute: '2-digit' } );
		} catch ( e ) {
			return '';
		}
	}

	/** En dessous de quoi la fin de session devient une nouvelle. */
	var PROCHE = 10 * 60 * 1000;

	/** Millisecondes restantes, ou `null` si le serveur ne l'a pas dit. */
	function resteDeSession( e ) {
		if ( ! e.session.valide || ! e.session.expire ) {
			return null;
		}

		var fin = new Date( e.session.expire ).getTime();

		return isNaN( fin ) ? null : fin - Date.now();
	}

	function prolonger() {
		if ( window.afkSync ) {
			window.afkSync.verifier();
		}
	}

	function adresseConnexion() {
		var cfg = window.AFK_PWA_FORM || window.AFK_PWA_OEUVRES || {};
		return cfg.connexion || '/wp-login.php';
	}

	/** Ouvre l'écran d'édition sur l'œuvre à compléter. */
	function ouvrirLEditeur( cible ) {
		var cfg = window.AFK_PWA_FORM || window.AFK_PWA_OEUVRES || {};

		if ( ! cfg.editeur || ! cible ) {
			return;
		}

		// ⚠️ Par le fragment, jamais par la requête : une adresse avec
		// « ? » ne correspond plus à l'entrée mise en cache par le service
		// worker, et la page ne s'ouvrirait pas hors connexion.
		window.location.href = cfg.editeur + ( /^\d+$/.test( String( cible ) )
			? '#oeuvre=' + cible
			: '#brouillon=' + encodeURIComponent( cible ) );
	}

	function reEssayer() {
		if ( window.afkSync ) {
			window.afkSync.vider();
		}
	}

	/* ------------------------------------------------------------------
	 * Affichage
	 * ---------------------------------------------------------------- */

	var bandes = [];

	/**
	 * Le message flottant.
	 *
	 * ⚠️ Un message qui passe, et non un bandeau qui s'installe. Le
	 * bandeau occupait une bande pleine largeur en haut du contenu, à
	 * demeure, pour dire une chose souvent transitoire — il ressemblait
	 * à une panne du site plutôt qu'à une information sur un envoi.
	 *
	 * ⚠️ Il **reste tant que la situation dure**. Un message d'alerte qui
	 * s'efface au bout de trois secondes est pire que pas de message :
	 * « hors connexion » n'est pas une nouvelle, c'est un état, et
	 * l'artiste doit pouvoir le relire quand il s'interroge.
	 */
	var toast = null;

	function surface() {
		if ( toast && document.body.contains( toast ) ) {
			return toast;
		}

		toast = document.createElement( 'div' );
		toast.className = 'afk-toast';
		toast.setAttribute( 'role', 'status' );
		toast.setAttribute( 'aria-live', 'polite' );
		toast.hidden = true;
		document.body.appendChild( toast );

		return toast;
	}

	/**
	 * Ce que l'artiste a écarté, pour ne pas le lui remettre en boucle.
	 *
	 * ⚠️ Retenu le temps de la session de navigation, et non de la seule
	 * page.
	 *
	 * Le message revenait à chaque page : l'artiste le fermait, passait à
	 * la suivante, et le retrouvait intact. Écarter ne servait alors à
	 * rien — sinon à donner le sentiment que l'application n'écoute pas.
	 *
	 * Il repart en revanche à la session suivante : ce qui attend d'être
	 * envoyé doit se redire un jour, sans quoi l'œuvre resterait sur
	 * l'appareil sans que plus rien ne le rappelle.
	 *
	 * Le stockage de session peut être refusé — navigation privée, réglage
	 * du navigateur. La mémoire prend alors le relais pour la page en
	 * cours : moins bien, mais mieux que de ne rien pouvoir écarter.
	 */
	var CLE_ECARTE = 'afkEtatEcarte';
	var ecarteIci  = '';

	function ecarte() {
		try {
			return window.sessionStorage.getItem( CLE_ECARTE ) || ecarteIci;
		} catch ( e ) {
			return ecarteIci;
		}
	}

	function ecarter( titre ) {
		ecarteIci = titre;

		try {
			window.sessionStorage.setItem( CLE_ECARTE, titre );
		} catch ( e ) {
			// Rien à faire : la mémoire tiendra pour cette page.
		}
	}

	/**
	 * La situation à dire, la première de la liste qui s'applique.
	 *
	 * @param {boolean} sansEcartee Passer celle que l'artiste a écartée.
	 */
	function bandeau( sansEcartee ) {
		for ( var i = 0; i < SITUATIONS.length; i++ ) {
			var trouve = SITUATIONS[ i ]( vu );

			if ( ! trouve ) {
				continue;
			}

			// ⚠️ Écarter celle du dessus n'emporte pas celle du dessous.
			//
			// Le message écarté faisait taire la surface entière : « hors
			// connexion » ne se disait plus parce qu'un autre message,
			// écarté, le précédait dans l'ordre. L'artiste perdait le seul
			// signe qu'il venait de sortir de couverture, pour avoir
			// referme un message sans rapport.
			if ( sansEcartee && ecarte() === trouve.titre ) {
				continue;
			}

			return trouve;
		}

		return null;
	}

	function dessine() {
		var b = bandeau();

		// La surface flottante d'abord : c'est elle qu'on voit désormais.
		// Elle seule porte la croix, elle seule saute ce qui a été écarté.
		remplir( surface(), bandeau( true ), true );

		bandes.forEach( function ( bande ) {
			// ⚠️ Vidée, pas laissée en l'état. Sans cela, le dernier
			// message affiché resterait à l'écran une fois la situation
			// résolue — « 1 envoi en attente » sur une file vide.
			if ( ! b ) {
				bande.innerHTML = '';
				bande.className = 'afk-etat';
				return;
			}

			remplir( bande, b, false );
		} );
	}

	/**
	 * Remplit une surface avec la situation courante.
	 *
	 * @param {Element} bande    Où écrire.
	 * @param {Object}  b        La situation, ou `null`.
	 * @param {boolean} flottant Vrai pour la surface flottante.
	 */
	function remplir( bande, b, flottant ) {
		( function () {
			if ( ! b ) {
				bande.innerHTML = '';
				bande.className = flottant ? 'afk-toast' : 'afk-etat';
				bande.hidden    = !! flottant;
				return;
			}

			// ⚠️ Au repos, la surface flottante ne s'affiche pas du tout.
			// L'heure du dernier enregistrement se lit déjà dans la barre
			// du formulaire ; la faire flotter par-dessus la page pour
			// annoncer que tout va bien serait du bruit.
			if ( flottant && 'muet' === b.ton ) {
				bande.innerHTML = '';
				bande.hidden    = true;
				return;
			}

			// ⚠️ Reconstruit plutôt que corrigé. Retoucher les nœuds en
			// place laissait le geste de la situation précédente accroché
			// au bandeau de la suivante — « Réessayer » sur un bandeau
			// « hors connexion », qui ne pouvait rien réessayer.
			bande.innerHTML = '';
			bande.hidden    = false;
			bande.className = ( flottant ? 'afk-toast afk-toast-' : 'afk-etat afk-etat-' ) + b.ton;

			var p = phase();

			if ( p ) {
				var etiquette = document.createElement( 'span' );
				etiquette.className = 'afk-etat-phase afk-phase-' + p.ton;
				etiquette.textContent = p.texte;
				bande.appendChild( etiquette );
			}

			// Au repos, une coche plutôt qu'une pastille de couleur : elle
			// confirme sans alerter. Sans aucune marque, la ligne se
			// confondait avec le reste du pied de page et l'artiste ne la
			// voyait pas — c'est pourtant la seule chose qu'il cherche.
			var puce = document.createElement( 'span' );
			puce.setAttribute( 'aria-hidden', 'true' );

			// Une rondelle qui tourne à la place de la pastille quand
			// quelque chose est réellement en train de se faire : un point
			// immobile ne distingue pas « en cours » de « en attente ».
			if ( b.rondelle ) {
				puce.className = 'afk-rondelle';
			} else {
				puce.className = 'afk-etat-puce';

				if ( b.marque ) {
					puce.classList.add( 'afk-etat-marque' );
					puce.textContent = b.marque;
				}
			}

			var texte = document.createElement( 'span' );
			texte.className = 'afk-etat-texte';

			var titre = document.createElement( 'strong' );
			titre.className = 'afk-etat-titre';
			titre.textContent = b.titre;

			// ⚠️ Sur la surface flottante, la pastille va DANS le titre.
			//
			// Elle était une colonne à part, à gauche du bloc de texte. Or
			// ce bloc prend toute la largeur — les gestes vont dessous —
			// et la pastille se retrouvait donc seule sur une rangée
			// entière : huit pixels de rond, vingt-six de hauteur, et un
			// titre séparé de la couleur qui le qualifie. Dans le titre,
			// elle est là où on la lit, et la rangée disparaît.
			if ( flottant ) {
				titre.insertBefore( puce, titre.firstChild );
			} else {
				bande.appendChild( puce );
			}

			texte.appendChild( titre );

			var dit = [ b.detail, complement( b ) ].filter( Boolean ).join( ' · ' );

			if ( dit ) {
				var detail = document.createElement( 'span' );
				detail.className = 'afk-etat-detail';
				detail.textContent = dit;
				texte.appendChild( detail );
			}

			bande.appendChild( texte );

			if ( b.lien ) {
				var lien = document.createElement( 'a' );

				// ⚠️ Le geste mis en avant est nommé, jamais déduit de sa
				// place. L'ordre des boutons changera un jour, et la mise
				// en avant suivrait alors le mauvais sans rien signaler.
				lien.className = 'afk-etat-action afk-etat-action-forte';
				lien.href = b.lien.url;

				// Un `span` porte le texte : le bouton plein prend la
				// couleur du ton en fond, et le texte doit s'en détacher.
				var mots = document.createElement( 'span' );
				mots.textContent = b.lien.texte;
				lien.appendChild( mots );

				// ⚠️ Dans un autre onglet, et jamais dans celui-ci. Quitter
				// la page emporterait une saisie en cours : le brouillon est
				// bien conservé, mais l'artiste ne le sait pas, et voir son
				// écran disparaître au milieu d'un dépôt suffit à lui faire
				// croire qu'il a tout perdu. Au retour sur cet onglet, la
				// file revérifie la session d'elle-même.
				if ( b.lien.ailleurs ) {
					lien.target = '_blank';
					lien.rel = 'noopener';
				}

				bande.appendChild( lien );
			}

			if ( b.geste ) {
				var bouton = document.createElement( 'button' );
				bouton.type = 'button';
				bouton.className = 'afk-etat-action';
				bouton.textContent = b.geste.texte;
				bouton.addEventListener( 'click', b.geste.faire );
				bande.appendChild( bouton );
			}

			// ⚠️ Un message qu'on ne peut pas écarter finit par être subi —
			// sauf celui-ci, qui porte le seul bouton capable d'envoyer.
			// Depuis que rien ne part tout seul, le refermer reviendrait à
			// masquer le chemin du site, et l'œuvre resterait sur
			// l'appareil sans que rien ne le rappelle.
			if ( flottant && ! b.indispensable ) {
				var fermer = document.createElement( 'button' );
				fermer.type = 'button';
				fermer.className = 'afk-toast-fermer';
				fermer.setAttribute( 'aria-label', 'Masquer ce message' );
				fermer.textContent = '×';
				fermer.addEventListener( 'click', function () {
					// Écarté pour cette situation seulement : la suivante,
					// ou son retour après résolution, se dira de nouveau.
					ecarter( b.titre );
					bande.hidden = true;
				} );

				// ⚠️ La croix est posée au coin, hors du flux : la classe
				// dit au texte de lui réserver sa place. Sans elle, le
				// titre passait dessous sur les messages longs.
				//
				// Une classe plutôt qu'un `:has()` : celui-ci n'est pas
				// compris par les navigateurs mobiles d'avant 2023, et le
				// message doit se lire partout.
				bande.classList.add( 'afk-toast-avec-fermer' );
				bande.appendChild( fermer );
			}
		}() );
	}

	/**
	 * Installe un bandeau à cet endroit.
	 *
	 * Appelable plusieurs fois : le formulaire se reconstruit, et son
	 * emplacement change avec lui.
	 */
	function poser( cible ) {
		if ( ! cible || bandes.indexOf( cible ) !== -1 ) {
			return;
		}

		cible.setAttribute( 'role', 'status' );
		cible.setAttribute( 'aria-live', 'polite' );
		bandes.push( cible );
		dessine();
	}

	/* ------------------------------------------------------------------
	 * Ce qui nous tient au courant
	 * ---------------------------------------------------------------- */

	window.addEventListener( 'online', function () {
		vu.enLigne = true;
		dessine();
	} );

	window.addEventListener( 'offline', function () {
		vu.enLigne = false;
		dessine();
	} );

	/* ------------------------------------------------------------------
	 * Une question à laquelle on ne répond pas par mégarde
	 * ---------------------------------------------------------------- */

	/**
	 * Pose une question dont la réponse détruit quelque chose.
	 *
	 * ⚠️ Pas de `window.confirm` : sur téléphone il s'affiche hors du
	 * contexte, ne dit pas ce qu'on s'apprête à perdre, et certains
	 * navigateurs en mode application le suppriment purement et
	 * simplement — le geste serait alors exécuté sans question.
	 *
	 * ⚠️ Elle habite le socle et non l'écran des œuvres. La liste et le
	 * formulaire posent tous deux des questions destructrices ; c'est le
	 * même geste, il doit avoir le même comportement — à commencer par le
	 * focus, qui se pose sur « Annuler ».
	 *
	 * @return {Promise<boolean>}
	 */
	function demander( titre, detail, action ) {
		return new Promise( function ( repondre ) {
			var voile = document.createElement( 'div' );
			voile.className = 'afk-fiche afk-fiche-question';
			voile.setAttribute( 'role', 'dialog' );
			voile.setAttribute( 'aria-modal', 'true' );

			var boite = document.createElement( 'div' );
			boite.className = 'afk-fiche-boite afk-fiche-etroite';

			var h = document.createElement( 'h2' );
			h.className = 'afk-fiche-titre';
			h.textContent = titre;
			boite.appendChild( h );

			var p = document.createElement( 'p' );
			p.className = 'afk-fiche-detail';
			p.textContent = detail;
			boite.appendChild( p );

			var pied = document.createElement( 'div' );
			pied.className = 'afk-fiche-pied afk-fiche-pied-deux';

			function clore( reponse ) {
				voile.remove();
				document.removeEventListener( 'keydown', auClavier );
				repondre( reponse );
			}

			function auClavier( e ) {
				// Échap annule — jamais ne confirme.
				if ( 'Escape' === e.key ) {
					clore( false );
				}
			}

			var non = document.createElement( 'button' );
			non.type = 'button';
			non.className = 'afk-form-bouton afk-form-secondaire';
			non.textContent = 'Annuler';
			non.addEventListener( 'click', function () { clore( false ); } );

			var oui = document.createElement( 'button' );
			oui.type = 'button';
			oui.className = 'afk-form-bouton afk-form-danger';
			oui.textContent = action;
			oui.addEventListener( 'click', function () { clore( true ); } );

			pied.appendChild( non );
			pied.appendChild( oui );
			boite.appendChild( pied );
			voile.appendChild( boite );

			voile.addEventListener( 'click', function ( e ) {
				if ( e.target === voile ) {
					clore( false );
				}
			} );

			document.addEventListener( 'keydown', auClavier );
			document.body.appendChild( voile );

			// Le bouton d'annulation prend le focus : la touche Entrée ne
			// doit pas détruire une saisie par simple réflexe.
			non.focus();
		} );
	}

	window.afkDemander = demander;

	document.addEventListener( 'afkSession', function ( e ) {
		vu.session = e.detail || vu.session;
		dessine();
	} );

	document.addEventListener( 'afkFile', function ( e ) {
		var d = e.detail || {};
		vu.file = d.file || vu.file;
		vu.photos = d.photos || 0;
		dessine();
	} );

	document.addEventListener( 'afkSaisie', function ( e ) {
		vu.saisie = e.detail || null;
		dessine();
	} );

	document.addEventListener( 'afkBrouillon', function ( e ) {
		var d = e.detail || {};
		vu.enregistre = d.majLe || 0;

		// ⚠️ Une écriture réelle vaut mieux que n'importe quelle détection
		// préalable — dans les deux sens. Un seul refus passager, quota
		// atteint le temps d'un nettoyage, laissait l'avertissement le
		// plus grave affiché pour le reste de la session, alors que tout
		// s'enregistrait de nouveau. C'est la dernière écriture qui dit
		// la vérité, pas la première.
		if ( 'boolean' === typeof d.garde ) {
			vu.stockage = d.garde;
		}

		dessine();
	} );

	/**
	 * Le stockage accepte-t-il d'écrire ?
	 *
	 * ⚠️ Vérifié en ouvrant réellement la base, et non en testant la
	 * présence de `indexedDB`. Safari en navigation privée expose l'API
	 * et refuse l'écriture : le test de présence répondait « oui » et on
	 * promettait une conservation qui n'existait pas.
	 */
	DB.ouvrir().then( function () {
		vu.stockage = true;
		dessine();
	} ).catch( function () {
		vu.stockage = false;
		dessine();
	} );

	window.afkEtat = {
		poser: poser,
		dessine: dessine,
		vu: function () { return vu; }
	};

	function demarrer() {
		// Sur la page des œuvres, le bandeau se place au-dessus de la
		// liste. Sur celle du dépôt, c'est le formulaire qui le pose dans
		// sa barre d'action — toujours à l'écran, elle.
		var explicite = document.querySelector( '[data-afk-etat]' );

		if ( explicite ) {
			poser( explicite );
		}

		// ⚠️ Plus d'insertion devant le premier flux venu.
		//
		// On se posait devant `.ts-post-feed`, et **l'accueil du site en
		// porte un**. Un artiste connecté voyait donc « Session expirée »
		// en pleine page d'accueil, au-dessus des dernières œuvres
		// référencées, sur un écran qui n'a rien à voir avec son dépôt.
		// Constaté sur le site. On ne s'affiche plus que là où l'on a
		// été appelé.

		if ( window.afkSync ) {
			window.afkSync.annoncer();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
