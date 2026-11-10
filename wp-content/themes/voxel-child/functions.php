<?php

// enqueue child theme styles
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style( 'child-style', get_stylesheet_uri() );
    if ( is_rtl() ) {
    	wp_enqueue_style( 'voxel-rtl', get_template_directory_uri() . '/rtl.css', [], wp_get_theme()->get('Version') );
    }
}, 500 );

// happy coding :)

/* Autoriser les fichiers SVG */
function wpc_mime_types($mimes) {
	$mimes['svg'] = 'image/svg xml';
	return $mimes;
}
add_filter('upload_mimes', 'wpc_mime_types');