<?php
/** Persistent administrative audit events and per-administrator scan overrides. */
defined( 'ABSPATH' ) || exit;

final class MRSA_Import_Audit {
	private $database;

	public function __construct( $database = null ) {
		if ( null === $database ) {
			global $wpdb;
			$database = $wpdb;
		}
		$this->database = $database;
	}

	public function record( $event_id, $action, $detail ) {
		$user = wp_get_current_user();
		if ( ! $user->ID || false === $this->database->insert( MRSA::table( 'import_audit' ), array(
			'user_id' => $user->ID,
			'username' => $user->user_login,
			'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
			'event_id' => $event_id,
			'action' => $action,
			'detail' => $detail,
		) ) ) {
			throw new RuntimeException( 'The audit record could not be saved. Deletions and unscanned imports cannot proceed without an audit record.' );
		}
	}

	public function bypass_enabled( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return 'bypass_enabled' === $this->database->get_var( $this->database->prepare(
			'SELECT action FROM ' . MRSA::table( 'import_audit' ) . " WHERE user_id=%d AND action IN ('bypass_enabled','bypass_disabled') ORDER BY id DESC LIMIT 1",
			$user_id
		) );
	}

	public function enable_bypass( $event_id, $confirmed ) {
		if ( ! $confirmed ) {
			throw new RuntimeException( 'Confirm the risk of importing without antivirus scanning before enabling the override.' );
		}
		$this->record( $event_id, 'bypass_enabled', 'Administrator explicitly enabled the unavailable-ClamAV override from Step 0 for their account on this site.' );
	}

	public function revoke_bypass( $event_id ) {
		$this->record( $event_id, 'bypass_disabled', 'Administrator restored mandatory ClamAV scanning for their imports.' );
	}
}
