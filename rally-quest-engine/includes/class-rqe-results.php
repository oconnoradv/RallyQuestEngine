<?php
/** Pure result rules shared by roster, scoring and result presentations. */
defined( 'ABSPATH' ) || exit;

final class RQE_Results {
	public static function tiers() {
		return array(
			'bronze_finisher_points' => 'Preliminary Bronze Finisher',
			'silver_finisher_points' => 'Preliminary Silver Finisher',
			'gold_finisher_points' => 'Preliminary Gold Finisher',
		);
	}

	/** Highest configured threshold met; manual statuses always take precedence. */
	public static function status( array $row, $minimum ) {
		if ( ! empty( $row['status_manual'] ) ) {
			return $row['status'];
		}
		foreach ( array_reverse( self::tiers(), true ) as $key => $label ) {
			if ( isset( $row[ $key ] ) && (int) $row['score'] >= (int) $row[ $key ] ) {
				return $label;
			}
		}
		return (int) $row['score'] >= (int) $minimum ? 'Preliminary Finisher' : 'Registered';
	}

	/** Fixed table aliases match the status-catalog usage query. */
	public static function automatic_status_sql() {
		$sql = 'CASE';
		foreach ( array_reverse( self::tiers(), true ) as $key => $label ) {
			$sql .= " WHEN e.$key IS NOT NULL AND r.score>=e.$key THEN '$label'";
		}
		return $sql . " WHEN r.score>=e.min_finisher_points THEN 'Preliminary Finisher' ELSE 'Registered' END";
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
