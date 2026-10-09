<?php
/** Standalone configuration regression tests: php tests/configuration-check.php */
require __DIR__ . '/diagnostics.php';
$GLOBALS['authorized'] = true;
$wp_version = '6.8';
function render_configuration() {
	ob_start();
	invoke_method( 'MRSA_Views', 'configuration_check' );
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
check( ! MRSA_Diagnostics::action_scheduler()['ready'], 'active plugin without scheduling API is not reported ready' );
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action() {}
}
check( MRSA_Diagnostics::action_scheduler()['ready'], 'installed active dependency with API is ready' );
foreach ( array( '8.0.0' => true, '5.7.44' => false, '10.6.0-MariaDB' => true, '5.5.5-10.11.6-MariaDB' => true, '10.5.28-MariaDB' => false, 'unknown' => false ) as $version => $supported ) {
	check( MRSA_Diagnostics::database_version_supported( $version ) === $supported, 'database minimum enforced for ' . $version );
}
foreach ( array( array( '10M', 10485760, false, 'passed' ), array( '9M', 10485760, false, 'failed' ), array( '101M', 105906176, true, 'passed' ), array( '100M', 105906176, true, 'failed' ), array( '0', 105906176, true, 'passed' ), array( '0', 10485760, false, 'failed' ) ) as $case ) {
	check( MRSA_Diagnostics::size_check( 'Test setting', $case[0], $case[1], $case[2] )['status'] === $case[3], 'exact size threshold checked: ' . $case[0] );
}
$GLOBALS['scanner_ready'] = false;
$report = MRSA_Diagnostics::check();
check( row_named( $report, 'ClamAV live scan' )['status'] === 'failed' && row_named( $report, 'ClamAV policies and signatures' )['status'] === 'review', 'unavailable scanner fails while unverified policies require manual review' );
check( row_named( $report, 'MRSA database tables' )['status'] === 'passed', 'all InnoDB tables pass metadata checks' );
$wpdb->table_engine = 'MyISAM';
check( row_named( MRSA_Diagnostics::check(), 'MRSA database tables' )['status'] === 'failed', 'nontransactional tables are flagged' );
$wpdb->table_engine = 'InnoDB';
$wpdb->missing_table = 'bonus_points';
check( strpos( row_named( MRSA_Diagnostics::check(), 'MRSA database tables' )['detail'], 'bonus_points' ) !== false, 'missing tables are named' );
$wpdb->missing_table = '';
$wpdb->database_version = '5.7.44';
$GLOBALS['schema_version'] = '5';
$GLOBALS['upload_limit'] = 9437184;
$GLOBALS['scanner_ready'] = true;
$report = MRSA_Diagnostics::check();
check( row_named( $report, 'Database server' )['status'] === 'failed' && row_named( $report, 'MRSA database schema' )['status'] === 'failed', 'outdated database and schema fail independently' );
check( row_named( $report, 'WordPress effective upload limit' )['status'] === 'failed', 'effective multisite or upload cap checked against 10 MB' );
check( row_named( $report, 'ClamAV live scan' )['status'] === 'passed' && row_named( $report, 'ClamAV policies and signatures' )['status'] === 'review', 'clean scan does not falsely certify signature freshness or limits' );
$GLOBALS['transients']['mrsa_configuration_1'] = array( 'checked_at' => '2026-10-09 20:00:00', 'rows' => array( array( 'name' => '<script>', 'status' => 'failed', 'detail' => '<unsafe>', 'remedy' => '<fix>' ) ) );
$html = render_configuration();
check( strpos( $html, '&lt;unsafe&gt;' ) !== false && strpos( $html, '<script>' ) === false, 'report output escapes diagnostic content' );
$GLOBALS['user_id'] = 2;
check( strpos( render_configuration(), '&lt;unsafe&gt;' ) === false, 'reports are isolated per administrator' );
$GLOBALS['authorized'] = false;
try { MRSA_Diagnostics::check(); throw new LogicException( 'Unauthorized check accepted.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'Only global' ) !== false, 'configuration collection rejects unauthorized administrator' ); }
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
try { MRSA_Admin::diagnostics_check(); throw new LogicException( 'Unauthorized POST accepted.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'Only global' ) !== false && $GLOBALS['scan_checks'] === $before, 'POST authorization runs before any environment checks' ); }
$GLOBALS['authorized'] = true;
try { MRSA_Admin::diagnostics_check(); throw new LogicException( 'Invalid nonce accepted.' ); }
catch ( RuntimeException $exception ) { check( $exception->getMessage() === 'Invalid nonce.' && $GLOBALS['scan_checks'] === $before, 'nonce verification runs before scanner checks' ); }
$GLOBALS['nonce_valid'] = true;
try { MRSA_Admin::diagnostics_check(); throw new LogicException( 'Expected redirect.' ); }
catch ( RuntimeException $exception ) {
	check( strpos( $exception->getMessage(), 'Redirect:' ) === 0 && strpos( $exception->getMessage(), '#mrsa-configuration' ) !== false && $GLOBALS['report_expiry'] === 900 && isset( $GLOBALS['transients']['mrsa_configuration_2']['rows'] ), 'valid check stores private expiring report and returns to diagnostic section' );
}
$GLOBALS['fail_report'] = true;
try { MRSA_Admin::diagnostics_check(); throw new LogicException( 'Report write failure ignored.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'could not be saved' ) !== false, 'report persistence failure surfaces explicitly' ); }
$_SERVER['REQUEST_METHOD'] = 'GET';
try { MRSA_Admin::diagnostics_check(); throw new LogicException( 'GET mutation accepted.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'Only global' ) !== false, 'check endpoint requires POST' ); }
