<?php
/** Opt-in public results, separate from privileged administration views. */
defined( 'ABSPATH' ) || exit;

final class RQE_Public_Results {
	/** Registers the public [rqe_results] shortcode and its stylesheet. */
	public static function init() {
		add_shortcode( 'rqe_results', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'legacy_shortcode' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Keeps posts that embed the former [mrsa_results] shortcode working once that plugin is inactive. */
	public static function legacy_shortcode() {
		if ( ! shortcode_exists( 'mrsa_results' ) ) {
			add_shortcode( 'mrsa_results', array( __CLASS__, 'shortcode' ) );
		}
	}

	public static function assets() {
		wp_enqueue_style( 'rqe-public-results', plugins_url( 'assets/public-results.css', RQE_FILE ), array(), RQE_VERSION );
	}

	/** Enables or disables public results for one event after an event-access check. */
	public static function save_visibility( $event_id, $enabled ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		if ( ! in_array( $enabled, array( 0, 1 ), true ) ) {
			throw new RuntimeException( 'Select whether public results are enabled.' );
		}
		RQE::begin_transaction();
		try {
			RQE_Statuses::lock_event( $event_id );
			RQE_Admin::checked( $wpdb->update( RQE::table( 'events' ), array( 'public_results_enabled' => $enabled ), array( 'id' => $event_id ) ) );
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! RQE::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Public results setting rollback failed; inspect the event before retrying.' );
			}
			throw $exception;
		}
	}

	/** Renders [rqe_results id="MC-7"]: the published event's name, description, image and escaped public standings only. */
	public static function shortcode( $attributes = array() ) {
		$attributes = shortcode_atts( array( 'id' => '' ), $attributes, 'rqe_results' );
		// Accepts the style-qualified Event ID (for example MC-7) or the legacy numeric ID.
		$parsed = RQE_Styles::parse_event_code( $attributes['id'] );
		if ( null === $parsed ) {
			return '<p class="rqe-public-results-message">' . esc_html( 'Results unavailable: specify a valid Event ID such as MC-7.' ) . '</p>';
		}
		$id = $parsed['id'];
		global $wpdb;
		try {
			$results = ( new RQE_Queries( $wpdb ) )->public_results( $id, $parsed['style'] );
		} catch ( RuntimeException $exception ) {
			error_log( 'RQE public results #' . (int) $id . ': ' . $exception->getMessage() );
			return '<p class="rqe-public-results-message">' . esc_html( 'Results are temporarily unavailable. Please try again later.' ) . '</p>';
		}
		if ( null === $results ) {
			return '<p class="rqe-public-results-message">' . esc_html( 'Results are not available for this event.' ) . '</p>';
		}
		$html = '<section class="rqe-public-results"><h2>' . esc_html( $results['name'] ) . '</h2>';
		if ( $results['logo_id'] && wp_attachment_is_image( $results['logo_id'] ) ) {
			$html .= '<figure class="rqe-public-results-image">' . wp_get_attachment_image( $results['logo_id'], 'medium', false, array( 'alt' => $results['name'] ) ) . '</figure>';
		}
		if ( '' !== trim( (string) $results['description'] ) ) {
			$html .= '<div class="rqe-public-results-description">' . wpautop( esc_html( $results['description'] ) ) . '</div>';
		}
		if ( ! $results['rows'] ) {
			return $html . '<p>' . esc_html( 'No participant results have been recorded for this event.' ) . '</p></section>';
		}
		$html .= '<p>' . esc_html( 'Equal scores share a place. Preliminary Finisher is provisional; final Event Status is assigned by an administrator.' ) . '</p><div class="rqe-public-results-scroll" role="region" aria-label="' . esc_attr( 'Overall results: ' . $results['name'] ) . '" tabindex="0"><table><caption>' . esc_html( $results['name'] . ' - Overall Results' ) . '</caption><thead><tr>';
		foreach ( array( 'Bib', 'Participant Name', 'Total Score', 'Accepted Bonuses', 'Rejected Bonuses', 'Event Status', 'Overall Placing' ) as $heading ) {
			$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $results['rows'] as $row ) {
			$html .= '<tr>';
			foreach ( array( 'bib', 'name', 'score', 'accepted_bonuses', 'rejected_bonuses', 'effective_status', 'overall_placing' ) as $key ) {
				$html .= '<td>' . esc_html( (string) $row[ $key ] ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div></section>';
	}
}
