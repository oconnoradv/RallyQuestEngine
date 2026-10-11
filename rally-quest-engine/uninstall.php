<?php
/** Destructive cleanup is per-site and explicitly opt-in. Never touches users/media. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
require_once __DIR__ . '/includes/class-rqe-bonus.php';
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'rqe_process_submission_inboxes', array(), 'rqe' );
}
// On multisite only opted-in sites are deleted, in bounded pages.
$cleanup = static function () use ( $wpdb ) {
	if ( ! get_option( 'rqe_delete_on_uninstall', false ) ) {
		return;
	}
	foreach ( array( 'event_admins', 'event_scorers', 'event_email_settings', 'status_options', 'certificate_mail', 'bikes', 'horses', 'riders', 'rider_bikes', 'events', 'registrations', 'bonus_points', 'bonus_submissions', 'import_audit', 'global_admins' ) as $name ) {
		$table = $wpdb->prefix . 'RQE_' . $name;
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}
	RQE_Bonus::remove_all_storage();
	delete_option( 'rqe_db_version' );
	delete_option( 'rqe_delete_on_uninstall' );
	delete_option( 'rqe_primary_style' );
	delete_option( 'rqe_legacy_import' );
	delete_metadata( 'user', 0, $wpdb->prefix . 'rqe_selected_event', '', true );
	delete_metadata( 'user', 0, $wpdb->prefix . 'rqe_show_archived', '', true );
};
if ( is_multisite() ) {
	$offset = 0;
	do {
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			$cleanup();
			restore_current_blog();
		}
		$offset += count( $sites );
	} while ( count( $sites ) === 100 );
} else {
	$cleanup();
}
