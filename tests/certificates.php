<?php
/** SQL-backed certificate approval/delivery and real local PDF regressions. No real mail is sent. */
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
function wp_generate_password( $length, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
function set_transient( $key, $value, $ttl ) { $GLOBALS['drafts'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['drafts'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['drafts'][ $key ] ); return true; }
function get_temp_dir() { return sys_get_temp_dir(); }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['certificate_uploads'] ?? __DIR__ . '/uploads', 'baseurl' => 'https://example.test/uploads' ); }
function get_attached_file( $id ) { return $GLOBALS['certificate_images'][ $id ] ?? false; }
function is_wp_error( $value ) { return $value instanceof RuntimeException; }
function wp_get_image_editor( $file ) { return new Certificate_Image_Editor( $file ); }
final class Certificate_Image_Editor {
	private $image;
	public function __construct( $file ) { $this->image = imagecreatefromstring( file_get_contents( $file ) ); }
	public function get_size() { return array( 'width' => imagesx( $this->image ), 'height' => imagesy( $this->image ) ); }
	public function resize( $width, $height, $crop ) {
		$scale = min( 1, $width / imagesx( $this->image ), $height / imagesy( $this->image ) );
		$this->image = imagescale( $this->image, (int) ( imagesx( $this->image ) * $scale ), (int) ( imagesy( $this->image ) * $scale ) );
		return true;
	}
	public function save( $file, $type ) {
		if ( ! imagepng( $this->image, $file ) ) { return new RuntimeException( 'Image fixture save failed.' ); }
		return array( 'path' => $file );
	}
}
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function wp_mail( $recipient, $subject, $message, $headers, $attachments ) {
	check( 1 === count( $attachments ) && is_file( $attachments[0] ), 'mail receives one private attachment' );
	$bytes = file_get_contents( $attachments[0] );
	check( 0 === strpos( $bytes, '%PDF-' ) && preg_match( '/%%EOF\s*$/', $bytes ), 'attachment is a complete real PDF with no appended HTML' );
	$GLOBALS['mail'][] = array( $recipient, $subject, $message, $attachments[0] );
	if ( ! empty( $GLOBALS['throw_mail'] ) ) {
		throw new RuntimeException( 'Simulated interrupted mail transport.' );
	}
	if ( ! empty( $GLOBALS['reentrant_batch'] ) ) {
		RQE_Certificates::send_batch( 7, $GLOBALS['reentrant_batch'] );
	}
	return empty( $GLOBALS['fail_mail'] );
}
require __DIR__ . '/event-administration.php';
require __DIR__ . '/../rally-quest-engine/includes/class-rqe-import-adapters.php';

$GLOBALS['actor'] = 1;
$GLOBALS['mail'] = array();
$wpdb->update( RQE::table( 'events' ), array( 'description' => 'Explore hidden places, discover extraordinary stories, and celebrate the adventure.' ), array( 'id' => 7 ) );
$wpdb->update( RQE::table( 'riders' ), array( 'name' => "Zo\xc3\xab & <script>bad</script>" ), array( 'id' => 10 ) );
RQE_Statuses::assign( 7, 1, 'event', 'Finisher' );
$snapshots = RQE_Certificates::snapshots( 7 );
check( count( $snapshots ) === 1 && $snapshots[0]['number'] === 42, 'certificates select manually finalized finishers and use the event bib' );
$html = RQE_Certificates::document( $snapshots );
check( strpos( $html, '&lt;script&gt;bad&lt;/script&gt;' ) !== false && strpos( $html, '<script>' ) === false, 'certificate participant data is escaped' );
check( strpos( $html, 'Total Score' ) !== false && strpos( $html, 'Accepted Bonuses' ) !== false && strpos( $html, 'Rejected Bonuses' ) !== false && strpos( $html, 'Overall Position' ) !== false && strpos( $html, '2026' ) !== false && strpos( $html, 'Explore hidden places' ) !== false, 'certificate includes description, position, score, bonus counts and year' );
$pdf = RQE_Certificates::pdf( $snapshots );
check( strlen( $pdf ) > 10000 && 0 === strpos( $pdf, '%PDF-' ), 'bundled renderer produces a real PDF with Unicode font support' );
check( preg_match_all( '/\/Type\s*\/Page\b/', $pdf ) === 1, 'a standard certificate fits exactly one landscape page' );
foreach ( array( 'Bronze', 'Silver', 'Gold' ) as $tier ) {
	$fixture = $snapshots[0];
	$fixture['status'] = $tier . ' Finisher';
	check( strpos( RQE_Certificates::certificate_html( $fixture ), 'alt="' . $tier . ' medal"' ) !== false, $tier . ' final status displays its matching medal' );
}
check( strpos( $html, 'class="medal"' ) === false, 'ordinary Finisher has no tier medal' );
$long = $snapshots[0];
$long['description'] = str_repeat( 'A long event description with safe <tag> text. ', 100 );
check( strpos( RQE_Certificates::document( array( $long ) ), nl2br( esc_html( $long['description'] ) ) ) !== false, 'long event descriptions are preserved in full on continuation pages' );
check( preg_match_all( '/\/Type\s*\/Page\b/', RQE_Certificates::pdf( array( $long, $snapshots[0] ) ) ) >= 3, 'long description continuation does not consume the next certificate page' );
rejects( function () use ( $snapshots ) { RQE_Certificates::pdf( array_fill( 0, 101, $snapshots[0] ) ); }, 'Print All' );
check( ! RQE_Certificates::eligible( array( 'status_manual' => 1, 'status' => 'dnf' ), array( array( 'label' => 'dnf', 'certificate_eligible' => 1 ) ) ), 'case variants cannot make DNF certificate eligible' );
RQE_Statuses::change_option( 7, 'event', 'add', 'Gold Final' );
RQE_Statuses::change_option( 7, 'event', 'certificate_on', 'Gold Final' );
RQE_Statuses::assign( 7, 1, 'event', 'Gold Final' );
check( RQE_Certificates::snapshots( 7 )[0]['status'] === 'Gold Final', 'explicitly eligible custom final statuses receive certificates' );
RQE_Statuses::assign( 7, 1, 'event', 'Finisher' );

$review = RQE_Certificates::review( 7 );
$wpdb->update( RQE::table( 'registrations' ), array( 'score' => 202 ), array( 'id' => 1 ) );
rejects( function () use ( $review ) { RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations' ); }, 'changed after review' );
$review = RQE_Certificates::review( 7 );
$GLOBALS['actor'] = 2;
rejects( function () use ( $review ) { RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations' ); }, 'expired' );
$GLOBALS['actor'] = 3;
rejects( function () { RQE_Certificates::snapshots( 7 ); }, 'not assigned' );
$GLOBALS['actor'] = 1;
$approved = RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations' );
rejects( function () use ( $review ) { RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations' ); }, 'expired' );
RQE_Event_State::change( 7, 'archive' );
$GLOBALS['reentrant_batch'] = $approved['batch'];
RQE_Certificates::send_batch( 7, $approved['batch'] );
unset( $GLOBALS['reentrant_batch'] );
check( count( $GLOBALS['mail'] ) === 1 && ! is_file( $GLOBALS['mail'][0][3] ), 'concurrent/reentrant delivery sends once and removes private attachment' );
check( RQE_Certificates::deliveries( 7, $approved['batch'] )[0]['state'] === 'sent', 'certificates can be emailed on archived/locked events' );
$review = RQE_Certificates::review( 7 );
$duplicate = RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations' );
check( $duplicate['queued'] === 0 && $duplicate['skipped'] === 1, 'unchanged sent certificates are skipped without explicit resend' );
$review = RQE_Certificates::review( 7 );
$resend = RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations', true );
$GLOBALS['fail_mail'] = true;
RQE_Certificates::send_batch( 7, $resend['batch'] );
unset( $GLOBALS['fail_mail'] );
check( RQE_Certificates::deliveries( 7, $resend['batch'] )[0]['state'] === 'failed', 'mail failures are explicit and require another review' );
$review = RQE_Certificates::review( 7 );
$uncertain = RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations', true );
$GLOBALS['throw_mail'] = true;
rejects( function () use ( $uncertain ) { RQE_Certificates::send_batch( 7, $uncertain['batch'] ); }, 'uncertain outcome' );
unset( $GLOBALS['throw_mail'] );
check( RQE_Certificates::deliveries( 7, $uncertain['batch'] )[0]['state'] === 'sending' && ! is_file( end( $GLOBALS['mail'] )[3] ), 'interrupted transport retains uncertain state and removes its private PDF' );
$review = RQE_Certificates::review( 7 );
$stale = RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations', true );
$wpdb->update( RQE::table( 'riders' ), array( 'email' => 'changed@example.test' ), array( 'id' => 10 ) );
RQE_Certificates::send_batch( 7, $stale['batch'] );
check( RQE_Certificates::deliveries( 7, $stale['batch'] )[0]['state'] === 'stale' && count( $GLOBALS['mail'] ) === 3, 'recipient changes invalidate approval before sending' );
$wpdb->update( RQE::table( 'riders' ), array( 'email' => 'invalid' ), array( 'id' => 10 ) );
$review = RQE_Certificates::review( 7 );
rejects( function () use ( $review ) { RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations' ); }, 'valid email address' );
$wpdb->update( RQE::table( 'riders' ), array( 'email' => 'one@example.test' ), array( 'id' => 10 ) );

for ( $i = 100; $i < 111; ++$i ) {
	$wpdb->insert( RQE::table( 'riders' ), array( 'id' => $i, 'name' => 'Participant ' . $i, 'email' => 'p' . $i . '@example.test' ) );
	$wpdb->insert( RQE::table( 'registrations' ), array( 'id' => $i, 'event_id' => 7, 'rider_id' => $i, 'bib' => $i, 'status' => 'Finisher', 'status_manual' => 1, 'score' => 300 ) );
}
$review = RQE_Certificates::review( 7 );
$bulk = RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations' );
$before = count( $GLOBALS['mail'] );
RQE_Certificates::send_batch( 7, $bulk['batch'] );
check( count( $GLOBALS['mail'] ) - $before === 10, 'bulk requests send at most ten separate participant emails' );
$states = array_column( RQE_Certificates::deliveries( 7, $bulk['batch'] ), 'state' );
check( count( array_filter( $states, function ( $state ) { return 'pending' === $state; } ) ) === 2, 'remaining deliveries include re-ranked participants whose certificate changed' );
RQE_Certificates::send_batch( 7, $bulk['batch'] );
check( count( $GLOBALS['mail'] ) - $before === 12, 'continuation sends remaining certificates without duplicating completed recipients' );
$wpdb->update( RQE::table( 'certificate_mail' ), array( 'updated_at' => '2000-01-01 00:00:00' ), array( 'batch' => $bulk['batch'] ) );
RQE_Certificates::expire();
check( count( RQE_Certificates::deliveries( 7, $bulk['batch'] ) ) === 0, 'delivery details expire after thirty days' );
$directory = sys_get_temp_dir() . '/rqe-certificate-images-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Image test directory could not be created.' ); }
$GLOBALS['certificate_uploads'] = $directory;
$image_file = $directory . '/event.png';
$GLOBALS['certificate_images'][55] = $image_file;
try {
	$image = imagecreatetruecolor( 600, 240 );
	$dark = imagecolorallocate( $image, 33, 41, 43 );
	$gold = imagecolorallocate( $image, 184, 150, 75 );
	$cream = imagecolorallocate( $image, 255, 250, 240 );
	imagefill( $image, 0, 0, $dark );
	imagefilledellipse( $image, 180, 67, 53, 53, $gold );
	imagefilledpolygon( $image, array( 65, 178, 160, 68, 244, 178 ), $cream );
	imagefilledpolygon( $image, array( 150, 178, 249, 100, 310, 178 ), $gold );
	imageline( $image, 55, 192, 545, 192, $gold );
	imagettftext( $image, 20, 0, 325, 110, $cream, __DIR__ . '/../rally-quest-engine/vendor/dompdf/dompdf/lib/fonts/DejaVuSerif.ttf', 'RALLY' );
	imagettftext( $image, 20, 0, 325, 146, $gold, __DIR__ . '/../rally-quest-engine/vendor/dompdf/dompdf/lib/fonts/DejaVuSerif.ttf', 'QUEST' );
	if ( ! imagepng( $image, $image_file ) ) { throw new RuntimeException( 'Image fixture could not be written.' ); }
	$wpdb->update( RQE::table( 'events' ), array( 'logo_id' => 55 ), array( 'id' => 7 ) );
	$reference = RQE_Certificates::image_reference( 55 );
	$with_image = RQE_Certificates::snapshots( 7, 10 );
	check( $with_image[0]['image'] === $reference && strpos( RQE_Certificates::document( $with_image ), 'data:image/png;base64,' ) !== false, 'configured event media is normalized and embedded locally in the certificate' );
	$image_pdf = RQE_Certificates::pdf( $with_image );
	check( preg_match_all( '/\/Type\s*\/Page\b/', $image_pdf ) === 1 && strpos( $image_pdf, '/Subtype /Image' ) !== false, 'image-bearing certificate is a single-page PDF with an embedded raster' );
	$review = RQE_Certificates::review( 7, 10 );
	$wpdb->update( RQE::table( 'events' ), array( 'certificate_design' => 'classic' ), array( 'id' => 7 ) );
	rejects( function () use ( $review ) { RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations', true ); }, 'changed after review' );
	$classic = RQE_Certificates::snapshots( 7, 10 );
	check( $classic[0]['design'] === 'classic' && strpos( RQE_Certificates::document( $classic ), 'certificate-classic' ) !== false, 'saved event design controls the shared print, PDF and review document' );
	check( preg_match_all( '/\/Type\s*\/Page\b/', RQE_Certificates::pdf( $classic ) ) === 1, 'Classic Formal produces a single-page certificate' );
	$wpdb->update( RQE::table( 'events' ), array( 'certificate_design' => 'vintage' ), array( 'id' => 7 ) );
	$review = RQE_Certificates::review( 7, 10 );
	$wpdb->update( RQE::table( 'registrations' ), array( 'score' => 0 ), array( 'id' => 100 ) );
	rejects( function () use ( $review ) { RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations', true ); }, 'changed after review' );
	$review = RQE_Certificates::review( 7, 10 );
	$wpdb->update( RQE::table( 'events' ), array( 'description' => 'Changed event description.' ), array( 'id' => 7 ) );
	rejects( function () use ( $review ) { RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations', true ); }, 'changed after review' );
	$review = RQE_Certificates::review( 7, 10 );
	imagesetpixel( $image, 10, 10, $cream );
	imagepng( $image, $image_file );
	rejects( function () use ( $review ) { RQE_Certificates::approve( 7, $review['token'], 'Certificate', 'Congratulations', true ); }, 'changed after review' );
	rejects( function () use ( $with_image ) { RQE_Certificates::pdf( $with_image ); }, 'image changed after' );
	$GLOBALS['certificate_images'][56] = __FILE__;
	rejects( function () { RQE_Certificates::image_reference( 56 ); }, 'local WordPress media' );
	rejects( function () { RQE_Certificates::image_reference( 57 ); }, 'local WordPress media' );
	$samples = array();
	foreach ( array( 'Finisher', 'Bronze Finisher', 'Silver Finisher', 'Gold Finisher' ) as $index => $status ) {
		$samples[] = array(
			'event' => 'Autumn Horizons Rally',
			'description' => 'A scavenger-hunt adventure through winding roads, hidden landmarks and unforgettable landscapes. Celebrating curiosity, determination and the joy of the journey.',
			'image' => RQE_Certificates::image_reference( 55 ),
			'year' => '2026',
			'name' => 'Alex Morgan',
			'number' => 42,
			'score' => array( 1250, 1800, 2400, 3200 )[ $index ],
			'accepted' => array( 18, 24, 30, 36 )[ $index ],
			'rejected' => 2,
			'position' => array( 24, 16, 8, 1 )[ $index ],
			'status' => $status,
		);
	}
	$samples_pdf = RQE_Certificates::pdf( $samples );
	check( preg_match_all( '/\/Type\s*\/Page\b/', $samples_pdf ) === 4, 'sample pack renders exactly one page per Finisher/Bronze/Silver/Gold design' );
	$classic_samples = array_map( function ( $data ) { $data['design'] = 'classic'; return $data; }, $samples );
	$classic_pdf = RQE_Certificates::pdf( $classic_samples );
	check( preg_match_all( '/\/Type\s*\/Page\b/', $classic_pdf ) === 4, 'Classic Formal sample pack includes all four status medals without changing status data' );
	foreach ( RQE_Statuses::final_finishers() as $index => $status ) {
		$wpdb->update( RQE::table( 'registrations' ), array( 'status' => $status, 'status_manual' => 1 ), array( 'id' => 100 + $index ) );
		check( RQE_Certificates::snapshots( 7, 100 + $index )[0]['status'] === $status, $status . ' participates in individual and all certificate selection' );
	}
	if ( getenv( 'RQE_CERTIFICATE_SAMPLE_DIR' ) ) {
		$output = getenv( 'RQE_CERTIFICATE_SAMPLE_DIR' );
		file_put_contents( $output . '/finisher-certificate-samples.pdf', $samples_pdf );
		file_put_contents( $output . '/certificates.html', RQE_Certificates::document( $samples ) );
		file_put_contents( $output . '/finisher-certificate-classic-samples.pdf', $classic_pdf );
		file_put_contents( $output . '/classic-certificates.html', RQE_Certificates::document( $classic_samples ) );
	}
} finally {
	unlink( $image_file );
	rmdir( $directory );
}
echo "Certificate regressions passed.\n";
