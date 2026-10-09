<?php
/** Escaped server-rendered administration views with optional local enhancement. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Views {
	private static function h( $value ) {
		echo esc_html( (string) $value );
	}

	private static function link( $label, $tab, $extra = array() ) {
		echo '<a href="' . esc_url( MRSA_Admin::url( $tab, $extra ) ) . '">' . esc_html( (string) $label ) . '</a>';
	}

	private static function form( $op, $hidden = array(), $class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
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
			echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
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

	private static function events() {
		global $wpdb;
		if ( MRSA::global_access() ) {
			return $wpdb->get_results( 'SELECT * FROM ' . MRSA::table( 'events' ) . ' ORDER BY event_date DESC,id DESC', ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT e.* FROM ' . MRSA::table( 'events' ) . ' e JOIN ' . MRSA::table( 'event_admins' ) . ' a ON a.event_id=e.id WHERE a.user_id=%d ORDER BY e.event_date DESC,e.id DESC', get_current_user_id() ), ARRAY_A );
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
		$global = MRSA::global_access();
		$tab = MRSA_Admin::query( 'tab', $global ? 'global' : 'events' );
		$tabs = array( 'global' => 'Global Setup', 'bikes' => 'Bike Setup', 'riders' => 'Rider Registration', 'events' => 'Event Setup', 'about' => 'About' );
		if ( ! isset( $tabs[ $tab ] ) || ( ! $global && in_array( $tab, array( 'global', 'bikes' ), true ) ) ) {
			wp_die( esc_html( 'This setup area is restricted to global administrators.' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<nav class="nav-tab-wrapper" aria-label="Setup tabs">';
		foreach ( $tabs as $key => $label ) {
			if ( ! $global && in_array( $key, array( 'global', 'bikes' ), true ) ) {
				continue;
			}
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( MRSA_Admin::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		self::notice();
		if ( ! $global ) {
			echo '<p class="mrsa-note">Event administrator access: your assigned events and their registration details only. Master rider profiles are read-only; global settings and the motorcycle catalog are restricted.</p>';
		}
		switch ( $tab ) {
			case 'global':
				self::global_setup();
				break;
			case 'bikes':
				self::bike_setup();
				break;
			case 'riders':
				self::rider_setup();
				break;
			case 'events':
				self::event_setup();
				break;
			case 'about':
				self::about();
		}
		echo '</div>';
	}

	private static function global_setup() {
		global $wpdb;
		echo '<h2>Global administrators</h2><p>WordPress users with <code>manage_options</code> always have bootstrap access. Explicit global assignments may manage all plugin records; no WordPress role is changed. Primary contact data is read from WordPress (billing_phone, then phone).</p>';
		self::form( 'global_admin', array(), 'mrsa-panel' );
		self::users_select();
		echo '<div class="mrsa-grid">';
		self::field( 'Secondary email', 'secondary_email', '', 'email', false, array( 'maxlength' => 254 ) );
		self::field( 'Secondary phone', 'secondary_phone', '', 'tel', false, array( 'maxlength' => 60 ) );
		echo '</div>';
		self::button( 'Assign global administrator' );
		echo '</form>';
		self::table( array( 'Username', 'Display name', 'Email', 'User phone', 'Secondary contacts / actions' ) );
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . MRSA::table( 'global_admins' ), ARRAY_A ) as $row ) {
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
			self::form( 'global_admin', array( 'user_id' => $row['user_id'] ) );
			self::field( 'Secondary email', 'secondary_email', $row['secondary_email'], 'email', false, array( 'maxlength' => 254 ) );
			self::field( 'Secondary phone', 'secondary_phone', $row['secondary_phone'], 'tel', false, array( 'maxlength' => 60 ) );
			self::button();
			self::button( 'Remove', 'remove_global_admin' );
			echo '</form></td></tr>';
		}
		self::end_table();
		echo '<h2>Uninstall retention</h2>';
		self::form( 'uninstall', array(), 'mrsa-panel' );
		echo '<label><input type="checkbox" name="delete_on_uninstall" value="1"' . checked( (bool) get_option( 'mrsa_delete_on_uninstall' ), true, false ) . '> Explicitly delete this site’s MRSA tables when the plugin is uninstalled.</label><p>Default: retain all data. Deactivation never deletes data. WordPress users and media are never deleted. On multisite this preference is set separately per site.</p>';
		self::button( 'Save retention preference' );
		echo '</form>';
	}

	private static function list_page( $tab, $sql, $args, $count_sql ) {
		global $wpdb;
		$page = max( 1, min( 1000000, (int) MRSA_Admin::query( 'list_page', '1' ) ) );
		$count = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count_sql, $args ) : $count_sql );
		$page = min( $page, max( 1, (int) ceil( $count / 100 ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT %d OFFSET %d', array_merge( $args, array( 100, ( $page - 1 ) * 100 ) ) ), ARRAY_A );
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
		global $wpdb;
		echo '<h2>Motorcycle catalog</h2>';
		$id = absint( MRSA_Admin::query( 'bike_id', '0' ) );
		$bike = $id ? MRSA::row( 'bikes', $id ) : array();
		if ( $id && ! $bike ) {
			wp_die( esc_html( 'Motorcycle not found.' ), '', array( 'response' => 404 ) );
		}
		self::form( 'bike', array( 'id' => $id ), 'mrsa-panel' );
		echo '<h3>' . ( $id ? 'Edit motorcycle #' . esc_html( $id ) : 'Manual motorcycle entry' ) . '</h3><div class="mrsa-grid">';
		self::field( 'Make', 'make', $bike['make'] ?? '', 'text', true, array( 'maxlength' => 80 ) );
		self::field( 'Model', 'model', $bike['model'] ?? '', 'text', true, array( 'maxlength' => 120 ) );
		self::field( 'Year', 'year', $bike['year'] ?? gmdate( 'Y' ), 'number', true, array( 'min' => 1900, 'max' => 2100, 'step' => 1 ) );
		echo '</div>';
		self::button( $id ? 'Save motorcycle' : 'Add motorcycle' );
		echo '</form>';
		$q = substr( MRSA_Admin::query( 'q' ), 0, 100 );
		$where = $q ? ' WHERE make LIKE %s OR model LIKE %s OR CAST(year AS CHAR) LIKE %s' : '';
		$args = $q ? array_fill( 0, 3, '%' . $wpdb->esc_like( $q ) . '%' ) : array();
		$rows = self::list_page( 'bikes', 'SELECT * FROM ' . MRSA::table( 'bikes' ) . $where . ' ORDER BY make,model,year DESC', $args, 'SELECT COUNT(*) FROM ' . MRSA::table( 'bikes' ) . $where );
		self::table( array( 'ID', 'Make', 'Model', 'Year', 'Source', 'Actions' ), array( 1, 3 ) );
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( 'id', 'make', 'model', 'year', 'source' ) as $key ) {
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
		echo '<h2>Import a motorcycle model</h2><p>Source: <a href="' . esc_url( MRSA_Importer::SOURCE ) . '" target="_blank" rel="noopener noreferrer">NHTSA Product Information Catalog and Vehicle Listing (vPIC) API</a>. Enter one make, exact model, and model year. The request is explicitly restricted to NHTSA records whose vehicle type is Motorcycle; an exact motorcycle match is required before anything is stored. No request is made on activation or page load.</p>';
		self::form( 'source_import', array(), 'mrsa-panel mrsa-edit-form' );
		self::field( 'Make', 'import_make', '', 'text', true, array( 'maxlength' => 80, 'placeholder' => 'Honda' ) );
		self::field( 'Exact model', 'import_model', '', 'text', true, array( 'maxlength' => 120, 'placeholder' => 'Gold Wing' ) );
		self::field( 'Model year', 'import_year', gmdate( 'Y' ), 'number', true, array( 'min' => 1900, 'max' => 2100, 'step' => 1 ) );
		echo '<label><input type="checkbox" name="source_consent" value="1" required> I understand that this sends the entered make and year to NHTSA and imports only an exact model returned as a Motorcycle.</label><p>One HTTPS request, a 256 KB response limit, and no redirects. Unavailable, malformed, non-motorcycle, and inexact results are rejected. Manual motorcycle entry remains available.</p>';
		self::button( 'Import motorcycle model' );
		echo '</form>';
	}

	private static function rider_setup() {
		global $wpdb;
		echo '<h2>Registered riders</h2>';
		$id = absint( MRSA_Admin::query( 'rider_id', '0' ) );
		$global = MRSA::global_access();
		if ( ! $global && $id ) {
			$visible = $wpdb->get_var( $wpdb->prepare( 'SELECT r.id FROM ' . MRSA::table( 'registrations' ) . ' r JOIN ' . MRSA::table( 'event_admins' ) . ' a ON a.event_id=r.event_id WHERE a.user_id=%d AND r.rider_id=%d LIMIT 1', get_current_user_id(), $id ) );
			if ( ! $visible ) {
				wp_die( esc_html( 'Rider not registered in your assigned events.' ), '', array( 'response' => 403 ) );
			}
		}
		$rider = $id ? MRSA::row( 'riders', $id ) : array();
		if ( $id && ! $rider ) {
			wp_die( esc_html( 'Rider not found.' ), '', array( 'response' => 404 ) );
		}
		if ( $global ) {
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
		if ( $id && ! $global ) {
			self::rider_events( $id );
		}
		$q = substr( MRSA_Admin::query( 'q' ), 0, 100 );
		$where = $q ? ' WHERE (r.name LIKE %s OR r.alias LIKE %s OR r.email LIKE %s)' : ' WHERE 1=1';
		$args = $q ? array_fill( 0, 3, '%' . $wpdb->esc_like( $q ) . '%' ) : array();
		if ( ! $global ) {
			$where .= ' AND EXISTS (SELECT 1 FROM ' . MRSA::table( 'registrations' ) . ' reg JOIN ' . MRSA::table( 'event_admins' ) . ' a ON a.event_id=reg.event_id WHERE reg.rider_id=r.id AND a.user_id=%d)';
			$args[] = get_current_user_id();
		}
		$rows = self::list_page( 'riders', 'SELECT r.id,r.name,r.alias,r.email,r.phone FROM ' . MRSA::table( 'riders' ) . ' r' . $where . ' ORDER BY r.name,r.id', $args, 'SELECT COUNT(*) FROM ' . MRSA::table( 'riders' ) . ' r' . $where );
		self::table( array( 'Rider ID', 'Name', 'Alias', 'Email', 'Phone', 'Actions' ) );
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( 'id', 'name', 'alias', 'email', 'phone' ) as $key ) {
				echo '<td>';
				self::h( $row[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			self::link( $global ? 'Edit / events' : 'Read-only / events', $global ? 'rider-edit' : 'riders', array( 'rider_id' => $row['id'] ) );
			echo '</td></tr>';
		}
		self::end_table();
	}

	private static function rider_form( $id, $rider ) {
		global $wpdb;
		self::form( 'rider', array( 'id' => $id ), 'mrsa-panel mrsa-edit-form' );
		echo '<h2>' . ( $id ? 'Edit rider #' . esc_html( $id ) : 'Add Rider' ) . '</h2>';
		foreach ( array( 'name' => array( 'Name', 160 ), 'alias' => array( 'Alias', 100 ), 'email' => array( 'Email (unique)', 254 ), 'phone' => array( 'Phone', 60 ), 'emergency_name' => array( 'Emergency contact name', 160 ), 'emergency_email' => array( 'Emergency contact email', 254 ), 'emergency_phone' => array( 'Emergency contact phone', 60 ) ) as $key => $config ) {
			self::field( $config[0], $key, $rider[ $key ] ?? '', false !== strpos( $key, 'email' ) ? 'email' : ( false !== strpos( $key, 'phone' ) ? 'tel' : 'text' ), in_array( $key, array( 'name', 'email' ), true ), array( 'maxlength' => $config[1] ) );
		}
		self::field( 'Insurance information (multiline)', 'insurance', $rider['insurance'] ?? '', 'textarea' );
		self::field( 'Other information (do not store unnecessary sensitive data)', 'other', $rider['other'] ?? '', 'textarea' );
		echo '<h4>Motorcycles and fuel capacity</h4><p>Choose make, then model, then year. Fuel capacity is optional text; include your preferred units. Multiple motorcycles allowed (up to 20). Without JavaScript, use the full catalog dropdown.</p>';
		$bikes = $wpdb->get_results( 'SELECT id,make,model,year FROM ' . MRSA::table( 'bikes' ) . ' ORDER BY make,model,year DESC', ARRAY_A );
		$owned = $id ? $wpdb->get_results( $wpdb->prepare( 'SELECT bike_id,fuel_capacity FROM ' . MRSA::table( 'rider_bikes' ) . ' WHERE rider_id=%d ORDER BY id', $id ), ARRAY_A ) : array();
		if ( ! $owned ) {
			$owned[] = array( 'bike_id' => '', 'fuel_capacity' => '' );
		}
		echo '<div class="mrsa-bike-rows">';
		foreach ( $owned as $row ) {
			self::bike_row( $bikes, $row );
		}
		echo '</div><template id="mrsa-bike-template">';
		self::bike_row( $bikes, array( 'bike_id' => '', 'fuel_capacity' => '' ) );
		echo '</template><button type="button" class="button mrsa-add-bike">Add motorcycle</button><p>';
		self::button( $id ? 'Save rider' : 'Add Rider' );
		echo '</p></form>';
	}

	public static function render_rider_edit() {
		if ( ! MRSA::access() || ! MRSA::global_access() ) {
			wp_die( esc_html( 'You are not authorized to manage master rider records.' ), '', array( 'response' => 403 ) );
		}
		$id = absint( MRSA_Admin::query( 'rider_id', '0' ) );
		$rider = $id ? MRSA::row( 'riders', $id ) : array();
		if ( $id && ! $rider ) {
			wp_die( esc_html( 'Rider not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>' . esc_html( $id ? 'Edit Rider' : 'Add Rider' ) . '</h2><p><a class="button" href="' . esc_url( MRSA_Admin::url( 'riders' ) ) . '">Back to Rider Registration</a></p>';
		self::notice();
		self::rider_form( $id, $rider );
		if ( $id ) {
			self::rider_events( $id );
		}
		echo '</div>';
	}

	private static function bike_row( $bikes, $row ) {
		echo '<div class="mrsa-bike-row"><div class="mrsa-dependent" hidden><label>Make <select class="mrsa-make" aria-label="Motorcycle make"></select></label><label>Model <select class="mrsa-model" aria-label="Motorcycle model"></select></label><label>Year <select class="mrsa-year" aria-label="Motorcycle year"></select></label></div><label class="mrsa-catalog-label">Catalog motorcycle <select name="bike_ids[]" class="mrsa-catalog"><option value="">No motorcycle</option>';
		foreach ( $bikes as $bike ) {
			echo '<option value="' . esc_attr( $bike['id'] ) . '" data-make="' . esc_attr( $bike['make'] ) . '" data-model="' . esc_attr( $bike['model'] ) . '" data-year="' . esc_attr( $bike['year'] ) . '"' . selected( $row['bike_id'], $bike['id'], false ) . '>' . esc_html( $bike['make'] . ' ' . $bike['model'] . ' (' . $bike['year'] . ')' ) . '</option>';
		}
		echo '</select></label>';
		self::field( 'Fuel capacity', 'fuel[]', $row['fuel_capacity'], 'text', false, array( 'maxlength' => 100, 'placeholder' => 'e.g. 5 US gal or 20 L' ) );
		echo '<button type="button" class="button mrsa-remove-bike">Remove motorcycle</button></div>';
	}

	private static function rider_events( $id ) {
		global $wpdb;
		echo '<h3>Registered events (read-only)</h3><p>Finisher / DNF / DNS / Registered are reserved outcome labels. Version ' . esc_html( MRSA_VERSION ) . ' stores registrations as Registered; scoring, outcomes and placing await defined rules.</p>';
		$sql = 'SELECT reg.*,e.name FROM ' . MRSA::table( 'registrations' ) . ' reg JOIN ' . MRSA::table( 'events' ) . ' e ON e.id=reg.event_id WHERE reg.rider_id=%d';
		$args = array( $id );
		if ( ! MRSA::global_access() ) {
			$sql .= ' AND EXISTS (SELECT 1 FROM ' . MRSA::table( 'event_admins' ) . ' a WHERE a.event_id=e.id AND a.user_id=%d)';
			$args[] = get_current_user_id();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY e.event_date DESC', $args ), ARRAY_A );
		self::table( array( 'Event ID', 'Event', 'Bib / scoring context', 'Pillion', 'Status', 'Score', 'Event placing' ), array( 4 ) );
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
			self::h( $row['status'] );
			echo '</td><td>';
			self::h( $row['score'] . ' (initial value; not scored)' );
			echo '</td><td>';
			self::h( null === $row['placing'] ? '—' : $row['placing'] );
			echo '</td></tr>';
		}
		self::end_table();
	}

	private static function event_setup() {
		$events = self::events();
		echo '<h2>Events</h2>';
		if ( MRSA::global_access() ) {
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
			self::link( 'Registrations', 'event-roster', array( 'event_id' => $event['id'] ) );
			echo ' | ';
			self::link( 'Scoring', 'scoring', array( 'event_id' => $event['id'] ) );
			echo '</td></tr>';
		}
		self::end_table();
		if ( MRSA::global_access() ) {
			self::event_administrators( $events );
		}
	}

	private static function event_administrators( $events ) {
		global $wpdb;
		echo '<h2>Event Administrator Assignments</h2><p>Assigned administrators may configure only their events and edit event bibs and pillion details. Global administrators enroll riders; event administrators cannot browse unenrolled master riders or edit their personal records.</p>';
		if ( $events ) {
			echo '<details class="mrsa-panel"><summary class="button button-primary">Add Administrator</summary>';
			self::form( 'event_admin' );
			self::event_select( $events );
			self::users_select();
			self::button( 'Add Administrator' );
			echo '</form></details>';
		} else {
			echo '<p>Create an event before assigning an event administrator.</p>';
		}
		self::table( array( 'Event', 'Username', 'Display name', 'Email', 'User phone', 'Actions' ), array( 0 ) );
		$rows = $wpdb->get_results( 'SELECT a.*,e.name FROM ' . MRSA::table( 'event_admins' ) . ' a JOIN ' . MRSA::table( 'events' ) . ' e ON e.id=a.event_id ORDER BY e.name', ARRAY_A );
		foreach ( $rows as $row ) {
			$user = get_user_by( 'id', $row['user_id'] );
			echo '<tr><td>';
			self::h( $row['name'] );
			echo '</td><td>';
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
		if ( ( ! $id && ! MRSA::global_access() ) || ( $id && ! MRSA::event_access( $id ) ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		$event = $id ? MRSA::row( 'events', $id ) : array();
		if ( $id && ! $event ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>' . esc_html( $id ? 'Edit Event' : 'Add Event' ) . '</h2><p><a class="button" href="' . esc_url( MRSA_Admin::url( 'events' ) ) . '">Back to Event Setup</a></p>';
		self::notice();
		self::event_form( $id, $event );
		echo '</div>';
	}

	public static function render_event_roster() {
		if ( ! MRSA::access() ) {
			wp_die( esc_html( 'You are not assigned to rally administration.' ), '', array( 'response' => 403 ) );
		}
		$id = absint( MRSA_Admin::query( 'event_id', '0' ) );
		if ( ! MRSA::event_access( $id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		$event = MRSA::row( 'events', $id );
		if ( ! $event ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>Event Roster</h2><p><a class="button" href="' . esc_url( MRSA_Admin::url( 'events' ) ) . '">Back to Event Setup</a> <a class="button" href="' . esc_url( MRSA_Admin::url( 'event-edit', array( 'event_id' => $id ) ) ) . '">Configure Event</a> <a class="button" href="' . esc_url( MRSA_Admin::url( 'scoring', array( 'event_id' => $id ) ) ) . '">Event Scoring</a></p>';
		self::notice();
		self::registrations( $event );
		echo '</div>';
	}

	private static function registrations( $event ) {
		global $wpdb;
		$id = (int) $event['id'];
		echo '<h3>Event Roster — ' . esc_html( $event['name'] ) . '</h3><p>Administrative enrollment is available regardless of the configured registration window. Bibs are unique within this event. Pillion captures the passenger’s name/details, not a separate master rider.</p>';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT reg.*,r.name,r.email,r.phone FROM ' . MRSA::table( 'registrations' ) . ' reg JOIN ' . MRSA::table( 'riders' ) . ' r ON r.id=reg.rider_id WHERE reg.event_id=%d ORDER BY reg.bib', $id ), ARRAY_A );
		$used = array_map( 'intval', array_column( $rows, 'bib' ) );
		if ( MRSA::global_access() ) {
			$available = $wpdb->get_results( $wpdb->prepare( 'SELECT r.id,r.name,r.email FROM ' . MRSA::table( 'riders' ) . ' r WHERE r.email NOT LIKE %s AND NOT EXISTS (SELECT 1 FROM ' . MRSA::table( 'registrations' ) . ' reg WHERE reg.rider_id=r.id AND reg.event_id=%d) ORDER BY r.email', 'erased-%@example.invalid', $id ), ARRAY_A );
			if ( $available && count( $used ) < (int) $event['max_bib'] ) {
				echo '<details class="mrsa-panel"><summary class="button">Add Rider</summary>';
				self::form( 'registration', array( 'event_id' => $id ), 'mrsa-registration-subform' );
				echo '<div class="mrsa-grid"><label class="mrsa-field"><span>Existing rider email (not enrolled in this event)</span><select name="rider_id" required><option value="">Select rider</option>';
				foreach ( $available as $rider ) {
					echo '<option value="' . esc_attr( $rider['id'] ) . '">' . esc_html( $rider['email'] . ' — ' . $rider['name'] ) . '</option>';
				}
				echo '</select></label><label class="mrsa-field"><span>Available bib (1–' . esc_html( $event['max_bib'] ) . ')</span><select name="bib" required>';
				$used_set = array_fill_keys( $used, true );
				for ( $bib = 1; $bib <= (int) $event['max_bib']; ++$bib ) {
					if ( ! isset( $used_set[ $bib ] ) ) {
						echo '<option value="' . esc_attr( $bib ) . '">' . esc_html( $bib ) . '</option>';
					}
				}
				echo '</select></label>';
				self::field( 'Pillion (optional name/details)', 'pillion', '', 'text', false, array( 'maxlength' => 160 ) );
				echo '</div>';
				self::button( 'Enroll rider in this event' );
				echo '</form></details>';
			} else {
				echo '<p>No unenrolled riders or no available bibs. Add master riders in Rider Registration or increase the maximum bib.</p>';
			}
		} else {
			echo '<p>Ask a global administrator to enroll additional master riders. You can edit bib and pillion details for this event below.</p>';
		}
		self::table( array( 'Rider ID', 'Bib / scoring context', 'Name', 'Email', 'Phone', 'Pillion', 'Status', 'Score', 'Event placing', 'Event registration actions' ), array( 6 ) );
		foreach ( $rows as $row ) {
			echo '<tr><td>';
			self::h( $row['rider_id'] );
			echo '</td><td>';
			self::link( $row['bib'], 'scoring', array( 'event_id' => $id, 'score_rider' => $row['rider_id'], 'score_tab' => 'pending' ) );
			echo '</td>';
			foreach ( array( 'name', 'email', 'phone', 'pillion', 'status' ) as $key ) {
				echo '<td>';
				self::h( $row[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			self::h( $row['score'] );
			echo '</td><td>';
			self::h( null === $row['placing'] ? '—' : $row['placing'] );
			echo '</td>';
			echo '<td>';
			echo '<details><summary class="button">Edit</summary>';
			self::form( 'edit_registration', array( 'event_id' => $id, 'id' => $row['id'] ) );
			self::field( 'Bib', 'bib', $row['bib'], 'number', true, array( 'min' => 1, 'max' => $event['max_bib'], 'step' => 1 ) );
			self::field( 'Pillion', 'pillion', $row['pillion'], 'text', false, array( 'maxlength' => 160 ) );
			self::button( 'Save bib / pillion' );
			self::button( 'Unenroll', 'remove_registration' );
			echo '</form></details></td></tr>';
		}
		self::end_table();
	}

	public static function render_scoring() {
		if ( ! MRSA::access() ) {
			wp_die( esc_html( 'You are not assigned to rally administration.' ), '', array( 'response' => 403 ) );
		}
		$events = self::events();
		$id = absint( MRSA_Admin::query( 'event_id', $events ? (string) $events[0]['id'] : '0' ) );
		if ( $id && ! MRSA::event_access( $id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap mrsa">';
		self::header();
		echo '<h2>Event Scoring</h2><p>';
		self::link( 'Return to Event Setup', 'events', $id ? array( 'event_id' => $id ) : array() );
		echo '</p>';
		if ( $events ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="mrsa-panel"><input type="hidden" name="page" value="mrsa-scoring">';
			self::event_select( $events, $id );
			echo '<button class="button">Choose event</button></form>';
		}
		$event = $id ? MRSA::row( 'events', $id ) : null;
		if ( $event ) {
			self::scoring( $event );
		} else {
			echo '<p>Create or select an assigned event to open its scoring context. Scoring functionality is future-defined.</p><ul><li>Bonus Point Library</li><li>Submissions - Pending Review</li><li>Submissions - Reviewed</li><li>Submissions - Contested</li></ul>';
		}
		echo '</div>';
	}

	private static function scoring( $event ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT reg.bib,reg.status,reg.score,reg.placing,r.id,r.name FROM ' . MRSA::table( 'registrations' ) . ' reg JOIN ' . MRSA::table( 'riders' ) . ' r ON r.id=reg.rider_id WHERE reg.event_id=%d ORDER BY reg.bib', $event['id'] ), ARRAY_A );
		echo '<h3>Scoring data — ' . esc_html( $event['name'] ) . '</h3><p>This page shows the current scoring fields for every rider on the event roster. The detailed scoring workflow and page design will be defined later.</p>';
		self::table( array( 'Bib', 'Rider ID', 'Rider', 'Status', 'Score', 'Placing' ), array( 3 ) );
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array( 'bib', 'id', 'name', 'status', 'score' ) as $key ) {
				echo '<td>';
				self::h( $row[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			self::h( null === $row['placing'] ? '—' : $row['placing'] );
			echo '</td></tr>';
		}
		self::end_table();
		$tab = MRSA_Admin::query( 'score_tab', 'library' );
		$tabs = array( 'library' => 'Bonus Point Library', 'pending' => 'Submissions - Pending Review', 'reviewed' => 'Submissions - Reviewed', 'contested' => 'Submissions - Contested' );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'library';
		}
		$rider_id = absint( MRSA_Admin::query( 'score_rider', '0' ) );
		echo '<section id="mrsa-scoring"><h3>Event scoring workspace</h3>';
		if ( $rider_id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT reg.bib,reg.status,r.name,r.id FROM ' . MRSA::table( 'registrations' ) . ' reg JOIN ' . MRSA::table( 'riders' ) . ' r ON r.id=reg.rider_id WHERE reg.event_id=%d AND reg.rider_id=%d', $event['id'], $rider_id ), ARRAY_A );
			if ( ! $row ) {
				wp_die( esc_html( 'The selected rider is not registered in this event.' ), '', array( 'response' => 404 ) );
			}
			echo '<p class="mrsa-note">Filtered context: event ' . esc_html( $event['name'] ) . ' (#' . esc_html( $event['id'] ) . '), rider ' . esc_html( $row['name'] ) . ' (#' . esc_html( $row['id'] ) . '), bib ' . esc_html( $row['bib'] ) . ', status ' . esc_html( $row['status'] ) . '.</p>';
			echo '<p>';
			self::link( 'Clear rider filter', 'scoring', array( 'event_id' => $event['id'], 'score_tab' => $tab ) );
			echo '</p>';
		}
		echo '<nav class="nav-tab-wrapper" aria-label="Scoring workspace">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( MRSA_Admin::url( 'scoring', array( 'event_id' => $event['id'], 'score_rider' => $rider_id, 'score_tab' => $key ) ) . '#mrsa-scoring' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><div class="mrsa-panel"><h4>' . esc_html( $tabs[ $tab ] ) . ' — future defined functionality</h4><p>No bonus rules, submissions, review transitions or scoring algorithm have been defined for v' . esc_html( MRSA_VERSION ) . '. There are no scoring records to display. No scores, finisher decisions or placing are calculated. This event/rider context is reserved for the future workflow.</p></div></section>';
	}

	private static function about() {
		global $wp_version;
		$repository = 'https://github.com/oconnoradv/MotorcycleRallyScoringApp';
		$links = array(
			'Repository'                  => $repository,
			'README'                      => $repository . '/blob/main/README.md',
			'License (GPLv3 or later)'    => $repository . '/blob/main/LICENSE',
			'GitHub Releases'             => $repository . '/releases',
			'Vehicle catalog data source' => MRSA_Importer::SOURCE,
		);
		echo '<h2>About</h2>';
		echo '<p>Motorcycle Rally Scoring Application provides site-scoped administration for rally organizers, motorcycle catalogs, master rider records, event setup, and rider enrollment.</p>';
		echo '<div class="notice notice-warning inline"><p><strong>Version status:</strong> Version ' . esc_html( MRSA_VERSION ) . ' provides setup and registration workflows. The scoring and submission-review tabs are reserved placeholders; this release does not calculate scores, determine finisher status, or assign placing.</p></div>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Version</th><td>' . esc_html( MRSA_VERSION ) . '</td></tr>';
		echo '<tr><th scope="row">Environment</th><td>WordPress ' . esc_html( $wp_version ) . ', PHP ' . esc_html( PHP_VERSION ) . '</td></tr>';
		foreach ( $links as $label => $url ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '<span class="screen-reader-text"> (opens in a new tab)</span></a></td></tr>';
		}
		echo '</table>';
		echo '<p>The plugin is free software released under the GNU General Public License, version 3 or (at your option) any later version. The repository and installable package include the license and documentation.</p>';
		echo '<h2>What this version includes</h2><ul class="ul-disc"><li>Global and event administrator assignments based on existing WordPress users.</li><li>Manual and model-specific NHTSA motorcycle catalog workflows.</li><li>Master rider records with emergency, insurance, and motorcycle details.</li><li>Separate event configuration, roster, and scoring pages with media-library logos, capacity controls, enrollment, pillion details, and unique event bibs.</li></ul>';
		echo '<h2>Catalog source</h2><div class="notice notice-info inline"><p>The optional import uses the <a href="' . esc_url( MRSA_Importer::SOURCE ) . '" target="_blank" rel="noopener noreferrer">NHTSA Product Information Catalog and Vehicle Listing API<span class="screen-reader-text"> (opens in a new tab)</span></a>. It requires a make, exact model, and year and stores only a result explicitly returned with the Motorcycle vehicle type.</p></div>';
		echo '<h2>Privacy and security</h2><p>Collect only necessary rider, emergency-contact, insurance, and pillion details. WordPress personal-data export and erasure tools include plugin rider information. Erasure anonymizes riders and removes motorcycle and pillion details while retaining anonymous registration identifiers and results fields for event integrity.</p><p>Use TLS, least-privilege accounts, secure backups, and supported WordPress, PHP, and database versions. Automated Semgrep and Trivy checks support secure development but do not certify NIST compliance, guarantee that every CVE is absent, or replace an independent security assessment.</p>';
		echo '<h2>Reporting issues</h2><ol><li>Search <a href="' . esc_url( $repository . '/issues' ) . '" target="_blank" rel="noopener noreferrer">existing issues<span class="screen-reader-text"> (opens in a new tab)</span></a> to see whether the problem is already reported.</li><li>If not, <a href="' . esc_url( $repository . '/issues/new' ) . '" target="_blank" rel="noopener noreferrer">open a new issue<span class="screen-reader-text"> (opens in a new tab)</span></a> with a short, descriptive title.</li><li>Describe the expected and actual behavior, steps to reproduce it, the affected tab or workflow, and any error messages or screenshots.</li><li>Include your environment: <code>Plugin ' . esc_html( MRSA_VERSION ) . ', WordPress ' . esc_html( $wp_version ) . ', PHP ' . esc_html( PHP_VERSION ) . '</code>.</li><li>Do not include passwords, rider records, emergency contacts, insurance details, or other sensitive data. Report security vulnerabilities privately to the repository owner rather than in a public issue.</li></ol>';
	}
}
