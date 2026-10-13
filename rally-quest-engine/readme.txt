=== Rally Quest Engine ===
Contributors: oconnoradv
Tags: rally, scavenger hunt, motorcycle, bicycle, geocache
Requires at least: 6.2
Requires PHP: 7.4
Requires Plugins: action-scheduler
Stable tag: 0.4.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Produce and manage scavenger-hunt style rallies for motorcycle, bicycle, horseback and geocache participants.

== Description ==

Rally Quest Engine (formerly Motorcycle Rally Scoring Application) helps organizers
produce and manage a high-quality scavenger-hunt style rally for Motorcyclists,
Bicyclists, Geocachers or Horseback participants. Efficient setup guides each
event through its required details, focused administration keeps participants,
conveyances, rosters and roles in one place, and near-real-time results update
as emailed bonus submissions are reviewed.

A leftmost Initial Setup tab explains how to use the application and where to
get help, configures one encrypted global submission inbox, and stores the
primary scavenger hunt style: Motorcycle (MC), Bicycle (BC), Horseback (HB) or
GeoCache (GC). The primary style sets the default style for new events and the
conveyance tab: Bikes for Motorcycle and Bicycle (Bicycle
hides the NHTSA import, renames Manual Bicycle Entry and greys out fuel
capacity), Horses for Horseback (Name, Breed, Sex, Age, Color), and none for
GeoCache. Event IDs combine the event style code and numeric ID, such as MC-7.

Local scoring bonus images upload and scan one file per request with JavaScript.
After scanning, every successful image appears in one combined review and one
bulk authorization imports the passing set. Without JavaScript, choose one image
per import. Current per-file limits still apply.
Diagnostics checks post capacity against one 10 MB file plus 1 MB overhead.

Development separates administrative request handling from import services,
uses database-injected read models for screen queries, and shares pure ranking
and status rules across rosters, results and rider score sheets. Diagnostics
composes focused check groups with one validated report-state precedence.
Authorization, scanning, audit requirements and coordinated database/gallery
rollback remain part of the operation contracts. Standalone PHP regression
suites exercise these contracts; no new production dependencies are required.

Release publication requires successful CodeQL JavaScript security analysis
for public repositories and no open repository Dependabot alerts, in addition
to the existing gates. Private repositories skip CodeQL with an Actions notice,
job summary and release-note disclosure; the other gates remain mandatory.
Repository owners must enable the security features and configure the
read-only RELEASE_SECURITY_TOKEN Actions secret as documented in README.md.
Unavailable alert access or failed required analysis blocks publication. CodeQL does
not analyze PHP; PHP remains covered by Semgrep.

Rally Quest Engine provides administrative tabs for:

* Initial Setup: Welcome guidance, an explicitly assigned shared inbox, a separately saved primary style, and Required Plugins and Configuration readiness. The default entry changes to Events only when all four setup sections are complete.
* Participants: participant details, conveyances for the primary style (make/model-filtered motorcycles or bicycles with optional manually entered four-digit years, or horses), emergency contacts, insurance information, and registered events.
* Events: style-coded Event IDs, required event details including a description, separate per-event Administrator and Scorer assignments, a read-only global inbox summary, encrypted event inbox overrides, independent status catalogs, and Roster links.
* Rosters: persistent header event context and a roster table with rider ID, bib ID, editable Registration Status, name, email, score, and overall placing. Rider IDs open rider details/editing, bibs open event scoring, and enrollment remains a separate screen.
* Scoring: event-specific Bonus Point Library, CSV/XLSX listing import, local-folder and public-URL image import, email submission review, required rejection reasons, and transactional point awards.
* Results: score ordering, computed overall placing, accepted/rejected submission counts, editable independent Event Status, and rider score sheets with submission history.
* Horses (Horseback style): Name, Breed, Sex, Age and Color records assignable to participants.
* Bikes (Motorcycle and Bicycle styles): side-by-side manual entry and motorcycle make/model import on wide screens, stacked on narrow screens, plus transactional bulk deletion of unassigned catalog rows.
* Rosters: Import Participants beside Add Participant opens a separate selected-event CSV/XLSX import screen for existing participants by Participant ID (or legacy Rider ID) or Email, with a downloadable sample CSV and a Back to Rosters link. Optional Bib ID, Registration Status (legacy Status accepted) and Pillion columns; statuses must be enabled for the event and blank bibs receive the next available number. WordPress administrators only, maximum 10 MB / 5,000 rows. ClamAV is required; invalid or conflicting rows reject the whole batch.
* Diagnostics: on-demand required-plugin/environment checks with native WordPress install/activate controls for required Action Scheduler; Gallery for SpotWalla remains optional. PHP, database/InnoDB/schema, ClamAV, private staging/storage and upload capacity are checked against current RQE limits (10 MB spreadsheets/images, 50 MB ZIP/download, 100 MB / 200 images per batch). Unverifiable hosting and scanner policies are labeled Manual review. Individual manual reviews may be ignored for setup readiness; a site administrator may separately acknowledge failed checks for setup navigation. Neither action hides failures or bypasses runtime checks. Detailed reports are private to the checking administrator and expire after 15 minutes; only a minimal readiness summary is shared site-wide. JSON export, restorable SQL backup, and verification-string-protected scoped deletion are also available. Gallery image files are stored separately and are not included in database exports or backups.
* About: version, licensing, attribution, scope, and an optional Gallery for SpotWalla recommendation with documentation and download links.

The Bonus Point Library stores event-specific bonus IDs, descriptions,
categories, points, one latitude/longitude pair, and matching image files.
Step 1 offers Download sample bonus listing XLSX with all five required
column headings and one example row. Replace/remove the example before
importing and retain the headings on the first worksheet.
First import the CSV or XLSX listing (step 1), then import images from a local
directory or public HTTPS image, ZIP, or standard directory-listing URLs
(step 2). Each step scans files locally with ClamAV, shows the scan report,
and normally requires explicit approval before importing. Local clamd with current
freshclam signatures is recommended; scanner errors always block imports.
When the local daemon is missing or refuses connections, an administrator can
explicitly confirm a per-account, per-site bypass. Later validated bulk imports
while ClamAV is unavailable do not require another approval prompt and display
an unscanned-import warning. Available scanners are still used; malware,
scanner errors, and unsafe content cannot be bypassed. Consent and unscanned
import activity retain user ID, username, UTC timestamp and event in the import
audit table. Diagnostics displays recent audit records; exports include all
records. Diagnostics data deletions retain the audit and bypass choices and
record the administrator, UTC timestamp and deletion scope in the same log.
Delete Virus Scan Audit Data explicitly clears that history and resets all
remembered overrides; a new record of the deletion remains. Destructive actions
use a responsive three-column layout with randomized-string verification in a
confirmation pop-up (inline without JavaScript or dialog support).
Remove ClamAV Scanning Override in Step 0 revokes the choice for your account. Manual image
replacement still requires ClamAV.
JPEG, PNG, GIF and WebP checks distinguish compressed pixels and binary color
tables from script text, preventing short random byte sequences from causing
false executable-content warnings. Metadata is still inspected, including
bounded PNG metadata decompression. Malformed/truncated containers, appended
content and malware results remain blocked; ClamAV scans the entire file.
JPEG, PNG, GIF and WebP imports keep only the primary image: data appended
after its end marker (JPEG EOI, PNG IEND, GIF trailer, WebP RIFF length) such
as gain maps, multi-picture images, editor/vendor trailers, motion-photo video
or padding is removed before scanning and is never stored.
The persistent ClamAV bypass warning is inside the minimized Step 0 panel.
Step 2 collects filename, local upload, URL, limit and skip-failed guidance in
Help? beside its heading, available on hover, keyboard focus or click/tap.

Each Diagnostics section has a native expand/collapse control. Administrative
Audit and Destructive operations default to minimized. Failed configuration
checks keep their section expanded and highlight failed rows red. With no
failures, Manual review results have an orange outline and all-passed results
have a green outline; both default to minimized. Failed results use a red
outline.
When minimized, the configuration panel also uses a matching pale green,
orange or red background; expanded panels retain their neutral background.
After a report expires, its last outcome retains the border color
and expanded/minimized default; the heading says Last check and Results
expired. Only the last outcome is retained per administrator/site; expired
diagnostic details are not retained. Before any check, the section remains
neutral and expanded so the check can be run.
Failed checks include expanded How to fix this requirement instructions;
Manual review items have expandable How to verify this requirement guidance.
Instructions cover WordPress/plugin menus, web-serving PHP settings, database
repair precautions, private storage, ClamAV and hosting/cron configuration.
Back up and test server changes on staging; rerun the check after repairs.
An unsuccessful ClamAV live check is Manual review when the current
administrator has an audited override from the scoring page. A working
scanner remains Passed. Overrides do not change import safeguards or make
roster imports available without ClamAV. Rerun Diagnostics after changing
the override to refresh the report.
Step 0 on the import screen provides optional setup commands and configuration
guidance plus a Check ClamAV Connection button. Run installation commands in
the server terminal with server-administrator access, not WordPress. Existing
pre-installed ClamAV is supported. The check scans a harmless local test file
without importing data; it does not certify signatures or scan configuration.
The default Unix socket can be overridden with RQE_CLAMAV_SOCKET in
wp-config.php (local Unix sockets or loopback TCP only). Configure clamd to
scan archives and Office/executable formats, enable AlertExceedsMax, and allow
at least 60 MB streams/files and 200 MB expanded scans. See README for setup.
Pending files stay outside the web root in private temporary storage.
RQE_IMPORT_TEMP_DIR can override the temporary directory. Reviews expire in
15 minutes, verify fingerprints, and require the original user/event.
Cancellation deletes staged files; WordPress cron cleans expired imports.
Formulas, macros, embedded files, script markers, and appended image data
are rejected. A passed scan is not a guarantee that a file is harmless.
URL image imports are all-or-nothing by default. Local folder imports use
one-image scan requests, list all passing images, and require one bulk
authorization. Without skip-failed enabled, an invalid image cancels the staged
passing set before authorization. With skip-failed enabled, invalid images are
excluded with filename/source-specific errors. Scanner errors stop further
uploads and cancel the staged set. Stop after current file finishes an in-flight
request and cancels staged reviews before authorization. During the bulk update,
earlier confirmed imports remain if a later request fails. Network failures stop without automatic retries:
inspect the gallery or saved scan review before retrying an uncertain result.
Error reports are private to the importing administrator and expire after
15 minutes. Recovery failures stop the import. Click gallery images to open
a larger overlay preview; Close image, Escape, or clicking outside the preview
returns to the listing. Re-importing an ID updates its
listing without removing other event listings. Images are stored in a dedicated
event folder and are not registered in the WordPress media library. The global
Inbox setup can poll one encrypted IMAP, Gmail API, or Microsoft 365 mailbox
assigned explicitly to one active event at a time. Assignment does not follow
the page-header selection. Existing event mailboxes remain explicit overrides,
take precedence for their event, and can be removed by a site administrator to
restore use of an assigned global inbox. A disabled saved override still takes
precedence until removed. Global and event inbox configuration, connection
testing and manual polling are restricted to WordPress site administrators.
Unread messages are accepted only when the sender matches one registered rider,
the subject contains exactly one existing Bonus ID, and exactly one supported
image passes format, executable-content, and ClamAV checks. Pending Review shows
the rider image beside the matching Bonus Point Library ID and image. Event
Administrators or separately assigned Event Scorers can accept or reject the
submission. Accepted library points update the rider total transactionally and
exactly once. Rejections require a reason and remain in Reviewed and rider
history. Results orders total scores using competition
ranking and displays the automatically derived Preliminary Finisher status
when a score is equal to or greater than Minimum Finisher Points.
Administrators manually finalize Event Status as Finisher, DNF or DNS;
manual assignments override later automatic calculations. Registration Status
is separately editable on Rosters. New registrations use Initial Registration
when enabled (otherwise the first enabled choice), automatic Event Status unless Cancelled,
score 0, and unset stored placing. Gallery files live in
the dedicated uploads/rqe-event-gallery directory and must be backed up
separately from the JSON and SQL database exports.
Diagnostics can delete all bonus listings, submissions, scoring images, and
submission-awarded score points for a selected event.

The plugin has no site-wide administrator assignment. WordPress administrators
manage shared riders, motorcycles, event creation, diagnostics, enrollment,
and event Administrator/Scorer assignments through WordPress capabilities. Assigned
Event Administrators are restricted to their assigned events and enrolled
riders; they cannot manage shared motorcycles, master rider records, other
events, diagnostics, or role assignments. Event Scorers can access only Scoring
and About for their assigned events.

Only WordPress site administrators can configure inboxes, test connections or
manually poll. Event Administrators and Scorers retain assigned submission
review. Global polling pauses when its assigned event is locked, archived or
unavailable; event overrides are checked before global mail is read. Polls,
connection tests, saves, assignment changes and override removal share
per-source locks to avoid concurrent credential changes or message-assignment
races. Provider messages are marked processed only after their database record
commits. Changing the IMAP host, port, encryption or username requires a newly
entered password; blank passwords retain the old secret only for an unchanged
account. Use trusted mailbox servers and encrypted transport.

Global and event inbox secrets are encrypted with AES-256-GCM using the
WordPress authentication salts. The global inbox uses row 0 in the existing
encrypted mailbox-settings table and is included in diagnostics JSON/SQL
backups; backups must still be protected as sensitive data. Saving credentials
or changing the assignment invalidates connection verification. IMAP requires
the PHP IMAP extension. Gmail requires a
refresh token authorized for gmail.modify; selecting Gmail API displays a
Help? hover/focus panel with Google Cloud project, Gmail API, OAuth consent,
credential, scope, and refresh-token setup steps. Microsoft 365 requires delegated
Mail.ReadWrite and offline_access consent. Administrators provide refresh
tokens; this plugin does not implement interactive OAuth consent callbacks.
Changing WordPress authentication salts requires re-entering saved inbox
credentials. Exports and SQL backups contain encrypted credential ciphertext
and should still be protected as sensitive administrative backups.

Tables use the current site's WordPress prefix followed by RQE_, avoiding
collisions between sites. No WordPress core tables or users are replaced.

== Installation ==

1. Back up the database and use a supported, patched WordPress/PHP installation.
2. Upload rally-quest-engine-vX.Y.Z.zip through Plugins > Add New > Upload Plugin.
3. Activate Rally Quest Engine.
4. Open Rally Quest in the administration menu as a WordPress administrator.
5. In Initial Setup, save the primary scavenger hunt style and configure a
   shared inbox when needed. Required Plugins and Configuration becomes ready
   after checks pass, all manual reviews are explicitly ignored for setup, or
   a site administrator records a setup-only override. Runtime safeguards
   remain in force.
6. Populate Bikes or Horses, add participants, and configure events.
7. Enroll participants in an event using an unused bib number.

Upgrading from Motorcycle Rally Scoring Application: version 0.2.0 uses new
tables (RQE_*), options and gallery folder (rqe-event-gallery), so it installs
as a separate plugin. Back up first, activate Rally Quest Engine, deactivate
Motorcycle Rally Scoring Application (upgrade it to 0.1.20 first if older),
then open wp-admin as a WordPress administrator. Existing data and gallery files
are imported once in a single transaction and an admin notice reports the
result. The import is skipped if Rally Quest Engine already has data, and the
legacy tables and gallery are left unchanged. Imported events presume the
Motorcycle style. Delete the former plugin only after verifying the import.

Event dates are derived from the configured start date/time in the WordPress
site timezone. Add Rider and Add Event open dedicated edit pages with one
attribute per row. Enrolled rider details remain read-only outside explicit
registration controls.

Rider ID and bib links from Rosters return to that selected roster through
the destination page's back link. Successful forms return to their originating
form or tab. If Rosters has
no unenrolled rider to select, Add Rider to Event can open the Add Rider form,
automatically enroll the new rider with the lowest available bib, and return
to the selected roster. Registration Status is editable by authorized
administrators and is separately managed from Event Status on Results.
Changing registration to Cancelled assigns manual DNS atomically, even if DNS
is disabled for manual selection. Cancelled imports and enrollments also receive
DNS. Administrators may override Event Status afterward. Unchanged Cancelled
saves and registration changes away from Cancelled retain that override.
Registered, Preliminary Finisher and DNS are protected system values and
cannot be deleted. The schema 11 upgrade assigns DNS once to existing Cancelled
registrations, replacing their previous Event Status without changing scores
or placing. Back up before upgrading and review affected final statuses.

Public Results Embed on Event Configuration displays the read-only RQE Event
ID and a copyable shortcode, for example [rqe_results id="MC-7"]. The style
code must match the event; a numeric ID is also accepted, and former
[mrsa_results] embeds keep working while the old plugin is inactive. Publication is
off by default; a WordPress administrator or assigned Event Administrator must
enable it and save. Add the existing WordPress Shortcode block to a post/page,
paste the shortcode, then publish or update. Its explicit ID does not depend
on the administrator header selection; multiple event embeds are supported.
Logged-out visitors see the event name, description and image, then only Bib,
Participant Name, Total Score, Accepted Bonuses,
Rejected Bonuses, Event Status and Overall Placing for enabled events.
Contact details, Registration Status, private review details, administrative
controls and administrative score-sheet links are excluded. Disabled/missing events show an
unavailable message. Purge WordPress page and CDN caches after visibility or
score/status changes; disabling publication cannot recall previously cached
or delivered results.

Enable the separate Publish accepted bonus details and photos option to make
public Bibs link to accepted Bonus IDs, descriptions, awarded points and submitted
proof thumbnails. This is off by default and requires public results enabled.
Public details recheck publication and enrollment; rejected and pending records
are excluded. Obtain participant permission before publishing photos. Missing
images are identified explicitly. Direct uploads URLs and downloaded/cached
copies cannot be revoked by turning the listing off.
Administrative Results and Reviewed Bibs open an accepted-bonus section while
the participant's complete submission history remains available below it.

The leftmost Initial Setup tab contains Welcome, Default Email Inbox Setup,
Primary Scavenger Hunt Style,
and Required Plugins and Configuration. Welcome becomes green and collapses
after global email is configured and at least one participant and event exist.
Default Email Inbox Setup becomes green and collapses after the provider
connection is verified. Polling additionally requires enabling it and assigning
an unlocked/unarchived event. Changing an existing assignment requires
acknowledging that unread messages for the previous event have been handled.
Primary Scavenger Hunt Style becomes green only after an explicit save. Events is
the default entry only when all four sections are green. Setup readiness does
not grant permissions or relax runtime validation.

Required Plugins and Configuration reports detailed results only to the
administrator who ran the check, for 15 minutes. Each Manual review result has
an individual Ignore action for setup readiness. Failed checks remain visible;
a separate confirmation may acknowledge them for setup navigation only.
Neither choice bypasses event state, polling, import, or other runtime
safeguards. A new check clears any failed-check setup override.
Both setup and diagnostics summaries show the count of applicable overridden
checks, without double-counting overlapping overrides or including passed
checks. Expired private reports retain a labeled last-check count; older
summaries without that count require rerunning the check.

Successful global inbox saves and all global connection tests return to Initial
Setup. Event inbox saves and tests return to Event Configuration. Failed saves
return to their configuration screen with a correction notice.
Main tabs are ordered Initial Setup, Events, Rosters, Scoring, Results,
Participants, Bikes/Horses, Diagnostics, About; GeoCache omits conveyances.
Green Initial Setup/About, blue workflow tabs, and orange Diagnostics headers
are categories, not readiness indicators. Role-based visibility is unchanged.

Choose a scavenger hunt style (defaulting to the primary style), then one of
its events in the header and press Set Event; this event persists per user
and site across Rosters, Scoring and Results until changed. Changing the
header event clears record filters; already-open forms keep their own event.
Two-column event configuration places Event Details and Event Administrator /
Scorer assignments on the left. The right side shows a grey read-only global
inbox summary and Event Inbox Override controls, followed immediately by Event
Status and Registration Status customization. Columns stack on small screens.
A saved event override takes precedence over the global inbox, including when
its polling is disabled, until a site administrator removes it. Configure,
test or remove an override from Event Configuration. Configured does not imply
Connection verified, and failed tests remain expanded.
When the effective inbox is verified and enabled, both email sections become
green and minimized, identifying Global or Event-specific inbox use. Global
email also requires assignment to this active event.
Event Protection and Certificates appears minimized below the event embed
section and on Results, sharing the same saved state and certificate actions.
Its summary shows Open (green), Locked (yellow), or Locked & Archived (blue).
Changes return to the invoking screen and appear in both places; existing
lock, unlock and archive permissions are retained.

The global inbox can be assigned to one unlocked, unarchived event at a time;
this assignment is independent of the page-header event selector. Event inbox
settings are independent explicit overrides, including legacy configurations.
Connection tests authenticate without reading messages. Saving credentials or
changing assignment invalidates verification. Polling, testing, assignment
changes and override changes are guarded against concurrent inbox operations;
provider messages are marked processed only after their database record commits.
Global credentials use row 0 in the existing encrypted mailbox settings table,
so existing JSON/SQL backups and data deletion include them without a schema
migration. Backups contain encrypted ciphertext and remain sensitive.
Event-local statuses can be added, disabled/re-enabled, and deleted only
when unused. Disabled assigned values are retained, but cannot be newly
assigned. Keep one registration value enabled for enrollment; automatic
Registered/Preliminary Finisher values cannot be deleted and disabling them
only affects manual selection.

Use MySQL 8.0+ or MariaDB 10.6+ with InnoDB tables. Atomic rider updates,
catalog deletion, and event-capacity changes rely on transaction and row-lock
support. Keep the database server on a vendor-supported, patched release.

Activation creates the current site's plugin tables. Activate individually
on each site when using multisite; network activation is not supported.

== Motorcycle data and external services ==

Source reference: https://vpic.nhtsa.dot.gov/api/

The NHTSA Product Information Catalog and Vehicle Listing (vPIC) API provides
vehicle make and model data. An administrator supplies only a make and must
explicitly initiate and confirm retrieval; no remote import runs on activation.
The importer intersects Get Models for Make results with a second response
restricted to NHTSA's motorcycle vehicle type and stores only model IDs present
in both responses. Only make and model are imported. A four-digit model year
may be entered manually and optionally when assigning a motorcycle to a rider;
it is not validated against the catalog.

An authorized retrieval discloses the site's server IP and ordinary HTTP
request metadata plus the entered make to NHTSA. Rider, administrator, and
event data are not sent. Each of the two requests is limited to 256 KB, follows
no redirects, and fails closed if the source is unavailable, malformed,
unconfirmed, or non-motorcycle. Manual motorcycle entry remains available.

== Privacy and retention ==

Results provides administrator-reviewed finisher certificates. Manually assigned
Finisher, Bronze Finisher, Silver Finisher and Gold Finisher are always eligible;
custom final statuses can opt in. Preliminary
statuses, Registered, DNF and DNS are excluded. Certificates include event/year,
participant name and Bib, score, accepted/rejected bonus counts and Event Status.
The original vintage-style A4 landscape template also includes the Event Image,
Event Description and Overall Position. Bronze, Silver or Gold final statuses
show a matching medal; ordinary Finisher has none. Long descriptions continue
on following pages without truncation. A configured event image must be a
readable local-upload JPEG/PNG/GIF/WebP up to 10 MB / 16 million pixels; image
PDFs require PHP GD. Missing configured media shows an error, while an event
with no image uses the Rally Quest Engine wordmark. Description, image content,
overall position or template-revision changes require a fresh email review.
Print individually or all, save through the browser, or download individual PDFs.
PDF attachments are generated locally using bundled Dompdf, with remote resources
disabled and private staging outside the web root. Attachments are removed after
each send; no external rendering service is used.

Review recipients, previews, subject and message before explicit approval.
Reviews expire after one hour; changed certificate data or recipients require
another review. Email uses the site's wp_mail configuration, separately for each
participant, in batches of ten with explicit continuation. Results links recent
batches. Sent means accepted by the mail system, not confirmed delivery. Unchanged
deliveries are skipped unless expressly approved for resend. Failed, stale or
uncertain interrupted sends are never retried automatically. Pending approvals
expire after one day; delivery details are cleaned after thirty days when a site
administrator accesses wp-admin. Completed snapshots/messages are cleared.
Delivery records are included in personal-data export/erasure and diagnostics
backup/export. Review transients can remain for one hour; participant erasure
invalidates their fingerprints so they cannot be sent.

Lock Event prevents result mutations, imports, enrollment and submission review.
Event Administrators may lock; only site administrators may unlock/archive.
Archive also locks; unarchive keeps locked. Public embeds/certificates remain
available. The per-user/site Show archived events header preference defaults off;
a selected archived event remains visible with a read-only notice.

Rider records may contain names, aliases, email addresses, phone numbers,
motorcycles, fuel capacity, emergency contacts, insurance details, and other
information. Event registrations link those records to events and bibs.
Administrator assignments reference WordPress users and can include secondary
contact details. Event logos reference existing WordPress media.

Site operators are responsible for informing participants, obtaining any
required consent, documenting retention, and protecting emergency contacts'
and pillions' information. Avoid putting identity documents, payment card
details, or unnecessary sensitive information in free-text fields.
Data is stored in the WordPress database, not encrypted separately by this
plugin. Use TLS, restricted database access, secure backups, and least-privilege
accounts. There is no analytics, telemetry, or remote rider-data service.

Use WordPress personal-data export and erasure tools where applicable.
Review exports and erasures against your legal retention obligations.
Erasure anonymizes the rider and removes motorcycle and pillion details;
registration identifiers, bibs, statuses, scores, and placing remain for event integrity.
Administrator assignments remain for access control, while secondary contact
details are cleared. Event assignments can be removed from the event Configure
page. Deactivation preserves data. Fresh installs retain data on uninstall by
default; existing sites continue to honor any previously saved uninstall-
deletion preference. Removing plugin data does not delete WordPress users or
media attachments. Back up before uninstalling a site with that preference.

== Security and directory guidelines ==

The implementation uses WordPress authorization and nonces, validated inputs,
escaped output, parameterized database operations, and database uniqueness
constraints for event bibs and rider enrollment. Assets use WordPress/core
facilities rather than remotely hosted scripts. Code is human-readable and
licensed under GPLv3 or later; the repository license is bundled in the ZIP.

Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/

These controls do not certify compliance with every CVE or NIST publication.
CVE is a vulnerability catalog; NIST requirements depend on the deployment
and applicable framework. WordPress.org listing/approval, penetration testing,
and formal compliance certification have not been performed. Operators must
maintain WordPress, PHP, and other plugins, evaluate their applicable NIST
controls, and arrange independent security assessment before production use.

== Frequently Asked Questions ==

= Does this version score rallies? =

Yes. Event Administrators and Event Scorers can accept valid email bonus
submissions to award the matching Bonus Point Library points exactly once.
Contested-submission handling remains reserved for a later specification.

= Can riders register themselves on the public website? =

No. The current version provides administrator-operated registration.

= Is this a payment processor? =

No. Rider and pillion prices are event configuration values only.

== Changelog ==

= 0.4.1 =
* Display the number of applicable overridden checks on Initial Setup and Diagnostics, including a labeled last-check count after private reports expire.
* Return global inbox saves and connection tests to Initial Setup; return event inbox saves and tests to the originating event. Failed saves retain the configuration screen for correction.
* Minimize verified global setup and verified enabled effective event inbox sections, shade them green, and identify Global or Event-specific inbox use.
* Share Event Protection and Certificates controls between Results and Event Configuration below the embed section; default minimized with Open (green), Locked (yellow), or Locked & Archived (blue) status.
* Order tabs as Initial Setup, Events, Rosters, Scoring, Results, Participants, Bikes/Horses, Diagnostics, About; use green, blue and orange category colors without changing role or style visibility.
* Preserve mailbox encryption, authorization, nonce checks, audited event-state mutations and runtime safeguards without a schema migration.

= 0.4.0 =
* Add a leftmost Initial Setup flow for Welcome, global inbox assignment, explicitly saved primary style, and shared Required Plugins and Configuration readiness.
* Assign one encrypted global inbox to one active event at a time, independently of the header selector; preserve event inboxes as explicit overrides and let site administrators remove an override.
* Add per-review setup-only ignore controls and an explicitly confirmed failed-check setup override; detailed reports remain private and runtime safety checks are unchanged.
* Show a read-only global inbox summary in Event Configuration, place both status catalogs immediately below Email Setup, and move administrator/scorer assignments below Event Details.
* Add an explicit event inbox override toggle, Event Status Customization and Registration Status Customization headings, initially minimized setup diagnostics, and audited readiness override requests.
* Require acknowledgment of unread messages before changing a global inbox assignment; connection verification alone completes the default email setup section.
* Synchronize polling, connection tests and inbox configuration changes with source locks to protect unread messages and verification state during concurrent operations.
* Keep global credentials in the existing encrypted mailbox table so backups/export and data deletion retain their established lifecycle; clear shared setup state on Delete All Data and uninstall.
* Upgrade plugin version to 0.4.0 without a database-schema migration.

= 0.3.0 =
* Add opt-in public accepted-bonus details linked from participant Bibs, with submitted proof photos alongside bonus descriptions and awarded points.
* Link administrative Results and Reviewed Bibs to the participant's accepted-bonus section while retaining full submission history.
* Keep public bonus details and photos disabled by default; validate event publication and enrollment before displaying accepted submissions.
* Restrict inbox configuration, connection testing and manual polling to WordPress site administrators. Changed IMAP server/account settings require a newly entered password.
* Upgrade to schema 16 without changing scores or submission decisions. Back up the database and gallery before updating.
* Run public-repository CodeQL analysis in addition to the existing dependency, static security and secret-scanning release gates.

= 0.2.3 =
* Set Event Status defaults to Registered, DNS, Active, Preliminary Finisher, Preliminary Bronze Finisher, Preliminary Silver Finisher, Preliminary Gold Finisher, Finisher, Bronze Finisher, Silver Finisher, Gold Finisher, DNF. One-time schema 15 migration preserves custom labels, disabled flags and participant results.
* All four manually finalized finisher statuses always qualify for individual/all certificates.
* Choose Vintage Ornamental or Classic Formal in Event Details. Print/PDF/email uses the saved design; medal color follows actual status and design changes require re-review.
* Added an original vintage-style certificate design with local Event Image, Event Description, Overall Position and Bronze/Silver/Gold medal icons.
* Print, PDF downloads and email review share the design. Image content, description, ranking and template revision are included in approval fingerprints.
* Preserve long descriptions on continuation pages and provide four fictional sample certificates for each predefined design in the repository documentation.

= 0.2.2 =
* Added Move Up / Move Down ordering for independent Registration and Event Status catalogs.
* Preliminary Minimum/Bronze/Silver/Gold finisher thresholds now qualify at equality or above; manual finalization remains authoritative.
* Aligned collapse markers beside status titles. Renamed the embed section to Embed this Event on a Post or Page and made it minimized by default.
* Added separate event locking/archiving controls, mutation guards, and remembered archived-event chooser visibility.
* Added printable and local Unicode PDF finisher certificates with event/year, participant name/Bib, score, bonus counts and final status.
* Added explicit administrator review and approval for individual/all certificate PDF emails, bounded batches, duplicate protection, stale-data checks, delivery outcomes and private cleanup.
* Added schema 14 migration, certificate lifecycle/privacy handling and bundled renderer dependencies. Back up before upgrading; equality cases now receive the higher preliminary tier.

= 0.2.1 =
* Added optional Bronze, Silver and Gold Finisher Points after Minimum Finisher Points. Blank means Not Used; configured tiers must strictly increase, and intermediate tiers may be skipped.
* Automatic Event Status uses the highest threshold strictly surpassed: Preliminary Finisher, Preliminary Bronze Finisher, Preliminary Silver Finisher or Preliminary Gold Finisher. Manual statuses, including cancellation DNS, remain authoritative.
* Expanded Event Status labels to 64 bytes and upgraded schema to 13 with NULL thresholds for existing events and seeded tier status choices.
* Defaulted Registration Status, Event Status, Diagnostics Export Data and Database Backup sections to minimized.
* Fixed remembered-event errors following legacy migration by invalidating user metadata caches and checking unchanged saves.
* Deprecated releases older than v0.2.0 while preserving historical artifacts.

= 0.2.0 =
* Renamed the plugin to Rally Quest Engine with RQE tables, options, hooks and gallery folder, plus a one-time transactional import of Motorcycle Rally Scoring Application data, gallery files and settings.
* Added a Welcome section with usage guidance, help links and a saved primary scavenger hunt style (Motorcycle, Bicycle, Horseback, GeoCache) that turns green and minimizes once set.
* Added style-aware conveyances: Bicycle hides the NHTSA import, renames Manual Bicycle Entry and greys out fuel capacity; Horseback replaces Bikes with a Horses tab (Name, Breed, Sex, Age, Color).
* Renamed Rider terminology to Participant throughout the interface; roster imports accept Participant ID or the former Rider ID header.
* Added a required Event Description and required event details; complete Event Details, Email Bonus Submissions and role-assignment sections turn green and minimize.
* Moved the Event Status catalog to the bottom of the right column and introduced style-coded Event IDs such as MC-7; existing events presume Motorcycle.
* Split the header chooser into scavenger hunt style and event menus.
* Public results embeds show the event description and image and accept [rqe_results id="MC-7"]; legacy [mrsa_results] embeds remain supported.
* Expanded inline documentation and updated the About tab and README.

= 0.1.20 =
* Assign manual DNS when Registration Status changes to Cancelled, including imports and enrollments, while allowing later administrator overrides.
* Protect DNS from deletion without requiring it to remain enabled for manual selection.
* Add an off-by-default Public Results Embed section with RQE Event ID, explicit publication control and copyable [rqe_results id="7"] shortcode for WordPress Shortcode blocks.
* Publish only rider names, bibs, scores, accepted/rejected counts, Event Status and placing with escaped, responsive read-only output.
* Upgrade database schema to 11, backfilling existing Cancelled riders to DNS once, preserving scores and placing, and defaulting public results off.
* Document publication privacy boundaries and WordPress/CDN cache-purge requirements.

= 0.1.19 =
* Added persistent per-user/site Choose Event header context shared by Rosters, Scoring and Results.
* Aligned Riders Search all records to the right of Add Rider with responsive layout.
* Added independent event-specific Registration Status and Event Status catalogs, with add, disable/re-enable and unused-value deletion controls.
* Added authorized administrator status editing on Rosters and Results. Automatic remains Registered at/below Minimum Finisher Points and Preliminary Finisher strictly above; administrators manually finalize Finisher, DNF or DNS.
* Added accepted/rejected submission counts to Results without changing point totals or competition rankings.
* Linked Reviewed bibs to read-only rider score sheets, including assigned Event Scorer access and return navigation.
* Arranged event configuration in two columns, with a collapsible configured-email section and role assignments on the right.
* Upgraded database schema to 10, retaining historical labels, assignments and scores and including status catalogs in backups and diagnostics.
* Kept Action Scheduler required and the Contested tab/placeholder unchanged.

= 0.1.18 =
* Added Gmail API setup guidance in a provider-specific Help? hover/focus panel on the event inbox configuration page.
* Added event inbox connection testing, verified-connection highlighting, and mailbox/provider status on Event Configuration.
* Improved inbox configuration validation errors to identify the exact missing or oversized provider credential.
* Show only the settings required by the selected IMAP, Gmail API, or Microsoft 365 inbox provider.
* Address Gmail API requests through the authenticated account to avoid mailbox user-resource 404 responses.
* Display provider-specific IMAP, Gmail OAuth, and Microsoft 365 setup instructions beside the inbox fields.

= 0.1.17 =
* Removed the plugin-defined site-wide administrator assignment and legacy storage. WordPress administrators now manage shared application data, while plugin administrator assignments exist only at the event level.
* Added separate Event Scorer assignments with Scoring-only access.
* Added encrypted per-event IMAP, Gmail API, and Microsoft 365 inbox configuration with scheduled and manual polling.
* Added strict one-code/one-image rider email ingestion, Pending Review image comparison, required rejection reasons, retained Reviewed/rider history, and transactional exactly-once score awards.

= 0.1.16 =
* List all locally uploaded scoring images that pass scanning and authorize their import with one bulk action instead of approving each image separately.

= 0.1.15 =
* Replaced the image-import URL field and Load via URL button with a single Load by URL button that prompts for the URL.
* Fixed rejection of JPEG, PNG, GIF and WebP images with appended gain-map, multi-picture, editor/vendor trailer, motion-photo or padding data; only the validated primary image is scanned and stored.

= 0.1.14 =
* Fixed false executable-content warnings caused by compressed JPEG, PNG, GIF and WebP image bytes.
* Added format-aware container checks while retaining metadata inspection, bounded PNG metadata decompression, full-file ClamAV scanning and appended-content rejection.
* Added real compressed-image fixtures and regressions for metadata scripts, truncated containers, animation frames and event-gallery imports.
* Required Dependabot release checks and CodeQL analysis for public repositories; private repositories explicitly disclose skipped CodeQL while retaining the other release gates.

= 0.1.13 =
* Rebuilt this release to fix sequential image uploads targeting an invalid URL when the hidden action field shadows the form destination; added a real-browser regression fixture.
* Separated administrative request inputs from bonus import, review, override and edit operations.
* Extracted shared hardened spreadsheet reading, audit persistence, roster import and audited Diagnostics deletion services.
* Isolated scanning state per import and separated local ClamAV transport from executable-content policy.
* Added injectable pending-review, cleanup scheduling, gallery filesystem and database adapters while preserving coordinated recovery.
* Moved administrative screen queries into read models and centralized competition ranking and result status rules.
* Composed focused Diagnostics check groups with validated shared report-state precedence.
* Added public service-contract regressions to the release gates and documented the development architecture.

= 0.1.12 =
* Rebuilt this release with matching green/orange/red background tints for minimized configuration panels; expanded panels remain neutral.
* Added a downloadable sample bonus-listing XLSX in Scoring Step 1, with the five required headings and a validated example row.
* Changed unsuccessful ClamAV diagnostic checks to Manual review when the current administrator has an audited scoring-page override; retained Passed for working scanners and unchanged import safeguards.
* Preserved the last diagnostic outcome's green/orange/red border after report expiration, with explicit Last check / Results expired labeling.
* Retained minimized defaults for passed/manual-review outcomes and expanded defaults for failed outcomes without retaining expired report details.
* Added regression coverage for the bundled XLSX template, override revocation/account isolation and expired-result presentation.

= 0.1.11 =
* Added Import Riders beside the renamed Add Rider roster button, opening a dedicated event-specific CSV/XLSX import screen with sample download and return navigation.
* Moved the persistent ClamAV bypass warning inside minimized Step 0 and consolidated Step 2 image-import guidance into heading help, matching the Bikes page.
* Added specific remediation instructions for every diagnostic requirement and verification guidance for manual-review results.
* Made every Diagnostics section collapsible and minimized Destructive operations by default.
* Added result-based configuration outlines and defaults: green/minimized for all passed, orange/minimized for manual review without failures, expanded with red failed rows for failures.
* Retained minimized neutral presentation after an all-passed report expires, with an explicit expired label and site/user-scoped last outcome.
* Expanded regression coverage for import navigation, collapsed warning/help placement and diagnostic status, expiration and persistence behavior.

= 0.1.10 =
* Rebuilt this release with one-at-a-time local scoring image uploads, individual scan review/approval, progress, stop/skip controls and saved-review recovery links.
* Retained earlier approved imports if a later file fails; preserved audited unavailable-scanner overrides and mandatory available-scanner review.
* Reduced diagnostic post-size requirements to 11 MB and one uploaded file per request while retaining the 10 MB per-file and 100 MB / 200-image queue safety limits.
* Added an on-demand Required Plugins and Configuration check above Diagnostics export and backup controls.
* Declared Action Scheduler as a required plugin and added permission-checked native WordPress installation and activation links; Gallery for SpotWalla remains optional.
* Checked WordPress/PHP requirements, import functions, database version, InnoDB tables/schema, upload limits, gallery storage, private staging and a live ClamAV scan.
* Displayed existing RQE import safety limits and marked unverifiable hosting resources, cron delivery and antivirus policies/signatures for manual review.
* Added private expiring configuration reports and regression coverage for permissions, nonces, dependency controls and exact upload thresholds.

= 0.1.9 =
* Added selected-event CSV/XLSX roster imports for existing riders, automatic available-bib assignment, batch validation and a downloadable sample CSV.
* Added Step 0 controls to enable or remove the audited unavailable-ClamAV override without bypassing working scanners.
* Added a Load via URL image-import button and compact source help, with the partial-import checkbox below both source controls.
* Grouped Diagnostics destructive actions in three responsive columns and moved randomized verification to accessible confirmation dialogs.
* Recorded Diagnostics deletions in the Administrative Audit, collapsed its history by default, and added explicit audit deletion that resets all overrides while retaining a new deletion record.
* Streamlined bike import information into contextual help and updated About headings with a green plugin recommendation section.
* Added roster and Diagnostics regression tests to the blocking release workflow.

= 0.1.8 =
* Arranged bonus import steps 0, 1, and 2 in a responsive three-column header.
* Collapsed working ClamAV setup to a green ClamAV Enabled label, remembered bypass to an orange ClamAV Manually Disabled label, and highlighted unconfigured setup in red.
* Consolidated step notices, errors, scan-review controls, and green successful import notifications below the import header.
* Returned import failures to the selected event's feedback area and added rendering regression coverage.

= 0.1.7 =
* Renamed and reordered tabs to Riders, Events, Rosters, Scoring, Results, Bikes, Diagnostics, and About; aligned motorcycle entry and import forms.
* Separated bonus listing and image imports into two steps, added partial-image imports with detailed errors, and added larger gallery image previews.
* Added local ClamAV scans, executable-content checks, private staging, expiring user/event-bound review and approval, and staged-file fingerprint verification.
* Added optional Step 0 server setup instructions and a ClamAV connection check.
* Added an explicitly confirmed per-administrator bypass only for unavailable ClamAV, persistent username/UTC import audits, unscanned-import warnings, and bypass revocation.
* Added audit history to Diagnostics and database exports/backups; retained audits during Diagnostics deletions.
* Added tested WordPress SQL injection, XSS and SSRF release rules, Trivy secret scanning, and dedicated full-history/working-tree Gitleaks release gates.
* Removed former-name wording from the Gallery for SpotWalla recommendation.

= 0.1.6 =
* Removed the former Administrators tab and renamed Rider Registration to Rider Setup.
* Moved event scoring workspaces below event selection and made Overall Results status read-only.
* Simplified Event Rosters to seven read-only columns with rider and bib links that return to the roster.
* Added event-specific bonus listings, CSV/XLSX imports, independent image galleries, and manual bonus editing.
* Added verified event-specific deletion of bonus listings and gallery images in Diagnostics.
* Added an optional Gallery for SpotWalla recommendation to About.

= 0.1.5 =
* Added rider-filtered roster/results navigation, computed overall results, automatic and manual result statuses, and an authorized rider score-sheet placeholder.
* Removed the former site-wide assignment controls and uninstall-retention controls; renamed Rider Registration to Rider Setup.
* Added automatic event enrollment after creating a rider from a full enrollment workflow.
* Added editable registration lifecycle statuses and renamed Event Placing to Overall Placing on Event Rosters.
* Added JSON export, SQL database backup, and randomized-string-verified destructive Diagnostics operations.

= 0.1.4 =
* Limit motorcycle catalog imports and manual catalog entries to make and model.
* Add transactional bulk selection and deletion for unused motorcycle catalog records.
* Filter rider motorcycles by make/model and store an optional rider-specific manual year.
* Return successful form submissions to their originating form or tab.
* Offer Add Rider when an event has no remaining unenrolled riders.
* Add a main Scoring tab with event selection and the existing scoring workspaces.

= 0.1.3 =
* Import all NHTSA models for a make only after motorcycle classification is confirmed.
* Support motorcycle catalog records with an unspecified year and optional four-digit manual years.
* Move event administrator assignments to each event's Configure page.
* Add Event Rosters with complete rider details and a dedicated Add Rider to Event workflow.
* Renamed the original setup tab to Administrators.

= 0.1.2 =
* Restrict external import to one exact NHTSA motorcycle make/model/year record.
* Move event administrator assignments into Event Setup behind Add Administrator.
* Separate event configuration, roster, and scoring pages and links.
* Add a branded application header throughout plugin administration.

= 0.1.1 =
* Switched the optional catalog import to arthurkao/vehicle-make-model-data.
* Moved rider and event creation into dedicated one-field-per-row edit pages.
* Expanded the project README and in-plugin About guidance.

= 0.1.0 =
* Initial administration, motorcycle catalog, rider, and event registration release.
* Reserved scoring tabs for future specifications.
