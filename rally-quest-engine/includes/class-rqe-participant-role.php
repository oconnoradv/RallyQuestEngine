<?php
/** Capability-free WordPress role for RQE-created participant accounts. */
defined( 'ABSPATH' ) || exit;

final class RQE_Participant_Role {
	const ROLE = 'rqe_participant';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'block_admin' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'application_passwords' ), 10, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	/** Participant pages and submissions use logged-in front-end forms, so the role grants no capabilities, not even read. */
	public static function ensure() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Rally Quest Participant', array() );
		}
	}

	/** Only accounts whose sole role is the participant role are restricted; promoted or existing users keep normal behavior. */
	public static function restricted( $user = null ) {
		$user = null === $user ? wp_get_current_user() : $user;
		return $user instanceof WP_User && $user->ID && array( self::ROLE ) === array_values( (array) $user->roles );
	}

	/** wp-admin is closed to participants; admin-post.php and admin-ajax.php stay available for front-end forms. */
	public static function block_admin() {
		if ( ! self::restricted() || wp_doing_ajax() || in_array( $GLOBALS['pagenow'] ?? '', array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	public static function admin_bar( $show ) {
		return self::restricted() ? false : $show;
	}

	public static function application_passwords( $available, $user ) {
		return self::restricted( $user ) ? false : $available;
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! self::restricted( $user ) ) {
			return $redirect_to;
		}
		return ( '' === $requested || 0 === strpos( $requested, admin_url() ) ) ? home_url( '/' ) : $redirect_to;
	}

	/** Moves accounts this plugin created as Contributors (random rqe- login, participant request, no posts) to the participant role. */
	public static function migrate() {
		global $wpdb;
		self::ensure();
		$ids = $wpdb->get_col( 'SELECT DISTINCT user_id FROM ' . RQE::table( 'participant_requests' ) );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Participant accounts could not be read for the role upgrade.' );
		}
		$moved = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$user = get_user_by( 'id', $id );
			if ( ! $user || array( 'contributor' ) !== array_values( (array) $user->roles ) || ! preg_match( '/^rqe-[0-9a-f]{20}$/D', $user->user_login ) || self::authored( $id ) ) {
				continue;
			}
			$user->set_role( self::ROLE );
			++$moved;
		}
		return $moved;
	}

	/** Any authored content (including drafts) means the account was used as a real Contributor; leave it unchanged. */
	public static function authored( $user_id ) {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author=%d AND post_type <> 'revision'", $user_id ) );
		return null === $count || (int) $count > 0;
	}

	/** Uninstall removes the role only when no user still holds it, so no account is left roleless. */
	public static function remove_if_unused() {
		if ( get_role( self::ROLE ) && array() === get_users( array( 'role' => self::ROLE, 'fields' => 'ID', 'number' => 1 ) ) ) {
			remove_role( self::ROLE );
		}
	}
}
