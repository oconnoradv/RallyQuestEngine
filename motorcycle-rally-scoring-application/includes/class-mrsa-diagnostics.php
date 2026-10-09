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
			'remedy' => 'Ask the server administrator to update PHP configuration and any host limits, then rerun this check.',
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
			'remedy' => 'Install and activate Action Scheduler through WordPress, then rerun the check.',
		);
		$rows[] = array( 'name' => 'WordPress', 'status' => version_compare( $wp_version, '6.2', '>=' ) ? 'passed' : 'failed', 'detail' => 'Current: ' . $wp_version . '. Requires WordPress 6.2+.', 'remedy' => 'Back up the site and update WordPress.' );
		$rows[] = array( 'name' => 'PHP', 'status' => version_compare( PHP_VERSION, '7.4', '>=' ) ? 'passed' : 'failed', 'detail' => 'Current: ' . PHP_VERSION . '. Requires PHP 7.4+; use a currently supported PHP release.', 'remedy' => 'Ask the host to upgrade PHP.' );
		foreach ( array( 'simplexml_load_string' => 'SimpleXML (XLSX)', 'gzuncompress' => 'zlib (XLSX/ZIP)', 'stream_socket_client' => 'Local sockets (ClamAV)', 'getimagesize' => 'Image inspection', 'hash_file' => 'File fingerprints' ) as $function => $label ) {
			$rows[] = array( 'name' => $label, 'status' => function_exists( $function ) ? 'passed' : 'failed', 'detail' => function_exists( $function ) ? 'Available.' : 'Required PHP function unavailable.', 'remedy' => 'Ask the host to enable the required PHP extension/function.' );
		}
		$rows[] = array( 'name' => 'WordPress archive reader', 'status' => class_exists( 'PclZip' ) || is_readable( ABSPATH . 'wp-admin/includes/class-pclzip.php' ) ? 'passed' : 'failed', 'detail' => 'XLSX and ZIP imports use the PclZip reader bundled with WordPress.', 'remedy' => 'Restore missing WordPress core files using the WordPress update/reinstall tools.' );
		$version = $wpdb->get_var( 'SELECT VERSION()' );
		$rows[] = array( 'name' => 'Database server', 'status' => ! $wpdb->last_error && is_string( $version ) && self::database_version_supported( $version ) ? 'passed' : 'failed', 'detail' => $wpdb->last_error ? 'Database version query failed.' : 'Current: ' . (string) $version . '. Requires MySQL 8.0+ or MariaDB 10.6+.', 'remedy' => 'Ask the host to verify database connectivity and upgrade the database server if necessary.' );
		$invalid = array();
		foreach ( array( 'global_admins', 'event_admins', 'bikes', 'riders', 'rider_bikes', 'events', 'registrations', 'bonus_points', 'import_audit' ) as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', MRSA::table( $table ) ), ARRAY_A );
			if ( $wpdb->last_error || ! $row || 'innodb' !== strtolower( $row['Engine'] ?? '' ) ) {
				$invalid[] = $table;
			}
		}
		$rows[] = array( 'name' => 'MRSA database tables', 'status' => $invalid ? 'failed' : 'passed', 'detail' => $invalid ? 'Missing, unreadable, or not InnoDB: ' . implode( ', ', $invalid ) . '.' : 'All nine MRSA tables exist and use InnoDB for transactional updates.', 'remedy' => 'Back up the database; ask the administrator to repair/install MRSA tables and ensure InnoDB.' );
		$schema = (string) get_option( 'mrsa_db_version', '' );
		$rows[] = array( 'name' => 'MRSA database schema', 'status' => MRSA_DB_VERSION === $schema ? 'passed' : 'failed', 'detail' => 'Installed schema: ' . ( $schema ?: 'unknown' ) . '. Expected: ' . MRSA_DB_VERSION . '.', 'remedy' => 'Reload WordPress with the current MRSA plugin to run its schema upgrade; investigate database errors if this persists.' );
		$rows[] = self::size_check( 'PHP upload_max_filesize', ini_get( 'upload_max_filesize' ), max( MRSA_Bonus::MAX_SHEET_SIZE, MRSA_Bonus::MAX_IMAGE_SIZE ) );
		// Local queues send one file per request; aggregate limits remain enforced by the importer/UI.
		$rows[] = self::size_check( 'PHP post_max_size', ini_get( 'post_max_size' ), MRSA_Bonus::MAX_IMAGE_SIZE + 1048576, true );
		$rows[] = array( 'name' => 'WordPress effective upload limit', 'status' => wp_max_upload_size() >= MRSA_Bonus::MAX_IMAGE_SIZE ? 'passed' : 'failed', 'detail' => 'Current: ' . size_format( wp_max_upload_size() ) . '. Required: at least ' . size_format( MRSA_Bonus::MAX_IMAGE_SIZE ) . ' per image/spreadsheet.', 'remedy' => 'Check PHP and multisite upload limits with the site/server administrator.' );
		$uploads = wp_upload_dir();
		$gallery = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) && is_writable( $uploads['basedir'] );
		$root = $gallery ? MRSA_Bonus::storage_root() : '';
		if ( $root && file_exists( $root ) ) {
			$gallery = is_dir( $root ) && is_writable( $root );
		}
		$rows[] = array( 'name' => 'Event gallery storage', 'status' => $gallery ? 'passed' : 'failed', 'detail' => $gallery ? 'Uploads and existing gallery root are writable.' : 'Uploads or the event gallery root are unavailable/unwritable.', 'remedy' => 'Ask the host to check PHP file ownership and permissions. No gallery files are changed by this check.' );
		$staging = MRSA_Bonus::check_staging();
		$rows[] = array( 'name' => 'Private import staging', 'status' => $staging['passed'] ? 'passed' : 'failed', 'detail' => $staging['message'], 'remedy' => 'Configure MRSA_IMPORT_TEMP_DIR as a writable directory outside web-accessible roots.' );
		$scan = MRSA_File_Scan::check_connection();
		$rows[] = array( 'name' => 'ClamAV live scan', 'status' => $scan['passed'] ? 'passed' : 'failed', 'detail' => $scan['message'], 'remedy' => 'Use bonus import Step 0 for server setup guidance. ClamAV is server software, not a WordPress plugin; roster imports require a working scanner.' );
		$rows[] = array( 'name' => 'ClamAV policies and signatures', 'status' => 'review', 'detail' => 'The live test scans a harmless file, not a maximum-sized upload. Signature freshness and scan limits cannot be certified by this test.', 'remedy' => 'Verify freshclam updates, archive/Office/PE/ELF scanning and AlertExceedsMax yes. StreamMaxLength and MaxFileSize: at least 60 MB; MaxScanSize: at least 200 MB. See README setup guidance.' );
		$rows[] = array( 'name' => 'Hosting and import capacity', 'status' => 'review', 'detail' => 'Web-server/proxy body limits, execution time, memory, disk space and per-event filesystem permissions require server review.', 'remedy' => 'Allow at least 11 MB request bodies for one 10 MB image/spreadsheet plus multipart overhead. Local scoring images upload one per request; the queue still allows 200 images / 100 MB total. XLSX/ZIP parsing may use substantially more memory than the compressed file size.' );
		$rows[] = array( 'name' => 'PHP max_file_uploads', 'status' => (int) ini_get( 'max_file_uploads' ) >= 1 ? 'passed' : 'failed', 'detail' => 'Current: ' . ini_get( 'max_file_uploads' ) . '. Sequential scoring uploads require one file per request.', 'remedy' => 'Enable at least one uploaded file per request.' );
		$rows[] = array( 'name' => 'PHP resources and scheduled cleanup', 'status' => 'review', 'detail' => 'memory_limit: ' . ini_get( 'memory_limit' ) . '; max_execution_time: ' . ini_get( 'max_execution_time' ) . ' seconds. WordPress built-in cron: ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled' : 'enabled' ) . '.', 'remedy' => 'Verify enough memory/time for your batch sizes and a reliable WordPress cron runner for expired import cleanup. A small live test cannot validate full-batch resources or cron delivery.' );
		return array( 'checked_at' => gmdate( 'Y-m-d H:i:s' ), 'rows' => $rows );
	}
}
