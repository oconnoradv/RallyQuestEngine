<?php
/** Compressed raster policy regression: php tests/raster-policy.php */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-file-scan.php';
function check_raster( $condition, $label ) {
	if ( ! $condition ) { throw new LogicException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . PHP_EOL;
}
$chunk = function ( $type, $data ) { return pack( 'N', strlen( $data ) ) . $type . $data . hash( 'crc32b', $type . $data, true ); };
$png = "\x89PNG\r\n\x1A\n" . $chunk( 'IHDR', pack( 'NNCCCCC', 8, 1, 8, 2, 0, 0, 0 ) ) .
	$chunk( 'IDAT', gzcompress( "\0" . str_pad( '<%<?=<?php', 24, 'a' ), 0 ) ) . $chunk( 'IEND', '' );
$fixtures = array(
	'JPEG' => file_get_contents( __DIR__ . '/fixtures/jpeg-binary-marker.jpg' ),
	'PNG' => $png,
	'GIF' => file_get_contents( __DIR__ . '/fixtures/gif-binary-marker.gif' ),
	'WebP' => file_get_contents( __DIR__ . '/fixtures/webp-binary-marker.webp' ),
);
$path = __DIR__ . '/.raster-policy-' . bin2hex( random_bytes( 6 ) );
$policy = new MRSA_Executable_Content_Policy();
$validate = function ( $bytes ) use ( $path, $policy ) {
	if ( strlen( $bytes ) !== file_put_contents( $path, $bytes ) ) { throw new RuntimeException( 'Fixture write failed.' ); }
	$policy->validate( $path );
};
$reject = function ( $bytes, $label ) use ( $validate ) {
	try {
		$validate( $bytes );
		throw new LogicException( 'Unsafe content accepted: ' . $label );
	} catch ( RuntimeException $exception ) { check_raster( true, $label ); }
};
$webp_chunk = function ( $type, $data ) { return $type . pack( 'V', strlen( $data ) ) . $data . ( strlen( $data ) % 2 ? "\0" : '' ); };
$riff = function ( $chunks ) { return 'RIFF' . pack( 'V', strlen( $chunks ) + 4 ) . 'WEBP' . $chunks; };
try {
	foreach ( $fixtures as $format => $bytes ) {
		check_raster( getimagesizefromstring( $bytes ) && false !== strpos( $bytes, '<%' ), $format . ': real image contains binary script-like bytes' );
		$validate( $bytes );
		check_raster( true, $format . ': compressed/palette bytes do not trigger text policy' );
		$reject( $bytes . '<?php evil();', $format . ': appended scripts remain blocked' );
		$reject( substr( $bytes, 0, -3 ), $format . ': truncated image remains blocked' );
		$primary_length = function ( $data ) use ( $path, $policy ) {
			if ( strlen( $data ) !== file_put_contents( $path, $data ) ) { throw new RuntimeException( 'Fixture write failed.' ); }
			return $policy->primary_image_length( $path );
		};
		check_raster( strlen( $bytes ) === $primary_length( $bytes ), $format . ': complete image primary length is the whole file' );
		check_raster( strlen( $bytes ) === $primary_length( $bytes . $bytes . str_repeat( "\0", 31 ) . '<?php trailer();' ), $format . ': appended image, padding and trailer are excluded from the primary image' );
		try {
			// WebP length comes from its RIFF header, so filler after cut-off pixels is indistinguishable from pixel data.
			$primary_length( substr( $bytes, 0, -3 ) . ( 'WebP' === $format ? '' : '<?php trailer();' ) );
			throw new LogicException( 'Truncated image produced a primary length: ' . $format );
		} catch ( RuntimeException $exception ) {
			check_raster( true, $format . ': truncated image cannot be trimmed into acceptance' );
		}
	}
	$prefix = substr( $png, 0, -12 );
	$end = $chunk( 'IEND', '' );
	foreach ( array(
		array( 'tEXt', "Note\0<?php evil();" ),
		array( 'zTXt', "Note\0\0" . gzcompress( '<?php evil();' ) ),
		array( 'iTXt', "Note\0\1\0\0\0" . gzcompress( '<?php evil();' ) ),
		array( 'iTXt', "Note\0\0\0\0\0<script>evil()</script>" ),
		array( 'iCCP', "Profile\0\0" . gzcompress( '<?php evil();' ) ),
	) as $metadata ) {
		$reject( $prefix . $chunk( $metadata[0], $metadata[1] ) . $end, 'PNG ' . $metadata[0] . ': plain/compressed metadata script remains blocked' );
	}
	$validate( $prefix . $chunk( 'zTXt', "Note\0\0" . gzcompress( 'safe note' ) ) . $end );
	check_raster( true, 'PNG: safe compressed metadata is inspected successfully' );
	$reject( $prefix . $chunk( 'zTXt', "Note\0\0invalid zlib" ) . $end, 'PNG: invalid compressed metadata fails explicitly' );
	$comment = "\x21\xFE" . chr( 3 ) . '<?p' . chr( 9 ) . 'hp evil()' . "\0";
	$reject( substr( $fixtures['GIF'], 0, -1 ) . $comment . ';', 'GIF: script split across metadata sub-blocks remains blocked' );
	$chunks = substr( $fixtures['WebP'], 12 );
	$reject( $riff( $chunks . $webp_chunk( 'XMP ', '<?php evil();' ) ), 'WebP: XMP metadata script remains blocked' );
	$reject( $riff( $chunks . $webp_chunk( 'EXIF', '<?php evil();' ) ), 'WebP: EXIF metadata script remains blocked' );
	$reject( $riff( $chunks . 'XMP ' . pack( 'V', 99999999 ) . 'bad' ), 'WebP: oversized chunk length remains blocked' );
	$validate( $riff( $webp_chunk( 'VP8X', str_repeat( "\0", 10 ) ) . $webp_chunk( 'ANMF', str_repeat( "\0", 16 ) . $webp_chunk( 'VP8L', 'pixels<%' ) ) ) );
	check_raster( true, 'WebP: animated frame compressed bytes are inspected as image data' );
	$reject( $riff( $webp_chunk( 'ANMF', str_repeat( "\0", 16 ) . $webp_chunk( 'ANMF', str_repeat( "\0", 16 ) . $webp_chunk( 'VP8L', 'pixels' ) ) ) ), 'WebP: invalid nested animation frames remain blocked' );
	foreach ( array(
		'PNG' => $prefix . $chunk( 'tEXt', "Note\0<?php evil();" ) . $end,
		'GIF' => substr( $fixtures['GIF'], 0, -1 ) . $comment . ';',
		'WebP' => $riff( $chunks . $webp_chunk( 'XMP ', '<?php evil();' ) ),
	) as $format => $bytes ) {
		file_put_contents( $path, $bytes . 'trailer' );
		try {
			$policy->primary_image_length( $path );
			throw new LogicException( 'Metadata script accepted while trimming: ' . $format );
		} catch ( RuntimeException $exception ) {
			check_raster( true, $format . ': metadata scripts inside the primary image remain blocked when trimming' );
		}
	}
} finally {
	if ( is_file( $path ) && ! unlink( $path ) ) { throw new RuntimeException( 'Fixture cleanup failed.' ); }
}
