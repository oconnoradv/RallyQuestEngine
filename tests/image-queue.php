<?php
/** Sequential endpoint contract tests: php tests/image-queue.php */
define( 'ABSPATH', __DIR__ );
final class RQE {
	public static function access() { return $GLOBALS['authorized']; }
	public static function event_access( $id ) { return $GLOBALS['event_access']; }
	public static function row( $table, $id ) { return array( 'id' => $id ); }
}
final class RQE_Bonus {
	public static function import( $event ) { $GLOBALS['uploads']++; return $GLOBALS['result']; }
	public static function pending( $event, $token ) {
		if ( $event !== 7 || $token !== str_repeat( 'a', 32 ) ) { throw new RuntimeException( 'Invalid review.' ); }
		return $GLOBALS['pending'];
	}
	public static function approve( $event, $token, $cancel, $bypass ) {
		$GLOBALS['approved'] = array( $cancel, $bypass );
		return array( 'message' => $cancel ? 'Cancelled.' : 'Imported.', 'errors' => array() );
	}
}
final class RQE_Bonus_Application_Service {
	public function import( $event, array $input ) { $GLOBALS['captured_import_input'] = $input; return RQE_Bonus::import( $event ); }
	public function pending( $event, $token ) { return RQE_Bonus::pending( $event, $token ); }
	public function review( $event, $token, $operation, array $input ) {
		$pending = $this->pending( $event, $token );
		if ( 'approve' === $operation && '1' !== ( $input['scan_approval'] ?? '' ) ) { throw new RuntimeException( 'Review required.' ); }
		if ( 'bypass' === $operation && '1' !== ( $input['bypass_confirmation'] ?? '' ) ) { throw new RuntimeException( 'Confirm risk.' ); }
		return array( 'pending' => $pending, 'result' => RQE_Bonus::approve( $event, $token, 'cancel' === $operation, 'bypass' === $operation ) );
	}
}
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function check_admin_referer( $action ) {
	if ( empty( $GLOBALS['nonce_valid'] ) ) { throw new RuntimeException( 'Invalid nonce.' ); }
}
final class Queue_Response extends Exception {
	public $data;
	public $success;
	public function __construct( $data, $success ) { $this->data = $data; $this->success = $success; }
}
function wp_send_json_success( $data ) { throw new Queue_Response( $data, true ); }
function wp_send_json_error( $data, $status ) { throw new Queue_Response( $data, false ); }
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-admin.php';
function check( $ok, $label ) {
	if ( ! $ok ) { throw new LogicException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . PHP_EOL;
}
function operation( $op ) {
	$_POST['queue_operation'] = $op;
	try { RQE_Admin::image_queue(); }
	catch ( Queue_Response $response ) { return $response; }
	throw new LogicException( 'Endpoint did not respond.' );
}
$GLOBALS['authorized'] = true;
$GLOBALS['event_access'] = true;
$GLOBALS['nonce_valid'] = true;
$GLOBALS['uploads'] = 0;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'event_id' => '7', 'import_step' => 'images' );
$_FILES = array( 'bonus_images' => array( 'name' => array( 'B1.jpg', 'B2.jpg' ) ) );
check( ! operation( 'upload' )->success && $GLOBALS['uploads'] === 0, 'endpoint refuses requests with multiple images before importing' );
$_FILES['bonus_images']['name'] = array( 'B1.jpg' );
$_POST['gallery_url'] = 'https://example.com/image.jpg';
check( ! operation( 'upload' )->success && $GLOBALS['uploads'] === 0, 'endpoint refuses combined URL/local sources' );
unset( $_POST['gallery_url'] );
$token = str_repeat( 'a', 32 );
$GLOBALS['result'] = array( 'pending_token' => $token, 'message' => 'Review.', 'errors' => array() );
$GLOBALS['pending'] = array( 'import_step' => 'images', 'rows' => array(), 'images' => array( array() ), 'scans' => array( array( 'status' => 'Passed' ) ), 'errors' => array(), 'can_approve' => true, 'unscanned' => false );
$response = operation( 'upload' );
check( $response->success && $response->data['state'] === 'review' && $response->data['token'] === $token && ! isset( $GLOBALS['approved'] ), 'one-image upload returns scan review without approving or persisting' );
check( 'images' === $GLOBALS['captured_import_input']['import_step'] && isset( $GLOBALS['captured_import_input']['files']['bonus_images'] ), 'controller passes captured HTTP fields and uploaded files explicitly to the application service' );
$_POST['pending_token'] = $token;
check( ! operation( 'approve' )->success && ! isset( $GLOBALS['approved'] ), 'approval requires explicit scan consent' );
$_POST['scan_approval'] = '1';
check( operation( 'approve' )->data['state'] === 'imported' && $GLOBALS['approved'] === array( false, false ), 'explicit approval reuses guarded fingerprint-verified import' );
check( operation( 'cancel' )->data['state'] === 'cancelled' && $GLOBALS['approved'] === array( true, false ), 'cancellation delegates private staged-file cleanup' );
check( ! operation( 'bypass' )->success, 'unscanned consent cannot be omitted' );
$_POST['bypass_confirmation'] = '1';
check( operation( 'bypass' )->success && $GLOBALS['approved'] === array( false, true ), 'explicit bypass uses existing audited unavailable-scanner path' );
$GLOBALS['pending']['images'][] = array();
check( ! operation( 'approve' )->success, 'approval cannot consume multi-image batch review through sequential endpoint' );
$GLOBALS['pending']['images'] = array();
$GLOBALS['pending']['scans'] = array( array( 'status' => 'Scanner error' ) );
$GLOBALS['pending']['can_approve'] = false;
$response = operation( 'upload' );
check( $response->data['scanner_error'] && ! $response->data['can_approve'], 'scanner failures are exposed as non-skippable queue errors' );
$GLOBALS['result'] = array( 'message' => 'Imported without antivirus scan; audited.', 'errors' => array() );
check( operation( 'upload' )->data['state'] === 'imported', 'remembered override retains existing no-repeat-prompt behavior' );
$GLOBALS['event_access'] = false;
check( ! operation( 'upload' )->success, 'event authorization enforced on each request' );
$GLOBALS['authorized'] = false;
check( ! operation( 'upload' )->success, 'unauthorized uploader rejected' );
$GLOBALS['authorized'] = true;
$GLOBALS['nonce_valid'] = false;
try { operation( 'upload' ); throw new LogicException( 'Invalid nonce accepted.' ); }
catch ( RuntimeException $exception ) { check( $exception->getMessage() === 'Invalid nonce.', 'nonce enforced before dispatch' ); }
$_SERVER['REQUEST_METHOD'] = 'GET';
check( ! operation( 'upload' )->success, 'queue endpoint only accepts POST' );
