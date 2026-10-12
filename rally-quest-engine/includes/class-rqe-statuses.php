<?php
/** Event-local status catalogs; mutations share the enrollment event lock. */
defined( 'ABSPATH' ) || exit;

final class RQE_Statuses {
	public static function defaults( $kind ) {
		return 'registration' === $kind ? array( 'Initial Registration', 'Paid', 'Fully Registered', 'Cancelled' ) : array( 'Registered', 'DNS', 'Active', 'Preliminary Finisher', 'Preliminary Bronze Finisher', 'Preliminary Silver Finisher', 'Preliminary Gold Finisher', 'Finisher', 'Bronze Finisher', 'Silver Finisher', 'Gold Finisher', 'DNF' );
	}

	public static function final_finishers() {
		return array( 'Finisher', 'Bronze Finisher', 'Silver Finisher', 'Gold Finisher' );
	}

	/** One-time upgrade order; retain custom choices, enabled flags and participant assignments. */
	public static function migrate_finishers() {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT id FROM ' . RQE::table( 'events' ) . ' ORDER BY id FOR UPDATE' );
		if ( ! is_array( $ids ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Finisher status migration could not read events.' );
		}
		foreach ( $ids as $event_id ) {
			self::seed( (int) $event_id );
			$options = self::options( $event_id, 'event' );
			$order = array();
			foreach ( self::defaults( 'event' ) as $label ) {
				foreach ( $options as $option ) {
					if ( 0 === strcasecmp( $label, $option['label'] ) ) {
						$order[] = $option;
					}
				}
			}
			foreach ( $options as $option ) {
				if ( ! in_array( $option['id'], array_column( $order, 'id' ), true ) ) {
					$order[] = $option;
				}
			}
			foreach ( $order as $position => $option ) {
				$data = array( 'sort_order' => $position );
				if ( in_array( strtolower( $option['label'] ), array_map( 'strtolower', self::final_finishers() ), true ) ) {
					$data['certificate_eligible'] = 1;
				}
				RQE_Admin::checked( $wpdb->update( RQE::table( 'status_options' ), $data, array( 'id' => $option['id'] ) ) );
			}
		}
	}

	public static function protected_labels() {
		return array_merge( array( 'Registered', 'Preliminary Finisher', 'DNS' ), array_values( RQE_Results::tiers() ) );
	}

	public static function migrate_ordering() {
		global $wpdb;
		RQE_Admin::checked( $wpdb->query( 'UPDATE ' . RQE::table( 'status_options' ) . ' SET sort_order=id' ) );
		RQE_Admin::checked( $wpdb->query( 'UPDATE ' . RQE::table( 'status_options' ) . " SET certificate_eligible=1 WHERE kind='event' AND label='Finisher'" ) );
	}

	/** Seed only the new tier statuses, preserving existing catalog choices. */
	public static function migrate_tiers() {
		global $wpdb;
		$events = $wpdb->get_col( 'SELECT id FROM ' . RQE::table( 'events' ) );
		if ( ! is_array( $events ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Finisher tier status migration could not read events.' );
		}
		foreach ( $events as $event_id ) {
			$existing = array_column( self::options( $event_id, 'event' ), 'label' );
			foreach ( RQE_Results::tiers() as $label ) {
				if ( ! in_array( $label, $existing, true ) ) {
					RQE_Admin::checked( $wpdb->insert( RQE::table( 'status_options' ), array( 'event_id' => $event_id, 'kind' => 'event', 'label' => $label, 'enabled' => 1 ) ) );
				}
			}
		}
	}

	private static function kind( $kind ) {
		if ( ! in_array( $kind, array( 'registration', 'event' ), true ) ) {
			throw new RuntimeException( 'Select Registration Status or Event Status.' );
		}
		return $kind;
	}

	public static function seed( $event_id ) {
		global $wpdb;
		foreach ( array( 'registration', 'event' ) as $kind ) {
			$existing = array_column( self::options( $event_id, $kind ), 'label' );
			foreach ( self::defaults( $kind ) as $label ) {
				if ( ! in_array( strtolower( $label ), array_map( 'strtolower', $existing ), true ) ) {
					RQE_Admin::checked( $wpdb->insert( RQE::table( 'status_options' ), array( 'event_id' => $event_id, 'kind' => $kind, 'label' => $label, 'enabled' => 1, 'sort_order' => count( $existing ), 'certificate_eligible' => 'event' === $kind && in_array( $label, self::final_finishers(), true ) ? 1 : 0 ) ) );
					$existing[] = $label;
				}
			}
		}
	}

	public static function migrate() {
		global $wpdb;
		RQE::begin_transaction();
		try {
			$ids = $wpdb->get_col( 'SELECT id FROM ' . RQE::table( 'events' ) . ' ORDER BY id FOR UPDATE' );
			if ( ! is_array( $ids ) || $wpdb->last_error ) {
				throw new RuntimeException( 'Event status migration could not read the events.' );
			}

			foreach ( $ids as $id ) {
				self::seed( (int) $id );
			}
			foreach ( array( 'registration' => 'registration_status', 'event' => 'status' ) as $kind => $column ) {
				RQE_Admin::checked( $wpdb->query( $wpdb->prepare(
					'INSERT INTO ' . RQE::table( 'status_options' ) . ' (event_id,kind,label,enabled) SELECT DISTINCT r.event_id,%s,r.' . $column . ',1 FROM ' . RQE::table( 'registrations' ) . ' r JOIN ' . RQE::table( 'events' ) . ' e ON e.id=r.event_id WHERE NOT EXISTS (SELECT 1 FROM ' . RQE::table( 'status_options' ) . ' o WHERE o.event_id=r.event_id AND o.kind=%s AND o.label=r.' . $column . ')',
					$kind, $kind
				) ) );
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! RQE::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Status migration rollback failed; inspect the database before retrying.' );
			}
			throw $exception;
		}
	}

	public static function migrate_cancelled() {
		global $wpdb;
		RQE::begin_transaction();
		try {
			$ids = $wpdb->get_col( 'SELECT id FROM ' . RQE::table( 'events' ) . ' ORDER BY id FOR UPDATE' );
			if ( ! is_array( $ids ) || $wpdb->last_error ) {
				throw new RuntimeException( 'Cancelled participant migration could not read the events.' );
			}
			foreach ( $ids as $id ) {
				if ( ! in_array( 'DNS', array_column( self::options( (int) $id, 'event' ), 'label' ), true ) ) {
					RQE_Admin::checked( $wpdb->insert( RQE::table( 'status_options' ), array( 'event_id' => (int) $id, 'kind' => 'event', 'label' => 'DNS', 'enabled' => 1 ) ) );
				}
			}
			RQE_Admin::checked( $wpdb->query( 'UPDATE ' . RQE::table( 'registrations' ) . " SET status='DNS',status_manual=1 WHERE registration_status='Cancelled'" ) );
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! RQE::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Cancelled participant migration rollback failed; inspect the database before retrying.' );
			}
			throw $exception;
		}
	}

	public static function registration_data( $value, $previous = null ) {
		$data = array( 'registration_status' => $value );
		if ( 'Cancelled' === $value && 'Cancelled' !== $previous ) {
			$data['status'] = 'DNS';
			$data['status_manual'] = 1;
		}
		return $data;
	}

	public static function options( $event_id, $kind ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'status_options' ) . ' WHERE event_id=%d AND kind=%s ORDER BY sort_order,id', $event_id, self::kind( $kind ) ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Event status choices could not be read.' );
		}
		return $rows;
	}

	public static function enabled_labels( array $options ) {
		return array_column( array_filter( $options, function ( $row ) { return ! empty( $row['enabled'] ); } ), 'label' );
	}

	public static function initial_registration( $event_id ) {
		$labels = self::enabled_labels( self::options( $event_id, 'registration' ) );
		if ( ! $labels ) {
			throw new RuntimeException( 'Enable a Registration Status before enrolling participants.' );
		}
		return in_array( 'Initial Registration', $labels, true ) ? 'Initial Registration' : $labels[0];
	}

	public static function lock_event( $event_id ) {
		RQE_Event_State::writable( $event_id );
	}

	public static function usage( $event_id, $kind, $label ) {
		global $wpdb;
		self::kind( $kind );
		$condition = 'registration' === $kind ? 'r.registration_status=%s' : '(r.status_manual=1 AND r.status=%s) OR (r.status_manual=0 AND ' . RQE_Results::automatic_status_sql() . '=%s)';
		$args = 'registration' === $kind ? array( $event_id, $label ) : array( $event_id, $label, $label );
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RQE::table( 'registrations' ) . ' r JOIN ' . RQE::table( 'events' ) . ' e ON e.id=r.event_id WHERE r.event_id=%d AND (' . $condition . ')', ...$args ) );
		if ( null === $count || $wpdb->last_error ) {
			throw new RuntimeException( 'Status usage could not be checked.' );
		}
		return (int) $count;
	}

	public static function change_option( $event_id, $kind, $action, $label ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		self::kind( $kind );
		$label = RQE_Admin::text( $label, 'event' === $kind ? 64 : 20, true );
		if ( 0 === strcasecmp( 'Automatic', $label ) ) {
			throw new RuntimeException( 'Automatic is a calculation mode, not a custom status.' );
		}
		if ( ! in_array( $action, array( 'add', 'enable', 'disable', 'delete', 'up', 'down', 'certificate_on', 'certificate_off' ), true ) ) {
			throw new RuntimeException( 'Unsupported status option action.' );
		}
		RQE::begin_transaction();
		try {
			self::lock_event( $event_id );
			$options = self::options( $event_id, $kind );
			$matches = array_values( array_filter( $options, function ( $row ) use ( $label ) { return 0 === strcasecmp( $row['label'], $label ); } ) );
			if ( 'add' === $action ) {
				if ( $matches ) {
					throw new RuntimeException( 'That status already exists. Re-enable it if it is disabled.' );
				}
				$order = $options ? max( array_column( $options, 'sort_order' ) ) + 1 : 0;
				RQE_Admin::checked( $wpdb->insert( RQE::table( 'status_options' ), array( 'event_id' => $event_id, 'kind' => $kind, 'label' => $label, 'enabled' => 1, 'sort_order' => $order, 'certificate_eligible' => 0 ) ) );
			} else {
				if ( ! $matches ) {
					throw new RuntimeException( 'The status option no longer exists.' );
				}
				$row = $matches[0];
				if ( in_array( $action, array( 'up', 'down' ), true ) ) {
					$index = array_search( $row['id'], array_column( $options, 'id' ) );
					$target = $index + ( 'up' === $action ? -1 : 1 );
					if ( ! isset( $options[ $target ] ) ) {
						throw new RuntimeException( 'This status is already at the end of the list.' );
					}
					$swap = $options[ $target ];
					$options[ $target ] = $row;
					$options[ $index ] = $swap;
					foreach ( $options as $position => $option ) {
						RQE_Admin::checked( $wpdb->update( RQE::table( 'status_options' ), array( 'sort_order' => $position ), array( 'id' => $option['id'] ) ) );
					}
					RQE::commit_transaction();
					return;
				}
				if ( in_array( $action, array( 'certificate_on', 'certificate_off' ), true ) ) {
					if ( 'event' === $kind && in_array( strtolower( $label ), array_map( 'strtolower', self::final_finishers() ), true ) ) {
						throw new RuntimeException( 'Standard final finisher statuses are always certificate eligible.' );
					}
					if ( 'event' !== $kind || in_array( strtolower( $label ), array_map( 'strtolower', array_merge( self::protected_labels(), array( 'DNF', 'Automatic' ) ) ), true ) ) {
						throw new RuntimeException( 'Only final finisher statuses can be certificate eligible.' );
					}
					RQE_Admin::checked( $wpdb->update( RQE::table( 'status_options' ), array( 'certificate_eligible' => 'certificate_on' === $action ? 1 : 0 ), array( 'id' => $row['id'] ) ) );
					RQE::commit_transaction();
					return;
				}
				if ( 'delete' === $action ) {
					if ( self::usage( $event_id, $kind, $row['label'] ) ) {
						throw new RuntimeException( 'This status is in use. Disable it instead of deleting it.' );
					}
					if ( 'event' === $kind && in_array( $row['label'], self::protected_labels(), true ) ) {
						throw new RuntimeException( 'System statuses cannot be deleted. They can be disabled for manual selection.' );
					}
				}
				if ( 'registration' === $kind && ! empty( $row['enabled'] ) && in_array( $action, array( 'disable', 'delete' ), true ) && count( self::enabled_labels( $options ) ) < 2 ) {
					throw new RuntimeException( 'Keep at least one Registration Status enabled for new enrollments.' );
				}
				$where = array( 'id' => $row['id'], 'event_id' => $event_id, 'kind' => $kind );
				RQE_Admin::checked( 'delete' === $action ? $wpdb->delete( RQE::table( 'status_options' ), $where ) : $wpdb->update( RQE::table( 'status_options' ), array( 'enabled' => 'enable' === $action ? 1 : 0 ), $where ) );
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! RQE::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Status update rollback failed; inspect the event before retrying.' );
			}
			throw $exception;
		}
	}

	public static function assign( $event_id, $registration_id, $kind, $value ) {
		global $wpdb;
		RQE_Admin::require_event( $event_id );
		self::kind( $kind );
		$value = RQE_Admin::text( $value, 'event' === $kind ? 64 : 20, true );
		RQE::begin_transaction();
		try {
			self::lock_event( $event_id );
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'registrations' ) . ' WHERE event_id=%d AND id=%d FOR UPDATE', $event_id, $registration_id ), ARRAY_A );
			if ( ! $row || $wpdb->last_error ) {
				throw new RuntimeException( 'Registration not found in this event or could not be read.' );
			}
			$automatic = 'event' === $kind && 'Automatic' === $value;
			$current = 'registration' === $kind ? $row['registration_status'] : ( $row['status_manual'] ? $row['status'] : 'Automatic' );
			if ( ! $automatic && $value !== $current && ! in_array( $value, self::enabled_labels( self::options( $event_id, $kind ) ), true ) ) {
				throw new RuntimeException( 'Select an enabled status for this event.' );
			}
			$data = 'registration' === $kind ? self::registration_data( $value, $row['registration_status'] ) : array( 'status' => $automatic ? 'Registered' : $value, 'status_manual' => $automatic ? 0 : 1 );
			RQE_Admin::checked( $wpdb->update( RQE::table( 'registrations' ), $data, array( 'event_id' => $event_id, 'id' => $registration_id ) ) );
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! RQE::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Status assignment rollback failed; inspect the roster before retrying.' );
			}
			throw $exception;
		}
	}
}
