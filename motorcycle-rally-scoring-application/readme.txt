=== Motorcycle Rally Scoring Application ===
Contributors: oconnoradv
Tags: motorcycle, rally, registration, events
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 0.1.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Manage motorcycle rally administrators, motorcycles, riders, and event registrations in WordPress.

== Description ==

Motorcycle Rally Scoring Application provides administrative tabs for:

* Administrators: global administrator assignments using existing WordPress users.
* Bike Setup: manual or imported motorcycle make/model records plus transactional bulk deletion of unassigned catalog rows.
* Rider Registration: rider details, make/model-filtered motorcycles with optional manually entered four-digit years, emergency contacts, insurance information, and registered events.
* Event Setup: event configuration and per-event administrator assignments.
* Event Rosters: event selection, complete registered-rider details, dedicated enrollment, new-rider recovery, pillion information, and unique per-event bib numbers.
* Scoring: event selection, roster scoring summaries, and the reserved scoring workspace tabs.
* About: version, licensing, attribution, and scope.

The Scoring tab has Bonus Point Library, Submissions - Pending Review,
Submissions - Reviewed, and Submissions - Contested tabs. Their requirements
have not yet been defined. These tabs are intentionally placeholders, not
a working scoring engine. Bib links provide event/rider context; this
release does not create bonus records, calculate scores, or determine placing.
New registrations have status Registered, score 0, and unset placing.

WordPress administrators can bootstrap setup. Assigned global administrators
manage plugin data. Assigned event administrators are restricted to their
assigned events and their enrolled riders; they cannot change global setup,
the motorcycle catalog, or master rider records.

Tables use the current site's WordPress prefix followed by MRSA_, avoiding
collisions between sites. No WordPress core tables or users are replaced.

== Installation ==

1. Back up the database and use a supported, patched WordPress/PHP installation.
2. Upload motorcycle-rally-scoring-application-vX.Y.Z.zip through Plugins > Add New > Upload Plugin.
3. Activate Motorcycle Rally Scoring Application.
4. Open the rally administration menu as a WordPress administrator.
5. Assign administrators, populate Bike Setup, register riders, and configure events.
6. Enroll riders in an event using an unused bib number.

Event dates are derived from the configured start date/time in the WordPress
site timezone. Add Rider and Add Event open dedicated edit pages with one
attribute per row. Enrolled rider details remain read-only outside explicit
registration controls.

Successful forms return to their originating form or tab. If Event Rosters has
no unenrolled rider to select, Add Rider to Event can open the Add Rider form
and return to enrollment after the new rider is saved.

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
details are cleared. Remove global assignments in Administrators and event
assignments on the event Configure page if needed.
Removing plugin data does not delete WordPress users or media attachments.
Deactivation preserves data. Destructive uninstall requires explicit opt-in
through the plugin's data-removal setting; back up before enabling it.

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
