<?php
/** Validate and atomically enroll existing riders from CSV/XLSX data. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Roster_Import_Service {
	public static function import_uploaded( $event_id, $file ) {
		if ( ! is_array( $file ) || ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || ! is_scalar( $file['error'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			throw new RuntimeException( 'Choose a successfully uploaded roster CSV or XLSX file.' );
		}
		$size = filesize( $file['tmp_name'] );
		if ( false === $size || $size < 1 || $size > MRSA_Bonus::MAX_SHEET_SIZE ) { throw new RuntimeException( 'Roster files must be nonempty and no larger than 10 MB.' ); }
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) { throw new RuntimeException( 'Choose a CSV or XLSX file.' ); }
		( new MRSA_Scan_Session() )->scan( $file['tmp_name'], $file['name'] );
		list( $header, $rows ) = MRSA_Spreadsheet_Reader::read( $file['tmp_name'], $extension );
		return self::import_rows( $event_id, $header, $rows );
	}

	public static function import_rows( $event_id, $header, $rows ) {
		global $wpdb;
		$columns = array();
		$allowed = array( 'rider id', 'email', 'bib id', 'status', 'pillion' );
		foreach ( $header as $index => $value ) {
			$name = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $value ) ) );
			if ( 'registration status' === $name ) {
				$name = 'status';
			}
			if ( ! in_array( $name, $allowed, true ) || isset( $columns[ $name ] ) ) {
				throw new RuntimeException( 'Use the sample columns only, without duplicate headers: Rider ID, Email, Bib ID, Registration Status (or Status), Pillion.' );
			}
			$columns[ $name ] = $index;
		}
		if ( ! isset( $columns['rider id'] ) && ! isset( $columns['email'] ) ) {
			throw new RuntimeException( 'The roster must have a Rider ID or Email column.' );
		}
		if ( count( $rows ) > MRSA_Bonus::MAX_RECORDS ) {
			throw new RuntimeException( 'Import no more than 5,000 roster rows at once.' );
		}
		MRSA::begin_transaction();
		try {
			$event = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MRSA::table( 'events' ) . ' WHERE id=%d FOR UPDATE', $event_id ), ARRAY_A );
			if ( ! $event ) {
				throw new RuntimeException( 'The selected event no longer exists.' );
			}
			$existing = $wpdb->get_results( $wpdb->prepare( 'SELECT rider_id,bib FROM ' . MRSA::table( 'registrations' ) . ' WHERE event_id=%d', $event_id ), ARRAY_A );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'The existing roster could not be read. No riders were imported.' );
			}
			$used = array_fill_keys( array_column( $existing, 'bib' ), true );
			$enrolled = array_fill_keys( array_column( $existing, 'rider_id' ), true );
			$records = array();
			$statuses = MRSA_Statuses::enabled_labels( MRSA_Statuses::options( $event_id, 'registration' ) );
			$default_status = MRSA_Statuses::initial_registration( $event_id );
			foreach ( $rows as $line => $row ) {
				if ( ! array_filter( $row, function ( $value ) { return '' !== trim( (string) $value ); } ) ) {
					continue;
				}
				try {
					foreach ( $row as $index => $value ) {
						if ( ! array_key_exists( $index, $header ) && '' !== trim( (string) $value ) ) {
							throw new RuntimeException( 'Unexpected cells outside the header columns.' );
						}
						if ( preg_match( '/^\s*[=+@]/', (string) $value ) || preg_match( '/<\?(?:php|=)|<script\b/i', (string) $value ) ) {
							throw new RuntimeException( 'Formulas or executable cell content are not allowed.' );
						}
					}
					$get = function ( $name ) use ( $columns, $row ) { return isset( $columns[ $name ] ) ? trim( (string) ( $row[ $columns[ $name ] ] ?? '' ) ) : ''; };
					$id = $get( 'rider id' );
					$email = $get( 'email' );
					if ( '' === $id && '' === $email ) {
						throw new RuntimeException( 'Supply a Rider ID or Email.' );
					}
					if ( '' !== $email ) { $email = MRSA_Admin::email( $email ); }
					$rider = '' !== $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MRSA::table( 'riders' ) . ' WHERE id=%d FOR UPDATE', MRSA_Admin::integer( $id, 1 ) ), ARRAY_A ) :
						$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MRSA::table( 'riders' ) . ' WHERE email=%s FOR UPDATE', $email ), ARRAY_A );
					if ( $wpdb->last_error || ! $rider || preg_match( '/^erased-\d+@example\.invalid$/', $rider['email'] ) ) {
						throw new RuntimeException( 'No eligible existing rider matches this identifier.' );
					}
					if ( '' !== $email && 0 !== strcasecmp( $email, $rider['email'] ) ) {
						throw new RuntimeException( 'Rider ID and Email identify different riders.' );
					}
					if ( isset( $enrolled[ $rider['id'] ] ) ) {
						throw new RuntimeException( 'Rider is already enrolled or repeated in this file.' );
					}
					$enrolled[ $rider['id'] ] = true;
					$bib = '' === $get( 'bib id' ) ? null : MRSA_Admin::integer( $get( 'bib id' ), 1, (int) $event['max_bib'] );
					if ( null !== $bib ) {
						if ( isset( $used[ $bib ] ) ) { throw new RuntimeException( 'Bib ID is already assigned or repeated in this file.' ); }
						$used[ $bib ] = true;
					}
					$status = $get( 'status' ) ?: $default_status;
					if ( ! in_array( $status, $statuses, true ) ) {
						throw new RuntimeException( 'Select an enabled Registration Status for this event.' );
					}
					$records[] = array_merge( array( 'event_id' => $event_id, 'rider_id' => (int) $rider['id'], 'bib' => $bib, 'pillion' => MRSA_Admin::text( $get( 'pillion' ), 160 ) ), MRSA_Statuses::registration_data( $status ) );
				} catch ( RuntimeException $exception ) {
					throw new RuntimeException( 'Roster row ' . ( $line + 2 ) . ': ' . $exception->getMessage() . ' No riders were imported.' );
				}
			}
			if ( ! $records ) { throw new RuntimeException( 'The roster file contains no riders.' ); }
			$next = 1;
			foreach ( $records as $record ) {
				if ( null === $record['bib'] ) {
					while ( isset( $used[ $next ] ) ) { ++$next; }
					if ( $next > (int) $event['max_bib'] ) { throw new RuntimeException( 'The event has insufficient available bibs. No riders were imported.' ); }
					$record['bib'] = $next;
					$used[ $next ] = true;
				}
				self::checked( $wpdb->insert( MRSA::table( 'registrations' ), $record ) );
			}
			MRSA::commit_transaction();
		} catch ( RuntimeException $exception ) {
			if ( ! MRSA::rollback_transaction() ) {
				throw new RuntimeException( $exception->getMessage() . ' Database rollback failed; inspect the roster before retrying.' );
			}
			throw $exception;
		}
		return 'Imported ' . count( $records ) . ' riders into the selected event roster.';
	}

	private static function checked( $result ) {
		if ( false === $result ) {
			throw new RuntimeException( 'The record could not be saved. Check for duplicate emails, catalog rows, assigned riders or bibs, then refresh and try again.' );
		}
		return $result;
	}
}