<?php
/** Standalone section-feedback rendering regression tests. */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
final class MRSA_Admin {
	public static function query( $key, $default = '' ) { return $GLOBALS['query'][ $key ] ?? $default; }
}
final class MRSA {
	public static function table( $name ) { return $name; }
}
final class MRSA_File_Scan {
	public static function check_connection() { return array( 'passed' => $GLOBALS['scanner_ready'] ?? false ); }
}
final class MRSA_Bonus {
	public static function bypass_enabled() { return $GLOBALS['bypassed'] ?? true; }
	public static function pending( $event, $token ) {
		if ( 'expired' === $token ) {
			throw new RuntimeException( 'Review expired.' );
		}
		return $GLOBALS['pending'];
	}
}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function admin_url( $path ) { return 'https://example.com/wp-admin/' . $path; }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="test">'; }
function get_current_user_id() { return 1; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-views.php';
$wpdb = new class {
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_results( $sql, $format ) { return array(); }
};

function check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	echo 'PASS: ' . $label . PHP_EOL;
}
function render_library() {
	$method = new ReflectionMethod( 'MRSA_Views', 'bonus_library' );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	ob_start();
	$method->invoke( null, array( 'id' => 7 ) );
	return ob_get_clean();
}
function section( $html, $step ) {
	$start = strpos( $html, 'id="mrsa-import-feedback"' );
	$end = strpos( $html, '<h4>Event Bonus Point Gallery', $start );
	return substr( $html, $start, $end - $start );
}

$feedback_id = str_repeat( 'a', 24 );
$GLOBALS['query'] = array( 'bonus_feedback' => $feedback_id, 'clamav_check' => str_repeat( 'b', 24 ) );
$GLOBALS['transients']['mrsa_bonus_feedback_1_' . $feedback_id] = array( 'event_id' => 7, 'step' => 'setup', 'severity' => 'success', 'message' => 'Check completed.' );
$GLOBALS['transients']['mrsa_clamav_check_1_' . str_repeat( 'b', 24 )] = array( 'event_id' => 7, 'passed' => false, 'message' => 'ClamAV unavailable.' );
$html = render_library();
$setup = section( $html, 'setup' );
check( false !== strpos( $setup, 'ClamAV unavailable.' ) && false !== strpos( $setup, 'bypass is enabled' ), 'connection results and bypass warning appear in shared feedback area' );
check( 1 === substr_count( $html, 'ClamAV unavailable.' ) && strpos( $html, 'id="mrsa-import-feedback"' ) > strpos( $html, 'Validate and Import Bonus Images' ), 'single feedback area immediately follows all three import columns' );
check( false === strpos( $html, 'mrsa-clamav-enabled' ), 'unavailable scanner does not show enabled status' );
check( false !== strpos( $html, '<details class="mrsa-clamav-disabled"><summary>ClamAV Manually Disabled</summary>' ) && false === strpos( $html, '<details class="mrsa-clamav-disabled" open' ), 'remembered bypass collapses Step 0 to orange manually disabled summary' );
$GLOBALS['bypassed'] = false;
$html = render_library();
check( false !== strpos( $html, 'mrsa-clamav-unconfigured' ) && false === strpos( $html, 'mrsa-clamav-disabled' ), 'unconfigured scanner without bypass displays red expanded setup' );
$GLOBALS['scanner_ready'] = true;
$html = render_library();
check( false !== strpos( $html, '<details class="mrsa-clamav-enabled"><summary>ClamAV Enabled</summary>' ) && false === strpos( $html, '<details class="mrsa-clamav-enabled" open' ), 'working scanner collapses Step 0 to the green enabled summary by default' );
check( false !== strpos( $html, 'Check ClamAV Connection' ), 'collapsed setup retains expandable connection controls' );
$GLOBALS['bypassed'] = true;
check( false !== strpos( render_library(), 'ClamAV Manually Disabled' ), 'remembered bypass remains visibly orange even when daemon is available' );
$GLOBALS['scanner_ready'] = false;

$GLOBALS['query'] = array( 'bonus_feedback' => $feedback_id, 'bonus_pending' => str_repeat( 'c', 32 ) );
$GLOBALS['transients']['mrsa_bonus_feedback_1_' . $feedback_id] = array( 'event_id' => 7, 'step' => 'listing', 'severity' => 'warning', 'message' => '<unsafe> listing notice' );
$GLOBALS['pending'] = array( 'import_step' => 'listing', 'rows' => array(), 'images' => array(), 'scans' => array(), 'errors' => array( 'Invalid listing cells.' ), 'can_approve' => false );
$html = render_library();
$listing = section( $html, 'listing' );
check( false !== strpos( $listing, 'Step 1: Bonus listing' ) && 1 === substr_count( $html, 'Invalid listing cells.' ), 'listing review errors are shown once with their step in shared feedback' );
check( false !== strpos( $listing, '&lt;unsafe&gt; listing notice' ) && false === strpos( $listing, '<unsafe>' ), 'section notice escapes untrusted text' );
check( false !== strpos( $listing, 'name="import_step" value="listing"' ), 'review cancellation preserves originating import step' );
$GLOBALS['pending']['can_approve'] = true;
$GLOBALS['pending']['unscanned'] = true;
$listing = section( render_library(), 'listing' );
check( false !== strpos( $listing, 'Confirm Bypass and Import' ) && false !== strpos( $listing, 'name="bypass_confirmation"' ), 'bypass confirmation remains actionable in shared feedback' );
$GLOBALS['pending']['unscanned'] = false;
check( false !== strpos( section( render_library(), 'listing' ), 'name="scan_approval"' ), 'normal scan approval remains actionable in shared feedback' );

$GLOBALS['query'] = array( 'bonus_report' => str_repeat( 'd', 24 ) );
$GLOBALS['transients']['mrsa_bonus_report_1_' . str_repeat( 'd', 24 )] = array( 'event_id' => 7, 'step' => 'images', 'errors' => array( 'B12.png: invalid image.' ) );
$html = render_library();
check( false !== strpos( section( $html, 'images' ), 'Step 2: Bonus images' ) && 1 === substr_count( $html, 'B12.png: invalid image.' ), 'image errors are shown once with their step in shared feedback' );
$GLOBALS['transients']['mrsa_bonus_report_1_' . str_repeat( 'd', 24 )]['event_id'] = 8;
check( false === strpos( render_library(), 'B12.png: invalid image.' ), 'feedback from another event is never displayed' );
unset( $GLOBALS['transients']['mrsa_bonus_report_1_' . str_repeat( 'd', 24 )] );
check( false !== strpos( section( render_library(), 'images' ), 'report has expired' ), 'expired image report warning remains in shared feedback' );
$GLOBALS['query'] = array( 'bonus_feedback' => $feedback_id );
$GLOBALS['transients']['mrsa_bonus_feedback_1_' . $feedback_id] = array( 'event_id' => 7, 'step' => 'images', 'severity' => 'success', 'message' => 'Imported 2 images.' );
check( false !== strpos( section( render_library(), 'images' ), 'notice-success inline"><p>Imported 2 images.' ), 'successful import uses green success styling in shared feedback' );
$GLOBALS['transients']['mrsa_bonus_feedback_1_' . $feedback_id]['severity'] = 'warning';
$GLOBALS['transients']['mrsa_bonus_feedback_1_' . $feedback_id]['message'] = 'Imported without antivirus.';
check( false !== strpos( section( render_library(), 'images' ), 'notice-warning inline"><p>Imported without antivirus.' ), 'unscanned imports retain warning styling rather than green success' );
