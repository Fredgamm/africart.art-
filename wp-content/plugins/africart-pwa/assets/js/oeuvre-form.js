/**
 * Formulaire de dépôt d'œuvre — l'éditeur hors connexion.
 *
 * ⚠️ Même principe de non-ingérence que pour la liste : **en ligne, ce
 * script ne change rien**. Le formulaire du site existe, il est complet,
 * et en superposer un second créerait deux expériences divergentes pour
 * un même geste. Nous ne prenons la main que **sans réseau**.
 *
 * Le formulaire se construit depuis `/schema`, jamais depuis une liste
 * écrite en dur : les clés du thème sont opaques — `text-14`,
 * `texteditor-4` — et changent au moindre renommage dans son éditeur.
 * Un formulaire figé se mettrait à écrire dans le vide, sans erreur.
 *
 * @package Africart_PWA
 */
( function () {
	'use strict';

	var CFG = window.AFK_PWA_FORM || {};

	if ( ! CFG.base ) {
		return;
	}

	var DB = window.afkOeuvresDB;

	/**
	 * Où trouver les visuels de l'œuvre ouverte.
	 *
	 * ⚠️ Les champs image ne portent que des identifiants d'attachement.
	 * Le formulaire cherchait leurs octets dans la réserve locale, et
	 * n'avait rien d'autre : une œuvre ouverte sur un second appareil —
	 * ou après un nettoyage du navigateur — affichait « Visuel #5462 »
	 * à la place de chaque photo. L'artiste voyait une œuvre amputée de
	 * ses images et ne pouvait rien en conclure.
	 *
	 * L'œuvre transporte désormais l'adresse de chacun. Cette table les
	 * garde à portée du champ photo, qui ne connaît que des identifiants.
	 */
	var VISUELS_CONNUS = {};

	if ( ! DB ) {
		return;
	}

	var MAGASIN = 'brouillons';
	var T       = CFG.textes || {};

	/* ------------------------------------------------------------------
	 * Brouillons
	 *
	 * ⚠️ La base n'est plus ouverte ici : voir `oeuvres-db.js`. Deux
	 * fichiers qui déclarent chacun leur version de la même base finissent
	 * par se refuser l'accès l'un à l'autre.
	 * ---------------------------------------------------------------- */

	function litBrouillon( localId ) {
		return DB.lire( MAGASIN, localId );
	}

	/**
	 * Enregistre le brouillon, et dit si ça a tenu.
	 *
	 * ⚠️ `DB.ecrire` rend `null` en cas de refus au lieu de rejeter — un
	 * choix du socle, pour qu'un stockage indisponible ne casse pas la
	 * page. Mais un enregistrement muet qui n'enregistre rien est le pire
	 * des cas : le formulaire affichait « Enregistré à 14 h 32 » et il ne
	 * restait rien. L'échec est donc annoncé.
	 */
	function ecritBrouillon( brouillon ) {
		return DB.ecrire( MAGASIN, brouillon ).then( function ( r ) {
			document.dispatchEvent( new CustomEvent( 'afkBrouillon', {
				detail: { majLe: brouillon.majLe || 0, garde: null !== r }
			} ) );

			return r;
		} );
	}

	/* ------------------------------------------------------------------
	 * Schéma
	 * ---------------------------------------------------------------- */

	// ⚠️ Le schema n'est plus rapatrie ici. Il l'est depuis toutes les
	// pages, par `oeuvres.js` : le rapatrier uniquement depuis la page de
	// depot exigeait de l'avoir ouverte une fois avec du reseau, ce
	// qu'aucun artiste ne sait.

	function schemaEnMemoire() {
		// Dans l'éditeur, le serveur l'a transmis : pas de réserve à
		// interroger, donc pas de formulaire vide chez qui ouvre Elementor
		// sans avoir parcouru le site.
		if ( CFG.schema && CFG.schema.champs ) {
			return CFG.schema;
		}

		var s = DB.schema();

		return s.champs.length ? s : null;
	}

	/* ------------------------------------------------------------------
	 * Construction du formulaire
	 * ---------------------------------------------------------------- */

	var LONGS  = [ 'texteditor', 'description' ];
	var LISTES = [ 'select', 'taxonomy' ];

	/**
	 * Types de champs que ce formulaire sait rendre.
	 *
	 * ⚠️ La liste est **fermée**, et c'est le garde-fou principal. Un
	 * type absent d'ici n'est pas rendu approximativement en champ
	 * texte : il est annoncé comme non pris en charge. Rendre de travers
	 * un type qu'on ne connaît pas ferait écrire des valeurs de travers,
	 * sans erreur — et l'artiste ne verrait le problème qu'en consultant
	 * sa fiche publique.
	 *
	 * Y ajouter un type veut dire : savoir le rendre **et** savoir
	 * l'écrire.
	 */
	var GERES = [ 'title', 'text', 'texteditor', 'description', 'email', 'phone', 'select', 'taxonomy' ];

	/**
	 * Types de champs que le formulaire hors connexion ne traite pas.
	 *
	 * ⚠️ Ils sont **affichés et expliqués**, jamais omis en silence. Un
	 * champ absent laisserait croire qu'il n'existe pas, et l'artiste
	 * découvrirait son œuvre incomplète après coup.
	 */
	var DIFFERES = [ 'image', 'file', 'location' ];

	/* ------------------------------------------------------------------
	 * Normalisation
	 *
	 * Les libellés viennent du thème, saisis à la main sur des années :
	 * astérisques dans le texte, deux-points, espaces doubles, majuscules
	 * irrégulières. Les rendre tels quels donne un formulaire qui a l'air
	 * bricolé — et l'artiste doute de ce qu'on lui demande.
	 * ---------------------------------------------------------------- */

	function libelle( champ ) {
		var t = String( champ.label || champ.key || '' );

		t = t.replace( /\s+/g, ' ' ).trim();

		// ⚠️ L'astérisque et le deux-points finaux sont **retirés du
		// texte**, pas ajoutés : le module pose lui-même la marque
		// d'obligation. Les laisser donnait « Titre * * », qui ne signale
		// plus rien et fait douter du reste.
		t = t.replace( /[\s*:]+$/, '' );

		// ⚠️ Une phrase de cent trente signes n'est pas un libellé.
		//
		// « Renseignez ici des mots clés, séparés par une virgule, afin
		// que votre œuvre soit référencée au mieux. Ex : … » — suivi de la
		// même phrase en aide, aux exemples près. Deux paragraphes
		// identiques au-dessus d'un champ, et l'artiste relit deux fois
		// pour vérifier qu'il n'a rien manqué.
		//
		// Le sujet suffit en titre ; la consigne, elle, reste dessous —
		// elle n'est pas perdue, elle est remise à sa place.
		if ( /mots?[\s-]*cl[ée]s/i.test( t ) && t.length > 40 ) {
			return 'Mots clés';
		}

		// ⚠️ « Œuvre signée ? (OUI / NON) » devient « Œuvre signée ? ».
		//
		// La consigne entre parenthèses date de l'époque où ce champ était
		// une ligne de texte libre. Deux boutons dessous la répètent, et
		// une consigne redondante fait douter : l'artiste se demande si on
		// attend autre chose que ce qu'il voit.
		t = t.replace( /\s*\(\s*oui\s*[\/·|-]\s*non\s*\)\s*$/i, '' );

		return t.replace( /[\s*:]+$/, '' );
	}

	/**
	 * Attributs de saisie par type.
	 *
	 * ⚠️ `font-size` de 16 px au minimum côté style, et le bon
	 * `inputmode` ici : sans eux, iOS zoome à chaque champ touché et ne
	 * dézoome jamais. Le formulaire devient inutilisable au doigt — et
	 * c'est le cas d'usage principal.
	 */
	var SAISIE = {
		email: { type: 'email', auto: 'email', mode: 'email' },
		phone: { type: 'tel', auto: 'tel', mode: 'tel' }
	};

	/**
	 * Exemples posés en filigrane, quand le site n'en donne pas.
	 *
	 * ⚠️ « Dimensions en cm » n'apprend rien à qui ne sait pas si l'on
	 * attend « 120x80 », « 120 x 80 x 5 » ou « H 120 L 80 ». Chacun
	 * répondait à sa façon, et la fiche publique affichait dix formats
	 * différents pour la même information — impossible à trier ensuite.
	 *
	 * Reconnus par le **libellé** et non par la clé : `text-3` ne dit
	 * rien, et une clé peut changer si le site est remanié. Le premier
	 * motif qui correspond gagne, l'ordre compte donc.
	 *
	 * Ces exemples ne s'imposent jamais : le champ garde son invite si le
	 * site en a défini une.
	 */
	var EXEMPLES = [
		{ motif: /dimension/i,                     invite: 'Ex. : 120 x 80 cm (hauteur x largeur)' },
		{ motif: /tirage/i,                        invite: 'Ex. : original, ou édition limitée de 30' },
		{ motif: /technique/i,                     invite: 'Ex. : acrylique sur toile, bois, plumes' },
		{ motif: /ann[ée]e.*(cr[ée]ation|estim)/i, invite: 'Ex. : 1998, ou « années 1990 »' },
		{ motif: /ann[ée]e.*acquisition/i,         invite: 'Ex. : 2019' },
		{ motif: /localisation/i,                  invite: 'Ex. : Abidjan, Côte d’Ivoire' },
		{ motif: /lieu.*acquisition/i,             invite: 'Ex. : galerie, marché, héritage familial…' },
		{ motif: /page web|site internet/i,        invite: 'Ex. : https://instagram.com/votre-compte' },
		{ motif: /prix.*acquisition/i,             invite: 'Ex. : 1500' },
		{ motif: /valeur estim/i,                  invite: 'Ex. : 2500' },
		{ motif: /adresse postale/i,               invite: 'Ex. : 12 rue des Jardins, Cocody, Abidjan' },
		{ motif: /d[ée]nomination sociale/i,       invite: 'Ex. : Galerie Soleil, ou laissez vide' },
		{ motif: /courriel|e-?mail/i,              invite: 'Ex. : vous@exemple.com' },
		// ⚠️ Les limites de mot ne sont pas décoratives : sans elles,
		// « Art naïf, Paysage, Abstrait… » — l'exemple de mots-clés du
		// site — était pris pour une demande de pays. Constaté au banc.
		{ motif: /\bpays\b/i,                      invite: 'Tapez les premières lettres…' },
		{ motif: /si autre, pr[ée]cis/i,           invite: 'Ex. : installation, textile, art vidéo' }
	];

	/**
	 * Ce champ pose-t-il une question fermée ?
	 *
	 * ⚠️ Deux formes, sur ce site : une liste déroulante à deux entrées
	 * « Oui / Non », et une ligne de texte dont le libellé se termine par
	 * « (OUI / NON) ». Dans les deux cas c'est une question fermée, et
	 * dans les deux cas l'artiste devait ouvrir un menu ou taper un mot
	 * pour y répondre — sur un téléphone, trois gestes pour dire oui.
	 *
	 * Les valeurs rendues sont **celles du site**, jamais des valeurs
	 * inventées : sur une liste, ce sont ses propres options ; sur une
	 * ligne de texte, « Oui » et « Non », qu'un champ texte accepte.
	 *
	 * @return {Object|null} `{ oui, non }` — les valeurs à écrire.
	 */
	function binaire( champ ) {
		var choix = champ.choix || [];

		if ( 'select' === champ.type && 2 === choix.length ) {
			var un   = String( choix[ 0 ].value || '' ).trim().toLowerCase();
			var deux = String( choix[ 1 ].value || '' ).trim().toLowerCase();

			if ( 'oui' === un && 'non' === deux ) {
				return { oui: choix[ 0 ].value, non: choix[ 1 ].value };
			}

			if ( 'non' === un && 'oui' === deux ) {
				return { oui: choix[ 1 ].value, non: choix[ 0 ].value };
			}

			return null;
		}

		if ( 'text' === champ.type && /\(\s*oui\s*[\/·|-]\s*non\s*\)\s*$/i.test( String( champ.label || '' ).replace( /[\s*:]+$/, '' ) ) ) {
			return { oui: 'Oui', non: 'Non' };
		}

		return null;
	}

	/**
	 * Ce champ est-il un accord à donner ?
	 *
	 * Une liste à une seule entrée — « OK » — n'est pas un choix : c'est
	 * une case à cocher déguisée. La rendre en menu déroulant obligeait à
	 * l'ouvrir pour découvrir qu'il n'y avait rien à décider.
	 *
	 * @return {string|null} La valeur qui vaut accord.
	 */
	function accord( champ ) {
		var choix = champ.choix || [];

		return 'select' === champ.type && 1 === choix.length ? choix[ 0 ].value : null;
	}

	/**
	 * Ce champ attend-il une liste de mots ?
	 *
	 * ⚠️ Reconnu au libellé, comme les autres. Sur ce site le champ
	 * s'appelle `texteditor-4` — un nom qui ne dit rien de son contenu et
	 * qui changerait au premier remaniement.
	 */
	function demandeDesMots( champ ) {
		if ( LONGS.indexOf( champ.type ) === -1 && 'text' !== champ.type ) {
			return false;
		}

		return /mots?[\s-]*cl[ée]s/i.test( String( champ.label || '' ) );
	}

	/** Ce champ attend-il un pays ? */
	function demandeUnPays( champ ) {
		return 'text' === champ.type && /\bpays\b/i.test( String( champ.label || '' ) );
	}

	/**
	 * Le nom court d'une étape.
	 *
	 * ⚠️ Les intitulés du site ne sont pas des titres d'étape.
	 * « Informations publiées sur AFRICART.art » fait trente-neuf signes,
	 * « Informations confidentielles » vingt-huit, et la deuxième étape
	 * n'en a aucun. Alignés dans une barre de navigation, ils passent à
	 * la ligne, se coupent, et l'on ne distingue plus une étape d'une
	 * autre — ce qui est exactement ce qu'une barre d'étapes doit servir
	 * à faire.
	 *
	 * Un mot par étape, dit du point de vue de l'artiste : ce qu'il
	 * décrit, puis à qui il parle.
	 *
	 * ⚠️ Reconnus par la clé du site, avec un repli. Si le client
	 * remanie ses étapes, les inconnues gardent le début de leur propre
	 * intitulé plutôt que de disparaître.
	 */
	var TITRES_ETAPES = {
		'step-general': 'L’œuvre',
		'ui-step-3':    'Détails',
		'ui-step-2':    'Description',
		'ui-step-5':    'Confidentiel'
	};

	function titreCourt( etape, rang ) {
		var cle = String( etape.key || '' );
		var sien = T[ 'etape_' + cle.replace( /[^a-z0-9]+/gi, '_' ) ];

		if ( sien ) {
			return sien;
		}

		if ( TITRES_ETAPES[ cle ] ) {
			return TITRES_ETAPES[ cle ];
		}

		var libelle = String( etape.label || '' ).trim();

		if ( ! libelle ) {
			return 'Étape ' + ( rang + 1 );
		}

		// Le premier segment de l'intitulé : ce qui précède un point, un
		// tiret ou une parenthèse dit presque toujours le sujet.
		var court = libelle.split( /[.(–—:]/ )[ 0 ].trim();

		return court.length > 22 ? court.slice( 0, 20 ).trim() + '…' : court;
	}

	/** Types rendus sur une seule colonne, même sur grand écran. */
	var PLEINE_LARGEUR = [ 'title', 'description', 'texteditor', 'image', 'file', 'location' ];

	/**
	 * Longueur au-delà de laquelle un libellé prend toute la largeur.
	 *
	 * ⚠️ Plusieurs « libellés » du site sont des phrases entières —
	 * « Acceptez-vous que nous partagions… », « En soumettant ces
	 * informations à… ». Les tasser dans une demi-colonne les casse sur
	 * trois lignes, et un libellé sur trois lignes ne se lit plus comme
	 * une question mais comme un paragraphe qu'on saute.
	 */
	var LIBELLE_LONG = 58;

	/* ------------------------------------------------------------------
	 * Validation
	 * ---------------------------------------------------------------- */

	/**
	 * Ce qui cloche dans une valeur, ou rien.
	 *
	 * ⚠️ Un champ que le formulaire **ne sait pas rendre** n'est jamais
	 * déclaré fautif, même obligatoire. L'artiste n'aurait aucun moyen de
	 * le corriger : le dépôt serait bloqué par un champ qu'il ne voit
	 * pas. Le serveur, lui, tranchera — et il a de quoi l'expliquer.
	 *
	 * @param {Object} champ  Champ du schéma.
	 * @param {*}      valeur Valeur saisie.
	 * @return {string} Message, ou chaîne vide.
	 */
	function erreurDe( champ, valeur ) {
		if ( GERES.indexOf( champ.type ) === -1 && 'image' !== champ.type && 'file' !== champ.type ) {
			return '';
		}

		var liste = Array.isArray( valeur );
		var texte = liste ? '' : String( null === valeur || undefined === valeur ? '' : valeur );
		var vide  = liste ? ! valeur.length : ! texte.trim();

		if ( champ.required && vide ) {
			return 'image' === champ.type || 'file' === champ.type
				? 'Au moins un visuel est nécessaire.'
				: 'Ce champ est obligatoire.';
		}

		if ( vide ) {
			return '';
		}

		if ( 'email' === champ.type && ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( texte ) ) {
			return 'Cette adresse électronique ne semble pas valide.';
		}

		if ( 'phone' === champ.type && texte.replace( /\D/g, '' ).length < 6 ) {
			return 'Ce numéro paraît trop court.';
		}

		if ( champ.maxi && texte.length > champ.maxi ) {
			return 'Maximum ' + champ.maxi + ' caractères — il y en a ' + texte.length + '.';
		}

		if ( champ.mini && texte.length < champ.mini ) {
			return 'Minimum ' + champ.mini + ' caractères.';
		}

		return '';
	}

	/** Affiche ou efface l'erreur d'un champ. */
	function marque( bloc, message ) {
		var zone   = bloc.querySelector( '.afk-champ-erreur' );
		var saisie = bloc.querySelector( '.afk-champ-saisie' );

		if ( ! zone ) {
			return;
		}

		zone.textContent = message;
		zone.hidden      = ! message;
		bloc.classList.toggle( 'afk-champ-fautif', !! message );

		if ( saisie ) {
			saisie.setAttribute( 'aria-invalid', message ? 'true' : 'false' );
		}
	}

	function champHTML( champ, valeur ) {
		var bloc = document.createElement( 'div' );
		bloc.className = 'afk-champ afk-champ-' + champ.type;

		if ( PLEINE_LARGEUR.indexOf( champ.type ) === -1 && libelle( champ ).length <= LIBELLE_LONG ) {
			bloc.classList.add( 'afk-champ-court' );
		}

		bloc.setAttribute( 'data-afk-champ', champ.key );

		var id     = 'afk-f-' + champ.key;
		var aideId = 'afk-a-' + champ.key;
		var errId  = 'afk-e-' + champ.key;

		var etiquette = document.createElement( 'label' );
		etiquette.className = 'afk-champ-label';
		etiquette.id = id + '-l';
		etiquette.setAttribute( 'for', id );
		etiquette.textContent = libelle( champ );

		if ( champ.required ) {
			var etoile = document.createElement( 'span' );
			etoile.className = 'afk-champ-requis';
			etoile.setAttribute( 'aria-hidden', 'true' );
			etoile.textContent = '*';
			etiquette.appendChild( etoile );
		}

		bloc.appendChild( etiquette );

		// L'aide du site, sous le libellé et avant le champ : après, elle
		// n'est lue qu'une fois la réponse donnée, c'est-à-dire trop tard.
		var decrit = [];

		if ( champ.aide ) {
			var aide = document.createElement( 'p' );
			aide.className = 'afk-champ-aide';
			aide.id = aideId;
			aide.textContent = champ.aide;
			bloc.appendChild( aide );
			decrit.push( aideId );
		}

		decrit.push( errId );

		var erreur = document.createElement( 'p' );
		erreur.className = 'afk-champ-erreur';
		erreur.id = errId;
		erreur.setAttribute( 'role', 'alert' );
		erreur.hidden = true;

		if ( 'image' === champ.type || 'file' === champ.type ) {
			bloc.appendChild( champFichier( champ, valeur ) );
			bloc.appendChild( erreur );
			return bloc;
		}

		if ( DIFFERES.indexOf( champ.type ) !== -1 ) {
			var note = document.createElement( 'p' );
			note.className = 'afk-champ-differe';
			note.textContent = 'À renseigner au retour du réseau.';
			bloc.appendChild( note );
			return bloc;
		}

		// Type inconnu : on le dit. Le rendre en champ texte produirait
		// une valeur que l'écriture refuserait — ou pire, accepterait mal.
		if ( GERES.indexOf( champ.type ) === -1 ) {
			var inconnu = document.createElement( 'p' );
			inconnu.className = 'afk-champ-inconnu';
			inconnu.textContent = 'Ce champ ne peut pas être renseigné depuis l’application. Utilisez le formulaire du site.';
			bloc.appendChild( inconnu );
			return bloc;
		}

		// ⚠️ Une question fermée se répond d'un doigt.
		//
		// « Oui / Non » était une liste déroulante : toucher le champ,
		// attendre le menu du système, viser une ligne, valider. Quatre
		// gestes pour dire oui, sur cinq questions d'affilée. Deux boutons
		// côte à côte se répondent en un seul, et l'on voit sa réponse
		// sans rien ouvrir.
		if ( demandeDesMots( champ ) ) {
			bloc.appendChild( champMots( champ, valeur, id, decrit ) );
			bloc.appendChild( erreur );
			return bloc;
		}

		var deuxChoix = binaire( champ );

		if ( deuxChoix ) {
			bloc.appendChild( champBinaire( champ, valeur, id, deuxChoix, decrit ) );
			bloc.appendChild( erreur );
			return bloc;
		}

		var seulChoix = accord( champ );

		if ( seulChoix ) {
			bloc.appendChild( champAccord( champ, valeur, id, seulChoix, decrit ) );
			bloc.appendChild( erreur );
			return bloc;
		}

		var saisie;

		if ( LISTES.indexOf( champ.type ) !== -1 ) {
			saisie = document.createElement( 'select' );

			// ⚠️ Un choix vide en tête, toujours. Sans lui, le premier de
			// la liste serait retenu par défaut, et l'artiste déposerait
			// une valeur qu'il n'a jamais choisie — « Artiste » pour un
			// galeriste, par exemple.
			var vide = document.createElement( 'option' );
			vide.value = '';
			vide.textContent = champ.required ? '— à choisir —' : '— aucun —';
			saisie.appendChild( vide );

			( champ.choix || [] ).forEach( function ( c ) {
				var option = document.createElement( 'option' );
				option.value = c.value;
				option.textContent = c.label;
				saisie.appendChild( option );
			} );
		} else if ( LONGS.indexOf( champ.type ) !== -1 ) {
			saisie = document.createElement( 'textarea' );
			saisie.rows = 4;
		} else {
			var reglage = SAISIE[ champ.type ] || {};
			saisie = document.createElement( 'input' );
			saisie.type = reglage.type || 'text';

			if ( reglage.auto ) {
				saisie.autocomplete = reglage.auto;
			}

			if ( reglage.mode ) {
				saisie.setAttribute( 'inputmode', reglage.mode );
			}
		}

		saisie.id = id;
		saisie.className = 'afk-champ-saisie';
		saisie.name = champ.key;
		saisie.setAttribute( 'data-afk-type', champ.type );
		saisie.setAttribute( 'aria-describedby', decrit.join( ' ' ) );

		if ( champ.required ) {
			saisie.setAttribute( 'aria-required', 'true' );
		}

		// L'invite du site quand elle dit autre chose que le libellé :
		// répéter le libellé en gris n'apprend rien et alourdit l'écran.
		if ( champ.invite && champ.invite.trim() !== libelle( champ ) ) {
			saisie.placeholder = champ.invite;
		} else if ( LISTES.indexOf( champ.type ) === -1 ) {
			// À défaut, un exemple. Le site n'en donne presque jamais, et
			// « Dimensions en cm » laisse chacun inventer son format.
			var exemple = exemplePour( champ );

			if ( exemple ) {
				saisie.placeholder = exemple;
			}
		}

		if ( champ.maxi ) {
			saisie.maxLength = champ.maxi;
		}

		// La taxonomie se lit en liste de slugs, même pour un seul terme.
		// Poser le tableau tel quel afficherait « [peinture] ».
		var courante = valeur;

		if ( Array.isArray( courante ) ) {
			courante = courante.length ? courante[ 0 ] : '';
		}

		saisie.value = null === courante || undefined === courante ? '' : String( courante );

		bloc.appendChild( saisie );

		if ( demandeUnPays( champ ) ) {
			listeDesPays( saisie, bloc );
		}

		if ( 'phone' === champ.type ) {
			champTelephone( saisie, champ );
		}

		// Un compte à rebours, seulement là où une borne existe. En poser
		// partout ferait du bruit ; n'en poser nulle part laisserait
		// découvrir la limite en butant dessus.
		if ( champ.maxi ) {
			var compteur = document.createElement( 'div' );
			compteur.className = 'afk-champ-compteur';

			var compte = function () {
				var reste = champ.maxi - saisie.value.length;
				var pluriel = Math.abs( reste ) > 1 ? 's' : '';
				compteur.textContent = reste + ' caractère' + pluriel + ' restant' + pluriel;
				compteur.classList.toggle( 'afk-champ-compteur-limite', reste <= Math.max( 10, champ.maxi * 0.1 ) );
			};

			saisie.addEventListener( 'input', compte );
			compte();
			bloc.appendChild( compteur );
		}

		bloc.appendChild( erreur );

		// ⚠️ L'erreur paraît à la sortie du champ, jamais à la frappe :
		// signaler « adresse non valide » dès la première lettre tapée
		// est exact et insupportable. Une fois signalée, en revanche, elle
		// s'efface dès que la saisie redevient correcte.
		saisie.addEventListener( 'blur', function () {
			marque( bloc, erreurDe( champ, saisie.value ) );
		} );

		saisie.addEventListener( 'input', function () {
			if ( bloc.classList.contains( 'afk-champ-fautif' ) ) {
				marque( bloc, erreurDe( champ, saisie.value ) );
			}
		} );

		return bloc;
	}

	/**
	 * Champ de dépôt de visuels.
	 *
	 * ⚠️ Le téléversement part **dès la sélection**, pas à l'envoi du
	 * formulaire. Attendre l'envoi ferait partir plusieurs mégaoctets au
	 * moment précis où l'artiste attend une réponse — et un échec réseau
	 * lui ferait tout recommencer, saisie comprise.
	 *
	 * Ce qui est retenu, ce sont les **identifiants** rendus par le
	 * serveur, jamais les fichiers : c'est cette forme que l'écriture
	 * attend.
	 */
	/**
	 * Taille au-delà de laquelle on refuse de garder une photo sur
	 * l'appareil.
	 *
	 * ⚠️ Le stockage d'un navigateur n'est pas extensible, et il peut
	 * être vidé sans prévenir. Accepter des photos de dix mégaoctets à
	 * volonté, c'est promettre une conservation qu'on ne peut pas tenir —
	 * et l'artiste ne l'apprendrait qu'en retrouvant sa saisie amputée.
	 */
	var POIDS_MAX = 8 * 1024 * 1024;

	/** Photos gardées en attente, tous champs confondus. */
	var EN_ATTENTE_MAX = 12;

	/**
	 * Des mots clés qu'on pose un par un.
	 *
	 * ⚠️ « Séparés par une virgule » demandait à l'artiste de tenir la
	 * syntaxe lui-même. Sur un téléphone, la virgule est sur un second
	 * clavier ; on obtenait « Juste, pou, test » — une faute de frappe
	 * qui devient un mot clé, et personne ne la voit dans une ligne de
	 * texte. Chaque mot devient une étiquette dès qu'on valide : on voit
	 * ce qu'on a posé, et on peut le retirer d'un doigt.
	 *
	 * ⚠️ La valeur écrite reste la liste séparée par des virgules. C'est
	 * un champ de texte sur AFRICART, lu tel quel par le référencement :
	 * y déposer autre chose casserait les œuvres déjà en ligne et les
	 * rendrait incomparables avec les nouvelles.
	 */
	function champMots( champ, valeur, id, decrit ) {
		var boite = document.createElement( 'div' );
		boite.className = 'afk-mots';

		var porteur = document.createElement( 'input' );
		porteur.type = 'hidden';
		porteur.className = 'afk-champ-saisie';
		porteur.name = champ.key;
		porteur.setAttribute( 'data-afk-type', champ.type );

		var liste = [];

		String( null === valeur || undefined === valeur ? '' : valeur )
			.split( ',' )
			.forEach( function ( m ) {
				var propre = m.trim();

				if ( propre && ! deja( propre ) ) {
					liste.push( propre );
				}
			} );

		function deja( mot ) {
			var plie = mot.trim().toLowerCase();

			return liste.some( function ( m ) { return m.toLowerCase() === plie; } );
		}

		function poser() {
			porteur.value = liste.join( ', ' );
		}

		poser();

		var rangee = document.createElement( 'div' );
		rangee.className = 'afk-mots-rangee';

		var saisie = document.createElement( 'input' );
		saisie.type = 'text';
		saisie.id = id;
		saisie.className = 'afk-mots-saisie';
		saisie.autocomplete = 'off';
		saisie.setAttribute( 'aria-describedby', decrit.join( ' ' ) );
		saisie.placeholder = champ.invite || 'Un mot, puis Entrée';

		// ⚠️ La touche de validation du clavier mobile porte un nom.
		// Sans cela iOS et Android affichent « Aller », qui laisse croire
		// qu'on quitte le formulaire.
		saisie.setAttribute( 'enterkeyhint', 'enter' );

		if ( champ.required ) {
			saisie.setAttribute( 'aria-required', 'true' );
		}

		function annonce() {
			porteur.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			porteur.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function dessine() {
			Array.prototype.forEach.call( rangee.querySelectorAll( '.afk-mot' ), function ( n ) {
				n.remove();
			} );

			liste.forEach( function ( mot, rang ) {
				var etiquette = document.createElement( 'span' );
				etiquette.className = 'afk-mot';

				var texte = document.createElement( 'span' );
				texte.className = 'afk-mot-texte';
				texte.textContent = mot;
				etiquette.appendChild( texte );

				var oter = document.createElement( 'button' );
				oter.type = 'button';
				oter.className = 'afk-mot-oter';
				oter.setAttribute( 'aria-label', 'Retirer « ' + mot + ' »' );
				oter.textContent = '×';
				oter.addEventListener( 'click', function () {
					liste.splice( rang, 1 );
					poser();
					dessine();
					annonce();
					saisie.focus();
				} );

				etiquette.appendChild( oter );
				rangee.insertBefore( etiquette, saisie );
			} );
		}

		function ajoute( brut ) {
			var ajoutes = 0;

			// Une virgule collée reste un séparateur : celui qui connaît la
			// consigne d'avant ne doit pas être puni de l'avoir suivie.
			String( brut || '' ).split( ',' ).forEach( function ( m ) {
				var propre = m.trim().replace( /\s+/g, ' ' );

				if ( ! propre || deja( propre ) ) {
					return;
				}

				liste.push( propre );
				ajoutes++;
			} );

			if ( ! ajoutes ) {
				return false;
			}

			poser();
			dessine();
			annonce();

			return true;
		}

		saisie.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ',' === e.key ) {
				// ⚠️ Sur un formulaire, Entrée soumet. Ici elle pose un
				// mot : sans ce garde-fou, valider son premier mot clé
				// déclencherait l'envoi de l'œuvre entière.
				e.preventDefault();

				if ( ajoute( saisie.value ) ) {
					saisie.value = '';
				}

				return;
			}

			// Retour arrière sur un champ vide : on retire le dernier posé.
			// C'est le geste attendu partout ailleurs, et l'apprendre ici
			// n'aurait servi qu'ici.
			if ( 'Backspace' === e.key && '' === saisie.value && liste.length ) {
				e.preventDefault();
				liste.pop();
				poser();
				dessine();
				annonce();
			}
		} );

		// ⚠️ Ce qui reste dans la ligne au moment de partir compte.
		//
		// Un mot tapé puis non validé aurait disparu au changement
		// d'étape, sans un mot d'explication — et l'artiste aurait cru
		// l'avoir posé.
		saisie.addEventListener( 'blur', function () {
			if ( ajoute( saisie.value ) ) {
				saisie.value = '';
			}
		} );

		// ⚠️ Les étiquettes ne se déduisent pas du porteur : elles sont
		// dessinées à côté. Une annulation qui repose la valeur d'origine
		// laisserait donc les anciennes à l'écran, et l'artiste verrait
		// des mots qui ne sont plus dans son œuvre.
		document.addEventListener( 'afkValeursPosees', function () {
			liste = [];

			String( porteur.value || '' ).split( ',' ).forEach( function ( m ) {
				var propre = m.trim();

				if ( propre && ! deja( propre ) ) {
					liste.push( propre );
				}
			} );

			saisie.value = '';
			dessine();
		} );

		// Toucher la boîte donne la main à la ligne de saisie : viser un
		// champ de trente pixels de haut au doigt n'est pas raisonnable.
		boite.addEventListener( 'click', function ( e ) {
			if ( e.target === boite || e.target === rangee ) {
				saisie.focus();
			}
		} );

		rangee.appendChild( saisie );
		boite.appendChild( rangee );
		boite.appendChild( porteur );

		dessine();

		return boite;
	}

	/**
	 * La liste des pays, attachée à un champ texte.
	 *
	 * ⚠️ Une liste à nous, pas un `<datalist>`.
	 *
	 * Le `datalist` du navigateur aurait tenu en trois lignes, mais il ne
	 * sait faire ni l'un ni l'autre de ce qui compte ici : afficher un
	 * drapeau à côté du nom, et trouver « Côte d'Ivoire » quand on tape
	 * « cote divoire » sans accent ni apostrophe — ce que fait tout le
	 * monde sur un clavier de téléphone. Son rendu varie en outre d'un
	 * navigateur à l'autre, jusqu'à ne rien afficher du tout sur certains.
	 *
	 * ⚠️ La valeur écrite reste **le nom du pays en clair**, jamais son
	 * code. Ce champ est une ligne de texte sur AFRICART, lue telle quelle
	 * sur la fiche publique : y déposer « CI » rendrait les fiches
	 * illisibles et les anciennes saisies incomparables.
	 *
	 * Le champ reste libre : rien n'oblige à choisir dans la liste. Un
	 * artiste dont le pays manquerait — ou qui veut écrire autre chose —
	 * n'est pas bloqué.
	 */
	function listeDesPays( saisie, bloc ) {
		var pays = window.AFK_PAYS;

		if ( ! pays || ! pays.length ) {
			return;
		}

		saisie.setAttribute( 'role', 'combobox' );
		saisie.setAttribute( 'aria-autocomplete', 'list' );
		saisie.setAttribute( 'aria-expanded', 'false' );
		saisie.autocomplete = 'off';

		var liste = document.createElement( 'ul' );
		liste.className = 'afk-pays-liste';
		liste.id = saisie.id + '-pays';
		liste.setAttribute( 'role', 'listbox' );
		liste.hidden = true;

		saisie.setAttribute( 'aria-controls', liste.id );

		var enveloppe = document.createElement( 'div' );
		enveloppe.className = 'afk-pays';
		saisie.parentNode.insertBefore( enveloppe, saisie );
		enveloppe.appendChild( saisie );
		enveloppe.appendChild( liste );

		var vise = -1;

		// ⚠️ Poser la valeur déclenche `input`, qui rouvrait la liste
		// aussitôt fermée : on choisissait un pays et le menu restait
		// ouvert par-dessus le champ suivant. Constaté au banc.
		var enPose = false;

		// ⚠️ Ni accents, ni apostrophes, ni espaces.
		//
		// J'avais d'abord remplacé la ponctuation par une espace :
		// « Côte d'Ivoire » devenait « cote d ivoire », et quelqu'un qui
		// tape « cote divoire » — comme tout le monde le fait — ne
		// trouvait rien. Constaté au banc. En supprimant tout, les deux
		// se rejoignent sur « cotedivoire ».
		function plie( t ) {
			return String( t || '' ).normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
				.toLowerCase().replace( /[^a-z0-9]+/g, '' );
		}

		function ferme() {
			liste.hidden = true;
			liste.textContent = '';
			saisie.setAttribute( 'aria-expanded', 'false' );
			saisie.removeAttribute( 'aria-activedescendant' );
			vise = -1;
		}

		function choisit( p ) {
			enPose = true;
			saisie.value = p.n;
			saisie.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			saisie.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			enPose = false;
			ferme();
		}

		function surligne( rang ) {
			var lignes = liste.querySelectorAll( '.afk-pays-ligne' );

			if ( ! lignes.length ) {
				return;
			}

			vise = ( rang + lignes.length ) % lignes.length;

			Array.prototype.forEach.call( lignes, function ( l, i ) {
				l.classList.toggle( 'afk-pays-vise', i === vise );
				l.setAttribute( 'aria-selected', i === vise ? 'true' : 'false' );
			} );

			saisie.setAttribute( 'aria-activedescendant', lignes[ vise ].id );
			lignes[ vise ].scrollIntoView( { block: 'nearest' } );
		}

		function ouvre() {
			if ( enPose ) {
				return;
			}

			var cherche = plie( saisie.value );

			// ⚠️ Ce qui COMMENCE par la saisie d'abord, le reste ensuite.
			// « ni » doit proposer le Niger et le Nigéria avant la
			// Bosnie-Herzégovine, qui contient pourtant les mêmes lettres.
			var debut = [];
			var dedans = [];

			for ( var i = 0; i < pays.length && debut.length + dedans.length < 60; i++ ) {
				if ( ! cherche ) {
					debut.push( pays[ i ] );
					continue;
				}

				var ou = plie( pays[ i ].n ).indexOf( cherche );

				if ( 0 === ou ) {
					debut.push( pays[ i ] );
				} else if ( ou > 0 ) {
					dedans.push( pays[ i ] );
				}
			}

			var trouves = debut.concat( dedans ).slice( 0, 40 );

			liste.textContent = '';
			vise = -1;
			saisie.removeAttribute( 'aria-activedescendant' );

			if ( ! trouves.length ) {
				liste.hidden = true;
				saisie.setAttribute( 'aria-expanded', 'false' );
				return;
			}

			trouves.forEach( function ( p, rang ) {
				var ligne = document.createElement( 'li' );
				ligne.className = 'afk-pays-ligne';
				ligne.id = liste.id + '-' + rang;
				ligne.setAttribute( 'role', 'option' );
				ligne.setAttribute( 'aria-selected', 'false' );

				var d = document.createElement( 'span' );
				d.className = 'afk-pays-drapeau';
				d.setAttribute( 'aria-hidden', 'true' );

				// ⚠️ Le code du pays sous le drapeau, jamais à sa place.
				// Windows ne dessine pas les drapeaux emoji et affiche les
				// deux lettres : c'est le repli, et il est déjà lisible.
				d.textContent = p.d;

				var n = document.createElement( 'span' );
				n.className = 'afk-pays-nom';
				n.textContent = p.n;

				ligne.appendChild( d );
				ligne.appendChild( n );

				// `mousedown` et non `click` : le `blur` du champ ferme la
				// liste avant qu'un `click` n'ait le temps d'arriver.
				ligne.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					choisit( p );
				} );

				liste.appendChild( ligne );
			} );

			liste.hidden = false;
			saisie.setAttribute( 'aria-expanded', 'true' );
		}

		saisie.addEventListener( 'input', ouvre );
		saisie.addEventListener( 'focus', ouvre );
		saisie.addEventListener( 'blur', function () { window.setTimeout( ferme, 120 ); } );

		saisie.addEventListener( 'keydown', function ( e ) {
			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();

				if ( liste.hidden ) { ouvre(); }

				surligne( vise + 1 );
				return;
			}

			if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				surligne( vise - 1 );
				return;
			}

			if ( 'Escape' === e.key && ! liste.hidden ) {
				e.preventDefault();
				ferme();
				return;
			}

			// ⚠️ Entrée ne valide QUE si une ligne est visée. Sans cette
			// condition, appuyer sur Entrée pour passer à l'étape suivante
			// remplaçait le pays tapé par le premier de la liste.
			if ( 'Enter' === e.key && ! liste.hidden && vise >= 0 ) {
				e.preventDefault();
				liste.querySelectorAll( '.afk-pays-ligne' )[ vise ]
					.dispatchEvent( new MouseEvent( 'mousedown' ) );
			}
		} );
	}

	/**
	 * Un numéro de téléphone : l'indicatif à gauche, le numéro à droite.
	 *
	 * ⚠️ Une seule ligne ne suffisait pas.
	 *
	 * Le champ acceptait « +225 07 00 00 00 00 » à condition que
	 * l'artiste connaisse et tape l'indicatif. La plupart écrivaient leur
	 * numéro local — « 07 00 00 00 00 » — parfaitement lisible à Abidjan
	 * et inutilisable depuis Paris. Rien ne le leur disait, et le
	 * secrétariat récupérait des numéros qu'il ne pouvait pas composer.
	 *
	 * L'indicatif devient donc un choix, et le numéro ce qu'il aurait
	 * toujours dû être : les chiffres qu'on donne à quelqu'un du pays.
	 *
	 * ⚠️ La valeur écrite reste **une seule chaîne**, indicatif compris.
	 * C'est un champ texte sur AFRICART, lu tel quel sur la fiche : y
	 * déposer deux valeurs demanderait un second champ que le site n'a
	 * pas, et les anciens numéros deviendraient incomparables.
	 *
	 * ⚠️ Aucun indicatif par défaut. Poser « +225 » d'office signerait
	 * ivoirien le numéro d'un artiste malien qui n'aurait pas pensé à
	 * regarder. Le dernier choisi est en revanche mémorisé : celui qui
	 * dépose dix œuvres ne le choisit qu'une fois.
	 */
	function champTelephone( saisie, champ ) {
		var pays = ( window.AFK_PAYS || [] ).filter( function ( p ) { return !! p.t; } );

		if ( ! pays.length ) {
			// Sans table d'indicatifs, on garde la ligne unique plutôt que
			// d'offrir un choix vide.
			return miseEnFormeSimple( saisie );
		}

		var MEMOIRE = 'afkIndicatif';

		var enveloppe = document.createElement( 'div' );
		enveloppe.className = 'afk-tel';
		saisie.parentNode.insertBefore( enveloppe, saisie );

		// La saisie d'origine devient le porteur de la valeur complète :
		// tout le module lit les valeurs par cette classe, et deux
		// mécanismes de lecture sur le même champ finiraient par diverger.
		saisie.type = 'hidden';
		saisie.removeAttribute( 'inputmode' );
		saisie.removeAttribute( 'placeholder' );

		var choix = document.createElement( 'button' );
		choix.type = 'button';
		choix.className = 'afk-tel-pays';
		choix.setAttribute( 'aria-haspopup', 'listbox' );
		choix.setAttribute( 'aria-expanded', 'false' );

		var drapeau = document.createElement( 'span' );
		drapeau.className = 'afk-tel-drapeau';
		drapeau.setAttribute( 'aria-hidden', 'true' );

		var code = document.createElement( 'span' );
		code.className = 'afk-tel-code';

		choix.appendChild( drapeau );
		choix.appendChild( code );

		var numero = document.createElement( 'input' );
		numero.type = 'tel';
		numero.id = saisie.id;
		numero.className = 'afk-tel-numero';
		numero.autocomplete = 'tel-national';
		numero.setAttribute( 'inputmode', 'tel' );
		numero.setAttribute( 'aria-describedby', saisie.getAttribute( 'aria-describedby' ) || '' );
		numero.placeholder = '07 00 00 00 00';

		if ( champ.required ) {
			numero.setAttribute( 'aria-required', 'true' );
		}

		// ⚠️ L'identifiant part du porteur : deux éléments ne peuvent pas
		// le partager, et c'est le champ visible que l'étiquette doit
		// atteindre quand on la touche.
		saisie.removeAttribute( 'id' );

		var liste = document.createElement( 'ul' );
		liste.className = 'afk-tel-liste';
		liste.setAttribute( 'role', 'listbox' );
		liste.hidden = true;

		var cherche = document.createElement( 'input' );
		cherche.type = 'text';
		cherche.className = 'afk-tel-cherche';
		cherche.placeholder = 'Chercher un pays…';
		cherche.autocomplete = 'off';

		var tiroir = document.createElement( 'div' );
		tiroir.className = 'afk-tel-tiroir';
		tiroir.hidden = true;
		tiroir.appendChild( cherche );
		tiroir.appendChild( liste );

		enveloppe.appendChild( choix );
		enveloppe.appendChild( numero );
		enveloppe.appendChild( saisie );
		enveloppe.appendChild( tiroir );

		var retenu = null;

		function plie( t ) {
			return String( t || '' ).normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
				.toLowerCase().replace( /[^a-z0-9+]+/g, '' );
		}

		/** Les chiffres, groupés par deux depuis la droite. */
		function espace( brut ) {
			var chiffres = String( brut || '' ).replace( /\D+/g, '' ).slice( 0, 14 );
			var groupes  = [];

			for ( var fin = chiffres.length; fin > 0; fin -= 2 ) {
				groupes.unshift( chiffres.slice( Math.max( 0, fin - 2 ), fin ) );
			}

			return groupes.join( ' ' );
		}

		function poser() {
			var chiffres = numero.value.replace( /\D+/g, '' );

			saisie.value = chiffres
				? ( ( retenu ? retenu.t + ' ' : '' ) + espace( chiffres ) )
				: '';

			saisie.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			saisie.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function montreLePays() {
			drapeau.textContent = retenu ? retenu.d : '🌍';
			code.textContent = retenu ? retenu.t : 'Indicatif';
			choix.setAttribute( 'aria-label',
				retenu ? ( 'Indicatif : ' + retenu.n + ' ' + retenu.t ) : 'Choisir l’indicatif du pays' );
		}

		function retient( p, memoriser ) {
			retenu = p;
			montreLePays();

			if ( memoriser && p ) {
				try {
					window.localStorage.setItem( MEMOIRE, p.c );
				} catch ( e ) { /* sans mémoire, on rechoisira */ }
			}
		}

		/**
		 * Sépare une valeur déjà écrite en indicatif et numéro.
		 *
		 * ⚠️ Le plus long indicatif d'abord. « +1 » et « +1246 » — la
		 * Barbade — commencent pareil : trié du plus court au plus long,
		 * tout numéro nord-américain aurait été rendu barbadien.
		 */
		function separe( valeur ) {
			var brut = String( valeur || '' ).trim();

			if ( 0 !== brut.indexOf( '+' ) ) {
				return { pays: null, numero: brut };
			}

			var compact = '+' + brut.replace( /\D+/g, '' );
			var trouve = null;

			pays.forEach( function ( p ) {
				if ( 0 === compact.indexOf( p.t ) && ( ! trouve || p.t.length > trouve.t.length ) ) {
					trouve = p;
				}
			} );

			if ( ! trouve ) {
				return { pays: null, numero: brut };
			}

			return { pays: trouve, numero: compact.slice( trouve.t.length ) };
		}

		function ferme() {
			tiroir.hidden = true;
			liste.hidden = true;
			choix.setAttribute( 'aria-expanded', 'false' );
		}

		function remplitLaListe() {
			var quoi = plie( cherche.value );

			liste.textContent = '';

			var vus = 0;

			pays.forEach( function ( p ) {
				if ( vus >= 40 ) {
					return;
				}

				// Le nom ou l'indicatif : celui qui connaît « +226 » ne
				// doit pas avoir à se rappeler comment s'écrit son pays.
				if ( quoi && plie( p.n ).indexOf( quoi ) === -1 && plie( p.t ).indexOf( quoi ) === -1 ) {
					return;
				}

				vus++;

				var ligne = document.createElement( 'li' );
				ligne.className = 'afk-tel-ligne';
				ligne.setAttribute( 'role', 'option' );
				ligne.setAttribute( 'aria-selected', retenu && retenu.c === p.c ? 'true' : 'false' );

				var d = document.createElement( 'span' );
				d.className = 'afk-tel-drapeau';
				d.setAttribute( 'aria-hidden', 'true' );
				d.textContent = p.d;

				var n = document.createElement( 'span' );
				n.className = 'afk-tel-nom';
				n.textContent = p.n;

				var t = document.createElement( 'span' );
				t.className = 'afk-tel-indicatif';
				t.textContent = p.t;

				ligne.appendChild( d );
				ligne.appendChild( n );
				ligne.appendChild( t );

				ligne.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					retient( p, true );
					poser();
					ferme();
					numero.focus();
				} );

				liste.appendChild( ligne );
			} );

			liste.hidden = ! liste.children.length;
		}

		choix.addEventListener( 'click', function () {
			if ( ! tiroir.hidden ) {
				ferme();
				return;
			}

			cherche.value = '';
			remplitLaListe();
			tiroir.hidden = false;
			choix.setAttribute( 'aria-expanded', 'true' );
			cherche.focus();
		} );

		cherche.addEventListener( 'input', remplitLaListe );

		cherche.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				ferme();
				choix.focus();
				return;
			}

			// Entrée retient la première proposition : c'est le geste
			// attendu après avoir tapé trois lettres.
			if ( 'Enter' === e.key ) {
				e.preventDefault();

				var premiere = liste.querySelector( '.afk-tel-ligne' );

				if ( premiere ) {
					premiere.dispatchEvent( new MouseEvent( 'mousedown' ) );
				}
			}
		} );

		cherche.addEventListener( 'blur', function () {
			window.setTimeout( function () {
				if ( ! tiroir.contains( document.activeElement ) ) {
					ferme();
				}
			}, 120 );
		} );

		numero.addEventListener( 'input', function () {
			var avant = numero.value.slice( 0, numero.selectionStart || 0 ).replace( /\D+/g, '' ).length;
			var neuf  = espace( numero.value );

			if ( neuf !== numero.value ) {
				numero.value = neuf;

				// ⚠️ Le curseur revient sur le même chiffre. Sans cela,
				// corriger au milieu le renvoyait à la fin à chaque frappe
				// et l'on tapait la suite à l'envers.
				try {
					var vus = 0;
					var ou  = neuf.length;

					for ( var i = 0; i < neuf.length; i++ ) {
						if ( /\d/.test( neuf[ i ] ) && ++vus === avant ) {
							ou = i + 1;
							break;
						}
					}

					numero.setSelectionRange( avant ? ou : 0, avant ? ou : 0 );
				} catch ( e ) { /* certains navigateurs refusent sur `tel` */ }
			}

			poser();
		} );

		// Une valeur déjà écrite : on la sépare pour la reposer des deux
		// côtés. Sans cela, rouvrir une œuvre montrait un champ vide alors
		// que le numéro était bien là.
		var depart = separe( saisie.value );

		if ( depart.pays ) {
			retient( depart.pays, false );
		} else {
			try {
				var dernier = window.localStorage.getItem( MEMOIRE );

				pays.forEach( function ( p ) {
					if ( p.c === dernier ) {
						retient( p, false );
					}
				} );
			} catch ( e ) { /* sans mémoire, on choisira */ }
		}

		// Une valeur posée de l'extérieur — annulation, reprise du profil —
		// arrive dans le porteur : les deux champs visibles, dessinés à
		// côté, ne s'en apercevraient pas.
		document.addEventListener( 'afkValeursPosees', function () {
			var revu = separe( saisie.value );

			retient( revu.pays, false );
			numero.value = espace( revu.numero );
		} );

		montreLePays();
		numero.value = espace( depart.numero );

		// Reposer la valeur complète : elle vient peut-être d'un ancien
		// format, et doit repartir dans le nôtre.
		if ( numero.value ) {
			poser();
		}
	}

	/**
	 * Le repli : une seule ligne, mise en forme pendant la frappe.
	 *
	 * Sert quand la table des indicatifs n'a pas été chargée — un fichier
	 * manquant, un cache partiel. Mieux vaut un champ qui marche moins
	 * bien qu'un champ qui ne marche pas.
	 */
	function miseEnFormeSimple( saisie ) {
		if ( ! saisie.placeholder ) {
			saisie.placeholder = 'Ex. : +225 07 00 00 00 00';
		}

		saisie.addEventListener( 'input', function () {
			var chiffres = saisie.value.replace( /\D+/g, '' ).slice( 0, 15 );
			var plus = 0 === saisie.value.indexOf( '+' );

			saisie.value = ( plus ? '+' : '' ) + chiffres;
		} );
	}

	/**
	 * L'exemple à poser en filigrane, s'il y en a un pour ce champ.
	 *
	 * @return {string}
	 */
	function exemplePour( champ ) {
		var nom = String( champ.label || '' );

		for ( var i = 0; i < EXEMPLES.length; i++ ) {
			if ( EXEMPLES[ i ].motif.test( nom ) ) {
				return EXEMPLES[ i ].invite;
			}
		}

		return '';
	}

	/**
	 * Une question fermée : deux boutons, et rien à ouvrir.
	 *
	 * ⚠️ La valeur reste portée par un champ caché de classe
	 * `afk-champ-saisie`. Tout le reste du module — enregistrement,
	 * validation, conditions, mise en file — lit les valeurs par cette
	 * classe : changer ce contrat ici aurait cassé six mécanismes à
	 * distance, sans erreur visible. C'est le même procédé que le champ
	 * photo, pour la même raison.
	 *
	 * ⚠️ Aucune réponse n'est cochée d'avance. Pré-cocher « Non » ferait
	 * déposer un refus que personne n'a formulé — sur « Acceptez-vous que
	 * nous partagions la valeur estimée », ce serait grave.
	 */
	function champBinaire( champ, valeur, id, paire, decrit ) {
		var groupe = document.createElement( 'div' );
		groupe.className = 'afk-choix';
		groupe.setAttribute( 'role', 'radiogroup' );
		groupe.setAttribute( 'aria-labelledby', id + '-l' );

		var porteur = document.createElement( 'input' );
		porteur.type = 'hidden';
		porteur.id = id;
		porteur.className = 'afk-champ-saisie';
		porteur.name = champ.key;
		porteur.setAttribute( 'data-afk-type', champ.type );
		porteur.setAttribute( 'aria-describedby', decrit.join( ' ' ) );
		porteur.value = null === valeur || undefined === valeur ? '' : String( valeur );

		[ { v: paire.oui, t: 'Oui' }, { v: paire.non, t: 'Non' } ].forEach( function ( o, rang ) {
			var bouton = document.createElement( 'button' );
			bouton.type = 'button';
			bouton.className = 'afk-choix-bouton';
			bouton.setAttribute( 'role', 'radio' );
			bouton.setAttribute( 'data-afk-valeur', o.v );
			bouton.textContent = o.t;

			function poser( actif ) {
				bouton.setAttribute( 'aria-checked', actif ? 'true' : 'false' );
				bouton.classList.toggle( 'afk-choix-actif', actif );

				// Un seul des deux est atteignable par tabulation : c'est
				// la règle d'un groupe de boutons radio, sans quoi la
				// touche de tabulation traverse chaque option une à une.
				bouton.tabIndex = actif || ( ! porteur.value && 0 === rang ) ? 0 : -1;
			}

			poser( porteur.value === String( o.v ) );

			bouton.addEventListener( 'click', function () {
				// ⚠️ Recliquer efface la réponse. Sans cela, une question
				// facultative touchée par mégarde ne pouvait plus être
				// laissée vide : il fallait recharger le formulaire.
				porteur.value = porteur.value === String( o.v ) ? '' : String( o.v );

				Array.prototype.forEach.call( groupe.querySelectorAll( '.afk-choix-bouton' ), function ( b ) {
					var sien = porteur.value === b.getAttribute( 'data-afk-valeur' );
					b.setAttribute( 'aria-checked', sien ? 'true' : 'false' );
					b.classList.toggle( 'afk-choix-actif', sien );
					b.tabIndex = sien ? 0 : -1;
				} );

				if ( ! porteur.value ) {
					groupe.querySelector( '.afk-choix-bouton' ).tabIndex = 0;
				}

				porteur.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				porteur.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );

			// Les flèches passent d'une réponse à l'autre, comme sur un
			// vrai groupe de boutons radio.
			bouton.addEventListener( 'keydown', function ( e ) {
				if ( [ 'ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp' ].indexOf( e.key ) === -1 ) {
					return;
				}

				e.preventDefault();

				var tous = groupe.querySelectorAll( '.afk-choix-bouton' );
				var autre = tous[ 0 ] === bouton ? tous[ 1 ] : tous[ 0 ];

				autre.focus();
				autre.click();
			} );

			groupe.appendChild( bouton );
		} );

		// Une annulation repose la valeur d'origine dans le porteur : les
		// deux boutons, dessines a cote, doivent la refleter.
		document.addEventListener( 'afkValeursPosees', function () {
			Array.prototype.forEach.call( groupe.querySelectorAll( '.afk-choix-bouton' ), function ( b, rang ) {
				var sien = porteur.value === b.getAttribute( 'data-afk-valeur' );

				b.setAttribute( 'aria-checked', sien ? 'true' : 'false' );
				b.classList.toggle( 'afk-choix-actif', sien );
				b.tabIndex = sien || ( ! porteur.value && 0 === rang ) ? 0 : -1;
			} );
		} );

		groupe.appendChild( porteur );

		return groupe;
	}

	/**
	 * Un accord à donner : une case, pas un menu.
	 */
	function champAccord( champ, valeur, id, oui, decrit ) {
		var enveloppe = document.createElement( 'label' );
		enveloppe.className = 'afk-accord';

		var case_ = document.createElement( 'input' );
		case_.type = 'checkbox';
		case_.className = 'afk-accord-case';
		case_.checked = String( valeur || '' ) === String( oui );

		var porteur = document.createElement( 'input' );
		porteur.type = 'hidden';
		porteur.id = id;
		porteur.className = 'afk-champ-saisie';
		porteur.name = champ.key;
		porteur.setAttribute( 'data-afk-type', champ.type );
		porteur.setAttribute( 'aria-describedby', decrit.join( ' ' ) );
		porteur.value = case_.checked ? String( oui ) : '';

		var dit = document.createElement( 'span' );
		dit.className = 'afk-accord-texte';
		dit.textContent = 'Je confirme et j’accepte';

		case_.addEventListener( 'change', function () {
			porteur.value = case_.checked ? String( oui ) : '';
			porteur.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			porteur.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		document.addEventListener( 'afkValeursPosees', function () {
			case_.checked = String( porteur.value || '' ) === String( oui );
		} );

		enveloppe.appendChild( case_ );
		enveloppe.appendChild( dit );
		enveloppe.appendChild( porteur );

		return enveloppe;
	}

	function champFichier( champ, valeur ) {
		var enveloppe = document.createElement( 'div' );
		enveloppe.className = 'afk-fichiers';

		var porteur = document.createElement( 'input' );
		porteur.type = 'hidden';
		porteur.className = 'afk-champ-saisie';
		porteur.name = champ.key;
		porteur.setAttribute( 'data-afk-type', champ.type );
		porteur.value = JSON.stringify( identifiants( valeur ) );

		var grille = document.createElement( 'div' );
		grille.className = 'afk-fichiers-grille';

		var choix = document.createElement( 'input' );
		choix.type = 'file';
		choix.className = 'afk-fichiers-choix';
		choix.accept = 'image/jpeg,image/png,image/webp,image/avif';
		choix.multiple = true;
		choix.id = 'afk-fichier-' + champ.key;

		var ajout = document.createElement( 'label' );
		ajout.className = 'afk-fichiers-ajout';
		ajout.setAttribute( 'for', choix.id );
		ajout.innerHTML = '<span class="afk-fichiers-plus" aria-hidden="true">+</span>'
			+ '<span class="afk-fichiers-invite">Ajouter des photos</span>'
			+ '<span class="afk-fichiers-glisser">ou déposez-les ici</span>';

		var etat = document.createElement( 'p' );
		etat.className = 'afk-fichiers-etat';

		// ⚠️ Annoncé, pas seulement affiché. Un envoi qui commence et
		// finit sans un mot laisse celui qui n'y voit pas devant un écran
		// muet ; `status` le dit sans interrompre la lecture en cours.
		etat.setAttribute( 'role', 'status' );
		etat.setAttribute( 'role', 'status' );

		/**
		 * Les adresses d'aperçu créées pour cet écran.
		 *
		 * ⚠️ Une `blob:` non révoquée retient la photo entière en mémoire.
		 * Sur une galerie de douze visuels redessinée à chaque ajout, la
		 * fuite se compte en dizaines de mégaoctets — et l'onglet finit
		 * par être tué par le système, saisie comprise.
		 */
		var ephemeres = [];

		function oublieLesApercus() {
			ephemeres.forEach( function ( u ) {
				try {
					window.URL.revokeObjectURL( u );
				} catch ( e ) { /* déjà révoquée */ }
			} );
			ephemeres = [];
		}

		/** L'image à montrer pour un identifiant, si on l'a. */
		async function apercuDe( id ) {
			var trace = await DB.lire( 'medias', id );

			// ⚠️ IndexedDB distingue 42 de « 42 ». Une photo envoyée
			// depuis cet appareil est rangée sous le nombre rendu par le
			// site ; une photo rapatriée, sous ce que porte le champ.
			// Chercher les deux coûte une lecture et évite une vignette
			// manquante sans la moindre explication.
			if ( ! trace && 'number' === typeof id ) {
				trace = await DB.lire( 'medias', String( id ) );
			} else if ( ! trace && /^[0-9]+$/.test( String( id ) ) ) {
				trace = await DB.lire( 'medias', parseInt( id, 10 ) );
			}

			// ⚠️ Ce qui est sur l'appareil passe avant ce qui est sur le
			// réseau, toujours. `blob` pour une photo qui attend son
			// envoi, `apercu` pour une photo déjà déposée : dans les deux
			// cas la vignette s'affiche sans dépendre de la connexion.
			var local = trace && ( trace.blob || trace.apercu );

			if ( local ) {
				var u = window.URL.createObjectURL( local );
				ephemeres.push( u );
				return u;
			}

			if ( trace && trace.url ) {
				return trace.url;
			}

			// Rien en réserve : l'adresse que porte l'œuvre elle-même.
			var ou = VISUELS_CONNUS[ String( id ) ];

			return ( ou && ( ou.apercu || ou.url ) ) || '';
		}

		async function dessine() {
			oublieLesApercus();

			Array.prototype.forEach.call( grille.querySelectorAll( '.afk-fichier' ), function ( n ) {
				n.remove();
			} );

			var liste = JSON.parse( porteur.value || '[]' );

			for ( var rang = 0; rang < liste.length; rang++ ) {
				grille.insertBefore( await vignette( liste[ rang ], rang ), ajout );
			}

			// La consigne de dépôt n'a plus à s'étaler quand des photos
			// occupent déjà la grille : la place revient aux visuels.
			ajout.classList.toggle( 'afk-fichiers-ajout-compact', liste.length > 0 );
		}

		async function vignette( id, rang ) {
			var figure = document.createElement( 'figure' );
			figure.className = 'afk-fichier';

			var attente = 'string' === typeof id && 0 === id.indexOf( 'local:' );
			var source  = await apercuDe( id );

			if ( source ) {
				var image = document.createElement( 'img' );
				image.className = 'afk-fichier-image';
				image.src = source;
				image.alt = '';
				image.loading = 'lazy';

				// Dernier filet : une réserve locale corrompue ne doit pas
				// laisser un carré cassé là où l'adresse du serveur
				// marcherait encore.
				image.addEventListener( 'error', async function () {
					var trace = await DB.lire( 'medias', id );

					if ( trace && trace.url && image.src !== trace.url ) {
						image.src = trace.url;
					}
				} );

				figure.appendChild( image );
			} else {
				// Pas d'aperçu sous la main — une photo déposée depuis un
				// autre appareil, par exemple. On montre qu'elle existe
				// plutôt que de laisser un trou dans la galerie.
				var sansImage = document.createElement( 'span' );
				sansImage.className = 'afk-fichier-sansimage';
				sansImage.textContent = 'Visuel #' + id;
				figure.appendChild( sansImage );
			}

			if ( attente ) {
				// Une photo gardée ici se distingue d'une photo déposée :
				// l'artiste doit savoir laquelle n'est pas encore partie.
				var badge = document.createElement( 'figcaption' );
				badge.className = 'afk-fichier-badge';
				badge.textContent = 'en attente';
				figure.appendChild( badge );
			}

			var retirer = document.createElement( 'button' );
			retirer.type = 'button';
			retirer.className = 'afk-fichier-retirer';
			retirer.setAttribute( 'aria-label', 'Retirer ce visuel' );
			retirer.textContent = '×';
			retirer.addEventListener( 'click', async function () {
				var courante = JSON.parse( porteur.value || '[]' );
				var partie   = courante.splice( rang, 1 )[ 0 ];

				porteur.value = JSON.stringify( courante );
				dessine();
				porteur.dispatchEvent( new Event( 'input', { bubbles: true } ) );

				await oubliePhoto( partie );
			} );

			figure.appendChild( retirer );

			return figure;
		}

		/** Traite une sélection, d'où qu'elle vienne — bouton ou glisser. */
		/**
		 * Dit où en est le dépôt d'un visuel.
		 *
		 * ⚠️ Une rondelle qui tourne, et non plus une phrase seule.
		 *
		 * « Envoi de photo.png… (1/2) » ne bougeait pas d'un pixel pendant
		 * les dix secondes d'un envoi sur réseau mobile : rien ne
		 * distinguait un transfert en cours d'un écran figé, et l'artiste
		 * touchait le bouton une seconde fois. Une rondelle dit que
		 * quelque chose se passe, ce qu'aucun texte immobile ne peut dire.
		 *
		 * ⚠️ Celle du module, pas une seconde : `.afk-rondelle` tourne
		 * déjà sur les cartes, dans la fiche et dans le message flottant.
		 * Deux rondelles de dessins différents dans la même application se
		 * liraient comme deux attentes de natures différentes.
		 *
		 * @param {string} texte Ce qu'on annonce. Vide pour effacer.
		 * @param {string} [ton] `encours` ou `echec`.
		 */
		function dit( texte, ton ) {
			etat.textContent = '';
			etat.classList.remove( 'afk-fichiers-echec', 'afk-fichiers-encours' );

			if ( ! texte ) {
				return;
			}

			if ( 'encours' === ton ) {
				etat.classList.add( 'afk-fichiers-encours' );

				var tourne = document.createElement( 'span' );
				tourne.className = 'afk-rondelle';
				tourne.setAttribute( 'aria-hidden', 'true' );
				etat.appendChild( tourne );
			} else if ( 'echec' === ton ) {
				etat.classList.add( 'afk-fichiers-echec' );
			}

			etat.appendChild( document.createTextNode( texte ) );
		}

		async function accueille( fichiers ) {
			if ( ! fichiers.length ) {
				return;
			}

			// ⚠️ Un dépôt part **dès la sélection**. Dans l'éditeur, chaque
			// essai de l'auteur créerait une vraie pièce jointe dans la
			// médiathèque du site, sans œuvre pour la porter.
			if ( CFG.apercu ) {
				dit( 'Aperçu : le dépôt de visuels est désactivé dans l’éditeur.' );
				choix.value = '';
				return;
			}

			choix.disabled = true;
			dit( '' );

			for ( var i = 0; i < fichiers.length; i++ ) {
				var f = fichiers[ i ];

				if ( f.size > POIDS_MAX ) {
					dit( f.name + ' dépasse ' + Math.round( POIDS_MAX / 1048576 ) + ' Mo.', 'echec' );
					break;
				}

				var resultat;

				if ( window.navigator.onLine ) {
					dit( 'Envoi de ' + f.name + '… (' + ( i + 1 ) + '/' + fichiers.length + ')', 'encours' );
					resultat = await televerse( f );

					// ⚠️ Le réseau a lâché pendant l'envoi. Ce n'est pas un
					// échec : c'est exactement le cas que le hors-connexion
					// sait traiter. On garde la photo ici, elle partira avec
					// l'œuvre. Échouer aurait obligé l'artiste à la
					// re-sélectionner une fois revenu en couverture.
					if ( resultat.reseau ) {
						if ( window.afkSync ) {
							window.afkSync.injoignable();
						}

						resultat = await garde( f );

						if ( ! resultat.erreur ) {
							dit( 'Réseau perdu — photo gardée sur cet appareil.' );
							resultat.attendue = true;
						}
					}

					// ⚠️ L'adresse **et** un aperçu réduit. L'adresse seule
					// suffisait tant qu'on avait du réseau ; hors connexion
					// elle ne mène nulle part, et la galerie se remplissait
					// d'images cassées pour des photos pourtant bien
					// déposées. L'artiste croyait les avoir perdues.
					if ( ! resultat.erreur && resultat.id ) {
						await DB.ecrire( 'medias', {
							id: resultat.id,
							url: resultat.url || '',
							apercu: await DB.apercu( f ),
							depuis: Date.now()
						} );
					}
				} else {
					// ⚠️ Hors connexion, la photo est **gardée**, pas
					// perdue. Elle porte un identifiant provisoire que la
					// file remplacera par le vrai au moment de l'envoi.
					dit( 'Photo gardée sur cet appareil.' );
					resultat = await garde( f );
					resultat.attendue = ! resultat.erreur;
				}

				if ( resultat.erreur ) {
					// Le message vient du serveur quand il y en a un : c'est
					// lui qui sait pourquoi il refuse, et le paraphraser
					// mentirait.
					dit( resultat.erreur, 'echec' );
					break;
				}

				// ⚠️ Ni identifiant, ni refus : on n'invente rien.
				//
				// Une réponse mal formée — un serveur qui répond 200 sans
				// `id` — faisait pousser `undefined` dans la liste des
				// visuels. La vignette affichait « Visuel #null », le champ
				// portait `[null]`, et l'envoi aurait emporté cette valeur
				// jusqu'à AFRICART. Constaté au banc.
				if ( ! resultat.id ) {
					dit( 'Cette photo n’a pas pu être enregistrée. Réessayez.', 'echec' );
					break;
				}

				var liste = JSON.parse( porteur.value || '[]' );
				liste.push( resultat.id );
				porteur.value = JSON.stringify( liste );
				await dessine();

				// ⚠️ Effacée dès que l'envoi aboutit. Elle restait sinon
				// figée sur « Envoi de photo.png… (1/1) » sous une vignette
				// pourtant bien arrivée — constaté à la capture, et de
				// nature à faire attendre indéfiniment.
				if ( ! resultat.attendue ) {
					dit( '' );
				}
			}

			choix.value = '';
			choix.disabled = false;
			porteur.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}

		choix.addEventListener( 'change', function () {
			accueille( Array.prototype.slice.call( choix.files || [] ) );
		} );

		// Le glisser-déposer, sur ordinateur. Il ne remplace pas le
		// bouton — au doigt, il n'existe pas.
		[ 'dragenter', 'dragover' ].forEach( function ( nom ) {
			enveloppe.addEventListener( nom, function ( e ) {
				e.preventDefault();
				enveloppe.classList.add( 'afk-fichiers-survol' );
			} );
		} );

		[ 'dragleave', 'drop' ].forEach( function ( nom ) {
			enveloppe.addEventListener( nom, function () {
				enveloppe.classList.remove( 'afk-fichiers-survol' );
			} );
		} );

		enveloppe.addEventListener( 'drop', function ( e ) {
			e.preventDefault();

			var tombes = Array.prototype.slice.call( ( e.dataTransfer && e.dataTransfer.files ) || [] );

			accueille( tombes.filter( function ( f ) {
				return 0 === String( f.type || '' ).indexOf( 'image/' );
			} ) );
		} );

		/**
		 * La file vient d'envoyer une photo restée en attente.
		 *
		 * Sans cela l'artiste continuerait de voir « en attente » sous une
		 * photo déjà déposée — et douterait, à raison, de ce qu'il voit.
		 */
		// Les adresses des visuels viennent d'arriver : les vignettes
		// qui affichaient « Visuel #5462 » ont maintenant de quoi se
		// montrer.
		document.addEventListener( 'afkVisuelsConnus', function () {
			dessine();
		} );

		// Une annulation a repose la liste d'origine dans le porteur : la
		// grille, dessinee a cote, ne s'en apercevrait pas.
		document.addEventListener( 'afkValeursPosees', function () {
			dessine();
		} );

		document.addEventListener( 'afkPhotoResolue', function ( e ) {
			var d = e.detail || {};
			var liste;

			try {
				liste = JSON.parse( porteur.value || '[]' );
			} catch ( x ) {
				return;
			}

			var i = liste.indexOf( d.local );

			if ( i < 0 ) {
				return;
			}

			liste[ i ]    = d.id;
			porteur.value = JSON.stringify( liste );
			dessine();
			porteur.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		grille.appendChild( ajout );
		enveloppe.appendChild( porteur );
		enveloppe.appendChild( grille );
		enveloppe.appendChild( choix );
		enveloppe.appendChild( etat );

		dessine();

		return enveloppe;
	}

	/**
	 * Oublie les octets d'une photo qu'on vient de retirer.
	 *
	 * ⚠️ Sans cela, ils restaient sur l'appareil pour toujours.
	 *
	 * Retirer un visuel enlevait son identifiant de la liste du champ, et
	 * rien d'autre : la photo elle-même dormait dans la réserve, réclamée
	 * par personne, comptée par le message flottant. L'artiste lisait
	 * « 1 photo gardée ici » sur un compte dont tout avait pourtant été
	 * envoyé, sans aucun geste pour la faire disparaître. Signalé au test.
	 *
	 * ⚠️ Seulement nos fichiers locaux, et seulement si aucun envoi ne
	 * les réclame. Une photo déjà en file part avec son œuvre : effacer
	 * ses octets ici la bloquerait sur « Photo perdue sur l'appareil ».
	 * Un identifiant numérique, lui, désigne un visuel du site — ce n'est
	 * pas à ce champ de le supprimer.
	 *
	 * @param {string} id Identifiant retiré du champ.
	 */
	async function oubliePhoto( id ) {
		if ( 'string' !== typeof id || 0 !== id.indexOf( 'local:' ) || ! DB ) {
			return;
		}

		try {
			var file = await DB.tout( 'file' );

			var reclamee = ( file || [] ).some( function ( a ) {
				var champs = ( a.charge && a.charge.champs ) || {};

				return Object.keys( champs ).some( function ( cle ) {
					return Array.isArray( champs[ cle ] ) && champs[ cle ].indexOf( id ) !== -1;
				} );
			} );

			if ( reclamee ) {
				return;
			}

			await DB.effacer( 'medias', id );

			// Le message flottant compte les photos en attente : il doit
			// apprendre celle-ci tout de suite, sinon il annonce encore ce
			// qui n'existe plus.
			if ( window.afkSync && window.afkSync.annoncer ) {
				window.afkSync.annoncer();
			}
		} catch ( e ) { /* réserve illisible : on ne force rien */ }
	}

	/**
	 * Garde une photo sur l'appareil, en attendant le réseau.
	 *
	 * L'identifiant rendu est une chaîne `local:…`, jamais un nombre :
	 * la distinction saute aux yeux à la lecture d'une file, et le
	 * serveur ne pourrait de toute façon rien en faire.
	 */
	async function garde( fichier ) {
		try {
			var deja = await DB.tout( 'medias' );

			// ⚠️ On ne compte QUE les photos qui attendent leur envoi.
			//
			// Le plafond portait sur la réserve entière, qui contient tout
			// autre chose : les aperçus des œuvres du site, rapatriés pour
			// la consultation hors connexion, et les correspondances
			// laissées par les envois passés. Une réserve normalement
			// garnie — elle en accepte cent vingt — dépassait donc douze
			// bien avant qu'une seule photo n'attende, et l'artiste ne
			// pouvait plus joindre AUCUNE photo hors connexion. Vérifié au
			// banc : quinze aperçus, zéro photo en attente, et l'ajout
			// refusé.
			//
			// Ce qui occupe vraiment la place et ne peut pas être
			// retéléchargé, c'est le fichier lui-même : `blob`.
			var enAttente = deja.filter( function ( m ) { return !! m.blob; } );

			if ( enAttente.length >= EN_ATTENTE_MAX ) {
				return { erreur: 'Trop de photos en attente. Reconnectez-vous pour les envoyer.' };
			}

			var id = 'local:' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 8 );

			await DB.ecrire( 'medias', {
				id: id,
				nom: fichier.name,
				type: fichier.type,
				poids: fichier.size,
				blob: fichier,
				depuis: Date.now()
			} );

			if ( window.afkJournal ) {
				window.afkJournal.note( 'PHOTO GARDEE', {
					id: id,
					nom: fichier.name,
					type: fichier.type || '(sans type)',
					poids: fichier.size,
					genre: ( fichier instanceof window.File ) ? 'File' : 'Blob'
				} );
			}

			return { id: id };
		} catch ( e ) {
			// Stockage refusé — navigation privée, quota atteint. Le dire
			// vaut mieux que de laisser croire que la photo est gardée.
			return { erreur: 'Cet appareil ne peut pas conserver la photo.' };
		}
	}

	/** Identifiants portés par une valeur de champ fichier. */
	function identifiants( valeur ) {
		var liste = [];

		( Array.isArray( valeur ) ? valeur : [] ).forEach( function ( v ) {
			// Une photo en attente garde son identifiant provisoire tel
			// quel : c'est la file qui le résoudra.
			if ( 'string' === typeof v && 0 === v.indexOf( 'local:' ) ) {
				liste.push( v );
			} else if ( 'number' === typeof v ) {
				liste.push( v );
			} else if ( v && v.file_id ) {
				liste.push( parseInt( v.file_id, 10 ) );
			} else if ( v && v.id ) {
				liste.push( parseInt( v.id, 10 ) );
			}
		} );

		return liste.filter( function ( x ) {
			return 'string' === typeof x ? 0 === x.indexOf( 'local:' ) : x > 0;
		} );
	}

	/** Envoie un fichier, rend son identifiant ou un message d'échec. */
	async function televerse( fichier ) {
		if ( ! CFG.medias ) {
			return { erreur: 'Dépôt de visuel indisponible.' };
		}

		var corps = new FormData();
		corps.append( 'fichier', fichier );

		try {
			var r = await window.fetch( CFG.medias, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': CFG.nonce },
				body: corps
			} );

			var d = await r.json();

			if ( ! r.ok ) {
				return { erreur: ( d && d.message ) || 'Envoi refusé.' };
			}

			return { id: d.id, url: d.url };
		} catch ( e ) {
			// ⚠️ Distingué d'un refus du serveur. Un refus est définitif —
			// mauvais format, fichier trop lourd ; une coupure ne l'est
			// pas, et l'appelant doit pouvoir se rabattre sur la
			// conservation locale plutôt que de tout perdre.
			return { erreur: 'Réseau perdu.', reseau: true };
		}
	}

	/**
	 * Valeurs saisies, dans la forme que l'écriture attend.
	 *
	 * ⚠️ La taxonomie fait exception : elle s'écrit en **liste** de
	 * slugs, même pour un seul terme. Transmettre la chaîne seule ferait
	 * échouer l'assignation sans message — l'œuvre existerait, sans sa
	 * catégorie, et rien ne l'aurait signalé.
	 */
	function valeursDuFormulaire( form ) {
		var champs = {};

		Array.prototype.forEach.call( form.querySelectorAll( '.afk-champ-saisie' ), function ( s ) {
			// Un champ masqué par une condition n'est pas transmis : sa
			// valeur ne concerne plus l'œuvre telle qu'elle est décrite.
			var bloc = s.closest( '[data-afk-champ]' );

			if ( bloc && bloc.hidden ) {
				return;
			}

			var type = s.getAttribute( 'data-afk-type' );

			if ( 'taxonomy' === type ) {
				champs[ s.name ] = s.value ? [ s.value ] : [];
				return;
			}

			if ( 'image' === type || 'file' === type ) {
				try {
					champs[ s.name ] = JSON.parse( s.value || '[]' );
				} catch ( e ) {
					champs[ s.name ] = [];
				}
				return;
			}

			champs[ s.name ] = s.value;
		} );

		return champs;
	}

	/**
	 * Monte le formulaire, en étapes réelles.
	 *
	 * ⚠️ Trente-six champs d'affilée ne se remplissent pas jusqu'au bout.
	 * Le site les répartit en cinq écrans ; les empiler tous sur une même
	 * page donnait un mur que personne n'atteint. Une étape à la fois,
	 * donc, avec le rang toujours visible — sans quoi l'artiste ne sait
	 * pas s'il en a pour une minute ou pour un quart d'heure.
	 */
	function construire( conteneur, schemaData, brouillon, oeuvre ) {
		var valeurs = ( brouillon && brouillon.champs ) || {};
		var champs  = schemaData.champs || [];

		conteneur.innerHTML = '';

		var form = document.createElement( 'form' );
		form.className = 'afk-form';
		form.setAttribute( 'novalidate', 'novalidate' );

		var tete = document.createElement( 'div' );
		tete.className = 'afk-form-tete';

		var rang = document.createElement( 'p' );
		rang.className = 'afk-form-rang';

		// ⚠️ Une barre d'étapes qu'on peut toucher.
		//
		// Pour revenir sur le nom de l'artiste depuis la dernière étape,
		// il fallait trois « Précédent » — et pour repartir, trois
		// « Suivant », chacun exigeant que l'écran soit valide. Relire ce
		// qu'on a saisi devenait un parcours d'obstacles, et l'artiste
		// renonçait à vérifier.
		//
		// Ces boutons ne valident rien : on circule librement. C'est le
		// dépôt qui vérifie, une fois, à la fin — et lui seul.
		var pas = document.createElement( 'ol' );
		pas.className = 'afk-pas';

		var jauge = document.createElement( 'div' );
		jauge.className = 'afk-form-jauge';

		var barre = document.createElement( 'span' );
		jauge.appendChild( barre );

		// ⚠️ Une sortie, en haut, dès le premier écran.
		//
		// Pour quitter, il fallait traverser les quatre étapes jusqu'au
		// bouton de dépôt — et comme chaque « Suivant » exige que l'écran
		// soit valide, une saisie commencée sur l'étape 1 emprisonnait
		// l'artiste : il ne pouvait ni avancer ni sortir sans perdre le
		// fil. Or ce qu'il a tapé est déjà conservé ; il ne manquait qu'un
		// chemin pour s'en aller.
		var sortie = document.createElement( 'button' );
		sortie.type = 'button';
		sortie.className = 'afk-form-sortie';
		sortie.setAttribute( 'data-afk-sortie', '' );
		sortie.textContent = T.form_sortie || 'Enregistrer et revenir aux œuvres';

		if ( ! CFG.liste ) {
			// Sans destination, ce bouton ne mène nulle part : mieux vaut
			// pas de bouton qu'un bouton qui ne fait rien.
			sortie.hidden = true;
		}

		// ⚠️ Et une porte pour repartir les mains vides.
		//
		// « Enregistrer et revenir » conserve tout : c'est ce qu'on veut
		// presque toujours, mais pas quand on a ouvert la mauvaise œuvre
		// ou saisi n'importe quoi pour essayer. Il ne restait alors qu'à
		// aller supprimer le brouillon depuis la liste — en le retrouvant
		// parmi les autres.
		var renoncer = document.createElement( 'button' );
		renoncer.type = 'button';
		renoncer.className = 'afk-form-annuler';
		renoncer.setAttribute( 'data-afk-annuler', '' );
		renoncer.textContent = T.form_annuler || 'Annuler';

		// ⚠️ Visible même sans page de liste. « Annuler » rétablit d'abord
		// les valeurs ; s'en aller n'est que sa conclusion, et l'absence
		// de destination ne doit pas priver l'artiste du reste.
		

		var rangee = document.createElement( 'div' );
		rangee.className = 'afk-form-tete-rangee';
		rangee.appendChild( rang );

		var gestes = document.createElement( 'div' );
		gestes.className = 'afk-form-tete-gestes';
		gestes.appendChild( renoncer );
		gestes.appendChild( sortie );
		rangee.appendChild( gestes );

		tete.appendChild( rangee );
		tete.appendChild( pas );
		tete.appendChild( jauge );
		form.appendChild( tete );

		// ⚠️ Le bandeau de réglage `art_form_avis` n'est plus rendu.
		//
		// C'était un texte **fixe** — « Hors connexion, votre saisie est
		// conservée… » — affiché sans condition, écrit à l'époque où ce
		// formulaire n'apparaissait que sans réseau. Depuis qu'il sert
		// aussi en ligne, il annonçait une panne à des gens parfaitement
		// connectés, juste au-dessus d'une bande d'état qui affichait
		// « En ligne, tout est envoyé ». Deux affirmations contraires sur
		// le même écran : celle qui sait est la bande, qui mesure ;
		// celle qui devine est partie.

		var declarees = ( schemaData.etapes || [] );
		var parEtape  = {};

		champs.forEach( function ( champ ) {
			var cle = champ.step || '_';
			parEtape[ cle ] = parEtape[ cle ] || [];
			parEtape[ cle ].push( champ );
		} );

		var ordre = declarees.map( function ( e ) { return e.key; } );

		Object.keys( parEtape ).forEach( function ( cle ) {
			if ( ordre.indexOf( cle ) === -1 ) {
				ordre.push( cle );
			}
		} );

		var etapes = [];

		ordre.forEach( function ( cle ) {
			if ( ! parEtape[ cle ] ) {
				return;
			}

			var groupe = document.createElement( 'fieldset' );
			groupe.className = 'afk-form-etape';

			var trouvee = declarees.filter( function ( e ) { return e.key === cle; } )[ 0 ];
			var nom     = ( trouvee && trouvee.label ) || 'Informations';

			var titre = document.createElement( 'legend' );
			titre.className = 'afk-form-titre';
			titre.textContent = nom;
			groupe.appendChild( titre );

			var champsBloc = document.createElement( 'div' );
			champsBloc.className = 'afk-form-champs';

			// ⚠️ Posée sur l'étape qui porte les champs concernés, et
			// seulement là. En tête du formulaire, elle proposerait de
			// remplir des champs que l'artiste n'a pas encore vus.
			var reprise = caseDuProfil( parEtape[ cle ], champsBloc );

			if ( reprise ) {
				groupe.appendChild( reprise );
			}

			parEtape[ cle ].forEach( function ( champ ) {
				champsBloc.appendChild( champHTML( champ, valeurs[ champ.key ] ) );
			} );

			groupe.appendChild( champsBloc );
			form.appendChild( groupe );

			etapes.push( { key: cle, label: nom, bloc: groupe, champs: parEtape[ cle ] } );
		} );

		/* --------------------------------------------------------------
		 * Barre d'action
		 *
		 * ⚠️ Collée en bas de l'écran, et non en fin de page : sur un
		 * formulaire à cinq écrans, un bouton en pied de document oblige
		 * à faire défiler pour agir, et l'état d'enregistrement n'est
		 * jamais sous les yeux au moment où il rassure.
		 * ------------------------------------------------------------ */

		var pied = document.createElement( 'div' );
		pied.className = 'afk-form-barre';

		var rangee = document.createElement( 'div' );
		rangee.className = 'afk-form-rangee';

		// ⚠️ Plus de bande d'état dans la barre.
		//
		// Elle y avait sa place tant qu'elle était le seul affichage ;
		// depuis que l'état passe par un message flottant, les deux
		// disaient la même chose au même instant, à dix centimètres l'un
		// de l'autre. Constaté sur le site. Le message flottant reste, la
		// barre garde ce qui lui appartient : l'heure d'enregistrement et
		// les refus de validation.

		var etat = document.createElement( 'span' );
		etat.className = 'afk-form-etat';
		etat.setAttribute( 'role', 'status' );
		// Reserve aux messages de relecture : « il manque quelque chose
		// sur cet ecran ». L'etat de la sauvegarde appartient a la bande.
		etat.textContent = '';

		var actions = document.createElement( 'div' );
		actions.className = 'afk-form-actions';

		var precedent = document.createElement( 'button' );
		precedent.type = 'button';
		precedent.className = 'afk-form-bouton afk-form-secondaire';
		precedent.textContent = 'Précédent';

		var suivant = document.createElement( 'button' );
		suivant.type = 'button';
		suivant.className = 'afk-form-bouton afk-form-primaire';
		suivant.textContent = 'Suivant';

		// ⚠️ Sans ce bouton, la saisie resterait indéfiniment sur
		// l'appareil : l'enregistrement automatique conserve, il ne
		// dépose pas. L'artiste doit pouvoir dire « c'est fini ».
		var depot = document.createElement( 'button' );
		depot.type = 'button';
		depot.className = 'afk-form-bouton afk-form-primaire afk-form-depot';
		depot.setAttribute( 'data-afk-depot', '' );
		// ⚠️ Le libellé ne qualifie plus le réseau.
		//
		// Il valait « Déposer au retour du réseau », écrit à l'époque où ce
		// formulaire n'existait que hors connexion. Il annonçait donc une
		// attente à des artistes parfaitement connectés — constaté sur le
		// site. La nuance appartient à la bande d'état, juste au-dessus,
		// qui la dit au bon moment et seulement alors.
		depot.textContent = oeuvre
			? ( T.form_maj || 'Enregistrer la modification' )
			: ( T.form_depot || 'Déposer l’œuvre' );

		// ⚠️ Envoyer ce qui attend, sans quitter le formulaire.
		//
		// Une œuvre déposée hors connexion reste sur l'appareil. Au retour
		// du réseau, le seul moyen de la faire partir était le message
		// flottant — que l'artiste avait pu écarter — ou la liste, qu'il
		// fallait penser à rouvrir. Il pouvait déposer une deuxième œuvre
		// sans se douter que la première n'était jamais partie.
		//
		// Caché tant qu'il n'y a rien à envoyer : un bouton qui ne
		// produirait rien fait douter de tous les autres.
		var envoi = document.createElement( 'button' );
		envoi.type = 'button';
		envoi.className = 'afk-form-bouton afk-form-secondaire afk-form-envoi';
		envoi.setAttribute( 'data-afk-envoi', '' );
		envoi.textContent = T.form_envoyer || 'Envoyer maintenant';
		envoi.hidden = true;

		actions.appendChild( envoi );
		actions.appendChild( precedent );
		actions.appendChild( suivant );
		actions.appendChild( depot );

		rangee.appendChild( etat );
		rangee.appendChild( actions );
		pied.appendChild( rangee );
		form.appendChild( pied );
		conteneur.appendChild( form );

		/* --------------------------------------------------------------
		 * Navigation
		 * ------------------------------------------------------------ */

		var visibles = etapes.slice();
		var courante = 0;

		// ⚠️ Quelles étapes l'artiste a réellement ouvertes.
		//
		// La barre signale ce qui manque, mais seulement là où il est
		// passé. Sans ce registre, ouvrir le formulaire allumait trois
		// alertes sur des étapes qu'il n'avait pas encore vues — un
		// reproche avant la faute.
		var vus = {};

		function blocDe( champ ) {
			return form.querySelector( '[data-afk-champ="' + champ.key + '"]' );
		}

		/**
		 * Vérifie une étape, et rend le premier champ fautif.
		 *
		 * ⚠️ Seuls les champs **affichés** sont jugés. Un champ écarté par
		 * la logique conditionnelle ne concerne plus cette œuvre, et le
		 * déclarer manquant bloquerait le dépôt sur une question qui n'a
		 * jamais été posée.
		 */
		/**
		 * Combien de champs manquent à cette étape, sans rien souligner.
		 *
		 * ⚠️ `verifie()` marque les champs fautifs au passage — c'est son
		 * office quand l'artiste demande à avancer. La barre d'étapes,
		 * elle, se recalcule à chaque frappe : s'en servir aurait
		 * souligné en rouge les trois étapes suivantes dès la première
		 * lettre du premier champ.
		 */
		function manquesDe( etape ) {
			var lues = valeursDuFormulaire( form );
			var n = 0;

			etape.champs.forEach( function ( champ ) {
				var bloc = blocDe( champ );

				if ( bloc && ! bloc.hidden && erreurDe( champ, lues[ champ.key ] ) ) {
					n++;
				}
			} );

			return n;
		}

		function verifie( etape ) {
			var lues    = valeursDuFormulaire( form );
			var premier = null;
			var nom     = '';
			var nombre  = 0;

			etape.champs.forEach( function ( champ ) {
				var bloc = blocDe( champ );

				if ( ! bloc || bloc.hidden ) {
					return;
				}

				var message = erreurDe( champ, lues[ champ.key ] );
				marque( bloc, message );

				if ( ! message ) {
					return;
				}

				nombre++;

				if ( ! premier ) {
					premier = bloc;
					nom     = libelle( champ );
				}
			} );

			return { bloc: premier, nom: nom, nombre: nombre };
		}

		function rafraichir() {
			appliqueConditions( form, champs );

			// Une étape dont tous les champs sont écartés n'a plus lieu
			// d'être : la traverser vide ferait douter d'une erreur.
			visibles = etapes.filter( function ( e ) {
				return e.champs.some( function ( champ ) {
					var bloc = blocDe( champ );
					return bloc && ! bloc.hidden;
				} );
			} );

			if ( ! visibles.length ) {
				visibles = etapes.slice( 0, 1 );
			}

			courante = Math.min( Math.max( courante, 0 ), visibles.length - 1 );

			// L'étape affichée est vue, y compris la première au premier
			// dessin — `allerA` n'est pas passé par là.
			if ( visibles[ courante ] ) {
				vus[ visibles[ courante ].key ] = true;
			}

			etapes.forEach( function ( e ) { e.bloc.classList.add( 'afk-etape-masquee' ); } );
			visibles[ courante ].bloc.classList.remove( 'afk-etape-masquee' );

			var total = visibles.length;

			tete.hidden = total < 2;
			rang.textContent = total > 1
				? 'Étape ' + ( courante + 1 ) + ' sur ' + total
				: visibles[ courante ].label;
			barre.style.width = Math.round( ( ( courante + 1 ) / total ) * 100 ) + '%';

			dessineLesPas( total );

			precedent.hidden = 0 === courante;
			suivant.hidden   = courante >= total - 1;
			depot.hidden     = courante < total - 1;
		}

		/**
		 * Dessine la barre d'étapes.
		 *
		 * ⚠️ Reconstruite à chaque rafraîchissement, et c'est voulu : une
		 * étape peut apparaître ou disparaître selon les réponses, et
		 * corriger les boutons en place aurait laissé un rang mort dans la
		 * barre — cliquable, et menant nulle part.
		 */
		function dessineLesPas( total ) {
			pas.textContent = '';
			pas.hidden = total < 2;

			if ( total < 2 ) {
				return;
			}

			visibles.forEach( function ( etape, rang ) {
				var manque = vus[ etape.key ] ? manquesDe( etape ) : 0;

				var item = document.createElement( 'li' );
				item.className = 'afk-pas-item';

				var bouton = document.createElement( 'button' );
				bouton.type = 'button';
				bouton.className = 'afk-pas-bouton';

				if ( rang === courante ) {
					bouton.classList.add( 'afk-pas-ici' );
					bouton.setAttribute( 'aria-current', 'step' );
				}

				// ⚠️ Le manque n'est signalé que sur une étape déjà vue.
				// Souligner en rouge ce que l'artiste n'a pas encore
				// ouvert, c'est lui reprocher de ne pas avoir deviné.
				if ( manque && rang !== courante ) {
					bouton.classList.add( 'afk-pas-manque' );
				}

				var puce = document.createElement( 'span' );
				puce.className = 'afk-pas-puce';
				puce.setAttribute( 'aria-hidden', 'true' );
				puce.textContent = manque && rang !== courante ? '!' : String( rang + 1 );

				var nom = document.createElement( 'span' );
				nom.className = 'afk-pas-nom';
				nom.textContent = titreCourt( etape, rang );

				bouton.appendChild( puce );
				bouton.appendChild( nom );

				// Le nom complet reste accessible : la barre le raccourcit
				// pour tenir, elle ne doit pas le supprimer.
				var dit = 'Étape ' + ( rang + 1 ) + ' sur ' + total + ' : ' + ( etape.label || nom.textContent );

				bouton.setAttribute( 'aria-label',
					manque && rang !== courante ? dit + ' — ' + manque + ' à compléter' : dit );

				bouton.addEventListener( 'click', function () {
					// ⚠️ Aucune validation. C'est tout l'objet de cette
					// barre : relire son étape 1 depuis la dernière ne doit
					// pas exiger d'avoir rempli les trois du milieu.
					etat.classList.remove( 'afk-form-etat-alerte' );
					etat.textContent = '';
					allerA( rang );
				} );

				item.appendChild( bouton );
				pas.appendChild( item );
			} );
		}

		function premiereSaisie( bloc ) {
			var saisies = bloc.querySelectorAll( '.afk-champ:not([hidden]) .afk-champ-saisie' );

			for ( var i = 0; i < saisies.length; i++ ) {
				if ( 'hidden' !== saisies[ i ].type ) {
					return saisies[ i ];
				}
			}

			return null;
		}

		/**
		 * Va à une étape.
		 *
		 * ⚠️ `discret` empêche le défilement vers le haut du formulaire.
		 * Sans cela, un refus de dépôt lançait **deux** défilements doux
		 * dans le même instant — l'un vers le haut du formulaire, l'autre
		 * vers le champ fautif — et le navigateur n'en honorait qu'un.
		 * L'artiste restait en haut de l'écran, loin de l'erreur : d'où
		 * l'impression, exacte de son point de vue, qu'il ne se passait
		 * rien.
		 */
		function allerA( index, discret ) {
			courante = index;

			// Vue : elle peut désormais signaler ce qui lui manque. Avant
			// d'y être passé, l'artiste n'a rien à se reprocher.
			if ( visibles[ index ] ) {
				vus[ visibles[ index ].key ] = true;
			}

			rafraichir();

			if ( discret ) {
				return;
			}

			// ⚠️ Ce focus-ci ne doit PAS amener le champ au quart de
			// l'écran : au changement d'étape, c'est le haut du formulaire
			// qu'on veut voir — la barre d'étapes et le titre de l'écran.
			// Sans ce témoin, notre propre placement défaisait le défilement
			// de la ligne suivante.
			focalise( premiereSaisie( visibles[ courante ].bloc ) );

			form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		/**
		 * Conduit l'œil et le doigt jusqu'au champ fautif.
		 *
		 * ⚠️ Le même placement que pour un champ touché : au quart de
		 * l'écran. Deux règles de défilement pour la même page — l'une au
		 * centre, l'autre au quart — auraient donné deux comportements
		 * selon qu'on arrive par la validation ou par le doigt.
		 */
		function montrer( bloc ) {
			amene( bloc );
			focalise( bloc.querySelector( '.afk-champ-saisie:not([type="hidden"])' ) );
		}

		/**
		 * Vérifie tout, et conduit l'artiste au premier problème.
		 *
		 * ⚠️ Sans cela, une saisie incomplète partait en file et revenait
		 * en `ERROR` une fois le réseau retrouvé — parfois des heures plus
		 * tard, avec un message du serveur et aucun moyen de savoir quel
		 * champ était en cause.
		 */
		function verifieTout( discret ) {
			for ( var i = 0; i < visibles.length; i++ ) {
				var bilan = verifie( visibles[ i ] );

				if ( ! bilan.bloc ) {
					continue;
				}

				// ⚠️ En mode discret, on répond sans rien exiger.
				//
				// Celui qui clique « Enregistrer et revenir » s'en va : le
				// conduire au champ fautif et lui reprocher un oubli
				// reviendrait à le retenir de force alors qu'il n'a rien
				// demandé de tel. On se contente de savoir que la saisie
				// n'est pas envoyable.
				if ( discret ) {
					return false;
				}

				// Changement d'étape seulement s'il y en a un : y aller
				// quand on y est déjà ne fait que déclencher un défilement
				// inutile, qui écrase celui vers le champ.
				if ( i !== courante ) {
					allerA( i, true );
				}

				montrer( bilan.bloc );

				// ⚠️ Le message nomme le champ. « Il manque quelque chose »
				// laissait chercher dans trente-six champs — et quand le
				// champ fautif était déjà à l'écran, rien ne bougeait :
				// l'artiste concluait que le bouton ne marchait pas.
				etat.textContent = bilan.nombre > 1
					? bilan.nombre + ' champs à corriger, à commencer par « ' + bilan.nom + ' »'
					: 'À corriger : « ' + bilan.nom + ' »';
				etat.classList.add( 'afk-form-etat-alerte' );

				return false;
			}

			etat.classList.remove( 'afk-form-etat-alerte' );

			return true;
		}

		precedent.addEventListener( 'click', function () {
			allerA( Math.max( 0, courante - 1 ) );
		} );

		suivant.addEventListener( 'click', function () {
			var bilan = verifie( visibles[ courante ] );

			if ( bilan.bloc ) {
				montrer( bilan.bloc );

				etat.textContent = bilan.nombre > 1
					? bilan.nombre + ' champs à corriger, à commencer par « ' + bilan.nom + ' »'
					: 'À corriger : « ' + bilan.nom + ' »';
				etat.classList.add( 'afk-form-etat-alerte' );

				return;
			}

			etat.classList.remove( 'afk-form-etat-alerte' );
			allerA( Math.min( visibles.length - 1, courante + 1 ) );
		} );

		/**
		 * L'étape en cours, désignée par sa clé.
		 *
		 * ⚠️ Sa clé, et non son rang. La logique conditionnelle fait
		 * apparaître et disparaître des étapes entières : un rang
		 * enregistré hier ne désigne pas forcément le même écran
		 * aujourd'hui, et l'artiste serait reposé ailleurs qu'où il s'est
		 * arrêté — au milieu d'un formulaire de trente-six champs, c'est
		 * pire que de le remettre au début.
		 */
		function etapeCourante() {
			return ( visibles[ courante ] || {} ).key || '';
		}

		/** Revient sur l'étape portant cette clé, si elle existe encore. */
		function reprendre( cle ) {
			if ( ! cle ) {
				return;
			}

			rafraichir();

			for ( var i = 0; i < visibles.length; i++ ) {
				if ( visibles[ i ].key === cle ) {
					courante = i;
					rafraichir();
					return;
				}
			}
		}

		return {
			form: form,
			etat: etat,
			rafraichir: rafraichir,
			verifieTout: verifieTout,
			etape: etapeCourante,
			reprendre: reprendre,

			/** Conduit l'œil jusqu'à un champ, quelle que soit son étape. */
			montrer: function ( bloc ) {
				for ( var i = 0; i < visibles.length; i++ ) {
					if ( visibles[ i ].bloc.contains( bloc ) ) {
						if ( i !== courante ) {
							allerA( i, true );
						}

						montrer( bloc );
						return;
					}
				}
			}
		};
	}

	/* ------------------------------------------------------------------
	 * Logique conditionnelle
	 *
	 * ⚠️ Sémantique du thème, relevée dans son code : **OU entre les
	 * groupes, ET à l'intérieur d'un groupe**. L'inverser masquerait
	 * exactement les champs qu'il faut montrer.
	 * ---------------------------------------------------------------- */

	function texte( v ) {
		if ( Array.isArray( v ) ) {
			return v.join( ' ' ).toLowerCase();
		}
		return String( null === v || undefined === v ? '' : v ).toLowerCase();
	}

	function evalueRegle( regle, valeurs ) {
		var v = valeurs[ regle.source ];
		var t = texte( v );
		var attendu = texte( regle.valeur );
		var nombre = parseFloat( v );
		var cible = parseFloat( regle.valeur );

		switch ( regle.type ) {
			// La comparaison de texte ignore la casse — c'est ce que fait
			// le thème, et s'en écarter masquerait des champs que son
			// propre formulaire affiche.
			case 'text:contains':          return '' !== attendu && t.indexOf( attendu ) !== -1;
			case 'text:equals':            return t === attendu;
			case 'text:not_equals':        return t !== attendu;
			case 'text:empty':             return '' === t;
			case 'text:not_empty':         return '' !== t;

			case 'taxonomy:contains':      return t.indexOf( attendu ) !== -1;
			case 'taxonomy:not_contains':  return t.indexOf( attendu ) === -1;
			case 'taxonomy:empty':         return '' === t;
			case 'taxonomy:not_empty':     return '' !== t;

			case 'number:equals':          return nombre === cible;
			case 'number:not_equals':      return nombre !== cible;
			case 'number:gt':              return nombre > cible;
			case 'number:gte':             return nombre >= cible;
			case 'number:lt':              return nombre < cible;
			case 'number:lte':             return nombre <= cible;
			case 'number:empty':           return '' === t;
			case 'number:not_empty':       return '' !== t;

			case 'switcher:checked':       return '1' === t || 'true' === t || 'oui' === t;
			case 'switcher:unchecked':     return ! ( '1' === t || 'true' === t || 'oui' === t );
		}

		// Type inconnu : on ne juge pas. Voir `visible()`.
		return null;
	}

	/**
	 * Ce champ doit-il s'afficher ?
	 *
	 * ⚠️ Le doute profite à l'affichage. Montrer un champ inutile gêne ;
	 * en masquer un obligatoire bloque le dépôt sans cause visible, et
	 * l'artiste ne peut ni le voir ni le corriger.
	 */
	function visible( champ, valeurs ) {
		var c = champ.conditions;

		if ( ! c || ! c.groupes || ! c.groupes.length ) {
			return true;
		}

		if ( false === c.complet ) {
			return true;
		}

		var passe = false;

		for ( var g = 0; g < c.groupes.length; g++ ) {
			var groupe = c.groupes[ g ];
			var groupePasse = true;

			for ( var r = 0; r < groupe.length; r++ ) {
				var resultat = evalueRegle( groupe[ r ], valeurs );

				if ( null === resultat ) {
					return true;
				}

				if ( ! resultat ) {
					groupePasse = false;
					break;
				}
			}

			if ( groupePasse ) {
				passe = true;
				break;
			}
		}

		return 'hide' === c.comportement ? ! passe : passe;
	}

	/**
	 * Applique les conditions à l'ensemble du formulaire.
	 *
	 * Un champ masqué est aussi **vidé de sa valeur transmise** : garder
	 * la réponse d'un particulier au champ « Dénomination sociale » après
	 * qu'il s'est déclaré galerie déposerait une donnée qu'il croit
	 * effacée.
	 */
	function appliqueConditions( form, champs ) {
		var valeurs = valeursDuFormulaire( form );
		var caches = 0;

		champs.forEach( function ( champ ) {
			var bloc = form.querySelector( '[data-afk-champ="' + champ.key + '"]' );

			if ( ! bloc ) {
				return;
			}

			var montrer = visible( champ, valeurs );

			bloc.hidden = ! montrer;

			if ( ! montrer ) {
				caches++;
			}
		} );

		return caches;
	}

	/* ------------------------------------------------------------------
	 * Enregistrement automatique
	 * ---------------------------------------------------------------- */

	/* ------------------------------------------------------------------
	 * Amener un champ sous les yeux
	 * ---------------------------------------------------------------- */

	/**
	 * À quelle hauteur de l'écran un champ touché vient se placer.
	 *
	 * ⚠️ Le quart, et non le milieu ni le haut.
	 *
	 * En haut, le champ passe sous la barre d'étapes, qui est collante.
	 * Au milieu, le clavier du téléphone — qui prend la moitié basse de
	 * l'écran — recouvre tout ce qui suit, et l'artiste ne voit ni l'aide
	 * du champ ni le champ suivant. Au quart, le libellé, l'aide et la
	 * saisie tiennent au-dessus du clavier.
	 */
	var HAUTEUR_DU_CHAMP = 0.25;

	/**
	 * Un focus que nous posons nous-mêmes.
	 *
	 * ⚠️ Il ne doit pas déclencher le placement automatique : le
	 * changement d'étape veut montrer le haut du formulaire, pas le
	 * premier champ. Sans ce témoin, notre propre placement défaisait le
	 * défilement demandé une ligne plus loin.
	 */
	var focusPose = false;

	function focalise( element ) {
		if ( ! element ) {
			return;
		}

		focusPose = true;
		element.focus( { preventScroll: true } );

		window.requestAnimationFrame( function () {
			focusPose = false;
		} );
	}

	/**
	 * Amène un bloc de champ au quart haut de l'écran.
	 *
	 * ⚠️ Le BLOC, et non la seule zone de saisie. Le libellé et l'aide
	 * viennent avec : « Dimensions en cm » sans « Ex. : 120 x 80 cm »
	 * laisse l'artiste deviner le format qu'on attend de lui.
	 *
	 * ⚠️ Et rien si l'on y est déjà, à vingt-quatre pixels près. Le
	 * clavier du téléphone provoque son propre défilement en s'ouvrant ;
	 * corriger de trois pixels par-dessus donnerait une secousse à chaque
	 * champ touché.
	 */
	function amene( bloc ) {
		if ( ! bloc || CFG.apercu ) {
			return;
		}

		// Au tour d'affichage suivant : le navigateur vient peut-être de
		// défiler lui-même pour le clavier, et mesurer avant lui reviendrait
		// à viser une position déjà périmée.
		window.requestAnimationFrame( function () {
			var boite = bloc.getBoundingClientRect();
			var vise  = window.innerHeight * HAUTEUR_DU_CHAMP;

			if ( Math.abs( boite.top - vise ) < 24 ) {
				return;
			}

			var doux = ! ( window.matchMedia
				&& window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

			window.scrollTo( {
				top: Math.max( 0, boite.top + window.scrollY - vise ),
				behavior: doux ? 'smooth' : 'auto'
			} );
		} );
	}

	/**
	 * Inscrit la saisie en cours dans l'adresse, une seule fois.
	 *
	 * ⚠️ Seulement pour une saisie neuve. Une œuvre du site est déjà
	 * désignée par `#oeuvre=<id>` : la réécrire en brouillon ferait perdre
	 * le lien avec AFRICART au premier rechargement.
	 *
	 * @param {string} localId Identité de la saisie.
	 */
	function marqueLAdresse( localId ) {
		if ( CFG.apercu || ! localId || 0 === String( localId ).indexOf( 'oeuvre-' ) ) {
			return;
		}

		if ( String( window.location.hash || '' ).indexOf( 'brouillon=' ) !== -1 ) {
			return;
		}

		try {
			window.history.replaceState( null, '', '#brouillon=' + encodeURIComponent( localId ) );
		} catch ( e ) { /* navigateur sans historique manipulable */ }
	}

	/**
	 * L'identité de la saisie en cours.
	 *
	 * ⚠️ « Enregistrer une œuvre » ouvre un formulaire NEUF.
	 *
	 * Cette fonction rendait le dernier brouillon connu, quel que soit le
	 * chemin emprunté : l'artiste qui venait déposer une seconde œuvre
	 * retrouvait la première à l'écran, et croyait la modifier alors
	 * qu'il voulait en créer une autre. Signalé au test.
	 *
	 * Une saisie précédente n'est pas perdue pour autant : dès qu'elle a
	 * un titre elle figure dans « Vos œuvres », et c'est par « Modifier »
	 * qu'on y revient — un chemin nommé, au lieu d'une reprise implicite.
	 *
	 * ⚠️ Le rechargement de page reste distinct du nouveau dépôt : la
	 * première écriture pose `#brouillon=<id>` dans l'adresse, et cette
	 * adresse-là rouvre bien la même saisie. Sans quoi un rafraîchissement
	 * accidentel aurait vidé l'écran.
	 *
	 * @param {boolean} neuve Vrai quand rien n'est visé dans l'adresse.
	 */
	function identifiantLocal( neuve ) {
		var cle = 'afkOeuvreBrouillonCourant';

		// L'éditeur ne réclame pas d'identité : rien n'y sera enregistré,
		// et en poser une salirait la réserve du navigateur de l'auteur.
		if ( CFG.apercu ) {
			return 'apercu';
		}

		var neuf = 'art-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 8 );

		try {
			// ⚠️ On ne reprend le brouillon courant QUE si l'adresse ne
			// demandait rien de neuf. Le poser sans condition ferait
			// reprendre la saisie precedente sur un dépôt tout neuf.
			if ( ! neuve ) {
				var connu = window.localStorage.getItem( cle );

				if ( connu ) {
					return connu;
				}
			}

			window.localStorage.setItem( cle, neuf );

			return neuf;
		} catch ( e ) {
			return neuf;
		}
	}

	/**
	 * Intervalle entre deux prises de pouls pendant la saisie.
	 *
	 * ⚠️ Sans elles, une coupure survenue **pendant** le remplissage ne
	 * se voyait qu'à l'envoi. `navigator.onLine` ne suffit pas : il dit
	 * « en ligne » sur un wifi sans internet. Une requête minuscule, et
	 * seulement tant que l'artiste tape réellement — un formulaire laissé
	 * ouvert sur un bureau n'interroge rien.
	 */
	var POULS = 90000;

	/**
	 * La clé du champ qui porte le titre de l'œuvre.
	 *
	 * Lue dans le schéma plutôt que devinée : le thème nomme ce champ
	 * `title` aujourd'hui, rien ne garantit qu'il le nommera ainsi demain,
	 * et c'est son **type** qui le désigne.
	 */
	function cleDuTitre( champs ) {
		for ( var i = 0; i < ( champs || [] ).length; i++ ) {
			if ( 'title' === champs[ i ].type ) {
				return champs[ i ].key;
			}
		}

		return '';
	}

	/** Y a-t-il quelque chose à conserver ? */
	function quelqueChose( valeurs ) {
		var cles = Object.keys( valeurs || {} );

		for ( var i = 0; i < cles.length; i++ ) {
			var v = valeurs[ cles[ i ] ];
			var texte = Array.isArray( v ) ? v.join( '' ) : String( null === v || undefined === v ? '' : v );

			if ( texte.trim() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Branche la sortie du haut sur l'enregistrement.
	 *
	 * ⚠️ On ne quitte pas si le titre manque : sans lui rien n'est
	 * conservé, et l'artiste partirait en croyant son travail à l'abri.
	 * Le message est le même qu'ailleurs, et le champ reçoit le focus —
	 * il est sur le premier écran, pas forcément celui qu'on regarde.
	 */
	function brancherSortie( form, etat, enregistrer, cleTitre, allerA, oeuvre, verifieTout ) {
		var bouton = form.querySelector( '[data-afk-sortie]' );

		if ( ! bouton ) {
			return;
		}

		bouton.addEventListener( 'click', function () {
			var champs = valeursDuFormulaire( form );

			if ( cleTitre && ! String( champs[ cleTitre ] || '' ).trim() ) {
				etat.textContent = T.form_titre_dabord || 'Donnez un titre à l’œuvre pour que votre saisie soit conservée.';
				etat.classList.remove( 'afk-form-etat-alerte' );

				var bloc = form.querySelector( '[data-afk-champ="' + cleTitre + '"]' );

				if ( bloc && allerA ) {
					allerA( bloc );
				}

				return;
			}

			enregistrer();

			// ⚠️ Avec du réseau, enregistrer c'est envoyer.
			//
			// Ce bouton se contentait d'écrire sur l'appareil. L'artiste
			// corrigeait une œuvre, cliquait « Enregistrer », revenait à
			// la liste — et y lisait « Modifications non envoyées » alors
			// qu'il avait quatre barres de réseau. Rien ne partirait tant
			// qu'il n'aurait pas retraversé les quatre étapes jusqu'au
			// dépôt.
			//
			// Ce n'est pas une synchronisation automatique : c'est un clic
			// explicite, et il ne concerne qu'une œuvre déjà sur le site.
			// Sur une création, envoyer ici reviendrait à la déposer sans
			// qu'on l'ait demandé.
			//
			// Et seulement si la saisie tient debout : une œuvre amputée
			// d'un champ obligatoire serait refusée par le site, et
			// l'artiste lirait « Envoi refusé » pour avoir voulu sortir.
			var partir = function () { window.location.href = CFG.liste; };

			if ( ! oeuvre || ! oeuvre.wpPostId || ! window.navigator.onLine || ! window.afkSync ) {
				partir();
				return;
			}

			if ( verifieTout && ! verifieTout( true ) ) {
				partir();
				return;
			}

			bouton.disabled = true;
			etat.textContent = T.form_envoi_sortie || 'Envoi de vos modifications…';
			etat.classList.remove( 'afk-form-etat-alerte' );

			window.afkSync.ajouter( 'enregistrer', String( oeuvre.wpPostId ), {
				wpPostId: oeuvre.wpPostId,
				champs: champs,
				hashAttendu: oeuvre.hash || ''
			} ).then( function () {
				enregistrer.cesser();
				partir();
			} ).catch( function () {
				// L'envoi n'a pas abouti : la saisie est conservée, la file
				// la reprendra. On ne retient pas l'artiste pour autant.
				partir();
			} );
		} );
	}

	function brancherEnregistrement( form, etat, localId, etapeDe, cleTitre ) {
		var minuteur = null;

		// ⚠️ Une fois la saisie partie, elle ne doit plus se réécrire.
		//
		// L'écouteur `pagehide` ci-dessous court-circuite la temporisation
		// pour ne rien perdre en quittant la page. Après un dépôt réussi,
		// il ressuscitait le brouillon que la file venait d'effacer : sur
		// la liste, l'œuvre déposée à l'instant s'affichait aussitôt
		// « Modifications non envoyées », avec un statut pourtant à jour.
		// Constaté sur le site, et parfaitement reproductible — ce n'était
		// pas une course, mais l'ordre normal des choses.
		var arrete = false;
		var dernierPouls = Date.now();
		var rappelTitre = T.form_titre_dabord || 'Donnez un titre à l’œuvre pour que votre saisie soit conservée.';

		function pouls() {
			if ( ! window.afkSync || Date.now() - dernierPouls < POULS ) {
				return;
			}

			dernierPouls = Date.now();
			window.afkSync.verifier();
		}

		function enregistrer() {
			if ( arrete ) {
				return;
			}

			var champs = valeursDuFormulaire( form );

			// ⚠️ Pas de brouillon tant que l'œuvre n'a pas de nom.
			//
			// L'enregistrement partait à la première frappe, quel que soit
			// le champ : ouvrir le formulaire et toucher n'importe quoi
			// suffisait à créer un brouillon. La liste « mes œuvres » se
			// remplissait de cartes « (sans titre) » que l'artiste devait
			// supprimer une à une, sans savoir d'où elles venaient.
			//
			// Le titre n'est pas un champ obligatoire de plus : c'est le
			// seul par lequel une œuvre se reconnaît dans une liste. Sans
			// lui, ce qu'on conserverait ne serait retrouvable par personne.
			if ( cleTitre && ! String( champs[ cleTitre ] || '' ).trim() ) {
				// ⚠️ Cette branche ne gouverne QUE son propre message.
				//
				// Elle posait une chaîne vide sans condition. Comme elle
				// s'exécute une seconde après la dernière frappe, elle
				// effaçait au passage tout ce qu'un autre geste venait
				// d'annoncer — « Vos modifications ont été annulées »
				// disparaissait sous les yeux de l'artiste, qui ne savait
				// plus si l'annulation avait eu lieu. Constaté au banc.
				if ( quelqueChose( champs ) ) {
					etat.textContent = rappelTitre;
				} else if ( etat.textContent === rappelTitre ) {
					etat.textContent = '';
				}

				etat.classList.remove( 'afk-form-etat-alerte' );

				return;
			}

			// ⚠️ Le rappel s'efface dès qu'il n'a plus lieu d'être.
			//
			// Sans cela il restait affiché après le premier enregistrement
			// réussi : l'artiste avait donné un titre, sa saisie était bien
			// conservée, et l'écran continuait de lui réclamer un titre.
			// Constaté au banc.
			//
			// Seulement lui : effacer sans condition emporterait un message
			// de validation en cours, qui n'a rien à voir.
			if ( etat.textContent === rappelTitre ) {
				etat.textContent = '';
			}

			// ⚠️ L'adresse retient la saisie des la premiere ecriture.
			//
			// Depuis que « Enregistrer une oeuvre » ouvre un formulaire
			// neuf, l'adresse nue en ouvre un autre a chaque visite — ce
			// qui est voulu, sauf pour un rafraichissement accidentel, qui
			// aurait vide l'ecran. Nommer la saisie dans l'adresse
			// distingue les deux : `#brouillon=<id>` rouvre celle-ci,
			// l'adresse nue en commence une.
			//
			// `replaceState` et non `hash` : on ne veut pas d'une entree
			// d'historique qui ferait revenir en arriere sur la meme page.
			marqueLAdresse( localId );

			ecritBrouillon( {
				localId: localId,
				champs: champs,
				// Où en était la saisie. Repris à la réouverture.
				etape: etapeDe ? etapeDe() : '',
				majLe: Date.now(),
				envoye: false
			} );

			// ⚠️ L'heure n'est plus ecrite ici. La bande d'etat la porte
			// deja, et les deux mentions ne tombaient pas a la meme
			// seconde : « Enregistre a 09:42 » juste au-dessus de
			// « Enregistre a 09:42:50 » donnait l'impression d'un compteur
			// detraque plutot que d'une sauvegarde qui marche.
		}

		// Attendre une seconde de silence : enregistrer à chaque frappe
		// solliciterait le stockage sans rien apporter, et ferait
		// clignoter le message d'état sous les doigts.
		form.addEventListener( 'input', function () {
			window.clearTimeout( minuteur );
			minuteur = window.setTimeout( enregistrer, 1000 );
			pouls();
		} );

		// Quitter la page ne doit rien perdre : la temporisation est
		// court-circuitée au dernier moment.
		window.addEventListener( 'pagehide', function () {
			window.clearTimeout( minuteur );
			enregistrer();
		} );

		/** La saisie est partie : plus rien ne doit la réécrire. */
		enregistrer.cesser = function () {
			arrete = true;
			window.clearTimeout( minuteur );
		};

		return enregistrer;
	}

	/**
	 * Le bouton « Envoyer maintenant » du pied de page.
	 *
	 * ⚠️ Il ne paraît que lorsqu'il a quelque chose à faire, et il le sait
	 * par la file elle-même : `afkFile` porte le décompte de ce qui est
	 * prêt. Le calculer ici serait une seconde source de vérité sur le
	 * même sujet — et deux comptes qui divergent valent moins qu'aucun.
	 */
	function brancherEnvoi( form, etat ) {
		var bouton = form.querySelector( '[data-afk-envoi]' );

		if ( ! bouton || ! window.afkSync ) {
			return;
		}

		function suivreLaFile( e ) {
			var f = ( e && e.detail && e.detail.file ) || {};

			bouton.hidden = ! ( f.prets > 0 ) || ! window.navigator.onLine;
		}

		document.addEventListener( 'afkFile', suivreLaFile );
		window.addEventListener( 'online', function () { window.afkSync.annoncer(); } );
		window.addEventListener( 'offline', function () { bouton.hidden = true; } );

		bouton.addEventListener( 'click', async function () {
			bouton.disabled = true;
			bouton.textContent = T.form_envoi_cours || 'Envoi…';

			var bilan = await window.afkSync.vider();

			bouton.disabled = false;
			bouton.textContent = T.form_envoyer || 'Envoyer maintenant';

			if ( bilan && bilan.envoyees > 0 ) {
				// ⚠️ Devant la page, pas au bas de l'écran : l'artiste
				// vient de cliquer, il regarde son doigt et pas le pied de
				// page. Une confirmation qu'on ne voit pas ne confirme
				// rien.
				eclair( 1 === bilan.envoyees
					? ( T.form_envoi_fait || 'Œuvre envoyée au site.' )
					: bilan.envoyees + ' œuvres envoyées au site.' );

				etat.textContent = '';
				etat.classList.remove( 'afk-form-etat-alerte' );

				return;
			}

			// Le message flottant dit déjà pourquoi rien n'est parti —
			// hors connexion, session perdue, saisie à compléter. Le
			// répéter ici en d'autres termes ferait deux explications
			// concurrentes du même refus.
			window.afkSync.annoncer();
		} );

		window.afkSync.annoncer();
	}

	/**
	 * Revenir sur ses pas, sans rien perdre.
	 *
	 * ⚠️ Annuler n'est pas supprimer.
	 *
	 * Ce bouton effaçait le brouillon, ses photos et ses envois en
	 * attente : sur une œuvre jamais déposée, il la faisait disparaître.
	 * L'artiste qui voulait revenir sur une correction perdait tout ce
	 * qu'il avait saisi depuis le début — sans avoir demandé cela.
	 *
	 * Il rétablit désormais les valeurs telles qu'elles étaient à
	 * l'ouverture du formulaire. C'est une annulation de la saisie en
	 * cours, pas une destruction : rien ne disparaît de la liste, et
	 * l'œuvre du site n'est de toute façon jamais touchée.
	 *
	 * ⚠️ La photo de départ est prise à la construction du formulaire, pas
	 * au moment du clic — à cet instant la saisie a déjà tout remplacé.
	 *
	 * ⚠️ Ce qui attend déjà dans la file d'envoi n'est pas retiré. Ces
	 * valeurs-là ne sont plus une saisie : elles sont une demande faite au
	 * site, et on y renonce depuis la carte, qui le dit dans ces termes.
	 *
	 * ⚠️ Jamais pendant un envoi. À cet instant l'œuvre part peut-être
	 * déjà avec les valeurs qu'on s'apprête à remplacer.
	 */
	function brancherAnnulation( form, etat, localId, oeuvre, enregistrer, avant, rafraichir, cleTitre ) {
		var bouton = form.querySelector( '[data-afk-annuler]' );

		if ( ! bouton ) {
			return;
		}

		bouton.addEventListener( 'click', async function () {
			var cibles = [ String( localId ) ];

			if ( oeuvre && oeuvre.wpPostId ) {
				cibles.push( String( oeuvre.wpPostId ) );
			}

			var file = DB ? await DB.tout( 'file' ) : [];

			var siennes = file.filter( function ( a ) {
				return cibles.indexOf( String( a.cible ) ) !== -1;
			} );

			if ( siennes.some( function ( a ) { return 'SYNCING' === a.etat; } ) ) {
				etat.textContent = T.form_envoi_encours
					|| 'Un envoi est en cours pour cette œuvre : attendez qu’il aboutisse.';
				etat.classList.add( 'afk-form-etat-alerte' );

				return;
			}

			// ⚠️ Rien n'a bougé : on part, sans rien demander.
			//
			// Le bouton répondait « Rien n'a été modifié depuis
			// l'ouverture » et restait sur place. C'était exact et
			// parfaitement inutile : celui qui appuie sur « Annuler »
			// demande à sortir, pas un bilan. Le message passait de
			// surcroît inaperçu au bas de l'écran, et l'on concluait que
			// le bouton était cassé. Signalé au test.
			if ( ! aChange( form, avant ) ) {
				quitter();

				return;
			}

			var question = window.afkDemander;

			if ( ! question ) {
				return;
			}

			var oui = await question(
				T.form_annuler_titre || 'Annuler vos modifications ?',
				T.form_annuler_dit
					|| 'Le formulaire retrouvera les valeurs qu’il avait à l’ouverture. '
						+ 'L’œuvre n’est pas supprimée : elle reste dans « Vos œuvres ».',
				T.form_annuler_oui || 'Annuler mes modifications'
			);

			if ( ! oui ) {
				return;
			}

			retablir( form, avant );

			// Les conditions et la progression dépendent des valeurs : une
			// étape rendue visible par une réponse qu'on vient de retirer
			// doit disparaître avec elle.
			if ( rafraichir ) {
				rafraichir();
			}

			// ⚠️ Et le brouillon suit — écrit ici, pas confié à
			// l'enregistrement automatique.
			//
			// Je l'avais d'abord appelé, et il ne faisait rien : il refuse
			// d'écrire tant qu'il n'y a pas de titre, ce qui est
			// exactement l'état d'un formulaire qu'on vient de vider. Le
			// brouillon gardait donc la saisie annulée, et la rouvrir la
			// ramenait telle quelle. Constaté au banc.
			//
			// Plus rien ne doit réécrire par-dessus : `quitter()` fera
			// taire l'enregistrement, mais une temporisation en cours
			// pourrait aboutir avant.
			if ( enregistrer && enregistrer.cesser ) {
				enregistrer.cesser();
			}

			var repose = valeursDuFormulaire( form );
			var titre  = cleTitre ? String( repose[ cleTitre ] || '' ).trim() : 'x';

			if ( titre ) {
				await DB.ecrire( 'brouillons', {
					localId: localId,
					champs: repose,
					majLe: Date.now(),
					envoye: false
				} );
			} else {
				// ⚠️ Vide, il n'y a rien à conserver — et ce n'est PAS la
				// suppression d'une œuvre.
				//
				// Un formulaire dont les valeurs d'ouverture étaient vides
				// est une œuvre qui n'existait pas avant cette saisie. En
				// garder le brouillon poserait une carte « (sans titre) »
				// dans la liste, que l'artiste devrait aller supprimer sans
				// savoir d'où elle vient. Une œuvre déjà sur le site, elle,
				// a toujours un titre : ce chemin ne la concerne jamais.
				await DB.effacer( 'brouillons', localId );

				try {
					if ( window.localStorage.getItem( 'afkOeuvreBrouillonCourant' ) === localId ) {
						window.localStorage.removeItem( 'afkOeuvreBrouillonCourant' );
					}
				} catch ( e ) { /* sans mémoire, on repartira d'un neuf */ }
			}

			if ( window.afkSync && window.afkSync.annoncer ) {
				window.afkSync.annoncer();
			}

			// ⚠️ Et l'on s'en va. « Annuler » est une sortie, au même
			// titre que son voisin : rester sur un formulaire qu'on vient
			// de vider laisserait l'artiste devant un écran qui ne
			// ressemble plus à rien de ce qu'il a fait.
			//
			// La confirmation, il la lira dans la liste : son œuvre y
			// porte le titre qu'elle avait à l'ouverture. Un message
			// éclair posé juste avant la navigation ne s'afficherait
			// jamais — la page part avant qu'on ait pu le lire.
			quitter();
		} );

		/**
		 * Repartir vers la liste, ou rester s'il n'y a nulle part où aller.
		 *
		 * ⚠️ Sans adresse de liste, on ne navigue pas : envoyer l'artiste
		 * sur l'accueil du site depuis un formulaire serait pire que de le
		 * laisser où il est. Il lui reste alors le message.
		 */
		function quitter() {
			if ( enregistrer && enregistrer.cesser ) {
				enregistrer.cesser();
			}

			if ( CFG.liste ) {
				window.location.href = CFG.liste;

				return;
			}

			eclair( T.form_annule || 'Vos modifications ont été annulées.' );
		}
	}

	/**
	 * Les valeurs du formulaire ont-elles bougé depuis l'ouverture ?
	 *
	 * Comparées champ par champ plutôt que par leur sérialisation : un
	 * tableau de photos et une chaîne vide ne se comparent pas de la même
	 * façon, et `JSON.stringify` aurait rendu vrai sur un simple
	 * changement d'ordre de clés.
	 */
	function aChange( form, avant ) {
		var maintenant = valeursDuFormulaire( form );
		var cles = {};

		Object.keys( avant || {} ).forEach( function ( c ) { cles[ c ] = true; } );
		Object.keys( maintenant ).forEach( function ( c ) { cles[ c ] = true; } );

		return Object.keys( cles ).some( function ( c ) {
			return commeUnTexte( maintenant[ c ] ) !== commeUnTexte( ( avant || {} )[ c ] );
		} );
	}

	function commeUnTexte( v ) {
		if ( Array.isArray( v ) ) {
			return v.join( '\u0001' );
		}

		return null === v || undefined === v ? '' : String( v );
	}

	/**
	 * Un message qui se voit, puis s'en va.
	 *
	 * ⚠️ La ligne d'état du pied de page ne suffisait pas.
	 *
	 * Elle est en petit, en gris, tout en bas — à l'endroit exact où
	 * l'artiste ne regarde pas au moment où il vient de cliquer en haut.
	 * « Rien n'a été modifié depuis l'ouverture » s'y affichait sans que
	 * personne le voie : le bouton semblait ne rien faire, et l'on
	 * concluait à une panne. Signalé au test.
	 *
	 * Ce message-ci passe devant la page, au milieu, et disparaît de
	 * lui-même au bout de trois secondes. Il ne sert QU'AUX
	 * confirmations : un refus de validation, lui, doit rester à l'écran
	 * jusqu'à ce qu'on l'ait traité, et il reste donc dans la ligne
	 * d'état, à côté du champ qu'il désigne.
	 *
	 * @param {string} texte Ce qu'on annonce.
	 * @param {string} ton   `bon` ou `calme`.
	 */
	var minuteurEclair = null;

	function eclair( texte, ton ) {
		if ( ! texte ) {
			return;
		}

		var boite = document.querySelector( '.afk-eclair' );

		if ( ! boite ) {
			boite = document.createElement( 'div' );
			boite.className = 'afk-eclair';

			// ⚠️ `status` et non `alert` : une confirmation ne doit pas
			// interrompre une synthèse vocale en cours de lecture.
			boite.setAttribute( 'role', 'status' );
			boite.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( boite );
		}

		boite.className = 'afk-eclair afk-eclair-' + ( ton || 'bon' );
		boite.textContent = texte;

		// Reparti pour trois secondes : deux messages coup sur coup ne
		// doivent pas faire disparaître le second avec le minuteur du
		// premier.
		window.clearTimeout( minuteurEclair );

		// Le retrait de la classe puis sa repose relance l'animation
		// d'entrée, même si le message précédent est encore à l'écran.
		boite.classList.remove( 'afk-eclair-vu' );
		void boite.offsetWidth;
		boite.classList.add( 'afk-eclair-vu' );

		minuteurEclair = window.setTimeout( function () {
			boite.classList.remove( 'afk-eclair-vu' );
		}, 3000 );
	}

	/**
	 * Repose les valeurs d'ouverture dans le formulaire.
	 *
	 * ⚠️ Les champs à porteur caché — photos, questions fermées, mots
	 * clés — ne se rétablissent pas en posant `value` : leur affichage est
	 * construit à côté et ne s'en apercevrait pas. Ils sont reconstruits
	 * par l'événement que le module écoute déjà pour se redessiner.
	 */
	function retablir( form, avant ) {
		var valeurs = avant || {};

		Array.prototype.forEach.call( form.querySelectorAll( '.afk-champ-saisie' ), function ( s ) {
			var v = valeurs[ s.name ];
			var type = s.getAttribute( 'data-afk-type' );

			if ( 'image' === type || 'file' === type ) {
				s.value = JSON.stringify( Array.isArray( v ) ? v : [] );
			} else if ( 'taxonomy' === type ) {
				s.value = Array.isArray( v ) ? ( v[ 0 ] || '' ) : ( v || '' );
			} else {
				s.value = null === v || undefined === v ? '' : String( v );
			}

			// L'affichage se refait sur ce signal : c'est celui que les
			// champs composés écoutent déjà, et il n'y en a qu'un.
			s.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			s.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );

		// ⚠️ Un seul signal pour « les valeurs viennent d'être posées de
		// l'extérieur ». L'annulation s'en sert, la case « reprendre mes
		// informations » aussi : dans les deux cas les champs composés —
		// photos, questions fermées, mots clés, téléphone — doivent se
		// redessiner, et un second signal pour dire la même chose aurait
		// fini par n'être écouté qu'à moitié.
		document.dispatchEvent( new CustomEvent( 'afkValeursPosees' ) );
	}


	/**
	 * Met la saisie en file, pour qu'elle parte au retour du réseau.
	 *
	 * ⚠️ Le `localId` accompagne la création et sert de clé
	 * d'idempotence côté serveur. Sans lui, une coupure réseau après la
	 * création mais avant la réponse ferait recommencer l'envoi, et
	 * l'artiste retrouverait deux fois la même œuvre.
	 */
	function brancherDepot( form, etat, localId, oeuvre, verifieTout, enregistrer ) {
		var bouton = form.querySelector( '[data-afk-depot]' );

		if ( ! bouton ) {
			return;
		}

		// ⚠️ Sans file, on ne part pas sans rien dire.
		//
		// Ce garde-fou se contentait de sortir : le bouton restait à
		// l'écran, l'air normal, et son clic ne faisait rien. Il a fallu
		// imprimer l'ordre des scripts du serveur pour comprendre. Un
		// module absent est un incident, et un incident se signale.
		if ( ! window.afkSync ) {
			bouton.addEventListener( 'click', function () {
				etat.textContent = 'Envoi indisponible sur cette page — rechargez-la.';
				etat.classList.add( 'afk-form-etat-alerte' );
			} );

			return;
		}

		bouton.addEventListener( 'click', async function () {
			// ⚠️ Rien ne part sans avoir été relu. Une saisie incomplète
			// mise en file revient en échec bien plus tard, avec un
			// message du serveur et aucun champ désigné.
			if ( verifieTout && ! verifieTout() ) {
				return;
			}

			bouton.disabled = true;

			var champs = valeursDuFormulaire( form );

			await ecritBrouillon( {
				localId: localId,
				champs: champs,
				majLe: Date.now(),
				envoye: false
			} );

			var bilan;

			if ( oeuvre && oeuvre.wpPostId ) {
				// ⚠️ L'empreinte détenue au moment de l'ouverture part avec
				// la modification. Sans elle, un changement fait entre-temps
				// sur le site serait écrasé en silence.
				// ⚠️ `deposer`, et non `enregistrer`.
				//
				// Ce bouton est le geste par lequel l'artiste dit « c'est
				// fini, regardez-la ». Envoyé sous le verbe
				// « enregistrer », il laissait l'œuvre dans le statut
				// qu'elle avait : déposée le matin, elle s'affichait
				// encore « Brouillon » le soir, et le secrétariat ne la
				// voyait jamais arriver en relecture.
				bilan = await window.afkSync.ajouter( 'deposer', String( oeuvre.wpPostId ), {
					wpPostId: oeuvre.wpPostId,
					champs: champs,
					hashAttendu: oeuvre.hash || ''
				} );
			} else {
				// ⚠️ Sans `statut: 'draft'`.
				//
				// Il était là depuis le premier jet, quand ce bouton
				// n'était qu'un enregistrement. Il forçait le serveur à
				// créer l'œuvre en brouillon quoi qu'il arrive, et rendait
				// le dépôt indistinguable d'une saisie en cours. Le statut
				// revient au site : il applique sa propre règle de dépôt.
				bilan = await window.afkSync.ajouter( 'creer', localId, {
					localId: localId,
					champs: champs
				} );

				// Le brouillon suivant doit avoir son propre identifiant :
				// réutiliser celui-ci ferait modifier l'œuvre déjà déposée.
				try {
					window.localStorage.removeItem( 'afkOeuvreBrouillonCourant' );
				} catch ( e ) { /* sans mémoire, on repartira d'un neuf */ }
			}

			annonceSaisie( 'depose' );

			// ⚠️ On dit ce qui s'est passé, pas ce qu'on espérait.
			//
			// « En attente d'envoi — partira dès que le réseau reviendra »
			// s'affichait même quand l'œuvre venait de partir à l'instant,
			// et laissait croire à une panne à qui n'en avait aucune.
			var parti = bilan && bilan.envoi && bilan.envoi.envoyees > 0;

			if ( parti ) {
				etat.textContent = T.form_depose || 'Œuvre envoyée au site.';
				etat.classList.remove( 'afk-form-etat-alerte' );
				bouton.textContent = T.form_fait || 'Déposée';
			} else {
				etat.textContent = T.form_partira
					|| 'Enregistrée sur votre appareil. Elle partira quand vous appuierez sur « Envoyer maintenant », une fois la connexion revenue.';
				bouton.textContent = T.form_enfile || '';
			}

			// Plus rien ne doit réécrire le brouillon : la file détient
			// désormais la vérité, et `pagehide` le ressusciterait.
			if ( enregistrer && enregistrer.cesser ) {
				enregistrer.cesser();
			}

			ecranDeFin( form, parti );
		} );
	}

	/**
	 * La case « reprendre mes informations de profil ».
	 *
	 * ⚠️ Réversible. Une case qu'on ne peut pas décocher n'est pas une
	 * case : elle mémorise donc ce qu'il y avait avant et le rend si on la
	 * décoche. Et elle ne remplit que les champs **vides** — écraser une
	 * saisie en cours pour y mettre des valeurs de profil serait un vol.
	 *
	 * @param {Array}   champs Les champs de l'étape.
	 * @param {Element} bloc   Le conteneur des champs, déjà construit.
	 * @return {Element|null}
	 */
	function caseDuProfil( champs, bloc ) {
		var profil = CFG.profil || {};

		var concernes = ( champs || [] ).filter( function ( c ) {
			return Object.prototype.hasOwnProperty.call( profil, c.key );
		} );

		if ( ! concernes.length ) {
			return null;
		}

		// ⚠️ Un seul champ suffit à justifier la case.
		//
		// J'ai d'abord exigé deux champs, en me disant qu'une case pour un
		// seul n'en valait pas la peine. Or sur ce site la plupart des
		// comptes n'ont que leur adresse électronique — ni prénom, ni nom,
		// ni téléphone : la case ne serait apparue pour presque personne,
		// et justement pour le champ le plus pénible à retaper au doigt.
		// Constaté en lisant les comptes réels.

		var enveloppe = document.createElement( 'label' );
		enveloppe.className = 'afk-form-reprise';

		var boite = document.createElement( 'input' );
		boite.type = 'checkbox';

		var texte = document.createElement( 'span' );
		texte.textContent = T.form_profil || 'Reprendre mes informations de profil';

		enveloppe.appendChild( boite );
		enveloppe.appendChild( texte );

		var avant = {};

		boite.addEventListener( 'change', function () {
			var aRedessiner = false;

			concernes.forEach( function ( c ) {
				var saisie = bloc.querySelector( '[name="' + c.key + '"]' );

				if ( ! saisie ) {
					return;
				}

				if ( boite.checked ) {
					if ( String( saisie.value || '' ).trim() ) {
						return;
					}

					avant[ c.key ] = saisie.value;
					saisie.value = profil[ c.key ];
				} else if ( Object.prototype.hasOwnProperty.call( avant, c.key ) ) {
					saisie.value = avant[ c.key ];
					delete avant[ c.key ];
				}

				saisie.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				aRedessiner = true;
			} );

			// ⚠️ Les champs composés se dessinent à côté de leur porteur :
			// poser une valeur dedans ne suffit pas à les mettre à jour.
			// Sans ce signal, cocher la case remplissait bien le téléphone
			// du profil dans la valeur enregistrée, et laissait le champ
			// visible vide sous les yeux de l'artiste.
			if ( aRedessiner ) {
				document.dispatchEvent( new CustomEvent( 'afkValeursPosees' ) );
			}
		} );

		return enveloppe;
	}

	/**
	 * L'écran qui suit le dépôt.
	 *
	 * ⚠️ Le formulaire restait sur son dernier écran, boutons grisés, au
	 * milieu de trente-six champs déjà remplis. Rien ne disait que
	 * c'était fini, et rien n'indiquait où aller : l'artiste relisait ses
	 * réponses en cherchant ce qu'il avait raté.
	 *
	 * Un dépôt est un aboutissement. Il mérite un écran à lui.
	 */
	function ecranDeFin( form, parti ) {
		var voile = document.createElement( 'div' );
		voile.className = 'afk-fin';

		var boite = document.createElement( 'div' );
		boite.className = 'afk-fin-boite';

		var marque = document.createElement( 'p' );
		marque.className = 'afk-fin-marque';
		marque.setAttribute( 'aria-hidden', 'true' );
		marque.textContent = parti ? '✓' : '↑';
		boite.appendChild( marque );

		var titre = document.createElement( 'h2' );
		titre.className = 'afk-fin-titre';
		titre.textContent = parti
			? ( T.form_fin_titre || 'Votre œuvre est arrivée sur AFRICART' )
			: ( T.form_fin_attente || 'Votre œuvre est enregistrée sur cet appareil' );
		boite.appendChild( titre );

		var dit = document.createElement( 'p' );
		dit.className = 'afk-fin-detail';

		// ⚠️ On annonce la validation, parce qu'elle explique pourquoi
		// l'œuvre n'apparaît pas tout de suite sur le site. Sans cela,
		// l'artiste la cherche, ne la trouve pas, et redépose.
		// ⚠️ « Elle partira quand vous lancerez l'envoi » ne disait rien à
		// personne.
		//
		// Testée par la cliente hors connexion : « j'avoue ne pas avoir
		// compris la phrase ». Elle supposait connus deux mots de notre
		// vocabulaire — « partir » et « lancer l'envoi » — et ne disait ni
		// où la saisie se trouve, ni quoi faire ensuite, ni quand.
		//
		// La nouvelle phrase dit les trois, dans cet ordre : c'est
		// enregistré ici, il faudra du réseau, et voici le geste. Elle ne
		// promet PAS un envoi automatique au retour du réseau — c'est
		// l'artiste qui décide quand ses œuvres partent, et annoncer le
		// contraire ferait attendre un envoi qui n'aurait jamais lieu.
		dit.textContent = parti
			? ( T.form_fin_detail || 'Elle sera examinée avant d’être publiée. Vous la retrouverez dans « Vos œuvres », avec son état.' )
			: ( T.form_fin_detail_attente || 'Tout est enregistré sur votre appareil, rien ne se perd. Dès que votre connexion sera rétablie, ouvrez « Vos œuvres » et appuyez sur « Envoyer maintenant » pour la transmettre à l’équipe AFRICART.' );
		boite.appendChild( dit );

		var pied = document.createElement( 'div' );
		pied.className = 'afk-fin-pied';

		if ( CFG.liste ) {
			var vers = document.createElement( 'a' );
			vers.className = 'afk-form-bouton afk-form-primaire';
			vers.href = CFG.liste;
			vers.textContent = T.form_fin_liste || 'Voir mes œuvres';
			pied.appendChild( vers );
		}

		var encore = document.createElement( 'button' );
		encore.type = 'button';
		encore.className = 'afk-form-bouton afk-form-secondaire';
		encore.textContent = T.form_fin_encore || 'Déposer une autre œuvre';
		encore.addEventListener( 'click', function () {
			// ⚠️ Rechargement plutôt que remise à zéro des champs : le
			// formulaire tire son identité d'un brouillon, et le vider
			// sans en changer ferait modifier l'œuvre qu'on vient de
			// déposer.
			window.location.hash = '';
			window.location.reload();
		} );

		pied.appendChild( encore );
		boite.appendChild( pied );
		voile.appendChild( boite );

		form.replaceChildren( voile );
		voile.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	}

	/**
	 * Ce que le fragment demande d'ouvrir.
	 *
	 * `#oeuvre=154` : une œuvre déjà sur le site, à modifier.
	 * `#brouillon=art-x` : une création qui n'est jamais partie.
	 * Rien : un nouveau dépôt.
	 */
	function demande() {
		// L'éditeur montre un formulaire neuf : reprendre le brouillon de
		// l'auteur y afficherait sa propre saisie en cours, prise pour du
		// contenu de la page.
		if ( CFG.apercu ) {
			return { quoi: 'nouveau' };
		}

		var h = String( window.location.hash || '' ).replace( /^#/, '' );
        var m = /^oeuvre=(\d+)$/.exec( h );

		if ( m ) {
			return { quoi: 'oeuvre', id: parseInt( m[ 1 ], 10 ) };
		}

		m = /^brouillon=(.+)$/.exec( h );

		if ( m ) {
			return { quoi: 'brouillon', id: decodeURIComponent( m[ 1 ] ) };
		}

		return { quoi: 'nouveau' };
	}

	/* ------------------------------------------------------------------
	 * Mise en route
	 * ---------------------------------------------------------------- */

	async function demarrer() {
		if ( ! await monter() ) {
			return;
		}

		// ⚠️ Dans l'éditeur, Elementor redessine un widget fraîchement
		// déposé par une requête Ajax, sans recharger la page. Sans cette
		// veille, le conteneur neuf resterait vide jusqu'au prochain
		// rechargement — c'est-à-dire exactement au moment où l'auteur
		// vient de poser le code court et regarde s'il fonctionne.
		if ( CFG.apercu && window.MutationObserver ) {
			new window.MutationObserver( function () {
				if ( document.querySelector( '[data-afk-form]:not([data-afk-monte])' ) ) {
					monter();
				}
			} ).observe( document.body, { childList: true, subtree: true } );
		}
	}

	async function monter() {
		// ⚠️ Notre ancrage passe avant celui du thème. Le jour où son
		// widget est retiré de la page — ce qui est le but de la
		// bascule — chercher `.ts-create-post` ne trouverait plus rien,
		// et la page de dépôt deviendrait blanche sans la moindre erreur.
		var notre     = document.querySelector( '[data-afk-form]:not([data-afk-monte])' );
		var conteneur = notre || document.querySelector( '.ts-create-post:not([data-afk-monte])' );

		if ( ! conteneur ) {
			return false;
		}

		conteneur.setAttribute( 'data-afk-monte', '' );

		// ⚠️ Poser le shortcode **est** la demande d'avoir notre
		// formulaire ici. Exiger en plus un réglage laissait un conteneur
		// vide et muet sur la page de dépôt : rien ne s'affichait, et rien
		// n'expliquait pourquoi.
		//
		// Le réglage ne gouverne donc que la reprise du conteneur **du
		// thème**, qui, elle, doit rester un choix explicite — sans quoi
		// installer le module changerait le formulaire du site à la
		// seconde où on l'active.
		if ( ! notre && window.navigator.onLine && ! CFG.toujours ) {
			return false;
		}

		var schemaData = schemaEnMemoire();

		if ( ! schemaData || ! schemaData.champs ) {
			// ⚠️ Sans schéma, on n'invente pas un formulaire : des clés
			// devinées écriraient à côté. Mieux vaut le dire.
			var avis = document.createElement( 'p' );
			avis.className = 'afk-oeuvres-avis';
			avis.textContent = T.form_absent || '';
			conteneur.innerHTML = '';
			conteneur.appendChild( avis );
			return false;
		}

		var quoi      = demande();

		// ⚠️ Une identité par cas, et aucune consommée pour rien.
		//
		// Une œuvre du site prend la sienne un peu plus bas
		// (`oeuvre-<id>`) : lui faire d'abord réclamer une identité de
		// brouillon en aurait créé une, inscrite dans la réserve du
		// navigateur, que personne n'aurait jamais réclamée.
		var localId = '';

		if ( 'brouillon' === quoi.quoi ) {
			localId = quoi.id;
		} else if ( 'nouveau' === quoi.quoi ) {
			localId = identifiantLocal( true );
		}

		// Le détail vient-il d'être rapatrié ? Sans ce témoin, l'œuvre
		// était demandée deux fois de suite au site — une fois pour ne pas
		// dessiner un formulaire vide, une fois pour ses visuels.
		var dejaFrais = false;
		var oeuvre    = null;
		var brouillon = null;

		if ( 'oeuvre' === quoi.quoi ) {
			// ⚠️ Une modification en cours l'emporte sur la version du
			// serveur : reprendre celle du serveur effacerait sous les
			// doigts ce que l'artiste a saisi et pas encore envoyé.
			brouillon = await litBrouillon( 'oeuvre-' + quoi.id );
			oeuvre    = await DB.lire( 'oeuvres', quoi.id );
			localId   = 'oeuvre-' + quoi.id;

			// Ouvrir une œuvre pour la modifier la remonte en haut de la
			// pile : c'est la dernière que l'élagage devrait couper.
			if ( oeuvre && DB.toucher ) {
				DB.toucher( quoi.id );
			}

			// ⚠️ Une œuvre sans son détail ne doit PAS ouvrir un
			// formulaire.
			//
			// La liste ne transporte pas les valeurs des champs. Une œuvre
			// connue de la réserve mais jamais détaillée — un appareil
			// neuf, un navigateur nettoyé — produisait donc un formulaire
			// entièrement vide, d'apparence normale. Enregistrer depuis
			// cet écran aurait effacé les trente-six champs de l'œuvre sur
			// AFRICART, sans le moindre avertissement.
			//
			// C'est le seul endroit où l'on attend le réseau avant de
			// dessiner : partout ailleurs on montre la copie locale et on
			// affine ensuite, mais ici il n'y a rien à montrer, et ce
			// « rien » est destructeur.
			if ( ! brouillon && oeuvre && ! oeuvre.champs && window.navigator.onLine && window.afkOeuvres ) {
				try {
					var complet = await window.afkOeuvres.detail( quoi.id );

					if ( complet && complet.champs ) {
						oeuvre    = complet;
						dejaFrais = true;
					}
				} catch ( e ) { /* le site n'a pas répondu : on le dira */ }
			}

			// Ce que la copie locale sait déjà des visuels, avant même
			// le premier dessin : hors connexion, c'est tout ce qu'on aura.
			if ( oeuvre && oeuvre.medias ) {
				VISUELS_CONNUS = oeuvre.medias;
			}

			if ( ! brouillon && oeuvre && oeuvre.champs ) {
				brouillon = { localId: localId, champs: oeuvre.champs, majLe: 0 };
			}

			// Ni saisie en cours, ni détail : on ne dessine rien. Le
			// message est le même dans les deux cas — l'œuvre n'est pas
			// modifiable depuis cet appareil tant qu'on n'a pas eu de
			// réseau une fois.
			if ( ! brouillon ) {
				conteneur.innerHTML = '';
				var absente = document.createElement( 'p' );
				absente.className = 'afk-oeuvres-avis';
				absente.textContent = T.form_absente || 'Cette œuvre n’est pas enregistrée sur cet appareil. Ouvrez-la une fois avec du réseau.';
				conteneur.appendChild( absente );
				return false;
			}
		} else {
			brouillon = await litBrouillon( localId );
		}

		// Le formulaire n'est pas la seule dependance du script d'etat :
		// il doit connaitre la file avant meme la premiere saisie.
		if ( window.afkSync ) {
			window.afkSync.annoncer();
		}

		var rendu = construire( conteneur, schemaData, brouillon, oeuvre );

		// ⚠️ Le formulaire s'affiche AVANT d'aller chercher les visuels.
		//
		// Les attendre laisserait un écran vide à celui dont le réseau est
		// mauvais — c'est-à-dire exactement le public visé. Les vignettes
		// se remplissent quand les adresses arrivent ; d'ici là elles
		// disent qu'un visuel existe, ce qui est déjà vrai.
		//
		// Au passage, les octets sont mis en réserve : la prochaine
		// ouverture de cette œuvre se fera sans réseau du tout.
		if ( ! dejaFrais && 'oeuvre' === quoi.quoi && quoi.id && window.navigator.onLine && window.afkOeuvres ) {
			( async function () {
				try {
					var frais = await window.afkOeuvres.detail( quoi.id );

					if ( ! frais || ! frais.medias ) {
						return;
					}

					VISUELS_CONNUS = frais.medias;
					document.dispatchEvent( new CustomEvent( 'afkVisuelsConnus' ) );

					if ( window.afkOeuvres.visuels ) {
						await window.afkOeuvres.visuels( frais );
						document.dispatchEvent( new CustomEvent( 'afkVisuelsConnus' ) );
					}
				} catch ( e ) { /* la copie locale reste affichée */ }
			}() );
		}

		rendu.rafraichir();
		ajusteHauteur( rendu.form );
		colleLaTete( rendu.form );

		// ⚠️ Reprise à l'écran où l'artiste s'est arrêté. Sans cela, une
		// saisie interrompue au quatrième écran repartait du premier, et
		// il fallait refranchir trois étapes déjà remplies pour retrouver
		// sa place — en repassant par leurs contrôles de validation.
		if ( brouillon && brouillon.etape ) {
			rendu.reprendre( brouillon.etape );
		}

		// Les conditions gouvernent aussi les étapes : un choix peut vider
		// un écran entier, qui doit alors disparaître du parcours.
		rendu.form.addEventListener( 'input', rendu.rafraichir );
		rendu.form.addEventListener( 'change', rendu.rafraichir );

		// ⚠️ Le champ touché vient sous les yeux, au quart de l'écran.
		//
		// `focusin` et non `click` : on arrive aussi sur un champ par la
		// tabulation, par « Suivant » du clavier du téléphone, ou depuis
		// un message de validation. Le placement doit être le même quel
		// que soit le chemin, sinon l'écran saute d'une fois sur l'autre.
		rendu.form.addEventListener( 'focusin', function ( e ) {
			if ( focusPose ) {
				return;
			}

			var bloc = e.target && e.target.closest
				? e.target.closest( '[data-afk-champ]' )
				: null;

			if ( bloc ) {
				amene( bloc );
			}
		} );

		// ⚠️ Dans l'éditeur, on s'arrête ici. Les étapes, la validation et
		// la logique conditionnelle restent vivantes — c'est ce que l'auteur
		// vient regarder — mais plus rien n'écrit ni ne part.
		if ( CFG.apercu ) {
			conteneur.insertBefore( ruban(), rendu.form );
			return true;
		}

		// ⚠️ L'œuvre ouverte dit si elle est en train de partir.
		//
		// Le message flottant parle de la file entière ; sur l'écran
		// d'édition, la question est plus étroite : « celle que je suis en
		// train de modifier, est-elle partie ? ». Sans cela, l'artiste
		// quittait la page en plein envoi, croyant l'affaire close.
		document.addEventListener( 'afkFile', async function () {
			if ( ! DB ) {
				return;
			}

			var file = await DB.tout( 'file' );

			var sienne = file.filter( function ( a ) {
				return String( a.cible ) === String( localId )
					|| ( oeuvre && String( a.cible ) === String( oeuvre.wpPostId ) );
			} )[ 0 ];

			if ( ! sienne || 'SYNCING' !== sienne.etat ) {
				rendu.form.classList.remove( 'afk-form-envoi' );
				return;
			}

			rendu.form.classList.add( 'afk-form-envoi' );
			rendu.etat.textContent = T.form_envoi || 'Envoi en cours — restez sur cette page.';
			rendu.etat.classList.remove( 'afk-form-etat-alerte' );
		} );

		var cleTitre    = cleDuTitre( schemaData.champs );
		var enregistrer = brancherEnregistrement( rendu.form, rendu.etat, localId, rendu.etape, cleTitre );

		brancherSortie( rendu.form, rendu.etat, enregistrer, cleTitre, rendu.montrer, oeuvre, rendu.verifieTout );
		brancherDepot( rendu.form, rendu.etat, localId, oeuvre, rendu.verifieTout, enregistrer );
		// ⚠️ La photo des valeurs, prise ici et pas plus tard.
		//
		// C'est le seul instant où le formulaire porte ce qu'il portait à
		// l'ouverture : dès la première frappe, il ne le sait plus. Prise
		// au clic sur « Annuler », elle aurait rendu la saisie qu'on
		// demande justement d'annuler.
		var avantSaisie = valeursDuFormulaire( rendu.form );

		brancherAnnulation( rendu.form, rendu.etat, localId, oeuvre, enregistrer, avantSaisie, rendu.rafraichir, cleTitre );
		brancherEnvoi( rendu.form, rendu.etat );

		// ⚠️ « Est-ce enregistré ? » et « est-ce parti ? » sont deux
		// questions différentes, et l'écran n'en traitait qu'une. Une
		// saisie peut être parfaitement enregistrée **et** n'avoir jamais
		// quitté l'appareil : c'est même le cas ordinaire tant que
		// l'artiste n'a pas appuyé sur « Déposer ».
		annonceSaisie( oeuvre ? 'modification' : 'brouillon' );

		return true;
	}

	/**
	 * Dit à la feuille de style de quelle hauteur elle dispose.
	 *
	 * ⚠️ Mesuré, pas estimé. La place restante sous l'en-tête du site
	 * dépend du thème, du menu, d'un éventuel bandeau — rien de tout cela
	 * n'est connu d'une feuille de style. Une valeur en dur laissait la
	 * barre d'action soit flottante au milieu de l'écran, soit poussée
	 * sous le pli.
	 */
	/**
	 * Fait tenir le bandeau d'en-tête là où il doit tenir.
	 *
	 * ⚠️ `position: sticky` est déclaré dans la feuille de style depuis le
	 * début, et ne suffisait pas. Deux choses le mettent en échec sur un
	 * site réel, toutes deux silencieuses — aucune erreur, aucun message,
	 * le bandeau se contente de disparaître vers le haut.
	 *
	 * 1. **L'en-tête du site.** Il est fixé en haut de la fenêtre. Notre
	 *    bandeau collait à `top: 0`, c'est-à-dire DERRIÈRE lui : il
	 *    montait, passait dessous et ne se voyait plus. La hauteur de cet
	 *    en-tête ne se devine pas depuis une feuille de style — elle
	 *    dépend du thème, du menu, de la barre d'administration, et change
	 *    au redimensionnement. On la mesure.
	 *
	 * 2. **Un conteneur qui rogne.** Un ancêtre en `overflow: hidden`
	 *    annule `sticky` pour tout ce qu'il contient. C'est la règle la
	 *    plus répandue des constructeurs de pages, posée pour empêcher un
	 *    débordement horizontal. On la lève sur la seule chaîne qui mène à
	 *    notre formulaire, et seulement pour `hidden` : `auto` et `scroll`
	 *    désignent une zone défilante voulue, à laquelle on ne touche pas.
	 *
	 * @param {Element} form Le formulaire.
	 */
	function colleLaTete( form ) {
		var tete = form.querySelector( '.afk-form-tete' );

		if ( ! tete ) {
			return;
		}

		// ⚠️ Une seule fois : la mise en page du thème n'a pas à être
		// retouchée à chaque défilement.
		var noeud = form.parentElement;

		while ( noeud && noeud !== document.body && noeud !== document.documentElement ) {
			var style = window.getComputedStyle( noeud );

			if ( 'hidden' === style.overflowX || 'hidden' === style.overflowY ) {
				noeud.style.overflow = 'visible';
			}

			noeud = noeud.parentElement;
		}

		function mesurer() {
			var haut = 0;

			// Ce qui occupe réellement le haut de la fenêtre, quel que
			// soit le nom que le thème lui donne.
			var dessus = document.elementsFromPoint
				? document.elementsFromPoint( Math.round( window.innerWidth / 2 ), 1 )
				: [];

			dessus.forEach( function ( el ) {
				if ( form.contains( el ) ) {
					return;
				}

				var pos = window.getComputedStyle( el ).position;

				if ( 'fixed' !== pos && 'sticky' !== pos ) {
					return;
				}

				haut = Math.max( haut, el.getBoundingClientRect().bottom );
			} );

			// La barre d'administration de WordPress, qui n'est pas dans
			// la pile au point mesuré quand elle est en haut de page.
			var barre = document.getElementById( 'wpadminbar' );

			if ( barre && 'fixed' === window.getComputedStyle( barre ).position ) {
				haut = Math.max( haut, barre.getBoundingClientRect().bottom );
			}

			// Plafonné : un en-tête démesuré ne doit pas pousser le
			// bandeau hors de l'écran, ce qui serait pire que rien.
			tete.style.setProperty( '--afk-tete-haut', Math.round( Math.min( Math.max( haut, 0 ), 180 ) ) + 'px' );
		}

		mesurer();
		window.addEventListener( 'resize', mesurer );

		// ⚠️ Et après le premier défilement. Beaucoup d'en-têtes ne se
		// fixent qu'une fois la page descendue : mesurée au chargement,
		// leur hauteur vaut zéro, et le bandeau se recollerait trop haut
		// dès que l'en-tête apparaît.
		var revu = false;

		window.addEventListener( 'scroll', function () {
			if ( revu ) {
				return;
			}

			revu = true;
			window.setTimeout( function () { revu = false; mesurer(); }, 200 );
		}, { passive: true } );

		// L'ombre n'apparaît que quand le bandeau est vraiment collé :
		// posée en permanence, elle soulignerait un titre au repos.
		var sentinelle = document.createElement( 'div' );
		sentinelle.className = 'afk-form-sentinelle';
		sentinelle.setAttribute( 'aria-hidden', 'true' );
		tete.parentNode.insertBefore( sentinelle, tete );

		if ( ! window.IntersectionObserver ) {
			return;
		}

		new window.IntersectionObserver( function ( entrees ) {
			tete.classList.toggle( 'afk-form-tete-collee', ! entrees[ 0 ].isIntersecting );
		}, { threshold: 0 } ).observe( sentinelle );
	}

	function ajusteHauteur( form ) {
		function poser() {
			var haut = Math.max( 0, Math.round( form.getBoundingClientRect().top + window.scrollY ) );

			// Plafonné : sur une page qui pousse le formulaire très bas,
			// une réserve nulle vaut mieux qu'une hauteur négative.
			form.style.setProperty( '--afk-decalage', Math.min( haut, 600 ) + 'px' );
		}

		poser();
		window.addEventListener( 'resize', poser );
	}

	/** Fait savoir à l'écran où en est cette saisie. */
	function annonceSaisie( phase ) {
		document.dispatchEvent( new CustomEvent( 'afkSaisie', { detail: { phase: phase } } ) );
	}

	/** Le rappel, dans l'éditeur, que ce formulaire ne mène nulle part. */
	function ruban() {
		var r = document.createElement( 'p' );
		r.className = 'afk-form-ruban';
		r.textContent = 'Aperçu Elementor — formulaire réel, mais rien n’est enregistré ni envoyé d’ici.';

		return r;
	}

	window.afkOeuvreForm = {
		brouillon: litBrouillon,
		identifiantLocal: identifiantLocal
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
