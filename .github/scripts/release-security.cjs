'use strict';

async function checkDependabot(github, repository) {
	const alerts = await github.paginate('GET /repos/{owner}/{repo}/dependabot/alerts', {
		...repository,
		state: 'open',
		per_page: 100
	});
	if (!Array.isArray(alerts)) throw new Error('Dependabot returned an invalid alert list.');
	if (alerts.length) {
		throw new Error('Publication blocked by open Dependabot alerts: ' +
			alerts.map(alert => '#' + alert.number + ' (' + alert.security_advisory.severity + ')').join(', '));
	}
}

function checkCodeQL(reports) {
	if (!reports.length) throw new Error('CodeQL did not produce a SARIF report.');
	let runs = 0;
	let findings = 0;
	for (const report of reports) {
		if (report.version !== '2.1.0' || !Array.isArray(report.runs) || !report.runs.length) {
			throw new Error('CodeQL produced an invalid or empty SARIF report.');
		}
		for (const run of report.runs) {
			if (run.tool?.driver?.name !== 'CodeQL' || !Array.isArray(run.results)) {
				throw new Error('Expected CodeQL analysis results.');
			}
			for (const invocation of run.invocations || []) {
				if (invocation.executionSuccessful === false ||
					(invocation.toolExecutionNotifications || []).some(note => note.level === 'error')) {
					throw new Error('CodeQL reported an analysis failure.');
				}
			}
			runs++;
			findings += run.results.length;
		}
	}
	if (!runs || findings) throw new Error('Publication blocked by CodeQL findings: ' + findings + '.');
}

module.exports = { checkDependabot, checkCodeQL };
