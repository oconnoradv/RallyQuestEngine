<?php
/** Event-scoped bonus point records and their independent image gallery. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Bonus {
	const MAX_RECORDS = 5000;
	const MAX_IMAGES = 200;
	const MAX_IMAGE_SIZE = 10485760;
	const MAX_IMAGE_TOTAL = 104857600;
	const MAX_SHEET_SIZE = 10485760;
	const MAX_ARCHIVE_SIZE = 52428800;
	const MAX_BONUS_POINTS = 2147483647;

	public static function storage_root() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			throw new RuntimeException( 'The WordPress upload directory is unavailable: ' . ( isset( $uploads['error'] ) ? $uploads['error'] : 'unknown error' ) );
		}
		return trailingslashit( $uploads['basedir'] ) . 'mrsa-event-gallery';
	}

	private static function event_dir( $event_id ) {
		return trailingslashit( self::storage_root() ) . absint( $event_id );
	}

	public static function image_url( $event_id, $image_file ) {
		if ( ! self::valid_image_file( $image_file ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . 'mrsa-event-gallery/' . absint( $event_id ) . '/' . rawurlencode( $image_file );
	}

	private static function valid_id( $bonus_id ) {
		return is_string( $bonus_id ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $bonus_id );
	}

	private static function valid_image_file( $image_file ) {
		return is_string( $image_file ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\.(?:jpg|png|gif|webp)$/D', $image_file );
	}

	private static function ensure_directory( $directory ) {
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( 'The dedicated event image directory could not be created.' );
		}
		$index = trailingslashit( $directory ) . 'index.php';
		if ( ! file_exists( $index ) && false === file_put_contents( $index, "<?php\n// Silence is golden.\n" ) ) {
			throw new RuntimeException( 'The event image directory could not be protected from directory listing.' );
		}
		$rules = trailingslashit( $directory ) . '.htaccess';
		if ( ! file_exists( $rules ) && false === file_put_contents( $rules, "Options -Indexes\n<FilesMatch \"\\.php$\">\nRequire all denied\n</FilesMatch>\n" ) ) {
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

	private static function normalize_rows( $header, $rows ) {
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

	private static function read_csv( $path ) {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new RuntimeException( 'The CSV file could not be opened.' );
		}
		$header = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $handle );
			throw new RuntimeException( 'The CSV file is empty or unreadable.' );
		}
		$rows = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			$rows[] = $row;
			if ( count( $rows ) > self::MAX_RECORDS ) {
				fclose( $handle );
				throw new RuntimeException( 'The spreadsheet exceeds the limit of ' . self::MAX_RECORDS . ' bonus listings.' );
			}
		}
		fclose( $handle );
		return self::normalize_rows( $header, $rows );
	}

	private static function extract_zip_entry( $archive, $name, $list, $max_size ) {
		foreach ( $list as $entry ) {
			if ( isset( $entry['filename'] ) && $name === $entry['filename'] ) {
				if ( (int) $entry['size'] > $max_size ) {
					throw new RuntimeException( 'The XLSX file contains an oversized worksheet component.' );
				}
				$extracted = $archive->extract( PCLZIP_OPT_BY_NAME, $name, PCLZIP_OPT_EXTRACT_AS_STRING );
				if ( ! is_array( $extracted ) || ! isset( $extracted[0]['content'] ) ) {
					throw new RuntimeException( 'A worksheet component could not be read from the XLSX file.' );
				}
				return $extracted[0]['content'];
			}
		}
		return '';
	}

	private static function parse_xml( $xml ) {
		$previous = libxml_use_internal_errors( true );
		$parsed   = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $parsed ) {
			throw new RuntimeException( 'The XLSX file contains invalid XML.' );
		}
		return $parsed;
	}

	private static function read_xlsx( $path ) {
		if ( ! class_exists( 'PclZip' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}
		if ( ! class_exists( 'PclZip' ) || ! function_exists( 'simplexml_load_string' ) ) {
			throw new RuntimeException( 'This server cannot read XLSX files. Save the workbook as CSV and import it instead.' );
		}
		$archive = new PclZip( $path );
		$list    = $archive->listContent();
		if ( ! is_array( $list ) ) {
			throw new RuntimeException( 'The XLSX file is not a valid workbook archive.' );
		}
		$total_size = 0;
		foreach ( $list as $entry ) {
			$total_size += isset( $entry['size'] ) ? (int) $entry['size'] : 0;
			if ( $total_size > self::MAX_ARCHIVE_SIZE * 4 ) {
				throw new RuntimeException( 'The XLSX workbook expands beyond the allowed size.' );
			}
		}
		$workbook_xml = self::extract_zip_entry( $archive, 'xl/workbook.xml', $list, 1048576 );
		if ( '' === $workbook_xml ) {
			throw new RuntimeException( 'The XLSX workbook is missing its workbook definition.' );
		}
		$workbook = self::parse_xml( $workbook_xml );
		$sheets   = $workbook->xpath('//*[local-name()="sheet"]');
		if ( ! $sheets || ! isset( $sheets[0] ) ) {
			throw new RuntimeException( 'The XLSX workbook does not contain a worksheet.' );
		}
		$sheet_ns = $sheets[0]->getNameSpaces( true );
		$sheet_at = $sheet_ns && isset( $sheet_ns['r'] ) ? $sheets[0]->attributes( $sheet_ns['r'] ) : $sheets[0]->attributes();
		$rel_id   = isset( $sheet_at['id'] ) ? (string) $sheet_at['id'] : '';
		if ( '' === $rel_id ) {
			throw new RuntimeException( 'The first XLSX worksheet has no relationship identifier.' );
		}
		$rels_xml = self::extract_zip_entry( $archive, 'xl/_rels/workbook.xml.rels', $list, 1048576 );
		$rels     = '' !== $rels_xml ? self::parse_xml( $rels_xml ) : null;
		$target   = '';
		if ( $rels ) {
			foreach ( $rels->xpath('//*[local-name()="Relationship"]') as $relationship ) {
				$attributes = $relationship->attributes();
				if ( isset( $attributes['Id'] ) && (string) $attributes['Id'] === $rel_id && isset( $attributes['Target'] ) ) {
					$target = (string) $attributes['Target'];
					break;
				}
			}
		}
		if ( '' === $target || false !== strpos( $target, '..' ) ) {
			throw new RuntimeException( 'The first XLSX worksheet could not be resolved safely.' );
		}
		$sheet_path = 0 === strpos( $target, '/' ) ? ltrim( $target, '/' ) : 'xl/' . $target;
		$sheet_xml  = self::extract_zip_entry( $archive, $sheet_path, $list, self::MAX_SHEET_SIZE );
		if ( '' === $sheet_xml ) {
			throw new RuntimeException( 'The first XLSX worksheet is missing.' );
		}
		$shared     = array();
		$shared_xml = self::extract_zip_entry( $archive, 'xl/sharedStrings.xml', $list, self::MAX_SHEET_SIZE );
		if ( '' !== $shared_xml ) {
			$shared_sheet = self::parse_xml( $shared_xml );
			foreach ( $shared_sheet->xpath('//*[local-name()="sst"]/*[local-name()="si"]') as $item ) {
				$text = '';
				foreach ( $item->xpath('.//*[local-name()="t"]') as $segment ) {
					$text .= (string) $segment;
				}
				$shared[] = $text;
			}
		}
		$sheet = self::parse_xml( $sheet_xml );
		$rows  = array();
		foreach ( $sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $xml_row ) {
			$row = array();
			foreach ( $xml_row->xpath('./*[local-name()="c"]') as $cell ) {
				$attributes = $cell->attributes();
				$reference  = isset( $attributes['r'] ) ? (string) $attributes['r'] : '';
				if ( ! preg_match( '/^([A-Z]+)/i', $reference, $column_match ) ) {
					continue;
				}
				$column = 0;
				foreach ( str_split( strtoupper( $column_match[1] ) ) as $letter ) {
					$column = ( $column * 26 ) + ord( $letter ) - 64;
				}
				$type  = isset( $attributes['t'] ) ? (string) $attributes['t'] : '';
				$value = '';
				if ( 'inlineStr' === $type ) {
					foreach ( $cell->xpath('.//*[local-name()="t"]') as $segment ) {
						$value .= (string) $segment;
					}
				} else {
					$values = $cell->xpath('./*[local-name()="v"]');
					$value  = $values && isset( $values[0] ) ? (string) $values[0] : '';
					if ( 's' === $type && '' !== $value && isset( $shared[ (int) $value ] ) ) {
						$value = $shared[ (int) $value ];
					}
				}
				$row[ $column - 1 ] = $value;
			}
			if ( $row ) {
				ksort( $row );
				$rows[] = $row;
				if ( count( $rows ) > self::MAX_RECORDS + 1 ) {
					throw new RuntimeException( 'The spreadsheet exceeds the limit of ' . self::MAX_RECORDS . ' bonus listings.' );
				}
			}
		}
		if ( ! $rows ) {
			throw new RuntimeException( 'The XLSX worksheet is empty.' );
		}
		$header = array_shift( $rows );
		return self::normalize_rows( $header, $rows );
	}

	private static function upload_file( $key, $multiple = false ) {
		if ( ! isset( $_FILES[ $key ] ) || ! is_array( $_FILES[ $key ] ) ) {
			return array();
		}
		$file = $_FILES[ $key ];
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

	private static function stage_image( $path, $name, $stage_dir, &$images, &$total_size ) {
		$id   = self::image_id( $name );
		$size = filesize( $path );
		if ( false === $size || 0 === $size || $size > self::MAX_IMAGE_SIZE ) {
			throw new RuntimeException( 'Each image must be non-empty and no larger than 10 MB.' );
		}
		$total_size += $size;
		if ( $total_size > self::MAX_IMAGE_TOTAL || count( $images ) >= self::MAX_IMAGES ) {
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
		if ( ! copy( $path, $staged_path ) ) {
			throw new RuntimeException( 'An image could not be staged for import.' );
		}
		$images[ $key ] = array( 'bonus_id' => $id, 'image_file' => $id . '.' . $extension, 'stage_path' => $staged_path );
	}

	private static function upload_images( $stage_dir, &$images, &$total_size ) {
		foreach ( self::upload_file( 'bonus_images', true ) as $file ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
				throw new RuntimeException( 'One of the selected image files could not be uploaded.' );
			}
			self::stage_image( $file['tmp_name'], $file['name'], $stage_dir, $images, $total_size );
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

	private static function stage_remote( $url, $stage_dir, &$images, &$total_size ) {
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
			if ( false === file_put_contents( $temp, $body ) ) {
				throw new RuntimeException( 'A remote image could not be staged.' );
			}
			self::stage_image( $temp, $name, $stage_dir, $images, $total_size );
			unlink( $temp );
			return;
		}
		if ( preg_match( '/\.zip$/i', $name ) || false !== strpos( $type, 'application/zip' ) || false !== strpos( $type, 'application/x-zip' ) ) {
			$temp = trailingslashit( $stage_dir ) . '.remote-' . wp_generate_password( 12, false, false ) . '.zip';
			if ( false === file_put_contents( $temp, $body ) ) {
				throw new RuntimeException( 'The public ZIP archive could not be staged.' );
			}
			self::stage_zip_images( $temp, $stage_dir, $images, $total_size );
			unlink( $temp );
			return;
		}
		if ( false === strpos( $type, 'text/html' ) && false === strpos( $type, 'text/plain' ) ) {
			throw new RuntimeException( 'The URL must point to an image, ZIP archive, or standard web-server directory listing.' );
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
			if ( wp_http_validate_url( $href ) ) {
				$links[] = $href;
			}
			if ( count( $links ) > self::MAX_IMAGES ) {
				throw new RuntimeException( 'The directory listing contains more than 200 image or archive links.' );
			}
		}
		if ( ! $links ) {
			throw new RuntimeException( 'No direct image or ZIP links were found. Use a public ZIP download or a standard web-server directory listing.' );
		}
		foreach ( array_unique( $links ) as $link ) {
			self::stage_remote( $link, $stage_dir, $images, $total_size );
		}
	}

	private static function stage_zip_images( $path, $stage_dir, &$images, &$total_size ) {
		$archive_size = filesize( $path );
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
			if ( $size > self::MAX_IMAGE_SIZE ) {
				throw new RuntimeException( 'A ZIP image exceeds the 10 MB per-image limit.' );
			}
			$contents = $archive->extract( PCLZIP_OPT_BY_NAME, $entry_name, PCLZIP_OPT_EXTRACT_AS_STRING );
			if ( ! is_array( $contents ) || ! isset( $contents[0]['content'] ) ) {
				throw new RuntimeException( 'An image could not be extracted from the ZIP archive.' );
			}
			$temp = trailingslashit( $stage_dir ) . '.zip-image-' . wp_generate_password( 12, false, false );
			if ( false === file_put_contents( $temp, $contents[0]['content'] ) ) {
				throw new RuntimeException( 'An extracted image could not be staged.' );
			}
			self::stage_image( $temp, basename( $entry_name ), $stage_dir, $images, $total_size );
			unlink( $temp );
		}
	}

	private static function make_stage_dir() {
		$root = self::storage_root();
		self::ensure_directory( $root );
		$stage_dir = trailingslashit( $root ) . '.staging-' . wp_generate_password( 16, false, false );
		if ( ! wp_mkdir_p( $stage_dir ) ) {
			throw new RuntimeException( 'A temporary image staging directory could not be created.' );
		}
		return $stage_dir;
	}

	private static function apply_import( $event_id, $rows, $images, $stage_dir ) {
		global $wpdb;
		$event_dir = $images ? self::event_dir( $event_id ) : '';
		if ( $images ) {
			self::ensure_directory( $event_dir );
		}
		$backups = array();
		$installed = array();
		MRSA::begin_transaction();
		try {
			foreach ( $rows as $row ) {
				$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', $event_id, $row['bonus_id'] ) );
				$data = array(
					'description' => $row['description'],
					'category'    => $row['category'],
					'points'      => $row['points'],
					'locations'   => $row['locations'],
				);
				if ( $existing ) {
					if ( false === $wpdb->update( MRSA::table( 'bonus_points' ), $data, array( 'event_id' => $event_id, 'bonus_id' => $row['bonus_id'] ) ) ) {
						throw new RuntimeException( 'A bonus listing could not be updated. No import was saved.' );
					}
				} else {
					if ( false === $wpdb->insert( MRSA::table( 'bonus_points' ), array_merge( array( 'event_id' => $event_id, 'bonus_id' => $row['bonus_id'] ), $data ) ) ) {
						throw new RuntimeException( 'A bonus listing could not be added. No import was saved.' );
					}
				}
			}
			$index = 0;
			foreach ( $images as $image ) {
				$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,image_file FROM ' . MRSA::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', $event_id, $image['bonus_id'] ), ARRAY_A );
				if ( ! $existing ) {
					if ( false === $wpdb->insert( MRSA::table( 'bonus_points' ), array( 'event_id' => $event_id, 'bonus_id' => $image['bonus_id'], 'description' => '' ) ) ) {
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
					if ( is_file( $old_path ) ) {
						$backup_path = trailingslashit( $stage_dir ) . '.backup-' . $index++;
						if ( ! rename( $old_path, $backup_path ) ) {
							throw new RuntimeException( 'An existing gallery image could not be backed up before replacement.' );
						}
						$backups[ $old_path ] = $backup_path;
					}
				}
				$destination = trailingslashit( $event_dir ) . $new_file;
				if ( ! rename( $image['stage_path'], $destination ) ) {
					throw new RuntimeException( 'An imported image could not be installed in the event gallery.' );
				}
				$installed[] = $destination;
				if ( false === $wpdb->update( MRSA::table( 'bonus_points' ), array( 'image_file' => $new_file ), array( 'id' => $existing['id'], 'event_id' => $event_id ) ) ) {
					throw new RuntimeException( 'The imported image could not be linked to its bonus listing.' );
				}
			}
			MRSA::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! MRSA::rollback_transaction() ) {
				throw new RuntimeException( 'The import failed and the database rollback failed. Gallery backups have been retained for recovery.' );
			}
			foreach ( $installed as $path ) {
				if ( is_file( $path ) && ! unlink( $path ) ) {
					throw new RuntimeException( 'The import failed and a newly installed image could not be removed: ' . basename( $path ) );
				}
			}
			foreach ( $backups as $original => $backup ) {
				if ( is_file( $backup ) && ! rename( $backup, $original ) ) {
					throw new RuntimeException( 'The import failed and a previous gallery image could not be restored: ' . basename( $original ) );
				}
			}
			if ( $stage_dir ) {
				self::remove_directory( $stage_dir );
			}
			throw $exception;
		}
		foreach ( $backups as $backup ) {
			if ( is_file( $backup ) && ! unlink( $backup ) ) {
				throw new RuntimeException( 'The import completed, but an old replaced image could not be removed from staging.' );
			}
		}
		if ( $stage_dir ) {
			self::remove_directory( $stage_dir );
		}
		return 'Imported or updated ' . count( $rows ) . ' bonus listings and ' . count( $images ) . ' event gallery images.';
	}

	public static function import( $event_id ) {
		$sheet_files = self::upload_file( 'bonus_file' );
		$sheet = $sheet_files ? $sheet_files[0] : null;
		$rows = array();
		if ( $sheet && UPLOAD_ERR_NO_FILE !== (int) $sheet['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $sheet['error'] || ! is_uploaded_file( $sheet['tmp_name'] ) ) {
				throw new RuntimeException( 'The CSV or XLSX file could not be uploaded.' );
			}
			if ( (int) $sheet['size'] > self::MAX_SHEET_SIZE ) {
				throw new RuntimeException( 'The CSV or XLSX file must be no larger than 10 MB.' );
			}
			$extension = strtolower( pathinfo( $sheet['name'], PATHINFO_EXTENSION ) );
			if ( 'csv' === $extension ) {
				$rows = self::read_csv( $sheet['tmp_name'] );
			} elseif ( 'xlsx' === $extension ) {
				$rows = self::read_xlsx( $sheet['tmp_name'] );
			} else {
				throw new RuntimeException( 'Choose a .csv or .xlsx file.' );
			}
		}
		$remote_url = trim( MRSA_Admin::posted( 'gallery_url' ) );
		$uploaded = self::upload_file( 'bonus_images', true );
		$has_uploads = false;
		foreach ( $uploaded as $file ) {
			if ( UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
				$has_uploads = true;
				break;
			}
		}
		if ( ! $rows && ! $has_uploads && '' === $remote_url ) {
			throw new RuntimeException( 'Choose a CSV/XLSX file, a local image folder, or a public image URL.' );
		}
		$stage_dir = $has_uploads || '' !== $remote_url ? self::make_stage_dir() : '';
		$images = array();
		$total_size = 0;
		try {
			self::upload_images( $stage_dir, $images, $total_size );
			if ( '' !== $remote_url ) {
				self::stage_remote( $remote_url, $stage_dir, $images, $total_size );
			}
			return self::apply_import( $event_id, $rows, $images, $stage_dir );
		} catch ( RuntimeException $exception ) {
			if ( $stage_dir && is_dir( $stage_dir ) && array() === glob( trailingslashit( $stage_dir ) . '.backup-*' ) ) {
				self::remove_directory( $stage_dir );
			}
			throw $exception;
		}
	}

	public static function save( $event_id, $bonus_id ) {
		global $wpdb;
		if ( ! self::valid_id( $bonus_id ) ) {
			throw new RuntimeException( 'The Bonus ID is invalid.' );
		}
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id,image_file FROM ' . MRSA::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', $event_id, $bonus_id ), ARRAY_A );
		if ( ! $existing ) {
			throw new RuntimeException( 'The selected bonus listing no longer exists.' );
		}
		$new_bonus_id = MRSA_Admin::text( MRSA_Admin::posted( 'new_bonus_id' ), 64, true );
		if ( ! self::valid_id( $new_bonus_id ) ) {
			throw new RuntimeException( 'Bonus ID must use 1–64 letters, numbers, hyphens, or underscores.' );
		}
		$duplicate = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s AND id<>%d', $event_id, $new_bonus_id, $existing['id'] ) );
		if ( $duplicate ) {
			throw new RuntimeException( 'Another bonus listing already uses that Bonus ID in this event.' );
		}
		$description = sanitize_textarea_field( MRSA_Admin::text( MRSA_Admin::posted( 'description' ), 5000 ) );
		$category    = MRSA_Admin::text( MRSA_Admin::posted( 'category' ), 120 );
		$points      = MRSA_Admin::integer( MRSA_Admin::posted( 'points' ), 0, self::MAX_BONUS_POINTS );
		$locations   = self::validate_location( MRSA_Admin::text( MRSA_Admin::posted( 'locations' ), 80 ) );
		$remove_image = '1' === MRSA_Admin::posted( 'remove_image' );
		$image_files = self::upload_file( 'bonus_image' );
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
			$stage_dir = self::make_stage_dir();
			try {
				self::stage_image( $image_file['tmp_name'], $image_file['name'], $stage_dir, $images, $total_size );
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
			if ( $stage_dir && is_dir( $stage_dir ) ) {
				self::remove_directory( $stage_dir );
			}
			throw new RuntimeException( 'The existing bonus image name is invalid and cannot be edited safely.' );
		}
		$event_dir = $existing['image_file'] || $has_image ? self::event_dir( $event_id ) : '';
		$old_file = $existing['image_file'];
		$old_path = $old_file ? trailingslashit( $event_dir ) . $old_file : '';
		if ( $old_file && ! $remove_image && ! $has_image && $new_bonus_id !== $bonus_id && ! is_file( $old_path ) ) {
			if ( $stage_dir && is_dir( $stage_dir ) ) {
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
					$stage_dir = self::make_stage_dir();
				}
				self::ensure_directory( $event_dir );
				$new_file = $has_image
					? $new_bonus_id . '.' . pathinfo( reset( $images )['image_file'], PATHINFO_EXTENSION )
					: $data['image_file'];
				$destination = trailingslashit( $event_dir ) . $new_file;
				if ( $old_path && is_file( $old_path ) ) {
					$backup = trailingslashit( $stage_dir ) . '.backup';
					if ( ! rename( $old_path, $backup ) ) {
						throw new RuntimeException( 'The existing bonus image could not be backed up for replacement.' );
					}
				}
				if ( $has_image ) {
					$image = reset( $images );
					$image['image_file'] = $new_bonus_id . '.' . pathinfo( $image['image_file'], PATHINFO_EXTENSION );
					if ( ! rename( $image['stage_path'], $destination ) ) {
						throw new RuntimeException( 'The replacement image could not be installed.' );
					}
					$installed = true;
					$data['image_file'] = $image['image_file'];
				} elseif ( $backup ) {
					if ( ! copy( $backup, $destination ) ) {
						throw new RuntimeException( 'The existing bonus image could not be renamed.' );
					}
					$installed = true;
				}
			}
			MRSA::begin_transaction();
			if ( false === $wpdb->update( MRSA::table( 'bonus_points' ), $data, array( 'id' => $existing['id'], 'event_id' => $event_id ) ) ) {
				throw new RuntimeException( 'The bonus listing could not be saved.' );
			}
			MRSA::commit_transaction();
		} catch ( RuntimeException $exception ) {
			MRSA::rollback_transaction();
			if ( $installed && is_file( $destination ) ) {
				if ( $backup ) {
					if ( ! unlink( $destination ) || ! rename( $backup, $old_path ) ) {
						throw new RuntimeException( 'The bonus edit failed and its previous image could not be restored.' );
					}
				} elseif ( ! $has_image || ! unlink( $destination ) ) {
					throw new RuntimeException( 'The bonus edit failed and its replacement image could not be removed.' );
				}
			} elseif ( $backup && is_file( $backup ) && ! rename( $backup, $old_path ) ) {
				throw new RuntimeException( 'The bonus edit failed and its previous image could not be restored.' );
			}
			if ( $stage_dir && is_dir( $stage_dir ) ) {
				self::remove_directory( $stage_dir );
			}
			throw $exception;
		}
		if ( $old_path && ! $backup && is_file( $old_path ) && $remove_image ) {
			if ( ! unlink( $old_path ) ) {
				throw new RuntimeException( 'The bonus listing was saved, but its previous image could not be removed.' );
			}
		}
		if ( $has_image && $backup && is_file( $backup ) && ! unlink( $backup ) ) {
			throw new RuntimeException( 'The bonus listing was saved, but its replaced image could not be removed from staging.' );
		}
		if ( $stage_dir && is_dir( $stage_dir ) ) {
			self::remove_directory( $stage_dir );
		}
	}

	private static function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			$path = $entry->getPathname();
			$removed = $entry->isDir() && ! $entry->isLink() ? rmdir( $path ) : unlink( $path );
			if ( ! $removed ) {
				throw new RuntimeException( 'An event gallery file could not be removed: ' . basename( $path ) );
			}
		}
		if ( ! rmdir( $directory ) ) {
			throw new RuntimeException( 'The event gallery directory could not be removed.' );
		}
	}

	public static function stage_event_storage_removal( $event_id ) {
		$directory = self::event_dir( $event_id );
		if ( ! is_dir( $directory ) ) {
			return '';
		}
		$staged = $directory . '.deleting-' . wp_generate_password( 12, false, false );
		if ( ! rename( $directory, $staged ) ) {
			throw new RuntimeException( 'The selected event gallery could not be safely staged for deletion.' );
		}
		return $staged;
	}

	public static function stage_all_storage_removal() {
		$directory = self::storage_root();
		if ( ! is_dir( $directory ) ) {
			return '';
		}
		$staged = $directory . '.deleting-' . wp_generate_password( 12, false, false );
		if ( ! rename( $directory, $staged ) ) {
			throw new RuntimeException( 'The event galleries could not be safely staged for deletion.' );
		}
		return $staged;
	}

	public static function restore_event_storage( $event_id, $staged ) {
		if ( ! $staged || ! is_dir( $staged ) ) {
			return;
		}
		$directory = self::event_dir( $event_id );
		if ( file_exists( $directory ) || ! rename( $staged, $directory ) ) {
			throw new RuntimeException( 'The selected event gallery could not be restored after the deletion failed.' );
		}
	}

	public static function restore_all_storage( $staged ) {
		if ( ! $staged || ! is_dir( $staged ) ) {
			return;
		}
		$directory = self::storage_root();
		if ( file_exists( $directory ) || ! rename( $staged, $directory ) ) {
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
