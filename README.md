# Motorcycle Rally Scoring Application

A WordPress plugin for administering motorcycle rallies, including administrator assignments, a motorcycle catalog, master rider records, event configuration, and event enrollment. Requires WordPress 6.2+, PHP 7.4+, and MySQL 8.0+ or MariaDB 10.6+ with InnoDB. Licensed under the GNU General Public License v3 or later (see [LICENSE](LICENSE)).

> **Version status:** Version 0.1.0 provides rally setup and registration workflows. The Bonus Point Library and submission review tabs reserve the future scoring workflow, but this release does **not** calculate scores, determine finisher status, or assign placing.

## Installation

1. Download `motorcycle-rally-scoring-application-vX.Y.Z.zip` from the release marked **Latest** on [GitHub Releases](https://github.com/oconnoradv/MotorcycleRallyScoringApp/releases).
2. In WordPress, open **Plugins > Add New > Upload Plugin**, select the ZIP, and activate **Motorcycle Rally Scoring Application**.
3. Open **Rally Scoring** in the WordPress administrator menu.
4. Configure administrators, populate the motorcycle catalog, add riders, create events, and enroll riders.

The plugin supports individual WordPress sites. On multisite, activate and manage it separately on each site; network activation is not supported.

## Managing rallies

The **Rally Scoring** page provides these tabs:

- **Global Setup** assigns global and event administrators from existing WordPress users. Users with `manage_options` retain bootstrap access.
- **Bike Setup** manages motorcycle make, model, and year records through manual entry, CSV input, or an optional bounded external import.
- **Rider Registration** lists master rider records. **Add Rider** and edit links open a dedicated page with one attribute per row, including contact details, emergency contacts, insurance notes, and motorcycles.
- **Event Setup** lists accessible events. **Add Event** and configuration links open a dedicated page with one attribute per row, including dates, prices, location, coordinates, capacity, and a WordPress media-library logo.
- **About** shows the installed environment, repository and license links, version scope, privacy and security guidance, and issue-reporting instructions.

Global administrators can manage every plugin record. Event administrators can access only assigned events and the riders enrolled in those events; they cannot change global assignments, the catalog, or master rider records.

### Rider and event registration

Rider email addresses are unique. A rider can have up to 20 catalog motorcycles with optional fuel-capacity notes. Event enrollment assigns a bib that must be unique within that event and may record optional pillion details.

Event registration open, close, start, and end values use the WordPress site timezone and must be chronological. The event date is derived from the start time. Rider and pillion prices are configuration values only; the plugin does not process payments.

### Scoring status

The separate **Event Scoring** page contains **Bonus Point Library**, **Submissions - Pending Review**, **Submissions - Reviewed**, and **Submissions - Contested** tabs. These are explicit placeholders until scoring rules and review transitions are specified.

New event registrations use status `Registered`, score `0`, and no placing. Bib links open the reserved scoring context for the selected event and rider, but no scoring records are created.

## Motorcycle catalog source

The optional factual import uses [arthurkao/vehicle-make-model-data](https://github.com/arthurkao/vehicle-make-model-data), an MIT-licensed year/make/model dataset covering cars, motorcycles, trucks, and UTVs from 2001 through 2015.

An administrator must explicitly start and confirm the import. It never runs during activation or page load. The importer:

- Retrieves one pinned CSV revision from GitHub using a single HTTPS request.
- Limits the response to 1 MB and follows no redirects.
- Selects recognized motorcycle manufacturers and imports at most 500 rows, newest first.
- Rejects unavailable or unexpected source data instead of inventing records.

Manufacturers that also produce cars may contribute non-motorcycle models, so administrators must review imported labels. The request discloses the site server's IP address and ordinary HTTP metadata to GitHub; rider and administrator data are never sent. Manual catalog entry and a bounded `make,model,year` CSV fallback remain available.

## Reporting issues

1. Search [existing issues](https://github.com/oconnoradv/MotorcycleRallyScoringApp/issues) to see whether the problem is already reported.
2. If not, [open a new issue](https://github.com/oconnoradv/MotorcycleRallyScoringApp/issues/new) with a short, descriptive title.
3. Include the expected and actual behavior, steps to reproduce the problem, the affected tab or workflow, and any error messages or screenshots.
4. Include the plugin, WordPress, PHP, and database versions. The plugin, WordPress, and PHP versions are shown on the **About** tab.
5. Do not include passwords, private rider information, emergency contacts, insurance details, or other sensitive data. Report security vulnerabilities privately to the repository owner rather than in a public issue.

## Storage, privacy, and data lifecycle

Activation creates plugin-owned tables using the current site's WordPress database prefix followed by `MRSA_`. WordPress users and media remain in core tables and are referenced rather than copied or owned by the plugin.

Rider records can contain names, aliases, email addresses, phone numbers, motorcycles, fuel capacity, emergency contacts, insurance details, and free-form notes. Registrations link riders to events and store bib, pillion, status, score, and placing values. Site operators are responsible for participant notices, consent where required, access control, retention, backups, and secure handling of this data.

WordPress personal-data export and erasure tools include relevant plugin data. Erasure anonymizes a rider and removes motorcycle and pillion details while retaining anonymous registration identifiers and results fields for event integrity. Administrator assignments remain until removed in **Global Setup**. Deactivation preserves data; destructive uninstall requires explicit opt-in in the plugin settings.

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
