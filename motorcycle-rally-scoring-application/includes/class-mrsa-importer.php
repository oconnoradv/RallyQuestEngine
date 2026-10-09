<?php
/** Explicit, bounded import of factual catalog labels only. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Importer {
	const SOURCE = 'https://github.com/arthurkao/vehicle-make-model-data';
	const DATA_URL = 'https://raw.githubusercontent.com/arthurkao/vehicle-make-model-data/d7e14ab7f3ffec5f7527055fd5ce2377dade6306/csv_data.csv';

	public static function import() {
		$response = wp_safe_remote_get( self::DATA_URL, array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 1048576, 'headers' => array( 'Accept' => 'text/csv' ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'The vehicle catalog source is unavailable. No records were imported. Use manual entry or the factual CSV fallback.' );
		}
		$lines = preg_split( '/\r\n|\r|\n/', trim( wp_remote_retrieve_body( $response ) ) );
		if ( ! $lines || str_getcsv( array_shift( $lines ), ',', '"', '' ) !== array( 'year', 'make', 'model' ) ) {
			throw new RuntimeException( 'The vehicle catalog source returned an unrecognized CSV format. No records were imported.' );
		}
		$makes = array( 'AMERICAN IRONHORSE', 'APRILIA', 'ATK', 'BENELLI', 'BETA', 'BIG DOG', 'BMW', 'BUELL', 'CAN-AM', 'CFMOTO', 'DUCATI', 'GAS GAS', 'HARLEY-DAVIDSON', 'HONDA', 'HUSABERG', 'HUSQVARNA', 'HYOSUNG', 'INDIAN', 'KAWASAKI', 'KTM', 'KYMCO', 'MOTO GUZZI', 'MV AGUSTA', 'PIAGGIO', 'POLARIS', 'ROYAL ENFIELD', 'SUZUKI', 'TRIUMPH', 'URAL', 'VESPA', 'VICTORY', 'YAMAHA', 'ZERO' );
		$rows = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cols = str_getcsv( $line, ',', '"', '' );
			if ( 3 !== count( $cols ) || ! in_array( strtoupper( trim( $cols[1] ) ), $makes, true ) ) {
				continue;
			}
			$year = trim( $cols[0] );
			$make = trim( $cols[1] );
			$model = trim( $cols[2] );
			if ( ! preg_match( '/^\d{4}$/D', $year ) || strlen( $make ) > 80 || strlen( $model ) > 120 || '' === $model ) {
				continue;
			}
			$key = strtolower( $make . '/' . $model . '/' . $year );
			$rows[ $key ] = array( 'make' => sanitize_text_field( $make ), 'model' => sanitize_text_field( $model ), 'year' => (int) $year, 'source' => self::SOURCE );
		}
		if ( ! $rows ) {
			throw new RuntimeException( 'No motorcycle make/model/year rows were found in the vehicle catalog source. No records were imported.' );
		}
		usort( $rows, static function ( $a, $b ) {
			$year_order = $b['year'] <=> $a['year'];
			return $year_order ?: array( $a['make'], $a['model'] ) <=> array( $b['make'], $b['model'] );
		} );
		return self::store( array_slice( $rows, 0, 500 ) );
	}

	public static function csv( $text ) {
		if ( strlen( $text ) > 100000 ) {
			throw new RuntimeException( 'CSV exceeds 100 KB.' );
		}
		$lines = preg_split( '/\r\n|\r|\n/', trim( $text ) );
		if ( count( $lines ) > 501 ) {
			throw new RuntimeException( 'Import at most 500 rows plus a header.' );
		}
		$rows = array();
		foreach ( $lines as $i => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cols = str_getcsv( $line, ',', '"', '' );
			if ( 0 === $i && array_map( 'strtolower', array_map( 'trim', $cols ) ) === array( 'make', 'model', 'year' ) ) {
				continue;
			}
			if ( count( $cols ) !== 3 ) {
				throw new RuntimeException( 'Each CSV row must contain make,model,year. Row ' . ( $i + 1 ) . ' is invalid.' );
			}
			$make = MRSA_Admin::text( $cols[0], 80, true );
			$model = MRSA_Admin::text( $cols[1], 120, true );
			$year = MRSA_Admin::integer( trim( $cols[2] ), 1900, 2100 );
			$rows[] = array( 'make' => $make, 'model' => $model, 'year' => $year, 'source' => 'Admin-provided factual CSV' );
		}
		if ( ! $rows ) {
			throw new RuntimeException( 'No factual CSV rows supplied.' );
		}
		return self::store( $rows );
	}

	private static function store( $rows ) {
		global $wpdb;
		$added = 0;
		foreach ( $rows as $row ) {
			if ( $row['year'] < 1900 || $row['year'] > 2100 ) {
				continue;
			}
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'bikes' ) . ' WHERE make=%s AND model=%s AND year=%d', $row['make'], $row['model'], $row['year'] ) );
			if ( ! $found ) {
				if ( false === $wpdb->insert( MRSA::table( 'bikes' ), $row ) ) {
					throw new RuntimeException( 'Catalog storage failed. Some preceding rows may have been imported; retrying will skip duplicates.' );
				}
				++$added;
			}
		}
		return $added . ' catalog rows added; duplicate rows skipped.';
	}
}
