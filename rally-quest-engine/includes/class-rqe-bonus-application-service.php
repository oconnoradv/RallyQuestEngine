<?php
/** Explicit-input application boundary for bonus imports, overrides and edits. */
defined( 'ABSPATH' ) || exit;

final class RQE_Bonus_Application_Service {
	public function normalize_listing( array $header, array $rows ) {
		return RQE_Bonus::normalize_rows( $header, $rows );
	}

	public function create_stage_directory() {
		return RQE_Bonus::create_stage_directory();
	}

	public function prepare_review( $event_id, array $rows, array $images, $stage_dir, array $errors, $partial, RQE_Scan_Session $scan_session, $step ) {
		return RQE_Bonus::prepare_review( $event_id, $rows, $images, $stage_dir, $errors, $partial, $scan_session, $step );
	}

	public function import( $event_id, array $input ) {
		return RQE_Bonus::import_with_input( $event_id, $input );
	}

	public function import_action( $operation, $event_id, array $input, $default_step ) {
		if ( ! in_array( $operation, array( 'bonus_import', 'bonus_import_approve', 'bonus_import_bypass', 'bonus_import_cancel' ), true ) ) {
			throw new RuntimeException( 'Unsupported bonus import operation.' );
		}
		if ( 'bonus_import' === $operation ) {
			$result = $this->import( $event_id, $input );
			$step = $default_step;
		} else {
			$review = $this->review(
				$event_id,
				$input['pending_token'] ?? '',
				'bonus_import_approve' === $operation ? 'approve' : ( 'bonus_import_bypass' === $operation ? 'bypass' : 'cancel' ),
				$input
			);
			$result = $review['result'];
			$step = $review['pending']['import_step'] ?? $default_step;
		}
		return array( 'result' => $result, 'step' => $step );
	}

	public function pending( $event_id, $token ) {
		return RQE_Bonus::pending( $event_id, $token );
	}

	public function review( $event_id, $token, $operation, array $input ) {
		if ( ! in_array( $operation, array( 'approve', 'bypass', 'cancel' ), true ) ) {
			throw new RuntimeException( 'Unsupported bonus import review operation.' );
		}
		$pending = RQE_Bonus::pending( $event_id, $token );
		if ( 'approve' === $operation && '1' !== ( $input['scan_approval'] ?? '' ) ) {
			throw new RuntimeException( 'Review the scan results and explicitly approve the import.' );
		}
		if ( 'bypass' === $operation && '1' !== ( $input['bypass_confirmation'] ?? '' ) ) {
			throw new RuntimeException( 'Confirm the risk of importing without an antivirus security scan.' );
		}
		return array(
			'pending' => $pending,
			'result' => RQE_Bonus::approve( $event_id, $token, 'cancel' === $operation, 'bypass' === $operation ),
		);
	}

	public function set_scan_override( $event_id, $enabled, $confirmed = false ) {
		if ( $enabled ) {
			RQE_Bonus::enable_bypass( $event_id, $confirmed );
			return 'WARNING: ClamAV override enabled for your account. Imports may proceed without antivirus scanning only when ClamAV is unavailable. Your consent was audited.';
		}
		RQE_Bonus::revoke_bypass( $event_id );
		return 'ClamAV scanning override removed for your account.';
	}

	public function save( $event_id, $bonus_id, array $input ) {
		return RQE_Bonus::save( $event_id, $bonus_id, $input );
	}
}
