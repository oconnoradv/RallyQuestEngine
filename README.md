# Motorcycle Rally Scoring Application

A WordPress plugin for administering motorcycle rallies, including administrator assignments, a motorcycle catalog, master rider records, event configuration, and event enrollment. Requires WordPress 6.2+, PHP 7.4+, and MySQL 8.0+ or MariaDB 10.6+ with InnoDB. Licensed under the GNU General Public License v3 or later (see [LICENSE](LICENSE)).

> **Version status:** The current development version provides rally setup, registration lifecycle tracking, computed overall results, exports, and backups. The Bonus Point Library and submission review tabs remain reserved for a future scoring workflow and do **not** create or calculate bonus records.

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
| **Administrators** | Assign global administrators from existing WordPress users and configure uninstall retention. Users with `manage_options` retain bootstrap access. | Global administrators |
| **Bike Setup** | Add or edit make/model catalog records, import motorcycle models by make from NHTSA, and bulk-delete selected unassigned records. | Global administrators |
| **Rider Registration** | List riders; open separate **Edit**, rider-filtered **Events**, and **Overall Results** destinations; and manage contact, emergency-contact, insurance, notes, motorcycle, year, and fuel-capacity attributes. | Global administrators can edit; event administrators have read-only access to riders in assigned events |
| **Event Setup** | Add events and open separate **Configure**, **Registrations**, and **Scoring** destinations. The Configure page also manages that event's administrator assignments. | Global administrators manage all events; event administrators manage assigned events |
| **Event Rosters** | Select an event, view every registered rider attribute, edit bib/pillion details and registration status, and open the dedicated enrollment screen. | Global and assigned event administrators; only global administrators can enroll riders |
| **Scoring** | Select an event and display its roster scoring summary and reserved scoring workspace tabs. | Global and assigned event administrators |
| **Overall Results** | Rank event riders by total score, derive or override result status, and open an authorized rider event score-sheet placeholder. | Global and assigned event administrators |
| **Diagnostics** | Export JSON, download a restorable SQL backup, and perform randomized-string-verified scoped data deletion. | Global administrators |
| **About** | Show version/environment details, repository and license links, source attribution, privacy guidance, and reporting instructions. | Global and assigned event administrators |

Global administrators can manage every plugin record. Event administrators can access only assigned events and enrolled rider details; they cannot change global assignments, the catalog, master rider records, or event enrollment.

### Primary workflows

1. **Build the motorcycle catalog:** add make/model records manually or explicitly import a make from NHTSA. Select individual records—or all records loaded on the current server page—to delete them together. Bulk deletion is all-or-nothing and stops if any selected record is assigned to a rider.
2. **Create riders:** open **Rider Registration > Add Rider**, enter one attribute per row, and attach up to 20 catalog motorcycles. Motorcycle filtering uses make and model only. The optional four-digit year belongs to the rider's motorcycle assignment and is not checked against the catalog.
3. **Create and configure events:** open **Event Setup > Add Event**, then use **Configure** for event attributes and event-administrator assignments.
4. **Build event rosters:** choose an event under **Event Rosters**, then use **Add Rider to Event** to select a rider who is not already enrolled and assign an available bib. If no unenrolled riders remain, **Add New Rider** creates the master rider, automatically assigns the event's lowest available bib, and returns to that roster.
5. **Track registration:** update each roster entry to **Initial Registration**, **Paid**, **Fully Registered**, or **Cancelled** independently of scoring results.
6. **Review results:** select an event under **Overall Results**. Scores are ordered highest to lowest using competition ranking; equal scores share a place and the next place is skipped. Result status can remain automatic or be overridden by an event administrator.
7. **Export or maintain data:** use **Diagnostics** to download JSON or SQL before any destructive operation. Deletions require the operation's displayed randomized verification string as well as normal authorization and nonce checks.

Successful create, edit, assignment, enrollment, import, and deletion forms return to the form or tab from which they were submitted.

### Rider and event registration

Rider email addresses are unique. A rider can have up to 20 catalog make/model motorcycles with optional rider-specific four-digit years and fuel-capacity notes. Event enrollment assigns a bib that must be unique within that event and may record optional pillion details.

Event registration open, close, start, and end values use the WordPress site timezone and must be chronological. The event date is derived from the start time. Rider and pillion prices are configuration values only; the plugin does not process payments.

### Scoring status

The **Scoring** tab contains **Bonus Point Library**, **Submissions - Pending Review**, **Submissions - Reviewed**, and **Submissions - Contested** tabs beneath the selected event. These are explicit placeholders until scoring rules and review transitions are specified.

New event registrations use registration status `Initial Registration`, result status `Registered`, score `0`, and no stored placing. On **Overall Results**, a score strictly greater than the event's minimum finish score produces automatic result status `Preliminary Finisher`; administrators can instead select `Registered`, `Preliminary Finisher`, `Finisher`, `DNF`, or `DNS`, or restore automatic behavior. Rider numbers link to an authorized score-sheet placeholder because the bonus-point data model and layout have not yet been defined.

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
| Administrators | References to WordPress users, global secondary contacts, and event assignments |
| Motorcycle catalog | Motorcycle make/model labels and source attribution |
| Riders | Contact, emergency-contact, insurance, notes, and unique email data |
| Rider motorcycles | Catalog association, optional manually entered model year, and fuel-capacity text |
| Events | Schedule, registration window, prices, location, coordinates, logo reference, bib capacity, and minimum-finisher-points configuration |
| Registrations | Event/rider association, unique event bib, pillion text, registration lifecycle status, automatic/manual result status, score, and placing |

Rider records can contain names, aliases, email addresses, phone numbers, motorcycle make/model assignments, manually entered model years, fuel capacity, emergency contacts, insurance details, and free-form notes. Registrations link riders to events and store bib, pillion, registration status, result status, score, and placing values. Diagnostics exports contain all plugin data and SQL backups contain restorable schema and records, so both must be protected as sensitive data. Site operators are responsible for participant notices, consent where required, access control, retention, backups, and secure handling of this data.

WordPress personal-data export and erasure tools include relevant plugin data. Erasure anonymizes a rider and removes motorcycle and pillion details while retaining anonymous registration identifiers and results fields for event integrity. Administrator assignments remain until removed in **Administrators** or the event's **Configure** page. Deactivation preserves data; destructive uninstall requires explicit opt-in in the plugin settings.

The plugin does not include analytics, telemetry, payment processing, remotely hosted JavaScript, or automatic external requests. Use TLS, least-privilege accounts, restricted database access, secure backups, and supported versions of WordPress, PHP, and the database server.

## Validation

The **Security checks and ZIP release** GitHub Actions workflow runs on pull requests, branch pushes, version tags, and manual dispatch. It:

- Checks PHP and JavaScript syntax.
- Scans PHP and JavaScript with Semgrep security rules.
- Uses Trivy to scan for known dependency vulnerabilities and secrets.
- Blocks packaging when findings or scanner failures occur.

These checks support secure development but do not certify NIST compliance, guarantee the absence of CVEs, or replace penetration testing and manual review. The repository currently has no bundled third-party dependencies or dependency manifests, so dependency scanning cannot assess the deployed WordPress installation, PHP runtime, database server, or other plugins.

## Building and publishing releases

For a release, update the plugin `Version:` header, `MRSA_VERSION`, and the `Stable tag` in `motorcycle-rally-scoring-application/readme.txt` to the same semantic version. Merge the intended release commit, then push a matching tag such as `v0.2.0`.

Only a matching `vMAJOR.MINOR.PATCH` tag can publish. After all security checks pass, the workflow builds a fresh installable ZIP from `motorcycle-rally-scoring-application/`, verifies it, creates `SHA256SUMS`, and publishes both files to a GitHub release. Branch and pull-request runs validate only and never publish.

The release job alone receives repository write permission. Protect release tags and restrict who can create them in repository rulesets.
