<?php
/** Loopback-only deterministic ClamAV protocol fixture; NOT an antivirus scanner. Used by the multipart regression. */
if ( 'cli' !== PHP_SAPI || empty( $argv[1] ) || ! ctype_digit( $argv[1] ) ) { exit( 1 ); }
$listener = stream_socket_server( 'tcp://127.0.0.1:' . $argv[1], $number, $message );
if ( ! $listener ) { throw new RuntimeException( $message ); }
echo 'ready' . PHP_EOL;
function read_exact( $socket, $size ) {
	$data = '';
	while ( strlen( $data ) < $size ) {
		$chunk = fread( $socket, $size - strlen( $data ) );
		if ( false === $chunk || '' === $chunk ) { throw new RuntimeException( 'Incomplete scanner fixture request.' ); }
		$data .= $chunk;
	}
	return $data;
}
while ( $socket = stream_socket_accept( $listener, 120 ) ) {
	try {
		if ( "zINSTREAM\0" !== read_exact( $socket, 10 ) ) { throw new RuntimeException( 'Invalid scan protocol.' ); }
		while ( $size = unpack( 'Nsize', read_exact( $socket, 4 ) )['size'] ) {
			if ( $size > 65536 ) { throw new RuntimeException( 'Invalid fixture chunk.' ); }
			read_exact( $socket, $size );
		}
		fwrite( $socket, "stream: OK\0" );
	} finally {
		fclose( $socket );
	}
}
fclose( $listener );
