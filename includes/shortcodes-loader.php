<?php
/**
 * Shortcodes loader for WF Match Reign Processor
 *
 * Loads and registers publicly available shortcodes used across the site.
 * This loader maps known shortcode tags to implementation functions.
 *
 * Important: ensure explicit_map entries point to the correct function names.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Attempt to include (or require) the files that declare shortcode functions.
// We don't hard-fail if files are missing so plugin remains resilient.
$shortcode_includes = array(
	'includes/shortcodes/shortcode-match-listing.php',
	'includes/shortcodes/shortcode-superstar-record.php',
	'includes/shortcodes/shortcode-superstar-reigns.php',
	'includes/shortcodes/shortcode-superstar-reigns.php', // kept for compatibility
	// add other shortcode files here if present
);

foreach ( $shortcode_includes as $rel ) {
	$path = plugin_dir_path( __DIR__ ) . $rel;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

// Register explicit mapping of shortcode tag => function name.
// IMPORTANT: ensure mappings point to the actual function names declared in the files.
$explicit_map = array(
	'wf_superstar_reigns'          => 'wf_shortcode_superstar_reigns',
	'match_participants_title_acf' => 'jjc_mh_match_participants_title_acf',
	'wf_championship_stats'        => 'wf_shortcode_championship_stats',
	// FIXED: map wf_superstar_record to the correct implementation function name
	'wf_superstar_record'          => 'wf_shortcode_superstar_record',
	'participants_title_acf'       => 'jjc_mh_match_participants_title_acf',
	'wf_match_participants'        => 'jjc_mh_match_participants_title_acf',
);

// Register those explicit tags
foreach ( $explicit_map as $tag => $fn ) {
	if ( shortcode_exists( $tag ) ) continue;
	if ( function_exists( $fn ) ) {
		add_shortcode( $tag, $fn );
		continue;
	}
	// Add a lazy loader: the tag is registered with a wrapper that calls the function
	add_shortcode( $tag, function( $atts = array(), $content = null ) use ( $fn ) {
		if ( function_exists( $fn ) ) return call_user_func( $fn, $atts, $content );
		return '';
	} );
}

// Also auto-register any global functions that follow the wf_*_shortcode convention
add_action( 'init', function() {
	global $wp_filter;
	if ( ! function_exists( 'get_defined_functions' ) ) return;
	$defs = get_defined_functions();
	$user = isset( $defs['user'] ) ? $defs['user'] : array();
	foreach ( $user as $fn ) {
		// pattern: wf_<tag>_shortcode -> registers as tag (strip wf_ and _shortcode)
		if ( strpos( $fn, 'wf_' ) === 0 && substr( $fn, -10 ) === '_shortcode' ) {
			$tag = substr( $fn, 3, -10 );
			if ( $tag && ! shortcode_exists( $tag ) ) add_shortcode( $tag, $fn );
		}
	}
}, 5 );