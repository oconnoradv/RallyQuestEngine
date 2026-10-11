<?php
/** Transactional destructive operations with coordinated gallery recovery and audit. */
defined( 'ABSPATH' ) || exit;

final class RQE_Diagnostics_Deletion_Service {
	public function delete( $operation, array $input ) {
		global $wpdb;
		$staged_gallery = '';
		$gallery_event_id = 0;
		$all_galleries = false;
		$staged_submission_files = array( 'root' => '', 'files' => array() );
		$event_id = 0;
		$audit_detail = '';
		RQE::begin_transaction();
		try {
			switch ( $operation ) {
				case 'diagnostics_delete_all':
					$staged_gallery = RQE_Bonus::stage_all_storage_removal();
					$all_galleries = true;
					RQE_Admin::checked( $wpdb->query( 'DELETE FROM ' . RQE::table( 'bonus_submissions' ) ) );
					RQE_Admin::checked( $wpdb->query( 'DELETE FROM ' . RQE::table( 'bonus_points' ) ) );
					foreach ( array( 'registrations', 'status_options', 'event_email_settings', 'event_scorers', 'rider_bikes', 'horses', 'event_admins', 'events', 'riders', 'bikes' ) as $table ) {
						RQE_Admin::checked( $wpdb->query( 'DELETE FROM ' . RQE::table( $table ) ) );
					}
					$message = 'All application data was deleted. Audit history and ClamAV overrides were retained.';
					break;
				case 'diagnostics_delete_bikes':
					RQE_Admin::checked( $wpdb->query( 'DELETE FROM ' . RQE::table( 'rider_bikes' ) ) );
					RQE_Admin::checked( $wpdb->query( 'DELETE FROM ' . RQE::table( 'bikes' ) ) );
					$message = 'All motorcycle catalog and participant-motorcycle data was deleted.';
					break;
				case 'diagnostics_delete_roster':
					$event_id = RQE_Admin::integer( $input['event_id'] ?? '', 1 );
					RQE_Admin::require_event( $event_id );
					$submission_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,image_file FROM ' . RQE::table( 'bonus_submissions' ) . ' WHERE event_id=%d', $event_id ), ARRAY_A );
					$staged_submission_files = RQE_Bonus::stage_submission_files( $submission_rows ?: array() );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'bonus_submissions' ), array( 'event_id' => $event_id ) ) );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'registrations' ), array( 'event_id' => $event_id ) ) );
					$message = 'The selected event roster and its participant bonus submissions were deleted.';
					break;
				case 'diagnostics_delete_bonus_points':
					$event_id = RQE_Admin::integer( $input['event_id'] ?? '', 1 );
					RQE_Admin::require_event( $event_id );
					$staged_gallery = RQE_Bonus::stage_event_storage_removal( $event_id );
					$gallery_event_id = $event_id;
					RQE_Admin::checked( $wpdb->query( $wpdb->prepare(
						'UPDATE ' . RQE::table( 'registrations' ) . ' reg JOIN (SELECT registration_id,SUM(points_awarded) awarded FROM ' . RQE::table( 'bonus_submissions' ) . " WHERE event_id=%d AND status='accepted' GROUP BY registration_id) scored ON scored.registration_id=reg.id SET reg.score=reg.score-scored.awarded WHERE reg.event_id=%d",
						$event_id,
						$event_id
					) ) );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'bonus_submissions' ), array( 'event_id' => $event_id ) ) );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'bonus_points' ), array( 'event_id' => $event_id ) ) );
					$message = 'All bonus listings, submissions, and scoring images for the selected event were deleted.';
					break;
				case 'diagnostics_delete_rider':
					$rider_id = RQE_Admin::integer( $input['rider_id'] ?? '', 1 );
					if ( ! RQE::row( 'riders', $rider_id ) ) {
						throw new RuntimeException( 'The selected participant no longer exists.' );
					}
					$submission_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,image_file FROM ' . RQE::table( 'bonus_submissions' ) . ' WHERE rider_id=%d', $rider_id ), ARRAY_A );
					$staged_submission_files = RQE_Bonus::stage_submission_files( $submission_rows ?: array() );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'bonus_submissions' ), array( 'rider_id' => $rider_id ) ) );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'registrations' ), array( 'rider_id' => $rider_id ) ) );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'rider_bikes' ), array( 'rider_id' => $rider_id ) ) );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'horses' ), array( 'rider_id' => $rider_id ) ) );
					RQE_Admin::checked( $wpdb->delete( RQE::table( 'riders' ), array( 'id' => $rider_id ) ) );
					$message = 'The selected participant and associated plugin data were deleted.';
					$audit_detail = ' Participant ID: ' . $rider_id . '.';
					break;
				case 'diagnostics_delete_audit':
					RQE_Admin::checked( $wpdb->query( 'DELETE FROM ' . RQE::table( 'import_audit' ) ) );
					$message = 'Virus-scan audit history was deleted and all remembered ClamAV overrides were reset. This deletion is recorded in the new audit log.';
					break;
				default:
					throw new RuntimeException( 'Unsupported diagnostics deletion.' );
			}
			RQE_Bonus::audit_deletion( $event_id, $operation, $message . $audit_detail );
			RQE::commit_transaction();
		} catch ( RuntimeException $e ) {
			if ( ! RQE::rollback_transaction() ) {
				throw new RuntimeException( $e->getMessage() . ' The database rollback failed; gallery recovery was not attempted.' );
			}
			if ( $staged_gallery ) {
				try {
					if ( $all_galleries ) {
						RQE_Bonus::restore_all_storage( $staged_gallery );
					} else {
						RQE_Bonus::restore_event_storage( $gallery_event_id, $staged_gallery );
					}
				} catch ( RuntimeException $restore_exception ) {
					throw new RuntimeException( $e->getMessage() . ' Gallery restoration also failed: ' . $restore_exception->getMessage() );
				}
			}
			if ( ! empty( $staged_submission_files['root'] ) ) {
				RQE_Bonus::restore_submission_files( $staged_submission_files );
			}
			throw $e;
		}
		if ( $staged_gallery ) {
			try {
				RQE_Bonus::finish_staged_storage_removal( $staged_gallery );
			} catch ( RuntimeException $cleanup_exception ) {
				throw new RuntimeException( 'The database records were deleted, but staged gallery files could not be fully removed: ' . $cleanup_exception->getMessage() );
			}
		}
		if ( ! empty( $staged_submission_files['root'] ) ) {
			RQE_Bonus::finish_staged_submission_files( $staged_submission_files );
		}
		return $message;
	}

}
