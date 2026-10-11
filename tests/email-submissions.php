<?php
/** Standalone email-submission regression tests: php tests/email-submissions.php */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

final class Email_Submission_Test_DB {
	public $tables = array(
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
	public function query( $query ) {
		if ( 'START TRANSACTION' === $query ) { $this->snapshot = $this->tables; return 1; }
		if ( 'COMMIT' === $query ) { return 1; }
		if ( 'ROLLBACK' === $query ) { $this->tables = $this->snapshot; return 1; }
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
	public static function table( $name ) { return $name; }
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
function wp_safe_remote_request( $url, $args ) {
	$GLOBALS['email_test_requests'][] = $url;
	if ( false !== strpos( $url, 'oauth2.googleapis.com/token' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'access_token' => 'test-access-token' ) ) );
	}
	if ( false !== strpos( $url, '/profile' ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'emailAddress' => 'submissions@example.test' ) ) );
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
