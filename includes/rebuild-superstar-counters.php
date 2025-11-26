<?php
/**
 * One-off runner to update match snapshots and rebuild superstar counters.
 *
 * Usage (in browser while logged in as an admin):
 *  - Update snapshots for specific matches:
 *      /wp-content/plugins/wf-match-reign-processor/rebuild-counters-runner.php?action=update_snapshots&match_ids=4273,4240
 *  - Recompute counters for specific superstars:
 *      /wp-content/plugins/wf-match-reign-processor/rebuild-counters-runner.php?action=recompute&superstar_ids=1557,2001
 *  - Update snapshots for matches then recompute counters for participants in those matches:
 *      /wp-content/plugins/wf-match-reign-processor/rebuild-counters-runner.php?action=both&match_ids=4273,4240
 *  - Recompute counters for all known participants (slow on large sites):
 *      /wp-content/plugins/wf-match-reign-processor/rebuild-counters-runner.php?action=recompute&all=1
 *  - Dry run (show what would be done without writing):
 *      add &dry=1 to any request
 *
 * Security:
 *  - Only accessible to logged-in users with 'manage_options'.
 *  - Delete this file after use.
 *
 * Notes:
 *  - This script uses the plugin's wf_update_match_snapshot, wf_expand_match_participants_to_individuals,
 *    wf_get_all_participant_ids and wf_recompute_superstar_counters functions when available.
 *  - If a function is missing the script will skip that step and report it.
 */

set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');

// Boot WP if needed
if ( ! function_exists( 'is_user_logged_in' ) ) {
	$max_levels = 6;
	$dir = __DIR__;
	$wp_loaded = false;
	for ( $i = 0; $i < $max_levels; $i++ ) {
		$try = realpath( $dir . str_repeat( '/..', $i ) . '/wp-load.php' );
		if ( $try && is_file( $try ) ) {
			require_once $try;
			$wp_loaded = true;
			break;
		}
	}
	if ( ! $wp_loaded ) {
		http_response_code(500);
		echo json_encode( array( 'error' => 'Could not locate wp-load.php. Place this file inside your WP install and try again.' ), JSON_PRETTY_PRINT );
		exit;
	}
}

// Authz
if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
	http_response_code(403);
	echo json_encode( array( 'error' => 'Forbidden. You must be logged in as an administrator to run this.' ), JSON_PRETTY_PRINT );
	exit;
}

// Helpers
function _rcr_parse_ids( $str ) {
	$out = array();
	if ( empty( $str ) ) return $out;
	$parts = preg_split( '/[,\s]+/', trim( $str ) );
	foreach ( $parts as $p ) {
		$p = trim( $p );
		if ( $p === '' ) continue;
		$id = intval( $p );
		if ( $id ) $out[] = $id;
	}
	return array_values( array_unique( $out ) );
}

function _rcr_response_and_exit( $data ) {
	echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

// Parse inputs
$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'both'; // update_snapshots | recompute | both
$match_ids = isset( $_GET['match_ids'] ) ? _rcr_parse_ids( $_GET['match_ids'] ) : array();
$superstar_ids = isset( $_GET['superstar_ids'] ) ? _rcr_parse_ids( $_GET['superstar_ids'] ) : array();
$all_flag = isset( $_GET['all'] ) && ( $_GET['all'] === '1' || $_GET['all'] === 'true' );
$dry = isset( $_GET['dry'] ) && ( $_GET['dry'] === '1' || $_GET['dry'] === 'true' );

// Result container
$result = array(
	'started_at' => date( 'c' ),
	'php_version' => phpversion(),
	'wp_version' => get_bloginfo( 'version' ),
	'action' => $action,
	'dry_run' => (bool) $dry,
	'requested_match_ids' => $match_ids,
	'requested_superstar_ids' => $superstar_ids,
	'steps' => array(),
);

// Resolve match IDs: if none and all flag for snapshots, fetch all matches
if ( empty( $match_ids ) && $all_flag && ( $action === 'update_snapshots' || $action === 'both' ) ) {
	// fetch all match IDs (lightweight)
	$args = array(
		'post_type' => defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match',
		'post_status' => array( 'publish', 'private', 'draft' ),
		'numberposts' => -1,
		'fields' => 'ids',
	);
	$mids = get_posts( $args );
	if ( is_array( $mids ) ) $match_ids = array_map( 'intval', $mids );
	$result['steps'][] = 'fetched_all_matches_for_snapshots';
}

// Step 1: Update snapshots for provided matches
if ( in_array( $action, array( 'update_snapshots', 'both' ), true ) ) {
	$step = array( 'name' => 'update_snapshots', 'attempted' => array(), 'skipped' => array(), 'errors' => array() );

	if ( empty( $match_ids ) ) {
		$step['skipped'][] = 'no_match_ids_provided';
	} else {
		foreach ( $match_ids as $mid ) {
			$step['attempted'][] = $mid;
			if ( $dry ) continue;
			if ( function_exists( 'wf_update_match_snapshot' ) ) {
				try {
					$res = wf_update_match_snapshot( $mid );
					$step['result'][ $mid ] = $res ? 'updated' : 'no_changes_or_failed';
				} catch ( Exception $e ) {
					$step['errors'][ $mid ] = $e->getMessage();
				}
			} else {
				$step['skipped'][] = 'wf_update_match_snapshot_missing';
				break;
			}
		}
	}
	$result['steps'][] = $step;
}

// Step 2: Build list of superstar IDs to recompute
$to_recompute = array();

// If specific superstar_ids provided, include them
if ( ! empty( $superstar_ids ) ) {
	$to_recompute = array_merge( $to_recompute, $superstar_ids );
}

// If match_ids provided, expand participants to individuals
if ( ! empty( $match_ids ) ) {
	foreach ( $match_ids as $mid ) {
		if ( function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
			try {
				$parts = wf_expand_match_participants_to_individuals( $mid );
				if ( is_array( $parts ) ) $to_recompute = array_merge( $to_recompute, $parts );
			} catch ( Exception $e ) {
				// ignore individual errors but record
				$result['steps'][] = array( 'name' => 'expand_error', 'match_id' => $mid, 'msg' => $e->getMessage() );
			}
		} else {
			// fallback: read match_participants_expanded meta
			$exp = get_post_meta( $mid, 'match_participants_expanded', true );
			if ( is_array( $exp ) ) $to_recompute = array_merge( $to_recompute, $exp );
		}
	}
}

// If all flag and action includes recompute, get all participants
if ( $all_flag && ( $action === 'recompute' || $action === 'both' ) ) {
	if ( function_exists( 'wf_get_all_participant_ids' ) ) {
		try {
			$all_ids = wf_get_all_participant_ids();
			if ( is_array( $all_ids ) ) $to_recompute = array_merge( $to_recompute, $all_ids );
			$result['steps'][] = 'collected_all_participant_ids_via_helper';
		} catch ( Exception $e ) {
			$result['steps'][] = array( 'name' => 'get_all_participants_error', 'msg' => $e->getMessage() );
		}
	} else {
		// fallback: search postmeta for match_participants_expanded or wf_match_snapshot (best-effort)
		global $wpdb;
		$found = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s,%s) LIMIT 10000",
			'match_participants_expanded', 'wf_match_snapshot'
		) );
		$found = array_map( 'intval', (array) $found );
		foreach ( $found as $mid ) {
			$exp = get_post_meta( $mid, 'match_participants_expanded', true );
			if ( is_array( $exp ) ) $to_recompute = array_merge( $to_recompute, $exp );
		}
		$result['steps'][] = 'collected_candidate_participants_via_postmeta';
	}
}

// Normalize list
$to_recompute = array_values( array_unique( array_filter( array_map( 'intval', $to_recompute ) ) ) );

$result['to_recompute_count'] = count( $to_recompute );
$result['to_recompute_sample'] = array_slice( $to_recompute, 0, 20 );

// Step 3: Recompute counters
if ( in_array( $action, array( 'recompute', 'both' ), true ) ) {
	$step = array( 'name' => 'recompute', 'attempted' => array(), 'skipped' => array(), 'errors' => array(), 'updated' => array() );

	if ( empty( $to_recompute ) ) {
		$step['skipped'][] = 'no_superstars_to_recompute';
	} else {
		foreach ( $to_recompute as $sid ) {
			$step['attempted'][] = $sid;
			if ( $dry ) continue;
			if ( function_exists( 'wf_recompute_superstar_counters' ) ) {
				try {
					$res = wf_recompute_superstar_counters( $sid );
					$step['updated'][ $sid ] = $res ? 'ok' : 'failed_or_no_change';
				} catch ( Exception $e ) {
					$step['errors'][ $sid ] = $e->getMessage();
				}
			} else {
				$step['skipped'][] = 'wf_recompute_superstar_counters_missing';
				break;
			}
		}
	}
	$result['steps'][] = $step;
}

// Finish
$result['finished_at'] = date( 'c' );
_rcr_response_and_exit( $result );

/* IMPORTANT:
 * - Delete this file after use.
 * - Running a full 'all' rebuild on very large sites may take time or hit memory limits.
 *   If that happens, run in smaller batches using match_ids or superstar_ids parameters.
 */
