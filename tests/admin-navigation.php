<?php
/** Admin save destinations and shared presentation regressions. No real mailbox requests. */
require __DIR__ . '/event-administration.php';
require_once dirname( __DIR__ ) . '/rally-quest-engine/includes/class-rqe-importer.php';

final class Admin_Test_Redirect extends LogicException {}
function wp_safe_redirect( $url ) { throw new Admin_Test_Redirect( $url ); }
function wp_get_referer() { return 'https://example.test/wp-admin/admin.php?page=rqe-event-email'; }
function wp_validate_redirect( $url, $fallback ) { return $url; }
function check_admin_referer( $action ) {
	check( 'rqe_save' === $action, 'admin mutations retain the existing nonce boundary' );
	if ( ! empty( $GLOBALS['navigation_bad_nonce'] ) ) { throw new RuntimeException( 'Invalid nonce.' ); }
}
function wp_safe_remote_request( $url, $args ) {
	if ( ! empty( $GLOBALS['navigation_connection_failure'] ) ) {
		return array( 'response' => array( 'code' => 401 ), 'body' => '{}' );
	}
	if ( 'https://oauth2.googleapis.com/token' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"fixture-token"}' );
	}
	if ( 'https://gmail.googleapis.com/gmail/v1/users/me/profile' === $url ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"emailAddress":"navigation@example.test"}' );
	}
	if ( strpos( $url, 'https://gmail.googleapis.com/gmail/v1/users/me/messages?' ) === 0 ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => '{"messages":[]}' );
	}
	throw new LogicException( 'Unexpected provider request: ' . $url );
}
function is_wp_error( $value ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }

function save_destination( array $input ) {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = array_merge( array( 'return_url' => 'https://example.test/wp-admin/admin.php?page=rqe&tab=about' ), $input );
	try {
		RQE_Admin::save();
	} catch ( Admin_Test_Redirect $redirect ) {
		parse_str( parse_url( $redirect->getMessage(), PHP_URL_QUERY ), $destination );
		return $destination;
	}
	throw new LogicException( 'Save did not redirect.' );
}

$GLOBALS['actor'] = 1;
request( array( 'page' => 'rqe-event-edit', 'event_id' => '7' ) );
$gmail = array( 'provider' => 'gmail', 'enabled' => '1', 'poll_interval_minutes' => '60', 'mailbox' => 'navigation-global@example.test', 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'refresh_token' => 'fixture-refresh' );
$destination = save_destination( array_merge( $gmail, array( 'op' => 'global_email' ) ) );
check( $destination['page'] === 'rqe' && $destination['tab'] === 'initial-setup' && empty( $destination['scope'] ), 'global save returns to Initial Setup despite a different posted return URL and configuration referer' );
$destination = save_destination( array( 'op' => 'global_email_test' ) );
check( $destination['tab'] === 'initial-setup' && RQE_Setup::inbox_complete(), 'global connection verification returns to Initial Setup and completes inbox readiness' );
check( strpos( render( 'global_inbox_setup' ), 'rqe-global-inbox rqe-complete"><summary>' ) !== false, 'successful global verification minimizes and shades the setup email section green' );
check( RQE_Email_Submissions::configuration( 0 )['settings']['poll_interval_minutes'] === 60 && strpos( render( 'render_event_email' ), 'name="poll_interval_minutes" value="5" required min="5" max="1440" step="1"' ) !== false, 'admin saves persist global intervals and new event configuration exposes the bounded five-minute default' );

$destination = save_destination( array_merge( $gmail, array( 'op' => 'global_email', 'mailbox' => '' ) ) );
check( $destination['page'] === 'rqe-event-email' && $destination['scope'] === 'global' && isset( $destination['error'] ), 'invalid global saves return to configuration with an explicit error' );
$GLOBALS['navigation_connection_failure'] = true;
$destination = save_destination( array( 'op' => 'global_email_test' ) );
check( $destination['tab'] === 'initial-setup' && isset( $destination['error'] ) && ! RQE_Setup::inbox_complete(), 'failed global verification returns to Initial Setup and removes green completion' );
$GLOBALS['navigation_connection_failure'] = false;

$destination = save_destination( array_merge( $gmail, array( 'op' => 'event_email', 'event_id' => '7', 'mailbox' => 'navigation-event@example.test' ) ) );
check( $destination['page'] === 'rqe-event-edit' && $destination['event_id'] === '7', 'event inbox save returns to the correct Event Configuration rather than its referer' );
$destination = save_destination( array( 'op' => 'event_email_test', 'event_id' => '7' ) );
check( $destination['page'] === 'rqe-event-edit' && RQE_Email_Submissions::configuration_status( 7 )['connected'], 'event inbox test returns to its event and stores successful verification' );
$email_html = render( 'render_event_edit' );
check( strpos( $email_html, 'rqe-email-panel rqe-complete"><summary><h2>Email Setup</h2>' ) !== false && substr_count( $email_html, '<h2>Email Setup</h2>' ) === 1 && strpos( $email_html, 'Event Specific Email' ) !== false, 'one verified event-specific email section is green, collapsed and identifies its source' );
$destination = save_destination( array( 'op' => 'event_email_check', 'event_id' => '7' ) );
check( $destination['page'] === 'rqe-event-edit' && ! isset( $destination['error'] ), 'manual event-specific pulls return to Event Configuration' );

$destination = save_destination( array_merge( $gmail, array( 'op' => 'event_email', 'event_id' => '7', 'mailbox' => '' ) ) );
check( $destination['page'] === 'rqe-event-email' && $destination['event_id'] === '7' && isset( $destination['error'] ), 'invalid event saves retain their configuration destination for correction' );
$destination = save_destination( array_merge( $gmail, array( 'op' => 'event_email', 'event_id' => '7', 'mailbox' => 'navigation-event@example.test', 'poll_interval_minutes' => '1441' ) ) );
check( isset( $destination['error'] ) && RQE_Email_Submissions::configuration( 7 )['settings']['poll_interval_minutes'] === 60, 'invalid admin intervals return a correction error without overwriting saved configuration' );
RQE_Email_Submissions::remove_event_override( 7 );
RQE_Email_Submissions::save_global_assignment( 7 );
save_destination( array( 'op' => 'global_email_test' ) );
$email_html = render( 'render_event_edit' );
check( strpos( $email_html, 'rqe-email-panel rqe-complete"><summary><h2>Email Setup</h2>' ) !== false && substr_count( $email_html, '<h2>Email Setup</h2>' ) === 1 && strpos( $email_html, 'Global Email — connection verified' ) !== false && strpos( $email_html, 'Remove Event Inbox Override' ) === false, 'assigned verified global email collapses the single section and has no override removal control' );
$destination = save_destination( array( 'op' => 'event_email_test', 'event_id' => '7' ) );
check( $destination['page'] === 'rqe-event-edit' && ! isset( $destination['error'] ) && ! RQE_Email_Submissions::has_event_override( 7 ), 'testing inherited email verifies the global source without creating an event override' );
$destination = save_destination( array( 'op' => 'event_email_check', 'event_id' => '7' ) );
check( $destination['page'] === 'rqe-event-edit' && ! isset( $destination['error'] ) && RQE_Email_Submissions::configuration( 0 )['last_checked_at'], 'pulling inherited email updates global polling status and returns to the event' );
check( strpos( render( 'render_event_edit' ), 'every 60 minutes' ) !== false, 'inherited email displays the global inbox interval' );
RQE_Email_Submissions::save_global_assignment( 8 );
save_destination( array( 'op' => 'global_email_test' ) );
check( strpos( render( 'render_event_edit' ), 'rqe-email-panel rqe-complete' ) === false, 'a verified global inbox assigned to another event does not mark this event email ready' );
$destination = save_destination( array( 'op' => 'event_email_test', 'event_id' => '7' ) );
check( $destination['page'] === 'rqe-event-edit' && isset( $destination['error'] ), 'testing an unassigned global source returns an explicit error to the event' );
RQE_Email_Submissions::save_global_assignment( 7 );
save_destination( array( 'op' => 'global_email_test' ) );
save_destination( array_merge( $gmail, array( 'op' => 'event_email', 'event_id' => '7', 'enabled' => '', 'mailbox' => 'navigation-event@example.test' ) ) );
save_destination( array( 'op' => 'event_email_test', 'event_id' => '7' ) );
$email_html = render( 'render_event_edit' );
check( strpos( $email_html, 'rqe-email-panel rqe-complete' ) === false && strpos( $email_html, 'rqe-email-panel" open' ) !== false && strpos( $email_html, 'Event override polling is disabled.' ) !== false && strpos( $email_html, 'Pull Email Now' ) === false, 'disabled verified event overrides remain expanded and never inherit a ready global inbox' );
save_destination( array_merge( $gmail, array( 'op' => 'event_email', 'event_id' => '7', 'mailbox' => 'navigation-event@example.test' ) ) );
$GLOBALS['navigation_connection_failure'] = true;
$destination = save_destination( array( 'op' => 'event_email_test', 'event_id' => '7' ) );
check( $destination['page'] === 'rqe-event-edit' && isset( $destination['error'] ) && strpos( render( 'render_event_edit' ), 'rqe-email-connection-failed" open' ) !== false, 'failed event verification returns to the event and leaves its email sections incomplete' );
$GLOBALS['navigation_connection_failure'] = false;
$encrypted = $wpdb->get_var( 'SELECT encrypted_settings FROM ' . RQE::table( 'event_email_settings' ) . ' WHERE event_id=7' );
$wpdb->update( RQE::table( 'event_email_settings' ), array( 'encrypted_settings' => 'invalid-ciphertext' ), array( 'event_id' => 7 ) );
$email_html = render( 'render_event_edit' );
check( strpos( $email_html, 'cannot be decrypted' ) !== false && strpos( $email_html, 'Remove Event Inbox Override' ) !== false && strpos( $email_html, 'rqe-email-panel rqe-complete' ) === false, 'unreadable overrides remain correctable with an explicit error rather than breaking the event editor or inheriting global readiness' );
$wpdb->update( RQE::table( 'event_email_settings' ), array( 'encrypted_settings' => $encrypted ), array( 'event_id' => 7 ) );

$GLOBALS['navigation_bad_nonce'] = true;
rejects( function () { save_destination( array( 'op' => 'global_email_test' ) ); }, 'Invalid nonce' );
$GLOBALS['navigation_bad_nonce'] = false;
$GLOBALS['actor'] = 2;
rejects( function () { save_destination( array( 'op' => 'event_email_test', 'event_id' => '7' ) ); }, 'WordPress administrator' );
$GLOBALS['actor'] = 1;

foreach ( array( 'lock' => array( 'locked', 'Locked' ), 'archive' => array( 'archived', 'Locked &amp; Archived' ), 'unarchive' => array( 'locked', 'Locked' ), 'unlock' => array( 'open', 'Active' ) ) as $action => $expected ) {
	$context = in_array( $action, array( 'lock', 'unarchive' ), true ) ? 'event-edit' : 'results';
	$destination = save_destination( array( 'op' => 'event_state', 'event_id' => '7', 'state_action' => $action, 'context_tab' => $context ) );
	check( $destination['page'] === ( 'event-edit' === $context ? 'rqe-event-edit' : 'rqe' ) && ( 'event-edit' === $context || $destination['tab'] === 'results' ), 'event protection ' . $action . ' returns to the originating screen' );
	$edit_html = render( 'render_event_edit' );
	$results_html = render( 'overall_results' );
	$marker = 'rqe-protection-' . $expected[0] . '" open><summary><h3>Event Protection and Certificates</h3> <strong>' . $expected[1] . '</strong>';
	check( strpos( $edit_html, $marker ) !== false && strpos( $results_html, $marker ) !== false, 'event state ' . $expected[1] . ' is shared, colored and expanded on both screens' );
	check( strpos( $edit_html, 'Embed this Event on a Post or Page' ) < strpos( $edit_html, 'Event Protection and Certificates' ) && strpos( $edit_html, 'Event Protection and Certificates' ) < strpos( $edit_html, 'Email Setup' ), 'event protection remains in the left column directly after the embed section' );
}
$before = RQE::row( 'events', 7 );
rejects( function () { save_destination( array( 'op' => 'event_state', 'event_id' => '7', 'state_action' => 'lock', 'context_tab' => 'about' ) ); }, 'supported event protection screen' );
check( RQE::row( 'events', 7 ) === $before, 'invalid protection return context cannot mutate state' );
$GLOBALS['actor'] = 2;
$panel = render( 'event_protection', RQE::row( 'events', 7 ), 'event-edit' );
check( strpos( $panel, 'value="lock"' ) !== false && strpos( $panel, 'value="archive"' ) === false && strpos( $panel, 'value="unlock"' ) === false, 'event administrators retain lock access without gaining site-admin actions' );
$GLOBALS['actor'] = 1;

foreach ( array( 'horse' => 'Horses', 'geocache' => null ) as $style => $conveyance ) {
	RQE_Styles::save_primary( $style );
	request( array( 'page' => 'rqe', 'tab' => 'events' ) );
	$html = render( 'render' );
	preg_match( '/<nav class="nav-tab-wrapper"[^>]*>(.*?)<\/nav>/s', $html, $nav );
	preg_match_all( '/>([^<]+)<\/a>/', $nav[1], $labels );
	$expected = array( 'Initial Setup', 'Events', 'Rosters', 'Scoring', 'Results', 'Participants' );
	if ( $conveyance ) { $expected[] = $conveyance; }
	$expected = array_merge( $expected, array( 'Diagnostics', 'About' ) );
	check( $labels[1] === $expected, 'navigation preserves conveyance visibility and order for ' . $style );
}
$GLOBALS['actor'] = 3;
request( array( 'page' => 'rqe', 'tab' => 'about' ) );
$html = render( 'render' );
preg_match( '/<nav class="nav-tab-wrapper"[^>]*>(.*?)<\/nav>/s', $html, $nav );
preg_match_all( '/>([^<]+)<\/a>/', $nav[1], $labels );
check( $labels[1] === array( 'Scoring', 'About' ), 'scorers retain their limited navigation after reordering' );
