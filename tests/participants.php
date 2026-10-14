<?php
/** SQL-backed account approval and private participant portal regressions; also a bounded HTTP upload fixture. */
ob_start();
require __DIR__ . '/event-administration.php';
ob_end_clean();
require_once __DIR__ . '/../rally-quest-engine/includes/class-rqe-import-adapters.php';
require_once __DIR__ . '/../rally-quest-engine/includes/class-rqe-file-scan.php';
define( 'DAY_IN_SECONDS', 86400 );
if ( getenv( 'RQE_TEST_SCAN_SOCKET' ) ) { define( 'RQE_CLAMAV_SOCKET', getenv( 'RQE_TEST_SCAN_SOCKET' ) ); }

class WP_User {
	public $ID;
	public $user_login;
	public $user_email;
	public $display_name;
	public $roles;
	public function __construct( $id, $email, $role = 'contributor' ) {
		$this->ID = $id; $this->user_login = 'user-' . $id; $this->user_email = $email;
		$this->display_name = 'Participant ' . $id; $this->roles = array( $role );
	}
}
class WP_Error {
	private $code;
	public function __construct( $code, $message ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_current_blog_id() { return 1; }
function current_time( $kind ) { return $GLOBALS['participant_time'] ?? '2026-10-13 12:00:00'; }
function wp_insert_user( $data ) {
	$id = count( $GLOBALS['participant_users'] ) + 100;
	$GLOBALS['participant_users'][ $id ] = new WP_User( $id, $data['user_email'], $data['role'] );
	$GLOBALS['participant_meta'][ $id ] = $data['meta_input'];
	return $id;
}
function delete_user_meta( $id, $key ) { unset( $GLOBALS['participant_meta'][ $id ][ $key ] ); return true; }
function wp_delete_user( $id ) { unset( $GLOBALS['participant_users'][ $id ], $GLOBALS['participant_meta'][ $id ] ); return true; }
function wp_new_user_notification( $id, $deprecated, $notify ) { $GLOBALS['password_notifications'][] = $id; }
function wp_generate_password( $length, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
function wp_schedule_single_event( $when, $hook, $args, $error ) { return true; }
function wp_mail( $email, $subject, $message ) {
	$GLOBALS['participant_mail'][] = array( $email, $subject, $message );
	return empty( $GLOBALS['mail_failure'] );
}
function get_temp_dir() { return sys_get_temp_dir(); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_mkdir_p( $directory ) { return is_dir( $directory ) || mkdir( $directory, 0700, true ); }
function wp_login_url( $return ) { return 'https://example.test/wp-login.php'; }
function remove_query_arg( $key, $url ) { return $url; }
function nocache_headers() {}
function check_admin_referer( $action ) {
	if ( ! empty( $GLOBALS['bad_participant_nonce'] ) ) { throw new RuntimeException( 'Invalid nonce.' ); }
}
function clear_participant_rates() {
	foreach ( array_keys( $GLOBALS['test_options'] ) as $key ) {
		if ( 0 === strpos( $key, 'rqe_participant_rate_' ) ) { delete_option( $key ); }
	}
}
function participant_request( $user_id, $status = 'verified', $event_id = 7 ) {
	global $wpdb;
	$payload = array( 'name' => 'Participant ' . $user_id, 'phone' => '555-0100', 'email' => get_user_by( 'id', $user_id )->user_email );
	RQE_Admin::checked( $wpdb->insert( RQE::table( 'participant_requests' ), array( 'event_id' => $event_id, 'user_id' => $user_id, 'status' => $status, 'payload' => wp_json_encode( $payload ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ) );
	return (int) $wpdb->insert_id;
}
RQE_Admin::checked( $wpdb->query( 'ALTER TABLE ' . RQE::table( 'events' ) . ' ADD self_registration_enabled INTEGER DEFAULT 0' ) );
RQE_Admin::checked( $wpdb->query( 'ALTER TABLE ' . RQE::table( 'riders' ) . " ADD insurance TEXT DEFAULT ''" ) );
RQE_Admin::checked( $wpdb->query( 'ALTER TABLE ' . RQE::table( 'riders' ) . " ADD other TEXT DEFAULT ''" ) );
foreach ( array( 'sender_email', 'provider', 'provider_message_id', 'provider_message_hash', 'email_subject', 'created_at' ) as $column ) {
	$columns = array_column( $wpdb->get_results( 'PRAGMA table_info(' . RQE::table( 'bonus_submissions' ) . ')', ARRAY_A ), 'name' );
	if ( ! in_array( $column, $columns, true ) ) { RQE_Admin::checked( $wpdb->query( 'ALTER TABLE ' . RQE::table( 'bonus_submissions' ) . ' ADD ' . $column . " TEXT DEFAULT ''" ) ); }
}
define( 'RQE_IMPORT_TEMP_DIR', sys_get_temp_dir() . '/rqe-participant-tests-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) ) );
if ( ! mkdir( RQE_IMPORT_TEMP_DIR, 0700 ) ) { throw new RuntimeException( 'Test private root could not be created.' ); }
$GLOBALS['participant_users'] = array( 1 => new WP_User( 1, 'admin@example.test', 'administrator' ), 2 => new WP_User( 2, 'existing@example.test', 'editor' ), 3 => new WP_User( 3, 'another@example.test' ) );
$GLOBALS['participant_meta'] = array();
$GLOBALS['participant_mail'] = array();
$GLOBALS['actor'] = 1;
$wpdb->update( RQE::table( 'events' ), array( 'hunt_style' => 'motorcycle', 'is_locked' => 0, 'is_archived' => 0, 'registration_open' => '2026-10-01 00:00:00', 'registration_close' => '2026-10-31 23:59:59', 'start_at' => '2026-10-01 00:00:00', 'end_at' => '2026-10-31 23:59:59' ), array( 'id' => 7 ) );
RQE_Participants::save_visibility( 7, true );
$wpdb->query( 'DELETE FROM ' . RQE::table( 'bonus_submissions' ) );
$wpdb->query( 'DELETE FROM ' . RQE::table( 'participant_requests' ) );
$wpdb->query( 'DELETE FROM ' . RQE::table( 'participant_links' ) );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

try {
	if ( 'cli-server' === PHP_SAPI ) {
		$id = participant_request( 2 );
		RQE_Participants::decide( 7, $id, true );
		$GLOBALS['actor'] = 2;
		$wpdb->insert( RQE::table( 'bonus_points' ), array( 'event_id' => 7, 'bonus_id' => 'WEB1', 'description' => 'Website fixture' ) );
		if ( '/health' === parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) ) {
			echo 'ready';
		} else {
			$token = RQE_Participants::submission_token( 7 );
			RQE_Participants::submit( 7, 'WEB1', 'Private web submission', $_FILES['proof'] ?? null, $token );
			$row = $wpdb->get_row( 'SELECT * FROM ' . RQE::table( 'bonus_submissions' ) . " WHERE provider='website'", ARRAY_A );
			check( $row && 'pending' === $row['status'] && 0 === (int) $row['points_awarded'] && 0 === strpos( $row['image_file'], 'p-' ), 'real multipart upload is pending with a private proof' );
			$path = RQE_Bonus::participant_image_path( 7, $row['image_file'] );
			check( is_file( $path ) && false === strpos( $path, wp_upload_dir()['basedir'] ), 'proof is outside public uploads' );
			check( strpos( RQE_Bonus::submission_image_url( 7, $row['image_file'] ), 'action=rqe_participant_image' ) !== false, 'proof uses authenticated serving endpoint' );
			clear_participant_rates();
			rejects( function () use ( $token ) { RQE_Participants::submit( 7, 'WEB1', '', $_FILES['proof'], $token ); }, 'already has' );
			$wpdb->update( RQE::table( 'bonus_submissions' ), array( 'status' => 'rejected' ), array( 'id' => $row['id'] ) );
			clear_participant_rates();
			rejects( function () use ( $token ) { RQE_Participants::submit( 7, 'WEB1', '', $_FILES['proof'], $token ); }, 'already been used' );
			$staged = RQE_Bonus::stage_submission_files( array( $row ) );
			check( ! is_file( $path ), 'private deletion stages proof outside web roots' );
			RQE_Bonus::restore_submission_files( $staged );
			check( is_file( $path ), 'failed deletion can restore private proof' );
		}
	} else {
		$input = array( 'name' => 'New Participant', 'email' => 'new@example.test', 'phone' => '', 'consent' => '1', 'website' => '' );
		$GLOBALS['actor'] = 0;
		RQE_Participants::register_request( 7, $input );
		$request = $wpdb->get_row( 'SELECT * FROM ' . RQE::table( 'participant_requests' ), ARRAY_A );
		$user = get_user_by( 'id', $request['user_id'] );
		check( 'pending' === $request['status'] && array( 'contributor' ) === $user->roles && get_user_meta( $user->ID, RQE_Participants::PENDING_META, true ), 'new Contributor account is pending before any login' );
		check( RQE_Participants::authenticate( $user ) instanceof WP_Error && 0 === RQE_Participants::current_user( $user->ID ), 'password authentication and cookie/REST identity reject pending accounts' );
		rejects( function () use ( $input ) { RQE_Participants::register_request( 7, $input ); }, 'wait' );
		preg_match( '/token=([a-f0-9]{64})/', $GLOBALS['participant_mail'][0][2], $matches );
		rejects( function () use ( $request ) { RQE_Participants::verify( $request['id'], str_repeat( '0', 64 ) ); }, 'invalid' );
		RQE_Participants::verify( $request['id'], $matches[1] );
		check( RQE_Participants::authenticate( $user ) instanceof WP_Error && 'verified' === RQE::row( 'participant_requests', $request['id'] )['status'], 'email verification alone does not unlock login' );
		rejects( function () use ( $request, $matches ) { RQE_Participants::verify( $request['id'], $matches[1] ); }, 'already used' );
		$GLOBALS['actor'] = 3;
		rejects( function () use ( $request ) { RQE_Participants::decide( 7, $request['id'], true ); }, 'not assigned' );
		$GLOBALS['actor'] = 1;
		RQE_Participants::decide( 7, $request['id'], true );
		check( ! get_user_meta( $user->ID, RQE_Participants::PENDING_META, true ) && RQE_Participants::authenticate( $user ) === $user && in_array( $user->ID, $GLOBALS['password_notifications'], true ), 'administrator approval unlocks login and sends password setup notification' );
		$GLOBALS['actor'] = $user->ID;
		$registration = RQE_Participants::enrollment( 7 );
		check( (int) $registration['bib'] > 0 && 0 === (int) $registration['score'] && ! RQE::access() && ! RQE::scoring_access( 7 ), 'approved Contributor receives Bib but no RQE administrative/scoring access' );
		$portal = RQE_Participant_Views::portal( array( 'id' => 'MC-7' ) );
		check( strpos( $portal, 'Submit a bonus' ) !== false && strpos( $portal, 'submission_token' ) !== false && strpos( $portal, 'cannot be edited' ) !== false, 'private portal includes append-only submit form and history' );
		$foreign_file = 'p-' . str_repeat( 'a', 32 ) . '.jpg';
		$wpdb->insert( RQE::table( 'bonus_submissions' ), array( 'event_id' => 7, 'rider_id' => 10, 'image_file' => $foreign_file, 'bonus_id' => 'OTHER', 'status' => 'pending' ) );
		$_GET = array( 'event_id' => '7', 'image_file' => $foreign_file );
		rejects( function () { RQE_Participants::image_handler(); }, 'another participant' );
		$wpdb->query( 'DELETE FROM ' . RQE::table( 'bonus_submissions' ) );
		rejects( function () { RQE_Participants::submit( 7, 'WEB1', '', null, 'bad-token' ); }, 'expired' );
		$token = RQE_Participants::submission_token( 7 );
		rejects( function () use ( $token ) { RQE_Participants::submit( 7, 'WEB1', '', array( 'tmp_name' => __FILE__, 'name' => 'proof.jpg', 'error' => 0 ), $token ); }, 'Upload one valid' );
		$GLOBALS['actor'] = 2;
		rejects( function () use ( $token ) { RQE_Participants::submit( 7, 'WEB1', '', null, $token ); }, 'Approved enrollment' );
		check( RQE_Participants::current_user( 2 ) === 2 && array( 'editor' ) === get_user_by( 'id', 2 )->roles, 'existing WordPress account retains login access and role' );
		rejects( function () { RQE_Participants::enrollment( 7 ); }, 'Approved enrollment' );
		clear_participant_rates();
		RQE_Participants::register_request( 7, array_merge( $input, array( 'email' => 'victim@example.test' ) ) );
		$existing = $wpdb->get_row( 'SELECT * FROM ' . RQE::table( 'participant_requests' ) . ' WHERE user_id=2', ARRAY_A );
		check( 'verified' === $existing['status'] && 'existing@example.test' === json_decode( $existing['payload'], true )['email'], 'existing logged-in user request binds to account email, never posted identity' );
		$GLOBALS['actor'] = 1;
		$wpdb->update( RQE::table( 'events' ), array( 'is_locked' => 1 ), array( 'id' => 7 ) );
		rejects( function () use ( $existing ) { RQE_Participants::decide( 7, $existing['id'], true ); }, 'locked' );
		$wpdb->update( RQE::table( 'events' ), array( 'is_locked' => 0, 'max_bib' => 1 ), array( 'id' => 7 ) );
		rejects( function () use ( $existing ) { RQE_Participants::decide( 7, $existing['id'], true ); }, 'No event bib' );
		check( 'verified' === RQE::row( 'participant_requests', $existing['id'] )['status'], 'capacity failure rolls back account links and approval' );
		$wpdb->update( RQE::table( 'events' ), array( 'max_bib' => 100 ), array( 'id' => 7 ) );
		RQE_Participants::decide( 7, $existing['id'], false );
		check( array( 'editor' ) === get_user_by( 'id', 2 )->roles && RQE_Participants::current_user( 2 ) === 2, 'event rejection does not deactivate existing users or change roles' );
		$pending = participant_request( 3, 'pending' );
		rejects( function () use ( $pending ) { RQE_Participants::decide( 7, $pending, true ); }, 'verification' );
		$GLOBALS['participant_time'] = '2026-12-01 00:00:00';
		rejects( function () { RQE_Participants::event( 7, true ); }, 'outside' );
		unset( $GLOBALS['participant_time'] );
		$GLOBALS['actor'] = 0;
		clear_participant_rates();
		$users_before = count( $GLOBALS['participant_users'] );
		$response = RQE_Participants::register_request( 7, array_merge( $input, array( 'email' => 'existing@example.test' ) ) );
		check( strpos( $response, 'sign in' ) !== false && $users_before === count( $GLOBALS['participant_users'] ), 'anonymous existing-email registration never links or changes an existing account' );
		$GLOBALS['actor'] = 1;
		RQE_Participants::deleted_user( $user->ID );
		check( ! $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . RQE::table( 'participant_links' ) . ' WHERE user_id=%d', $user->ID ) ), 'deleted account revokes plugin ownership without deleting scoring records' );
		$wpdb->update( RQE::table( 'events' ), array( 'self_registration_enabled' => 0 ), array( 'id' => 7 ) );
		check( strpos( RQE_Participant_Views::registration( array( 'id' => 'MC-7' ) ), 'not enabled' ) !== false, 'registration shortcode requires explicit event opt-in' );
		check( strpos( RQE_Participant_Views::portal( array( 'id' => 'HB-7' ) ), 'not enabled' ) !== false, 'disabled portal does not reveal private history' );
		$guard = new ReflectionMethod( 'RQE_Participants', 'post_guard' );
		if ( PHP_VERSION_ID < 80100 ) { $guard->setAccessible( true ); }
		$GLOBALS['actor'] = 0;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_COOKIE['rqe_registration_1'] = str_repeat( 'b', 64 );
		$_POST = array( 'guest_token' => 'wrong' );
		rejects( function () use ( $guard ) { $guard->invoke( null, 'rqe_participant_register', true ); }, 'session expired' );
		$_POST['guest_token'] = RQE_Participants::guest_token();
		$guard->invoke( null, 'rqe_participant_register', true );
		check( true, 'anonymous registration requires both a WordPress nonce and browser-specific CSRF proof' );
		$GLOBALS['bad_participant_nonce'] = true;
		rejects( function () use ( $guard ) { $guard->invoke( null, 'rqe_participant_register', true ); }, 'Invalid nonce' );
		unset( $GLOBALS['bad_participant_nonce'] );
		echo 'Participant account and portal tests passed.' . PHP_EOL;
	}
} catch ( RuntimeException $error ) {
	if ( 'cli-server' !== PHP_SAPI ) { throw $error; }
	http_response_code( 400 );
	echo $error->getMessage();
} finally {
	RQE_Bonus::remove_participant_storage();
	foreach ( glob( RQE_IMPORT_TEMP_DIR . '/rqe-bonus-*' ) as $directory ) {
		if ( is_dir( $directory ) ) { throw new RuntimeException( 'A test image staging directory was leaked.' ); }
	}
	rmdir( RQE_IMPORT_TEMP_DIR );
}
