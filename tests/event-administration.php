<?php
/** SQL-backed status, context, result and view regressions: php tests/event-administration.php */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MRSA_VERSION', 'test' );
define( 'MRSA_FILE', __DIR__ . '/../motorcycle-rally-scoring-application/motorcycle-rally-scoring-application.php' );

final class Event_Test_DB {
	public $prefix = '';
	public $last_error = '';
	public $insert_id = 0;
	public $fail_write = false;
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
function is_user_logged_in() { return true; }
function current_user_can( $capability ) { return 1 === $GLOBALS['actor']; }
function get_current_user_id() { return $GLOBALS['actor']; }
function get_user_option( $key, $id ) { return $GLOBALS['preferences'][ $GLOBALS['site'] ][ $id ][ $key ] ?? 0; }
function update_user_option( $id, $key, $value, $global = false ) {
	if ( $global ) { throw new LogicException( 'Event preference must be site-scoped.' ); }
	if ( ! empty( $GLOBALS['fail_preference'] ) ) { return false; }
	$GLOBALS['preferences'][ $GLOBALS['site'] ][ $id ][ $key ] = $value;
	return true;
}
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_email( $value ) { return strtolower( $value ); }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
function selected( $value, $current, $echo = true ) { $html = (string) $value === (string) $current ? ' selected="selected"' : ''; if ( $echo ) { echo $html; } return $html; }
function wp_list_pluck( $rows, $key ) { return array_column( $rows, $key ); }
function wp_timezone_string() { return 'UTC'; }
function get_users( $args ) { return array(); }
function get_user_by( $key, $id ) { return (object) array( 'ID' => $id, 'user_login' => 'user-' . $id, 'user_email' => 'user@example.test', 'display_name' => 'User ' . $id ); }
function get_user_meta( $id, $key, $single ) { return ''; }
function wp_salt( $scheme ) { return 'fixture-salt'; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( ( $args['response'] ?? 500 ) . ': ' . $message ); }
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-admin.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-statuses.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-email-submissions.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-bonus.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-roster-import-service.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-views.php';

function check( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } echo 'PASS: ' . $label . PHP_EOL; }
function rejects( $run, $message ) {
	try { $run(); } catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), $message ) !== false, 'rejects ' . $message );
		return;
	}
	throw new LogicException( 'Expected rejection: ' . $message );
}
function request( $query = array() ) {
	$_GET = $query;
	$property = new ReflectionProperty( 'MRSA_Admin', 'selected_event' );
	if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
	$property->setValue( null, null );
}
function render( $method, ...$args ) {
	$reflection = new ReflectionMethod( 'MRSA_Views', $method );
	if ( PHP_VERSION_ID < 80100 ) { $reflection->setAccessible( true ); }
	ob_start();
	try { $reflection->invoke( null, ...$args ); return ob_get_contents(); } finally { ob_end_clean(); }
}

$wpdb = new Event_Test_DB();
foreach ( array(
	'events' => 'id INTEGER PRIMARY KEY,name TEXT,event_date TEXT,max_bib INTEGER,min_finisher_points INTEGER,logo_id INTEGER DEFAULT 0',
	'riders' => "id INTEGER PRIMARY KEY,name TEXT,email TEXT,alias TEXT DEFAULT '',phone TEXT DEFAULT ''",
	'registrations' => "id INTEGER PRIMARY KEY,event_id INTEGER,rider_id INTEGER,bib INTEGER,pillion TEXT DEFAULT '',registration_status TEXT DEFAULT 'Initial Registration',status TEXT DEFAULT 'Registered',status_manual INTEGER DEFAULT 0,score INTEGER DEFAULT 0,placing INTEGER",
	'status_options' => 'id INTEGER PRIMARY KEY AUTOINCREMENT,event_id INTEGER,kind TEXT,label TEXT COLLATE NOCASE,enabled INTEGER,UNIQUE(event_id,kind,label)',
	'event_admins' => 'event_id INTEGER,user_id INTEGER',
	'event_scorers' => 'event_id INTEGER,user_id INTEGER',
	'event_email_settings' => "event_id INTEGER PRIMARY KEY,provider TEXT,enabled INTEGER,encrypted_settings TEXT,last_checked_at TEXT,last_error TEXT DEFAULT '',last_tested_at TEXT,last_test_error TEXT DEFAULT ''",
	'bonus_points' => "event_id INTEGER,bonus_id TEXT,description TEXT DEFAULT '',image_file TEXT DEFAULT ''",
	'bonus_submissions' => "id INTEGER PRIMARY KEY,event_id INTEGER,rider_id INTEGER,bib INTEGER,registration_id INTEGER,bonus_id TEXT,status TEXT,points_awarded INTEGER DEFAULT 0,submitted_at TEXT DEFAULT '',reviewed_at TEXT,reviewed_by INTEGER DEFAULT 0,rejection_description TEXT DEFAULT '',rejection_code TEXT DEFAULT '',image_file TEXT DEFAULT '',submission_description TEXT DEFAULT ''",
) as $table => $schema ) {
	MRSA_Admin::checked( $wpdb->query( 'CREATE TABLE ' . MRSA::table( $table ) . ' (' . $schema . ')' ) );
}
foreach ( array( 7, 8 ) as $id ) {
	$wpdb->insert( MRSA::table( 'events' ), array( 'id' => $id, 'name' => 'Event ' . $id, 'event_date' => '2026-10-10', 'max_bib' => 100, 'min_finisher_points' => 100 ) );
}
$wpdb->insert( MRSA::table( 'riders' ), array( 'id' => 10, 'name' => 'Rider One', 'email' => 'one@example.test' ) );
$wpdb->insert( MRSA::table( 'riders' ), array( 'id' => 11, 'name' => 'Rider Two', 'email' => 'two@example.test' ) );
$wpdb->insert( MRSA::table( 'registrations' ), array( 'id' => 1, 'event_id' => 7, 'rider_id' => 10, 'bib' => 42, 'registration_status' => 'Legacy registration', 'status' => 'Legacy event', 'status_manual' => 1, 'score' => 101 ) );
$wpdb->insert( MRSA::table( 'registrations' ), array( 'id' => 2, 'event_id' => 7, 'rider_id' => 11, 'bib' => 43, 'score' => 101 ) );
$wpdb->insert( MRSA::table( 'registrations' ), array( 'id' => 3, 'event_id' => 8, 'rider_id' => 10, 'bib' => 44, 'score' => 0 ) );
MRSA_Statuses::migrate();
$before = MRSA::row( 'registrations', 1 );
check( in_array( 'Legacy registration', array_column( MRSA_Statuses::options( 7, 'registration' ), 'label' ), true ) && in_array( 'Legacy event', array_column( MRSA_Statuses::options( 7, 'event' ), 'label' ), true ), 'migration preserves historical status labels in separate catalogs' );
MRSA_Statuses::change_option( 7, 'registration', 'disable', 'Paid' );
$count = count( MRSA_Statuses::options( 7, 'registration' ) );
MRSA_Statuses::migrate();
check( count( MRSA_Statuses::options( 7, 'registration' ) ) === $count && ! in_array( 'Paid', MRSA_Statuses::enabled_labels( MRSA_Statuses::options( 7, 'registration' ) ), true ) && MRSA::row( 'registrations', 1 ) === $before, 'repeat migration preserves disabled choices, scores and manual assignments' );
MRSA_Statuses::change_option( 7, 'registration', 'add', 'Checked In' );
MRSA_Statuses::assign( 7, 1, 'registration', 'Checked In' );
check( MRSA::row( 'registrations', 1 )['status'] === 'Legacy event', 'Registration Status assignment does not change Event Status' );
MRSA_Statuses::assign( 7, 1, 'event', 'Finisher' );
check( MRSA::row( 'registrations', 1 )['registration_status'] === 'Checked In', 'Event Status assignment does not change Registration Status' );
MRSA_Statuses::change_option( 7, 'registration', 'disable', 'Checked In' );
MRSA_Statuses::assign( 7, 1, 'registration', 'Checked In' );
rejects( function () { MRSA_Statuses::assign( 7, 2, 'registration', 'Checked In' ); }, 'enabled status' );
rejects( function () { MRSA_Statuses::change_option( 7, 'registration', 'delete', 'Checked In' ); }, 'in use' );
check( MRSA::row( 'registrations', 1 )['registration_status'] === 'Checked In', 'disabled in-use value is retained and cannot be deleted' );
MRSA_Statuses::change_option( 7, 'registration', 'enable', 'Checked In' );
MRSA_Statuses::assign( 7, 1, 'registration', 'Initial Registration' );
MRSA_Statuses::change_option( 7, 'registration', 'delete', 'Checked In' );
rejects( function () { MRSA_Statuses::change_option( 7, 'event', 'add', 'finisher' ); }, 'already exists' );
rejects( function () { MRSA_Statuses::change_option( 7, 'event', 'add', 'Automatic' ); }, 'calculation mode' );
rejects( function () { MRSA_Statuses::assign( 8, 1, 'event', 'DNS' ); }, 'Registration not found' );
rejects( function () { MRSA_Statuses::change_option( 7, 'event', 'delete', 'Preliminary Finisher' ); }, 'in use' );
rejects( function () { MRSA_Statuses::change_option( 8, 'event', 'delete', 'Preliminary Finisher' ); }, 'cannot be deleted' );
MRSA_Statuses::change_option( 7, 'registration', 'disable', 'Initial Registration' );
check( MRSA_Statuses::initial_registration( 7 ) === 'Fully Registered', 'disabled default falls back to the first enabled Registration Status' );
MRSA_Statuses::change_option( 7, 'registration', 'disable', 'Fully Registered' );
MRSA_Statuses::change_option( 7, 'registration', 'disable', 'Cancelled' );
rejects( function () { MRSA_Statuses::change_option( 7, 'registration', 'disable', 'Legacy registration' ); }, 'at least one' );
$wpdb->fail_write = true;
rejects( function () { MRSA_Statuses::assign( 7, 1, 'event', 'DNS' ); }, 'could not be saved' );
$wpdb->fail_write = false;
check( MRSA::row( 'registrations', 1 )['status'] === 'Finisher', 'failed write rolls back status assignment' );
MRSA_Statuses::assign( 7, 1, 'event', 'Automatic' );
$queries = new MRSA_Queries( $wpdb );
foreach ( array( 99 => 'Registered', 100 => 'Registered', 101 => 'Preliminary Finisher' ) as $score => $status ) {
	$wpdb->update( MRSA::table( 'registrations' ), array( 'score' => $score ), array( 'id' => 1 ) );
	check( $queries->rider_score( 7, 10 )['effective_status'] === $status, 'automatic threshold at score ' . $score );
}
foreach ( array( 'Finisher', 'DNF', 'DNS' ) as $status ) {
	MRSA_Statuses::assign( 7, 1, 'event', $status );
	$wpdb->update( MRSA::table( 'registrations' ), array( 'score' => 1000 ), array( 'id' => 1 ) );
	check( $queries->rider_score( 7, 10 )['effective_status'] === $status, 'manual ' . $status . ' survives subsequent score changes' );
}
$wpdb->update( MRSA::table( 'registrations' ), array( 'score' => 101 ), array( 'id' => 1 ) );
foreach ( array( array( 7, 10, 'accepted' ), array( 7, 10, 'rejected' ), array( 7, 10, 'rejected' ), array( 7, 10, 'pending' ), array( 7, 10, 'invalid' ), array( 8, 10, 'accepted' ) ) as $index => $submission ) {
	$wpdb->insert( MRSA::table( 'bonus_submissions' ), array( 'id' => $index + 1, 'event_id' => $submission[0], 'rider_id' => $submission[1], 'bib' => 42, 'registration_id' => 1, 'bonus_id' => 'B1', 'status' => $submission[2], 'points_awarded' => 'accepted' === $submission[2] ? 125 : 0 ) );
}
$results = $queries->results( 7, 100 );
check( (int) $results[0]['accepted_bonuses'] === 1 && (int) $results[0]['rejected_bonuses'] === 2 && (int) $results[1]['accepted_bonuses'] === 0 && (int) $results[1]['rejected_bonuses'] === 0, 'real SQL counts decisions rather than points, excludes pending/invalid and isolates events/riders' );
check( array_column( $results, 'overall_placing' ) === array( 1, 1 ), 'submission aggregation preserves scores and competition ties' );
MRSA_Statuses::change_option( 8, 'registration', 'add', 'Verified' );
MRSA_Statuses::change_option( 8, 'registration', 'disable', 'Initial Registration' );
MRSA_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Registration Status' ), array( array( '11', '' ) ) );
$imported = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MRSA::table( 'registrations' ) . ' WHERE event_id=%d AND rider_id=%d', 8, 11 ), ARRAY_A );
check( $imported['registration_status'] === 'Paid' && (int) $imported['score'] === 0 && (int) $imported['status_manual'] === 0, 'new imports use enabled defaults without importing score or Event Status' );
$wpdb->insert( MRSA::table( 'riders' ), array( 'id' => 12, 'name' => 'Rider Three', 'email' => 'three@example.test' ) );
MRSA_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Status' ), array( array( '12', 'Verified' ) ) );
check( MRSA_Statuses::usage( 8, 'registration', 'Verified' ) === 1, 'legacy Status import heading accepts enabled event-local custom values' );
MRSA_Statuses::change_option( 8, 'registration', 'disable', 'Verified' );
$wpdb->insert( MRSA::table( 'riders' ), array( 'id' => 13, 'name' => 'Rider Four', 'email' => 'four@example.test' ) );
rejects( function () { MRSA_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Registration Status' ), array( array( '13', 'Verified' ) ) ); }, 'enabled Registration Status' );
check( null === $queries->rider_score( 8, 13 ), 'disabled custom-status import rolls back without enrolling a rider' );
rejects( function () { MRSA_Roster_Import_Service::import_rows( 8, array( 'Rider ID', 'Status', 'Registration Status' ), array( array( '13', 'Paid', 'Paid' ) ) ); }, 'duplicate headers' );
$wpdb->insert( MRSA::table( 'bonus_submissions' ), array( 'id' => 7, 'event_id' => 7, 'rider_id' => 0, 'bib' => 0, 'registration_id' => 0, 'bonus_id' => '', 'status' => 'invalid' ) );
$wpdb->insert( MRSA::table( 'event_admins' ), array( 'event_id' => 7, 'user_id' => 2 ) );
$wpdb->insert( MRSA::table( 'event_scorers' ), array( 'event_id' => 8, 'user_id' => 3 ) );
$GLOBALS['actor'] = 2;
MRSA_Statuses::assign( 7, 1, 'event', 'Finisher' );
rejects( function () { MRSA_Statuses::assign( 8, 3, 'event', 'DNS' ); }, 'not assigned' );
$GLOBALS['actor'] = 3;
rejects( function () { MRSA_Statuses::assign( 8, 3, 'event', 'DNS' ); }, 'not assigned' );
rejects( function () { MRSA_Statuses::change_option( 8, 'event', 'disable', 'Finisher' ); }, 'not assigned' );
$GLOBALS['actor'] = 1;
request( array( 'event_id' => '7' ) );
check( MRSA_Admin::selected_event() === 7, 'explicit event links set header context' );
request( array( 'tab' => 'results' ) );
check( MRSA_Admin::selected_event() === 7, 'header event survives navigation without event parameters' );
$GLOBALS['site'] = 2;
request();
check( MRSA_Admin::selected_event() === 0, 'header selection is independent between sites' );
$GLOBALS['site'] = 1;
$GLOBALS['actor'] = 2;
request();
check( MRSA_Admin::selected_event() === 0, 'header selection is independent between users' );
request( array( 'event_id' => '8' ) );
rejects( function () { MRSA_Admin::selected_event(); }, '403:' );
$GLOBALS['actor'] = 1;
$GLOBALS['fail_preference'] = true;
request( array( 'event_id' => '8' ) );
rejects( function () { MRSA_Admin::selected_event(); }, 'could not be remembered' );
$GLOBALS['fail_preference'] = false;
update_user_option( 1, 'mrsa_selected_event', 999 );
request();
ob_start();
$selected = MRSA_Admin::selected_event();
$notice = ob_get_clean();
check( 0 === $selected && strpos( $notice, 'no longer accessible' ) !== false, 'stale selection requires explicit reselection and shows a warning' );
request( array( 'page' => 'mrsa', 'tab' => 'results', 'event_id' => '7' ) );
$html = render( 'overall_results' );
check( strpos( $html, 'Accepted Bonuses' ) !== false && strpos( $html, 'Rejected Bonuses' ) !== false && strpos( $html, 'Event Status' ) !== false && strpos( $html, 'name="event_id" value="7"' ) !== false && strpos( $html, 'Choose event</button>' ) === false, 'Results includes decision counts, editable Event Status and no local event selector' );
$results_html = '<div class="wrap mrsa">' . render( 'header' ) . $html . '</div>';
$html = render( 'registrations', MRSA::row( 'events', 7 ) );
check( strpos( $html, 'Registration Status' ) !== false && strpos( $html, 'name="op" value="registration_lifecycle"' ) !== false && strpos( $html, 'disabled; retained' ) !== false, 'Rosters edits registration status while retaining assigned disabled values' );
$html = render( 'reviewed_submissions', MRSA::row( 'events', 7 ), 0 );
check( strpos( $html, 'page=mrsa-rider-score&amp;event_id=7&amp;rider_id=10&amp;from=reviewed' ) !== false, 'Reviewed Bib links use score-sheet event, rider and return context' );
check( strpos( $html, '<td>—</td><td>Unmatched message</td>' ) !== false && strpos( $html, 'rider_id=0&amp;from=reviewed' ) === false, 'unmatched reviewed messages have no broken score-sheet hyperlink' );
request( array( 'page' => 'mrsa', 'tab' => 'riders' ) );
$riders_html = '<div class="wrap mrsa">' . render( 'header' ) . render( 'rider_setup' ) . '</div>';
check( strpos( $riders_html, 'Choose Event' ) !== false && strpos( $riders_html, 'value="7" selected="selected"' ) !== false, 'header reflects the remembered authorized event' );
check( strpos( $riders_html, '>Add Rider</a>' ) < strpos( $riders_html, 'Search all records' ) && substr_count( $riders_html, 'Search all records' ) === 1 && strpos( $riders_html, 'mrsa-riders-toolbar' ) !== false, 'Riders toolbar places a single server search after Add Rider' );
$GLOBALS['actor'] = 3;
request( array( 'page' => 'mrsa-rider-score', 'event_id' => '8', 'rider_id' => '10', 'from' => 'reviewed' ) );
$html = render( 'render_rider_score' );
check( strpos( $html, 'Event Status:' ) !== false && strpos( $html, 'score_tab=reviewed' ) !== false && strpos( $html, 'name="op" value="registration_status"' ) === false, 'assigned scorers can read score sheets and return to Reviewed but cannot edit statuses' );
request( array( 'event_id' => '7', 'rider_id' => '10' ) );
rejects( function () { render( 'render_rider_score' ); }, '403:' );
$GLOBALS['actor'] = 1;
request( array( 'page' => 'mrsa', 'tab' => 'scoring', 'event_id' => '7', 'score_tab' => 'contested' ) );
$html = render( 'scoring', MRSA::row( 'events', 7 ) );
check( strpos( $html, 'Submissions - Contested' ) !== false && strpos( $html, 'No contested-submission workflow is configured.' ) !== false, 'Contested tab and placeholder content remain unchanged' );
request( array( 'page' => 'mrsa-event-edit', 'event_id' => '7' ) );
MRSA_Email_Submissions::save_configuration( 7, array( 'provider' => 'imap', 'enabled' => '1', 'mailbox' => 'inbox@example.test', 'host' => 'imap.example.test', 'port' => '993', 'encryption' => 'ssl', 'username' => 'inbox@example.test', 'folder' => 'INBOX', 'password' => 'fixture-password' ) );
$html = render( 'render_event_edit' );
check( strpos( $html, 'mrsa-event-columns' ) !== false && strpos( $html, 'mrsa-email-connected"><summary>' ) !== false && strpos( $html, '<strong>Configured</strong>' ) !== false && strpos( $html, 'Configuration saved. Test the inbox' ) !== false, 'configured email panel is green/collapsed without falsely claiming verified connectivity' );
check( strpos( $html, 'Email Bonus Submissions' ) < strpos( $html, 'Event Administrator Assignments' ) && strpos( $html, 'Event Administrator Assignments' ) < strpos( $html, 'Event Scorer Assignments' ), 'right column orders email, administrator assignments and scorer assignments' );
check( strpos( $html, 'name="status_kind" value="registration"' ) !== false && strpos( $html, 'name="status_kind" value="event"' ) !== false, 'event page exposes separate status catalogs' );
$wpdb->update( MRSA::table( 'event_email_settings' ), array( 'last_test_error' => 'Fixture connection error' ), array( 'event_id' => 7 ) );
$failed_html = render( 'render_event_edit' );
check( strpos( $failed_html, 'mrsa-email-connection-failed" open' ) !== false && strpos( $failed_html, 'Fixture connection error' ) !== false, 'failed inbox test remains expanded and prominent' );
if ( getenv( 'MRSA_PREVIEW_DIR' ) ) {
	file_put_contents( getenv( 'MRSA_PREVIEW_DIR' ) . '/event.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="admin.css">' . $html );
	file_put_contents( getenv( 'MRSA_PREVIEW_DIR' ) . '/riders.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="admin.css"><script defer src="admin.js"></script>' . $riders_html );
	file_put_contents( getenv( 'MRSA_PREVIEW_DIR' ) . '/results.html', '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="admin.css"><script defer src="admin.js"></script>' . $results_html );
}
