=== Motorcycle Rally Scoring Application ===
Contributors: oconnoradv
Tags: motorcycle, rally, registration, events
Requires at least: 6.2
Requires PHP: 7.4
Requires Plugins: action-scheduler
Stable tag: 0.1.15
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Manage motorcycle rally administrators, motorcycles, riders, and event registrations in WordPress.

== Description ==

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

Motorcycle Rally Scoring Application provides administrative tabs for:

* Riders: rider details, make/model-filtered motorcycles with optional manually entered four-digit years, emergency contacts, insurance information, and registered events.
* Events: event configuration, per-event administrator assignments, and Roster links.
* Rosters: event selection and a read-only roster table with rider ID, bib ID, registration status, name, email, score, and overall placing. Rider IDs open rider details/editing, bibs open event scoring, and enrollment remains a separate screen.
* Scoring: event-specific Bonus Point Library, CSV/XLSX listing import, local-folder and public-URL image import, and event-roster scoring summaries in the remaining workspace tabs.
* Results: score ordering, computed overall placing, read-only result status, and rider score-sheet placeholders.
* Bikes: side-by-side manual entry and motorcycle make/model import on wide screens, stacked on narrow screens, plus transactional bulk deletion of unassigned catalog rows.
* Rosters: Import Riders beside Add Rider opens a separate selected-event CSV/XLSX import screen for existing riders by Rider ID or Email, with a downloadable sample CSV and a Back to Rosters link. Optional Bib ID, Status and Pillion columns; blank bibs receive the next available number. Global administrators only, maximum 10 MB / 5,000 rows. ClamAV is required; invalid or conflicting rows reject the whole batch.
* Diagnostics: on-demand required-plugin/environment checks with native WordPress install/activate controls for required Action Scheduler; Gallery for SpotWalla remains optional. PHP, database/InnoDB/schema, ClamAV, private staging/storage and upload capacity are checked against current MRSA limits (10 MB spreadsheets/images, 50 MB ZIP/download, 100 MB / 200 images per batch). Unverifiable hosting and scanner policies are labeled Manual review. JSON export, restorable SQL backup, and verification-string-protected scoped deletion are also available. Gallery image files are stored separately and are not included in database exports or backups.
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
The default Unix socket can be overridden with MRSA_CLAMAV_SOCKET in
wp-config.php (local Unix sockets or loopback TCP only). Configure clamd to
scan archives and Office/executable formats, enable AlertExceedsMax, and allow
at least 60 MB streams/files and 200 MB expanded scans. See README for setup.
Pending files stay outside the web root in private temporary storage.
MRSA_IMPORT_TEMP_DIR can override the temporary directory. Reviews expire in
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
event folder and are not registered in the WordPress media library. Submissions
- Pending Review, Submissions - Reviewed, and Submissions - Contested remain
placeholders. Bib links provide event/rider context; this release does not
calculate bonus scores. Results orders existing total scores using competition
ranking and displays the automatically derived Preliminary Finisher status
when a score is greater than the event minimum. Status is read-only on that
page. New registrations have lifecycle status Initial Registration, result
status Registered, score 0, and unset stored placing. Gallery files live in
the dedicated uploads/mrsa-event-gallery directory and must be backed up
separately from the JSON and SQL database exports.
Diagnostics can delete all bonus listings and images for a selected event.

WordPress administrators can bootstrap access. Existing assigned global
administrators retain access to plugin data, though the application no longer
provides global-assignment or uninstall-retention controls. Assigned event
administrators are restricted to their assigned events and enrolled riders;
they cannot change global assignments, the motorcycle catalog, or master
rider records.

Tables use the current site's WordPress prefix followed by MRSA_, avoiding
collisions between sites. No WordPress core tables or users are replaced.

== Installation ==

1. Back up the database and use a supported, patched WordPress/PHP installation.
2. Upload motorcycle-rally-scoring-application-vX.Y.Z.zip through Plugins > Add New > Upload Plugin.
3. Activate Motorcycle Rally Scoring Application.
4. Open the rally administration menu as a WordPress administrator.
5. Assign administrators, populate Bikes, register riders, and configure events.
6. Enroll riders in an event using an unused bib number.

Event dates are derived from the configured start date/time in the WordPress
site timezone. Add Rider and Add Event open dedicated edit pages with one
attribute per row. Enrolled rider details remain read-only outside explicit
registration controls.

Rider ID and bib links from Rosters return to that selected roster through
the destination page's back link. Successful forms return to their originating
form or tab. If Rosters has
no unenrolled rider to select, Add Rider to Event can open the Add Rider form,
automatically enroll the new rider with the lowest available bib, and return
to the selected roster. Roster registration status is displayed read-only and
remains independent of scoring-result status.

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

No. Scoring workflows are reserved for later specifications.

= Can riders register themselves on the public website? =

No. The current version provides administrator-operated registration.

= Is this a payment processor? =

No. Rider and pillion prices are event configuration values only.

== Changelog ==

= Unreleased =
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
* Displayed existing MRSA import safety limits and marked unverifiable hosting resources, cron delivery and antivirus policies/signatures for manual review.
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
* Removed the Administrators tab and controls, preserving existing administrator access, and renamed Rider Registration to Rider Setup.
* Moved event scoring workspaces below event selection and made Overall Results status read-only.
* Simplified Event Rosters to seven read-only columns with rider and bib links that return to the roster.
* Added event-specific bonus listings, CSV/XLSX imports, independent image galleries, and manual bonus editing.
* Added verified event-specific deletion of bonus listings and gallery images in Diagnostics.
* Added an optional Gallery for SpotWalla recommendation to About.

= 0.1.5 =
* Added rider-filtered roster/results navigation, computed overall results, automatic and manual result statuses, and an authorized rider score-sheet placeholder.
* Removed the Administrators tab and its global-assignment and uninstall-retention controls; renamed Rider Registration to Rider Setup.
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
* Rename Global Setup to Administrators.

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
