<?php
/** Event-level serialization for result mutations, locks and archive visibility. */
defined( 'ABSPATH' ) || exit;

final class RQE_Event_State {
	/** Call inside the mutation's transaction, before locking participant/submission rows. */
	public static function writable( $event_id ) {
		global $wpdb;
		$event = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ), ARRAY_A );
		if ( ! $event || $wpdb->last_error ) {
			throw new RuntimeException( 'The event could not be read or locked.' );
		}
		if ( ! empty( $event['is_locked'] ) || ! empty( $event['is_archived'] ) ) {
			throw new RuntimeException( 'This event is locked or archived. A WordPress site administrator must unlock it before changing results.' );
		}
		return $event;
	}

	public static function change( $event_id, $action ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		if ( ! in_array( $action, array( 'lock', 'unlock', 'archive', 'unarchive' ), true ) ) {
			throw new RuntimeException( 'Select a supported event state action.' );
		}
		if ( 'lock' !== $action ) {
			RQE_Admin::require_site_admin();
		}
		RQE::begin_transaction();
		try {
			$event = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ), ARRAY_A );
			if ( ! $event || $wpdb->last_error ) {
				throw new RuntimeException( 'Event state could not be read.' );
			}
			if ( 'unlock' === $action && ! empty( $event['is_archived'] ) ) {
				throw new RuntimeException( 'Unarchive this event before unlocking it.' );
			}
			$data = 'archive' === $action ? array( 'is_locked' => 1, 'is_archived' => 1 ) :
				( 'unarchive' === $action ? array( 'is_archived' => 0, 'is_locked' => 1 ) : array( 'is_locked' => 'lock' === $action ? 1 : 0 ) );
			RQE_Admin::checked( $wpdb->update( RQE::table( 'events' ), $data, array( 'id' => $event_id ) ) );
			( new RQE_Import_Audit() )->record( $event_id, 'event_' . $action, 'Event state changed: ' . $action . '.' );
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			RQE::rollback_transaction();
			throw $exception;
		}
	}

	public static function show_archived() {
		return (bool) get_user_option( 'rqe_show_archived', get_current_user_id() );
	}

	public static function save_visibility( $show ) {
		update_user_option( get_current_user_id(), 'rqe_show_archived', $show ? 1 : 0, false );
		wp_cache_delete( get_current_user_id(), 'user_meta' );
		if ( self::show_archived() !== (bool) $show ) {
			throw new RuntimeException( 'Archived-event visibility could not be saved.' );
		}
	}

	/** Lock all affected events before a destructive operation; callers already own a transaction. */
	public static function protect_deletion( $operation, array $input ) {
		global $wpdb;
		if ( in_array( $operation, array( 'diagnostics_delete_roster', 'diagnostics_delete_bonus_points' ), true ) ) {
			self::writable( RQE_Admin::integer( $input['event_id'] ?? '', 1 ) );
		} elseif ( in_array( $operation, array( 'diagnostics_delete_all', 'diagnostics_delete_rider' ), true ) ) {
			$sql = 'SELECT id FROM ' . RQE::table( 'events' );
			if ( 'diagnostics_delete_rider' === $operation ) {
				$sql .= ' WHERE id IN (SELECT event_id FROM ' . RQE::table( 'registrations' ) . ' WHERE rider_id=%d)';
				$sql = $wpdb->prepare( $sql . ' ORDER BY id FOR UPDATE', RQE_Admin::integer( $input['rider_id'] ?? '', 1 ) );
			} else {
				$sql .= ' ORDER BY id FOR UPDATE';
			}
			$ids = $wpdb->get_col( $sql );
			if ( ! is_array( $ids ) || $wpdb->last_error ) {
				throw new RuntimeException( 'Event protection could not be checked before deletion.' );
			}
			foreach ( $ids as $id ) {
				self::writable( (int) $id );
			}
		}
	}
}
