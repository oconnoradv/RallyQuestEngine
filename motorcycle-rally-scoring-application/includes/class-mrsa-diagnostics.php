<?php
/** On-demand environment checks; no server configuration is changed. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Diagnostics {
	public static function action_scheduler() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file = 'action-scheduler/action-scheduler.php';
		$installed = isset( get_plugins()[ $file ] );
		$active = $installed && is_plugin_active( $file );
		return array( 'installed' => $installed, 'active' => $active, 'ready' => $active && function_exists( 'as_schedule_single_action' ) );
	}

	public static function size_check( $name, $value, $required, $zero_unlimited = false ) {
		$bytes = wp_convert_hr_to_bytes( $value );
		$unlimited = $zero_unlimited && '0' === trim( (string) $value );
		return array(
			'name' => $name, 'status' => $unlimited || $bytes >= $required ? 'passed' : 'failed',
			'detail' => 'Current: ' . ( $unlimited ? 'unlimited' : (string) $value ) . '. Required: at least ' . size_format( $required ) . '.',
			'remedy' => 'In the hosting PHP settings or the web-serving PHP php.ini, set ' . str_replace( 'PHP ', '', $name ) . ' = ' . (int) ceil( $required / 1048576 ) . 'M or higher. Do not edit only the command-line PHP configuration. If the host permits .user.ini, this setting can be placed there instead; allow its cache to refresh. Restart/reload the affected PHP-FPM or web-server service after php.ini changes. Rerun this check and confirm the reported value meets the requirement.',
		);
	}

	public static function database_version_supported( $version ) {
		if ( false !== stripos( $version, 'MariaDB' ) ) {
			return preg_match( '/(?:^|-)(\d+\.\d+\.\d+)(?=-MariaDB)/i', $version, $match ) && version_compare( $match[1], '10.6', '>=' );
		}
		return preg_match( '/^(\d+\.\d+(?:\.\d+)?)/', $version, $match ) && version_compare( $match[1], '8.0', '>=' );
	}

	public static function check() {
		if ( ! MRSA::global_access() ) {
			throw new RuntimeException( 'Only global administrators may check MRSA configuration.' );
		}
		global $wpdb, $wp_version;
		$rows = array();
		$plugin = self::action_scheduler();
		$rows[] = array(
			'name' => 'Action Scheduler (required)', 'status' => $plugin['ready'] ? 'passed' : 'failed',
			'detail' => $plugin['ready'] ? 'Installed, active, and scheduling API available.' : ( $plugin['installed'] ? 'Installed but inactive or its API is unavailable.' : 'Required standalone plugin is not installed.' ),
			'remedy' => 'Use Install Action Scheduler above, then activate it in Plugins > Installed Plugins; if already installed, use Activate Action Scheduler. If active but its API is unavailable, update the standalone plugin and inspect the PHP error log for load errors or plugin conflicts on a staging copy. A user without installation/activation permission must ask a WordPress administrator. Return here and rerun the check.',
		);
		$rows[] = array( 'name' => 'WordPress', 'status' => version_compare( $wp_version, '6.2', '>=' ) ? 'passed' : 'failed', 'detail' => 'Current: ' . $wp_version . '. Requires WordPress 6.2+.', 'remedy' => 'Back up the database, site files and event galleries. Test the upgrade on staging, then use Dashboard > Updates to install a supported WordPress release (minimum 6.2). If updates are host-managed, request the upgrade from your host. Rerun this check afterward.' );
		$rows[] = array( 'name' => 'PHP', 'status' => version_compare( PHP_VERSION, '7.4', '>=' ) ? 'passed' : 'failed', 'detail' => 'Current: ' . PHP_VERSION . '. Requires PHP 7.4+; use a currently supported PHP release.', 'remedy' => 'Back up and test on staging. In the hosting control panel, select a currently supported PHP version for this WordPress site, not only the command-line interpreter. Ask the host to change the PHP-FPM pool/web-server handler if no selector exists. Re-enable required extensions for that version and rerun this check.' );
		$functions = array(
			'simplexml_load_string' => array( 'SimpleXML (XLSX)', 'Enable SimpleXML for the PHP version serving WordPress. On Debian/Ubuntu, the matching phpX.Y-xml package supplies it; use your actual PHP version. Reload PHP-FPM/web server.' ),
			'gzuncompress' => array( 'zlib (XLSX/ZIP)', 'Enable zlib in the web-serving PHP installation; ask the host to install a PHP build with zlib support if it is absent. Reload PHP-FPM/web server.' ),
			'stream_socket_client' => array( 'Local sockets (ClamAV)', 'Ask the host to allow stream_socket_client in the web-serving PHP disable_functions policy and local socket connections to clamd. This uses PHP streams; installing the sockets extension alone does not resolve a disabled streams function.' ),
			'getimagesize' => array( 'Image inspection', 'Ask the host to enable getimagesize in the web-serving PHP configuration/disable_functions policy. This standard image-inspection function does not require installing GD.' ),
			'hash_file' => array( 'File fingerprints', 'Ask the host to enable the standard hash extension and allow hash_file in the web-serving PHP disable_functions policy. Reload PHP-FPM/web server.' ),
		);
		foreach ( $functions as $function => $requirement ) {
			$rows[] = array( 'name' => $requirement[0], 'status' => function_exists( $function ) ? 'passed' : 'failed', 'detail' => function_exists( $function ) ? 'Available.' : 'Required PHP function unavailable: ' . $function . '.', 'remedy' => $requirement[1] . ' Ask the host to confirm this function is available to the WordPress PHP process, then rerun this check.' );
		}
		$rows[] = array( 'name' => 'WordPress archive reader', 'status' => class_exists( 'PclZip' ) || is_readable( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ? 'passed' : 'failed', 'detail' => 'XLSX and ZIP imports use the PclZip reader bundled with WordPress.', 'remedy' => 'After a full backup, use Dashboard > Updates > Re-install the current WordPress version, or ask the host to restore wp-admin/includes/class-pclzip.php from the matching official WordPress release. Check that the PHP service account can read it; do not download replacement PHP files from unofficial sources. Rerun the check.' );
		$version = $wpdb->get_var( 'SELECT VERSION()' );
		$rows[] = array( 'name' => 'Database server', 'status' => ! $wpdb->last_error && is_string( $version ) && self::database_version_supported( $version ) ? 'passed' : 'failed', 'detail' => $wpdb->last_error ? 'Database version query failed.' : 'Current: ' . (string) $version . '. Requires MySQL 8.0+ or MariaDB 10.6+.', 'remedy' => 'If the query failed, ask the host to verify DB_HOST, DB_NAME, DB_USER and DB_PASSWORD in wp-config.php and database connectivity/permissions; do not share credentials in support screenshots. For an old server, back up and test a host-managed migration to a supported MySQL 8.0+ or MariaDB 10.6+ release. Rerun the check after reconnecting.' );
		$invalid = array();
		foreach ( array( 'global_admins', 'event_admins', 'bikes', 'riders', 'rider_bikes', 'events', 'registrations', 'bonus_points', 'import_audit' ) as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', MRSA::table( $table ) ), ARRAY_A );
			if ( $wpdb->last_error || ! $row || 'innodb' !== strtolower( $row['Engine'] ?? '' ) ) {
				$invalid[] = $table;
			}
		}
		$rows[] = array( 'name' => 'MRSA database tables', 'status' => $invalid ? 'failed' : 'passed', 'detail' => $invalid ? 'Missing, unreadable, or not InnoDB: ' . implode( ', ', $invalid ) . '.' : 'All nine MRSA tables exist and use InnoDB for transactional updates.', 'remedy' => 'Back up before changing tables. In phpMyAdmin or the host database console, inspect the listed tables using the actual WordPress prefix (for example wp_MRSA_bonus_points). Ask the DBA to restore missing data from backup and resolve table metadata/CREATE/ALTER permissions. After testing on staging, convert existing non-InnoDB MRSA tables with ALTER TABLE `actual_table_name` ENGINE=InnoDB; substitute the verified table name, never run the placeholder. Install/reactivate the current MRSA plugin for new tables, then rerun the check. Do not use Diagnostics deletion as a repair.' );
		$schema = (string) get_option( 'mrsa_db_version', '' );
		$rows[] = array( 'name' => 'MRSA database schema', 'status' => MRSA_DB_VERSION === $schema ? 'passed' : 'failed', 'detail' => 'Installed schema: ' . ( $schema ?: 'unknown' ) . '. Expected: ' . MRSA_DB_VERSION . '.', 'remedy' => 'Back up the database and install the current MRSA package, then reload WordPress to trigger its schema upgrade. If the mismatch remains, ask the host to check PHP/database logs and the database user CREATE/ALTER permissions. Do not manually change the mrsa_db_version option to hide a failed upgrade. Rerun the check.' );
		$rows[] = self::size_check( 'PHP upload_max_filesize', ini_get( 'upload_max_filesize' ), max( MRSA_Bonus::MAX_SHEET_SIZE, MRSA_Bonus::MAX_IMAGE_SIZE ) );
		// Local queues send one file per request; aggregate limits remain enforced by the importer/UI.
		$rows[] = self::size_check( 'PHP post_max_size', ini_get( 'post_max_size' ), MRSA_Bonus::MAX_IMAGE_SIZE + 1048576, true );
		$rows[] = array( 'name' => 'WordPress effective upload limit', 'status' => wp_max_upload_size() >= MRSA_Bonus::MAX_IMAGE_SIZE ? 'passed' : 'failed', 'detail' => 'Current: ' . size_format( wp_max_upload_size() ) . '. Required: at least ' . size_format( MRSA_Bonus::MAX_IMAGE_SIZE ) . ' per image/spreadsheet.', 'remedy' => 'Set web-serving PHP upload_max_filesize to at least 10M and post_max_size to at least 11M. On multisite, use Network Admin > Settings > Upload Settings and set Max upload file size to at least 10240 KB; check the site upload-space quota too. Ask the administrator to review plugins/filters that lower upload limits if PHP and network settings already pass. Rerun the check.' );
		$uploads = wp_upload_dir();
		$gallery = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) && is_writable( $uploads['basedir'] );
		$root = $gallery ? MRSA_Bonus::storage_root() : '';
		if ( $root && file_exists( $root ) ) {
			$gallery = is_dir( $root ) && is_writable( $root );
		}
		$rows[] = array( 'name' => 'Event gallery storage', 'status' => $gallery ? 'passed' : 'failed', 'detail' => $gallery ? 'Uploads and existing gallery root are writable.' : 'Uploads or the event gallery root are unavailable/unwritable.', 'remedy' => 'Ask the host to locate the configured uploads directory and its mrsa-event-gallery subdirectory. Give only the PHP service account/appropriate group write and directory traversal permissions; check disk quota, free space, read-only mounts and SELinux/AppArmor restrictions. Do not use world-writable 0777 permissions. Preserve/back up existing event images, then rerun the check.' );
		$staging = MRSA_Bonus::check_staging();
		$rows[] = array( 'name' => 'Private import staging', 'status' => $staging['passed'] ? 'passed' : 'failed', 'detail' => $staging['message'], 'remedy' => 'Have the host create a private directory outside the document root, WordPress, wp-content and uploads, owned by the PHP service account (0700 on Unix). Before the stop-editing line in wp-config.php, add define( \'MRSA_IMPORT_TEMP_DIR\', \'/absolute/private/path/mrsa-imports\' ); replacing the example with the actual private path (use a valid Windows path on Windows). Check PHP open_basedir policy, disk space and write permissions. Never stage files in public uploads. Rerun the check.' );
		$scan = MRSA_File_Scan::check_connection();
		$bypassed = MRSA_Bonus::bypass_enabled();
		$rows[] = array( 'name' => 'ClamAV live scan', 'status' => $scan['passed'] ? 'passed' : ( $bypassed ? 'review' : 'failed' ), 'detail' => $scan['message'] . ( ! $scan['passed'] && $bypassed ? ' Manual review: your audited scoring-page ClamAV override is enabled. This does not certify scanning or make roster imports available.' : '' ), 'remedy' => 'Open Scoring > select an event > Bonus Point Library > Step 0 for full installation commands. On Debian/Ubuntu, the host installs clamav and clamav-daemon, enables freshclam, waits for signatures and starts clamav-daemon. Verify service status/logs and grant the PHP account access to the local socket without making it world-writable. Set MRSA_CLAMAV_SOCKET in wp-config.php to the actual Unix socket or tcp://127.0.0.1:3310 for a loopback-only listener; never expose clamd publicly. Use Check ClamAV Connection, then rerun Diagnostics. On Windows/other hosting use the platform guide in Step 0. An override does not fix the scanner; roster imports still require a working scanner, and bonus imports still block malware, scanner errors and unsafe content.' );
		$rows[] = array( 'name' => 'ClamAV policies and signatures', 'status' => 'review', 'detail' => 'The live test scans a harmless file, not a maximum-sized upload. Signature freshness and scan limits cannot be certified by this test.', 'remedy' => 'Have the host review freshclam logs for recent successful signature updates. In the active clamd.conf (Debian/Ubuntu: /etc/clamav/clamd.conf), set ScanArchive, ScanOLE2, ScanPE, ScanELF and AlertExceedsMax to yes; StreamMaxLength and MaxFileSize to at least 60M; MaxScanSize to at least 200M. Update existing entries, restart clamd and check service logs. Follow Step 0 for platform-specific guidance. Rerunning the small scan cannot automatically certify these policies; retain host verification.' );
		$rows[] = array( 'name' => 'Hosting and import capacity', 'status' => 'review', 'detail' => 'Web-server/proxy body limits, execution time, memory, disk space and per-event filesystem permissions require server review.', 'remedy' => 'Ask the host to allow at least 11 MB request bodies at every web-server/proxy/CDN layer. For Nginx, configure client_max_body_size 11m or higher in the site configuration and reload after validating it; for Apache, review LimitRequestBody (at least 11534336 bytes if limited); on IIS, review requestFiltering/requestLimits maxAllowedContentLength and other request caps. Check storage quota and request timeouts. Local scoring images upload one per request; the queue still allows 200 images / 100 MB total. Test a permitted large file on staging; XLSX/ZIP parsing needs additional memory. This remains manual review.' );
		$rows[] = array( 'name' => 'PHP max_file_uploads', 'status' => (int) ini_get( 'max_file_uploads' ) >= 1 ? 'passed' : 'failed', 'detail' => 'Current: ' . ini_get( 'max_file_uploads' ) . '. Sequential scoring uploads require one file per request.', 'remedy' => 'In the host PHP settings or web-serving php.ini, set file_uploads = On and max_file_uploads = 1 or higher. The default of 20 is also suitable; you do not need 200 for sequential uploads. Have the host reload PHP-FPM/web server and rerun the check.' );
		$rows[] = array( 'name' => 'PHP resources and scheduled cleanup', 'status' => 'review', 'detail' => 'memory_limit: ' . ini_get( 'memory_limit' ) . '; max_execution_time: ' . ini_get( 'max_execution_time' ) . ' seconds. WordPress built-in cron: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled' : 'enabled' ) . '.', 'remedy' => 'Test representative imports on staging and review PHP logs for memory exhaustion/timeouts; have the host adjust web-serving memory_limit, max_execution_time and upstream timeouts based on measured demand, rather than disabling limits. If DISABLE_WP_CRON is true, configure a host scheduled task to run WordPress cron (for example wp cron event run --due-now with the correct site path and service account). Otherwise ensure loopback cron requests work and traffic triggers cleanup. Verify mrsa_bonus_expire events run and abandoned staging is removed. A small scan cannot validate full-batch resources or cron delivery.' );
		return array( 'checked_at' => gmdate( 'Y-m-d H:i:s' ), 'rows' => $rows );
	}
}
