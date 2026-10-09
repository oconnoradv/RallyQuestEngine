<?php
/** Admin request handling. Every mutation has an explicit authorization boundary. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_mrsa_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_mrsa_diagnostics_download', array( __CLASS__, 'diagnostics_download' ) );
	}

	public static function menu() {
		if ( MRSA::access() ) {
			add_menu_page( 'Motorcycle Rally Scoring Application', 'Rally Scoring', 'read', 'mrsa', array( 'MRSA_Views', 'render' ), 'dashicons-location-alt', 58 );
			add_submenu_page( null, 'Event Scoring', 'Event Scoring', 'read', 'mrsa-scoring', array( 'MRSA_Views', 'render_scoring' ) );
			add_submenu_page( null, 'Edit Rider', 'Edit Rider', 'read', 'mrsa-rider-edit', array( 'MRSA_Views', 'render_rider_edit' ) );
			add_submenu_page( null, 'Edit Event', 'Edit Event', 'read', 'mrsa-event-edit', array( 'MRSA_Views', 'render_event_edit' ) );
			add_submenu_page( null, 'Event Roster', 'Event Roster', 'read', 'mrsa-event-roster', array( 'MRSA_Views', 'render_event_roster' ) );
			add_submenu_page( null, 'Add Rider to Event', 'Add Rider to Event', 'read', 'mrsa-event-enroll', array( 'MRSA_Views', 'render_event_enroll' ) );
			add_submenu_page( null, 'Rider Overall Event Score Sheet', 'Rider Overall Event Score Sheet', 'read', 'mrsa-rider-score', array( 'MRSA_Views', 'render_rider_score' ) );
			add_submenu_page( null, 'Edit Bonus Point', 'Edit Bonus Point', 'read', 'mrsa-bonus-edit', array( 'MRSA_Views', 'render_bonus_edit' ) );
		}
	}

	public static function assets( $hook ) {
		if ( ! MRSA::access() ) {
			return;
		}
		wp_add_inline_style( 'dashicons', '#adminmenu .toplevel_page_mrsa .wp-menu-image::before,#adminmenu .toplevel_page_mrsa:hover .wp-menu-image::before,#adminmenu .toplevel_page_mrsa.current .wp-menu-image::before{color:#ff0800}' );
		if ( ! in_array( $hook, array( 'toplevel_page_mrsa', 'admin_page_mrsa-scoring', 'admin_page_mrsa-rider-edit', 'admin_page_mrsa-event-edit', 'admin_page_mrsa-event-roster', 'admin_page_mrsa-event-enroll', 'admin_page_mrsa-rider-score', 'admin_page_mrsa-bonus-edit' ), true ) ) {
			return;
		}
		$asset_version = MRSA_VERSION . '.' . MRSA_DB_VERSION;
		wp_enqueue_style( 'mrsa-admin', plugins_url( 'assets/admin.css', MRSA_FILE ), array(), $asset_version );
		wp_enqueue_script( 'mrsa-admin', plugins_url( 'assets/admin.js', MRSA_FILE ), array(), $asset_version, true );
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
		if ( in_array( $tab, array( 'rider-edit', 'event-edit', 'event-roster', 'event-enroll', 'rider-score', 'bonus-edit' ), true ) ) {
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
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! MRSA::access() ) {
			wp_die( esc_html( 'Unauthorized request.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mrsa_save' );
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
				case 'event_admin':
				case 'remove_event_admin':
					self::require_global();
					self::event_admin( 'remove_event_admin' === $op );
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					$tab = 'event-edit';
					break;
				case 'bike':
					self::require_global();
					self::bike();
					$tab = 'bikes';
					break;
				case 'delete_bike':
					self::require_global();
					$message = self::delete_bikes( false );
					$tab = 'bikes';
					break;
				case 'delete_bikes':
					self::require_global();
					$message = self::delete_bikes( true );
					$tab = 'bikes';
					break;
				case 'source_import':
					self::require_global();
					if ( '1' !== self::posted( 'source_consent' ) ) {
						throw new RuntimeException( 'Confirm that you understand the source and review requirements before importing the factual catalog data.' );
					}
					$message = MRSA_Importer::import( self::posted( 'import_make' ) );
					$tab = 'bikes';
					break;
				case 'rider':
					self::require_global();
					$is_new = 0 === self::integer( self::posted( 'id', '0' ) );
					$extra['rider_id'] = self::rider();
					$enroll_event_id = self::posted( 'enroll_event_id' );
					if ( $is_new && '' !== $enroll_event_id ) {
						$enroll_event_id = self::integer( $enroll_event_id, 1 );
						self::require_event( $enroll_event_id );
						self::enroll_new_rider( $enroll_event_id, $extra['rider_id'] );
						$message = 'Rider saved and added to the event with the next available bib.';
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
					self::require_global();
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
					$check = MRSA_File_Scan::check_connection();
					$check_id = wp_generate_password( 24, false, false );
					if ( ! set_transient( 'mrsa_clamav_check_' . get_current_user_id() . '_' . $check_id, array_merge( $check, array( 'event_id' => $extra['event_id'] ) ), 15 * MINUTE_IN_SECONDS ) ) {
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
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					if ( 'bonus_import' === $op ) {
						$result = MRSA_Bonus::import( $extra['event_id'] );
					} else {
						$pending = MRSA_Bonus::pending( $extra['event_id'], self::posted( 'pending_token' ) );
						$bonus_step = $pending['import_step'] ?? $bonus_step;
						if ( 'bonus_import_approve' === $op && '1' !== self::posted( 'scan_approval' ) ) {
							throw new RuntimeException( 'Review the scan results and explicitly approve the import.' );
						}
						if ( 'bonus_import_bypass' === $op && '1' !== self::posted( 'bypass_confirmation' ) ) {
							throw new RuntimeException( 'Confirm the risk of importing without an antivirus security scan.' );
						}
						$result = MRSA_Bonus::approve( $extra['event_id'], self::posted( 'pending_token' ), 'bonus_import_cancel' === $op, 'bonus_import_bypass' === $op );
					}
					if ( isset( $result['pending_token'] ) ) {
						$extra['bonus_pending'] = $result['pending_token'];
					}
					$message = $result['message'];
					if ( $result['errors'] ) {
						$report_id = wp_generate_password( 24, false, false );
						if ( ! set_transient( 'mrsa_bonus_report_' . get_current_user_id() . '_' . $report_id, array( 'event_id' => $extra['event_id'], 'step' => $bonus_step, 'errors' => $result['errors'] ), 15 * MINUTE_IN_SECONDS ) ) {
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
					if ( 'bonus_import_disable_scan' === $op ) {
						MRSA_Bonus::enable_bypass( $extra['event_id'] );
						$message = 'WARNING: ClamAV override enabled for your account. Imports may proceed without antivirus scanning only when ClamAV is unavailable. Your consent was audited.';
					} else {
						MRSA_Bonus::revoke_bypass( $extra['event_id'] );
						$message = 'ClamAV scanning override removed for your account.';
					}
					$tab = 'scoring';
					$extra['score_tab'] = 'library';
					break;
				case 'bonus_point':
					$extra['event_id'] = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $extra['event_id'] );
					MRSA_Bonus::save( $extra['event_id'], self::text( self::posted( 'bonus_id' ), 64, true ) );
					$tab = 'scoring';
					$extra['score_tab'] = 'library';
					break;
				case 'diagnostics_delete_all':
				case 'diagnostics_delete_bikes':
				case 'diagnostics_delete_roster':
				case 'diagnostics_delete_rider':
				case 'diagnostics_delete_bonus_points':
				case 'diagnostics_delete_audit':
					self::require_global();
					self::verify_diagnostics( $op );
					$message = self::diagnostics_delete( $op );
					$tab = 'diagnostics';
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
			if ( null !== $bonus_step && ! empty( $extra['event_id'] ) && MRSA::event_access( $extra['event_id'] ) && MRSA::row( 'events', $extra['event_id'] ) ) {
				self::bonus_redirect( array( 'event_id' => $extra['event_id'] ), $bonus_step, $e->getMessage(), 'error' );
			}
			wp_die( esc_html( $e->getMessage() ), esc_html( 'Rally update not saved' ), array( 'response' => 400, 'back_link' => true ) );
		}
	}

	private static function bonus_redirect( $extra, $step, $message, $severity ) {
		$id = wp_generate_password( 24, false, false );
		if ( ! set_transient( 'mrsa_bonus_feedback_' . get_current_user_id() . '_' . $id, array(
			'event_id' => $extra['event_id'], 'step' => $step, 'message' => $message, 'severity' => $severity,
		), 15 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html( $message . ' The section feedback could not be stored.' ), '', array( 'response' => 500, 'back_link' => true ) );
		}
		unset( $extra['notice'] );
		$extra['score_tab'] = 'library';
		$extra['bonus_feedback'] = $id;
		wp_safe_redirect( self::url( 'scoring', $extra ) . '#mrsa-import-feedback' );
		exit;
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
		$data = array( 'make' => self::text( self::posted( 'make' ), 80, true ), 'model' => self::text( self::posted( 'model' ), 120, true ), 'year' => 0 );
		if ( ! $id ) {
			$data['source'] = 'Manual entry';
		}
		self::checked( $id ? $wpdb->update( MRSA::table( 'bikes' ), $data, array( 'id' => $id ) ) : $wpdb->insert( MRSA::table( 'bikes' ), $data ) );
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
		MRSA::begin_transaction();
		try {
			foreach ( $ids as $id ) {
				$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'bikes' ) . ' WHERE id=%d FOR UPDATE', $id ) );
				if ( ! $locked ) {
					throw new RuntimeException( 'A selected motorcycle no longer exists or could not be locked.' );
				}
				$used = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'rider_bikes' ) . ' WHERE bike_id=%d LIMIT 1', $id ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Unable to check motorcycle assignments. No catalog records were deleted.' );
				}
				if ( $used ) {
					throw new RuntimeException( 'A selected motorcycle is assigned to a rider. No catalog records were deleted.' );
				}
			}
			foreach ( $ids as $id ) {
				self::checked( $wpdb->delete( MRSA::table( 'bikes' ), array( 'id' => $id ) ) );
			}
			MRSA::commit_transaction();
		} catch ( RuntimeException $e ) {
			MRSA::rollback_transaction();
			throw $e;
		}
		return count( $ids ) . ' motorcycle catalog record(s) deleted.';
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

	private static function roster_import( $event_id ) {
		$file = $_FILES['roster_file'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || ! is_scalar( $file['error'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new RuntimeException( 'Choose a successfully uploaded roster CSV or XLSX file.' );
		}
		$size = filesize( $file['tmp_name'] );
		if ( false === $size || $size < 1 || $size > MRSA_Bonus::MAX_SHEET_SIZE ) {
			throw new RuntimeException( 'Roster files must be nonempty and no larger than 10 MB.' );
		}
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
			throw new RuntimeException( 'Choose a CSV or XLSX file.' );
		}
		MRSA_File_Scan::reset();
		MRSA_File_Scan::scan( $file['tmp_name'], $file['name'] );
		list( $header, $rows ) = MRSA_Bonus::read_spreadsheet( $file['tmp_name'], $extension );
		return self::import_roster_rows( $event_id, $header, $rows );
	}

	private static function import_roster_rows( $event_id, $header, $rows ) {
		global $wpdb;
		$columns = array();
		$allowed = array( 'rider id', 'email', 'bib id', 'status', 'pillion' );
		foreach ( $header as $index => $value ) {
			$name = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value ) ) );
			if ( ! in_array( $name, $allowed, true ) || isset( $columns[ $name ] ) ) {
				throw new RuntimeException( 'Use the sample columns only, without duplicate headers: Rider ID, Email, Bib ID, Status, Pillion.' );
			}
			$columns[ $name ] = $index;
		}
		if ( ! isset( $columns['rider id'] ) && ! isset( $columns['email'] ) ) {
			throw new RuntimeException( 'The roster must have a Rider ID or Email column.' );
		}
		if ( count( $rows ) > MRSA_Bonus::MAX_RECORDS ) {
			throw new RuntimeException( 'Import no more than 5,000 roster rows at once.' );
		}
		MRSA::begin_transaction();
		try {
			$event = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MRSA::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ), ARRAY_A );
			if ( ! $event ) {
				throw new RuntimeException( 'The selected event no longer exists.' );
			}
			$existing = $wpdb->get_results( $wpdb->prepare( 'SELECT rider_id,bib FROM ' . MRSA::table( 'registrations' ) . ' WHERE event_id=%d', $event_id ), ARRAY_A );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'The existing roster could not be read. No riders were imported.' );
			}
			$used = array_fill_keys( array_column( $existing, 'bib' ), true );
			$enrolled = array_fill_keys( array_column( $existing, 'rider_id' ), true );
			$records = array();
			foreach ( $rows as $line => $row ) {
				if ( ! array_filter( $row, function ( $value ) { return '' !== trim( (string) $value ); } ) ) {
					continue;
				}
				try {
					foreach ( $row as $index => $value ) {
						if ( ! array_key_exists( $index, $header ) && '' !== trim( (string) $value ) ) {
							throw new RuntimeException( 'Unexpected cells outside the header columns.' );
						}
						if ( preg_match( '/^\s*[=+@]/', (string) $value ) || preg_match( '/<\?(?:php|=)|<script\b/i', (string) $value ) ) {
							throw new RuntimeException( 'Formulas or executable cell content are not allowed.' );
						}
					}
					$get = function ( $name ) use ( $columns, $row ) { return isset( $columns[ $name ] ) ? trim( (string) ( $row[ $columns[ $name ] ] ?? '' ) ) : ''; };
					$id = $get( 'rider id' );
					$email = $get( 'email' );
					if ( '' === $id && '' === $email ) {
						throw new RuntimeException( 'Supply a Rider ID or Email.' );
					}
					if ( '' !== $email ) { $email = self::email( $email ); }
					$rider = '' !== $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MRSA::table( 'riders' ) . ' WHERE id=%d FOR UPDATE', self::integer( $id, 1 ) ), ARRAY_A ) :
						$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MRSA::table( 'riders' ) . ' WHERE email=%s FOR UPDATE', $email ), ARRAY_A );
					if ( $wpdb->last_error || ! $rider || preg_match( '/^erased-\d+@example\.invalid$/', $rider['email'] ) ) {
						throw new RuntimeException( 'No eligible existing rider matches this identifier.' );
					}
					if ( '' !== $email && 0 !== strcasecmp( $email, $rider['email'] ) ) {
						throw new RuntimeException( 'Rider ID and Email identify different riders.' );
					}
					if ( isset( $enrolled[ $rider['id'] ] ) ) {
						throw new RuntimeException( 'Rider is already enrolled or repeated in this file.' );
					}
					$enrolled[ $rider['id'] ] = true;
					$bib = '' === $get( 'bib id' ) ? null : self::integer( $get( 'bib id' ), 1, (int) $event['max_bib'] );
					if ( null !== $bib ) {
						if ( isset( $used[ $bib ] ) ) { throw new RuntimeException( 'Bib ID is already assigned or repeated in this file.' ); }
						$used[ $bib ] = true;
					}
					$status = $get( 'status' ) ?: 'Initial Registration';
					if ( ! in_array( $status, array( 'Initial Registration', 'Paid', 'Fully Registered', 'Cancelled' ), true ) ) {
						throw new RuntimeException( 'Unsupported registration Status.' );
					}
					$records[] = array( 'event_id' => $event_id, 'rider_id' => (int) $rider['id'], 'bib' => $bib, 'registration_status' => $status, 'pillion' => self::text( $get( 'pillion' ), 160 ) );
				} catch ( RuntimeException $exception ) {
					throw new RuntimeException( 'Roster row ' . ( $line + 2 ) . ': ' . $exception->getMessage() . ' No riders were imported.' );
				}
			}
			if ( ! $records ) { throw new RuntimeException( 'The roster file contains no riders.' ); }
			$next = 1;
			foreach ( $records as $record ) {
				if ( null === $record['bib'] ) {
					while ( isset( $used[ $next ] ) ) { ++$next; }
					if ( $next > (int) $event['max_bib'] ) { throw new RuntimeException( 'The event has insufficient available bibs. No riders were imported.' ); }
					$record['bib'] = $next;
					$used[ $next ] = true;
				}
				self::checked( $wpdb->insert( MRSA::table( 'registrations' ), $record ) );
			}
			MRSA::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! MRSA::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Database rollback failed; inspect the roster before retrying.' );
			}
			throw $exception;
		}
		return 'Imported ' . count( $records ) . ' riders into the selected event roster.';
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

	private static function enroll_new_rider( $event_id, $rider_id ) {
		global $wpdb;
		MRSA::begin_transaction();
		try {
			$event = $wpdb->get_row( $wpdb->prepare( 'SELECT id,max_bib FROM ' . MRSA::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ), ARRAY_A );
			if ( ! $event ) {
				throw new RuntimeException( 'The event no longer exists. The rider was saved but could not be enrolled.' );
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MRSA::table( 'registrations' ) . ' WHERE event_id=%d AND rider_id=%d', $event_id, $rider_id ) );
			if ( $exists ) {
				throw new RuntimeException( 'The rider was saved and is already registered for this event.' );
			}
			$used = array_fill_keys( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT bib FROM ' . MRSA::table( 'registrations' ) . ' WHERE event_id=%d', $event_id ) ) ), true );
			$bib = 0;
			for ( $candidate = 1; $candidate <= (int) $event['max_bib']; ++$candidate ) {
				if ( ! isset( $used[ $candidate ] ) ) {
					$bib = $candidate;
					break;
				}
			}
			if ( ! $bib ) {
				throw new RuntimeException( 'The rider was saved, but every event bib is assigned. Increase the maximum bib before enrolling the rider.' );
			}
			self::checked( $wpdb->insert( MRSA::table( 'registrations' ), array( 'event_id' => $event_id, 'rider_id' => $rider_id, 'bib' => $bib, 'pillion' => '' ) ) );
			MRSA::commit_transaction();
		} catch ( RuntimeException $e ) {
			MRSA::rollback_transaction();
			throw $e;
		}
	}

	private static function registration_status( $event_id ) {
		global $wpdb;
		$id = self::integer( self::posted( 'id' ), 1 );
		$row = MRSA::row( 'registrations', $id );
		if ( ! $row || (int) $row['event_id'] !== $event_id ) {
			throw new RuntimeException( 'Registration not found in the selected event.' );
		}
		$status = self::text( self::posted( 'status' ), 20, true );
		$allowed = array( 'Automatic', 'Registered', 'Preliminary Finisher', 'Finisher', 'DNF', 'DNS' );
		if ( ! in_array( $status, $allowed, true ) ) {
			throw new RuntimeException( 'Select a supported rider status.' );
		}
		$data = 'Automatic' === $status ? array( 'status' => 'Registered', 'status_manual' => 0 ) : array( 'status' => $status, 'status_manual' => 1 );
		self::checked( $wpdb->update( MRSA::table( 'registrations' ), $data, array( 'id' => $id, 'event_id' => $event_id ) ) );
	}

	private static function registration_lifecycle( $event_id ) {
		global $wpdb;
		$id = self::integer( self::posted( 'id' ), 1 );
		$row = MRSA::row( 'registrations', $id );
		if ( ! $row || (int) $row['event_id'] !== $event_id ) {
			throw new RuntimeException( 'Registration not found in the selected event.' );
		}
		$status = self::text( self::posted( 'registration_status' ), 20, true );
		if ( ! in_array( $status, array( 'Initial Registration', 'Paid', 'Fully Registered', 'Cancelled' ), true ) ) {
			throw new RuntimeException( 'Select a supported registration status.' );
		}
		self::checked( $wpdb->update( MRSA::table( 'registrations' ), array( 'registration_status' => $status ), array( 'id' => $id, 'event_id' => $event_id ) ) );
	}

	public static function diagnostics_hash( $operation, $code ) {
		return wp_hash( get_current_user_id() . '|' . $operation . '|' . $code );
	}

	private static function verify_diagnostics( $operation ) {
		$code = self::text( self::posted( 'verification_code' ), 32, true );
		$expected = self::text( self::posted( 'verification_hash' ), 100, true );
		if ( ! hash_equals( $expected, self::diagnostics_hash( $operation, $code ) ) ) {
			throw new RuntimeException( 'The randomized verification string did not match. No data was deleted.' );
		}
	}

	private static function diagnostics_delete( $operation ) {
		global $wpdb;
		$staged_gallery = '';
		$gallery_event_id = 0;
		$all_galleries = false;
		$event_id = 0;
		$audit_detail = '';
		MRSA::begin_transaction();
		try {
			switch ( $operation ) {
				case 'diagnostics_delete_all':
					$staged_gallery = MRSA_Bonus::stage_all_storage_removal();
					$all_galleries = true;
					self::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'bonus_points' ) ) );
					foreach ( array( 'registrations', 'rider_bikes', 'event_admins', 'events', 'riders', 'bikes', 'global_admins' ) as $table ) {
						self::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( $table ) ) );
					}
					$message = 'All application data was deleted. Audit history and ClamAV overrides were retained.';
					break;
				case 'diagnostics_delete_bikes':
					self::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'rider_bikes' ) ) );
					self::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'bikes' ) ) );
					$message = 'All motorcycle catalog and rider-motorcycle data was deleted.';
					break;
				case 'diagnostics_delete_roster':
					$event_id = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $event_id );
					self::checked( $wpdb->delete( MRSA::table( 'registrations' ), array( 'event_id' => $event_id ) ) );
					$message = 'The selected event roster was deleted.';
					break;
				case 'diagnostics_delete_bonus_points':
					$event_id = self::integer( self::posted( 'event_id' ), 1 );
					self::require_event( $event_id );
					$staged_gallery = MRSA_Bonus::stage_event_storage_removal( $event_id );
					$gallery_event_id = $event_id;
					self::checked( $wpdb->delete( MRSA::table( 'bonus_points' ), array( 'event_id' => $event_id ) ) );
					$message = 'All bonus listings and gallery images for the selected event were deleted.';
					break;
				case 'diagnostics_delete_rider':
					$rider_id = self::integer( self::posted( 'rider_id' ), 1 );
					if ( ! MRSA::row( 'riders', $rider_id ) ) {
						throw new RuntimeException( 'The selected rider no longer exists.' );
					}
					self::checked( $wpdb->delete( MRSA::table( 'registrations' ), array( 'rider_id' => $rider_id ) ) );
					self::checked( $wpdb->delete( MRSA::table( 'rider_bikes' ), array( 'rider_id' => $rider_id ) ) );
					self::checked( $wpdb->delete( MRSA::table( 'riders' ), array( 'id' => $rider_id ) ) );
					$message = 'The selected rider and associated plugin data were deleted.';
					$audit_detail = ' Rider ID: ' . $rider_id . '.';
					break;
				case 'diagnostics_delete_audit':
					self::checked( $wpdb->query( 'DELETE FROM ' . MRSA::table( 'import_audit' ) ) );
					$message = 'Virus-scan audit history was deleted and all remembered ClamAV overrides were reset. This deletion is recorded in the new audit log.';
					break;
				default:
					throw new RuntimeException( 'Unsupported diagnostics deletion.' );
			}
			MRSA_Bonus::audit_deletion( $event_id, $operation, $message . $audit_detail );
			MRSA::commit_transaction();
		} catch ( RuntimeException $e ) {
			if ( ! MRSA::rollback_transaction() ) {
				throw new RuntimeException( $e->getMessage() . ' The database rollback failed; gallery recovery was not attempted.' );
			}
			if ( $staged_gallery ) {
				try {
					if ( $all_galleries ) {
						MRSA_Bonus::restore_all_storage( $staged_gallery );
					} else {
						MRSA_Bonus::restore_event_storage( $gallery_event_id, $staged_gallery );
					}
				} catch ( RuntimeException $restore_exception ) {
					throw new RuntimeException( $e->getMessage() . ' Gallery restoration also failed: ' . $restore_exception->getMessage() );
				}
			}
			throw $e;
		}
		if ( $staged_gallery ) {
			try {
				MRSA_Bonus::finish_staged_storage_removal( $staged_gallery );
			} catch ( RuntimeException $cleanup_exception ) {
				throw new RuntimeException( 'The database records were deleted, but staged gallery files could not be fully removed: ' . $cleanup_exception->getMessage() );
			}
		}
		return $message;
	}

	public static function diagnostics_download() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! MRSA::global_access() ) {
			wp_die( esc_html( 'Unauthorized request.' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mrsa_diagnostics_download' );
		$type = self::posted( 'download_type' );
		if ( ! in_array( $type, array( 'json', 'sql' ), true ) ) {
			wp_die( esc_html( 'Unsupported diagnostics download.' ), '', array( 'response' => 400 ) );
		}
		global $wpdb;
		$tables = array( 'global_admins', 'event_admins', 'bikes', 'riders', 'rider_bikes', 'events', 'registrations', 'bonus_points', 'import_audit' );
		$stamp = gmdate( 'Ymd-His' );
		nocache_headers();
		if ( 'json' === $type ) {
			$data = array( 'generated_at' => gmdate( 'c' ), 'site' => site_url(), 'plugin_version' => MRSA_VERSION, 'tables' => array() );
			foreach ( $tables as $name ) {
				$data['tables'][ $name ] = $wpdb->get_results( 'SELECT * FROM ' . MRSA::table( $name ), ARRAY_A );
			}
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="mrsa-export-' . $stamp . '.json"' );
			echo wp_json_encode( $data, JSON_PRETTY_PRINT );
			exit;
		}
		header( 'Content-Type: application/sql; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mrsa-backup-' . $stamp . '.sql"' );
		echo "-- Motorcycle Rally Scoring Application database backup\n";
		echo '-- Generated: ' . esc_sql( gmdate( 'c' ) ) . " UTC\n\n";
		foreach ( $tables as $name ) {
			$table = MRSA::table( $name );
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
