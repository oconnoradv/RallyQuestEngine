<?php
/** Admin request handling. Every mutation has an explicit authorization boundary. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_mrsa_save', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		if ( MRSA::access() ) {
			add_menu_page( 'Motorcycle Rally Scoring Application', 'Rally Scoring', 'read', 'mrsa', array( 'MRSA_Views', 'render' ), 'dashicons-location-alt', 58 );
			add_submenu_page( 'mrsa', 'Event Scoring', 'Event Scoring', 'read', 'mrsa-scoring', array( 'MRSA_Views', 'render_scoring' ) );
			add_submenu_page( null, 'Edit Rider', 'Edit Rider', 'read', 'mrsa-rider-edit', array( 'MRSA_Views', 'render_rider_edit' ) );
			add_submenu_page( null, 'Edit Event', 'Edit Event', 'read', 'mrsa-event-edit', array( 'MRSA_Views', 'render_event_edit' ) );
		}
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_mrsa', 'rally-scoring_page_mrsa-scoring', 'admin_page_mrsa-rider-edit', 'admin_page_mrsa-event-edit' ), true ) || ! MRSA::access() ) {
			return;
		}
		wp_enqueue_style( 'mrsa-admin', plugins_url( 'assets/admin.css', MRSA_FILE ), array(), MRSA_VERSION );
		wp_enqueue_script( 'mrsa-admin', plugins_url( 'assets/admin.js', MRSA_FILE ), array(), MRSA_VERSION, true );
		if ( 'admin_page_mrsa-event-edit' === $hook ) {
			wp_enqueue_media();
		}
	}

	public static function query( $key, $default = '' ) {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : $default;
	}

	public static function posted( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : $default;
	}

	public static function text( $value, $limit, $required = false ) {
		if ( ! is_scalar( $value ) ) {
			throw new RuntimeException( 'Invalid text value.' );
		}
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( strlen( $value ) > $limit || ( $required && '' === $value ) ) {
			throw new RuntimeException( 'A required text field is empty or exceeds its maximum length (' . $limit . ' bytes).' );
		}
		return $value;
	}

	public static function integer( $value, $min = 0, $max = PHP_INT_MAX ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/D', (string) $value ) || strlen( (string) $value ) > 18 || (float) $value < $min || (float) $value > $max ) {
			throw new RuntimeException( 'Invalid integer or out-of-range value.' );
		}
		return (int) $value;
	}

	public static function decimal( $value, $min = 0, $max = 99999999.99, $precision = 2, $optional = false ) {
		if ( $optional && '' === $value ) {
			return null;
		}
		if ( ! is_scalar( $value ) || ! preg_match( '/^-?\d+(?:\.\d{1,' . (int) $precision . '})?$/D', (string) $value ) || (float) $value < $min || (float) $value > $max ) {
			throw new RuntimeException( 'Invalid numeric value or precision.' );
		}
		return number_format( (float) $value, $precision, '.', '' );
	}

	public static function email( $value, $optional = false ) {
		if ( $optional && '' === trim( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( strlen( $value ) > 254 || ! is_email( $value ) ) {
			throw new RuntimeException( 'Enter a valid email address.' );
		}
		return sanitize_email( $value );
	}

	public static function date( $value, $datetime = false ) {
		$format = $datetime ? 'Y-m-d\TH:i' : 'Y-m-d';
		$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
		if ( ! $date || $date->format( $format ) !== $value || (int) $date->format( 'Y' ) < 1900 || (int) $date->format( 'Y' ) > 2100 ) {
			throw new RuntimeException( 'Enter valid dates (1900–2100) and local date/time values.' );
		}
		return $date->format( $datetime ? 'Y-m-d H:i:s' : 'Y-m-d' );
	}

	public static function url( $tab, $extra = array() ) {
		if ( 'scoring' === $tab ) {
			return add_query_arg( array_merge( array( 'page' => 'mrsa-scoring' ), $extra ), admin_url( 'admin.php' ) );
		}
		if ( in_array( $tab, array( 'rider-edit', 'event-edit' ), true ) ) {
			return add_query_arg( array_merge( array( 'page' => 'mrsa-' . $tab ), $extra ), admin_url( 'admin.php' ) );
		}
		return add_query_arg( array_merge( array( 'page' => 'mrsa', 'tab' => $tab ), $extra ), admin_url( 'admin.php' ) );
	}

	private static function require_global() {
		if ( ! MRSA::global_access() ) {
			throw new RuntimeException( 'You are not authorized to manage global settings, catalog or master rider records.' );
		}
	}

	private static function require_event( $id ) {
		if ( ! MRSA::event_access( $id ) || ! MRSA::row( 'events', $id ) ) {
			throw new RuntimeException( 'Event not found or you are not assigned to it.' );
		}
	}

	private static function require_user( $id ) {
		if ( ! get_user_by( 'id', $id ) ) {
			throw new RuntimeException( 'The selected WordPress user no longer exists.' );
		}
	}

	private static function checked( $result ) {
		if ( false === $result ) {
			throw new RuntimeException( 'The record could not be saved. Check for duplicate emails, catalog rows, assigned riders or bibs, then refresh and try again.' );
		}
		return $result;
	}

	public static function save() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! MRSA::access() ) {
			wp_die( esc_html( 'Unauthorized request.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mrsa_save' );
		$op = self::posted( 'op' );
		$tab = 'global';
		$extra = array();
		try {
			switch ( $op ) {
				case 'global_admin':
				case 'remove_global_admin':
					self::require_global();
					self::global_admin( 'remove_global_admin' === $op );
					break;
				case 'event_admin':
				case 'remove_event_admin':
					self::require_global();
					self::event_admin( 'remove_event_admin' === $op );
					break;
				case 'uninstall':
					self::require_global();
					update_option( 'mrsa_delete_on_uninstall', '1' === self::posted( 'delete_on_uninstall' ), false );
					break;
				case 'bike':
					self::require_global();
					self::bike();
					$tab = 'bikes';
					break;
				case 'delete_bike':
					self::require_global();
					self::delete_bike();
					$tab = 'bikes';
					break;
				case 'source_import':
				case 'csv_import':
					self::require_global();
					if ( '1' !== self::posted( 'source_consent' ) ) {
						throw new RuntimeException( 'Confirm that you understand the source and review requirements before importing the factual catalog data.' );
					}
					$message = 'source_import' === $op ? MRSA_Importer::import() : MRSA_Importer::csv( self::posted( 'csv' ) );
					$tab = 'bikes';
					break;
				case 'rider':
					self::require_global();
					$extra['rider_id'] = self::rider();
					$tab = 'rider-edit';
					break;
				case 'event':
					$extra['event_id'] = self::event();
					$tab = 'event-edit';
					break;
				case 'registration':
				case 'edit_registration':
				case 'remove_registration':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					self::registration( $extra['event_id'], $op );
					$tab = 'event-edit';
					break;
				default:
					throw new RuntimeException( 'Unknown operation.' );
			}
			$extra['notice'] = $message ?? 'Saved successfully.';
			wp_safe_redirect( self::url( $tab, $extra ) );
			exit;
		} catch ( RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), esc_html( 'Rally update not saved' ), array( 'response' => 400, 'back_link' => true ) );
		}
	}

	private static function global_admin( $remove ) {
		global $wpdb;
		$id = self::integer( self::posted( 'user_id' ), 1 );
		if ( $remove ) {
			self::checked( $wpdb->delete( MRSA::table( 'global_admins' ), array( 'user_id' => $id ) ) );
			return;
		}
		self::require_user( $id );
		$data = array( 'secondary_email' => self::email( self::posted( 'secondary_email' ), true ), 'secondary_phone' => self::text( self::posted( 'secondary_phone' ), 60 ) );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . MRSA::table( 'global_admins' ) . ' WHERE user_id=%d', $id ) );
		self::checked( $exists ? $wpdb->update( MRSA::table( 'global_admins' ), $data, array( 'user_id' => $id ) ) : $wpdb->insert( MRSA::table( 'global_admins' ), array_merge( array( 'user_id' => $id ), $data ) ) );
	}

	private static function event_admin( $remove ) {
		global $wpdb;
		$event_id = self::integer( self::posted( 'event_id' ), 1 );
		$id = self::integer( self::posted( 'user_id' ), 1 );
		self::require_event( $event_id );
		if ( ! $remove ) {
			self::require_user( $id );
		}
		$where = array( 'event_id' => $event_id, 'user_id' => $id );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . MRSA::table( 'event_admins' ) . ' WHERE event_id=%d AND user_id=%d', $event_id, $id ) );
		if ( $remove ) {
			self::checked( $wpdb->delete( MRSA::table( 'event_admins' ), $where ) );
		} elseif ( ! $exists ) {
			self::checked( $wpdb->insert( MRSA::table( 'event_admins' ), $where ) );
		}
	}

	private static function bike() {
		global $wpdb;
		$id = self::integer( self::posted( 'id', '0' ) );
		if ( $id && ! MRSA::row( 'bikes', $id ) ) {
			throw new RuntimeException( 'Motorcycle not found.' );
		}
		$data = array( 'make' => self::text( self::posted( 'make' ), 80, true ), 'model' => self::text( self::posted( 'model' ), 120, true ), 'year' => self::integer( self::posted( 'year' ), 1900, 2100 ) );
		if ( ! $id ) {
			$data['source'] = 'Manual entry';
		}
		self::checked( $id ? $wpdb->update( MRSA::table( 'bikes' ), $data, array( 'id' => $id ) ) : $wpdb->insert( MRSA::table( 'bikes' ), $data ) );
	}

	private static function delete_bike() {
		global $wpdb;
		$id = self::integer( self::posted( 'id' ), 1 );
		MRSA::begin_transaction();
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'bikes' ) . ' WHERE id=%d FOR UPDATE', $id ) );
			if ( ! $locked ) {
				throw new RuntimeException( 'Motorcycle no longer exists or could not be locked.' );
			}
			$used = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'rider_bikes' ) . ' WHERE bike_id=%d LIMIT 1', $id ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Unable to check motorcycle assignments. No catalog entry was deleted.' );
			}
			if ( $used ) {
				throw new RuntimeException( 'This motorcycle is used by a rider and cannot be removed.' );
			}
			self::checked( $wpdb->delete( MRSA::table( 'bikes' ), array( 'id' => $id ) ) );
			MRSA::commit_transaction();
		} catch ( RuntimeException $e ) {
			MRSA::rollback_transaction();
			throw $e;
		}
	}

	private static function rider() {
		global $wpdb;
		$id = self::integer( self::posted( 'id', '0' ) );
		if ( $id && ! MRSA::row( 'riders', $id ) ) {
			throw new RuntimeException( 'Rider not found.' );
		}
		$data = array();
		foreach ( array( 'name' => 160, 'alias' => 100, 'phone' => 60, 'emergency_name' => 160, 'emergency_phone' => 60 ) as $key => $limit ) {
			$data[ $key ] = self::text( self::posted( $key ), $limit, 'name' === $key );
		}
		$data['email'] = self::email( self::posted( 'email' ) );
		$data['emergency_email'] = self::email( self::posted( 'emergency_email' ), true );
		foreach ( array( 'insurance', 'other' ) as $key ) {
			$data[ $key ] = sanitize_textarea_field( self::posted( $key ) );
			if ( strlen( $data[ $key ] ) > 5000 ) {
				throw new RuntimeException( 'Insurance and other information must not exceed 5000 bytes each.' );
			}
		}
		$bike_ids = isset( $_POST['bike_ids'] ) && is_array( $_POST['bike_ids'] ) ? wp_unslash( $_POST['bike_ids'] ) : array();
		$fuel = isset( $_POST['fuel'] ) && is_array( $_POST['fuel'] ) ? wp_unslash( $_POST['fuel'] ) : array();
		if ( count( $bike_ids ) > 20 || count( $bike_ids ) !== count( $fuel ) ) {
			throw new RuntimeException( 'Select at most 20 motorcycles with matching fuel capacities.' );
		}
		$bikes = array();
		foreach ( array_values( $bike_ids ) as $i => $bike_id ) {
			if ( '' === $bike_id ) {
				continue;
			}
			$bike_id = self::integer( $bike_id, 1 );
			$bikes[] = array( 'bike_id' => $bike_id, 'fuel_capacity' => self::text( array_values( $fuel )[ $i ], 100 ) );
		}
		MRSA::begin_transaction();
		try {
			$lock_ids = array_values( array_unique( array_column( $bikes, 'bike_id' ) ) );
			sort( $lock_ids, SORT_NUMERIC );
			foreach ( $lock_ids as $bike_id ) {
				// Catalog deletion uses the same row lock; ordered locking avoids deadlocks.
				$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'bikes' ) . ' WHERE id=%d FOR UPDATE', $bike_id ) );
				if ( ! $locked ) {
					throw new RuntimeException( 'A selected motorcycle no longer exists or could not be locked. Refresh the catalog and try again.' );
				}
			}
			self::checked( $id ? $wpdb->update( MRSA::table( 'riders' ), $data, array( 'id' => $id ) ) : $wpdb->insert( MRSA::table( 'riders' ), $data ) );
			$id = $id ?: (int) $wpdb->insert_id;
			self::checked( $wpdb->delete( MRSA::table( 'rider_bikes' ), array( 'rider_id' => $id ) ) );
			foreach ( $bikes as $bike ) {
				self::checked( $wpdb->insert( MRSA::table( 'rider_bikes' ), array_merge( array( 'rider_id' => $id ), $bike ) ) );
			}
			MRSA::commit_transaction();
		} catch ( RuntimeException $e ) {
			MRSA::rollback_transaction();
			throw $e;
		}
		return $id;
	}

	private static function event() {
		global $wpdb;
		$id = self::integer( self::posted( 'id', '0' ) );
		if ( $id ) {
			self::require_event( $id );
		} else {
			self::require_global();
		}
		$data = array( 'name' => self::text( self::posted( 'name' ), 160, true ), 'max_bib' => self::integer( self::posted( 'max_bib' ), 1, 10000 ), 'logo_id' => self::integer( self::posted( 'logo_id', '0' ) ), 'location' => self::text( self::posted( 'location' ), 200 ), 'min_finisher_points' => self::integer( self::posted( 'min_finisher_points', '0' ), 0, 1000000000 ) );
		if ( $data['logo_id'] && ( 'attachment' !== get_post_type( $data['logo_id'] ) || ! wp_attachment_is_image( $data['logo_id'] ) ) ) {
			throw new RuntimeException( 'Select an existing image from the WordPress media library.' );
		}
		foreach ( array( 'registration_open', 'registration_close', 'start_at', 'end_at' ) as $key ) {
			$data[ $key ] = self::date( self::posted( $key ), true );
		}
		$data['event_date'] = substr( $data['start_at'], 0, 10 );
		if ( $data['registration_open'] > $data['registration_close'] || $data['registration_close'] > $data['start_at'] || $data['start_at'] > $data['end_at'] ) {
			throw new RuntimeException( 'Dates must follow: registration open ≤ close ≤ start ≤ end.' );
		}
		foreach ( array( 'rider_price', 'pillion_price' ) as $key ) {
			$data[ $key ] = self::decimal( self::posted( $key, '0' ) );
		}
		$data['latitude'] = self::decimal( self::posted( 'latitude' ), -90, 90, 7, true );
		$data['longitude'] = self::decimal( self::posted( 'longitude' ), -180, 180, 7, true );
		if ( ( null === $data['latitude'] ) !== ( null === $data['longitude'] ) ) {
			throw new RuntimeException( 'Provide both coordinates or leave both blank.' );
		}
		MRSA::begin_transaction();
		try {
			if ( $id ) {
				// Serialize capacity changes with enrollment, not just duplicate bib assignment.
				$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $id ) );
				if ( ! $locked ) {
					throw new RuntimeException( 'Event no longer exists.' );
				}
				$highest_bib = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(bib) FROM ' . MRSA::table( 'registrations' ) . ' WHERE event_id=%d', $id ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Unable to check existing bib assignments. No event update was saved.' );
				}
				if ( (int) $highest_bib > $data['max_bib'] ) {
					throw new RuntimeException( 'Maximum bib cannot be below an existing assigned bib.' );
				}
			}
			self::checked( $id ? $wpdb->update( MRSA::table( 'events' ), $data, array( 'id' => $id ) ) : $wpdb->insert( MRSA::table( 'events' ), $data ) );
			$id = $id ?: (int) $wpdb->insert_id;
			MRSA::commit_transaction();
		} catch ( RuntimeException $e ) {
			MRSA::rollback_transaction();
			throw $e;
		}
		return $id;
	}

	private static function registration( $event_id, $op ) {
		global $wpdb;
		MRSA::begin_transaction();
		try {
			$event = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MRSA::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ), ARRAY_A );
			if ( ! $event ) {
				throw new RuntimeException( 'Event no longer exists.' );
			}
			$id = self::integer( self::posted( 'id', '0' ) );
			$row = $id ? MRSA::row( 'registrations', $id ) : null;
			if ( 'registration' !== $op && ( ! $row || (int) $row['event_id'] !== $event_id ) ) {
				throw new RuntimeException( 'Registration not found in the selected event.' );
			}
			if ( 'remove_registration' === $op ) {
				self::checked( $wpdb->delete( MRSA::table( 'registrations' ), array( 'id' => $id, 'event_id' => $event_id ) ) );
			} else {
				$data = array( 'bib' => self::integer( self::posted( 'bib' ), 1, (int) $event['max_bib'] ), 'pillion' => self::text( self::posted( 'pillion' ), 160 ) );
				if ( 'registration' === $op ) {
					// Event administrators never browse or enroll unregistered master PII.
					self::require_global();
					$rider_id = self::integer( self::posted( 'rider_id' ), 1 );
					$rider = MRSA::row( 'riders', $rider_id );
					if ( ! $rider || preg_match( '/^erased-\d+@example\.invalid$/', $rider['email'] ) ) {
						throw new RuntimeException( 'The selected rider no longer exists or was erased.' );
					}
					$data['rider_id'] = $rider_id;
					$data['event_id'] = $event_id;
					self::checked( $wpdb->insert( MRSA::table( 'registrations' ), $data ) );
				} else {
					self::checked( $wpdb->update( MRSA::table( 'registrations' ), $data, array( 'id' => $id, 'event_id' => $event_id ) ) );
				}
			}
			MRSA::commit_transaction();
		} catch ( RuntimeException $e ) {
			MRSA::rollback_transaction();
			throw $e;
		}
	}
}
