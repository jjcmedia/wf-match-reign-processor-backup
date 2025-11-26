<?php
/**
 * Shortcode handler: wf_shortcode_superstar_record
 *
 * Superstar record output (cleaned):
 *  - Championships and Feuds removed (these are handled elsewhere / not tracked here)
 *  - Keeps the current layout and visual tweaks (profile, metadata, stats)
 *
 * Path:
 *   includes/shortcodes/shortcode-superstar-record.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wf_shortcode_superstar_record' ) ) {
	function wf_shortcode_superstar_record( $atts = array(), $content = '' ) {
		$atts = shortcode_atts( array(
			'id' => 0,
		), (array) $atts, 'wf_superstar_record' );

		$post_id = intval( $atts['id'] );
		if ( ! $post_id ) {
			$post = get_post();
			$post_id = ( $post && isset( $post->ID ) ) ? intval( $post->ID ) : 0;
		}
		if ( ! $post_id ) {
			return '';
		}

		// simple meta helper
		$get_meta = function( $key, $default = '' ) use ( $post_id ) {
			$v = get_post_meta( $post_id, $key, true );
			if ( $v === '' || $v === null ) return $default;
			return $v;
		};

		// robust date parse (returns DateTimeImmutable or empty string)
		$parse_superstar_date = function( $raw ) {
			if ( empty( $raw ) ) return '';
			if ( is_string( $raw ) && trim( $raw ) === '0' ) return '';
			if ( $raw instanceof DateTimeInterface ) return new DateTimeImmutable( $raw->format( 'c' ) );

			if ( is_numeric( $raw ) ) {
				$raw_str = (string) $raw;
				$len = strlen( $raw_str );
				if ( $len >= 10 && intval( $raw ) > 1000000000 ) {
					try {
						$dt = new DateTimeImmutable( "@".intval( $raw ) );
						$tz = new DateTimeZone( date_default_timezone_get() ?: 'UTC' );
						return $dt->setTimezone( $tz );
					} catch ( Exception $e ) {
						return '';
					}
				}
				if ( $len === 8 ) {
					$dt = DateTimeImmutable::createFromFormat( 'Ymd', $raw_str );
					if ( $dt instanceof DateTimeImmutable ) return $dt;
				}
				return '';
			}

			$formats = array(
				'Y-m-d','Y/m/d','Y.m.d','Ymd',
				'm/d/Y','d/m/Y','d-m-Y',
				'F j, Y','F j Y','j F Y','Y',
			);
			foreach ( $formats as $fmt ) {
				$dt = DateTimeImmutable::createFromFormat( $fmt, $raw );
				if ( $dt instanceof DateTimeImmutable ) {
					$ts = $dt->getTimestamp();
					if ( $ts > 0 ) return $dt;
				}
			}

			$ts = strtotime( $raw );
			if ( $ts !== false && $ts > 0 ) {
				$dt = new DateTimeImmutable( "@$ts" );
				$tz = new DateTimeZone( date_default_timezone_get() ?: 'UTC' );
				$dt = $dt->setTimezone( $tz );
				$year = (int) $dt->format( 'Y' );
				$current_year = (int) date( 'Y' );
				if ( $year >= 1900 && $year <= $current_year ) return $dt;
			}

			return '';
		};

		// Load fields
		$name = get_the_title( $post_id );
		$photo = $get_meta( 'superstar_image', '' );
		if ( is_numeric( $photo ) ) {
			$photo = wp_get_attachment_url( intval( $photo ) );
		}

		$status_term = '';
		if ( taxonomy_exists( 'superstar-status' ) ) {
			$terms = get_the_terms( $post_id, 'superstar-status' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$status_term = esc_html( $terms[0]->name );
			}
		}
		if ( ! $status_term ) $status_term = $get_meta( 'superstar_status', '' );

		$real_name = $get_meta( 'superstar_real_name', '' );

		$dob_raw = $get_meta( 'date_of_birth', '' );
		$dob = '';
		if ( $dob_raw !== '' ) {
			$dt_obj = $parse_superstar_date( $dob_raw );
			if ( $dt_obj instanceof DateTimeImmutable ) {
				$dob = date_i18n( 'F j, Y', $dt_obj->getTimestamp() );
			}
		}

		$hometown_term = '';
		if ( taxonomy_exists( 'location' ) ) {
			$terms = get_the_terms( $post_id, 'location' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) $hometown_term = esc_html( $terms[0]->name );
		}
		if ( ! $hometown_term ) $hometown_term = $get_meta( 'hometown', '' );

		$height = $get_meta( 'height', '' );
		$weight = $get_meta( 'weight', '' );
		$phys = trim( ( $height ? $height : '' ) . ( $height && $weight ? ' / ' : '' ) . ( $weight ? $weight : '' ) );

		$company_term = '';
		if ( taxonomy_exists( 'company' ) ) {
			$terms = get_the_terms( $post_id, 'company' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) $company_term = esc_html( $terms[0]->name );
		}
		if ( ! $company_term ) $company_term = $get_meta( 'company', '' );

		$stable_term = '';
		if ( taxonomy_exists( 'stable' ) ) {
			$terms = get_the_terms( $post_id, 'stable' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) $stable_term = esc_html( $terms[0]->name );
		}
		if ( ! $stable_term ) $stable_term = $get_meta( 'stable', '' );

		$total_matches   = (int) $get_meta( 'wf_total_matches', 0 );
		$tag_matches     = (int) $get_meta( 'wf_tag_matches', 0 );
		$singles_matches = max( 0, $total_matches - $tag_matches );
		$wins            = (int) $get_meta( 'wf_wins', 0 );
		$losses          = (int) $get_meta( 'wf_losses', 0 );

		// load tag-specific counters if present
		$tag_wins   = (int) $get_meta( 'wf_tag_wins', 0 );
		$tag_losses = (int) $get_meta( 'wf_tag_losses', 0 );

		// compute win percentages (one decimal)
		$singles_win_pct = '';
		if ( $singles_matches > 0 ) {
			$singles_win_pct = round( ( (float) $wins / (float) max(1, $singles_matches ) ) * 100, 1 );
		}
		$tag_win_pct = '';
		if ( $tag_matches > 0 ) {
			$tag_win_pct = round( ( (float) $tag_wins / (float) max(1, $tag_matches ) ) * 100, 1 );
		}

		// Notable feuds and championships removed — not used here

		// Build markup
		$out = '<div class="wf-superstar-record" aria-labelledby="wf-superstar-' . esc_attr( $post_id ) . '">';

		// Scoped styles: keep existing visuals and set .value color to #D2D2D2
		$out .= '<style>';
		$out .= '.wf-superstar-record .wf-profile-photo{ width:118px;height:118px;border-radius:6px;overflow:hidden;background:#101010;display:flex;align-items:center;justify-content:center; border:1px solid rgba(0,0,0,0.45); box-shadow:0 3px 8px rgba(0,0,0,0.45) inset; margin:0 auto; }';
		$out .= '.wf-superstar-record .wf-profile-photo img{ width:100%;height:100%;object-fit:cover;display:block; }';
		$out .= '.wf-superstar-record .wf-record-dashboard{ display:flex; gap:16px; align-items:flex-start; width:100%; box-sizing:border-box; }';
		$out .= '.wf-superstar-record .wf-record-profile{ flex:0 0 160px; max-width:160px; padding:12px; border-radius:8px; border:1px solid rgba(0,0,0,0.25); background:transparent; box-sizing:border-box; }';
		$out .= '.wf-superstar-record .wf-record-main{ flex:0 0 250px; max-width:250px; min-width:160px; box-sizing:border-box; display:flex; flex-direction:column; gap:8px; }';
		$out .= '.wf-superstar-record .wf-match-meta{ padding:6px 10px; border-radius:6px; background: rgba(0,0,0,0.02); border:1px solid rgba(0,0,0,0.35); }';
		$out .= '.wf-superstar-record .wf-match-meta .value { color: #D2D2D2; }';
		$out .= '.wf-superstar-record .wf-record-side{ flex:1 1 auto; min-width:0; box-sizing:border-box; margin-left:auto; display:flex; justify-content:flex-end; }';
		$out .= '.wf-superstar-record .wf-record-side .wf-record-stats{ display:flex; flex-direction:column; gap:12px; align-items:flex-end; width:100%; max-width:900px; }';
		$out .= '.wf-superstar-record .wf-stats-row { display:flex; gap:14px; align-items:flex-start; justify-content:flex-end; flex-wrap:wrap; }';
		$out .= '.wf-superstar-record .wf-stats-col { min-width:180px; max-width:360px; border:1px solid rgba(0,0,0,0.35); border-radius:8px; box-shadow: 0 6px 20px rgba(0,0,0,0.45) inset; }';
		$out .= '.wf-superstar-record .wf-stats-group{ padding:10px; border-radius:8px; }';
		$out .= '.wf-superstar-record .wf-stats-group .row-like{ display:flex; justify-content:space-between; padding:6px 8px; border-radius:6px; background:rgba(0,0,0,0.02); margin-bottom:6px; }';
		$out .= '.wf-superstar-record .wf-stats-group .label{ color:#6b7280; font-size:0.9rem; }';
		$out .= '.wf-superstar-record .wf-stats-group .val{ font-weight:800; }';
		$out .= '.wf-superstar-record .wf-profile-name{ font-weight:800; font-size:1.05rem; margin-top:8px; text-align:center; }';
		$out .= '.wf-superstar-record .wf-profile-role{ font-size:0.9rem; color:#6b7280; text-align:center; }';
		$out .= '.wf-superstar-record .wf-status{ margin-top:6px; color:#fb923c; font-weight:700; text-align:center; }';
		$out .= '.wf-superstar-record .wf-record-main > *{ min-width:0; }';
		$out .= '.wf-superstar-record .wf-record-side > *{ min-width:0; }';
		$out .= '@media (max-width:559px){ .wf-superstar-record .wf-record-dashboard{ flex-direction:column; } .wf-superstar-record .wf-record-main{ width:100% !important; flex:0 0 auto; max-width:100% !important; } .wf-superstar-record .wf-record-side{ margin-left:0; justify-content:flex-start; } .wf-superstar-record .wf-stats-row{ flex-direction:column; } .wf-superstar-record .wf-stats-col{ width:100%; } }';
		$out .= '@media (min-width:560px){ .wf-superstar-record .wf-stats-row{ flex-direction:row; } .wf-superstar-record .wf-stats-col{ flex:0 0 auto; } }';
		$out .= '</style>';

		$out .= '<div class="wf-record-dashboard">';

		// LEFT: profile
		$out .= '<div class="wf-record-profile">';
		$out .= '<div class="wf-profile-photo">';
		if ( $photo ) {
			$out .= '<img src="' . esc_url( $photo ) . '" alt="' . esc_attr( $name ) . '">';
		} else {
			$out .= '<div style="padding:12px;text-align:center;color:#6b7280">No Photo</div>';
		}
		$out .= '</div>'; // photo

		$out .= '<div class="wf-profile-name">' . esc_html( $name ) . '</div>';
		if ( $real_name ) $out .= '<div class="wf-profile-role">(' . esc_html( $real_name ) . ')</div>';
		if ( $status_term ) $out .= '<div class="wf-status">' . esc_html( $status_term ) . '</div>';

		$out .= '</div>'; // left

		// CENTER: metadata constrained to 250px
		$out .= '<div class="wf-record-main">';
		if ( $dob ) {
			$out .= '<div class="wf-match-meta"><div class="label">Date of Birth</div><div class="value">' . esc_html( $dob ) . '</div></div>';
		}
		if ( $hometown_term ) {
			$out .= '<div class="wf-match-meta"><div class="label">Hometown</div><div class="value">' . esc_html( $hometown_term ) . '</div></div>';
		}
		if ( $phys ) {
			$out .= '<div class="wf-match-meta"><div class="label">Physicals</div><div class="value">' . esc_html( $phys ) . '</div></div>';
		}
		if ( $company_term ) {
			$out .= '<div class="wf-match-meta"><div class="label">Company</div><div class="value">' . esc_html( $company_term ) . '</div></div>';
		}
		if ( $stable_term ) {
			$out .= '<div class="wf-match-meta"><div class="label">Stable / Team</div><div class="value">' . esc_html( $stable_term ) . '</div></div>';
		}

		$out .= '</div>'; // center

		// RIGHT: expand to remaining space; margin-left:auto moves this block to the right
		$out .= '<div class="wf-record-side">';
		$out .= '<div class="wf-record-stats">';

		// Tag + Singles laid out inside a responsive row container
		$out .= '<div class="wf-stats-row">';

		// Tag column
		$out .= '<div class="wf-stats-col">';
		$out .= '<div class="wf-stats-group" style="background:linear-gradient(180deg, rgba(4,22,18,0.16), rgba(4,22,18,0.04));">';
		$out .= '<div style="font-weight:800;margin-bottom:6px;">Tag</div>';
		$out .= '<div class="row-like"><div class="label">Matches</div><div class="val">' . esc_html( $tag_matches ) . '</div></div>';
		$out .= '<div class="row-like"><div class="label">Wins</div><div class="val">' . esc_html( $tag_wins ) . '</div></div>';
		$out .= '<div class="row-like"><div class="label">Losses</div><div class="val">' . esc_html( $tag_losses ) . '</div></div>';
		if ( $tag_win_pct !== '' ) {
			$out .= '<div class="row-like"><div class="label">Win %</div><div class="val">' . esc_html( $tag_win_pct ) . '%</div></div>';
		}
		$out .= '</div>'; // tag group
		$out .= '</div>'; // tag col

		// Singles column
		$out .= '<div class="wf-stats-col">';
		$out .= '<div class="wf-stats-group" style="background:linear-gradient(180deg, rgba(12,8,20,0.12), rgba(12,8,20,0.02));">';
		$out .= '<div style="font-weight:800;margin-bottom:6px;">Singles</div>';
		$out .= '<div class="row-like"><div class="label">Matches</div><div class="val">' . esc_html( $singles_matches ) . '</div></div>';
		$out .= '<div class="row-like"><div class="label">Wins</div><div class="val">' . esc_html( $wins ) . '</div></div>';
		$out .= '<div class="row-like"><div class="label">Losses</div><div class="val">' . esc_html( $losses ) . '</div></div>';
		if ( $singles_win_pct !== '' ) {
			$out .= '<div class="row-like"><div class="label">Win %</div><div class="val">' . esc_html( $singles_win_pct ) . '%</div></div>';
		}
		$out .= '</div>'; // singles group
		$out .= '</div>'; // singles col

		$out .= '</div>'; // stats-row

		// Compact total box below groups (keeps right alignment)
		$out .= '<div style="margin-top:12px;padding:8px;border-radius:6px;background:rgba(255,122,89,0.06);font-weight:800;text-align:center;max-width:260px;align-self:flex-end;">Total Matches: ' . esc_html( $total_matches ) . '</div>';

		$out .= '</div>'; // wf-record-stats
		$out .= '</div>'; // wf-record-side

		$out .= '</div>'; // wf-record-dashboard
		$out .= '</div>'; // wrapper

		return apply_filters( 'wf_shortcode_superstar_record_output', $out, $post_id, $atts );
	}

	add_shortcode( 'wf_superstar_record', 'wf_shortcode_superstar_record' );
	add_shortcode( 'superstar_record', 'wf_shortcode_superstar_record' );
}
