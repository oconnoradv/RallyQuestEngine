<?php
/** Standalone roster import regression tests: php tests/roster-import.php */
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
final class MRSA {
	public static function table( $name ) { return $name; }
	public static function begin_transaction() { $GLOBALS['wpdb']->snapshot = $GLOBALS['wpdb']->registrations; }
	public static function commit_transaction() {}
	public static function rollback_transaction() { $GLOBALS['wpdb']->registrations = $GLOBALS['wpdb']->snapshot; return true; }
}
final class Roster_Test_DB {
	public $last_error = '';
	public $registrations = array();
	public $snapshot = array();
	public $max_bib = 6;
	public $fail_insert = false;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_row( $query, $format ) {
		list( $sql, $args ) = $query;
		if ( strpos( $sql, 'FROM events' ) !== false ) { return array( 'id' => $args[0], 'max_bib' => $this->max_bib ); }
		for ( $id = 1; $id <= 6; ++$id ) {
			$email = 6 === $id ? 'erased-6@example.invalid' : 'rider' . $id . '@example.com';
			if ( strpos( $sql, 'WHERE id=' ) !== false ? $args[0] === $id : $args[0] === $email ) {
				return array( 'id' => $id, 'email' => $email );
			}
		}
		return null;
	}
	public function get_results( $query, $format ) {
		return array_values( array_filter( $this->registrations, function ( $row ) use ( $query ) { return $row['event_id'] === $query[1][0]; } ) );
	}
	public function insert( $table, $data ) {
		if ( $this->fail_insert ) { return false; }
		$this->registrations[] = $data;
		return 1;
	}
}
function sanitize_text_field( $value ) { return $value; }
function sanitize_email( $value ) { return $value; }
function is_email( $value ) { return filter_var( $value, FILTER_VALIDATE_EMAIL ); }
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-admin.php';
require __DIR__ . '/../motorcycle-rally-scoring-application/includes/class-mrsa-bonus.php';
function check( $ok, $label ) {
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . PHP_EOL;
}
function import_rows( $rows, $header = array( 'Rider ID', 'Email', 'Bib ID', 'Status', 'Pillion' ) ) {
	$method = new ReflectionMethod( 'MRSA_Admin', 'import_roster_rows' );
	if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
	return $method->invoke( null, 7, $header, $rows );
}
$wpdb = new Roster_Test_DB();
$wpdb->registrations = array( array( 'event_id' => 7, 'rider_id' => 5, 'bib' => 2 ), array( 'event_id' => 8, 'rider_id' => 1, 'bib' => 1 ) );
$baseline = $wpdb->registrations;
import_rows( array( array( '1', '', '', '', '' ), array( '', 'rider2@example.com', '1', 'Paid', 'Passenger' ), array( '3', 'rider3@example.com', '', 'Fully Registered', '' ) ) );
$added = array_slice( $wpdb->registrations, 2 );
check( array_column( $added, 'bib' ) === array( 3, 1, 4 ), 'automatic bibs respect existing bibs and all explicit bibs later in the file' );
check( array_column( $added, 'rider_id' ) === array( 1, 2, 3 ) && array_unique( array_column( $added, 'event_id' ) ) === array( 7 ), 'IDs and emails resolve existing riders into selected event only' );
check( $added[0]['registration_status'] === 'Initial Registration' && $added[1]['pillion'] === 'Passenger', 'blank status defaults correctly and optional pillion is retained' );
$cases = array(
	'unknown rider' => array( array( '99', '', '', '', '' ) ),
	'mismatched identifiers' => array( array( '1', 'rider2@example.com', '', '', '' ) ),
	'already enrolled rider' => array( array( '5', '', '', '', '' ) ),
	'duplicate rider in file' => array( array( '1' ), array( '1' ) ),
	'used bib' => array( array( '1', '', '2' ) ),
	'duplicate bib in file' => array( array( '1', '', '1' ), array( '2', '', '1' ) ),
	'out of range bib' => array( array( '1', '', '7' ) ),
	'invalid status' => array( array( '1', '', '', 'Finisher' ) ),
	'erased rider' => array( array( '6' ) ),
	'formula' => array( array( '=1+1' ) ),
	'executable cell' => array( array( '1', '', '', '', '<script>alert(1)</script>' ) ),
	'missing identifier' => array( array( '', '', '1' ) ),
	'extra data cell' => array( array( '1', '', '', '', '', 'unexpected' ) ),
);
foreach ( $cases as $label => $rows ) {
	$wpdb->registrations = $baseline;
	try {
		import_rows( $rows );
		throw new LogicException( 'Invalid roster unexpectedly accepted: ' . $label );
	} catch ( RuntimeException $exception ) {
		check( strpos( $exception->getMessage(), 'Roster row' ) !== false && $wpdb->registrations === $baseline, $label . ' rejects batch with row-specific error and no persistence' );
	}
}
$wpdb->registrations = $baseline;
$wpdb->max_bib = 2;
try { import_rows( array( array( '1' ), array( '2' ) ) ); throw new LogicException( 'Capacity exceeded.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'insufficient' ) !== false && $wpdb->registrations === $baseline, 'capacity failure rolls back earlier inserted rows' ); }
$wpdb->max_bib = 6;
$wpdb->fail_insert = true;
try { import_rows( array( array( '1' ) ) ); throw new LogicException( 'Failed insert accepted.' ); }
catch ( RuntimeException $exception ) { check( $wpdb->registrations === $baseline, 'database write failure rolls back roster' ); }
$wpdb->fail_insert = false;
foreach ( array( array( 'Name' ), array( 'Rider ID', 'Rider ID' ) ) as $header ) {
	try { import_rows( array( array( '1' ) ), $header ); throw new LogicException( 'Invalid header accepted.' ); }
	catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'columns' ) !== false, 'unknown or duplicate headers rejected' ); }
}
import_rows( array( array( 'rider1@example.com' ) ), array( 'Email' ) );
check( end( $wpdb->registrations )['bib'] === 1, 'email-only roster assigns next free bib' );
$sample = __DIR__ . '/../motorcycle-rally-scoring-application/samples/event-roster.csv';
list( $header, $rows ) = MRSA_Bonus::read_spreadsheet( $sample, 'csv' );
check( $header === array( 'Rider ID', 'Email', 'Bib ID', 'Status', 'Pillion' ) && count( $rows ) === 2, 'downloadable sample CSV parses with documented columns' );
// Archive fixture supplies components; the production parser still validates the XML.
define( 'PCLZIP_OPT_BY_NAME', 1 );
define( 'PCLZIP_OPT_EXTRACT_AS_STRING', 2 );
final class PclZip {
	public static $entries = array();
	public function __construct( $path ) {}
	public function listContent() {
		$list = array();
		foreach ( self::$entries as $name => $content ) {
			$list[] = array( 'filename' => $name, 'size' => strlen( $content ), 'folder' => false );
		}
		return $list;
	}
	public function extract( $option, $name, $mode ) { return array( array( 'content' => self::$entries[ $name ] ) ); }
}
PclZip::$entries = array(
	'xl/workbook.xml' => '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Roster" sheetId="1" r:id="rId1"/></sheets></workbook>',
	'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>',
	'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Rider ID</t></is></c><c r="C1" t="inlineStr"><is><t>Bib ID</t></is></c></row><row r="2"><c r="A2"><v>3</v></c><c r="C2"><v>4</v></c></row></sheetData></worksheet>',
);
list( $header, $rows ) = MRSA_Bonus::read_spreadsheet( 'fixture.xlsx', 'xlsx' );
check( $header === array( 0 => 'Rider ID', 2 => 'Bib ID' ) && $rows === array( array( 0 => '3', 2 => '4' ) ), 'XLSX parser preserves sparse columns and numeric cells' );
$wpdb->registrations = $baseline;
import_rows( $rows, $header );
check( end( $wpdb->registrations )['rider_id'] === 3 && end( $wpdb->registrations )['bib'] === 4, 'parsed XLSX rows enroll into selected event' );
PclZip::$entries['xl/worksheets/sheet1.xml'] = '<worksheet><sheetData><row><c r="A1"><f>1+1</f><v>2</v></c></row></sheetData></worksheet>';
try { MRSA_Bonus::read_spreadsheet( 'fixture.xlsx', 'xlsx' ); throw new LogicException( 'Formula workbook accepted.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'formulas' ) !== false, 'roster XLSX formulas rejected' ); }
PclZip::$entries['xl/vbaProject.bin'] = 'unsafe';
try { MRSA_Bonus::read_spreadsheet( 'fixture.xlsx', 'xlsx' ); throw new LogicException( 'Macro workbook accepted.' ); }
catch ( RuntimeException $exception ) { check( strpos( $exception->getMessage(), 'macros' ) !== false, 'roster XLSX macros rejected' ); }
