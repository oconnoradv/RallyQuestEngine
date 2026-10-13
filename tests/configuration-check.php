<?php
/** Standalone configuration regression tests: php tests/configuration-check.php */
require __DIR__ . '/diagnostics.php';
$GLOBALS['authorized'] = true;
$wp_version = '6.8';
function render_configuration() {
	ob_start();
	invoke_method( 'RQE_Views', 'configuration_check' );
	return ob_get_clean();
}
function row_named( $report, $name ) {
	foreach ( $report['rows'] as $row ) {
		if ( $row['name'] === $name ) { return $row; }
	}
	throw new RuntimeException( 'Missing check: ' . $name );
}
$GLOBALS['can_manage_plugins'] = true;
$html = render_configuration();
check( strpos( $html, 'Install Action Scheduler' ) !== false && strpos( $html, 'install-plugin_action-scheduler' ) !== false, 'missing dependency has a nonce-protected WordPress install link' );
check( empty( $GLOBALS['scan_checks'] ), 'rendering configuration does not run background scans' );
$GLOBALS['can_manage_plugins'] = false;
check( strpos( render_configuration(), 'Install Action Scheduler' ) === false, 'plugin installation is hidden without WordPress installer capability' );
$GLOBALS['scheduler_installed'] = true;
$GLOBALS['can_manage_plugins'] = true;
$html = render_configuration();
check( strpos( $html, 'Activate Action Scheduler' ) !== false && strpos( $html, 'activate-plugin_action-scheduler' ) !== false, 'installed inactive dependency offers native activation' );
$GLOBALS['scheduler_active'] = true;
check( ! RQE_Diagnostics::action_scheduler()['ready'], 'active plugin without scheduling API is not reported ready' );
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action() {}
}
if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action() {}
}
if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action() { return false; }
}
check( RQE_Diagnostics::action_scheduler()['ready'], 'installed active dependency with API is ready' );
foreach ( array( '8.0.0' => true, '5.7.44' => false, '10.6.0-MariaDB' => true, '5.5.5-10.11.6-MariaDB' => true, '10.5.28-MariaDB' => false, 'unknown' => false ) as $version => $supported ) {
	check( RQE_Diagnostics::database_version_supported( $version ) === $supported, 'database minimum enforced for ' . $version );
}
foreach ( array( array( '10M', 10485760, false, 'passed' ), array( '9M', 10485760, false, 'failed' ), array( '11M', 11534336, true, 'passed' ), array( '10M', 11534336, true, 'failed' ), array( '0', 11534336, true, 'passed' ), array( '0', 10485760, false, 'failed' ) ) as $case ) {
	check( RQE_Diagnostics::size_check( 'Test setting', $case[0], $case[1], $case[2] )['status'] === $case[3], 'exact size threshold checked: ' . $case[0] );
}
$GLOBALS['scanner_ready'] = false;
$report = RQE_Diagnostics::check();
foreach ( array(
	'Action Scheduler (required)' => 'Plugins > Installed Plugins',
	'WordPress' => 'Dashboard > Updates',
	'PHP' => 'PHP-FPM',
	'SimpleXML (XLSX)' => 'phpX.Y-xml',
	'zlib (XLSX/ZIP)' => 'zlib support',
	'Local sockets (ClamAV)' => 'disable_functions',
	'Image inspection' => 'does not require installing GD',
	'File fingerprints' => 'hash_file',
	'WordPress archive reader' => 'wp-admin/includes/class-pclzip.php',
	'Database server' => 'DB_HOST',
	'RQE database tables' => 'ENGINE=InnoDB',
	'RQE database schema' => 'Do not manually change',
	'PHP upload_max_filesize' => 'upload_max_filesize = 10M',
	'PHP post_max_size' => 'post_max_size = 11M',
	'WordPress effective upload limit' => '10240 KB',
	'Event gallery storage' => 'Do not use world-writable 0777',
	'Private import staging' => 'RQE_IMPORT_TEMP_DIR',
	'ClamAV live scan' => 'RQE_CLAMAV_SOCKET',
	'ClamAV policies and signatures' => 'clamd.conf',
	'Hosting and import capacity' => 'client_max_body_size',
	'PHP max_file_uploads' => 'max_file_uploads = 1',
	'PHP resources and scheduled cleanup' => 'wp cron event run --due-now',
) as $name => $instruction ) {
	check( strpos( row_named( $report, $name )['remedy'], $instruction ) !== false, 'actionable remediation provided for ' . $name );
}
check( row_named( $report, 'ClamAV live scan' )['status'] === 'failed' && row_named( $report, 'ClamAV policies and signatures' )['status'] === 'review', 'unavailable scanner fails while unverified policies require manual review' );
$audit_before = $wpdb->tables['import_audit'];
$wpdb->tables['import_audit'][] = array( 'user_id' => get_current_user_id(), 'action' => 'bypass_enabled' );
$overridden = row_named( RQE_Diagnostics::check(), 'ClamAV live scan' );
check( $overridden['status'] === 'review' && strpos( $overridden['detail'], 'override is enabled' ) !== false, 'unavailable scanner with current administrator override requires manual review rather than failing' );
$GLOBALS['scanner_ready'] = true;
check( row_named( RQE_Diagnostics::check(), 'ClamAV live scan' )['status'] === 'passed', 'working scanner remains passed even with override' );
$GLOBALS['scanner_ready'] = false;
$wpdb->tables['import_audit'][] = array( 'user_id' => get_current_user_id(), 'action' => 'bypass_disabled' );
check( row_named( RQE_Diagnostics::check(), 'ClamAV live scan' )['status'] === 'failed', 'revoked override restores unavailable scanner failure' );
$wpdb->tables['import_audit'] = $audit_before;
$wpdb->tables['import_audit'][] = array( 'user_id' => 99, 'action' => 'bypass_enabled' );
check( row_named( RQE_Diagnostics::check(), 'ClamAV live scan' )['status'] === 'failed', 'another administrator override does not change current diagnostics' );
$wpdb->tables['import_audit'] = $audit_before;
check( row_named( $report, 'RQE database tables' )['status'] === 'passed', 'all InnoDB tables pass metadata checks' );
$wpdb->table_engine = 'MyISAM';
check( row_named( RQE_Diagnostics::check(), 'RQE database tables' )['status'] === 'failed', 'nontransactional tables are flagged' );
$wpdb->table_engine = 'InnoDB';
$wpdb->missing_table = 'bonus_points';
check( strpos( row_named( RQE_Diagnostics::check(), 'RQE database tables' )['detail'], 'bonus_points' ) !== false, 'missing tables are named' );
$wpdb->missing_table = '';
$wpdb->database_version = '5.7.44';
$GLOBALS['schema_version'] = '6';
$GLOBALS['upload_limit'] = 9437184;
$GLOBALS['scanner_ready'] = true;
$report = RQE_Diagnostics::check();
check( row_named( $report, 'Database server' )['status'] === 'failed' && row_named( $report, 'RQE database schema' )['status'] === 'failed', 'outdated database and schema fail independently' );
check( row_named( $report, 'WordPress effective upload limit' )['status'] === 'failed', 'effective multisite or upload cap checked against 10 MB' );
check( row_named( $report, 'ClamAV live scan' )['status'] === 'passed' && row_named( $report, 'ClamAV policies and signatures' )['status'] === 'review', 'clean scan does not falsely certify signature freshness or limits' );
$GLOBALS['transients']['rqe_configuration_1'] = array( 'checked_at' => '2026-10-09 20:00:00', 'rows' => array( array( 'name' => '<script>', 'status' => 'failed', 'detail' => '<unsafe>', 'remedy' => '<fix>' ) ) );
$html = render_configuration();
check( strpos( $html, '&lt;unsafe&gt;' ) !== false && strpos( $html, '<script>' ) === false, 'report output escapes diagnostic content' );
check( strpos( $html, 'rqe-configuration-failed" id="rqe-configuration" open' ) !== false && strpos( $html, '<tr class="rqe-configuration-failed-row">' ) !== false, 'failed report stays expanded and highlights failed rows' );
check( strpos( $html, 'class="rqe-configuration-help" open><summary>How to fix this requirement' ) !== false && strpos( $html, '&lt;fix&gt;' ) !== false, 'failed-check instructions are expanded and escaped' );
$GLOBALS['transients']['rqe_configuration_1']['rows'][0]['status'] = 'review';
$html = render_configuration();
check( strpos( $html, 'class="rqe-configuration-help"><summary>How to verify this requirement' ) !== false, 'manual-review instructions can be expanded' );
check( strpos( $html, 'rqe-configuration-review" id="rqe-configuration"><summary>' ) !== false && strpos( $html, 'rqe-configuration-failed-row' ) === false, 'manual review without failures is orange and minimized' );
$GLOBALS['transients']['rqe_configuration_1']['rows'][] = array( 'name' => 'Failure', 'status' => 'failed', 'detail' => 'Failed check', 'remedy' => 'Fix it' );
check( strpos( render_configuration(), 'rqe-configuration-failed" id="rqe-configuration" open' ) !== false, 'failures take precedence over manual review' );
array_pop( $GLOBALS['transients']['rqe_configuration_1']['rows'] );
$GLOBALS['transients']['rqe_configuration_1']['rows'][0]['status'] = 'passed';
$html = render_configuration();
check( strpos( $html, 'rqe-configuration-help' ) === false, 'passed checks do not show unnecessary fix instructions' );
check( strpos( $html, 'rqe-configuration-passed" id="rqe-configuration"><summary>' ) !== false, 'all-passed report is green and minimized' );
$GLOBALS['user_id'] = 2;
check( strpos( render_configuration(), '&lt;unsafe&gt;' ) === false, 'reports are isolated per administrator' );
check( strpos( render_configuration(), 'rqe-diagnostics-section" id="rqe-configuration" open' ) !== false, 'missing or expired report stays neutral and expanded to run checks' );
update_user_option( 2, 'rqe_configuration_last_state', 'passed' );
$html = render_configuration();
check( strpos( $html, 'rqe-configuration-passed" id="rqe-configuration"><summary>' ) !== false && strpos( $html, 'Last check: Passed' ) !== false && strpos( $html, 'Results expired' ) !== false && strpos( $html, '&lt;unsafe&gt;' ) === false, 'expired passed report retains green border, minimized state and expired label without stale details' );
foreach ( array( 'failed', 'review' ) as $state ) {
	update_user_option( 2, 'rqe_configuration_last_state', $state );
	$html = render_configuration();
	check( strpos( $html, 'rqe-configuration-' . $state . '" id="rqe-configuration"' . ( 'failed' === $state ? ' open' : '' ) . '><summary>' ) !== false && strpos( $html, 'Results expired' ) !== false, 'expired ' . $state . ' report retains its border and result-based default' );
}
$GLOBALS['authorized'] = false;
try { RQE_Diagnostics::check(); throw new LogicException( 'Unauthorized check accepted.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'Only WordPress' ) !== false, 'configuration collection rejects unauthorized administrator' ); }
function wp_die( $message, $title = '', $options = array() ) { throw new RuntimeException( $message ); }
function check_admin_referer( $action ) {
	$GLOBALS['checked_nonce'] = $action;
	if ( empty( $GLOBALS['nonce_valid'] ) ) { throw new RuntimeException( 'Invalid nonce.' ); }
}
function set_transient( $key, $value, $expiry ) {
	if ( ! empty( $GLOBALS['fail_report'] ) ) { return false; }
	$GLOBALS['transients'][ $key ] = $value;
	$GLOBALS['report_expiry'] = $expiry;
	return true;
}
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_safe_redirect( $url ) { throw new RuntimeException( 'Redirect: ' . $url ); }
$_SERVER['REQUEST_METHOD'] = 'POST';
$before = $GLOBALS['scan_checks'];
try { RQE_Admin::diagnostics_check(); throw new LogicException( 'Unauthorized POST accepted.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'Only WordPress' ) !== false && $GLOBALS['scan_checks'] === $before, 'POST authorization runs before any environment checks' ); }
$GLOBALS['authorized'] = true;
try { RQE_Admin::diagnostics_check(); throw new LogicException( 'Invalid nonce accepted.' ); }
catch ( RuntimeException $exception ) { check( $exception->getMessage() === 'Invalid nonce.' && $GLOBALS['scan_checks'] === $before, 'nonce verification runs before scanner checks' ); }
$GLOBALS['nonce_valid'] = true;
try { RQE_Admin::diagnostics_check(); throw new LogicException( 'Expected redirect.' ); }
catch ( RuntimeException $exception ) {
	check( strpos( $exception->getMessage(), 'Redirect:' ) === 0 && strpos( $exception->getMessage(), '#rqe-configuration' ) !== false && $GLOBALS['report_expiry'] === 900 && isset( $GLOBALS['transients']['rqe_configuration_2']['rows'] ), 'valid check stores private expiring report and returns to diagnostic section' );
	check( get_user_option( 'rqe_configuration_last_state', 2 ) === 'failed', 'latest failed check replaces prior outcome' );
}
$GLOBALS['fail_user_option'] = true;
$GLOBALS['user_options'][2]['rqe_configuration_last_state'] = 'passed';
try { RQE_Admin::diagnostics_check(); throw new LogicException( 'Outcome write failure ignored.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'last configuration outcome could not be saved' ) !== false, 'outcome persistence failure surfaces explicitly' ); }
$GLOBALS['fail_user_option'] = false;
$GLOBALS['fail_report'] = true;
try { RQE_Admin::diagnostics_check(); throw new LogicException( 'Report write failure ignored.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'could not be saved' ) !== false, 'report persistence failure surfaces explicitly' ); }
$_SERVER['REQUEST_METHOD'] = 'GET';
try { RQE_Admin::diagnostics_check(); throw new LogicException( 'GET mutation accepted.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'Only WordPress' ) !== false, 'check endpoint requires POST' ); }
