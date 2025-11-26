<?php
/**
 * Resilient helpers for shortcodes: label normalization, event resolution,
 * promo/brand image resolution, team/stable display name resolution, and
 * small utilities used by shortcodes.
 *
 * Overwrite this file in includes/helpers.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* Optional: load central match-type config when present so wf_is_match_tag can use it */
$mt_cfg = __DIR__ . '/match-type-config.php';
if ( file_exists( $mt_cfg ) ) {
	require_once $mt_cfg;
}

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

/* --------------------------
   New: strict tag-match detection
   -------------------------- */

/**
 * Determine if a match should be treated as a tag match.
 *
 * Logic summary (conservative / strict):
 * 1) If explicit match_type matches configured tag types -> tag.
 * 2) If any participant row has role containing "tag" -> tag.
 * 3) If any raw participant is a team post type -> tag.
 * 4) Fallback: expanded participant count >= 4 -> tag.
 *
 * This avoids misclassifying triple-threats (3-person singles) as tag matches.
 *
 * @param int $match_id
 * @param array|null $expanded_participant_ids Optional expanded individual IDs (if previously computed)
 * @param array|null $rows Optional rows returned by wf_get_match_participants_rows
 * @return bool
 */
if ( ! function_exists( 'wf_is_match_tag' ) ) {
	function wf_is_match_tag( $match_id, $expanded_participant_ids = null, $rows = null ) {
		$match_id = intval( $match_id );
		if ( ! $match_id ) return false;

		$team_post_types = array( 'team', 'teams', 'stable' );

		// 1) Prefer explicit match type config (strict)
		$match_type = '';
		if ( function_exists( 'wf_get_match_type_candidate' ) ) {
			$match_type = wf_get_match_type_candidate( $match_id );
		} else {
			if ( function_exists( 'get_field' ) ) {
				$mt = get_field( 'match_type', $match_id );
				if ( $mt !== null && $mt !== '' ) $match_type = (string) $mt;
			}
			if ( $match_type === '' ) {
				$mt = get_post_meta( $match_id, 'match_type', true );
				if ( $mt !== '' && $mt !== null ) $match_type = (string) $mt;
			}
		}
		if ( $match_type !== '' ) {
			$t = strtolower( trim( (string) $match_type ) );
			if ( function_exists( 'wf_get_tag_match_types' ) ) {
				$types = wf_get_tag_match_types();
				if ( is_array( $types ) ) {
					$norm = array_map( 'strtolower', $types );
					if ( in_array( $t, $norm, true ) ) return true;
				}
			}
			// also compare taxonomy terms for match_type
			if ( taxonomy_exists( 'match_type' ) ) {
				$terms = get_the_terms( $match_id, 'match_type' );
				if ( is_array( $terms ) && ! empty( $terms ) ) {
					foreach ( $terms as $term ) {
						$slug = isset( $term->slug ) ? strtolower( $term->slug ) : '';
						$name = isset( $term->name ) ? strtolower( $term->name ) : '';
						if ( function_exists( 'wf_get_tag_match_types' ) ) {
							$types = wf_get_tag_match_types();
							if ( in_array( $slug, $types, true ) || in_array( $name, $types, true ) ) return true;
						} elseif ( $slug === $t || $name === $t ) {
							return true;
						}
					}
				}
			}
		}

		// 2) Ensure we have participant rows and expanded ids
		if ( $rows === null && function_exists( 'wf_get_match_participants_rows' ) ) {
			$rows = wf_get_match_participants_rows( $match_id );
		}
		if ( $expanded_participant_ids === null && function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
			$expanded_participant_ids = wf_expand_match_participants_to_individuals( $match_id );
		}
		if ( ! is_array( $rows ) ) $rows = array();
		if ( ! is_array( $expanded_participant_ids ) ) $expanded_participant_ids = array();

		// 3) If any participant row's role explicitly contains "tag", treat as tag (explicit)
		foreach ( (array) $rows as $r ) {
			if ( ! empty( $r['role'] ) && stripos( (string) $r['role'], 'tag' ) !== false ) return true;
		}

		// 4) If any participant in the raw rows is a team post, treat this as tag (teams imply multi-member)
		foreach ( (array) $rows as $r ) {
			$pid = isset( $r['participant'] ) ? intval( $r['participant'] ) : 0;
			if ( $pid ) {
				$ptype = function_exists( 'get_post_type' ) ? get_post_type( $pid ) : '';
				if ( in_array( $ptype, $team_post_types, true ) ) return true;
			}
		}

		// 5) Conservative fallback using expanded participant count:
		// - 4 or more expanded participants -> tag (typical tag-team / multi-team)
		// - 2 or 3 participants -> treat as singles/multiman (do not classify as tag)
		$expanded_count = count( $expanded_participant_ids );
		if ( $expanded_count >= 4 ) return true;

		// Otherwise, not a tag match.
		return false;
	}
}

/**
 * Read a candidate "match_type" value for a match.
 * Looks in ACF, meta, and match_type taxonomy.
 *
 * @param int $match_id
 * @return string
 */
if ( ! function_exists( 'wf_get_match_type_candidate' ) ) {
	function wf_get_match_type_candidate( $match_id ) {
		$match_id = intval( $match_id );
		if ( ! $match_id ) return '';
		$val = '';

		if ( function_exists( 'get_field' ) ) {
			$maybe = get_field( 'match_type', $match_id );
			if ( $maybe !== null && $maybe !== '' ) $val = (string) $maybe;
		}

		if ( $val === '' ) {
			$maybe = get_post_meta( $match_id, 'match_type', true );
			if ( $maybe !== '' && $maybe !== null ) $val = (string) $maybe;
		}

		if ( $val === '' ) {
			// try taxonomy 'match_type'
			if ( taxonomy_exists( 'match_type' ) ) {
				$terms = get_the_terms( $match_id, 'match_type' );
				if ( is_array( $terms ) && ! empty( $terms ) ) {
					// use first term name
					$val = isset( $terms[0]->name ) ? (string) $terms[0]->name : '';
				}
			}
		}

		return trim( (string) $val );
	}
}
