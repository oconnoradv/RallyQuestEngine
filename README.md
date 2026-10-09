# MotorcycleRallyScoringApp
A Competitive Motorcycle Rally Scoring Application

## WordPress plugin v0.1.0

Install `releases/motorcycle-rally-scoring-application-v0.1.0.zip` using
**Plugins → Add New → Upload Plugin**, then activate it. The source is in
`motorcycle-rally-scoring-application/`. See that directory's `readme.txt`
for setup, privacy, external-data import, and security information.

The release implements administrative setup, a motorcycle catalog, rider
registration, event configuration, and event enrollment with unique bibs.
The four scoring tabs are placeholders pending their specifications; this
release does not calculate scores.

The installable ZIP is distributed in this repository. It is not a claim
of WordPress.org approval or NIST/CVE certification.

## Security checks and GitHub releases

The **Security checks and ZIP release** workflow runs on pull requests,
branch pushes, version tag pushes, and manual dispatch. It checks PHP and
JavaScript syntax, scans plugin source with Semgrep's PHP/JavaScript security
rules, and scans the repository with Trivy for known dependency vulnerabilities
and secrets. Findings (including low-severity and unfixed CVEs) and scanner
errors block publication. Scanner/action versions are pinned; Semgrep registry
rules and Trivy's vulnerability database are fetched at scan time.

After merging the workflow, create and push a tag such as `v0.1.0` on the
intended release commit. Tags must have the form `vMAJOR.MINOR.PATCH` and match
the plugin header, `MRSA_VERSION`, and readme stable tag. Alternatively, manually
run the workflow against an existing version tag that contains this workflow.
Branch and pull-request runs only check security; they never publish.

Only after every check succeeds does the workflow build a fresh installable
ZIP from the checked-out plugin directory and publish it, together with
`SHA256SUMS`, as a GitHub release for that existing tag. It does not reuse the
ZIP in `releases/` or overwrite an existing release. Only the publication job
has repository write permission. Protect release tags and restrict who can
create them in repository rulesets.

These automated checks are not penetration testing or NIST certification.
There are currently no bundled third-party dependencies or dependency
manifests, so dependency scanning cannot assess the deployed WordPress/PHP
versions, database server, or other plugins. Assess those separately and keep
them patched. A passing scan does not establish that all CVE or NIST risks
are absent.
