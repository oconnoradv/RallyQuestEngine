<?php
/** Escaped server-rendered administration views with optional local enhancement. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-mrsa-results.php';
require_once __DIR__ . '/class-mrsa-queries.php';

final class MRSA_Views {
	private static function h( $value ) {
		echo esc_html( (string) $value );
	}

	private static function year( $value ) {
		return (int) $value ? (string) $value : 'Unspecified';
	}

	private static function queries() {
		global $wpdb;
		return new MRSA_Queries( $wpdb );
	}

	private static function link( $label, $tab, $extra = array() ) {
		echo '<a href="' . esc_url( MRSA_Admin::url( $tab, $extra ) ) . '">' . esc_html( (string) $label ) . '</a>';
	}

	private static function form( $op, $hidden = array(), $class = '', $id = '', $enctype = '' ) {
		$return_url = MRSA_Admin::query( 'return_url' );
		if ( $return_url && ! isset( $hidden['return_url'] ) ) {
			$hidden['return_url'] = $return_url;
		}
		$enroll_event_id = absint( MRSA_Admin::query( 'enroll_event_id', '0' ) );
		if ( $enroll_event_id && ! isset( $hidden['enroll_event_id'] ) ) {
			$hidden['enroll_event_id'] = $enroll_event_id;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( $enctype ? ' enctype="' . esc_attr( $enctype ) . '"' : '' ) . '>';
		wp_nonce_field( 'mrsa_save' );
		foreach ( array_merge( array( 'action' => 'mrsa_save', 'op' => $op ), $hidden ) as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
	}

	private static function button( $label = 'Save', $op = '' ) {
		echo '<button type="submit" class="button"' . ( $op ? ' name="op" value="' . esc_attr( $op ) . '" data-confirm="Remove this assignment or registration?"' : '' ) . '>' . esc_html( $label ) . '</button>';
	}

	private static function notice() {
		$notice = MRSA_Admin::query( 'notice' );
		if ( $notice ) {
			$class = false !== strpos( $notice, 'WARNING: imported without an antivirus security scan' ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $notice ) . '</p></div>';
		}
	}

	private static function header() {
		echo '<div class="mrsa-header"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span><div><h1>Motorcycle Rally Scoring Application</h1><p>an O&rsquo;ConnorADV project <span>v' . esc_html( MRSA_VERSION ) . '</span></p></div></div><hr class="wp-header-end">';
	}

	private static function field( $label, $name, $value = '', $type = 'text', $required = false, $attrs = array() ) {
		echo '<label class="mrsa-field"><span>' . esc_html( $label ) . '</span>';
		if ( 'textarea' === $type ) {
			echo '<textarea name="' . esc_attr( $name ) . '" rows="4" maxlength="5000">' . esc_textarea( (string) $value ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"' . ( $required ? ' required' : '' );
			foreach ( $attrs as $key => $val ) {
				echo ' ' . esc_attr( $key ) . '="' . esc_attr( (string) $val ) . '"';
			}
			echo '>';
		}
		echo '</label>';
	}

	private static function table( $headers, $filters = array() ) {
		echo '<div class="mrsa-table-region"><div class="mrsa-table-tools"><label>Filter loaded rows <input type="search" class="mrsa-search"></label><label>Rows per page <select class="mrsa-page-size"><option>25</option><option>50</option><option>100</option></select></label></div><div class="mrsa-scroll"><table class="widefat striped mrsa-table"><thead><tr>';
		foreach ( $headers as $i => $header ) {
			echo '<th scope="col"' . ( in_array( $i, $filters, true ) ? ' data-filter="true"' : '' ) . '>' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';
	}

	private static function end_table() {
		echo '</tbody></table></div><div class="mrsa-pagination" aria-live="polite"></div></div>';
	}

	private static function events( $rider_id = 0 ) {
		return self::queries()->events( $rider_id, MRSA::site_access(), get_current_user_id() );
	}

	private static function scoring_events() {
		return self::queries()->scoring_events( MRSA::site_access(), get_current_user_id() );
	}

	private static function users_select( $selected = 0 ) {
		echo '<label class="mrsa-field"><span>Username (all WordPress users)</span><input type="search" class="mrsa-user-filter" placeholder="Filter usernames" aria-label="Filter usernames"><select name="user_id" class="mrsa-user-select" required><option value="">Choose a user</option>';
		foreach ( get_users( array( 'orderby' => 'login', 'order' => 'ASC' ) ) as $user ) {
			echo '<option value="' . esc_attr( (string) $user->ID ) . '" data-name="' . esc_attr( $user->display_name ) . '" data-email="' . esc_attr( $user->user_email ) . '" data-phone="' . esc_attr( MRSA::user_phone( $user->ID ) ) . '"' . selected( $selected, $user->ID, false ) . '>' . esc_html( $user->user_login ) . '</option>';
		}
		echo '</select></label><table class="widefat mrsa-profile"><thead><tr><th>Display name (read-only)</th><th>Email (read-only)</th><th>User phone (read-only)</th></tr></thead><tbody><tr><td data-profile="name">—</td><td data-profile="email">—</td><td data-profile="phone">—</td></tr></tbody></table>';
	}

	private static function event_select( $events, $selected = 0, $name = 'event_id' ) {
		echo '<label class="mrsa-field"><span>Event</span><select name="' . esc_attr( $name ) . '" required><option value="">Choose an event</option>';
		foreach ( $events as $event ) {
			echo '<option value="' . esc_attr( $event['id'] ) . '"' . selected( $selected, $event['id'], false ) . '>' . esc_html( $event['name'] . ' (#' . $event['id'] . ')' ) . '</option>';
		}
		echo '</select></label>';
	}

	public static function render() {
		if ( ! MRSA::access() ) {
			wp_die( esc_html( 'You are not assigned to rally administration.' ), '', array( 'response' => 403 ) );
		}
		$site_admin = MRSA::site_access();
		$scorer_only = ! $site_admin && ! MRSA::event_ids();
		$tab = MRSA_Admin::query( 'tab', 'events' );
		$tabs = array( 'riders' => 'Riders', 'events' => 'Events', 'rosters' => 'Rosters', 'scoring' => 'Scoring', 'results' => 'Results', 'bikes' => 'Bikes', 'diagnostics' => 'Diagnostics', 'about' => 'About' );
		if ( $scorer_only && 'events' === $tab ) {
			$tab = 'scoring';
		}
		if ( ! isset( $tabs[ $tab ] ) || ( ! $site_admin && in_array( $tab, array( 'bikes', 'diagnostics' ), true ) ) || ( $scorer_only && ! in_array( $tab, array( 'scoring', 'about' ), true ) ) ) {
			wp_die( esc_html( 'This shared-data area is restricted to WordPress administrators.' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<nav class="nav-tab-wrapper" aria-label="Rally Scoring tabs">';
		foreach ( $tabs as $key => $label ) {
			if ( ( ! $site_admin && in_array( $key, array( 'bikes', 'diagnostics' ), true ) ) || ( $scorer_only && ! in_array( $key, array( 'scoring', 'about' ), true ) ) ) {
				continue;
			}
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( MRSA_Admin::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		self::notice();
		if ( $scorer_only ) {
			echo '<p class="mrsa-note">Event Scorer access: review submissions and view scoring data only for your assigned events.</p>';
		} elseif ( ! $site_admin ) {
			echo '<p class="mrsa-note">Event administrator access: your assigned events and their enrolled rider details only. Shared rider records, motorcycles, event creation, diagnostics, and role assignments require a WordPress administrator.</p>';
		}
		switch ( $tab ) {
			case 'bikes':
				self::bike_setup();
				break;
			case 'riders':
				self::rider_setup();
				break;
			case 'events':
				self::event_setup();
				break;
			case 'rosters':
				self::event_rosters();
				break;
			case 'scoring':
				self::scoring_setup();
				break;
			case 'results':
				self::overall_results();
				break;
			case 'diagnostics':
				self::diagnostics();
				break;
			case 'about':
				self::about();
		}
		echo '</div>';
	}

	private static function list_page( $tab, array $data ) {
		$page = $data['page'];
		$count = $data['count'];
		$rows = $data['rows'];
		echo '<form method="get" class="mrsa-server-search"><input type="hidden" name="page" value="mrsa"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><label>Search all records <input type="search" name="q" value="' . esc_attr( MRSA_Admin::query( 'q' ) ) . '" maxlength="100"></label> <button class="button">Search</button></form>';
		echo '<p>' . esc_html( $count . ' matching records; server page ' . $page . ' of ' . max( 1, (int) ceil( $count / 100 ) ) ) . '. Local filters and sorting apply to this page (up to 100 records). ';
		if ( $page > 1 ) {
			self::link( 'Previous page', $tab, array( 'q' => MRSA_Admin::query( 'q' ), 'list_page' => $page - 1 ) );
			echo ' ';
		}
		if ( $page * 100 < $count ) {
			self::link( 'Next page', $tab, array( 'q' => MRSA_Admin::query( 'q' ), 'list_page' => $page + 1 ) );
		}
		echo '</p>';
		return $rows;
	}

	private static function bike_setup() {
		echo '<h2>Motorcycle catalog</h2>';
		$id = absint( MRSA_Admin::query( 'bike_id', '0' ) );
		$bike = $id ? MRSA::row( 'bikes', $id ) : array();
		if ( $id && ! $bike ) {
			wp_die( esc_html( 'Motorcycle not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="mrsa-bike-entry">';
		self::form( 'bike', array( 'id' => $id ), 'mrsa-panel' );
		echo '<h3>' . ( $id ? 'Edit motorcycle #' . esc_html( $id ) : 'Manual motorcycle entry' ) . '</h3><div class="mrsa-grid">';
		self::field( 'Make', 'make', $bike['make'] ?? '', 'text', true, array( 'maxlength' => 80 ) );
		self::field( 'Model', 'model', $bike['model'] ?? '', 'text', true, array( 'maxlength' => 120 ) );
		echo '</div>';
		self::button( $id ? 'Save motorcycle' : 'Add motorcycle' );
		echo '</form>';
		self::form( 'source_import', array(), 'mrsa-panel' );
		echo '<div class="mrsa-bike-import-heading"><h3>Import motorcycle models for a make</h3><details class="mrsa-url-help"><summary aria-label="Motorcycle import information">Help?</summary><div class="mrsa-url-help-content"><p>Source: <a href="' . esc_url( MRSA_Importer::SOURCE ) . '" target="_blank" rel="noopener noreferrer">NHTSA Product Information Catalog and Vehicle Listing (vPIC) API<span class="screen-reader-text"> (opens in a new tab)</span></a>. Enter one make. Make/model records from Get Models for Make are stored only when the motorcycle-filtered NHTSA response confirms the same model ID. No year is imported. No request is made on activation or page load.</p><p>Two HTTPS requests, each with a 256 KB response limit and no redirects. Unavailable, malformed, unconfirmed, and non-motorcycle results are rejected. Manual motorcycle entry remains available.</p></div></details></div><div class="mrsa-bike-import-controls">';
		self::field( 'Make', 'import_make', '', 'text', true, array( 'maxlength' => 80, 'placeholder' => 'Honda' ) );
		self::button( 'Import motorcycle models' );
		echo '</div><label><input type="checkbox" name="source_consent" value="1" required> I understand that this sends the entered make to NHTSA and imports only models independently confirmed as Motorcycles.</label>';
		echo '</form></div>';
		$rows = self::list_page( 'bikes', self::queries()->bike_page( MRSA_Admin::query( 'q' ), MRSA_Admin::query( 'list_page', '1' ) ) );
		echo '<p><label><input type="checkbox" class="mrsa-select-all-bikes"> Select all motorcycle records loaded on this server page</label></p>';
		self::table( array( 'Select', 'ID', 'Make', 'Model', 'Source', 'Actions' ), array( 2 ) );
		foreach ( $rows as $row ) {
			echo '<tr><td><input type="checkbox" name="bike_ids[]" value="' . esc_attr( $row['id'] ) . '" form="mrsa-bulk-delete-bikes" aria-label="Select motorcycle catalog record ' . esc_attr( $row['id'] ) . '"></td>';
			foreach ( array( 'id', 'make', 'model', 'source' ) as $key ) {
				echo '<td>';
				self::h( $row[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			self::link( 'Edit', 'bikes', array( 'bike_id' => $row['id'] ) );
			self::form( 'delete_bike', array( 'id' => $row['id'] ), 'mrsa-inline' );
			echo '<button class="button" data-confirm="Remove this catalog entry? Entries used by riders cannot be removed.">Delete</button></form></td></tr>';
		}
		self::end_table();
		self::form( 'delete_bikes', array(), 'mrsa-panel', 'mrsa-bulk-delete-bikes' );
		echo '<p>Select multiple catalog rows above, then delete them together. If any selected motorcycle is assigned to a rider, none are deleted.</p><button type="submit" class="button" data-confirm="Delete all selected motorcycle catalog records?">Delete selected motorcycles</button></form>';
	}

	private static function rider_setup() {
		echo '<h2>Registered riders</h2>';
		$id = absint( MRSA_Admin::query( 'rider_id', '0' ) );
		$site_admin = MRSA::site_access();
		if ( ! $site_admin && $id ) {
			$visible = self::queries()->rider_visible( $id, get_current_user_id() );
			if ( ! $visible ) {
				wp_die( esc_html( 'Rider not registered in your assigned events.' ), '', array( 'response' => 403 ) );
			}
		}
		$rider = $id ? MRSA::row( 'riders', $id ) : array();
		if ( $id && ! $rider ) {
			wp_die( esc_html( 'Rider not found.' ), '', array( 'response' => 404 ) );
		}
		if ( $site_admin ) {
			echo '<p><a class="button button-primary" href="' . esc_url( MRSA_Admin::url( 'rider-edit' ) ) . '">Add Rider</a></p>';
		} elseif ( $id ) {
			self::table( array( 'Rider ID', 'Name', 'Alias', 'Email', 'Phone' ) );
			echo '<tr>';
			foreach ( array( 'id', 'name', 'alias', 'email', 'phone' ) as $key ) {
				echo '<td>';
				self::h( $rider[ $key ] );
				echo '</td>';
			}
			echo '</tr>';
			self::end_table();
		}
		if ( $id && ! $site_admin ) {
			self::rider_events( $id );
		}
		$rows = self::list_page( 'riders', self::queries()->rider_page( MRSA_Admin::query( 'q' ), MRSA_Admin::query( 'list_page', '1' ), $site_admin, get_current_user_id() ) );
		self::table( array( 'Rider ID', 'Name', 'Alias', 'Email', 'Phone', 'Actions' ) );
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( 'id', 'name', 'alias', 'email', 'phone' ) as $key ) {
				echo '<td>';
				self::h( $row[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			if ( $site_admin ) {
				self::link( 'Edit', 'rider-edit', array( 'rider_id' => $row['id'] ) );
				echo ' | ';
			} else {
				echo 'Read only | ';
			}
			echo '<a href="' . esc_url( MRSA_Admin::url( 'rosters', array( 'rider_id' => $row['id'] ) ) ) . '">Events</a> | ';
			echo '<a href="' . esc_url( MRSA_Admin::url( 'results', array( 'rider_id' => $row['id'] ) ) ) . '">Results</a>';
			echo '</td></tr>';
		}
		self::end_table();
	}

	private static function rider_form( $id, $rider ) {
		self::form( 'rider', array( 'id' => $id ), 'mrsa-panel mrsa-edit-form' );
		echo '<h2>' . ( $id ? 'Edit rider #' . esc_html( $id ) : 'Add Rider' ) . '</h2>';
		foreach ( array( 'name' => array( 'Name', 160 ), 'alias' => array( 'Alias', 100 ), 'email' => array( 'Email (unique)', 254 ), 'phone' => array( 'Phone', 60 ), 'emergency_name' => array( 'Emergency contact name', 160 ), 'emergency_email' => array( 'Emergency contact email', 254 ), 'emergency_phone' => array( 'Emergency contact phone', 60 ) ) as $key => $config ) {
			self::field( $config[0], $key, $rider[ $key ] ?? '', false !== strpos( $key, 'email' ) ? 'email' : ( false !== strpos( $key, 'phone' ) ? 'tel' : 'text' ), in_array( $key, array( 'name', 'email' ), true ), array( 'maxlength' => $config[1] ) );
		}
		self::field( 'Insurance information (multiline)', 'insurance', $rider['insurance'] ?? '', 'textarea' );
		self::field( 'Other information (do not store unnecessary sensitive data)', 'other', $rider['other'] ?? '', 'textarea' );
		echo '<h4>Motorcycles and fuel capacity</h4><p>Choose a catalog make and model, then optionally enter the rider&rsquo;s four-digit model year. The year is not validated against the catalog. Fuel capacity is optional text; include your preferred units. Multiple motorcycles allowed (up to 20). Without JavaScript, use the full catalog dropdown.</p>';
		$motorcycles = self::queries()->rider_bikes( $id );
		$bikes = $motorcycles['catalog'];
		$owned = $motorcycles['owned'];
		if ( ! $owned ) {
			$owned[] = array( 'bike_id' => '', 'model_year' => '', 'fuel_capacity' => '' );
		}
		echo '<div class="mrsa-bike-rows">';
		foreach ( $owned as $row ) {
			self::bike_row( $bikes, $row );
		}
		echo '</div><template id="mrsa-bike-template">';
		self::bike_row( $bikes, array( 'bike_id' => '', 'model_year' => '', 'fuel_capacity' => '' ) );
		echo '</template><button type="button" class="button mrsa-add-bike">Add motorcycle</button><p>';
		self::button( $id ? 'Save rider' : 'Add Rider' );
		echo '</p></form>';
	}

	public static function render_rider_edit() {
		if ( ! MRSA::access() ) {
			wp_die( esc_html( 'You are not authorized to view rider records.' ), '', array( 'response' => 403 ) );
		}
		$site_admin = MRSA::site_access();
		$id = absint( MRSA_Admin::query( 'rider_id', '0' ) );
		$rider = $id ? MRSA::row( 'riders', $id ) : array();
		if ( $id && ! $rider ) {
			wp_die( esc_html( 'Rider not found.' ), '', array( 'response' => 404 ) );
		}
		if ( ! $site_admin ) {
			$visible = $id ? self::queries()->rider_visible( $id, get_current_user_id() ) : false;
			if ( ! $visible ) {
				wp_die( esc_html( 'Rider not registered in your assigned events.' ), '', array( 'response' => 403 ) );
			}
		}
		echo '<div class="wrap mrsa">';
		self::header();
		$return_url = MRSA_Admin::query( 'return_url' );
		$back_url = $return_url ? wp_validate_redirect( $return_url, MRSA_Admin::url( 'riders' ) ) : MRSA_Admin::url( 'riders' );
		echo '<h2>' . esc_html( $site_admin ? ( $id ? 'Edit Rider' : 'Add Rider' ) : 'Rider Details' ) . '</h2><p><a class="button" href="' . esc_url( $back_url ) . '">' . esc_html( $return_url ? 'Back to Event Roster' : 'Back to Riders' ) . '</a></p>';
		self::notice();
		if ( $site_admin ) {
			self::rider_form( $id, $rider );
		} else {
			self::table( array( 'Rider ID', 'Name', 'Alias', 'Email', 'Phone' ) );
			echo '<tr>';
			foreach ( array( 'id', 'name', 'alias', 'email', 'phone' ) as $key ) {
				echo '<td>';
				self::h( $rider[ $key ] );
				echo '</td>';
			}
			echo '</tr>';
			self::end_table();
		}
		if ( $id ) {
			self::rider_events( $id );
		}
		echo '</div>';
	}

	private static function bike_row( $bikes, $row ) {
		echo '<div class="mrsa-bike-row"><div class="mrsa-dependent" hidden><label>Make <select class="mrsa-make" aria-label="Motorcycle make"></select></label><label>Model <select class="mrsa-model" aria-label="Motorcycle model"></select></label></div><label class="mrsa-catalog-label">Catalog motorcycle <select name="bike_ids[]" class="mrsa-catalog"><option value="">No motorcycle</option>';
		foreach ( $bikes as $bike ) {
			echo '<option value="' . esc_attr( $bike['id'] ) . '" data-make="' . esc_attr( $bike['make'] ) . '" data-model="' . esc_attr( $bike['model'] ) . '"' . selected( $row['bike_id'], $bike['id'], false ) . '>' . esc_html( $bike['make'] . ' ' . $bike['model'] ) . '</option>';
		}
		echo '</select></label>';
		self::field( 'Year (optional)', 'model_year[]', (int) ( $row['model_year'] ?? 0 ) ?: '', 'number', false, array( 'min' => 1000, 'max' => 9999, 'step' => 1, 'placeholder' => 'YYYY', 'inputmode' => 'numeric' ) );
		self::field( 'Fuel capacity', 'fuel[]', $row['fuel_capacity'], 'text', false, array( 'maxlength' => 100, 'placeholder' => 'e.g. 5 US gal or 20 L' ) );
		echo '<button type="button" class="button mrsa-remove-bike">Remove motorcycle</button></div>';
	}

	private static function rider_events( $id ) {
		echo '<h3>Registered events (read-only)</h3><p>Result status is automatic unless a manual status was previously recorded. Status is read-only on Results.</p>';
		$rows = self::queries()->rider_events( $id, MRSA::site_access(), get_current_user_id() );
		self::table( array( 'Event ID', 'Event', 'Bib / scoring context', 'Pillion', 'Status', 'Score', 'Overall Placing' ), array( 4 ) );
		foreach ( $rows as $row ) {
			echo '<tr><td>';
			self::h( $row['event_id'] );
			echo '</td><td>';
			self::h( $row['name'] );
			echo '</td><td>';
			self::link( $row['bib'], 'scoring', array( 'event_id' => $row['event_id'], 'score_rider' => $id, 'score_tab' => 'pending' ) );
			echo '</td><td>';
			self::h( $row['pillion'] );
			echo '</td><td>';
			self::h( $row['effective_status'] );
			echo '</td><td>';
			self::h( $row['score'] );
			echo '</td><td>';
			self::h( null === $row['placing'] ? '—' : $row['placing'] );
			echo '</td></tr>';
		}
		self::end_table();
	}

	private static function event_setup() {
		$events = self::events();
		echo '<h2>Events</h2>';
		if ( MRSA::site_access() ) {
			echo '<p><a class="button button-primary" href="' . esc_url( MRSA_Admin::url( 'event-edit' ) ) . '">Add Event</a></p>';
		}
		self::table( array( 'ID', 'Name', 'Date', 'Location', 'Max bib', 'Actions' ), array( 2, 3 ) );
		foreach ( $events as $event ) {
			echo '<tr>';
			foreach ( array( 'id', 'name', 'event_date', 'location', 'max_bib' ) as $key ) {
				echo '<td>';
				self::h( $event[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			self::link( 'Configure', 'event-edit', array( 'event_id' => $event['id'] ) );
			echo ' | ';
			self::link( 'Roster', 'rosters', array( 'event_id' => $event['id'] ) );
			echo ' | ';
			self::link( 'Scoring', 'scoring', array( 'event_id' => $event['id'] ) );
			echo '</td></tr>';
		}
		self::end_table();
	}

	private static function event_administrators( $event ) {
		echo '<h2>Event Administrator Assignments</h2><p>Assigned administrators may configure only their events and use their event rosters and scoring tools. WordPress administrators manage assignments, shared rider records, and enrollment.</p>';
		echo '<details class="mrsa-panel"><summary class="button button-primary">Add Administrator</summary>';
		self::form( 'event_admin', array( 'event_id' => $event['id'] ) );
		self::users_select();
		self::button( 'Add Administrator' );
		echo '</form></details>';
		self::table( array( 'Username', 'Display name', 'Email', 'User phone', 'Actions' ) );
		$rows = self::queries()->event_administrators( $event['id'] );
		foreach ( $rows as $row ) {
			$user = get_user_by( 'id', $row['user_id'] );
			echo '<tr><td>';
			self::h( $user ? $user->user_login : 'Deleted user #' . $row['user_id'] );
			echo '</td><td>';
			self::h( $user ? $user->display_name : '—' );
			echo '</td><td>';
			self::h( $user ? $user->user_email : '—' );
			echo '</td><td>';
			self::h( $user ? MRSA::user_phone( $user->ID ) : '—' );
			echo '</td><td>';
			self::form( 'remove_event_admin', array( 'user_id' => $row['user_id'], 'event_id' => $row['event_id'] ) );
			self::button( 'Remove', 'remove_event_admin' );
			echo '</form></td></tr>';
		}
		self::end_table();
	}

	private static function event_scorers( $event ) {
		echo '<h2>Event Scorer Assignments</h2><p>Scorers can review and score email submissions for this event. They cannot configure events, manage rosters, or access shared rider and motorcycle data.</p>';
		echo '<details class="mrsa-panel"><summary class="button button-primary">Add Scorer</summary>';
		self::form( 'event_scorer', array( 'event_id' => $event['id'] ) );
		self::users_select();
		self::button( 'Add Scorer' );
		echo '</form></details>';
		self::table( array( 'Username', 'Display name', 'Email', 'User phone', 'Actions' ) );
		foreach ( self::queries()->event_scorers( $event['id'] ) as $row ) {
			$user = get_user_by( 'id', $row['user_id'] );
			echo '<tr><td>';
			self::h( $user ? $user->user_login : 'Deleted user #' . $row['user_id'] );
			echo '</td><td>';
			self::h( $user ? $user->display_name : '—' );
			echo '</td><td>';
			self::h( $user ? $user->user_email : '—' );
			echo '</td><td>';
			self::h( $user ? MRSA::user_phone( $user->ID ) : '—' );
			echo '</td><td>';
			self::form( 'remove_event_scorer', array( 'user_id' => $row['user_id'], 'event_id' => $row['event_id'] ) );
			self::button( 'Remove', 'remove_event_scorer' );
			echo '</form></td></tr>';
		}
		self::end_table();
	}

	private static function event_form( $id, $event ) {
		self::form( 'event', array( 'id' => $id ), 'mrsa-panel mrsa-edit-form' );
		echo '<h2>' . ( $id ? 'Configure event #' . esc_html( $id ) : 'Add Event' ) . '</h2><p>Dates/times use the WordPress site timezone: ' . esc_html( wp_timezone_string() ) . '. Registration open ≤ close ≤ start ≤ end. The event date is derived from its start time. Prices use your event’s currency; no payments are processed.</p>';
		self::field( 'Event name', 'name', $event['name'] ?? '', 'text', true, array( 'maxlength' => 160 ) );
		self::field( 'Maximum bib (1–10000)', 'max_bib', $event['max_bib'] ?? 100, 'number', true, array( 'min' => 1, 'max' => 10000, 'step' => 1 ) );
		foreach ( array( 'registration_open' => 'Registration opens', 'registration_close' => 'Registration closes', 'start_at' => 'Event starts', 'end_at' => 'Event ends' ) as $key => $label ) {
			$value = empty( $event[ $key ] ) ? '' : str_replace( ' ', 'T', substr( $event[ $key ], 0, 16 ) );
			self::field( $label, $key, $value, 'datetime-local', true );
		}
		foreach ( array( 'rider_price' => 'Rider price', 'pillion_price' => 'Pillion price' ) as $key => $label ) {
			self::field( $label, $key, $event[ $key ] ?? 0, 'number', true, array( 'min' => 0, 'max' => '99999999.99', 'step' => '0.01' ) );
		}
		self::field( 'Location', 'location', $event['location'] ?? '', 'text', false, array( 'maxlength' => 200 ) );
		self::field( 'Latitude', 'latitude', $event['latitude'] ?? '', 'number', false, array( 'min' => -90, 'max' => 90, 'step' => '0.0000001' ) );
		self::field( 'Longitude', 'longitude', $event['longitude'] ?? '', 'number', false, array( 'min' => -180, 'max' => 180, 'step' => '0.0000001' ) );
		self::field( 'Minimum finisher points (configuration only)', 'min_finisher_points', $event['min_finisher_points'] ?? 0, 'number', true, array( 'min' => 0, 'max' => 1000000000, 'step' => 1 ) );
		echo '<div class="mrsa-logo">';
		self::field( 'Existing media image ID (0 = none)', 'logo_id', $event['logo_id'] ?? 0, 'number', false, array( 'min' => 0, 'step' => 1 ) );
		echo '<button type="button" class="button mrsa-media">Select logo in WordPress media library</button><button type="button" class="button mrsa-clear-media">Clear logo</button><div class="mrsa-logo-preview">';
		if ( ! empty( $event['logo_id'] ) ) {
			echo wp_get_attachment_image( (int) $event['logo_id'], 'thumbnail' );
		}
		echo '</div></div>';
		if ( ! current_user_can( 'upload_files' ) ) {
			echo '<p>You can select an existing image by ID. Media uploads require the WordPress upload_files capability; this plugin does not grant it.</p>';
		}
		self::button( $id ? 'Save event configuration' : 'Add Event' );
		echo '</form>';
	}

	public static function render_event_edit() {
		if ( ! MRSA::access() ) {
			wp_die( esc_html( 'You are not assigned to rally administration.' ), '', array( 'response' => 403 ) );
		}
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		if ( ( ! $id && ! MRSA::site_access() ) || ( $id && ! MRSA::event_access( $id ) ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		$event = $id ? MRSA::row( 'events', $id ) : array();
		if ( $id && ! $event ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>' . esc_html( $id ? 'Edit Event' : 'Add Event' ) . '</h2><p><a class="button" href="' . esc_url( MRSA_Admin::url( 'events' ) ) . '">Back to Events</a></p>';
		self::notice();
		self::event_form( $id, $event );
		if ( $id ) {
			echo '<section class="mrsa-panel"><h2>Email Bonus Submissions</h2><p>Configure the event inbox used for rider bonus submissions.</p><p><a class="button button-primary" href="' . esc_url( MRSA_Admin::url( 'event-email', array( 'event_id' => $id ) ) ) . '">Configure Submission Inbox</a></p></section>';
		}
		if ( $id && MRSA::site_access() ) {
			self::event_administrators( $event );
			self::event_scorers( $event );
		}
		echo '</div>';
	}

	public static function render_event_email() {
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		if ( ! $id || ! MRSA::event_access( $id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		$event = MRSA::row( 'events', $id );
		if ( ! $event ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		$config = MRSA_Email_Submissions::configuration( $id );
		$settings = $config['settings'];
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>Email Bonus Submissions — ' . esc_html( $event['name'] ) . '</h2><p><a class="button" href="' . esc_url( MRSA_Admin::url( 'event-edit', array( 'event_id' => $id ) ) ) . '">Back to Event Configuration</a></p>';
		self::notice();
		echo '<section class="mrsa-panel"><p>Each accepted inbox message must map to one registered rider by sender email, contain exactly one Bonus Point Library ID in the subject, and include exactly one JPEG, PNG, GIF, or WebP attachment. Valid images require the configured ClamAV scanner.</p>';
		self::form( 'event_email', array( 'event_id' => $id ), 'mrsa-edit-form mrsa-email-config' );
		echo '<label class="mrsa-field"><span>Provider</span><select name="provider" required>';
		foreach ( array( 'imap' => 'IMAP', 'gmail' => 'Gmail API', 'microsoft' => 'Microsoft 365 / Graph API' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $config['provider'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><label><input type="checkbox" name="enabled" value="1"' . checked( 1, $config['enabled'], false ) . '> Enable scheduled inbox polling every five minutes</label>';
		self::field( 'Submission mailbox email', 'mailbox', $settings['mailbox'] ?? '', 'email', true, array( 'maxlength' => 254 ) );
		echo '<h3>IMAP settings</h3>';
		self::field( 'IMAP host', 'host', $settings['host'] ?? '', 'text', false, array( 'maxlength' => 255 ) );
		self::field( 'IMAP port', 'port', $settings['port'] ?? 993, 'number', false, array( 'min' => 1, 'max' => 65535 ) );
		echo '<label class="mrsa-field"><span>IMAP encryption</span><select name="encryption"><option value="ssl"' . selected( $settings['encryption'] ?? 'ssl', 'ssl', false ) . '>SSL</option><option value="tls"' . selected( $settings['encryption'] ?? '', 'tls', false ) . '>TLS</option><option value="none"' . selected( $settings['encryption'] ?? '', 'none', false ) . '>None</option></select></label>';
		self::field( 'IMAP username', 'username', $settings['username'] ?? '', 'text', false, array( 'maxlength' => 254 ) );
		self::field( 'IMAP password (leave blank to preserve)', 'password', '', 'password', false, array( 'autocomplete' => 'new-password', 'maxlength' => 4096 ) );
		self::field( 'Mailbox folder (IMAP or Microsoft)', 'folder', $settings['folder'] ?? ( 'imap' === $config['provider'] ? 'INBOX' : 'inbox' ), 'text', false, array( 'maxlength' => 120 ) );
		echo '<h3>OAuth API settings (Gmail or Microsoft 365)</h3>';
		echo '<div class="mrsa-email-provider-help" data-email-provider-only="gmail"' . ( 'gmail' === $config['provider'] ? '' : ' hidden' ) . '><span>Gmail API project setup</span><details class="mrsa-url-help"><summary aria-label="Gmail API project setup help">Help?</summary><div class="mrsa-url-help-content"><h4>Steps to Set Up a Google Cloud Project for Gmail API</h4><h5>1. Create a Google Cloud Project</h5><ol><li>Open the <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">Google Cloud Console<span class="screen-reader-text"> (opens in a new tab)</span></a>.</li><li>Choose <strong>IAM &amp; Admin &gt; Create a Project</strong>.</li><li>Enter a descriptive project name. You may edit the project ID before creation; it cannot be changed later.</li><li>Select <strong>Create</strong>.</li></ol><h5>2. Enable Billing (if necessary)</h5><ol><li>Open <strong>Billing</strong> in Google Cloud Console.</li><li>Select the new project.</li><li>If Google requires billing for the account or enabled services, link a billing account.</li></ol><h5>3. Enable the Gmail API</h5><ol><li>Open <strong>APIs &amp; Services &gt; Library</strong>.</li><li>Search for <strong>Gmail API</strong>.</li><li>Open Gmail API and select <strong>Enable</strong>.</li></ol><h5>4. Create Credentials</h5><ol><li>Open <strong>APIs &amp; Services &gt; OAuth consent screen</strong> and configure the application and test/authorized users.</li><li>Open <strong>APIs &amp; Services &gt; Credentials</strong>.</li><li>Select <strong>Create Credentials &gt; OAuth client ID</strong>. An API key alone cannot read a Gmail inbox.</li><li>Authorize the mailbox with the <code>https://www.googleapis.com/auth/gmail.modify</code> scope and obtain a refresh token.</li><li>Enter the OAuth client ID, client secret, and refresh token below.</li></ol><h5>5. Use the Gmail API</h5><p>Save the event inbox configuration, enable polling, and use <strong>Check Inbox Now</strong>. The configured Google account must own or have authorized access to the submission mailbox.</p></div></details></div>';
		self::field( 'OAuth client ID', 'client_id', $settings['client_id'] ?? '', 'text', false, array( 'maxlength' => 255 ) );
		self::field( 'OAuth client secret (leave blank to preserve)', 'client_secret', '', 'password', false, array( 'autocomplete' => 'new-password', 'maxlength' => 4096 ) );
		self::field( 'OAuth refresh token (leave blank to preserve)', 'refresh_token', '', 'password', false, array( 'autocomplete' => 'new-password', 'maxlength' => 4096 ) );
		self::field( 'Microsoft tenant ID (Microsoft only)', 'tenant_id', $settings['tenant_id'] ?? '', 'text', false, array( 'maxlength' => 100 ) );
		self::button( 'Save inbox configuration' );
		echo '</form>';
		echo '<p><strong>Last checked:</strong> ' . esc_html( $config['last_checked_at'] ?: 'Never' ) . '</p>';
		if ( $config['last_error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $config['last_error'] ) . '</p></div>';
		}
		if ( $config['enabled'] ) {
			self::form( 'event_email_check', array( 'event_id' => $id ) );
			self::button( 'Check Inbox Now' );
			echo '</form>';
		}
		echo '</section></div>';
	}

	public static function render_event_roster() {
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		wp_safe_redirect( MRSA_Admin::url( 'rosters', $id ? array( 'event_id' => $id ) : array() ) );
		exit;
	}

	private static function event_rosters() {
		$rider_id = absint( MRSA_Admin::query( 'rider_id', '0' ) );
		$events = self::events( $rider_id );
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		$allowed_ids = array_map( 'intval', wp_list_pluck( $events, 'id' ) );
		if ( $id && ( ! MRSA::event_access( $id ) || ! in_array( $id, $allowed_ids, true ) ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="mrsa-roster-toolbar"><div class="mrsa-roster-toolbar__event">';
		if ( $events ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="mrsa-roster-event-select"><input type="hidden" name="page" value="mrsa"><input type="hidden" name="tab" value="rosters">';
			if ( $rider_id ) {
				echo '<input type="hidden" name="rider_id" value="' . esc_attr( $rider_id ) . '">';
			}
			self::event_select( $events, $id );
			echo '<button class="button">Choose event</button></form>';
		}
		echo '</div><div class="mrsa-roster-toolbar__actions"><h2>Event Roster</h2>';
		if ( $id && MRSA::site_access() ) {
			echo '<a class="button button-primary" href="' . esc_url( MRSA_Admin::url( 'event-enroll', array( 'event_id' => $id ) ) ) . '">Add Rider</a>';
			echo '<a class="button" href="' . esc_url( MRSA_Admin::url( 'roster-import', array( 'event_id' => $id ) ) ) . '">Import Riders</a>';
		}
		echo '</div></div>';
		if ( $rider_id ) {
			$rider = MRSA::row( 'riders', $rider_id );
			echo '<p>Showing only accessible events containing <strong>' . esc_html( $rider ? $rider['name'] : 'the selected rider' ) . '</strong>.</p>';
		}
		$event = $id ? MRSA::row( 'events', $id ) : null;
		if ( $event ) {
			self::registrations( $event );
		} else {
			echo '<p>' . esc_html( $events ? 'Choose an event to display its registered riders.' : 'Create or be assigned to an event before viewing a roster.' ) . '</p>';
		}
	}

	public static function render_roster_import() {
		if ( ! MRSA::site_access() ) {
			wp_die( esc_html( 'Only WordPress administrators may import riders into events.' ), '', array( 'response' => 403 ) );
		}
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		if ( ! MRSA::event_access( $id ) || ! ( $event = MRSA::row( 'events', $id ) ) ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>Import Riders — ' . esc_html( $event['name'] ) . '</h2><p><a class="button" href="' . esc_url( MRSA_Admin::url( 'rosters', array( 'event_id' => $id ) ) ) . '">Back to Rosters</a></p>';
		self::notice();
		self::form( 'roster_import', array( 'event_id' => $id ), 'mrsa-panel mrsa-edit-form', '', 'multipart/form-data' );
		echo '<h3>Import Event Roster (CSV or XLSX)</h3><p>Enroll existing riders only. Supply Rider ID or Email; if both are supplied they must match. Optional columns: Bib ID, Status, Pillion. Blank bibs receive the next available number; blank status defaults to Initial Registration. Existing enrollments, duplicate riders or bibs, and invalid rows reject the entire batch.</p><p><a href="' . esc_url( plugins_url( 'samples/event-roster.csv', MRSA_FILE ) ) . '" download>Download sample roster CSV</a> (replace example identifiers with existing riders). Use the same column headings on the first worksheet of an XLSX file.</p><label class="mrsa-field"><span>Roster file (CSV or XLSX, maximum 10 MB / 5,000 rows)</span><input type="file" name="roster_file" accept=".csv,.xlsx" required></label><p>ClamAV must be available for roster imports. Files are scanned and checked for unsafe content; bonus-import scanning overrides do not apply.</p>';
		self::button( 'Import Event Roster' );
		echo '</form></div>';
	}

	private static function registrations( $event ) {
		$id = (int) $event['id'];
		echo '<p><strong>Event:</strong> ' . esc_html( $event['name'] ) . '. Administrative enrollment is available regardless of the configured registration window. Bibs are unique within this event.</p>';
		$rows = self::queries()->roster( $id );
		self::table( array( 'Rider ID', 'Bib ID', 'Status', 'Name', 'Email', 'Score', 'Overall Placing' ) );
		$roster_url = MRSA_Admin::url( 'rosters', array( 'event_id' => $id ) );
		foreach ( $rows as $row ) {
			echo '<tr><td>';
			self::link( $row['rider_id'], 'rider-edit', array( 'rider_id' => $row['rider_id'], 'return_url' => $roster_url ) );
			echo '</td><td>';
			self::link( $row['bib'], 'scoring', array( 'event_id' => $id, 'score_rider' => $row['rider_id'], 'score_tab' => 'pending' ) );
			echo '</td><td>';
			self::h( $row['registration_status'] );
			echo '</td><td>';
			self::h( $row['name'] );
			echo '</td><td>';
			self::h( $row['email'] );
			echo '</td><td>';
			self::h( $row['score'] );
			echo '</td><td>';
			self::h( $row['overall_placing'] );
			echo '</td></tr>';
		}
		self::end_table();
	}

	public static function render_event_enroll() {
		if ( ! MRSA::site_access() ) {
			wp_die( esc_html( 'Only WordPress administrators may add riders to events.' ), '', array( 'response' => 403 ) );
		}
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		if ( ! MRSA::event_access( $id ) || ! ( $event = MRSA::row( 'events', $id ) ) ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		$enrollment = self::queries()->enrollment( $id );
		$available = $enrollment['available'];
		$used = $enrollment['used'];
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>Add Rider to Event — ' . esc_html( $event['name'] ) . '</h2><p><a class="button" href="' . esc_url( MRSA_Admin::url( 'rosters', array( 'event_id' => $id ) ) ) . '">Back to Rosters</a></p>';
		if ( $available && count( $used ) < (int) $event['max_bib'] ) {
			self::form( 'registration', array( 'event_id' => $id ), 'mrsa-panel mrsa-edit-form' );
			echo '<label class="mrsa-field"><span>Rider not already associated with this event</span><select name="rider_id" required><option value="">Choose a rider</option>';
			foreach ( $available as $rider ) {
				echo '<option value="' . esc_attr( $rider['id'] ) . '">' . esc_html( $rider['name'] . ' — ' . $rider['email'] ) . '</option>';
			}
			echo '</select></label><label class="mrsa-field"><span>Available bib (1–' . esc_html( $event['max_bib'] ) . ')</span><select name="bib" required>';
			for ( $bib = 1; $bib <= (int) $event['max_bib']; ++$bib ) {
				if ( ! isset( $used[ $bib ] ) ) {
					echo '<option value="' . esc_attr( $bib ) . '">' . esc_html( $bib ) . '</option>';
				}
			}
			echo '</select></label>';
			self::field( 'Pillion (optional name/details)', 'pillion', '', 'text', false, array( 'maxlength' => 160 ) );
			self::button( 'Add Rider to Event' );
			echo '</form>';
			self::table( array( 'Rider ID', 'Name', 'Alias', 'Email', 'Phone' ) );
			foreach ( $available as $rider ) {
				echo '<tr>';
				foreach ( array( 'id', 'name', 'alias', 'email', 'phone' ) as $key ) {
					echo '<td>';
					self::h( $rider[ $key ] );
					echo '</td>';
				}
				echo '</tr>';
			}
			self::end_table();
		} elseif ( count( $used ) >= (int) $event['max_bib'] ) {
			echo '<p>Every bib is assigned. Increase the event maximum bib before adding another rider.</p>';
		} else {
			$return_url = MRSA_Admin::url( 'rosters', array( 'event_id' => $id ) );
			$add_url = MRSA_Admin::url( 'rider-edit', array( 'return_url' => $return_url, 'enroll_event_id' => $id ) );
			echo '<div class="notice notice-info inline"><p>No additional riders are available. Do you wish to add a new rider?</p><p><a class="button button-primary" href="' . esc_url( $add_url ) . '" data-confirm="No additional riders are available. Open the Add Rider form?">Add New Rider</a></p></div>';
		}
		echo '</div>';
	}

	public static function render_scoring() {
		$extra = array();
		foreach ( array( 'event_id', 'score_rider', 'score_tab' ) as $key ) {
			$value = MRSA_Admin::query( $key );
			if ( '' !== $value ) {
				$extra[ $key ] = $value;
			}
		}
		wp_safe_redirect( MRSA_Admin::url( 'scoring', $extra ) );
		exit;
	}

	private static function scoring_setup() {
		$events = self::scoring_events();
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		if ( $id && ! MRSA::scoring_access( $id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		echo '<h2>Scoring</h2>';
		if ( $events ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="mrsa-panel"><input type="hidden" name="page" value="mrsa"><input type="hidden" name="tab" value="scoring">';
			self::event_select( $events, $id );
			echo '<button class="button">Choose event</button></form>';
		}
		$event = $id ? MRSA::row( 'events', $id ) : null;
		if ( $event ) {
			if ( MRSA::event_access( $id ) ) {
				echo '<p><a class="button" href="' . esc_url( MRSA_Admin::url( 'rosters', array( 'event_id' => $id ) ) ) . '">Back to Event Roster</a></p>';
			}
			self::scoring( $event );
		} else {
			echo '<p>' . esc_html( $events ? 'Choose an event to display its scoring tabs.' : 'Create or be assigned to an event before opening scoring.' ) . '</p>';
		}
	}

	private static function scoring( $event ) {
		$can_configure = MRSA::event_access( $event['id'] );
		$tab = MRSA_Admin::query( 'score_tab', $can_configure ? 'library' : 'pending' );
		$tabs = array( 'library' => 'Bonus Point Library', 'pending' => 'Submissions - Pending Review', 'reviewed' => 'Submissions - Reviewed', 'contested' => 'Submissions - Contested' );
		if ( ! $can_configure ) {
			unset( $tabs['library'] );
		}
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = $can_configure ? 'library' : 'pending';
		}
		$rider_id = absint( MRSA_Admin::query( 'score_rider', '0' ) );
		echo '<section id="mrsa-scoring"><nav class="nav-tab-wrapper" aria-label="Scoring workspace">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( MRSA_Admin::url( 'scoring', array( 'event_id' => $event['id'], 'score_rider' => $rider_id, 'score_tab' => $key ) ) . '#mrsa-scoring' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><h3>Event Scoring Workspace — ' . esc_html( $event['name'] ) . '</h3>';
		if ( $rider_id ) {
			$row = self::queries()->scoring_rider( $event['id'], $rider_id );
			if ( ! $row ) {
				wp_die( esc_html( 'The selected rider is not registered in this event.' ), '', array( 'response' => 404 ) );
			}
			echo '<p class="mrsa-note">Filtered context: event ' . esc_html( $event['name'] ) . ' (#' . esc_html( $event['id'] ) . '), rider ' . esc_html( $row['name'] ) . ' (#' . esc_html( $row['id'] ) . '), bib ' . esc_html( $row['bib'] ) . ', status ' . esc_html( $row['status'] ) . '.</p>';
			echo '<p>';
			self::link( 'Clear rider filter', 'scoring', array( 'event_id' => $event['id'], 'score_tab' => $tab ) );
			echo '</p>';
		}
		echo '<h4>' . esc_html( $tabs[ $tab ] ) . ' — ' . esc_html( $event['name'] ) . '</h4>';
		if ( 'library' === $tab ) {
			self::bonus_library( $event );
			echo '</section>';
			return;
		}
		if ( 'pending' === $tab ) {
			self::pending_submissions( $event, $rider_id );
			echo '</section>';
			return;
		}
		if ( 'reviewed' === $tab ) {
			self::reviewed_submissions( $event, $rider_id );
			echo '</section>';
			return;
		}
		if ( 'contested' === $tab ) {
			echo '<p>No contested-submission workflow is configured. Reviewed decisions remain available in the Reviewed tab and rider submission history.</p></section>';
			return;
		}
		$rows = self::queries()->scoring( $event['id'], $event['min_finisher_points'] );
		self::table( array( 'Bib', 'Rider ID', 'Rider', 'Status', 'Score', 'Placing' ), array( 3 ) );
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( 'bib', 'id', 'name' ) as $key ) {
				echo '<td>';
				self::h( $row[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			self::h( $row['effective_status'] );
			echo '</td><td>';
			self::h( $row['score'] );
			echo '</td><td>';
			self::h( null === $row['placing'] ? '—' : $row['placing'] );
			echo '</td></tr>';
		}
		self::end_table();
		echo '</section>';
	}

	private static function pending_submissions( $event, $rider_id ) {
		$rows = self::queries()->pending_submissions( $event['id'], $rider_id );
		if ( ! $rows ) {
			echo '<p>No submissions are pending review.</p>';
			return;
		}
		foreach ( $rows as $row ) {
			$submission_url = MRSA_Email_Submissions::image_url( $event['id'], $row['image_file'] );
			$bonus_url = MRSA_Bonus::image_url( $event['id'], $row['bonus_image'] );
			echo '<article class="mrsa-panel mrsa-submission-review"><h5>Submission #' . esc_html( $row['id'] ) . ' — Bib ' . esc_html( $row['bib'] ) . ', ' . esc_html( $row['rider_name'] ) . '</h5><div class="mrsa-submission-comparison">';
			echo '<section><h6>Rider submission</h6><p><strong>Submitted ID:</strong> ' . esc_html( $row['bonus_id'] ) . '<br><strong>Email subject:</strong> ' . esc_html( $row['email_subject'] ) . '<br><strong>Description:</strong> ' . esc_html( $row['submission_description'] ?: '—' ) . '<br><strong>Received:</strong> ' . esc_html( $row['submitted_at'] ) . '</p>';
			if ( $submission_url ) {
				echo '<a href="' . esc_url( $submission_url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $submission_url ) . '" alt="Rider submission for bonus ' . esc_attr( $row['bonus_id'] ) . '"></a>';
			}
			$library_missing = null === $row['bonus_points'];
			echo '</section><section><h6>Bonus Point Library match</h6><p><strong>Library ID:</strong> ' . esc_html( $row['bonus_id'] ) . '<br><strong>Points:</strong> ' . esc_html( $library_missing ? 'Unavailable' : $row['bonus_points'] ) . '<br><strong>Description:</strong> ' . esc_html( $library_missing ? 'The matching library record was removed after intake.' : $row['bonus_description'] ) . '</p>';
			if ( $bonus_url ) {
				echo '<a href="' . esc_url( $bonus_url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $bonus_url ) . '" alt="Bonus Point Library image for ' . esc_attr( $row['bonus_id'] ) . '"></a>';
			}
			echo '</section></div>';
			self::form( 'submission_review', array( 'event_id' => $event['id'], 'submission_id' => $row['id'] ), 'mrsa-submission-decision' );
			echo '<label class="mrsa-field"><span>Rejection reason</span><select name="rejection_code"><option value="">Select when rejecting</option>';
			foreach ( array(
				'wrong_bonus_code' => 'Wrong bonus code',
				'wrong_image' => 'Wrong image',
				'wrong_location' => 'Wrong location',
				'bad_image' => 'Bad image',
				'missing_rally_flag' => 'Missing rally flag',
				'wrong_time_window' => 'Wrong time window',
				'other' => 'Other',
			) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></label>';
			self::field( 'Rejection description (required for Other)', 'rejection_description', '', 'textarea', false );
			echo '<p><button type="submit" class="button button-primary" name="decision" value="accepted"' . ( $library_missing ? ' disabled' : '' ) . '>Accept and Award ' . esc_html( $library_missing ? 'Unavailable' : $row['bonus_points'] ) . ' Points</button> <button type="submit" class="button" name="decision" value="rejected">Reject Submission</button></p></form></article>';
		}
	}

	private static function reviewed_submissions( $event, $rider_id ) {
		$rows = self::queries()->reviewed_submissions( $event['id'], $rider_id );
		if ( ! $rows ) {
			echo '<p>No submissions have been reviewed.</p>';
			return;
		}
		self::table( array( 'Submission', 'Received', 'Bib', 'Rider', 'Bonus ID', 'Decision', 'Points', 'Reason', 'Reviewer', 'Reviewed', 'Photo' ), array( 5 ) );
		foreach ( $rows as $row ) {
			$reviewer = $row['reviewed_by'] ? get_user_by( 'id', $row['reviewed_by'] ) : null;
			echo '<tr><td>' . esc_html( $row['id'] ) . '</td><td>' . esc_html( $row['submitted_at'] ) . '</td><td>' . esc_html( $row['bib'] ?: '—' ) . '</td><td>' . esc_html( $row['rider_name'] ?: 'Unmatched message' ) . '</td><td>' . esc_html( $row['bonus_id'] ?: '—' ) . '</td><td>' . esc_html( ucfirst( $row['status'] ) ) . '</td><td>' . esc_html( $row['points_awarded'] ) . '</td><td>' . esc_html( $row['rejection_description'] ?: str_replace( '_', ' ', $row['rejection_code'] ) ) . '</td><td>' . esc_html( $reviewer ? $reviewer->user_login : ( $row['reviewed_by'] ? 'Deleted user #' . $row['reviewed_by'] : 'Automated intake' ) ) . '</td><td>' . esc_html( $row['reviewed_at'] ?: '—' ) . '</td><td>';
			$image_url = MRSA_Email_Submissions::image_url( $event['id'], $row['image_file'] );
			if ( $image_url ) {
				echo '<a href="' . esc_url( $image_url ) . '" target="_blank" rel="noopener noreferrer">View photo</a>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		self::end_table();
	}

	private static function bonus_import_feedback( $event_id, $step ) {
		$feedback_id = MRSA_Admin::query( 'bonus_feedback' );
		$feedback = preg_match( '/^[A-Za-z0-9]{24}$/D', $feedback_id ) ? get_transient( 'mrsa_bonus_feedback_' . get_current_user_id() . '_' . $feedback_id ) : false;
		if ( $feedback && (int) $feedback['event_id'] === $event_id && $feedback['step'] === $step ) {
			$severity = in_array( $feedback['severity'], array( 'success', 'warning', 'error' ), true ) ? $feedback['severity'] : 'warning';
			echo '<div class="notice notice-' . esc_attr( $severity ) . ' inline"><p>' . esc_html( $feedback['message'] ) . '</p></div>';
		} elseif ( $feedback_id && ! $feedback && 'listing' === $step ) {
			echo '<div class="notice notice-warning inline"><p>The import feedback expired or is unavailable. Feedback remains available for 15 minutes.</p></div>';
		}
		if ( 'setup' === $step ) {
			self::clamav_feedback( $event_id );
			return;
		}
		$pending_token = MRSA_Admin::query( 'bonus_pending' );
		if ( $pending_token ) {
			try {
				$pending = MRSA_Bonus::pending( $event_id, $pending_token );
			} catch ( RuntimeException $exception ) {
				$pending = null;
				if ( ( $feedback['step'] ?? 'listing' ) === $step ) {
					echo '<div class="notice notice-warning inline"><p>' . esc_html( $exception->getMessage() ) . '</p></div>';
				}
			}
			if ( $pending && ( $pending['import_step'] ?? 'images' ) === $step ) {
				echo '<section class="mrsa-panel"><h3>Review security scan results</h3><p>No listings or images have been imported yet. This review expires after 15 minutes. A passed scan means no threat was detected, not a guarantee that a file is harmless.</p>';
				echo '<p>' . esc_html( count( $pending['rows'] ) . ' bonus listings and ' . count( $pending['images'] ) . ' validated images ready for approval.' ) . '</p>';
				self::table( array( 'File', 'Scan status', 'Result' ) );
				foreach ( $pending['scans'] as $scan ) {
					echo '<tr><td>' . esc_html( $scan['file'] ) . '</td><td>' . esc_html( $scan['status'] ) . '</td><td>' . esc_html( $scan['detail'] ) . '</td></tr>';
				}
				self::end_table();
				if ( $pending['errors'] ) {
					echo '<h4>Excluded files and source/validation errors</h4><ul class="ul-disc mrsa-import-errors">';
					foreach ( $pending['errors'] as $error ) {
						echo '<li>' . esc_html( $error ) . '</li>';
					}
					echo '</ul>';
				}
				if ( $pending['can_approve'] ) {
					if ( ! empty( $pending['unscanned'] ) ) {
						echo '<div class="notice notice-warning inline"><p><strong>No antivirus security scan was performed.</strong> ClamAV is unavailable. Format and executable-content checks do not replace virus scanning. Your username, UTC date/time, event, and unscanned import activity will be recorded in the audit table.</p></div>';
						self::form( 'bonus_import_bypass', array( 'event_id' => $event_id, 'pending_token' => $pending_token, 'import_step' => $step ) );
						echo '<label><input type="checkbox" name="bypass_confirmation" value="1" required> I accept the risk and confirm importing these validated files without antivirus scanning. Remember this choice for my account on this site and import without further approval prompts only while ClamAV is unavailable.</label><p>';
						self::button( 'Confirm Bypass and Import' );
					} else {
						self::form( 'bonus_import_approve', array( 'event_id' => $event_id, 'pending_token' => $pending_token, 'import_step' => $step ) );
						echo '<label><input type="checkbox" name="scan_approval" value="1" required> I have reviewed the scan results and approve importing only the validated listings/images into this event.</label><p>';
						self::button( 'Approve and Import' );
					}
					echo '</p></form>';
				} else {
					echo '<div class="notice notice-error inline"><p>Import approval is blocked. Resolve scanner or file errors and try again. Scanner errors always block the batch; the unavailable-ClamAV bypass cannot override a malware detection or validation failure.</p></div>';
				}
				self::form( 'bonus_import_cancel', array( 'event_id' => $event_id, 'pending_token' => $pending_token, 'import_step' => $step ) );
				self::button( 'Cancel and Remove Staged Files' );
				echo '</form></section>';
			}
		}
		$report_id = MRSA_Admin::query( 'bonus_report' );
		if ( preg_match( '/^[A-Za-z0-9]{24}$/D', $report_id ) ) {
			$report = get_transient( 'mrsa_bonus_report_' . get_current_user_id() . '_' . $report_id );
			if ( $report && (int) $report['event_id'] === $event_id && ( $report['step'] ?? 'images' ) === $step ) {
				echo '<div class="notice notice-warning inline"><h4>Bonus import errors</h4><p>See the import summary in this section for the number saved. Correct the following file/source errors and retry those files.</p><ul class="ul-disc mrsa-import-errors">';
				foreach ( $report['errors'] as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			} elseif ( ! $report && ( $feedback['step'] ?? 'images' ) === $step ) {
				echo '<div class="notice notice-warning inline"><p>The image import report has expired or is unavailable for this event. Reports remain available for 15 minutes.</p></div>';
			}
		}
	}

	private static function bonus_library( $event ) {
		$event_id = (int) $event['id'];
		echo '<h4>Bulk Import Bonus Listing</h4><ol><li>Validate and import the bonus listing XLSX or CSV.</li><li>Validate and import the bonus images.</li></ol><p>ClamAV scans require review and approval. If the daemon is unavailable, you can explicitly confirm a per-account bypass; later unscanned imports then proceed without another approval prompt and are audited. Scanner errors, detected malware, and unsafe content cannot be bypassed. Re-importing a Bonus ID updates its listing without removing other IDs.</p>';
		echo '<div class="mrsa-import-header"><div id="mrsa-import-setup">';
		self::clamav_setup( $event_id );
		echo '</div><div id="mrsa-import-listing">';
		self::form( 'bonus_import', array( 'event_id' => $event_id, 'import_step' => 'listing' ), 'mrsa-panel mrsa-edit-form', '', 'multipart/form-data' );
		echo '<h4>Step 1: Import the bonus listing</h4>';
		echo '<label class="mrsa-field"><span>Bonus listing spreadsheet (XLSX or CSV)</span><input type="file" name="bonus_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>';
		echo '<p>Columns: Bonus ID, Bonus Description, Bonus Category, Bonus Points, Bonus Locations (GPS coordinates). Enter locations as latitude,longitude.</p><p><a href="' . esc_url( plugins_url( 'samples/bonus-listing.xlsx', MRSA_FILE ) ) . '" download>Download sample bonus listing XLSX</a>. Replace or remove the example row before importing; keep the column headings on the first worksheet.</p>';
		self::button( 'Validate and Import Bonus Listing' );
		echo '</form></div><div id="mrsa-import-images">';
		self::form( 'bonus_import', array( 'event_id' => $event_id, 'import_step' => 'images' ), 'mrsa-panel mrsa-edit-form', '', 'multipart/form-data' );
		echo '<div class="mrsa-image-import-heading"><h4>Step 2: Import the bonus images</h4><details class="mrsa-url-help"><summary aria-label="Bonus image import information">Help?</summary><div class="mrsa-url-help-content"><p>Image filenames must match Bonus IDs, for example B12.jpg. An image with a new ID creates a listing with empty attributes; import its spreadsheet attributes in step 1.</p><p>With JavaScript, select a folder to upload, scan, review, and import one image at a time. Without JavaScript, choose one image per import. Each image must fit the server upload limit and the 10 MB MRSA limit.</p><p>Public URLs must use HTTPS and point to an image, a ZIP of images, or a standard web-server directory listing. Google Drive/Dropbox links must provide a public ZIP download or directory listing. Limits: 200 images, 10 MB per image, 100 MB total, and 50 MB per ZIP/download. Server upload limits may be lower. Load by URL prompts for the link and imports only the URL source; selected local files are not included.</p><p>Local uploads: each image is imported separately after approval; earlier imports remain if a later file fails or is cancelled. With the skip-failed option, invalid files are skipped; scanner errors stop the queue. URL imports: failed images block the batch unless this option is selected. Remembered unavailable-ClamAV overrides still apply and are audited.</p></div></details></div>';
		echo '<label class="mrsa-field"><span>Local bonus images</span><input type="file" name="bonus_images[]" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp"></label>';
		echo '<div class="mrsa-url-source"><button type="button" class="button mrsa-url-open" hidden>Load by URL</button><div class="mrsa-url-prompt"><h4 id="mrsa-url-prompt-title" hidden>Load bonus images by URL</h4><p class="mrsa-url-prompt-hint" hidden>Enter a public HTTPS link to an image, a ZIP of images, or a directory listing. Only this URL is imported; selected local files are not included.</p>';
		self::field( 'Public HTTPS image, ZIP, or directory-listing URL', 'gallery_url', '', 'url', false, array( 'maxlength' => 2000, 'placeholder' => 'https://example.com/event-images/', 'autocomplete' => 'url' ) );
		echo '<div class="mrsa-url-actions"><button type="button" class="button mrsa-url-cancel" hidden>Cancel</button><button class="button mrsa-url-submit" type="submit" name="image_source" value="url">Load by URL</button></div></div></div>';
		echo '<label><input type="checkbox" name="allow_partial_images" value="1"> Import all successful images and skip failed images</label>';
		self::button( 'Validate and Import Bonus Images' );
		echo '</form></div></div><section class="mrsa-import-feedback" id="mrsa-import-feedback" aria-label="Bonus import notices and scan results">';
		foreach ( array( 'setup', 'listing', 'images' ) as $step ) {
			ob_start();
			self::bonus_import_feedback( $event_id, $step );
			$feedback = ob_get_clean();
			if ( '' !== $feedback ) {
				$labels = array( 'setup' => 'Step 0: ClamAV', 'listing' => 'Step 1: Bonus listing', 'images' => 'Step 2: Bonus images' );
				echo '<div class="mrsa-import-feedback-group"><h4>' . esc_html( $labels[ $step ] ) . '</h4>' . $feedback . '</div>';
			}
		}
		echo '</section><section class="mrsa-panel mrsa-image-queue" hidden data-max-image="' . esc_attr( MRSA_Bonus::MAX_IMAGE_SIZE ) . '" data-max-total="' . esc_attr( MRSA_Bonus::MAX_IMAGE_TOTAL ) . '" data-max-count="' . esc_attr( MRSA_Bonus::MAX_IMAGES ) . '" aria-label="Sequential image scan and bulk import"><h4>Scan queue and bulk image import</h4><p class="mrsa-queue-progress" role="status" aria-live="polite"></p><div class="mrsa-queue-review"></div><p><button type="button" class="button mrsa-queue-stop">Stop after current file</button><a class="button" href="' . esc_url( MRSA_Admin::url( 'scoring', array( 'event_id' => $event_id, 'score_tab' => 'library' ) ) ) . '">Refresh event gallery</a></p><ol class="mrsa-queue-log"></ol></section>';
		$rows = self::queries()->bonus_gallery( $event_id );
		echo '<h4>Event Bonus Point Gallery</h4>';
		if ( ! $rows ) {
			echo '<p>No bonus listings or images have been added for this event.</p>';
			return;
		}
		self::table( array( 'Bonus ID', 'Image', 'Bonus Description', 'Bonus Category', 'Bonus Points', 'Bonus Locations' ) );
		foreach ( $rows as $row ) {
			echo '<tr><td>';
			self::link( $row['bonus_id'], 'bonus-edit', array( 'event_id' => $event_id, 'bonus_id' => $row['bonus_id'] ) );
			echo '</td><td>';
			if ( $row['image_file'] ) {
				$image_url = MRSA_Bonus::image_url( $event_id, $row['image_file'] );
				echo '<a class="mrsa-bonus-image-link" href="' . esc_url( $image_url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( 'View larger image for Bonus ' . $row['bonus_id'] ) . '"><img class="mrsa-bonus-thumbnail" src="' . esc_url( $image_url ) . '" alt="' . esc_attr( 'Bonus ' . $row['bonus_id'] ) . '" loading="lazy"></a>';
			} else {
				self::h( 'No image' );
			}
			echo '</td><td>';
			self::h( $row['description'] );
			echo '</td><td>';
			self::h( $row['category'] );
			echo '</td><td>';
			self::h( $row['points'] );
			echo '</td><td>';
			self::h( $row['locations'] );
			echo '</td></tr>';
		}
		self::end_table();
		echo '<dialog class="mrsa-gallery-dialog" aria-labelledby="mrsa-gallery-title"><div class="mrsa-gallery-dialog-toolbar"><h3 id="mrsa-gallery-title">Bonus image</h3><button type="button" class="button mrsa-gallery-close" autofocus>Close image</button></div><p class="mrsa-gallery-image-error" role="alert" hidden>The image could not be loaded. Close this preview and retry, or replace the image in the bonus editor.</p><img class="mrsa-gallery-full-image" alt=""></dialog>';
	}

	private static function clamav_setup( $event_id ) {
		$connection = MRSA_File_Scan::check_connection();
		$bypassed = MRSA_Bonus::bypass_enabled();
		if ( $bypassed ) {
			echo '<details class="mrsa-clamav-disabled"><summary>ClamAV Manually Disabled</summary>';
		} elseif ( $connection['passed'] ) {
			echo '<details class="mrsa-clamav-enabled"><summary>ClamAV Enabled</summary>';
		} else {
			echo '<div class="mrsa-clamav-unconfigured">';
		}
		echo '<section class="mrsa-panel mrsa-edit-form" id="mrsa-clamav-setup"><h4>Step 0: Set up or verify ClamAV</h4><p>If ClamAV is already installed using the README instructions, skip installation and check the connection below. Otherwise, follow the setup guide or ask your hosting provider to configure it.</p><p>Installation requires server administrator access. These commands run in your server terminal, not WordPress; this page does not install packages, run privileged commands, or change server configuration.</p>';
		if ( $bypassed ) {
			echo '<div class="notice notice-warning inline"><p><strong>Your ClamAV bypass is enabled on this site.</strong> When ClamAV is unavailable, valid imports run without an approval prompt and without an antivirus security scan. Each import is logged with your username and UTC date/time. When ClamAV is available, scanning and approval remain required.</p></div>';
		}
		echo '<details><summary>ClamAV installation and configuration guide</summary>';
		echo '<h5>Ubuntu / Debian (systemd)</h5><p>Run these commands on the WordPress server using an account with sudo access:</p>';
		echo '<pre class="mrsa-setup-commands"><code>' . esc_html( "sudo apt-get update\nsudo apt-get install clamav clamav-daemon\nsudo systemctl enable --now clamav-freshclam" ) . '</code></pre><p>Wait for freshclam to download its signature databases. Configure the following settings in <code>/etc/clamav/clamd.conf</code>, updating existing values rather than adding duplicates:</p>';
		echo '<pre class="mrsa-setup-commands"><code>' . esc_html( "ScanArchive yes\nScanPE yes\nScanELF yes\nScanOLE2 yes\nAlertExceedsMax yes\nStreamMaxLength 60M\nMaxFileSize 60M\nMaxScanSize 200M" ) . '</code></pre>';
		echo '<p>Give the PHP service account access to the local clamd socket through its socket group and permissions. Do not make the socket world-writable. If group membership changes, restart the relevant PHP-FPM or web-server service using your host&rsquo;s instructions. Then start or restart the daemon:</p>';
		echo '<pre class="mrsa-setup-commands"><code>' . esc_html( "sudo systemctl enable --now clamav-daemon\nsudo systemctl restart clamav-daemon\nsudo systemctl status clamav-daemon clamav-freshclam" ) . '</code></pre>';
		echo '<h5>Other Linux distributions, Windows, or managed hosting</h5><p>Use your distribution&rsquo;s ClamAV packages or the <a href="https://docs.clamav.net/manual/Installing.html" target="_blank" rel="noopener noreferrer">official ClamAV installation guide<span class="screen-reader-text"> (opens in a new tab)</span></a>. Configure clamd and freshclam with equivalent scan settings above. Service names and configuration paths vary. On managed hosting, ask the provider to enable a local clamd endpoint for your PHP process; installing a WordPress plugin alone cannot install the server daemon.</p>';
		echo '<h5>Connect WordPress</h5><p>The default endpoint is <code>unix:///var/run/clamav/clamd.ctl</code>. If your server uses a different local socket, add <code>MRSA_CLAMAV_SOCKET</code> to <code>wp-config.php</code> before the stop-editing line. Choose one endpoint only:</p>';
		echo '<pre class="mrsa-setup-commands"><code>' . esc_html( "define( 'MRSA_CLAMAV_SOCKET', 'unix:///var/run/clamav/clamd.ctl' );\n// Alternatively, for a loopback-only clamd TCP listener:\n// define( 'MRSA_CLAMAV_SOCKET', 'tcp://127.0.0.1:3310' );" ) . '</code></pre>';
		echo '<p>For TCP, bind clamd to <code>127.0.0.1</code> with <code>TCPSocket 3310</code> and <code>TCPAddr 127.0.0.1</code>; never expose its unauthenticated listener to the network. Keep ClamAV and virus signatures current. Ensure private import temporary storage is writable and outside the web root; set <code>MRSA_IMPORT_TEMP_DIR</code> in <code>wp-config.php</code> if needed.</p></details>';
		self::form( 'bonus_clamav_check', array( 'event_id' => $event_id ) );
		echo '<p>';
		self::button( 'Check ClamAV Connection' );
		echo '</p></form><p>The check streams a small, harmless test file to the configured local daemon. It does not import bonus records or images or enable a bypass. When ClamAV is available, each import requires its own scan and approval.</p>';
		if ( $bypassed ) {
			self::form( 'bonus_import_require_scan', array( 'event_id' => $event_id ) );
			self::button( 'Remove ClamAV Scanning Override' );
			echo '</form>';
		} else {
			self::form( 'bonus_import_disable_scan', array( 'event_id' => $event_id ) );
			echo '<label><input type="checkbox" name="bypass_confirmation" value="1" required> I accept the risk of unscanned imports when ClamAV is unavailable. Remember this override for my account and record my username and UTC date/time in the audit log.</label><p>';
			self::button( 'Disable ClamAV Scanning' );
			echo '</p></form><p>This override applies only when ClamAV is unavailable. An available scanner is still used; malware, scanner errors, and unsafe content cannot be bypassed.</p>';
		}
		echo '</section>';
		if ( $bypassed || $connection['passed'] ) {
			echo '</details>';
		} else {
			echo '</div>';
		}
	}

	private static function clamav_feedback( $event_id ) {
		$check_id = MRSA_Admin::query( 'clamav_check' );
		if ( preg_match( '/^[A-Za-z0-9]{24}$/D', $check_id ) ) {
			$check = get_transient( 'mrsa_clamav_check_' . get_current_user_id() . '_' . $check_id );
			if ( $check && (int) $check['event_id'] === $event_id ) {
				echo '<div class="notice ' . ( $check['passed'] ? 'notice-success' : 'notice-error' ) . ' inline"><p>' . esc_html( $check['message'] ) . '</p></div>';
			} else {
				echo '<div class="notice notice-warning inline"><p>The connection-check result expired or is unavailable for this event. Run the check again.</p></div>';
			}
		}
	}

	public static function render_bonus_edit() {
		if ( ! MRSA::access() ) {
			wp_die( esc_html( 'You are not authorized to manage bonus listings.' ), '', array( 'response' => 403 ) );
		}
		$event_id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		$bonus_id = MRSA_Admin::query( 'bonus_id' );
		if ( ! MRSA::scoring_access( $event_id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $bonus_id ) ) {
			wp_die( esc_html( 'Bonus listing not found.' ), '', array( 'response' => 404 ) );
		}
		$bonus = self::queries()->bonus( $event_id, $bonus_id );
		if ( ! $bonus ) {
			wp_die( esc_html( 'Bonus listing not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>Edit Bonus Point — ' . esc_html( $bonus_id ) . '</h2><p><a class="button" href="' . esc_url( MRSA_Admin::url( 'scoring', array( 'event_id' => $event_id, 'score_tab' => 'library' ) ) . '#mrsa-scoring' ) . '">Back to Event Bonus Point Library</a></p>';
		self::notice();
		if ( $bonus['image_file'] ) {
			echo '<p><img class="mrsa-bonus-preview" src="' . esc_url( MRSA_Bonus::image_url( $event_id, $bonus['image_file'] ) ) . '" alt="' . esc_attr( 'Bonus ' . $bonus_id ) . '"></p>';
		}
		self::form( 'bonus_point', array( 'event_id' => $event_id, 'bonus_id' => $bonus_id ), 'mrsa-panel mrsa-edit-form', '', 'multipart/form-data' );
		self::field( 'Bonus ID', 'new_bonus_id', $bonus['bonus_id'], 'text', true, array( 'maxlength' => 64, 'pattern' => '[A-Za-z0-9][A-Za-z0-9_-]{0,63}' ) );
		self::field( 'Bonus Description', 'description', $bonus['description'], 'textarea' );
		self::field( 'Bonus Category', 'category', $bonus['category'], 'text', false, array( 'maxlength' => 120 ) );
		self::field( 'Bonus Points', 'points', $bonus['points'], 'number', true, array( 'min' => 0, 'max' => MRSA_Bonus::MAX_BONUS_POINTS, 'step' => 1 ) );
		self::field( 'Bonus Locations (latitude,longitude)', 'locations', $bonus['locations'], 'text', false, array( 'maxlength' => 80, 'placeholder' => '47.6205,-122.3493' ) );
		echo '<label class="mrsa-field"><span>Replace image (filename must match Bonus ID)</span><input type="file" name="bonus_image" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp"></label>';
		if ( $bonus['image_file'] ) {
			echo '<label><input type="checkbox" name="remove_image" value="1"> Remove the current gallery image</label>';
		}
		echo '<p>';
		self::button( 'Save Bonus Point' );
		echo '</p></form></div>';
	}

	private static function overall_results() {
		$rider_id = absint( MRSA_Admin::query( 'rider_id', '0' ) );
		$events = self::events( $rider_id );
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		$allowed_ids = array_map( 'intval', wp_list_pluck( $events, 'id' ) );
		if ( $id && ! in_array( $id, $allowed_ids, true ) ) {
			wp_die( esc_html( 'Event not found, not assigned to you, or does not contain the selected rider.' ), '', array( 'response' => 403 ) );
		}
		echo '<h2>Results</h2>';
		if ( $rider_id ) {
			$rider = MRSA::row( 'riders', $rider_id );
			echo '<p>Showing only accessible events containing <strong>' . esc_html( $rider ? $rider['name'] : 'the selected rider' ) . '</strong>.</p>';
		}
		if ( $events ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="mrsa-panel"><input type="hidden" name="page" value="mrsa"><input type="hidden" name="tab" value="results">';
			if ( $rider_id ) {
				echo '<input type="hidden" name="rider_id" value="' . esc_attr( $rider_id ) . '">';
			}
			self::event_select( $events, $id );
			echo '<button class="button">Choose event</button></form>';
		}
		if ( ! $id ) {
			echo '<p>' . esc_html( $events ? 'Choose an event to display its overall results.' : 'No matching accessible events were found.' ) . '</p>';
			return;
		}
		$event = MRSA::row( 'events', $id );
		$rows = self::queries()->results( $id, $event['min_finisher_points'] );
		echo '<h3>' . esc_html( $event['name'] ) . '</h3><p>Overall placing uses competition ranking by total score: equal scores share a place and the next place is skipped. Automatic status becomes Preliminary Finisher only when total score is greater than the event minimum finish score of ' . esc_html( $event['min_finisher_points'] ) . '.</p>';
		self::table( array( 'Rider #', 'Rider Name', 'Total Score', 'Status', 'Overall Placing' ) );
		foreach ( $rows as $row ) {
			$rank = $row['overall_placing'];
			$effective_status = $row['effective_status'];
			$score_url = admin_url( 'admin.php?page=mrsa-rider-score&event_id=' . $id . '&rider_id=' . (int) $row['rider_id'] );
			echo '<tr><td><a href="' . esc_url( $score_url ) . '">' . esc_html( $row['bib'] ) . '</a></td><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['score'] ) . '</td><td>' . esc_html( $effective_status ) . '</td><td>' . esc_html( $rank ) . '</td></tr>';
		}
		self::end_table();
	}

	public static function render_rider_score() {
		$event_id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		$rider_id = absint( MRSA_Admin::query( 'rider_id', '0' ) );
		if ( ! MRSA::event_access( $event_id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		$row = self::queries()->rider_score( $event_id, $rider_id );
		if ( ! $row ) {
			wp_die( esc_html( 'The selected rider is not registered in this event.' ), '', array( 'response' => 404 ) );
		}
		$status = $row['effective_status'];
		$placing = $row['overall_placing'];
		echo '<div class="wrap mrsa">';
		self::header();
		$back_url = MRSA::event_access( $event_id ) ? MRSA_Admin::url( 'results', array( 'event_id' => $event_id ) ) : MRSA_Admin::url( 'scoring', array( 'event_id' => $event_id, 'score_tab' => 'reviewed' ) );
		echo '<h2>Rider Overall Event Score Sheet</h2><p><a class="button" href="' . esc_url( $back_url ) . '">Back</a></p>';
		echo '<div class="mrsa-panel"><p><strong>Event:</strong> ' . esc_html( $row['event_name'] ) . '</p><p><strong>Rider:</strong> ' . esc_html( $row['name'] ) . ' (bib ' . esc_html( $row['bib'] ) . ')</p><p><strong>Total score:</strong> ' . esc_html( $row['score'] ) . '</p><p><strong>Status:</strong> ' . esc_html( $status ) . '</p><p><strong>Overall placing:</strong> ' . esc_html( $placing ) . '</p></div>';
		echo '<h3>Bonus Submission History</h3>';
		$submissions = self::queries()->rider_submission_history( $event_id, $rider_id );
		if ( ! $submissions ) {
			echo '<p>No email bonus submissions have been recorded for this rider.</p>';
		} else {
			self::table( array( 'Submission', 'Received', 'Bonus ID', 'Description', 'Status', 'Points', 'Rejection reason', 'Photo' ), array( 4 ) );
			foreach ( $submissions as $submission ) {
				echo '<tr><td>' . esc_html( $submission['id'] ) . '</td><td>' . esc_html( $submission['submitted_at'] ) . '</td><td>' . esc_html( $submission['bonus_id'] ) . '</td><td>' . esc_html( $submission['bonus_description'] ) . '</td><td>' . esc_html( ucfirst( $submission['status'] ) ) . '</td><td>' . esc_html( $submission['points_awarded'] ) . '</td><td>' . esc_html( $submission['rejection_description'] ?: str_replace( '_', ' ', $submission['rejection_code'] ) ) . '</td><td>';
				$image_url = MRSA_Email_Submissions::image_url( $event_id, $submission['image_file'] );
				echo $image_url ? '<a href="' . esc_url( $image_url ) . '" target="_blank" rel="noopener noreferrer">View photo</a>' : '—';
				echo '</td></tr>';
			}
			self::end_table();
		}
		echo '</div>';
	}

	private static function diagnostics() {
		if ( ! MRSA::site_access() ) {
			wp_die( esc_html( 'Only WordPress administrators may use Diagnostics.' ), '', array( 'response' => 403 ) );
		}
		$events = self::queries()->diagnostic_events();
		$riders = self::queries()->diagnostic_riders();
		echo '<h2>Diagnostics</h2><p>Export application data, create a restorable database backup, or permanently remove scoped plugin data.</p>';
		self::configuration_check();
		echo '<div class="mrsa-grid">';
		foreach ( array( 'json' => array( 'Export Data', 'Download all plugin table data as JSON.' ), 'sql' => array( 'Database Backup', 'Download plugin table definitions and data as restorable SQL.' ) ) as $type => $details ) {
			echo '<details class="mrsa-panel mrsa-diagnostics-section" open><summary><h3>' . esc_html( $details[0] ) . '</h3></summary><p>' . esc_html( $details[1] ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mrsa_diagnostics_download"><input type="hidden" name="download_type" value="' . esc_attr( $type ) . '">';
			wp_nonce_field( 'mrsa_diagnostics_download' );
			echo '<button class="button button-primary">Download ' . esc_html( strtoupper( $type ) ) . '</button></form></details>';
		}
		echo '</div><details class="mrsa-administrative-audit"><summary><h3>Administrative Audit</h3></summary><p>Latest 100 records, with usernames and timestamps in UTC. Exports and database backups include the full audit table. Ordinary data deletions retain audit history and recorded bypass choices. Deleting virus-scan audit data resets all overrides and leaves a new deletion record.</p>';
		$audit_rows = self::queries()->recent_audit();
		self::table( array( 'User ID', 'Username', 'Date/time (UTC)', 'Event ID', 'Action', 'Details' ) );
		foreach ( $audit_rows as $audit_row ) {
			echo '<tr>';
			foreach ( $audit_row as $value ) {
				echo '<td>' . esc_html( $value ) . '</td>';
			}
			echo '</tr>';
		}
		self::end_table();
		echo '</details>';
		echo '<details class="mrsa-diagnostics-section"><summary><h3>Destructive operations</h3></summary><div class="notice notice-error inline"><p>These operations cannot be undone. Download a database backup first. Each operation requires a randomized verification string and is recorded in the audit log.</p></div><div class="mrsa-destructive-grid">';
		self::diagnostics_delete_form( 'diagnostics_delete_all', 'Delete All Data', 'Delete application data, including administrator assignments. Import audit records and recorded bypass choices are retained.', array() );
		self::diagnostics_delete_form( 'diagnostics_delete_bikes', 'Delete All Bike Data', 'Delete the motorcycle catalog and every rider-motorcycle assignment.', array() );
		$event_options = '<option value="">Choose an event</option>';
		foreach ( $events as $event ) {
			$event_options .= '<option value="' . esc_attr( $event['id'] ) . '">' . esc_html( $event['name'] . ' — ' . $event['event_date'] ) . '</option>';
		}
		self::diagnostics_delete_form( 'diagnostics_delete_roster', 'Delete Event Roster', 'Delete all registrations for one event.', '<label class="mrsa-field"><span>Event</span><select name="event_id" required>' . $event_options . '</select></label>' );
		self::diagnostics_delete_form( 'diagnostics_delete_bonus_points', 'Delete Event Bonus Data', 'Delete all bonus listings, rider submissions, scoring images, and submission-awarded score points for one event.', '<label class="mrsa-field"><span>Event</span><select name="event_id" required>' . $event_options . '</select></label>' );
		$rider_options = '<option value="">Choose a rider</option>';
		foreach ( $riders as $rider ) {
			$rider_options .= '<option value="' . esc_attr( $rider['id'] ) . '">' . esc_html( $rider['name'] . ' — ' . $rider['email'] ) . '</option>';
		}
		self::diagnostics_delete_form( 'diagnostics_delete_rider', 'Delete Specific Rider', 'Delete one rider, their motorcycle assignments, and all event registrations.', '<label class="mrsa-field"><span>Rider</span><select name="rider_id" required>' . $rider_options . '</select></label>' );
		self::diagnostics_delete_form( 'diagnostics_delete_audit', 'Delete Virus Scan Audit Data', 'Delete audit history and reset remembered ClamAV overrides for every administrator. A new record of this deletion will remain.', array() );
		echo '</div></details>';
	}

	private static function configuration_check() {
		$report = get_transient( 'mrsa_configuration_' . get_current_user_id() );
		$has_report = is_array( $report ) && ! empty( $report['rows'] ) && isset( $report['checked_at'] );
		$state = '';
		$labels = array( 'passed' => 'Passed', 'failed' => 'Failed', 'review' => 'Manual review' );
		if ( $has_report ) {
			$state = MRSA_Diagnostic_Report::state( $report['rows'] );
		} else {
			$last_state = get_user_option( 'mrsa_configuration_last_state', get_current_user_id() );
			if ( isset( $labels[ $last_state ] ) ) {
				$state = $last_state;
			}
		}
		$status_label = $state ? ( $has_report ? $labels[ $state ] : 'Last check: ' . $labels[ $state ] . ' — Results expired' ) : '';
		echo '<details class="mrsa-panel mrsa-diagnostics-section' . ( $state ? ' mrsa-configuration-' . esc_attr( $state ) : '' ) . '" id="mrsa-configuration"' . ( ! $state || 'failed' === $state ? ' open' : '' ) . '><summary><h3>Required Plugins and Configuration</h3>' . ( $state ? ' <strong class="mrsa-check-' . esc_attr( $state ) . '">' . esc_html( $status_label ) . '</strong>' : '' ) . '</summary><p>Check the current WordPress/PHP environment, MRSA database, local ClamAV scanner, storage, and import settings. Action Scheduler is required; Gallery for SpotWalla remains optional.</p><p><strong>MRSA limits:</strong> spreadsheets and individual images: 10 MB; remote downloads/ZIP archives: 50 MB; accepted images per queue: 100 MB / 200 images; spreadsheet rows: 5,000. Server settings are checked against these existing safety limits. Sequential image uploads need at least 11 MB post capacity including overhead for each 10 MB file.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mrsa_diagnostics_check">';
		wp_nonce_field( 'mrsa_diagnostics_check' );
		echo '<button type="submit" class="button button-primary">Check Required Plugins and Configuration</button></form>';
		$plugin = MRSA_Diagnostics::action_scheduler();
		if ( ! $plugin['installed'] && current_user_can( 'install_plugins' ) ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=action-scheduler' ), 'install-plugin_action-scheduler' ) ) . '">Install Action Scheduler</a></p>';
		} elseif ( $plugin['installed'] && ! $plugin['active'] && current_user_can( 'activate_plugin', 'action-scheduler/action-scheduler.php' ) ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( 'action-scheduler/action-scheduler.php' ) ), 'activate-plugin_action-scheduler/action-scheduler.php' ) ) . '">Activate Action Scheduler</a></p>';
		} elseif ( ! $plugin['ready'] ) {
			echo '<p>Action Scheduler is not ready. A WordPress administrator with plugin installation/activation permissions must resolve this requirement.</p>';
		}
		echo '<p>Installation uses the standard WordPress installer and its permissions, compatibility and filesystem checks. Return here after installing/activating and rerun the check. Server software and PHP settings must be managed by your hosting administrator.</p>';
		if ( $has_report ) {
			echo '<p>Last checked: ' . esc_html( $report['checked_at'] ) . ' UTC. Results expire after 15 minutes and are a snapshot, not a guarantee that all operations will succeed.</p><div class="mrsa-scroll"><table class="widefat striped"><thead><tr><th>Check</th><th>Status</th><th>Result / required action</th></tr></thead><tbody>';
			foreach ( $report['rows'] as $row ) {
				echo '<tr' . ( 'failed' === $row['status'] ? ' class="mrsa-configuration-failed-row"' : '' ) . '><td>' . esc_html( $row['name'] ) . '</td><td><strong class="mrsa-check-' . esc_attr( $row['status'] ) . '">' . esc_html( $labels[ $row['status'] ] ) . '</strong></td><td>' . esc_html( $row['detail'] );
				if ( 'passed' !== $row['status'] ) {
					echo '<details class="mrsa-configuration-help"' . ( 'failed' === $row['status'] ? ' open' : '' ) . '><summary>' . esc_html( 'failed' === $row['status'] ? 'How to fix this requirement' : 'How to verify this requirement' ) . '</summary><p>' . esc_html( $row['remedy'] ) . '</p></details>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p>No current report. Run the check to display results; no configuration changes or plugin installations occur automatically.</p>';
		}
		echo '</details>';
	}

	private static function diagnostics_delete_form( $operation, $title, $description, $fields ) {
		$code = strtoupper( wp_generate_password( 8, false, false ) );
		echo '<details class="mrsa-panel mrsa-destructive-action mrsa-diagnostics-section" open><summary><h4>' . esc_html( $title ) . '</h4></summary><p>' . esc_html( $description ) . '</p>';
		self::form( $operation, array( 'verification_hash' => MRSA_Admin::diagnostics_hash( $operation, $code ) ) );
		if ( is_string( $fields ) ) {
			echo $fields; // Built exclusively from escaped local table values above.
		}
		echo '<div class="mrsa-delete-verification"><h4 id="' . esc_attr( $operation ) . '-title">Confirm: ' . esc_html( $title ) . '</h4><p>' . esc_html( $description ) . ' This cannot be undone.</p><p class="mrsa-delete-target"></p><label class="mrsa-field"><span>Type <code>' . esc_html( $code ) . '</code> to verify</span><input name="verification_code" type="text" required autocomplete="off" pattern="' . esc_attr( $code ) . '"></label><button type="button" class="button mrsa-delete-cancel" hidden>Cancel</button><button type="submit" class="button mrsa-delete-confirm" hidden>Confirm deletion</button></div>';
		self::button( $title );
		echo '</form></details>';
	}

	private static function about() {
		global $wp_version;
		$repository = 'https://github.com/oconnoradv/MotorcycleRallyScoringApp';
		$recommended_plugin = 'https://github.com/oconnoradv/SpotwallaGallary';
		$links = array(
			'Repository'                  => $repository,
			'README'                      => $repository . '/blob/main/README.md',
			'License (GPLv3 or later)'    => $repository . '/blob/main/LICENSE',
			'GitHub Releases'             => $repository . '/releases',
			'Vehicle catalog data source' => MRSA_Importer::SOURCE,
		);
		echo '<h2>About</h2>';
		echo '<p>Motorcycle Rally Scoring Application provides site-scoped administration for rally organizers, motorcycle catalogs, master rider records, event setup, and rider enrollment.</p>';
		echo '<div class="notice notice-warning inline"><p><strong>Version status:</strong> This development version provides event-scoped email bonus ingestion, separate Event Scorer assignments, pending/reviewed submission history, transactional point awards, the Bonus Point Library, registration lifecycle tracking, score-based overall ranking, exports, and backups. Contested-submission handling remains undefined.</p></div>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Version</th><td>' . esc_html( MRSA_VERSION ) . '</td></tr>';
		echo '<tr><th scope="row">Environment</th><td>WordPress ' . esc_html( $wp_version ) . ', PHP ' . esc_html( PHP_VERSION ) . '</td></tr>';
		foreach ( $links as $label => $url ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '<span class="screen-reader-text"> (opens in a new tab)</span></a></td></tr>';
		}
		echo '</table>';
		echo '<p>The plugin is free software released under the GNU General Public License, version 3 or (at your option) any later version. The repository and installable package include the license and documentation.</p>';
		echo '<h2>What this version includes</h2><ul class="ul-disc"><li>Event Administrator assignments based on existing WordPress users; WordPress administrators manage shared application data.</li><li>Manual and make-based NHTSA motorcycle make/model catalog workflows with bulk deletion.</li><li>Master rider records with emergency, insurance, make/model motorcycle, optional manual year, and fuel-capacity details.</li><li>Event configuration, roster, enrollment, event-scoped bonus listings, and a dedicated bonus image gallery stored outside the WordPress media library.</li><li>Successful forms return to their originating form or tab.</li></ul>';
		echo '<section class="mrsa-panel mrsa-recommended-plugins"><h2>Plugins you Might Like</h2><h3>Gallery for SpotWalla</h3>';
		echo '<p>Display public SpotWalla tracks, trips, and retrospectives on WordPress pages or posts, individually or in galleries. This optional plugin works independently of Motorcycle Rally Scoring Application; it is not required for scoring or the event bonus image library.</p>';
		echo '<p><a href="' . esc_url( $recommended_plugin ) . '" target="_blank" rel="noopener noreferrer">View plugin details and documentation<span class="screen-reader-text"> (opens in a new tab)</span></a> &middot; <a href="' . esc_url( $recommended_plugin . '/releases' ) . '" target="_blank" rel="noopener noreferrer">Download from GitHub Releases<span class="screen-reader-text"> (opens in a new tab)</span></a></p>';
		echo '<p>Installation is optional and manual. Review the plugin&rsquo;s requirements and privacy guidance before installing; embedded maps load content from SpotWalla. This recommendation does not imply endorsement or approval by WordPress.org or SpotWalla.</p></section>';
		echo '<h2>Bike Catalog Import Source</h2><div class="notice notice-info inline"><p>The optional import uses the <a href="' . esc_url( MRSA_Importer::SOURCE ) . '" target="_blank" rel="noopener noreferrer">NHTSA Product Information Catalog and Vehicle Listing API<span class="screen-reader-text"> (opens in a new tab)</span></a>. It accepts a make and intersects Get Models for Make results with NHTSA&rsquo;s motorcycle-filtered models. Only make and model are imported; an optional four-digit year is entered separately on a rider&rsquo;s motorcycle assignment and is not validated against the catalog.</p></div>';
		echo '<h2>Privacy and security</h2><p>Collect only necessary rider, emergency-contact, insurance, pillion, and bonus-submission details. WordPress personal-data export includes plugin rider, submission, role-assignment, and review information. Rider erasure anonymizes retained scoring records and removes motorcycle, pillion, submission-description, sender-email, and submission-photo data while retaining anonymous registration and decision fields for event integrity.</p><p>Event mailbox secrets are encrypted at rest but encrypted exports/backups remain sensitive. Use TLS, least-privilege accounts, secure backups, and supported WordPress, PHP, and database versions. Automated Semgrep and Trivy checks support secure development but do not certify NIST compliance, guarantee that every CVE is absent, or replace an independent security assessment.</p>';
		echo '<h2>Reporting issues</h2><ol><li>Search <a href="' . esc_url( $repository . '/issues' ) . '" target="_blank" rel="noopener noreferrer">existing issues<span class="screen-reader-text"> (opens in a new tab)</span></a> to see whether the problem is already reported.</li><li>If not, <a href="' . esc_url( $repository . '/issues/new' ) . '" target="_blank" rel="noopener noreferrer">open a new issue<span class="screen-reader-text"> (opens in a new tab)</span></a> with a short, descriptive title.</li><li>Describe the expected and actual behavior, steps to reproduce it, the affected tab or workflow, and any error messages or screenshots.</li><li>Include your environment: <code>Plugin ' . esc_html( MRSA_VERSION ) . ', WordPress ' . esc_html( $wp_version ) . ', PHP ' . esc_html( PHP_VERSION ) . '</code>.</li><li>Do not include passwords, rider records, emergency contacts, insurance details, or other sensitive data. Report security vulnerabilities privately to the repository owner rather than in a public issue.</li></ol>';
	}
}
