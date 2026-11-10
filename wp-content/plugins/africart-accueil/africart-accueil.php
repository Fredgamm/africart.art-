<?php
/**
 * Plugin Name:       AFRICART – Page d'accueil
 * Description:       Sections de la page d'accueil AFRICART en shortcodes (hero + recherche, mur d'œuvres, index, bandeau, page artiste, à propos), chargement de la police BrutGrotesque, et formulaire « Question sur une œuvre » (CF7) sur la fiche œuvre.
 * Version:           1.0.9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Solutions Web
 * Author URI:        https://solutionsweb.net
 * Text Domain:       africart-accueil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFK_ACC_VERSION', '1.0.9' );
define( 'AFK_ACC_FILE', __FILE__ );
define( 'AFK_ACC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFK_ACC_URL', plugin_dir_url( __FILE__ ) );

/*
 * Structure Voxel du type « oeuvre » (relevée le 15/10/2026) :
 *  - titre            : post_title
 *  - photo principale : champ image « cover » (méta `cover`, ID(s) séparés par des virgules)
 *  - photos annexes   : champ image « gallery »
 *  - artiste          : champ texte « text » (méta `text`, « Nom de l'artiste »)
 *  - type d'art       : taxonomie `oeuvre_art`
 * Ces clés sont filtrables si la structure évolue.
 */
define( 'AFK_ACC_POST_TYPE', 'oeuvre' );

require_once AFK_ACC_DIR . 'includes/options.php';
require_once AFK_ACC_DIR . 'includes/data.php';
require_once AFK_ACC_DIR . 'includes/assets.php';
require_once AFK_ACC_DIR . 'includes/shortcodes.php';
require_once AFK_ACC_DIR . 'includes/rest.php';
require_once AFK_ACC_DIR . 'includes/oeuvre-form.php';
require_once AFK_ACC_DIR . 'includes/brand-colors.php';
