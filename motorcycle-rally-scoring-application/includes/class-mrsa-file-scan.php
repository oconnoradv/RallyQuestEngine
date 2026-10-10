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
	private function validate_text( $content ) {
		if ( preg_match( '/<\?(?:php\b|=)|<script\b|<%|#!\s*\/(?:usr|bin)\//i', $content ) ) {
			throw new RuntimeException( 'Executable or script content was detected. Only data files and raster images are allowed.' );
		}
	}

	private function read_jpeg( $handle ) {
		$content = stream_get_contents( $handle, 10485761 );
		if ( false === $content || strlen( $content ) > 10485760 ) {
			throw new RuntimeException( 'The JPEG could not be checked within the 10 MB image limit.' );
		}
		return $content;
	}

	private function validate_jpeg( $handle ) {
		$content = $this->read_jpeg( $handle );
		if ( $this->jpeg_primary_length( $content ) !== strlen( $content ) ) {
			throw new RuntimeException( 'The JPEG is incomplete, malformed or contains appended content. Export a fresh image and scan again.' );
		}
	}

	/**
	 * Byte length of a validated raster's primary image through its format end marker (JPEG EOI,
	 * PNG IEND, GIF trailer, WebP RIFF size), or null for other files. Phones, editors and
	 * sharing apps commonly append gain maps, MPF images, vendor trailers, video or padding.
	 */
	public function primary_image_length( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'The file could not be read for executable-content checks.' );
		}
		try {
			$content = $this->read_image( $handle );
		} finally {
			fclose( $handle );
		}
		if ( "\xFF\xD8" === substr( $content, 0, 2 ) ) {
			return $this->jpeg_primary_length( $content );
		}
		if ( "\x89PNG\r\n\x1A\n" === substr( $content, 0, 8 ) ) {
			return $this->png_primary_length( $content );
		}
		if ( in_array( substr( $content, 0, 6 ), array( 'GIF87a', 'GIF89a' ), true ) ) {
			return $this->gif_primary_length( $content );
		}
		if ( 'RIFF' === substr( $content, 0, 4 ) && 'WEBP' === substr( $content, 8, 4 ) ) {
			return $this->webp_primary_length( $content );
		}
		return null;
	}

	private function jpeg_primary_length( $content ) {
		$length = strlen( $content );
		$offset = 2;
		$scanned = false;
		while ( $offset < $length ) {
			if ( "\xFF" !== $content[ $offset ] ) {
				break;
			}
			while ( $offset < $length && "\xFF" === $content[ $offset ] ) {
				++$offset;
			}
			if ( $offset >= $length ) {
				break;
			}
			$marker = ord( $content[ $offset++ ] );
			if ( 0xD9 === $marker ) {
				if ( $scanned ) {
					return $offset;
				}
				break;
			}
			if ( 0x00 === $marker || 0xD8 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) ) {
				break;
			}
			if ( 0x01 === $marker ) {
				continue;
			}
			if ( $offset + 2 > $length ) {
				break;
			}
			$size = unpack( 'nsize', substr( $content, $offset, 2 ) )['size'];
			if ( $size < 2 || $offset + $size > $length ) {
				break;
			}
			$this->validate_text( substr( $content, $offset + 2, $size - 2 ) );
			$offset += $size;
			if ( 0xDA === $marker ) {
				$scanned = true;
				// Entropy-coded pixels are binary, not script text. Metadata stays checked above.
				while ( $offset < $length ) {
					$next = strpos( $content, "\xFF", $offset );
					if ( false === $next || $next + 1 >= $length ) {
						$offset = $length;
						break;
					}
					$offset = $next;
					$code_offset = $next + 1;
					while ( $code_offset < $length && "\xFF" === $content[ $code_offset ] ) {
						++$code_offset;
					}
					if ( $code_offset >= $length ) {
						$offset = $length;
						break;
					}
					$code = ord( $content[ $code_offset ] );
					if ( 0x00 !== $code && ! ( $code >= 0xD0 && $code <= 0xD7 ) ) {
						break;
					}
					$offset = $code_offset + 1;
				}
			}
		}
		throw new RuntimeException( 'The JPEG is incomplete, malformed or contains appended content. Export a fresh image and scan again.' );
	}

	private function read_image( $handle ) {
		$content = stream_get_contents( $handle, 10485761 );
		if ( false === $content || strlen( $content ) > 10485760 ) {
			throw new RuntimeException( 'The image could not be checked within the 10 MB image limit.' );
		}
		return $content;
	}

	private function invalid_image() {
		throw new RuntimeException( 'The image is incomplete, malformed or contains appended content. Export a fresh image and scan again.' );
	}

	private function validate_png( $content ) {
		if ( $this->png_primary_length( $content ) !== strlen( $content ) ) {
			$this->invalid_image();
		}
	}

	private function png_primary_length( $content ) {
		$offset = 8;
		$length = strlen( $content );
		$has_pixels = false;
		while ( $offset + 12 <= $length ) {
			$size = unpack( 'Nsize', substr( $content, $offset, 4 ) )['size'];
			$type = substr( $content, $offset + 4, 4 );
			if ( $size > $length - $offset - 12 || ! preg_match( '/^[A-Za-z]{4}$/D', $type ) ) {
				$this->invalid_image();
			}
			$data = substr( $content, $offset + 8, $size );
			if ( 8 === $offset && ( 'IHDR' !== $type || 13 !== $size ) ) {
				$this->invalid_image();
			}
			if ( 'IDAT' === $type ) {
				$has_pixels = true;
			} elseif ( in_array( $type, array( 'tEXt', 'eXIf' ), true ) ) {
				$this->validate_text( $data );
			} elseif ( in_array( $type, array( 'zTXt', 'iCCP', 'iTXt' ), true ) ) {
				$this->validate_png_metadata( $type, $data );
			} elseif ( ! in_array( $type, array( 'IHDR', 'IEND', 'PLTE', 'tRNS', 'bKGD', 'sBIT', 'hIST', 'pHYs', 'tIME', 'sRGB', 'gAMA', 'cHRM', 'acTL', 'fcTL', 'fdAT' ), true ) ) {
				$this->validate_text( $data );
			}
			$offset += $size + 12;
			if ( 'IEND' === $type ) {
				if ( 0 === $size && $has_pixels && "\xAE\x42\x60\x82" === substr( $content, $offset - 4, 4 ) ) {
					return $offset;
				}
				$this->invalid_image();
			}
		}
		$this->invalid_image();
	}

	private function validate_png_metadata( $type, $data ) {
		$separator = strpos( $data, "\0" );
		if ( false === $separator || $separator + 2 >= strlen( $data ) ) {
			$this->invalid_image();
		}
		$this->validate_text( substr( $data, 0, $separator ) );
		$offset = $separator + 2;
		$compressed = true;
		if ( 'iTXt' === $type ) {
			$flag = ord( $data[ $separator + 1 ] );
			if ( $flag > 1 || ! isset( $data[ $offset ] ) || "\0" !== $data[ $offset ] ) {
				$this->invalid_image();
			}
			$compressed = 1 === $flag;
			++$offset;
			for ( $field = 0; $field < 2; ++$field ) {
				$end = strpos( $data, "\0", $offset );
				if ( false === $end ) {
					$this->invalid_image();
				}
				$this->validate_text( substr( $data, $offset, $end - $offset ) );
				$offset = $end + 1;
			}
		} elseif ( "\0" !== $data[ $separator + 1 ] ) {
			$this->invalid_image();
		}
		$text = substr( $data, $offset );
		if ( $compressed ) {
			$text = @gzuncompress( $text, 10485760 );
			if ( false === $text ) {
				throw new RuntimeException( 'Compressed image metadata is invalid or exceeds the inspection limit.' );
			}
		}
		$this->validate_text( $text );
	}

	private function gif_blocks( $content, &$offset ) {
		$blocks = '';
		$length = strlen( $content );
		while ( $offset < $length ) {
			$size = ord( $content[ $offset++ ] );
			if ( 0 === $size ) {
				return $blocks;
			}
			if ( $size > $length - $offset ) {
				$this->invalid_image();
			}
			$blocks .= substr( $content, $offset, $size );
			$offset += $size;
		}
		$this->invalid_image();
	}

	private function validate_gif( $content ) {
		if ( $this->gif_primary_length( $content ) !== strlen( $content ) ) {
			$this->invalid_image();
		}
	}

	private function gif_primary_length( $content ) {
		$length = strlen( $content );
		if ( $length < 13 ) {
			$this->invalid_image();
		}
		$packed = ord( $content[10] );
		$offset = 13 + ( ( $packed & 0x80 ) ? 3 * ( 1 << ( ( $packed & 7 ) + 1 ) ) : 0 );
		$has_pixels = false;
		while ( $offset < $length ) {
			$type = ord( $content[ $offset++ ] );
			if ( 0x3B === $type && $has_pixels ) {
				return $offset;
			}
			if ( 0x21 === $type && $offset < $length ) {
				++$offset;
				$this->validate_text( $this->gif_blocks( $content, $offset ) );
			} elseif ( 0x2C === $type && $offset + 9 < $length ) {
				$packed = ord( $content[ $offset + 8 ] );
				$offset += 9 + ( ( $packed & 0x80 ) ? 3 * ( 1 << ( ( $packed & 7 ) + 1 ) ) : 0 );
				if ( $offset >= $length || ord( $content[ $offset ] ) < 2 || ord( $content[ $offset ] ) > 8 ) {
					$this->invalid_image();
				}
				++$offset;
				$this->gif_blocks( $content, $offset );
				$has_pixels = true;
			} else {
				$this->invalid_image();
			}
		}
		$this->invalid_image();
	}

	private function webp_primary_length( $content ) {
		if ( strlen( $content ) < 12 ) {
			$this->invalid_image();
		}
		$riff_length = unpack( 'Vsize', substr( $content, 4, 4 ) )['size'] + 8;
		if ( $riff_length > strlen( $content ) ) {
			$this->invalid_image();
		}
		$this->validate_webp( substr( $content, 0, $riff_length ) );
		return $riff_length;
	}

	private function validate_webp( $content, $is_frame = false ) {
		$length = strlen( $content );
		if ( $length < 12 || unpack( 'Vsize', substr( $content, 4, 4 ) )['size'] + 8 !== $length ) {
			$this->invalid_image();
		}
		$offset = 12;
		$has_pixels = false;
		while ( $offset + 8 <= $length ) {
			$type = substr( $content, $offset, 4 );
			$size = unpack( 'Vsize', substr( $content, $offset + 4, 4 ) )['size'];
			$offset += 8;
			if ( $size > $length - $offset || $offset + $size + ( $size % 2 ) > $length ) {
				$this->invalid_image();
			}
			$data = substr( $content, $offset, $size );
			if ( 'ANMF' === $type ) {
				if ( $is_frame || $size < 16 ) {
					$this->invalid_image();
				}
				$frame = substr( $data, 16 );
				$this->validate_webp( 'RIFF' . pack( 'V', strlen( $frame ) + 4 ) . 'WEBP' . $frame, true );
				$has_pixels = true;
			} elseif ( in_array( $type, array( 'VP8 ', 'VP8L' ), true ) ) {
				$has_pixels = true;
			} elseif ( ! in_array( $type, array( 'VP8X', 'ALPH', 'ANIM' ), true ) ) {
				$this->validate_text( $data );
			}
			$offset += $size + ( $size % 2 );
		}
		if ( ! $has_pixels || $offset !== $length ) {
			$this->invalid_image();
		}
	}

	public function validate( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'The file could not be read for executable-content checks.' );
		}
		try {
			$signature = fread( $handle, 12 );
			if ( false === $signature || 0 !== fseek( $handle, 0 ) ) {
				throw new RuntimeException( 'The file could not be read for executable-content checks.' );
			}
			if ( "\xFF\xD8" === substr( $signature, 0, 2 ) ) {
				$this->validate_jpeg( $handle );
				return;
			}
			if ( "\x89PNG\r\n\x1A\n" === substr( $signature, 0, 8 ) ) {
				$this->validate_png( $this->read_image( $handle ) );
				return;
			}
			if ( in_array( substr( $signature, 0, 6 ), array( 'GIF87a', 'GIF89a' ), true ) ) {
				$this->validate_gif( $this->read_image( $handle ) );
				return;
			}
			if ( 'RIFF' === substr( $signature, 0, 4 ) && 'WEBP' === substr( $signature, 8, 4 ) ) {
				$this->validate_webp( $this->read_image( $handle ) );
				return;
			}
			$tail = '';
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 65536 );
				if ( false === $chunk ) {
					throw new RuntimeException( 'The file could not be read for executable-content checks.' );
				}
				$content = $tail . $chunk;
				$this->validate_text( $content );
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
