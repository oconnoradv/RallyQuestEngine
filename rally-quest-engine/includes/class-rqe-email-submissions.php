<?php
/** Event-scoped email ingestion and transactional bonus-submission review. */
defined( 'ABSPATH' ) || exit;

final class RQE_Email_Submissions {
	const MAX_MESSAGES = 25;
	const MAX_ATTACHMENT_SIZE = 10485760;

	public static function schedule() {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && ! as_has_scheduled_action( 'rqe_process_submission_inboxes' ) ) {
			as_schedule_recurring_action( time() + 300, 300, 'rqe_process_submission_inboxes', array(), 'rqe' );
		}
	}

	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|mrsa-event-email', true );
	}

	private static function encrypt( array $settings ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			throw new RuntimeException( 'OpenSSL is required to protect event email credentials.' );
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( wp_json_encode( $settings ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			throw new RuntimeException( 'Event email credentials could not be encrypted.' );
		}
		return base64_encode( $iv . $tag . $ciphertext );
	}

	private static function decrypt( $encrypted ) {
		$payload = base64_decode( (string) $encrypted, true );
		if ( false === $payload || strlen( $payload ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			throw new RuntimeException( 'The saved event email configuration cannot be decrypted.' );
		}
		$json = openssl_decrypt( substr( $payload, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $payload, 0, 12 ), substr( $payload, 12, 16 ) );
		$settings = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $settings ) ) {
			throw new RuntimeException( 'The saved event email configuration is invalid.' );
		}
		return $settings;
	}

	public static function configuration( $event_id, $include_secrets = false ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'event_email_settings' ) . ' WHERE event_id=%d', $event_id ), ARRAY_A );
		if ( ! $row ) {
			return array( 'provider' => 'imap', 'enabled' => 0, 'settings' => array(), 'last_checked_at' => null, 'last_error' => '', 'last_tested_at' => null, 'last_test_error' => '' );
		}
		$settings = self::decrypt( $row['encrypted_settings'] );
		if ( ! $include_secrets ) {
			foreach ( array( 'password', 'client_secret', 'refresh_token' ) as $secret ) {
				unset( $settings[ $secret ] );
			}
		}
		return array(
			'provider' => $row['provider'],
			'enabled' => (int) $row['enabled'],
			'settings' => $settings,
			'last_checked_at' => $row['last_checked_at'],
			'last_error' => $row['last_error'],
			'last_tested_at' => $row['last_tested_at'] ?? null,
			'last_test_error' => $row['last_test_error'] ?? '',
		);
	}

	public static function configuration_status( $event_id ) {
		try {
			$config = self::configuration( $event_id, true );
			$settings = $config['settings'];
			$required = array( 'mailbox' );
			if ( 'imap' === $config['provider'] ) {
				$required = array_merge( $required, array( 'host', 'port', 'encryption', 'username', 'folder', 'password' ) );
			} elseif ( 'gmail' === $config['provider'] ) {
				$required = array_merge( $required, array( 'client_id', 'client_secret', 'refresh_token' ) );
			} elseif ( 'microsoft' === $config['provider'] ) {
				$required = array_merge( $required, array( 'tenant_id', 'client_id', 'client_secret', 'refresh_token', 'folder' ) );
			} else {
				return array( 'configured' => false, 'connected' => false, 'mailbox' => '', 'provider' => '', 'provider_label' => '', 'last_tested_at' => null, 'last_test_error' => 'The saved mailbox provider is unsupported.' );
			}
			$configured = true;
			foreach ( $required as $key ) {
				if ( ! isset( $settings[ $key ] ) || '' === (string) $settings[ $key ] ) {
					$configured = false;
					break;
				}
			}
			$labels = array(
				'imap' => 'IMAP',
				'gmail' => 'Gmail API (OAuth)',
				'microsoft' => 'Microsoft 365 / Graph API (OAuth)',
			);
			return array(
				'configured' => $configured,
				'connected' => $configured && ! empty( $config['last_tested_at'] ) && empty( $config['last_test_error'] ),
				'mailbox' => $configured ? $settings['mailbox'] : '',
				'provider' => $config['provider'],
				'provider_label' => $labels[ $config['provider'] ],
				'last_tested_at' => $config['last_tested_at'],
				'last_test_error' => $config['last_test_error'],
			);
		} catch ( RuntimeException $exception ) {
			return array( 'configured' => false, 'connected' => false, 'mailbox' => '', 'provider' => '', 'provider_label' => '', 'last_tested_at' => null, 'last_test_error' => $exception->getMessage() );
		}
	}

	public static function save_configuration( $event_id, array $input ) {
		global $wpdb;
		$provider = sanitize_key( $input['provider'] ?? '' );
		if ( ! in_array( $provider, array( 'imap', 'gmail', 'microsoft' ), true ) ) {
			throw new RuntimeException( 'Select IMAP, Gmail API, or Microsoft 365.' );
		}
		$existing = self::configuration( $event_id, true );
		$old = $existing['provider'] === $provider ? $existing['settings'] : array();
		$mailbox = sanitize_email( trim( (string) ( $input['mailbox'] ?? '' ) ) );
		if ( ! $mailbox || strlen( $mailbox ) > 254 ) {
			throw new RuntimeException( 'Enter the event submission mailbox email address.' );
		}
		$settings = array( 'mailbox' => $mailbox );
		if ( 'imap' === $provider ) {
			$settings['host'] = self::imap_host( $input['host'] ?? '' );
			$settings['port'] = self::port( $input['port'] ?? '993' );
			$settings['encryption'] = sanitize_key( $input['encryption'] ?? 'ssl' );
			if ( ! in_array( $settings['encryption'], array( 'ssl', 'tls', 'none' ), true ) ) {
				throw new RuntimeException( 'Select a supported IMAP encryption mode.' );
			}
			$settings['username'] = self::plain( $input, 'username', 254, true, 'IMAP username' );
			$settings['folder'] = self::mail_folder( $input['folder'] ?? '' );
			$settings['password'] = self::secret( $input, $old, 'password', 'IMAP password' );
		} elseif ( 'gmail' === $provider ) {
			$settings['client_id'] = self::plain( $input, 'client_id', 255, true, 'OAuth client ID' );
			$settings['client_secret'] = self::secret( $input, $old, 'client_secret', 'OAuth client secret' );
			$settings['refresh_token'] = self::secret( $input, $old, 'refresh_token', 'OAuth refresh token' );
		} else {
			$settings['tenant_id'] = self::plain( $input, 'tenant_id', 100, true, 'Microsoft tenant ID' );
			$settings['client_id'] = self::plain( $input, 'client_id', 255, true, 'OAuth client ID' );
			$settings['client_secret'] = self::secret( $input, $old, 'client_secret', 'OAuth client secret' );
			$settings['refresh_token'] = self::secret( $input, $old, 'refresh_token', 'OAuth refresh token' );
			$settings['folder'] = self::mail_folder( $input['folder'] ?? '' );
		}
		$data = array(
			'provider' => $provider,
			'enabled' => ! empty( $input['enabled'] ) ? 1 : 0,
			'encrypted_settings' => self::encrypt( $settings ),
			'last_error' => '',
			'last_tested_at' => null,
			'last_test_error' => '',
		);
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM ' . RQE::table( 'event_email_settings' ) . ' WHERE event_id=%d', $event_id ) );
		$result = $exists ? $wpdb->update( RQE::table( 'event_email_settings' ), $data, array( 'event_id' => $event_id ) ) : $wpdb->insert( RQE::table( 'event_email_settings' ), array_merge( array( 'event_id' => $event_id ), $data ) );
		if ( false === $result ) {
			throw new RuntimeException( 'The event email configuration could not be saved.' );
		}
		return 'Event submission email configuration saved.';
	}

	public static function test_connection( $event_id ) {
		$config = self::configuration( $event_id, true );
		$status = self::configuration_status( $event_id );
		if ( ! $status['configured'] ) {
			throw new RuntimeException( 'Save a complete event submission inbox configuration before testing the connection.' );
		}
		try {
			if ( 'imap' === $config['provider'] ) {
				$mailbox = self::open_imap( $config['settings'] );
				imap_close( $mailbox );
			} elseif ( 'gmail' === $config['provider'] ) {
				$token = self::gmail_token( $config['settings'] );
				self::http_json( 'https://gmail.googleapis.com/gmail/v1/users/me/profile', array( 'method' => 'GET', 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
			} elseif ( 'microsoft' === $config['provider'] ) {
				$token = self::microsoft_token( $config['settings'] );
				$user = rawurlencode( $config['settings']['mailbox'] );
				$folder = rawurlencode( $config['settings']['folder'] ?: 'inbox' );
				self::http_json( 'https://graph.microsoft.com/v1.0/users/' . $user . '/mailFolders/' . $folder . '?$select=id,displayName', array( 'method' => 'GET', 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
			} else {
				throw new RuntimeException( 'The saved mailbox provider is unsupported.' );
			}
			self::record_connection_test( $event_id, '' );
			return 'Submission inbox connection test passed for ' . $status['mailbox'] . ' using ' . $status['provider_label'] . '.';
		} catch ( RuntimeException $exception ) {
			try {
				self::record_connection_test( $event_id, $exception->getMessage() );
			} catch ( RuntimeException $status_exception ) {
				throw new RuntimeException( $exception->getMessage() . ' The connection-test result also could not be saved: ' . $status_exception->getMessage() );
			}
			throw $exception;
		}
	}

	private static function record_connection_test( $event_id, $error ) {
		global $wpdb;
		if ( false === $wpdb->update( RQE::table( 'event_email_settings' ), array( 'last_tested_at' => gmdate( 'Y-m-d H:i:s' ), 'last_test_error' => substr( (string) $error, 0, 65535 ) ), array( 'event_id' => $event_id ) ) ) {
			throw new RuntimeException( 'The inbox connection-test result could not be saved.' );
		}
	}

	private static function plain( array $input, $key, $limit, $required = false, $label = '' ) {
		$value = trim( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ) );
		if ( $required && '' === $value ) {
			throw new RuntimeException( 'Enter the required ' . ( $label ?: 'email configuration field' ) . '.' );
		}
		if ( strlen( $value ) > $limit ) {
			throw new RuntimeException( 'The ' . ( $label ?: 'email configuration field' ) . ' exceeds its maximum length.' );
		}
		return $value;
	}

	private static function secret( array $input, array $old, $key, $label = '' ) {
		$value = trim( (string) ( $input[ $key ] ?? '' ) );
		if ( '' === $value && ! empty( $old[ $key ] ) ) {
			return $old[ $key ];
		}
		if ( '' === $value ) {
			throw new RuntimeException( 'Enter the required ' . ( $label ?: 'email credential' ) . '.' );
		}
		if ( strlen( $value ) > 4096 ) {
			throw new RuntimeException( 'The ' . ( $label ?: 'email credential' ) . ' exceeds its maximum length.' );
		}
		return $value;
	}

	private static function port( $value ) {
		if ( ! preg_match( '/^\d{1,5}$/D', (string) $value ) || (int) $value < 1 || (int) $value > 65535 ) {
			throw new RuntimeException( 'Enter a valid IMAP port.' );
		}
		return (int) $value;
	}

	private static function imap_host( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( strlen( $value ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value ) ) {
			throw new RuntimeException( 'Enter a valid IMAP hostname without a URL scheme or mailbox flags.' );
		}
		return $value;
	}

	private static function mail_folder( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 120 || preg_match( '/[\x00-\x1F{}]/', $value ) ) {
			throw new RuntimeException( 'Enter a valid mailbox folder without control characters or braces.' );
		}
		return $value;
	}

	public static function process_all() {
		global $wpdb;
		$event_ids = $wpdb->get_col( 'SELECT event_id FROM ' . RQE::table( 'event_email_settings' ) . ' WHERE enabled=1' );
		foreach ( $event_ids as $event_id ) {
			try {
				self::process_event( (int) $event_id );
			} catch ( RuntimeException $exception ) {
				error_log( 'RQE event inbox #' . (int) $event_id . ': ' . $exception->getMessage() );
			}
		}
	}

	public static function process_event( $event_id ) {
		$config = self::configuration( $event_id, true );
		if ( ! $config['enabled'] ) {
			throw new RuntimeException( 'Email submission polling is disabled for this event.' );
		}
		try {
			$provider = $config['provider'];
			if ( ! in_array( $provider, array( 'imap', 'gmail', 'microsoft' ), true ) ) {
				throw new RuntimeException( 'The saved mailbox provider is unsupported.' );
			}
			$messages = 'imap' === $provider ? self::imap_messages( $config['settings'] ) : ( 'gmail' === $provider ? self::gmail_messages( $config['settings'] ) : self::microsoft_messages( $config['settings'] ) );
			$processed = 0;
			foreach ( $messages as $message ) {
				if ( self::process_message( $event_id, $provider, $message ) ) {
					++$processed;
				}
				if ( isset( $message['mark_processed'] ) && is_callable( $message['mark_processed'] ) ) {
					call_user_func( $message['mark_processed'] );
				}
			}
			self::record_check( $event_id, '' );
			return 'Inbox check completed. ' . $processed . ' new message(s) recorded.';
		} catch ( RuntimeException $exception ) {
			try {
				self::record_check( $event_id, $exception->getMessage() );
			} catch ( RuntimeException $status_exception ) {
				throw new RuntimeException( $exception->getMessage() . ' The failure status also could not be saved: ' . $status_exception->getMessage() );
			}
			throw $exception;
		}
	}

	private static function record_check( $event_id, $error ) {
		global $wpdb;
		if ( false === $wpdb->update( RQE::table( 'event_email_settings' ), array( 'last_checked_at' => gmdate( 'Y-m-d H:i:s' ), 'last_error' => substr( (string) $error, 0, 65535 ) ), array( 'event_id' => $event_id ) ) ) {
			throw new RuntimeException( 'The inbox check status could not be saved.' );
		}
	}

	private static function process_message( $event_id, $provider, array $message ) {
		global $wpdb;
		$raw_message_id = sanitize_text_field( (string) ( $message['id'] ?? '' ) );
		if ( '' === $raw_message_id ) {
			throw new RuntimeException( 'The mailbox provider returned a message without a stable identifier.' );
		}
		$message_id = substr( $raw_message_id, 0, 191 );
		$message_hash = hash( 'sha256', $raw_message_id );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'bonus_submissions' ) . ' WHERE event_id=%d AND provider=%s AND provider_message_hash=%s', $event_id, $provider, $message_hash ) );
		if ( $exists ) {
			return false;
		}
		$sender = sanitize_email( (string) ( $message['sender'] ?? '' ) );
		$subject = trim( sanitize_text_field( (string) ( $message['subject'] ?? '' ) ) );
		$description = substr( sanitize_textarea_field( (string) ( $message['description'] ?? '' ) ), 0, 5000 );
		$submitted_at = self::mysql_date( $message['received_at'] ?? '' );
		$base = array(
			'event_id' => $event_id,
			'sender_email' => $sender,
			'provider' => $provider,
			'provider_message_id' => $message_id,
			'provider_message_hash' => $message_hash,
			'email_subject' => substr( $subject, 0, 255 ),
			'submission_description' => $description,
			'submitted_at' => $submitted_at,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( ! $sender ) {
			return self::invalid_message( $base, 'Sender email address is missing or invalid.' );
		}
		$registration = $wpdb->get_row( $wpdb->prepare(
			'SELECT reg.id AS registration_id,reg.rider_id,reg.bib FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=reg.rider_id WHERE reg.event_id=%d AND LOWER(r.email)=LOWER(%s)',
			$event_id,
			$sender
		), ARRAY_A );
		if ( ! $registration ) {
			return self::invalid_message( $base, 'The sender email does not match a participant registered for this event.' );
		}
		$bonus_id = self::subject_bonus_id( $event_id, $subject );
		if ( ! $bonus_id ) {
			return self::invalid_message( array_merge( $base, $registration ), 'The subject must contain exactly one Bonus Point Library ID.' );
		}
		$attachments = array_values( array_filter( $message['attachments'] ?? array(), function ( $attachment ) {
			$mime = strtolower( (string) ( $attachment['mime'] ?? '' ) );
			$extension = strtolower( pathinfo( (string) ( $attachment['name'] ?? '' ), PATHINFO_EXTENSION ) );
			return 0 === strpos( $mime, 'image/' ) || in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true );
		} ) );
		if ( 1 !== count( $attachments ) ) {
			return self::invalid_message( array_merge( $base, $registration, array( 'bonus_id' => $bonus_id ) ), 'The email must contain exactly one JPEG, PNG, GIF, or WebP image attachment.' );
		}
		$attachment = $attachments[0];
		if ( ! empty( $attachment['size'] ) && (int) $attachment['size'] > self::MAX_ATTACHMENT_SIZE ) {
			return self::invalid_message( array_merge( $base, $registration, array( 'bonus_id' => $bonus_id ) ), 'The image attachment exceeds 10 MB.' );
		}
		$data = $attachment['data'] ?? '';
		if ( '' === $data && isset( $attachment['loader'] ) && is_callable( $attachment['loader'] ) ) {
			$data = call_user_func( $attachment['loader'] );
		}
		if ( ! is_string( $data ) || '' === $data || strlen( $data ) > self::MAX_ATTACHMENT_SIZE ) {
			return self::invalid_message( array_merge( $base, $registration, array( 'bonus_id' => $bonus_id ) ), 'The image attachment is empty or exceeds 10 MB.' );
		}
		$temp = wp_tempnam( 'rqe-email-submission' );
		if ( ! $temp || false === file_put_contents( $temp, $data ) ) {
			throw new RuntimeException( 'The email attachment could not be written to private temporary storage.' );
		}
		$image_file = '';
		try {
			$key = bin2hex( random_bytes( 16 ) );
			$image_file = RQE_Bonus::store_submission_image( $temp, $attachment['name'] ?? '', $bonus_id, $event_id, $key );
		} finally {
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
		}
		$row = array_merge( $base, $registration, array( 'bonus_id' => $bonus_id, 'image_file' => $image_file, 'status' => 'pending', 'rejection_description' => '' ) );
		if ( false === $wpdb->insert( RQE::table( 'bonus_submissions' ), $row ) ) {
			self::remove_submission_image( $event_id, $image_file );
			throw new RuntimeException( 'The validated participant submission could not be recorded.' );
		}
		return true;
	}

	private static function invalid_message( array $base, $description ) {
		global $wpdb;
		$row = array_merge( array(
			'registration_id' => 0,
			'rider_id' => 0,
			'bib' => 0,
			'bonus_id' => '',
			'image_file' => '',
		), $base, array(
			'status' => 'invalid',
			'rejection_code' => 'intake_error',
			'rejection_description' => substr( $description, 0, 65535 ),
			'reviewed_at' => gmdate( 'Y-m-d H:i:s' ),
		) );
		if ( false === $wpdb->insert( RQE::table( 'bonus_submissions' ), $row ) ) {
			throw new RuntimeException( 'An invalid inbox message could not be recorded for idempotent processing.' );
		}
		return true;
	}

	private static function subject_bonus_id( $event_id, $subject ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT bonus_id FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d', $event_id ) );
		$matches = array();
		foreach ( $ids as $id ) {
			if ( preg_match( '/(?<![A-Za-z0-9_-])' . preg_quote( $id, '/' ) . '(?![A-Za-z0-9_-])/i', $subject ) ) {
				$matches[ strtolower( $id ) ] = $id;
			}
		}
		return 1 === count( $matches ) ? reset( $matches ) : '';
	}

	private static function mysql_date( $value ) {
		$timestamp = strtotime( (string) $value );
		return false === $timestamp ? gmdate( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	public static function review( $event_id, $submission_id, $decision, $reason_code = '', $description = '' ) {
		global $wpdb;
		if ( ! in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
			throw new RuntimeException( 'Select Accept or Reject.' );
		}
		$allowed_reasons = array( 'wrong_bonus_code', 'wrong_image', 'wrong_location', 'bad_image', 'missing_rally_flag', 'wrong_time_window', 'other' );
		$reason_code = sanitize_key( $reason_code );
		$description = trim( sanitize_textarea_field( $description ) );
		if ( 'rejected' === $decision && ( ! in_array( $reason_code, $allowed_reasons, true ) || ( 'other' === $reason_code && '' === $description ) ) ) {
			throw new RuntimeException( 'Select a rejection reason; describe the reason when Other is selected.' );
		}
		RQE::begin_transaction();
		try {
			$submission = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'bonus_submissions' ) . ' WHERE id=%d AND event_id=%d FOR UPDATE', $submission_id, $event_id ), ARRAY_A );
			if ( ! $submission || 'pending' !== $submission['status'] ) {
				throw new RuntimeException( 'This submission is no longer pending review.' );
			}
			$points = 0;
			if ( 'accepted' === $decision ) {
				$registration = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'registrations' ) . ' WHERE id=%d AND event_id=%d AND rider_id=%d FOR UPDATE', $submission['registration_id'], $event_id, $submission['rider_id'] ) );
				if ( ! $registration ) {
					throw new RuntimeException( 'The matching event registration no longer exists.' );
				}
				$duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . RQE::table( 'bonus_submissions' ) . " WHERE event_id=%d AND rider_id=%d AND bonus_id=%s AND status='accepted' AND id<>%d FOR UPDATE", $event_id, $submission['rider_id'], $submission['bonus_id'], $submission_id ) );
				if ( $duplicate ) {
					throw new RuntimeException( 'This participant already has an accepted submission for that bonus ID.' );
				}
				$points = $wpdb->get_var( $wpdb->prepare( 'SELECT points FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', $event_id, $submission['bonus_id'] ) );
				if ( null === $points ) {
					throw new RuntimeException( 'The matching Bonus Point Library entry no longer exists.' );
				}
				if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . RQE::table( 'registrations' ) . ' SET score=score+%d WHERE id=%d', $points, $registration ) ) ) {
					throw new RuntimeException( 'The participant score could not be updated.' );
				}
			}
			$result = $wpdb->update( RQE::table( 'bonus_submissions' ), array(
				'status' => $decision,
				'points_awarded' => (int) $points,
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => gmdate( 'Y-m-d H:i:s' ),
				'rejection_code' => 'rejected' === $decision ? $reason_code : '',
				'rejection_description' => 'rejected' === $decision ? $description : '',
			), array( 'id' => $submission_id, 'event_id' => $event_id, 'status' => 'pending' ) );
			if ( false === $result || 1 !== $result ) {
				throw new RuntimeException( 'The submission review could not be saved.' );
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			RQE::rollback_transaction();
			throw $exception;
		}
		return 'accepted' === $decision ? 'Submission accepted and ' . (int) $points . ' points added to the participant total.' : 'Submission rejected and retained in review history.';
	}

	public static function image_url( $event_id, $image_file ) {
		return RQE_Bonus::submission_image_url( $event_id, $image_file );
	}

	private static function remove_submission_image( $event_id, $image_file ) {
		if ( ! preg_match( '/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/D', (string) $image_file ) ) {
			return;
		}
		$uploads = wp_upload_dir();
		$path = trailingslashit( $uploads['basedir'] ) . 'rqe-event-gallery/' . absint( $event_id ) . '/submissions/' . $image_file;
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	private static function http_json( $url, array $args ) {
		$args['timeout'] = 20;
		$args['redirection'] = 0;
		$args['limit_response_size'] = 16777216;
		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Mailbox request failed: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			throw new RuntimeException( 'Mailbox provider returned an unsuccessful or invalid response (HTTP ' . $code . ').' );
		}
		return $data;
	}

	private static function oauth_token( $url, array $body ) {
		$data = self::http_json( $url, array( 'method' => 'POST', 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => $body ) );
		if ( empty( $data['access_token'] ) ) {
			throw new RuntimeException( 'The mailbox provider did not return an OAuth access token.' );
		}
		return $data['access_token'];
	}

	private static function gmail_messages( array $settings ) {
		$token = self::gmail_token( $settings );
		$headers = array( 'Authorization' => 'Bearer ' . $token );
		$user = 'me';
		$list = self::http_json( 'https://gmail.googleapis.com/gmail/v1/users/' . $user . '/messages?q=is%3Aunread%20has%3Aattachment&maxResults=' . self::MAX_MESSAGES, array( 'method' => 'GET', 'headers' => $headers ) );
		$messages = array();
		foreach ( $list['messages'] ?? array() as $item ) {
			$id = $item['id'] ?? '';
			if ( ! $id ) {
				continue;
			}
			$message = self::http_json( 'https://gmail.googleapis.com/gmail/v1/users/' . $user . '/messages/' . rawurlencode( $id ) . '?format=full', array( 'method' => 'GET', 'headers' => $headers ) );
			$header_values = array();
			foreach ( $message['payload']['headers'] ?? array() as $header ) {
				$header_values[ strtolower( $header['name'] ?? '' ) ] = $header['value'] ?? '';
			}
			$attachments = array();
			self::gmail_parts( $message['payload'] ?? array(), $attachments, function ( $attachment_id ) use ( $id, $headers, $user ) {
				$data = self::http_json( 'https://gmail.googleapis.com/gmail/v1/users/' . $user . '/messages/' . rawurlencode( $id ) . '/attachments/' . rawurlencode( $attachment_id ), array( 'method' => 'GET', 'headers' => $headers ) );
				return self::base64url_decode( $data['data'] ?? '' );
			} );
			$messages[] = array(
				'id' => $id,
				'sender' => self::header_email( $header_values['from'] ?? '' ),
				'subject' => $header_values['subject'] ?? '',
				'description' => self::gmail_text( $message['payload'] ?? array() ),
				'received_at' => $header_values['date'] ?? '',
				'attachments' => $attachments,
				'mark_processed' => function () use ( $id, $headers, $user ) {
					self::http_json( 'https://gmail.googleapis.com/gmail/v1/users/' . $user . '/messages/' . rawurlencode( $id ) . '/modify', array( 'method' => 'POST', 'headers' => array_merge( $headers, array( 'Content-Type' => 'application/json' ) ), 'body' => wp_json_encode( array( 'removeLabelIds' => array( 'UNREAD' ) ) ) ) );
				},
			);
		}
		return $messages;
	}

	private static function gmail_token( array $settings ) {
		return self::oauth_token( 'https://oauth2.googleapis.com/token', array(
			'client_id' => $settings['client_id'],
			'client_secret' => $settings['client_secret'],
			'refresh_token' => $settings['refresh_token'],
			'grant_type' => 'refresh_token',
		) );
	}

	private static function gmail_parts( array $part, array &$attachments, $fetch ) {
		$file_name = $part['filename'] ?? '';
		if ( $file_name ) {
			$data = $part['body']['data'] ?? '';
			$loader = null;
			if ( ! $data && ! empty( $part['body']['attachmentId'] ) ) {
				$attachment_id = $part['body']['attachmentId'];
				$loader = function () use ( $fetch, $attachment_id ) {
					return call_user_func( $fetch, $attachment_id );
				};
			} else {
				$data = self::base64url_decode( $data );
			}
			$attachments[] = array( 'name' => $file_name, 'mime' => $part['mimeType'] ?? '', 'size' => (int) ( $part['body']['size'] ?? 0 ), 'data' => $data, 'loader' => $loader );
		}
		foreach ( $part['parts'] ?? array() as $child ) {
			self::gmail_parts( $child, $attachments, $fetch );
		}
	}

	private static function gmail_text( array $part ) {
		$mime = strtolower( $part['mimeType'] ?? '' );
		if ( in_array( $mime, array( 'text/plain', 'text/html' ), true ) && ! empty( $part['body']['data'] ) ) {
			$text = self::base64url_decode( $part['body']['data'] );
			return 'text/html' === $mime ? wp_strip_all_tags( $text ) : $text;
		}
		foreach ( $part['parts'] ?? array() as $child ) {
			$text = self::gmail_text( $child );
			if ( '' !== trim( $text ) ) {
				return $text;
			}
		}
		return '';
	}

	private static function base64url_decode( $value ) {
		$value = strtr( (string) $value, '-_', '+/' );
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		$decoded = base64_decode( $value, true );
		return false === $decoded ? '' : $decoded;
	}

	private static function microsoft_messages( array $settings ) {
		$token = self::microsoft_token( $settings );
		$headers = array( 'Authorization' => 'Bearer ' . $token );
		$user = rawurlencode( $settings['mailbox'] );
		$folder = rawurlencode( $settings['folder'] ?: 'inbox' );
		$list = self::http_json( 'https://graph.microsoft.com/v1.0/users/' . $user . '/mailFolders/' . $folder . '/messages?$filter=isRead%20eq%20false&$top=' . self::MAX_MESSAGES . '&$select=id,subject,bodyPreview,from,receivedDateTime,hasAttachments', array( 'method' => 'GET', 'headers' => $headers ) );
		$messages = array();
		foreach ( $list['value'] ?? array() as $message ) {
			$id = $message['id'] ?? '';
			if ( ! $id ) {
				continue;
			}
			$attachment_data = self::http_json( 'https://graph.microsoft.com/v1.0/users/' . $user . '/messages/' . rawurlencode( $id ) . '/attachments?$top=20&$select=id,name,contentType,size', array( 'method' => 'GET', 'headers' => $headers ) );
			$attachments = array();
			foreach ( $attachment_data['value'] ?? array() as $attachment ) {
				if ( empty( $attachment['id'] ) ) {
					continue;
				}
				$attachment_id = $attachment['id'];
				$attachments[] = array(
					'name' => $attachment['name'] ?? '',
					'mime' => $attachment['contentType'] ?? '',
					'size' => (int) ( $attachment['size'] ?? 0 ),
					'data' => '',
					'loader' => function () use ( $headers, $user, $id, $attachment_id ) {
						$data = self::http_json( 'https://graph.microsoft.com/v1.0/users/' . $user . '/messages/' . rawurlencode( $id ) . '/attachments/' . rawurlencode( $attachment_id ), array( 'method' => 'GET', 'headers' => $headers ) );
						$decoded = base64_decode( $data['contentBytes'] ?? '', true );
						return false === $decoded ? '' : $decoded;
					},
				);
			}
			$messages[] = array(
				'id' => $id,
				'sender' => $message['from']['emailAddress']['address'] ?? '',
				'subject' => $message['subject'] ?? '',
				'description' => $message['bodyPreview'] ?? '',
				'received_at' => $message['receivedDateTime'] ?? '',
				'attachments' => $attachments,
				'mark_processed' => function () use ( $id, $headers, $user ) {
					self::http_json( 'https://graph.microsoft.com/v1.0/users/' . $user . '/messages/' . rawurlencode( $id ), array( 'method' => 'PATCH', 'headers' => array_merge( $headers, array( 'Content-Type' => 'application/json' ) ), 'body' => wp_json_encode( array( 'isRead' => true ) ) ) );
				},
			);
		}
		return $messages;
	}

	private static function microsoft_token( array $settings ) {
		$tenant = rawurlencode( $settings['tenant_id'] );
		return self::oauth_token( 'https://login.microsoftonline.com/' . $tenant . '/oauth2/v2.0/token', array(
			'client_id' => $settings['client_id'],
			'client_secret' => $settings['client_secret'],
			'refresh_token' => $settings['refresh_token'],
			'grant_type' => 'refresh_token',
		) );
	}

	private static function imap_messages( array $settings ) {
		$mailbox = self::open_imap( $settings );
		$messages = array();
		$uids = imap_search( $mailbox, 'UNSEEN', SE_UID ) ?: array();
		$uids = array_slice( array_reverse( $uids ), 0, self::MAX_MESSAGES );
		$remaining = count( $uids );
		foreach ( $uids as $uid ) {
			$header = imap_headerinfo( $mailbox, imap_msgno( $mailbox, $uid ) );
			$from = isset( $header->from[0] ) ? $header->from[0] : null;
			$structure = imap_fetchstructure( $mailbox, $uid, FT_UID );
			$attachments = array();
			if ( $structure ) {
				self::imap_parts( $mailbox, $uid, $structure, '', $attachments );
			}
			$messages[] = array(
				'id' => (string) $uid,
				'sender' => $from && isset( $from->mailbox, $from->host ) ? $from->mailbox . '@' . $from->host : '',
				'subject' => isset( $header->subject ) ? imap_utf8( $header->subject ) : '',
				'description' => $structure ? self::imap_text( $mailbox, $uid, $structure, '' ) : '',
				'received_at' => $header->date ?? '',
				'attachments' => $attachments,
				'mark_processed' => function () use ( $mailbox, $uid, &$remaining ) {
					if ( ! imap_setflag_full( $mailbox, (string) $uid, '\\Seen', ST_UID ) ) {
						throw new RuntimeException( 'The processed IMAP message could not be marked as read.' );
					}
					--$remaining;
					if ( 0 === $remaining ) {
						imap_close( $mailbox );
					}
				},
			);
		}
		if ( ! $messages ) {
			imap_close( $mailbox );
		}
		return $messages;
	}

	private static function open_imap( array $settings ) {
		if ( ! function_exists( 'imap_open' ) ) {
			throw new RuntimeException( 'The PHP IMAP extension is required for IMAP inboxes.' );
		}
		$flags = '/imap';
		if ( 'ssl' === $settings['encryption'] ) {
			$flags .= '/ssl';
		} elseif ( 'tls' === $settings['encryption'] ) {
			$flags .= '/tls';
		} else {
			$flags .= '/notls';
		}
		$mailbox_name = '{' . $settings['host'] . ':' . (int) $settings['port'] . $flags . '}' . $settings['folder'];
		$mailbox = @imap_open( $mailbox_name, $settings['username'], $settings['password'], 0, 1 );
		if ( ! $mailbox ) {
			throw new RuntimeException( 'The IMAP mailbox could not be opened: ' . ( imap_last_error() ?: 'unknown error' ) );
		}
		return $mailbox;
	}

	private static function imap_parts( $mailbox, $uid, $part, $prefix, array &$attachments ) {
		$part_number = '' === $prefix ? '1' : $prefix;
		$name = '';
		foreach ( array_merge( $part->parameters ?? array(), $part->dparameters ?? array() ) as $parameter ) {
			$attribute = strtolower( $parameter->attribute ?? '' );
			if ( in_array( $attribute, array( 'name', 'filename' ), true ) ) {
				$name = imap_utf8( $parameter->value ?? '' );
			}
		}
		if ( $name ) {
			$encoding = (int) $part->encoding;
			$attachments[] = array(
				'name' => $name,
				'mime' => self::imap_mime( $part ),
				'size' => (int) ( $part->bytes ?? 0 ),
				'data' => '',
				'loader' => function () use ( $mailbox, $uid, $part_number, $encoding ) {
					return self::decode_imap_part( imap_fetchbody( $mailbox, $uid, $part_number, FT_UID ), $encoding );
				},
			);
		}
		foreach ( $part->parts ?? array() as $index => $child ) {
			$number = '' === $prefix ? (string) ( $index + 1 ) : $prefix . '.' . ( $index + 1 );
			self::imap_parts( $mailbox, $uid, $child, $number, $attachments );
		}
	}

	private static function imap_text( $mailbox, $uid, $part, $prefix ) {
		$part_number = '' === $prefix ? '1' : $prefix;
		if ( 0 === (int) ( $part->type ?? -1 ) && 'PLAIN' === strtoupper( $part->subtype ?? '' ) ) {
			return self::decode_imap_part( imap_fetchbody( $mailbox, $uid, $part_number, FT_UID ), (int) $part->encoding );
		}
		foreach ( $part->parts ?? array() as $index => $child ) {
			$number = '' === $prefix ? (string) ( $index + 1 ) : $prefix . '.' . ( $index + 1 );
			$text = self::imap_text( $mailbox, $uid, $child, $number );
			if ( '' !== trim( $text ) ) {
				return $text;
			}
		}
		return '';
	}

	private static function decode_imap_part( $data, $encoding ) {
		if ( 3 === $encoding ) {
			$data = base64_decode( $data, true );
		} elseif ( 4 === $encoding ) {
			$data = quoted_printable_decode( $data );
		}
		return false === $data ? '' : $data;
	}

	private static function imap_mime( $part ) {
		$primary = array( 'text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'other' );
		return ( $primary[ (int) ( $part->type ?? 7 ) ] ?? 'other' ) . '/' . strtolower( $part->subtype ?? 'octet-stream' );
	}

	private static function header_email( $value ) {
		if ( preg_match( '/<([^>]+)>/', (string) $value, $matches ) ) {
			return sanitize_email( $matches[1] );
		}
		return sanitize_email( trim( (string) $value ) );
	}
}
