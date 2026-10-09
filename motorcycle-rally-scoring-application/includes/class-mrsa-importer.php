<?php
/** Explicit, bounded import of factual catalog labels only. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Importer {
	const SOURCE = 'https://vpic.nhtsa.dot.gov/api/';
	const API = 'https://vpic.nhtsa.dot.gov/api/vehicles';

	public static function import( $make ) {
		$make = MRSA_Admin::text( $make, 80, true );
		$all_models = self::fetch( '/GetModelsForMake/' . rawurlencode( $make ) . '?format=json' );
		$motorcycles = self::fetch( '/GetModelsForMakeYear/make/' . rawurlencode( $make ) . '/vehicletype/motorcycle?format=json' );
		$model_ids = array();
		foreach ( $all_models as $result ) {
			if ( is_array( $result ) && isset( $result['Model_ID'] ) && is_numeric( $result['Model_ID'] ) && (int) $result['Model_ID'] > 0 ) {
				$model_ids[ (int) $result['Model_ID'] ] = true;
			}
		}
		$rows = array();
		foreach ( $motorcycles as $result ) {
			$model_id = is_array( $result ) && isset( $result['Model_ID'] ) && is_numeric( $result['Model_ID'] ) ? (int) $result['Model_ID'] : 0;
			if ( ! $model_id || ! isset( $model_ids[ $model_id ] ) || 'Motorcycle' !== ( $result['VehicleTypeName'] ?? '' ) ) {
				continue;
			}
			$source_make = trim( sanitize_text_field( (string) ( $result['Make_Name'] ?? '' ) ) );
			$source_model = trim( sanitize_text_field( (string) ( $result['Model_Name'] ?? '' ) ) );
			if ( '' !== $source_make && '' !== $source_model && strlen( $source_make ) <= 80 && strlen( $source_model ) <= 120 ) {
				$rows[ $model_id ] = array( 'make' => $source_make, 'model' => $source_model, 'year' => 0, 'source' => self::SOURCE );
			}
		}
		if ( ! $rows ) {
			throw new RuntimeException( 'No NHTSA models for that make were confirmed as motorcycles. No records were imported.' );
		}
		return self::store( array_values( $rows ) );
	}

	private static function fetch( $path ) {
		$url = self::API . $path;
		$response = wp_safe_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 262144, 'headers' => array( 'Accept' => 'application/json' ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new RuntimeException( 'The NHTSA motorcycle catalog is unavailable. No records were imported; use manual entry instead.' );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['Results'] ) || ! is_array( $data['Results'] ) ) {
			throw new RuntimeException( 'The NHTSA motorcycle catalog returned an unrecognized response. No records were imported.' );
		}
		return $data['Results'];
	}

	private static function store( $rows ) {
		global $wpdb;
		$added = 0;
		foreach ( $rows as $row ) {
			$result = $wpdb->query( $wpdb->prepare(
				'INSERT INTO ' . MRSA::table( 'bikes' ) . ' (make,model,year,source) VALUES (%s,%s,%d,%s) ON DUPLICATE KEY UPDATE id=id',
				$row['make'],
				$row['model'],
				$row['year'],
				$row['source']
			) );
			if ( false === $result ) {
				throw new RuntimeException( 'Catalog storage failed. Some preceding rows may have been imported; retrying will skip duplicates.' );
			}
			if ( 1 === $result ) {
				++$added;
			}
		}
		return $added . ' catalog rows added; duplicate rows skipped.';
	}
}
