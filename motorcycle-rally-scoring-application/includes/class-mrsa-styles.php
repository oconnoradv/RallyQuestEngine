<?php
/**
 * Scavenger hunt styles.
 *
 * The site-wide primary style controls shared tabs and participant conveyance fields
 * (motorcycles, bicycles, horses or none for GeoCache). Each event also stores its own
 * style, which supplies the public event code such as MC-7 or HB-12.
 */
defined( 'ABSPATH' ) || exit;

final class MRSA_Styles {
	const OPTION = 'mrsa_primary_style';
	const DEFAULT_STYLE = 'motorcycle';

	/** Ordered style catalog: key => label, event-code prefix and participant noun. */
	public static function all() {
		return array(
			'motorcycle' => array( 'label' => 'Motorcycle', 'code' => 'MC', 'people' => 'Motorcyclists' ),
			'bicycle'    => array( 'label' => 'Bicycle', 'code' => 'BC', 'people' => 'Bicyclists' ),
			'horse'      => array( 'label' => 'Horseback', 'code' => 'HB', 'people' => 'Horseback riders' ),
			'geocache'   => array( 'label' => 'GeoCache', 'code' => 'GC', 'people' => 'Geocachers' ),
		);
	}

	public static function valid( $style ) {
		return is_string( $style ) && isset( self::all()[ $style ] );
	}

	/** The saved primary style, or an empty string until the Welcome section is completed. */
	public static function saved_primary() {
		$style = get_option( self::OPTION, '' );
		return self::valid( $style ) ? $style : '';
	}

	/** The primary style used for behavior; Motorcycle until an administrator chooses otherwise. */
	public static function primary() {
		$style = self::saved_primary();
		return $style ? $style : self::DEFAULT_STYLE;
	}

	public static function save_primary( $style ) {
		if ( ! self::valid( $style ) ) {
			throw new RuntimeException( 'Choose Motorcycle, Bicycle, Horseback or GeoCache as the primary scavenger hunt style.' );
		}
		if ( self::saved_primary() !== $style && ! update_option( self::OPTION, $style, false ) ) {
			throw new RuntimeException( 'The primary scavenger hunt style could not be saved.' );
		}
	}

	public static function label( $style ) {
		return self::valid( $style ) ? self::all()[ $style ]['label'] : self::all()[ self::DEFAULT_STYLE ]['label'];
	}

	public static function code( $style ) {
		return self::valid( $style ) ? self::all()[ $style ]['code'] : self::all()[ self::DEFAULT_STYLE ]['code'];
	}

	/** Event ID shown to administrators and accepted by the public results shortcode. */
	public static function event_code( array $event ) {
		return self::code( $event['hunt_style'] ?? self::DEFAULT_STYLE ) . '-' . (int) $event['id'];
	}

	/**
	 * Parse "MC-7" (style-qualified) or "7" (legacy numeric) event identifiers.
	 *
	 * @return array|null array( 'id' => int, 'style' => string|null ) or null when malformed.
	 */
	public static function parse_event_code( $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$value = strtoupper( trim( (string) $value ) );
		if ( ! preg_match( '/^(?:([A-Z]{2})-)?([1-9]\d{0,17})$/D', $value, $match ) || (float) $match[2] > PHP_INT_MAX ) {
			return null;
		}
		$style = null;
		if ( '' !== $match[1] ) {
			foreach ( self::all() as $key => $config ) {
				if ( $config['code'] === $match[1] ) {
					$style = $key;
				}
			}
			if ( null === $style ) {
				return null;
			}
		}
		return array( 'id' => (int) $match[2], 'style' => $style );
	}

	/** Motorcycle and Bicycle share the vehicle catalog and participant vehicle assignments. */
	public static function uses_vehicles( $style ) {
		return in_array( $style, array( 'motorcycle', 'bicycle' ), true );
	}

	public static function uses_fuel( $style ) {
		return 'motorcycle' === $style;
	}

	public static function uses_horses( $style ) {
		return 'horse' === $style;
	}
}
