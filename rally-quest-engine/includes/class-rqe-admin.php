<?php
/** Admin request handling. Every mutation has an explicit authorization boundary. */
defined( 'ABSPATH' ) || exit;

final class RQE_Admin {
	private static $selected_event = null;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_rqe_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_rqe_diagnostics_download', array( __CLASS__, 'diagnostics_download' ) );
		add_action( 'admin_post_rqe_diagnostics_check', array( __CLASS__, 'diagnostics_check' ) );
		add_action( 'admin_post_rqe_image_queue', array( __CLASS__, 'image_queue' ) );
		RQE_Certificates::init();
	}

	public static function menu() {
		if ( RQE::access() ) {
			add_menu_page( 'Rally Quest Engine', 'Rally Quest', 'read', 'rqe', array( 'RQE_Views', 'render' ), 'dashicons-location-alt', 58 );
			add_submenu_page( null, 'Event Scoring', 'Event Scoring', 'read', 'rqe-scoring', array( 'RQE_Views', 'render_scoring' ) );
			add_submenu_page( null, 'Edit Participant', 'Edit Participant', 'read', 'rqe-rider-edit', array( 'RQE_Views', 'render_rider_edit' ) );
			add_submenu_page( null, 'Edit Event', 'Edit Event', 'read', 'rqe-event-edit', array( 'RQE_Views', 'render_event_edit' ) );
			add_submenu_page( null, 'Event Email Submissions', 'Event Email Submissions', 'read', 'rqe-event-email', array( 'RQE_Views', 'render_event_email' ) );
			add_submenu_page( null, 'Event Roster', 'Event Roster', 'read', 'rqe-event-roster', array( 'RQE_Views', 'render_event_roster' ) );
			add_submenu_page( null, 'Add Participant to Event', 'Add Participant to Event', 'read', 'rqe-event-enroll', array( 'RQE_Views', 'render_event_enroll' ) );
			add_submenu_page( null, 'Import Participants', 'Import Participants', 'read', 'rqe-roster-import', array( 'RQE_Views', 'render_roster_import' ) );
			add_submenu_page( null, 'Participant Overall Event Score Sheet', 'Participant Overall Event Score Sheet', 'read', 'rqe-rider-score', array( 'RQE_Views', 'render_rider_score' ) );
			add_submenu_page( null, 'Edit Bonus Point', 'Edit Bonus Point', 'read', 'rqe-bonus-edit', array( 'RQE_Views', 'render_bonus_edit' ) );
		}
	}

	public static function assets( $hook ) {
		if ( ! RQE::access() ) {
			return;
		}
		wp_add_inline_style( 'dashicons', '#adminmenu .toplevel_page_rqe .wp-menu-image::before,#adminmenu .toplevel_page_rqe:hover .wp-menu-image::before,#adminmenu .toplevel_page_rqe.current .wp-menu-image::before{color:#ff0800}' );
		if ( ! in_array( $hook, array( 'toplevel_page_rqe', 'admin_page_rqe-scoring', 'admin_page_rqe-rider-edit', 'admin_page_rqe-event-edit', 'admin_page_rqe-event-email', 'admin_page_rqe-event-roster', 'admin_page_rqe-event-enroll', 'admin_page_rqe-roster-import', 'admin_page_rqe-rider-score', 'admin_page_rqe-bonus-edit' ), true ) ) {
			return;
		}
		// Rebuilt releases can retain their version; invalidate cached UI assets when their contents change.
		$asset_version = RQE_VERSION . '.' . RQE_DB_VERSION . '.' . substr( hash_file( 'sha256', dirname( RQE_FILE ) . '/assets/admin.js' ), 0, 12 ) . '.' . substr( hash_file( 'sha256', dirname( RQE_FILE ) . '/assets/admin.css' ), 0, 12 );
		wp_enqueue_style( 'rqe-admin', plugins_url( 'assets/admin.css', RQE_FILE ), array(), $asset_version );
		wp_enqueue_script( 'rqe-admin', plugins_url( 'assets/admin.js', RQE_FILE ), array(), $asset_version, true );
		if ( 'admin_page_rqe-event-edit' === $hook ) {
			wp_enqueue_media();
		}
	}

	public static function query( $key, $default = '' ) {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : $default;
	}

	public static function posted( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : $default;
	}

	/** Capture the bonus-import form once at the HTTP boundary, then pass values explicitly. */
	private static function bonus_input() {
		$input = array( 'files' => isset( $_FILES ) && is_array( $_FILES ) ? $_FILES : array() );
		foreach ( array( 'import_step', 'gallery_url', 'image_source', 'allow_partial_images', 'scan_approval', 'bypass_confirmation', 'pending_token', 'new_bonus_id', 'description', 'category', 'points', 'locations', 'remove_image' ) as $key ) {
			$input[ $key ] = self::posted( $key );
		}
		return $input;
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
		if ( in_array( $tab, array( 'rider-edit', 'event-edit', 'event-email', 'event-roster', 'event-enroll', 'roster-import', 'rider-score', 'bonus-edit' ), true ) ) {
			return add_query_arg( array_merge( array( 'page' => 'rqe-' . $tab ), $extra ), admin_url( 'admin.php' ) );
		}
		return add_query_arg( array_merge( array( 'page' => 'rqe', 'tab' => $tab ), $extra ), admin_url( 'admin.php' ) );
	}

	public static function remember_event( $id ) {
		if ( ! RQE::scoring_access( $id ) || ! RQE::row( 'events', $id ) ) {
			throw new RuntimeException( 'Event not found or you are not assigned to it.' );
		}
		$current = (int) get_user_option( 'rqe_selected_event', get_current_user_id() );
		if ( $current !== $id && ! update_user_option( get_current_user_id(), 'rqe_selected_event', $id, false ) ) {
			// WordPress also returns false when the database already contains this value.
			wp_cache_delete( get_current_user_id(), 'user_meta' );
			if ( (int) get_user_option( 'rqe_selected_event', get_current_user_id() ) !== $id ) {
				throw new RuntimeException( 'The selected event could not be remembered.' );
			}
		}
		self::$selected_event = $id;
	}

	public static function selected_event() {
		if ( null !== self::$selected_event ) {
			return self::$selected_event;
		}
		$explicit = self::query( 'event_id' );
		if ( '' !== $explicit ) {
			try {
				self::remember_event( self::integer( $explicit, 1 ) );
			} catch ( RuntimeException $exception ) {
				wp_die( esc_html( $exception->getMessage() ), '', array( 'response' => 403 ) );
			}
			return self::$selected_event;
		}
		$id = (int) get_user_option( 'rqe_selected_event', get_current_user_id() );
		if ( $id && ( ! RQE::scoring_access( $id ) || ! RQE::row( 'events', $id ) ) ) {
			echo '<div class="notice notice-warning"><p>Your selected event no longer exists or is no longer accessible. Choose an event in the header.</p></div>';
			$id = 0;
		}
		self::$selected_event = $id;
		return $id;
	}

	public static function require_site_admin() {
		if ( ! RQE::site_access() ) {
			throw new RuntimeException( 'You must be a WordPress administrator to manage shared participants, motorcycles, events, diagnostics, or event administrator assignments.' );
		}
	}

	public static function require_event( $id ) {
		if ( ! RQE::event_access( $id ) || ! RQE::row( 'events', $id ) ) {
			throw new RuntimeException( 'Event not found or you are not assigned to it.' );
		}
	}

	private static function require_scoring_event( $id ) {
		if ( ! RQE::scorer_access( $id ) || ! RQE::row( 'events', $id ) ) {
			throw new RuntimeException( 'Scoring event not found or you are not assigned to it.' );
		}
	}

	private static function require_user( $id ) {
		if ( ! get_user_by( 'id', $id ) ) {
			throw new RuntimeException( 'The selected WordPress user no longer exists.' );
		}
	}

	public static function checked( $result ) {
		if ( false === $result ) {
			throw new RuntimeException( 'The record could not be saved. Check for duplicate emails, catalog rows, assigned participants or bibs, then refresh and try again.' );
		}
		return $result;
	}

	private static function return_url() {
		$url = self::posted( 'return_url' );
		$url = $url ? wp_validate_redirect( $url, '' ) : wp_get_referer();
		if ( ! $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$admin_path = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
			return $admin_path && 0 === strpos( $url, $admin_path ) ? $url : '';
		}
		return 0 === strpos( $url, admin_url() ) ? $url : '';
	}

	public static function save() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! RQE::access() ) {
			wp_die( esc_html( 'Unauthorized request.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rqe_save' );
		$op = self::posted( 'op' );
		$return_url = self::return_url();
		$tab = 'events';
		$extra = array();
		$bonus_step = null;
		try {
			if ( in_array( $op, array( 'bonus_clamav_check', 'bonus_import_require_scan', 'bonus_import_disable_scan' ), true ) ) {
				$bonus_step = 'setup';
			} elseif ( 'bonus_import' === $op ) {
				$bonus_step = 'listing' === self::posted( 'import_step' ) ? 'listing' : 'images';
			} elseif ( in_array( $op, array( 'bonus_import_approve', 'bonus_import_bypass', 'bonus_import_cancel' ), true ) ) {
				$bonus_step = 'listing' === self::posted( 'import_step' ) ? 'listing' : 'images';
			}
			switch ( $op ) {
				case 'archive_visibility':
					$tab = self::posted( 'context_tab', 'events' );
					if ( ! in_array( $tab, array( 'initial-setup', 'riders', 'events', 'rosters', 'scoring', 'results', 'bikes', 'horses', 'diagnostics', 'about' ), true ) || ( 'initial-setup' === $tab && ! RQE::site_access() ) ) {
						throw new RuntimeException( 'Select an application tab.' );
					}
					if ( ! RQE::site_access() && ! RQE::event_ids() && ! in_array( $tab, array( 'scoring', 'about' ), true ) ) {
						$tab = 'scoring';
					}
					RQE_Event_State::save_visibility( '1' === self::posted( 'show_archived' ) );
					$message = 'Archived-event visibility updated.';
					break;
				case 'event_state':
					$return_url = '';
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					$tab = self::posted( 'context_tab', 'results' );
					if ( ! in_array( $tab, array( 'results', 'event-edit' ), true ) ) {
						throw new RuntimeException( 'Select a supported event protection screen.' );
					}
					RQE_Event_State::change( $extra['event_id'], self::posted( 'state_action' ) );
					$message = 'Event state updated.';
					break;
				case 'choose_event':
					$return_url = '';
					$tab = self::posted( 'context_tab', 'events' );
					if ( ! in_array( $tab, array( 'initial-setup', 'riders', 'events', 'rosters', 'scoring', 'results', 'bikes', 'horses', 'diagnostics', 'about' ), true ) || ( 'initial-setup' === $tab && ! RQE::site_access() ) ) {
						throw new RuntimeException( 'Select an application tab.' );
					}
					if ( ! RQE::site_access() && ! RQE::event_ids() && ! in_array( $tab, array( 'scoring', 'about' ), true ) ) {
						$tab = 'scoring';
					}
					self::remember_event( self::integer( self::posted( 'event_id' ), 1 ) );
					if ( in_array( $tab, array( 'rosters', 'results' ), true ) && ! RQE::event_access( self::$selected_event ) ) {
						$tab = 'scoring';
					}
					$message = 'Selected event updated.';
					break;
				case 'status_option':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					RQE_Statuses::change_option( $extra['event_id'], self::posted( 'status_kind' ), self::posted( 'status_action' ), self::posted( 'status_label' ) );
					$tab = 'event-edit';
					break;
				case 'public_results':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					RQE_Public_Results::save_visibility( $extra['event_id'], self::integer( self::posted( 'public_results_enabled', '0' ), 0, 1 ), self::integer( self::posted( 'public_bonus_details_enabled', '0' ), 0, 1 ) );
					$message = 'Public results setting saved. Purge WordPress/CDN page caches to update previously cached embeds.';
					$tab = 'event-edit';
					break;
				case 'event_admin':
				case 'remove_event_admin':
					self::require_site_admin();
					self::event_admin( 'remove_event_admin' === $op );
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					$tab = 'event-edit';
					break;
				case 'event_scorer':
				case 'remove_event_scorer':
					self::require_site_admin();
					self::event_assignment( 'event_scorers', 'remove_event_scorer' === $op );
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					$tab = 'event-edit';
					break;
				case 'event_email':
					$return_url = '';
					self::require_site_admin();
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					$input = array();
					foreach ( array( 'provider', 'enabled', 'poll_interval_minutes', 'mailbox', 'host', 'port', 'encryption', 'username', 'folder', 'password', 'client_id', 'client_secret', 'refresh_token', 'tenant_id' ) as $key ) {
						$input[ $key ] = self::posted( $key, 'poll_interval_minutes' === $key ? '5' : '' );
					}
					$message = RQE_Email_Submissions::save_configuration( $extra['event_id'], $input );
					$tab = 'event-edit';
					break;
				case 'global_email':
					$return_url = '';
					self::require_site_admin();
					$input = array();
					foreach ( array( 'provider', 'enabled', 'poll_interval_minutes', 'mailbox', 'host', 'port', 'encryption', 'username', 'folder', 'password', 'client_id', 'client_secret', 'refresh_token', 'tenant_id' ) as $key ) {
						$input[ $key ] = self::posted( $key, 'poll_interval_minutes' === $key ? '5' : '' );
					}
					$message = RQE_Email_Submissions::save_configuration( 0, $input );
					$tab = 'initial-setup';
					break;
				case 'global_inbox_assignment':
					self::require_site_admin();
					$global_status = RQE_Email_Submissions::global_status();
					if ( $global_status['assigned_event_id'] && (string) $global_status['assigned_event_id'] !== self::posted( 'assigned_event_id', '0' ) && '1' !== self::posted( 'assignment_confirmation' ) ) {
						throw new RuntimeException( 'Confirm that unread messages for the previous event have been handled before changing the global inbox assignment.' );
					}
					$message = RQE_Email_Submissions::save_global_assignment( self::posted( 'assigned_event_id', '0' ) );
					$tab = 'initial-setup';
					break;
				case 'global_email_test':
					$return_url = '';
					self::require_site_admin();
					$message = RQE_Email_Submissions::test_connection( 0 );
					$tab = 'initial-setup';
					break;
				case 'event_email_check':
					$return_url = '';
					self::require_site_admin();
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					$message = RQE_Email_Submissions::process_event( $extra['event_id'] );
					$tab = 'event-edit';
					break;
				case 'event_email_test':
					$return_url = '';
					self::require_site_admin();
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					$message = RQE_Email_Submissions::test_event_connection( $extra['event_id'] );
					$tab = 'event-edit';
					break;
				case 'event_email_clear':
					$return_url = '';
					self::require_site_admin();
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					$message = RQE_Email_Submissions::remove_event_override( $extra['event_id'] );
					$tab = 'event-edit';
					break;
				case 'event_email_override':
					self::require_site_admin();
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					if ( '1' === self::posted( 'event_email_override' ) ) {
						$tab = 'event-email';
						$message = 'Save the event-specific inbox configuration to enable the override.';
					} else {
						$message = RQE_Email_Submissions::has_event_override( $extra['event_id'] ) ? RQE_Email_Submissions::remove_event_override( $extra['event_id'] ) : 'No event override is enabled.';
						$tab = 'event-edit';
					}
					break;
				case 'submission_review':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_scoring_event( $extra['event_id'] );
					$message = RQE_Email_Submissions::review(
						$extra['event_id'],
						self::integer( self::posted( 'submission_id' ), 1 ),
						self::posted( 'decision' ),
						self::posted( 'rejection_code' ),
						self::posted( 'rejection_description' )
					);
					$tab = 'scoring';
					$extra['score_tab'] = 'pending';
					break;
				case 'primary_style':
					self::require_site_admin();
					RQE_Styles::save_primary( self::posted( 'primary_style' ) );
					$message = 'Primary scavenger hunt style saved.';
					$tab = 'initial-setup';
					break;
				case 'horse':
					self::require_site_admin();
					$extra['horse_id'] = RQE_Horses::save( self::integer( self::posted( 'id', '0' ) ), RQE_Horses::data( wp_unslash( $_POST ) ) );
					$tab = 'horses';
					break;
				case 'delete_horse':
					self::require_site_admin();
					RQE_Horses::delete( self::integer( self::posted( 'id' ), 1 ) );
					$message = 'Horse deleted.';
					$tab = 'horses';
					break;
				case 'bike':
					self::require_site_admin();
					self::bike();
					$tab = 'bikes';
					break;
				case 'delete_bike':
					self::require_site_admin();
					$message = self::delete_bikes( false );
					$tab = 'bikes';
					break;
				case 'delete_bikes':
					self::require_site_admin();
					$message = self::delete_bikes( true );
					$tab = 'bikes';
					break;
				case 'source_import':
					self::require_site_admin();
					if ( '1' !== self::posted( 'source_consent' ) ) {
						throw new RuntimeException( 'Confirm that you understand the source and review requirements before importing the factual catalog data.' );
					}
					$message = RQE_Importer::import( self::posted( 'import_make' ) );
					$tab = 'bikes';
					break;
				case 'rider':
					self::require_site_admin();
					$is_new = 0 === self::integer( self::posted( 'id', '0' ) );
					$extra['rider_id'] = self::rider();
					$enroll_event_id = self::posted( 'enroll_event_id' );
					if ( $is_new && '' !== $enroll_event_id ) {
						$enroll_event_id = self::integer( $enroll_event_id, 1 );
						self::require_event( $enroll_event_id );
						self::enroll_new_rider( $enroll_event_id, $extra['rider_id'] );
						$message = 'Participant saved and added to the event with the next available bib.';
					}
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
					$tab = 'rosters';
					break;
				case 'roster_import':
					self::require_site_admin();
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					$message = self::roster_import( $extra['event_id'] );
					$tab = 'rosters';
					break;
				case 'registration_status':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					self::registration_status( $extra['event_id'] );
					$tab = 'results';
					break;
				case 'registration_lifecycle':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					self::registration_lifecycle( $extra['event_id'] );
					$tab = 'rosters';
					break;
				case 'bonus_clamav_check':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					$check = RQE_File_Scan::check_connection();
					$check_id = wp_generate_password( 24, false, false );
					if ( ! set_transient( 'rqe_clamav_check_' . get_current_user_id() . '_' . $check_id, array_merge( $check, array( 'event_id' => $extra['event_id'] ) ), 15 * MINUTE_IN_SECONDS ) ) {
						throw new RuntimeException( 'The connection-check result could not be stored. ' . $check['message'] );
					}
					$extra['clamav_check'] = $check_id;
					$message = 'ClamAV connection check completed. See Step 0 for the result.';
					$tab = 'scoring';
					$extra['score_tab'] = 'library';
					break;
				case 'bonus_import':
				case 'bonus_import_approve':
				case 'bonus_import_bypass':
				case 'bonus_import_cancel':
					$service = new RQE_Bonus_Application_Service();
					$input = self::bonus_input();
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					$action = $service->import_action( $op, $extra['event_id'], $input, $bonus_step );
					$bonus_step = $action['step'];
					$result = $action['result'];
					if ( isset( $result['pending_token'] ) ) {
						$extra['bonus_pending'] = $result['pending_token'];
					}
					$message = $result['message'];
					if ( $result['errors'] ) {
						$report_id = wp_generate_password( 24, false, false );
						if ( ! set_transient( 'rqe_bonus_report_' . get_current_user_id() . '_' . $report_id, array( 'event_id' => $extra['event_id'], 'step' => $bonus_step, 'errors' => $result['errors'] ), 15 * MINUTE_IN_SECONDS ) ) {
							throw new RuntimeException( $message . ' The detailed error report could not be stored. Errors: ' . implode( '; ', $result['errors'] ) );
						}
						$extra['bonus_report'] = $report_id;
					}
					$tab = 'scoring';
					$extra['score_tab'] = 'library';
					break;
				case 'bonus_import_disable_scan':
				case 'bonus_import_require_scan':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					$message = ( new RQE_Bonus_Application_Service() )->set_scan_override(
						$extra['event_id'],
						'bonus_import_disable_scan' === $op,
						'1' === self::posted( 'bypass_confirmation' )
					);
					$tab = 'scoring';
					$extra['score_tab'] = 'library';
					break;
				case 'bonus_point':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					( new RQE_Bonus_Application_Service() )->save( $extra['event_id'], self::text( self::posted( 'bonus_id' ), 64, true ), self::bonus_input() );
					$tab = 'scoring';
					$extra['score_tab'] = 'library';
					break;
				case 'participant_visibility':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					RQE_Participants::save_visibility( $extra['event_id'], '1' === self::posted( 'enabled' ) );
					$tab = 'event-edit';
					break;
				case 'participant_decide':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					if ( ! in_array( self::posted( 'decision' ), array( 'approve', 'reject' ), true ) ) { throw new RuntimeException( 'Choose approval or rejection.' ); }
					RQE_Participants::decide( $extra['event_id'], self::integer( self::posted( 'id' ), 1 ), 'approve' === self::posted( 'decision' ) );
					$tab = 'event-edit';
					break;
				case 'certificate_history_delete':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					RQE_Certificate_History::delete( $extra['event_id'], self::integer( self::posted( 'id' ), 1 ), '1' === self::posted( 'confirmed' ) );
					$tab = 'results';
					$message = 'Certificate history entry deleted. Delivery deduplication and scores are unchanged.';
					break;
				case 'diagnostics_delete_certificate_history':
					self::require_site_admin();
					self::verify_diagnostics( $op );
					RQE_Certificate_History::delete_all( true );
					$tab = 'diagnostics';
					$message = 'All certificate history deleted. Certificate delivery queues and scores were not changed.';
					break;
				case 'diagnostics_delete_all':
				case 'diagnostics_delete_bikes':
				case 'diagnostics_delete_roster':
				case 'diagnostics_delete_rider':
				case 'diagnostics_delete_bonus_points':
				case 'diagnostics_delete_audit':
					self::require_site_admin();
					self::verify_diagnostics( $op );
					$message = self::diagnostics_delete( $op );
					$tab = 'diagnostics';
					break;
				case 'diagnostics_review':
					self::require_site_admin();
					$report = self::current_diagnostics_report();
					$message = RQE_Setup::set_review_ignored(
						self::text( self::posted( 'review_name' ), 160, true ),
						'1' === self::posted( 'ignore_review' ),
						$report['rows'],
						$report['checked_at']
					);
					$tab = 'diagnostics' === self::posted( 'context_tab' ) ? 'diagnostics' : 'initial-setup';
					$message = 'Manual review preference saved. This affects setup readiness only; it does not change or bypass the check.';
					break;
				case 'diagnostics_setup_override':
					self::require_site_admin();
					if ( '1' !== self::posted( 'setup_override_confirmation' ) ) {
						throw new RuntimeException( 'Confirm that you understand failed checks remain visible and runtime protections still apply.' );
					}
					$report = self::current_diagnostics_report();
					RQE_Setup::set_setup_override( true, $report['rows'], $report['checked_at'] );
					$tab = 'initial-setup';
					$message = 'Setup override acknowledged. Failed checks remain visible and runtime validation and safety checks are unchanged.';
					break;
				case 'diagnostics_override_remove':
					self::require_site_admin();
					$report = self::current_diagnostics_report();
					RQE_Setup::set_setup_override( false, $report['rows'], $report['checked_at'] );
					$tab = 'initial-setup';
					$message = 'Setup override removed. Readiness now reflects the diagnostic results and manual-review choices.';
					break;
				default:
					throw new RuntimeException( 'Unknown operation.' );
			}
			$message = $message ?? 'Saved successfully.';
			if ( null !== $bonus_step ) {
				self::bonus_redirect( $extra, $bonus_step, $message, false !== strpos( $message, 'WARNING:' ) ? 'warning' : 'success' );
			}
			$extra['notice'] = $message;
			$redirect = $return_url ? add_query_arg( 'notice', $message, remove_query_arg( 'notice', $return_url ) ) : self::url( $tab, $extra );
			if ( isset( $extra['bonus_report'] ) ) {
				$redirect = add_query_arg( 'bonus_report', $extra['bonus_report'], $redirect );
			}
			if ( isset( $extra['bonus_pending'] ) ) {
				$redirect = add_query_arg( 'bonus_pending', $extra['bonus_pending'], $redirect );
			}
			if ( isset( $extra['clamav_check'] ) ) {
				$redirect = add_query_arg( 'clamav_check', $extra['clamav_check'], $redirect );
			}
			wp_safe_redirect( $redirect );
			exit;
		} catch ( RuntimeException $e ) {
			if ( 'event_email' === $op && ! empty( $extra['event_id'] ) && RQE::site_access() && RQE::row( 'events', $extra['event_id'] ) ) {
				wp_safe_redirect( self::url( 'event-email', array( 'event_id' => $extra['event_id'], 'error' => $e->getMessage() ) ) );
				exit;
			}
			if ( in_array( $op, array( 'event_email_test', 'event_email_check', 'event_email_clear' ), true ) && ! empty( $extra['event_id'] ) && RQE::event_access( $extra['event_id'] ) && RQE::row( 'events', $extra['event_id'] ) ) {
				wp_safe_redirect( self::url( 'event-edit', array( 'event_id' => $extra['event_id'], 'error' => $e->getMessage() ) ) );
				exit;
			}
			if ( 'global_email_test' === $op ) {
				wp_safe_redirect( self::url( 'initial-setup', array( 'error' => $e->getMessage() ) ) );
				exit;
			}
			if ( 'global_email' === $op ) {
				wp_safe_redirect( self::url( 'event-email', array( 'scope' => 'global', 'error' => $e->getMessage() ) ) );
				exit;
			}
			if ( null !== $bonus_step && ! empty( $extra['event_id'] ) && RQE::event_access( $extra['event_id'] ) && RQE::row( 'events', $extra['event_id'] ) ) {
				self::bonus_redirect( array( 'event_id' => $extra['event_id'] ), $bonus_step, $e->getMessage(), 'error' );
			}
			wp_die( esc_html( $e->getMessage() ), esc_html( 'Rally update not saved' ), array( 'response' => 400, 'back_link' => true ) );
		}
	}

	private static function current_diagnostics_report() {
		$report = get_transient( 'rqe_configuration_' . get_current_user_id() );
		if ( ! is_array( $report ) || empty( $report['rows'] ) || empty( $report['checked_at'] ) ) {
			throw new RuntimeException( 'The private diagnostic report is missing or expired. Rerun Required Plugins and Configuration first.' );
		}
		RQE_Diagnostic_Report::state( $report['rows'] );
		return $report;
	}

	private static function bonus_redirect( $extra, $step, $message, $severity ) {
		$id = wp_generate_password( 24, false, false );
		if ( ! set_transient( 'rqe_bonus_feedback_' . get_current_user_id() . '_' . $id, array(
			'event_id' => $extra['event_id'], 'step' => $step, 'message' => $message, 'severity' => $severity,
		), 15 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html( $message . ' The section feedback could not be stored.' ), '', array( 'response' => 500, 'back_link' => true ) );
		}
		unset( $extra['notice'] );
		$extra['score_tab'] = 'library';
		$extra['bonus_feedback'] = $id;
		wp_safe_redirect( self::url( 'scoring', $extra ) . '#rqe-import-feedback' );
		exit;
	}

	private static function event_admin( $remove ) {
		self::event_assignment( 'event_admins', $remove );
	}

	private static function event_assignment( $table, $remove ) {
		global $wpdb;
		$event_id = self::integer( self::posted( 'event_id' ), 1 );
		$id = self::integer( self::posted( 'user_id' ), 1 );
		self::require_event( $event_id );
		if ( ! $remove ) {
			self::require_user( $id );
		}
		$where = array( 'event_id' => $event_id, 'user_id' => $id );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . RQE::table( $table ) . ' WHERE event_id=%d AND user_id=%d', $event_id, $id ) );
		if ( $remove ) {
			self::checked( $wpdb->delete( RQE::table( $table ), $where ) );
		} elseif ( ! $exists ) {
			self::checked( $wpdb->insert( RQE::table( $table ), $where ) );
		}
	}

	private static function bike() {
		global $wpdb;
		$id = self::integer( self::posted( 'id', '0' ) );
		if ( $id && ! RQE::row( 'bikes', $id ) ) {
			throw new RuntimeException( 'Motorcycle not found.' );
		}
		$data = array( 'make' => self::text( self::posted( 'make' ), 80, true ), 'model' => self::text( self::posted( 'model' ), 120, true ), 'year' => 0 );
		if ( ! $id ) {
			$data['source'] = 'Manual entry';
		}
		self::checked( $id ? $wpdb->update( RQE::table( 'bikes' ), $data, array( 'id' => $id ) ) : $wpdb->insert( RQE::table( 'bikes' ), $data ) );
	}

	private static function delete_bikes( $bulk ) {
		global $wpdb;
		$values = $bulk
			? ( isset( $_POST['bike_ids'] ) && is_array( $_POST['bike_ids'] ) ? wp_unslash( $_POST['bike_ids'] ) : array() )
			: array( self::posted( 'id' ) );
		if ( ! $values || count( $values ) > 500 ) {
			throw new RuntimeException( 'Select between 1 and 500 motorcycle records to delete.' );
		}
		$ids = array();
		foreach ( $values as $value ) {
			$ids[] = self::integer( $value, 1 );
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		RQE::begin_transaction();
		try {
			foreach ( $ids as $id ) {
				$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'bikes' ) . ' WHERE id=%d FOR UPDATE', $id ) );
				if ( ! $locked ) {
					throw new RuntimeException( 'A selected motorcycle no longer exists or could not be locked.' );
				}
				$used = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'rider_bikes' ) . ' WHERE bike_id=%d LIMIT 1', $id ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Unable to check motorcycle assignments. No catalog records were deleted.' );
				}
				if ( $used ) {
					throw new RuntimeException( 'A selected motorcycle is assigned to a participant. No catalog records were deleted.' );
				}
			}
			foreach ( $ids as $id ) {
				self::checked( $wpdb->delete( RQE::table( 'bikes' ), array( 'id' => $id ) ) );
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $e ) {
			RQE::rollback_transaction();
			throw $e;
		}
		return count( $ids ) . ' motorcycle catalog record(s) deleted.';
	}

	private static function rider() {
		global $wpdb;
		$id = self::integer( self::posted( 'id', '0' ) );
		if ( $id && ! RQE::row( 'riders', $id ) ) {
			throw new RuntimeException( 'Participant not found.' );
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
		// Only the conveyance section rendered for the primary style is replaced; other assignments are preserved.
		$vehicles = '1' === self::posted( 'vehicle_selection' );
		$horses = '1' === self::posted( 'horse_selection' );
		$horse_ids = $horses && isset( $_POST['horse_ids'] ) && is_array( $_POST['horse_ids'] ) ? wp_unslash( $_POST['horse_ids'] ) : array();
		$bike_ids = isset( $_POST['bike_ids'] ) && is_array( $_POST['bike_ids'] ) ? wp_unslash( $_POST['bike_ids'] ) : array();
		$fuel = isset( $_POST['fuel'] ) && is_array( $_POST['fuel'] ) ? wp_unslash( $_POST['fuel'] ) : array();
		$model_years = isset( $_POST['model_year'] ) && is_array( $_POST['model_year'] ) ? wp_unslash( $_POST['model_year'] ) : array();
		if ( count( $bike_ids ) > 20 || count( $bike_ids ) !== count( $fuel ) || count( $bike_ids ) !== count( $model_years ) ) {
			throw new RuntimeException( 'Select at most 20 motorcycles with matching years and fuel capacities.' );
		}
		$bikes = array();
		foreach ( array_values( $bike_ids ) as $i => $bike_id ) {
			if ( '' === $bike_id ) {
				continue;
			}
			$bike_id = self::integer( $bike_id, 1 );
			$model_year = trim( (string) array_values( $model_years )[ $i ] );
			$bikes[] = array(
				'bike_id'      => $bike_id,
				'model_year'   => '' === $model_year ? 0 : self::integer( $model_year, 1000, 9999 ),
				'fuel_capacity' => self::text( array_values( $fuel )[ $i ], 100 ),
			);
		}
		RQE::begin_transaction();
		try {
			$lock_ids = array_values( array_unique( array_column( $bikes, 'bike_id' ) ) );
			sort( $lock_ids, SORT_NUMERIC );
			foreach ( $lock_ids as $bike_id ) {
				// Catalog deletion uses the same row lock; ordered locking avoids deadlocks.
				$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'bikes' ) . ' WHERE id=%d FOR UPDATE', $bike_id ) );
				if ( ! $locked ) {
					throw new RuntimeException( 'A selected motorcycle no longer exists or could not be locked. Refresh the catalog and try again.' );
				}
			}
			self::checked( $id ? $wpdb->update( RQE::table( 'riders' ), $data, array( 'id' => $id ) ) : $wpdb->insert( RQE::table( 'riders' ), $data ) );
			$id = $id ?: (int) $wpdb->insert_id;
			if ( $vehicles ) {
				self::checked( $wpdb->delete( RQE::table( 'rider_bikes' ), array( 'rider_id' => $id ) ) );
				foreach ( $bikes as $bike ) {
					self::checked( $wpdb->insert( RQE::table( 'rider_bikes' ), array_merge( array( 'rider_id' => $id ), $bike ) ) );
				}
			}
			if ( $horses ) {
				RQE_Horses::assign( $id, $horse_ids );
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $e ) {
			RQE::rollback_transaction();
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
			self::require_site_admin();
		}
		// Required to save: name, description, maximum bib, four dates, participant price and Minimum Finisher Points.
		$description = self::posted( 'description' );
		$description = is_scalar( $description ) ? trim( sanitize_textarea_field( (string) $description ) ) : '';
		if ( '' === $description || strlen( $description ) > 5000 ) {
			throw new RuntimeException( 'Event description is required (maximum 5000 bytes).' );
		}
		foreach ( array( 'rider_price' => 'Participant price', 'min_finisher_points' => 'Minimum Finisher Points' ) as $key => $label ) {
			if ( '' === trim( (string) self::posted( $key ) ) ) {
				throw new RuntimeException( $label . ' is required.' );
			}
		}
		$style = self::posted( 'hunt_style', RQE_Styles::primary() );
		if ( ! RQE_Styles::valid( $style ) ) {
			throw new RuntimeException( 'Choose a valid scavenger hunt style.' );
		}
		$data = array( 'name' => self::text( self::posted( 'name' ), 160, true ), 'description' => $description, 'hunt_style' => $style, 'max_bib' => self::integer( self::posted( 'max_bib' ), 1, 10000 ), 'logo_id' => self::integer( self::posted( 'logo_id', '0' ) ), 'location' => self::text( self::posted( 'location' ), 200 ), 'min_finisher_points' => self::integer( self::posted( 'min_finisher_points' ), 0, 1000000000 ) );
		$data['certificate_design'] = RQE_Certificates::design( self::posted( 'certificate_design', 'vintage' ) );
		$lower = $data['min_finisher_points'];
		$lower_label = 'Minimum Finisher Points';
		foreach ( RQE_Results::tiers() as $key => $status ) {
			$label = str_replace( 'Preliminary ', '', $status ) . ' Points';
			if ( isset( $_POST[ $key ] ) && ! is_scalar( $_POST[ $key ] ) ) {
				throw new RuntimeException( $label . ' must be a whole number from 0 to 1000000000, or left blank (Not Used).' );
			}
			$value = self::posted( $key, '' );
			if ( is_string( $value ) && '' === trim( $value ) ) {
				$data[ $key ] = null;
				continue;
			}
			try {
				$value = self::integer( $value, 0, 1000000000 );
			} catch ( RuntimeException $exception ) {
				throw new RuntimeException( $label . ' must be a whole number from 0 to 1000000000, or left blank (Not Used).' );
			}
			if ( $value <= $lower ) {
				throw new RuntimeException( $label . ' must be greater than ' . $lower_label . '.' );
			}
			$data[ $key ] = $value;
			$lower = $value;
			$lower_label = $label;
		}
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
		$data['rider_price'] = self::decimal( self::posted( 'rider_price' ) );
		$pillion = self::posted( 'pillion_price', '' );
		$data['pillion_price'] = self::decimal( '' === $pillion ? '0' : $pillion );
		$data['latitude'] = self::decimal( self::posted( 'latitude' ), -90, 90, 7, true );
		$data['longitude'] = self::decimal( self::posted( 'longitude' ), -180, 180, 7, true );
		if ( ( null === $data['latitude'] ) !== ( null === $data['longitude'] ) ) {
			throw new RuntimeException( 'Provide both coordinates or leave both blank.' );
		}
		RQE::begin_transaction();
		try {
			if ( $id ) {
				RQE_Event_State::writable( $id );
				// Serialize capacity changes with enrollment, not just duplicate bib assignment.
				$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $id ) );
				if ( ! $locked ) {
					throw new RuntimeException( 'Event no longer exists.' );
				}
				$highest_bib = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(bib) FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d', $id ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Unable to check existing bib assignments. No event update was saved.' );
				}
				if ( (int) $highest_bib > $data['max_bib'] ) {
					throw new RuntimeException( 'Maximum bib cannot be below an existing assigned bib.' );
				}
			}
			self::checked( $id ? $wpdb->update( RQE::table( 'events' ), $data, array( 'id' => $id ) ) : $wpdb->insert( RQE::table( 'events' ), $data ) );
			$is_new = ! $id;
			$id = $id ?: (int) $wpdb->insert_id;
			if ( $is_new ) {
				RQE_Statuses::seed( $id );
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $e ) {
			RQE::rollback_transaction();
			throw $e;
		}
		return $id;
	}

	private static function roster_import( $event_id ) { return RQE_Roster_Import_Service::import_uploaded( $event_id, $_FILES['roster_file'] ?? null ); }

	private static function registration( $event_id, $op ) {
		global $wpdb;
		$staged_submission_files = array( 'root' => '', 'files' => array() );
		RQE::begin_transaction();
		try {
			RQE_Event_State::writable( $event_id );
			$event = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ), ARRAY_A );
			if ( ! $event ) {
				throw new RuntimeException( 'Event no longer exists.' );
			}
			$id = self::integer( self::posted( 'id', '0' ) );
			$row = $id ? RQE::row( 'registrations', $id ) : null;
			if ( 'registration' !== $op && ( ! $row || (int) $row['event_id'] !== $event_id ) ) {
				throw new RuntimeException( 'Registration not found in the selected event.' );
			}
			if ( 'remove_registration' === $op ) {
				$submission_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event_id,image_file FROM ' . RQE::table( 'bonus_submissions' ) . ' WHERE registration_id=%d', $id ), ARRAY_A );
				$staged_submission_files = RQE_Bonus::stage_submission_files( $submission_rows ?: array() );
				self::checked( $wpdb->delete( RQE::table( 'bonus_submissions' ), array( 'registration_id' => $id ) ) );
				self::checked( $wpdb->delete( RQE::table( 'registrations' ), array( 'id' => $id, 'event_id' => $event_id ) ) );
			} else {
				$data = array( 'bib' => self::integer( self::posted( 'bib' ), 1, (int) $event['max_bib'] ), 'pillion' => self::text( self::posted( 'pillion' ), 160 ) );
				if ( 'registration' === $op ) {
					// Event administrators never browse or enroll unregistered master PII.
					self::require_site_admin();
					$rider_id = self::integer( self::posted( 'rider_id' ), 1 );
					$rider = RQE::row( 'riders', $rider_id );
					if ( ! $rider || preg_match( '/^erased-\d+@example\.invalid$/', $rider['email'] ) ) {
						throw new RuntimeException( 'The selected participant no longer exists or was erased.' );
					}
					$data['rider_id'] = $rider_id;
					$data['event_id'] = $event_id;
					$data = array_merge( $data, RQE_Statuses::registration_data( RQE_Statuses::initial_registration( $event_id ) ) );
					self::checked( $wpdb->insert( RQE::table( 'registrations' ), $data ) );
				} else {
					self::checked( $wpdb->update( RQE::table( 'registrations' ), $data, array( 'id' => $id, 'event_id' => $event_id ) ) );
				}
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $e ) {
			RQE::rollback_transaction();
			if ( ! empty( $staged_submission_files['root'] ) ) {
				RQE_Bonus::restore_submission_files( $staged_submission_files );
			}
			throw $e;
		}
		if ( ! empty( $staged_submission_files['root'] ) ) {
			RQE_Bonus::finish_staged_submission_files( $staged_submission_files );
		}
	}

	private static function enroll_new_rider( $event_id, $rider_id ) {
		global $wpdb;
		RQE::begin_transaction();
		try {
			RQE_Event_State::writable( $event_id );
			$event = $wpdb->get_row( $wpdb->prepare( 'SELECT id,max_bib FROM ' . RQE::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ), ARRAY_A );
			if ( ! $event ) {
				throw new RuntimeException( 'The event no longer exists. The participant was saved but could not be enrolled.' );
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d AND rider_id=%d', $event_id, $rider_id ) );
			if ( $exists ) {
				throw new RuntimeException( 'The participant was saved and is already registered for this event.' );
			}
			$used = array_fill_keys( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT bib FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d', $event_id ) ) ), true );
			$bib = 0;
			for ( $candidate = 1; $candidate <= (int) $event['max_bib']; ++$candidate ) {
				if ( ! isset( $used[ $candidate ] ) ) {
					$bib = $candidate;
					break;
				}
			}
			if ( ! $bib ) {
				throw new RuntimeException( 'The participant was saved, but every event bib is assigned. Increase the maximum bib before enrolling the participant.' );
			}
			self::checked( $wpdb->insert( RQE::table( 'registrations' ), array_merge( array( 'event_id' => $event_id, 'rider_id' => $rider_id, 'bib' => $bib, 'pillion' => '' ), RQE_Statuses::registration_data( RQE_Statuses::initial_registration( $event_id ) ) ) ) );
			RQE::commit_transaction();
		} catch ( RuntimeException $e ) {
			RQE::rollback_transaction();
			throw $e;
		}
	}

	private static function registration_status( $event_id ) {
		RQE_Statuses::assign( $event_id, self::integer( self::posted( 'id' ), 1 ), 'event', self::posted( 'status' ) );
	}

	private static function registration_lifecycle( $event_id ) {
		RQE_Statuses::assign( $event_id, self::integer( self::posted( 'id' ), 1 ), 'registration', self::posted( 'registration_status' ) );
	}

	public static function image_queue() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! RQE::access() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized image upload request.' ), 403 );
		}
		check_admin_referer( 'rqe_save' );
		try {
			$event_id = self::integer( self::posted( 'event_id' ), 1 );
			self::require_event( $event_id );
			$result = self::image_queue_operation( $event_id, self::posted( 'queue_operation' ) );
			wp_send_json_success( $result );
		} catch ( RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		}
	}

	/** Reuse single-use, user/event-bound scan reviews; never accept a combined file upload. */
	private static function image_queue_operation( $event_id, $operation ) {
		if ( 'upload' === $operation ) {
			$file = $_FILES['bonus_images'] ?? null;
			if ( ! is_array( $file ) || ! isset( $file['name'] ) || ! is_array( $file['name'] ) || count( $file['name'] ) !== 1 || array_keys( $file['name'] ) !== array( 0 ) ) {
				throw new RuntimeException( 'Upload exactly one image per request.' );
			}
			if ( 'images' !== self::posted( 'import_step' ) || self::posted( 'gallery_url' ) || isset( $_FILES['bonus_file'] ) ) {
				throw new RuntimeException( 'The sequential upload accepts only a local image, not listings or URL sources.' );
			}
			$input = self::bonus_input();
			$result = ( new RQE_Bonus_Application_Service() )->import( $event_id, $input );
			if ( isset( $result['pending_token'] ) ) {
				$pending = RQE_Bonus::pending( $event_id, $result['pending_token'] );
				return array(
					'state' => 'review', 'token' => $result['pending_token'],
					'message' => $result['message'], 'scans' => $pending['scans'],
					'errors' => $pending['errors'], 'can_approve' => (bool) $pending['can_approve'],
					'unscanned' => ! empty( $pending['unscanned'] ),
					'scanner_error' => in_array( 'Scanner error', array_column( $pending['scans'], 'status' ), true ),
				);
			}
			return array( 'state' => 'imported', 'message' => $result['message'], 'errors' => $result['errors'] );
		}
		if ( ! in_array( $operation, array( 'approve', 'bypass', 'cancel' ), true ) ) {
			throw new RuntimeException( 'Unsupported sequential image operation.' );
		}
		$token = self::posted( 'pending_token' );
		$service = new RQE_Bonus_Application_Service();
		$pending = $service->pending( $event_id, $token );
		if ( 'images' !== $pending['import_step'] || count( $pending['images'] ) > 1 || $pending['rows'] ) {
			throw new RuntimeException( 'This review is not for a single image.' );
		}
		$review = $service->review( $event_id, $token, $operation, self::bonus_input() );
		$result = $review['result'];
		return array( 'state' => 'cancel' === $operation ? 'cancelled' : 'imported', 'message' => $result['message'], 'errors' => $result['errors'] );
	}

	public static function diagnostics_hash( $operation, $code ) {
		return wp_hash( get_current_user_id() . '|' . $operation . '|' . $code );
	}

	public static function diagnostics_check() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! RQE::site_access() ) {
			wp_die( esc_html( 'Only WordPress administrators may check RQE configuration.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rqe_diagnostics_check' );
		$report = RQE_Diagnostics::check();
		if ( ! set_transient( 'rqe_configuration_' . get_current_user_id(), $report, 15 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html( 'The configuration report could not be saved. Rerun the check.' ), '', array( 'response' => 500 ) );
		}
		$state = RQE_Setup::record_diagnostics( $report['rows'], $report['checked_at'], true );
		// Retain only the last outcome for this administrator/site, not expired diagnostic details.
		update_user_option( get_current_user_id(), 'rqe_configuration_last_state', $state );
		if ( get_user_option( 'rqe_configuration_last_state', get_current_user_id() ) !== $state ) {
			wp_die( esc_html( 'The last configuration outcome could not be saved. Rerun the check.' ), '', array( 'response' => 500 ) );
		}
		$context_tab = self::posted( 'context_tab', 'diagnostics' );
		if ( ! in_array( $context_tab, array( 'initial-setup', 'diagnostics' ), true ) ) {
			$context_tab = 'diagnostics';
		}
		wp_safe_redirect( self::url( $context_tab ) . '#rqe-configuration' );
		exit;
	}

	private static function verify_diagnostics( $operation ) {
		$code = self::text( self::posted( 'verification_code' ), 32, true );
		$expected = self::text( self::posted( 'verification_hash' ), 100, true );
		if ( ! hash_equals( $expected, self::diagnostics_hash( $operation, $code ) ) ) {
			throw new RuntimeException( 'The randomized verification string did not match. No data was deleted.' );
		}
	}

	private static function diagnostics_delete( $operation ) {
		$input = array( 'event_id' => self::posted( 'event_id' ), 'rider_id' => self::posted( 'rider_id' ) );
		return ( new RQE_Diagnostics_Deletion_Service() )->delete( $operation, $input );
	}
	public static function diagnostics_download() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! RQE::site_access() ) {
			wp_die( esc_html( 'Unauthorized request.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'rqe_diagnostics_download' );
		$type = self::posted( 'download_type' );
		if ( ! in_array( $type, array( 'json', 'sql' ), true ) ) {
			wp_die( esc_html( 'Unsupported diagnostics download.' ), '', array( 'response' => 400 ) );
		}
		global $wpdb;
		$tables = array( 'event_admins', 'event_scorers', 'event_email_settings', 'status_options', 'certificate_mail', 'certificate_history', 'participant_links', 'participant_requests', 'bikes', 'horses', 'riders', 'rider_bikes', 'events', 'registrations', 'bonus_points', 'bonus_submissions', 'import_audit' );
		$stamp = gmdate( 'Ymd-His' );
		nocache_headers();
		if ( 'json' === $type ) {
			$data = array( 'generated_at' => gmdate( 'c' ), 'site' => site_url(), 'plugin_version' => RQE_VERSION, 'tables' => array() );
			foreach ( $tables as $name ) {
				$data['tables'][ $name ] = $wpdb->get_results( 'SELECT * FROM ' . RQE::table( $name ), ARRAY_A );
			}
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="rqe-export-' . $stamp . '.json"' );
			echo wp_json_encode( $data, JSON_PRETTY_PRINT );
			exit;
		}
		header( 'Content-Type: application/sql; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="rqe-backup-' . $stamp . '.sql"' );
		echo "-- Rally Quest Engine database backup\n";
		echo '-- Generated: ' . esc_sql( gmdate( 'c' ) ) . " UTC\n\n";
		foreach ( $tables as $name ) {
			$table = RQE::table( $name );
			$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $table, ARRAY_N );
			if ( ! $create || ! isset( $create[1] ) ) {
				wp_die( esc_html( 'Unable to read a plugin table definition.' ), '', array( 'response' => 500 ) );
			}
			echo 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . "`;\n" . $create[1] . ";\n";
			foreach ( $wpdb->get_results( 'SELECT * FROM ' . $table, ARRAY_A ) as $row ) {
				$columns = array_map( function ( $column ) {
					return '`' . str_replace( '`', '``', $column ) . '`';
				}, array_keys( $row ) );
				$values = array_map( array( __CLASS__, 'sql_backup_value' ), array_values( $row ) );
				echo 'INSERT INTO `' . esc_sql( $table ) . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ");\n";
			}
			echo "\n";
		}
		exit;
	}

	private static function sql_backup_value( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . str_replace(
			array( '\\', "\0", "\n", "\r", "\x1a", "'" ),
			array( '\\\\', '\\0', '\\n', '\\r', '\\Z', "\\'" ),
			(string) $value
		) . "'";
	}
}
