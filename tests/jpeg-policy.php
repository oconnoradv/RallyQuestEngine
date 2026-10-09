<?php
/** JPEG binary/content policy regression: php tests/jpeg-policy.php */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-file-scan.php';

function check_jpeg( $condition, $label ) {
	if ( ! $condition ) { throw new LogicException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . PHP_EOL;
}

$jpeg = file_get_contents( __DIR__ . '/fixtures/jpeg-binary-marker.jpg' );
$info = getimagesizefromstring( $jpeg );
check_jpeg( $info && 'image/jpeg' === $info['mime'] && false !== strpos( $jpeg, '<%' ), 'real JPEG fixture contains natural script-like compressed bytes' );
$path = __DIR__ . '/.jpeg-policy-' . bin2hex( random_bytes( 6 ) );
$policy = new MRSA_Executable_Content_Policy();
$write = function ( $content ) use ( $path ) {
	if ( strlen( $content ) !== file_put_contents( $path, $content ) ) {
		throw new RuntimeException( 'JPEG fixture could not be written.' );
	}
};
$reject = function ( $content, $label ) use ( $write, $path, $policy ) {
	$write( $content );
	try {
		$policy->validate( $path );
		throw new LogicException( 'Unsafe JPEG accepted: ' . $label );
	} catch ( RuntimeException $exception ) {
		check_jpeg( true, $label );
	}
};
$segment = function ( $marker, $payload ) {
	return "\xFF" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;
};
try {
	$write( $jpeg );
	$policy->validate( $path );
	check_jpeg( true, 'compressed JPEG marker bytes do not cause an executable-content false positive' );
	foreach ( array( '<?php echo 1;', '<?=1;', '<script>alert(1)</script>', '<% code %>', '#!/bin/sh' ) as $script ) {
		$reject( substr( $jpeg, 0, 2 ) . $segment( 0xFE, $script ) . substr( $jpeg, 2 ), 'script in JPEG comment metadata remains blocked' );
		$reject( substr( $jpeg, 0, 2 ) . $segment( 0xE1, $script ) . substr( $jpeg, 2 ), 'script in JPEG application metadata remains blocked' );
	}
	$reject( $jpeg . '<?php echo 1;', 'content appended after JPEG end remains blocked' );
	$reject( $jpeg . '<?php echo 1;' . "\xFF\xD9", 'appended payload with a forged final JPEG marker remains blocked' );
	$reject( substr( $jpeg, 0, -2 ), 'JPEG without an end marker remains blocked' );
	$reject( "\xFF\xD8\xFF\xE1\xFF\xFF" . $jpeg, 'invalid segment lengths remain blocked' );
	$reject( '<?php echo 1;', 'plain-text script checks remain unchanged' );
	$reject( str_repeat( 'a', 65534 ) . '<?php echo 1;', 'script markers spanning text-read chunk boundaries remain blocked' );
	$progressive = "\xFF\xD8" . $segment( 0xDA, 'header' ) . "pixels<%\xFF\0data\xFF\xFF\xD0more" .
		$segment( 0xFE, 'safe comment') . $segment( 0xDA, 'header') . "pixels<?=\xFF\xD9";
	$write( $progressive );
	$policy->validate( $path );
	check_jpeg( true, 'multiple scans, stuffed bytes and restart markers are handled without treating pixels as text' );

	$write( $jpeg );
	$transport = new class {
		public $calls = 0;
		public $response = 'stream: OK';
		public function scan( $path ) {
			++$this->calls;
			check_jpeg( filesize( $path ) === 10453, 'antivirus transport receives the complete JPEG including compressed pixels' );
			return $this->response;
		}
	};
	$session = new MRSA_Scan_Session( false, $transport );
	$session->scan( $path, 'B12.jpeg' );
	check_jpeg( 'Passed' === $session->results()[0]['status'], 'JPEG still requires a clean antivirus result' );
	$transport->response = 'stream: Test-Malware FOUND';
	try {
		( new MRSA_Scan_Session( true, $transport ) )->scan( $path, 'B12.jpg' );
		throw new LogicException( 'Malware result bypassed.' );
	} catch ( RuntimeException $exception ) {
		check_jpeg( strpos( $exception->getMessage(), 'Test-Malware' ) !== false, 'malware detections remain blocked even with unavailable-scanner consent' );
	}
} finally {
	if ( is_file( $path ) && ! unlink( $path ) ) { throw new RuntimeException( 'JPEG fixture cleanup failed.' ); }
}
