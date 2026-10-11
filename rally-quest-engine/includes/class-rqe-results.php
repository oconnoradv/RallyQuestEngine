<?php
/** Pure result rules shared by roster, scoring and result presentations. */
defined( 'ABSPATH' ) || exit;

final class RQE_Results {
	/** Automatic Event Status: Preliminary Finisher strictly above Minimum Finisher Points, otherwise Registered, unless manually set. */
	public static function status( array $row, $minimum ) {
		return ! empty( $row['status_manual'] ) ? $row['status'] : ( (int) $row['score'] > (int) $minimum ? 'Preliminary Finisher' : 'Registered' );
	}

	/** Competition ranking, independent of input ordering; ties consume their positions. */
	public static function rankings( array $rows ) {
		$counts = array_count_values( array_map( function ( $row ) {
			return (string) (int) $row['score'];
		}, $rows ) );
		krsort( $counts, SORT_NUMERIC );
		$ranks = array();
		$position = 1;
		foreach ( $counts as $score => $count ) {
			$ranks[ (string) $score ] = $position;
			$position += $count;
		}
		return $ranks;
	}
}
