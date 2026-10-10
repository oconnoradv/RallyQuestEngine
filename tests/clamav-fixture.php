<?php
/** Local protocol fixture, not an antivirus engine. */
$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $error );
if ( ! $server ) {
	throw new RuntimeException( $error );
}
echo 'tcp://' . stream_socket_get_name( $server, false ) . PHP_EOL;
flush();
function read_bytes( $socket, $length ) {
	$content = '';
	while ( strlen( $content ) < $length ) {
		$chunk = fread( $socket, $length - strlen( $content ) );
		if ( false === $chunk || '' === $chunk ) {
			throw new RuntimeException( 'Incomplete fixture request' );
		}
		$content .= $chunk;
	}
	return $content;
}
while ( $socket = stream_socket_accept( $server, 60 ) ) {
	stream_set_timeout( $socket, 5 );
	if ( "zINSTREAM\0" !== read_bytes( $socket, 10 ) ) {
		throw new RuntimeException( 'Invalid INSTREAM command' );
	}
	$content = '';
	while ( true ) {
		$length = unpack( 'Nlength', read_bytes( $socket, 4 ) )['length'];
		if ( 0 === $length ) {
			break;
		}
		$content .= read_bytes( $socket, $length );
	}
	$check_mode = isset( $argv[1] ) ? trim( file_get_contents( $argv[1] ) ) : '';
	if ( '' !== $check_mode && false !== strpos( $content, 'MRSA ClamAV connection check.' ) ) {
		fwrite( $socket, 'TRUNCATED' === $check_mode ? 'stream: OK' : "stream: scan failed ERROR\0" );
	} elseif ( false !== strpos( $content, 'MRSA_TEST_INFECTED' ) ) {
		fwrite( $socket, "stream: Test.Signature FOUND\0" );
	} elseif ( false !== strpos( $content, 'MRSA_TEST_ERROR' ) ) {
		fwrite( $socket, "stream: scan failed ERROR\0" );
	} elseif ( false !== strpos( $content, 'MRSA_TEST_TRUNCATED' ) ) {
		fwrite( $socket, 'stream: OK' );
	} else {
		fwrite( $socket, "stream: OK\0" );
	}
	fclose( $socket );
}
fclose( $server );
