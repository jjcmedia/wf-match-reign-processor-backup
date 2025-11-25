<?php
/**
 * Resilient helpers for shortcodes: label normalization, event resolution,
 * promo/brand image resolution, team/stable display name resolution, and
 * small utilities used by shortcodes.
 *
 * Overwrite this file in includes/helpers.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'jjc_mh_ensure_string' ) ) {
	function jjc_mh_ensure_string( $val ) {
		// Null/empty
		if ( $val === null || $val === '' ) return '';

		// Scalars: try to resolve numeric to post title/term name where appropriate
		if ( is_scalar( $val ) ) {
			$s = (string) $val;
			$s_trim = trim( $s );
			// numeric or numeric-like string: attempt to resolve as post ID or term ID
			if ( $s_trim !== '' && ( ctype_digit( $s_trim ) || ( is_numeric( $s_trim ) && (string)(int)$s_trim === $s_trim ) ) ) {
				$id = intval( $s_trim );
				if ( $id ) {
					// Try post title
					$p = get_post( $id );
					if ( $p ) return get_the_title( $p );
					// Try term
					$t = get_term( $id );
					if ( ! is_wp_error( $t ) && $t && isset( $t->name ) ) return $t->name;
				}
			}
			// Not numeric or no resolution -> return as-is
			return $s_trim;
		}

		// Arrays: prefer first non-empty localized string
		if ( is_array( $val ) ) {
			// ACF-style array may contain 'ID' or 'title' or 'url'
			if ( isset( $val['ID'] ) && intval( $val['ID'] ) ) {
				$p = get_post( intval( $val['ID'] ) );
				if ( $p ) return get_the_title( $p );
			}
			if ( isset( $val['title'] ) ) return (string) $val['title'];
			if ( isset( $val['url'] ) ) return (string) $val['url'];
			// fallback to first non-empty element
			foreach ( $val as $e ) {
				$s = jjc_mh_ensure_string( $e );
				if ( $s !== '' ) return $s;
			}
			return '';
		}

		// Objects (WP_Post, ACF object, term)
		if ( is_object( $val ) ) {
			// WP_Post or object with ID
			if ( isset( $val->ID ) ) {
				$p = get_post( intval( $val->ID ) );
				if ( $p ) return get_the_title( $p );
			}
			// term-like object
			if ( isset( $val->name ) ) return (string) $val->name;
			if ( isset( $val->post_title ) ) return (string) $val->post_title;
			if ( method_exists( $val, '__toString' ) ) return (string) $val;
			return '';
		}

		return '';
	}
}

/**
 * Resolve an arbitrary label which may be stored as:
 * - numeric post ID
 * - numeric term ID
 * - ACF object / array
 * - free text
 *
 * Returns a readable string or empty.
 */
if ( ! function_exists( 'jjc_mh_resolve_label' ) ) {
	function jjc_mh_resolve_label( $val ) {
		// Prefer ensure_string semantics
		$s = jjc_mh_ensure_string( $val );
		if ( $s !== '' ) return $s;

		// If still empty and numeric, handle term/post tries again
		if ( is_numeric( $val ) ) {
			$id = intval( $val );
			if ( $id ) {
				$p = get_post( $id );
				if ( $p ) return get_the_title( $p );
				$t = get_term( $id );
				if ( ! is_wp_error( $t ) && $t && isset( $t->name ) ) return $t->name;
			}
		}
		return '';
	}
}

if ( ! function_exists( 'jjc_mh_get_post_id_from_field' ) ) {
	function jjc_mh_get_post_id_from_field( $f ) {
		if ( is_numeric( $f ) ) return intval( $f );
		if ( is_array( $f ) && isset( $f['ID'] ) ) return intval( $f['ID'] );
		if ( is_object( $f ) && isset( $f->ID ) ) return intval( $f->ID );
		if ( is_string( $f ) && ctype_digit( $f ) ) return intval( $f );
		return 0;
	}
}

if ( ! function_exists( 'jjc_mh_get_team_ids_for_superstar' ) ) {
	function jjc_mh_get_team_ids_for_superstar( $superstar_id ) {
		$meta_keys = array( 'teams', 'member_of', 'team_ids', 'wf_team_ids', 'team' );
		$out = array();
		foreach ( $meta_keys as $mk ) {
			$v = get_post_meta( $superstar_id, $mk, true );
			if ( empty( $v ) ) continue;
			if ( is_array( $v ) ) {
				foreach ( $v as $i ) if ( is_numeric( $i ) ) $out[] = intval( $i );
			} elseif ( is_numeric( $v ) ) {
				$out[] = intval( $v );
			} else {
				$dec = @json_decode( $v, true );
				if ( is_array( $dec ) ) foreach ( $dec as $i ) if ( is_numeric( $i ) ) $out[] = intval( $i );
			}
		}
		$out = array_values( array_unique( array_map( 'intval', $out ) ) );
		return $out;
	}
}

/* --- NEW: helper to expand a team post into member superstar IDs --- */
if ( ! function_exists( 'jjc_mh_get_team_member_ids' ) ) {
	function jjc_mh_get_team_member_ids( $team_post_id ) {
		$team_post_id = intval( $team_post_id );
		if ( ! $team_post_id ) return array();

		$keys = array( 'members', 'team_members', 'member_ids', 'members_ids', 'roster', 'team_roster', 'wf_members', 'members_list' );
		$out = array();

		// 1) ACF relationship/repeater style fields
		if ( function_exists( 'get_field' ) ) {
			foreach ( $keys as $k ) {
				$f = get_field( $k, $team_post_id );
				if ( empty( $f ) ) continue;
				if ( is_array( $f ) ) {
					foreach ( $f as $item ) {
						if ( is_numeric( $item ) ) $out[] = intval( $item );
						elseif ( is_object( $item ) && isset( $item->ID ) ) $out[] = intval( $item->ID );
						elseif ( is_array( $item ) && isset( $item['ID'] ) && is_numeric( $item['ID'] ) ) $out[] = intval( $item['ID'] );
					}
					if ( ! empty( $out ) ) return array_values( array_unique( $out ) );
				}
			}
		}

		// 2) Common postmeta patterns (JSON, serialized, CSV)
		foreach ( $keys as $k ) {
			$v = get_post_meta( $team_post_id, $k, true );
			if ( empty( $v ) ) continue;

			if ( is_numeric( $v ) ) {
				$out[] = intval( $v );
			} elseif ( is_array( $v ) ) {
				foreach ( $v as $itm ) if ( is_numeric( $itm ) ) $out[] = intval( $itm );
			} elseif ( is_string( $v ) ) {
				$dec = @json_decode( $v, true );
				if ( is_array( $dec ) ) {
					foreach ( $dec as $itm ) if ( is_numeric( $itm ) ) $out[] = intval( $itm );
				} else {
					$maybe_ser = @unserialize( $v );
					if ( is_array( $maybe_ser ) ) {
						foreach ( $maybe_ser as $itm ) if ( is_numeric( $itm ) ) $out[] = intval( $itm );
					}
					if ( strpos( $v, ',' ) !== false ) {
						foreach ( explode( ',', $v ) as $itm ) if ( ctype_digit( trim( $itm ) ) ) $out[] = intval( $itm );
					}
				}
			}

			if ( ! empty( $out ) ) return array_values( array_unique( $out ) );
		}

		// 3) Child posts (some setups store members as child posts)
		$children = get_posts( array(
			'post_type' => 'any',
			'posts_per_page' => -1,
			'post_parent' => $team_post_id,
			'fields' => 'ids',
		) );
		if ( ! empty( $children ) ) {
			foreach ( $children as $cid ) if ( is_numeric( $cid ) ) $out[] = intval( $cid );
			if ( ! empty( $out ) ) return array_values( array_unique( $out ) );
		}

		return array_values( array_unique( array_map( 'intval', $out ) ) );
	}
}

/* Backwards-compatible alias used elsewhere in your code */
if ( ! function_exists( 'jjc_mh_expand_team_to_members' ) ) {
	function jjc_mh_expand_team_to_members( $team_post_id ) {
		return jjc_mh_get_team_member_ids( $team_post_id );
	}
}

/**
 * Robustly resolve event details associated with a match post.
 * Returns array: [ 'id' => int, 'title' => string, 'promotion' => mixed, 'match_type' => string ]
 */
if ( ! function_exists( 'jjc_mh_get_event_details' ) ) {
	function jjc_mh_get_event_details( $match_id ) {
		$match_id = intval( $match_id );
		$out = array( 'id' => 0, 'title' => '', 'promotion' => '', 'match_type' => '' );
		if ( ! $match_id ) return $out;

		// ACF relationship fields
		if ( function_exists( 'get_field' ) ) {
			$keys = array( 'event', 'wf_event', 'related_event', 'event_post', 'match_event' );
			foreach ( $keys as $k ) {
				$f = get_field( $k, $match_id );
				if ( $f ) {
					$eid = jjc_mh_get_post_id_from_field( $f );
					if ( $eid ) {
						$out['id'] = $eid;
						break;
					}
				}
			}
		}

		// Common meta keys
		if ( ! $out['id'] ) {
			$meta_keys = array( 'event_id', 'wf_event', 'event', 'wf_event_id', 'event_post', 'match_event', 'event_reference' );
			foreach ( $meta_keys as $mk ) {
				$v = get_post_meta( $match_id, $mk, true );
				if ( empty( $v ) ) continue;
				$eid = jjc_mh_get_post_id_from_field( $v );
				if ( $eid ) { $out['id'] = $eid; break; }
				if ( is_string( $v ) && ctype_digit( $v ) ) { $out['id'] = intval( $v ); break; }
			}
		}

		// Fallback: parent post is event
		if ( ! $out['id'] ) {
			$p = get_post( $match_id );
			if ( $p && ! empty( $p->post_parent ) ) {
				$pp = get_post( intval( $p->post_parent ) );
				if ( $pp && get_post_type( $pp ) === 'event' ) $out['id'] = $pp->ID;
			}
		}

		// Populate event details if event id was found
		if ( $out['id'] ) {
			$out['title'] = get_the_title( $out['id'] );
			if ( function_exists( 'get_field' ) ) {
				$prom = get_field( 'promotion', $out['id'] );
				if ( $prom === null || $prom === '' ) $prom = get_field( 'promoter', $out['id'] );
				if ( $prom !== null && $prom !== '' ) $out['promotion'] = $prom;
			}
			if ( empty( $out['promotion'] ) ) {
				$prom = get_post_meta( $out['id'], 'promotion', true ) ?: get_post_meta( $out['id'], 'promoter', true );
				if ( $prom ) $out['promotion'] = $prom;
			}
			$mt = get_post_meta( $out['id'], 'match_type', true );
			if ( empty( $mt ) ) $mt = get_post_meta( $match_id, 'match_type', true );
			$out['match_type'] = jjc_mh_resolve_label( $mt );
			return $out;
		}

		// No event id: read match meta directly
		$maybe_title = get_post_meta( $match_id, 'event_title', true ) ?: get_post_meta( $match_id, 'event_name', true );
		if ( $maybe_title ) $out['title'] = jjc_mh_ensure_string( $maybe_title );

		$maybe_promo = get_post_meta( $match_id, 'promotion', true ) ?: get_post_meta( $match_id, 'promoter', true );
		if ( $maybe_promo ) $out['promotion'] = $maybe_promo;

		$maybe_mt = get_post_meta( $match_id, 'match_type', true );
		if ( $maybe_mt ) $out['match_type'] = jjc_mh_resolve_label( $maybe_mt );

		return $out;
	}
}

/**
 * Output promo image HTML for a promotion/event/match.
 * Accepts post ID, attachment ID, URL, or ACF-like arrays/objects.
 * If $match_id provided, falls back to event/match featured image.
 */
if ( ! function_exists( 'jjc_mh_output_promo_image_html' ) ) {
	function jjc_mh_output_promo_image_html( $promotion, $match_id = 0, $size = 'full' ) {
		// Normalize ACF structures
		if ( is_object( $promotion ) || is_array( $promotion ) ) {
			if ( is_object( $promotion ) && isset( $promotion->ID ) ) $promotion = intval( $promotion->ID );
			elseif ( is_array( $promotion ) && isset( $promotion['ID'] ) ) $promotion = intval( $promotion['ID'] );
			elseif ( is_array( $promotion ) && isset( $promotion['url'] ) ) $promotion = $promotion['url'];
			elseif ( is_array( $promotion ) && isset( $promotion['src'] ) ) $promotion = $promotion['src'];
		}

		// numeric -> try post thumbnail or attachment
		if ( is_numeric( $promotion ) ) {
			$pid = intval( $promotion );
			$url = get_the_post_thumbnail_url( $pid, $size );
			if ( $url ) return '<img src="' . esc_url( $url ) . '" alt="" />';
			$att_url = wp_get_attachment_image_url( $pid, $size );
			if ( $att_url ) return '<img src="' . esc_url( $att_url ) . '" alt="" />';
		}

		// URL
		if ( is_string( $promotion ) && filter_var( $promotion, FILTER_VALIDATE_URL ) ) {
			return '<img src="' . esc_url( $promotion ) . '" alt="" />';
		}

		// Label -> try find event/post by title
		if ( is_string( $promotion ) && $promotion !== '' ) {
			$maybe = get_page_by_title( $promotion, OBJECT, array( 'event', 'post' ) );
			if ( $maybe ) {
				$url = get_the_post_thumbnail_url( $maybe->ID, $size );
				if ( $url ) return '<img src="' . esc_url( $url ) . '" alt="" />';
			}
		}

		// Fallback to provided match_id: event -> match thumbnail
		$match_id = intval( $match_id );
		if ( $match_id ) {
			$ev = function_exists( 'jjc_mh_get_event_details' ) ? jjc_mh_get_event_details( $match_id ) : array();
			if ( ! empty( $ev['id'] ) ) {
				$url = get_the_post_thumbnail_url( intval( $ev['id'] ), $size );
				if ( $url ) return '<img src="' . esc_url( $url ) . '" alt="" />';
			}
			$url = get_the_post_thumbnail_url( $match_id, $size );
			if ( $url ) return '<img src="' . esc_url( $url ) . '" alt="" />';
		}

		return '';
	}
}

/**
 * Resolve a brand/promotion image (logo) for display.
 */
if ( ! function_exists( 'jjc_mh_get_brand_image_html' ) ) {
	function jjc_mh_get_brand_image_html( $promotion, $size = 'thumbnail' ) {
		return jjc_mh_output_promo_image_html( $promotion, 0, $size );
	}
}

/**
 * Resolve team/stable display name from various storage patterns.
 * Returns readable string or empty.
 */
if ( ! function_exists( 'jjc_mh_get_team_stable_name' ) ) {
	function jjc_mh_get_team_stable_name( $team_post_id ) {
		$team_post_id = intval( $team_post_id );
		if ( ! $team_post_id ) return '';

		// Defer to strict helper if present
		if ( function_exists( 'jjc_mh_get_team_stable_name_strict' ) ) {
			$maybe = jjc_mh_get_team_stable_name_strict( $team_post_id );
			// normalize object/array/id/string
			if ( is_object( $maybe ) ) {
				if ( isset( $maybe->ID ) ) {
					$tp = get_post( intval( $maybe->ID ) );
					if ( $tp ) return get_the_title( $tp );
				}
				if ( isset( $maybe->post_title ) ) return (string) $maybe->post_title;
			} elseif ( is_array( $maybe ) ) {
				if ( isset( $maybe['ID'] ) && intval( $maybe['ID'] ) ) {
					$tp = get_post( intval( $maybe['ID'] ) );
					if ( $tp ) return get_the_title( $tp );
				}
				if ( isset( $maybe['title'] ) ) return jjc_mh_ensure_string( $maybe['title'] );
				$first = reset( $maybe );
				return jjc_mh_ensure_string( $first );
			} elseif ( is_numeric( $maybe ) && intval( $maybe ) ) {
				$tp = get_post( intval( $maybe ) );
				if ( $tp ) return get_the_title( $tp );
				return (string) $maybe;
			} else {
				return jjc_mh_ensure_string( $maybe );
			}
		}

		// Check common meta keys on the team post
		$try_keys = array( 'stable_name', 'team_name', 'name', 'title', 'display_name' );
		foreach ( $try_keys as $k ) {
			$v = get_post_meta( $team_post_id, $k, true );
			if ( $v !== '' && $v !== null ) return jjc_mh_ensure_string( $v );
		}

		// Fallback to post title
		$tp = get_post( $team_post_id );
		if ( $tp ) return get_the_title( $tp );

		return '';
	}
}

/**
 * Simple wrapper: return post thumbnail HTML for championships used by other shortcodes.
 */
if ( ! function_exists( 'wf_get_championship_image_html' ) ) {
	function wf_get_championship_image_html( $champ_id, $size = 'thumbnail' ) {
		$champ_id = intval( $champ_id );
		if ( ! $champ_id ) return '';
		$url = get_the_post_thumbnail_url( $champ_id, $size );
		if ( $url ) {
			return '<img class="wf-champ-image" src="' . esc_url( $url ) . '" alt="' . esc_attr( get_the_title( $champ_id ) ) . '" decoding="async" loading="lazy" />';
		}
		return '';
	}
}

/**
 * Shared helper: return CSS class(es) used for alternating/list row visuals.
 *
 * $index: zero-based row index
 * $context: optional context string (e.g. 'match' or 'reigns')
 *
 * Using this helper ensures match listing and reign listing use identical classes.
 */
if ( ! function_exists( 'wf_get_row_class' ) ) {
	function wf_get_row_class( $index = 0, $context = '' ) {
		$classes = array();

		// alternating parity classes (keeps behaviour consistent)
		$classes[] = ( ( $index % 2 ) === 0 ) ? 'wf-row-even' : 'wf-row-odd';

		// shared base class name for list rows
		$classes[] = 'wf-list-row';

		// optional context modifier
		if ( $context ) {
			$classes[] = 'wf-list-row--' . sanitize_html_class( $context );
		}

		return implode( ' ', $classes );
	}
}

/**
 * Calculate number of days for a reign excluding the start date.
 *
 * Behavior:
 * - If $end_date is null/empty the function uses the current site time.
 * - Returns 0 when end <= start (no negative days).
 * - Uses WordPress timezone (wp_timezone()) when available so counts respect site timezone.
 * - Uses DateTimeImmutable and ->diff('%a') to return calendar days excluding the start date.
 *
 * Examples:
 * - start = 2025-11-23, end = 2025-11-24 => returns 1
 * - start = 2025-11-23, end = 2025-11-23 => returns 0
 * - start = 2025-11-23, end = null (ongoing) => returns days since start excluding the start day
 *
 * @param string|int|null $start_date Date string or timestamp for the start (required).
 * @param string|int|null $end_date   Date string or timestamp for the end (optional).
 * @return int Number of days excluding the start date.
 */
if ( ! function_exists( 'wf_mr_calculate_reign_days' ) ) {
	function wf_mr_calculate_reign_days( $start_date, $end_date = null ) {
		if ( empty( $start_date ) ) {
			return 0;
		}

		// Prefer WP timezone when available; fall back to option or UTC
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( get_option( 'timezone_string' ) ?: 'UTC' );

		$to_dt = function( $val ) use ( $tz ) {
			if ( $val === null || $val === '' ) return null;

			// If it's an integer, examine digit count to decide interpretation.
			if ( is_int( $val ) ) {
				$s = (string) $val;
				// 8-digit integers -> treat as YYYYMMDD
				if ( preg_match( '/^\d{8}$/', $s ) ) {
					$dt = DateTimeImmutable::createFromFormat( 'Ymd', $s, $tz );
					if ( $dt ) return $dt->setTimezone( $tz );
				}
				// Large ints (likely unix timestamps) -> treat as timestamp
				$ival = intval( $val );
				if ( $ival > 1000000000 ) {
					return ( new DateTimeImmutable( "@$ival" ) )->setTimezone( $tz );
				}
				// otherwise fall through to null
				return null;
			}

			// Normalize to string
			$raw = (string) $val;

			// 8-digit YYYYMMDD should be parsed first (avoid mistaking as timestamp)
			if ( preg_match( '/^\d{8}$/', $raw ) ) {
				$dt = DateTimeImmutable::createFromFormat( 'Ymd', $raw, $tz );
				if ( $dt ) return $dt->setTimezone( $tz );
			}

			// YYYY-MM-DD
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
				$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $raw, $tz );
				if ( $dt ) return $dt->setTimezone( $tz );
			}

			// If it's a numeric string and appears to be a Unix timestamp, only treat it as such when it's large enough.
			if ( ctype_digit( $raw ) && strlen( $raw ) <= 10 ) {
				$maybe_ts = intval( $raw );
				if ( $maybe_ts > 1000000000 ) {
					return ( new DateTimeImmutable( "@$maybe_ts" ) )->setTimezone( $tz );
				}
				// If it's numeric but too small to be a timestamp and not an 8-digit date, avoid interpreting it as timestamp.
			}

			// fallback to strtotime-parsed timestamp
			$ts = strtotime( $raw );
			if ( $ts !== false && $ts !== -1 ) {
				return ( new DateTimeImmutable( "@$ts" ) )->setTimezone( $tz );
			}

			return null;
		};

		$start = $to_dt( $start_date );
		if ( ! $start ) return 0;

		$end = $to_dt( $end_date );
		if ( ! $end ) {
			$end = new DateTimeImmutable( 'now', $tz );
		}

		// If end <= start => 0
		if ( $end <= $start ) return 0;

		// Use calendar-day difference: %a is the number of days between dates, excluding the start date.
		$diff = $end->diff( $start );
		return (int) $diff->format( '%a' );
	}
}

// Ensure match-type-config is available (defines wf_get_tag_match_types)
$__wf_match_type_config = __DIR__ . '/match-type-config.php';
if ( file_exists( $__wf_match_type_config ) ) {
	require_once $__wf_match_type_config;
}

/**
 * Determine whether a match should be considered a tag match.
 *
 * Signature is forgiving so callers may pass:
 *  - wf_is_match_tag( $match_id )
 *  - wf_is_match_tag( $match_id, $participant_ids_array )
 *  - wf_is_match_tag( $match_id, null, $rows_array )
 *  - wf_is_match_tag( null, 'Six-Man Tag' )  // evaluate by string
 *
 * Logic:
 *  1) If explicit match_type string provided, compare (case-insensitive)
 *     against wf_get_tag_match_types() (if available).
 *  2) If $match_id provided, prefer post meta / ACF match_type.
 *  3) If taxonomy terms exist for common match-type taxonomies, check term
 *     name/slug against configured tag types.
 *  4) Fallback: use provided participant IDs or rows to determine participant count
 *     and treat >2 participants as tag (2 is singles).
 *
 * Returns bool.
 */
if ( ! function_exists( 'wf_is_match_tag' ) ) {
	function wf_is_match_tag( $match_id = null, $maybe = null, $rows = null ) {
		// Normalize candidate match_type string vs participant ids
		$match_type = '';
		$participant_ids = null;
		$provided_rows = null;

		// If $maybe is an array assume it's participant IDs
		if ( is_array( $maybe ) ) {
			$participant_ids = array_values( array_map( 'intval', $maybe ) );
		} else {
			// If it's a scalar string, treat as match_type string
			if ( is_string( $maybe ) && trim( $maybe ) !== '' ) {
				$match_type = trim( strtolower( $maybe ) );
			} elseif ( is_numeric( $maybe ) && (string)(int)$maybe === (string)$maybe ) {
				// numeric second param might be a term id or count — prefer match meta below
				$match_type = '';
			}
		}

		// Provided rows may be passed as third arg
		if ( is_array( $rows ) && ! empty( $rows ) ) {
			$provided_rows = $rows;
		}

		// If we have a match_id and no explicit match_type string, try to read meta/ACF
		if ( $match_id && $match_type === '' ) {
			// prefer post meta
			$mt = get_post_meta( $match_id, 'match_type', true );
			if ( empty( $mt ) && function_exists( 'get_field' ) ) {
				$mt = get_field( 'match_type', $match_id );
			}
			if ( ! empty( $mt ) ) {
				$match_type = trim( strtolower( (string) $mt ) );
			}
		}

		// --- REPLACED: taxonomy-first, config-driven logic ---
		// Load configured tag types (match-type-config.php via wf_get_tag_match_types)
		$tag_types = array();
		if ( function_exists( 'wf_get_tag_match_types' ) ) {
			$tag_types = (array) wf_get_tag_match_types();
		}
		if ( empty( $tag_types ) ) {
			$tag_types = array( 'tag', 'tag team', 'tag-team', 'tagteam', 'trios', 'six-man', 'six man', 'six-man tag', 'war games', 'wargames', 'mens-war-games', 'mens war games', 'four-on-four', 'gauntlet' );
		}
		// Normalize to lowercase strings and unique
		$norm = array();
		foreach ( (array) $tag_types as $t ) {
			if ( ! is_scalar( $t ) ) continue;
			$val = trim( (string) $t );
			if ( $val !== '' ) $norm[] = strtolower( $val );
		}
		$tag_types = array_values( array_unique( $norm ) );

		// Helper: check a WP_Term object against configured tag types.
		$term_matches_tag_types = function( $term ) use ( $tag_types ) {
			if ( ! $term ) return false;
			$tname = trim( strtolower( (string) $term->name ) );
			$tslug = trim( strtolower( (string) $term->slug ) );
			$tid = intval( $term->term_id );
			foreach ( $tag_types as $tt ) {
				// numeric configured values may represent term IDs
				if ( ctype_digit( $tt ) && intval( $tt ) === $tid ) return true;
				if ( $tt === $tname || strpos( $tname, $tt ) !== false ) return true;
				if ( $tt === $tslug || strpos( $tslug, $tt ) !== false ) return true;
			}
			// explicit singles markers -> decisive non-tag
			$singles_keywords = array( 'singles', 'singles match', 'singles-match' );
			if ( in_array( $tname, $singles_keywords, true ) || in_array( $tslug, array( 'singles', 'singles-match' ), true ) ) {
				return 'singles';
			}
			return false;
		};

		// 1) PRIMARY: taxonomy term checks (decisive)
		if ( $match_id ) {
			$taxonomies_to_check = array( 'match-type', 'match_type', 'type' );
			foreach ( $taxonomies_to_check as $tax ) {
				if ( taxonomy_exists( $tax ) ) {
					$terms = get_the_terms( $match_id, $tax );
					if ( is_array( $terms ) && ! empty( $terms ) ) {
						foreach ( $terms as $term ) {
							$res = $term_matches_tag_types( $term );
							if ( $res === true ) return true;
							if ( $res === 'singles' ) return false;
						}
					}
				}
			}
		}

		// 2) explicit match_type meta/ACF (configured phrases or numeric ids)
		if ( $match_id && $match_type === '' ) {
			$mt = get_post_meta( $match_id, 'match_type', true );
			if ( empty( $mt ) && function_exists( 'get_field' ) ) {
				$mt = get_field( 'match_type', $match_id );
			}
			if ( ! empty( $mt ) ) $match_type = trim( strtolower( (string) $mt ) );
		}
		if ( $match_type !== '' ) {
			foreach ( $tag_types as $tt ) {
				if ( ctype_digit( $tt ) && ctype_digit( $match_type ) && intval( $tt ) === intval( $match_type ) ) return true;
				if ( $tt === $match_type || strpos( $match_type, $tt ) !== false ) return true;
			}
			if ( strpos( $match_type, 'singles' ) !== false || strpos( $match_type, 'single' ) !== false ) return false;
		}

		// 3) FALLBACK: consult wf_match_snapshot if taxonomy & match_type inconclusive
		if ( $match_id ) {
			$snap = get_post_meta( $match_id, 'wf_match_snapshot', true );
			if ( is_string( $snap ) ) {
				$dec = json_decode( $snap, true );
				if ( is_array( $dec ) && isset( $dec['is_tag'] ) ) {
					return (bool) $dec['is_tag'];
				}
			} elseif ( is_array( $snap ) && isset( $snap['is_tag'] ) ) {
				return (bool) $snap['is_tag'];
			}
		}

		// 4) participant rows: explicit 'role' containing "tag" or team-side markers (A/B)
		if ( is_array( $provided_rows ) ) {
			foreach ( $provided_rows as $r ) {
				if ( is_array( $r ) && ! empty( $r['role'] ) ) {
					$role = strtolower( (string) $r['role'] );
					if ( strpos( $role, 'tag' ) !== false ) return true;
					if ( in_array( $role, array( 'a', 'b', 'team a', 'team b' ), true ) ) return true;
				}
			}
		}

		// 5) team post types indicate tag matches
		$team_post_types = array( 'team', 'teams', 'stable' );

		if ( is_array( $participant_ids ) && ! empty( $participant_ids ) ) {
			foreach ( $participant_ids as $pid ) {
				$ptype = function_exists( 'get_post_type' ) ? get_post_type( $pid ) : '';
				if ( in_array( $ptype, $team_post_types, true ) ) return true;
			}
		}

		if ( $match_id ) {
			$winners = get_post_meta( $match_id, 'wf_winners', true );
			if ( is_array( $winners ) && ! empty( $winners ) ) {
				foreach ( $winners as $w ) {
					$w = intval( $w );
					if ( ! $w ) continue;
					$ptype = function_exists( 'get_post_type' ) ? get_post_type( $w ) : '';
					if ( in_array( $ptype, $team_post_types, true ) ) return true;
				}
			}
		}

		// 6) final fallback: participant count heuristic (>2 => tag)
		$count = 0;
		if ( is_array( $participant_ids ) ) {
			$count = count( $participant_ids );
		} elseif ( is_array( $provided_rows ) && ! empty( $provided_rows ) ) {
			$ids = array();
			foreach ( $provided_rows as $r ) {
				if ( is_array( $r ) && ! empty( $r['participant'] ) ) $ids[] = intval( $r['participant'] );
			}
			$count = count( array_values( array_unique( $ids ) ) );
		} elseif ( $match_id ) {
			$mp = get_post_meta( $match_id, 'match_participants', true );
			if ( empty( $mp ) ) $mp = get_post_meta( $match_id, 'participants', true );
			if ( is_array( $mp ) ) $count = count( $mp );
			else {
				$maybe_ser = maybe_unserialize( $mp );
				if ( is_array( $maybe_ser ) ) $count = count( $maybe_ser );
				else $count = intval( $mp );
			}
		}

		// Treat >2 participants as tag; exactly 2 as singles
		return ( $count > 2 );
	}
}