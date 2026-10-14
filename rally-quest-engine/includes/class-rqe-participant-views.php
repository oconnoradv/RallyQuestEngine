<?php
/** WordPress-native, event-scoped public forms and private participant history. */
defined( 'ABSPATH' ) || exit;

final class RQE_Participant_Views {
	private static function event_id( $attributes ) {
		$attributes = shortcode_atts( array( 'id' => '' ), is_array( $attributes ) ? $attributes : array(), 'rqe_register' );
		$code = $attributes['id'];
		if ( ! is_string( $code ) || ! preg_match( '/^(MC|BC|HB|GC)-([1-9][0-9]{0,9})$/D', $code, $matches ) ) {
			throw new RuntimeException( 'Use the event shortcode copied from Event Configuration.' );
		}
		$event = RQE_Participants::event( (int) $matches[2] );
		if ( RQE_Styles::event_code( $event ) !== $code ) { throw new RuntimeException( 'The event shortcode no longer matches this event.' ); }
		return (int) $event['id'];
	}

	private static function start_form( $action, $id ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( 'rqe_participant_submit' === $action ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="event_id" value="' . (int) $id . '">';
		wp_nonce_field( $action );
	}

	public static function registration( $attributes ) {
		ob_start();
		echo '<section class="rqe-participant" aria-label="Event registration">';
		try {
			$id = self::event_id( $attributes );
			$event = RQE_Participants::event( $id, true );
			echo '<h2>Add a Participant: ' . esc_html( $event['name'] ) . '</h2><p>New accounts require email verification and administrator approval before login. Existing accounts retain their roles and login access, but require approval for this event.</p>';
			self::start_form( 'rqe_participant_register', $id );
			if ( ! is_user_logged_in() ) {
				echo '<p>Already registered with this website? <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Sign in before requesting event access</a>.</p>';
				echo '<input type="hidden" name="guest_token" value="' . esc_attr( RQE_Participants::guest_token() ) . '"><label>Email address<input type="email" name="email" maxlength="190" autocomplete="email" required></label>';
			} else {
				echo '<p>Account: ' . esc_html( wp_get_current_user()->user_email ) . '</p>';
			}
			echo '<label>Participant name<input name="name" maxlength="160" autocomplete="name" required></label><label>Phone (optional)<input type="tel" name="phone" maxlength="60" autocomplete="tel"></label>';
			echo '<div class="rqe-registration-trap" aria-hidden="true"><label>Leave this field blank<input name="website" tabindex="-1" autocomplete="off"></label></div>';
			echo '<label class="rqe-consent"><input type="checkbox" name="consent" value="1" required> I consent to storage of my account and event registration information for event administration.</label><button type="submit">Request registration</button></form>';
		} catch ( RuntimeException $error ) {
			echo '<p role="alert">' . esc_html( $error->getMessage() ) . '</p>';
		}
		echo '</section>';
		return ob_get_clean();
	}

	public static function portal( $attributes ) {
		global $wpdb;
		ob_start();
		echo '<section class="rqe-participant" aria-label="Participant bonus submissions">';
		try {
			$id = self::event_id( $attributes );
			if ( ! is_user_logged_in() ) {
				echo '<p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">Sign in</a> with your approved participant account to submit bonuses and view your history.</p>';
			} else {
				$registration = RQE_Participants::enrollment( $id );
				$event = RQE_Participants::event( $id );
				echo '<h2>' . esc_html( $event['name'] ) . ': Participant #' . (int) $registration['bib'] . '</h2><p>Total points: <strong>' . (int) $registration['score'] . '</strong> · Registration: ' . esc_html( $registration['registration_status'] ) . '</p>';
				$now = current_time( 'mysql' );
				if ( ! empty( $event['is_locked'] ) || ! empty( $event['is_archived'] ) || empty( $event['start_at'] ) || empty( $event['end_at'] ) || $now < $event['start_at'] || $now > $event['end_at'] ) {
					echo '<p>Submissions are closed. Your history remains available while participant website access is enabled.</p>';
				} else {
					$bonuses = $wpdb->get_results( $wpdb->prepare( 'SELECT bonus_id,description FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d ORDER BY bonus_id', $id ), ARRAY_A );
					if ( ! is_array( $bonuses ) || $wpdb->last_error ) { throw new RuntimeException( 'The bonus library could not be read.' ); }
					echo '<h3>Submit a bonus</h3><p>Submit one proof image (JPEG, PNG, GIF or WebP, up to 10 MB). Submissions require scorer review and cannot be edited or deleted by participants.</p>';
					self::start_form( 'rqe_participant_submit', $id );
					echo '<input type="hidden" name="submission_token" value="' . esc_attr( RQE_Participants::submission_token( $id ) ) . '">';
					echo '<label>Bonus<select name="bonus_id" required><option value="">Select a bonus</option>';
					foreach ( $bonuses as $bonus ) { echo '<option value="' . esc_attr( $bonus['bonus_id'] ) . '">' . esc_html( $bonus['bonus_id'] . ' — ' . $bonus['description'] ) . '</option>'; }
					echo '</select></label><label>Description (optional)<textarea name="description" maxlength="5000" rows="3"></textarea></label><label>Proof image<input type="file" name="proof" accept="image/jpeg,image/png,image/gif,image/webp" required></label><button type="submit">Submit for review</button></form>';
				}
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'bonus_submissions' ) . ' WHERE event_id=%d AND rider_id=%d ORDER BY id DESC LIMIT 51', $id, $registration['rider_id'] ), ARRAY_A );
				if ( ! is_array( $rows ) || $wpdb->last_error ) { throw new RuntimeException( 'Submission history could not be read.' ); }
				echo '<h3>Submission and scoring history</h3>';
				if ( ! $rows ) {
					echo '<p>No submissions yet.</p>';
				} else {
					// Cursor pagination preserves event/account scope and does not grow query offsets.
					$before = RQE_Admin::query( 'rqe_before_' . $id );
					if ( '' !== $before ) {
						$cursor = RQE_Admin::integer( $before, 1 );
						$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'bonus_submissions' ) . ' WHERE event_id=%d AND rider_id=%d AND id<%d ORDER BY id DESC LIMIT 51', $id, $registration['rider_id'], $cursor ), ARRAY_A );
						if ( ! is_array( $rows ) || $wpdb->last_error ) { throw new RuntimeException( 'Submission history could not be read.' ); }
					}
					$more = count( $rows ) > 50;
					$rows = array_slice( $rows, 0, 50 );
					echo '<div class="rqe-participant-scroll"><table><caption>Your submissions (timestamps UTC)</caption><thead><tr><th scope="col">Bonus</th><th scope="col">Submitted</th><th scope="col">Decision</th><th scope="col">Points</th><th scope="col">Review</th><th scope="col">Proof</th></tr></thead><tbody>';
					foreach ( $rows as $row ) {
						echo '<tr><td>' . esc_html( $row['bonus_id'] ) . '</td><td>' . esc_html( $row['submitted_at'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . (int) $row['points_awarded'] . '</td><td>' . esc_html( trim( ( $row['rejection_code'] ?? '' ) . ' ' . $row['rejection_description'] ) ) . '</td><td>';
						if ( 0 === strpos( (string) $row['image_file'], 'p-' ) ) { echo '<a href="' . esc_url( RQE_Bonus::submission_image_url( $id, $row['image_file'] ) ) . '">View proof</a>'; }
						echo '</td></tr>';
					}
					echo '</tbody></table></div><p><a href="' . esc_url( remove_query_arg( 'rqe_before_' . $id, get_permalink() ) ) . '">Newest submissions</a>';
					if ( $more ) { echo ' · <a href="' . esc_url( add_query_arg( 'rqe_before_' . $id, end( $rows )['id'], get_permalink() ) ) . '">Older submissions</a>'; }
					echo '</p>';
				}
			}
		} catch ( RuntimeException $error ) {
			echo '<p role="alert">' . esc_html( $error->getMessage() ) . '</p>';
		}
		echo '</section>';
		return ob_get_clean();
	}

	public static function administration( array $event ) {
		$id = (int) $event['id'];
		$code = RQE_Styles::event_code( $event );
		$rows = self::pending_rows( $id );
		echo '<details class="rqe-diagnostics-section rqe-participant-access' . ( $rows ? ' rqe-has-pending' : '' ) . '" id="rqe-participant-access"' . ( $rows ? ' open' : '' ) . '><summary><h3>Participant Website Access &amp; Registration</h3>' . ( $rows ? ' <strong class="rqe-pending-count">' . esc_html( count( $rows ) . ' pending request(s)' ) . '</strong>' : '' ) . '</summary><p>Enable participant website access, then copy these shortcodes into a WordPress Shortcode block. Registration uses the event registration window; submissions use its start/end window. New participant accounts remain unable to log in until email verification and approval.</p>';
		RQE_Views::form( 'participant_visibility', array( 'event_id' => $id ) );
		echo '<label><input type="checkbox" name="enabled" value="1"' . checked( ! empty( $event['self_registration_enabled'] ), true, false ) . '> Enable self-registration and private participant portal</label> ';
		echo '<button class="button">Save Participant Website Access</button>';
		echo '</form><p><code>' . esc_html( '[rqe_register id="' . $code . '"]' ) . '</code> — Add a Participant</p><p><code>' . esc_html( '[rqe_participant id="' . $code . '"]' ) . '</code> — Submit bonuses and view private history</p><p>Exclude both embedding pages from page/CDN caching. Proof images submitted on the website are private and are not exposed in public results.</p>';
		echo '<h4>Pending registration requests</h4>';
		self::requests( $id, $rows, 'event-edit' );
		self::decision_history( array( $id ), 'rqe-participant-decisions-' . $id );
		echo '</details>';
	}

	/** Participants tab: pending approvals across every event the user administers; expanded while requests wait. */
	public static function approvals_panel() {
		$groups = array();
		foreach ( RQE_Alerts::pending_requests() as $pending ) {
			$rows = self::pending_rows( $pending['event_id'] );
			if ( $rows ) {
				$groups[] = array( 'event' => $pending, 'rows' => $rows );
			}
		}
		$total = array_sum( array_map( function ( $group ) { return count( $group['rows'] ); }, $groups ) );
		echo '<details class="rqe-panel rqe-participant-approvals' . ( $total ? ' rqe-has-pending' : '' ) . '" id="rqe-participant-approvals"' . ( $total ? ' open' : '' ) . '><summary><h3>Approve or Reject Self-Registered Participants</h3> <strong class="rqe-pending-count">' . esc_html( $total ? $total . ' pending request(s)' : 'No pending requests' ) . '</strong></summary>';
		if ( ! $groups ) {
			echo '<p>No requests awaiting approval.</p>';
		}
		foreach ( $groups as $group ) {
			echo '<h4>' . esc_html( $group['event']['name'] ) . ' <a href="' . esc_url( RQE_Admin::url( 'event-edit', array( 'event_id' => $group['event']['event_id'] ) ) . '#rqe-participant-access' ) . '">Event participant access</a></h4>';
			self::requests( $group['event']['event_id'], $group['rows'], 'riders' );
		}
		self::decision_history( RQE::event_ids(), 'rqe-participant-decisions' );
		echo '</details>';
	}

	private static function pending_rows( $event_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'participant_requests' ) . " WHERE event_id=%d AND status IN ('pending','verified') ORDER BY id LIMIT 100", $event_id ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) { throw new RuntimeException( 'Participant approval requests could not be read.' ); }
		return $rows;
	}

	private static function requests( $id, array $rows, $context_tab ) {
		if ( ! $rows ) { echo '<p>No requests awaiting approval.</p>'; }
		foreach ( $rows as $row ) {
			$user = get_user_by( 'id', $row['user_id'] );
			$payload = json_decode( $row['payload'], true );
			echo '<div class="rqe-participant-request"><p><strong>' . esc_html( $payload['name'] ?? 'Unknown participant' ) . '</strong> — ' . esc_html( $user ? $user->user_email : 'Account deleted' ) . ' · ' . esc_html( $row['status'] ) . '</p><p>Approval links this verified account to an existing participant with the same email, or creates a participant and assigns the next available event Bib. Confirm the identity before approving.</p>';
			RQE_Views::form( 'participant_decide', array( 'event_id' => $id, 'context_tab' => $context_tab ) );
			echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
			if ( 'verified' === $row['status'] && $user ) { echo '<button class="button button-primary" name="decision" value="approve">Approve and enroll</button> '; }
			echo '<button class="button" name="decision" value="reject" data-confirm="Reject this registration request? A new, never-activated account will be locked.">Reject request</button></form></div>';
		}
		if ( 100 === count( $rows ) ) { echo '<p>Showing the first 100 requests. Review these to see additional requests.</p>'; }
	}

	/** Recent approvals/rejections from the Administrative Audit, linked to the full audit for site administrators. */
	private static function decision_history( array $event_ids, $anchor ) {
		$rows = RQE_Alerts::participant_decisions( $event_ids );
		echo '<details class="rqe-participant-decisions" id="' . esc_attr( $anchor ) . '"><summary>Participant approval/rejection audit (latest 20)</summary><p>Every approval and rejection is retained in the Administrative Audit.';
		if ( RQE::site_access() ) {
			echo ' <a href="' . esc_url( RQE_Admin::url( 'diagnostics' ) . '#rqe-administrative-audit' ) . '">Open the full Administrative Audit</a>';
		}
		echo '</p>';
		if ( ! $rows ) {
			echo '<p>No participant decisions recorded yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">Date/time (UTC)</th><th scope="col">Username</th><th scope="col">Event ID</th><th scope="col">Decision</th><th scope="col">Details</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( $row['occurred_at'] ) . '</td><td>' . esc_html( $row['username'] ) . '</td><td>' . esc_html( $row['event_id'] ) . '</td><td>' . esc_html( 'participant_approved' === $row['action'] ? 'Approved' : 'Rejected' ) . '</td><td>' . esc_html( $row['detail'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</details>';
	}
}
