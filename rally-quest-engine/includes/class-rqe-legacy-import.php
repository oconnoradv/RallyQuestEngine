<?php
/**
 * One-time import of data saved by the former Motorcycle Rally Scoring
 * Application (MRSA) plugin.
 *
 * Rally Quest Engine stores data in its own RQE_ tables. When a site
 * administrator opens wp-admin, the legacy MRSA_ tables are copied once into
 * empty RQE_ tables inside a single transaction, the event gallery is copied
 * to the new uploads folder and the remaining settings are carried over. The
 * legacy tables and gallery are never modified, so the former plugin can be
 * reactivated if the import must be reviewed.
 */
defined( 'ABSPATH' ) || exit;

final class RQE_Legacy_Import {
	/** Option that records the import outcome so it runs only once. */
	const OPTION = 'rqe_legacy_import';

	/** Oldest MRSA schema whose data matches the current RQE migrations. */
	const MIN_LEGACY_DB_VERSION = 11;

	/** Registers the admin hooks that run the import and report its outcome. */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/** Returns the legacy MRSA table name for an RQE table key. */
	public static function legacy_table( $name ) {
		global $wpdb;
		RQE::table( $name );
		return $wpdb->prefix . 'MRSA_' . $name;
	}

	/** True when the former plugin is still loaded in this request. */
	public static function legacy_plugin_active() {
		return defined( 'MRSA_VERSION' );
	}

	/**
	 * Runs the import once for a site administrator after the former plugin
	 * has been deactivated. Failures roll back and are retried on a later
	 * admin request, because the import is transactional.
	 */
	public static function maybe_run() {
		if ( ! current_user_can( 'manage_options' ) || get_option( self::OPTION ) || self::legacy_plugin_active() ) {
			return;
		}
		try {
			$result = self::run();
		} catch ( Throwable $exception ) {
			set_transient( 'rqe_legacy_import_error_' . get_current_user_id(), $exception->getMessage(), HOUR_IN_SECONDS );
			return;
		}
		update_option( self::OPTION, $result, false );
	}

	/**
	 * Imports legacy rows and settings, returning the outcome to persist.
	 *
	 * @return array{status:string,rows?:int,gallery?:string}
	 */
	public static function run() {
		global $wpdb;
		if ( ! self::table_exists( self::legacy_table( 'events' ) ) ) {
			return array( 'status' => 'none', 'notified' => true );
		}
		$legacy_version = (int) get_option( 'mrsa_db_version', 0 );
		if ( $legacy_version < self::MIN_LEGACY_DB_VERSION ) {
			throw new RuntimeException( 'Legacy Motorcycle Rally Scoring Application data uses an older schema. Reactivate that plugin at version 0.1.20, open its admin page once to upgrade, deactivate it, then reload this page.' );
		}
		$tables = array();
		foreach ( self::tables() as $name ) {
			if ( self::table_exists( self::legacy_table( $name ) ) ) {
				$tables[] = $name;
			}
		}
		foreach ( self::tables() as $name ) {
			if ( null !== $wpdb->get_var( 'SELECT 1 FROM `' . RQE::table( $name ) . '` LIMIT 1' ) ) {
				return array( 'status' => 'skipped', 'notified' => false );
			}
		}
		$gallery = self::copy_gallery();
		$rows    = 0;
		try {
			RQE::begin_transaction();
			foreach ( $tables as $name ) {
				$columns = array_values( array_intersect( self::columns( RQE::table( $name ) ), self::columns( self::legacy_table( $name ) ) ) );
				if ( ! $columns ) {
					continue;
				}
				$list   = '`' . implode( '`,`', $columns ) . '`';
				$copied = $wpdb->query( 'INSERT INTO `' . RQE::table( $name ) . "` ($list) SELECT $list FROM `" . self::legacy_table( $name ) . '`' );
				if ( false === $copied ) {
					throw new RuntimeException( 'Unable to import legacy ' . $name . ' records. No legacy data was imported.' );
				}
				$rows += (int) $copied;
			}
			$meta = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) SELECT user_id, %s, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $wpdb->prefix . 'rqe_selected_event', $wpdb->prefix . 'mrsa_selected_event' ) );
			if ( false === $meta ) {
				throw new RuntimeException( 'Unable to import legacy selected events. No legacy data was imported.' );
			}
			RQE::commit_transaction();
		} catch ( Throwable $exception ) {
			RQE::rollback_transaction();
			if ( 'copied' === $gallery ) {
				self::remove_directory( self::gallery_path( 'rqe' ) );
			}
			throw $exception;
		}
		if ( get_option( 'mrsa_delete_on_uninstall', false ) ) {
			update_option( 'rqe_delete_on_uninstall', 1, false );
		}
		return array( 'status' => 'imported', 'rows' => $rows, 'gallery' => $gallery, 'notified' => false );
	}

	/** Shows import guidance and a one-time outcome notice to site administrators. */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::legacy_plugin_active() && ! get_option( self::OPTION ) ) {
			echo '<div class="notice notice-warning"><p><strong>Rally Quest Engine:</strong> Deactivate Motorcycle Rally Scoring Application, then reload this page to import its events, participants and scoring data. Do not delete the former plugin until the import completes.</p></div>';
			return;
		}
		$error = get_transient( 'rqe_legacy_import_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'rqe_legacy_import_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p><strong>Rally Quest Engine legacy import failed:</strong> ' . esc_html( $error ) . ' The import will retry on the next admin page load.</p></div>';
			return;
		}
		$result = get_option( self::OPTION );
		if ( ! is_array( $result ) || ! empty( $result['notified'] ) ) {
			return;
		}
		if ( 'imported' === $result['status'] ) {
			$gallery = 'failed' === $result['gallery'] ? ' The event gallery could not be copied; copy uploads/mrsa-event-gallery to uploads/rqe-event-gallery manually.' : '';
			echo '<div class="notice notice-success is-dismissible"><p><strong>Rally Quest Engine:</strong> Imported ' . esc_html( number_format_i18n( (int) $result['rows'] ) ) . ' records from Motorcycle Rally Scoring Application. Update embedded results shortcodes to [rqe_results id="MC-7"]; the former [mrsa_results] shortcode keeps working while that plugin is inactive.' . esc_html( $gallery ) . '</p></div>';
		} else {
			echo '<div class="notice notice-info is-dismissible"><p><strong>Rally Quest Engine:</strong> Existing Rally Quest Engine data was found, so legacy Motorcycle Rally Scoring Application data was not imported.</p></div>';
		}
		$result['notified'] = true;
		update_option( self::OPTION, $result, false );
	}

	/** Tables copied from the former plugin. */
	private static function tables() {
		return array( 'event_admins', 'event_scorers', 'event_email_settings', 'status_options', 'bikes', 'horses', 'riders', 'rider_bikes', 'events', 'registrations', 'bonus_points', 'bonus_submissions', 'import_audit' );
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function columns( $table ) {
		global $wpdb;
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" );
		return is_array( $columns ) ? $columns : array();
	}

	private static function gallery_path( $prefix ) {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . $prefix . '-event-gallery';
	}

	/**
	 * Copies the legacy event gallery when the new gallery does not exist yet.
	 *
	 * @return string none|exists|copied|failed
	 */
	private static function copy_gallery() {
		$source = self::gallery_path( 'mrsa' );
		$target = self::gallery_path( 'rqe' );
		if ( ! is_dir( $source ) ) {
			return 'none';
		}
		if ( file_exists( $target ) ) {
			return 'exists';
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		if ( ! wp_mkdir_p( $target ) ) {
			return 'failed';
		}
		foreach ( $items as $item ) {
			if ( $item->isLink() ) {
				continue;
			}
			$path = $target . '/' . str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source ) + 1 ) );
			$ok   = $item->isDir() ? wp_mkdir_p( $path ) : copy( $item->getPathname(), $path );
			if ( ! $ok ) {
				self::remove_directory( $target );
				return 'failed';
			}
		}
		return 'copied';
	}

	private static function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
