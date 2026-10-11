<?php
/** Transactional destructive operations with coordinated gallery recovery and audit. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Diagnostics_Deletion_Service {
	public function delete( $operation, array $input ) {
		global $wpdb;
		$staged_gallery = '';
		$gallery_event_id = 0;
		$all_galleries = false;
		$staged_submission_files = array( 'root' => '', 'files' => array() );
		$event_id = 0;
		$audit_detail = '';
		MRSA::begin_transaction();
		try {
			switch ( $operation ) {
				case 'diagnostics_delete_all':
					$staged_gallery = MRSA_Bonus::stage_all_storage_removal();
					$all_galleries = true;
					MRSA_Admin::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'bonus_submissions' ) ) );
					MRSA_Admin::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'bonus_points' ) ) );
					foreach ( array( 'registrations', 'status_options', 'event_email_settings', 'event_scorers', 'rider_bikes', 'horses', 'event_admins', 'events', 'riders', 'bikes' ) as $table ) {
						MRSA_Admin::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( $table ) ) );
					}
					$message = 'All application data was deleted. Audit history and ClamAV overrides were retained.';
					break;
				case 'diagnostics_delete_bikes':
					MRSA_Admin::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'rider_bikes' ) ) );
					MRSA_Admin::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'bikes' ) ) );
					$message = 'All motorcycle catalog and participant-motorcycle data was deleted.';
					break;
				case 'diagnostics_delete_roster':
					$event_id = MRSA_Admin::integer( $input['event_id'] ?? '', 1 );
					MRSA_Admin::require_event( $event_id );
					$submission_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,image_file FROM ' . MRSA::table( 'bonus_submissions' ) . ' WHERE event_id=%d', $event_id ), ARRAY_A );
					$staged_submission_files = MRSA_Bonus::stage_submission_files( $submission_rows ?: array() );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'bonus_submissions' ), array( 'event_id' => $event_id ) ) );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'registrations' ), array( 'event_id' => $event_id ) ) );
					$message = 'The selected event roster and its participant bonus submissions were deleted.';
					break;
				case 'diagnostics_delete_bonus_points':
					$event_id = MRSA_Admin::integer( $input['event_id'] ?? '', 1 );
					MRSA_Admin::require_event( $event_id );
					$staged_gallery = MRSA_Bonus::stage_event_storage_removal( $event_id );
					$gallery_event_id = $event_id;
					MRSA_Admin::checked( $wpdb->query( $wpdb->prepare(
						'UPDATE ' . MRSA::table( 'registrations' ) . ' reg JOIN (SELECT registration_id,SUM(points_awarded) awarded FROM ' . MRSA::table( 'bonus_submissions' ) . " WHERE event_id=%d AND status='accepted' GROUP BY registration_id) scored ON scored.registration_id=reg.id SET reg.score=reg.score-scored.awarded WHERE reg.event_id=%d",
						$event_id,
						$event_id
					) ) );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'bonus_submissions' ), array( 'event_id' => $event_id ) ) );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'bonus_points' ), array( 'event_id' => $event_id ) ) );
					$message = 'All bonus listings, submissions, and scoring images for the selected event were deleted.';
					break;
				case 'diagnostics_delete_rider':
					$rider_id = MRSA_Admin::integer( $input['rider_id'] ?? '', 1 );
					if ( ! MRSA::row( 'riders', $rider_id ) ) {
						throw new RuntimeException( 'The selected participant no longer exists.' );
					}
					$submission_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,image_file FROM ' . MRSA::table( 'bonus_submissions' ) . ' WHERE rider_id=%d', $rider_id ), ARRAY_A );
					$staged_submission_files = MRSA_Bonus::stage_submission_files( $submission_rows ?: array() );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'bonus_submissions' ), array( 'rider_id' => $rider_id ) ) );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'registrations' ), array( 'rider_id' => $rider_id ) ) );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'rider_bikes' ), array( 'rider_id' => $rider_id ) ) );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'horses' ), array( 'rider_id' => $rider_id ) ) );
					MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'riders' ), array( 'id' => $rider_id ) ) );
					$message = 'The selected participant and associated plugin data were deleted.';
					$audit_detail = ' Participant ID: ' . $rider_id . '.';
					break;
				case 'diagnostics_delete_audit':
					MRSA_Admin::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'import_audit' ) ) );
					$message = 'Virus-scan audit history was deleted and all remembered ClamAV overrides were reset. This deletion is recorded in the new audit log.';
					break;
				default:
					throw new RuntimeException( 'Unsupported diagnostics deletion.' );
			}
			MRSA_Bonus::audit_deletion( $event_id, $operation, $message . $audit_detail );
			MRSA::commit_transaction();
		} catch ( RuntimeException $e ) {
			if ( ! MRSA::rollback_transaction() ) {
				throw new RuntimeException( $e->getMessage() . ' The database rollback failed; gallery recovery was not attempted.' );
			}
			if ( $staged_gallery ) {
				try {
					if ( $all_galleries ) {
						MRSA_Bonus::restore_all_storage( $staged_gallery );
					} else {
						MRSA_Bonus::restore_event_storage( $gallery_event_id, $staged_gallery );
					}
				} catch ( RuntimeException $restore_exception ) {
					throw new RuntimeException( $e->getMessage() . ' Gallery restoration also failed: ' . $restore_exception->getMessage() );
				}
			}
			if ( ! empty( $staged_submission_files['root'] ) ) {
				MRSA_Bonus::restore_submission_files( $staged_submission_files );
			}
			throw $e;
		}
		if ( $staged_gallery ) {
			try {
				MRSA_Bonus::finish_staged_storage_removal( $staged_gallery );
			} catch ( RuntimeException $cleanup_exception ) {
				throw new RuntimeException( 'The database records were deleted, but staged gallery files could not be fully removed: ' . $cleanup_exception->getMessage() );
			}
		}
		if ( ! empty( $staged_submission_files['root'] ) ) {
			MRSA_Bonus::finish_staged_submission_files( $staged_submission_files );
		}
		return $message;
	}

}
