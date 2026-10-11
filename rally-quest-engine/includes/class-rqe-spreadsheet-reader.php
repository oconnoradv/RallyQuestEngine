<?php
/** Hardened, format-neutral CSV/XLSX reader shared by roster and bonus imports. */
defined( 'ABSPATH' ) || exit;

final class RQE_Spreadsheet_Reader {
	const MAX_RECORDS = 5000;
	const MAX_SHEET_SIZE = 10485760;
	const MAX_ARCHIVE_SIZE = 52428800;

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
		return array( $header, $rows );
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
		if ( preg_match( '/<!DOCTYPE|<!ENTITY|<\?(?:php\b|=)|<script\b/i', $xml ) ) {
			throw new RuntimeException( 'XLSX XML with entities or executable content is not allowed.' );
		}
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
			if ( ! empty( $entry['filename'] ) && preg_match( '#(?:vbaProject|(?:^|/)(?:embeddings|activeX|externalLinks)/|\.(?:exe|dll|php|js|vbs|ps1|bat|cmd|com)$)#i', $entry['filename'] ) ) {
				throw new RuntimeException( 'The workbook contains macros, executable files, embedded objects, or external links.' );
			}
			if ( empty( $entry['folder'] ) && ! preg_match( '/\.(?:xml|rels)$/i', $entry['filename'] ) ) {
				throw new RuntimeException( 'Use a data-only XLSX workbook without embedded files or images.' );
			}
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
				if ( $cell->xpath('./*[local-name()="f"]') ) {
					throw new RuntimeException( 'Spreadsheet formulas are not allowed. Export a values-only workbook or CSV.' );
				}
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
		return array( $header, $rows );
	}

	public static function read( $path, $extension ) {
		if ( 'csv' === $extension ) { return self::read_csv( $path ); }
		if ( 'xlsx' === $extension ) { return self::read_xlsx( $path ); }
		throw new RuntimeException( 'Choose a CSV or XLSX file.' );
	}
}
