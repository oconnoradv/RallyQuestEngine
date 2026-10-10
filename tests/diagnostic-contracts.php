<?php
/** Public collector composition contracts: php tests/diagnostic-contracts.php */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-diagnostics.php';
final class MRSA {
	public static function site_access() { return $GLOBALS['authorized']; }
}
function check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $label );
	}
	echo 'PASS: ' . $label . PHP_EOL;
}
$GLOBALS['authorized'] = true;
$calls = 0;
$group = function () use ( &$calls ) {
	++$calls;
	return array( array( 'name' => 'Custom', 'status' => 'review', 'detail' => 'Review needed', 'remedy' => 'Inspect configuration' ) );
};
$report = MRSA_Diagnostics::check( array( $group ) );
check( $calls === 1 && count( $report['rows'] ) === 1 && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $report['checked_at'] ), 'explicit diagnostic composition invokes only supplied checks and timestamps the report' );
foreach ( array( array(), array( 'not_a_callable' ), array( function () { return 'invalid'; } ), array( function () { return array( array( 'status' => 'unknown' ) ); } ) ) as $invalid ) {
	try {
		MRSA_Diagnostics::check( $invalid );
		throw new LogicException( 'Invalid diagnostic collector accepted.' );
	} catch ( RuntimeException $exception ) {
		check( true, 'invalid diagnostic provider fails explicitly' );
	}
}
$GLOBALS['authorized'] = false;
try {
	MRSA_Diagnostics::check( array( $group ) );
	throw new LogicException( 'Unauthorized collector accepted.' );
} catch ( RuntimeException $exception ) {
	check( $calls === 1, 'authorization precedes even injected diagnostic checks' );
}
