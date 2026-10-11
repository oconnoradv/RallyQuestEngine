<?php
/** Storage, authorization and privacy integration. */
defined( 'ABSPATH' ) || exit;

final class MRSA {
	public static function table( $name ) {
		global $wpdb;
		$allowed = array( 'event_admins', 'event_scorers', 'event_email_settings', 'status_options', 'bikes', 'riders', 'rider_bikes', 'events', 'registrations', 'bonus_points', 'bonus_submissions', 'import_audit' );
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

	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'mrsa_process_submission_inboxes', array(), 'mrsa' );
		}
	}

	public static function init() {
		add_action( 'mrsa_bonus_expire', array( 'MRSA_Bonus', 'expire' ), 10, 3 );
		add_action( 'mrsa_process_submission_inboxes', array( 'MRSA_Email_Submissions', 'process_all' ) );
		if ( get_option( 'mrsa_db_version' ) !== MRSA_DB_VERSION ) {
			self::install();
		}
		if ( is_admin() ) {
			MRSA_Admin::init();
		}
		MRSA_Email_Submissions::schedule();
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function install() {
		global $wpdb;
		$previous_db_version = get_option( 'mrsa_db_version' );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$legacy_global_admins = $wpdb->prefix . 'MRSA_global_admins';
		$wpdb->query( "DROP TABLE IF EXISTS `$legacy_global_admins`" );
		$t = self::table( 'event_admins' );
		dbDelta( "CREATE TABLE $t (
			event_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (event_id,user_id),
			KEY user_id (user_id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'event_scorers' );
		dbDelta( "CREATE TABLE $t (
			event_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (event_id,user_id),
			KEY user_id (user_id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'event_email_settings' );
		dbDelta( "CREATE TABLE $t (
			event_id bigint(20) unsigned NOT NULL,
			provider varchar(20) NOT NULL,
			enabled tinyint(1) unsigned NOT NULL DEFAULT 0,
			encrypted_settings longtext NOT NULL,
			last_checked_at datetime DEFAULT NULL,
			last_error text NOT NULL,
			last_tested_at datetime DEFAULT NULL,
			last_test_error text NOT NULL,
			PRIMARY KEY  (event_id)
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
			registration_status varchar(20) NOT NULL DEFAULT 'Initial Registration',
			status varchar(20) NOT NULL DEFAULT 'Registered',
			status_manual tinyint(1) unsigned NOT NULL DEFAULT 0,
			score bigint(20) NOT NULL DEFAULT 0,
			placing int(10) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_bib (event_id,bib),
			UNIQUE KEY event_rider (event_id,rider_id),
			KEY rider_id (rider_id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'bonus_points' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			bonus_id varchar(64) NOT NULL,
			description text NOT NULL,
			category varchar(120) NOT NULL DEFAULT '',
			points bigint(20) unsigned NOT NULL DEFAULT 0,
			locations varchar(80) NOT NULL DEFAULT '',
			image_file varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY event_bonus (event_id,bonus_id),
			KEY event_id (event_id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'bonus_submissions' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rider_id bigint(20) unsigned NOT NULL DEFAULT 0,
			bib int(10) unsigned NOT NULL DEFAULT 0,
			bonus_id varchar(64) NOT NULL DEFAULT '',
			sender_email varchar(254) NOT NULL DEFAULT '',
			provider varchar(20) NOT NULL,
			provider_message_id varchar(191) NOT NULL,
			provider_message_hash char(64) NOT NULL,
			email_subject varchar(255) NOT NULL DEFAULT '',
			submission_description text NOT NULL,
			submitted_at datetime NOT NULL,
			image_file varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			points_awarded bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewed_at datetime DEFAULT NULL,
			rejection_code varchar(32) NOT NULL DEFAULT '',
			rejection_description text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY provider_message (event_id,provider,provider_message_hash),
			KEY event_status (event_id,status,id),
			KEY rider_history (event_id,rider_id,id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'import_audit' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			username varchar(60) NOT NULL,
			occurred_at datetime NOT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(32) NOT NULL,
			detail text NOT NULL,
			PRIMARY KEY  (id),
			KEY user_action (user_id,action,id),
			KEY event_id (event_id)
		) ENGINE=InnoDB $c;" );
		$t = self::table( 'status_options' );
		dbDelta( "CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			kind varchar(20) NOT NULL,
			label varchar(20) NOT NULL,
			enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY event_kind_label (event_id,kind,label)
		) ENGINE=InnoDB $c;" );
		if ( (int) $previous_db_version < 10 ) {
			MRSA_Statuses::migrate();
		}
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

	public static function site_access() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	public static function event_ids() {
		global $wpdb;
		if ( self::site_access() ) {
			return array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . self::table( 'events' ) ) );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT a.event_id FROM ' . self::table( 'event_admins' ) . ' a INNER JOIN ' . self::table( 'events' ) . ' e ON e.id=a.event_id WHERE a.user_id=%d', get_current_user_id() ) ) );
	}

	public static function scoring_event_ids() {
		global $wpdb;
		if ( self::site_access() ) {
			return array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . self::table( 'events' ) ) );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			'SELECT event_id FROM ' . self::table( 'event_admins' ) . ' WHERE user_id=%d UNION SELECT event_id FROM ' . self::table( 'event_scorers' ) . ' WHERE user_id=%d',
			get_current_user_id(),
			get_current_user_id()
		) ) );
	}

	public static function event_access( $id ) {
		return $id > 0 && in_array( (int) $id, self::event_ids(), true );
	}

	public static function scoring_access( $id ) {
		return $id > 0 && in_array( (int) $id, self::scoring_event_ids(), true );
	}

	public static function scorer_access( $id ) {
		global $wpdb;
		if ( self::site_access() || self::event_access( $id ) ) {
			return true;
		}
		return $id > 0 && (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::table( 'event_scorers' ) . ' WHERE event_id=%d AND user_id=%d', $id, get_current_user_id() ) );
	}

	public static function access() {
		return is_user_logged_in() && ( self::site_access() || count( self::scoring_event_ids() ) > 0 );
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
			$events = $wpdb->get_results( $wpdb->prepare( 'SELECT e.name,r.bib,r.pillion,r.registration_status,r.status,r.status_manual,r.score,r.placing,e.min_finisher_points FROM ' . self::table( 'registrations' ) . ' r JOIN ' . self::table( 'events' ) . ' e ON e.id=r.event_id WHERE r.rider_id=%d', $rider['id'] ), ARRAY_A );
			foreach ( $events as $event ) {
				$result_status = MRSA_Results::status( $event, $event['min_finisher_points'] );
				$fields[] = array( 'name' => 'Event / bib / pillion / registration status / event status / score / placing', 'value' => implode( ' / ', array( $event['name'], $event['bib'], $event['pillion'], $event['registration_status'], $result_status, $event['score'], $event['placing'] ) ) );
			}
			$submissions = $wpdb->get_results( $wpdb->prepare( 'SELECT e.name,s.bib,s.bonus_id,s.email_subject,s.submission_description,s.submitted_at,s.status,s.points_awarded,s.rejection_code,s.rejection_description FROM ' . self::table( 'bonus_submissions' ) . ' s JOIN ' . self::table( 'events' ) . ' e ON e.id=s.event_id WHERE s.rider_id=%d ORDER BY s.submitted_at', $rider['id'] ), ARRAY_A );
			foreach ( $submissions as $submission ) {
				$fields[] = array( 'name' => 'Bonus email submission', 'value' => implode( ' / ', array( $submission['name'], $submission['bib'], $submission['bonus_id'], $submission['email_subject'], $submission['submission_description'], $submission['submitted_at'], $submission['status'], $submission['points_awarded'], $submission['rejection_code'], $submission['rejection_description'] ) ) );
			}
			$data[] = array( 'group_id' => 'mrsa', 'group_label' => 'Rally registrations', 'item_id' => 'mrsa-rider-' . $rider['id'], 'data' => $fields );
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$admin_assignments = $wpdb->get_col( $wpdb->prepare( 'SELECT e.name FROM ' . self::table( 'event_admins' ) . ' a JOIN ' . self::table( 'events' ) . ' e ON e.id=a.event_id WHERE a.user_id=%d', $user->ID ) );
			$scorer_assignments = $wpdb->get_col( $wpdb->prepare( 'SELECT e.name FROM ' . self::table( 'event_scorers' ) . ' s JOIN ' . self::table( 'events' ) . ' e ON e.id=s.event_id WHERE s.user_id=%d', $user->ID ) );
			if ( $admin_assignments || $scorer_assignments ) {
				$data[] = array( 'group_id' => 'mrsa', 'group_label' => 'Event administration', 'item_id' => 'mrsa-user-' . $user->ID, 'data' => array(
					array( 'name' => 'Event Administrator assignments', 'value' => implode( ', ', $admin_assignments ) ),
					array( 'name' => 'Event Scorer assignments', 'value' => implode( ', ', $scorer_assignments ) ),
				) );
			}
			$reviews = $wpdb->get_results( $wpdb->prepare( 'SELECT e.name,s.id,s.status,s.bonus_id,s.reviewed_at,s.rejection_code,s.rejection_description FROM ' . self::table( 'bonus_submissions' ) . ' s JOIN ' . self::table( 'events' ) . ' e ON e.id=s.event_id WHERE s.reviewed_by=%d ORDER BY s.reviewed_at', $user->ID ), ARRAY_A );
			if ( $reviews ) {
				$review_fields = array();
				foreach ( $reviews as $review ) {
					$review_fields[] = array( 'name' => 'Submission review', 'value' => implode( ' / ', array( $review['name'], $review['id'], $review['bonus_id'], $review['status'], $review['reviewed_at'], $review['rejection_code'], $review['rejection_description'] ) ) );
				}
				$data[] = array( 'group_id' => 'mrsa', 'group_label' => 'Event scoring reviews', 'item_id' => 'mrsa-reviews-' . $user->ID, 'data' => $review_fields );
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
			$staged_submission_files = array( 'root' => '', 'files' => array() );
			try {
				$submission_files = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,image_file FROM ' . self::table( 'bonus_submissions' ) . ' WHERE rider_id=%d', $id ), ARRAY_A );
				$staged_submission_files = MRSA_Bonus::stage_submission_files( $submission_files ?: array() );
				self::begin_transaction();
				$success = false !== $wpdb->update( self::table( 'riders' ), array( 'name' => 'Erased rider ' . $id, 'alias' => '', 'email' => 'erased-' . $id . '@example.invalid', 'phone' => '', 'emergency_name' => '', 'emergency_email' => '', 'emergency_phone' => '', 'insurance' => '', 'other' => '' ), array( 'id' => $id ) );
				$success = false !== $wpdb->delete( self::table( 'rider_bikes' ), array( 'rider_id' => $id ) ) && $success;
				$success = false !== $wpdb->update( self::table( 'registrations' ), array( 'pillion' => '' ), array( 'rider_id' => $id ) ) && $success;
				$success = false !== $wpdb->update( self::table( 'bonus_submissions' ), array( 'sender_email' => 'erased-' . $id . '@example.invalid', 'submission_description' => '', 'image_file' => '' ), array( 'rider_id' => $id ) ) && $success;
				if ( ! $success ) {
					throw new RuntimeException( 'Privacy erasure storage failed.' );
				}
				self::commit_transaction();
			} catch ( RuntimeException $e ) {
				self::rollback_transaction();
				if ( ! empty( $staged_submission_files['root'] ) ) {
					try {
						MRSA_Bonus::restore_submission_files( $staged_submission_files );
					} catch ( RuntimeException $restore_exception ) {
						error_log( 'MRSA privacy erasure image restoration failed for rider #' . $id . ': ' . $restore_exception->getMessage() );
					}
				}
				$success = false;
			}
			if ( $success && ! empty( $staged_submission_files['root'] ) ) {
				try {
					MRSA_Bonus::finish_staged_submission_files( $staged_submission_files );
				} catch ( RuntimeException $cleanup_exception ) {
					error_log( 'MRSA privacy erasure staged image cleanup failed for rider #' . $id . ': ' . $cleanup_exception->getMessage() );
					$success = false;
				}
			}
			$removed = $success;
			$retained = true;
			$messages[] = $success ? 'Rider personal information and submission photos/descriptions erased; event registration IDs, bibs, submission decisions, statuses, scores and placing retained for event integrity.' : 'Unable to erase rider information; please retry or contact the site administrator.';
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$assigned = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::table( 'event_admins' ) . ' WHERE user_id=%d LIMIT 1', $user->ID ) );
			$scorer = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . self::table( 'event_scorers' ) . ' WHERE user_id=%d LIMIT 1', $user->ID ) );
			if ( $assigned || $scorer ) {
				$retained = true;
				$messages[] = 'Event Administrator and Event Scorer assignments retained for access control. Assignments can be removed from the event Configure page. WordPress user profiles and site-administrator access are managed by WordPress.';
			}
		}
		return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true );
	}
}
