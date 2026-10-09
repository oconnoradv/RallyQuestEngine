<?php
/** Destructive cleanup is per-site and explicitly opt-in. Never touches users/media. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
// On multisite only opted-in sites are deleted, in bounded pages.
$cleanup = static function () use ( $wpdb ) {
	if ( ! get_option( 'mrsa_delete_on_uninstall', false ) ) {
		return;
	}
	foreach ( array( 'global_admins', 'event_admins', 'bikes', 'riders', 'rider_bikes', 'events', 'registrations' ) as $name ) {
		$table = $wpdb->prefix . 'MRSA_' . $name;
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" );
	}
	delete_option( 'mrsa_db_version' );
	delete_option( 'mrsa_delete_on_uninstall' );
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
