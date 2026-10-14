<?php
defined( 'ABSPATH' ) || exit;

/** Read-only pending-action alerts, scoped to what the current user may remediate. */
final class RQE_Alerts {
	private static $cache = null;

	public static function reset() {
		self::$cache = null;
	}

	/** Pending (unverified) and verified participant requests per scoped event. */
	public static function pending_requests() {
		global $wpdb;
		if ( ! is_user_logged_in() ) {
			return array();
		}
		$ids = RQE::event_ids();
		if ( ! $ids ) {
			return array();
		}
		$ids = array_map( 'intval', $ids );
		$rows = $wpdb->get_results(
			'SELECT r.event_id,e.name,COUNT(*) AS total FROM ' . RQE::table( 'participant_requests' ) . ' r INNER JOIN ' . RQE::table( 'events' ) . " e ON e.id=r.event_id WHERE r.status IN ('pending','verified') AND r.event_id IN (" . implode( ',', $ids ) . ') GROUP BY r.event_id,e.name ORDER BY e.name,r.event_id',
			ARRAY_A
		);
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return array();
		}
		return array_map( function ( $row ) {
			return array( 'event_id' => (int) $row['event_id'], 'name' => (string) $row['name'], 'total' => (int) $row['total'] );
		}, $rows );
	}

	public static function pending_request_total() {
		return array_sum( array_column( self::pending_requests(), 'total' ) );
	}

	/** Pending bonus submissions for events the current user may score. */
	private static function pending_bonus_reviews() {
		global $wpdb;
		if ( ! is_user_logged_in() ) {
			return array();
		}
		$ids = array_filter( array_map( 'intval', RQE::scoring_event_ids() ) );
		if ( ! $ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			'SELECT s.event_id,e.name,COUNT(*) AS total FROM ' . RQE::table( 'bonus_submissions' ) . ' s INNER JOIN ' . RQE::table( 'events' ) . " e ON e.id=s.event_id WHERE s.status='pending' AND s.event_id IN (" . implode( ',', $ids ) . ') GROUP BY s.event_id,e.name ORDER BY e.name,s.event_id',
			ARRAY_A
		);
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return array();
		}
		return array_map( function ( $row ) {
			return array( 'event_id' => (int) $row['event_id'], 'name' => (string) $row['name'], 'total' => (int) $row['total'] );
		}, $rows );
	}

	/** Inbox polling or verification errors; inbox management is restricted to site administrators. */
	private static function inbox_errors() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT s.event_id,e.name FROM ' . RQE::table( 'event_email_settings' ) . ' s LEFT JOIN ' . RQE::table( 'events' ) . " e ON e.id=s.event_id WHERE (s.enabled=1 AND s.last_error<>'') OR s.last_test_error<>'' ORDER BY s.event_id",
			ARRAY_A
		);
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return array();
		}
		$alerts = array();
		foreach ( $rows as $row ) {
			$event_id = (int) $row['event_id'];
			if ( 0 === $event_id ) {
				$alerts[] = array( 'type' => 'inbox', 'severity' => 'error', 'message' => 'The global bonus-submission inbox reported an error.', 'label' => 'Review the global inbox', 'url' => RQE_Admin::url( 'event-email', array( 'scope' => 'global' ) ) );
			} elseif ( null !== $row['name'] ) {
				$alerts[] = array( 'type' => 'inbox', 'severity' => 'error', 'message' => 'The ' . $row['name'] . ' event inbox reported an error.', 'label' => 'Review the event inbox', 'url' => RQE_Admin::url( 'event-email', array( 'event_id' => $event_id ) ) );
			}
		}
		return $alerts;
	}

	private static function setup_alerts() {
		$state = RQE_Setup::diagnostics_state();
		if ( ! empty( $state['reset'] ) ) {
			return array();
		}
		$critical = (int) ( $state['critical_failed_count'] ?? 0 );
		$failed = (int) ( $state['failed_count'] ?? 0 );
		$url = RQE_Admin::url( 'diagnostics' ) . '#rqe-configuration';
		if ( $critical ) {
			return array( array( 'type' => 'setup', 'severity' => 'error', 'message' => $critical . ' critical required setup check(s) failed.', 'label' => 'Fix Required Plugins and Configuration', 'url' => $url ) );
		}
		if ( $failed && empty( $state['setup_override'] ) ) {
			return array( array( 'type' => 'setup', 'severity' => 'warning', 'message' => $failed . ' required setup check(s) failed.', 'label' => 'Fix Required Plugins and Configuration', 'url' => $url ) );
		}
		return array();
	}

	/** @return array<int,array{type:string,severity:string,message:string,label:string,url:string}> */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$alerts = array();
		foreach ( self::pending_bonus_reviews() as $row ) {
			$alerts[] = array(
				'type' => 'bonus_review',
				'severity' => 'warning',
				'message' => $row['total'] . ' bonus review(s) pending for ' . $row['name'] . '.',
				'label' => 'Review bonuses',
				'url' => RQE_Admin::url( 'scoring', array( 'event_id' => $row['event_id'], 'score_tab' => 'pending' ) ) . '#rqe-scoring',
			);
		}
		foreach ( self::pending_requests() as $row ) {
			$alerts[] = array(
				'type' => 'participants',
				'severity' => 'warning',
				'message' => $row['total'] . ' participant registration request(s) awaiting approval or rejection for ' . $row['name'] . '.',
				'label' => 'Review requests',
				'url' => RQE_Admin::url( 'event-edit', array( 'event_id' => $row['event_id'] ) ) . '#rqe-participant-access',
			);
		}
		if ( RQE::site_access() ) {
			$alerts = array_merge( $alerts, self::setup_alerts(), self::inbox_errors() );
		}
		self::$cache = $alerts;
		return $alerts;
	}

	public static function count() {
		return count( self::all() );
	}

	/** Event-scoped participant approval/rejection history from the Administrative Audit. */
	public static function participant_decisions( array $event_ids, $limit = 20 ) {
		global $wpdb;
		$event_ids = array_filter( array_map( 'intval', $event_ids ) );
		if ( ! $event_ids ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT username,occurred_at,event_id,action,detail FROM ' . RQE::table( 'import_audit' ) . " WHERE action IN ('participant_approved','participant_rejected') AND event_id IN (" . implode( ',', $event_ids ) . ') ORDER BY id DESC LIMIT %d',
				max( 1, (int) $limit )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Participant decision history could not be read.' );
		}
		return $rows;
	}
}
