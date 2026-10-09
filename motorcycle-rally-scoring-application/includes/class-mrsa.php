<?php
/** Storage, authorization and privacy integration. */
defined( 'ABSPATH' ) || exit;

final class MRSA {
	public static function table( $name ) {
		global $wpdb;
		$allowed = array( 'global_admins', 'event_admins', 'bikes', 'riders', 'rider_bikes', 'events', 'registrations' );
		if ( ! in_array( $name, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Unknown MRSA table.' );
		}
		return $wpdb->prefix . 'MRSA_' . $name;
	}

	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			wp_die( esc_html( 'Motorcycle Rally Scoring Application requires individual site activation. Network-wide activation/provisioning is not supported.' ), '', array( 'back_link' => true ) );
		}
		self::install();
	}

	public static function init() {
		if ( get_option( 'mrsa_db_version' ) !== MRSA_DB_VERSION ) {
			self::install();
		}
		if ( is_admin() ) {
			MRSA_Admin::init();
		}
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function install() {
		global $wpdb;
		$previous_db_version = get_option( 'mrsa_db_version' );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = self::table( 'global_admins' );
		dbDelta( "CREATE TABLE $t (
			user_id bigint(20) unsigned NOT NULL,
			secondary_email varchar(254) NOT NULL DEFAULT '',
			secondary_phone varchar(60) NOT NULL DEFAULT '',
			PRIMARY KEY  (user_id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'event_admins' );
		dbDelta( "CREATE TABLE $t (
			event_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (event_id,user_id),
			KEY user_id (user_id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'bikes' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			make varchar(80) NOT NULL,
			model varchar(120) NOT NULL,
			year smallint(5) unsigned NOT NULL DEFAULT 0,
			source varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY catalog (make,model,year)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'riders' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(160) NOT NULL,
			alias varchar(100) NOT NULL DEFAULT '',
			email varchar(254) NOT NULL,
			phone varchar(60) NOT NULL DEFAULT '',
			emergency_name varchar(160) NOT NULL DEFAULT '',
			emergency_email varchar(254) NOT NULL DEFAULT '',
			emergency_phone varchar(60) NOT NULL DEFAULT '',
			insurance text NOT NULL,
			other text NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'rider_bikes' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rider_id bigint(20) unsigned NOT NULL,
			bike_id bigint(20) unsigned NOT NULL,
			model_year smallint(5) unsigned NOT NULL DEFAULT 0,
			fuel_capacity varchar(100) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY rider_id (rider_id),
			KEY bike_id (bike_id)
		) ENGINE=InnoDB $c;" );
		if ( MRSA_DB_VERSION !== $previous_db_version ) {
			$wpdb->query( 'UPDATE ' . self::table( 'rider_bikes' ) . ' rb JOIN ' . self::table( 'bikes' ) . ' b ON b.id=rb.bike_id SET rb.model_year=b.year WHERE rb.model_year=0 AND b.year BETWEEN 1000 AND 9999' );
		}
		$t = self::table( 'events' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(160) NOT NULL,
			max_bib int(10) unsigned NOT NULL DEFAULT 100,
			logo_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_date date NOT NULL,
			registration_open datetime NOT NULL,
			registration_close datetime NOT NULL,
			start_at datetime NOT NULL,
			end_at datetime NOT NULL,
			rider_price decimal(10,2) NOT NULL DEFAULT 0,
			pillion_price decimal(10,2) NOT NULL DEFAULT 0,
			location varchar(200) NOT NULL DEFAULT '',
			latitude decimal(10,7) DEFAULT NULL,
			longitude decimal(10,7) DEFAULT NULL,
			min_finisher_points bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'registrations' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			rider_id bigint(20) unsigned NOT NULL,
			bib int(10) unsigned NOT NULL,
			pillion varchar(160) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'Registered',
			score bigint(20) NOT NULL DEFAULT 0,
			placing int(10) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_bib (event_id,bib),
			UNIQUE KEY event_rider (event_id,rider_id),
			KEY rider_id (rider_id)
		) ENGINE=InnoDB $c;" );
		update_option( 'mrsa_db_version', MRSA_DB_VERSION, false );
	}

	public static function begin_transaction() {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'Unable to start a database transaction. No update was attempted.' );
		}
	}

	public static function commit_transaction() {
		global $wpdb;
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new RuntimeException( 'Unable to commit the database transaction. Refresh and verify the record before retrying.' );
		}
	}

	public static function rollback_transaction() {
		global $wpdb;
		return false !== $wpdb->query( 'ROLLBACK' );
	}

	public static function global_access() {
		global $wpdb;
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return is_user_logged_in() && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::table( 'global_admins' ) . ' WHERE user_id = %d', get_current_user_id() ) );
	}

	public static function event_ids() {
		global $wpdb;
		if ( self::global_access() ) {
			return array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . self::table( 'events' ) ) );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT a.event_id FROM ' . self::table( 'event_admins' ) . ' a INNER JOIN ' . self::table( 'events' ) . ' e ON e.id=a.event_id WHERE a.user_id=%d', get_current_user_id() ) ) );
	}

	public static function event_access( $id ) {
		return $id > 0 && in_array( (int) $id, self::event_ids(), true );
	}

	public static function access() {
		return is_user_logged_in() && ( self::global_access() || count( self::event_ids() ) > 0 );
	}

	public static function row( $table, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( $table ) . ' WHERE id=%d', $id ), ARRAY_A );
	}

	public static function user_phone( $user_id ) {
		$phone = get_user_meta( $user_id, 'billing_phone', true );
		return (string) ( $phone ? $phone : get_user_meta( $user_id, 'phone', true ) );
	}

	public static function exporters( $items ) {
		$items['mrsa'] = array( 'exporter_friendly_name' => 'Motorcycle Rally Scoring Application', 'callback' => array( __CLASS__, 'export' ) );
		return $items;
	}

	public static function erasers( $items ) {
		$items['mrsa'] = array( 'eraser_friendly_name' => 'Motorcycle Rally Scoring Application', 'callback' => array( __CLASS__, 'erase' ) );
		return $items;
	}

	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$data = array();
		if ( (int) $page > 1 ) {
			return array( 'data' => $data, 'done' => true );
		}
		$rider = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'riders' ) . ' WHERE email=%s', $email ), ARRAY_A );
		if ( $rider ) {
			$fields = array();
			foreach ( $rider as $key => $value ) {
				$fields[] = array( 'name' => ucwords( str_replace( '_', ' ', $key ) ), 'value' => $value );
			}
			$bikes = $wpdb->get_results( $wpdb->prepare( 'SELECT b.make,b.model,rb.model_year,rb.fuel_capacity FROM ' . self::table( 'rider_bikes' ) . ' rb JOIN ' . self::table( 'bikes' ) . ' b ON b.id=rb.bike_id WHERE rb.rider_id=%d', $rider['id'] ), ARRAY_A );
			foreach ( $bikes as $bike ) {
				$fields[] = array( 'name' => 'Motorcycle', 'value' => $bike['make'] . ' / ' . $bike['model'] . ' / ' . ( (int) $bike['model_year'] ? $bike['model_year'] : 'Unspecified year' ) );
				$fields[] = array( 'name' => 'Fuel capacity', 'value' => $bike['fuel_capacity'] );
			}
			$events = $wpdb->get_results( $wpdb->prepare( 'SELECT e.name,r.bib,r.pillion,r.status,r.score,r.placing FROM ' . self::table( 'registrations' ) . ' r JOIN ' . self::table( 'events' ) . ' e ON e.id=r.event_id WHERE r.rider_id=%d', $rider['id'] ), ARRAY_A );
			foreach ( $events as $event ) {
				$fields[] = array( 'name' => 'Event / bib / pillion / status / score / placing', 'value' => implode( ' / ', $event ) );
			}
			$data[] = array( 'group_id' => 'mrsa', 'group_label' => 'Rally registrations', 'item_id' => 'mrsa-rider-' . $rider['id'], 'data' => $fields );
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$admin = $wpdb->get_row( $wpdb->prepare( 'SELECT secondary_email,secondary_phone FROM ' . self::table( 'global_admins' ) . ' WHERE user_id=%d', $user->ID ), ARRAY_A );
			$assignments = $wpdb->get_col( $wpdb->prepare( 'SELECT e.name FROM ' . self::table( 'event_admins' ) . ' a JOIN ' . self::table( 'events' ) . ' e ON e.id=a.event_id WHERE a.user_id=%d', $user->ID ) );
			if ( $admin || $assignments ) {
				$data[] = array( 'group_id' => 'mrsa', 'group_label' => 'Rally administration', 'item_id' => 'mrsa-user-' . $user->ID, 'data' => array(
					array( 'name' => 'Global administrator', 'value' => $admin ? 'Yes' : 'No' ),
					array( 'name' => 'Secondary email', 'value' => $admin ? $admin['secondary_email'] : '' ),
					array( 'name' => 'Secondary phone', 'value' => $admin ? $admin['secondary_phone'] : '' ),
					array( 'name' => 'Assigned events', 'value' => implode( ', ', $assignments ) ),
				) );
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$removed = false;
		$retained = false;
		$messages = array();
		$rider = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'riders' ) . ' WHERE email=%s', $email ), ARRAY_A );
		if ( $rider ) {
			$id = (int) $rider['id'];
			$success = false;
			try {
				self::begin_transaction();
				$success = false !== $wpdb->update( self::table( 'riders' ), array( 'name' => 'Erased rider ' . $id, 'alias' => '', 'email' => 'erased-' . $id . '@example.invalid', 'phone' => '', 'emergency_name' => '', 'emergency_email' => '', 'emergency_phone' => '', 'insurance' => '', 'other' => '' ), array( 'id' => $id ) );
				$success = false !== $wpdb->delete( self::table( 'rider_bikes' ), array( 'rider_id' => $id ) ) && $success;
				$success = false !== $wpdb->update( self::table( 'registrations' ), array( 'pillion' => '' ), array( 'rider_id' => $id ) ) && $success;
				if ( ! $success ) {
					throw new RuntimeException( 'Privacy erasure storage failed.' );
				}
				self::commit_transaction();
			} catch ( RuntimeException $e ) {
				self::rollback_transaction();
				$success = false;
			}
			$removed = $success;
			$retained = true;
			$messages[] = $success ? 'Rider personal information erased; event registration IDs, bibs, statuses, scores and placing retained for event integrity.' : 'Unable to erase rider information; please retry or contact the site administrator.';
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$result = $wpdb->update( self::table( 'global_admins' ), array( 'secondary_email' => '', 'secondary_phone' => '' ), array( 'user_id' => $user->ID ) );
			$removed = $removed || ( false !== $result && $result > 0 );
			$assigned = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::table( 'global_admins' ) . ' WHERE user_id=%d', $user->ID ) ) || $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::table( 'event_admins' ) . ' WHERE user_id=%d LIMIT 1', $user->ID ) );
			if ( $assigned ) {
				$retained = true;
				$messages[] = 'Administrator assignments retained for access control; remove global assignments in Administrators and event assignments from the event Configure page if appropriate. WordPress user profiles are managed by WordPress.';
			}
		}
		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true );
	}
}
