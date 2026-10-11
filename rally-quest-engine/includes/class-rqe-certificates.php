<?php
/** Private local certificates and explicit, reviewed outbound email batches. */
defined( 'ABSPATH' ) || exit;

final class RQE_Certificates {
	const BATCH_SIZE = 10;

	public static function init() {
		add_action( 'admin_post_rqe_certificates', array( __CLASS__, 'handle' ) );
		add_action( 'admin_init', array( __CLASS__, 'expire' ) );
	}

	public static function expire() {
		global $wpdb;
		if ( ! RQE::site_access() ) {
			return;
		}
		RQE_Admin::checked( $wpdb->query( $wpdb->prepare( 'UPDATE ' . RQE::table( 'certificate_mail' ) . " SET state='stale',snapshot='',subject='',message='',detail='Approval expired. Review again.' WHERE state='pending' AND created_at<%s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ) );
		RQE_Admin::checked( $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RQE::table( 'certificate_mail' ) . ' WHERE updated_at<%s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ) );
	}

	public static function link( $event_id, $mode, $rider_id = 0 ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'rqe_certificates', 'event_id' => $event_id, 'mode' => $mode, 'rider_id' => $rider_id ), admin_url( 'admin-post.php' ) ), 'rqe_certificates' );
	}

	public static function eligible( array $row, array $options ) {
		$excluded = array_map( 'strtolower', array_merge( RQE_Statuses::protected_labels(), array( 'DNF', 'Automatic' ) ) );
		if ( empty( $row['status_manual'] ) || in_array( strtolower( $row['status'] ), $excluded, true ) ) {
			return false;
		}
		foreach ( $options as $option ) {
			if ( $option['label'] === $row['status'] && ! empty( $option['certificate_eligible'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** No public endpoint: even archived-event certificates require current event administration access. */
	public static function snapshots( $event_id, $rider_id = 0 ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		$event = RQE::row( 'events', $event_id );
		if ( ! $event ) {
			throw new RuntimeException( 'Event not found.' );
		}
		$options = RQE_Statuses::options( $event_id, 'event' );
		$rows = ( new RQE_Queries( $wpdb ) )->results( $event_id, $event['min_finisher_points'] );
		$participants = $wpdb->get_results( $wpdb->prepare( 'SELECT r.id,r.email FROM ' . RQE::table( 'riders' ) . ' r JOIN ' . RQE::table( 'registrations' ) . ' reg ON reg.rider_id=r.id WHERE reg.event_id=%d', $event_id ), ARRAY_A );
		if ( ! is_array( $participants ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Certificate recipients could not be read.' );
		}
		$emails = array_column( $participants, 'email', 'id' );
		$snapshots = array();
		foreach ( $rows as $row ) {
			if ( ( $rider_id && (int) $row['rider_id'] !== $rider_id ) || ! self::eligible( $row, $options ) ) {
				continue;
			}
			$snapshots[] = array(
				'event_id' => (int) $event_id,
				'rider_id' => (int) $row['rider_id'],
				'event' => $event['name'],
				'year' => substr( ( $event['start_at'] ?? '' ) ?: $event['event_date'], 0, 4 ),
				'name' => $row['name'],
				'number' => (int) $row['bib'],
				'score' => (int) $row['score'],
				'accepted' => (int) $row['accepted_bonuses'],
				'rejected' => (int) $row['rejected_bonuses'],
				'status' => $row['effective_status'],
				'email' => $emails[ $row['rider_id'] ] ?? '',
			);
		}
		if ( count( $snapshots ) > 5000 ) {
			throw new RuntimeException( 'Generate certificates for no more than 5,000 participants per review.' );
		}
		return $snapshots;
	}

	public static function fingerprint( array $data ) {
		return hash( 'sha256', wp_json_encode( $data ) );
	}

	public static function certificate_html( array $data ) {
		$html = '<article class="certificate"><h1>Finisher Certificate</h1><h2>' . esc_html( $data['event'] ) . '</h2><p>' . esc_html( $data['year'] ) . '</p><h2>' . esc_html( $data['name'] ) . '</h2>';
		foreach ( array( 'number' => 'Participant Number (Bib)', 'score' => 'Total Score', 'accepted' => 'Accepted Bonuses', 'rejected' => 'Rejected Bonuses', 'status' => 'Event Status' ) as $key => $label ) {
			$html .= '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $data[ $key ] ) . '</p>';
		}
		return $html . '</article>';
	}

	public static function document( array $snapshots ) {
		$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Finisher Certificates</title><style>@page{size:A4 landscape;margin:15mm}body{font-family:"DejaVu Sans",sans-serif;color:#172b4d}.certificate{border:4px double #365d78;padding:12mm;text-align:center;page-break-after:always}.certificate:last-child{page-break-after:auto}h1{font-size:28pt}h2{font-size:20pt}p{font-size:12pt}@media print{.controls{display:none}}.controls{margin:16px}</style></head><body>';
		foreach ( $snapshots as $data ) {
			$html .= self::certificate_html( $data );
		}
		return $html . '</body></html>';
	}

	/** Render only fixed local HTML; no remote resources, embedded PHP or JavaScript are allowed. */
	public static function pdf( array $snapshots ) {
		if ( ! extension_loaded( 'dom' ) || ! extension_loaded( 'mbstring' ) ) {
			throw new RuntimeException( 'Certificate PDFs require the PHP DOM and mbstring extensions. Ask the site host to enable them, or use browser Print / Save as PDF.' );
		}
		if ( count( $snapshots ) > 100 ) {
			throw new RuntimeException( 'For more than 100 certificates, use Print All / Save as PDF in your browser instead of a server PDF download.' );
		}
		require_once dirname( __DIR__ ) . '/vendor/autoload.php';
		$directory = RQE_Bonus::create_stage_directory();
		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isPhpEnabled', false );
			$options->set( 'isJavascriptEnabled', false );
			$options->set( 'tempDir', $directory );
			$options->set( 'fontCache', $directory );
			$options->set( 'chroot', dirname( __DIR__ ) . '/vendor/dompdf/dompdf' );
			$pdf = new \Dompdf\Dompdf( $options );
			$pdf->loadHtml( self::document( $snapshots ), 'UTF-8' );
			$pdf->setPaper( 'A4', 'landscape' );
			$pdf->render();
			return $pdf->output();
		} catch ( \Dompdf\Exception $exception ) {
			error_log( 'RQE certificate PDF: ' . $exception->getMessage() );
			throw new RuntimeException( 'Certificate PDF generation failed. Check PHP logs before reviewing and retrying.' );
		} finally {
			( new RQE_Gallery_Filesystem() )->remove_directory( $directory );
		}
	}

	public static function review( $event_id, $rider_id = 0 ) {
		$rows = self::snapshots( $event_id, $rider_id );
		if ( ! $rows ) {
			throw new RuntimeException( 'No manually finalized, certificate-eligible finishers were found.' );
		}
		$token = wp_generate_password( 48, false, false );
		$draft = array( 'event_id' => $event_id, 'rider_id' => $rider_id, 'rows' => $rows, 'hash' => self::fingerprint( $rows ) );
		if ( ! set_transient( self::draft_key( $token ), $draft, HOUR_IN_SECONDS ) ) {
			throw new RuntimeException( 'Certificate review could not be saved. Nothing was sent.' );
		}
		return array( 'token' => $token, 'draft' => $draft );
	}

	private static function draft_key( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{48}$/D', $token ) ) {
			throw new RuntimeException( 'Invalid certificate review identifier.' );
		}
		return 'rqe_certificate_review_' . get_current_user_id() . '_' . $token;
	}

	/** Each approval is single-use, locked on its unique batch identifier before recipient inserts. */
	public static function approve( $event_id, $token, $subject, $message, $resend = false ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		$draft = get_transient( self::draft_key( $token ) );
		if ( ! is_array( $draft ) || (int) $draft['event_id'] !== $event_id ) {
			throw new RuntimeException( 'Certificate review expired or was already approved. Review again.' );
		}
		$subject = RQE_Admin::text( $subject, 200, true );
		if ( preg_match( '/[\r\n]/', $subject ) || ! is_string( $message ) || strlen( $message ) > 5000 ) {
			throw new RuntimeException( 'Use a single-line subject and a message no longer than 5,000 bytes.' );
		}
		$message = sanitize_textarea_field( $message );
		RQE::begin_transaction();
		try {
			$event = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ) );
			if ( ! $event || $wpdb->last_error ) {
				throw new RuntimeException( 'Event could not be locked for certificate approval.' );
			}
			$existing_batch = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'certificate_mail' ) . ' WHERE batch=%s LIMIT 1', $token ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Previous certificate approval could not be checked.' );
			}
			if ( $existing_batch ) {
				throw new RuntimeException( 'This review has already been approved. Use its existing delivery batch.' );
			}
			$current = self::snapshots( $event_id, (int) $draft['rider_id'] );
			if ( ! hash_equals( $draft['hash'], self::fingerprint( $current ) ) ) {
				throw new RuntimeException( 'Certificate data or recipients changed after review. Review again before sending.' );
			}
			$queued = 0;
			$skipped = 0;
			foreach ( $draft['rows'] as $data ) {
				if ( ! is_email( $data['email'] ) || preg_match( '/^erased-\d+@example\.invalid$/', $data['email'] ) ) {
					throw new RuntimeException( 'Participant #' . $data['number'] . ' needs a valid email address. Nothing was queued.' );
				}
				$hash = self::fingerprint( $data );
				$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'certificate_mail' ) . " WHERE event_id=%d AND rider_id=%d AND fingerprint=%s AND state IN ('pending','sending','sent') LIMIT 1", $event_id, $data['rider_id'], $hash ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Previous certificate deliveries could not be checked.' );
				}
				if ( $existing && ! $resend ) {
					++$skipped;
					continue;
				}
				RQE_Admin::checked( $wpdb->insert( RQE::table( 'certificate_mail' ), array( 'batch' => $token, 'event_id' => $event_id, 'rider_id' => $data['rider_id'], 'user_id' => get_current_user_id(), 'snapshot' => wp_json_encode( $data ), 'fingerprint' => $hash, 'subject' => $subject, 'message' => $message, 'state' => 'pending', 'detail' => '', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ) );
				++$queued;
			}
			( new RQE_Import_Audit() )->record( $event_id, 'certificates_approved', 'Approved ' . $queued . ' certificate emails; skipped ' . $skipped . ' unchanged deliveries. Explicit resend: ' . ( $resend ? 'yes' : 'no' ) . '.' );
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			RQE::rollback_transaction();
			throw $exception;
		}
		delete_transient( self::draft_key( $token ) );
		return array( 'batch' => $token, 'queued' => $queued, 'skipped' => $skipped );
	}

	public static function deliveries( $event_id, $batch ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		self::draft_key( $batch );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,rider_id,state,detail FROM ' . RQE::table( 'certificate_mail' ) . ' WHERE event_id=%d AND batch=%s AND user_id=%d ORDER BY id', $event_id, $batch, get_current_user_id() ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Certificate delivery outcomes could not be read.' );
		}
		return $rows;
	}

	public static function send_batch( $event_id, $batch ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		self::draft_key( $batch );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'certificate_mail' ) . " WHERE event_id=%d AND batch=%s AND user_id=%d AND state='pending' ORDER BY id LIMIT %d", $event_id, $batch, get_current_user_id(), self::BATCH_SIZE ) );
		if ( ! is_array( $ids ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Certificate batch could not be read.' );
		}
		foreach ( $ids as $id ) {
			RQE::begin_transaction();
			try {
				// Same event-first order as scoring: the snapshot is rechecked under the event lock.
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ) ) ) {
					throw new RuntimeException( 'Event no longer exists.' );
				}
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'certificate_mail' ) . ' WHERE id=%d FOR UPDATE', $id ), ARRAY_A );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Certificate delivery could not be locked.' );
				}
				if ( ! $row || 'pending' !== $row['state'] ) {
					RQE::commit_transaction();
					continue;
				}
				$current = self::snapshots( $event_id, (int) $row['rider_id'] );
				if ( strtotime( $row['created_at'] . ' UTC' ) < time() - DAY_IN_SECONDS || count( $current ) !== 1 || ! hash_equals( $row['fingerprint'], self::fingerprint( $current[0] ) ) ) {
					RQE_Admin::checked( $wpdb->update( RQE::table( 'certificate_mail' ), array( 'state' => 'stale', 'snapshot' => '', 'subject' => '', 'message' => '', 'detail' => 'Approval expired or data changed. Review again.', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id ) ) );
					RQE::commit_transaction();
					continue;
				}
				RQE_Admin::checked( $wpdb->update( RQE::table( 'certificate_mail' ), array( 'state' => 'sending', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id, 'state' => 'pending' ) ) );
				( new RQE_Import_Audit() )->record( $event_id, 'certificate_attempt', 'Starting reviewed certificate email for participant ID ' . $row['rider_id'] . '; delivery ID ' . $id . '.' );
				RQE::commit_transaction();
			} catch ( RuntimeException $exception ) {
				RQE::rollback_transaction();
				throw $exception;
			}
			$directory = '';
			$mail_attempted = false;
			try {
				$pdf = self::pdf( $current );
				$directory = RQE_Bonus::create_stage_directory();
				$path = $directory . '/certificate.pdf';
				if ( false === file_put_contents( $path, $pdf ) || ! chmod( $path, 0600 ) ) {
					throw new RuntimeException( 'Private certificate attachment could not be written.' );
				}
				$mail_attempted = true;
				$accepted = wp_mail( $current[0]['email'], $row['subject'], $row['message'], array(), array( $path ) );
				$state = $accepted ? 'sent' : 'failed';
				$detail = $accepted ? 'Accepted by the mail system; delivery is not guaranteed.' : 'WordPress mail returned a failure. Review again to retry.';
			} catch ( RuntimeException $exception ) {
				if ( $mail_attempted ) {
					error_log( 'RQE certificate mail: ' . $exception->getMessage() );
					throw new RuntimeException( 'Certificate mail stopped with an uncertain outcome. Check the recent batch on Results before explicitly reviewing a resend.', 0, $exception );
				}
				$state = 'failed';
				$detail = $exception->getMessage();
			} finally {
				if ( $directory ) {
					( new RQE_Gallery_Filesystem() )->remove_directory( $directory );
				}
			}
			RQE_Admin::checked( $wpdb->update( RQE::table( 'certificate_mail' ), array( 'state' => $state, 'snapshot' => '', 'subject' => '', 'message' => '', 'detail' => $detail, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id, 'state' => 'sending' ) ) );
			( new RQE_Import_Audit() )->record( $event_id, 'certificate_' . $state, 'Participant ID ' . $row['rider_id'] . '; delivery ID ' . $id . ': ' . $detail );
		}
	}

	private static function form( $event_id, $mode ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'rqe_certificates' );
		echo '<input type="hidden" name="action" value="rqe_certificates"><input type="hidden" name="event_id" value="' . esc_attr( $event_id ) . '"><input type="hidden" name="mode" value="' . esc_attr( $mode ) . '">';
	}

	public static function handle() {
		try {
			check_admin_referer( 'rqe_certificates' );
			$post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' );
			$event_id = RQE_Admin::integer( $post ? RQE_Admin::posted( 'event_id' ) : RQE_Admin::query( 'event_id' ), 1 );
			$mode = $post ? RQE_Admin::posted( 'mode' ) : RQE_Admin::query( 'mode' );
			RQE_Admin::require_event( $event_id );
			nocache_headers();
			if ( in_array( $mode, array( 'approve', 'continue' ), true ) && ! $post ) {
				throw new RuntimeException( 'Certificate sending requires explicit POST approval.' );
			}
			if ( 'pdf' === $mode || 'print' === $mode ) {
				$rows = self::snapshots( $event_id, RQE_Admin::integer( RQE_Admin::query( 'rider_id', '0' ) ) );
				if ( ! $rows ) {
					throw new RuntimeException( 'No eligible final finishers were found.' );
				}
				if ( 'pdf' === $mode ) {
					$pdf = self::pdf( $rows );
					header( 'Content-Type: application/pdf' );
					header( 'Content-Disposition: attachment; filename="finisher-certificates.pdf"' );
					echo $pdf;
					exit;
				} else {
					header( 'Content-Type: text/html; charset=utf-8' );
					$html = self::document( $rows );
					echo str_replace( '<body>', '<body><div class="controls"><button onclick="window.print()">Print / Save as PDF</button></div>', $html );
				}
			} elseif ( 'review' === $mode ) {
				$review = self::review( $event_id, RQE_Admin::integer( RQE_Admin::query( 'rider_id', '0' ) ) );
				header( 'Content-Type: text/html; charset=utf-8' );
				echo '<!doctype html><meta charset="utf-8"><title>Review Certificate Emails</title><h1>Review Certificate Emails</h1><p>Review every recipient and certificate. Nothing is sent until you approve. Unchanged pending/sent certificates are skipped unless you explicitly request a resend. Invalid addresses block approval. Delivery batches process up to 10 participants per request; continue remaining batches below.</p>';
				foreach ( $review['draft']['rows'] as $data ) {
					echo '<details><summary>' . esc_html( $data['name'] . ' — ' . $data['email'] ) . '</summary>' . self::certificate_html( $data ) . '</details>';
				}
				self::form( $event_id, 'approve' );
				echo '<input type="hidden" name="token" value="' . esc_attr( $review['token'] ) . '"><p><label>Subject <input name="subject" maxlength="200" required value="Your finisher certificate"></label></p><p><label>Message <textarea name="message" maxlength="5000" rows="5">Congratulations! Your reviewed finisher certificate is attached.</textarea></label></p><p><label><input type="checkbox" name="resend" value="1">Explicitly resend unchanged certificates, including ones already sent or with an uncertain outcome</label></p><p><label><input type="checkbox" name="approved" value="1" required>I reviewed and approve these recipients and certificates</label></p><button>Approve and Send First Batch</button></form>';
			} elseif ( in_array( $mode, array( 'approve', 'continue', 'outcomes' ), true ) ) {
				if ( 'approve' === $mode ) {
					if ( '1' !== RQE_Admin::posted( 'approved' ) ) {
						throw new RuntimeException( 'Review and explicitly approve the certificates first.' );
					}
					$result = self::approve( $event_id, RQE_Admin::posted( 'token' ), RQE_Admin::posted( 'subject' ), RQE_Admin::posted( 'message' ), '1' === RQE_Admin::posted( 'resend' ) );
					$batch = $result['batch'];
					self::send_batch( $event_id, $batch );
				} else {
					$batch = $post ? RQE_Admin::posted( 'batch' ) : RQE_Admin::query( 'batch' );
					if ( 'continue' === $mode ) {
						self::send_batch( $event_id, $batch );
					}
				}
				header( 'Content-Type: text/html; charset=utf-8' );
				echo '<!doctype html><meta charset="utf-8"><title>Certificate Delivery Outcomes</title><h1>Certificate Delivery Outcomes</h1><p>Sent means accepted by the mail system, not confirmed delivery. Sending means the outcome may be uncertain; it is never retried automatically. Failed or stale certificates need a new review; uncertain outcomes also require explicit resend approval.</p>';
				if ( isset( $result ) ) {
					echo '<p>Approved: ' . esc_html( $result['queued'] ) . ' queued; ' . esc_html( $result['skipped'] ) . ' unchanged deliveries skipped.</p>';
				}
				if ( isset( $result ) ) {
					echo '<p>' . esc_html( $result['queued'] . ' queued; ' . $result['skipped'] . ' unchanged deliveries skipped.' ) . '</p>';
				}
				$pending = 0;
				foreach ( self::deliveries( $event_id, $batch ) as $row ) {
					echo '<p>' . esc_html( 'Participant ID ' . $row['rider_id'] . ': ' . $row['state'] . ' — ' . $row['detail'] ) . '</p>';
					$pending += 'pending' === $row['state'] ? 1 : 0;
				}
				if ( $pending ) {
					self::form( $event_id, 'continue' );
					echo '<input type="hidden" name="batch" value="' . esc_attr( $batch ) . '"><button>Continue Next Batch (' . esc_html( $pending ) . ' remaining)</button></form>';
				}
			} else {
				throw new RuntimeException( 'Select a supported certificate operation.' );
			}
			echo '<p class="controls"><a href="' . esc_url( RQE_Admin::url( 'results', array( 'event_id' => $event_id ) ) ) . '">Back to Results</a></p>';
		} catch ( RuntimeException $exception ) {
			wp_die( esc_html( $exception->getMessage() ), '', array( 'response' => 400, 'back_link' => true ) );
		}
		exit;
	}
}
