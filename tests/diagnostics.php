<?php
/** Standalone diagnostics regression tests: php tests/diagnostics.php */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MRSA_DB_VERSION', '6' );
define( 'MINUTE_IN_SECONDS', 60 );
final class MRSA {
	public static function table( $name ) { return $name; }
	public static function global_access() { return $GLOBALS['authorized']; }
	public static function event_access( $id ) { return self::global_access(); }
	public static function row( $table, $id ) { return array( 'id' => $id ); }
	public static function begin_transaction() { $GLOBALS['wpdb']->snapshot = $GLOBALS['wpdb']->tables; }
	public static function commit_transaction() {}
	public static function rollback_transaction() {
		$GLOBALS['wpdb']->tables = $GLOBALS['wpdb']->snapshot;
		return true;
	}
}
final class Diagnostics_Test_DB {
	public $tables = array();
	public $snapshot = array();
	public $fail_audit = false;
	public $last_error = '';
	public $database_version = '8.0.40';
	public $table_engine = 'InnoDB';
	public $missing_table = '';
	public function query( $sql ) {
		$table = substr( $sql, strlen( 'DELETE FROM ' ) );
		$this->tables[ $table ] = array();
		return 1;
	}
	public function delete( $table, $where ) {
		$this->tables[ $table ] = array_values( array_filter( $this->tables[ $table ], function ( $row ) use ( $where ) {
			foreach ( $where as $key => $value ) {
				if ( $row[ $key ] !== $value ) { return true; }
			}
			return false;
		} ) );
		return 1;
	}
	public function insert( $table, $data ) {
		if ( $this->fail_audit ) { return false; }
		$this->tables[ $table ][] = $data;
		return 1;
	}
	public function prepare( $sql, ...$args ) { return $args; }
	public function get_var( $args ) {
		if ( 'SELECT VERSION()' === $args ) { return $this->database_version; }
		foreach ( array_reverse( $this->tables['import_audit'] ) as $row ) {
			if ( $row['user_id'] === $args[0] && in_array( $row['action'], array( 'bypass_enabled', 'bypass_disabled' ), true ) ) {
				return $row['action'];
			}
		}
		return null;
	}
	public function get_row( $query, $format ) {
		return $query[0] === $this->missing_table ? null : array( 'Engine' => $this->table_engine );
	}
	public function get_results( $sql, $format ) {
		if ( strpos( $sql, 'FROM events' ) !== false ) {
			return array( array( 'id' => 7, 'name' => 'Autumn Rally', 'event_date' => '2026-10-09' ) );
		}
		if ( strpos( $sql, 'FROM riders' ) !== false ) {
			return array( array( 'id' => 9, 'name' => 'Test Rider', 'email' => 'rider@example.invalid' ) );
		}
		return array();
	}
}
function get_current_user_id() { return $GLOBALS['user_id'] ?? 1; }
function wp_get_current_user() { return (object) array( 'ID' => get_current_user_id(), 'user_login' => 'admin-' . get_current_user_id() ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function wp_hash( $value ) { return hash_hmac( 'sha256', $value, 'diagnostics-test-key' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function admin_url( $path ) { return '/not-a-real-wordpress-endpoint/' . $path; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_generate_password( $length, $special, $extra ) { return substr( str_repeat( 'AB12CD34', 2 ), 0, $length ); }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/mrsa-diagnostics-no-gallery-' . bin2hex( random_bytes( 12 ) ) ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function get_plugins() { return empty( $GLOBALS['scheduler_installed'] ) ? array() : array( 'action-scheduler/action-scheduler.php' => array() ); }
function is_plugin_active( $file ) { return ! empty( $GLOBALS['scheduler_active'] ); }
function current_user_can( $capability, ...$args ) { return ! empty( $GLOBALS['can_manage_plugins'] ); }
function self_admin_url( $path ) { return admin_url( $path ); }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=' . rawurlencode( $action ); }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function wp_convert_hr_to_bytes( $value ) {
	$number = (float) $value;
	$unit = strtolower( substr( trim( $value ), -1 ) );
	return (int) ( $number * ( 'g' === $unit ? 1073741824 : ( 'm' === $unit ? 1048576 : ( 'k' === $unit ? 1024 : 1 ) ) ) );
}
function size_format( $bytes ) { return ( $bytes / 1048576 ) . ' MB'; }
function get_option( $key, $default = '' ) { return $GLOBALS['schema_version'] ?? '6'; }
function wp_max_upload_size() { return $GLOBALS['upload_limit'] ?? 10485760; }
function get_temp_dir() { return sys_get_temp_dir(); }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
final class MRSA_File_Scan {
	public static function check_connection() {
		$GLOBALS['scan_checks'] = ( $GLOBALS['scan_checks'] ?? 0 ) + 1;
		return array( 'passed' => ! empty( $GLOBALS['scanner_ready'] ), 'message' => empty( $GLOBALS['scanner_ready'] ) ? 'ClamAV unavailable.' : 'Harmless scan passed.' );
	}
}
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-admin.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-import-audit.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-import-adapters.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-bonus.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-views.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-diagnostics.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-diagnostics-deletion-service.php';
function invoke_method( $class, $name, ...$args ) {
	$method = new ReflectionMethod( $class, $name );
	if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
	return $method->invoke( null, ...$args );
}
function get_user_option( $key, $user_id ) { return $GLOBALS['user_options'][ $user_id ][ $key ] ?? false; }
function update_user_option( $user_id, $key, $value ) {
	if ( ! empty( $GLOBALS['fail_user_option'] ) ) { return false; }
	$GLOBALS['user_options'][ $user_id ][ $key ] = $value;
	return true;
}
function check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . PHP_EOL;
}
$wpdb = new Diagnostics_Test_DB();
$GLOBALS['authorized'] = true;
ob_start();
invoke_method( 'MRSA_Views', 'diagnostics' );
$html = ob_get_clean();
if ( in_array( '--preview', $argv ?? array(), true ) ) {
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="admin.css"><title>Diagnostics preview</title></head><body><main class="mrsa">' . $html . '</main><script src="admin.js"></script></body></html>';
	exit;
}
check( strpos( $html, 'mrsa-destructive-grid' ) !== false && substr_count( $html, 'mrsa-destructive-action' ) === 6, 'six destructive actions share a compact grid' );
check( substr_count( $html, 'mrsa-diagnostics-section' ) === 10, 'configuration, exports, destructive group and individual actions can all collapse' );
check( strpos( $html, '<details class="mrsa-diagnostics-section"><summary><h3>Destructive operations</h3></summary>' ) !== false, 'Destructive operations defaults to collapsed' );
foreach ( array( 'Required Plugins and Configuration', 'Export Data', 'Database Backup', 'Destructive operations' ) as $heading ) {
	check( strpos( $html, '<summary><h3>' . $heading . '</h3>' ) !== false, 'native collapse control wraps ' . $heading );
}
check( substr_count( $html, 'mrsa-destructive-action mrsa-diagnostics-section" open><summary><h4>' ) === 6, 'all destructive cards retain initially visible controls with individual collapse buttons' );
check( strpos( $html, '<details class="mrsa-administrative-audit"><summary><h3>Administrative Audit</h3></summary>' ) !== false, 'Administrative Audit uses a native expand control and defaults to collapsed' );
$audit_start = strpos( $html, '<details class="mrsa-administrative-audit">' );
$audit_end = strpos( $html, '</details>', $audit_start );
check( strpos( substr( $html, $audit_start, $audit_end - $audit_start ), 'Date/time (UTC)' ) !== false && $audit_end < strpos( $html, '<h3>Destructive operations' ), 'collapsed audit contains the existing history table without hiding destructive actions' );
check( strpos( $html, 'Delete Virus Scan Audit Data' ) !== false && strpos( $html, 'reset remembered ClamAV overrides' ) !== false, 'audit deletion describes override reset and retained deletion record' );
check( substr_count( $html, 'name="verification_hash"' ) === 6 && substr_count( $html, 'mrsa-delete-verification' ) === 6, 'each action retains separate server verification and dialog content' );
check( substr_count( $html, 'name="_wpnonce"' ) === 9, 'configuration check, exports and destructive operations retain nonce fields' );
check( strpos( $html, 'id="mrsa-configuration"' ) < strpos( $html, '<h3>Database Backup' ), 'configuration section precedes export and backup controls' );
$operations = array( 'all', 'bikes', 'roster', 'bonus_points', 'rider', 'audit' );
foreach ( $operations as $suffix ) {
	$operation = 'diagnostics_delete_' . $suffix;
	$wpdb->tables = array_fill_keys( array( 'registrations', 'rider_bikes', 'event_admins', 'events', 'riders', 'bikes', 'global_admins', 'bonus_points' ), array( array( 'id' => 9, 'event_id' => 7, 'rider_id' => 9 ) ) );
	$wpdb->tables['import_audit'] = array(
		array( 'user_id' => 1, 'action' => 'bypass_enabled' ),
		array( 'user_id' => 2, 'action' => 'bypass_enabled' ),
	);
	$_POST = array( 'event_id' => '7', 'rider_id' => '9', 'verification_code' => 'AB12CD34', 'verification_hash' => MRSA_Admin::diagnostics_hash( $operation, 'AB12CD34' ) );
	invoke_method( 'MRSA_Admin', 'verify_diagnostics', $operation );
	$deletion = new MRSA_Diagnostics_Deletion_Service();
	$input = array( 'event_id' => '7', 'rider_id' => '9' );
	$before = $wpdb->tables;
	$wpdb->fail_audit = true;
	try {
		$deletion->delete( $operation, $input );
		throw new RuntimeException( 'Unaudited deletion unexpectedly succeeded.' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'audit' ) !== false && $before === $wpdb->tables, $suffix . ': audit failure rolls back all deletions and overrides' );
	}
	$wpdb->fail_audit = false;
	$deletion->delete( $operation, $input );
	$audit = end( $wpdb->tables['import_audit'] );
	check( $audit['action'] === $operation && $audit['username'] === 'admin-1' && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $audit['occurred_at'] ), $suffix . ': deletion records actor, operation and UTC timestamp' );
	check( $audit['event_id'] === ( in_array( $suffix, array( 'roster', 'bonus_points' ), true ) ? 7 : 0 ), $suffix . ': audit records event scope or site-wide scope' );
	if ( 'audit' === $suffix ) {
		check( count( $wpdb->tables['import_audit'] ) === 1 && ! MRSA_Bonus::bypass_enabled(), 'clearing audit leaves only deletion activity and resets current administrator override' );
		$GLOBALS['user_id'] = 2;
		check( ! MRSA_Bonus::bypass_enabled(), 'audit deletion resets other administrators overrides too' );
		$GLOBALS['user_id'] = 1;
	} else {
		check( count( $wpdb->tables['import_audit'] ) === 3 && MRSA_Bonus::bypass_enabled(), $suffix . ': ordinary deletions preserve previous audit and overrides' );
	}
	if ( 'rider' === $suffix ) {
		check( strpos( $audit['detail'], 'Rider ID: 9' ) !== false, 'rider deletion retains deleted rider ID in audit details' );
	}
	$_POST['verification_code'] = 'WRONG';
	try {
		invoke_method( 'MRSA_Admin', 'verify_diagnostics', $operation );
		throw new RuntimeException( 'Invalid verification unexpectedly accepted.' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'did not match' ) !== false, $suffix . ': mismatched verification rejected' );
	}
}
$GLOBALS['authorized'] = false;
try {
	invoke_method( 'MRSA_Admin', 'require_global' );
	throw new RuntimeException( 'Non-global administrator unexpectedly authorized.' );
} catch ( RuntimeException $exception ) {
	check( strpos( $exception->getMessage(), 'not authorized' ) !== false, 'Diagnostics mutation authorization rejects non-global administrators' );
}
