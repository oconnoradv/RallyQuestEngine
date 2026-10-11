<?php
/** Event-local status catalogs; mutations share the enrollment event lock. */
defined( 'ABSPATH' ) || exit;

final class RQE_Statuses {
	public static function defaults( $kind ) {
		return 'registration' === $kind ? array( 'Initial Registration', 'Paid', 'Fully Registered', 'Cancelled' ) : array( 'Registered', 'Preliminary Finisher', 'Finisher', 'DNF', 'DNS' );
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
				if ( ! in_array( $label, $existing, true ) ) {
					RQE_Admin::checked( $wpdb->insert( RQE::table( 'status_options' ), array( 'event_id' => $event_id, 'kind' => $kind, 'label' => $label, 'enabled' => 1 ) ) );
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
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . RQE::table( 'status_options' ) . ' WHERE event_id=%d AND kind=%s ORDER BY id', $event_id, self::kind( $kind ) ), ARRAY_A );
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
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RQE::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ) );
		if ( ! $id || $wpdb->last_error ) {
			throw new RuntimeException( 'The event no longer exists or could not be locked.' );
		}
	}

	public static function usage( $event_id, $kind, $label ) {
		global $wpdb;
		self::kind( $kind );
		$condition = 'registration' === $kind ? 'r.registration_status=%s' : "(r.status_manual=1 AND r.status=%s) OR (r.status_manual=0 AND CASE WHEN r.score>e.min_finisher_points THEN 'Preliminary Finisher' ELSE 'Registered' END=%s)";
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
		$label = RQE_Admin::text( $label, 20, true );
		if ( 0 === strcasecmp( 'Automatic', $label ) ) {
			throw new RuntimeException( 'Automatic is a calculation mode, not a custom status.' );
		}
		if ( ! in_array( $action, array( 'add', 'enable', 'disable', 'delete' ), true ) ) {
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
				RQE_Admin::checked( $wpdb->insert( RQE::table( 'status_options' ), array( 'event_id' => $event_id, 'kind' => $kind, 'label' => $label, 'enabled' => 1 ) ) );
			} else {
				if ( ! $matches ) {
					throw new RuntimeException( 'The status option no longer exists.' );
				}
				$row = $matches[0];
				if ( 'delete' === $action ) {
					if ( self::usage( $event_id, $kind, $row['label'] ) ) {
						throw new RuntimeException( 'This status is in use. Disable it instead of deleting it.' );
					}
					if ( 'event' === $kind && in_array( $row['label'], array( 'Registered', 'Preliminary Finisher', 'DNS' ), true ) ) {
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
		$value = RQE_Admin::text( $value, 20, true );
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
