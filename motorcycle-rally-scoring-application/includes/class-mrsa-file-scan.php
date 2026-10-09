<?php
/** Local ClamAV transport, executable-content policy and request-local scan state. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Scanner_Unavailable_Exception extends RuntimeException {}

final class MRSA_ClamAV_Transport {
	private $address;

	public function __construct( $address = null ) {
		$this->address = null === $address ? ( defined( 'MRSA_CLAMAV_SOCKET' ) ? MRSA_CLAMAV_SOCKET : 'unix:///var/run/clamav/clamd.ctl' ) : $address;
	}

	private function write_all( $socket, $data ) {
		while ( '' !== $data ) {
			$written = fwrite( $socket, $data );
			if ( false === $written || 0 === $written ) {
				throw new RuntimeException( 'ClamAV could not receive the file. Import is blocked.' );
			}
			$data = substr( $data, $written );
		}
	}

	public function scan( $path ) {
		if ( ! is_string( $this->address ) || ! preg_match( '#^(?:unix:///[^\\x00]+|tcp://127\.0\.0\.1:[0-9]{1,5}|tcp://\[::1\]:[0-9]{1,5})$#D', $this->address ) ) {
			throw new RuntimeException( 'MRSA_CLAMAV_SOCKET must specify a local Unix socket or loopback TCP address.' );
		}
		$socket = @stream_socket_client( $this->address, $errno, $error, 5 );
		if ( ! $socket ) {
			if ( in_array( (int) $errno, array( 2, 61, 111, 10061 ), true ) ) {
				throw new MRSA_Scanner_Unavailable_Exception( 'Local ClamAV is unavailable: the local socket is missing or the daemon refused the connection.' );
			}
			throw new RuntimeException( 'Local ClamAV is unavailable. Ask the site administrator to configure clamd and MRSA_CLAMAV_SOCKET; import is blocked.' );
		}
		$handle = null;
		try {
			stream_set_timeout( $socket, 30 );
			$handle = fopen( $path, 'rb' );
			if ( ! $handle ) {
				throw new RuntimeException( 'The scan file could not be opened.' );
			}
			$this->write_all( $socket, "zINSTREAM\0" );
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 65536 );
				if ( false === $chunk ) {
					throw new RuntimeException( 'The scan file could not be read.' );
				}
				if ( '' !== $chunk ) {
					$this->write_all( $socket, pack( 'N', strlen( $chunk ) ) . $chunk );
				}
			}
			$this->write_all( $socket, pack( 'N', 0 ) );
			$response = '';
			while ( false === strpos( $response, "\0" ) && strlen( $response ) < 4096 ) {
				$chunk = fread( $socket, 4096 - strlen( $response ) );
				$metadata = stream_get_meta_data( $socket );
				if ( false === $chunk || '' === $chunk || $metadata['timed_out'] ) {
					throw new RuntimeException( 'ClamAV returned no complete result or timed out. Import is blocked.' );
				}
				$response .= $chunk;
			}
			if ( false === strpos( $response, "\0" ) ) {
				throw new RuntimeException( 'ClamAV returned an oversized or incomplete result. Import is blocked.' );
			}
			return trim( substr( $response, 0, strpos( $response, "\0" ) ) );
		} finally {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			fclose( $socket );
		}
	}
}

final class MRSA_Executable_Content_Policy {
	public function validate( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'The file could not be read for executable-content checks.' );
		}
		try {
			$tail = '';
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 65536 );
				if ( false === $chunk ) {
					throw new RuntimeException( 'The file could not be read for executable-content checks.' );
				}
				$content = $tail . $chunk;
				if ( preg_match( '/<\?(?:php\b|=)|<script\b|<%|#!\s*\/(?:usr|bin)\//i', $content ) ) {
					throw new RuntimeException( 'Executable or script content was detected. Only data files and raster images are allowed.' );
				}
				$tail = substr( $content, -64 );
			}
		} finally {
			fclose( $handle );
		}
	}
}

final class MRSA_Scan_Session {
	private $transport;
	private $policy;
	private $allow_unavailable;
	private $results = array();
	private $failed = false;
	private $skipped = false;

	public function __construct( $allow_unavailable = false, $transport = null, $policy = null ) {
		$this->allow_unavailable = (bool) $allow_unavailable;
		$this->transport = $transport ?: new MRSA_ClamAV_Transport();
		$this->policy = $policy ?: new MRSA_Executable_Content_Policy();
	}

	public function results() { return $this->results; }
	public function failed() { return $this->failed; }
	public function skipped() { return $this->skipped; }

	public function scan( $path, $name ) {
		$status = 'Scanner error';
		$detail = '';
		try {
			if ( $this->failed ) {
				throw new RuntimeException( 'The scanner failed earlier in this batch. Import is blocked; restore ClamAV and scan again.' );
			}
			$response = null;
			try {
				$response = $this->transport->scan( $path );
			} catch ( MRSA_Scanner_Unavailable_Exception $exception ) {
				if ( ! $this->allow_unavailable ) {
					throw $exception;
				}
				$this->skipped = true;
			}
			if ( null !== $response && 'stream: OK' !== $response ) {
				if ( preg_match( '/^stream: (.+) FOUND$/D', $response, $matches ) ) {
					$status = 'Blocked';
					throw new RuntimeException( 'ClamAV detected ' . $matches[1] . '.' );
				}
				throw new RuntimeException( 'ClamAV did not return a clean result: ' . $response );
			}
			$status = 'Blocked';
			$this->policy->validate( $path );
			$status = null === $response ? 'Not scanned' : 'Passed';
			$detail = null === $response ? 'ClamAV unavailable; no antivirus security scan performed. Executable-content checks passed.' : 'ClamAV: no malware detected; executable-content checks passed.';
		} catch ( RuntimeException $exception ) {
			$detail = $exception->getMessage();
			if ( 'Scanner error' === $status ) {
				$this->failed = true;
			}
			throw $exception;
		} finally {
			$this->results[] = array( 'file' => $name, 'status' => $status, 'detail' => $detail );
		}
	}

	public static function check_connection() {
		$path = wp_tempnam( 'mrsa-clamav-check' );
		if ( ! $path ) {
			return array( 'passed' => false, 'message' => 'A temporary file could not be created for the ClamAV connection check. Check PHP temporary-directory permissions.' );
		}
		try {
			if ( false === file_put_contents( $path, "MRSA ClamAV connection check.\n" ) ) {
				throw new RuntimeException( 'The ClamAV connection-check file could not be written.' );
			}
			( new self() )->scan( $path, 'Connection-check file' );
			return array( 'passed' => true, 'message' => 'ClamAV connection check passed: the local daemon accepted a file stream and returned a clean scan result. This does not verify signature freshness or scanner configuration; review the setup checklist before importing.' );
		} catch ( RuntimeException $exception ) {
			return array( 'passed' => false, 'message' => 'ClamAV connection check failed: ' . $exception->getMessage() );
		} finally {
			if ( is_file( $path ) && ! unlink( $path ) ) {
				error_log( 'MRSA: ClamAV connection-check temporary file could not be removed.' );
			}
		}
	}
}

/** Compatibility facade for older integrations; imports use MRSA_Scan_Session directly. */
final class MRSA_File_Scan {
	private static $session;

	public static function reset( $allow_unavailable = false ) {
		self::$session = new MRSA_Scan_Session( $allow_unavailable );
	}
	private static function session() {
		if ( ! self::$session ) {
			self::reset();
		}
		return self::$session;
	}
	public static function skipped() { return self::session()->skipped(); }
	public static function results() { return self::session()->results(); }
	public static function failed() { return self::session()->failed(); }
	public static function scan( $path, $name ) { self::session()->scan( $path, $name ); }
	public static function check_connection() { return MRSA_Scan_Session::check_connection(); }
}
