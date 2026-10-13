<?php
/** SQL-backed status, context, result and view regressions: php tests/event-administration.php */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'RQE_VERSION', 'test' );
define( 'RQE_FILE', __DIR__ . '/../rally-quest-engine/rally-quest-engine.php' );

final class Event_Test_DB {
	public $prefix = '';
	public $last_error = '';
	public $insert_id = 0;
	public $fail_write = false;
	public $fail_read = false;
	public $sql = array();
	private $pdo;

	public function __construct() { $this->pdo = new PDO( 'sqlite::memory:' ); }
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	private function run( $query ) {
		$sql = is_array( $query ) ? $query[0] : $query;
		$args = is_array( $query ) ? $query[1] : array();
		$this->sql[] = $sql;
		$this->last_error = '';
		try {
			if ( $this->fail_read && 0 === strpos( $sql, 'SELECT' ) ) {
				throw new RuntimeException( 'Injected read failure' );
			}
			if ( $this->fail_write && preg_match( '/^(INSERT|UPDATE|DELETE)/', $sql ) ) {
				throw new RuntimeException( 'Injected write failure' );
			}
			$sql = str_replace( array( ' FOR UPDATE', 'START TRANSACTION' ), array( '', 'BEGIN TRANSACTION' ), $sql );
			$sql = preg_replace( '/%[dsf]/', '?', $sql );
			$statement = $this->pdo->prepare( $sql );
			$statement->execute( $args );
			$this->insert_id = (int) $this->pdo->lastInsertId();
			return $statement;
		} catch ( Exception $exception ) {
			$this->last_error = $exception->getMessage();
			return false;
		}
	}
	public function query( $sql ) { $statement = $this->run( $sql ); return $statement ? $statement->rowCount() : false; }
	public function get_results( $sql, $format ) { $statement = $this->run( $sql ); return $statement ? $statement->fetchAll( PDO::FETCH_ASSOC ) : null; }
	public function get_row( $sql, $format ) { $rows = $this->get_results( $sql, $format ); return $rows ? $rows[0] : null; }
	public function get_col( $sql ) { $statement = $this->run( $sql ); return $statement ? $statement->fetchAll( PDO::FETCH_COLUMN ) : null; }
	public function get_var( $sql ) { $statement = $this->run( $sql ); $value = $statement ? $statement->fetchColumn() : false; return false === $value ? null : $value; }
	public function insert( $table, $data ) {
		$sql = 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '%s' ) ) . ')';
		return $this->query( $this->prepare( $sql, ...array_values( $data ) ) );
	}
	private function conditions( $data ) { return implode( ' AND ', array_map( function ( $key ) { return $key . '=%s'; }, array_keys( $data ) ) ); }
	public function update( $table, $data, $where ) {
		$set = implode( ',', array_map( function ( $key ) { return $key . '=%s'; }, array_keys( $data ) ) );
		return $this->query( $this->prepare( 'UPDATE ' . $table . ' SET ' . $set . ' WHERE ' . $this->conditions( $where ), ...array_merge( array_values( $data ), array_values( $where ) ) ) );
	}
	public function delete( $table, $where ) { return $this->query( $this->prepare( 'DELETE FROM ' . $table . ' WHERE ' . $this->conditions( $where ), ...array_values( $where ) ) ); }
}

$GLOBALS['actor'] = 1;
$GLOBALS['site'] = 1;
$GLOBALS['preferences'] = array();
function is_user_logged_in() { return $GLOBALS['actor'] > 0; }
function current_user_can( $capability ) { return 1 === $GLOBALS['actor']; }
function get_current_user_id() { return $GLOBALS['actor']; }
function wp_get_current_user() { return (object) array( 'ID' => get_current_user_id(), 'user_login' => 'fixture-admin' ); }
function get_user_option( $key, $id ) {
	if ( isset( $GLOBALS['preference_cache'][ $GLOBALS['site'] ][ $id ][ $key ] ) ) {
		return $GLOBALS['preference_cache'][ $GLOBALS['site'] ][ $id ][ $key ];
	}
	return $GLOBALS['preferences'][ $GLOBALS['site'] ][ $id ][ $key ] ?? 0;
}
function wp_cache_delete( $id, $group ) {
	if ( 'user_meta' !== $group ) { throw new LogicException( 'Expected user metadata cache invalidation.' ); }
	unset( $GLOBALS['preference_cache'][ $GLOBALS['site'] ][ $id ] );
	return true;
}
function update_user_option( $id, $key, $value, $global = false ) {
	if ( $global ) { throw new LogicException( 'Event preference must be site-scoped.' ); }
	if ( ! empty( $GLOBALS['fail_preference'] ) ) { return false; }
	if ( (string) ( $GLOBALS['preferences'][ $GLOBALS['site'] ][ $id ][ $key ] ?? '' ) === (string) $value ) { return false; }
	$GLOBALS['preferences'][ $GLOBALS['site'] ][ $id ][ $key ] = $value;
	return true;
}
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_email( $value ) { return strtolower( $value ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
if ( ! function_exists( '__' ) ) {
	function __( $value, $domain = 'default' ) { return $value; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $value, $domain = 'default' ) { return esc_html( __( $value, $domain ) ); }
}
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function get_permalink() { return 'https://example.test/results/'; }
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() { return array( 'basedir' => $GLOBALS['proof_uploads'] ?? __DIR__ . '/fixtures/public-proof-unused', 'baseurl' => 'https://example.test/uploads', 'error' => false ); }
}
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=fixture'; }
function selected( $value, $current, $echo = true ) { $html = (string) $value === (string) $current ? ' selected="selected"' : ''; if ( $echo ) { echo $html; } return $html; }
function wp_list_pluck( $rows, $key ) { return array_column( $rows, $key ); }
function wp_timezone_string() { return 'UTC'; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function get_post_type( $id ) { return ''; }
function checked( $a, $b = true, $echo = true ) { return (string) $a === (string) $b ? ' checked' : ''; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['test_options'][ $key ] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['test_options'] ) ) { return false; }
	$GLOBALS['test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) { unset( $GLOBALS['test_options'][ $key ] ); return true; }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wpautop( $value ) { return '<p>' . $value . '</p>'; }
function wp_attachment_is_image( $id ) { return 55 === (int) $id; }
function wp_get_attachment_image( $id, $size = 'thumbnail', $icon = false, $attr = array() ) { return '<img src="fixture-' . (int) $id . '.jpg" alt="' . esc_attr( $attr['alt'] ?? '' ) . '">'; }
function add_shortcode( $tag, $callback ) { $GLOBALS['shortcodes'][ $tag ] = $callback; }
function add_action( $hook, $callback ) { $GLOBALS['hooks'][ $hook ] = $callback; }
function wp_enqueue_style( $handle, $url, $dependencies, $version ) { $GLOBALS['styles'][ $handle ] = $url; }
function plugins_url( $path, $file ) { return 'https://example.test/plugins/rqe/' . $path; }
function shortcode_atts( $defaults, $attributes, $tag = '' ) {
	return array_merge( $defaults, array_intersect_key( (array) $attributes, $defaults ) );
}
function get_users( $args ) { return array(); }
function get_user_by( $key, $id ) { return (object) array( 'ID' => $id, 'user_login' => 'user-' . $id, 'user_email' => 'user@example.test', 'display_name' => 'User ' . $id ); }
function get_user_meta( $id, $key, $single ) { return ''; }
function wp_salt( $scheme ) { return 'fixture-salt'; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( ( $args['response'] ?? 500 ) . ': ' . $message ); }
require __DIR__ . '/../rally-quest-engine/includes/class-rqe.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-event-state.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-certificates.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-import-audit.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-admin.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-statuses.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-email-submissions.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-bonus.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-roster-import-service.php';
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['test_options'][ $key ] ?? $default;
	}
}
require_once __DIR__ . '/../rally-quest-engine/includes/class-rqe-styles.php';
require_once __DIR__ . '/../rally-quest-engine/includes/class-rqe-horses.php';
require_once __DIR__ . '/../rally-quest-engine/includes/class-rqe-diagnostics.php';
require_once __DIR__ . '/../rally-quest-engine/includes/class-rqe-setup.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-views.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-public-results.php';

function check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } echo 'PASS: ' . $label . PHP_EOL; }
function rejects( $run, $message ) {
	try { $run(); } catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), $message ) !== false, 'rejects ' . $message );
		return;
	}
	rejects( function () { RQE_Admin::integer( '<script>alert(1)</script>', 1 ); }, 'Invalid integer' );
	throw new LogicException( 'Expected rejection: ' . $message );
}
function request( $query = array() ) {
	$_GET = $query;
	$property = new ReflectionProperty( 'RQE_Admin', 'selected_event' );
	if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
	$property->setValue( null, null );
}
function render( $method, ...$args ) {
	$reflection = new ReflectionMethod( 'RQE_Views', $method );
	if ( PHP_VERSION_ID < 80100 ) { $reflection->setAccessible( true ); }
	ob_start();
	try { $reflection->invoke( null, ...$args ); return ob_get_contents(); } finally { ob_end_clean(); }
}

$wpdb = new Event_Test_DB();
foreach ( array(
	'events' => "id INTEGER PRIMARY KEY,name TEXT,description TEXT DEFAULT '',hunt_style TEXT DEFAULT 'motorcycle',certificate_design TEXT NOT NULL DEFAULT 'vintage',event_date TEXT,max_bib INTEGER,min_finisher_points INTEGER,bronze_finisher_points INTEGER DEFAULT NULL,silver_finisher_points INTEGER DEFAULT NULL,gold_finisher_points INTEGER DEFAULT NULL,is_locked INTEGER DEFAULT 0,is_archived INTEGER DEFAULT 0,logo_id INTEGER DEFAULT 0,public_results_enabled INTEGER NOT NULL DEFAULT 0,public_bonus_details_enabled INTEGER NOT NULL DEFAULT 0,registration_open TEXT,registration_close TEXT,start_at TEXT,end_at TEXT,rider_price TEXT,pillion_price TEXT,location TEXT,latitude TEXT,longitude TEXT",
	'horses' => "id INTEGER PRIMARY KEY,rider_id INTEGER DEFAULT 0,name TEXT,breed TEXT DEFAULT '',sex TEXT DEFAULT '',age INTEGER,color TEXT DEFAULT ''",
	'riders' => "id INTEGER PRIMARY KEY,name TEXT,email TEXT,alias TEXT DEFAULT '',phone TEXT DEFAULT ''",
	'registrations' => "id INTEGER PRIMARY KEY,event_id INTEGER,rider_id INTEGER,bib INTEGER,pillion TEXT DEFAULT '',registration_status TEXT DEFAULT 'Initial Registration',status TEXT DEFAULT 'Registered',status_manual INTEGER DEFAULT 0,score INTEGER DEFAULT 0,placing INTEGER",
	'status_options' => 'id INTEGER PRIMARY KEY AUTOINCREMENT,event_id INTEGER,kind TEXT,label TEXT COLLATE NOCASE,enabled INTEGER,sort_order INTEGER DEFAULT 0,certificate_eligible INTEGER DEFAULT 0,UNIQUE(event_id,kind,label)',
	'certificate_mail' => "id INTEGER PRIMARY KEY AUTOINCREMENT,batch TEXT,event_id INTEGER,rider_id INTEGER,user_id INTEGER,snapshot TEXT,fingerprint TEXT,subject TEXT,message TEXT,state TEXT,detail TEXT,created_at TEXT,updated_at TEXT,UNIQUE(batch,rider_id)",
	'import_audit' => 'id INTEGER PRIMARY KEY AUTOINCREMENT,event_id INTEGER,user_id INTEGER,username TEXT,occurred_at TEXT,action TEXT,detail TEXT',
	'event_admins' => 'event_id INTEGER,user_id INTEGER',
	'event_scorers' => 'event_id INTEGER,user_id INTEGER',
	'event_email_settings' => "event_id INTEGER PRIMARY KEY,provider TEXT,enabled INTEGER,encrypted_settings TEXT,last_checked_at TEXT,last_error TEXT DEFAULT '',last_tested_at TEXT,last_test_error TEXT DEFAULT ''",
	'bonus_points' => "event_id INTEGER,bonus_id TEXT,description TEXT DEFAULT '',image_file TEXT DEFAULT ''",
	'bonus_submissions' => "id INTEGER PRIMARY KEY,event_id INTEGER,rider_id INTEGER,bib INTEGER,registration_id INTEGER,bonus_id TEXT,status TEXT,points_awarded INTEGER DEFAULT 0,submitted_at TEXT DEFAULT '',reviewed_at TEXT,reviewed_by INTEGER DEFAULT 0,rejection_description TEXT DEFAULT '',rejection_code TEXT DEFAULT '',image_file TEXT DEFAULT '',submission_description TEXT DEFAULT ''",
) as $table => $schema ) {
	RQE_Admin::checked( $wpdb->query( 'CREATE TABLE ' . RQE::table( $table ) . ' (' . $schema . ')' ) );
}
foreach ( array( 7, 8 ) as $id ) {
	$wpdb->insert( RQE::table( 'events' ), array( 'id' => $id, 'name' => 'Event ' . $id, 'hunt_style' => 7 === $id ? 'motorcycle' : 'horse', 'event_date' => '2026-10-10', 'max_bib' => 100, 'min_finisher_points' => 100 ) );
}
$wpdb->insert( RQE::table( 'riders' ), array( 'id' => 10, 'name' => 'Rider One', 'email' => 'one@example.test' ) );
$wpdb->insert( RQE::table( 'riders' ), array( 'id' => 11, 'name' => 'Rider Two', 'email' => 'two@example.test' ) );
$wpdb->insert( RQE::table( 'registrations' ), array( 'id' => 1, 'event_id' => 7, 'rider_id' => 10, 'bib' => 42, 'registration_status' => 'Legacy registration', 'status' => 'Legacy event', 'status_manual' => 1, 'score' => 101 ) );
$wpdb->insert( RQE::table( 'registrations' ), array( 'id' => 2, 'event_id' => 7, 'rider_id' => 11, 'bib' => 43, 'score' => 101 ) );
$wpdb->insert( RQE::table( 'registrations' ), array( 'id' => 3, 'event_id' => 8, 'rider_id' => 10, 'bib' => 44, 'score' => 0 ) );
RQE_Statuses::migrate();
$before = RQE::row( 'registrations', 1 );
check( in_array( 'Legacy registration', array_column( RQE_Statuses::options( 7, 'registration' ), 'label' ), true ) && in_array( 'Legacy event', array_column( RQE_Statuses::options( 7, 'event' ), 'label' ), true ), 'migration preserves historical status labels in separate catalogs' );
RQE_Statuses::change_option( 7, 'registration', 'disable', 'Paid' );
$count = count( RQE_Statuses::options( 7, 'registration' ) );
RQE_Statuses::migrate();
check( count( RQE_Statuses::options( 7, 'registration' ) ) === $count && ! in_array( 'Paid', RQE_Statuses::enabled_labels( RQE_Statuses::options( 7, 'registration' ) ), true ) && RQE::row( 'registrations', 1 ) === $before, 'repeat migration preserves disabled choices, scores and manual assignments' );
RQE_Statuses::change_option( 7, 'registration', 'add', 'Checked In' );
RQE_Statuses::assign( 7, 1, 'registration', 'Checked In' );
check( RQE::row( 'registrations', 1 )['status'] === 'Legacy event', 'Registration Status assignment does not change Event Status' );
RQE_Statuses::assign( 7, 1, 'event', 'Finisher' );
check( RQE::row( 'registrations', 1 )['registration_status'] === 'Checked In', 'Event Status assignment does not change Registration Status' );
RQE_Statuses::change_option( 7, 'registration', 'disable', 'Checked In' );
RQE_Statuses::assign( 7, 1, 'registration', 'Checked In' );
rejects( function () { RQE_Statuses::assign( 7, 2, 'registration', 'Checked In' ); }, 'enabled status' );
rejects( function () { RQE_Statuses::change_option( 7, 'registration', 'delete', 'Checked In' ); }, 'in use' );
check( RQE::row( 'registrations', 1 )['registration_status'] === 'Checked In', 'disabled in-use value is retained and cannot be deleted' );
RQE_Statuses::change_option( 7, 'registration', 'enable', 'Checked In' );
RQE_Statuses::assign( 7, 1, 'registration', 'Initial Registration' );
RQE_Statuses::change_option( 7, 'registration', 'delete', 'Checked In' );
rejects( function () { RQE_Statuses::change_option( 7, 'event', 'add', 'finisher' ); }, 'already exists' );
rejects( function () { RQE_Statuses::change_option( 7, 'event', 'add', 'Automatic' ); }, 'calculation mode' );
rejects( function () { RQE_Statuses::assign( 8, 1, 'event', 'DNS' ); }, 'Registration not found' );
rejects( function () { RQE_Statuses::change_option( 7, 'event', 'delete', 'Preliminary Finisher' ); }, 'in use' );
rejects( function () { RQE_Statuses::change_option( 8, 'event', 'delete', 'Preliminary Finisher' ); }, 'cannot be deleted' );
RQE_Statuses::change_option( 7, 'registration', 'disable', 'Initial Registration' );
check( RQE_Statuses::initial_registration( 7 ) === RQE_Statuses::enabled_labels( RQE_Statuses::options( 7, 'registration' ) )[0], 'disabled default falls back to the first enabled ordered Registration Status' );
RQE_Statuses::change_option( 7, 'registration', 'disable', 'Fully Registered' );
RQE_Statuses::change_option( 7, 'registration', 'disable', 'Cancelled' );
rejects( function () { RQE_Statuses::change_option( 7, 'registration', 'disable', 'Legacy registration' ); }, 'at least one' );
$wpdb->fail_write = true;
rejects( function () { RQE_Statuses::assign( 7, 1, 'event', 'DNS' ); }, 'could not be saved' );
$wpdb->fail_write = false;
check( RQE::row( 'registrations', 1 )['status'] === 'Finisher', 'failed write rolls back status assignment' );
RQE_Statuses::assign( 7, 1, 'event', 'Automatic' );
$queries = new RQE_Queries( $wpdb );
foreach ( array( 99 => 'Registered', 100 => 'Preliminary Finisher', 101 => 'Preliminary Finisher' ) as $score => $status ) {
	$wpdb->update( RQE::table( 'registrations' ), array( 'score' => $score ), array( 'id' => 1 ) );
	check( $queries->rider_score( 7, 10 )['effective_status'] === $status, 'automatic threshold at score ' . $score );
}
foreach ( array( 'Finisher', 'DNF', 'DNS' ) as $status ) {
	RQE_Statuses::assign( 7, 1, 'event', $status );
	$wpdb->update( RQE::table( 'registrations' ), array( 'score' => 1000 ), array( 'id' => 1 ) );
	check( $queries->rider_score( 7, 10 )['effective_status'] === $status, 'manual ' . $status . ' survives subsequent score changes' );
}
$wpdb->update( RQE::table( 'registrations' ), array( 'score' => 101 ), array( 'id' => 1 ) );
foreach ( array( array( 7, 10, 'accepted' ), array( 7, 10, 'rejected' ), array( 7, 10, 'rejected' ), array( 7, 10, 'pending' ), array( 7, 10, 'invalid' ), array( 8, 10, 'accepted' ) ) as $index => $submission ) {
	$wpdb->insert( RQE::table( 'bonus_submissions' ), array( 'id' => $index + 1, 'event_id' => $submission[0], 'rider_id' => $submission[1], 'bib' => 42, 'registration_id' => 1, 'bonus_id' => 'B1', 'status' => $submission[2], 'points_awarded' => 'accepted' === $submission[2] ? 125 : 0 ) );
}
$results = $queries->results( 7, 100 );
check( (int) $results[0]['accepted_bonuses'] === 1 && (int) $results[0]['rejected_bonuses'] === 2 && (int) $results[1]['accepted_bonuses'] === 0 && (int) $results[1]['rejected_bonuses'] === 0, 'real SQL counts decisions rather than points, excludes pending/invalid and isolates events/riders' );
check( array_column( $results, 'overall_placing' ) === array( 1, 1 ), 'submission aggregation preserves scores and competition ties' );
RQE_Statuses::change_option( 8, 'registration', 'add', 'Verified' );
RQE_Statuses::change_option( 8, 'registration', 'disable', 'Initial Registration' );
RQE_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Registration Status' ), array( array( '11', '' ) ) );
$imported = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d AND rider_id=%d', 8, 11 ), ARRAY_A );
check( $imported['registration_status'] === 'Paid' && (int) $imported['score'] === 0 && (int) $imported['status_manual'] === 0, 'new imports use enabled defaults without importing score or Event Status' );
$wpdb->insert( RQE::table( 'riders' ), array( 'id' => 12, 'name' => 'Rider Three', 'email' => 'three@example.test' ) );
RQE_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Status' ), array( array( '12', 'Verified' ) ) );
check( RQE_Statuses::usage( 8, 'registration', 'Verified' ) === 1, 'legacy Status import heading accepts enabled event-local custom values' );
RQE_Statuses::change_option( 8, 'registration', 'disable', 'Verified' );
$wpdb->insert( RQE::table( 'riders' ), array( 'id' => 13, 'name' => 'Rider Four', 'email' => 'four@example.test' ) );
rejects( function () { RQE_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Registration Status' ), array( array( '13', 'Verified' ) ) ); }, 'enabled Registration Status' );
check( null === $queries->rider_score( 8, 13 ), 'disabled custom-status import rolls back without enrolling a rider' );
rejects( function () { RQE_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Status', 'Registration Status' ), array( array( '13', 'Paid', 'Paid' ) ) ); }, 'duplicate headers' );
$wpdb->insert( RQE::table( 'bonus_submissions' ), array( 'id' => 7, 'event_id' => 7, 'rider_id' => 0, 'bib' => 0, 'registration_id' => 0, 'bonus_id' => '', 'status' => 'invalid' ) );
$wpdb->insert( RQE::table( 'event_admins' ), array( 'event_id' => 7, 'user_id' => 2 ) );
$wpdb->insert( RQE::table( 'event_scorers' ), array( 'event_id' => 8, 'user_id' => 3 ) );
$GLOBALS['actor'] = 2;
RQE_Statuses::assign( 7, 1, 'event', 'Finisher' );
rejects( function () { RQE_Statuses::assign( 8, 3, 'event', 'DNS' ); }, 'not assigned' );
$GLOBALS['actor'] = 3;
rejects( function () { RQE_Statuses::assign( 8, 3, 'event', 'DNS' ); }, 'not assigned' );
rejects( function () { RQE_Statuses::change_option( 8, 'event', 'disable', 'Finisher' ); }, 'not assigned' );
$GLOBALS['actor'] = 1;
request( array( 'event_id' => '7' ) );
check( RQE_Admin::selected_event() === 7, 'explicit event links set header context' );
request( array( 'tab' => 'results' ) );
check( RQE_Admin::selected_event() === 7, 'header event survives navigation without event parameters' );
$GLOBALS['preferences'][1][1]['rqe_selected_event'] = '8';
$GLOBALS['preference_cache'][1][1]['rqe_selected_event'] = 7;
request( array( 'event_id' => '8' ) );
check( RQE_Admin::selected_event() === 8 && get_user_option( 'rqe_selected_event', 1 ) === '8', 'migrated selection survives a stale user cache and an unchanged WordPress update' );
request( array( 'event_id' => '7' ) );
check( RQE_Admin::selected_event() === 7, 'a migrated selection can still be changed to a different event' );
$GLOBALS['site'] = 2;
request();
check( RQE_Admin::selected_event() === 0, 'header selection is independent between sites' );
$GLOBALS['site'] = 1;
$GLOBALS['actor'] = 2;
request();
check( RQE_Admin::selected_event() === 0, 'header selection is independent between users' );
request( array( 'event_id' => '8' ) );
rejects( function () { RQE_Admin::selected_event(); }, '403:' );
$GLOBALS['actor'] = 1;
$GLOBALS['fail_preference'] = true;
request( array( 'event_id' => '8' ) );
rejects( function () { RQE_Admin::selected_event(); }, 'could not be remembered' );
$GLOBALS['fail_preference'] = false;
update_user_option( 1, 'rqe_selected_event', 999 );
request();
ob_start();
$selected = RQE_Admin::selected_event();
$notice = ob_get_clean();
check( 0 === $selected && strpos( $notice, 'no longer accessible' ) !== false, 'stale selection requires explicit reselection and shows a warning' );
request( array( 'page' => 'rqe', 'tab' => 'results', 'event_id' => '7' ) );
$html = render( 'overall_results' );
check( strpos( $html, 'Accepted Bonuses' ) !== false && strpos( $html, 'Rejected Bonuses' ) !== false && strpos( $html, 'Event Status' ) !== false && strpos( $html, 'name="event_id" value="7"' ) !== false && strpos( $html, 'Choose event</button>' ) === false, 'Results includes decision counts, editable Event Status and no local event selector' );
$results_html = '<div class="wrap rqe">' . render( 'header' ) . $html . '</div>';
$html = render( 'registrations', RQE::row( 'events', 7 ) );
check( strpos( $html, 'Registration Status' ) !== false && strpos( $html, 'name="op" value="registration_lifecycle"' ) !== false && strpos( $html, 'disabled; retained' ) !== false, 'Rosters edits registration status while retaining assigned disabled values' );
$html = render( 'reviewed_submissions', RQE::row( 'events', 7 ), 0 );
check( strpos( $html, 'page=rqe-rider-score&amp;event_id=7&amp;rider_id=10&amp;from=reviewed' ) !== false, 'Reviewed Bib links use score-sheet event, rider and return context' );
check( strpos( $html, '<td>—</td><td>Unmatched message</td>' ) !== false && strpos( $html, 'rider_id=0&amp;from=reviewed' ) === false, 'unmatched reviewed messages have no broken score-sheet hyperlink' );
request( array( 'page' => 'rqe', 'tab' => 'riders' ) );
$riders_html = '<div class="wrap rqe">' . render( 'header' ) . render( 'rider_setup' ) . '</div>';
check( strpos( $riders_html, 'Choose Event' ) !== false && strpos( $riders_html, 'value="7" data-style="motorcycle" selected="selected"' ) !== false && strpos( $riders_html, 'data-style="horse">Event 8 (HB-8)' ) !== false && strpos( $riders_html, 'id="rqe-header-style"' ) !== false, 'header reflects the remembered authorized event' );
check( false !== strpos( $riders_html, '>Add Participant</a>' ) && strpos( $riders_html, '>Add Participant</a>' ) < strpos( $riders_html, 'Search all records' ) && substr_count( $riders_html, 'Search all records' ) === 1 && strpos( $riders_html, 'rqe-riders-toolbar' ) !== false, 'Participants toolbar places a single server search after Add Participant' );
$GLOBALS['actor'] = 3;
request( array( 'page' => 'rqe-rider-score', 'event_id' => '8', 'rider_id' => '10', 'from' => 'reviewed' ) );
$html = render( 'render_rider_score' );
check( strpos( $html, 'Event Status:' ) !== false && strpos( $html, 'score_tab=reviewed' ) !== false && strpos( $html, 'name="op" value="registration_status"' ) === false, 'assigned scorers can read score sheets and return to Reviewed but cannot edit statuses' );
request( array( 'event_id' => '7', 'rider_id' => '10' ) );
rejects( function () { render( 'render_rider_score' ); }, '403:' );
$GLOBALS['actor'] = 1;
request( array( 'page' => 'rqe', 'tab' => 'scoring', 'event_id' => '7', 'score_tab' => 'contested' ) );
$html = render( 'scoring', RQE::row( 'events', 7 ) );
check( strpos( $html, 'Submissions - Contested' ) !== false && strpos( $html, 'No contested-submission workflow is configured.' ) !== false, 'Contested tab and placeholder content remain unchanged' );
request( array( 'page' => 'rqe-event-edit', 'event_id' => '7' ) );
RQE_Email_Submissions::save_configuration( 7, array( 'provider' => 'imap', 'enabled' => '1', 'mailbox' => 'inbox@example.test', 'host' => 'imap.example.test', 'port' => '993', 'encryption' => 'ssl', 'username' => 'inbox@example.test', 'folder' => 'INBOX', 'password' => 'fixture-password' ) );
$html = render( 'render_event_edit' );
check( strpos( $html, 'rqe-event-columns' ) !== false && strpos( $html, 'rqe-email-panel" open' ) !== false && strpos( $html, 'Configuration saved. Test the event override' ) !== false && strpos( $html, 'Global inbox (read-only)' ) !== false, 'event editor shows a read-only global summary and an unverified event override remains open' );
check( strpos( $html, 'Event Details:' ) < strpos( $html, 'Event Administrator Assignments' ) && strpos( $html, 'Event Administrator Assignments' ) < strpos( $html, 'Event Inbox Override' ), 'administrator and scorer assignments are placed below Event Details in the left column' );
check( strpos( $html, 'Email Setup' ) < strpos( $html, 'Event Status</h2>' ) && strpos( $html, 'Event Status</h2>' ) < strpos( $html, 'Registration Status</h2>' ), 'Event Status and Registration Status customization follow Email Setup in order' );
check( strpos( $html, 'name="status_kind" value="registration"' ) !== false && strpos( $html, 'name="status_kind" value="event"' ) !== false, 'event page exposes separate status catalogs' );
$wpdb->update( RQE::table( 'event_email_settings' ), array( 'last_test_error' => 'Fixture connection error' ), array( 'event_id' => 7 ) );
$failed_html = render( 'render_event_edit' );
check( strpos( $failed_html, 'rqe-email-connection-failed" open' ) !== false && strpos( $failed_html, 'Fixture connection error' ) !== false, 'failed inbox test remains expanded and prominent' );
check( strpos( $html, '<summary><h2>Embed this Event on a Post or Page</h2></summary>' ) !== false && strpos( $html, '[rqe_results id=&quot;MC-7&quot;]' ) !== false && strpos( $html, 'name="rqe_embed_event_id" value="MC-7" readonly="readonly"' ) !== false, 'event edit provides a collapsed embed section, explicit read-only event ID and copyable shortcode' );
check( strpos( $html, 'name="op" value="public_results"' ) !== false && strpos( $html, 'name="public_results_enabled" value="1" checked' ) === false && strpos( $html, 'purge WordPress/CDN' ) !== false, 'public results control is off by default with cache-purge guidance' );

RQE_Statuses::change_option( 7, 'registration', 'enable', 'Cancelled' );
RQE_Statuses::change_option( 7, 'registration', 'enable', 'Initial Registration' );
RQE_Statuses::change_option( 7, 'event', 'disable', 'DNS' );
RQE_Statuses::assign( 7, 1, 'registration', 'Initial Registration' );
RQE_Statuses::assign( 7, 1, 'event', 'Finisher' );
$cancel_before = RQE::row( 'registrations', 1 );
RQE_Statuses::assign( 7, 1, 'registration', 'Cancelled' );
$cancelled = RQE::row( 'registrations', 1 );
check( $cancelled['status'] === 'DNS' && (int) $cancelled['status_manual'] === 1 && $cancelled['score'] === $cancel_before['score'] && $cancelled['placing'] === $cancel_before['placing'], 'cancellation overrides an existing manual result with DNS atomically without changing score or placing, even if DNS is disabled' );
check( $queries->rider_score( 7, 10 )['effective_status'] === 'DNS', 'cancelled DNS overrides the above-threshold automatic status' );
RQE_Statuses::assign( 7, 1, 'event', 'DNF' );
RQE_Statuses::assign( 7, 1, 'registration', 'Cancelled' );
check( RQE::row( 'registrations', 1 )['status'] === 'DNF', 'administrator may override cancellation DNS; unchanged Cancelled saves retain that manual override' );
RQE_Statuses::assign( 7, 1, 'registration', 'Initial Registration' );
check( RQE::row( 'registrations', 1 )['status'] === 'DNF', 'restoring registration leaves Event Status unchanged' );
$wpdb->fail_write = true;
rejects( function () { RQE_Statuses::assign( 7, 1, 'registration', 'Cancelled' ); }, 'could not be saved' );
$wpdb->fail_write = false;
check( RQE::row( 'registrations', 1 )['registration_status'] === 'Initial Registration' && RQE::row( 'registrations', 1 )['status'] === 'DNF', 'failed cancellation rolls back both status fields' );
RQE_Statuses::assign( 7, 1, 'registration', 'Cancelled' );
RQE_Statuses::assign( 7, 1, 'event', 'Automatic' );
check( $queries->rider_score( 7, 10 )['effective_status'] === 'Preliminary Finisher', 'explicit return to Automatic is allowed after cancellation' );
rejects( function () { RQE_Statuses::change_option( 7, 'event', 'delete', 'DNS' ); }, 'cannot be deleted' );
$unaffected = RQE::row( 'registrations', 2 );
$wpdb->delete( RQE::table( 'status_options' ), array( 'event_id' => 8, 'kind' => 'event', 'label' => 'DNS' ) );
$wpdb->fail_write = true;
rejects( function () { RQE_Statuses::migrate_cancelled(); }, 'could not be saved' );
$wpdb->fail_write = false;
check( (int) RQE::row( 'registrations', 1 )['status_manual'] === 0 && ! in_array( 'DNS', array_column( RQE_Statuses::options( 8, 'event' ), 'label' ), true ), 'failed migration leaves catalog and statuses unchanged for a safe upgrade retry' );
RQE_Statuses::migrate_cancelled();
$migrated = RQE::row( 'registrations', 1 );
RQE_Statuses::migrate_cancelled();
check( RQE::row( 'registrations', 1 ) === $migrated && $migrated['status'] === 'DNS' && (int) $migrated['status_manual'] === 1 && RQE::row( 'registrations', 2 ) === $unaffected, 'cancelled migration is repeatable and does not change non-cancelled riders or scores' );
check( in_array( 'DNS', array_column( RQE_Statuses::options( 8, 'event' ), 'label' ), true ) && ! in_array( 'DNS', RQE_Statuses::enabled_labels( RQE_Statuses::options( 7, 'event' ) ), true ), 'migration restores a previously deleted DNS option without re-enabling disabled DNS' );
RQE_Statuses::assign( 7, 1, 'event', 'Finisher' );
check( RQE::row( 'registrations', 1 )['status'] === 'Finisher', 'post-upgrade manual assignments are not forced back to DNS' );
foreach ( array( 'Paid', 'Fully Registered' ) as $label ) {
	RQE_Statuses::change_option( 8, 'registration', 'disable', $label );
}
check( RQE_Statuses::initial_registration( 8 ) === 'Cancelled', 'Cancelled may be the enabled enrollment default' );
$_POST = array( 'rider_id' => '13', 'bib' => '60', 'pillion' => '' );
$enroll = new ReflectionMethod( 'RQE_Admin', 'registration' );
if ( PHP_VERSION_ID < 80100 ) { $enroll->setAccessible( true ); }
$enroll->invoke( null, 8, 'registration' );
check( $queries->rider_score( 8, 13 )['effective_status'] === 'DNS', 'manual enrollment with Cancelled default sets DNS' );
$wpdb->insert( RQE::table( 'riders' ), array( 'id' => 14, 'name' => 'Rider Five', 'email' => 'five@example.test' ) );
$enroll_new = new ReflectionMethod( 'RQE_Admin', 'enroll_new_rider' );
if ( PHP_VERSION_ID < 80100 ) { $enroll_new->setAccessible( true ); }
$enroll_new->invoke( null, 8, 14 );
check( $queries->rider_score( 8, 14 )['effective_status'] === 'DNS', 'new-rider automatic enrollment with Cancelled default sets DNS' );
$wpdb->insert( RQE::table( 'riders' ), array( 'id' => 15, 'name' => 'Rider Six', 'email' => 'six@example.test' ) );
RQE_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Registration Status' ), array( array( '15', 'Cancelled' ) ) );
check( $queries->rider_score( 8, 15 )['effective_status'] === 'DNS', 'roster imports set Cancelled riders to manual DNS' );
$event_post = array( 'id' => '0', 'name' => 'New Event', 'description' => 'A desert scavenger hunt.', 'hunt_style' => 'bicycle', 'max_bib' => '100', 'logo_id' => '0', 'min_finisher_points' => '100', 'rider_price' => '25.00', 'pillion_price' => '', 'registration_open' => '2026-10-01T00:00', 'registration_close' => '2026-10-02T00:00', 'start_at' => '2026-10-03T00:00', 'end_at' => '2026-10-04T00:00' );
$create_event = new ReflectionMethod( 'RQE_Admin', 'event' );
if ( PHP_VERSION_ID < 80100 ) { $create_event->setAccessible( true ); }
foreach ( array( 'description' => 'description is required', 'rider_price' => 'price is required', 'min_finisher_points' => 'Minimum Finisher Points is required' ) as $missing => $message ) {
	$_POST = array_merge( $event_post, array( $missing => '' ) );
	rejects( function () use ( $create_event ) { $create_event->invoke( null ); }, $message );
}
$_POST = array_merge( $event_post, array( 'hunt_style' => 'skateboard' ) );
rejects( function () use ( $create_event ) { $create_event->invoke( null ); }, 'valid scavenger hunt style' );
$_POST = $event_post;
$new_event_id = $create_event->invoke( null );
$new_event = RQE::row( 'events', $new_event_id );
check( (int) $new_event['public_results_enabled'] === 0, 'new events keep public results disabled and receive seeded status choices' );
check( 'bicycle' === $new_event['hunt_style'] && 'A desert scavenger hunt.' === $new_event['description'] && RQE_Styles::event_code( $new_event ) === 'BC-' . $new_event_id && '0.00' === $new_event['pillion_price'], 'events save description, style-qualified ID and optional pillion price' );
check( RQE_Views::event_details_complete( $new_event ) && ! RQE_Views::event_details_complete( array_merge( $new_event, array( 'description' => ' ' ) ) ), 'event details complete only when all required fields are stored' );
$details_html = render( 'event_form', $new_event_id, $new_event );
check( strpos( $details_html, 'rqe-complete' ) !== false && strpos( $details_html, 'Event Details: BC-' . $new_event_id ) !== false && strpos( $details_html, '<textarea name="description" rows="4" maxlength="5000" required>' ) !== false, 'completed event details are green, collapsed and show the coded Event ID' );
check( strpos( render( 'event_form', 0, array() ), ' open><summary><h2>Add Event' ) !== false, 'incomplete event details remain expanded' );
check( null === $new_event['bronze_finisher_points'] && null === $new_event['silver_finisher_points'] && null === $new_event['gold_finisher_points'], 'optional thresholds default to NULL, not zero' );
check( substr_count( $details_html, 'placeholder="Not Used"' ) === 3 && strpos( $details_html, 'name="min_finisher_points"' ) < strpos( $details_html, 'name="bronze_finisher_points"' ) && strpos( $details_html, 'name="bronze_finisher_points"' ) < strpos( $details_html, 'name="silver_finisher_points"' ) && strpos( $details_html, 'name="silver_finisher_points"' ) < strpos( $details_html, 'name="gold_finisher_points"' ), 'optional thresholds appear in order after Minimum Finisher Points with Not Used placeholders' );
foreach ( array( 'registration' => 'Registration Status', 'event' => 'Event Status' ) as $kind => $title ) {
	check( strpos( render( 'status_catalog', 7, $kind, $title ), '<details class="rqe-panel rqe-status-catalog"><summary><h2>' . $title ) !== false, $title . ' defaults to minimized' );
}
foreach ( array(
	array( 'bronze_finisher_points' => '100' ),
	array( 'bronze_finisher_points' => '200', 'silver_finisher_points' => '200' ),
	array( 'bronze_finisher_points' => '200', 'gold_finisher_points' => '150' ),
	array( 'silver_finisher_points' => '300', 'gold_finisher_points' => '300' ),
	array( 'gold_finisher_points' => '100' ),
) as $invalid ) {
	$_POST = array_merge( $event_post, $invalid );
	rejects( function () use ( $create_event ) { $create_event->invoke( null ); }, 'must be greater than' );
}
foreach ( array( '-1', '1.5', '1000000001', array( '500' ) ) as $invalid ) {
	$_POST = array_merge( $event_post, array( 'gold_finisher_points' => $invalid ) );
	rejects( function () use ( $create_event ) { $create_event->invoke( null ); }, 'Gold Finisher Points must be a whole number' );
}
$_POST = array_merge( $event_post, array( 'id' => (string) $new_event_id, 'gold_finisher_points' => '400' ) );
$create_event->invoke( null );
check( (int) RQE::row( 'events', $new_event_id )['gold_finisher_points'] === 400 && null === RQE::row( 'events', $new_event_id )['silver_finisher_points'], 'Gold can be configured without intermediate tiers' );
$_POST = array_merge( $event_post, array( 'id' => (string) $new_event_id ) );
$create_event->invoke( null );
check( null === RQE::row( 'events', $new_event_id )['gold_finisher_points'], 'clearing a saved optional threshold restores Not Used' );
$_POST = array();

RQE_Public_Results::init();
check( $GLOBALS['shortcodes']['rqe_results'] === array( 'RQE_Public_Results', 'shortcode' ) && $GLOBALS['hooks']['wp_enqueue_scripts'] === array( 'RQE_Public_Results', 'assets' ), 'WordPress frontend hooks register the shortcode and isolated stylesheet' );
RQE_Public_Results::assets();
check( strpos( $GLOBALS['styles']['rqe-public-results'], 'assets/public-results.css' ) !== false, 'public stylesheet is local and separate from admin assets' );
$GLOBALS['actor'] = 0;
check( ! RQE::access(), 'anonymous visitors have no administrative access' );
$off = RQE_Public_Results::shortcode( array( 'id' => '7' ) );
check( strpos( $off, 'Results are not available' ) !== false && strpos( $off, 'Rider One' ) === false && strpos( $off, 'Event 7' ) === false, 'unpublished event exposes no rider data or event name to anonymous visitors' );
rejects( function () { RQE_Public_Results::save_visibility( 7, 1 ); }, 'not assigned' );
$GLOBALS['actor'] = 3;
rejects( function () { RQE_Public_Results::save_visibility( 8, 1 ); }, 'not assigned' );
$GLOBALS['actor'] = 2;
rejects( function () { RQE_Public_Results::save_visibility( 8, 1 ); }, 'not assigned' );
RQE_Public_Results::save_visibility( 7, 1 );
check( (int) RQE::row( 'events', 7 )['public_results_enabled'] === 1, 'assigned Event Administrators can explicitly enable public results' );
rejects( function () { RQE_Public_Results::save_visibility( 7, 2 ); }, 'whether public results' );
$wpdb->fail_write = true;
rejects( function () { RQE_Public_Results::save_visibility( 7, 0 ); }, 'could not be saved' );
$wpdb->fail_write = false;
check( (int) RQE::row( 'events', 7 )['public_results_enabled'] === 1, 'publication setting write failures roll back' );
$GLOBALS['actor'] = 1;
RQE_Public_Results::save_visibility( 8, 1 );
RQE_Public_Results::save_visibility( $new_event_id, 1 );
request( array( 'event_id' => '8', 'page' => 'rqe-event-edit' ) );
RQE_Admin::selected_event();
$GLOBALS['actor'] = 0;
$wpdb->sql = array();
$public_data = $queries->public_results( 7 );
$public_columns = array( 'bib', 'score', 'name', 'accepted_bonuses', 'rejected_bonuses', 'overall_placing', 'effective_status' );
check( count( $public_data['rows'] ) === 2 && array_diff( array_keys( $public_data['rows'][0] ), $public_columns ) === array(), 'public query returns only allowlisted result fields with no registration IDs, statuses or contacts' );
check( strpos( implode( "\n", $wpdb->sql ), 'reg.*' ) === false && strpos( implode( "\n", $wpdb->sql ), 'e.public_results_enabled=1' ) !== false, 'public SQL selects explicit fields and checks publication on the result query itself' );
check( array_column( $public_data['rows'], 'overall_placing' ) === array_column( $queries->results( 7, 100 ), 'overall_placing' ) && $public_data['rows'][0]['effective_status'] === 'Finisher', 'public ranking and effective manual statuses match administrative Results' );
$public_html = RQE_Public_Results::shortcode( array( 'id' => '7' ) );
check( strpos( $public_html, '<h2>Event 7</h2>' ) !== false && strpos( $public_html, 'Rider One' ) !== false && strpos( $public_html, 'Accepted Bonuses' ) !== false && strpos( $public_html, 'Rejected Bonuses' ) !== false, 'anonymous visitors can view enabled event results using the explicit shortcode ID instead of header selection' );
foreach ( array( 'one@example.test', 'Registration Status', 'name="op"', '<form', '<a ', 'admin.php', 'submissions/', 'reviewed_by', 'pillion' ) as $private ) {
	check( strpos( $public_html, $private ) === false, 'public HTML excludes ' . $private );
}
$other_html = RQE_Public_Results::shortcode( array( 'id' => '8' ) );
check( strpos( $other_html, '<h2>Event 8</h2>' ) !== false && substr_count( $public_html . $other_html, '<section class="rqe-public-results">' ) === 2 && strpos( $public_html . $other_html, ' id=') === false, 'multiple embeds isolate events without duplicate DOM IDs' );
check( strpos( RQE_Public_Results::shortcode( array( 'id' => (string) $new_event_id ) ), 'No participant results' ) !== false, 'enabled empty events show an explicit empty-results message' );
foreach ( array( '', '0', '-7', '7 OR 1=1', '7.0', '9999999999999999999', array( '7' ), true, 'XX-7', 'MC-', 'MC-0', 'MC 7' ) as $id ) {
	$before_queries = count( $wpdb->sql );
	check( strpos( RQE_Public_Results::shortcode( array( 'id' => $id ) ), 'valid Event ID' ) !== false && count( $wpdb->sql ) === $before_queries, 'invalid shortcode IDs are rejected before querying' );
}
check( strpos( RQE_Public_Results::shortcode( array( 'id' => '999' ) ), 'Results are not available' ) !== false, 'missing and unpublished events have the same non-disclosing response' );
check( strpos( RQE_Public_Results::shortcode( array( 'id' => 'MC-7' ) ), 'Rider One' ) !== false && strpos( RQE_Public_Results::shortcode( array( 'id' => 'mc-7' ) ), 'Rider One' ) !== false, 'style-qualified shortcode IDs render results' );
check( strpos( RQE_Public_Results::shortcode( array( 'id' => 'HB-7' ) ), 'Results are not available' ) !== false && strpos( RQE_Public_Results::shortcode( array( 'id' => 'HB-8' ) ), '<h2>Event 8</h2>' ) !== false, 'shortcode style prefixes must match the event style' );
$wpdb->update( RQE::table( 'events' ), array( 'description' => "Ride <b>far</b>\nand <script>x</script>", 'logo_id' => 55 ), array( 'id' => 7 ) );
$described = RQE_Public_Results::shortcode( array( 'id' => 'MC-7' ) );
check( strpos( $described, 'rqe-public-results-description' ) !== false && strpos( $described, '&lt;b&gt;far&lt;/b&gt;' ) !== false && strpos( $described, '<script>' ) === false && strpos( $described, 'src="fixture-55.jpg"' ) !== false && strpos( $described, 'rqe-public-results-image' ) < strpos( $described, '<table' ), 'public results include the escaped event description and image above the table' );
$wpdb->update( RQE::table( 'events' ), array( 'description' => '', 'logo_id' => 0 ), array( 'id' => 7 ) );
$wpdb->update( RQE::table( 'events' ), array( 'name' => '<script>alert("event")</script>' ), array( 'id' => 7 ) );
$wpdb->update( RQE::table( 'riders' ), array( 'name' => '<img src=x onerror=alert(1)>' ), array( 'id' => 10 ) );
$escaped = RQE_Public_Results::shortcode( array( 'id' => '7' ) );
check( strpos( $escaped, '<script>' ) === false && strpos( $escaped, '<img ' ) === false && strpos( $escaped, '&lt;script&gt;' ) !== false && strpos( $escaped, '&lt;img ' ) !== false, 'public headings, captions, labels and cells escape stored content' );
$wpdb->update( RQE::table( 'events' ), array( 'name' => 'Event 7' ), array( 'id' => 7 ) );
$wpdb->update( RQE::table( 'riders' ), array( 'name' => 'Rider One' ), array( 'id' => 10 ) );
$log = __DIR__ . '/rqe-public-test-' . bin2hex( random_bytes( 8 ) ) . '.log';
$old_log = ini_get( 'error_log' );
ini_set( 'error_log', $log );
$wpdb->fail_read = true;
try {
	$unavailable = RQE_Public_Results::shortcode( array( 'id' => '7' ) );
	check( strpos( $unavailable, 'temporarily unavailable' ) !== false && strpos( $unavailable, 'Check the database' ) === false && strpos( file_get_contents( $log ), 'RQE public results #7:' ) !== false, 'database failures show a generic public message and log administrator diagnostics' );
} finally {
	$wpdb->fail_read = false;
	ini_set( 'error_log', $old_log );
	unlink( $log );
}
$GLOBALS['actor'] = 1;
RQE_Public_Results::save_visibility( 7, 0 );
check( RQE_Public_Results::shortcode( array( 'id' => '7' ) ) === $off, 'disabling publication hides results on the next render, even from administrators' );
$edit_off = render( 'public_results_embed', RQE::row( 'events', 7 ) );
check( strpos( $edit_off, 'value="1" checked' ) === false, 'disabled publication is reflected in the edit checkbox' );
// Scavenger hunt styles, Welcome and horse conveyance.
check( RQE_Styles::parse_event_code( 'HB-12' ) === array( 'id' => 12, 'style' => 'horse' ) && RQE_Styles::parse_event_code( 12 ) === array( 'id' => 12, 'style' => null ) && null === RQE_Styles::parse_event_code( 'ZZ-12' ), 'event codes parse style prefixes and legacy numeric IDs' );
check( '' === RQE_Styles::saved_primary() && 'motorcycle' === RQE_Styles::primary(), 'primary style defaults to Motorcycle until chosen' );
check( ! RQE_Setup::welcome_complete() && ! RQE_Setup::inbox_complete(), 'Welcome and global inbox readiness remain incomplete before credentials are configured and verified' );
$welcome = render( 'welcome' );
check( strpos( $welcome, ' open><summary><h2>Welcome to Rally Quest Engine' ) !== false && strpos( $welcome, 'name="op" value="primary_style"' ) === false, 'unsatisfied Welcome section is expanded and separate from style selection' );
$style_section = render( 'primary_style_setup' );
check( strpos( $style_section, ' open><summary><h2>Primary Style' ) !== false && strpos( $style_section, 'name="op" value="primary_style"' ) !== false && strpos( $style_section, 'value="geocache"' ) !== false, 'unsaved primary style has its own expanded section and explicit save control' );
RQE_Email_Submissions::save_configuration( 0, array( 'provider' => 'gmail', 'enabled' => '1', 'mailbox' => 'global@example.test', 'client_id' => 'client-id', 'client_secret' => 'client-secret', 'refresh_token' => 'refresh-token' ) );
check( RQE_Setup::welcome_complete() && ! RQE_Setup::inbox_complete(), 'configured global email plus existing participant and event completes Welcome but not connection readiness' );
RQE_Email_Submissions::save_global_assignment( 7 );
$wpdb->update( RQE::table( 'event_email_settings' ), array( 'last_tested_at' => '2026-10-09 21:00:00', 'last_test_error' => '' ), array( 'event_id' => 0 ) );
check( RQE_Setup::inbox_complete(), 'verified enabled global inbox assigned to an active event completes inbox readiness' );
rejects( function () { RQE_Styles::save_primary( 'skateboard' ); }, 'primary scavenger hunt style' );
RQE_Styles::save_primary( 'horse' );
$style_section = render( 'primary_style_setup' );
check( strpos( $style_section, 'rqe-complete"><summary>' ) !== false && strpos( $style_section, 'Horseback saved' ) !== false && false === strpos( $style_section, ' open><summary>' ), 'explicitly saved primary style becomes green and collapsed' );
rejects( function () { RQE_Horses::data( array( 'name' => '' ) ); }, 'required text field' );
rejects( function () { RQE_Horses::data( array( 'name' => 'Ace', 'sex' => 'Unicorn' ) ); }, 'horse sex' );
rejects( function () { RQE_Horses::data( array( 'name' => 'Ace', 'age' => '61' ) ); }, 'Invalid' );
$horse_a = RQE_Horses::save( 0, RQE_Horses::data( array( 'name' => 'Ace', 'breed' => 'Arabian', 'sex' => 'Mare', 'age' => '9', 'color' => 'Bay', 'rider_id' => '10' ) ) );
$horse_b = RQE_Horses::save( 0, RQE_Horses::data( array( 'name' => 'Blaze', 'age' => '' ) ) );
$horse_c = RQE_Horses::save( 0, RQE_Horses::data( array( 'name' => 'Comet', 'rider_id' => '11' ) ) );
check( null === RQE::row( 'horses', $horse_b )['age'] && 'Arabian' === RQE::row( 'horses', $horse_a )['breed'], 'horse records store optional details' );
rejects( function () use ( $horse_c ) { RQE_Horses::assign( 10, array( $horse_c ) ); }, 'another participant' );
RQE_Horses::assign( 10, array( $horse_b ) );
check( 0 === (int) RQE::row( 'horses', $horse_a )['rider_id'] && 10 === (int) RQE::row( 'horses', $horse_b )['rider_id'] && 11 === (int) RQE::row( 'horses', $horse_c )['rider_id'], 'horse assignment replaces only the participant selection' );
$horse_form = render( 'rider_form', 10, RQE::row( 'riders', 10 ) );
check( strpos( $horse_form, 'name="horse_selection" value="1"' ) !== false && strpos( $horse_form, 'value="' . $horse_b . '" checked' ) !== false && strpos( $horse_form, 'Comet' ) === false && strpos( $horse_form, 'vehicle_selection' ) === false, 'Horseback participant form lists available horses and hides vehicles' );
RQE_Styles::save_primary( 'geocache' );
$geo_form = render( 'rider_form', 10, RQE::row( 'riders', 10 ) );
check( strpos( $geo_form, 'horse_selection' ) === false && strpos( $geo_form, 'vehicle_selection' ) === false, 'GeoCache participant form has no conveyance section' );
$bicycle_row = render( 'bike_row', array(), array( 'bike_id' => '', 'model_year' => '', 'fuel_capacity' => '5 gal' ), false );
check( strpos( $bicycle_row, 'type="hidden" name="fuel[]" value="5 gal"' ) !== false && strpos( $bicycle_row, 'disabled="disabled"' ) !== false && strpos( $bicycle_row, 'Remove bicycle' ) !== false, 'Bicycle rows grey out fuel while preserving saved values' );
RQE_Horses::delete( $horse_a );
check( null === RQE::row( 'horses', $horse_a ), 'horses can be deleted' );
unset( $GLOBALS['test_options']['rqe_primary_style'] );
$GLOBALS['actor'] = 1;
$wpdb->update( RQE::table( 'events' ), array( 'bronze_finisher_points' => 200, 'silver_finisher_points' => 300, 'gold_finisher_points' => 400 ), array( 'id' => 7 ) );
$wpdb->update( RQE::table( 'registrations' ), array( 'score' => 401, 'status_manual' => 0 ), array( 'event_id' => 7, 'rider_id' => 10 ) );
foreach ( array(
	$queries->scoring_rider( 7, 10 ),
	$queries->rider_score( 7, 10 ),
	array_values( array_filter( $queries->rider_events( 10, true, 1 ), function ( $row ) { return 7 === (int) $row['event_id']; } ) )[0],
	array_values( array_filter( $queries->scoring( 7, 100 ), function ( $row ) { return 10 === (int) $row['id']; } ) )[0],
	array_values( array_filter( $queries->results( 7, 100 ), function ( $row ) { return 10 === (int) $row['rider_id']; } ) )[0],
) as $row ) {
	check( 'Preliminary Gold Finisher' === $row['effective_status'], 'every administrative score surface uses the configured highest tier' );
}
RQE_Public_Results::save_visibility( 7, 1 );
check( strpos( RQE_Public_Results::shortcode( array( 'id' => 'MC-7' ) ), 'Preliminary Gold Finisher' ) !== false, 'public results use the same automatic tier as administration' );
check( 1 === RQE_Statuses::usage( 7, 'event', 'Preliminary Gold Finisher' ), 'catalog usage counts automatic tier assignments' );
RQE_Statuses::migrate_tiers();
$options_before = count( RQE_Statuses::options( 7, 'event' ) );
RQE_Statuses::migrate_tiers();
check( $options_before === count( RQE_Statuses::options( 7, 'event' ) ), 'tier migration is idempotent and preserves historical choices' );
rejects( function () { RQE_Statuses::change_option( 7, 'event', 'delete', 'Preliminary Silver Finisher' ); }, 'System statuses cannot be deleted' );
RQE_Statuses::change_option( 7, 'event', 'enable', 'DNS' );
RQE_Statuses::assign( 7, 1, 'event', 'DNS' );
check( 'DNS' === $queries->rider_score( 7, 10 )['effective_status'], 'manual DNS remains authoritative above Gold' );
RQE_Statuses::assign( 7, 1, 'event', 'Automatic' );
$wpdb->update( RQE::table( 'registrations' ), array( 'score' => 201 ), array( 'event_id' => 7, 'rider_id' => 10 ) );
check( 'Preliminary Bronze Finisher' === $queries->rider_score( 7, 10 )['effective_status'], 'returning to Automatic and reducing points recalculates the tier' );
$wpdb->update( RQE::table( 'events' ), array( 'bronze_finisher_points' => null, 'silver_finisher_points' => null, 'gold_finisher_points' => null ), array( 'id' => 7 ) );
check( 'Preliminary Finisher' === $queries->rider_score( 7, 10 )['effective_status'], 'removing tiers immediately restores the minimum-only calculation' );
$GLOBALS['actor'] = 1;
$ordered = array_column( RQE_Statuses::options( 7, 'event' ), 'label' );
$moving = $ordered[1];
RQE_Statuses::change_option( 7, 'event', 'up', $moving );
check( $moving === RQE_Statuses::options( 7, 'event' )[0]['label'], 'Move Up changes the displayed catalog order' );
RQE_Statuses::change_option( 7, 'event', 'down', $moving );
check( $ordered === array_column( RQE_Statuses::options( 7, 'event' ), 'label' ), 'Move Down restores catalog ordering without changing assignments' );
rejects( function () { RQE_Statuses::change_option( 7, 'event', 'certificate_on', 'dnf' ); }, 'Only final finisher' );
rejects( function () { RQE_Statuses::change_option( 7, 'event', 'certificate_on', 'Preliminary Finisher' ); }, 'Only final finisher' );
RQE_Event_State::change( 7, 'lock' );
rejects( function () { RQE_Statuses::assign( 7, 1, 'event', 'Finisher' ); }, 'locked or archived' );
rejects( function () use ( $moving ) { RQE_Statuses::change_option( 7, 'event', 'up', $moving ); }, 'locked or archived' );
rejects( function () { RQE_Roster_Import_Service::import_rows( 7, array( 'Participant ID' ), array( array( '11' ) ) ); }, 'locked or archived' );
$apply_bonus = new ReflectionMethod( 'RQE_Bonus', 'apply_import' );
if ( PHP_VERSION_ID < 80100 ) { $apply_bonus->setAccessible( true ); }
rejects( function () use ( $apply_bonus ) { $apply_bonus->invoke( null, 7, array(), array(), '', false ); }, 'locked or archived' );
RQE::begin_transaction();
rejects( function () { RQE_Event_State::protect_deletion( 'diagnostics_delete_all', array() ); }, 'locked or archived' );
RQE::rollback_transaction();
request( array( 'event_id' => '7' ) );
check( strpos( render( 'overall_results' ), 'status changes are disabled' ) !== false, 'locked result status controls are read-only' );
$GLOBALS['actor'] = 2;
rejects( function () { RQE_Event_State::change( 7, 'unlock' ); }, 'WordPress administrator' );
$GLOBALS['actor'] = 1;
RQE_Event_State::change( 7, 'archive' );
rejects( function () { RQE_Event_State::change( 7, 'unlock' ); }, 'Unarchive' );
RQE_Event_State::change( 7, 'unarchive' );
check( 1 === (int) RQE::row( 'events', 7 )['is_locked'], 'unarchiving preserves the lock' );
RQE_Event_State::change( 7, 'unlock' );
RQE_Event_State::change( 8, 'archive' );
RQE_Admin::remember_event( 7 );
request();
check( strpos( render( 'header', 'Results' ), '<option value="8" data-style=' ) === false, 'archived events are hidden by default' );
RQE_Event_State::save_visibility( true );
check( strpos( render( 'header', 'Results' ), '<option value="8" data-style=' ) !== false, 'per-user archived visibility reveals archived events' );
RQE_Event_State::save_visibility( false );
RQE_Admin::remember_event( 8 );
request();
check( strpos( render( 'header', 'Results' ), '<option value="8" data-style=' ) !== false, 'selected archived context remains visible when archived lists are hidden' );
RQE_Event_State::change( 8, 'unarchive' );
RQE_Event_State::change( 8, 'unlock' );
RQE_Admin::remember_event( 7 );
request();
RQE_Statuses::change_option( 7, 'event', 'disable', 'Silver Finisher' );
$before_assignments = $wpdb->get_results( 'SELECT * FROM ' . RQE::table( 'registrations' ) . ' ORDER BY id', ARRAY_A );
RQE::begin_transaction();
RQE_Statuses::migrate_finishers();
RQE::commit_transaction();
$ordered = array_column( RQE_Statuses::options( 7, 'event' ), 'label' );
check( array_slice( $ordered, 0, 12 ) === RQE_Statuses::defaults( 'event' ), 'upgrade installs the requested twelve-status order before custom labels' );
check( ! in_array( 'Silver Finisher', RQE_Statuses::enabled_labels( RQE_Statuses::options( 7, 'event' ) ), true ), 'upgrade preserves disabled status choices' );
check( $before_assignments === $wpdb->get_results( 'SELECT * FROM ' . RQE::table( 'registrations' ) . ' ORDER BY id', ARRAY_A ), 'order migration leaves scores and participant statuses unchanged' );
foreach ( RQE_Statuses::final_finishers() as $label ) {
	check( RQE_Certificates::eligible( array( 'status' => $label, 'status_manual' => 1 ), array() ), $label . ' qualifies even when disabled or absent from manual choices' );
}
rejects( function () { RQE_Statuses::change_option( 7, 'event', 'certificate_off', 'Silver Finisher' ); }, 'always certificate eligible' );
check( strpos( render( 'event_form', 7, RQE::row( 'events', 7 ) ), 'name="certificate_design"' ) !== false, 'event edit provides the predefined certificate design selector' );
rejects( function () { RQE_Certificates::design( 'arbitrary-path' ); }, 'predefined certificate design' );
$_POST = array_merge( $event_post, array( 'id' => (string) $new_event_id, 'certificate_design' => 'classic' ) );
$create_event->invoke( null );
check( RQE::row( 'events', $new_event_id )['certificate_design'] === 'classic', 'event save persists a predefined certificate design' );
$_POST['certificate_design'] = 'unknown';
rejects( function () use ( $create_event ) { $create_event->invoke( null ); }, 'predefined certificate design' );
check( RQE::row( 'events', $new_event_id )['certificate_design'] === 'classic', 'invalid design does not overwrite the saved selection' );
check( array_column( RQE_Statuses::options( $new_event_id, 'event' ), 'label' ) === RQE_Statuses::defaults( 'event' ), 'new events seed the exact requested status order' );
RQE_Statuses::change_option( 7, 'event', 'up', 'DNS' );
check( 'DNS' === RQE_Statuses::options( 7, 'event' )[0]['label'], 'manual ordering remains available after the one-time default migration' );
$wpdb->update( RQE::table( 'status_options' ), array( 'label' => 'bronze finisher', 'enabled' => 0 ), array( 'event_id' => 7, 'kind' => 'event', 'label' => 'Bronze Finisher' ) );
$status_count = count( RQE_Statuses::options( 7, 'event' ) );
RQE_Statuses::migrate_finishers();
$bronze_options = array_values( array_filter( RQE_Statuses::options( 7, 'event' ), function ( $option ) { return 'bronze finisher' === strtolower( $option['label'] ); } ) );
check( count( RQE_Statuses::options( 7, 'event' ) ) === $status_count && count( $bronze_options ) === 1 && (int) $bronze_options[0]['enabled'] === 0, 'upgrade matches historical status labels case-insensitively without duplication or re-enabling' );
if ( getenv( 'RQE_PREVIEW_DIR' ) ) {
	file_put_contents( getenv( 'RQE_PREVIEW_DIR' ) . '/event.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="admin.css">' . $html );
	file_put_contents( getenv( 'RQE_PREVIEW_DIR' ) . '/riders.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="admin.css"><script defer src="admin.js"></script>' . $riders_html );
	file_put_contents( getenv( 'RQE_PREVIEW_DIR' ) . '/results.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="admin.css"><script defer src="admin.js"></script>' . $results_html );
	file_put_contents( getenv( 'RQE_PREVIEW_DIR' ) . '/public-results.html', '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="public-results.css">' . $public_html . $other_html );
}
require_once __DIR__ . '/public-bonus-details.php';
test_public_bonus_details();
