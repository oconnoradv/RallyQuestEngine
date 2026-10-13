<?php
/** Escaped server-rendered administration views with optional local enhancement. */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-rqe-results.php';
require_once __DIR__ . '/class-rqe-queries.php';

final class RQE_Views {
	private static function h( $value ) {
		echo esc_html( (string) $value );
	}

	private static function year( $value ) {
		return (int) $value ? (string) $value : 'Unspecified';
	}

	private static function queries() {
		global $wpdb;
		return new RQE_Queries( $wpdb );
	}

	private static function link( $label, $tab, $extra = array() ) {
		echo '<a href="' . esc_url( RQE_Admin::url( $tab, $extra ) ) . '">' . esc_html( (string) $label ) . '</a>';
	}

	private static function form( $op, $hidden = array(), $class = '', $id = '', $enctype = '' ) {
		$return_url = RQE_Admin::query( 'return_url' );
		if ( $return_url && ! isset( $hidden['return_url'] ) ) {
			$hidden['return_url'] = $return_url;
		}
		$enroll_event_id = absint( RQE_Admin::query( 'enroll_event_id', '0' ) );
		if ( $enroll_event_id && ! isset( $hidden['enroll_event_id'] ) ) {
			$hidden['enroll_event_id'] = $enroll_event_id;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ( $enctype ? ' enctype="' . esc_attr( $enctype ) . '"' : '' ) . '>';
		wp_nonce_field( 'rqe_save' );
		foreach ( array_merge( array( 'action' => 'rqe_save', 'op' => $op ), $hidden ) as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
	}

	private static function button( $label = 'Save', $op = '' ) {
		echo '<button type="submit" class="button"' . ( $op ? ' name="op" value="' . esc_attr( $op ) . '" data-confirm="Remove this assignment or registration?"' : '' ) . '>' . esc_html( $label ) . '</button>';
	}

	private static function notice() {
		$error = RQE_Admin::query( 'error' );
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		$notice = RQE_Admin::query( 'notice' );
		if ( $notice ) {
			$class = false !== strpos( $notice, 'WARNING: imported without an antivirus security scan' ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $notice ) . '</p></div>';
		}
	}

	private static function header() {
		$id = RQE_Admin::selected_event();
		$events = self::scoring_events();
		$show_archived = RQE_Event_State::show_archived();
		$events = array_values( array_filter( $events, function ( $event ) use ( $show_archived, $id ) {
			return $show_archived || empty( $event['is_archived'] ) || (int) $event['id'] === $id;
		} ) );
		echo '<div class="rqe-header"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span><div><h1>Rally Quest Engine</h1><p>an O&rsquo;ConnorADV project <span>v' . esc_html( RQE_VERSION ) . '</span></p></div>';
		if ( $events ) {
			$tab = RQE_Admin::query( 'tab', 'events' );
			$page = RQE_Admin::query( 'page' );
			if ( in_array( $page, array( 'rqe-rider-score', 'rqe-scoring', 'rqe-bonus-edit' ), true ) ) {
				$tab = 'scoring';
			} elseif ( in_array( $page, array( 'rqe-event-roster', 'rqe-event-enroll', 'rqe-roster-import' ), true ) ) {
				$tab = 'rosters';
			}
			// Two-step chooser: the style narrows the event list (JavaScript); without JavaScript every event remains selectable.
			$style = RQE_Styles::primary();
			foreach ( $events as $event ) {
				if ( (int) $event['id'] === $id && RQE_Styles::valid( $event['hunt_style'] ?? '' ) ) {
					$style = $event['hunt_style'];
				}
			}
			self::form( 'choose_event', array( 'context_tab' => $tab, 'return_url' => '', 'enroll_event_id' => '' ), 'rqe-header-event' );
			echo '<label for="rqe-header-style"><span>Scavenger Hunt Style</span><select id="rqe-header-style" class="rqe-header-style">';
			foreach ( RQE_Styles::all() as $key => $config ) {
				echo '<option value="' . esc_attr( $key ) . '"' . selected( $style, $key, false ) . '>' . esc_html( $config['label'] ) . '</option>';
			}
			echo '</select></label><label for="rqe-header-event"><span>Choose Event</span><select id="rqe-header-event" name="event_id" required><option value="">Choose an event</option>';
			foreach ( $events as $event ) {
				echo '<option value="' . esc_attr( $event['id'] ) . '" data-style="' . esc_attr( RQE_Styles::valid( $event['hunt_style'] ?? '' ) ? $event['hunt_style'] : RQE_Styles::DEFAULT_STYLE ) . '"' . selected( $id, $event['id'], false ) . '>' . esc_html( $event['name'] . ' (' . RQE_Styles::event_code( $event ) . ')' . ( ! empty( $event['is_archived'] ) ? ' — Archived' : '' ) ) . '</option>';
			}
			echo '</select></label>';
			self::button( 'Set Event' );
			echo '</form>';
		}
		self::form( 'archive_visibility', array( 'context_tab' => $tab ?? 'events' ), 'rqe-archive-visibility' );
		echo '<label><input type="checkbox" name="show_archived" value="1"' . checked( $show_archived, true, false ) . '> Show archived events</label> ';
		self::button( 'Apply Visibility' );
		echo '</form>';
		echo '</div><hr class="wp-header-end">';
		$selected = $id ? RQE::row( 'events', $id ) : null;
		if ( $selected && ( ! empty( $selected['is_locked'] ) || ! empty( $selected['is_archived'] ) ) ) {
			echo '<div class="notice notice-info"><p>This event is ' . esc_html( ! empty( $selected['is_archived'] ) ? 'archived and locked' : 'locked' ) . '. Results are read-only. Certificates and enabled public embeds remain available. Only a site administrator can unlock it.</p></div>';
		}
	}

	private static function field( $label, $name, $value = '', $type = 'text', $required = false, $attrs = array() ) {
		echo '<label class="rqe-field"><span>' . esc_html( $label ) . '</span>';
		if ( 'textarea' === $type ) {
			echo '<textarea name="' . esc_attr( $name ) . '" rows="4" maxlength="5000"' . ( $required ? ' required' : '' ) . '>' . esc_textarea( (string) $value ) . '</textarea>';
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
		echo '<div class="rqe-table-region"><div class="rqe-table-tools"><label>Filter loaded rows <input type="search" class="rqe-search"></label><label>Rows per page <select class="rqe-page-size"><option>25</option><option>50</option><option>100</option></select></label></div><div class="rqe-scroll"><table class="widefat striped rqe-table"><thead><tr>';
		foreach ( $headers as $i => $header ) {
			echo '<th scope="col"' . ( in_array( $i, $filters, true ) ? ' data-filter="true"' : '' ) . '>' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';
	}

	private static function end_table() {
		echo '</tbody></table></div><div class="rqe-pagination" aria-live="polite"></div></div>';
	}

	private static function events( $rider_id = 0 ) {
		return self::queries()->events( $rider_id, RQE::site_access(), get_current_user_id() );
	}

	private static function scoring_events() {
		return self::queries()->scoring_events( RQE::site_access(), get_current_user_id() );
	}

	private static function users_select( $selected = 0 ) {
		echo '<label class="rqe-field"><span>Username (all WordPress users)</span><input type="search" class="rqe-user-filter" placeholder="Filter usernames" aria-label="Filter usernames"><select name="user_id" class="rqe-user-select" required><option value="">Choose a user</option>';
		foreach ( get_users( array( 'orderby' => 'login', 'order' => 'ASC' ) ) as $user ) {
			echo '<option value="' . esc_attr( (string) $user->ID ) . '" data-name="' . esc_attr( $user->display_name ) . '" data-email="' . esc_attr( $user->user_email ) . '" data-phone="' . esc_attr( RQE::user_phone( $user->ID ) ) . '"' . selected( $selected, $user->ID, false ) . '>' . esc_html( $user->user_login ) . '</option>';
		}
		echo '</select></label><table class="widefat rqe-profile"><thead><tr><th>Display name (read-only)</th><th>Email (read-only)</th><th>User phone (read-only)</th></tr></thead><tbody><tr><td data-profile="name">—</td><td data-profile="email">—</td><td data-profile="phone">—</td></tr></tbody></table>';
	}

	private static function event_select( $events, $selected = 0, $name = 'event_id' ) {
		echo '<label class="rqe-field"><span>Event</span><select name="' . esc_attr( $name ) . '" required><option value="">Choose an event</option>';
		foreach ( $events as $event ) {
			echo '<option value="' . esc_attr( $event['id'] ) . '"' . selected( $selected, $event['id'], false ) . '>' . esc_html( $event['name'] . ' (' . RQE_Styles::event_code( $event ) . ')' ) . '</option>';
		}
		echo '</select></label>';
	}

	public static function render() {
		if ( ! RQE::access() ) {
			wp_die( esc_html( 'You are not assigned to rally administration.' ), '', array( 'response' => 403 ) );
		}
		$site_admin = RQE::site_access();
		$scorer_only = ! $site_admin && ! RQE::event_ids();
		$default_tab = $site_admin && ! RQE_Setup::readiness()['complete'] ? 'initial-setup' : 'events';
		$tab = RQE_Admin::query( 'tab', $default_tab );
		$style = RQE_Styles::primary();
		$tabs = $site_admin ? array( 'initial-setup' => 'Initial Setup' ) : array();
		$tabs += array( 'riders' => 'Participants', 'events' => 'Events', 'rosters' => 'Rosters', 'scoring' => 'Scoring', 'results' => 'Results' );
		// Conveyance tabs follow the primary style: vehicles for Motorcycle/Bicycle, horses for Horseback, none for GeoCache.
		if ( RQE_Styles::uses_vehicles( $style ) ) {
			$tabs['bikes'] = 'Bikes';
		} elseif ( RQE_Styles::uses_horses( $style ) ) {
			$tabs['horses'] = 'Horses';
		}
		$tabs += array( 'diagnostics' => 'Diagnostics', 'about' => 'About' );
		$shared = array( 'bikes', 'horses', 'diagnostics' );
		if ( $scorer_only && 'events' === $tab ) {
			$tab = 'scoring';
		}
		if ( ! isset( $tabs[ $tab ] ) || ( ! $site_admin && in_array( $tab, $shared, true ) ) || ( $scorer_only && ! in_array( $tab, array( 'scoring', 'about' ), true ) ) ) {
			wp_die( esc_html( 'This shared-data area is restricted to WordPress administrators.' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap rqe">';
		self::header();
		echo '<nav class="nav-tab-wrapper" aria-label="Rally Scoring tabs">';
		foreach ( $tabs as $key => $label ) {
			if ( ( ! $site_admin && in_array( $key, $shared, true ) ) || ( $scorer_only && ! in_array( $key, array( 'scoring', 'about' ), true ) ) ) {
				continue;
			}
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( RQE_Admin::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		self::notice();
		if ( $scorer_only ) {
			echo '<p class="rqe-note">Event Scorer access: review submissions and view scoring data only for your assigned events.</p>';
		} elseif ( ! $site_admin ) {
			echo '<p class="rqe-note">Event administrator access: your assigned events and their enrolled participant details only. Shared participant records, motorcycles, event creation, diagnostics, and role assignments require a WordPress administrator.</p>';
		}
		switch ( $tab ) {
			case 'initial-setup':
				self::initial_setup();
				break;
			case 'bikes':
				self::bike_setup();
				break;
			case 'horses':
				self::horse_setup();
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

	private static function server_search( $tab ) {
		echo '<form method="get" class="rqe-server-search"><input type="hidden" name="page" value="rqe"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><label>Search all records <input type="search" name="q" value="' . esc_attr( RQE_Admin::query( 'q' ) ) . '" maxlength="100"></label> <button class="button">Search</button></form>';
	}

	/** Collapsible summary of a completed configuration area: green and closed once complete. */
	private static function section_open( $title, $complete, $class = '', $complete_label = 'Complete' ) {
		echo '<details class="rqe-panel rqe-section ' . esc_attr( $class ) . ( $complete ? ' rqe-complete' : '' ) . '"' . ( $complete ? '' : ' open' ) . '><summary><h2>' . esc_html( $title ) . '</h2>' . ( $complete ? ' <strong class="rqe-complete-label">' . esc_html( $complete_label ) . '</strong>' : '' ) . '</summary>';
	}

	/** Welcome guidance; the summary tracks the global inbox, a participant and an event. */
	private static function welcome() {
		$complete = RQE_Setup::welcome_complete();
		$repository = 'https://github.com/oconnoradv/RallyQuestEngine';
		self::section_open( 'Welcome to Rally Quest Engine', $complete, 'rqe-welcome', 'Global email configured, participant and event added' );
		echo '<p>Rally Quest Engine helps you produce and manage a high-quality scavenger-hunt style rally for Motorcyclists, Bicyclists, Geocachers or Horseback participants, with efficient setup, focused administration and near-real-time results.</p>';
		echo '<ol class="rqe-welcome-steps"><li><strong>Set up a global submission inbox</strong> and explicitly assign it to one active event. An event-specific inbox can override it.</li><li><strong>Choose a primary scavenger hunt style</strong> to set the default for new events and the available Bikes or Horses tab.</li><li><strong>Create your first event and participant</strong>, then complete event details, roles and the Bonus Point Library.</li><li><strong>Review submissions and results</strong> with event administrators or scorers, and optionally publish results with a shortcode.</li></ol>';
		echo '<p><strong>Need help?</strong> See the About tab, the <a href="' . esc_url( $repository . '/blob/main/README.md' ) . '" target="_blank" rel="noopener noreferrer">README<span class="screen-reader-text"> (opens in a new tab)</span></a> or <a href="' . esc_url( $repository . '/issues' ) . '" target="_blank" rel="noopener noreferrer">GitHub Issues<span class="screen-reader-text"> (opens in a new tab)</span></a>. Do not post personal participant data in public issues.</p>';
		echo '</details>';
	}

	private static function global_inbox_setup() {
		$status = RQE_Email_Submissions::global_status();
		$events = array_values( array_filter( self::scoring_events(), function ( $event ) {
			return empty( $event['is_locked'] ) && empty( $event['is_archived'] );
		} ) );
		$complete = ! empty( $status['connected'] ) && ! empty( $status['enabled'] ) && ! empty( $status['assigned_event_active'] );
		$label = $complete ? 'Verified, polling enabled and assigned to ' . $status['assigned_event'] : 'Connection, polling and event assignment required';
		self::section_open( 'Global Inbox', $complete, 'rqe-global-inbox', $label );
		echo '<p>Use one shared mailbox for one explicitly assigned active event at a time. Assignment is independent of the event selected in the page header. A configured event-specific inbox takes precedence for that event; existing event inboxes remain explicit overrides.</p>';
		if ( $status['configured'] ) {
			echo '<p><strong>Mailbox:</strong> ' . esc_html( $status['mailbox'] ) . ' (' . esc_html( $status['provider_label'] ) . ').</p>';
		} else {
			echo '<p>The global mailbox is not configured. Save credentials and test the provider connection before enabling global polling.</p>';
		}
		if ( $status['connected'] ) {
			echo '<p class="rqe-email-status"><strong>Connection verified:</strong> ' . esc_html( $status['last_tested_at'] ) . ' UTC. The test authenticates only; it does not read or mark messages.</p>';
		} elseif ( $status['last_test_error'] ) {
			echo '<p class="rqe-email-status rqe-email-error"><strong>Connection not verified:</strong> ' . esc_html( $status['last_test_error'] ) . '</p>';
		} elseif ( $status['configured'] ) {
			echo '<p class="rqe-email-status">Configuration saved. Test the connection to verify credentials and mailbox access.</p>';
		}
		if ( $status['configured'] && ! $status['enabled'] ) {
			echo '<p class="rqe-email-error"><strong>Polling is disabled.</strong> Enable scheduled polling in the global inbox configuration before relying on automatic ingestion.</p>';
		}
		echo '<p><a class="button" href="' . esc_url( RQE_Admin::url( 'event-email', array( 'scope' => 'global' ) ) ) . '">Configure Global Email</a></p>';
		self::form( 'global_inbox_assignment', array(), 'rqe-global-assignment' );
		echo '<label class="rqe-field"><span>Assign global inbox to an active event</span><select name="assigned_event_id"><option value="0">Do not assign</option>';
		foreach ( $events as $event ) {
			echo '<option value="' . esc_attr( $event['id'] ) . '"' . selected( $status['assigned_event_id'], $event['id'], false ) . '>' . esc_html( $event['name'] . ' (' . RQE_Styles::event_code( $event ) . ')' ) . '</option>';
		}
		echo '</select></label>';
		if ( ! $events ) {
			echo '<p>Create and save an unlocked, unarchived event before assigning the global inbox.</p>';
		}
		self::button( 'Save Global Inbox Assignment' );
		echo '</form>';
		if ( $status['configured'] ) {
			self::form( 'global_email_test', array(), 'rqe-inline-form' );
			self::button( 'Test Global Inbox Connection' );
			echo '</form>';
		}
		if ( $status['assigned_event_id'] && ! $status['assigned_event_active'] ) {
			echo '<p class="rqe-email-error"><strong>Polling is paused:</strong> the assigned event is locked, archived or no longer available. Assign the inbox to an active event.</p>';
		}
		echo '</details>';
	}

	private static function primary_style_setup() {
		$saved = RQE_Styles::saved_primary();
		self::section_open( 'Primary Style', '' !== $saved, 'rqe-primary-style', '' !== $saved ? RQE_Styles::label( $saved ) . ' saved' : 'Choose and explicitly save a style' );
		echo '<p>The primary style sets the default for new events and controls whether shared participant data uses Bikes, Horses or no conveyance tab.</p>';
		self::form( 'primary_style', array(), 'rqe-style-form' );
		echo '<fieldset><legend>Primary scavenger hunt style</legend>';
		foreach ( RQE_Styles::all() as $key => $config ) {
			echo '<label><input type="radio" name="primary_style" value="' . esc_attr( $key ) . '"' . checked( RQE_Styles::primary(), $key, false ) . ' required> ' . esc_html( $config['label'] . ' (' . $config['code'] . ')' ) . '</label>';
		}
		echo '</fieldset>';
		self::button( 'Save Primary Style' );
		echo '</form></details>';
	}

	private static function initial_setup() {
		if ( ! RQE::site_access() ) {
			wp_die( esc_html( 'Only WordPress administrators may manage Initial Setup.' ), '', array( 'response' => 403 ) );
		}
		echo '<h2>Initial Setup</h2><p>Complete the shared site settings first. Overrides acknowledge readiness only; they do not disable runtime validation or safety checks.</p>';
		self::welcome();
		self::global_inbox_setup();
		self::primary_style_setup();
		self::configuration_check();
	}

	private static function list_page( $tab, array $data, $search = true ) {
		$page = $data['page'];
		$count = $data['count'];
		$rows = $data['rows'];
		if ( $search ) {
			self::server_search( $tab );
		}
		echo '<p>' . esc_html( $count . ' matching records; server page ' . $page . ' of ' . max( 1, (int) ceil( $count / 100 ) ) ) . '. Local filters and sorting apply to this page (up to 100 records). ';
		if ( $page > 1 ) {
			self::link( 'Previous page', $tab, array( 'q' => RQE_Admin::query( 'q' ), 'list_page' => $page - 1 ) );
			echo ' ';
		}
		if ( $page * 100 < $count ) {
			self::link( 'Next page', $tab, array( 'q' => RQE_Admin::query( 'q' ), 'list_page' => $page + 1 ) );
		}
		echo '</p>';
		return $rows;
	}

	private static function bike_setup() {
		// Motorcycle and Bicycle share one catalog; Bicycle hides the NHTSA motorcycle import.
		$bicycle = 'bicycle' === RQE_Styles::primary();
		$noun = $bicycle ? 'bicycle' : 'motorcycle';
		echo '<h2>' . esc_html( ucfirst( $noun ) . ' catalog' ) . '</h2>';
		$id = absint( RQE_Admin::query( 'bike_id', '0' ) );
		$bike = $id ? RQE::row( 'bikes', $id ) : array();
		if ( $id && ! $bike ) {
			wp_die( esc_html( ucfirst( $noun ) . ' not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="rqe-bike-entry">';
		self::form( 'bike', array( 'id' => $id ), 'rqe-panel' );
		echo '<h3>' . esc_html( $id ? 'Edit ' . $noun . ' #' . $id : 'Manual ' . ucfirst( $noun ) . ' Entry' ) . '</h3><div class="rqe-grid">';
		self::field( 'Make', 'make', $bike['make'] ?? '', 'text', true, array( 'maxlength' => 80 ) );
		self::field( 'Model', 'model', $bike['model'] ?? '', 'text', true, array( 'maxlength' => 120 ) );
		echo '</div>';
		self::button( $id ? 'Save ' . $noun : 'Add ' . $noun );
		echo '</form>';
		if ( ! $bicycle ) {
			self::form( 'source_import', array(), 'rqe-panel' );
			echo '<div class="rqe-bike-import-heading"><h3>Import motorcycle models for a make</h3><details class="rqe-url-help"><summary aria-label="Motorcycle import information">Help?</summary><div class="rqe-url-help-content"><p>Source: <a href="' . esc_url( RQE_Importer::SOURCE ) . '" target="_blank" rel="noopener noreferrer">NHTSA Product Information Catalog and Vehicle Listing (vPIC) API<span class="screen-reader-text"> (opens in a new tab)</span></a>. Enter one make. Make/model records from Get Models for Make are stored only when the motorcycle-filtered NHTSA response confirms the same model ID. No year is imported. No request is made on activation or page load.</p><p>Two HTTPS requests, each with a 256 KB response limit and no redirects. Unavailable, malformed, unconfirmed, and non-motorcycle results are rejected. Manual motorcycle entry remains available.</p></div></details></div><div class="rqe-bike-import-controls">';
			self::field( 'Make', 'import_make', '', 'text', true, array( 'maxlength' => 80, 'placeholder' => 'Honda' ) );
			self::button( 'Import motorcycle models' );
			echo '</div><label><input type="checkbox" name="source_consent" value="1" required> I understand that this sends the entered make to NHTSA and imports only models independently confirmed as Motorcycles.</label>';
			echo '</form>';
		}
		echo '</div>';
		$rows = self::list_page( 'bikes', self::queries()->bike_page( RQE_Admin::query( 'q' ), RQE_Admin::query( 'list_page', '1' ) ) );
		echo '<p><label><input type="checkbox" class="rqe-select-all-bikes"> ' . esc_html( 'Select all ' . $noun . ' records loaded on this server page' ) . '</label></p>';
		self::table( array( 'Select', 'ID', 'Make', 'Model', 'Source', 'Actions' ), array( 2 ) );
		foreach ( $rows as $row ) {
			echo '<tr><td><input type="checkbox" name="bike_ids[]" value="' . esc_attr( $row['id'] ) . '" form="rqe-bulk-delete-bikes" aria-label="' . esc_attr( 'Select ' . $noun . ' catalog record ' . $row['id'] ) . '"></td>';
			foreach ( array( 'id', 'make', 'model', 'source' ) as $key ) {
				echo '<td>';
				self::h( $row[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			self::link( 'Edit', 'bikes', array( 'bike_id' => $row['id'] ) );
			self::form( 'delete_bike', array( 'id' => $row['id'] ), 'rqe-inline' );
			echo '<button class="button" data-confirm="Remove this catalog entry? Entries used by participants cannot be removed.">Delete</button></form></td></tr>';
		}
		self::end_table();
		self::form( 'delete_bikes', array(), 'rqe-panel', 'rqe-bulk-delete-bikes' );
		echo '<p>' . esc_html( 'Select multiple catalog rows above, then delete them together. If any selected ' . $noun . ' is assigned to a participant, none are deleted.' ) . '</p><button type="submit" class="button" data-confirm="' . esc_attr( 'Delete all selected ' . $noun . ' catalog records?' ) . '">' . esc_html( 'Delete selected ' . $noun . 's' ) . '</button></form>';
	}

	/** Horseback style: participant-owned horse records (Name, Breed, Sex, Age, Color). */
	private static function horse_setup() {
		echo '<h2>Horses</h2><p>Each horse belongs to one participant. Assign or unassign horses here or on the participant&rsquo;s edit page.</p>';
		$id = absint( RQE_Admin::query( 'horse_id', '0' ) );
		$horse = $id ? RQE::row( 'horses', $id ) : array();
		if ( $id && ! $horse ) {
			wp_die( esc_html( 'Horse not found.' ), '', array( 'response' => 404 ) );
		}
		$owner = $id ? (int) $horse['rider_id'] : absint( RQE_Admin::query( 'rider_id', '0' ) );
		self::form( 'horse', array( 'id' => $id ), 'rqe-panel rqe-edit-form' );
		echo '<h3>' . esc_html( $id ? 'Edit horse #' . $id : 'Add Horse' ) . '</h3><div class="rqe-grid">';
		self::field( 'Name', 'name', $horse['name'] ?? '', 'text', true, array( 'maxlength' => 100 ) );
		self::field( 'Breed', 'breed', $horse['breed'] ?? '', 'text', false, array( 'maxlength' => 80 ) );
		echo '<label class="rqe-field"><span>Sex</span><select name="sex"><option value="">Unspecified</option>';
		foreach ( RQE_Horses::sexes() as $sex ) {
			echo '<option value="' . esc_attr( $sex ) . '"' . selected( $horse['sex'] ?? '', $sex, false ) . '>' . esc_html( $sex ) . '</option>';
		}
		echo '</select></label>';
		self::field( 'Age (years)', 'age', isset( $horse['age'] ) && null !== $horse['age'] ? $horse['age'] : '', 'number', false, array( 'min' => 0, 'max' => 60, 'step' => 1 ) );
		self::field( 'Color', 'color', $horse['color'] ?? '', 'text', false, array( 'maxlength' => 60 ) );
		echo '<label class="rqe-field"><span>Participant (owner)</span><select name="rider_id"><option value="0">Unassigned</option>';
		foreach ( self::queries()->diagnostic_riders() as $rider ) {
			echo '<option value="' . esc_attr( $rider['id'] ) . '"' . selected( $owner, (int) $rider['id'], false ) . '>' . esc_html( $rider['name'] . ' (#' . $rider['id'] . ')' ) . '</option>';
		}
		echo '</select></label></div>';
		self::button( $id ? 'Save horse' : 'Add Horse' );
		echo '</form>';
		self::table( array( 'ID', 'Name', 'Breed', 'Sex', 'Age', 'Color', 'Participant', 'Actions' ), array( 3, 6 ) );
		foreach ( self::queries()->horses() as $row ) {
			echo '<tr>';
			foreach ( array( 'id', 'name', 'breed', 'sex', 'age', 'color' ) as $key ) {
				echo '<td>';
				self::h( null === $row[ $key ] ? '' : $row[ $key ] );
				echo '</td>';
			}
			echo '<td>';
			self::h( $row['rider_id'] ? $row['rider_name'] . ' (#' . $row['rider_id'] . ')' : 'Unassigned' );
			echo '</td><td>';
			self::link( 'Edit', 'horses', array( 'horse_id' => $row['id'] ) );
			self::form( 'delete_horse', array( 'id' => $row['id'] ), 'rqe-inline' );
			echo '<button class="button" data-confirm="Delete this horse record?">Delete</button></form></td></tr>';
		}
		self::end_table();
	}
	private static function rider_setup() {
		echo '<h2>Registered participants</h2>';
		$id = absint( RQE_Admin::query( 'rider_id', '0' ) );
		$site_admin = RQE::site_access();
		if ( ! $site_admin && $id ) {
			$visible = self::queries()->rider_visible( $id, get_current_user_id() );
			if ( ! $visible ) {
				wp_die( esc_html( 'Participant not registered in your assigned events.' ), '', array( 'response' => 403 ) );
			}
		}
		$rider = $id ? RQE::row( 'riders', $id ) : array();
		if ( $id && ! $rider ) {
			wp_die( esc_html( 'Participant not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="rqe-riders-toolbar">';
		if ( $site_admin ) {
			echo '<p><a class="button button-primary" href="' . esc_url( RQE_Admin::url( 'rider-edit' ) ) . '">Add Participant</a></p>';
		}
		self::server_search( 'riders' );
		echo '</div>';
		if ( ! $site_admin && $id ) {
			self::table( array( 'Participant ID', 'Name', 'Alias', 'Email', 'Phone' ) );
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
		$rows = self::list_page( 'riders', self::queries()->rider_page( RQE_Admin::query( 'q' ), RQE_Admin::query( 'list_page', '1' ), $site_admin, get_current_user_id() ), false );
		self::table( array( 'Participant ID', 'Name', 'Alias', 'Email', 'Phone', 'Actions' ) );
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
			echo '<a href="' . esc_url( RQE_Admin::url( 'rosters', array( 'rider_id' => $row['id'] ) ) ) . '">Events</a> | ';
			echo '<a href="' . esc_url( RQE_Admin::url( 'results', array( 'rider_id' => $row['id'] ) ) ) . '">Results</a>';
			echo '</td></tr>';
		}
		self::end_table();
	}

	private static function rider_form( $id, $rider ) {
		self::form( 'rider', array( 'id' => $id ), 'rqe-panel rqe-edit-form' );
		echo '<h2>' . ( $id ? 'Edit participant #' . esc_html( $id ) : 'Add Participant' ) . '</h2>';
		foreach ( array( 'name' => array( 'Name', 160 ), 'alias' => array( 'Alias', 100 ), 'email' => array( 'Email (unique)', 254 ), 'phone' => array( 'Phone', 60 ), 'emergency_name' => array( 'Emergency contact name', 160 ), 'emergency_email' => array( 'Emergency contact email', 254 ), 'emergency_phone' => array( 'Emergency contact phone', 60 ) ) as $key => $config ) {
			self::field( $config[0], $key, $rider[ $key ] ?? '', false !== strpos( $key, 'email' ) ? 'email' : ( false !== strpos( $key, 'phone' ) ? 'tel' : 'text' ), in_array( $key, array( 'name', 'email' ), true ), array( 'maxlength' => $config[1] ) );
		}
		self::field( 'Insurance information (multiline)', 'insurance', $rider['insurance'] ?? '', 'textarea' );
		self::field( 'Other information (do not store unnecessary sensitive data)', 'other', $rider['other'] ?? '', 'textarea' );
		$style = RQE_Styles::primary();
		if ( RQE_Styles::uses_vehicles( $style ) ) {
			$fuel = RQE_Styles::uses_fuel( $style );
			$noun = $fuel ? 'motorcycle' : 'bicycle';
			echo '<input type="hidden" name="vehicle_selection" value="1"><h4>' . esc_html( $fuel ? 'Motorcycles and fuel capacity' : 'Bicycles' ) . '</h4><p>' . esc_html( 'Choose a catalog make and model, then optionally enter the participant’s four-digit model year. The year is not validated against the catalog. ' . ( $fuel ? 'Fuel capacity is optional text; include your preferred units. ' : 'Fuel capacity does not apply to bicycles; any previously saved value is retained. ' ) . 'Multiple ' . $noun . 's allowed (up to 20). Without JavaScript, use the full catalog dropdown.' ) . '</p>';
			$motorcycles = self::queries()->rider_bikes( $id );
			$bikes = $motorcycles['catalog'];
			$owned = $motorcycles['owned'];
			if ( ! $owned ) {
				$owned[] = array( 'bike_id' => '', 'model_year' => '', 'fuel_capacity' => '' );
			}
			echo '<div class="rqe-bike-rows">';
			foreach ( $owned as $row ) {
				self::bike_row( $bikes, $row, $fuel );
			}
			echo '</div><template id="rqe-bike-template">';
			self::bike_row( $bikes, array( 'bike_id' => '', 'model_year' => '', 'fuel_capacity' => '' ), $fuel );
			echo '</template><button type="button" class="button rqe-add-bike">' . esc_html( 'Add ' . $noun ) . '</button>';
		} elseif ( RQE_Styles::uses_horses( $style ) ) {
			echo '<input type="hidden" name="horse_selection" value="1"><h4>Horses</h4><p>Select this participant&rsquo;s horses. Only unassigned horses and horses already owned by this participant are listed. Add or edit horse details on the <a href="' . esc_url( RQE_Admin::url( 'horses', $id ? array( 'rider_id' => $id ) : array() ) ) . '">Horses tab</a>.</p>';
			$horses = self::queries()->rider_horses( $id );
			if ( ! $horses ) {
				echo '<p>No available horses.</p>';
			}
			echo '<ul class="rqe-horse-choices">';
			foreach ( $horses as $horse ) {
				$details = array_filter( array( $horse['breed'], $horse['sex'], null === $horse['age'] ? '' : $horse['age'] . ' years', $horse['color'] ), 'strlen' );
				echo '<li><label><input type="checkbox" name="horse_ids[]" value="' . esc_attr( $horse['id'] ) . '"' . checked( $id && (int) $horse['rider_id'] === $id, true, false ) . '> ' . esc_html( $horse['name'] . ( $details ? ' (' . implode( ', ', $details ) . ')' : '' ) ) . '</label></li>';
			}
			echo '</ul>';
		}
		echo '<p>';
		self::button( $id ? 'Save participant' : 'Add Participant' );
		echo '</p></form>';
	}

	public static function render_rider_edit() {
		if ( ! RQE::access() ) {
			wp_die( esc_html( 'You are not authorized to view participant records.' ), '', array( 'response' => 403 ) );
		}
		$site_admin = RQE::site_access();
		$id = absint( RQE_Admin::query( 'rider_id', '0' ) );
		$rider = $id ? RQE::row( 'riders', $id ) : array();
		if ( $id && ! $rider ) {
			wp_die( esc_html( 'Participant not found.' ), '', array( 'response' => 404 ) );
		}
		if ( ! $site_admin ) {
			$visible = $id ? self::queries()->rider_visible( $id, get_current_user_id() ) : false;
			if ( ! $visible ) {
				wp_die( esc_html( 'Participant not registered in your assigned events.' ), '', array( 'response' => 403 ) );
			}
		}
		echo '<div class="wrap rqe">';
		self::header();
		$return_url = RQE_Admin::query( 'return_url' );
		$back_url = $return_url ? wp_validate_redirect( $return_url, RQE_Admin::url( 'riders' ) ) : RQE_Admin::url( 'riders' );
		echo '<h2>' . esc_html( $site_admin ? ( $id ? 'Edit Participant' : 'Add Participant' ) : 'Participant Details' ) . '</h2><p><a class="button" href="' . esc_url( $back_url ) . '">' . esc_html( $return_url ? 'Back to Event Roster' : 'Back to Participants' ) . '</a></p>';
		self::notice();
		if ( $site_admin ) {
			self::rider_form( $id, $rider );
		} else {
			self::table( array( 'Participant ID', 'Name', 'Alias', 'Email', 'Phone' ) );
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

	private static function bike_row( $bikes, $row, $fuel = true ) {
		echo '<div class="rqe-bike-row"><div class="rqe-dependent" hidden><label>Make <select class="rqe-make" aria-label="Motorcycle make"></select></label><label>Model <select class="rqe-model" aria-label="Motorcycle model"></select></label></div><label class="rqe-catalog-label">Catalog motorcycle <select name="bike_ids[]" class="rqe-catalog"><option value="">No motorcycle</option>';
		foreach ( $bikes as $bike ) {
			echo '<option value="' . esc_attr( $bike['id'] ) . '" data-make="' . esc_attr( $bike['make'] ) . '" data-model="' . esc_attr( $bike['model'] ) . '"' . selected( $row['bike_id'], $bike['id'], false ) . '>' . esc_html( $bike['make'] . ' ' . $bike['model'] ) . '</option>';
		}
		echo '</select></label>';
		self::field( 'Year (optional)', 'model_year[]', (int) ( $row['model_year'] ?? 0 ) ?: '', 'number', false, array( 'min' => 1000, 'max' => 9999, 'step' => 1, 'placeholder' => 'YYYY', 'inputmode' => 'numeric' ) );
		if ( $fuel ) {
			self::field( 'Fuel capacity', 'fuel[]', $row['fuel_capacity'], 'text', false, array( 'maxlength' => 100, 'placeholder' => 'e.g. 5 US gal or 20 L' ) );
		} else {
			// Bicycle style: fuel is greyed out; a hidden copy preserves any value saved under the Motorcycle style.
			echo '<input type="hidden" name="fuel[]" value="' . esc_attr( (string) $row['fuel_capacity'] ) . '">';
			self::field( 'Fuel capacity (not applicable)', 'fuel_display[]', '', 'text', false, array( 'disabled' => 'disabled', 'class' => 'rqe-disabled-field', 'placeholder' => 'Not used for bicycles' ) );
		}
		echo '<button type="button" class="button rqe-remove-bike">' . esc_html( $fuel ? 'Remove motorcycle' : 'Remove bicycle' ) . '</button></div>';
	}

	private static function rider_events( $id ) {
		echo '<h3>Registered events (read-only)</h3><p>Event Status is calculated automatically unless an administrator assigns a manual status on Results.</p>';
		$rows = self::queries()->rider_events( $id, RQE::site_access(), get_current_user_id() );
		self::table( array( 'Event ID', 'Event', 'Bib / scoring context', 'Pillion', 'Event Status', 'Score', 'Overall Placing' ), array( 4 ) );
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
		if ( RQE::site_access() ) {
			echo '<p><a class="button button-primary" href="' . esc_url( RQE_Admin::url( 'event-edit' ) ) . '">Add Event</a></p>';
		}
		self::table( array( 'ID', 'Name', 'Date', 'Location', 'Max bib', 'Actions' ), array( 2, 3 ) );
		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td>';
			self::h( RQE_Styles::event_code( $event ) );
			echo '</td>';
			foreach ( array( 'name', 'event_date', 'location', 'max_bib' ) as $key ) {
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
		echo '<h2>Event Administrator Assignments</h2><p>Assigned administrators may configure only their events and use their event rosters and scoring tools. WordPress administrators manage assignments, shared participant records, and enrollment.</p>';
		echo '<details class="rqe-panel"><summary class="button button-primary">Add Administrator</summary>';
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
			self::h( $user ? RQE::user_phone( $user->ID ) : '—' );
			echo '</td><td>';
			self::form( 'remove_event_admin', array( 'user_id' => $row['user_id'], 'event_id' => $row['event_id'] ) );
			self::button( 'Remove', 'remove_event_admin' );
			echo '</form></td></tr>';
		}
		self::end_table();
	}

	private static function event_scorers( $event ) {
		echo '<h2>Event Scorer Assignments</h2><p>Scorers can review and score email submissions for this event. They cannot configure events, manage rosters, or access shared participant and motorcycle data.</p>';
		echo '<details class="rqe-panel"><summary class="button button-primary">Add Scorer</summary>';
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
			self::h( $user ? RQE::user_phone( $user->ID ) : '—' );
			echo '</td><td>';
			self::form( 'remove_event_scorer', array( 'user_id' => $row['user_id'], 'event_id' => $row['event_id'] ) );
			self::button( 'Remove', 'remove_event_scorer' );
			echo '</form></td></tr>';
		}
		self::end_table();
	}

	/** True when every field required to save an event has a stored value. */
	public static function event_details_complete( $event ) {
		if ( empty( $event['id'] ) || '' === trim( (string) ( $event['description'] ?? '' ) ) ) {
			return false;
		}
		foreach ( array( 'name', 'max_bib', 'registration_open', 'registration_close', 'start_at', 'end_at', 'rider_price', 'min_finisher_points' ) as $key ) {
			if ( ! isset( $event[ $key ] ) || '' === trim( (string) $event[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function event_form( $id, $event ) {
		$style = RQE_Styles::valid( $event['hunt_style'] ?? '' ) ? $event['hunt_style'] : RQE_Styles::primary();
		self::section_open( $id ? 'Event Details: ' . RQE_Styles::event_code( $event ) : 'Add Event', self::event_details_complete( $event ), 'rqe-event-details', 'Required details saved' );
		self::form( 'event', array( 'id' => $id ), 'rqe-edit-form' );
		echo '<p>Fields marked required must be completed to save the event. Dates/times use the WordPress site timezone: ' . esc_html( wp_timezone_string() ) . '. Registration open ≤ close ≤ start ≤ end. The event date is derived from its start time. Prices use your event’s currency; no payments are processed.</p>';
		self::field( 'Event name (required)', 'name', $event['name'] ?? '', 'text', true, array( 'maxlength' => 160 ) );
		self::field( 'Event description (required)', 'description', $event['description'] ?? '', 'textarea', true );
		echo '<label class="rqe-field"><span>Scavenger hunt style</span><select name="hunt_style" required>';
		foreach ( RQE_Styles::all() as $key => $config ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $style, $key, false ) . '>' . esc_html( $config['label'] . ' (' . $config['code'] . ')' ) . '</option>';
		}
		echo '</select></label>';
		self::field( 'Maximum bib (1–10000, required)', 'max_bib', $event['max_bib'] ?? 100, 'number', true, array( 'min' => 1, 'max' => 10000, 'step' => 1 ) );
		foreach ( array( 'registration_open' => 'Registration opens', 'registration_close' => 'Registration closes', 'start_at' => 'Event starts', 'end_at' => 'Event ends' ) as $key => $label ) {
			$value = empty( $event[ $key ] ) ? '' : str_replace( ' ', 'T', substr( $event[ $key ], 0, 16 ) );
			self::field( $label . ' (required)', $key, $value, 'datetime-local', true );
		}
		self::field( 'Participant price (required)', 'rider_price', $event['rider_price'] ?? '', 'number', true, array( 'min' => 0, 'max' => '99999999.99', 'step' => '0.01' ) );
		self::field( 'Pillion price', 'pillion_price', $event['pillion_price'] ?? 0, 'number', false, array( 'min' => 0, 'max' => '99999999.99', 'step' => '0.01' ) );
		self::field( 'Location', 'location', $event['location'] ?? '', 'text', false, array( 'maxlength' => 200 ) );
		self::field( 'Latitude', 'latitude', $event['latitude'] ?? '', 'number', false, array( 'min' => -90, 'max' => 90, 'step' => '0.0000001' ) );
		self::field( 'Longitude', 'longitude', $event['longitude'] ?? '', 'number', false, array( 'min' => -180, 'max' => 180, 'step' => '0.0000001' ) );
		self::field( 'Minimum Finisher Points (required)', 'min_finisher_points', $event['min_finisher_points'] ?? '', 'number', true, array( 'min' => 0, 'max' => 1000000000, 'step' => 1 ) );
		foreach ( RQE_Results::tiers() as $key => $status ) {
			self::field( str_replace( 'Preliminary ', '', $status ) . ' Points (optional)', $key, $event[ $key ] ?? '', 'number', false, array( 'min' => 0, 'max' => 1000000000, 'step' => 1, 'placeholder' => 'Not Used' ) );
		}
		echo '<p>Leave optional finisher thresholds blank for <strong>Not Used</strong>. Each configured tier must exceed the highest configured lower threshold. Scores equal to or greater than a threshold qualify; manual Event Status assignments override automatic tiers.</p>';
		echo '<label class="rqe-field"><span>Finisher Certificate Design</span><select name="certificate_design" required>';
		foreach ( RQE_Certificates::designs() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $event['certificate_design'] ?? 'vintage', $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><p>The selected design applies to this event\'s printed, downloaded and emailed certificates. Medals follow each participant\'s actual Bronze, Silver or Gold status. Changing the design requires fresh email review.</p>';
		echo '<div class="rqe-logo">';
		self::field( 'Existing media image ID (0 = none)', 'logo_id', $event['logo_id'] ?? 0, 'number', false, array( 'min' => 0, 'step' => 1 ) );
		echo '<button type="button" class="button rqe-media">Select logo in WordPress media library</button><button type="button" class="button rqe-clear-media">Clear logo</button><div class="rqe-logo-preview">';
		if ( ! empty( $event['logo_id'] ) ) {
			echo wp_get_attachment_image( (int) $event['logo_id'], 'thumbnail' );
		}
		echo '</div></div>';
		if ( ! current_user_can( 'upload_files' ) ) {
			echo '<p>You can select an existing image by ID. Media uploads require the WordPress upload_files capability; this plugin does not grant it.</p>';
		}
		self::button( $id ? 'Save event configuration' : 'Add Event' );
		echo '</form></details>';
	}

	/** Administrator and Scorer assignments; green and collapsed once at least one person is assigned. */
	private static function event_roles( $event ) {
		$assigned = count( self::queries()->event_administrators( $event['id'] ) ) + count( self::queries()->event_scorers( $event['id'] ) );
		self::section_open( 'Event Administrators and Scorers', $assigned > 0, 'rqe-event-roles', $assigned . ' assigned' );
		self::event_administrators( $event );
		self::event_scorers( $event );
		echo '</details>';
	}

	public static function render_event_edit() {
		if ( ! RQE::access() ) {
			wp_die( esc_html( 'You are not assigned to rally administration.' ), '', array( 'response' => 403 ) );
		}
		$id = absint( RQE_Admin::query( 'event_id', '0' ) );
		if ( ( ! $id && ! RQE::site_access() ) || ( $id && ! RQE::event_access( $id ) ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		$event = $id ? RQE::row( 'events', $id ) : array();
		if ( $id && ! $event ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap rqe">';
		self::header();
		echo '<h2>' . esc_html( $id ? 'Edit Event' : 'Add Event' ) . '</h2><p><a class="button" href="' . esc_url( RQE_Admin::url( 'events' ) ) . '">Back to Events</a></p>';
		self::notice();
		echo '<div class="rqe-event-columns"><div>';
		self::event_form( $id, $event );
		if ( $id ) {
			if ( RQE::site_access() ) {
				self::event_roles( $event );
			}
			self::public_results_embed( $event );
		}
		echo '</div><div>';
		if ( $id ) {
			$global_status = RQE_Email_Submissions::global_status();
			$assigned = $global_status['assigned_event_id'] === $id && $global_status['assigned_event_active'];
			echo '<section class="rqe-panel rqe-global-email-summary" aria-labelledby="rqe-global-email-heading"><h2 id="rqe-global-email-heading">Email Setup</h2><div class="rqe-global-email-readonly"><strong>Global inbox (read-only)</strong>';
			if ( $global_status['configured'] ) {
				echo '<p>' . esc_html( $global_status['mailbox'] . ' — ' . $global_status['provider_label'] ) . '</p>';
				echo '<p>' . esc_html( $assigned ? 'Assigned to this event' : ( $global_status['assigned_event'] ? 'Assigned to ' . $global_status['assigned_event'] : 'Not assigned to this event' ) ) . '</p>';
				echo '<p>' . esc_html( $global_status['connected'] ? 'Connection verified ' . $global_status['last_tested_at'] . ' UTC' : 'Connection not verified' ) . '</p>';
			} else {
				echo '<p>Global email is not configured.</p>';
			}
			echo '</div>';
			if ( RQE::site_access() ) {
				echo '<p><a href="' . esc_url( RQE_Admin::url( 'initial-setup' ) ) . '">Manage global inbox assignment and credentials</a></p>';
			} else {
				echo '<p>Only a WordPress administrator can manage the global inbox.</p>';
			}
			echo '</section>';
		}
		if ( $id && ! RQE::site_access() ) {
			echo '<section class="rqe-panel rqe-email-panel"><h2>' . esc_html__( 'Event Inbox Override', 'rally-quest-engine' ) . '</h2><p>' . esc_html__( 'Only WordPress administrators may configure or test an event-specific inbox. Existing event mailboxes continue to override the global inbox.', 'rally-quest-engine' ) . '</p></section>';
		} elseif ( $id ) {
			$email_status = RQE_Email_Submissions::configuration_status( $id );
			$has_override = RQE_Email_Submissions::has_event_override( $id );
			$ready = $email_status['connected'];
			$panel_class = $ready ? ' rqe-email-connected' : ( $email_status['last_test_error'] ? ' rqe-email-connection-failed' : '' );
			echo '<details class="rqe-panel rqe-email-panel' . esc_attr( $panel_class ) . '"' . ( $ready ? '' : ' open' ) . '><summary><h2>Event Inbox Override</h2>' . ( $ready ? ' <strong>Connection verified</strong>' : '' ) . '</summary><p>Configure an independent mailbox for this event. A saved event inbox takes precedence over the global inbox and is tested separately.</p><div class="rqe-email-actions"><a class="button button-primary" href="' . esc_url( RQE_Admin::url( 'event-email', array( 'event_id' => $id ) ) ) . '">Configure Event Inbox Override</a>';
			if ( $email_status['configured'] ) {
				echo '<span class="rqe-email-summary"><strong>' . esc_html( $email_status['mailbox'] ) . '</strong><span>' . esc_html( $email_status['provider_label'] ) . '</span></span>';
				self::form( 'event_email_test', array( 'event_id' => $id ), 'rqe-inline-form' );
				self::button( 'Test Event Override' );
				echo '</form>';
			}
			if ( $has_override ) {
				self::form( 'event_email_clear', array( 'event_id' => $id ), 'rqe-inline-form' );
				echo '<button type="submit" class="button" data-confirm="Remove this saved event inbox override and its credentials?">Remove Event Inbox Override</button></form>';
			}
			echo '</div>';
			if ( $email_status['connected'] ) {
				echo '<p class="rqe-email-status"><strong>Connection verified:</strong> ' . esc_html( $email_status['last_tested_at'] ) . ' UTC</p>';
			} elseif ( $email_status['last_test_error'] ) {
				echo '<p class="rqe-email-status"><strong>Connection test failed:</strong> ' . esc_html( $email_status['last_test_error'] ) . '</p>';
			} elseif ( $email_status['configured'] ) {
				echo '<p class="rqe-email-status">Configuration saved. Test the event override to verify credentials and mailbox access. Saving invalidates its previous connection test.</p>';
			} elseif ( $has_override ) {
				echo '<p class="rqe-email-error">A saved event override is incomplete or unreadable. It still takes precedence over the global inbox until removed and must be reconfigured or removed.</p>';
			} else {
				echo '<p class="rqe-email-status">No event override is saved. This event receives the global inbox only when explicitly assigned to it.</p>';
			}
			if ( $has_override && ! $email_status['enabled'] ) {
				echo '<p class="rqe-email-error"><strong>Event override polling is disabled.</strong> This explicit override takes precedence over the global inbox, so this event will not poll either inbox until the override is enabled or removed.</p>';
			}
			echo '</details>';
		}
		if ( $id ) {
			self::status_catalog( $id, 'event', 'Event Status' );
			self::status_catalog( $id, 'registration', 'Registration Status' );
		}
		echo '</div></div></div>';
	}

	private static function public_results_embed( $event ) {
		$id = (int) $event['id'];
		echo '<details class="rqe-panel rqe-embed-panel"><summary><h2>Embed this Event on a Post or Page</h2></summary><p>Publish this event&rsquo;s overall results in a WordPress post or page. Publication is off by default; enabling it allows anyone to view participant names, bibs, scores, accepted/rejected submission counts, Event Status and placing. The event description and image are shown above the results. Contact details, Registration Status and administrative controls are never published. Accepted submission details and submitted proof photos require the separate opt-in below.</p>';
		$code = RQE_Styles::event_code( $event );
		self::field( 'RQE Event ID', 'rqe_embed_event_id', $code, 'text', false, array( 'readonly' => 'readonly' ) );
		self::field( 'Results shortcode (copy into a WordPress Shortcode block)', 'rqe_results_shortcode', '[rqe_results id="' . $code . '"]', 'text', false, array( 'readonly' => 'readonly' ) );
		echo '<p>In the WordPress post/page editor, add a <strong>Shortcode</strong> block, paste the shortcode above, then publish or update the post. Results use this explicit Event ID, not the event selected in the administrator header. Disabling public results stops new renders; purge WordPress/CDN page caches to remove previously cached results. Scores and status changes also require cache refreshes on cached pages.</p>';
		self::form( 'public_results', array( 'event_id' => $id ), 'rqe-inline-form' );
		echo '<label><input type="checkbox" name="public_results_enabled" value="1"' . ( ! empty( $event['public_results_enabled'] ) ? ' checked' : '' ) . '> Enable public results for this event</label> ';
		echo '<p><label><input type="checkbox" name="public_bonus_details_enabled" value="1"' . ( ! empty( $event['public_bonus_details_enabled'] ) ? ' checked' : '' ) . '> ' . esc_html__( 'Enable public Bib links to accepted bonus submissions and submitted proof photos (requires public results)', 'rally-quest-engine' ) . '</label></p><p><strong>' . esc_html__( 'Privacy warning:', 'rally-quest-engine' ) . '</strong> ' . esc_html__( 'Opting in makes accepted submitted photos public to anyone visiting the results page. Review participant consent and photos before enabling. Pending/rejected submissions and private message/reviewer details are excluded. Disabling either setting stops new detail renders, but previously shared direct upload URLs remain accessible and downloaded copies cannot be revoked. Purge WordPress/CDN page and image caches; cached copies may persist. Uncheck accepted details before disabling public results.', 'rally-quest-engine' ) . '</p>';
		self::button( 'Save Public Results Setting' );
		echo '</form></details>';
	}

	private static function status_catalog( $event_id, $kind, $title ) {
		echo '<details class="rqe-panel rqe-status-catalog"><summary><h2>' . esc_html( $title ) . '</h2></summary><p>Manage this event&rsquo;s status choices (maximum ' . ( 'event' === $kind ? '64' : '20' ) . ' bytes). Disabled values remain on existing participants but cannot be newly assigned. In-use values cannot be deleted.</p>';
		$event = RQE::row( 'events', $event_id );
		echo '<fieldset' . ( ! empty( $event['is_locked'] ) || ! empty( $event['is_archived'] ) ? ' disabled' : '' ) . '>';
		if ( 'event' === $kind ) {
			echo '<p>Automatic is a calculation mode. It uses Registered or the highest Preliminary Finisher tier whose configured threshold is met or exceeded: Minimum, Bronze, Silver or Gold. Unused tiers are skipped. These system statuses and DNS cannot be deleted; disabling them affects manual choices only. Changing Registration Status to Cancelled sets manual Event Status to DNS. Manual assignments remain authoritative until returned to Automatic. At the end of the event, manually assign Finisher, Bronze Finisher, Silver Finisher, Gold Finisher, DNF or DNS on Results. Mark custom final finisher statuses certificate eligible below.</p>';
		} else {
			echo '<p>Keep at least one value enabled. New participants default to Initial Registration when enabled, otherwise the first enabled value. Changing Registration Status to Cancelled sets Event Status to DNS. Saving an unchanged Cancelled value or changing registration away from Cancelled does not overwrite a later manual Event Status.</p>';
		}
		echo '<ul class="rqe-status-options">';
		$ordered_options = RQE_Statuses::options( $event_id, $kind );
		foreach ( $ordered_options as $index => $option ) {
			$used = RQE_Statuses::usage( $event_id, $kind, $option['label'] );
			$protected = 'event' === $kind && in_array( $option['label'], RQE_Statuses::protected_labels(), true );
			echo '<li><strong>' . esc_html( $option['label'] ) . '</strong> <span>' . esc_html( ( $option['enabled'] ? 'Enabled' : 'Disabled' ) . ' / ' . $used . ' participants' ) . '</span>';
			self::form( 'status_option', array( 'event_id' => $event_id, 'status_kind' => $kind, 'status_label' => $option['label'] ), 'rqe-inline-form' );
			echo '<button class="button" name="status_action" value="' . ( $option['enabled'] ? 'disable' : 'enable' ) . '">' . ( $option['enabled'] ? 'Disable' : 'Enable' ) . '</button>';
			echo ' <button class="button" name="status_action" value="up"' . ( 0 === $index ? ' disabled' : '' ) . '>Move Up</button> <button class="button" name="status_action" value="down"' . ( count( $ordered_options ) - 1 === $index ? ' disabled' : '' ) . '>Move Down</button>';
			if ( 'event' === $kind && in_array( strtolower( $option['label'] ), array_map( 'strtolower', RQE_Statuses::final_finishers() ), true ) ) {
				echo ' <span>Certificate Eligible: Always</span>';
			} elseif ( 'event' === $kind && ! $protected && 'DNF' !== $option['label'] ) {
				echo ' <button class="button" name="status_action" value="' . ( ! empty( $option['certificate_eligible'] ) ? 'certificate_off' : 'certificate_on' ) . '">' . esc_html( ! empty( $option['certificate_eligible'] ) ? 'Certificate Eligible: Yes (disable)' : 'Certificate Eligible: No (enable)' ) . '</button>';
			}
			if ( ! $used && ! $protected ) {
				echo ' <button class="button" name="status_action" value="delete" data-confirm="Delete this unused status?">Delete</button>';
			}
			echo '</form></li>';
		}
		echo '</ul>';
		self::form( 'status_option', array( 'event_id' => $event_id, 'status_kind' => $kind, 'status_action' => 'add' ), 'rqe-inline-form' );
		self::field( 'New ' . $title, 'status_label', '', 'text', true, array( 'maxlength' => 'event' === $kind ? 64 : 20 ) );
		self::button( 'Add Status' );
		echo '</form></fieldset></details>';
	}

	private static function rider_status_control( $event_id, array $row, $kind, array $options ) {
		$event = RQE::row( 'events', $event_id );
		if ( $event && ( ! empty( $event['is_locked'] ) || ! empty( $event['is_archived'] ) ) ) {
			echo '<p>Event is locked; status changes are disabled.</p>';
			return;
		}
		$registration = 'registration' === $kind;
		$name = $registration ? 'registration_status' : 'status';
		$current = $registration ? $row['registration_status'] : ( $row['status_manual'] ? $row['status'] : 'Automatic' );
		$labels = RQE_Statuses::enabled_labels( $options );
		if ( ! $registration ) {
			array_unshift( $labels, 'Automatic' );
		}
		if ( ! in_array( $current, $labels, true ) ) {
			array_unshift( $labels, $current );
		}
		self::form( $registration ? 'registration_lifecycle' : 'registration_status', array( 'event_id' => $event_id, 'id' => $row['id'] ), 'rqe-status-form' );
		echo '<label><span class="screen-reader-text">' . esc_html( ( $registration ? 'Registration Status' : 'Event Status' ) . ' for bib ' . $row['bib'] ) . '</span><select name="' . esc_attr( $name ) . '">';
		foreach ( $labels as $label ) {
			$enabled = 'Automatic' === $label || in_array( $label, RQE_Statuses::enabled_labels( $options ), true );
			echo '<option value="' . esc_attr( $label ) . '"' . selected( $current, $label, false ) . '>' . esc_html( $label . ( $enabled ? '' : ' (disabled; retained)' ) ) . '</option>';
		}
		echo '</select></label>';
		self::button( 'Save' );
		echo '</form>';
	}

	public static function render_event_email() {
		if ( ! RQE::site_access() ) {
			wp_die( esc_html__( 'Only WordPress administrators may configure submission inboxes.', 'rally-quest-engine' ), '', array( 'response' => 403 ) );
		}
		$is_global = 'global' === RQE_Admin::query( 'scope' );
		$id = $is_global ? 0 : absint( RQE_Admin::query( 'event_id', '0' ) );
		if ( ! $is_global && ( ! $id || ! RQE::event_access( $id ) ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		$event = $is_global ? null : RQE::row( 'events', $id );
		if ( ! $is_global && ! $event ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		$config = RQE_Email_Submissions::configuration( $id );
		$settings = $config['settings'];
		echo '<div class="wrap rqe">';
		self::header();
		echo '<h2>' . esc_html( $is_global ? 'Global Email Inbox' : 'Event Inbox Override — ' . $event['name'] ) . '</h2><p><a class="button" href="' . esc_url( $is_global ? RQE_Admin::url( 'initial-setup' ) : RQE_Admin::url( 'event-edit', array( 'event_id' => $id ) ) ) . '">' . esc_html( $is_global ? 'Back to Initial Setup' : 'Back to Event Configuration' ) . '</a></p>';
		self::notice();
		echo '<section class="rqe-panel"><p>' . esc_html( $is_global ? 'The shared mailbox is assigned to one unlocked, unarchived event at a time in Initial Setup. The selected page-header event does not affect assignment. Any explicit event inbox override takes precedence.' : 'Each accepted inbox message must map to one registered participant by sender email, contain exactly one Bonus Point Library ID in the subject, and include exactly one JPEG, PNG, GIF, or WebP attachment. Valid images require the configured ClamAV scanner.' ) . '</p>';
		self::form( $is_global ? 'global_email' : 'event_email', $is_global ? array( 'scope' => 'global' ) : array( 'event_id' => $id ), 'rqe-edit-form rqe-email-config' );
		echo '<label class="rqe-field"><span>Provider</span><select name="provider" required>';
		foreach ( array( 'imap' => 'IMAP', 'gmail' => 'Gmail API', 'microsoft' => 'Microsoft 365 / Graph API' ) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $config['provider'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><label><input type="checkbox" name="enabled" value="1"' . checked( 1, $config['enabled'], false ) . '> Enable scheduled inbox polling every five minutes</label>';
		echo '<div class="rqe-email-setup-layout"><div class="rqe-email-setup-fields">';
		self::field( 'Submission mailbox email', 'mailbox', $settings['mailbox'] ?? '', 'email', true, array( 'maxlength' => 254 ) );
		echo '<div data-email-provider-types="imap"' . ( 'imap' === $config['provider'] ? '' : ' hidden' ) . '><h3>IMAP settings</h3>';
		self::field( 'IMAP host', 'host', $settings['host'] ?? '', 'text', false, array( 'maxlength' => 255 ) );
		self::field( 'IMAP port', 'port', $settings['port'] ?? 993, 'number', false, array( 'min' => 1, 'max' => 65535 ) );
		echo '<label class="rqe-field"><span>IMAP encryption</span><select name="encryption"><option value="ssl"' . selected( $settings['encryption'] ?? 'ssl', 'ssl', false ) . '>SSL</option><option value="tls"' . selected( $settings['encryption'] ?? '', 'tls', false ) . '>TLS</option><option value="none"' . selected( $settings['encryption'] ?? '', 'none', false ) . '>None</option></select></label>';
		self::field( 'IMAP username', 'username', $settings['username'] ?? '', 'text', false, array( 'maxlength' => 254 ) );
		self::field( 'IMAP password (leave blank to preserve)', 'password', '', 'password', false, array( 'autocomplete' => 'new-password', 'maxlength' => 4096 ) );
		echo '</div><div data-email-provider-types="gmail microsoft"' . ( in_array( $config['provider'], array( 'gmail', 'microsoft' ), true ) ? '' : ' hidden' ) . '><h3>OAuth API settings</h3>';
		echo '<div class="rqe-email-provider-help" data-email-provider-types="gmail"' . ( 'gmail' === $config['provider'] ? '' : ' hidden' ) . '><span>Gmail API project setup</span><details class="rqe-url-help"><summary aria-label="Gmail API project setup help">Help?</summary><div class="rqe-url-help-content"><h4>Steps to Set Up a Google Cloud Project for Gmail API</h4><h5>1. Create a Google Cloud Project</h5><ol><li>Open the <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">Google Cloud Console<span class="screen-reader-text"> (opens in a new tab)</span></a>.</li><li>Choose <strong>IAM &amp; Admin &gt; Create a Project</strong>.</li><li>Enter a descriptive project name. You may edit the project ID before creation; it cannot be changed later.</li><li>Select <strong>Create</strong>.</li></ol><h5>2. Enable Billing (if necessary)</h5><ol><li>Open <strong>Billing</strong> in Google Cloud Console.</li><li>Select the new project.</li><li>If Google requires billing for the account or enabled services, link a billing account.</li></ol><h5>3. Enable the Gmail API</h5><ol><li>Open <strong>APIs &amp; Services &gt; Library</strong>.</li><li>Search for <strong>Gmail API</strong>.</li><li>Open Gmail API and select <strong>Enable</strong>.</li></ol><h5>4. Create Credentials</h5><ol><li>Open <strong>APIs &amp; Services &gt; OAuth consent screen</strong> and configure the application and test/authorized users.</li><li>Open <strong>APIs &amp; Services &gt; Credentials</strong>.</li><li>Select <strong>Create Credentials &gt; OAuth client ID</strong>. An API key alone cannot read a Gmail inbox.</li><li>Authorize the mailbox with the <code>https://www.googleapis.com/auth/gmail.modify</code> scope and obtain a refresh token.</li><li>Enter the OAuth client ID, client secret, and refresh token below.</li></ol><h5>5. Use the Gmail API</h5><p>Save the event inbox configuration, enable polling, and use <strong>Check Inbox Now</strong>. The configured Google account must own or have authorized access to the submission mailbox.</p></div></details></div>';
		echo '<div data-email-provider-types="microsoft"' . ( 'microsoft' === $config['provider'] ? '' : ' hidden' ) . '>';
		self::field( 'Microsoft tenant ID', 'tenant_id', $settings['tenant_id'] ?? '', 'text', false, array( 'maxlength' => 100 ) );
		echo '</div>';
		self::field( 'OAuth client ID', 'client_id', $settings['client_id'] ?? '', 'text', false, array( 'maxlength' => 255 ) );
		self::field( 'OAuth client secret (leave blank to preserve)', 'client_secret', '', 'password', false, array( 'autocomplete' => 'new-password', 'maxlength' => 4096 ) );
		self::field( 'OAuth refresh token (leave blank to preserve)', 'refresh_token', '', 'password', false, array( 'autocomplete' => 'new-password', 'maxlength' => 4096 ) );
		echo '</div><div data-email-provider-types="imap microsoft"' . ( in_array( $config['provider'], array( 'imap', 'microsoft' ), true ) ? '' : ' hidden' ) . '>';
		self::field( 'Mailbox folder', 'folder', $settings['folder'] ?? ( 'imap' === $config['provider'] ? 'INBOX' : 'inbox' ), 'text', false, array( 'maxlength' => 120 ) );
		echo '</div>';
		self::button( 'Save inbox configuration' );
		echo '</div><aside class="rqe-email-setup-instructions" aria-live="polite">';
		echo '<section data-email-provider-types="imap"' . ( 'imap' === $config['provider'] ? '' : ' hidden' ) . '><h3>Configure IMAP</h3><ol><li>Enable the PHP IMAP extension in the WordPress web container or server runtime.</li><li>Enter the mailbox&rsquo;s IMAP hostname, normally supplied by the email provider.</li><li>Use port <strong>993 with SSL</strong> unless the provider specifies a different secure configuration.</li><li>Enter the full mailbox username and its password or provider-issued app password.</li><li>Use <strong>INBOX</strong> as the folder unless submissions are delivered to another folder.</li><li>Save, return to Event Configuration, and select <strong>Test Email Inbox</strong>.</li></ol><p>For Gmail over IMAP, use a Google app password when two-step verification is enabled. Do not use the normal Google account password if Google requires app passwords.</p></section>';
		echo '<section data-email-provider-types="gmail"' . ( 'gmail' === $config['provider'] ? '' : ' hidden' ) . '><h3>Configure Gmail OAuth</h3><ol><li>Create a Google Cloud project and enable the Gmail API.</li><li>Configure the OAuth consent screen and add the mailbox account as a test user when the app is in testing mode.</li><li>Create an OAuth client ID and client secret.</li><li>Authorize <code>https://www.googleapis.com/auth/gmail.modify</code> for the mailbox account.</li><li>Obtain a refresh token using the same client ID and secret. Google OAuth Playground may be used with <strong>Use your own OAuth credentials</strong> enabled.</li><li>Enter the mailbox, client ID, client secret, and refresh token; save and select <strong>Test Email Inbox</strong>.</li></ol><p>The refresh token must belong to the same OAuth client and Gmail account entered here. An API key or short-lived access token cannot replace it.</p></section>';
		echo '<section data-email-provider-types="microsoft"' . ( 'microsoft' === $config['provider'] ? '' : ' hidden' ) . '><h3>Configure Microsoft 365 OAuth</h3><ol><li>Register an application in Microsoft Entra ID.</li><li>Grant delegated <strong>Mail.ReadWrite</strong> and <strong>offline_access</strong> consent.</li><li>Obtain the tenant ID, client ID, client secret, and a refresh token for the mailbox account.</li><li>Use <strong>inbox</strong> as the folder unless submissions use another Microsoft mail folder.</li><li>Save and select <strong>Test Email Inbox</strong>.</li></ol></section>';
		echo '<p class="rqe-email-security-note"><strong>Security:</strong> Treat passwords, client secrets, and refresh tokens as credentials. Do not include them in screenshots or support messages.</p></aside></div>';
		echo '</form>';
		echo '<p><strong>Last checked:</strong> ' . esc_html( $config['last_checked_at'] ?: 'Never' ) . '</p>';
		echo '<p><strong>Last connection test:</strong> ' . esc_html( $config['last_tested_at'] ?: 'Never' ) . '</p>';
		if ( $config['last_test_error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $config['last_test_error'] ) . '</p></div>';
		}
		if ( $config['last_error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $config['last_error'] ) . '</p></div>';
		}
		if ( $is_global && $config['settings'] ) {
			self::form( 'global_email_test' );
			self::button( 'Test Global Inbox Connection' );
			echo '</form><p>The test authenticates with the selected provider and does not read, consume or mark inbox messages.</p>';
		} elseif ( ! $is_global && $config['enabled'] ) {
			self::form( 'event_email_check', array( 'event_id' => $id ) );
			self::button( 'Check Inbox Now' );
			echo '</form>';
		}
		echo '</section></div>';
	}

	public static function render_event_roster() {
		$id = absint( RQE_Admin::query( 'event_id', '0' ) );
		wp_safe_redirect( RQE_Admin::url( 'rosters', $id ? array( 'event_id' => $id ) : array() ) );
		exit;
	}

	private static function event_rosters() {
		$rider_id = absint( RQE_Admin::query( 'rider_id', '0' ) );
		$events = self::events( $rider_id );
		$id = RQE_Admin::selected_event();
		$allowed_ids = array_map( 'intval', wp_list_pluck( $events, 'id' ) );
		if ( ( $id && ! RQE::event_access( $id ) ) || ( $rider_id && ! $events ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		if ( $id && $rider_id && ! in_array( $id, $allowed_ids, true ) ) {
			echo '<p>The selected participant is not registered in the header event. Choose one of their events in the header.</p>';
			return;
		}
		echo '<div class="rqe-roster-toolbar"><h2>Event Roster</h2><div class="rqe-roster-toolbar__actions">';
		if ( $id && RQE::site_access() ) {
			echo '<a class="button button-primary" href="' . esc_url( RQE_Admin::url( 'event-enroll', array( 'event_id' => $id ) ) ) . '">Add Participant</a>';
			echo '<a class="button" href="' . esc_url( RQE_Admin::url( 'roster-import', array( 'event_id' => $id ) ) ) . '">Import Participants</a>';
		}
		echo '</div></div>';
		if ( $rider_id ) {
			$rider = RQE::row( 'riders', $rider_id );
			echo '<p>Showing only accessible events containing <strong>' . esc_html( $rider ? $rider['name'] : 'the selected participant' ) . '</strong>.</p>';
		}
		$event = $id ? RQE::row( 'events', $id ) : null;
		if ( $event ) {
			self::registrations( $event );
		} else {
			echo '<p>' . esc_html( $events ? 'Choose an event in the header to display its registered participants.' : 'Create or be assigned to an event before viewing a roster.' ) . '</p>';
		}
	}

	public static function render_roster_import() {
		if ( ! RQE::site_access() ) {
			wp_die( esc_html( 'Only WordPress administrators may import participants into events.' ), '', array( 'response' => 403 ) );
		}
		$id = absint( RQE_Admin::query( 'event_id', '0' ) );
		if ( ! RQE::event_access( $id ) || ! ( $event = RQE::row( 'events', $id ) ) ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap rqe">';
		self::header();
		echo '<h2>Import Participants — ' . esc_html( $event['name'] ) . '</h2><p><a class="button" href="' . esc_url( RQE_Admin::url( 'rosters', array( 'event_id' => $id ) ) ) . '">Back to Rosters</a></p>';
		self::notice();
		self::form( 'roster_import', array( 'event_id' => $id ), 'rqe-panel rqe-edit-form', '', 'multipart/form-data' );
		echo '<h3>Import Event Roster (CSV or XLSX)</h3><p>Enroll existing participants only. Supply Participant ID or Email; if both are supplied they must match. Optional columns: Bib ID, Registration Status (legacy Status also supported), Pillion. Use an enabled Registration Status for this event. Blank bibs receive the next available number; blank status defaults to Initial Registration when enabled, otherwise the first enabled value. Existing enrollments, duplicate participants or bibs, and invalid rows reject the entire batch.</p><p><a href="' . esc_url( plugins_url( 'samples/event-roster.csv', RQE_FILE ) ) . '" download>Download sample roster CSV</a> (replace example identifiers with existing participants). Use the same column headings on the first worksheet of an XLSX file.</p><label class="rqe-field"><span>Roster file (CSV or XLSX, maximum 10 MB / 5,000 rows)</span><input type="file" name="roster_file" accept=".csv,.xlsx" required></label><p>ClamAV must be available for roster imports. Files are scanned and checked for unsafe content; bonus-import scanning overrides do not apply.</p>';
		self::button( 'Import Event Roster' );
		echo '</form></div>';
	}

	private static function registrations( $event ) {
		$id = (int) $event['id'];
		echo '<p><strong>Event:</strong> ' . esc_html( $event['name'] ) . '. Administrative enrollment is available regardless of the configured registration window. Bibs are unique within this event.</p>';
		$rows = self::queries()->roster( $id );
		$options = RQE_Statuses::options( $id, 'registration' );
		self::table( array( 'Participant ID', 'Bib ID', 'Registration Status', 'Name', 'Email', 'Score', 'Overall Placing' ) );
		$roster_url = RQE_Admin::url( 'rosters', array( 'event_id' => $id ) );
		foreach ( $rows as $row ) {
			echo '<tr><td>';
			self::link( $row['rider_id'], 'rider-edit', array( 'rider_id' => $row['rider_id'], 'return_url' => $roster_url ) );
			echo '</td><td>';
			self::link( $row['bib'], 'scoring', array( 'event_id' => $id, 'score_rider' => $row['rider_id'], 'score_tab' => 'pending' ) );
			echo '</td><td data-value="' . esc_attr( $row['registration_status'] ) . '">';
			self::rider_status_control( $id, $row, 'registration', $options );
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
		if ( ! RQE::site_access() ) {
			wp_die( esc_html( 'Only WordPress administrators may add participants to events.' ), '', array( 'response' => 403 ) );
		}
		$id = absint( RQE_Admin::query( 'event_id', '0' ) );
		if ( ! RQE::event_access( $id ) || ! ( $event = RQE::row( 'events', $id ) ) ) {
			wp_die( esc_html( 'Event not found.' ), '', array( 'response' => 404 ) );
		}
		$enrollment = self::queries()->enrollment( $id );
		$available = $enrollment['available'];
		$used = $enrollment['used'];
		echo '<div class="wrap rqe">';
		self::header();
		echo '<h2>Add Participant to Event — ' . esc_html( $event['name'] ) . '</h2><p><a class="button" href="' . esc_url( RQE_Admin::url( 'rosters', array( 'event_id' => $id ) ) ) . '">Back to Rosters</a></p>';
		if ( $available && count( $used ) < (int) $event['max_bib'] ) {
			self::form( 'registration', array( 'event_id' => $id ), 'rqe-panel rqe-edit-form' );
			echo '<label class="rqe-field"><span>Participant not already associated with this event</span><select name="rider_id" required><option value="">Choose a participant</option>';
			foreach ( $available as $rider ) {
				echo '<option value="' . esc_attr( $rider['id'] ) . '">' . esc_html( $rider['name'] . ' — ' . $rider['email'] ) . '</option>';
			}
			echo '</select></label><label class="rqe-field"><span>Available bib (1–' . esc_html( $event['max_bib'] ) . ')</span><select name="bib" required>';
			for ( $bib = 1; $bib <= (int) $event['max_bib']; ++$bib ) {
				if ( ! isset( $used[ $bib ] ) ) {
					echo '<option value="' . esc_attr( $bib ) . '">' . esc_html( $bib ) . '</option>';
				}
			}
			echo '</select></label>';
			self::field( 'Pillion (optional name/details)', 'pillion', '', 'text', false, array( 'maxlength' => 160 ) );
			self::button( 'Add Participant to Event' );
			echo '</form>';
			self::table( array( 'Participant ID', 'Name', 'Alias', 'Email', 'Phone' ) );
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
			echo '<p>Every bib is assigned. Increase the event maximum bib before adding another participant.</p>';
		} else {
			$return_url = RQE_Admin::url( 'rosters', array( 'event_id' => $id ) );
			$add_url = RQE_Admin::url( 'rider-edit', array( 'return_url' => $return_url, 'enroll_event_id' => $id ) );
			echo '<div class="notice notice-info inline"><p>No additional participants are available. Do you wish to add a new participant?</p><p><a class="button button-primary" href="' . esc_url( $add_url ) . '" data-confirm="No additional participants are available. Open the Add Participant form?">Add New Participant</a></p></div>';
		}
		echo '</div>';
	}

	public static function render_scoring() {
		$extra = array();
		foreach ( array( 'event_id', 'score_rider', 'score_tab' ) as $key ) {
			$value = RQE_Admin::query( $key );
			if ( '' !== $value ) {
				$extra[ $key ] = $value;
			}
		}
		wp_safe_redirect( RQE_Admin::url( 'scoring', $extra ) );
		exit;
	}

	private static function scoring_setup() {
		$events = self::scoring_events();
		$id = RQE_Admin::selected_event();
		if ( $id && ! RQE::scoring_access( $id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		echo '<h2>Scoring</h2>';
		$event = $id ? RQE::row( 'events', $id ) : null;
		if ( $event ) {
			if ( RQE::event_access( $id ) ) {
				echo '<p><a class="button" href="' . esc_url( RQE_Admin::url( 'rosters', array( 'event_id' => $id ) ) ) . '">Back to Event Roster</a></p>';
			}
			self::scoring( $event );
		} else {
			echo '<p>' . esc_html( $events ? 'Choose an event in the header to display its scoring tabs.' : 'Create or be assigned to an event before opening scoring.' ) . '</p>';
		}
	}

	private static function scoring( $event ) {
		$can_configure = RQE::event_access( $event['id'] );
		$tab = RQE_Admin::query( 'score_tab', $can_configure ? 'library' : 'pending' );
		$tabs = array( 'library' => 'Bonus Point Library', 'pending' => 'Submissions - Pending Review', 'reviewed' => 'Submissions - Reviewed', 'contested' => 'Submissions - Contested' );
		if ( ! $can_configure ) {
			unset( $tabs['library'] );
		}
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = $can_configure ? 'library' : 'pending';
		}
		$rider_id = absint( RQE_Admin::query( 'score_rider', '0' ) );
		echo '<section id="rqe-scoring"><nav class="nav-tab-wrapper" aria-label="Scoring workspace">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( RQE_Admin::url( 'scoring', array( 'event_id' => $event['id'], 'score_rider' => $rider_id, 'score_tab' => $key ) ) . '#rqe-scoring' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav><h3>Event Scoring Workspace — ' . esc_html( $event['name'] ) . '</h3>';
		if ( $rider_id ) {
			$row = self::queries()->scoring_rider( $event['id'], $rider_id );
			if ( ! $row ) {
				wp_die( esc_html( 'The selected participant is not registered in this event.' ), '', array( 'response' => 404 ) );
			}
			echo '<p class="rqe-note">Filtered context: event ' . esc_html( $event['name'] ) . ' (#' . esc_html( $event['id'] ) . '), participant ' . esc_html( $row['name'] ) . ' (#' . esc_html( $row['id'] ) . '), bib ' . esc_html( $row['bib'] ) . ', Event Status ' . esc_html( $row['effective_status'] ) . '.</p>';
			echo '<p>';
			self::link( 'Clear participant filter', 'scoring', array( 'event_id' => $event['id'], 'score_tab' => $tab ) );
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
			echo '<p>No contested-submission workflow is configured. Reviewed decisions remain available in the Reviewed tab and participant submission history.</p></section>';
			return;
		}
		$rows = self::queries()->scoring( $event['id'], $event['min_finisher_points'] );
		self::table( array( 'Bib', 'Participant ID', 'Participant', 'Event Status', 'Score', 'Placing' ), array( 3 ) );
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
			$submission_url = RQE_Email_Submissions::image_url( $event['id'], $row['image_file'] );
			$bonus_url = RQE_Bonus::image_url( $event['id'], $row['bonus_image'] );
			echo '<article class="rqe-panel rqe-submission-review"><h5>Submission #' . esc_html( $row['id'] ) . ' — Bib ' . esc_html( $row['bib'] ) . ', ' . esc_html( $row['rider_name'] ) . '</h5><div class="rqe-submission-comparison">';
			echo '<section><h6>Participant submission</h6><p><strong>Submitted ID:</strong> ' . esc_html( $row['bonus_id'] ) . '<br><strong>Email subject:</strong> ' . esc_html( $row['email_subject'] ) . '<br><strong>Description:</strong> ' . esc_html( $row['submission_description'] ?: '—' ) . '<br><strong>Received:</strong> ' . esc_html( $row['submitted_at'] ) . '</p>';
			if ( $submission_url ) {
				echo '<a href="' . esc_url( $submission_url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $submission_url ) . '" alt="Participant submission for bonus ' . esc_attr( $row['bonus_id'] ) . '"></a>';
			}
			$library_missing = null === $row['bonus_points'];
			echo '</section><section><h6>Bonus Point Library match</h6><p><strong>Library ID:</strong> ' . esc_html( $row['bonus_id'] ) . '<br><strong>Points:</strong> ' . esc_html( $library_missing ? 'Unavailable' : $row['bonus_points'] ) . '<br><strong>Description:</strong> ' . esc_html( $library_missing ? 'The matching library record was removed after intake.' : $row['bonus_description'] ) . '</p>';
			if ( $bonus_url ) {
				echo '<a href="' . esc_url( $bonus_url ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $bonus_url ) . '" alt="Bonus Point Library image for ' . esc_attr( $row['bonus_id'] ) . '"></a>';
			}
			echo '</section></div>';
			self::form( 'submission_review', array( 'event_id' => $event['id'], 'submission_id' => $row['id'] ), 'rqe-submission-decision' );
			echo '<label class="rqe-field"><span>Rejection reason</span><select name="rejection_code"><option value="">Select when rejecting</option>';
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
		self::table( array( 'Submission', 'Received', 'Bib', 'Participant', 'Bonus ID', 'Decision', 'Points', 'Reason', 'Reviewer', 'Reviewed', 'Photo' ), array( 5 ) );
		foreach ( $rows as $row ) {
			$reviewer = $row['reviewed_by'] ? get_user_by( 'id', $row['reviewed_by'] ) : null;
			echo '<tr><td>' . esc_html( $row['id'] ) . '</td><td>' . esc_html( $row['submitted_at'] ) . '</td><td>';
			if ( $row['bib'] && $row['rider_id'] ) {
				echo '<a href="' . esc_url( RQE_Admin::url( 'rider-score', array( 'event_id' => $event['id'], 'rider_id' => $row['rider_id'], 'from' => 'reviewed' ) ) . '#rqe-accepted-bonuses' ) . '">' . esc_html( $row['bib'] ) . '</a>';
			} else {
				self::h( $row['bib'] ?: '—' );
			}
			echo '</td><td>' . esc_html( $row['rider_name'] ?: 'Unmatched message' ) . '</td><td>' . esc_html( $row['bonus_id'] ?: '—' ) . '</td><td>' . esc_html( ucfirst( $row['status'] ) ) . '</td><td>' . esc_html( $row['points_awarded'] ) . '</td><td>' . esc_html( $row['rejection_description'] ?: str_replace( '_', ' ', $row['rejection_code'] ) ) . '</td><td>' . esc_html( $reviewer ? $reviewer->user_login : ( $row['reviewed_by'] ? 'Deleted user #' . $row['reviewed_by'] : 'Automated intake' ) ) . '</td><td>' . esc_html( $row['reviewed_at'] ?: '—' ) . '</td><td>';
			$image_url = RQE_Email_Submissions::image_url( $event['id'], $row['image_file'] );
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
		$feedback_id = RQE_Admin::query( 'bonus_feedback' );
		$feedback = preg_match( '/^[A-Za-z0-9]{24}$/D', $feedback_id ) ? get_transient( 'rqe_bonus_feedback_' . get_current_user_id() . '_' . $feedback_id ) : false;
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
		$pending_token = RQE_Admin::query( 'bonus_pending' );
		if ( $pending_token ) {
			try {
				$pending = RQE_Bonus::pending( $event_id, $pending_token );
			} catch ( RuntimeException $exception ) {
				$pending = null;
				if ( ( $feedback['step'] ?? 'listing' ) === $step ) {
					echo '<div class="notice notice-warning inline"><p>' . esc_html( $exception->getMessage() ) . '</p></div>';
				}
			}
			if ( $pending && ( $pending['import_step'] ?? 'images' ) === $step ) {
				echo '<section class="rqe-panel"><h3>Review security scan results</h3><p>No listings or images have been imported yet. This review expires after 15 minutes. A passed scan means no threat was detected, not a guarantee that a file is harmless.</p>';
				echo '<p>' . esc_html( count( $pending['rows'] ) . ' bonus listings and ' . count( $pending['images'] ) . ' validated images ready for approval.' ) . '</p>';
				self::table( array( 'File', 'Scan status', 'Result' ) );
				foreach ( $pending['scans'] as $scan ) {
					echo '<tr><td>' . esc_html( $scan['file'] ) . '</td><td>' . esc_html( $scan['status'] ) . '</td><td>' . esc_html( $scan['detail'] ) . '</td></tr>';
				}
				self::end_table();
				if ( $pending['errors'] ) {
					echo '<h4>Excluded files and source/validation errors</h4><ul class="ul-disc rqe-import-errors">';
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
		$report_id = RQE_Admin::query( 'bonus_report' );
		if ( preg_match( '/^[A-Za-z0-9]{24}$/D', $report_id ) ) {
			$report = get_transient( 'rqe_bonus_report_' . get_current_user_id() . '_' . $report_id );
			if ( $report && (int) $report['event_id'] === $event_id && ( $report['step'] ?? 'images' ) === $step ) {
				echo '<div class="notice notice-warning inline"><h4>Bonus import errors</h4><p>See the import summary in this section for the number saved. Correct the following file/source errors and retry those files.</p><ul class="ul-disc rqe-import-errors">';
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
		echo '<div class="rqe-import-header"><div id="rqe-import-setup">';
		self::clamav_setup( $event_id );
		echo '</div><div id="rqe-import-listing">';
		self::form( 'bonus_import', array( 'event_id' => $event_id, 'import_step' => 'listing' ), 'rqe-panel rqe-edit-form', '', 'multipart/form-data' );
		echo '<h4>Step 1: Import the bonus listing</h4>';
		echo '<label class="rqe-field"><span>Bonus listing spreadsheet (XLSX or CSV)</span><input type="file" name="bonus_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>';
		echo '<p>Columns: Bonus ID, Bonus Description, Bonus Category, Bonus Points, Bonus Locations (GPS coordinates). Enter locations as latitude,longitude.</p><p><a href="' . esc_url( plugins_url( 'samples/bonus-listing.xlsx', RQE_FILE ) ) . '" download>Download sample bonus listing XLSX</a>. Replace or remove the example row before importing; keep the column headings on the first worksheet.</p>';
		self::button( 'Validate and Import Bonus Listing' );
		echo '</form></div><div id="rqe-import-images">';
		self::form( 'bonus_import', array( 'event_id' => $event_id, 'import_step' => 'images' ), 'rqe-panel rqe-edit-form', '', 'multipart/form-data' );
		echo '<div class="rqe-image-import-heading"><h4>Step 2: Import the bonus images</h4><details class="rqe-url-help"><summary aria-label="Bonus image import information">Help?</summary><div class="rqe-url-help-content"><p>Image filenames must match Bonus IDs, for example B12.jpg. An image with a new ID creates a listing with empty attributes; import its spreadsheet attributes in step 1.</p><p>With JavaScript, select a folder to upload, scan, review, and import one image at a time. Without JavaScript, choose one image per import. Each image must fit the server upload limit and the 10 MB RQE limit.</p><p>Public URLs must use HTTPS and point to an image, a ZIP of images, or a standard web-server directory listing. Google Drive/Dropbox links must provide a public ZIP download or directory listing. Limits: 200 images, 10 MB per image, 100 MB total, and 50 MB per ZIP/download. Server upload limits may be lower. Load by URL prompts for the link and imports only the URL source; selected local files are not included.</p><p>Local uploads: each image is imported separately after approval; earlier imports remain if a later file fails or is cancelled. With the skip-failed option, invalid files are skipped; scanner errors stop the queue. URL imports: failed images block the batch unless this option is selected. Remembered unavailable-ClamAV overrides still apply and are audited.</p></div></details></div>';
		echo '<label class="rqe-field"><span>Local bonus images</span><input type="file" name="bonus_images[]" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp"></label>';
		echo '<div class="rqe-url-source"><button type="button" class="button rqe-url-open" hidden>Load by URL</button><div class="rqe-url-prompt"><h4 id="rqe-url-prompt-title" hidden>Load bonus images by URL</h4><p class="rqe-url-prompt-hint" hidden>Enter a public HTTPS link to an image, a ZIP of images, or a directory listing. Only this URL is imported; selected local files are not included.</p>';
		self::field( 'Public HTTPS image, ZIP, or directory-listing URL', 'gallery_url', '', 'url', false, array( 'maxlength' => 2000, 'placeholder' => 'https://example.com/event-images/', 'autocomplete' => 'url' ) );
		echo '<div class="rqe-url-actions"><button type="button" class="button rqe-url-cancel" hidden>Cancel</button><button class="button rqe-url-submit" type="submit" name="image_source" value="url">Load by URL</button></div></div></div>';
		echo '<label><input type="checkbox" name="allow_partial_images" value="1"> Import all successful images and skip failed images</label>';
		self::button( 'Validate and Import Bonus Images' );
		echo '</form></div></div><section class="rqe-import-feedback" id="rqe-import-feedback" aria-label="Bonus import notices and scan results">';
		foreach ( array( 'setup', 'listing', 'images' ) as $step ) {
			ob_start();
			self::bonus_import_feedback( $event_id, $step );
			$feedback = ob_get_clean();
			if ( '' !== $feedback ) {
				$labels = array( 'setup' => 'Step 0: ClamAV', 'listing' => 'Step 1: Bonus listing', 'images' => 'Step 2: Bonus images' );
				echo '<div class="rqe-import-feedback-group"><h4>' . esc_html( $labels[ $step ] ) . '</h4>' . $feedback . '</div>';
			}
		}
		echo '</section><section class="rqe-panel rqe-image-queue" hidden data-max-image="' . esc_attr( RQE_Bonus::MAX_IMAGE_SIZE ) . '" data-max-total="' . esc_attr( RQE_Bonus::MAX_IMAGE_TOTAL ) . '" data-max-count="' . esc_attr( RQE_Bonus::MAX_IMAGES ) . '" aria-label="Sequential image scan and bulk import"><h4>Scan queue and bulk image import</h4><p class="rqe-queue-progress" role="status" aria-live="polite"></p><div class="rqe-queue-review"></div><p><button type="button" class="button rqe-queue-stop">Stop after current file</button><a class="button" href="' . esc_url( RQE_Admin::url( 'scoring', array( 'event_id' => $event_id, 'score_tab' => 'library' ) ) ) . '">Refresh event gallery</a></p><ol class="rqe-queue-log"></ol></section>';
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
				$image_url = RQE_Bonus::image_url( $event_id, $row['image_file'] );
				echo '<a class="rqe-bonus-image-link" href="' . esc_url( $image_url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( 'View larger image for Bonus ' . $row['bonus_id'] ) . '"><img class="rqe-bonus-thumbnail" src="' . esc_url( $image_url ) . '" alt="' . esc_attr( 'Bonus ' . $row['bonus_id'] ) . '" loading="lazy"></a>';
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
		echo '<dialog class="rqe-gallery-dialog" aria-labelledby="rqe-gallery-title"><div class="rqe-gallery-dialog-toolbar"><h3 id="rqe-gallery-title">Bonus image</h3><button type="button" class="button rqe-gallery-close" autofocus>Close image</button></div><p class="rqe-gallery-image-error" role="alert" hidden>The image could not be loaded. Close this preview and retry, or replace the image in the bonus editor.</p><img class="rqe-gallery-full-image" alt=""></dialog>';
	}

	private static function clamav_setup( $event_id ) {
		$connection = RQE_File_Scan::check_connection();
		$bypassed = RQE_Bonus::bypass_enabled();
		if ( $bypassed ) {
			echo '<details class="rqe-clamav-disabled"><summary>ClamAV Manually Disabled</summary>';
		} elseif ( $connection['passed'] ) {
			echo '<details class="rqe-clamav-enabled"><summary>ClamAV Enabled</summary>';
		} else {
			echo '<div class="rqe-clamav-unconfigured">';
		}
		echo '<section class="rqe-panel rqe-edit-form" id="rqe-clamav-setup"><h4>Step 0: Set up or verify ClamAV</h4><p>If ClamAV is already installed using the README instructions, skip installation and check the connection below. Otherwise, follow the setup guide or ask your hosting provider to configure it.</p><p>Installation requires server administrator access. These commands run in your server terminal, not WordPress; this page does not install packages, run privileged commands, or change server configuration.</p>';
		if ( $bypassed ) {
			echo '<div class="notice notice-warning inline"><p><strong>Your ClamAV bypass is enabled on this site.</strong> When ClamAV is unavailable, valid imports run without an approval prompt and without an antivirus security scan. Each import is logged with your username and UTC date/time. When ClamAV is available, scanning and approval remain required.</p></div>';
		}
		echo '<details><summary>ClamAV installation and configuration guide</summary>';
		echo '<h5>Ubuntu / Debian (systemd)</h5><p>Run these commands on the WordPress server using an account with sudo access:</p>';
		echo '<pre class="rqe-setup-commands"><code>' . esc_html( "sudo apt-get update\nsudo apt-get install clamav clamav-daemon\nsudo systemctl enable --now clamav-freshclam" ) . '</code></pre><p>Wait for freshclam to download its signature databases. Configure the following settings in <code>/etc/clamav/clamd.conf</code>, updating existing values rather than adding duplicates:</p>';
		echo '<pre class="rqe-setup-commands"><code>' . esc_html( "ScanArchive yes\nScanPE yes\nScanELF yes\nScanOLE2 yes\nAlertExceedsMax yes\nStreamMaxLength 60M\nMaxFileSize 60M\nMaxScanSize 200M" ) . '</code></pre>';
		echo '<p>Give the PHP service account access to the local clamd socket through its socket group and permissions. Do not make the socket world-writable. If group membership changes, restart the relevant PHP-FPM or web-server service using your host&rsquo;s instructions. Then start or restart the daemon:</p>';
		echo '<pre class="rqe-setup-commands"><code>' . esc_html( "sudo systemctl enable --now clamav-daemon\nsudo systemctl restart clamav-daemon\nsudo systemctl status clamav-daemon clamav-freshclam" ) . '</code></pre>';
		echo '<h5>Other Linux distributions, Windows, or managed hosting</h5><p>Use your distribution&rsquo;s ClamAV packages or the <a href="https://docs.clamav.net/manual/Installing.html" target="_blank" rel="noopener noreferrer">official ClamAV installation guide<span class="screen-reader-text"> (opens in a new tab)</span></a>. Configure clamd and freshclam with equivalent scan settings above. Service names and configuration paths vary. On managed hosting, ask the provider to enable a local clamd endpoint for your PHP process; installing a WordPress plugin alone cannot install the server daemon.</p>';
		echo '<h5>Connect WordPress</h5><p>The default endpoint is <code>unix:///var/run/clamav/clamd.ctl</code>. If your server uses a different local socket, add <code>RQE_CLAMAV_SOCKET</code> to <code>wp-config.php</code> before the stop-editing line. Choose one endpoint only:</p>';
		echo '<pre class="rqe-setup-commands"><code>' . esc_html( "define( 'RQE_CLAMAV_SOCKET', 'unix:///var/run/clamav/clamd.ctl' );\n// Alternatively, for a loopback-only clamd TCP listener:\n// define( 'RQE_CLAMAV_SOCKET', 'tcp://127.0.0.1:3310' );" ) . '</code></pre>';
		echo '<p>For TCP, bind clamd to <code>127.0.0.1</code> with <code>TCPSocket 3310</code> and <code>TCPAddr 127.0.0.1</code>; never expose its unauthenticated listener to the network. Keep ClamAV and virus signatures current. Ensure private import temporary storage is writable and outside the web root; set <code>RQE_IMPORT_TEMP_DIR</code> in <code>wp-config.php</code> if needed.</p></details>';
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
		$check_id = RQE_Admin::query( 'clamav_check' );
		if ( preg_match( '/^[A-Za-z0-9]{24}$/D', $check_id ) ) {
			$check = get_transient( 'rqe_clamav_check_' . get_current_user_id() . '_' . $check_id );
			if ( $check && (int) $check['event_id'] === $event_id ) {
				echo '<div class="notice ' . ( $check['passed'] ? 'notice-success' : 'notice-error' ) . ' inline"><p>' . esc_html( $check['message'] ) . '</p></div>';
			} else {
				echo '<div class="notice notice-warning inline"><p>The connection-check result expired or is unavailable for this event. Run the check again.</p></div>';
			}
		}
	}

	public static function render_bonus_edit() {
		if ( ! RQE::access() ) {
			wp_die( esc_html( 'You are not authorized to manage bonus listings.' ), '', array( 'response' => 403 ) );
		}
		$event_id = absint( RQE_Admin::query( 'event_id', '0' ) );
		$bonus_id = RQE_Admin::query( 'bonus_id' );
		if ( ! RQE::scoring_access( $event_id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $bonus_id ) ) {
			wp_die( esc_html( 'Bonus listing not found.' ), '', array( 'response' => 404 ) );
		}
		$bonus = self::queries()->bonus( $event_id, $bonus_id );
		if ( ! $bonus ) {
			wp_die( esc_html( 'Bonus listing not found.' ), '', array( 'response' => 404 ) );
		}
		echo '<div class="wrap rqe">';
		self::header();
		echo '<h2>Edit Bonus Point — ' . esc_html( $bonus_id ) . '</h2><p><a class="button" href="' . esc_url( RQE_Admin::url( 'scoring', array( 'event_id' => $event_id, 'score_tab' => 'library' ) ) . '#rqe-scoring' ) . '">Back to Event Bonus Point Library</a></p>';
		self::notice();
		if ( $bonus['image_file'] ) {
			echo '<p><img class="rqe-bonus-preview" src="' . esc_url( RQE_Bonus::image_url( $event_id, $bonus['image_file'] ) ) . '" alt="' . esc_attr( 'Bonus ' . $bonus_id ) . '"></p>';
		}
		self::form( 'bonus_point', array( 'event_id' => $event_id, 'bonus_id' => $bonus_id ), 'rqe-panel rqe-edit-form', '', 'multipart/form-data' );
		self::field( 'Bonus ID', 'new_bonus_id', $bonus['bonus_id'], 'text', true, array( 'maxlength' => 64, 'pattern' => '[A-Za-z0-9][A-Za-z0-9_-]{0,63}' ) );
		self::field( 'Bonus Description', 'description', $bonus['description'], 'textarea' );
		self::field( 'Bonus Category', 'category', $bonus['category'], 'text', false, array( 'maxlength' => 120 ) );
		self::field( 'Bonus Points', 'points', $bonus['points'], 'number', true, array( 'min' => 0, 'max' => RQE_Bonus::MAX_BONUS_POINTS, 'step' => 1 ) );
		self::field( 'Bonus Locations (latitude,longitude)', 'locations', $bonus['locations'], 'text', false, array( 'maxlength' => 80, 'placeholder' => '47.6205,-122.3493' ) );
		echo '<label class="rqe-field"><span>Replace image (filename must match Bonus ID)</span><input type="file" name="bonus_image" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp"></label>';
		if ( $bonus['image_file'] ) {
			echo '<label><input type="checkbox" name="remove_image" value="1"> Remove the current gallery image</label>';
		}
		echo '<p>';
		self::button( 'Save Bonus Point' );
		echo '</p></form></div>';
	}

	private static function overall_results() {
		$rider_id = absint( RQE_Admin::query( 'rider_id', '0' ) );
		$events = self::events( $rider_id );
		$id = RQE_Admin::selected_event();
		$allowed_ids = array_map( 'intval', wp_list_pluck( $events, 'id' ) );
		if ( ( $id && ! RQE::event_access( $id ) ) || ( $rider_id && ! $events ) ) {
			wp_die( esc_html( 'Event not found, not assigned to you, or does not contain the selected participant.' ), '', array( 'response' => 403 ) );
		}
		if ( $id && $rider_id && ! in_array( $id, $allowed_ids, true ) ) {
			echo '<p>The selected participant is not registered in the header event. Choose one of their events in the header.</p>';
			return;
		}
		echo '<h2>Results</h2>';
		if ( $rider_id ) {
			$rider = RQE::row( 'riders', $rider_id );
			echo '<p>Showing only accessible events containing <strong>' . esc_html( $rider ? $rider['name'] : 'the selected participant' ) . '</strong>.</p>';
		}
		if ( ! $id ) {
			echo '<p>' . esc_html( $events ? 'Choose an event in the header to display its overall results.' : 'No matching accessible events were found.' ) . '</p>';
			return;
		}
		$event = RQE::row( 'events', $id );
		$rows = self::queries()->results( $id, $event['min_finisher_points'] );
		$options = RQE_Statuses::options( $id, 'event' );
		echo '<section class="rqe-panel"><h3>Event Protection and Certificates</h3><p>State: <strong>' . esc_html( ! empty( $event['is_archived'] ) ? 'Archived / Locked' : ( ! empty( $event['is_locked'] ) ? 'Locked' : 'Editable' ) ) . '</strong>. Archive hides the event by default and locks results. Unarchiving keeps it locked. Locking does not automatically finalize preliminary statuses.</p>';
		self::form( 'event_state', array( 'event_id' => $id ), 'rqe-inline-form' );
		if ( empty( $event['is_locked'] ) && empty( $event['is_archived'] ) ) {
			echo '<button class="button" name="state_action" value="lock" data-confirm="Lock this event and prevent result changes?">Lock Event</button> ';
		}
		if ( RQE::site_access() ) {
			if ( ! empty( $event['is_locked'] ) && empty( $event['is_archived'] ) ) {
				echo '<button class="button" name="state_action" value="unlock" data-confirm="Unlock this event and allow editing?">Unlock Event</button> ';
			}
			echo '<button class="button" name="state_action" value="' . ( ! empty( $event['is_archived'] ) ? 'unarchive' : 'archive' ) . '" data-confirm="Change this event archive state?">' . esc_html( ! empty( $event['is_archived'] ) ? 'Unarchive Event (keep locked)' : 'Archive Event (also lock)' ) . '</button>';
		}
		echo '</form><p>Finisher, Bronze Finisher, Silver Finisher and Gold Finisher always qualify after manual finalization; preliminary statuses, DNF and DNS are excluded. Design: <strong>' . esc_html( RQE_Certificates::designs()[ RQE_Certificates::design( $event['certificate_design'] ?? 'vintage' ) ] ) . '</strong> (choose in Event Details).</p><p><a class="button" href="' . esc_url( RQE_Certificates::link( $id, 'print' ) ) . '">Print All Finisher Certificates / Save as PDF</a> <a class="button" href="' . esc_url( RQE_Certificates::link( $id, 'review' ) ) . '">Review &amp; Email All Certificates</a></p></section>';
		global $wpdb;
		$batches = $wpdb->get_col( $wpdb->prepare( 'SELECT batch FROM ' . RQE::table( 'certificate_mail' ) . ' WHERE event_id=%d AND user_id=%d GROUP BY batch ORDER BY MAX(id) DESC LIMIT 10', $id, get_current_user_id() ) );
		if ( ! is_array( $batches ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Recent certificate deliveries could not be read.' );
		}
		foreach ( $batches as $batch ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'rqe_certificates', 'event_id' => $id, 'mode' => 'outcomes', 'batch' => $batch ), admin_url( 'admin-post.php' ) ), 'rqe_certificates' );
			echo '<p><a href="' . esc_url( $url ) . '">View / Continue Certificate Batch ' . esc_html( substr( $batch, 0, 8 ) ) . '</a></p>';
		}
		echo '<h3>' . esc_html( $event['name'] ) . '</h3><p>Overall placing uses competition ranking by total score: equal scores share a place and the next place is skipped. Automatic Event Status uses the highest configured threshold met or exceeded: Preliminary Finisher at Minimum Finisher Points (' . esc_html( $event['min_finisher_points'] ) . ') or above, then Preliminary Bronze, Silver or Gold Finisher. Unused tiers are skipped. At the end of the event, an administrator must manually assign Finisher, DNF or DNS. Manual assignments override automatic calculation. Accepted/Rejected Bonuses count submissions, not point totals; pending and invalid messages are excluded.</p>';
		self::table( array( 'Participant #', 'Participant Name', 'Total Score', 'Accepted Bonuses', 'Rejected Bonuses', 'Event Status', 'Overall Placing', 'Certificate' ) );
		foreach ( $rows as $row ) {
			$rank = $row['overall_placing'];
			$effective_status = $row['effective_status'];
			$score_url = admin_url( 'admin.php?page=rqe-rider-score&event_id=' . $id . '&rider_id=' . (int) $row['rider_id'] ) . '#rqe-accepted-bonuses';
			echo '<tr><td><a href="' . esc_url( $score_url ) . '">' . esc_html( $row['bib'] ) . '</a></td><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['score'] ) . '</td><td>' . esc_html( $row['accepted_bonuses'] ) . '</td><td>' . esc_html( $row['rejected_bonuses'] ) . '</td><td data-value="' . esc_attr( $effective_status ) . '">' . esc_html( $effective_status ) . '<details><summary>Change Event Status</summary>';
			self::rider_status_control( $id, $row, 'event', $options );
			echo '</details></td><td>' . esc_html( $rank ) . '</td><td>';
			if ( RQE_Certificates::eligible( $row, $options ) ) {
				echo '<a href="' . esc_url( RQE_Certificates::link( $id, 'print', $row['rider_id'] ) ) . '">Print</a> | <a href="' . esc_url( RQE_Certificates::link( $id, 'pdf', $row['rider_id'] ) ) . '">Download PDF</a> | <a href="' . esc_url( RQE_Certificates::link( $id, 'review', $row['rider_id'] ) ) . '">Review &amp; Email</a>';
			} else {
				echo 'Not eligible';
			}
			echo '</td></tr>';
		}
		self::end_table();
	}

	public static function render_rider_score() {
		$event_id = absint( RQE_Admin::query( 'event_id', '0' ) );
		$rider_id = absint( RQE_Admin::query( 'rider_id', '0' ) );
		if ( ! RQE::scoring_access( $event_id ) ) {
			wp_die( esc_html( 'Event not found or not assigned to you.' ), '', array( 'response' => 403 ) );
		}
		$row = self::queries()->rider_score( $event_id, $rider_id );
		if ( ! $row ) {
			wp_die( esc_html( 'The selected participant is not registered in this event.' ), '', array( 'response' => 404 ) );
		}
		$status = $row['effective_status'];
		$placing = $row['overall_placing'];
		echo '<div class="wrap rqe">';
		self::header();
		$back_url = RQE::event_access( $event_id ) && 'reviewed' !== RQE_Admin::query( 'from' ) ? RQE_Admin::url( 'results', array( 'event_id' => $event_id ) ) : RQE_Admin::url( 'scoring', array( 'event_id' => $event_id, 'score_tab' => 'reviewed', 'score_rider' => $rider_id ) );
		echo '<h2>Participant Overall Event Score Sheet</h2><p><a class="button" href="' . esc_url( $back_url ) . '">Back</a></p>';
		echo '<div class="rqe-panel"><p><strong>Event:</strong> ' . esc_html( $row['event_name'] ) . '</p><p><strong>Participant:</strong> ' . esc_html( $row['name'] ) . ' (bib ' . esc_html( $row['bib'] ) . ')</p><p><strong>Total score:</strong> ' . esc_html( $row['score'] ) . '</p><p><strong>Event Status:</strong> ' . esc_html( $status ) . '</p><p><strong>Overall placing:</strong> ' . esc_html( $placing ) . '</p></div>';
		echo '<section id="rqe-accepted-bonuses"><h3>' . esc_html__( 'Accepted Bonus Submissions', 'rally-quest-engine' ) . '</h3>';
		echo RQE_Public_Results::accepted_table( $event_id, self::queries()->accepted_submissions( $event_id, $rider_id ) );
		echo '</section><details class="rqe-submission-history"><summary><h3>' . esc_html__( 'Bonus Submission History (all decisions)', 'rally-quest-engine' ) . '</h3></summary>';
		$submissions = self::queries()->rider_submission_history( $event_id, $rider_id );
		if ( ! $submissions ) {
			echo '<p>No email bonus submissions have been recorded for this participant.</p>';
		} else {
			self::table( array( 'Submission', 'Received', 'Bonus ID', 'Description', 'Status', 'Points', 'Rejection reason', 'Photo' ), array( 4 ) );
			foreach ( $submissions as $submission ) {
				echo '<tr><td>' . esc_html( $submission['id'] ) . '</td><td>' . esc_html( $submission['submitted_at'] ) . '</td><td>' . esc_html( $submission['bonus_id'] ) . '</td><td>' . esc_html( $submission['bonus_description'] ) . '</td><td>' . esc_html( ucfirst( $submission['status'] ) ) . '</td><td>' . esc_html( $submission['points_awarded'] ) . '</td><td>' . esc_html( $submission['rejection_description'] ?: str_replace( '_', ' ', $submission['rejection_code'] ) ) . '</td><td>';
				$image_url = RQE_Email_Submissions::image_url( $event_id, $submission['image_file'] );
				echo $image_url ? '<a href="' . esc_url( $image_url ) . '" target="_blank" rel="noopener noreferrer">View photo</a>' : '—';
				echo '</td></tr>';
			}
			self::end_table();
		}
		echo '</details></div>';
	}

	private static function diagnostics() {
		if ( ! RQE::site_access() ) {
			wp_die( esc_html( 'Only WordPress administrators may use Diagnostics.' ), '', array( 'response' => 403 ) );
		}
		$events = self::queries()->diagnostic_events();
		$riders = self::queries()->diagnostic_riders();
		echo '<h2>Diagnostics</h2><p>Export application data, create a restorable database backup, or permanently remove scoped plugin data. Environment checks and setup-readiness preferences are on Initial Setup.</p>';
		echo '<div class="rqe-grid">';
		foreach ( array( 'json' => array( 'Export Data', 'Download all plugin table data as JSON.' ), 'sql' => array( 'Database Backup', 'Download plugin table definitions and data as restorable SQL.' ) ) as $type => $details ) {
			echo '<details class="rqe-panel rqe-diagnostics-section"><summary><h3>' . esc_html( $details[0] ) . '</h3></summary><p>' . esc_html( $details[1] ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rqe_diagnostics_download"><input type="hidden" name="download_type" value="' . esc_attr( $type ) . '">';
			wp_nonce_field( 'rqe_diagnostics_download' );
			echo '<button class="button button-primary">Download ' . esc_html( strtoupper( $type ) ) . '</button></form></details>';
		}
		echo '</div><details class="rqe-administrative-audit"><summary><h3>Administrative Audit</h3></summary><p>Latest 100 records, with usernames and timestamps in UTC. Exports and database backups include the full audit table. Ordinary data deletions retain audit history and recorded bypass choices. Deleting virus-scan audit data resets all overrides and leaves a new deletion record.</p>';
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
		echo '<details class="rqe-diagnostics-section"><summary><h3>Destructive operations</h3></summary><div class="notice notice-error inline"><p>These operations cannot be undone. Download a database backup first. Each operation requires a randomized verification string and is recorded in the audit log.</p></div><div class="rqe-destructive-grid">';
		self::diagnostics_delete_form( 'diagnostics_delete_all', 'Delete All Data', 'Delete application data, including administrator assignments. Import audit records and recorded bypass choices are retained.', array() );
		self::diagnostics_delete_form( 'diagnostics_delete_bikes', 'Delete All Bike Data', 'Delete the motorcycle catalog and every rider-motorcycle assignment.', array() );
		$event_options = '<option value="">Choose an event</option>';
		foreach ( $events as $event ) {
			$event_options .= '<option value="' . esc_attr( $event['id'] ) . '">' . esc_html( $event['name'] . ' — ' . $event['event_date'] ) . '</option>';
		}
		self::diagnostics_delete_form( 'diagnostics_delete_roster', 'Delete Event Roster', 'Delete all registrations for one event.', '<label class="rqe-field"><span>Event</span><select name="event_id" required>' . $event_options . '</select></label>' );
		self::diagnostics_delete_form( 'diagnostics_delete_bonus_points', 'Delete Event Bonus Data', 'Delete all bonus listings, participant submissions, scoring images, and submission-awarded score points for one event.', '<label class="rqe-field"><span>Event</span><select name="event_id" required>' . $event_options . '</select></label>' );
		$rider_options = '<option value="">Choose a participant</option>';
		foreach ( $riders as $rider ) {
			$rider_options .= '<option value="' . esc_attr( $rider['id'] ) . '">' . esc_html( $rider['name'] . ' — ' . $rider['email'] ) . '</option>';
		}
		self::diagnostics_delete_form( 'diagnostics_delete_rider', 'Delete Specific Participant', 'Delete one participant, their motorcycle assignments, and all event registrations.', '<label class="rqe-field"><span>Participant</span><select name="rider_id" required>' . $rider_options . '</select></label>' );
		self::diagnostics_delete_form( 'diagnostics_delete_audit', 'Delete Virus Scan Audit Data', 'Delete audit history and reset remembered ClamAV overrides for every administrator. A new record of this deletion will remain.', array() );
		echo '</div></details>';
	}

	private static function configuration_check() {
		$report = get_transient( 'rqe_configuration_' . get_current_user_id() );
		$has_report = is_array( $report ) && ! empty( $report['rows'] ) && isset( $report['checked_at'] );
		$state = '';
		$labels = array( 'passed' => 'Passed', 'failed' => 'Failed', 'review' => 'Manual review' );
		$shared_state = RQE_Setup::diagnostics_state();
		$ignored_reviews = RQE_Setup::ignored_reviews();
		if ( $has_report ) {
			$state = RQE_Diagnostic_Report::state( $report['rows'], $ignored_reviews );
		} elseif ( empty( $shared_state['reset'] ) ) {
			$last_state = get_user_option( 'rqe_configuration_last_state', get_current_user_id() );
			if ( isset( $labels[ $last_state ] ) ) {
				$state = $last_state;
			}
			if ( ! $state && isset( $shared_state['diagnostics_state'], $labels[ $shared_state['diagnostics_state'] ] ) ) {
				$state = $shared_state['diagnostics_state'];
			}
		}
		$override = ! empty( $shared_state['setup_override'] );
		$display_state = $override ? 'passed' : $state;
		$review_count = 0;
		$failed_count = 0;
		if ( $has_report ) {
			foreach ( $report['rows'] as $row ) {
				$review_count += 'review' === $row['status'] ? 1 : 0;
				$failed_count += 'failed' === $row['status'] ? 1 : 0;
			}
		} else {
			$review_count = (int) ( $shared_state['review_count'] ?? 0 );
			$failed_count = (int) ( $shared_state['failed_count'] ?? 0 );
		}
		if ( $override ) {
			$status_label = 'Setup override active — ' . $failed_count . ' failed check(s) remain';
		} elseif ( $state ) {
			$status_label = $has_report ? ( 'passed' === $state && $review_count ? 'Passed for setup — manual reviews ignored' : $labels[ $state ] ) : 'Last check: ' . $labels[ $state ] . ' — Results expired';
		} else {
			$status_label = '';
		}
		$context_tab = 'initial-setup' === RQE_Admin::query( 'tab' ) ? 'initial-setup' : 'diagnostics';
		echo '<details class="rqe-panel rqe-diagnostics-section' . ( $display_state ? ' rqe-configuration-' . esc_attr( $display_state ) : '' ) . '" id="rqe-configuration"' . ( ! $display_state || 'failed' === $display_state ? ' open' : '' ) . '><summary><h3>Required Plugins and Configuration</h3>' . ( $status_label ? ' <strong class="rqe-check-' . esc_attr( $display_state ) . '">' . esc_html( $status_label ) . '</strong>' : '' ) . '</summary><p>Check the current WordPress/PHP environment, RQE database, local ClamAV scanner, storage, and import settings. Action Scheduler is required; Gallery for SpotWalla remains optional.</p><p>Manual-review choices and setup overrides affect only this readiness summary. Failed results remain visible and runtime validation, polling eligibility and import safety checks are never bypassed.</p><p><strong>RQE limits:</strong> spreadsheets and individual images: 10 MB; remote downloads/ZIP archives: 50 MB; accepted images per queue: 100 MB / 200 images; spreadsheet rows: 5,000. Server settings are checked against these existing safety limits. Sequential image uploads need at least 11 MB post capacity including overhead for each 10 MB file.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="rqe_diagnostics_check"><input type="hidden" name="context_tab" value="' . esc_attr( $context_tab ) . '">';
		wp_nonce_field( 'rqe_diagnostics_check' );
		echo '<button type="submit" class="button button-primary">Check Required Plugins and Configuration</button></form>';
		$plugin = RQE_Diagnostics::action_scheduler();
		if ( ! $plugin['installed'] && current_user_can( 'install_plugins' ) ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=action-scheduler' ), 'install-plugin_action-scheduler' ) ) . '">Install Action Scheduler</a></p>';
		} elseif ( $plugin['installed'] && ! $plugin['active'] && current_user_can( 'activate_plugin', 'action-scheduler/action-scheduler.php' ) ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( 'action-scheduler/action-scheduler.php' ) ), 'activate-plugin_action-scheduler/action-scheduler.php' ) ) . '">Activate Action Scheduler</a></p>';
		} elseif ( ! $plugin['ready'] ) {
			echo '<p>Action Scheduler is not ready. A WordPress administrator with plugin installation/activation permissions must resolve this requirement.</p>';
		}
		echo '<p>Installation uses the standard WordPress installer and its permissions, compatibility and filesystem checks. Return here after installing/activating and rerun the check. Server software and PHP settings must be managed by your hosting administrator.</p>';
		if ( $has_report ) {
			echo '<p>Last checked: ' . esc_html( $report['checked_at'] ) . ' UTC. Detailed results are private to your administrator account and expire after 15 minutes. This is a snapshot, not a guarantee that all operations will succeed.</p><div class="rqe-scroll"><table class="widefat striped"><thead><tr><th>Check</th><th>Status</th><th>Result / required action</th></tr></thead><tbody>';
			foreach ( $report['rows'] as $row ) {
				$is_ignored = 'review' === $row['status'] && ! empty( $ignored_reviews[ $row['name'] ] );
				echo '<tr' . ( 'failed' === $row['status'] ? ' class="rqe-configuration-failed-row"' : '' ) . '><td>' . esc_html( $row['name'] ) . '</td><td><strong class="rqe-check-' . esc_attr( $row['status'] ) . '">' . esc_html( $labels[ $row['status'] ] ) . '</strong>';
				if ( $is_ignored ) {
					echo '<br><strong class="rqe-check-review">Ignored for setup only</strong>';
				}
				echo '</td><td>' . esc_html( $row['detail'] );
				if ( 'passed' !== $row['status'] ) {
					echo '<details class="rqe-configuration-help"' . ( 'failed' === $row['status'] ? ' open' : '' ) . '><summary>' . esc_html( 'failed' === $row['status'] ? 'How to fix this requirement' : 'How to verify this requirement' ) . '</summary><p>' . esc_html( $row['remedy'] ) . '</p></details>';
				}
				if ( 'review' === $row['status'] ) {
					echo '<p>' . ( $is_ignored ? 'This manual review remains unverified; it is ignored only when calculating setup readiness.' : 'This check still needs a manual review to qualify as ready.' ) . '</p>';
					self::form( 'diagnostics_review', array( 'review_name' => $row['name'], 'ignore_review' => $is_ignored ? '0' : '1' ), 'rqe-inline-form' );
					self::button( $is_ignored ? 'Include review in readiness' : 'Ignore this manual review for setup readiness' );
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table></div>';
			if ( $failed_count && ! $override ) {
				echo '<div class="notice notice-warning inline"><p>Failed checks stay visible. A site administrator may acknowledge them for setup navigation only; this does not bypass runtime checks.</p></div>';
				self::form( 'diagnostics_setup_override' );
				echo '<label><input type="checkbox" name="setup_override_confirmation" value="1" required> I understand failed checks remain visible and all runtime validation and safety checks still apply.</label> ';
				self::button( 'Acknowledge Failed Checks for Setup Only' );
				echo '</form>';
			} elseif ( $override ) {
				echo '<div class="notice notice-warning inline"><p>The setup override changes only the readiness summary. ' . esc_html( $failed_count ) . ' failed checks remain visible; event polling, imports and other runtime operations continue to enforce their own checks.</p></div>';
				self::form( 'diagnostics_override_remove' );
				self::button( 'Remove Setup Override' );
				echo '</form>';
			}
		} else {
			$checked_at = $shared_state['diagnostics_checked_at'] ?? '';
			if ( $checked_at ) {
				echo '<p>Last check: ' . esc_html( $checked_at ) . ' UTC. Results expired; rerun the check to view the detailed report, which is private to your account.</p>';
			} else {
				echo '<p>No current report. Run the check to display results; no configuration changes or plugin installations occur automatically.</p>';
			}
			if ( $override ) {
				echo '<p class="rqe-setup-override-note"><strong>Setup override is active.</strong> The latest failed-check count is ' . esc_html( $failed_count ) . '. Rerun diagnostics to review details or remove the override.</p>';
			}
		}
		echo '</details>';
	}

	private static function diagnostics_delete_form( $operation, $title, $description, $fields ) {
		$code = strtoupper( wp_generate_password( 8, false, false ) );
		echo '<details class="rqe-panel rqe-destructive-action rqe-diagnostics-section" open><summary><h4>' . esc_html( $title ) . '</h4></summary><p>' . esc_html( $description ) . '</p>';
		self::form( $operation, array( 'verification_hash' => RQE_Admin::diagnostics_hash( $operation, $code ) ) );
		if ( is_string( $fields ) ) {
			echo $fields; // Built exclusively from escaped local table values above.
		}
		echo '<div class="rqe-delete-verification"><h4 id="' . esc_attr( $operation ) . '-title">Confirm: ' . esc_html( $title ) . '</h4><p>' . esc_html( $description ) . ' This cannot be undone.</p><p class="rqe-delete-target"></p><label class="rqe-field"><span>Type <code>' . esc_html( $code ) . '</code> to verify</span><input name="verification_code" type="text" required autocomplete="off" pattern="' . esc_attr( $code ) . '"></label><button type="button" class="button rqe-delete-cancel" hidden>Cancel</button><button type="submit" class="button rqe-delete-confirm" hidden>Confirm deletion</button></div>';
		self::button( $title );
		echo '</form></details>';
	}

	private static function about() {
		global $wp_version;
		$repository = 'https://github.com/oconnoradv/RallyQuestEngine';
		$recommended_plugin = 'https://github.com/oconnoradv/SpotwallaGallary';
		$links = array(
			'Repository'                  => $repository,
			'README'                      => $repository . '/blob/main/README.md',
			'License (GPLv3 or later)'    => $repository . '/blob/main/LICENSE',
			'GitHub Releases'             => $repository . '/releases',
			'Vehicle catalog data source' => RQE_Importer::SOURCE,
		);
		echo '<h2>About</h2>';
		echo '<p>Rally Quest Engine helps organizers produce and manage a high-quality scavenger-hunt style rally for Motorcyclists, Bicyclists, Geocachers or Horseback participants. Efficient setup guides each event through its required details, focused administration keeps participants, rosters and roles in one place, and near-real-time results update as bonus submissions are reviewed.</p>';
		echo '<div class="notice notice-info inline"><p><strong>Version status:</strong> This version provides scavenger hunt styles with style-coded Event IDs, event-scoped email bonus ingestion, separate Event Scorer assignments, pending/reviewed submission history, transactional point awards, the Bonus Point Library, registration and Event Status tracking, score-based overall ranking, public results embeds, exports, and backups. Contested-submission handling remains undefined. Sites upgrading from Motorcycle Rally Scoring Application import their data once after the former plugin is deactivated.</p></div>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Version</th><td>' . esc_html( RQE_VERSION ) . '</td></tr>';
		echo '<tr><th scope="row">Environment</th><td>WordPress ' . esc_html( $wp_version ) . ', PHP ' . esc_html( PHP_VERSION ) . '</td></tr>';
		foreach ( $links as $label => $url ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $url ) . '<span class="screen-reader-text"> (opens in a new tab)</span></a></td></tr>';
		}
		echo '</table>';
		echo '<p>The plugin is free software released under the GNU General Public License, version 3 or (at your option) any later version. The repository and installable package include the license and documentation.</p>';
		echo '<h2>What this version includes</h2><ul class="ul-disc"><li>Event Administrator assignments based on existing WordPress users; WordPress administrators manage shared application data.</li><li>A primary scavenger hunt style (Motorcycle, Bicycle, Horseback or GeoCache) that sets the default for new events and the available conveyance tab and fields.</li><li>Manual and make-based NHTSA motorcycle make/model catalog workflows with bulk deletion, manual bicycle entry, and a Horses tab with breed, sex, age, color and name.</li><li>Master participant records with emergency, insurance, conveyance, optional manual year, and fuel-capacity details for motorcycles.</li><li>Event configuration, roster, enrollment, event-scoped bonus listings, and a dedicated bonus image gallery stored outside the WordPress media library.</li><li>Successful forms return to their originating form or tab.</li></ul>';
		echo '<section class="rqe-panel rqe-recommended-plugins"><h2>Plugins you Might Like</h2><h3>Gallery for SpotWalla</h3>';
		echo '<p>Display public SpotWalla tracks, trips, and retrospectives on WordPress pages or posts, individually or in galleries. This optional plugin works independently of Rally Quest Engine; it is not required for scoring or the event bonus image library.</p>';
		echo '<p><a href="' . esc_url( $recommended_plugin ) . '" target="_blank" rel="noopener noreferrer">View plugin details and documentation<span class="screen-reader-text"> (opens in a new tab)</span></a> &middot; <a href="' . esc_url( $recommended_plugin . '/releases' ) . '" target="_blank" rel="noopener noreferrer">Download from GitHub Releases<span class="screen-reader-text"> (opens in a new tab)</span></a></p>';
		echo '<p>Installation is optional and manual. Review the plugin&rsquo;s requirements and privacy guidance before installing; embedded maps load content from SpotWalla. This recommendation does not imply endorsement or approval by WordPress.org or SpotWalla.</p></section>';
		echo '<h2>Bike Catalog Import Source</h2><div class="notice notice-info inline"><p>The optional import uses the <a href="' . esc_url( RQE_Importer::SOURCE ) . '" target="_blank" rel="noopener noreferrer">NHTSA Product Information Catalog and Vehicle Listing API<span class="screen-reader-text"> (opens in a new tab)</span></a>. It accepts a make and intersects Get Models for Make results with NHTSA&rsquo;s motorcycle-filtered models. Only make and model are imported; an optional four-digit year is entered separately on a participant&rsquo;s motorcycle assignment and is not validated against the catalog.</p></div>';
		echo '<h2>Privacy and security</h2><p>Collect only necessary participant, emergency-contact, insurance, pillion, and bonus-submission details. WordPress personal-data export includes plugin participant, submission, role-assignment, and review information. Participant erasure anonymizes retained scoring records and removes motorcycle, pillion, submission-description, sender-email, and submission-photo data while retaining anonymous registration and decision fields for event integrity.</p><p>Event mailbox secrets are encrypted at rest but encrypted exports/backups remain sensitive. Use TLS, least-privilege accounts, secure backups, and supported WordPress, PHP, and database versions. Automated Semgrep and Trivy checks support secure development but do not certify NIST compliance, guarantee that every CVE is absent, or replace an independent security assessment.</p>';
		echo '<h2>Reporting issues</h2><ol><li>Search <a href="' . esc_url( $repository . '/issues' ) . '" target="_blank" rel="noopener noreferrer">existing issues<span class="screen-reader-text"> (opens in a new tab)</span></a> to see whether the problem is already reported.</li><li>If not, <a href="' . esc_url( $repository . '/issues/new' ) . '" target="_blank" rel="noopener noreferrer">open a new issue<span class="screen-reader-text"> (opens in a new tab)</span></a> with a short, descriptive title.</li><li>Describe the expected and actual behavior, steps to reproduce it, the affected tab or workflow, and any error messages or screenshots.</li><li>Include your environment: <code>Plugin ' . esc_html( RQE_VERSION ) . ', WordPress ' . esc_html( $wp_version ) . ', PHP ' . esc_html( PHP_VERSION ) . '</code>.</li><li>Do not include passwords, participant records, emergency contacts, insurance details, or other sensitive data. Report security vulnerabilities privately to the repository owner rather than in a public issue.</li></ol>';
	}
}
