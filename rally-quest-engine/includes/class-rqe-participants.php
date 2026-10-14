<?php
/** Verified account/event enrollment and append-only participant bonus intake. */
defined( 'ABSPATH' ) || exit;

final class RQE_Participants {
	const PENDING_META = 'rqe_pending_participant_account';

	public static function init() {
		add_shortcode( 'rqe_register', array( 'RQE_Participant_Views', 'registration' ) );
		add_shortcode( 'rqe_participant', array( 'RQE_Participant_Views', 'portal' ) );
		add_action( 'template_redirect', array( __CLASS__, 'prepare_page' ) );
		add_action( 'admin_post_nopriv_rqe_participant_register', array( __CLASS__, 'register_handler' ) );
		add_action( 'admin_post_rqe_participant_register', array( __CLASS__, 'register_handler' ) );
		add_action( 'admin_post_nopriv_rqe_participant_verify', array( __CLASS__, 'verify_handler' ) );
		add_action( 'admin_post_rqe_participant_verify', array( __CLASS__, 'verify_handler' ) );
		add_action( 'admin_post_rqe_participant_submit', array( __CLASS__, 'submit_handler' ) );
		add_action( 'admin_post_rqe_participant_image', array( __CLASS__, 'image_handler' ) );
		add_action( 'rqe_participant_rate_expire', array( __CLASS__, 'expire_rate' ), 10, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'deleted_user' ) );
		add_action( 'remove_user_from_blog', array( __CLASS__, 'removed_from_blog' ), 10, 2 );
		add_filter( 'authenticate', array( __CLASS__, 'authenticate' ), 99 );
		add_filter( 'determine_current_user', array( __CLASS__, 'current_user' ), 99 );
		global $current_user;
		if ( $current_user instanceof WP_User && RQE_Participant_Accounts::blocked( $current_user->ID ) ) {
			wp_set_current_user( 0 );
		}
	}

	public static function authenticate( $user ) {
		if ( $user instanceof WP_User && RQE_Participant_Accounts::locked( $user->ID ) ) {
			return new WP_Error( 'rqe_account_locked', 'This participant account was locked after its registration request was declined. Contact the event administrator.' );
		}
		if ( $user instanceof WP_User && get_user_meta( $user->ID, self::PENDING_META, true ) ) {
			return new WP_Error( 'rqe_approval_pending', 'Your participant account requires email verification and event administrator approval before login.' );
		}
		return $user;
	}

	public static function current_user( $id ) {
		return RQE_Participant_Accounts::blocked( $id ) ? 0 : $id;
	}

	/** Private pages must bypass full-page caches; anonymous forms also use a per-browser CSRF token. */
	public static function prepare_page() {
		global $post;
		if ( ! $post || ( ! has_shortcode( $post->post_content, 'rqe_register' ) && ! has_shortcode( $post->post_content, 'rqe_participant' ) ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		if ( ! is_user_logged_in() && empty( $_COOKIE[ self::cookie_name() ] ) ) {
			$token = bin2hex( random_bytes( 32 ) );
			setcookie( self::cookie_name(), $token, array( 'expires' => time() + 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			$_COOKIE[ self::cookie_name() ] = $token;
		}
		wp_enqueue_style( 'rqe-participant', plugins_url( 'assets/participant.css', RQE_FILE ), array(), RQE_VERSION );
	}

	private static function cookie_name() { return 'rqe_registration_' . get_current_blog_id(); }

	/** Used inside destructive-operation/erasure transactions; never deletes WordPress accounts. */
	public static function unlink_rider( $rider_id ) {
		global $wpdb;
		$user_id = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . RQE::table( 'participant_links' ) . ' WHERE rider_id=%d', $rider_id ) );
		if ( $wpdb->last_error ) { throw new RuntimeException( 'Participant identity link could not be read for removal.' ); }
		if ( $user_id ) { RQE_Admin::checked( $wpdb->delete( RQE::table( 'participant_requests' ), array( 'user_id' => $user_id ) ) ); }
		RQE_Admin::checked( $wpdb->delete( RQE::table( 'participant_links' ), array( 'rider_id' => $rider_id ) ) );
	}

	public static function removed_from_blog( $user_id, $blog_id ) {
		if ( (int) $blog_id !== (int) get_current_blog_id() ) {
			switch_to_blog( $blog_id );
			try { self::deleted_user( $user_id ); } finally { restore_current_blog(); }
		} else {
			self::deleted_user( $user_id );
		}
	}

	public static function deleted_user( $user_id ) {
		global $wpdb;
		RQE::begin_transaction();
		try {
			RQE_Admin::checked( $wpdb->delete( RQE::table( 'participant_requests' ), array( 'user_id' => $user_id ) ) );
			RQE_Admin::checked( $wpdb->delete( RQE::table( 'participant_links' ), array( 'user_id' => $user_id ) ) );
			RQE::commit_transaction();
		} catch ( RuntimeException $error ) {
			RQE::rollback_transaction();
			error_log( 'RQE participant access cleanup failed for WordPress account #' . (int) $user_id . ': ' . $error->getMessage() );
			throw $error;
		}
	}

	public static function submission_token( $event_id ) {
		$payload = get_current_user_id() . ':' . (int) $event_id . ':' . time() . ':' . bin2hex( random_bytes( 16 ) );
		return $payload . ':' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}

	private static function submission_key( $event_id, $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^([1-9][0-9]*):([1-9][0-9]*):([0-9]{10}):([a-f0-9]{32}):([a-f0-9]{64})$/D', $token, $parts ) ||
			(int) $parts[1] !== get_current_user_id() || (int) $parts[2] !== (int) $event_id || (int) $parts[3] > time() || (int) $parts[3] < time() - 3600 ||
			! hash_equals( hash_hmac( 'sha256', implode( ':', array_slice( $parts, 1, 4 ) ), wp_salt( 'nonce' ) ), $parts[5] ) ) {
			throw new RuntimeException( 'Submission form expired or belongs to another account/event. Reload your participant page.' );
		}
		return $parts[4];
	}

	public static function guest_token() {
		$token = $_COOKIE[ self::cookie_name() ] ?? '';
		return is_string( $token ) && preg_match( '/^[a-f0-9]{64}$/D', $token ) ? hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) ) : '';
	}

	private static function post_guard( $action, $anonymous = false ) {
		nocache_headers();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ( ! $anonymous && ! is_user_logged_in() ) ) {
			throw new RuntimeException( 'Sign in and submit the form using its submit button.' );
		}
		check_admin_referer( $action );
		if ( ! is_user_logged_in() && ( '' === self::guest_token() || ! hash_equals( self::guest_token(), RQE_Admin::posted( 'guest_token' ) ) ) ) {
			throw new RuntimeException( 'Registration session expired. Reload the registration page with cookies enabled.' );
		}
	}

	public static function event( $id, $registration = false ) {
		$event = RQE::row( 'events', $id );
		if ( ! $event || empty( $event['self_registration_enabled'] ) ) {
			throw new RuntimeException( 'Participant website access is not enabled for this event.' );
		}
		if ( $registration ) {
			if ( ! empty( $event['is_locked'] ) || ! empty( $event['is_archived'] ) ) {
				throw new RuntimeException( 'Registration is closed for this protected event.' );
			}
			$now = current_time( 'mysql' );
			if ( empty( $event['registration_open'] ) || empty( $event['registration_close'] ) || $now < $event['registration_open'] || $now > $event['registration_close'] ) {
				throw new RuntimeException( 'Registration is outside this event\'s registration window.' );
			}
		}
		return $event;
	}

	public static function save_visibility( $id, $enabled ) {
		global $wpdb;
		RQE_Admin::require_event( $id );
		RQE::begin_transaction();
		try {
			RQE_Event_State::writable( $id );
			if ( $enabled ) { RQE_Bonus::participant_storage( $id ); }
			RQE_Admin::checked( $wpdb->update( RQE::table( 'events' ), array( 'self_registration_enabled' => $enabled ? 1 : 0 ), array( 'id' => $id ) ) );
			( new RQE_Import_Audit() )->record( $id, 'participant_visibility', 'Participant website access ' . ( $enabled ? 'enabled.' : 'disabled.' ) );
			RQE::commit_transaction();
		} catch ( RuntimeException $error ) {
			RQE::rollback_transaction();
			throw $error;
		}
	}

	/** Atomic per-identity/time-slot limit; keys contain hashes, not addresses, and expire via cron. */
	private static function throttle( $purpose, $identity, $seconds ) {
		$stamp = (int) floor( time() / $seconds );
		$key = 'rqe_participant_rate_' . hash_hmac( 'sha256', $purpose . '|' . $identity . '|' . $stamp, wp_salt( 'nonce' ) );
		if ( ! add_option( $key, $stamp, '', false ) ) {
			throw new RuntimeException( 'Please wait before repeating this request.' );
		}
		$scheduled = wp_schedule_single_event( time() + $seconds * 2, 'rqe_participant_rate_expire', array( $key, $stamp ), true );
		if ( is_wp_error( $scheduled ) || false === $scheduled ) {
			delete_option( $key );
			throw new RuntimeException( 'Request protection cleanup could not be scheduled. Contact the site administrator.' );
		}
	}

	public static function expire_rate( $key, $stamp ) {
		if ( preg_match( '/^rqe_participant_rate_[a-f0-9]{64}$/D', (string) $key ) && (int) get_option( $key, -1 ) === (int) $stamp ) {
			delete_option( $key );
		}
	}

	public static function register_request( $event_id, array $input ) {
		global $wpdb;
		self::event( $event_id, true );
		$name = RQE_Admin::text( $input['name'] ?? '', 160, true );
		$phone = RQE_Admin::text( $input['phone'] ?? '', 60 );
		$logged_in = is_user_logged_in();
		$user = $logged_in ? wp_get_current_user() : null;
		$email = RQE_Admin::email( $logged_in ? $user->user_email : ( $input['email'] ?? '' ) );
		if ( ! empty( $input['website'] ) || '1' !== ( $input['consent'] ?? '' ) ) {
			throw new RuntimeException( 'Confirm permission to store your registration information.' );
		}
		self::throttle( 'registration-ip', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 60 );
		self::throttle( 'registration-email', strtolower( $email ), 600 );
		$created = false;
		if ( ! $logged_in ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$request = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'participant_requests' ) . ' WHERE event_id=%d AND user_id=%d', $event_id, $user->ID ), ARRAY_A );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Registration requests could not be read.' ); }
				if ( get_user_meta( $user->ID, self::PENDING_META, true ) && $request && 'pending' === $request['status'] ) {
					self::send_verification( $request, $user );
				}
				return 'If you already have an account, sign in to request event access. For a new pending account, check your email for verification instructions.';
			}
			RQE_Participant_Role::ensure();
			$id = wp_insert_user( array(
				'user_login' => 'rqe-' . bin2hex( random_bytes( 10 ) ), 'user_email' => $email, 'display_name' => $name,
				'user_pass' => wp_generate_password( 40, true, true ), 'role' => RQE_Participant_Role::ROLE,
				'meta_input' => array( self::PENDING_META => 1 ),
			) );
			if ( is_wp_error( $id ) ) {
				// Duplicate-account races use the same non-enumerating response.
				if ( in_array( $id->get_error_code(), array( 'existing_user_email', 'existing_user_login' ), true ) ) {
					return 'If you already have an account, sign in to request event access. For a new pending account, check your email for verification instructions.';
				}
				throw new RuntimeException( 'The pending account could not be created. Contact the site administrator.' );
			}
			$user = get_user_by( 'id', $id );
			$created = true;
		}
		RQE::begin_transaction();
		try {
			RQE_Event_State::writable( $event_id );
			self::event( $event_id, true );
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'participant_requests' ) . ' WHERE event_id=%d AND user_id=%d', $event_id, $user->ID ) );
			if ( $wpdb->last_error || $existing ) {
				throw new RuntimeException( 'A registration request already exists for this account and event. Contact the event administrator.' );
			}
			$now = gmdate( 'Y-m-d H:i:s' );
			RQE_Admin::checked( $wpdb->insert( RQE::table( 'participant_requests' ), array(
				'event_id' => $event_id, 'user_id' => $user->ID, 'status' => $logged_in ? 'verified' : 'pending',
				'payload' => wp_json_encode( array( 'name' => $name, 'phone' => $phone, 'email' => $email ) ), 'created_at' => $now, 'updated_at' => $now,
			) ) );
			$id = (int) $wpdb->insert_id;
			$audit = new RQE_Import_Audit();
			$detail = 'Participant account #' . $user->ID . ' requested enrollment.';
			if ( $logged_in ) { $audit->record( $event_id, 'participant_request', $detail ); } else { $audit->record_service( $event_id, 'participant_request', $detail ); }
			RQE::commit_transaction();
		} catch ( RuntimeException $error ) {
			RQE::rollback_transaction();
			if ( $created ) {
				if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
				if ( ! wp_delete_user( $user->ID ) ) {
					throw new RuntimeException( $error->getMessage() . ' Pending account cleanup failed; contact the site administrator.' );
				}
			}
			throw $error;
		}
		if ( $logged_in ) {
			self::notify_admins( $event_id );
			return 'Registration requested. An event administrator must approve your event access.';
		}
		self::send_verification( RQE::row( 'participant_requests', $id ), $user );
		return 'If you already have an account, sign in to request event access. For a new pending account, check your email for verification instructions.';
	}

	private static function send_verification( array $request, $user ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );
		RQE_Admin::checked( $wpdb->update( RQE::table( 'participant_requests' ), array( 'token_hash' => hash( 'sha256', $token ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), array( 'id' => $request['id'], 'status' => 'pending' ) ) );
		$url = add_query_arg( array( 'action' => 'rqe_participant_verify', 'request_id' => $request['id'], 'token' => $token ), admin_url( 'admin-post.php' ) );
		if ( ! wp_mail( $user->user_email, 'Verify your Rally Quest registration', "Verify your email using this single-use link within 24 hours:\n" . $url . "\nAdministrator approval is also required before a new account can log in." ) ) {
			throw new RuntimeException( 'Verification email could not be sent. The account remains pending; retry registration later to resend or contact the administrator.' );
		}
	}

	public static function verify( $id, $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', (string) $token ) ) { throw new RuntimeException( 'Invalid verification link.' ); }
		RQE::begin_transaction();
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'participant_requests' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
			if ( ! $row || $wpdb->last_error || 'pending' !== $row['status'] || empty( $row['expires_at'] ) || $row['expires_at'] < gmdate( 'Y-m-d H:i:s' ) || ! hash_equals( $row['token_hash'], hash( 'sha256', $token ) ) ) {
				throw new RuntimeException( 'Verification link is invalid, expired or already used. Retry registration to resend.' );
			}
			$user = get_user_by( 'id', $row['user_id'] );
			$payload = json_decode( $row['payload'], true );
			if ( ! $user || ! is_array( $payload ) || strtolower( $payload['email'] ?? '' ) !== strtolower( $user->user_email ) ) {
				throw new RuntimeException( 'The account email has changed. Contact the event administrator before verifying.' );
			}
			RQE_Admin::checked( $wpdb->update( RQE::table( 'participant_requests' ), array( 'status' => 'verified', 'token_hash' => '', 'expires_at' => null, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) ) );
			( new RQE_Import_Audit() )->record_service( $row['event_id'], 'participant_verified', 'Email verified for account #' . $row['user_id'] . '.' );
			RQE::commit_transaction();
		} catch ( RuntimeException $error ) {
			RQE::rollback_transaction();
			throw $error;
		}
		self::notify_admins( (int) $row['event_id'] );
	}

	private static function notify_admins( $event_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . RQE::table( 'event_admins' ) . ' WHERE event_id=%d', $event_id ) );
		if ( ! is_array( $ids ) || $wpdb->last_error ) { throw new RuntimeException( 'Registration was saved but administrator notification recipients could not be read.' ); }
		$ids = array_unique( array_merge( $ids, get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ) );
		foreach ( $ids as $id ) {
			$user = get_user_by( 'id', $id );
			if ( $user && ! wp_mail( $user->user_email, 'Rally Quest registration awaiting approval', 'Review participant requests in Event Configuration: ' . RQE_Admin::url( 'event-edit', array( 'event_id' => $event_id ) ) ) ) {
				throw new RuntimeException( 'Registration was saved but an administrator notification failed. Contact the event administrator.' );
			}
		}
	}

	public static function decide( $event_id, $id, $approve ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		RQE::begin_transaction();
		try {
			RQE_Event_State::writable( $event_id );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'participant_requests' ) . ' WHERE id=%d AND event_id=%d FOR UPDATE', $id, $event_id ), ARRAY_A );
			if ( ! $row || $wpdb->last_error || ! in_array( $row['status'], array( 'pending', 'verified' ), true ) ) {
				throw new RuntimeException( 'This request is no longer awaiting a decision.' );
			}
			if ( $approve && 'verified' !== $row['status'] ) { throw new RuntimeException( 'Email verification is required before approval.' ); }
			$user = get_user_by( 'id', $row['user_id'] );
			if ( ! $user ) { throw new RuntimeException( 'The WordPress account no longer exists.' ); }
			if ( $approve ) {
				$event = self::event( $event_id, true );
				$payload = json_decode( $row['payload'], true );
				if ( ! is_array( $payload ) || strtolower( $payload['email'] ?? '' ) !== strtolower( $user->user_email ) ) { throw new RuntimeException( 'Registration details or verified account email changed. Reject this request and contact the site administrator to review account identity.' ); }
				$link = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'participant_links' ) . ' WHERE user_id=%d FOR UPDATE', $user->ID ), ARRAY_A );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Participant identity link could not be read.' ); }
				if ( $link ) {
					$rider_id = (int) $link['rider_id'];
					if ( ! RQE::row( 'riders', $rider_id ) ) { throw new RuntimeException( 'Linked participant no longer exists.' ); }
				} else {
					$rider_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'riders' ) . ' WHERE email=%s FOR UPDATE', $user->user_email ) );
					if ( $wpdb->last_error ) { throw new RuntimeException( 'Participant record could not be read.' ); }
					if ( ! $rider_id ) {
						RQE_Admin::checked( $wpdb->insert( RQE::table( 'riders' ), array( 'name' => RQE_Admin::text( $payload['name'] ?? '', 160, true ), 'email' => RQE_Admin::email( $user->user_email ), 'phone' => RQE_Admin::text( $payload['phone'] ?? '', 60 ), 'insurance' => '', 'other' => '' ) ) );
						$rider_id = (int) $wpdb->insert_id;
					}
					RQE_Admin::checked( $wpdb->insert( RQE::table( 'participant_links' ), array( 'user_id' => $user->ID, 'rider_id' => $rider_id ) ) );
				}
				$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d AND rider_id=%d', $event_id, $rider_id ) );
				if ( $wpdb->last_error ) { throw new RuntimeException( 'Event enrollment could not be read.' ); }
				if ( ! $exists ) {
					$bibs = $wpdb->get_col( $wpdb->prepare( 'SELECT bib FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d', $event_id ) );
					if ( ! is_array( $bibs ) || $wpdb->last_error ) { throw new RuntimeException( 'Event capacity could not be read.' ); }
					$bib = 1;
					while ( in_array( $bib, array_map( 'intval', $bibs ), true ) && $bib <= (int) $event['max_bib'] ) { ++$bib; }
					if ( $bib > (int) $event['max_bib'] ) { throw new RuntimeException( 'No event bib is available. Increase capacity before approving.' ); }
					RQE_Admin::checked( $wpdb->insert( RQE::table( 'registrations' ), array_merge( array( 'event_id' => $event_id, 'rider_id' => $rider_id, 'bib' => $bib, 'pillion' => '' ), RQE_Statuses::registration_data( RQE_Statuses::initial_registration( $event_id ) ) ) ) );
				}
			}
			RQE_Admin::checked( $wpdb->update( RQE::table( 'participant_requests' ), array( 'status' => $approve ? 'approved' : 'rejected', 'token_hash' => '', 'expires_at' => null, 'payload' => '', 'approved_by' => get_current_user_id(), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) ) );
			( new RQE_Import_Audit() )->record( $event_id, $approve ? 'participant_approved' : 'participant_rejected', 'Request #' . $id . '; account #' . $user->ID . '.' );
			RQE::commit_transaction();
		} catch ( RuntimeException $error ) {
			RQE::rollback_transaction();
			throw $error;
		}
		if ( $approve ) {
			RQE_Participant_Accounts::unlock( $user->ID );
		}
		if ( $approve && get_user_meta( $user->ID, self::PENDING_META, true ) ) {
			delete_user_meta( $user->ID, self::PENDING_META );
			if ( get_user_meta( $user->ID, self::PENDING_META, true ) ) { throw new RuntimeException( 'Enrollment approved, but account activation failed. Contact the site administrator.' ); }
			wp_new_user_notification( $user->ID, null, 'user' );
		}
		if ( ! $approve ) {
			RQE_Participant_Accounts::lock( $user );
		}
		if ( ! wp_mail( $user->user_email, 'Rally Quest registration decision', $approve ? 'Your event registration was approved. Sign in using your WordPress account; use the WordPress lost-password page if needed.' : 'Your event registration request was declined. Contact the event administrator.' ) ) {
			throw new RuntimeException( 'The decision was saved but participant notification failed.' );
		}
	}

	/** The WordPress role alone never grants participant access. */
	public static function enrollment( $event_id ) {
		global $wpdb;
		if ( ! is_user_logged_in() || self::current_user( get_current_user_id() ) === 0 ) { throw new RuntimeException( 'Sign in with an approved participant account.' ); }
		self::event( $event_id );
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT reg.* FROM ' . RQE::table( 'participant_links' ) . ' l JOIN ' . RQE::table( 'participant_requests' ) . " p ON p.user_id=l.user_id AND p.status='approved' JOIN " . RQE::table( 'registrations' ) . ' reg ON reg.rider_id=l.rider_id AND reg.event_id=p.event_id WHERE l.user_id=%d AND p.event_id=%d',
			get_current_user_id(), $event_id
		), ARRAY_A );
		if ( ! $row || $wpdb->last_error || 0 === strcasecmp( 'Cancelled', $row['registration_status'] ) ) { throw new RuntimeException( 'Approved enrollment is required for this event.' ); }
		return $row;
	}

	public static function submit( $event_id, $bonus_id, $description, $file, $token ) {
		global $wpdb;
		$registration = self::enrollment( $event_id );
		$key = self::submission_key( $event_id, $token );
		$bonus_id = RQE_Admin::text( $bonus_id, 64, true );
		$description = RQE_Admin::text( $description, 5000 );
		if ( ! is_array( $file ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_string( $file['name'] ?? null ) || ! is_int( $file['error'] ?? null ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) || filesize( $file['tmp_name'] ) > RQE_Bonus::MAX_IMAGE_SIZE ) {
			throw new RuntimeException( 'Upload one valid JPEG, PNG, GIF or WebP proof image up to 10 MB.' );
		}
		self::throttle( 'submission', (string) get_current_user_id(), 10 );
		RQE::begin_transaction();
		$image_file = '';
		try {
			$event = RQE_Event_State::writable( $event_id );
			$registration = self::enrollment( $event_id );
			$now = current_time( 'mysql' );
			if ( empty( $event['start_at'] ) || empty( $event['end_at'] ) || $now < $event['start_at'] || $now > $event['end_at'] ) { throw new RuntimeException( 'Bonus submission is outside the event window.' ); }
			$bonus = $wpdb->get_var( $wpdb->prepare( 'SELECT bonus_id FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', $event_id, $bonus_id ) );
			if ( ! $bonus || $wpdb->last_error ) { throw new RuntimeException( 'Choose a bonus from this event\'s library.' ); }
			$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'bonus_submissions' ) . " WHERE event_id=%d AND rider_id=%d AND bonus_id=%s AND status IN ('pending','accepted') LIMIT 1", $event_id, $registration['rider_id'], $bonus ) );
			if ( $wpdb->last_error || $existing ) { throw new RuntimeException( 'This bonus already has a pending or accepted submission. Contact an event scorer.' ); }
			$duplicate = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'bonus_submissions' ) . " WHERE event_id=%d AND provider='website' AND provider_message_hash=%s", $event_id, hash( 'sha256', $key ) ) );
			if ( $wpdb->last_error || $duplicate ) { throw new RuntimeException( 'This submission form has already been used. View your history before submitting again.' ); }
			$image_file = RQE_Bonus::store_submission_image( $file['tmp_name'], $file['name'], $bonus, $event_id, $key, true );
			$user = wp_get_current_user();
			RQE_Admin::checked( $wpdb->insert( RQE::table( 'bonus_submissions' ), array(
				'event_id' => $event_id, 'registration_id' => $registration['id'], 'rider_id' => $registration['rider_id'], 'bib' => $registration['bib'],
				'bonus_id' => $bonus, 'sender_email' => $user->user_email, 'provider' => 'website', 'provider_message_id' => $key,
				'provider_message_hash' => hash( 'sha256', $key ), 'email_subject' => $bonus, 'submission_description' => $description,
				'submitted_at' => gmdate( 'Y-m-d H:i:s' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'image_file' => $image_file,
				'status' => 'pending', 'rejection_description' => '',
			) ) );
			( new RQE_Import_Audit() )->record( $event_id, 'participant_submission', 'Participant #' . $registration['rider_id'] . ' submitted bonus ' . $bonus . ' for review.' );
			RQE::commit_transaction();
		} catch ( RuntimeException $error ) {
			RQE::rollback_transaction();
			if ( $image_file ) {
				$path = RQE_Bonus::participant_image_path( $event_id, $image_file );
				if ( is_file( $path ) && ! unlink( $path ) ) { throw new RuntimeException( $error->getMessage() . ' Private proof cleanup failed; contact the administrator.' ); }
			}
			throw $error;
		}
	}

	private static function fail( RuntimeException $error ) {
		wp_die( esc_html( $error->getMessage() ), 'Rally Quest request not completed', array( 'response' => 400, 'back_link' => true ) );
	}

	public static function register_handler() {
		try {
			self::post_guard( 'rqe_participant_register', true );
			$message = self::register_request( RQE_Admin::integer( RQE_Admin::posted( 'event_id' ), 1 ), array( 'name' => RQE_Admin::posted( 'name' ), 'email' => RQE_Admin::posted( 'email' ), 'phone' => RQE_Admin::posted( 'phone' ), 'consent' => RQE_Admin::posted( 'consent' ), 'website' => RQE_Admin::posted( 'website' ) ) );
			wp_die( esc_html( $message ), 'Rally Quest registration', array( 'response' => 200, 'back_link' => true ) );
		} catch ( RuntimeException $error ) { self::fail( $error ); }
	}

	public static function verify_handler() {
		try {
			nocache_headers();
			self::verify( RQE_Admin::integer( RQE_Admin::query( 'request_id' ), 1 ), RQE_Admin::query( 'token' ) );
			wp_die( 'Email verified. An event administrator must approve your registration before a new account can log in.', 'Rally Quest verification', array( 'response' => 200 ) );
		} catch ( RuntimeException $error ) { self::fail( $error ); }
	}

	public static function submit_handler() {
		try {
			self::post_guard( 'rqe_participant_submit' );
			self::submit( RQE_Admin::integer( RQE_Admin::posted( 'event_id' ), 1 ), RQE_Admin::posted( 'bonus_id' ), RQE_Admin::posted( 'description' ), $_FILES['proof'] ?? null, RQE_Admin::posted( 'submission_token' ) );
			wp_die( 'Submission saved for scorer review. It cannot be edited; return to your participant page to view its status.', 'Rally Quest submission', array( 'response' => 200, 'back_link' => true ) );
		} catch ( RuntimeException $error ) { self::fail( $error ); }
	}

	public static function image_handler() {
		global $wpdb;
		try {
			nocache_headers();
			$event_id = RQE_Admin::integer( RQE_Admin::query( 'event_id' ), 1 );
			$file = RQE_Admin::query( 'image_file' );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT rider_id FROM ' . RQE::table( 'bonus_submissions' ) . ' WHERE event_id=%d AND image_file=%s', $event_id, $file ), ARRAY_A );
			if ( ! $row || $wpdb->last_error ) { throw new RuntimeException( 'Submission proof not found.' ); }
			if ( ! RQE::scoring_access( $event_id ) ) {
				$registration = self::enrollment( $event_id );
				if ( (int) $registration['rider_id'] !== (int) $row['rider_id'] ) { throw new RuntimeException( 'This proof belongs to another participant.' ); }
			}
			$path = RQE_Bonus::participant_image_path( $event_id, $file );
			if ( ! is_readable( $path ) || ! is_file( $path ) ) { throw new RuntimeException( 'Private proof is unavailable.' ); }
			$types = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' );
			header( 'Content-Type: ' . $types[ pathinfo( $path, PATHINFO_EXTENSION ) ] );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Disposition: inline; filename="proof.' . pathinfo( $path, PATHINFO_EXTENSION ) . '"' );
			readfile( $path );
			exit;
		} catch ( RuntimeException $error ) { self::fail( $error ); }
	}
}
