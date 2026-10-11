<?php
/** Standalone import regression tests: php tests/bonus-import.php */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
// Locking is covered by the SQL-backed event suite; this fixture isolates import rollback.
final class RQE_Event_State { public static function writable( $id ) {} }
$check_control = tempnam( sys_get_temp_dir(), 'rqe-check-test' );
$scanner = proc_open( array( PHP_BINARY, __DIR__ . '/clamav-fixture.php', $check_control ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( ! is_resource( $scanner ) ) {
	throw new RuntimeException( 'The scanner protocol fixture could not start.' );
}
define( 'RQE_CLAMAV_SOCKET', trim( fgets( $pipes[1] ) ) );

final class RQE_Admin {
	public static function posted( $key ) {
		return $_POST[ $key ] ?? '';
	}
}

final class RQE {
	public static function table( $name ) {
		return $name;
	}
	public static function begin_transaction() {
		$GLOBALS['wpdb']->snapshot = $GLOBALS['wpdb']->rows;
	}
	public static function commit_transaction() {}
	public static function rollback_transaction() {
		$GLOBALS['wpdb']->rows = $GLOBALS['wpdb']->snapshot;
		return true;
	}
}

final class Bonus_Test_DB {
	public $rows = array();
	public $audit_rows = array();
	public $fail_audit = false;
	public $snapshot = array();
	public $insert_id = 0;
	public $fail_id = '';
	public function prepare( $sql, ...$args ) {
		if ( false !== strpos( $sql, 'import_audit' ) ) {
			return array( 'audit', $args[0] );
		}
		return $args;
	}
	public function get_row( $args, $format ) {
		foreach ( $this->rows as $row ) {
			if ( $row['event_id'] === $args[0] && strtolower( $row['bonus_id'] ) === strtolower( $args[1] ) ) {
				return $row;
			}
		}
		return null;
	}
	public function get_var( $args ) {
		if ( 'audit' === $args[0] ) {
			foreach ( array_reverse( $this->audit_rows ) as $row ) {
				if ( $row['user_id'] === $args[1] && in_array( $row['action'], array( 'bypass_enabled', 'bypass_disabled' ), true ) ) {
					return $row['action'];
				}
			}
			return null;
		}
		$row = $this->get_row( $args, ARRAY_A );
		return $row ? $row['id'] : null;
	}
	public function insert( $table, $data ) {
		if ( 'import_audit' === $table ) {
			if ( $this->fail_audit ) {
				return false;
			}
			$this->audit_rows[] = $data;
			return 1;
		}
		$data['id'] = ++$this->insert_id;
		$data['image_file'] = '';
		$this->rows[ $data['id'] ] = $data;
		return 1;
	}
	public function update( $table, $data, $where ) {
		$row = $this->rows[ $where['id'] ];
		if ( $row['bonus_id'] === $this->fail_id ) {
			return false;
		}
		$this->rows[ $where['id'] ] = array_merge( $row, $data );
		return 1;
	}
}

function wp_upload_dir() {
	return array( 'basedir' => $GLOBALS['test_root'] );
}
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function absint( $value ) { return abs( (int) $value ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function wp_generate_password( $length, $special, $extra ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
function esc_url_raw( $url ) { return $url; }
function wp_http_validate_url( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_safe_remote_get( $url, $options ) {
	return $GLOBALS['responses'][ $url ] ?? array( 'code' => 404, 'body' => '', 'type' => 'text/plain' );
}
function is_wp_error( $response ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $header ) { return 'content-type' === $header ? $response['type'] : ''; }
function get_temp_dir() { return sys_get_temp_dir(); }
function wp_tempnam( $name ) { return tempnam( sys_get_temp_dir(), $name ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function get_current_user_id() { return $GLOBALS['test_user'] ?? 1; }
function wp_get_current_user() { return (object) array( 'ID' => get_current_user_id(), 'user_login' => 'admin-' . get_current_user_id() ); }
function set_transient( $key, $value, $expiry ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); return true; }
function wp_schedule_single_event( $expiry, $hook, $args, $error ) { $GLOBALS['cleanup'][] = $args; return true; }
function sanitize_textarea_field( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }

require __DIR__ . '/../rally-quest-engine/includes/class-rqe-file-scan.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-spreadsheet-reader.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-import-audit.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-import-adapters.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-bonus.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-bonus-application-service.php';

final class Bonus_Tracking_Pending_Store {
	public $values = array();
	public $calls = array();
	public function get( $key ) { $this->calls[] = 'get'; return $this->values[ $key ] ?? false; }
	public function put( $key, $value, $expires ) { $this->calls[] = 'put'; $this->values[ $key ] = $value; return true; }
	public function remove( $key ) { $this->calls[] = 'remove'; unset( $this->values[ $key ] ); return true; }
}
final class Bonus_Tracking_Scheduler {
	public $calls = array();
	public function schedule( $when, $hook, $args ) { $this->calls[] = array( $when, $hook, $args ); return true; }
}

function check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	echo 'PASS: ' . $label . PHP_EOL;
}

function reviewed_import( $event_id ) {
	$before = $GLOBALS['wpdb']->rows;
	$prepared = RQE_Bonus::import( $event_id );
	check( $before === $GLOBALS['wpdb']->rows, 'scanning changes no event database records' );
	$pending = RQE_Bonus::pending( $event_id, $prepared['pending_token'] );
	if ( ! $pending['can_approve'] ) {
		RQE_Bonus::approve( $event_id, $prepared['pending_token'], true );
		if ( empty( $_POST['allow_partial_images'] ) ) {
			throw new RuntimeException( implode( '; ', $pending['errors'] ) );
		}
		return array( 'message' => '0 event gallery images', 'errors' => $pending['errors'] );
	}
	return RQE_Bonus::approve( $event_id, $prepared['pending_token'] );
}

$GLOBALS['test_root'] = __DIR__ . '/.bonus-import-' . bin2hex( random_bytes( 6 ) );
$wpdb = new Bonus_Test_DB();
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+afo0AAAAASUVORK5CYII=' );
$GLOBALS['responses'] = array(
	'https://example.com/gallery/' => array(
		'code' => 200, 'type' => 'text/html',
		'body' => '<a href="GOOD.png">good</a><a href="INVALID.png">invalid</a><a href="MISSING.png">missing</a><a href="LAST.png">last</a>',
	),
	'https://example.com/gallery/GOOD.png' => array( 'code' => 200, 'type' => 'image/png', 'body' => $png ),
	'https://example.com/gallery/LAST.png' => array( 'code' => 200, 'type' => 'image/png', 'body' => $png ),
	'https://example.com/gallery/INVALID.png' => array( 'code' => 200, 'type' => 'image/png', 'body' => 'not an image' ),
);
$_FILES = array();
$_POST = array( 'import_step' => 'images', 'gallery_url' => 'https://example.com/gallery/', 'allow_partial_images' => '1' );
$original_post = $_POST;
try {
	$_POST['image_source'] = 'url';
	$_POST['gallery_url'] = '';
	try {
		RQE_Bonus::import( 7 );
		throw new RuntimeException( 'Empty URL unexpectedly accepted' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'Enter a public HTTPS' ) !== false, 'Load via URL requires a URL explicitly' );
	}
	$_POST['gallery_url'] = 'https://example.com/gallery/GOOD.png';
	$_FILES['bonus_images'] = array( 'name' => array( 'LOCAL.png' ), 'tmp_name' => array( '' ), 'error' => array( UPLOAD_ERR_INI_SIZE ), 'size' => array( 0 ) );
	$prepared = RQE_Bonus::import( 7 );
	$pending = RQE_Bonus::pending( 7, $prepared['pending_token'] );
	check( $pending['can_approve'] && 1 === count( $pending['images'] ) && ! $pending['errors'], 'Load via URL uses remote source only and retains scan approval' );
	RQE_Bonus::approve( 7, $prepared['pending_token'], true );
	$_FILES = array();
	$pending_store = new Bonus_Tracking_Pending_Store();
	$scheduler = new Bonus_Tracking_Scheduler();
	RQE_Bonus::configure_adapters( $pending_store, $scheduler );
	$_POST = array( 'import_step' => 'listing', 'image_source' => 'invalid', 'gallery_url' => '' );
	$explicit = array(
		'import_step' => 'images',
		'image_source' => 'url',
		'gallery_url' => 'https://example.com/gallery/GOOD.png',
		'allow_partial_images' => '',
		'files' => array(),
	);
	$prepared = ( new RQE_Bonus_Application_Service() )->import( 7, $explicit );
	$pending = RQE_Bonus::pending( 7, $prepared['pending_token'] );
	check( 'images' === $pending['import_step'] && in_array( 'put', $pending_store->calls, true ), 'application service consumes explicit form data and injected pending-review persistence' );
	check( 'rqe_bonus_expire' === $scheduler->calls[0][1], 'actual bonus import schedules cleanup through injected scheduler' );
	RQE_Bonus::approve( 7, $prepared['pending_token'], true );
	RQE_Bonus::configure_adapters();
	$_POST = $original_post;
	unset( $_POST['image_source'] );
	$_POST['gallery_url'] = 'https://example.com/gallery/';
	$connection = RQE_File_Scan::check_connection();
	check( $connection['passed'] && array() === $wpdb->rows, 'Step 0 connection check performs an actual scan without importing data' );
	foreach ( array( 'ERROR', 'TRUNCATED' ) as $mode ) {
		file_put_contents( $check_control, $mode );
		$connection = RQE_File_Scan::check_connection();
		check( ! $connection['passed'] && array() === $wpdb->rows, 'Step 0 connection check rejects scanner response without importing data: ' . $mode );
	}
	file_put_contents( $check_control, '' );
	( new RQE_Import_Audit( $wpdb ) )->record( 7, 'bypass_enabled', 'Test consent' );
	$prepared = RQE_Bonus::import( 7 );
	$pending = RQE_Bonus::pending( 7, $prepared['pending_token'] );
	check( empty( $pending['unscanned'] ) && $pending['can_approve'], 'remembered bypass still requires scan review when ClamAV is available' );
	RQE_Bonus::approve( 7, $prepared['pending_token'], true );
	$result = reviewed_import( 7 );
	check( count( $wpdb->rows ) === 2 && count( $result['errors'] ) === 2, 'partial import retains two valid images and reports two failures' );
	check( strpos( $result['errors'][0], 'INVALID.png' ) !== false && strpos( $result['errors'][1], 'HTTP 404' ) !== false, 'errors identify filename and reason' );
	check( is_file( $GLOBALS['test_root'] . '/rqe-event-gallery/7/GOOD.png' ), 'valid image stored in selected event gallery' );

	$_POST['allow_partial_images'] = '';
	try {
		reviewed_import( 8 );
		throw new RuntimeException( 'Strict import unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'INVALID.png' ) !== false, 'strict import stops on named invalid image' );
	}
	check( count( $wpdb->rows ) === 2 && ! is_dir( $GLOBALS['test_root'] . '/rqe-event-gallery/8' ), 'strict staging failure saves no images or records' );

	$_POST['allow_partial_images'] = '1';
	$wpdb->fail_id = 'GOOD';
	$result = reviewed_import( 9 );
	check( count( $wpdb->rows ) === 3 && count( $result['errors'] ) === 3, 'database failure rolls back only failed image and continues' );
	check( ! is_file( $GLOBALS['test_root'] . '/rqe-event-gallery/9/GOOD.png' ) && is_file( $GLOBALS['test_root'] . '/rqe-event-gallery/9/LAST.png' ), 'failed database write removes its installed image' );

	$GLOBALS['responses']['https://example.com/valid/'] = array(
		'code' => 200, 'type' => 'text/html',
		'body' => '<a href="../gallery/GOOD.png">good</a><a href="../gallery/LAST.png">last</a>',
	);
	$GLOBALS['responses']['https://example.com/valid/../gallery/GOOD.png'] = $GLOBALS['responses']['https://example.com/gallery/GOOD.png'];
	$GLOBALS['responses']['https://example.com/valid/../gallery/LAST.png'] = $GLOBALS['responses']['https://example.com/gallery/LAST.png'];
	$_POST['gallery_url'] = 'https://example.com/valid/';
	$_POST['allow_partial_images'] = '';
	$wpdb->fail_id = 'LAST';
	try {
		reviewed_import( 12 );
		throw new RuntimeException( 'Strict database failure unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'could not be linked' ) !== false, 'strict database failure surfaces a linking error' );
	}
	check( count( $wpdb->rows ) === 3 && ! is_file( $GLOBALS['test_root'] . '/rqe-event-gallery/12/GOOD.png' ), 'strict database failure rolls back the entire image batch' );

	$_POST['allow_partial_images'] = '1';
	$GLOBALS['responses']['https://example.com/duplicates/'] = array(
		'code' => 200, 'type' => 'text/html',
		'body' => '<a href="../gallery/GOOD.png">good</a><a href="../gallery/GOOD.png?again=1">duplicate</a>',
	);
	$GLOBALS['responses']['https://example.com/duplicates/../gallery/GOOD.png'] = $GLOBALS['responses']['https://example.com/gallery/GOOD.png'];
	$GLOBALS['responses']['https://example.com/duplicates/../gallery/GOOD.png?again=1'] = $GLOBALS['responses']['https://example.com/gallery/GOOD.png'];
	$_POST['gallery_url'] = 'https://example.com/duplicates/';
	$result = reviewed_import( 13 );
	check( count( $result['errors'] ) === 1 && strpos( $result['errors'][0], 'More than one image' ) !== false, 'duplicate image reports a specific error while retaining the first image' );

	$_POST['gallery_url'] = 'https://example.com/gallery/INVALID.png';
	$result = reviewed_import( 10 );
	check( strpos( $result['message'], '0 event gallery images' ) !== false && count( $result['errors'] ) === 1, 'all-invalid batch reports zero successes explicitly' );

	$_POST['gallery_url'] = '';
	$_FILES['bonus_images'] = array(
		'name' => array( 'TOO_BIG.png' ), 'type' => array( 'image/png' ),
		'tmp_name' => array( '' ), 'error' => array( UPLOAD_ERR_INI_SIZE ), 'size' => array( 0 ),
	);
	$result = reviewed_import( 11 );
	check( strpos( $result['errors'][0], 'TOO_BIG.png: The image exceeds the server upload_max_filesize limit.' ) !== false, 'server upload failure names file and upload limit' );
	check( array() === glob( $GLOBALS['test_root'] . '/rqe-event-gallery/.staging-*' ), 'staging directories cleaned after recoverable imports' );

	$_FILES = array();
	$wpdb->fail_id = '';
	$_POST['gallery_url'] = 'https://example.com/gallery/GOOD.png';
	$prepared = RQE_Bonus::import( 20 );
	$pending = RQE_Bonus::pending( 20, $prepared['pending_token'] );
	check( $pending['can_approve'] && 'Passed' === $pending['scans'][0]['status'], 'clean scan report is available before approval' );
	check( ! is_dir( $GLOBALS['test_root'] . '/rqe-event-gallery/20' ), 'unapproved files are never published in gallery' );
	$GLOBALS['test_user'] = 2;
	try {
		RQE_Bonus::pending( 20, $prepared['pending_token'] );
		throw new RuntimeException( 'Cross-user review unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'another user/event' ) !== false, 'scan report is bound to importing user' );
	}
	$GLOBALS['test_user'] = 1;
	try {
		RQE_Bonus::approve( 21, $prepared['pending_token'] );
		throw new RuntimeException( 'Cross-event approval unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'another user/event' ) !== false, 'approval is bound to selected event' );
	}
	$image = reset( $pending['images'] );
	file_put_contents( $image['stage_path'], 'modified after scan' );
	try {
		RQE_Bonus::approve( 20, $prepared['pending_token'] );
		throw new RuntimeException( 'Modified file unexpectedly imported' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'changed after scanning' ) !== false, 'fingerprint prevents importing a changed staged file' );
	}

	foreach ( array( 'INFECTED', 'ERROR', 'TRUNCATED', 'SCRIPT' ) as $kind ) {
		$marker = 'SCRIPT' === $kind ? '<?php /* rejected test marker */' : 'RQE_TEST_' . $kind;
		$text_chunk = pack( 'N', strlen( $marker ) + 5 ) . 'tEXt' . "Note\0" . $marker . hash( 'crc32b', 'tEXtNote' . "\0" . $marker, true );
		$GLOBALS['responses']['https://example.com/' . $kind . '.png'] = array(
			'code' => 200, 'type' => 'image/png',
			'body' => substr( $png, 0, -12 ) . $text_chunk . substr( $png, -12 ),
		);
		$_POST['gallery_url'] = 'https://example.com/' . $kind . '.png';
		$prepared = RQE_Bonus::import( 22 );
		$pending = RQE_Bonus::pending( 22, $prepared['pending_token'] );
		check( ! $pending['can_approve'] && count( $pending['errors'] ) === 1, $kind . ' scan outcome blocks import approval' );
		check( 'Passed' !== $pending['scans'][0]['status'], $kind . ' never produces a clean scan result' );
		RQE_Bonus::approve( 22, $prepared['pending_token'], true );
	}

	foreach ( array( 'INFECTED', 'ERROR' ) as $kind ) {
		$GLOBALS['responses']['https://example.com/mixed/'] = array(
			'code' => 200, 'type' => 'text/html',
			'body' => '<a href="https://example.com/gallery/GOOD.png">good</a><a href="https://example.com/' . $kind . '.png">bad</a>',
		);
		$_POST['gallery_url'] = 'https://example.com/mixed/';
		$prepared = RQE_Bonus::import( 27 );
		$pending = RQE_Bonus::pending( 27, $prepared['pending_token'] );
		check( ( 'INFECTED' === $kind ) === $pending['can_approve'], 'partial mode permits a clean subset but never overrides scanner errors: ' . $kind );
		RQE_Bonus::approve( 27, $prepared['pending_token'], true );
	}

	$application = new RQE_Bonus_Application_Service();
	$stage_dir = $application->create_stage_directory();
	$sheet_path = $stage_dir . '/.listing-input';
	file_put_contents( $sheet_path, "Bonus ID,Bonus Description,Bonus Category,Bonus Points,Bonus Locations\nB100,Example,Test,10,\"1,2\"\n" );
	$scan_session = new RQE_Scan_Session();
	$scan_session->scan( $sheet_path, 'bonuses.csv' );
	list( $header, $raw_rows ) = RQE_Spreadsheet_Reader::read( $sheet_path, 'csv' );
	$rows = ( new RQE_Bonus_Application_Service() )->normalize_listing( $header, $raw_rows );
	$before = $wpdb->rows;
	$prepared = $application->prepare_review( 28, $rows, array(), $stage_dir, array(), false, $scan_session, 'listing' );
	check( $before === $wpdb->rows, 'listing scan and preparation leave event records untouched' );
	$pending = RQE_Bonus::pending( 28, $prepared['pending_token'] );
	check( count( $pending['hashes'] ) === 1 && $pending['can_approve'], 'listing report fingerprints the scanned spreadsheet' );
	RQE_Bonus::approve( 28, $prepared['pending_token'] );
	$listing = $wpdb->get_row( array( 28, 'B100' ), ARRAY_A );
	check( $listing && 10 === $listing['points'], 'only approval commits the validated bonus listing' );

	$_POST['gallery_url'] = 'https://example.com/gallery/GOOD.png';
	$prepared = RQE_Bonus::import( 23 );
	$pending = RQE_Bonus::pending( 23, $prepared['pending_token'] );
	$result = RQE_Bonus::approve( 23, $prepared['pending_token'], true );
	check( ! is_dir( $pending['stage_dir'] ) && ! is_dir( $GLOBALS['test_root'] . '/rqe-event-gallery/23' ), 'cancelling removes private staging and imports nothing' );
	$prepared = RQE_Bonus::import( 24 );
	RQE_Bonus::approve( 24, $prepared['pending_token'] );
	try {
		RQE_Bonus::approve( 24, $prepared['pending_token'] );
		throw new RuntimeException( 'Repeated approval unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'expired' ) !== false, 'approval token is single-use' );
	}
	$prepared = RQE_Bonus::import( 25 );
	$key = 'rqe_bonus_pending_1_' . $prepared['pending_token'];
	$GLOBALS['transients'][ $key ]['expires'] = time() - 1;
	try {
		RQE_Bonus::approve( 25, $prepared['pending_token'] );
		throw new RuntimeException( 'Expired approval unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'expired' ) !== false, 'expired scans require rescanning' );
	}

	$application = new RQE_Bonus_Application_Service();
	$header = array( 'Bonus ID', 'Bonus Description', 'Bonus Category', 'Bonus Points', 'Bonus Locations' );
	try {
		$application->normalize_listing( $header, array( array( 'B1', '=DANGEROUS()', 'Test', '10', '1,2' ) ) );
		throw new RuntimeException( 'Formula unexpectedly accepted' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'formulas' ) !== false, 'CSV formula cells rejected' );
	}
	RQE_Bonus::revoke_bypass( 25 );
	proc_terminate( $scanner );
	proc_close( $scanner );
	$scanner = null;
	$connection = RQE_File_Scan::check_connection();
	check( ! $connection['passed'] && strpos( $connection['message'], 'unavailable' ) !== false, 'Step 0 connection check reports an unavailable scanner explicitly' );
	$prepared = RQE_Bonus::import( 26 );
	$pending = RQE_Bonus::pending( 26, $prepared['pending_token'] );
	check( $pending['can_approve'] && $pending['unscanned'] && 'Not scanned' === $pending['scans'][0]['status'], 'unavailable ClamAV prepares validated files with an explicit unscanned status' );
	try {
		RQE_Bonus::approve( 26, $prepared['pending_token'] );
		throw new RuntimeException( 'Unconfirmed bypass unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'confirm' ) !== false, 'first unscanned import requires explicit bypass consent' );
	}
	RQE_Bonus::approve( 26, $prepared['pending_token'], true );
	check( ! RQE_Bonus::bypass_enabled(), 'cancelling unscanned import does not save consent' );
	$prepared = RQE_Bonus::import( 27 );
	$before_audit = count( $wpdb->audit_rows );
	$result = RQE_Bonus::approve( 27, $prepared['pending_token'], false, true );
	$audit = $wpdb->audit_rows[ $before_audit ];
	check( RQE_Bonus::bypass_enabled() && 'admin-1' === $audit['username'] && 27 === $audit['event_id'] && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $audit['occurred_at'] ), 'bypass consent records username, user ID, event and UTC date/time' );
	check( strpos( $result['message'], 'without an antivirus security scan' ) !== false && 'import_completed' === end( $wpdb->audit_rows )['action'], 'unscanned import emits warning and persistent completion audit' );
	$result = RQE_Bonus::import( 28 );
	check( ! isset( $result['pending_token'] ) && strpos( $result['message'], 'without an antivirus security scan' ) !== false, 'remembered bypass automatically imports validated images without another approval prompt' );
	$stage_dir = $application->create_stage_directory();
	$sheet_path = $stage_dir . '/.listing-input';
	file_put_contents( $sheet_path, "Bonus ID,Bonus Description,Bonus Category,Bonus Points,Bonus Locations\nB101,Unscanned example,Test,20,\"1,2\"\n" );
	RQE_File_Scan::reset( true );
	RQE_File_Scan::scan( $sheet_path, 'bonuses.csv' );
	list( $header, $raw_rows ) = RQE_Spreadsheet_Reader::read( $sheet_path, 'csv' );
	$rows = ( new RQE_Bonus_Application_Service() )->normalize_listing( $header, $raw_rows );
	$scan_session = new RQE_Scan_Session( true );
	$scan_session->scan( $sheet_path, 'bonuses.csv' );
	$prepared = $application->prepare_review( 28, $rows, array(), $stage_dir, array(), false, $scan_session, 'listing' );
	$result = RQE_Bonus::approve( 28, $prepared['pending_token'] );
	check( strpos( $result['message'], '1 bonus listings' ) !== false && strpos( $result['message'], 'without an antivirus security scan' ) !== false, 'listing imports retain parsing and persist an unscanned warning and audit' );
	$stage_dir = $application->create_stage_directory();
	$sheet_path = $stage_dir . '/.listing-input';
	file_put_contents( $sheet_path, "Bonus ID,Bonus Description,Bonus Category,Bonus Points,Bonus Locations\nB102,Unscanned example,Test,20,\"1,2\"\n" );
	RQE_File_Scan::reset( true );
	RQE_File_Scan::scan( $sheet_path, 'bonuses.csv' );
	list( $header, $raw_rows ) = RQE_Spreadsheet_Reader::read( $sheet_path, 'csv' );
	$rows = ( new RQE_Bonus_Application_Service() )->normalize_listing( $header, $raw_rows );
	$scan_session = new RQE_Scan_Session( true );
	$scan_session->scan( $sheet_path, 'bonuses.csv' );
	$prepared = $application->prepare_review( 28, $rows, array(), $stage_dir, array(), false, $scan_session, 'listing' );
	file_put_contents( $sheet_path, 'changed after validation' );
	$before = $wpdb->rows;
	try {
		RQE_Bonus::approve( 28, $prepared['pending_token'] );
		throw new RuntimeException( 'Tampered unscanned listing unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( $before === $wpdb->rows && strpos( $exception->getMessage(), 'changed' ) !== false, 'unscanned listing still requires its staged fingerprint to match' );
	}
	$GLOBALS['test_user'] = 2;
	$prepared = RQE_Bonus::import( 29 );
	check( ! RQE_Bonus::bypass_enabled() && isset( $prepared['pending_token'] ), 'bypass is isolated to the consenting administrator' );
	RQE_Bonus::approve( 29, $prepared['pending_token'], true );
	$GLOBALS['test_user'] = 1;
	$wpdb->fail_audit = true;
	$before = $wpdb->rows;
	try {
		RQE_Bonus::import( 30 );
		throw new RuntimeException( 'Unaudited import unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( $before === $wpdb->rows && strpos( $exception->getMessage(), 'audit' ) !== false, 'audit storage failure prevents unscanned persistence' );
	}
	$wpdb->fail_audit = false;
	$script_chunk = pack( 'N', 23 ) . 'tEXt' . "Note\0<?php malicious();" . hash( 'crc32b', "tEXtNote\0<?php malicious();", true );
	$GLOBALS['responses']['https://example.com/gallery/SCRIPT.png'] = array( 'code' => 200, 'type' => 'image/png', 'body' => substr( $png, 0, -12 ) . $script_chunk . substr( $png, -12 ) );
	$_POST['gallery_url'] = 'https://example.com/gallery/SCRIPT.png';
	$prepared = RQE_Bonus::import( 31 );
	$pending = RQE_Bonus::pending( 31, $prepared['pending_token'] );
	check( ! $pending['can_approve'], 'remembered bypass never overrides executable or image validation failures' );
	RQE_Bonus::approve( 31, $prepared['pending_token'], true );
	RQE_Bonus::revoke_bypass( 31 );
	check( ! RQE_Bonus::bypass_enabled() && 'bypass_disabled' === end( $wpdb->audit_rows )['action'], 'restoring mandatory scanning revokes remembered consent and is audited' );
	try {
		RQE_Bonus::enable_bypass( 31 );
		throw new RuntimeException( 'Unconfirmed Step 0 override unexpectedly succeeded' );
	} catch ( RuntimeException $exception ) {
		check( ! RQE_Bonus::bypass_enabled() && strpos( $exception->getMessage(), 'Confirm' ) !== false, 'Step 0 override rejects missing risk confirmation' );
	}
	$_POST['bypass_confirmation'] = '1';
	RQE_Bonus::enable_bypass( 31 );
	check( RQE_Bonus::bypass_enabled() && 'bypass_enabled' === end( $wpdb->audit_rows )['action'], 'Step 0 override records consent in persistent audit' );
	foreach ( array(
		'JPEG' => array( 'jpeg', 'image/jpeg', file_get_contents( __DIR__ . '/fixtures/jpeg-binary-marker.jpg' ), 'jpg' ),
		'PNG' => array( 'png', 'image/png', $png, 'png' ),
		'GIF' => array( 'gif', 'image/gif', file_get_contents( __DIR__ . '/fixtures/gif-binary-marker.gif' ), 'gif' ),
		'WEBP' => array( 'webp', 'image/webp', file_get_contents( __DIR__ . '/fixtures/webp-binary-marker.webp' ), 'webp' ),
	) as $format => $fixture ) {
		$url = 'https://example.com/gallery/' . $format . '.' . $fixture[0];
		$GLOBALS['responses'][ $url ] = array( 'code' => 200, 'type' => $fixture[1], 'body' => $fixture[2] );
		$result = ( new RQE_Bonus_Application_Service() )->import( 32, array(
			'import_step' => 'images', 'image_source' => 'url', 'gallery_url' => $url, 'files' => array(),
		) );
		check( ! isset( $result['pending_token'] ) && is_file( $GLOBALS['test_root'] . '/rqe-event-gallery/32/' . $format . '.' . $fixture[3] ), $format . ': real compressed image passes staging and audited unavailable-scanner import without false script rejection' );
	}
	$primary_jpeg = file_get_contents( __DIR__ . '/fixtures/jpeg-binary-marker.jpg' );
	foreach ( array(
		'PHONE.jpg' => array( 'image/jpeg', $primary_jpeg ),
		'EDITED.png' => array( 'image/png', $png ),
		'SHARED.gif' => array( 'image/gif', file_get_contents( __DIR__ . '/fixtures/gif-binary-marker.gif' ) ),
		'SHARED.webp' => array( 'image/webp', file_get_contents( __DIR__ . '/fixtures/webp-binary-marker.webp' ) ),
	) as $file => $fixture ) {
		$url = 'https://example.com/gallery/' . $file;
		$GLOBALS['responses'][ $url ] = array( 'code' => 200, 'type' => $fixture[0], 'body' => $fixture[1] . $fixture[1] . str_repeat( "\0", 64 ) . 'SEFH<?php trailer();' );
		( new RQE_Bonus_Application_Service() )->import( 32, array(
			'import_step' => 'images', 'image_source' => 'url', 'gallery_url' => $url, 'files' => array(),
		) );
		$stored = $GLOBALS['test_root'] . '/rqe-event-gallery/32/' . $file;
		check( is_file( $stored ) && file_get_contents( $stored ) === $fixture[1], $file . ': appended image data and trailer are removed; only the validated primary image is stored' );
	}
	RQE_Bonus::revoke_bypass( 31 );
} finally {
	foreach ( $GLOBALS['cleanup'] ?? array() as $args ) {
		RQE_Bonus::expire( ...$args );
	}
	if ( is_resource( $scanner ) ) {
		proc_terminate( $scanner );
	}
	foreach ( $pipes as $pipe ) {
		if ( is_resource( $pipe ) ) {
			fclose( $pipe );
		}
	}
	if ( is_resource( $scanner ) ) {
		proc_close( $scanner );
	}
	unlink( $check_control );
	RQE_Bonus::remove_all_storage();
	if ( is_dir( $GLOBALS['test_root'] ) ) {
		rmdir( $GLOBALS['test_root'] );
	}
}
