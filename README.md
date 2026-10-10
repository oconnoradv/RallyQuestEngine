# Motorcycle Rally Scoring Application

A WordPress plugin for administering motorcycle rallies, including administrator assignments, a motorcycle catalog, master rider records, event configuration, and event enrollment. Requires WordPress 6.2+, PHP 7.4+, MySQL 8.0+ or MariaDB 10.6+ with InnoDB, and the installed/active [Action Scheduler plugin](https://wordpress.org/plugins/action-scheduler/). Licensed under the GNU General Public License v3 or later (see [LICENSE](LICENSE)).

> **Version status:** The current development version provides rally setup, event-scoped bonus listings and image galleries, registration lifecycle tracking, computed overall results, exports, and backups. Bonus scoring calculations and submission review remain future work.

## Installation

1. Download `motorcycle-rally-scoring-application-vX.Y.Z.zip` from the release marked **Latest** on [GitHub Releases](https://github.com/oconnoradv/MotorcycleRallyScoringApp/releases).
2. In WordPress, open **Plugins > Add New > Upload Plugin**, select the ZIP, and activate **Motorcycle Rally Scoring Application**.
3. Open **Rally Scoring** in the WordPress administrator menu.
4. Configure administrators, populate the motorcycle catalog, add riders, create events, and enroll riders.

The plugin supports individual WordPress sites. On multisite, activate and manage it separately on each site; network activation is not supported.

## Current administration design

The plugin is an administrator-operated application. It does not expose public registration forms or change WordPress roles. The **Rally Scoring** screen provides the following navigation:

| Tab | Current function | Access |
| --- | --- | --- |
| **Riders** | List riders; open separate **Edit**, rider-filtered **Events**, and **Results** destinations; and manage contact, emergency-contact, insurance, notes, motorcycle, year, and fuel-capacity attributes. | WordPress administrators can edit shared rider records; Event Administrators have read-only access to riders enrolled in assigned events |
| **Events** | Add events and open separate **Configure**, **Roster**, and **Scoring** destinations. The Configure page also manages that event's administrator assignments. | WordPress administrators manage all events; Event Administrators manage assigned events |
| **Rosters** | Select an event and view Rider ID, Bib ID, read-only registration status, name, email, score, and overall placing. Rider IDs open rider details/editing; bibs open the event scoring workspace. | WordPress administrators and assigned Event Administrators; only WordPress administrators can enroll riders |
| **Scoring** | Select an event, import its bonus listing from CSV/XLSX, manage its bonus image gallery, and view the roster in the remaining scoring workspace tabs. | Global and assigned event administrators |
| **Results** | Rank event riders by total score, display result status read-only, and open an authorized rider event score-sheet placeholder. | Global and assigned event administrators |
| **Bikes** | Add or edit make/model catalog records, import motorcycle models by make from NHTSA, and bulk-delete selected unassigned records. Manual entry and import appear side by side on wide screens and stack on narrow screens. | WordPress administrators |
| **Diagnostics** | Export JSON, download a restorable SQL backup, and perform randomized-string-verified scoped data deletion. | WordPress administrators |
| **About** | Show version/environment details, repository and license links, an optional Gallery for SpotWalla recommendation, source attribution, privacy guidance, and reporting instructions. | Global and assigned event administrators |

The plugin has no site-wide administrator-assignment feature. WordPress users with the core `manage_options` capability manage shared riders, motorcycles, event creation, diagnostics, event enrollment, and Event Administrator assignments. Event Administrators are assigned only on an event's **Configure** page and can access only assigned events and enrolled rider details.

### Primary workflows

1. **Build the motorcycle catalog:** add make/model records manually or explicitly import a make from NHTSA. Select individual records—or all records loaded on the current server page—to delete them together. Bulk deletion is all-or-nothing and stops if any selected record is assigned to a rider.
2. **Create riders:** open **Riders > Add Rider**, enter one attribute per row, and attach up to 20 catalog motorcycles. Motorcycle filtering uses make and model only. The optional four-digit year belongs to the rider's motorcycle assignment and is not checked against the catalog.
3. **Create and configure events:** open **Events > Add Event**, then use **Configure** for event attributes and event-administrator assignments.
4. **Build event rosters:** choose an event under **Rosters**, then use **Add Rider to Event** to select a rider who is not already enrolled and assign an available bib. If no unenrolled riders remain, **Add New Rider** creates the master rider, automatically assigns the event's lowest available bib, and returns to that roster.
5. **Track registration:** review each roster entry's **Initial Registration**, **Paid**, **Fully Registered**, or **Cancelled** status independently of scoring results. Status is read-only on Rosters.
6. **Review results:** select an event under **Results**. Scores are ordered highest to lowest using competition ranking; equal scores share a place and the next place is skipped. Result status is read-only on this page.
7. **Export or maintain data:** use **Diagnostics** to download JSON or SQL before any destructive operation. Destructive actions are arranged in three columns (two or one on smaller screens). Select an action to open its verification pop-up, review the selected event or rider, and type the randomized verification string before confirming. Without dialog support or JavaScript, verification remains inline. Normal authorization and nonce checks still apply. Successful database deletions and their scope are recorded with the administrator's username and UTC timestamp; audit-storage failure rolls back the deletion.

Successful create, edit, assignment, enrollment, import, and deletion forms return to the form or tab from which they were submitted.

Under **Rosters**, WordPress administrators can choose **Import Riders** beside **Add Rider** to open the selected event's dedicated import screen and download a sample CSV. **Back to Rosters** returns to that event; successful imports also return to its roster. Columns are **Rider ID**, **Email**, **Bib ID**, **Status**, and **Pillion**. Each row must identify an existing rider by ID or email; when both are supplied they must match. No master rider records are created or changed. Blank bibs receive the lowest available bib after all supplied bibs are reserved. Blank status defaults to Initial Registration; the other accepted registration statuses are Paid, Fully Registered, and Cancelled. Score and overall placing are not imported. Files are limited to 10 MB and 5,000 rows, and XLSX uses its first worksheet. ClamAV must be available; the bonus-import bypass does not apply to roster imports. Formulas, executable content, unknown/duplicate headers, erased/unknown riders, existing enrollments, duplicate riders/bibs, and capacity violations reject the entire batch. Database errors roll back the import.

### Configuration readiness

Above Diagnostics exports/backups, **Required Plugins and Configuration** provides an on-demand, nonce-protected check for WordPress administrators. It reports Action Scheduler installation/activation and API availability, WordPress/PHP versions and import functions, database version, all eight InnoDB tables and schema version, effective upload/PHP limits, writable gallery storage, private staging, and a live harmless-file ClamAV scan. Reports are private to the administrator and expire after 15 minutes. Missing Action Scheduler offers **Install Action Scheduler**; an installed inactive copy offers **Activate Action Scheduler**, only with the appropriate WordPress capabilities. These use WordPress's standard installer/activation flow, including compatibility and filesystem checks. Rerun diagnostics afterwards. The plugin dependency header is enforced by WordPress 6.5+; on older supported WordPress versions, install and activate Action Scheduler manually. Gallery for SpotWalla remains optional. The checker does not install PHP extensions or ClamAV or edit server settings.

Each Diagnostics section can be minimized using its heading's expand/collapse control, including configuration checks, exports, backups, the destructive-operations group, and each deletion action. Destructive operations and Administrative Audit default to collapsed. Configuration results with failures use a red outline, stay expanded and highlight failed rows red; without failures, manual-review results use an orange outline and all-passed results a green outline, both collapsed initially. The summary names the result even when collapsed. After results expire, the last outcome's border color and expanded/minimized default remain, with **Last check** and **Results expired** labels. Only the last outcome is retained per administrator/site; expired report details are not retained or presented as current. Before any check, configuration stays neutral and expanded. Other sections initially remain expanded. Collapsing a section does not change its form values or verification requirements.

Failed requirements include expanded **How to fix this requirement** instructions with specific WordPress menus, PHP settings, database repair precautions, private storage configuration, and ClamAV troubleshooting. **Manual review** rows include expandable verification guidance for scanner policies, hosting request limits, resources, and scheduled cleanup. A failed ClamAV live check becomes **Manual review** when the current administrator has an audited scoring-page override; a working scanner remains **Passed**. The override does not certify scanning or relax import enforcement, and roster imports still require ClamAV. Rerun Diagnostics after changing an override to refresh its snapshot. Server changes must be performed by the hosting administrator; back up and test changes on staging, then rerun the check.

The check retains MRSA's safety limits: 10 MB per spreadsheet/image, 50 MB remote download/ZIP, 100 MB accepted images and 200 images per queue, and 5,000 spreadsheet rows. `upload_max_filesize` and the WordPress upload cap must support at least 10 MB; `post_max_size` should support at least 11 MB (one 10 MB file plus multipart overhead), or `0` for unlimited. Sequential image uploads require only one file per request. No 256 MB import allowance is introduced. Web-server/proxy request limits, memory/time/disk resources, cron delivery, signature freshness, antivirus policies and scanner size limits remain explicitly **Manual review** rather than falsely passing. Verify ClamAV `StreamMaxLength`/`MaxFileSize` of at least 60 MB, `MaxScanSize` of at least 200 MB, freshclam updates and `AlertExceedsMax yes`. A clean small scan is not certification that a maximum-sized batch will succeed.

### Rider and event registration

Rider email addresses are unique. A rider can have up to 20 catalog make/model motorcycles with optional rider-specific four-digit years and fuel-capacity notes. Event enrollment assigns a bib that must be unique within that event and may record optional pillion details.

Event registration open, close, start, and end values use the WordPress site timezone and must be chronological. The event date is derived from the start time. Rider and pillion prices are configuration values only; the plugin does not process payments.

### Scoring status

The **Bonus Point Library** stores listings separately per event. **Step 1:** validate a CSV or XLSX workbook with Bonus ID, Bonus Description, Bonus Category, Bonus Points, and Bonus Locations (GPS coordinates); normally review the security scan report and approve the listing import. Locations use one `latitude,longitude` pair. Matching IDs are updated on re-import, while other IDs remain. **Step 2:** local images upload and scan sequentially, then every successful image is listed in one combined review for a single bulk authorization; public HTTPS image/ZIP/directory sources retain their combined scan review. Each image filename must be its Bonus ID (for example, `B12.jpg`). For URL imports, an image error blocks the batch by default. For local queues, an error blocks the staged set by default. Select **Import all successful images and skip failed images** to exclude invalid files and bulk import the successful set; scanner errors stop either workflow. An unavailable daemon can be explicitly bypassed as described below. Reports are private to the importing administrator and available for 15 minutes. Errors in database rollback or image recovery stop the import rather than risking additional changes.

Step 1 includes **Download sample bonus listing XLSX** with the five required column headings and one example row. Replace or remove that row before importing your event bonuses; keep the headings on the first worksheet and store GPS coordinates as `latitude,longitude`.

### Import antivirus setup

Steps 0, 1, and 2 appear in a three-column import header, stacking on narrow screens. A successful live ClamAV connection check collapses Step 0 to a green **ClamAV Enabled** label; expand it to access setup guidance and connection controls. Import notices, errors, scan-review controls, and operation-specific warnings share one section immediately below the header, grouped by originating step; the persistent ClamAV bypass warning lives inside Step 0. Successful notifications are highlighted in green; unscanned-import warnings remain warnings. Import actions return to this feedback area in the selected event. Feedback reports are private to the administrator and expire after 15 minutes.

A remembered bypass instead collapses Step 0 to an orange **ClamAV Manually Disabled** label for that administrator. Without bypass or a successful connection check, Step 0 stays expanded and highlighted red. These labels do not change scan enforcement: an available daemon is still used even with remembered bypass consent.

Step 0 includes **Disable ClamAV Scanning**, requiring explicit risk consent to enable the existing per-account unavailable-scanner override and audit the choice. With the override enabled, expand Step 0 to see the ClamAV bypass warning and use **Remove ClamAV Scanning Override**. These controls do not stop or uninstall the server daemon.

Step 2 offers a single **Load by URL** button beside the local file chooser. Like **Choose Files**, it prompts for the source: a dialog asks for the public HTTPS URL, validates it, and imports only that source using the same scanning and approval safeguards (without JavaScript, the URL field and button appear inline). All image-import guidance (filenames, local uploads, HTTPS/source requirements, limits, and skip-failed behavior) is in **Help?** beside the Step 2 heading, using the Bikes page's hover, keyboard-focus, or tap/click pattern. The partial-image checkbox sits below both the local file chooser and URL load controls and applies to either source.

Local scoring image uploads use a sequential scan queue with JavaScript: choose a folder, clear the URL, then **Validate and Import Bonus Images**. Each request uploads and scans exactly one image, but no passing staged image is imported until all selected files have been processed. The page lists every successful image and its scan result, then one **Approve and Bulk Import Images** authorization imports the passing set. The existing audited remembered unavailable-scanner override still avoids repeated prompts; working scanners always require the combined review. A 200-image/100 MB queue is not sent in one HTTP request. Individual images still must fit PHP/host per-file limits and MRSA's 10 MB limit; this is not chunked uploading of oversized individual files. Without JavaScript, import one image at a time using the same server-side review.

Before bulk authorization, **Import all successful images and skip failed images** allows invalid images to be excluded from the combined review; without it, a failed image cancels the staged passing set. Scanner errors and uncertain network/server outcomes stop the queue without automatic retries. **Stop after current file** finishes an in-flight request, then cancels the staged set. During the authorized bulk update, each fingerprint-verified staged image is still committed through the guarded server endpoint; if a later request fails, earlier confirmed imports remain and the page reports the interruption. Refresh the gallery to inspect saved results; use the saved-review link if an outcome is uncertain. Navigating away loses the browser queue, while abandoned private staging expires through the existing cleanup. URL imports keep the existing batch review and approval behavior.

The import screen includes **Step 0: Set up or verify ClamAV**, with expandable Ubuntu/Debian installation commands, configuration guidance, and links for other platforms. Run the commands on the server with server-administrator permissions, or have your hosting provider complete them; WordPress does not execute installation commands or elevate privileges. **Check ClamAV Connection** performs a small local file-stream scan and displays the result without importing any event data. It does not certify signature freshness or scan-limit configuration. Existing README-based pre-installation remains supported: skip installation and use the connection check.

Bonus imports normally require a **local ClamAV daemon (`clamd`)** with current signatures maintained by `freshclam`. The default endpoint is `unix:///var/run/clamav/clamd.ctl`. If needed, set `MRSA_CLAMAV_SOCKET` in `wp-config.php` to your local Unix socket or `tcp://127.0.0.1:3310` (loopback only). Give the WordPress PHP process permission to access the socket. Never expose clamd's unauthenticated TCP listener to the network.

Enable archive, PE/ELF, and Office scanning in clamd, and set `AlertExceedsMax yes` so scan-limit violations are blocked rather than reported clean. Configure `StreamMaxLength` and `MaxFileSize` to at least 60 MB, and `MaxScanSize` to at least 200 MB for the supported archives. Host administrators must keep the engine and signatures updated; this plugin does not install or configure ClamAV. Timeouts, permission/configuration errors, and scanner errors block approval.

If the local socket is missing or the daemon refuses the connection, bulk imports show **Not scanned** results and require explicit **Confirm Bypass and Import** consent before saving. This cannot distinguish an uninstalled daemon from a stopped daemon. Consent is remembered only for that administrator on that site. Subsequent validated bulk listing/image imports while ClamAV is unavailable proceed without another approval prompt; each post-import notice warns that no antivirus security scan was performed. Available ClamAV is still used and its scan report still requires approval. Malware detections are excluded/blocked, scanner errors block the batch, and format, executable-content, size, and fingerprint checks remain mandatory. Manual editor replacement images do not use this bypass.

Consent, revocation, unscanned import start/completion/failure records, and Diagnostics deletions are stored in `{prefix}MRSA_import_audit` with user ID, username snapshot, UTC date/time, event ID (0 for site-wide actions), action, and details. Audit insertion must succeed before unscanned files can be imported. **Diagnostics > Administrative Audit** displays the latest 100 records; JSON exports and SQL backups include all records. Event deletion and Delete All Data preserve this history and remembered choices. **Delete Virus Scan Audit Data** explicitly clears the audit table and resets every administrator's remembered ClamAV override, then leaves a new audit record identifying who deleted the history and when. **Remove ClamAV Scanning Override** in Step 0 revokes your remembered consent and records the change. Opt-in destructive uninstall removes the audit table with the other plugin tables; otherwise it is retained.

Files are streamed to the local scanner, never to a third-party service. CSV formulas, XLSX formulas/macros/embedded files, XML entities, script markers, and appended image content are rejected in addition to format checks. Use values-only XLSX workbooks without embedded images. Local checks and antivirus reduce risk but do not guarantee the absence of malicious content.

JPEG, PNG, GIF and WebP content checks distinguish compressed pixels and binary color tables from metadata: image data is not searched as script text, avoiding false positives from short byte sequences such as `<%`. Metadata remains checked, including bounded decompression of PNG text/profile metadata. Malformed/truncated containers and trailing content are rejected, and ClamAV still scans the entire file. JPEG, PNG, GIF and WebP imports keep only the validated primary image: data appended after its end marker (JPEG EOI, PNG IEND, GIF trailer, WebP RIFF length)—gain maps, MPF images, editor/vendor trailers, motion-photo video or padding—is removed before scanning and never stored. This does not relax malware detection, scan review or unavailable-scanner consent requirements.

Pending files stay in a random, private temporary directory outside the web root and uploads. If the server's default temporary directory is web-accessible, define `MRSA_IMPORT_TEMP_DIR` as an existing writable directory outside the document root, with filesystem permissions restricted to the PHP service account. Approval is user/event-bound, expires after 15 minutes, is single-use, and verifies staged file SHA-256 fingerprints before importing. Cancel removes staged files; abandoned files are removed by WordPress cron after expiry (configure a reliable cron runner on low-traffic sites). Recovery backups are retained with an error log entry when automatic cleanup would risk losing data. Manual replacement images in the bonus editor are also scanned before saving.

Images are stored in the plugin's event-specific upload folder and are not added to the WordPress media library. Click a gallery thumbnail for a larger overlay preview; close it with the Close image button, Escape, or a click outside the preview to return to the listing. Without dialog support, the image link opens in a new tab. Bonus scoring calculations and the other submission-review tabs are not implemented.

New event registrations use registration status `Initial Registration`, result status `Registered`, score `0`, and no stored placing. A score strictly greater than the event's minimum finish score produces automatic result status `Preliminary Finisher`. Results displays this status read-only. Rider numbers link to an authorized score-sheet placeholder; submission records and the scoring workflow remain undefined.

## Motorcycle catalog source

The optional factual import uses the [NHTSA Product Information Catalog and Vehicle Listing (vPIC) API](https://vpic.nhtsa.dot.gov/api/). The administrator supplies only a make.

An administrator must explicitly start and confirm the import. It never runs during activation or page load. The importer:

- Calls **Get Models for Make**, then intersects those model IDs with a second NHTSA response restricted to the `motorcycle` vehicle type.
- Limits each response to 256 KB and follows no redirects.
- Imports only intersected records explicitly identified as `VehicleTypeName: Motorcycle`.
- Stores only the confirmed make and model. A year is entered separately and optionally when assigning a motorcycle to a rider.
- Rejects unavailable, malformed, unconfirmed, and non-motorcycle results.

The requests disclose the site server's IP address, the entered make, and ordinary HTTP metadata to NHTSA; rider, administrator, and event data are never sent. Manual motorcycle entry remains available.

## Reporting issues

1. Search [existing issues](https://github.com/oconnoradv/MotorcycleRallyScoringApp/issues) to see whether the problem is already reported.
2. If not, [open a new issue](https://github.com/oconnoradv/MotorcycleRallyScoringApp/issues/new) with a short, descriptive title.
3. Include the expected and actual behavior, steps to reproduce the problem, the affected tab or workflow, and any error messages or screenshots.
4. Include the plugin, WordPress, PHP, and database versions. The plugin, WordPress, and PHP versions are shown on the **About** tab.
5. Do not include passwords, private rider information, emergency contacts, insurance details, or other sensitive data. Report security vulnerabilities privately to the repository owner rather than in a public issue.

## Storage, privacy, and data lifecycle

Activation creates plugin-owned tables using the current site's WordPress database prefix followed by `MRSA_`. WordPress users and media remain in core tables and are referenced rather than copied or owned by the plugin.

| Stored area | Contents |
| --- | --- |
| Event Administrators | References to WordPress users assigned to specific events |
| Motorcycle catalog | Motorcycle make/model labels and source attribution |
| Riders | Contact, emergency-contact, insurance, notes, and unique email data |
| Rider motorcycles | Catalog association, optional manually entered model year, and fuel-capacity text |
| Events | Schedule, registration window, prices, location, coordinates, logo reference, bib capacity, and minimum-finisher-points configuration |
| Registrations | Event/rider association, unique event bib, pillion text, registration lifecycle status, automatic/manual result status, score, and placing |
| Bonus listings | Event-specific bonus IDs, descriptions, categories, points, GPS coordinates, and image filenames |
| Import audit | Administrator ID and username snapshot, UTC date/time, event ID, bypass consent/revocation, unscanned import activity and Diagnostics deletions; retained except when explicitly deleting audit data |

Rider records can contain names, aliases, email addresses, phone numbers, motorcycle make/model assignments, manually entered model years, fuel capacity, emergency contacts, insurance details, and free-form notes. Registrations link riders to events and store bib, pillion, registration status, result status, score, and placing values. Diagnostics exports contain all plugin database data and SQL backups contain restorable schema and records, so both must be protected as sensitive data. Bonus gallery files are stored under a dedicated `mrsa-event-gallery` uploads directory, outside the WordPress media attachment library; gallery files are not included in database exports or backups and must be backed up separately. Site operators are responsible for participant notices, consent where required, access control, retention, backups, and secure handling of this data.

WordPress personal-data export and erasure tools include relevant plugin data. Erasure anonymizes a rider and removes motorcycle and pillion details while retaining anonymous registration identifiers and results fields for event integrity. Event Administrator assignments remain for access control and can be removed from the event's **Configure** page. WordPress administrator access is controlled only by WordPress roles and capabilities. Deactivation preserves data. Fresh installs retain data on uninstall by default; existing sites continue to honor any previously saved uninstall-retention preference.

The plugin does not include analytics, telemetry, payment processing, remotely hosted JavaScript, or automatic external requests. Use TLS, least-privilege accounts, restricted database access, secure backups, and supported versions of WordPress, PHP, and the database server.

## Development architecture

The plugin uses composition rather than an inheritance framework. WordPress authorization, nonces and redirects belong at the administrative request boundary; import operations receive explicit inputs rather than reading request globals. Database transactions and gallery rollback remain coordinated by the operation that owns the mutation.

`MRSA_Bonus_Application_Service` provides explicit-input import, review, override and editor operations. `MRSA_Roster_Import_Service` owns roster validation and atomic enrollment; `MRSA_Diagnostics_Deletion_Service` owns audited destructive operations and gallery recovery. Bonus and roster imports share `MRSA_Spreadsheet_Reader`, retaining the data-only XLSX protections and size/row limits. `MRSA_Import_Audit` owns audit persistence and per-administrator override lookup.

Each import creates its own `MRSA_Scan_Session`, separating report state and unavailable-scanner policy from `MRSA_ClamAV_Transport` and `MRSA_Executable_Content_Policy`. Pending review storage, cleanup scheduling and gallery filesystem operations use small injectable adapters; audit persistence also accepts a database adapter. Existing static bonus/scanner entry points remain for compatibility, while administrative requests use the explicit application service. Custom adapters must preserve failure reporting, single-use review expiry/locking, private staging and coordinated recovery semantics; they are not a way to bypass scanning or audit requirements.

Administrative screens use `MRSA_Queries` read models for event-scoped lists, searches, pagination, rider visibility and score summaries. Its database adapter is constructor-injected; query failures raise explicit errors rather than appearing as empty lists. Callers still enforce screen authorization, and assigned-administrator queries retain their user/event restrictions. `MRSA_Results` contains the shared pure competition-ranking and automatic/manual status rules, so roster, results and score-sheet screens use the same calculations.

`MRSA_Diagnostics` collects cohesive plugin, runtime, database, upload, storage, scanner and hosting check groups. An explicitly supplied callable list can compose a report without modifying the collector. `MRSA_Diagnostic_Report` validates result rows and applies the shared Failed > Manual review > Passed precedence used by report persistence and rendering. Authorization runs before any check, including supplied checks.

Public-contract regressions cover ranking edge cases, parameterized read models, database failures and diagnostic composition. Run standalone suites with `php tests/<suite>.php`; the release workflow runs these alongside the import, roster, queue and Diagnostics regressions. No additional production dependencies are required.

Open `tests/image-queue-browser.html` in a browser to exercise the single-image request with a mocked response and real form controls. This covers the hidden `action` field shadowing the form's JavaScript `action` property; requests must read the form's destination attribute instead.

## Validation

The **Security checks and ZIP release** GitHub Actions workflow runs on pull requests, branch pushes, version tags, and manual dispatch. It:

- Checks PHP and JavaScript syntax.
- For public repositories, runs CodeQL's extended JavaScript security queries on the exact checked-out commit, uploads code-scanning results, and rejects any SARIF findings or missing/failed analysis. For private repositories, skips CodeQL with an Actions notice, job summary and explicit release-note disclosure; publication continues when the other gates pass. Unknown visibility blocks the job. CodeQL does not support PHP; Semgrep remains the PHP static-analysis gate.
- Requires zero open repository Dependabot alerts of any severity on release tags, using paginated API reads, then rechecks immediately before publishing. Missing credentials, disabled alerts and API errors block publication.
- Scans PHP and JavaScript with Semgrep security rules covering common exploit patterns, including SQL injection, cross-site scripting, command injection, file inclusion, unsafe deserialization, and server-side request forgery. Supplemental WordPress rules check request data reaching `$wpdb` queries (including unsafe `prepare` templates), HTML output, and unsafe WordPress HTTP calls; vulnerable and safe fixtures verify those rules before each scan.
- Uses Trivy's vulnerability and secret scanners on the working tree.
- Runs a separate, version/checksum-pinned Gitleaks CLI check with redacted output against all available Git history and the working tree. Checkout fetches full history so deleted historical secrets are also checked.
- Blocks packaging when findings or scanner failures occur.

These checks support secure development but do not certify NIST compliance, guarantee the absence of CVEs, or replace penetration testing and manual review. The repository currently has no bundled third-party dependencies or dependency manifests, so dependency scanning cannot assess the deployed WordPress installation, PHP runtime, database server, or other plugins.

All required scanners are release gates: findings, invalid rules, failed fixture tests, download/checksum failures, and scanner errors stop publication. CodeQL is required only for public repositories; private repositories receive an explicit skip notice instead. Packaging may finish independently, but the release job cannot publish until every applicable gate succeeds. Do not bypass findings to publish; fix exploit findings and revoke/rotate any detected secret, then address exposed history as appropriate. Registry Semgrep rules are updated upstream; the supplemental rules are versioned in `.github/semgrep/wordpress.yml`. Static analysis is not exhaustive, especially across helper/function boundaries or for stored-data flows.

Before publishing, enable **Dependency graph** and **Dependabot alerts** under the repository's **Settings > Advanced Security** (called **Code security and analysis** in some GitHub interfaces). Add an Actions repository secret named `RELEASE_SECURITY_TOKEN`: use a fine-grained token restricted to this repository with **Dependabot alerts: Read-only** permission. The normal workflow token cannot read Dependabot alerts. Never put the token in source or release notes. Enable GitHub Code Security/code scanning where required by the repository's visibility/license, and use this workflow's advanced CodeQL setup rather than a conflicting default setup. Manual workflow dispatch also exercises the Dependabot gate; dispatch on a branch to verify access without publishing a release.

Dependabot version updates are configured weekly for GitHub Actions in `.github/dependabot.yml`. Dependabot alert checks reflect GitHub's repository dependency graph, not a new scan of the tagged package; a clear alert list is not proof of deployed dependencies being secure. The repository currently has no Composer/npm runtime manifests. Keep release dependencies reflected in the default branch's dependency graph and resolve open alerts before tagging. Run `node tests/release-security.cjs` to exercise the fail-closed alert and SARIF gate contracts.

`.github/gitleaks.toml` retains all default Gitleaks rules and allows only the exact non-secret transient-key prefix `mrsa_bonus_pending_1_` in `tests/bonus-import.php` for the generic API-key rule. It does not exclude test files or historical commits from secret scanning.

## Building and publishing releases

For a release, update the plugin `Version:` header, `MRSA_VERSION`, and the `Stable tag` in `motorcycle-rally-scoring-application/readme.txt` to the same semantic version. Merge the intended release commit, then push a matching tag such as `v0.2.0`.

Only a matching `vMAJOR.MINOR.PATCH` tag can publish. After all security checks pass, the workflow builds a fresh installable ZIP from `motorcycle-rally-scoring-application/`, verifies it, creates `SHA256SUMS`, and publishes both files to a GitHub release. Branch and pull-request runs validate only and never publish.

The release job alone receives repository write permission. Protect release tags and restrict who can create them in repository rulesets.
