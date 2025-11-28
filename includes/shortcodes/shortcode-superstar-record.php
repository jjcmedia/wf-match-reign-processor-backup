<?php
/**
 * Shortcode handler: wf_shortcode_superstar_record
 *
 * Clean, semantic markup for the superstar header.
 * Inline styles removed — all visuals come from the shortcodes.css dashboard block.
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

		$get_meta = function( $key, $default = '' ) use ( $post_id ) {
			$v = get_post_meta( $post_id, $key, true );
			if ( $v === '' || $v === null ) return $default;
			return $v;
		};

		$parse_date = function( $raw ) {
			if ( empty( $raw ) ) return '';
			$ts = strtotime( $raw );
			if ( $ts === false || $ts <= 0 ) return '';
			return date_i18n( 'F j, Y', $ts );
		};

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
		$dob = $parse_date( $get_meta( 'date_of_birth', '' ) );

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

		$tag_wins   = (int) $get_meta( 'wf_tag_wins', 0 );
		$tag_losses = (int) $get_meta( 'wf_tag_losses', 0 );

		$singles_win_pct = '';
		if ( $singles_matches > 0 ) {
			$singles_win_pct = round( ( (float) $wins / (float) max(1, $singles_matches ) ) * 100, 1 );
		}
		$tag_win_pct = '';
		if ( $tag_matches > 0 ) {
			$tag_win_pct = round( ( (float) $tag_wins / (float) max(1, $tag_matches ) ) * 100, 1 );
		}

		// Build markup (no inline styles)
		$out = '<div class="wf-superstar-record" aria-labelledby="wf-superstar-' . esc_attr( $post_id ) . '">';
		$out .= '<div class="wf-record-dashboard">';

		// LEFT: picture + status only
		$out .= '<div class="wf-record-profile">';
		$out .= '<div class="wf-profile-photo">';
		if ( $photo ) {
			$out .= '<img decoding="async" src="' . esc_url( $photo ) . '" alt="' . esc_attr( $name ) . '">';
		} else {
			$out .= '<div class="wf-no-photo">No Photo</div>';
		}
		$out .= '</div>'; // photo
		if ( $status_term ) {
			$out .= '<div class="wf-status" aria-hidden="true">' . esc_html( $status_term ) . '</div>';
		}
		$out .= '</div>'; // left

		// CENTER
		$out .= '<div class="wf-record-main">';

		// center-left: name / company / follow
		$out .= '<div class="wf-center-left">';
		$out .= '<h1 class="wf-main-name">' . esc_html( $name ) . '</h1>';
		if ( $real_name ) $out .= '<div class="wf-real-name">(' . esc_html( $real_name ) . ')</div>';
		if ( $company_term || $stable_term ) {
			$out .= '<div class="wf-company-line">';
			if ( $company_term ) $out .= '<span class="wf-company">' . esc_html( $company_term ) . '</span>';
			if ( $stable_term ) $out .= '<span class="wf-company-sep">•</span><span class="wf-stable">' . esc_html( $stable_term ) . '</span>';
			$out .= '</div>';
		}
		$out .= '<div class="wf-follow-wrap"><button class="wf-follow" type="button">Follow</button></div>';
		$out .= '</div>'; // wf-center-left

		// center-meta: label / value list
		$out .= '<div class="wf-center-meta">';
		if ( $height || $weight ) $out .= '<div class="wf-meta-row"><div class="wf-meta-label">HT/WT</div><div class="wf-meta-value">' . esc_html( $phys ) . '</div></div>';
		if ( $dob ) $out .= '<div class="wf-meta-row"><div class="wf-meta-label">BIRTHDATE</div><div class="wf-meta-value">' . esc_html( $dob ) . '</div></div>';
		if ( $hometown_term ) $out .= '<div class="wf-meta-row"><div class="wf-meta-label">HOMETOWN</div><div class="wf-meta-value">' . esc_html( $hometown_term ) . '</div></div>';
		if ( $status_term ) $out .= '<div class="wf-meta-row"><div class="wf-meta-label">STATUS</div><div class="wf-meta-value"><span class="wf-status-dot" aria-hidden="true"></span>' . esc_html( $status_term ) . '</div></div>';
		$out .= '</div>'; // wf-center-meta

		$out .= '</div>'; // wf-record-main

		// RIGHT: stats (numbers row + boxes + total)
		$out .= '<div class="wf-record-side"><div class="wf-record-stats">';

		// numbers row
		$out .= '<div class="wf-numbers">';
		$out .= '<div class="wf-number-col"><div class="wf-number-label">Tag</div><div class="wf-number">' . esc_html( $tag_matches ) . '</div><div class="wf-number-sub">Matches</div></div>';
		$out .= '<div class="wf-number-col"><div class="wf-number-label">Singles</div><div class="wf-number">' . esc_html( $singles_matches ) . '</div><div class="wf-number-sub">Matches</div></div>';
		$out .= '</div>'; // wf-numbers

		// two record boxes
		$out .= '<div class="wf-record-boxes">';
		$out .= '<div class="wf-record-box wf-box-tag">';
		$out .= '<div class="record-title">Tag</div>';
		$out .= '<div class="record-row-like"><div class="k">Matches</div><div class="v">' . esc_html( $tag_matches ) . '</div></div>';
		$out .= '<div class="record-row-like"><div class="k">Wins</div><div class="v">' . esc_html( $tag_wins ) . '</div></div>';
		$out .= '<div class="record-row-like"><div class="k">Losses</div><div class="v">' . esc_html( $tag_losses ) . '</div></div>';
		if ( $tag_win_pct !== '' ) $out .= '<div class="record-row-like"><div class="k">Win %</div><div class="v win-percent">' . esc_html( $tag_win_pct ) . '%</div></div>';
		$out .= '</div>'; // tag box

		$out .= '<div class="wf-record-box wf-box-singles">';
		$out .= '<div class="record-title">Singles</div>';
		$out .= '<div class="record-row-like"><div class="k">Matches</div><div class="v">' . esc_html( $singles_matches ) . '</div></div>';
		$out .= '<div class="record-row-like"><div class="k">Wins</div><div class="v">' . esc_html( $wins ) . '</div></div>';
		$out .= '<div class="record-row-like"><div class="k">Losses</div><div class="v">' . esc_html( $losses ) . '</div></div>';
		if ( $singles_win_pct !== '' ) $out .= '<div class="record-row-like"><div class="k">Win %</div><div class="v win-percent">' . esc_html( $singles_win_pct ) . '%</div></div>';
		$out .= '</div>'; // singles box

		$out .= '</div>'; // wf-record-boxes

		// total pill
		$out .= '<div class="wf-total">Total Matches: ' . esc_html( $total_matches ) . '</div>';

		$out .= '</div></div>'; // wf-record-stats, wf-record-side

		$out .= '</div></div>'; // wf-record-dashboard, wrapper

		return apply_filters( 'wf_shortcode_superstar_record_output', $out, $post_id, $atts );
	}

	add_shortcode( 'wf_superstar_record', 'wf_shortcode_superstar_record' );
	add_shortcode( 'superstar_record', 'wf_shortcode_superstar_record' );
}