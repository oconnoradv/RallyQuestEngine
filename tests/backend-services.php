<?php
/** Injectable backend contract regressions: php tests/backend-services.php */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

final class RQE {
	public static function table( $name ) { return $name; }
}

final class Backend_Test_DB {
	public $records = array();
	public $action = null;
	public function insert( $table, $record ) { $this->records[] = array( $table, $record ); return 1; }
	public function prepare( $sql, ...$args ) { return $args; }
	public function get_var( $args ) { return $this->action; }
}

function get_current_user_id() { return 4; }
function wp_get_current_user() { return (object) array( 'ID' => 4, 'user_login' => 'test-admin' ); }
function set_transient( $key, $value, $expiry ) { $GLOBALS['backend_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['backend_transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['backend_transients'][ $key ] ); return true; }
function wp_schedule_single_event( $when, $hook, $args, $error = true ) { $GLOBALS['backend_schedules'][] = array( $when, $hook, $args ); return true; }
function wp_upload_dir() { return array( 'basedir' => ABSPATH, 'baseurl' => 'https://example.test/uploads' ); }
function get_temp_dir() { return sys_get_temp_dir(); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function wp_generate_password( $length, $special, $extra ) { return str_repeat( 'A', $length ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function check_backend( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	echo 'PASS: ' . $message . PHP_EOL;
}

require __DIR__ . '/../rally-quest-engine/includes/class-rqe-import-audit.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-import-adapters.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-file-scan.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-bonus.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-bonus-application-service.php';

try {
	( new RQE_Bonus_Application_Service() )->import_action( 'unknown', 8, array(), 'listing' );
	throw new LogicException( 'Unknown import operation silently accepted.' );
} catch ( RuntimeException $exception ) {
	check_backend( strpos( $exception->getMessage(), 'Unsupported' ) !== false, 'application boundary rejects unknown operations rather than cancelling an existing review' );
}

final class Backend_Fake_Transport {
	public $calls = 0;
	private $result;
	public function __construct( $result ) { $this->result = $result; }
	public function scan( $path ) {
		++$this->calls;
		if ( $this->result instanceof Throwable ) { throw $this->result; }
		return $this->result;
	}
}

final class Backend_Fake_Policy {
	public $calls = 0;
	public function validate( $path ) { ++$this->calls; }
}

final class Backend_Fake_Filesystem {
	public $calls = array();
	public function make_directory( $path, $mode ) { $this->calls[] = array( 'mkdir', $path, $mode ); return true; }
	public function remove_empty_directory( $path ) { $this->calls[] = array( 'rmdir', $path ); return true; }
}

$file = __DIR__ . '/.backend-services-scan.bin';
if ( false === file_put_contents( $file, 'plain data' ) ) { throw new RuntimeException( 'Could not create scanner fixture.' ); }
try {
	$unavailable_transport = new Backend_Fake_Transport( new RQE_Scanner_Unavailable_Exception( 'local scanner offline' ) );
	$policy = new Backend_Fake_Policy();
	$unscanned = new RQE_Scan_Session( true, $unavailable_transport, $policy );
	$unscanned->scan( $file, 'test data' );
	check_backend( $unscanned->skipped() && 'Not scanned' === $unscanned->results()[0]['status'] && 1 === $policy->calls, 'scan session independently records allowed unavailable scans and still enforces file policy' );

	$clean_transport = new Backend_Fake_Transport( 'stream: OK' );
	$clean_policy = new Backend_Fake_Policy();
	$clean = new RQE_Scan_Session( false, $clean_transport, $clean_policy );
	$clean->scan( $file, 'test data' );
	check_backend( ! $clean->skipped() && ! $clean->failed() && 'Passed' === $clean->results()[0]['status'] && 1 === $clean_transport->calls, 'new scan session starts with isolated clean transport and policy state' );
	check_backend( 1 === count( $unscanned->results() ) && 1 === count( $clean->results() ), 'simultaneous scan reports do not share mutable state' );

	$error_transport = new Backend_Fake_Transport( new RuntimeException( 'scanner protocol failed' ) );
	$failed = new RQE_Scan_Session( true, $error_transport, new Backend_Fake_Policy() );
	foreach ( array( 1, 2 ) as $attempt ) {
		try {
			$failed->scan( $file, 'test data' );
			throw new RuntimeException( 'Scanner error unexpectedly passed.' );
		} catch ( RuntimeException $exception ) {
			check_backend( false !== strpos( $exception->getMessage(), 'scanner' ), 'scanner failures remain blocking' );
		}
	}
	check_backend( $failed->failed() && 1 === $error_transport->calls, 'failed session blocks retries without reusing state across requests' );

	$database = new Backend_Test_DB();
	$audit = new RQE_Import_Audit( $database );
	$audit->record( 8, 'bypass_enabled', 'Explicit consent' );
	check_backend( 1 === count( $database->records ) && 'import_audit' === $database->records[0][0] && 'test-admin' === $database->records[0][1]['username'], 'audit persistence uses the injected database contract' );
	$database->action = 'bypass_enabled';
	check_backend( $audit->bypass_enabled( 4 ), 'override lookup uses the same injectable audit component' );

	$pending = new RQE_Pending_Review_Store();
	$pending->put( 'backend-test', array( 'event_id' => 8 ), time() + 60 );
	check_backend( 8 === $pending->get( 'backend-test' )['event_id'], 'pending-review storage adapter delegates through the transient contract' );
	$pending->remove( 'backend-test' );
	check_backend( false === $pending->get( 'backend-test' ), 'pending-review removal adapter deletes persisted review data' );

	$scheduler = new RQE_Import_Scheduler();
	$scheduler->schedule( time() + 60, 'rqe_test_cleanup', array( 4 ) );
	check_backend( 'rqe_test_cleanup' === $GLOBALS['backend_schedules'][0][1], 'cleanup scheduling is isolated behind an injectable adapter' );

	$filesystem = new Backend_Fake_Filesystem();
	RQE_Bonus::configure_adapters( null, null, $filesystem, $audit );
	$stage = RQE_Bonus::check_staging();
	check_backend( $stage['passed'] && array( 'mkdir', 'rmdir' ) === array_column( $filesystem->calls, 0 ), 'gallery staging checks use the injected filesystem adapter end to end' );
	RQE_Bonus::configure_adapters();
} finally {
	if ( is_file( $file ) && ! unlink( $file ) ) { throw new RuntimeException( 'Scanner fixture could not be removed.' ); }
}
