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
	public static function save_visibility( $event_id, $enabled, $details_enabled = 0 ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		if ( ! in_array( $enabled, array( 0, 1 ), true ) ) {
			throw new RuntimeException( 'Select whether public results are enabled.' );
		}
		if ( ! in_array( $details_enabled, array( 0, 1 ), true ) ) {
			throw new RuntimeException( __( 'Select whether public accepted bonus details are enabled.', 'rally-quest-engine' ) );
		}
		if ( $details_enabled && ! $enabled ) {
			throw new RuntimeException( __( 'Public accepted bonus details require public results to be enabled.', 'rally-quest-engine' ) );
		}
		RQE::begin_transaction();
		try {
			RQE_Statuses::lock_event( $event_id );
			RQE_Admin::checked( $wpdb->update( RQE::table( 'events' ), array( 'public_results_enabled' => $enabled, 'public_bonus_details_enabled' => $details_enabled ), array( 'id' => $event_id ) ) );
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! RQE::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Public results setting rollback failed; inspect the event before retrying.' );
			}
			throw $exception;
		}
	}

	private static function valid_bib( $value ) {
		return is_string( $value ) && preg_match( '/^[1-9][0-9]{0,9}$/D', $value ) && (float) $value <= 2147483647;
	}

	private static function requested_bib( $key ) {
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
			return null;
		}
		$value = wp_unslash( $_GET[ $key ] );
		return self::valid_bib( $value ) ? $value : null;
	}

	/** Keep navigation on the embedding page and preserve only other event-scoped Bib selections. */
	private static function detail_url( $event_id, $bib = null ) {
		$args = array();
		foreach ( array_keys( $_GET ) as $key ) {
			if ( preg_match( '/^rqe_bib_[1-9][0-9]*$/D', (string) $key ) && $key !== 'rqe_bib_' . $event_id ) {
				$bib_value = self::requested_bib( $key );
				if ( null !== $bib_value ) {
					$args[ $key ] = $bib_value;
				}
			}
		}
		if ( null !== $bib ) {
			$args[ 'rqe_bib_' . $event_id ] = (int) $bib;
		}
		return add_query_arg( $args, get_permalink() );
	}

	/** Shared accepted-row rendering: no submission metadata, contact details or review controls. */
	public static function accepted_table( $event_id, array $rows ) {
		if ( ! $rows ) {
			return '<p>' . esc_html__( 'No accepted bonus submissions have been recorded for this participant.', 'rally-quest-engine' ) . '</p>';
		}
		$html = '<div class="rqe-public-results-scroll"><table class="rqe-accepted-bonuses"><caption>' . esc_html__( 'Accepted Bonus Submissions', 'rally-quest-engine' ) . '</caption><thead><tr>';
		foreach ( array(
			__( 'Bonus ID', 'rally-quest-engine' ),
			__( 'Bonus Description', 'rally-quest-engine' ),
			__( 'Submitted (UTC)', 'rally-quest-engine' ),
			__( 'Points Awarded', 'rally-quest-engine' ),
			__( 'Submitted Proof', 'rally-quest-engine' ),
		) as $heading ) {
			$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( array( 'bonus_id', 'bonus_description', 'submitted_at', 'points_awarded' ) as $key ) {
				$html .= '<td>' . esc_html( (string) $row[ $key ] ) . '</td>';
			}
			$html .= '<td>' . self::proof_photo( $event_id, $row['image_file'], $row['bonus_id'] ) . '</td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	private static function proof_photo( $event_id, $image_file, $bonus_id ) {
		$unavailable = '<span>' . esc_html__( 'Photo unavailable', 'rally-quest-engine' ) . '</span>';
		if ( '' === (string) $image_file ) {
			return '<span>' . esc_html__( 'No submitted photo', 'rally-quest-engine' ) . '</span>';
		}
		if ( ! preg_match( '/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/D', (string) $image_file, $match ) ) {
			return $unavailable;
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return $unavailable;
		}
		$root = realpath( $uploads['basedir'] );
		$path = realpath( trailingslashit( $uploads['basedir'] ) . 'rqe-event-gallery/' . (int) $event_id . '/submissions/' . $image_file );
		// Never follow an event-gallery symlink outside this event's local upload directory.
		$expected = str_replace( '\\', '/', (string) $root ) . '/rqe-event-gallery/' . (int) $event_id . '/submissions/';
		if ( ! $root || ! $path || 0 !== strpos( str_replace( '\\', '/', $path ), $expected ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return $unavailable;
		}
		$image = @getimagesize( $path );
		$mimes = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' );
		if ( ! $image || $mimes[ $match[1] ] !== $image['mime'] ) {
			return $unavailable;
		}
		$url = RQE_Bonus::submission_image_url( $event_id, $image_file );
		/* translators: %s: bonus identifier. */
		$alt = sprintf( __( 'Submitted proof for bonus %s', 'rally-quest-engine' ), $bonus_id );
		return '<a class="rqe-submitted-proof" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" decoding="async"><span>' . esc_html__( 'View larger photo', 'rally-quest-engine' ) . '</span></a>';
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
			$queries = new RQE_Queries( $wpdb );
			$results = $queries->public_results( $id, $parsed['style'] );
			$key = 'rqe_bib_' . $id;
			$has_selection = array_key_exists( $key, $_GET );
			$requested_bib = self::requested_bib( $key );
			$details = null;
			if ( $results && ! empty( $results['public_bonus_details_enabled'] ) && null !== $requested_bib ) {
				$details = $queries->public_accepted_submissions( $id, (int) $requested_bib, $parsed['style'] );
			}
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
		if ( $has_selection ) {
			$html .= '<p><a href="' . esc_url( self::detail_url( $id ) ) . '">' . esc_html__( 'Back to overall results', 'rally-quest-engine' ) . '</a></p>';
			if ( null === $details ) {
				return $html . '<p>' . esc_html__( 'Accepted bonus details are not available for this participant.', 'rally-quest-engine' ) . '</p></section>';
			}
			/* translators: 1: participant name, 2: event Bib number. */
			$heading = sprintf( __( '%1$s — Bib %2$s — Accepted Bonuses', 'rally-quest-engine' ), $details['name'], $details['bib'] );
			return $html . '<h3>' . esc_html( $heading ) . '</h3>' . self::accepted_table( $id, $details['rows'] ) . '</section>';
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
				$cell = esc_html( (string) $row[ $key ] );
				if ( 'bib' === $key && ! empty( $results['public_bonus_details_enabled'] ) ) {
					$cell = '<a href="' . esc_url( self::detail_url( $id, $row['bib'] ) ) . '">' . $cell . '</a>';
				}
				$html .= '<td>' . $cell . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table></div></section>';
	}
}
