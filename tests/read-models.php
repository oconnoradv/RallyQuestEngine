<?php
/** Public read-model and pure-rule contracts: php tests/read-models.php */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
require __DIR__ . '/../rally-quest-engine/includes/class-rqe.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-results.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-queries.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-diagnostics.php';

function check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	echo 'PASS: ' . $label . PHP_EOL;
}

$ranks = RQE_Results::rankings( array( array( 'score' => 50 ), array( 'score' => '100' ), array( 'score' => 100 ), array( 'score' => 0 ), array( 'score' => -5 ) ) );
check( $ranks === array( 100 => 1, 50 => 3, 0 => 4, -5 => 5 ), 'competition rankings handle unordered rows, ties, numeric strings and negative scores' );
check( RQE_Results::rankings( array() ) === array(), 'empty roster has no rankings' );
check( RQE_Results::status( array( 'score' => 100 ), 100 ) === 'Registered', 'minimum finisher threshold is strictly greater than' );
check( RQE_Results::status( array( 'score' => 101 ), 100 ) === 'Preliminary Finisher', 'above-threshold automatic status is retained' );
check( RQE_Results::status( array( 'score' => 1000, 'status_manual' => 1, 'status' => 'DNF' ), 100 ) === 'DNF', 'manual historical status overrides automatic status' );
foreach ( array( 'Finisher', 'DNF', 'DNS' ) as $manual ) {
	check( RQE_Results::status( array( 'score' => 0, 'status_manual' => 1, 'status' => $manual ), 100 ) === $manual, 'manual ' . $manual . ' is preserved below threshold' );
	check( RQE_Results::status( array( 'score' => 1000, 'status_manual' => 1, 'status' => $manual ), 100 ) === $manual, 'manual ' . $manual . ' is preserved above threshold' );
}

$row = array( 'name' => 'Check', 'status' => 'passed', 'detail' => 'Result', 'remedy' => 'Action' );
check( RQE_Diagnostic_Report::state( array( $row ) ) === 'passed', 'all-passed report has passed aggregate state' );
$review = array_merge( $row, array( 'status' => 'review' ) );
$failed = array_merge( $row, array( 'status' => 'failed' ) );
check( RQE_Diagnostic_Report::state( array( $row, $review ) ) === 'review', 'manual review takes precedence over passed' );
check( RQE_Diagnostic_Report::state( array( $review, $failed ) ) === 'failed', 'failure takes precedence over manual review' );
foreach ( array( array(), array( array_merge( $row, array( 'status' => 'unknown' ) ) ), array( array( 'status' => 'passed' ) ) ) as $invalid ) {
	try {
		RQE_Diagnostic_Report::state( $invalid );
		throw new LogicException( 'Invalid report accepted.' );
	} catch ( RuntimeException $exception ) {
		check( true, 'invalid diagnostic contracts fail explicitly' );
	}
}

$wpdb = new class {
	public $prefix = 'test_';
	public $last_error = '';
	public $data = array();
	public $count = 0;
	public $query;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_results( $query, $format ) { $this->query = $query; return $this->data; }
	public function get_var( $query ) { $this->query = $query; return $this->count; }
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
};
$queries = new RQE_Queries( $wpdb );
$queries->events( 7, false, 99 );
check( $wpdb->query[1] === array( 7, 99 ) && strpos( $wpdb->query[0], 'a.user_id=%d' ) !== false, 'event query retains rider and assigned-administrator restrictions' );
$queries->events( 0, true, 99 );
check( is_string( $wpdb->query ) && strpos( $wpdb->query, 'event_admins' ) === false, 'site-administrator event query retains full access' );
$queries->scoring_events( false, 99 );
check( $wpdb->query[1] === array( 99, 99 ) && strpos( $wpdb->query[0], 'event_admins' ) !== false && strpos( $wpdb->query[0], 'event_scorers' ) !== false, 'scoring event query includes both event administrator and separate scorer assignments' );
$wpdb->data = array( array( 'score' => 100 ), array( 'score' => 50 ), array( 'score' => 100 ) );
$roster = $queries->roster( 7 );
check( array_column( $roster, 'overall_placing' ) === array( 1, 3, 1 ) && $wpdb->query[1] === array( 7 ), 'roster read model computes ranks without changing bib order or event scope' );
$results = $queries->results( 7, 50 );
check( $results[1]['effective_status'] === 'Registered' && $results[0]['overall_placing'] === 1, 'result read model supplies shared status and rankings' );
check( $wpdb->query[1] === array( 7, 7 ) && strpos( $wpdb->query[0], "status IN ('accepted','rejected')" ) !== false && strpos( $wpdb->query[0], 's.event_id=reg.event_id AND s.rider_id=reg.rider_id' ) !== false, 'results aggregate only accepted and rejected submissions in the same rider/event scope' );
$wpdb->data = array( array( 'score' => 50, 'min_finisher_points' => 50 ) );
$wpdb->count = 2;
$score = $queries->rider_score( 7, 4 );
check( $score['overall_placing'] === 3 && $score['effective_status'] === 'Registered' && $wpdb->query[1] === array( 7, 50 ), 'score sheet counts strictly higher scores in selected event' );
$wpdb->data = array();
check( $queries->rider_score( 7, 4 ) === null, 'missing rider score remains distinguishable from database failure' );
$wpdb->count = 201;
$page = $queries->rider_page( 'A_%', 999, false, 99 );
check( $page['page'] === 3 && $page['pages'] === 3 && $wpdb->query[1] === array( '%A\\_\\%%', '%A\\_\\%%', '%A\\_\\%%', 99, 100, 200 ), 'rider pagination clamps pages, escapes search wildcards and binds administrator scope' );
$page = $queries->bike_page( '', -1 );
check( $page['page'] === 1 && $wpdb->query[1] === array( 100, 0 ), 'catalog pagination clamps negative pages without inventing search conditions' );
$wpdb->count = 0;
$page = $queries->rider_page( '', 99, true, 99 );
check( $page['page'] === 1 && $page['pages'] === 1 && strpos( $wpdb->query[0], 'event_admins' ) === false, 'empty site-administrator catalog retains one page and unrestricted query' );
$wpdb->last_error = 'Database unavailable';
try {
	$queries->roster( 7 );
	throw new LogicException( 'Database failure hidden.' );
} catch ( RuntimeException $exception ) {
	check( strpos( $exception->getMessage(), 'could not be read' ) !== false, 'read-model errors surface rather than becoming empty results' );
}
