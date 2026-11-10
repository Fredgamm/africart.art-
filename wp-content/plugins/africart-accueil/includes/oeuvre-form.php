<?php
/**
 * Formulaire « Question sur une œuvre » (Contact Form 7) sur la fiche œuvre Voxel.
 *
 * 1. Contexte : le template Voxel est rendu hors de la boucle, CF7 ne connaît donc pas l'œuvre
 *    (_wpcf7_container_post = 0) et [_post_title] / [_post_url] restaient vides dans l'e-mail.
 *    On lui donne l'œuvre affichée.
 * 2. Style : aux couleurs du site (bleu principal réglable, #011638 par défaut ; or #D9BA61), limité au bloc .afk-cf7-oeuvre.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * L'œuvre concernée. CF7 fusionne nos champs cachés sans écraser les siens (`+=`), on ne peut donc pas
 * corriger _wpcf7_container_post (laissé à 0 par le template Voxel). On envoie l'ID dans un champ à nous,
 * puis on fournit [_post_title], [_post_url] et [_post_id] à partir de lui, après vérification.
 */
add_filter( 'wpcf7_form_hidden_fields', function ( $fields ) {
	if ( is_singular( AFK_ACC_POST_TYPE ) ) {
		$fields['afk_oeuvre'] = (string) get_queried_object_id();
	}
	return $fields;
} );

add_filter( 'wpcf7_special_mail_tags', function ( $output, $name, $html = false, $mail_tag = null ) {
	if ( ! in_array( $name, array( '_post_title', '_post_url', '_post_id', '_post_name' ), true ) ) {
		return $output;
	}
	if ( null !== $output && '' !== $output && '0' !== (string) $output ) {
		return $output; // CF7 connaît déjà la page : on ne touche à rien.
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$id   = isset( $_POST['afk_oeuvre'] ) ? absint( wp_unslash( $_POST['afk_oeuvre'] ) ) : 0;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || AFK_ACC_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
		return $output;
	}
	switch ( $name ) {
		case '_post_title':
			return html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		case '_post_url':
			return get_permalink( $post );
		case '_post_name':
			return $post->post_name;
		default:
			return (string) $post->ID;
	}
}, 20, 4 );

/* CF7 ajoute <p> et <br> automatiquement (autop) : on le coupe uniquement pour ce formulaire,
   reconnu à sa classe afk-cf7-oeuvre, sans toucher aux autres formulaires du site. */
add_filter( 'wpcf7_autop_or_not', function ( $autop ) {
	$cf = function_exists( 'wpcf7_get_current_contact_form' ) ? wpcf7_get_current_contact_form() : null;
	if ( $cf && false !== strpos( (string) $cf->prop( 'form' ), 'afk-cf7-oeuvre' ) ) {
		return false;
	}
	return $autop;
} );

/* ---------------------------------------------------------------------------
 * Anti-spam intégré, sans service externe ni clé :
 *  - pot de miel : un champ « Site web » invisible pour les humains, que les robots remplissent ;
 *  - délai minimum : un envoi moins de 4 s après l'affichage du formulaire est refusé
 *    (horodatage signé, donc infalsifiable ; compatible avec le cache de page, qui ne fait que vieillir l'horodatage) ;
 *  - liens : plus de 2 liens dans la question = spam.
 * CF7 traite alors l'envoi comme du spam : rien n'est envoyé, le visiteur voit un message d'erreur.
 * ------------------------------------------------------------------------- */
function afk_acc_is_oeuvre_form( $cf ) {
	return $cf && false !== strpos( (string) $cf->prop( 'form' ), 'afk-cf7-oeuvre' );
}

function afk_acc_form_sig( $t ) {
	return substr( hash_hmac( 'sha256', 'afk-cf7|' . $t, wp_salt( 'nonce' ) ), 0, 20 );
}

add_filter( 'wpcf7_form_hidden_fields', function ( $fields ) {
	$cf = function_exists( 'wpcf7_get_current_contact_form' ) ? wpcf7_get_current_contact_form() : null;
	if ( afk_acc_is_oeuvre_form( $cf ) ) {
		$t                  = (string) time();
		$fields['afk_t']    = $t . '.' . afk_acc_form_sig( $t );
	}
	return $fields;
}, 20 );

add_filter( 'wpcf7_form_elements', function ( $html ) {
	$cf = function_exists( 'wpcf7_get_current_contact_form' ) ? wpcf7_get_current_contact_form() : null;
	if ( afk_acc_is_oeuvre_form( $cf ) ) {
		$html .= '<span class="afk-hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"><label>Site web <input type="text" name="afk_site" value="" tabindex="-1" autocomplete="off"></label></span>';
	}
	return $html;
} );

add_filter( 'wpcf7_spam', function ( $spam, $submission = null ) {
	if ( $spam ) {
		return $spam;
	}
	if ( ! $submission && class_exists( 'WPCF7_Submission' ) ) {
		$submission = WPCF7_Submission::get_instance();
	}
	if ( ! $submission || ! afk_acc_is_oeuvre_form( $submission->get_contact_form() ) ) {
		return $spam;
	}
	$reason = '';
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- CF7 gère déjà le nonce/REST.
	$hp = isset( $_POST['afk_site'] ) ? trim( (string) wp_unslash( $_POST['afk_site'] ) ) : '';
	$ts = isset( $_POST['afk_t'] ) ? (string) wp_unslash( $_POST['afk_t'] ) : '';
	// phpcs:enable
	if ( '' !== $hp ) {
		$reason = 'pot de miel rempli';
	} else {
		$parts = explode( '.', $ts );
		if ( 2 !== count( $parts ) || ! hash_equals( afk_acc_form_sig( $parts[0] ), $parts[1] ) ) {
			$reason = 'horodatage absent ou invalide';
		} elseif ( time() - (int) $parts[0] < 4 ) {
			$reason = 'envoi trop rapide';
		} else {
			$q = (string) $submission->get_posted_data( 'question' );
			if ( preg_match_all( '~https?://|www\.~i', $q ) > 2 ) {
				$reason = 'trop de liens';
			}
		}
	}
	if ( $reason ) {
		if ( method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log( array( 'agent' => 'afk-anti-spam', 'reason' => $reason ) );
		}
		return true;
	}
	return $spam;
}, 10, 2 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( AFK_ACC_POST_TYPE ) ) {
		return;
	}
	wp_register_style( 'afk-acc-oeuvre-form', false, array(), AFK_ACC_VERSION );
	wp_enqueue_style( 'afk-acc-oeuvre-form' );
	wp_add_inline_style( 'afk-acc-oeuvre-form', str_replace( '#0F4D73', esc_attr( afk_acc_opt( 'primary' ) ), afk_acc_oeuvre_form_css() ) );
}, 30 );

function afk_acc_oeuvre_form_css() {
	return <<<'CSS'
.afk-cf7-oeuvre{--afk-f-blue:#0F4D73;--afk-f-gold:#D9BA61;display:flex;flex-direction:column;gap:14px;max-width:560px;margin-top:10px;color:var(--afk-f-blue);font-family:inherit}
.afk-cf7-oeuvre .afk-cf7-ligne{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.afk-cf7-oeuvre label{display:flex;flex-direction:column;gap:6px;margin:0;font-size:13px;font-weight:500;line-height:1.3;color:var(--afk-f-blue)}
.afk-cf7-oeuvre .wpcf7-form-control-wrap{display:block}
.afk-cf7-oeuvre br{display:none}
.afk-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}
.afk-cf7-oeuvre.afk-cf7-oeuvre .wpcf7-form-control:not(.wpcf7-submit){width:100%!important;box-sizing:border-box!important;margin:0!important;padding:10px 12px!important;border:1.5px solid var(--afk-f-blue)!important;border-radius:0!important;background:#fff!important;box-shadow:none!important;font-family:inherit!important;font-size:15px!important;line-height:1.4!important;color:#18222E!important}
.afk-cf7-oeuvre.afk-cf7-oeuvre textarea.wpcf7-form-control{height:130px!important;min-height:100px;resize:vertical}
.afk-cf7-oeuvre.afk-cf7-oeuvre .wpcf7-form-control:not(.wpcf7-submit):focus{outline:2px solid var(--afk-f-gold)!important;outline-offset:1px}
.afk-cf7-oeuvre .afk-cf7-mention{margin:0;font-size:12px;color:#5B6673}
.afk-cf7-oeuvre.afk-cf7-oeuvre .wpcf7-submit{align-self:flex-start;margin:0!important;padding:13px 24px!important;border:0!important;border-radius:0!important;background:var(--afk-f-blue)!important;color:#fff!important;font-family:inherit!important;font-size:15px!important;font-weight:600!important;cursor:pointer;transition:background-color .2s,color .2s}
.afk-cf7-oeuvre.afk-cf7-oeuvre .wpcf7-submit:hover,.afk-cf7-oeuvre.afk-cf7-oeuvre .wpcf7-submit:focus-visible{background:var(--afk-f-gold)!important;color:var(--afk-f-blue)!important}
.afk-cf7-oeuvre .wpcf7-not-valid-tip{margin-top:4px;font-size:12px;color:#B4533A}
.afk-cf7-oeuvre .wpcf7-spinner{margin:0 0 0 12px}
.wpcf7 form:has(.afk-cf7-oeuvre) .wpcf7-response-output{max-width:560px;margin:14px 0 0!important;padding:10px 14px!important;font-size:14px;border-width:1.5px!important}
@media (max-width:480px){.afk-cf7-oeuvre .afk-cf7-ligne{grid-template-columns:1fr}.afk-cf7-oeuvre.afk-cf7-oeuvre .wpcf7-submit{align-self:stretch}}
CSS;
}
