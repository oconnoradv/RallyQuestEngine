<?php
/** Accepted bonus/public proof regressions; also included by event-administration.php. */
function test_public_bonus_details() {
	global $wpdb;
	$queries = new RQE_Queries( $wpdb );
	$GLOBALS['actor'] = 1;
	request( array( 'event_id' => '7', 'page' => 'rqe-event-edit' ) );
	$event_before = RQE::row( 'events', 7 );
	$other_before = RQE::row( 'events', 8 );
	$uploads = __DIR__ . '/fixtures/public-proof-' . bin2hex( random_bytes( 8 ) );
	$directory = $uploads . '/rqe-event-gallery/7/submissions';
	mkdir( $directory, 0700, true );
	$GLOBALS['proof_uploads'] = $uploads;
	$certificate_uploads_before = $GLOBALS['certificate_uploads'] ?? null;
	$GLOBALS['certificate_uploads'] = $uploads;
	$proof = str_repeat( 'a', 32 ) . '.gif';
	$invalid_image = str_repeat( 'b', 32 ) . '.png';
	file_put_contents( $directory . '/' . $proof, base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ) );
	file_put_contents( $directory . '/' . $invalid_image, 'not an image' );
	try {
		$wpdb->insert( RQE::table( 'event_scorers' ), array( 'event_id' => 7, 'user_id' => 4 ) );
		foreach ( array( 'sender_email', 'provider_message_id', 'email_subject' ) as $column ) {
			RQE_Admin::checked( $wpdb->query( 'ALTER TABLE ' . RQE::table( 'bonus_submissions' ) . " ADD $column TEXT DEFAULT ''" ) );
		}
		$wpdb->insert( RQE::table( 'bonus_points' ), array( 'event_id' => 7, 'bonus_id' => 'ACCEPT-PROOF', 'description' => '<script>bonus-description</script>', 'image_file' => 'LIBRARY-PHOTO.jpg' ) );
		foreach ( array(
			array( 900, 7, 10, 'ACCEPT-PROOF', 'accepted', $proof ),
			array( 901, 7, 10, 'REJECTION-PRIVATE', 'rejected', $proof ),
			array( 902, 7, 10, 'PENDING-PRIVATE', 'pending', $proof ),
			array( 903, 8, 10, 'CROSS-EVENT-PRIVATE', 'accepted', $proof ),
			array( 904, 7, 11, 'OTHER-RIDER-PRIVATE', 'accepted', $proof ),
			array( 905, 7, 999, 'UNENROLLED-PRIVATE', 'accepted', $proof ),
			array( 906, 7, 10, 'MISSING-PROOF', 'accepted', str_repeat( 'c', 32 ) . '.gif' ),
			array( 907, 7, 10, 'INVALID-PROOF', 'accepted', $invalid_image ),
			array( 908, 7, 10, 'NO-PROOF', 'accepted', '' ),
			array( 909, 7, 10, 'TRAVERSAL-PROOF', 'accepted', '../' . $proof ),
		) as $fixture ) {
			$wpdb->insert( RQE::table( 'bonus_submissions' ), array(
				'id' => $fixture[0], 'event_id' => $fixture[1], 'rider_id' => $fixture[2], 'bib' => 42, 'bonus_id' => $fixture[3], 'status' => $fixture[4], 'image_file' => $fixture[5],
				'points_awarded' => 15, 'submitted_at' => '2026-10-11 01:00:00', 'submission_description' => 'PRIVATE-MESSAGE',
				'sender_email' => 'PRIVATE-SENDER@example.test', 'provider_message_id' => 'PRIVATE-PROVIDER-ID', 'email_subject' => 'PRIVATE-SUBJECT',
				'rejection_description' => 'PRIVATE-REASON', 'reviewed_by' => 999, 'reviewed_at' => 'PRIVATE-REVIEW-TIME',
			) );
		}
		check( (int) $event_before['public_bonus_details_enabled'] === 0, 'public proof publication defaults off in existing event fixture' );
		rejects( function () { RQE_Public_Results::save_visibility( 7, 0, 1 ); }, 'require public results' );
		rejects( function () { RQE_Public_Results::save_visibility( 7, 1, 2 ); }, 'whether public accepted bonus details' );
		RQE_Public_Results::save_visibility( 7, 1, 0 );
		$GLOBALS['actor'] = 0;
		request( array( 'rqe_bib_7' => '42' ) );
		$disabled = RQE_Public_Results::shortcode( array( 'id' => 'MC-7' ) );
		check( strpos( $disabled, 'not available for this participant' ) !== false && strpos( $disabled, 'ACCEPT-PROOF' ) === false && strpos( $disabled, '<img' ) === false, 'bookmarked detail requests cannot bypass disabled opt-in' );
		check( null === $queries->public_accepted_submissions( 7, 42, 'motorcycle' ), 'read model enforces extra opt-in independently of shortcode' );
		$GLOBALS['actor'] = 2;
		RQE_Public_Results::save_visibility( 7, 1, 1 );
		check( (int) RQE::row( 'events', 7 )['public_bonus_details_enabled'] === 1, 'assigned event administrators may opt into accepted proof publication' );
		$embed = render( 'public_results_embed', RQE::row( 'events', 7 ) );
		check( strpos( $embed, 'name="public_bonus_details_enabled" value="1" checked' ) !== false && strpos( $embed, 'direct upload URLs remain accessible' ) !== false && strpos( $embed, 'cached copies may persist' ) !== false, 'embed opt-in reflects stored setting and warns about public photos and revocation limits' );
		$wpdb->fail_write = true;
		rejects( function () { RQE_Public_Results::save_visibility( 7, 0, 0 ); }, 'could not be saved' );
		$wpdb->fail_write = false;
		check( (int) RQE::row( 'events', 7 )['public_bonus_details_enabled'] === 1 && (int) RQE::row( 'events', 7 )['public_results_enabled'] === 1, 'both publication flags roll back together on write failure' );
		$GLOBALS['actor'] = 3;
		rejects( function () { RQE_Public_Results::save_visibility( 7, 1, 1 ); }, 'not assigned' );
		$GLOBALS['actor'] = 0;
		request();
		$standings = RQE_Public_Results::shortcode( array( 'id' => 'MC-7' ) );
		check( strpos( $standings, 'https://example.test/results/?rqe_bib_7=42' ) !== false && strpos( $standings, 'wp-admin' ) === false && strpos( $standings, 'rider_id' ) === false, 'opted-in Bib links use only same-page event-scoped public parameters' );
		request( array( 'rqe_bib_7' => '42' ) );
		$wpdb->sql = array();
		$data = $queries->public_accepted_submissions( 7, 42, 'motorcycle' );
		$allowed = array( 'bonus_id', 'bonus_description', 'points_awarded', 'submitted_at', 'image_file' );
		check( $data['bib'] == 42 && array_diff( array_keys( $data['rows'][0] ), $allowed ) === array(), 'accepted read model selects only public-safe bonus/proof columns' );
		$accepted_sql = end( $wpdb->sql );
		check( strpos( $accepted_sql, "s.status='accepted'" ) !== false && strpos( $accepted_sql, 'e.public_results_enabled=1 AND e.public_bonus_details_enabled=1 AND e.hunt_style=%s' ) !== false && strpos( $accepted_sql, 'reg.event_id=s.event_id AND reg.rider_id=s.rider_id' ) !== false && strpos( $accepted_sql, 's.*' ) === false, 'detail SQL rechecks publication, style, event enrollment and accepted decision on row fetch' );
		$wpdb->sql = array();
		$detail = RQE_Public_Results::shortcode( array( 'id' => 'MC-7' ) );
		check( ! isset( $_GET['_wpnonce'] ) && strpos( $detail, 'ACCEPT-PROOF' ) !== false && ! preg_match( '/\b(?:INSERT|UPDATE|DELETE)\b/', implode( "\n", $wpdb->sql ) ), 'anonymous accepted-details GET is nonce-free and strictly read-only' );
		check( strpos( $detail, 'ACCEPT-PROOF' ) !== false && strpos( $detail, '&lt;script&gt;bonus-description&lt;/script&gt;' ) !== false && strpos( $detail, '<script>' ) === false, 'accepted listing escapes library description' );
		check( strpos( $detail, 'class="rqe-submitted-proof"' ) !== false && strpos( $detail, '<img src="https://example.test/uploads/rqe-event-gallery/7/submissions/' . $proof ) !== false && strpos( $detail, 'loading="lazy"' ) !== false && strpos( $detail, 'View larger photo' ) !== false && strpos( $detail, 'LIBRARY-PHOTO' ) === false, 'submitted proof appears as a linked lazy thumbnail rather than the library reference photo' );
		check( substr_count( $detail, 'Photo unavailable' ) === 3 && strpos( $detail, 'No submitted photo' ) !== false, 'missing files, invalid raster content and traversal filenames are explicitly unavailable' );
		foreach ( array( 'PRIVATE-', 'REJECTION-PRIVATE', 'PENDING-PRIVATE', 'CROSS-EVENT-PRIVATE', 'OTHER-RIDER-PRIVATE', 'UNENROLLED-PRIVATE', 'one@example.test', 'reviewed_by', 'registration_id', 'submission_description', 'sender_email', 'wp-admin', 'name="op"', '<form' ) as $private ) {
			check( strpos( $detail, $private ) === false, 'public accepted details exclude ' . $private );
		}
		check( null === $queries->public_accepted_submissions( 7, 44, 'motorcycle' ) && null === $queries->public_accepted_submissions( 7, 42, 'horse' ) && null === $queries->public_accepted_submissions( 8, 42, 'horse' ), 'public detail queries reject cross-event Bibs, mismatched styles and unenrolled participants' );
		check( strpos( RQE_Public_Results::shortcode( array( 'id' => 'HB-8' ) ), 'Event 8 - Overall Results' ) !== false, 'one event selection does not switch another embed into details' );
		$GLOBALS['actor'] = 1;
		RQE_Public_Results::save_visibility( 8, 1, 1 );
		$GLOBALS['actor'] = 0;
		request( array( 'rqe_bib_7' => '42', 'rqe_bib_8' => '44', 'rqe_bib_99' => array( '42' ), 'page' => 'PRIVATE-ADMIN' ) );
		$other_detail = RQE_Public_Results::shortcode( array( 'id' => 'HB-8' ) );
		check( strpos( $other_detail, 'CROSS-EVENT-PRIVATE' ) !== false && strpos( $other_detail, 'ACCEPT-PROOF' ) === false && strpos( $other_detail, 'href="https://example.test/results/?rqe_bib_7=42"' ) !== false && strpos( $other_detail, 'PRIVATE-ADMIN' ) === false, 'multi-embed details and Back links preserve only other valid event selections' );
		foreach ( array( '', '0', '-42', '42 OR 1=1', '42.0', "42\\'", '42\\\\', array( '42' ), '999999999999', '44' ) as $bib ) {
			request( array( 'rqe_bib_7' => $bib ) );
			$invalid = RQE_Public_Results::shortcode( array( 'id' => 'MC-7' ) );
			check( strpos( $invalid, 'not available for this participant' ) !== false && strpos( $invalid, 'ACCEPT-PROOF' ) === false, 'invalid or foreign Bib selection does not reveal submissions' );
		}
		request( array( 'rqe_bib_7' => '42' ) );
		$GLOBALS['actor'] = 1;
		$wpdb->update( RQE::table( 'events' ), array( 'public_results_enabled' => 0 ), array( 'id' => 7 ) );
		check( null === $queries->public_accepted_submissions( 7, 42, 'motorcycle' ) && strpos( RQE_Public_Results::shortcode( array( 'id' => 'MC-7' ) ), 'Results are not available for this event' ) !== false, 'unpublishing results blocks details even if extra opt-in remains set' );
		RQE_Public_Results::save_visibility( 7, 1, 1 );
		foreach ( array( 'is_locked', 'is_archived' ) as $flag ) {
			$wpdb->update( RQE::table( 'events' ), array( $flag => 1 ), array( 'id' => 7 ) );
			rejects( function () { RQE_Public_Results::save_visibility( 7, 0, 0 ); }, 'locked' );
			check( null !== $queries->public_accepted_submissions( 7, 42, 'motorcycle' ), 'published accepted reads remain available for protected events' );
			$wpdb->update( RQE::table( 'events' ), array( $flag => 0 ), array( 'id' => 7 ) );
		}
		request( array( 'event_id' => '7', 'rider_id' => '10', 'from' => 'reviewed' ) );
		$GLOBALS['actor'] = 4;
		$score = render( 'render_rider_score' );
		$accepted_section = explode( '</section>', explode( '<section id="rqe-accepted-bonuses">', $score )[1] )[0];
		check( strpos( $accepted_section, 'ACCEPT-PROOF' ) !== false && strpos( $accepted_section, 'rqe-submitted-proof' ) !== false && strpos( $accepted_section, 'REJECTION-PRIVATE' ) === false && strpos( $accepted_section, 'PENDING-PRIVATE' ) === false, 'admin score sheet shares accepted-only renderer with submitted thumbnails' );
		check( strpos( $score, 'Bonus Submission History (all decisions)' ) !== false && strpos( $score, 'REJECTION-PRIVATE' ) !== false && strpos( $score, 'PENDING-PRIVATE' ) !== false && strpos( $score, 'name="op" value="registration_status"' ) === false, 'full privileged history remains separate and scorers gain no edit controls' );
		$reviewed = render( 'reviewed_submissions', RQE::row( 'events', 7 ), 10 );
		check( strpos( $reviewed, 'from=reviewed#rqe-accepted-bonuses' ) !== false, 'Reviewed Bib links target accepted section while retaining return context' );
		$GLOBALS['actor'] = 0;
		rejects( function () { render( 'render_rider_score' ); }, 'not assigned' );
		$GLOBALS['actor'] = 2;
		request( array( 'event_id' => '7', 'page' => 'rqe-event-edit' ) );
		$results = render( 'overall_results' );
		check( strpos( $results, 'rider_id=10#rqe-accepted-bonuses' ) !== false, 'admin Results Bib links target the accepted-only score-sheet section' );
		$wpdb->sql = array();
		rejects( function () { render( 'render_event_email' ); }, 'Only WordPress administrators' );
		check( strpos( implode( "\n", $wpdb->sql ), 'event_email_settings' ) === false, 'event administrator inbox page rejection occurs before fetching mailbox configuration' );
		$wpdb->sql = array();
		$event_edit = render( 'render_event_edit' );
		check( strpos( $event_edit, 'Only WordPress administrators may configure submission inboxes.' ) !== false && strpos( $event_edit, 'Configure Submission Inbox' ) === false && strpos( $event_edit, 'Test Email Inbox' ) === false && strpos( $event_edit, 'inbox@example.test' ) === false && strpos( implode( "\n", $wpdb->sql ), 'event_email_settings' ) === false, 'event administrators see only informational inbox panel without mailbox status, addresses or controls' );
	} finally {
		$GLOBALS['actor'] = 1;
		$wpdb->fail_write = false;
		$wpdb->update( RQE::table( 'events' ), $event_before, array( 'id' => 7 ) );
		$wpdb->update( RQE::table( 'events' ), $other_before, array( 'id' => 8 ) );
		foreach ( range( 900, 909 ) as $id ) { $wpdb->delete( RQE::table( 'bonus_submissions' ), array( 'id' => $id ) ); }
		$wpdb->delete( RQE::table( 'bonus_points' ), array( 'event_id' => 7, 'bonus_id' => 'ACCEPT-PROOF' ) );
		$wpdb->delete( RQE::table( 'event_scorers' ), array( 'event_id' => 7, 'user_id' => 4 ) );
		unlink( $directory . '/' . $proof );
		unlink( $directory . '/' . $invalid_image );
		rmdir( $directory );
		rmdir( dirname( $directory ) );
		rmdir( dirname( dirname( $directory ) ) );
		rmdir( $uploads );
		unset( $GLOBALS['proof_uploads'] );
		if ( null === $certificate_uploads_before ) {
			unset( $GLOBALS['certificate_uploads'] );
		} else {
			$GLOBALS['certificate_uploads'] = $certificate_uploads_before;
		}
		request();
	}
}

if ( ! class_exists( 'Event_Test_DB' ) ) {
	require __DIR__ . '/event-administration.php';
}
