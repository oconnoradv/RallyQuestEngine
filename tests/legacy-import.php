<?php
/** Standalone legacy MRSA import regression tests: php tests/legacy-import.php */
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['test_options'] = array();
$GLOBALS['test_transients'] = array();
$GLOBALS['can_manage'] = true;
$GLOBALS['uploads'] = sys_get_temp_dir() . '/rqe-legacy-' . bin2hex( random_bytes( 4 ) );
mkdir( $GLOBALS['uploads'] . '/mrsa-event-gallery/7/submissions', 0777, true );
file_put_contents( $GLOBALS['uploads'] . '/mrsa-event-gallery/7/bonus.jpg', 'image' );
file_put_contents( $GLOBALS['uploads'] . '/mrsa-event-gallery/7/submissions/s.jpg', 'submission' );

function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo 'PASS: ' . $message . PHP_EOL;
}
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['test_options'] ) ? $GLOBALS['test_options'][ $name ] : $default; }
function update_option( $name, $value ) { $GLOBALS['test_options'][ $name ] = $value; return true; }
function current_user_can() { return $GLOBALS['can_manage']; }
function get_current_user_id() { return 1; }
function set_transient( $key, $value ) { $GLOBALS['test_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return isset( $GLOBALS['test_transients'][ $key ] ) ? $GLOBALS['test_transients'][ $key ] : false; }
function delete_transient( $key ) { unset( $GLOBALS['test_transients'][ $key ] ); return true; }
function add_action() {}
function wp_cache_delete( $id, $group ) {
	$GLOBALS['cache_deletions'][] = array( $id, $group );
	return true;
}
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['uploads'] ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function number_format_i18n( $number ) { return number_format( $number ); }

final class Legacy_Test_DB {
	public $prefix = 'wp_';
	public $usermeta = 'wp_usermeta';
	public $pdo;
	public $fail_table = '';
	public $last_error = '';
	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)' );
	}
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', $this->pdo->quote( $arg ), $sql, 1 );
		}
		return $sql;
	}
	public function esc_like( $text ) { return $text; }
	public function get_var( $sql ) {
		if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $sql, $match ) ) {
			$found = $this->pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote( $match[1] ) )->fetchColumn();
			return false === $found ? null : $found;
		}
		$value = $this->pdo->query( $sql )->fetchColumn();
		return false === $value ? null : $value;
	}
	public function get_col( $sql ) {
		if ( 0 === strpos( $sql, 'SELECT DISTINCT user_id' ) ) {
			return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN );
		}
		preg_match( '/^SHOW COLUMNS FROM `(.+)`$/', $sql, $match );
		return array_column( $this->pdo->query( 'PRAGMA table_info(`' . $match[1] . '`)' )->fetchAll( PDO::FETCH_ASSOC ), 'name' );
	}
	public function query( $sql ) {
		if ( '' !== $this->fail_table && false !== strpos( $sql, 'INSERT INTO `wp_RQE_' . $this->fail_table . '`' ) ) {
			return false;
		}
		return $this->pdo->exec( $sql );
	}
	public function rows( $table ) { return $this->pdo->query( "SELECT * FROM `$table` ORDER BY 1" )->fetchAll( PDO::FETCH_ASSOC ); }
}
final class RQE {
	public static function table( $name ) {
		if ( ! in_array( $name, array( 'event_admins', 'event_scorers', 'event_email_settings', 'status_options', 'bikes', 'horses', 'riders', 'rider_bikes', 'events', 'registrations', 'bonus_points', 'bonus_submissions', 'import_audit' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown RQE table.' );
		}
		return 'wp_RQE_' . $name;
	}
	public static function begin_transaction() { $GLOBALS['wpdb']->pdo->beginTransaction(); }
	public static function commit_transaction() { $GLOBALS['wpdb']->pdo->commit(); }
	public static function rollback_transaction() { $GLOBALS['wpdb']->pdo->rollBack(); return true; }
}
final class RQE_Statuses {
	public static function migrate_tiers() { $GLOBALS['tier_migrations']++; }
	public static function migrate_ordering() {}
	public static function migrate_finishers() {}
}
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-legacy-import.php';

function fresh_db() {
	global $wpdb;
	$wpdb = new Legacy_Test_DB();
	foreach ( array( 'event_admins', 'event_scorers', 'event_email_settings', 'status_options', 'bikes', 'horses', 'riders', 'rider_bikes', 'registrations', 'bonus_points', 'bonus_submissions', 'import_audit' ) as $name ) {
		$wpdb->pdo->exec( "CREATE TABLE wp_RQE_$name (id INTEGER, event_id INTEGER, label TEXT)" );
	}
	$wpdb->pdo->exec( "CREATE TABLE wp_RQE_events (id INTEGER, name TEXT, description TEXT NOT NULL DEFAULT '', hunt_style TEXT NOT NULL DEFAULT 'motorcycle')" );
	foreach ( array( 'event_admins', 'event_scorers', 'event_email_settings', 'status_options', 'bikes', 'riders', 'rider_bikes', 'registrations', 'bonus_points', 'bonus_submissions', 'import_audit' ) as $name ) {
		$wpdb->pdo->exec( "CREATE TABLE wp_MRSA_$name (id INTEGER, event_id INTEGER, label TEXT, retired_column TEXT)" );
		$wpdb->pdo->exec( "INSERT INTO wp_MRSA_$name VALUES (1, 7, '$name', 'x')" );
	}
	$wpdb->pdo->exec( 'CREATE TABLE wp_MRSA_events (id INTEGER, name TEXT)' );
	$wpdb->pdo->exec( "INSERT INTO wp_MRSA_events VALUES (7, 'Spring Rally')" );
	$wpdb->pdo->exec( "INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_mrsa_selected_event', '7')" );
	$GLOBALS['test_options'] = array( 'mrsa_db_version' => '11', 'mrsa_delete_on_uninstall' => 1 );
	$GLOBALS['test_transients'] = array();
	$GLOBALS['cache_deletions'] = array();
	$GLOBALS['tier_migrations'] = 0;
}

fresh_db();
$GLOBALS['can_manage'] = false;
RQE_Legacy_Import::maybe_run();
check( false === get_option( RQE_Legacy_Import::OPTION ) && array() === $wpdb->rows( 'wp_RQE_riders' ), 'users without manage_options never trigger the import' );
$GLOBALS['can_manage'] = true;

$GLOBALS['test_options']['mrsa_db_version'] = '10';
RQE_Legacy_Import::maybe_run();
check( false === get_option( RQE_Legacy_Import::OPTION ) && false !== strpos( get_transient( 'rqe_legacy_import_error_1' ), 'version 0.1.20' ), 'outdated legacy schema asks for a v0.1.20 upgrade instead of re-running migrations' );
ob_start();
RQE_Legacy_Import::notices();
check( false !== strpos( ob_get_clean(), 'legacy import failed' ) && false === get_transient( 'rqe_legacy_import_error_1' ), 'failure notice is shown once' );

fresh_db();
$wpdb->fail_table = 'registrations';
RQE_Legacy_Import::maybe_run();
check( false === get_option( RQE_Legacy_Import::OPTION ) && array() === $wpdb->rows( 'wp_RQE_riders' ) && array() === $wpdb->rows( 'wp_RQE_events' ) && ! is_dir( $GLOBALS['uploads'] . '/rqe-event-gallery' ), 'failed table copy rolls back rows and the copied gallery so the import can retry' );
check( array() === $GLOBALS['cache_deletions'], 'rolled-back imports do not invalidate user metadata caches' );
$wpdb->fail_table = '';
ob_start();
RQE_Legacy_Import::notices();
ob_end_clean();

RQE_Legacy_Import::maybe_run();
$result = get_option( RQE_Legacy_Import::OPTION );
$events = $wpdb->rows( 'wp_RQE_events' );
$riders = $wpdb->rows( 'wp_RQE_riders' );
check( 'imported' === $result['status'] && 12 === $result['rows'] && 'copied' === $result['gallery'], 'legacy rows and gallery import in one pass' );
check( array( array( 'id' => 7, 'name' => 'Spring Rally', 'description' => '', 'hunt_style' => 'motorcycle' ) ) == $events, 'imported events keep IDs and presume the Motorcycle hunt style' );
check( array( array( 'id' => 1, 'event_id' => 7, 'label' => 'riders' ) ) == $riders, 'only intersecting columns are copied' );
check( 'submission' === file_get_contents( $GLOBALS['uploads'] . '/rqe-event-gallery/7/submissions/s.jpg' ) && is_file( $GLOBALS['uploads'] . '/mrsa-event-gallery/7/bonus.jpg' ), 'gallery copy is complete and the legacy gallery is preserved' );
check( '7' === $wpdb->get_var( "SELECT meta_value FROM wp_usermeta WHERE meta_key = 'wp_rqe_selected_event'" ) && 1 === get_option( 'rqe_delete_on_uninstall' ), 'selected event and uninstall preference carry over' );
check( array( array( 1, 'user_meta' ) ) === $GLOBALS['cache_deletions'], 'committed legacy selections invalidate the affected WordPress user metadata cache' );
check( 1 === $GLOBALS['tier_migrations'], 'legacy imports seed new tier statuses after copying historical catalogs' );
check( 1 === count( $wpdb->rows( 'wp_MRSA_riders' ) ), 'legacy tables are left intact' );
ob_start();
RQE_Legacy_Import::notices();
$notice = ob_get_clean();
check( false !== strpos( $notice, 'Imported 12 records' ) && false !== strpos( $notice, 'rqe_results' ), 'success notice explains the shortcode change' );
ob_start();
RQE_Legacy_Import::notices();
check( '' === ob_get_clean(), 'success notice is shown only once' );
RQE_Legacy_Import::maybe_run();
check( 1 === count( $wpdb->rows( 'wp_RQE_riders' ) ), 'completed import never runs twice' );

fresh_db();
$wpdb->pdo->exec( "INSERT INTO wp_RQE_riders VALUES (5, 0, 'existing')" );
RQE_Legacy_Import::maybe_run();
check( 'skipped' === get_option( RQE_Legacy_Import::OPTION )['status'] && 1 === count( $wpdb->rows( 'wp_RQE_riders' ) ), 'existing Rally Quest Engine data is never overwritten' );

fresh_db();
$wpdb->pdo->exec( 'DROP TABLE wp_MRSA_events' );
RQE_Legacy_Import::maybe_run();
check( 'none' === get_option( RQE_Legacy_Import::OPTION )['status'], 'sites without legacy data record a silent no-op' );

define( 'MRSA_VERSION', '0.1.20' );
fresh_db();
RQE_Legacy_Import::maybe_run();
ob_start();
RQE_Legacy_Import::notices();
check( false === get_option( RQE_Legacy_Import::OPTION ) && false !== strpos( ob_get_clean(), 'Deactivate Motorcycle Rally Scoring Application' ), 'active legacy plugin blocks import and asks for deactivation' );

$cleanup = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['uploads'], FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $cleanup as $item ) {
	$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
}
rmdir( $GLOBALS['uploads'] );
