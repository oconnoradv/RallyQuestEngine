<?php
/** Standalone section-feedback rendering regression tests. */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MRSA_FILE', __DIR__ . '/../motorcycle-rally-scoring-application/motorcycle-rally-scoring-application.php' );
final class MRSA_Admin {
	public static function query( $key, $default = '' ) { return $GLOBALS['query'][ $key ] ?? $default; }
	public static function url( $tab, $args ) { return '/wp-admin/admin.php?tab=' . $tab . '&' . http_build_query( $args ); }
}
final class MRSA {
	public static function table( $name ) { return $name; }
}
final class MRSA_File_Scan {
	public static function check_connection() { return array( 'passed' => $GLOBALS['scanner_ready'] ?? false ); }
}
final class MRSA_Bonus {
	const MAX_IMAGE_SIZE = 10485760;
	const MAX_IMAGE_TOTAL = 104857600;
	const MAX_IMAGES = 200;
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
function plugins_url( $path, $file ) { return 'https://example.com/plugins/mrsa/' . $path; }
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
if ( in_array( '--preview', $argv ?? array(), true ) ) {
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="admin.css"><title>Sequential upload preview</title></head><body><main class="mrsa">' . $html . '</main><script src="admin.js"></script></body></html>';
	exit;
}
check( false !== strpos( $html, 'mrsa-image-queue' ) && false !== strpos( $html, 'data-max-image="10485760"' ) && false === strpos( $html, 'multiple webkitdirectory' ), 'sequential image queue uses server limits and falls back to a single uploaded image without JavaScript' );
check( strpos( $html, 'samples/bonus-listing.xlsx' ) !== false && strpos( $html, 'Download sample bonus listing XLSX' ) < strpos( $html, 'Validate and Import Bonus Listing' ), 'Step 1 links the downloadable sample XLSX before import controls' );
check( strpos( $html, 'Load via URL' ) < strpos( $html, 'name="allow_partial_images"' ) && strpos( $html, 'name="bonus_images[]"' ) < strpos( $html, 'name="allow_partial_images"' ), 'partial-image checkbox follows both file chooser and URL load button' );
$images_start = strpos( $html, 'id="mrsa-import-images"' );
$help_start = strpos( $html, '<details class="mrsa-url-help">', $images_start );
$help_end = strpos( $html, '</details>', $help_start );
$help = substr( $html, $help_start, $help_end - $help_start );
foreach ( array( 'Image filenames must match Bonus IDs', 'With JavaScript, select a folder', 'Public URLs must use HTTPS', 'Local uploads: each image is imported separately' ) as $text ) {
	check( false !== strpos( $help, $text ) && 1 === substr_count( $html, $text ), 'Step 2 helper text appears only in heading help: ' . $text );
}
check( $help_start < strpos( $html, 'name="bonus_images[]"' ) && strpos( $help, 'aria-label="Bonus image import information"' ) !== false, 'Step 2 uses accessible heading help like the Bikes import panel' );
$setup = section( $html, 'setup' );
check( false !== strpos( $setup, 'ClamAV unavailable.' ) && false === strpos( $setup, 'bypass is enabled' ), 'connection results remain in shared feedback without the persistent bypass warning' );
$disabled_start = strpos( $html, '<details class="mrsa-clamav-disabled">' );
$disabled_end = strpos( $html, '</section></details>', $disabled_start );
$disabled = substr( $html, $disabled_start, $disabled_end - $disabled_start );
check( false !== strpos( $disabled, 'Your ClamAV bypass is enabled on this site.' ) && 1 === substr_count( $html, 'Your ClamAV bypass is enabled on this site.' ), 'bypass warning appears once inside minimized Step 0' );
check( 1 === substr_count( $html, 'ClamAV unavailable.' ) && strpos( $html, 'id="mrsa-import-feedback"' ) > strpos( $html, 'Validate and Import Bonus Images' ), 'single feedback area immediately follows all three import columns' );
check( false === strpos( $html, 'mrsa-clamav-enabled' ), 'unavailable scanner does not show enabled status' );
check( false !== strpos( $html, '<details class="mrsa-clamav-disabled"><summary>ClamAV Manually Disabled</summary>' ) && false === strpos( $html, '<details class="mrsa-clamav-disabled" open' ), 'remembered bypass collapses Step 0 to orange manually disabled summary' );
check( false !== strpos( $html, 'Remove ClamAV Scanning Override' ) && false === strpos( $html, '>Disable ClamAV Scanning<' ), 'disabled Step 0 offers override removal instead of disabling again' );
$GLOBALS['bypassed'] = false;
$html = render_library();
check( false !== strpos( $html, '>Disable ClamAV Scanning<' ) && false !== strpos( $html, 'name="bypass_confirmation" value="1" required' ), 'Step 0 disable button requires explicit risk consent' );
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
