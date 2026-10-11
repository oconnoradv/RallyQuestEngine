<?php
/** Semgrep-only fixtures. Never load this file in WordPress. */
function sql_queries( $wpdb ) {
	$value = $_GET['id'];
	$query = 'SELECT * FROM riders WHERE id=' . $value;
	// ruleid: rqe-wordpress-sql-injection
	$wpdb->query( $query );
	// ruleid: rqe-wordpress-sql-injection
	$wpdb->get_results( $query );
	// ruleid: rqe-wordpress-sql-injection
	$wpdb->get_row( $query );
	// ruleid: rqe-wordpress-sql-injection
	$wpdb->get_col( $query );
	// ruleid: rqe-wordpress-sql-injection
	$wpdb->get_var( $query );
	// ok: rqe-wordpress-sql-injection
	$wpdb->get_row( $wpdb->prepare( 'SELECT * FROM riders WHERE id=%d', $value ) );
	// ok: rqe-wordpress-sql-injection
	$wpdb->query( 'SELECT * FROM riders WHERE id=' . absint( $value ) );
	// ruleid: rqe-wordpress-sql-injection
	$wpdb->query( 'SELECT * FROM riders WHERE name=' . sanitize_text_field( $_POST['name'] ) );
	// ruleid: rqe-wordpress-tainted-sql-template
	$wpdb->prepare( "SELECT * FROM riders WHERE name='$value' AND id=%d", 1 );
	// ok: rqe-wordpress-tainted-sql-template
	$wpdb->prepare( 'SELECT * FROM riders WHERE name=%s', $value );
	// ruleid: rqe-wordpress-sql-injection
	$wpdb->query( 'SELECT * FROM riders WHERE name=' . RQE_Admin::posted( 'name' ) );
}

function unsafe_prepare_result( $wpdb ) {
	// ruleid: rqe-wordpress-tainted-sql-template
	$query = $wpdb->prepare( 'SELECT * FROM riders WHERE name=' . $_POST['name'] . ' AND id=%d', 1 );
	// ok: rqe-wordpress-sql-injection
	$wpdb->query( $query );
}

function output_and_requests() {
	// ruleid: rqe-wordpress-reflected-xss
	echo $_GET['name'];
	// ruleid: rqe-wordpress-reflected-xss
	echo RQE_Admin::query( 'notice' );
	// ruleid: rqe-wordpress-reflected-xss
	print sanitize_text_field( $_POST['name'] );
	// ok: rqe-wordpress-reflected-xss
	echo esc_html( $_GET['name'] );
	// ok: rqe-wordpress-reflected-xss
	echo '<input value="' . esc_attr( $_GET['name'] ) . '">';
	// ruleid: rqe-wordpress-unsafe-request
	wp_remote_get( $_POST['url'] );
	// ruleid: rqe-wordpress-unsafe-request
	wp_remote_post( RQE_Admin::posted( 'url' ), array() );
	// ruleid: rqe-wordpress-unsafe-request
	wp_remote_request( esc_url_raw( $_GET['url'] ) );
	// ok: rqe-wordpress-unsafe-request
	wp_safe_remote_get( $_POST['url'] );
	// ok: rqe-wordpress-unsafe-request
	wp_remote_get( 'https://example.com/static-data' );
}
