'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { checkDependabot, checkCodeQL } = require('../.github/scripts/release-security.cjs');

(async () => {
	const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/security-release.yml'), 'utf8');
	const policy = workflow.match(/- name: Report CodeQL visibility policy[\s\S]*?        run: \|\n((?:          .*\n)+)/);
	assert.ok(policy, 'Visibility policy must be present in the workflow.');
	const script = policy[1].replace(/^          /gm, '');
	const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'mrsa-codeql-policy-'));
	try {
		for (const visibility of ['true', 'false', '']) {
			const output = path.join(directory, 'output');
			const summary = path.join(directory, 'summary');
			fs.writeFileSync(output, '');
			fs.writeFileSync(summary, '');
			const run = spawnSync('bash', ['-c', script], {
				encoding: 'utf8',
				env: { ...process.env, REPOSITORY_PRIVATE: visibility, GITHUB_OUTPUT: output, GITHUB_STEP_SUMMARY: summary }
			});
			if (run.error) throw run.error;
			assert.equal(run.status, visibility === '' ? 1 : 0);
			if (visibility === 'true') {
				assert.match(run.stdout, /::notice::CodeQL was skipped/);
				assert.match(fs.readFileSync(output, 'utf8'), /notice=CodeQL was skipped/);
				assert.match(fs.readFileSync(summary, 'utf8'), /repository is private/);
			} else if (visibility === 'false') {
				assert.match(fs.readFileSync(output, 'utf8'), /CodeQL JavaScript security analysis passed/);
				assert.equal(fs.readFileSync(summary, 'utf8'), '');
			} else {
				assert.match(run.stdout, /::error::Repository visibility was unavailable/);
			}
		}
		const codeql = workflow.split('  codeql:')[1].split('  dependabot:')[0];
		for (const step of ['Check out release source', 'Initialize CodeQL', 'Analyze the checked-out commit', 'Reject CodeQL findings or incomplete reports']) {
			assert.ok(codeql.includes('- name: ' + step + '\n        if: github.event.repository.private == false'));
		}
		assert.ok(workflow.includes('needs: [security, codeql, dependabot]'));
		assert.ok(workflow.includes('CODEQL_NOTICE: ${{ needs.codeql.outputs.notice }}'));
		assert.ok(workflow.includes('$CODEQL_NOTICE Publication'));
	} finally {
		fs.rmSync(directory, { recursive: true, force: true });
	}
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
