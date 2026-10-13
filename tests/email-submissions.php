<?php
/** Standalone email-submission regression tests: php tests/email-submissions.php */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-event-state.php';

final class Email_Submission_Test_DB {
	public $last_error = '';
	public $active_transaction = false;
	public $tables = array(
		'events' => array( 7 => array( 'id' => 7, 'is_locked' => 0, 'is_archived' => 0 ) ),
		'event_email_settings' => array(),
		'bonus_points' => array(
			array( 'event_id' => 7, 'bonus_id' => 'B12', 'points' => 125 ),
			array( 'event_id' => 7, 'bonus_id' => 'B13', 'points' => 50 ),
		),
		'bonus_submissions' => array(
			1 => array( 'id' => 1, 'event_id' => 7, 'registration_id' => 4, 'rider_id' => 9, 'bonus_id' => 'B12', 'status' => 'pending' ),
			2 => array( 'id' => 2, 'event_id' => 7, 'registration_id' => 4, 'rider_id' => 9, 'bonus_id' => 'B13', 'status' => 'pending' ),
		),
		'registrations' => array( 4 => array( 'id' => 4, 'event_id' => 7, 'rider_id' => 9, 'score' => 10 ) ),
	);
	public $snapshot;
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function get_row( $query, $format ) {
		$sql = $query['sql'];
		$args = $query['args'];
		if ( strpos( $sql, 'FROM events' ) !== false ) {
			return $this->tables['events'][ (int) $args[0] ] ?? null;
		}
		if ( strpos( $sql, 'FROM event_email_settings' ) !== false ) {
			return $this->tables['event_email_settings'][ (int) $args[0] ] ?? null;
		}
		if ( strpos( $sql, 'FROM bonus_submissions' ) !== false ) {
			$row = $this->tables['bonus_submissions'][ (int) $args[0] ] ?? null;
			return $row && (int) $row['event_id'] === (int) $args[1] ? $row : null;
		}
		return null;
	}
	public function get_var( $query ) {
		$sql = $query['sql'];
		$args = $query['args'];
		if ( strpos( $sql, 'SELECT event_id FROM event_email_settings' ) !== false ) {
			return isset( $this->tables['event_email_settings'][ (int) $args[0] ] ) ? $args[0] : null;
		}
		if ( strpos( $sql, "status='accepted'" ) !== false ) {
			foreach ( $this->tables['bonus_submissions'] as $row ) {
				if ( (int) $row['event_id'] === (int) $args[0] && (int) $row['rider_id'] === (int) $args[1] && $row['bonus_id'] === $args[2] && 'accepted' === $row['status'] && (int) $row['id'] !== (int) $args[3] ) {
					return $row['id'];
				}
			}
			return null;
		}
		if ( strpos( $sql, 'SELECT points FROM bonus_points' ) !== false ) {
			foreach ( $this->tables['bonus_points'] as $row ) {
				if ( (int) $row['event_id'] === (int) $args[0] && $row['bonus_id'] === $args[1] ) { return $row['points']; }
			}
			return null;
		}
		if ( strpos( $sql, 'SELECT id FROM registrations' ) !== false ) {
			$row = $this->tables['registrations'][ (int) $args[0] ] ?? null;
			return $row && (int) $row['event_id'] === (int) $args[1] && (int) $row['rider_id'] === (int) $args[2] ? $row['id'] : null;
		}
		return null;
	}
	public function get_col( $query ) {
		$args = $query['args'];
		return array_values( array_map( function ( $row ) { return $row['bonus_id']; }, array_filter( $this->tables['bonus_points'], function ( $row ) use ( $args ) { return (int) $row['event_id'] === (int) $args[0]; } ) ) );
	}
	public function insert( $table, $data ) {
		if ( 'event_email_settings' === $table ) {
			$this->tables[ $table ][ (int) $data['event_id'] ] = array_merge( array( 'last_checked_at' => null, 'last_error' => '' ), $data );
			return 1;
		}
		if ( 'bonus_submissions' === $table ) {
			if ( 'poll-second' === $data['provider_message_id'] ) { return false; }
			$this->tables[ $table ][] = $data;
			return 1;
		}
		return false;
	}
	public function update( $table, $data, $where ) {
		if ( 'event_email_settings' === $table ) {
			$id = (int) $where['event_id'];
			$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $data );
			return 1;
		}
		if ( 'bonus_submissions' === $table ) {
			$id = (int) $where['id'];
			if ( ! isset( $this->tables[ $table ][ $id ] ) || $this->tables[ $table ][ $id ]['status'] !== $where['status'] ) { return 0; }
			$this->tables[ $table ][ $id ] = array_merge( $this->tables[ $table ][ $id ], $data );
			return 1;
		}
		return false;
	}
	public function delete( $table, $where ) {
		if ( 'event_email_settings' !== $table ) { return false; }
		$id = (int) $where['event_id'];
		if ( ! isset( $this->tables[ $table ][ $id ] ) ) { return 0; }
		unset( $this->tables[ $table ][ $id ] );
		return 1;
	}
	public function query( $query ) {
		if ( 'START TRANSACTION' === $query ) { $this->snapshot = $this->tables; $this->active_transaction = true; return 1; }
		if ( 'COMMIT' === $query ) { $this->active_transaction = false; return 1; }
		if ( 'ROLLBACK' === $query ) { $this->tables = $this->snapshot; $this->active_transaction = false; return 1; }
		if ( is_array( $query ) && strpos( $query['sql'], 'UPDATE registrations SET score=score+' ) === 0 ) {
			$points = (int) $query['args'][0];
			$id = (int) $query['args'][1];
			$this->tables['registrations'][ $id ]['score'] += $points;
			return 1;
		}
		return false;
	}
}

final class RQE {
	public static function site_access() { return $GLOBALS['email_site_admin'] ?? true; }
	public static function table( $name ) { return $name; }
	public static function row( $table, $id ) { return $GLOBALS['wpdb']->tables[ $table ][ (int) $id ] ?? null; }
	public static function begin_transaction() { global $wpdb; $wpdb->query( 'START TRANSACTION' ); }
	public static function commit_transaction() { global $wpdb; $wpdb->query( 'COMMIT' ); }
	public static function rollback_transaction() { global $wpdb; return false !== $wpdb->query( 'ROLLBACK' ); }
}
function wp_salt( $scheme ) { return 'email-submission-test-salt'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ); }
function sanitize_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? strtolower( $value ) : ''; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function get_current_user_id() { return 22; }
function get_option( $key, $default = false ) { return $GLOBALS['email_options'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $key, $GLOBALS['email_options'] ?? array() ) ) { return false; }
	$GLOBALS['email_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) { unset( $GLOBALS['email_options'][ $key ] ); return true; }
function wp_safe_remote_request( $url, $args ) {
	$GLOBALS['email_test_requests'][] = $url;
	if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'access_token' => 'test-access-token' ) ) );
	}
	if ( false !== strpos( $url, '/profile' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'emailAddress' => 'submissions@example.test' ) ) );
	}
	if ( ! empty( $GLOBALS['poll_fixture'] ) && false !== strpos( $url, '/messages?' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"messages":[{"id":"poll-first"},{"id":"poll-second"}]}' );
	}
	if ( ! empty( $GLOBALS['poll_fixture'] ) && false !== strpos( $url, '/modify' ) ) {
		check( ! $GLOBALS['wpdb']->active_transaction, 'mailbox messages are marked processed only after database commit' );
		$GLOBALS['marked'][] = $url;
		return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
	if ( ! empty( $GLOBALS['poll_fixture'] ) && false !== strpos( $url, '?format=full' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"payload":{"headers":[]}}' );
	}
	return array( 'response' => array( 'code' => 404 ), 'body' => '{}' );
}
function is_wp_error( $value ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }

require __DIR__ . '/../rally-quest-engine/includes/class-rqe-email-submissions.php';

function check( $condition, $label ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . PHP_EOL;
}
function invoke_private( $name, ...$args ) {
	$method = new ReflectionMethod( 'RQE_Email_Submissions', $name );
	if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
	return $method->invoke( null, ...$args );
}

$wpdb = new Email_Submission_Test_DB();
RQE_Email_Submissions::save_configuration( 7, array(
	'provider' => 'imap',
	'enabled' => '1',
	'mailbox' => 'submissions@example.test',
	'host' => 'imap.example.test',
	'port' => '993',
	'encryption' => 'ssl',
	'username' => 'submissions@example.test',
	'folder' => 'INBOX',
	'password' => 'first-secret',
) );
$first_ciphertext = $wpdb->tables['event_email_settings'][7]['encrypted_settings'];
$config = RQE_Email_Submissions::configuration( 7, true );
check( 'first-secret' === $config['settings']['password'] && false === strpos( $first_ciphertext, 'first-secret' ), 'event inbox credentials are encrypted at rest and decrypt correctly' );
RQE_Email_Submissions::save_configuration( 7, array(
	'provider' => 'imap',
	'enabled' => '1',
	'mailbox' => 'submissions@example.test',
	'host' => 'imap.example.test',
	'port' => '993',
	'encryption' => 'ssl',
	'username' => 'submissions@example.test',
	'folder' => 'INBOX',
	'password' => '',
) );
check( 'first-secret' === RQE_Email_Submissions::configuration( 7, true )['settings']['password'], 'blank secret fields preserve the saved credential' );
$original_settings = $wpdb->tables['event_email_settings'][7];
$imap_input = array( 'provider' => 'imap', 'enabled' => '1', 'mailbox' => 'submissions@example.test', 'host' => 'imap.example.test', 'port' => '993', 'encryption' => 'ssl', 'username' => 'submissions@example.test', 'folder' => 'INBOX', 'password' => '' );
foreach ( array( 'host' => 'attacker.example.test', 'port' => '143', 'encryption' => 'none', 'username' => 'other@example.test' ) as $key => $value ) {
	try {
		RQE_Email_Submissions::save_configuration( 7, array_merge( $imap_input, array( $key => $value ) ) );
		throw new RuntimeException( 'Changed credential destination unexpectedly succeeded.' );
	} catch ( RuntimeException $exception ) {
		check( false !== strpos( $exception->getMessage(), 'required when the server or account changes' ) && $wpdb->tables['event_email_settings'][7] === $original_settings, 'changing IMAP ' . $key . ' cannot reuse or overwrite saved credentials' );
	}
}
$GLOBALS['email_site_admin'] = false;
foreach ( array( 'save', 'test' ) as $operation ) {
	try {
		if ( 'save' === $operation ) { RQE_Email_Submissions::save_configuration( 7, $imap_input ); } else { RQE_Email_Submissions::test_connection( 7 ); }
		throw new RuntimeException( 'Non-administrator mailbox operation unexpectedly succeeded.' );
	} catch ( RuntimeException $exception ) {
		check( false !== strpos( $exception->getMessage(), 'Only WordPress administrators' ), 'event administrators cannot ' . $operation . ' mailbox credentials' );
	}
}
$GLOBALS['email_site_admin'] = true;
$status = RQE_Email_Submissions::configuration_status( 7 );
check( $status['configured'] && ! $status['connected'] && 'IMAP' === $status['provider_label'], 'a complete saved inbox is displayed but remains unverified before a connection test' );
try {
	RQE_Email_Submissions::save_configuration( 7, array(
		'provider' => 'gmail',
		'enabled' => '1',
		'mailbox' => 'submissions@example.test',
		'client_id' => 'gmail-client-id',
		'client_secret' => '',
		'refresh_token' => '',
	) );
	throw new RuntimeException( 'Incomplete Gmail configuration unexpectedly succeeded.' );
} catch ( RuntimeException $exception ) {
	check( false !== strpos( $exception->getMessage(), 'OAuth client secret' ), 'Gmail setup identifies the exact missing OAuth credential' );
}
RQE_Email_Submissions::save_configuration( 7, array(
	'provider' => 'gmail',
	'enabled' => '1',
	'mailbox' => 'submissions@example.test',
	'client_id' => 'gmail-client-id',
	'client_secret' => 'gmail-client-secret',
	'refresh_token' => 'gmail-refresh-token',
) );
check( false !== strpos( RQE_Email_Submissions::test_connection( 7 ), 'passed' ), 'the email inbox test authenticates without processing messages' );
check( in_array( 'https://gmail.googleapis.com/gmail/v1/users/me/profile', $GLOBALS['email_test_requests'], true ), 'Gmail connection tests address the authenticated OAuth account' );
$status = RQE_Email_Submissions::configuration_status( 7 );
check( $status['connected'] && 'Gmail API (OAuth)' === $status['provider_label'] && $status['last_tested_at'], 'a successful current connection test marks the inbox as connected' );
	$wpdb->tables['events'][8] = array( 'id' => 8, 'name' => 'Global Mail Event', 'is_locked' => 0, 'is_archived' => 0 );
	RQE_Email_Submissions::save_configuration( 0, array(
		'provider' => 'gmail',
		'enabled' => '1',
		'mailbox' => 'global@example.test',
		'client_id' => 'global-client-id',
		'client_secret' => 'global-client-secret',
		'refresh_token' => 'global-refresh-token',
	) );
	$global_ciphertext = $wpdb->tables['event_email_settings'][0]['encrypted_settings'];
	check( false === strpos( $global_ciphertext, 'global-client-secret' ) && RQE_Email_Submissions::configuration( 0, true )['settings']['client_secret'] === 'global-client-secret', 'global credentials use the existing encrypted mailbox storage and remain exportable with the plugin table' );
	RQE_Email_Submissions::save_global_assignment( 8 );
	check( 8 === RQE_Email_Submissions::global_status()['assigned_event_id'] && ! RQE_Email_Submissions::global_status()['connected'], 'global assignment is explicit and saving it invalidates the prior connection verification' );
	RQE_Email_Submissions::test_connection( 0 );
	check( RQE_Email_Submissions::global_status()['connected'] && RQE_Email_Submissions::global_status()['assigned_event_active'], 'the global inbox is verified against its provider and its active assigned event' );
	$wpdb->tables['event_email_settings'][8] = $wpdb->tables['event_email_settings'][7];
	$before = count( $GLOBALS['email_test_requests'] );
	check( false !== strpos( invoke_private( 'process_global_event', 8 ), 'override takes precedence' ) && $before === count( $GLOBALS['email_test_requests'] ), 'global inbox leaves unread messages untouched when the assigned event has an explicit override' );
	unset( $wpdb->tables['event_email_settings'][8] );
	$wpdb->tables['events'][8]['is_locked'] = 1;
	try {
		RQE_Email_Submissions::save_global_assignment( 8 );
		throw new RuntimeException( 'A locked event accepted the global inbox.' );
	} catch ( RuntimeException $exception ) {
		check( false !== strpos( $exception->getMessage(), 'unlocked, unarchived' ), 'global inbox cannot be assigned to a locked event' );
	}
	$wpdb->tables['events'][8]['is_locked'] = 0;
	$GLOBALS['email_options']['rqe_submission_poll_lock_7'] = time() . ':' . str_repeat( 'a', 32 );
	try {
		RQE_Email_Submissions::save_configuration( 7, array_merge( $imap_input, array( 'password' => 'poll-password' ) ) );
		throw new RuntimeException( 'An inbox configuration changed during a poll.' );
	} catch ( RuntimeException $exception ) {
		check( false !== strpos( $exception->getMessage(), 'being checked' ), 'inbox credentials cannot change while that source is being polled' );
	}
	try {
		RQE_Email_Submissions::remove_event_override( 7 );
		throw new RuntimeException( 'An event inbox override was removed during a poll.' );
	} catch ( RuntimeException $exception ) {
		check( false !== strpos( $exception->getMessage(), 'being checked' ), 'an event inbox override cannot be removed while that source is being polled' );
	}
	try {
		RQE_Email_Submissions::test_connection( 7 );
		throw new RuntimeException( 'A mailbox connection test started during a poll.' );
	} catch ( RuntimeException $exception ) {
		check( false !== strpos( $exception->getMessage(), 'being checked' ), 'connection tests cannot verify credentials while that inbox is being polled' );
	}
	unset( $GLOBALS['email_options']['rqe_submission_poll_lock_7'] );
check( RQE_Email_Submissions::has_event_override( 7 ), 'a saved event inbox remains an explicit override until an administrator removes it' );
check( 'B12' === invoke_private( 'subject_bonus_id', 7, 'Rider submission B12' ), 'one subject bonus code resolves to its library ID' );
check( '' === invoke_private( 'subject_bonus_id', 7, 'B12 and B13' ), 'multiple subject bonus codes are rejected' );
check( '' === invoke_private( 'subject_bonus_id', 7, 'B120' ), 'partial bonus-code matches are rejected' );

$message = RQE_Email_Submissions::review( 7, 1, 'accepted' );
check( 135 === $wpdb->tables['registrations'][4]['score'] && 'accepted' === $wpdb->tables['bonus_submissions'][1]['status'] && 125 === $wpdb->tables['bonus_submissions'][1]['points_awarded'], 'accepting awards the library points and records the review' );
try {
	RQE_Email_Submissions::review( 7, 1, 'accepted' );
	throw new RuntimeException( 'Second acceptance unexpectedly succeeded.' );
} catch ( RuntimeException $exception ) {
	check( 135 === $wpdb->tables['registrations'][4]['score'] && false !== strpos( $exception->getMessage(), 'no longer pending' ), 'an accepted submission cannot award points twice' );
}
try {
	RQE_Email_Submissions::review( 7, 2, 'rejected', 'other', '' );
	throw new RuntimeException( 'Other rejection without description unexpectedly succeeded.' );
} catch ( RuntimeException $exception ) {
	check( 'pending' === $wpdb->tables['bonus_submissions'][2]['status'], 'Other rejection requires a description before changing state' );
}
RQE_Email_Submissions::review( 7, 2, 'rejected', 'wrong_image', 'The submitted motorcycle is not at the library location.' );
check( 'rejected' === $wpdb->tables['bonus_submissions'][2]['status'] && 0 === $wpdb->tables['bonus_submissions'][2]['points_awarded'] && 135 === $wpdb->tables['registrations'][4]['score'], 'rejecting records the reason without changing the rider score' );
$wpdb->tables['events'][7]['is_locked'] = 1;
$before = count( $GLOBALS['email_test_requests'] );
try {
	RQE_Email_Submissions::process_event( 7 );
	throw new LogicException( 'Locked polling unexpectedly succeeded.' );
} catch ( RuntimeException $exception ) {
	check( false !== strpos( $exception->getMessage(), 'locked or archived' ) && $before === count( $GLOBALS['email_test_requests'] ), 'locked inbox polling does not fetch or consume provider messages' );
}
try {
	RQE_Email_Submissions::review( 7, 2, 'accepted' );
	throw new LogicException( 'Locked review unexpectedly succeeded.' );
} catch ( RuntimeException $exception ) {
	check( false !== strpos( $exception->getMessage(), 'locked or archived' ) && 135 === $wpdb->tables['registrations'][4]['score'], 'locked events reject submission score changes' );
}
$wpdb->tables['events'][7]['is_locked'] = 0;
$GLOBALS['poll_fixture'] = true;
$GLOBALS['marked'] = array();
try {
	RQE_Email_Submissions::process_event( 7 );
	throw new LogicException( 'Second message insertion unexpectedly succeeded.' );
} catch ( RuntimeException $exception ) {
	check( false !== strpos( $exception->getMessage(), 'could not be recorded' ) && count( $GLOBALS['marked'] ) === 1 && count( $wpdb->tables['bonus_submissions'] ) === 3, 'later intake failures retain committed earlier messages without consuming the failing message' );
}
check( false !== strpos( RQE_Email_Submissions::remove_event_override( 7 ), 'global inbox will be used only if it is assigned' ) && ! RQE_Email_Submissions::has_event_override( 7 ), 'removing an event override leaves the global inbox subject to explicit assignment' );
