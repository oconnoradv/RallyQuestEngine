<?php
/** Read models for administration screens; callers enforce screen authorization. */
defined( 'ABSPATH' ) || exit;

final class RQE_Queries {
	private $db;

	public function __construct( $db ) {
		$this->db = $db;
	}

	private function rows( $sql, array $args = array() ) {
		$rows = $this->db->get_results( $args ? $this->db->prepare( $sql, ...$args ) : $sql, ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) ) {
			throw new RuntimeException( 'Application data could not be read. Check the database connection and server logs.' );
		}
		return $rows;
	}

	public function events( $rider_id, $site_access, $user_id ) {
		$join = $rider_id ? ' JOIN ' . RQE::table( 'registrations' ) . ' reg ON reg.event_id=e.id AND reg.rider_id=%d' : '';
		$args = $rider_id ? array( $rider_id ) : array();
		$sql = 'SELECT e.* FROM ' . RQE::table( 'events' ) . ' e' . $join;
		if ( ! $site_access ) {
			$sql .= ' JOIN ' . RQE::table( 'event_admins' ) . ' a ON a.event_id=e.id WHERE a.user_id=%d';
			$args[] = $user_id;
		}
		return $this->rows( $sql . ' ORDER BY e.event_date DESC,e.id DESC', $args );
	}

	public function scoring_events( $site_access, $user_id ) {
		$sql = 'SELECT e.* FROM ' . RQE::table( 'events' ) . ' e';
		$args = array();
		if ( ! $site_access ) {
			$sql .= ' WHERE EXISTS (SELECT 1 FROM ' . RQE::table( 'event_admins' ) . ' a WHERE a.event_id=e.id AND a.user_id=%d) OR EXISTS (SELECT 1 FROM ' . RQE::table( 'event_scorers' ) . ' s WHERE s.event_id=e.id AND s.user_id=%d)';
			$args = array( $user_id, $user_id );
		}
		return $this->rows( $sql . ' ORDER BY e.event_date DESC,e.id DESC', $args );
	}

	public function roster( $event_id ) {
		$rows = $this->rows( 'SELECT reg.id,reg.rider_id,reg.bib,reg.registration_status,reg.score,r.name,r.email FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=reg.rider_id WHERE reg.event_id=%d ORDER BY reg.bib', array( $event_id ) );
		return $this->ranked( $rows );
	}

	public function enrollment( $event_id ) {
		$available = $this->rows( 'SELECT r.id,r.name,r.alias,r.email,r.phone FROM ' . RQE::table( 'riders' ) . ' r WHERE r.email NOT LIKE %s AND NOT EXISTS (SELECT 1 FROM ' . RQE::table( 'registrations' ) . ' reg WHERE reg.rider_id=r.id AND reg.event_id=%d) ORDER BY r.name,r.id', array( 'erased-%@example.invalid', $event_id ) );
		$bibs = $this->rows( 'SELECT bib FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d', array( $event_id ) );
		return array( 'available' => $available, 'used' => array_fill_keys( array_map( 'intval', array_column( $bibs, 'bib' ) ), true ) );
	}

	public function scoring_rider( $event_id, $rider_id ) {
		$rows = $this->rows( 'SELECT reg.bib,reg.status,reg.status_manual,reg.score,e.min_finisher_points,e.bronze_finisher_points,e.silver_finisher_points,e.gold_finisher_points,r.name,r.id FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=reg.rider_id JOIN ' . RQE::table( 'events' ) . ' e ON e.id=reg.event_id WHERE reg.event_id=%d AND reg.rider_id=%d', array( $event_id, $rider_id ) );
		if ( $rows ) {
			$rows[0]['effective_status'] = RQE_Results::status( $rows[0], $rows[0]['min_finisher_points'] );
		}
		return $rows ? $rows[0] : null;
	}

	public function scoring( $event_id, $minimum ) {
		$rows = $this->rows( 'SELECT reg.bib,reg.status,reg.status_manual,reg.score,reg.placing,e.bronze_finisher_points,e.silver_finisher_points,e.gold_finisher_points,r.id,r.name FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=reg.rider_id JOIN ' . RQE::table( 'events' ) . ' e ON e.id=reg.event_id WHERE reg.event_id=%d ORDER BY reg.bib', array( $event_id ) );
		foreach ( $rows as &$row ) {
			$row['effective_status'] = RQE_Results::status( $row, $minimum );
		}
		unset( $row );
		return $rows;
	}

	public function rider_events( $rider_id, $site_access, $user_id ) {
		$sql = 'SELECT reg.*,e.name,e.min_finisher_points,e.bronze_finisher_points,e.silver_finisher_points,e.gold_finisher_points FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'events' ) . ' e ON e.id=reg.event_id WHERE reg.rider_id=%d';
		$args = array( $rider_id );
		if ( ! $site_access ) {
			$sql .= ' AND EXISTS (SELECT 1 FROM ' . RQE::table( 'event_admins' ) . ' a WHERE a.event_id=e.id AND a.user_id=%d)';
			$args[] = $user_id;
		}
		$rows = $this->rows( $sql . ' ORDER BY e.event_date DESC', $args );
		foreach ( $rows as &$row ) {
			$row['effective_status'] = RQE_Results::status( $row, $row['min_finisher_points'] );
		}
		unset( $row );
		return $rows;
	}

	/** Ranked results rows with accepted/rejected submission counts for an event. */
	public function results( $event_id, $minimum ) {
		return $this->ranked( $this->result_rows( $event_id, false ), $minimum );
	}

	private function result_rows( $event_id, $public ) {
		$columns = ( $public ? 'reg.bib,reg.score,reg.status,reg.status_manual,r.name' : 'reg.*,r.name' ) . ',e.bronze_finisher_points,e.silver_finisher_points,e.gold_finisher_points';
		$publication = ' JOIN ' . RQE::table( 'events' ) . ' e ON e.id=reg.event_id' . ( $public ? ' AND e.public_results_enabled=1' : '' );
		return $this->rows(
			'SELECT ' . $columns . ',COALESCE(s.accepted,0) accepted_bonuses,COALESCE(s.rejected,0) rejected_bonuses FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=reg.rider_id' . $publication . " LEFT JOIN (SELECT event_id,rider_id,SUM(CASE WHEN status='accepted' THEN 1 ELSE 0 END) accepted,SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) rejected FROM " . RQE::table( 'bonus_submissions' ) . " WHERE event_id=%d AND status IN ('accepted','rejected') GROUP BY event_id,rider_id) s ON s.event_id=reg.event_id AND s.rider_id=reg.rider_id WHERE reg.event_id=%d ORDER BY reg.score DESC,reg.bib ASC",
			array( $event_id, $event_id )
		);
	}

	/** Public-safe results for a published event, or null when unpublished, missing, or the style code does not match. */
	public function public_results( $event_id, $style = null ) {
		$events = $this->rows( 'SELECT name,description,logo_id,hunt_style,min_finisher_points FROM ' . RQE::table( 'events' ) . ' WHERE id=%d AND public_results_enabled=1', array( $event_id ) );
		if ( ! $events || ( null !== $style && $events[0]['hunt_style'] !== $style ) ) {
			return null;
		}
		$rows = $this->ranked( $this->result_rows( $event_id, true ), $events[0]['min_finisher_points'] );
		foreach ( $rows as &$row ) {
			unset( $row['status'], $row['status_manual'], $row['bronze_finisher_points'], $row['silver_finisher_points'], $row['gold_finisher_points'] );
		}
		unset( $row );
		return array( 'name' => $events[0]['name'], 'description' => $events[0]['description'], 'logo_id' => (int) $events[0]['logo_id'], 'rows' => $rows );
	}

	private function ranked( array $rows, $minimum = null ) {
		$ranks = RQE_Results::rankings( $rows );
		foreach ( $rows as &$row ) {
			$row['overall_placing'] = $ranks[ (string) (int) $row['score'] ];
			if ( null !== $minimum ) {
				$row['effective_status'] = RQE_Results::status( $row, $minimum );
			}
		}
		unset( $row );
		return $rows;
	}

	public function rider_score( $event_id, $rider_id ) {
		$rows = $this->rows( 'SELECT reg.*,r.name,e.name event_name,e.min_finisher_points,e.bronze_finisher_points,e.silver_finisher_points,e.gold_finisher_points FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=reg.rider_id JOIN ' . RQE::table( 'events' ) . ' e ON e.id=reg.event_id WHERE reg.event_id=%d AND reg.rider_id=%d', array( $event_id, $rider_id ) );
		if ( ! $rows ) {
			return null;
		}
		$row = $rows[0];
		$count = $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d AND score>%d', $event_id, $row['score'] ) );
		if ( ! empty( $this->db->last_error ) ) {
			throw new RuntimeException( 'The participant placing could not be read. Check the database connection and server logs.' );
		}
		$row['overall_placing'] = 1 + (int) $count;
		$row['effective_status'] = RQE_Results::status( $row, $row['min_finisher_points'] );
		return $row;
	}

	public function bonus_gallery( $event_id ) {
		return $this->rows( 'SELECT bonus_id,description,category,points,locations,image_file FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d ORDER BY bonus_id', array( $event_id ) );
	}

	public function diagnostic_events() {
		return $this->rows( 'SELECT id,name,event_date FROM ' . RQE::table( 'events' ) . ' ORDER BY event_date DESC,id DESC' );
	}

	public function diagnostic_riders() {
		return $this->rows( 'SELECT id,name,email FROM ' . RQE::table( 'riders' ) . ' ORDER BY name,id' );
	}

	public function recent_audit() {
		return $this->rows( 'SELECT user_id,username,occurred_at,event_id,action,detail FROM ' . RQE::table( 'import_audit' ) . ' ORDER BY id DESC LIMIT 100' );
	}

	public function rider_visible( $rider_id, $user_id ) {
		$visible = $this->db->get_var( $this->db->prepare( 'SELECT reg.rider_id FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'event_admins' ) . ' a ON a.event_id=reg.event_id WHERE a.user_id=%d AND reg.rider_id=%d LIMIT 1', $user_id, $rider_id ) );
		if ( ! empty( $this->db->last_error ) ) {
			throw new RuntimeException( 'Participant access could not be verified. Check the database connection and server logs.' );
		}
		return (bool) $visible;
	}

	public function rider_bikes( $rider_id ) {
		return array(
			'catalog' => $this->rows( 'SELECT id,make,model FROM ' . RQE::table( 'bikes' ) . ' ORDER BY make,model,id' ),
			'owned' => $rider_id ? $this->rows( 'SELECT bike_id,model_year,fuel_capacity FROM ' . RQE::table( 'rider_bikes' ) . ' WHERE rider_id=%d ORDER BY id', array( $rider_id ) ) : array(),
		);
	}

	/** All horses with the owning participant name for the Horses tab. */
	public function horses() {
		return $this->rows( 'SELECT h.*,r.name rider_name FROM ' . RQE::table( 'horses' ) . ' h LEFT JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=h.rider_id ORDER BY h.name,h.id' );
	}

	/** Horses this participant owns plus unassigned horses available for selection. */
	public function rider_horses( $rider_id ) {
		return $this->rows( 'SELECT id,name,breed,sex,age,color,rider_id FROM ' . RQE::table( 'horses' ) . ' WHERE rider_id=0 OR rider_id=%d ORDER BY name,id', array( (int) $rider_id ) );
	}

	public function event_administrators( $event_id ) {
		return $this->rows( 'SELECT * FROM ' . RQE::table( 'event_admins' ) . ' WHERE event_id=%d ORDER BY user_id', array( $event_id ) );
	}

	public function event_scorers( $event_id ) {
		return $this->rows( 'SELECT * FROM ' . RQE::table( 'event_scorers' ) . ' WHERE event_id=%d ORDER BY user_id', array( $event_id ) );
	}

	public function pending_submissions( $event_id, $rider_id = 0 ) {
		$sql = 'SELECT s.*,r.name rider_name,b.description bonus_description,b.points bonus_points,b.image_file bonus_image FROM ' . RQE::table( 'bonus_submissions' ) . ' s JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=s.rider_id LEFT JOIN ' . RQE::table( 'bonus_points' ) . " b ON b.event_id=s.event_id AND b.bonus_id=s.bonus_id WHERE s.event_id=%d AND s.status='pending'";
		$args = array( $event_id );
		if ( $rider_id ) {
			$sql .= ' AND s.rider_id=%d';
			$args[] = $rider_id;
		}
		return $this->rows( $sql . ' ORDER BY s.submitted_at,s.id', $args );
	}

	public function reviewed_submissions( $event_id, $rider_id = 0 ) {
		$sql = 'SELECT s.*,r.name rider_name,b.description bonus_description,b.image_file bonus_image FROM ' . RQE::table( 'bonus_submissions' ) . ' s LEFT JOIN ' . RQE::table( 'riders' ) . ' r ON r.id=s.rider_id LEFT JOIN ' . RQE::table( 'bonus_points' ) . " b ON b.event_id=s.event_id AND b.bonus_id=s.bonus_id WHERE s.event_id=%d AND s.status IN ('accepted','rejected','invalid')";
		$args = array( $event_id );
		if ( $rider_id ) {
			$sql .= ' AND s.rider_id=%d';
			$args[] = $rider_id;
		}
		return $this->rows( $sql . ' ORDER BY COALESCE(s.reviewed_at,s.submitted_at) DESC,s.id DESC', $args );
	}

	public function rider_submission_history( $event_id, $rider_id ) {
		return $this->rows( 'SELECT s.*,b.description bonus_description FROM ' . RQE::table( 'bonus_submissions' ) . ' s LEFT JOIN ' . RQE::table( 'bonus_points' ) . ' b ON b.event_id=s.event_id AND b.bonus_id=s.bonus_id WHERE s.event_id=%d AND s.rider_id=%d ORDER BY s.submitted_at DESC,s.id DESC', array( $event_id, $rider_id ) );
	}

	public function bonus( $event_id, $bonus_id ) {
		$rows = $this->rows( 'SELECT * FROM ' . RQE::table( 'bonus_points' ) . ' WHERE event_id=%d AND bonus_id=%s', array( $event_id, $bonus_id ) );
		return $rows ? $rows[0] : null;
	}

	public function bike_page( $search, $page ) {
		$search = substr( $search, 0, 100 );
		$where = $search ? ' WHERE make LIKE %s OR model LIKE %s' : '';
		$args = $search ? array_fill( 0, 2, '%' . $this->db->esc_like( $search ) . '%' ) : array();
		return $this->page( 'SELECT * FROM ' . RQE::table( 'bikes' ) . $where . ' ORDER BY make,model,id', 'SELECT COUNT(*) FROM ' . RQE::table( 'bikes' ) . $where, $args, $page );
	}

	public function rider_page( $search, $page, $site_access, $user_id ) {
		$search = substr( $search, 0, 100 );
		$where = $search ? ' WHERE (r.name LIKE %s OR r.alias LIKE %s OR r.email LIKE %s)' : ' WHERE 1=1';
		$args = $search ? array_fill( 0, 3, '%' . $this->db->esc_like( $search ) . '%' ) : array();
		if ( ! $site_access ) {
			$where .= ' AND EXISTS (SELECT 1 FROM ' . RQE::table( 'registrations' ) . ' reg JOIN ' . RQE::table( 'event_admins' ) . ' a ON a.event_id=reg.event_id WHERE reg.rider_id=r.id AND a.user_id=%d)';
			$args[] = $user_id;
		}
		return $this->page( 'SELECT r.id,r.name,r.alias,r.email,r.phone FROM ' . RQE::table( 'riders' ) . ' r' . $where . ' ORDER BY r.name,r.id', 'SELECT COUNT(*) FROM ' . RQE::table( 'riders' ) . ' r' . $where, $args, $page );
	}

	private function page( $sql, $count_sql, array $args, $page ) {
		$count = (int) $this->db->get_var( $args ? $this->db->prepare( $count_sql, ...$args ) : $count_sql );
		if ( ! empty( $this->db->last_error ) ) {
			throw new RuntimeException( 'The record count could not be read. Check the database connection and server logs.' );
		}
		$pages = max( 1, (int) ceil( $count / 100 ) );
		$page = max( 1, min( 1000000, $pages, (int) $page ) );
		$rows = $this->rows( $sql . ' LIMIT %d OFFSET %d', array_merge( $args, array( 100, ( $page - 1 ) * 100 ) ) );
		return array( 'count' => $count, 'page' => $page, 'pages' => $pages, 'rows' => $rows );
	}
}
