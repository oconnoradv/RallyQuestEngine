<?php
/** Durable, paginated certificate print/PDF/email history, kept separate from the expiring delivery queue. */
defined( 'ABSPATH' ) || exit;

final class RQE_Certificate_History {
	const PER_PAGE = 50;
	const INSERT_CHUNK = 100;
	const BACKFILL_OPTION = 'rqe_certificate_history_backfilled';

	/** Allowed outcomes per operation. Print/PDF record a generation request, never confirmed physical printing. */
	public static function outcomes() {
		return array(
			'print' => array( 'generated' ),
			'pdf' => array( 'generated' ),
			'email' => array( 'sending', 'sent', 'failed', 'stale' ),
		);
	}

	public static function labels() {
		return array(
			'print' => 'Print view generated',
			'pdf' => 'PDF generated',
			'email' => 'Email',
			'generated' => 'Generated',
			'sending' => 'Sending - outcome uncertain',
			'sent' => 'Sent - accepted by the mail system',
			'failed' => 'Failed',
			'stale' => 'Stale - review again',
		);
	}

	/**
	 * Records one history entry. A source key makes the call idempotent: the existing entry is updated in place.
	 * Context keys: design, participant_name, detail, source_key, user_id, created_at.
	 */
	public static function record( $event_id, $rider_id, $operation, $outcome, array $context = array() ) {
		RQE_Admin::require_event( (int) $event_id );
		return self::write( self::entry( $event_id, $rider_id, $operation, $outcome, $context ) );
	}

	/**
	 * Internal persistence for the certificate renderer: records one print or PDF generation request per participant
	 * snapshot, in bounded multi-row inserts. Callers must already have authorized event access (snapshots() does).
	 */
	public static function record_generated( $event_id, $operation, array $snapshots ) {
		global $wpdb;
		$event_id = (int) $event_id;
		if ( ! in_array( $operation, array( 'print', 'pdf' ), true ) ) {
			throw new RuntimeException( 'Unsupported certificate history operation.' );
		}
		$request = bin2hex( random_bytes( 16 ) );
		$detail = 'print' === $operation ? 'Print view opened for browser printing or Save as PDF; physical printing is not confirmed.' : 'Certificate PDF download generated; printing is not confirmed.';
		$entries = array();
		foreach ( $snapshots as $data ) {
			if ( (int) ( $data['event_id'] ?? 0 ) !== $event_id ) {
				throw new RuntimeException( 'Certificate history does not match the selected event.' );
			}
			$entries[] = self::entry( $event_id, (int) $data['rider_id'], $operation, 'generated', array(
				'design' => $data['design'] ?? '',
				'participant_name' => $data['name'] ?? '',
				'detail' => $detail,
				'source_key' => $operation . ':' . $request . ':' . (int) $data['rider_id'],
			) );
		}
		$columns = array( 'event_id', 'rider_id', 'user_id', 'operation', 'outcome', 'design', 'participant_name', 'detail', 'source_key', 'created_at' );
		RQE::begin_transaction();
		try {
			foreach ( array_chunk( $entries, self::INSERT_CHUNK ) as $chunk ) {
				$values = array();
				foreach ( $chunk as $entry ) {
					foreach ( $columns as $column ) {
						$values[] = $entry[ $column ];
					}
				}
				$row = '(%d,%d,%d,%s,%s,%s,%s,%s,%s,%s)';
				$sql = 'INSERT INTO ' . RQE::table( 'certificate_history' ) . ' (' . implode( ',', $columns ) . ') VALUES ' . implode( ',', array_fill( 0, count( $chunk ), $row ) );
				if ( false === $wpdb->query( $wpdb->prepare( $sql, ...$values ) ) ) {
					throw new RuntimeException( 'Certificate history could not be saved. No certificate was generated.' );
				}
			}
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			RQE::rollback_transaction();
			throw $exception;
		}
		return count( $entries );
	}

	/**
	 * Internal persistence for authorized delivery and expiry (including cron/no-user runs): records an email delivery
	 * state from a certificate_mail row (keyed by a one-way hash of batch and participant).
	 * Only participant name and design are read from the snapshot; the address, subject and message are never stored.
	 */
	public static function record_delivery( array $mail, $outcome, $detail, array $snapshot = array(), $created_at = '' ) {
		if ( ! $snapshot && ! empty( $mail['snapshot'] ) ) {
			$decoded = json_decode( (string) $mail['snapshot'], true );
			$snapshot = is_array( $decoded ) ? $decoded : array();
		}
		return self::write( self::entry( (int) $mail['event_id'], (int) $mail['rider_id'], 'email', $outcome, array(
			'design' => $snapshot['design'] ?? '',
			'participant_name' => $snapshot['name'] ?? ( $mail['participant_name'] ?? '' ),
			'detail' => $detail,
			'source_key' => self::delivery_key( $mail ),
			'user_id' => (int) ( $mail['user_id'] ?? 0 ),
			'created_at' => $created_at,
		) ) );
	}

	public static function delivery_key( array $mail ) {
		if ( empty( $mail['batch'] ) || empty( $mail['rider_id'] ) ) {
			throw new RuntimeException( 'Certificate delivery history requires a batch and participant.' );
		}
		return 'mail:' . hash( 'sha256', $mail['batch'] . '|' . (int) $mail['rider_id'] );
	}

	/** Newest first, at most 50 rows per page; out-of-range pages are clamped to the last page. */
	public static function page( $event_id, $page = 1 ) {
		global $wpdb;
		$event_id = (int) $event_id;
		RQE_Admin::require_event( $event_id );
		$table = RQE::table( 'certificate_history' );
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE event_id=%d', $event_id ) );
		if ( null === $total || $wpdb->last_error ) {
			throw new RuntimeException( 'Certificate history could not be read.' );
		}
		$total = (int) $total;
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page = min( $pages, max( 1, (int) $page ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,event_id,rider_id,user_id,operation,outcome,design,participant_name,detail,created_at FROM ' . $table . ' WHERE event_id=%d ORDER BY created_at DESC,id DESC LIMIT %d OFFSET %d', $event_id, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Certificate history could not be read.' );
		}
		return array( 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => self::PER_PAGE );
	}

	/** Deletes one history entry only; delivery queue rows, scores and statuses are never touched. */
	public static function delete( $event_id, $id, $confirmed ) {
		global $wpdb;
		$event_id = (int) $event_id;
		$id = (int) $id;
		RQE_Admin::require_event( $event_id );
		if ( true !== $confirmed ) {
			throw new RuntimeException( 'Confirm deletion of this certificate history entry before deleting it.' );
		}
		if ( $id < 1 ) {
			throw new RuntimeException( 'Certificate history entry not found.' );
		}
		$table = RQE::table( 'certificate_history' );
		RQE::begin_transaction();
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,rider_id,operation,outcome FROM ' . $table . ' WHERE id=%d AND event_id=%d FOR UPDATE', $id, $event_id ), ARRAY_A );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Certificate history could not be read.' );
			}
			if ( ! $row ) {
				throw new RuntimeException( 'Certificate history entry not found. It may already have been deleted.' );
			}
			if ( 1 !== (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $table . ' WHERE id=%d AND event_id=%d', $id, $event_id ) ) ) {
				throw new RuntimeException( 'Certificate history entry could not be deleted.' );
			}
			( new RQE_Import_Audit() )->record( $event_id, 'certificate_history_deleted', 'Deleted certificate history entry ID ' . $id . ' (' . $row['operation'] . ', ' . $row['outcome'] . ') for participant ID ' . (int) $row['rider_id'] . '. Delivery records and scores were not changed.' );
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			RQE::rollback_transaction();
			throw $exception;
		}
		return true;
	}

	/** Site administrators only (Diagnostics): deletes every event's history; delivery queue rows are kept. */
	public static function delete_all( $confirmed ) {
		global $wpdb;
		if ( ! RQE::site_access() ) {
			throw new RuntimeException( 'Only site administrators can delete all certificate history.' );
		}
		if ( true !== $confirmed ) {
			throw new RuntimeException( 'Confirm deletion of all certificate history before deleting it.' );
		}
		RQE::begin_transaction();
		try {
			$deleted = $wpdb->query( 'DELETE FROM ' . RQE::table( 'certificate_history' ) );
			if ( false === $deleted ) {
				throw new RuntimeException( 'Certificate history could not be deleted.' );
			}
			( new RQE_Import_Audit() )->record( 0, 'certificate_history_deleted_all', 'Deleted all certificate history (' . (int) $deleted . ' entries). Delivery records and scores were not changed.' );
			RQE::commit_transaction();
		} catch ( RuntimeException $exception ) {
			RQE::rollback_transaction();
			throw $exception;
		}
		return (int) $deleted;
	}

	/**
	 * One-time import of completed outcomes still held in certificate_mail. Idempotent per delivery key, and guarded
	 * by an option so entries an administrator later deletes are not resurrected. Pending rows are skipped; they are
	 * recorded when sent or expired.
	 */
	public static function backfill( $force = false ) {
		if ( ! RQE::site_access() ) {
			throw new RuntimeException( 'Only site administrators can import certificate history.' );
		}
		return self::run_backfill( $force );
	}

	/** Trusted system migration for RQE::install only (no current user is required); never wire it to a request handler. */
	public static function migration_backfill() {
		return self::run_backfill( false );
	}

	private static function run_backfill( $force ) {
		global $wpdb;
		if ( ! $force && get_option( self::BACKFILL_OPTION ) ) {
			return 0;
		}
		$count = 0;
		$cursor = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT m.id,m.batch,m.event_id,m.rider_id,m.user_id,m.snapshot,m.state,m.detail,m.updated_at,r.name AS participant_name FROM ' . RQE::table( 'certificate_mail' ) . ' m JOIN ' . RQE::table( 'events' ) . ' e ON e.id=m.event_id LEFT JOIN ' . RQE::table( 'riders' ) . " r ON r.id=m.rider_id WHERE m.state IN ('sending','sent','failed','stale') AND m.id>%d ORDER BY m.id LIMIT 500", $cursor ), ARRAY_A );
			if ( ! is_array( $rows ) || $wpdb->last_error ) {
				throw new RuntimeException( 'Existing certificate deliveries could not be read for history.' );
			}
			foreach ( $rows as $row ) {
				$detail = 'sending' === $row['state'] ? 'Imported: delivery outcome uncertain.' : (string) $row['detail'];
				self::record_delivery( $row, $row['state'], $detail, array(), (string) $row['updated_at'] );
				++$count;
				$cursor = (int) $row['id'];
			}
		} while ( count( $rows ) === 500 );
		$completed = gmdate( 'Y-m-d H:i:s' );
		update_option( self::BACKFILL_OPTION, $completed, false );
		if ( get_option( self::BACKFILL_OPTION ) !== $completed ) { throw new RuntimeException( 'Certificate history migration completion could not be saved.' ); }
		return $count;
	}

	private static function entry( $event_id, $rider_id, $operation, $outcome, array $context ) {
		$outcomes = self::outcomes();
		if ( ! isset( $outcomes[ $operation ] ) || ! in_array( $outcome, $outcomes[ $operation ], true ) ) {
			throw new RuntimeException( 'Unsupported certificate history operation or outcome.' );
		}
		$design = (string) ( $context['design'] ?? '' );
		$created = (string) ( $context['created_at'] ?? '' );
		$key = (string) ( $context['source_key'] ?? '' );
		if ( '' === $key ) {
			$key = $operation . ':' . bin2hex( random_bytes( 16 ) ) . ':' . (int) $rider_id;
		}
		if ( strlen( $key ) > 191 ) {
			throw new RuntimeException( 'Certificate history source key is too long.' );
		}
		return array(
			'event_id' => (int) $event_id,
			'rider_id' => (int) $rider_id,
			'user_id' => ! empty( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id(),
			'operation' => $operation,
			'outcome' => $outcome,
			'design' => isset( RQE_Certificates::designs()[ $design ] ) ? $design : '',
			'participant_name' => self::clip( sanitize_text_field( (string) ( $context['participant_name'] ?? '' ) ), 160 ),
			'detail' => self::clip( sanitize_text_field( (string) ( $context['detail'] ?? '' ) ), 500 ),
			'source_key' => $key,
			'created_at' => preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created ) ? $created : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/** Upsert by unique source key; a concurrent duplicate insert falls back to updating the winning row. */
	private static function write( array $entry ) {
		global $wpdb;
		$table = RQE::table( 'certificate_history' );
		$changes = array_intersect_key( $entry, array_flip( array( 'outcome', 'design', 'participant_name', 'detail', 'created_at' ) ) );
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $table . ' WHERE source_key=%s', $entry['source_key'] ) );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Certificate history could not be read.' );
			}
			if ( $id ) {
				if ( '' === $changes['design'] ) {
					unset( $changes['design'] );
				}
				if ( '' === $changes['participant_name'] ) {
					unset( $changes['participant_name'] );
				}
				if ( false === $wpdb->update( $table, $changes, array( 'id' => (int) $id ) ) ) {
					throw new RuntimeException( 'Certificate history could not be saved.' );
				}
				return (int) $id;
			}
			if ( false !== $wpdb->insert( $table, $entry ) ) {
				return (int) $wpdb->insert_id;
			}
		}
		throw new RuntimeException( 'Certificate history could not be saved.' );
	}

	private static function clip( $text, $limit ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
	}
}
