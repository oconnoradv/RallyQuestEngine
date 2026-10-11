<?php
/** Event-scoped bonus point records and their independent image gallery. */
defined( 'ABSPATH' ) || exit;

final class RQE_Bonus_Recovery_Exception extends RuntimeException {}

final class RQE_Bonus {
	const MAX_RECORDS = 5000;
	const MAX_IMAGES = 200;
	const MAX_IMAGE_SIZE = 10485760;
	const MAX_IMAGE_TOTAL = 104857600;
	const MAX_SHEET_SIZE = 10485760;
	const MAX_ARCHIVE_SIZE = 52428800;
	const MAX_BONUS_POINTS = 2147483647;
	private static $pending_store;
	private static $scheduler;
	private static $gallery_filesystem;
	private static $audit_service;

	public static function configure_adapters( $pending_store = null, $scheduler = null, $filesystem = null, $audit = null ) {
		self::$pending_store = $pending_store;
		self::$scheduler = $scheduler;
		self::$gallery_filesystem = $filesystem;
		self::$audit_service = $audit;
	}

	private static function pending_store() {
		return self::$pending_store ?: ( self::$pending_store = new RQE_Pending_Review_Store() );
	}

	private static function scheduler() {
		return self::$scheduler ?: ( self::$scheduler = new RQE_Import_Scheduler() );
	}

	private static function filesystem() {
		return self::$gallery_filesystem ?: ( self::$gallery_filesystem = new RQE_Gallery_Filesystem() );
	}

	private static function audit_service() {
		return self::$audit_service ?: ( self::$audit_service = new RQE_Import_Audit() );
	}

	public static function storage_root() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			throw new RuntimeException( 'The WordPress upload directory is unavailable: ' . ( isset( $uploads['error'] ) ? $uploads['error'] : 'unknown error' ) );
		}
		return trailingslashit( $uploads['basedir'] ) . 'rqe-event-gallery';
	}

	private static function event_dir( $event_id ) {
		return trailingslashit( self::storage_root() ) . absint( $event_id );
	}

	public static function image_url( $event_id, $image_file ) {
		if ( ! self::valid_image_file( $image_file ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . 'rqe-event-gallery/' . absint( $event_id ) . '/' . rawurlencode( $image_file );
	}

	public static function store_submission_image( $source_path, $original_name, $bonus_id, $event_id, $storage_key ) {
		if ( ! self::valid_id( $bonus_id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $storage_key ) ) {
			throw new RuntimeException( 'The submission image identifier is invalid.' );
		}
		$extension = strtolower( pathinfo( basename( (string) $original_name ), PATHINFO_EXTENSION ) );
		if ( 'jpeg' === $extension ) {
			$extension = 'jpg';
		}
		if ( ! in_array( $extension, array( 'jpg', 'png', 'gif', 'webp' ), true ) ) {
			throw new RuntimeException( 'The email attachment must be a JPEG, PNG, GIF, or WebP image with a matching file extension.' );
		}
		$stage_dir = self::create_stage_directory();
		$images = array();
		$total_size = 0;
		try {
			self::stage_image( $source_path, $bonus_id . '.' . $extension, $stage_dir, $images, $total_size, new RQE_Scan_Session() );
			$image = reset( $images );
			$directory = trailingslashit( self::event_dir( $event_id ) ) . 'submissions';
			self::ensure_directory( $directory );
			$file_name = $storage_key . '.' . pathinfo( $image['image_file'], PATHINFO_EXTENSION );
			$destination = trailingslashit( $directory ) . $file_name;
			if ( ! self::filesystem()->move( $image['stage_path'], $destination ) ) {
				throw new RuntimeException( 'The validated submission image could not be stored.' );
			}
			self::remove_directory( $stage_dir );
			return $file_name;
		} catch ( RuntimeException $exception ) {
			if ( self::filesystem()->is_dir( $stage_dir ) ) {
				self::remove_directory( $stage_dir );
			}
			throw $exception;
		}
	}

	public static function submission_image_url( $event_id, $image_file ) {
		if ( ! preg_match( '/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/D', (string) $image_file ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . 'rqe-event-gallery/' . absint( $event_id ) . '/submissions/' . rawurlencode( $image_file );
	}

	private static function valid_id( $bonus_id ) {
		return is_string( $bonus_id ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $bonus_id );
	}

	private static function valid_image_file( $image_file ) {
		return is_string( $image_file ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.(?:jpg|png|gif|webp)$/D', $image_file );
	}

	private static function ensure_directory( $directory ) {
		if ( ! self::filesystem()->is_dir( $directory ) && ! self::filesystem()->create_tree( $directory ) ) {
			throw new RuntimeException( 'The dedicated event image directory could not be created.' );
		}
		$index = trailingslashit( $directory ) . 'index.php';
		if ( ! self::filesystem()->exists( $index ) && false === self::filesystem()->write( $index, "<?php\n// Silence is golden.\n" ) ) {
			throw new RuntimeException( 'The event image directory could not be protected from directory listing.' );
		}
		$rules = trailingslashit( $directory ) . '.htaccess';
		if ( ! self::filesystem()->exists( $rules ) && false === self::filesystem()->write( $rules, "Options -Indexes\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n" ) ) {
			throw new RuntimeException( 'The event image directory access rules could not be written.' );
		}
	}

	private static function validate_location( $location ) {
		$location = trim( $location );
		if ( '' === $location ) {
			return '';
		}
		if ( ! preg_match( '/^\s*(-?(?:\d+\.?\d*|\.\d+))\s*,\s*(-?(?:\d+\.?\d*|\.\d+))\s*$/D', $location, $matches ) ) {
			throw new RuntimeException( 'Bonus Locations must be a single latitude,longitude pair (for example, 47.6205,-122.3493).' );
		}
		$latitude  = (float) $matches[1];
		$longitude = (float) $matches[2];
		if ( $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
			throw new RuntimeException( 'Bonus Locations contains latitude or longitude outside the valid GPS range.' );
		}
		return number_format( $latitude, 7, '.', '' ) . ',' . number_format( $longitude, 7, '.', '' );
	}

	private static function normalize_header( $header ) {
		$header = strtolower( trim( (string) $header ) );
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', $header );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $header ) );
	}

	private static function columns( $header ) {
		$aliases = array(
			'bonus id'                         => 'bonus_id',
			'bonus description'                => 'description',
			'bonus category'                   => 'category',
			'bonus points'                     => 'points',
			'bonus locations gps coordinates'  => 'locations',
			'bonus locations gps coordinate'   => 'locations',
			'bonus locations'                  => 'locations',
		);
		$columns = array();
		foreach ( $header as $index => $name ) {
			$normalized = self::normalize_header( $name );
			if ( isset( $aliases[ $normalized ] ) ) {
				$columns[ $aliases[ $normalized ] ] = $index;
			}
		}
		foreach ( array( 'bonus_id', 'description', 'category', 'points', 'locations' ) as $required ) {
			if ( ! isset( $columns[ $required ] ) ) {
				throw new RuntimeException( 'The spreadsheet must contain Bonus ID, Bonus Description, Bonus Category, Bonus Points, and Bonus Locations (GPS coordinates) columns.' );
			}
		}
		return $columns;
	}

	public static function normalize_rows( $header, $rows ) {
		$columns = self::columns( $header );
		if ( count( $rows ) > self::MAX_RECORDS ) {
			throw new RuntimeException( 'The spreadsheet exceeds the limit of ' . self::MAX_RECORDS . ' bonus listings.' );
		}
		$normalized = array();
		$seen       = array();
		foreach ( $rows as $line => $row ) {
			if ( ! is_array( $row ) || ! array_filter( $row, static function ( $value ) {
				return '' !== trim( (string) $value );
			} ) ) {
				continue;
			}
			$bonus_id = trim( (string) ( $row[ $columns['bonus_id'] ] ?? '' ) );
			if ( ! self::valid_id( $bonus_id ) ) {
				throw new RuntimeException( 'Invalid Bonus ID on spreadsheet row ' . ( $line + 2 ) . '. Use 1–64 letters, numbers, hyphens, or underscores.' );
			}
			$key = strtolower( $bonus_id );
			if ( isset( $seen[ $key ] ) ) {
				throw new RuntimeException( 'Duplicate Bonus ID "' . $bonus_id . '" on spreadsheet row ' . ( $line + 2 ) . '.' );
			}
			$seen[ $key ] = true;
			$points       = trim( (string) ( $row[ $columns['points'] ] ?? '' ) );
			if ( ! preg_match( '/^\d{1,10}$/D', $points ) || (float) $points > self::MAX_BONUS_POINTS ) {
				throw new RuntimeException( 'Bonus Points must be a non-negative whole number on spreadsheet row ' . ( $line + 2 ) . '.' );
			}
			$description = trim( (string) ( $row[ $columns['description'] ] ?? '' ) );
			$category    = trim( (string) ( $row[ $columns['category'] ] ?? '' ) );
			foreach ( $row as $value ) {
				if ( preg_match( '/^\s*(?:[=+@]|-[^0-9.])/', (string) $value ) ) {
					throw new RuntimeException( 'Spreadsheet formulas or executable cell content are not allowed on row ' . ( $line + 2 ) . '.' );
				}
				if ( preg_match( '/<\?(?:php\b|=)|<script\b|<%/i', (string) $value ) ) {
					throw new RuntimeException( 'Executable cell content is not allowed on row ' . ( $line + 2 ) . '.' );
				}
			}
			if ( strlen( $description ) > 5000 || strlen( $category ) > 120 ) {
				throw new RuntimeException( 'Bonus Description or Bonus Category is too long on spreadsheet row ' . ( $line + 2 ) . '.' );
			}
			$normalized[] = array(
				'bonus_id'    => $bonus_id,
				'description' => sanitize_textarea_field( $description ),
				'category'    => sanitize_text_field( $category ),
				'points'      => (int) $points,
				'locations'   => self::validate_location( (string) ( $row[ $columns['locations'] ] ?? '' ) ),
			);
		}
		return $normalized;
	}

	public static function read_spreadsheet( $path, $extension ) {
		return RQE_Spreadsheet_Reader::read( $path, $extension );
	}

	private static function upload_file( $key, $multiple = false, $files = null ) {
		$files = null === $files ? $_FILES : $files;
		if ( ! isset( $files[ $key ] ) || ! is_array( $files[ $key ] ) ) {
			return array();
		}
		$file = $files[ $key ];
		$fields = array( 'name', 'type', 'tmp_name', 'error', 'size' );
		if ( $multiple ) {
			$files = array();
			foreach ( $fields as $field ) {
				if ( ! isset( $file[ $field ] ) || ! is_array( $file[ $field ] ) ) {
					throw new RuntimeException( 'The uploaded file list is malformed.' );
				}
			}
			$count = count( $file['name'] );
			for ( $i = 0; $i < $count; ++$i ) {
				foreach ( $fields as $field ) {
					if ( ! array_key_exists( $i, $file[ $field ] ) ) {
						throw new RuntimeException( 'The uploaded file list is incomplete.' );
					}
				}
				$files[] = array(
					'name'     => $file['name'][ $i ],
					'type'     => $file['type'][ $i ],
					'tmp_name' => $file['tmp_name'][ $i ],
					'error'    => $file['error'][ $i ],
					'size'     => $file['size'][ $i ],
				);
			}
			return $files;
		}
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $file ) ) {
				throw new RuntimeException( 'The uploaded file details are incomplete.' );
			}
		}
		return array( $file );
	}

	private static function image_id( $name ) {
		$name = basename( str_replace( '\\', '/', (string) $name ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$id   = pathinfo( $name, PATHINFO_FILENAME );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) || ! self::valid_id( $id ) ) {
			throw new RuntimeException( 'Image file names must be the Bonus ID followed by .jpg, .jpeg, .png, .gif, or .webp.' );
		}
		return $id;
	}

	private static function stage_image( $path, $name, $stage_dir, &$images, &$total_size, $scan_session ) {
		$id   = self::image_id( $name );
		$size = self::filesystem()->size( $path );
		if ( false === $size || 0 === $size || $size > self::MAX_IMAGE_SIZE ) {
			throw new RuntimeException( 'Each image must be non-empty and no larger than 10 MB.' );
		}
		if ( $total_size + $size > self::MAX_IMAGE_TOTAL || count( $images ) >= self::MAX_IMAGES ) {
			throw new RuntimeException( 'The image import exceeds the limit of 200 images or 100 MB total.' );
		}
		$info = @getimagesize( $path );
		$mime_extensions = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' );
		if ( ! $info || ! isset( $mime_extensions[ $info['mime'] ] ) ) {
			throw new RuntimeException( 'Only valid JPEG, PNG, GIF, and WebP images can be imported.' );
		}
		$source_extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$extension        = $mime_extensions[ $info['mime'] ];
		if ( 'jpeg' === $source_extension ) {
			$source_extension = 'jpg';
		}
		if ( $source_extension !== $extension ) {
			throw new RuntimeException( 'Image file extension does not match its actual image format: ' . basename( $name ) . '.' );
		}
		$key = strtolower( $id );
		if ( isset( $images[ $key ] ) ) {
			throw new RuntimeException( 'More than one image was provided for Bonus ID "' . $id . '".' );
		}
		$staged_path = trailingslashit( $stage_dir ) . $id . '.' . $extension;
		if ( ! self::filesystem()->copy( $path, $staged_path ) ) {
			throw new RuntimeException( 'An image could not be staged for import.' );
		}
		// Keep only the primary image; appended gain maps, MPF images, trailers, video or padding are discarded before scanning.
		$primary_length = ( new RQE_Executable_Content_Policy() )->primary_image_length( $staged_path );
		if ( null === $primary_length ) {
			throw new RuntimeException( 'Only valid JPEG, PNG, GIF, and WebP images can be imported.' );
		}
		if ( $primary_length < $size ) {
			if ( ! self::filesystem()->truncate( $staged_path, $primary_length ) ) {
				throw new RuntimeException( 'Extra data after the image could not be removed.' );
			}
			$size = $primary_length;
		}
		$scan_session->scan( $staged_path, $name );
		$handle = fopen( $staged_path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'The staged image could not be checked for appended content.' );
		}
		try {
			$valid_end = false;
			if ( 'webp' === $extension ) {
				$header = fread( $handle, 12 );
				$valid_end = 12 === strlen( $header ) && unpack( 'Vsize', substr( $header, 4, 4 ) )['size'] + 8 === $size;
			} else {
				$end_size = 'png' === $extension ? 12 : ( 'jpg' === $extension ? 2 : 1 );
				if ( 0 === fseek( $handle, -$end_size, SEEK_END ) ) {
					$end = fread( $handle, $end_size );
					$valid_end = 'png' === $extension ? "\0\0\0\0IEND\xAE\x42\x60\x82" === $end : ( 'jpg' === $extension ? "\xFF\xD9" === $end : ';' === $end );
				}
			}
			if ( ! $valid_end ) {
				throw new RuntimeException( 'The image is incomplete or contains appended content. Export a fresh image without extra data and scan again.' );
			}
		} finally {
			fclose( $handle );
		}
		$images[ $key ] = array( 'bonus_id' => $id, 'image_file' => $id . '.' . $extension, 'stage_path' => $staged_path );
		$total_size += $size;
	}

	private static function image_attempt( $name, $callback, &$errors ) {
		try {
			$callback();
		} catch ( RuntimeException $exception ) {
			$message = $name . ': ' . $exception->getMessage();
			if ( null === $errors ) {
				throw new RuntimeException( $message );
			}
			$errors[] = $message;
		}
	}

	private static function upload_images( $stage_dir, &$images, &$total_size, &$errors, $scan_session, $files ) {
		foreach ( $files as $file ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				continue;
			}
			self::image_attempt( $file['name'], static function () use ( $file, $stage_dir, &$images, &$total_size, $scan_session ) {
				$upload_errors = array(
					UPLOAD_ERR_INI_SIZE   => 'The image exceeds the server upload_max_filesize limit.',
					UPLOAD_ERR_FORM_SIZE  => 'The image exceeds the form upload size limit.',
					UPLOAD_ERR_PARTIAL    => 'The image was only partially uploaded. Retry the upload.',
					UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is missing.',
					UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image.',
					UPLOAD_ERR_EXTENSION  => 'A server extension stopped the image upload.',
				);
				if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
					throw new RuntimeException( $upload_errors[ (int) $file['error'] ] ?? 'The image could not be uploaded.' );
				}
				if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
					throw new RuntimeException( 'The uploaded image temporary file is unavailable.' );
				}
				self::stage_image( $file['tmp_name'], $file['name'], $stage_dir, $images, $total_size, $scan_session );
			}, $errors );
		}
	}

	private static function remote_get( $url, $limit ) {
		$url = esc_url_raw( trim( $url ) );
		if ( ! $url || ! wp_http_validate_url( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			throw new RuntimeException( 'Enter a valid public HTTPS directory, image, or ZIP URL.' );
		}
		$response = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 3, 'limit_response_size' => min( $limit, self::MAX_ARCHIVE_SIZE ) ) );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'The public image URL could not be downloaded: ' . $response->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'The public image URL returned HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . '.' );
		}
		$content_length = wp_remote_retrieve_header( $response, 'content-length' );
		if ( is_numeric( $content_length ) && (int) $content_length > min( $limit, self::MAX_ARCHIVE_SIZE ) ) {
			throw new RuntimeException( 'The remote image source exceeds the 50 MB download limit.' );
		}
		return $response;
	}

	private static function stage_remote( $url, $stage_dir, &$images, &$total_size, &$errors, $scan_session, $allow_listing = true ) {
		$response = self::remote_get( $url, self::MAX_IMAGE_TOTAL );
		$body     = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_ARCHIVE_SIZE ) {
			throw new RuntimeException( 'The remote image source exceeds the 50 MB download limit.' );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = rawurldecode( basename( $path ) );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', $name ) ) {
			$temp = trailingslashit( $stage_dir ) . '.remote-' . wp_generate_password( 12, false, false );
			if ( false === self::filesystem()->write( $temp, $body ) ) {
				throw new RuntimeException( 'A remote image could not be staged.' );
			}
			self::stage_image( $temp, $name, $stage_dir, $images, $total_size, $scan_session );
			self::filesystem()->remove_file( $temp );
			return;
		}
		if ( preg_match( '/\.zip$/i', $name ) || false !== strpos( $type, 'application/zip' ) || false !== strpos( $type, 'application/x-zip' ) ) {
			$temp = trailingslashit( $stage_dir ) . '.remote-' . wp_generate_password( 12, false, false ) . '.zip';
			if ( false === self::filesystem()->write( $temp, $body ) ) {
				throw new RuntimeException( 'The public ZIP archive could not be staged.' );
			}
			$scan_session->scan( $temp, $name ? $name : 'Remote ZIP archive' );
			self::stage_zip_images( $temp, $stage_dir, $images, $total_size, $errors, $scan_session );
			self::filesystem()->remove_file( $temp );
			return;
		}
		if ( false === strpos( $type, 'text/html' ) && false === strpos( $type, 'text/plain' ) ) {
			throw new RuntimeException( 'The URL must point to an image, ZIP archive, or standard web-server directory listing.' );
		}
		if ( ! $allow_listing ) {
			throw new RuntimeException( 'The image or ZIP link returned a web page instead of a downloadable file.' );
		}
		$matches = array();
		preg_match_all( '/href\s*=\s*(["\'])(.*?)\1/i', $body, $matches );
		$links = array();
		$parts = wp_parse_url( $url );
		foreach ( $matches[2] as $href ) {
			$href = html_entity_decode( trim( $href ), ENT_QUOTES, 'UTF-8' );
			if ( ! preg_match( '/\.(?:jpe?g|png|gif|webp|zip)(?:\?.*)?$/i', $href ) ) {
				continue;
			}
			if ( 0 === strpos( $href, '//' ) ) {
				$href = $parts['scheme'] . ':' . $href;
			} elseif ( ! preg_match( '#^https?://#i', $href ) ) {
				$source_path = isset( $parts['path'] ) ? $parts['path'] : '/';
				$base_path = '/' === substr( $source_path, -1 ) ? $source_path : trailingslashit( dirname( $source_path ) );
				$href      = ( $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) ) . ( 0 === strpos( $href, '/' ) ? $href : $base_path . $href );
			}
			$links[] = $href;
			if ( count( $links ) > self::MAX_IMAGES ) {
				throw new RuntimeException( 'The directory listing contains more than 200 image or archive links.' );
			}
		}
		if ( ! $links ) {
			throw new RuntimeException( 'No direct image or ZIP links were found. Use a public ZIP download or a standard web-server directory listing.' );
		}
		foreach ( array_unique( $links ) as $link ) {
			self::image_attempt( $link, static function () use ( $link, $stage_dir, &$images, &$total_size, &$errors, $scan_session ) {
				self::stage_remote( $link, $stage_dir, $images, $total_size, $errors, $scan_session, false );
			}, $errors );
		}
	}

	private static function stage_zip_images( $path, $stage_dir, &$images, &$total_size, &$errors, $scan_session ) {
		$archive_size = self::filesystem()->size( $path );
		if ( false === $archive_size || $archive_size > self::MAX_ARCHIVE_SIZE ) {
			throw new RuntimeException( 'The ZIP archive must be no larger than 50 MB.' );
		}
		if ( ! class_exists( 'PclZip' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}
		$archive = new PclZip( $path );
		$list    = $archive->listContent();
		if ( ! is_array( $list ) ) {
			throw new RuntimeException( 'The ZIP file is invalid or could not be opened.' );
		}
		$total_uncompressed = 0;
		foreach ( $list as $entry ) {
			$entry_name = isset( $entry['filename'] ) ? $entry['filename'] : '';
			$size       = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			$total_uncompressed += $size;
			if ( $total_uncompressed > self::MAX_IMAGE_TOTAL * 2 ) {
				throw new RuntimeException( 'The ZIP archive expands beyond the allowed image-import size.' );
			}
			if ( ! preg_match( '/\.(?:jpe?g|png|gif|webp)$/i', $entry_name ) ) {
				continue;
			}
			self::image_attempt( $entry_name, static function () use ( $size, $archive, $entry_name, $stage_dir, &$images, &$total_size ) {
				if ( $size > self::MAX_IMAGE_SIZE ) {
					throw new RuntimeException( 'The ZIP image exceeds the 10 MB per-image limit.' );
				}
				$contents = $archive->extract( PCLZIP_OPT_BY_NAME, $entry_name, PCLZIP_OPT_EXTRACT_AS_STRING );
				if ( ! is_array( $contents ) || ! isset( $contents[0]['content'] ) ) {
					throw new RuntimeException( 'The image could not be extracted from the ZIP archive.' );
				}
				$temp = trailingslashit( $stage_dir ) . '.zip-image-' . wp_generate_password( 12, false, false );
				if ( false === self::filesystem()->write( $temp, $contents[0]['content'] ) ) {
					throw new RuntimeException( 'The extracted image could not be staged.' );
				}
				self::stage_image( $temp, basename( $entry_name ), $stage_dir, $images, $total_size, $scan_session );
				self::filesystem()->remove_file( $temp );
			}, $errors );
		}
	}

	private static function make_stage_dir() {
		$root = defined( 'RQE_IMPORT_TEMP_DIR' ) ? RQE_IMPORT_TEMP_DIR : get_temp_dir();
		$root = realpath( $root );
		if ( ! $root || ! is_writable( $root ) ) {
			throw new RuntimeException( 'A writable private import temporary directory is required.' );
		}
		$uploads = wp_upload_dir();
		foreach ( array( ABSPATH, $uploads['basedir'], defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $public_root ) {
			$public_root = $public_root ? realpath( $public_root ) : false;
			if ( $public_root && ( strtolower( $root ) === strtolower( $public_root ) || 0 === strpos( strtolower( wp_normalize_path( $root ) ) . '/', strtolower( trailingslashit( wp_normalize_path( $public_root ) ) ) ) ) ) {
				throw new RuntimeException( 'RQE_IMPORT_TEMP_DIR must be outside the web root and gallery uploads directory.' );
			}
		}
		$stage_dir = trailingslashit( $root ) . 'rqe-bonus-' . wp_generate_password( 32, false, false );
		if ( ! self::filesystem()->make_directory( $stage_dir, 0700 ) ) {
			throw new RuntimeException( 'A temporary image staging directory could not be created.' );
		}
		return $stage_dir;
	}

	public static function create_stage_directory() {
		return self::make_stage_dir();
	}

	private static function pending_key( $token ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) ) {
			throw new RuntimeException( 'The import review identifier is invalid.' );
		}
		return 'rqe_bonus_pending_' . get_current_user_id() . '_' . $token;
	}

	public static function check_staging() {
		try {
			$directory = self::create_stage_directory();
			if ( ! self::filesystem()->remove_empty_directory( $directory ) ) {
				throw new RuntimeException( 'The empty diagnostic staging directory could not be removed.' );
			}
			return array( 'passed' => true, 'message' => 'A private staging directory was created and removed successfully.' );
		} catch ( RuntimeException $exception ) {
			return array( 'passed' => false, 'message' => $exception->getMessage() );
		}
	}

	public static function bypass_enabled() {
		return self::audit_service()->bypass_enabled();
	}

	private static function audit( $event_id, $action, $detail ) {
		self::audit_service()->record( $event_id, $action, $detail );
	}

	public static function revoke_bypass( $event_id ) {
		self::audit( $event_id, 'bypass_disabled', 'Administrator restored mandatory ClamAV scanning for their imports.' );
	}

	public static function audit_deletion( $event_id, $operation, $detail ) {
		self::audit( $event_id, $operation, $detail );
	}

	public static function enable_bypass( $event_id, $confirmed = null ) {
		if ( null === $confirmed ) {
			$confirmed = '1' === RQE_Admin::posted( 'bypass_confirmation' );
		}
		self::audit_service()->enable_bypass( $event_id, true === $confirmed || '1' === $confirmed );
	}

	public static function pending( $event_id, $token ) {
		$pending = self::pending_store()->get( self::pending_key( $token ) );
		if ( ! $pending || (int) $pending['event_id'] !== (int) $event_id || $pending['expires'] <= time() ) {
			throw new RuntimeException( 'This import review expired or belongs to another user/event. Scan the files again.' );
		}
		return $pending;
	}

	public static function prepare_review( $event_id, $rows, $images, $stage_dir, $errors, $partial, $scan_session, $step ) {
		$token = wp_generate_password( 32, false, false );
		$hashes = array();
		foreach ( $images as $image ) {
			$hash = self::filesystem()->hash( 'sha256', $image['stage_path'] );
			if ( false === $hash ) {
				throw new RuntimeException( 'A staged image could not be fingerprinted. Import is blocked.' );
			}
			$hashes[ $image['stage_path'] ] = $hash;
		}
		foreach ( self::filesystem()->files( trailingslashit( $stage_dir ) . '.listing-*' ) as $path ) {
			$hash = self::filesystem()->hash( 'sha256', $path );
			if ( false === $hash ) {
				throw new RuntimeException( 'The staged listing could not be fingerprinted. Import is blocked.' );
			}
			$hashes[ $path ] = $hash;
		}
		$pending = array(
			'event_id' => $event_id, 'rows' => $rows, 'images' => $images,
			'stage_dir' => $stage_dir, 'hashes' => $hashes, 'errors' => $errors,
			'partial' => $partial, 'scans' => $scan_session->results(),
			'import_step' => 'listing' === $step ? 'listing' : 'images',
			'unscanned' => $scan_session->skipped(),
			'can_approve' => ! $scan_session->failed() && ( $rows || $images ) && ( $partial || ! $errors ),
			'expires' => time() + 15 * MINUTE_IN_SECONDS,
		);
		if ( ! self::pending_store()->put( self::pending_key( $token ), $pending, $pending['expires'] ) ) {
			throw new RuntimeException( 'The import scan report could not be stored. No data was imported.' );
		}
		$scheduled = self::scheduler()->schedule( $pending['expires'], 'rqe_bonus_expire', array( get_current_user_id(), $token, $stage_dir ) );
		if ( is_wp_error( $scheduled ) || false === $scheduled ) {
			self::pending_store()->remove( self::pending_key( $token ) );
			throw new RuntimeException( 'Private import cleanup could not be scheduled. No data was imported.' );
		}
		return array( 'message' => $pending['unscanned'] ? 'ClamAV is unavailable. No antivirus security scan was performed. Review the validation results and confirm the bypass before importing.' : 'Scan complete. Review the results and approve before importing. No event data has been changed.', 'errors' => array(), 'pending_token' => $token );
	}

	public static function expire( $user_id, $token, $stage_dir ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) || ! preg_match( '/^rqe-bonus-[A-Za-z0-9]{32}$/D', basename( $stage_dir ) ) ) {
			throw new RuntimeException( 'Invalid private import cleanup target.' );
		}
		if ( self::filesystem()->is_dir( $stage_dir ) ) {
			if ( self::filesystem()->files( trailingslashit( $stage_dir ) . '.backup-*' ) ) {
				error_log( 'RQE: import recovery backups retained in ' . $stage_dir );
				return;
			}
			$lock = trailingslashit( $stage_dir ) . '.approval-lock';
			if ( self::filesystem()->is_file( $lock ) && self::filesystem()->modified_time( $lock ) > time() - 300 ) {
				$scheduled = self::scheduler()->schedule( time() + 300, 'rqe_bonus_expire', array( $user_id, $token, $stage_dir ) );
				if ( is_wp_error( $scheduled ) || false === $scheduled ) {
					error_log( 'RQE: cleanup rescheduling failed for active import ' . $stage_dir );
				}
				return;
			}
			self::remove_directory( $stage_dir );
		}
		self::pending_store()->remove( 'rqe_bonus_pending_' . absint( $user_id ) . '_' . $token );
	}

	public static function approve( $event_id, $token, $cancel = false, $confirm_bypass = false ) {
		$pending = self::pending( $event_id, $token );
		if ( ! $cancel && ! empty( $pending['unscanned'] ) && ! $confirm_bypass && ! self::bypass_enabled() ) {
			throw new RuntimeException( 'Explicitly confirm importing without an antivirus security scan before proceeding.' );
		}
		$lock = trailingslashit( $pending['stage_dir'] ) . '.approval-lock';
		$handle = @fopen( $lock, 'x' );
		if ( ! $handle ) {
			throw new RuntimeException( 'This import is already processing or has been consumed. Refresh the gallery.' );
		}
		fclose( $handle );
		self::pending_store()->remove( self::pending_key( $token ) );
		if ( $cancel ) {
			self::remove_directory( $pending['stage_dir'] );
			return array( 'message' => 'Import cancelled. Staged files were removed; no event data was changed.', 'errors' => array() );
		}
		if ( ! $pending['can_approve'] ) {
			self::remove_directory( $pending['stage_dir'] );
			throw new RuntimeException( 'This scan report is blocked. Correct the scan errors and scan again.' );
		}
		$imported = 0;
		try {
			foreach ( $pending['hashes'] as $path => $hash ) {
				if ( ! self::filesystem()->is_file( $path ) || self::filesystem()->is_link( $path ) || ! hash_equals( $hash, (string) self::filesystem()->hash( 'sha256', $path ) ) ) {
					throw new RuntimeException( 'A staged file changed after scanning. Import is blocked; scan again.' );
				}
				if ( ! empty( $pending['unscanned'] ) ) {
					if ( $confirm_bypass ) {
						self::audit( $event_id, 'bypass_enabled', 'Administrator confirmed importing without an antivirus security scan when ClamAV is unavailable; remembered for this administrator on this site.' );
					}
					self::audit( $event_id, 'import_started', 'No antivirus security scan performed. Importing ' . count( $pending['rows'] ) . ' bonus listings and ' . count( $pending['images'] ) . ' validated images.' );
				}
			}
			if ( $pending['partial'] ) {
				foreach ( $pending['images'] as $image ) {
					try {
						self::apply_import( $event_id, array(), array( $image ), $pending['stage_dir'], false );
						++$imported;
					} catch ( RQE_Bonus_Recovery_Exception $exception ) {
						throw $exception;
					} catch ( RuntimeException $exception ) {
						if ( ! empty( $pending['unscanned'] ) ) {
							try {
								self::audit( $event_id, 'import_failed', 'No antivirus security scan performed. ' . $imported . ' images saved before stopping. ' . $exception->getMessage() );
							} catch ( RuntimeException $audit_exception ) {
								error_log( 'RQE: unscanned import failure audit could not be saved for event ' . $event_id . ', user ' . get_current_user_id() );
							}
						}
						$pending['errors'][] = $image['image_file'] . ': ' . $exception->getMessage();
					}
				}
				self::remove_directory( $pending['stage_dir'] );
			} else {
				self::apply_import( $event_id, $pending['rows'], $pending['images'], $pending['stage_dir'] );
				$imported = count( $pending['images'] );
			}
		} catch ( RuntimeException $exception ) {
			if ( ! ( $exception instanceof RQE_Bonus_Recovery_Exception ) && self::filesystem()->is_dir( $pending['stage_dir'] ) ) {
				self::remove_directory( $pending['stage_dir'] );
			}
			$message = $imported . ' images saved before stopping. ' . $exception->getMessage();
			if ( $exception instanceof RQE_Bonus_Recovery_Exception ) {
				throw new RQE_Bonus_Recovery_Exception( $message );
			}
			throw new RuntimeException( $message );
		}
		$message = 'Imported or updated ' . count( $pending['rows'] ) . ' bonus listings and ' . $imported . ' event gallery images. ' . count( $pending['errors'] ) . ' image/source errors.';
		if ( ! empty( $pending['unscanned'] ) ) {
			$message .= ' WARNING: imported without an antivirus security scan because ClamAV was unavailable. Format and executable-content checks were retained; bypass recorded in the audit log.';
			try {
				self::audit( $event_id, 'import_completed', $message );
			} catch ( RuntimeException $exception ) {
				throw new RuntimeException( $message . ' The completion audit could not be saved; the pre-import audit remains. Do not retry without checking the event data.' );
			}
		}
		return array( 'message' => $message, 'errors' => $pending['errors'] );
	}

	private static function apply_import( $event_id, $rows, $images, $stage_dir, $cleanup = true ) {
		global $wpdb;
		$event_dir = $images ? self::event_dir( $event_id ) : '';
		if ( $images ) {
			self::ensure_directory( $event_dir );
		}
		$backups = array();
		$installed = array();
		RQE::begin_transaction();
		try {
			foreach ( $rows as $row ) {
				$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', $event_id, $row['bonus_id'] ) );
				$data = array(
					'description' => $row['description'],
					'category'    => $row['category'],
					'points'      => $row['points'],
					'locations'   => $row['locations'],
				);
				if ( $existing ) {
					if ( false === $wpdb->update( RQE::table( 'bonus_points' ), $data, array( 'event_id' => $event_id, 'bonus_id' => $row['bonus_id'] ) ) ) {
						throw new RuntimeException( 'A bonus listing could not be updated. No import was saved.' );
					}
				} else {
					if ( false === $wpdb->insert( RQE::table( 'bonus_points' ), array_merge( array( 'event_id' => $event_id, 'bonus_id' => $row['bonus_id'] ), $data ) ) ) {
						throw new RuntimeException( 'A bonus listing could not be added. No import was saved.' );
					}
				}
			}
			$index = 0;
			foreach ( $images as $image ) {
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,image_file FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', $event_id, $image['bonus_id'] ), ARRAY_A );
				if ( ! $existing ) {
					if ( false === $wpdb->insert( RQE::table( 'bonus_points' ), array( 'event_id' => $event_id, 'bonus_id' => $image['bonus_id'], 'description' => '' ) ) ) {
						throw new RuntimeException( 'A bonus gallery record could not be created.' );
					}
					$existing = array( 'id' => $wpdb->insert_id, 'image_file' => '' );
				}
				$old_file = $existing['image_file'];
				$new_file = $image['image_file'];
				foreach ( array_unique( array_filter( array( $old_file, $new_file ) ) ) as $file_name ) {
					if ( ! self::valid_image_file( $file_name ) ) {
						throw new RuntimeException( 'An existing gallery image name is invalid; no import was saved.' );
					}
					$old_path = trailingslashit( $event_dir ) . $file_name;
					if ( self::filesystem()->is_file( $old_path ) ) {
						$backup_path = trailingslashit( $stage_dir ) . '.backup-' . $index++;
						if ( ! self::filesystem()->move( $old_path, $backup_path ) ) {
							throw new RuntimeException( 'An existing gallery image could not be backed up before replacement.' );
						}
						$backups[ $old_path ] = $backup_path;
					}
				}
				$destination = trailingslashit( $event_dir ) . $new_file;
				if ( ! self::filesystem()->move( $image['stage_path'], $destination ) ) {
					throw new RuntimeException( $image['image_file'] . ': The image could not be installed in the event gallery.' );
				}
				$installed[] = $destination;
				if ( false === $wpdb->update( RQE::table( 'bonus_points' ), array( 'image_file' => $new_file ), array( 'id' => $existing['id'], 'event_id' => $event_id ) ) ) {
					throw new RuntimeException( $image['image_file'] . ': The image could not be linked to its bonus listing.' );
				}
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! RQE::rollback_transaction() ) {
				throw new RQE_Bonus_Recovery_Exception( 'The import failed and the database rollback failed. Gallery backups have been retained for recovery.' );
			}
			foreach ( $installed as $path ) {
				if ( self::filesystem()->is_file( $path ) && ! self::filesystem()->remove_file( $path ) ) {
					throw new RQE_Bonus_Recovery_Exception( 'The import failed and a newly installed image could not be removed: ' . basename( $path ) );
				}
			}
			foreach ( $backups as $original => $backup ) {
				if ( self::filesystem()->is_file( $backup ) && ! self::filesystem()->move( $backup, $original ) ) {
					throw new RQE_Bonus_Recovery_Exception( 'The import failed and a previous gallery image could not be restored: ' . basename( $original ) );
				}
			}
			if ( $stage_dir && $cleanup ) {
				self::remove_directory( $stage_dir );
			}
			throw $exception;
		}
		foreach ( $backups as $backup ) {
			if ( self::filesystem()->is_file( $backup ) && ! self::filesystem()->remove_file( $backup ) ) {
				throw new RQE_Bonus_Recovery_Exception( 'The import completed, but an old replaced image could not be removed from staging.' );
			}
		}
		if ( $stage_dir && $cleanup ) {
			self::remove_directory( $stage_dir );
		}
		return 'Imported or updated ' . count( $rows ) . ' bonus listings and ' . count( $images ) . ' event gallery images.';
	}

	public static function import( $event_id ) {
		return self::import_with_input( $event_id, self::legacy_import_input() );
	}

	public static function import_with_input( $event_id, $input ) {
		$result = self::prepare_import( $event_id, $input );
		$pending = self::pending( $event_id, $result['pending_token'] );
		if ( ! empty( $pending['unscanned'] ) && $pending['can_approve'] && self::bypass_enabled() ) {
			return self::approve( $event_id, $result['pending_token'] );
		}
		return $result;
	}

	private static function legacy_save_input() {
		$keys = array( 'new_bonus_id', 'description', 'category', 'points', 'locations', 'remove_image' );
		$input = array( 'files' => $_FILES );
		foreach ( $keys as $key ) {
			$input[ $key ] = RQE_Admin::posted( $key );
		}
		return $input;
	}

	private static function legacy_import_input() {
		$input = array(
			'import_step' => RQE_Admin::posted( 'import_step' ),
			'gallery_url' => RQE_Admin::posted( 'gallery_url' ),
			'image_source' => RQE_Admin::posted( 'image_source' ),
			'allow_partial_images' => RQE_Admin::posted( 'allow_partial_images' ),
			'files' => $_FILES,
		);
		return $input;
	}

	private static function prepare_import( $event_id, $input = null ) {
		$input = null === $input ? self::legacy_import_input() : $input;
		$scan_session = new RQE_Scan_Session( true );
		$step = (string) ( $input['import_step'] ?? '' );
		if ( ! in_array( $step, array( 'listing', 'images' ), true ) ) {
			throw new RuntimeException( 'Choose step 1 to import listings or step 2 to import images.' );
		}
		$sheet_files = self::upload_file( 'bonus_file', false, $input['files'] ?? array() );
		$sheet = $sheet_files ? $sheet_files[0] : null;
		$remote_url = trim( (string) ( $input['gallery_url'] ?? '' ) );
		$uploaded = 'images' === $step && 'url' === ( $input['image_source'] ?? '' ) ? array() : self::upload_file( 'bonus_images', true, $input['files'] ?? array() );
		if ( 'images' === $step && 'url' === ( $input['image_source'] ?? '' ) ) {
			if ( '' === $remote_url ) {
				throw new RuntimeException( 'Enter a public HTTPS image, ZIP, or directory-listing URL before choosing Load by URL.' );
			}
			$uploaded = array();
		}
		$has_uploads = false;
		foreach ( $uploaded as $file ) {
			if ( UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
				$has_uploads = true;
				break;
			}
		}
		if ( 'listing' === $step && ( $has_uploads || '' !== $remote_url ) ) {
			throw new RuntimeException( 'Step 1 accepts a CSV/XLSX listing only. Import images separately in step 2.' );
		}
		if ( 'images' === $step && $sheet && UPLOAD_ERR_NO_FILE !== (int) $sheet['error'] ) {
			throw new RuntimeException( 'Import the spreadsheet in step 1, not in the image import.' );
		}
		$rows = array();
		if ( $sheet && UPLOAD_ERR_NO_FILE !== (int) $sheet['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $sheet['error'] || ! is_uploaded_file( $sheet['tmp_name'] ) ) {
				throw new RuntimeException( 'The CSV or XLSX file could not be uploaded.' );
			}
			if ( (int) $sheet['size'] > self::MAX_SHEET_SIZE ) {
				throw new RuntimeException( 'The CSV or XLSX file must be no larger than 10 MB.' );
			}
			$stage_dir = self::create_stage_directory();
			$extension = strtolower( pathinfo( $sheet['name'], PATHINFO_EXTENSION ) );
			$sheet_path = trailingslashit( $stage_dir ) . '.listing-input';
			try {
				if ( ! self::filesystem()->copy( $sheet['tmp_name'], $sheet_path ) ) {
					throw new RuntimeException( 'The listing could not be placed in private staging.' );
				}
				$scan_session->scan( $sheet_path, $sheet['name'] );
				list( $header, $sheet_rows ) = RQE_Spreadsheet_Reader::read( $sheet_path, $extension );
				$rows = self::normalize_rows( $header, $sheet_rows );
				if ( ! $rows ) {
					throw new RuntimeException( 'The listing contains no bonus rows. Add bonus data and scan again.' );
				}
			} catch ( RuntimeException $exception ) {
				try {
					return self::prepare_review( $event_id, array(), array(), $stage_dir, array( $sheet['name'] . ': ' . $exception->getMessage() ), false, $scan_session, $step );
				} catch ( RuntimeException $prepare_exception ) {
					self::remove_directory( $stage_dir );
					throw $prepare_exception;
				}
			}
		}
		if ( ! $rows && ! $has_uploads && '' === $remote_url ) {
			throw new RuntimeException( 'Choose a CSV/XLSX file, a local image folder, or a public image URL.' );
		}
		if ( 'listing' === $step ) {
			if ( ! $rows || $has_uploads || '' !== $remote_url ) {
				if ( isset( $stage_dir ) ) {
					self::remove_directory( $stage_dir );
				}
				throw new RuntimeException( 'Step 1 requires a non-empty CSV/XLSX listing only. Import images separately in step 2.' );
			}
			try {
				return self::prepare_review( $event_id, $rows, array(), $stage_dir, array(), false, $scan_session, $step );
			} catch ( RuntimeException $exception ) {
				self::remove_directory( $stage_dir );
				throw $exception;
			}
		}
		$stage_dir = $has_uploads || '' !== $remote_url ? self::create_stage_directory() : '';
		$images = array();
		$total_size = 0;
		$partial = '1' === ( $input['allow_partial_images'] ?? '' );
		$errors = array();
		try {
			if ( 'url' !== ( $input['image_source'] ?? '' ) ) {
				self::upload_images( $stage_dir, $images, $total_size, $errors, $scan_session, $uploaded );
			}
			if ( '' !== $remote_url ) {
				self::image_attempt( $remote_url, static function () use ( $remote_url, $stage_dir, &$images, &$total_size, &$errors, $scan_session ) {
					self::stage_remote( $remote_url, $stage_dir, $images, $total_size, $errors, $scan_session );
				}, $errors );
			}
			if ( ! $images && ! $errors ) {
				$errors[] = 'No supported images were found in the selected source.';
			}
			return self::prepare_review( $event_id, array(), $images, $stage_dir, $errors, $partial, $scan_session, $step );
		} catch ( RuntimeException $exception ) {
			if ( $stage_dir && self::filesystem()->is_dir( $stage_dir ) ) {
				self::remove_directory( $stage_dir );
			}
			throw $exception;
		}
	}

	public static function save( $event_id, $bonus_id, $input = null ) {
		global $wpdb;
		$input = null === $input ? self::legacy_save_input() : $input;
		$scan_session = new RQE_Scan_Session();
		if ( ! self::valid_id( $bonus_id ) ) {
			throw new RuntimeException( 'The Bonus ID is invalid.' );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,image_file FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', $event_id, $bonus_id ), ARRAY_A );
		if ( ! $existing ) {
			throw new RuntimeException( 'The selected bonus listing no longer exists.' );
		}
		$new_bonus_id = RQE_Admin::text( $input['new_bonus_id'] ?? '', 64, true );
		if ( ! self::valid_id( $new_bonus_id ) ) {
			throw new RuntimeException( 'Bonus ID must use 1–64 letters, numbers, hyphens, or underscores.' );
		}
		$duplicate = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s AND id<>%d', $event_id, $new_bonus_id, $existing['id'] ) );
		if ( $duplicate ) {
			throw new RuntimeException( 'Another bonus listing already uses that Bonus ID in this event.' );
		}
		$description = sanitize_textarea_field( RQE_Admin::text( $input['description'] ?? '', 5000 ) );
		$category    = RQE_Admin::text( $input['category'] ?? '', 120 );
		$points      = RQE_Admin::integer( $input['points'] ?? '', 0, self::MAX_BONUS_POINTS );
		$locations   = self::validate_location( RQE_Admin::text( $input['locations'] ?? '', 80 ) );
		$remove_image = '1' === ( $input['remove_image'] ?? '' );
		$image_files = self::upload_file( 'bonus_image', false, $input['files'] ?? array() );
		$image_file  = $image_files ? $image_files[0] : null;
		$has_image   = $image_file && UPLOAD_ERR_NO_FILE !== (int) $image_file['error'];
		if ( $has_image && $remove_image ) {
			throw new RuntimeException( 'Choose either a replacement image or remove the current image, not both.' );
		}
		$stage_dir = '';
		$images = array();
		$total_size = 0;
		if ( $has_image ) {
			if ( UPLOAD_ERR_OK !== (int) $image_file['error'] || ! is_uploaded_file( $image_file['tmp_name'] ) ) {
				throw new RuntimeException( 'The replacement image could not be uploaded.' );
			}
			$image_id = self::image_id( $image_file['name'] );
			if ( strtolower( $image_id ) !== strtolower( $new_bonus_id ) ) {
				throw new RuntimeException( 'The image file name must match Bonus ID "' . $new_bonus_id . '".' );
			}
			$stage_dir = self::create_stage_directory();
			try {
				self::stage_image( $image_file['tmp_name'], $image_file['name'], $stage_dir, $images, $total_size, $scan_session );
			} catch ( RuntimeException $exception ) {
				self::remove_directory( $stage_dir );
				throw $exception;
			}
		}
		$data = array( 'bonus_id' => $new_bonus_id, 'description' => $description, 'category' => $category, 'points' => $points, 'locations' => $locations );
		if ( $remove_image ) {
			$data['image_file'] = '';
		}
		elseif ( $existing['image_file'] && $new_bonus_id !== $bonus_id ) {
			$extension = pathinfo( $existing['image_file'], PATHINFO_EXTENSION );
			$data['image_file'] = $new_bonus_id . '.' . $extension;
		}
		if ( $existing['image_file'] && ! self::valid_image_file( $existing['image_file'] ) ) {
			if ( $stage_dir && self::filesystem()->is_dir( $stage_dir ) ) {
				self::remove_directory( $stage_dir );
			}
			throw new RuntimeException( 'The existing bonus image name is invalid and cannot be edited safely.' );
		}
		$event_dir = $existing['image_file'] || $has_image ? self::event_dir( $event_id ) : '';
		$old_file = $existing['image_file'];
		$old_path = $old_file ? trailingslashit( $event_dir ) . $old_file : '';
		if ( $old_file && ! $remove_image && ! $has_image && $new_bonus_id !== $bonus_id && ! self::filesystem()->is_file( $old_path ) ) {
			if ( $stage_dir && self::filesystem()->is_dir( $stage_dir ) ) {
				self::remove_directory( $stage_dir );
			}
			throw new RuntimeException( 'The existing gallery image is missing. Upload a replacement or remove the image before renaming this Bonus ID.' );
		}
		$destination = '';
		$backup = '';
		$installed = false;
		try {
			if ( $has_image || ( $old_file && ! $remove_image && $new_bonus_id !== $bonus_id ) ) {
				if ( ! $stage_dir ) {
					$stage_dir = self::create_stage_directory();
				}
				self::ensure_directory( $event_dir );
				$new_file = $has_image
					? $new_bonus_id . '.' . pathinfo( reset( $images )['image_file'], PATHINFO_EXTENSION )
					: $data['image_file'];
				$destination = trailingslashit( $event_dir ) . $new_file;
				if ( $old_path && self::filesystem()->is_file( $old_path ) ) {
					$backup = trailingslashit( $stage_dir ) . '.backup';
					if ( ! self::filesystem()->move( $old_path, $backup ) ) {
						throw new RuntimeException( 'The existing bonus image could not be backed up for replacement.' );
					}
				}
				if ( $has_image ) {
					$image = reset( $images );
					$image['image_file'] = $new_bonus_id . '.' . pathinfo( $image['image_file'], PATHINFO_EXTENSION );
					if ( ! self::filesystem()->move( $image['stage_path'], $destination ) ) {
						throw new RuntimeException( 'The replacement image could not be installed.' );
					}
					$installed = true;
					$data['image_file'] = $image['image_file'];
				} elseif ( $backup ) {
					if ( ! self::filesystem()->copy( $backup, $destination ) ) {
						throw new RuntimeException( 'The existing bonus image could not be renamed.' );
					}
					$installed = true;
				}
			}
			RQE::begin_transaction();
			if ( false === $wpdb->update( RQE::table( 'bonus_points' ), $data, array( 'id' => $existing['id'], 'event_id' => $event_id ) ) ) {
				throw new RuntimeException( 'The bonus listing could not be saved.' );
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			RQE::rollback_transaction();
			if ( $installed && self::filesystem()->is_file( $destination ) ) {
				if ( $backup ) {
					if ( ! self::filesystem()->remove_file( $destination ) || ! self::filesystem()->move( $backup, $old_path ) ) {
						throw new RuntimeException( 'The bonus edit failed and its previous image could not be restored.' );
					}
				} elseif ( ! $has_image || ! self::filesystem()->remove_file( $destination ) ) {
					throw new RuntimeException( 'The bonus edit failed and its replacement image could not be removed.' );
				}
			} elseif ( $backup && self::filesystem()->is_file( $backup ) && ! self::filesystem()->move( $backup, $old_path ) ) {
				throw new RuntimeException( 'The bonus edit failed and its previous image could not be restored.' );
			}
			if ( $stage_dir && self::filesystem()->is_dir( $stage_dir ) ) {
				self::remove_directory( $stage_dir );
			}
			throw $exception;
		}
		if ( $old_path && ! $backup && self::filesystem()->is_file( $old_path ) && $remove_image ) {
			if ( ! self::filesystem()->remove_file( $old_path ) ) {
				throw new RuntimeException( 'The bonus listing was saved, but its previous image could not be removed.' );
			}
		}
		if ( $has_image && $backup && self::filesystem()->is_file( $backup ) && ! self::filesystem()->remove_file( $backup ) ) {
			throw new RuntimeException( 'The bonus listing was saved, but its replaced image could not be removed from staging.' );
		}
		if ( $stage_dir && self::filesystem()->is_dir( $stage_dir ) ) {
			self::remove_directory( $stage_dir );
		}
	}

	private static function remove_directory( $directory ) {
		self::filesystem()->remove_directory( $directory );
	}

	public static function stage_event_storage_removal( $event_id ) {
		$directory = self::event_dir( $event_id );
		if ( ! self::filesystem()->is_dir( $directory ) ) {
			return '';
		}
		$staged = $directory . '.deleting-' . wp_generate_password( 12, false, false );
		if ( ! self::filesystem()->move( $directory, $staged ) ) {
			throw new RuntimeException( 'The selected event gallery could not be safely staged for deletion.' );
		}
		return $staged;
	}

	public static function stage_submission_files( array $rows ) {
		$staged_root = trailingslashit( self::storage_root() ) . '.submission-deleting-' . wp_generate_password( 12, false, false );
		$moved = array();
		foreach ( $rows as $row ) {
			$event_id = absint( $row['event_id'] ?? 0 );
			$image_file = (string) ( $row['image_file'] ?? '' );
			if ( ! $event_id || ! preg_match( '/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/D', $image_file ) ) {
				continue;
			}
			$source = trailingslashit( self::event_dir( $event_id ) ) . 'submissions/' . $image_file;
			if ( ! self::filesystem()->is_file( $source ) ) {
				continue;
			}
			$directory = trailingslashit( $staged_root ) . $event_id;
			self::ensure_directory( $directory );
			$destination = trailingslashit( $directory ) . $image_file;
			if ( ! self::filesystem()->move( $source, $destination ) ) {
				self::restore_submission_files( array( 'root' => $staged_root, 'files' => $moved ) );
				throw new RuntimeException( 'A participant submission image could not be safely staged for deletion.' );
			}
			$moved[] = array( 'source' => $source, 'staged' => $destination );
		}
		return array( 'root' => $moved ? $staged_root : '', 'files' => $moved );
	}

	public static function restore_submission_files( array $staged ) {
		foreach ( array_reverse( $staged['files'] ?? array() ) as $file ) {
			self::ensure_directory( dirname( $file['source'] ) );
			if ( self::filesystem()->is_file( $file['staged'] ) && ( self::filesystem()->exists( $file['source'] ) || ! self::filesystem()->move( $file['staged'], $file['source'] ) ) ) {
				throw new RuntimeException( 'A participant submission image could not be restored after deletion failed.' );
			}
		}
		if ( ! empty( $staged['root'] ) && self::filesystem()->is_dir( $staged['root'] ) ) {
			self::remove_directory( $staged['root'] );
		}
	}

	public static function finish_staged_submission_files( array $staged ) {
		if ( ! empty( $staged['root'] ) && self::filesystem()->is_dir( $staged['root'] ) ) {
			self::remove_directory( $staged['root'] );
		}
	}

	public static function stage_all_storage_removal() {
		$directory = self::storage_root();
		if ( ! self::filesystem()->is_dir( $directory ) ) {
			return '';
		}
		$staged = $directory . '.deleting-' . wp_generate_password( 12, false, false );
		if ( ! self::filesystem()->move( $directory, $staged ) ) {
			throw new RuntimeException( 'The event galleries could not be safely staged for deletion.' );
		}
		return $staged;
	}

	public static function restore_event_storage( $event_id, $staged ) {
		if ( ! $staged || ! self::filesystem()->is_dir( $staged ) ) {
			return;
		}
		$directory = self::event_dir( $event_id );
		if ( self::filesystem()->exists( $directory ) || ! self::filesystem()->move( $staged, $directory ) ) {
			throw new RuntimeException( 'The selected event gallery could not be restored after the deletion failed.' );
		}
	}

	public static function restore_all_storage( $staged ) {
		if ( ! $staged || ! self::filesystem()->is_dir( $staged ) ) {
			return;
		}
		$directory = self::storage_root();
		if ( self::filesystem()->exists( $directory ) || ! self::filesystem()->move( $staged, $directory ) ) {
			throw new RuntimeException( 'The event galleries could not be restored after the deletion failed.' );
		}
	}

	public static function finish_staged_storage_removal( $staged ) {
		if ( $staged ) {
			self::remove_directory( $staged );
		}
	}

	public static function remove_all_storage() {
		$root = self::storage_root();
		self::remove_directory( $root );
	}
}
