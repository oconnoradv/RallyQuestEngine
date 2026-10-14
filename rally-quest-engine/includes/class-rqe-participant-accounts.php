<?php
/** Lifecycle of RQE-created participant accounts: rejection locks and Users-screen cleanup of unused accounts. */
defined( 'ABSPATH' ) || exit;

final class RQE_Participant_Accounts {
	const LOCKED_META = 'rqe_participant_account_locked';
	const FILTER = 'rqe_participant_accounts';
	const BULK_ACTION = 'rqe_delete_unused_participants';

	public static function init() {
		add_filter( 'allow_password_reset', array( __CLASS__, 'allow_password_reset' ), 10, 2 );
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'views_users', array( __CLASS__, 'views' ) );
		add_filter( 'users_list_table_query_args', array( __CLASS__, 'filter_query' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column' ), 10, 3 );
		add_filter( 'bulk_actions-users', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function locked( $user_id ) {
		return (bool) get_user_meta( (int) $user_id, self::LOCKED_META, true );
	}

	/** Pending or locked accounts cannot sign in, keep a session, use REST/application passwords or reset a password. */
	public static function blocked( $user_id ) {
		return $user_id && ( get_user_meta( (int) $user_id, RQE_Participants::PENDING_META, true ) || self::locked( $user_id ) );
	}

	/**
	 * Locks an account this plugin created when its registration is rejected before it was ever activated.
	 * Activated accounts and existing WordPress users are never locked by an event decision.
	 */
	public static function lock( $user ) {
		if ( ! $user instanceof WP_User || ! get_user_meta( $user->ID, RQE_Participants::PENDING_META, true ) || ! RQE_Participant_Role::restricted( $user ) ) {
			return false;
		}
		update_user_meta( $user->ID, self::LOCKED_META, gmdate( 'Y-m-d H:i:s' ) );
		if ( ! self::locked( $user->ID ) ) {
			throw new RuntimeException( 'The request was rejected, but the participant account could not be locked. Delete it from Users.' );
		}
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		}
		return true;
	}

	/** An authorized approval for another event reactivates a locked account. */
	public static function unlock( $user_id ) {
		if ( ! self::locked( $user_id ) ) {
			return false;
		}
		delete_user_meta( (int) $user_id, self::LOCKED_META );
		if ( self::locked( $user_id ) ) {
			throw new RuntimeException( 'Enrollment approved, but the locked participant account could not be unlocked. Contact the site administrator.' );
		}
		return true;
	}

	/**
	 * Unused means: locked, created by RQE, participant role only, never linked to a participant,
	 * no pending/verified/approved request and no authored content.
	 */
	public static function unused( $user_id ) {
		global $wpdb;
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user || ! self::locked( $user->ID ) || ! RQE_Participant_Role::restricted( $user ) || ! preg_match( '/^rqe-[0-9a-f]{20}$/D', $user->user_login ) || RQE_Participant_Role::authored( $user->ID ) ) {
			return false;
		}
		$linked = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RQE::table( 'participant_links' ) . ' WHERE user_id=%d', $user->ID ) );
		$active = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RQE::table( 'participant_requests' ) . " WHERE user_id=%d AND status IN ('pending','verified','approved')", $user->ID ) );
		return ! $wpdb->last_error && null !== $linked && null !== $active && 0 === (int) $linked && 0 === (int) $active;
	}

	public static function allow_password_reset( $allowed, $user_id ) {
		return self::blocked( $user_id ) ? false : $allowed;
	}

	private static function locked_ids() {
		return array_map( 'intval', (array) get_users( array( 'meta_key' => self::LOCKED_META, 'fields' => 'ID', 'number' => -1 ) ) );
	}

	private static function delete_cap() {
		return is_multisite() ? 'remove_users' : 'delete_users';
	}

	public static function views( $views ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return $views;
		}
		$count = count( self::locked_ids() );
		$current = 'locked' === sanitize_key( wp_unslash( $_GET[ self::FILTER ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$views[ self::FILTER ] = '<a href="' . esc_url( add_query_arg( self::FILTER, 'locked', admin_url( 'users.php' ) ) ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>Locked RQE participants <span class="count">(' . (int) $count . ')</span></a>';
		return $views;
	}

	/** Only the Users list table is filtered; other user queries on the screen are untouched. */
	public static function filter_query( $args ) {
		if ( 'locked' === sanitize_key( wp_unslash( $_GET[ self::FILTER ] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
			$args['meta_key'] = self::LOCKED_META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded admin list filter.
		}
		return $args;
	}

	public static function columns( $columns ) {
		$columns[ self::FILTER ] = 'Rally Quest Account';
		return $columns;
	}

	public static function column( $output, $column, $user_id ) {
		if ( self::FILTER !== $column ) {
			return $output;
		}
		if ( self::locked( $user_id ) ) {
			return self::unused( $user_id ) ? '<strong>Locked — rejected, unused</strong><br>Safe to bulk delete' : '<strong>Locked — rejected</strong><br>Has another request, link or content; kept';
		}
		if ( get_user_meta( (int) $user_id, RQE_Participants::PENDING_META, true ) ) {
			return 'Pending verification/approval';
		}
		return RQE_Participant_Role::restricted( get_user_by( 'id', (int) $user_id ) ) ? 'Active participant' : '—';
	}

	public static function bulk_actions( $actions ) {
		if ( current_user_can( self::delete_cap() ) ) {
			$actions[ self::BULK_ACTION ] = is_multisite() ? 'Remove unused RQE participant accounts' : 'Delete unused RQE participant accounts';
		}
		return $actions;
	}

	/** Verifies the Users-screen bulk nonce and capability; every account is rechecked so only unused locked accounts are removed. */
	public static function handle_bulk( $redirect, $action, $user_ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect;
		}
		check_admin_referer( 'bulk-users' );
		if ( ! current_user_can( self::delete_cap() ) ) {
			wp_die( 'You are not allowed to remove users.', '', array( 'response' => 403 ) );
		}
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$deleted = array();
		$skipped = 0;
		foreach ( array_map( 'intval', (array) $user_ids ) as $id ) {
			$allowed = is_multisite() ? current_user_can( 'remove_user', $id ) : current_user_can( 'delete_user', $id );
			if ( ! $id || get_current_user_id() === $id || ! $allowed || ! self::unused( $id ) ) {
				++$skipped;
				continue;
			}
			$removed = is_multisite() ? remove_user_from_blog( $id, get_current_blog_id() ) : wp_delete_user( $id );
			if ( $removed && ! is_wp_error( $removed ) ) {
				$deleted[] = $id;
			} else {
				++$skipped;
			}
		}
		if ( $deleted ) {
			( new RQE_Import_Audit() )->record( 0, 'participant_accounts_deleted', 'Removed ' . count( $deleted ) . ' unused locked participant account(s): #' . implode( ', #', $deleted ) . '.' );
		}
		return add_query_arg( array( 'rqe_accounts_deleted' => count( $deleted ), 'rqe_accounts_skipped' => $skipped ), remove_query_arg( array( 'rqe_accounts_deleted', 'rqe_accounts_skipped' ), $redirect ) );
	}

	public static function notice() {
		if ( 'users.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! isset( $_GET['rqe_accounts_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only result counts.
			return;
		}
		$deleted = absint( $_GET['rqe_accounts_deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = absint( $_GET['rqe_accounts_skipped'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-' . ( $skipped ? 'warning' : 'success' ) . ' is-dismissible"><p>' . esc_html( $deleted . ' unused Rally Quest participant account(s) removed.' . ( $skipped ? ' ' . $skipped . ' selected account(s) were kept because they are not locked and unused.' : '' ) ) . '</p></div>';
	}

	/** Schema upgrade: lock never-activated RQE accounts whose every request was already rejected. */
	public static function migrate() {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT user_id FROM ' . RQE::table( 'participant_requests' ) . " GROUP BY user_id HAVING SUM(CASE WHEN status='rejected' THEN 0 ELSE 1 END)=0" );
		if ( ! is_array( $ids ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Rejected participant accounts could not be read for the account-lock upgrade.' );
		}
		$locked = 0;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$user = get_user_by( 'id', $id );
			if ( $user && ! self::locked( $id ) && preg_match( '/^rqe-[0-9a-f]{20}$/D', $user->user_login ) && self::lock( $user ) ) {
				++$locked;
			}
		}
		return $locked;
	}
}
