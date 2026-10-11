<?php
/** Opt-in public results, separate from privileged administration views. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Public_Results {
	public static function init() {
		add_shortcode( 'mrsa_results', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'mrsa-public-results', plugins_url( 'assets/public-results.css', MRSA_FILE ), array(), MRSA_VERSION );
	}

	public static function save_visibility( $event_id, $enabled ) {
		global $wpdb;
		MRSA_Admin::require_event( $event_id );
		if ( ! in_array( $enabled, array( 0, 1 ), true ) ) {
			throw new RuntimeException( 'Select whether public results are enabled.' );
		}
		MRSA::begin_transaction();
		try {
			MRSA_Statuses::lock_event( $event_id );
			MRSA_Admin::checked( $wpdb->update( MRSA::table( 'events' ), array( 'public_results_enabled' => $enabled ), array( 'id' => $event_id ) ) );
			MRSA::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! MRSA::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Public results setting rollback failed; inspect the event before retrying.' );
			}
			throw $exception;
		}
	}

	public static function shortcode( $attributes = array() ) {
		$attributes = shortcode_atts( array( 'id' => '' ), $attributes, 'mrsa_results' );
		$id = $attributes['id'];
		if ( ( ! is_string( $id ) && ! is_int( $id ) ) || ! preg_match( '/^[1-9]\d{0,17}$/D', (string) $id ) || (float) $id > PHP_INT_MAX ) {
			return '<p class="mrsa-public-results-message">' . esc_html( 'Results unavailable: specify a valid MRSA Event ID.' ) . '</p>';
		}
		global $wpdb;
		try {
			$results = ( new MRSA_Queries( $wpdb ) )->public_results( (int) $id );
		} catch ( RuntimeException $exception ) {
			error_log( 'MRSA public results #' . (int) $id . ': ' . $exception->getMessage() );
			return '<p class="mrsa-public-results-message">' . esc_html( 'Results are temporarily unavailable. Please try again later.' ) . '</p>';
		}
		if ( null === $results ) {
			return '<p class="mrsa-public-results-message">' . esc_html( 'Results are not available for this event.' ) . '</p>';
		}
		$html = '<section class="mrsa-public-results"><h2>' . esc_html( $results['name'] ) . '</h2>';
		if ( ! $results['rows'] ) {
			return $html . '<p>' . esc_html( 'No rider results have been recorded for this event.' ) . '</p></section>';
		}
		$html .= '<p>' . esc_html( 'Equal scores share a place. Preliminary Finisher is provisional; final Event Status is assigned by an administrator.' ) . '</p><div class="mrsa-public-results-scroll" role="region" aria-label="' . esc_attr( 'Overall results: ' . $results['name'] ) . '" tabindex="0"><table><caption>' . esc_html( $results['name'] . ' - Overall Results' ) . '</caption><thead><tr>';
		foreach ( array( 'Bib', 'Rider Name', 'Total Score', 'Accepted Bonuses', 'Rejected Bonuses', 'Event Status', 'Overall Placing' ) as $heading ) {
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
