<?php
/** Horseback-style horse records. Each horse belongs to at most one participant (rider_id 0 = unassigned). */
defined( 'ABSPATH' ) || exit;

final class MRSA_Horses {
	public static function sexes() {
		return array( 'Mare', 'Gelding', 'Stallion', 'Filly', 'Colt' );
	}

	/** Validate posted horse fields from the Horses tab. */
	public static function data( array $input ) {
		$sex = isset( $input['sex'] ) && is_scalar( $input['sex'] ) ? (string) $input['sex'] : '';
		if ( '' !== $sex && ! in_array( $sex, self::sexes(), true ) ) {
			throw new RuntimeException( 'Choose a listed horse sex or Unspecified.' );
		}
		$age = isset( $input['age'] ) && is_scalar( $input['age'] ) ? trim( (string) $input['age'] ) : '';
		return array(
			'name'     => MRSA_Admin::text( $input['name'] ?? '', 100, true ),
			'breed'    => MRSA_Admin::text( $input['breed'] ?? '', 80 ),
			'sex'      => $sex,
			'age'      => '' === $age ? null : MRSA_Admin::integer( $age, 0, 60 ),
			'color'    => MRSA_Admin::text( $input['color'] ?? '', 60 ),
			'rider_id' => MRSA_Admin::integer( $input['rider_id'] ?? '0' ),
		);
	}

	public static function save( $id, array $data ) {
		global $wpdb;
		if ( $id && ! MRSA::row( 'horses', $id ) ) {
			throw new RuntimeException( 'Horse not found.' );
		}
		if ( $data['rider_id'] && ! MRSA::row( 'riders', $data['rider_id'] ) ) {
			throw new RuntimeException( 'The selected participant no longer exists.' );
		}
		MRSA_Admin::checked( $id ? $wpdb->update( MRSA::table( 'horses' ), $data, array( 'id' => $id ) ) : $wpdb->insert( MRSA::table( 'horses' ), $data ) );
		return $id ? $id : (int) $wpdb->insert_id;
	}

	public static function delete( $id ) {
		global $wpdb;
		if ( ! MRSA::row( 'horses', $id ) ) {
			throw new RuntimeException( 'Horse not found.' );
		}
		MRSA_Admin::checked( $wpdb->delete( MRSA::table( 'horses' ), array( 'id' => $id ) ) );
	}

	/**
	 * Replace a participant's horse selection inside the caller's transaction.
	 * Only unassigned horses or horses already owned by this participant may be selected.
	 */
	public static function assign( $rider_id, array $horse_ids ) {
		global $wpdb;
		$table = MRSA::table( 'horses' );
		$ids = array();
		foreach ( $horse_ids as $horse_id ) {
			$ids[] = MRSA_Admin::integer( $horse_id, 1 );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		if ( count( $ids ) > 20 ) {
			throw new RuntimeException( 'Select at most 20 horses for one participant.' );
		}
		foreach ( $ids as $horse_id ) {
			$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT rider_id FROM ' . $table . ' WHERE id=%d FOR UPDATE', $horse_id ) );
			if ( null === $owner || $wpdb->last_error ) {
				throw new RuntimeException( 'A selected horse no longer exists. Refresh and try again.' );
			}
			if ( (int) $owner && (int) $owner !== (int) $rider_id ) {
				throw new RuntimeException( 'A selected horse already belongs to another participant.' );
			}
		}
		MRSA_Admin::checked( $wpdb->query( $wpdb->prepare( 'UPDATE ' . $table . ' SET rider_id=0 WHERE rider_id=%d', $rider_id ) ) );
		foreach ( $ids as $horse_id ) {
			MRSA_Admin::checked( $wpdb->update( $table, array( 'rider_id' => $rider_id ), array( 'id' => $horse_id ) ) );
		}
	}
}
