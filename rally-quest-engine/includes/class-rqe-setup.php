<?php
/** Shared setup readiness summaries; detailed diagnostic reports remain user-private. */
defined( 'ABSPATH' ) || exit;

final class RQE_Setup {
	const OPTION = 'rqe_setup_readiness';

	public static function welcome_complete() {
		global $wpdb;
		if ( ! RQE_Email_Submissions::configuration_status( 0 )['configured'] ) {
			return false;
		}
		$riders = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RQE::table( 'riders' ) );
		if ( $wpdb->last_error || $riders < 1 ) {
			return false;
		}
		$events = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RQE::table( 'events' ) );
		return ! $wpdb->last_error && $events > 0;
	}

	public static function inbox_complete() {
		$status = RQE_Email_Submissions::global_status();
		return ! empty( $status['connected'] );
	}

	public static function diagnostics_state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	public static function ignored_reviews() {
		$state = self::diagnostics_state();
		return isset( $state['review_ignores'] ) && is_array( $state['review_ignores'] ) ? $state['review_ignores'] : array();
	}

	public static function diagnostics_complete() {
		$state = self::diagnostics_state();
		return 'passed' === ( $state['diagnostics_state'] ?? '' ) || ! empty( $state['setup_override'] );
	}

	public static function readiness() {
		$welcome = self::welcome_complete();
		$inbox = self::inbox_complete();
		$style = '' !== RQE_Styles::saved_primary();
		$diagnostics = self::diagnostics_complete();
		return array(
			'welcome' => $welcome,
			'inbox' => $inbox,
			'style' => $style,
			'diagnostics' => $diagnostics,
			'complete' => $welcome && $inbox && $style && $diagnostics,
		);
	}

	public static function record_diagnostics( array $rows, $checked_at, $clear_override = false ) {
		$state = self::diagnostics_state();
		$ignored = self::ignored_reviews();
		$effective = RQE_Diagnostic_Report::state( $rows, $ignored );
		$review_count = 0;
		$failed_count = 0;
		foreach ( $rows as $row ) {
			$failed_count += 'failed' === $row['status'] ? 1 : 0;
			$review_count += 'review' === $row['status'] ? 1 : 0;
		}
		$next = array(
			'diagnostics_state' => $effective,
			'diagnostics_checked_at' => (string) $checked_at,
			'review_ignores' => $ignored,
			'setup_override' => $clear_override ? 0 : ( ! empty( $state['setup_override'] ) ? 1 : 0 ),
			'failed_count' => $failed_count,
			'review_count' => $review_count,
			'reset' => 0,
		);
		self::save_state( $next );
		return $effective;
	}

	public static function set_review_ignored( $name, $ignored, array $rows, $checked_at ) {
		$found = false;
		foreach ( $rows as $row ) {
			if ( isset( $row['name'], $row['status'] ) && $row['name'] === $name && 'review' === $row['status'] ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			throw new RuntimeException( 'That manual review is no longer available. Rerun Required Plugins and Configuration.' );
		}
		$ignored_names = self::ignored_reviews();
		if ( $ignored ) {
			$ignored_names[ $name ] = true;
		} else {
			unset( $ignored_names[ $name ] );
		}
		$state = self::diagnostics_state();
		$state['review_ignores'] = $ignored_names;
		( new RQE_Import_Audit() )->record( 0, 'setup_review_override', 'Administrator requested ' . ( $ignored ? 'ignoring' : 'restoring' ) . ' manual review for setup readiness only: ' . $name );
		self::save_state( $state );
		return self::record_diagnostics( $rows, $checked_at );
	}

	public static function set_setup_override( $enabled, array $rows, $checked_at ) {
		if ( $enabled ) {
			$incomplete = false;
			foreach ( $rows as $row ) {
				$incomplete = $incomplete || 'passed' !== ( $row['status'] ?? '' );
			}
			if ( ! $incomplete ) {
				throw new RuntimeException( 'A setup override is available only while the current report contains failed or manual-review checks.' );
			}
		}
		$state = self::diagnostics_state();
		$state['setup_override'] = $enabled ? 1 : 0;
		( new RQE_Import_Audit() )->record( 0, 'setup_override', 'Administrator requested ' . ( $enabled ? 'enabling' : 'removing' ) . ' the setup-only diagnostics override. Runtime protections remain enforced.' );
		self::save_state( $state );
		return self::record_diagnostics( $rows, $checked_at );
	}

	public static function clear() {
		self::save_state( array( 'reset' => 1, 'setup_override' => 0, 'review_ignores' => array() ) );
	}

	private static function save_state( array $state ) {
		if ( get_option( self::OPTION, array() ) !== $state ) {
			update_option( self::OPTION, $state, false );
		}
		if ( get_option( self::OPTION, array() ) !== $state ) {
			throw new RuntimeException( 'Shared setup readiness could not be saved.' );
		}
	}
}
