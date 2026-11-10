<?php
/**
 * Bleu de marque : remplace l'ancienne palette de bleus (#0F4D73 et ses dérivés : #123C56, #4A6A7E…) par la couleur principale (#011638 par défaut)
 * dans les feuilles de style de l'extension AFRICART PWA, SANS modifier ses fichiers.
 *
 * Principe : on lit ses CSS sur le disque, on ne garde que les déclarations qui contiennent l'ancien bleu
 * (avec leurs sélecteurs et leurs @media), on y met le nouveau bleu, et on charge ce petit CSS juste après
 * les siens. Même sélecteurs, chargés après : ils l'emportent. Le résultat est mis en cache et recalculé
 * automatiquement si un fichier de l'extension PWA change (mise à jour par son développeur).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Toute couleur CSS écrite en hex ou en rgb()/rgba() (les candidates ; le tri « bleu ou pas » se fait ensuite). */
function afk_acc_old_blue_regex() {
	return '~#(?:[0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})\b|rgba?\(\s*[0-9.]+%?\s*,\s*[0-9.]+%?\s*,\s*[0-9.]+%?\s*(?:,\s*[0-9.]+%?\s*)?\)~i';
}

function afk_acc_rgb2hsl( $r, $g, $b ) {
	$r /= 255; $g /= 255; $b /= 255;
	$mx = max( $r, $g, $b ); $mn = min( $r, $g, $b );
	$l  = ( $mx + $mn ) / 2; $h = 0; $s = 0;
	if ( $mx !== $mn ) {
		$d = $mx - $mn;
		$s = $l > 0.5 ? $d / ( 2 - $mx - $mn ) : $d / ( $mx + $mn );
		if ( $mx === $r ) { $h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 ); }
		elseif ( $mx === $g ) { $h = ( $b - $r ) / $d + 2; }
		else { $h = ( $r - $g ) / $d + 4; }
		$h *= 60;
	}
	return array( $h, $s, $l );
}

function afk_acc_hsl2rgb( $h, $s, $l ) {
	$h /= 360;
	if ( 0.0 === (float) $s ) {
		$v = (int) round( $l * 255 );
		return array( $v, $v, $v );
	}
	$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
	$p = 2 * $l - $q;
	$f = function ( $t ) use ( $p, $q ) {
		if ( $t < 0 ) { $t += 1; }
		if ( $t > 1 ) { $t -= 1; }
		if ( $t < 1 / 6 ) { return $p + ( $q - $p ) * 6 * $t; }
		if ( $t < 1 / 2 ) { return $q; }
		if ( $t < 2 / 3 ) { return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6; }
		return $p;
	};
	return array( (int) round( $f( $h + 1 / 3 ) * 255 ), (int) round( $f( $h ) * 255 ), (int) round( $f( $h - 1 / 3 ) * 255 ) );
}

/**
 * Transpose un bleu de l'ancienne palette (teinte ~203°, bleu #0F4D73) vers la nouvelle (#011638 par défaut).
 * Renvoie null si la couleur n'est pas un bleu de l'ancienne palette (rouges, verts, or, gris neutres : intacts).
 *  - les bleus foncés de base (#0F4D73, #123C56 l'encre de l'en-tête, etc.) deviennent exactement le nouveau bleu ;
 *  - les plus foncés (survols) restent un cran plus sombres ;
 *  - les bleus moyens et clairs (textes doux, bordures, fonds teintés) gardent leur clarté relative, dans la nouvelle teinte.
 */
function afk_acc_map_blue( $r, $g, $b ) {
	list( $h, $s, $l ) = afk_acc_rgb2hsl( $r, $g, $b );
	if ( $h < 185 || $h > 212 || $s < 0.12 ) {
		return null;
	}
	list( $nr, $ng, $nb ) = afk_acc_new_blue_rgb();
	if ( $s >= 0.5 && $l >= 0.195 && $l <= 0.26 ) {
		return array( $nr, $ng, $nb );
	}
	list( $nh, $ns, $nl ) = afk_acc_rgb2hsl( $nr, $ng, $nb );
	$base_l = 0.25; // clarté de l'ancien bleu
	$s2     = min( 1, $s * ( $ns / 0.77 ) );
	if ( $l < $base_l ) {
		$l2 = $l * $nl / $base_l;
	} else {
		$l2 = $nl + ( $l - $base_l ) * ( 1 - $nl ) / ( 1 - $base_l );
	}
	return afk_acc_hsl2rgb( $nh, $s2, $l2 );
}

function afk_acc_new_blue_rgb() {
	$hex = ltrim( (string) afk_acc_opt( 'primary' ), '#' );
	if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
		$hex = '011638';
	}
	return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), '#' . $hex );
}

/** Décode une couleur hex/rgb : array( r, g, b, alpha|null, format ) ou null. */
function afk_acc_parse_color( $v ) {
	if ( '#' === $v[0] ) {
		$h = substr( $v, 1 );
		if ( strlen( $h ) <= 4 ) {
			$h = implode( '', array_map( function ( $c ) { return $c . $c; }, str_split( $h ) ) );
		}
		return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), strlen( $h ) === 8 ? substr( $h, 6, 2 ) : null, 'hex' );
	}
	preg_match_all( '~[0-9.]+%?~', $v, $m );
	$n = $m[0];
	if ( count( $n ) < 3 ) {
		return null;
	}
	$c = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$c[] = '%' === substr( $n[ $i ], -1 ) ? (int) round( (float) $n[ $i ] * 2.55 ) : (int) $n[ $i ];
	}
	return array( $c[0], $c[1], $c[2], isset( $n[3] ) ? $n[3] : null, 'rgb' );
}

/** Remplace chaque bleu de l'ancienne palette ; $found passe à true si au moins un a été remplacé. */
function afk_acc_swap_blue( $str, &$found = null ) {
	$found = false;
	return preg_replace_callback(
		afk_acc_old_blue_regex(),
		function ( $m ) use ( &$found ) {
			$c = afk_acc_parse_color( $m[0] );
			if ( ! $c ) {
				return $m[0];
			}
			$new = afk_acc_map_blue( $c[0], $c[1], $c[2] );
			if ( ! $new ) {
				return $m[0];
			}
			$found = true;
			if ( 'hex' === $c[4] ) {
				return sprintf( '#%02x%02x%02x', $new[0], $new[1], $new[2] ) . ( null !== $c[3] ? $c[3] : '' );
			}
			return null !== $c[3] ? "rgba({$new[0]}, {$new[1]}, {$new[2]}, {$c[3]})" : "rgb({$new[0]}, {$new[1]}, {$new[2]})";
		},
		$str
	);
}

function afk_acc_has_old_blue( $str ) {
	afk_acc_swap_blue( $str, $found );
	return $found;
}

/** Découpe des déclarations sur « ; » en ignorant ceux entre parenthèses (url(data:...;base64), etc.). */
function afk_acc_split_decls( $body ) {
	$out   = array();
	$buf   = '';
	$depth = 0;
	$len   = strlen( $body );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $body[ $i ];
		if ( '(' === $c ) {
			$depth++;
		} elseif ( ')' === $c ) {
			$depth = max( 0, $depth - 1 );
		}
		if ( ';' === $c && 0 === $depth ) {
			$out[] = $buf;
			$buf   = '';
			continue;
		}
		$buf .= $c;
	}
	if ( '' !== trim( $buf ) ) {
		$out[] = $buf;
	}
	return $out;
}

/** Parcourt un bloc CSS et renvoie uniquement ce qui contient l'ancien bleu, recoloré. */
function afk_acc_extract_blue( $css, &$i ) {
	$out = '';
	$len = strlen( $css );
	while ( $i < $len ) {
		$open  = strpos( $css, '{', $i );
		$close = strpos( $css, '}', $i );
		if ( false === $open || ( false !== $close && $close < $open ) ) {
			$i = ( false === $close ) ? $len : $close + 1; // fin du bloc courant
			return $out;
		}
		$prelude = trim( substr( $css, $i, $open - $i ) );
		$i       = $open + 1;
		if ( preg_match( '~^@(media|supports|container|layer|document)~i', $prelude ) ) {
			$inner = afk_acc_extract_blue( $css, $i );
			if ( '' !== $inner ) {
				$out .= $prelude . '{' . $inner . '}';
			}
			continue;
		}
		// Bloc « feuille » : on cherche l'accolade fermante correspondante (au cas où il y aurait du nesting).
		$depth = 1;
		$start = $i;
		while ( $i < $len && $depth > 0 ) {
			if ( '{' === $css[ $i ] ) {
				$depth++;
			} elseif ( '}' === $css[ $i ] ) {
				$depth--;
			}
			$i++;
		}
		$body = substr( $css, $start, $i - 1 - $start );
		if ( '' === $prelude || ! afk_acc_has_old_blue( $body ) ) {
			continue;
		}
		if ( preg_match( '~^@(-webkit-)?keyframes~i', $prelude ) || false !== strpos( $body, '{' ) ) {
			$out .= $prelude . '{' . afk_acc_swap_blue( $body ) . '}'; // bloc complexe : recopié en entier
			continue;
		}
		if ( 0 === strpos( $prelude, '@' ) ) {
			continue; // @font-face, @page… : pas concernés
		}
		$keep = array();
		foreach ( afk_acc_split_decls( $body ) as $d ) {
			if ( afk_acc_has_old_blue( $d ) ) {
				$keep[] = trim( afk_acc_swap_blue( $d ) );
			}
		}
		if ( $keep ) {
			$out .= $prelude . '{' . implode( ';', $keep ) . '}';
		}
	}
	return $out;
}

function afk_acc_pwa_css_files() {
	$dir = WP_PLUGIN_DIR . '/africart-pwa/assets/css';
	$fs  = is_dir( $dir ) ? glob( $dir . '/*.css' ) : array();
	return $fs ? $fs : array();
}

function afk_acc_pwa_blue_override_css() {
	$files = afk_acc_pwa_css_files();
	if ( ! $files ) {
		return '';
	}
	$sig = AFK_ACC_VERSION . '|' . afk_acc_opt( 'primary' );
	foreach ( $files as $f ) {
		$sig .= '|' . basename( $f ) . ':' . filemtime( $f ) . ':' . filesize( $f );
	}
	$key    = 'afk_acc_pwa_blue_' . md5( $sig );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return $cached;
	}
	$out = '';
	foreach ( $files as $f ) {
		$css = (string) file_get_contents( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$css = preg_replace( '~/\*.*?\*/~s', '', $css );
		$i   = 0;
		$len = strlen( $css );
		while ( $i < $len ) {
			$before = $i;
			$out   .= afk_acc_extract_blue( $css, $i );
			if ( $i <= $before ) {
				break;
			}
		}
	}
	set_transient( $key, $out, WEEK_IN_SECONDS );
	return $out;
}

add_action( 'wp_enqueue_scripts', function () {
	$css = afk_acc_pwa_blue_override_css();
	if ( '' === $css ) {
		return;
	}
	// Chargé après les feuilles de l'extension PWA (on les déclare comme dépendances).
	$deps = array();
	foreach ( wp_styles()->registered as $handle => $style ) {
		if ( is_string( $style->src ) && false !== strpos( $style->src, '/africart-pwa/' ) ) {
			$deps[] = $handle;
		}
	}
	wp_register_style( 'afk-acc-pwa-blue', false, $deps, AFK_ACC_VERSION );
	wp_enqueue_style( 'afk-acc-pwa-blue' );
	wp_add_inline_style( 'afk-acc-pwa-blue', '/* AFRICART : ancien bleu de l’extension PWA remplacé par ' . esc_html( afk_acc_opt( 'primary' ) ) . " */\n" . $css );
}, 999 );
