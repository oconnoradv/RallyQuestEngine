<?php
/** Explicit, bounded import of factual catalog labels only. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Importer {
	const SOURCE = 'https://vpic.nhtsa.dot.gov/api/';
	const API = 'https://vpic.nhtsa.dot.gov/api/vehicles';

	public static function import( $make, $model, $year ) {
		$make = MRSA_Admin::text( $make, 80, true );
		$model = MRSA_Admin::text( $model, 120, true );
		$year = MRSA_Admin::integer( $year, 1900, 2100 );
		$url = self::API . '/GetModelsForMakeYear/make/' . rawurlencode( $make ) . '/modelyear/' . $year . '/vehicletype/motorcycle?format=json';
		$response = wp_safe_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 262144, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'The NHTSA motorcycle catalog is unavailable. No record was imported; use manual entry instead.' );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['Results'] ) || ! is_array( $data['Results'] ) ) {
			throw new RuntimeException( 'The NHTSA motorcycle catalog returned an unrecognized response. No record was imported.' );
		}
		$rows = array();
		foreach ( $data['Results'] as $result ) {
			if ( ! is_array( $result ) || 'Motorcycle' !== ( $result['VehicleTypeName'] ?? '' ) || 0 !== strcasecmp( trim( (string) ( $result['Model_Name'] ?? '' ) ), $model ) ) {
				continue;
			}
			$source_make = trim( sanitize_text_field( (string) ( $result['Make_Name'] ?? '' ) ) );
			$source_model = trim( sanitize_text_field( (string) $result['Model_Name'] ) );
			if ( '' !== $source_make && strlen( $source_make ) <= 80 && strlen( $source_model ) <= 120 ) {
				$rows[] = array( 'make' => $source_make, 'model' => $source_model, 'year' => $year, 'source' => self::SOURCE );
			}
		}
		if ( ! $rows ) {
			throw new RuntimeException( 'No exact NHTSA motorcycle match was found for that make, model, and year. No record was imported.' );
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
