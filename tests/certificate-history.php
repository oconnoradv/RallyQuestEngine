<?php
/** Certificate history regressions: php tests/certificate-history.php (SQLite-backed; no mail or PDF is produced). */
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function wp_generate_password( $length, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
function set_transient( $key, $value, $ttl ) { $GLOBALS['drafts'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['drafts'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['drafts'][ $key ] ); return true; }
function wp_doing_cron() { return ! empty( $GLOBALS['doing_cron'] ); }
function wp_mail( $recipient, $subject, $message, $headers, $attachments ) { throw new LogicException( 'History tests must not send mail.' ); }
require __DIR__ . '/event-administration.php';
if ( ! class_exists( 'RQE_Certificate_History' ) ) {
	require __DIR__ . '/../rally-quest-engine/includes/class-rqe-certificate-history.php';
}
RQE_Admin::checked( $wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . RQE::table( 'certificate_history' ) . ' (id INTEGER PRIMARY KEY AUTOINCREMENT,event_id INTEGER,rider_id INTEGER,user_id INTEGER,operation TEXT,outcome TEXT,design TEXT,participant_name TEXT,detail TEXT,source_key TEXT NOT NULL UNIQUE,created_at TEXT)' ) );

$history = RQE::table( 'certificate_history' );
$mail = RQE::table( 'certificate_mail' );
$count = function ( $table, $where = '1=1' ) use ( $wpdb ) { return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where ); };
$no_secrets = function ( $label ) use ( $wpdb, $history ) {
	$dump = wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . $history, ARRAY_A ) );
	foreach ( array( '@example.test', 'Private subject', 'Private congratulations body' ) as $secret ) {
		check( false === strpos( $dump, $secret ), $label . ' (no ' . $secret . ')' );
	}
};

$GLOBALS['actor'] = 1;
$wpdb->query( 'DELETE FROM ' . $history );
$wpdb->query( 'DELETE FROM ' . $mail );
$wpdb->insert( RQE::table( 'event_admins' ), array( 'event_id' => 8, 'user_id' => 4 ) );
$wpdb->update( RQE::table( 'riders' ), array( 'name' => 'Rider One', 'email' => 'one@example.test' ), array( 'id' => 10 ) );
$wpdb->update( RQE::table( 'riders' ), array( 'name' => 'Rider Two' ), array( 'id' => 11 ) );
RQE_Statuses::assign( 7, 1, 'event', 'Finisher' );
$snapshots = RQE_Certificates::snapshots( 7 );
check( count( $snapshots ) >= 1, 'fixture has an eligible finisher' );

// Print / PDF generation requests: one entry per participant per request, never claiming physical printing.
check( RQE_Certificate_History::record_generated( 7, 'print', $snapshots ) === count( $snapshots ), 'print request records each participant' );
RQE_Certificate_History::record_generated( 7, 'pdf', $snapshots );
$page = RQE_Certificate_History::page( 7, 1 );
check( $page['total'] === 2 * count( $snapshots ) && 50 === $page['per_page'], 'repeated generation requests are retained separately' );
$print = array_values( array_filter( $page['rows'], function ( $row ) { return 'print' === $row['operation']; } ) )[0];
check( 'generated' === $print['outcome'] && false !== strpos( $print['detail'], 'not confirmed' ) && in_array( $print['participant_name'], array( 'Rider One', 'Rider Two' ), true ) && isset( RQE_Certificates::designs()[ $print['design'] ] ), 'print history records a generation request, participant and design only' );
rejects( function () use ( $snapshots ) { RQE_Certificate_History::record_generated( 8, 'print', $snapshots ); }, 'does not match' );
rejects( function () { RQE_Certificate_History::record( 7, 10, 'email', 'generated' ); }, 'Unsupported' );

// Email states are keyed per delivery and idempotent.
$delivery = array( 'batch' => str_repeat( 'a', 48 ), 'event_id' => 7, 'rider_id' => 10, 'user_id' => 1, 'snapshot' => wp_json_encode( array( 'name' => 'Rider One', 'design' => 'classic', 'email' => 'one@example.test' ) ) );
$before = $count( $history );
$first = RQE_Certificate_History::record_delivery( $delivery, 'sending', 'Sending started.' );
$second = RQE_Certificate_History::record_delivery( $delivery, 'sent', 'Accepted by the mail system.' );
$row = RQE::row( 'certificate_history', $first );
check( $first === $second && $count( $history ) === $before + 1 && 'sent' === $row['outcome'] && 'classic' === $row['design'], 'email state transitions update one idempotent history entry' );
check( 0 === strpos( $row['source_key'], 'mail:' ) && false === strpos( $row['source_key'], $delivery['batch'] ), 'delivery source key does not expose the batch identifier' );

// Real send_batch stale path records history without touching the queue contract.
$review = RQE_Certificates::review( 7, 10 );
$approved = RQE_Certificates::approve( 7, $review['token'], 'Private subject', 'Private congratulations body', true );
$wpdb->update( RQE::table( 'riders' ), array( 'email' => 'changed@example.test' ), array( 'id' => 10 ) );
RQE_Certificates::send_batch( 7, $approved['batch'] );
check( 'stale' === RQE_Certificates::deliveries( 7, $approved['batch'] )[0]['state'], 'changed recipient still makes the delivery stale' );
check( 1 === $count( $history, "operation='email' AND outcome='stale'" ), 'stale delivery is recorded in history' );
$wpdb->update( RQE::table( 'riders' ), array( 'email' => 'one@example.test' ), array( 'id' => 10 ) );

// Expiring approvals are recorded as stale before the queue snapshot is cleared.
$review = RQE_Certificates::review( 7, 10 );
$expiring = RQE_Certificates::approve( 7, $review['token'], 'Private subject', 'Private congratulations body', true );
$wpdb->update( $mail, array( 'created_at' => '2000-01-01 00:00:00' ), array( 'batch' => $expiring['batch'] ) );
RQE_Certificates::expire();
check( 2 === $count( $history, "operation='email' AND outcome='stale'" ) && 'stale' === RQE_Certificates::deliveries( 7, $expiring['batch'] )[0]['state'], 'expired approval is recorded as stale' );
$no_secrets( 'history never stores addresses, subjects or message bodies' );

// Backfill imports completed queue outcomes once, without private snapshot content or pending rows.
$wpdb->insert( $mail, array( 'batch' => str_repeat( 'b', 48 ), 'event_id' => 7, 'rider_id' => 10, 'user_id' => 1, 'snapshot' => wp_json_encode( array( 'name' => 'Rider One', 'design' => 'vintage', 'email' => 'one@example.test' ) ), 'fingerprint' => 'x', 'subject' => 'Private subject', 'message' => 'Private congratulations body', 'state' => 'sending', 'detail' => '', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00' ) );
$wpdb->insert( $mail, array( 'batch' => str_repeat( 'c', 48 ), 'event_id' => 7, 'rider_id' => 11, 'user_id' => 1, 'snapshot' => '', 'fingerprint' => 'y', 'subject' => '', 'message' => '', 'state' => 'sent', 'detail' => 'Accepted by the mail system; delivery is not guaranteed.', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-03 00:00:00' ) );
$wpdb->insert( $mail, array( 'batch' => str_repeat( 'd', 48 ), 'event_id' => 7, 'rider_id' => 11, 'user_id' => 1, 'snapshot' => '', 'fingerprint' => 'z', 'subject' => 'Private subject', 'message' => 'Private congratulations body', 'state' => 'pending', 'detail' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$GLOBALS['actor'] = 2;
rejects( function () { RQE_Certificate_History::backfill(); }, 'site administrators' );
$GLOBALS['actor'] = 1;
$before = $count( $history );
check( 4 === RQE_Certificate_History::backfill(), 'backfill reads completed sending, sent and stale queue outcomes' );
check( $count( $history ) === $before + 2, 'backfill is idempotent with already-recorded deliveries and skips pending rows' );
$imported = $wpdb->get_row( 'SELECT * FROM ' . $history . " WHERE rider_id=11 AND operation='email'", ARRAY_A );
check( 'Rider Two' === $imported['participant_name'] && '2026-01-03 00:00:00' === $imported['created_at'] && 'sent' === $imported['outcome'], 'backfill uses participant name and the recorded outcome time' );
$no_secrets( 'backfill does not copy private snapshot, subject or message' );
check( 0 === RQE_Certificate_History::backfill() && $count( $history ) === $before + 2, 'backfill runs only once' );
RQE_Certificate_History::backfill( true );
check( $count( $history ) === $before + 2, 'forced backfill creates no duplicates' );

// Pagination: newest first, 50 per page, clamped.
for ( $i = 0; $i < 55; ++$i ) {
	RQE_Certificate_History::record( 7, 10, 'print', 'generated', array( 'participant_name' => 'Rider One', 'created_at' => '2026-02-01 00:00:00' ) );
}
$total = $count( $history, 'event_id=7' );
$one = RQE_Certificate_History::page( 7, 1 );
$last = RQE_Certificate_History::page( 7, 999 );
check( 50 === count( $one['rows'] ) && $total === $one['total'] && $last['page'] === $one['pages'] && count( $last['rows'] ) === $total - 50 * ( $one['pages'] - 1 ), 'history pages hold at most 50 entries and clamp out-of-range pages' );
check( (int) $one['rows'][0]['id'] > (int) $one['rows'][1]['id'] || $one['rows'][0]['created_at'] > $one['rows'][1]['created_at'], 'history is newest first' );
check( 1 === RQE_Certificate_History::page( 8, 1 )['page'] && 0 === RQE_Certificate_History::page( 8, 1 )['total'], 'history is scoped to its event' );

// Authorization and confirmation.
$target = (int) $one['rows'][0]['id'];
$GLOBALS['actor'] = 3;
rejects( function () { RQE_Certificate_History::page( 7, 1 ); }, 'not assigned' );
$GLOBALS['actor'] = 4;
rejects( function () use ( $target ) { RQE_Certificate_History::delete( 7, $target, true ); }, 'not assigned' );
rejects( function () use ( $target ) { RQE_Certificate_History::delete( 8, $target, true ); }, 'not found' );
rejects( function () { RQE_Certificate_History::delete_all( true ); }, 'site administrators' );
$GLOBALS['actor'] = 1;
rejects( function () use ( $target ) { RQE_Certificate_History::delete( 7, $target, false ); }, 'Confirm' );
rejects( function () use ( $target ) { RQE_Certificate_History::delete( 7, $target, '1' ); }, 'Confirm' );
check( null !== RQE::row( 'certificate_history', $target ), 'unconfirmed or unauthorized deletion keeps the entry' );

// Individual deletion is separate from delivery records and scores, and is audited.
$queue = $wpdb->get_results( 'SELECT * FROM ' . $mail . ' ORDER BY id', ARRAY_A );
$scores = $wpdb->get_results( 'SELECT id,score,status FROM ' . RQE::table( 'registrations' ) . ' ORDER BY id', ARRAY_A );
$total = $count( $history );
check( true === RQE_Certificate_History::delete( 7, $target, true ), 'confirmed deletion succeeds' );
check( null === RQE::row( 'certificate_history', $target ) && $count( $history ) === $total - 1, 'only the selected history entry is deleted' );
check( $queue === $wpdb->get_results( 'SELECT * FROM ' . $mail . ' ORDER BY id', ARRAY_A ) && $scores === $wpdb->get_results( 'SELECT id,score,status FROM ' . RQE::table( 'registrations' ) . ' ORDER BY id', ARRAY_A ), 'history deletion does not touch certificate_mail or scores' );
check( 1 === $count( RQE::table( 'import_audit' ), "action='certificate_history_deleted' AND event_id=7" ), 'history deletion is audited' );
rejects( function () use ( $target ) { RQE_Certificate_History::delete( 7, $target, true ); }, 'not found' );

// Failed audit/write rolls back a deletion.
$keep = (int) RQE_Certificate_History::page( 7, 1 )['rows'][0]['id'];
$wpdb->fail_write = true;
rejects( function () use ( $keep ) { RQE_Certificate_History::delete( 7, $keep, true ); }, 'could not be deleted' );
rejects( function () use ( $snapshots ) { RQE_Certificate_History::record_generated( 7, 'pdf', $snapshots ); }, 'could not be saved' );
$wpdb->fail_write = false;
check( null !== RQE::row( 'certificate_history', $keep ), 'failed deletion keeps the entry' );

// Diagnostics delete-all.
rejects( function () { RQE_Certificate_History::delete_all( false ); }, 'Confirm' );
check( RQE_Certificate_History::delete_all( true ) > 0 && 0 === $count( $history ), 'delete all removes every history entry' );
check( $queue === $wpdb->get_results( 'SELECT * FROM ' . $mail . ' ORDER BY id', ARRAY_A ), 'delete all keeps certificate_mail deduplication records' );
check( 1 === $count( RQE::table( 'import_audit' ), "action='certificate_history_deleted_all'" ), 'delete all is audited' );
check( 0 === RQE_Certificate_History::backfill(), 'deleted history is not resurrected by the one-time backfill' );
// Cron expiry with no logged-in user still records stale history; non-cron anonymous requests do nothing.
$review = RQE_Certificates::review( 7, 10 );
$cron = RQE_Certificates::approve( 7, $review['token'], 'Private subject', 'Private congratulations body', true );
$wpdb->update( $mail, array( 'created_at' => '2000-01-01 00:00:00' ), array( 'batch' => $cron['batch'] ) );
$stale_before = $count( $history, "operation='email' AND outcome='stale'" );
$GLOBALS['actor'] = 0;
RQE_Certificates::expire();
check( 'pending' === $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . $mail . ' WHERE batch=%s', $cron['batch'] ) ), 'anonymous non-cron request does not expire approvals' );
$GLOBALS['doing_cron'] = true;
RQE_Certificates::expire();
unset( $GLOBALS['doing_cron'] );
check( 'stale' === $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM ' . $mail . ' WHERE batch=%s', $cron['batch'] ) ) && $count( $history, "operation='email' AND outcome='stale'" ) === $stale_before + 1, 'cron expiry with no user records stale history' );
rejects( function () { RQE_Certificate_History::page( 7, 1 ); }, 'not assigned' );
rejects( function () { RQE_Certificate_History::record( 7, 10, 'print', 'generated' ); }, 'not assigned' );
rejects( function () { RQE_Certificate_History::backfill( true ); }, 'site administrators' );
rejects( function () { RQE_Certificate_History::delete_all( true ); }, 'site administrators' );
$GLOBALS['actor'] = 1;

// Trusted install migration backfill runs with no logged-in user, once.
delete_option( RQE_Certificate_History::BACKFILL_OPTION );
$wpdb->insert( $mail, array( 'batch' => str_repeat( 'e', 48 ), 'event_id' => 7, 'rider_id' => 11, 'user_id' => 1, 'snapshot' => wp_json_encode( array( 'name' => 'Rider Two', 'design' => 'vintage', 'email' => 'two@example.test' ) ), 'fingerprint' => 'w', 'subject' => 'Private subject', 'message' => 'Private congratulations body', 'state' => 'failed', 'detail' => 'WordPress mail returned a failure.', 'created_at' => '2026-03-01 00:00:00', 'updated_at' => '2026-03-01 00:00:00' ) );
$GLOBALS['actor'] = 0;
$before = $count( $history );
$missing = 0;
foreach ( $wpdb->get_results( 'SELECT * FROM ' . $mail . " WHERE state IN ('sending','sent','failed','stale')", ARRAY_A ) as $entry ) {
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $history . ' WHERE source_key=%s', RQE_Certificate_History::delivery_key( $entry ) ) ) ) { ++$missing; }
}
check( RQE_Certificate_History::migration_backfill() > 0 && $count( $history ) === $before + $missing, 'install migration backfill works without a user and skips already-recorded deliveries' );
check( 0 === RQE_Certificate_History::migration_backfill() && $count( $history ) === $before + $missing, 'install migration backfill runs once' );
$migrated = $wpdb->get_row( 'SELECT * FROM ' . $history . " WHERE outcome='failed' AND rider_id=11", ARRAY_A );
check( $migrated && 1 === (int) $migrated['user_id'] && 'Rider Two' === $migrated['participant_name'], 'migrated entry keeps the original administrator and participant name' );
$no_secrets( 'migration backfill does not copy private snapshot, subject or message' );
$GLOBALS['actor'] = 1;
echo 'Certificate history tests passed.' . PHP_EOL;