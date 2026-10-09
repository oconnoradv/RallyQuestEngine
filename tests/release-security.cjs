'use strict';
const assert = require('node:assert/strict');
const { checkDependabot, checkCodeQL } = require('../.github/scripts/release-security.cjs');

(async () => {
	const clean = () => ({
		version: '2.1.0',
		runs: [{ tool: { driver: { name: 'CodeQL' } }, results: [], invocations: [{ executionSuccessful: true }] }]
	});
	checkCodeQL([clean()]);
	for (const invalid of [[], [{}], [{ version: '2.1.0', runs: [] }]]) {
		assert.throws(() => checkCodeQL(invalid));
	}
	const finding = clean();
	finding.runs[0].results.push({ level: 'note', suppressions: [{ kind: 'external' }] });
	assert.throws(() => checkCodeQL([finding]), /findings: 1/);
	const failed = clean();
	failed.runs[0].invocations[0].executionSuccessful = false;
	assert.throws(() => checkCodeQL([failed]), /analysis failure/);
	const notification = clean();
	notification.runs[0].invocations[0].toolExecutionNotifications = [{ level: 'error' }];
	assert.throws(() => checkCodeQL([notification]), /analysis failure/);
	const unknown = clean();
	unknown.runs[0].tool.driver.name = 'Other';
	assert.throws(() => checkCodeQL([unknown]), /Expected CodeQL/);
	await checkDependabot({ paginate: async (route, options) => {
		assert.equal(route, 'GET /repos/{owner}/{repo}/dependabot/alerts');
		assert.deepEqual(options, { owner: 'owner', repo: 'repo', state: 'open', per_page: 100 });
		return [];
	} }, { owner: 'owner', repo: 'repo' });
	await assert.rejects(checkDependabot({ paginate: async () => [
		{ number: 1, security_advisory: { severity: 'low' } }
	] }, {}), /#1 \(low\)/);
	await assert.rejects(checkDependabot({ paginate: async () => { throw new Error('API forbidden'); } }, {}), /API forbidden/);
	await assert.rejects(checkDependabot({ paginate: async () => ({}) }, {}), /invalid alert list/);
	console.log('PASS: release gates reject findings, missing/failed analysis and unavailable Dependabot access.');
})().catch(error => { console.error(error); process.exitCode = 1; });
