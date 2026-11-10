/* AFRICART PWA — écrans de réglages.
 * Rattrapage des images et des visuels de partage (traitement par lots
 * piloté depuis le navigateur), sélection depuis la médiathèque avec
 * aperçu et remplissage automatique des dimensions.
 *
 * Chaque module ayant sa propre page, il n'y a plus d'onglets : ce
 * fichier est chargé sur toutes les pages du menu AFRICART PWA et
 * n'agit que sur les éléments réellement présents.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		/* ---------------- Rattrapage des images ----------------
		 * Le navigateur enchaîne de petites requêtes : chacune traite un
		 * média et rend la main. Cela évite les dépassements de temps
		 * d'exécution du serveur et permet d'afficher une progression
		 * réelle plutôt qu'une attente aveugle.
		 */
		var $batch = $( '.afk-batch' );

		if ( $batch.length ) {
			var running = false;

			function paint( s ) {
				$batch.find( '.afk-batch-fill' ).css( 'width', s.percent + '%' );
				$batch.find( '.afk-batch-bar' ).attr( 'aria-valuenow', s.percent );
				$batch.find( '.afk-batch-done' ).text( s.offset );
				$batch.find( '.afk-batch-total' ).text( s.total );
				$batch.find( '.afk-batch-summary' ).text( s.summary );

				var reste = s.total - s.offset;
				var etat = '';
				if ( 'done' === s.status ) {
					etat = '— terminé';
				} else if ( running ) {
					etat = '— ' + reste + ' restants';
				} else if ( s.offset > 0 ) {
					etat = '— ' + reste + ' restants, en pause';
				}
				$batch.find( '.afk-batch-state' ).text( etat );
			}

			function fail( message ) {
				running = false;
				$batch.find( '.afk-batch-error' ).text( message ).prop( 'hidden', false );
				$batch.find( '.afk-batch-start' ).prop( 'disabled', false );
				$batch.find( '.afk-batch-stop' ).prop( 'disabled', true );
			}

			function send( command ) {
				return $.post( window.ajaxurl, {
					action:  $batch.data( 'action' ),
					nonce:   $batch.data( 'nonce' ),
					command: command,
					size:    1
				} );
			}

			function loop() {
				if ( ! running ) {
					return;
				}

				send( 'step' )
					.done( function ( res ) {
						if ( ! res || ! res.success ) {
							fail( ( res && res.data && res.data.message ) || 'Le traitement a été interrompu.' );
							return;
						}

						paint( res.data );

						if ( 'running' === res.data.status && running ) {
							loop();
						} else {
							running = false;
							$batch.find( '.afk-batch-start' ).prop( 'disabled', false );
							$batch.find( '.afk-batch-stop' ).prop( 'disabled', true );
							paint( res.data );
						}
					} )
					.fail( function () {
						fail( 'Le serveur n’a pas répondu. La progression est conservée : relancez pour reprendre.' );
					} );
			}

			function begin( command ) {
				$batch.find( '.afk-batch-error' ).prop( 'hidden', true );
				$batch.find( '.afk-batch-start' ).prop( 'disabled', true );
				$batch.find( '.afk-batch-stop' ).prop( 'disabled', false );

				send( command )
					.done( function ( res ) {
						if ( ! res || ! res.success ) {
							fail( ( res && res.data && res.data.message ) || 'Impossible de démarrer.' );
							return;
						}
						running = true;
						paint( res.data );
						loop();
					} )
					.fail( function () {
						fail( 'Le serveur n’a pas répondu.' );
					} );
			}

			$batch.on( 'click', '.afk-batch-start', function () {
				// Une progression entamée se reprend, elle ne se relance pas.
				var deja = parseInt( $batch.find( '.afk-batch-done' ).text(), 10 ) > 0;
				begin( deja ? 'resume' : 'start' );
			} );

			$batch.on( 'click', '.afk-batch-restart', function () {
				if ( ! window.confirm( 'Reprendre depuis le début ? Les versions déjà produites sont conservées et seront simplement ignorées.' ) ) {
					return;
				}
				begin( 'start' );
			} );

			$batch.on( 'click', '.afk-batch-stop', function () {
				running = false;
				$( this ).prop( 'disabled', true );
				send( 'stop' ).done( function ( res ) {
					if ( res && res.success ) {
						paint( res.data );
					}
					$batch.find( '.afk-batch-start' ).prop( 'disabled', false );
				} );
			} );

			// Fermer la page en cours de traitement n'annule rien, mais
			// mieux vaut prévenir.
			window.addEventListener( 'beforeunload', function ( e ) {
				if ( running ) {
					e.preventDefault();
					e.returnValue = '';
				}
			} );
		}

		/* ---------------- Visuels de partage des œuvres ----------------
		 * Traitement par lots : le bouton relance tant qu'il reste des
		 * œuvres à corriger, et rend compte à chaque passage.
		 */
		$( document ).on( 'click', '.afk-seo-backfill', function () {
			var $btn = $( this );
			var $out = $btn.siblings( '.afk-seo-feedback' );
			var nonce = $( '.afk-batch' ).data( 'nonce' );

			if ( ! nonce ) {
				$out.text( 'Jeton de sécurité introuvable — rechargez la page.' );
				return;
			}

			$btn.prop( 'disabled', true );

			function step() {
				$.post( window.ajaxurl, {
					action: 'afk_pwa_seo_backfill',
					nonce: nonce
				} ).done( function ( res ) {
					if ( ! res || ! res.success ) {
						$out.text( ( res && res.data && res.data.message ) || 'Le traitement a échoué.' );
						$btn.prop( 'disabled', false );
						return;
					}

					$out.text( res.data.message );

					if ( res.data.remaining > 0 && res.data.done > 0 ) {
						step(); // Il reste du travail et le dernier lot a produit un effet.
					} else {
						$btn.prop( 'disabled', false );
					}
				} ).fail( function () {
					$out.text( 'Le serveur n’a pas répondu.' );
					$btn.prop( 'disabled', false );
				} );
			}

			step();
		} );

		/* ---------------- Médiathèque ---------------- */
		$( document ).on( 'click', '.afk-media-pick', function ( e ) {
			e.preventDefault();

			var $btn       = $( this );
			var targetId   = $btn.data( 'target' );
			var sizeTarget = $btn.data( 'size-target' ); // '' | 'screenshot_wide' | 'screenshot_mobile'

			var frame = wp.media( {
				title:    'Choisir une image',
				button:   { text: 'Utiliser cette image' },
				library:  { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();

				$( '#' + targetId ).val( att.url ).trigger( 'change' );

				// Aperçu immédiat.
				$( '#' + targetId.replace( 'afk-', 'afk-preview-' ) ).attr( 'src', att.url );

				// Remplit les dimensions pour les screenshots.
				if ( sizeTarget && att.width && att.height ) {
					$( '#afk-' + sizeTarget + '_w' ).val( att.width );
					$( '#afk-' + sizeTarget + '_h' ).val( att.height );
				}
			} );

			frame.open();
		} );

		// Code hexadécimal complet. Partagé par les aperçus et les champs
		// de couleur, donc déclaré avant les deux.
		var HEX = /^#[0-9a-f]{6}$/i;

		/* --------------------------------------------------------------
		 * Aperçus
		 *
		 * Les blocs d'aperçu déclarent ce qu'ils reflètent :
		 *   data-preview="clé"       → couleur de fond de l'élément
		 *   data-preview-text="clé"  → texte de l'élément
		 *
		 * Le lien est donc décrit dans le balisage, pas ici : ajouter un
		 * élément à un aperçu ne demande aucune modification du script.
		 * ------------------------------------------------------------ */

		var $apercus = $( '.afk-preview' );

		if ( $apercus.length ) {

			var champ = function ( cle ) {
				return $( '[name="afk_pwa_settings[' + cle + ']"]' );
			};

			/** Valeur courante d'un réglage, repli sur son indice grisé. */
			var valeurDe = function ( cle ) {
				var $c = champ( cle );

				if ( ! $c.length ) {
					return '';
				}

				var v = $.trim( $c.val() );

				return '' !== v ? v : ( $c.attr( 'placeholder' ) || '' );
			};

			/** Reflète une couleur, en ignorant un code incomplet. */
			var refleteCouleur = function ( cle ) {
				var v = valeurDe( cle );

				if ( ! HEX.test( v ) ) {
					return;
				}

				$apercus.find( '[data-preview="' + cle + '"]' ).css( 'background-color', v );
			};

			/** Reflète un texte. Un champ vidé retombe sur son indice. */
			var refleteTexte = function ( cle ) {
				$apercus.find( '[data-preview-text="' + cle + '"]' ).text( valeurDe( cle ) );
			};

			/**
			 * Barre du navigateur et note explicative, selon le mode.
			 *
			 * En « standalone » et « fullscreen » l'application occupe
			 * tout l'écran : montrer la barre donnerait une idée fausse.
			 */
			var refleteMode = function () {
				var mode    = $( '#afk-display' ).val() || 'standalone';
				var avecBarre = 'browser' === mode || 'minimal-ui' === mode;

				$apercus.find( '.afk-phone-bar' ).prop( 'hidden', ! avecBarre );

				$apercus.find( '.afk-preview-notes li' ).each( function () {
					var note = $( this ).data( 'note' );
					$( this ).prop( 'hidden', note !== 'display-' + ( avecBarre ? 'browser' : mode ) );
				} );
			};

			/**
			 * Bandeau d'installation : position, visuel, hauteur, et le
			 * parcours choisi dans les onglets de plateforme — chaque
			 * plateforme a son animation et son message, et le bouton
			 * n'existe que là où la fenêtre native peut s'ouvrir.
			 */
			var CLES_PLATEFORME = {
				android: { gif: 'install_gif_android', message: 'install_message' },
				// Samsung suit le parcours Android (même fenêtre native, même
				// visuel) : seule la note Play Protect s'y ajoute.
				samsung: { gif: 'install_gif_android', message: 'install_message' },
				ios:     { gif: 'install_gif_ios',     message: 'install_ios' },
				inapp:   { gif: 'install_gif_inapp',   message: 'install_inapp' }
			};

			var refleteBandeau = function () {
				var $banner = $apercus.find( '.afk-banner' );

				if ( ! $banner.length ) {
					return;
				}

				var plateforme = $( '#afk-plateformes .afk-mini-tab.active' ).data( 'plateforme' ) || 'android';
				var cles       = CLES_PLATEFORME[ plateforme ];

				$banner.attr( 'data-position', $( '#afk-banner_position' ).val() || 'bottom' );

				var visuel  = valeurDe( cles.gif );
				var hauteur = parseInt( valeurDe( 'banner_gif_height' ), 10 );

				// Même plancher que le module qui sert le vrai bandeau :
				// sous 40 px le visuel serait illisible, et d'anciennes
				// installations portent un 0 hérité d'une version passée.
				if ( ! ( hauteur >= 40 ) ) {
					hauteur = parseInt( champ( 'banner_gif_height' ).attr( 'placeholder' ), 10 ) || 200;
				}

				$banner.find( '.afk-banner-visuel' )
					.attr( 'src', visuel || $( '.afk-phone-icon' ).attr( 'src' ) || '' )
					.css( 'max-height', Math.round( hauteur / 2.4 ) + 'px' );

				$banner.find( '.afk-banner-message' ).text( valeurDe( cles.message ) );

				// La note Play Protect n'existe que sur Samsung ; le bouton,
				// partout où la fenêtre native peut s'ouvrir.
				$banner.find( '.afk-banner-note-texte' ).text( valeurDe( 'install_samsung' ) );
				$banner.find( '.afk-banner-note' ).prop( 'hidden', 'samsung' !== plateforme );
				$banner.find( '.afk-banner-bouton' )
					.prop( 'hidden', 'android' !== plateforme && 'samsung' !== plateforme );

				$apercus.find( '[data-plateforme-note]' ).each( function () {
					$( this ).prop( 'hidden', $( this ).data( 'plateforme-note' ) !== plateforme );
				} );
			};

			/** Où la fenêtre d'invitation sera proposée. */
			var refleteContexte = function () {
				var ou = $( '#afk-os_popup_context' ).val() || 'pwa';
				$apercus.find( '[data-contexte-note]' ).each( function () {
					$( this ).prop( 'hidden', $( this ).data( 'contexte-note' ) !== ou );
				} );
			};

			var rafraichit = function () {
				[ 'theme_color', 'background_color', 'accent_color' ].forEach( refleteCouleur );
				[ 'app_name', 'start_url', 'install_title', 'install_button',
				  'push_title', 'push_prompt', 'push_choose', 'push_accept',
				  'push_refuse', 'push_privacy' ].forEach( refleteTexte );
				refleteContexte();
				refleteMode();
				refleteBandeau();
			};

			// Un seul écouteur pour tout le formulaire : les aperçus ne
			// connaissent pas les champs, et réciproquement.
			$( '.afk-wrap' ).on( 'input change', 'input, select, textarea', rafraichit );

			// Le dépliage de l'aperçu : montrer ce que le visiteur découvre.
			$apercus.on( 'click', '.afk-modal-mock-deplier', function () {
				var ouvert = 'true' === $( this ).attr( 'aria-expanded' );
				$( this ).attr( 'aria-expanded', ouvert ? 'false' : 'true' )
					.siblings( '.afk-modal-mock-liste' ).prop( 'hidden', ouvert );
			} );

			$( '#afk-plateformes' ).on( 'click', '.afk-mini-tab', function () {
				$( this ).addClass( 'active' ).siblings().removeClass( 'active' );
				refleteBandeau();
			} );

			rafraichit();
		}

		/* --------------------------------------------------------------
		 * Aperçu des partages
		 *
		 * La carte est construite depuis la page réelle du contenu
		 * choisi : le serveur la lit et renvoie ses balises. Un onglet
		 * par canal — chacun compose sa carte différemment, le CSS s'en
		 * charge via l'attribut data-canal.
		 * ------------------------------------------------------------ */

		var $partage = $( '#afk-share-preview' );

		if ( $partage.length && window.afkSettings && afkSettings.shareNonce ) {

			// Une page déjà lue ne l'est pas deux fois : changer de canal
			// ne coûte rien, seul le changement de contenu interroge.
			var dejaLues = {};

			var rendCarte = function ( donnees ) {
				var $carte = $partage.find( '.afk-share-carte' );

				$carte.find( '.afk-share-image' )
					.attr( 'src', donnees.image || '' )
					.prop( 'hidden', ! donnees.image );

				$carte.find( '.afk-share-titre' ).text( donnees.title || '' );
				$carte.find( '.afk-share-desc' ).text( donnees.description || '' );

				// Les réseaux affichent le domaine, pas l'adresse complète.
				var domaine = '';
				if ( donnees.url ) {
					var lien = document.createElement( 'a' );
					lien.href = donnees.url;
					domaine   = lien.hostname;
				}
				$carte.find( '.afk-share-domaine' ).text( domaine );
			};

			var charge = function () {
				var id    = $( '#afk-share-article' ).val();
				var $etat = $partage.find( '.afk-share-etat' );

				if ( dejaLues[ id ] ) {
					rendCarte( dejaLues[ id ] );
					return;
				}

				$etat.text( afkSettings.shareLoading );
				$partage.find( '.afk-share-carte' ).css( 'opacity', 0.4 );

				$.post( afkSettings.ajaxUrl, {
					action:  'afk_share_preview',
					nonce:   afkSettings.shareNonce,
					post_id: id
				} ).done( function ( res ) {
					if ( res && res.success ) {
						dejaLues[ id ] = res.data;
						rendCarte( res.data );
						$etat.text( '' );
					} else {
						$etat.text( ( res && res.data && res.data.message ) || afkSettings.shareFailed );
					}
				} ).fail( function () {
					$etat.text( afkSettings.shareFailed );
				} ).always( function () {
					$partage.find( '.afk-share-carte' ).css( 'opacity', 1 );
				} );
			};

			$( '#afk-canaux' ).on( 'click', '.afk-mini-tab', function () {
				var canal = $( this ).data( 'canal' );

				$( this ).addClass( 'active' ).siblings().removeClass( 'active' );
				$partage.find( '.afk-share-carte' ).attr( 'data-canal', canal );

				$partage.find( '[data-canal-note]' ).each( function () {
					$( this ).prop( 'hidden', $( this ).data( 'canal-note' ) !== canal );
				} );
			} );

			$( '#afk-share-article' ).on( 'change', charge );

			charge();
		}

		/* --------------------------------------------------------------
		 * Champs de couleur
		 *
		 * Le carré et le champ texte décrivent la même valeur : ils se
		 * suivent l'un l'autre. Seul le champ texte porte un nom, donc
		 * lui seul est enregistré — le carré n'est qu'une commande.
		 * ------------------------------------------------------------ */

		var $champsCouleur = $( '.afk-color-field' );

		// Le carré vers le champ texte : à chaque déplacement dans le
		// sélecteur, pour que la valeur suive le geste en direct.
		$champsCouleur.on( 'input change', '.afk-color-swatch', function () {
			$( this ).closest( '.afk-color-field' )
				.find( '.afk-color' )
				.val( this.value.toLowerCase() )
				.removeClass( 'afk-color-invalide' );
		} );

		// Le champ texte vers le carré. Une valeur incomplète en cours de
		// frappe ne doit pas faire sauter le carré au noir : on ne le
		// met à jour qu'une fois le code complet.
		$champsCouleur.on( 'input', '.afk-color', function () {
			var $champ = $( this );
			var valeur = $.trim( this.value );
			var valide = HEX.test( valeur );

			$champ.toggleClass( 'afk-color-invalide', '' !== valeur && ! valide );

			if ( valide ) {
				$champ.closest( '.afk-color-field' ).find( '.afk-color-swatch' ).val( valeur );
			}
		} );

		// Retour à la valeur d'usine, sans passer par un enregistrement.
		$champsCouleur.on( 'click', '.afk-color-reset', function () {
			var $bloc  = $( this ).closest( '.afk-color-field' );
			var usine  = $( this ).data( 'default' );

			$bloc.find( '.afk-color' ).val( usine ).removeClass( 'afk-color-invalide' );

			if ( HEX.test( usine ) ) {
				$bloc.find( '.afk-color-swatch' ).val( usine );
			}
		} );

		/* --------------------------------------------------------------
		 * Tableaux répétables — lignes ajoutables et retirables
		 * ------------------------------------------------------------ */

		/**
		 * Rend un tableau répétable.
		 *
		 * @param {string} table   Sélecteur du tableau.
		 * @param {string} bouton  Sélecteur du bouton d'ajout.
		 * @param {string} modele  Sélecteur du modèle de ligne.
		 * @param {string} pivot   Champ portant l'indice, ex. 'post_type'.
		 */
		var initRepeater = function ( table, bouton, modele, pivot ) {
			var $table = $( table );

			if ( ! $table.length ) {
				return;
			}

			var motif = new RegExp( '\\[(\\d+)\\]\\[' + pivot + '\\]$' );

			/**
			 * Indice à donner à la prochaine ligne.
			 *
			 * On lit les indices présents plutôt que de compter les lignes :
			 * après une suppression, un simple décompte réattribuerait un
			 * indice déjà pris et les deux lignes s'écraseraient à l'envoi.
			 */
			var prochainIndice = function () {
				var max = -1;

				$table.find( 'tbody [name*="[' + pivot + ']"]' ).each( function () {
					var trouve = motif.exec( this.name );
					if ( trouve ) {
						max = Math.max( max, parseInt( trouve[1], 10 ) );
					}
				} );

				return max + 1;
			};

			var majLigneVide = function () {
				$table.find( '.afk-repeater-empty' )
					.toggle( 0 === $table.find( '.afk-repeater-row' ).length );
			};

			$( bouton ).on( 'click', function () {
				$table.find( 'tbody' ).append(
					$( modele ).html().replace( /__i__/g, prochainIndice() )
				);

				majLigneVide();
				$table.find( '.afk-repeater-row:last' ).find( 'input, select' ).first().trigger( 'focus' );
			} );

			$table.on( 'click', '.afk-repeater-remove', function () {
				$( this ).closest( '.afk-repeater-row' ).remove();
				majLigneVide();
			} );
		};

		initRepeater( '#afk-triggers', '#afk-trigger-add', '#afk-trigger-template', 'post_type' );
		initRepeater( '#afk-cssbg', '#afk-cssbg-add', '#afk-cssbg-template', 'selector' );

		/* --------------------------------------------------------------
		 * Clé d'envoi OneSignal — retrait d'une valeur héritée
		 *
		 * L'administration ne propose plus de saisir la clé : rangée en
		 * base, elle suivait toute sauvegarde et toute copie du site. Elle
		 * se déclare maintenant dans wp-config.php. Il reste à pouvoir
		 * effacer ce qu'une version antérieure y avait écrit.
		 * ------------------------------------------------------------ */

		$( document ).on( 'click', '#afk-push-key-clear', function () {
			var $res = $( '#afk-push-key-result' );

			if ( ! window.afkSettings || ! afkSettings.keyNonce ) {
				return;
			}

			if ( ! window.confirm( 'Retirer la clé de la base de données ? Si elle n’est pas déclarée dans wp-config.php, le site ne pourra plus envoyer de notifications lui-même.' ) ) {
				return;
			}

			$.post( afkSettings.ajaxUrl, {
				action: 'afk_save_push_key',
				nonce:  afkSettings.keyNonce
			} ).done( function ( res ) {
				var ok = res && res.success;
				$res.css( 'color', ok ? '#2f7d5d' : '#b32d2e' )
					.text( ok ? res.data.message : ( res.data && res.data.message ) || afkSettings.failed );
				if ( ok ) {
					$( '#afk-push-key-clear' ).hide();
				}
			} ).fail( function () {
				$res.css( 'color', '#b32d2e' ).text( afkSettings.failed );
			} );
		} );

		/* --------------------------------------------------------------
		 * Banc d'essai de la publication
		 *
		 * L'écran annonce AVANT de publier si le type choisi est pris en
		 * charge : publier sans déclencheur n'enverrait rien, et rien
		 * n'expliquerait ce silence.
		 * ------------------------------------------------------------ */

		var $testType = $( '#afk-test-type' );

		if ( $testType.length ) {

			var majTypeChoisi = function () {
				var $opt   = $testType.find( 'option:selected' );
				var pris   = '1' === String( $opt.data( 'pris' ) );
				var cible  = $opt.data( 'cible' );
				var visuel = $opt.data( 'visuel' );

				$( '#afk-test-pris' )
					.prop( 'hidden', false )
					.toggleClass( 'afk-test-ko', ! pris )
					.toggleClass( 'afk-test-ok', pris )
					.html(
						pris
							? '<span class="dashicons dashicons-yes-alt"></span> <strong>Type pris en charge.</strong> ' +
							  'La publication enverra une notification ' +
							  ( cible ? 'aux abonnés du centre d’intérêt « ' + cible + ' ».' : 'à tous les abonnés.' )
							: '<span class="dashicons dashicons-warning"></span> <strong>Type non pris en charge.</strong> ' +
							  'Aucun déclencheur ne vise ce type de contenu : le publier n’enverrait rien. ' +
							  'Ajoutez une ligne dans « Déclencheurs » ci-dessus.'
					);

				$( '#afk-test-valeurs' ).text(
					'Valeurs proposées : « ' + $opt.data( 'titre' ) + ' »' +
					( visuel ? ', visuel ' + visuel : ', sans visuel fourni' )
				);

				$( '#afk-test-publier' ).prop( 'disabled', ! pris );
			};

			$testType.on( 'change', majTypeChoisi );
			majTypeChoisi();

			$( '#afk-test-publier' ).on( 'click', function () {
				var $btn = $( this );
				var $res = $( '#afk-test-resultat' );

				$btn.prop( 'disabled', true );
				$res.prop( 'hidden', false ).html( '<p class="afk-hint">Publication en cours…</p>' );

				$.post( afkSettings.ajaxUrl, {
					action:    'afk_push_publish_test',
					nonce:     afkSettings.testNonce,
					post_type: $testType.val()
				} ).done( function ( r ) {
					if ( ! r || ! r.success ) {
						$res.html( '<p class="afk-test-ko">' +
							( ( r && r.data && r.data.message ) || afkSettings.failed ) + '</p>' );
						$btn.prop( 'disabled', false );
						return;
					}

					var d = r.data;
					var classe = 'ok' === d.etat ? 'afk-test-ok' : 'afk-test-ko';

					$res.html(
						'<p class="' + classe + '"><strong>' + d.message + '</strong></p>' +
						'<p class="afk-hint">Contenu créé : <a href="' + d.edition + '">' + d.titre + '</a>' +
						' — <a href="' + d.lien + '" target="_blank" rel="noopener">le voir sur le site</a>. ' +
						'Il reste publié : supprimez-le quand vous voudrez.</p>' +
						( d.image ? '<img src="' + d.image + '" alt="" style="max-width:90px;border-radius:6px;margin-top:6px" />' : '' )
					);

					$( '#afk-test-menage' ).prop( 'hidden', false );
					$btn.prop( 'disabled', false );
				} ).fail( function () {
					$res.html( '<p class="afk-test-ko">' + afkSettings.failed + '</p>' );
					$btn.prop( 'disabled', false );
				} );
			} );

			$( '#afk-test-menage' ).on( 'click', function () {
				var $btn = $( this );

				if ( ! window.confirm( 'Supprimer définitivement tous les contenus de test créés par cet outil ?' ) ) {
					return;
				}

				$btn.prop( 'disabled', true );

				$.post( afkSettings.ajaxUrl, {
					action: 'afk_push_cleanup_test',
					nonce:  afkSettings.testNonce
				} ).done( function ( r ) {
					$( '#afk-test-resultat' ).prop( 'hidden', false )
						.html( '<p class="afk-test-ok">' +
							( ( r && r.data && r.data.message ) || '' ) + '</p>' );
					$btn.prop( 'hidden', true );
				} ).always( function () {
					$btn.prop( 'disabled', false );
				} );
			} );
		}

		/* --------------------------------------------------------------
		 * Correction du nom de site chez Rank Math
		 *
		 * Un seul clic, une seule écriture. Les aperçus déjà lus sont
		 * périmés après coup : on vide leur mémoire pour que le prochain
		 * affichage relise la page corrigée.
		 * ------------------------------------------------------------ */

		$( document ).on( 'click', '#afk-fix-site-name', function () {
			var $btn = $( this );
			var $res = $( '#afk-fix-site-name-result' );

			if ( ! window.afkSettings || ! afkSettings.fixNameNonce ) {
				return;
			}

			$btn.prop( 'disabled', true );

			$.post( afkSettings.ajaxUrl, {
				action: 'afk_fix_site_name',
				nonce:  afkSettings.fixNameNonce
			} ).done( function ( res ) {
				if ( res && res.success ) {
					$res.css( 'color', '#2f7d5d' ).text( res.data.message );
					$btn.hide();
				} else {
					$res.css( 'color', '#b32d2e' )
						.text( ( res && res.data && res.data.message ) || afkSettings.failed );
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$res.css( 'color', '#b32d2e' ).text( afkSettings.failed );
				$btn.prop( 'disabled', false );
			} );
		} );

		/* --------------------------------------------------------------
		 * Notification de test
		 * ------------------------------------------------------------ */

		$( '#afk-push-test' ).on( 'click', function () {
			var $btn    = $( this );
			var $result = $( '#afk-push-test-result' );

			if ( ! window.afkSettings ) {
				return;
			}

			$btn.prop( 'disabled', true );
			$result.css( 'color', '' ).text( afkSettings.sending );

			$.post( afkSettings.ajaxUrl, {
				action: 'afk_push_test',
				nonce:  afkSettings.pushNonce
			} ).done( function ( res ) {
				var ok = res && res.success;
				$result.css( 'color', ok ? '#2f7d5d' : '#b32d2e' )
					.text( ok ? res.data.message : ( res.data && res.data.message ) || afkSettings.failed );
			} ).fail( function () {
				$result.css( 'color', '#b32d2e' ).text( afkSettings.failed );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
} )( jQuery );
