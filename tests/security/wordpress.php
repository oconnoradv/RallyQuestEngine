<?php
/** Semgrep-only fixtures. Never load this file in WordPress. */
function sql_queries( $wpdb ) {
	$value = $_GET['id'];
	$query = 'SELECT * FROM riders WHERE id=' . $value;
	// ruleid: mrsa-wordpress-sql-injection
	$wpdb->query( $query );
	// ruleid: mrsa-wordpress-sql-injection
	$wpdb->get_results( $query );
	// ruleid: mrsa-wordpress-sql-injection
	$wpdb->get_row( $query );
	// ruleid: mrsa-wordpress-sql-injection
	$wpdb->get_col( $query );
	// ruleid: mrsa-wordpress-sql-injection
	$wpdb->get_var( $query );
	// ok: mrsa-wordpress-sql-injection
	$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM riders WHERE id=%d', $value ) );
	// ok: mrsa-wordpress-sql-injection
	$wpdb->query( 'SELECT * FROM riders WHERE id=' . absint( $value ) );
	// ruleid: mrsa-wordpress-sql-injection
	$wpdb->query( 'SELECT * FROM riders WHERE name=' . sanitize_text_field( $_POST['name'] ) );
	// ruleid: mrsa-wordpress-tainted-sql-template
	$wpdb->prepare( "SELECT * FROM riders WHERE name='$value' AND id=%d", 1 );
	// ok: mrsa-wordpress-tainted-sql-template
	$wpdb->prepare( 'SELECT * FROM riders WHERE name=%s', $value );
	// ruleid: mrsa-wordpress-sql-injection
	$wpdb->query( 'SELECT * FROM riders WHERE name=' . MRSA_Admin::posted( 'name' ) );
}

function unsafe_prepare_result( $wpdb ) {
	// ruleid: mrsa-wordpress-tainted-sql-template
	$query = $wpdb->prepare( 'SELECT * FROM riders WHERE name=' . $_POST['name'] . ' AND id=%d', 1 );
	// ok: mrsa-wordpress-sql-injection
	$wpdb->query( $query );
}

function output_and_requests() {
	// ruleid: mrsa-wordpress-reflected-xss
	echo $_GET['name'];
	// ruleid: mrsa-wordpress-reflected-xss
	echo MRSA_Admin::query( 'notice' );
	// ruleid: mrsa-wordpress-reflected-xss
	print sanitize_text_field( $_POST['name'] );
	// ok: mrsa-wordpress-reflected-xss
	echo esc_html( $_GET['name'] );
	// ok: mrsa-wordpress-reflected-xss
	echo '<input value="' . esc_attr( $_GET['name'] ) . '">';
	// ruleid: mrsa-wordpress-unsafe-request
	wp_remote_get( $_POST['url'] );
	// ruleid: mrsa-wordpress-unsafe-request
	wp_remote_post( MRSA_Admin::posted( 'url' ), array() );
	// ruleid: mrsa-wordpress-unsafe-request
	wp_remote_request( esc_url_raw( $_GET['url'] ) );
	// ok: mrsa-wordpress-unsafe-request
	wp_safe_remote_get( $_POST['url'] );
	// ok: mrsa-wordpress-unsafe-request
	wp_remote_get( 'https://example.com/static-data' );
}
