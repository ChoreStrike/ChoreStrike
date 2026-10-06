/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * Canonical sequential browser gate (CS-PLAN-068 §3.1, decision D1).
 *
 * Runs the three Chromium pools SEQUENTIALLY against one disposable
 * database and enforces the required-scenario contract:
 *
 *   1. DISCOVERY — `playwright test --list` per project. Every required
 *      spec must be selected by exactly ONE project (a gap means a deleted
 *      or unselected required test; a duplicate means two pools would run
 *      the same reset-owning spec). Required scenario titles are pinned in
 *      the manifest below — the manifest never learns its requirements
 *      from the run it validates.
 *   2. EXECUTION — each project with `--workers=1 --retries=0
 *      --forbid-only` and the JSON reporter. A flaky or retried result
 *      cannot hide behind a second attempt (retries are disabled).
 *   3. RESULT CHECK — zero skipped, failed, flaky or interrupted tests in
 *      any required spec; every required scenario actually RAN (a skip
 *      that only existed in discovery is still a violation).
 *
 * Reports and traces stay under gitignored build/e2e-gate/<run-id>/.
 * They may contain browser/network data; never commit or publish them.
 *
 * Self-test: `node scripts/run-e2e-gate.js --self-test` feeds synthetic
 * discovery/report fixtures through the pure checks below (selection gap,
 * duplicate ownership, skipped/failed/flaky results, missing scenario) —
 * it never launches a browser.
 *
 * npm: `npm run test:e2e:gate`
 */

'use strict'

const { spawnSync } = require('node:child_process')
const fs = require('node:fs')
const path = require('node:path')

const GATE_PROJECTS = ['chromium', 'chromium-serial-db', 'chromium-scheduling-owner']
const SETUP_PROJECT = 'setup'
// Gitignored AND outside Playwright's `build/test-results/` output dir —
// Playwright wipes its output dir at the start of every run, which would
// destroy the previous project's JSON report before the result check reads it.
const REPORT_DIR = path.join('build', 'e2e-gate')
const SCENARIO_FILE = path.join(__dirname, '../tests/e2e/required-scenarios.json')

/**
 * The required-file/scenario manifest (CS-PLAN-068 §3.1). `project` is the
 * ONLY pool that may own the file; `scenarios` are case-insensitive title
 * substrings that must both be discovered AND actually run; `minTests` is
 * the lower bound of tests the file must execute in the gate.
 */
const REQUIRED_SPECS = [
	// Canonical journey (replaces gui-scratch-flow, parent decision D5).
	{ file: 'core-household-journey.spec.ts', project: 'chromium-serial-db', scenarios: ['canonical household journey'], minTests: 1 },
	// Seed/reset-owned serial pool.
	{ file: 'setup-wizard-journey.spec.ts', project: 'chromium-serial-db', scenarios: ['owner completes the wizard through the ui', 'six-floor house cap', 'six-floor apartment cap'], minTests: 5 },
	{ file: 'task-completion-full-flow.spec.ts', project: 'chromium-serial-db', scenarios: ['undo restores the exact state'], minTests: 5 },
	{ file: 'insights-flow.spec.ts', project: 'chromium-serial-db', scenarios: ['overcapacity warning appears when a day exceeds its capacity', 'owner corrects a recorded work split'], minTests: 4 },
	{ file: 'family-core-ux.spec.ts', project: 'chromium-serial-db', scenarios: ['child: my day hides manage controls and executes the checklist'], minTests: 7 },
	{ file: 'workflow-state-reconciliation.spec.ts', project: 'chromium-serial-db', scenarios: ['same-device logout and child login'], minTests: 4 },
	// CS-PLAN-072 WP1: the occurrence-timeline client-contract slice.
	{ file: 'family-rhythm.spec.ts', project: 'chromium-serial-db', scenarios: ['a ui completion carries the full occurrence receipt', 'an owner records an agreed handover in the ui', 'my day shows one catch-up card', 'fixed and relative schedules explain'], minTests: 13 },
	// CS-PLAN-070 WP7 (CS-PLAN-078): the reward catalog/request browser
	// contract — gate flip, catalog scopes, full request lifecycle.
	{ file: 'family-reward-api.spec.ts', project: 'chromium-serial-db', scenarios: ['the catalog gate is owner-controlled, versioned and denied for members', 'child request lifecycle: frozen terms, approve, fulfill, exact debit, exact refund'], minTests: 3 },
	// CS-PLAN-070 WP8 (CS-PLAN-079): the reward UI and onboarding slice —
	// two independent gates, exact prices, lifecycle, conflicts, funding
	// hold, privacy boundaries, the uncertain-command replay and the
	// mobile/keyboard/localization proof.
	{ file: 'family-rewards.spec.ts', project: 'chromium-serial-db', scenarios: ['catalog-off core works for every role', 'owner administration works while the catalog is off', 'child request', 'adult self-approval, cancellation', 'changed terms require renewed review', 'funding hold', 'foreign requests stay invisible', 'uncertain command outcome replays', 'mobile targets, keyboard operation'], minTests: 9 },
	{ file: 'today-view.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'members-owner.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 3 },
	{ file: 'family-membership-hardening.spec.ts', project: 'chromium-serial-db', scenarios: ['invite enforces the approved 60/300 boundary', 'remove enforces the approved 60/300 boundary', 'updaterole enforces the approved 60/300 boundary'], minTests: 3 },
	{ file: 'settings.spec.ts', project: 'chromium-serial-db', scenarios: ['reset keeps setup, structure, tasks and history'], minTests: 8 },
	{ file: 'room-management.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'dashboard.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'task-checklist-and-features.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: '3-floor-house.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'error-handling.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'validation.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'multi-user-interactions.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'time-travel-overdue.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	// WP3 cutover: time-travel-streak-notification (freeze/at-risk) and
	// release-boost-shop retired with the legacy economy.
	{ file: 'release-task-lifecycle.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'release-visibility-roles.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	{ file: 'release-task-templates.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	// CS-PLAN-070 WP5: release-notifications (weekly-goal bell) is replaced
	// by the opt-in quiet-hours digest — the app's ONLY notification path.
	{ file: 'quiet-digest.spec.ts', project: 'chromium-serial-db', scenarios: ['quiet hours gate the delivery attempt', 'opted-in free and paused days suppress task reminders'], minTests: 4 },
	{ file: 'release-setup-wizard.spec.ts', project: 'chromium-serial-db', scenarios: [], minTests: 1 },
	// CS-PLAN-070 WP4: the consent lifecycle needs the owner + reset-owned
	// family DB (flag flip + UI consent/withdraw).
	{ file: 'ranking-consent.spec.ts', project: 'chromium-serial-db', scenarios: ['owner enables ranking; member joins pending and withdraws'], minTests: 1 },
	// CS-PLAN-070 WP6: the calm-core spec flips the personal optional-area
	// preference and walks the pause lifecycle through the real API — a
	// parallel reseed would corrupt the preference/pause state, serial only.
	{ file: 'calm-core-ui.spec.ts', project: 'chromium-serial-db', scenarios: ['every role lands on my day', 'optional arcade hides', 'pause lifecycle stays neutral', 'a free day stays neutral', 'reduced motion overrides an explicit animation opt-in'], minTests: 6 },
	// Owner-scoped scheduling pool.
	{ file: 'scheduling.spec.ts', project: 'chromium-scheduling-owner', scenarios: [], minTests: 1 },
	{ file: 'flex-window-scheduling.spec.ts', project: 'chromium-scheduling-owner', scenarios: [], minTests: 1 },
	{ file: 'availability-settings-flow.spec.ts', project: 'chromium-scheduling-owner', scenarios: [], minTests: 1 },
	{ file: 'scheduling-apply-flow.spec.ts', project: 'chromium-scheduling-owner', scenarios: ['exit journey'], minTests: 7 },
	// Default parallel pool (read-only against the seed).
	{ file: 'admin-only-tasks.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'calendar.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'core-functionality.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'dashboard-navigation.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'fast-core-tests.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'leaderboard.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'minimal-smoke-tests.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'overdue-task-claiming.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'private-tasks.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'room-detail.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'self-availability-member.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'snake-game.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'task-completion.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'task-manager.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	// WP3 cutover: teamwork-rewards (teamwork bonus) retired.
	{ file: 'title-selection.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	{ file: 'workflow-request-budgets.spec.ts', project: 'chromium', scenarios: [], minTests: 1 },
	// Auth setup runs once as the dependency of every browser project.
	{ file: 'auth.setup.ts', project: SETUP_PROJECT, scenarios: [], minTests: 3 },
]

// ---------------------------------------------------------------------------
// Pure checks (unit-tested by --self-test)
// ---------------------------------------------------------------------------

function collectSpecs(suites, out = [], parents = []) {
	for (const suite of suites ?? []) {
		const titles = [...parents, suite.title].filter(Boolean)
		for (const spec of suite.specs ?? []) {
			out.push({
				file: spec.file ?? suite.file,
				title: spec.title,
				identity: [...titles, spec.title].join(' > '),
				projectName: (spec.tests ?? [])[0]?.projectName,
				tests: spec.tests ?? [],
			})
		}
		collectSpecs(suite.suites, out, titles)
	}
	return out
}

/**
 * @param {Array<{file: string, title: string, projectName: string}>} discovered
 * @param {Array<{file: string, project: string, scenarios: string[], minTests: number}>} [manifest]
 * @param scenarios
 * @return {string[]} violations
 */
function checkSelection(discovered, manifest = REQUIRED_SPECS, scenarios) {
	const violations = []
	const ownership = new Map()
	for (const entry of discovered) {
		const base = path.basename(entry.file)
		// The setup project's specs are listed alongside every browser
		// project's discovery output; their projectName is 'setup', which is
		// exactly the manifest-assigned owner for auth.setup.ts.
		if (!ownership.has(base)) {
			ownership.set(base, new Set())
		}
		ownership.get(base).add(entry.projectName)
	}

	for (const required of manifest) {
		const owners = ownership.get(required.file)
		if (!owners || owners.size === 0) {
			violations.push(`selection gap: required spec '${required.file}' is selected by NO project (deleted or unselected required test)`)
			continue
		}
		if (owners.size > 1) {
			violations.push(`duplicate ownership: '${required.file}' is selected by [${[...owners].join(', ')}] — a spec must run in exactly one pool`)
			continue
		}
		const owner = [...owners][0]
		if (owner !== required.project) {
			violations.push(`wrong pool: '${required.file}' is owned by '${owner}' but the manifest assigns '${required.project}'`)
			continue
		}
		const titles = discovered
			.filter((entry) => path.basename(entry.file) === required.file)
			.map((entry) => entry.title.toLowerCase())
		for (const scenario of required.scenarios) {
			if (!titles.some((title) => title.includes(scenario))) {
				violations.push(`missing required scenario in '${required.file}': no discovered test title matches '${scenario}'`)
			}
		}
		if (titles.length < required.minTests) {
			violations.push(`insufficient coverage: '${required.file}' discovers ${titles.length} tests but the manifest requires >= ${required.minTests}`)
		}
		if (scenarios) {
			const actual = [...new Set(discovered.filter(entry => path.basename(entry.file) === required.file)
				.map(entry => entry.identity))].sort()
			if (!scenarios[required.file] || JSON.stringify(actual) !== JSON.stringify(scenarios[required.file])) {
				violations.push(`scenario inventory mismatch: '${required.file}' (review the changed selection before updating the manifest)`)
			}
		}
	}
	return violations
}

/**
 * @param {object} report Parsed Playwright JSON report of one project run.
 * @param {Array<{file: string, project: string, scenarios: string[], minTests: number}>} [manifest]
 * @param scenarios
 * @return {string[]} violations
 */
function checkResults(report, manifest = REQUIRED_SPECS, scenarios) {
	const violations = []
	if (report.errors?.length) violations.push('report contains global errors')
	const ran = new Map()
	for (const spec of collectSpecs(report.suites ?? [])) {
		const base = path.basename(spec.file)
		if (!ran.has(base)) {
			ran.set(base, { titles: [], skipped: 0, failed: 0, flaky: 0, interrupted: 0 })
		}
		const bucket = ran.get(base)
		bucket.titles.push((spec.title ?? '').toLowerCase())
		for (const test of spec.tests ?? []) {
			if (test.status === 'skipped') {
				bucket.skipped++
			} else if (test.status === 'flaky') {
				bucket.flaky++
			} else if (test.status === 'unexpected') {
				bucket.failed++
			} else if (test.status === 'interrupted') {
				bucket.interrupted++
			}
			if (test.status !== 'expected' || test.expectedStatus !== 'passed'
				|| test.results?.length !== 1 || test.results[0].status !== 'passed'
				|| test.results[0].retry !== 0) {
				violations.push(`invalid attempt: '${base}' / '${spec.title}' must pass once without retries`)
			}
		}
		if (!spec.tests.length) violations.push(`missing attempt: '${base}' / '${spec.title}'`)
	}
	if (scenarios) violations.push(...checkSelection(collectSpecs(report.suites), manifest, scenarios))

	for (const required of manifest) {
		const bucket = ran.get(required.file)
		if (!bucket) {
			violations.push(`never ran: required spec '${required.file}' produced no results in '${required.project}'`)
			continue
		}
		if (bucket.skipped > 0) {
			violations.push(`skip violation: '${required.file}' skipped ${bucket.skipped} required test(s) — a missing fixture is a failure, not a runtime skip`)
		}
		if (bucket.failed > 0) {
			violations.push(`failure: '${required.file}' has ${bucket.failed} failing required test(s)`)
		}
		if (bucket.flaky > 0) {
			violations.push(`flaky result: '${required.file}' has ${bucket.flaky} test(s) that only passed on retry (retries are disabled in the gate)`)
		}
		if (bucket.interrupted > 0) {
			violations.push(`interrupted: '${required.file}' has ${bucket.interrupted} interrupted test(s)`)
		}
		const executed = bucket.titles.length - bucket.skipped
		if (executed < required.minTests) {
			violations.push(`insufficient coverage: '${required.file}' executed ${executed} test(s) but the manifest requires >= ${required.minTests}`)
		}
		for (const scenario of required.scenarios) {
			const ranTitles = bucket.titles.filter((title) => title.includes(scenario))
			if (ranTitles.length === 0) {
				violations.push(`required scenario did not run in '${required.file}': '${scenario}'`)
			}
		}
	}
	return violations
}

// ---------------------------------------------------------------------------
// Process wrappers
// ---------------------------------------------------------------------------

function runPlaywright(args, env = {}) {
	const result = spawnSync(process.execPath, [require.resolve('@playwright/test/cli'), 'test', ...args], {
		stdio: ['ignore', 'pipe', 'pipe'],
		env: { ...process.env, ...env },
		maxBuffer: 16 * 1024 * 1024,
	})
	return {
		status: result.error || result.signal ? 1 : (result.status ?? 1),
		stdout: result.stdout ? result.stdout.toString('utf8') : '',
		stderr: result.stderr ? result.stderr.toString('utf8') : '',
	}
}

function parseJsonFromStdout(stdout) {
	const start = stdout.indexOf('{')
	if (start === -1) {
		return null
	}
	try {
		return JSON.parse(stdout.slice(start))
	} catch {
		return null
	}
}

/**
 * Discovery for one project: machine-readable `--list` output. Never
 * launches a browser. (The CLI exits nonzero when the other projects'
 * specs are reported as skipped in the list stats — the JSON report is
 * still complete, so only a missing report is fatal here.)
 * @param project
 */
function discoverProject(project) {
	const result = runPlaywright(['--project=' + project, '--list', '--reporter=json'])
	const report = parseJsonFromStdout(result.stdout)
	if (result.status !== 0 || !report || report.errors?.length) {
		throw new Error(
			`playwright --list failed for project '${project}' (exit ${result.status})`,
		)
	}
	return collectSpecs(report.suites ?? [])
}

function runProject(project, reportPath) {
	console.log(`[gate] running project '${project}' (workers=1, retries=0, forbid-only)…`)
	const result = runPlaywright(
		['--project=' + project, '--workers=1', '--retries=0', '--forbid-only', '--trace=retain-on-failure',
			'--output=' + path.join(path.dirname(reportPath), project), '--reporter=json'],
		{
			PLAYWRIGHT_JSON_OUTPUT_DIR: path.resolve(path.dirname(reportPath)),
			PLAYWRIGHT_JSON_OUTPUT_NAME: path.basename(reportPath),
		},
	)
	return result
}

// ---------------------------------------------------------------------------
// Self-test (synthetic fixtures, no browser)
// ---------------------------------------------------------------------------

function selfTest() {
	const failures = []
	const expectViolation = (label, violations, needle) => {
		if (violations.some((violation) => violation.toLowerCase().includes(needle))) {
			console.log(`  ok    ${label}`)
		} else {
			failures.push(label)
			console.log(`  FAIL  ${label} — no violation matching '${needle}' in:\n        ${violations.join('\n        ')}`)
		}
	}

	const [journey] = REQUIRED_SPECS
	const good = (file, title, project) => ({
		file: `tests/e2e/${file}`,
		title,
		projectName: project ?? journey.project,
	})
	const manifestOf = (entry) => [entry]

	console.log('self-test: selection checks')
	expectViolation(
		'selection gap is rejected',
		checkSelection([{ ...good('some-other-file.spec.ts', 'unrelated') }], manifestOf(journey)),
		'selection gap',
	)
	expectViolation(
		'duplicate project ownership is rejected',
		checkSelection(
			[good(journey.file, 'canonical household journey'), { ...good(journey.file, 'canonical household journey'), projectName: 'chromium' }],
			manifestOf(journey),
		),
		'duplicate ownership',
	)
	expectViolation(
		'wrong pool assignment is rejected',
		checkSelection([{ ...good(journey.file, 'canonical household journey'), projectName: 'chromium' }], manifestOf(journey)),
		'wrong pool',
	)
	expectViolation(
		'missing required scenario is rejected',
		checkSelection([good(journey.file, 'some unrelated title')], manifestOf(journey)),
		'missing required scenario',
	)
	const selectionOk = checkSelection(
		[good(journey.file, 'The canonical household journey walks every phase')],
		manifestOf(journey),
	)
	if (selectionOk.length === 0) {
		console.log('  ok    compliant discovery passes')
	} else {
		failures.push('compliant discovery')
		console.log(`  FAIL  compliant discovery must pass:\n        ${selectionOk.join('\n        ')}`)
	}

	console.log('self-test: result checks')
	const specIn = (status) => ({
		suites: [{
			file: `tests/e2e/${journey.file}`,
			specs: [{ title: 'The canonical household journey walks every phase', tests: [{ status, expectedStatus: 'passed', results: [{ status: 'passed', retry: 0 }] }] }],
		}],
	})
	const passing = checkResults(specIn('expected'), manifestOf(journey))
	if (passing.length === 0) {
		console.log('  ok    fully passing spec produces no violation')
	} else {
		failures.push('passing spec')
		console.log(`  FAIL  a passing spec must not violate:\n        ${passing.join('\n        ')}`)
	}

	expectViolation('required skip is rejected', checkResults(specIn('skipped'), manifestOf(journey)), 'skip violation')
	expectViolation('pass-on-retry result is rejected', checkResults(specIn('flaky'), manifestOf(journey)), 'flaky result')
	expectViolation('failed result is rejected', checkResults(specIn('unexpected'), manifestOf(journey)), 'failure')
	expectViolation('missing result report is rejected', checkResults({ suites: [] }, manifestOf(journey)), 'never ran')
	const noAttempt = specIn('expected')
	noAttempt.suites[0].specs[0].tests[0].results = []
	expectViolation('empty attempts are rejected', checkResults(noAttempt, [journey]), 'invalid attempt')
	const expectedFailure = specIn('expected')
	expectedFailure.suites[0].specs[0].tests[0].expectedStatus = 'failed'
	expectViolation('expected failures are rejected', checkResults(expectedFailure, [journey]), 'invalid attempt')

	if (failures.length > 0) {
		console.error(`\nself-test FAILED: ${failures.length} check(s)`)
		return 1
	}
	console.log('\nself-test passed: selection gaps, duplicate ownership, and synthetic skip/flaky/failure results are all rejected.')
	return 0
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main() {
	const args = process.argv.slice(2)
	if (args.includes('--self-test')) {
		return selfTest()
	}
	if (args.some(arg => arg !== '--write-manifest')) throw new Error('Unknown browser gate argument')

	console.log('[gate] discovery: verifying required-spec selection across the three pools…')
	const discovered = []
	for (const project of [...GATE_PROJECTS, SETUP_PROJECT]) {
		discovered.push(...discoverProject(project))
	}
	if (args.includes('--write-manifest')) {
		const inventory = Object.fromEntries(REQUIRED_SPECS.map(required => [required.file,
			[...new Set(discovered.filter(entry => path.basename(entry.file) === required.file).map(entry => entry.identity))].sort()]))
		const violations = checkSelection(discovered)
		if (violations.length) throw new Error(violations.join('\n'))
		fs.writeFileSync(SCENARIO_FILE, JSON.stringify(inventory, null, 2) + '\n')
		console.log('[gate] scenario inventory generated; review its diff before committing')
		return 0
	}
	const scenarios = JSON.parse(fs.readFileSync(SCENARIO_FILE, 'utf8'))
	const selectionViolations = checkSelection(discovered, REQUIRED_SPECS, scenarios)
	if (selectionViolations.length > 0) {
		console.error(`[gate] SELECTION FAILED — ${selectionViolations.length} violation(s):`)
		for (const violation of selectionViolations) {
			console.error(`  - ${violation}`)
		}
		return 1
	}
	console.log(`[gate] discovery ok: ${REQUIRED_SPECS.length} required specs owned by exactly one pool each.`)

	let exitCode = 0
	fs.mkdirSync(REPORT_DIR, { recursive: true })
	const runDir = fs.mkdtempSync(path.join(REPORT_DIR, 'run-'))
	console.log(`[gate] local evidence: ${runDir} (raw reports/traces must not be published)`)
	for (const project of GATE_PROJECTS) {
		const reportPath = path.join(runDir, `report-${project}.json`)
		const result = runProject(project, reportPath)
		if (result.status !== 0) {
			console.error(`[gate] project '${project}' exited with code ${result.status} (see ${reportPath} and build/playwright-report/ for traces).`)
			exitCode = exitCode || result.status
		}
		let report = null
		try {
			report = JSON.parse(fs.readFileSync(reportPath, 'utf8'))
		} catch {
			console.error(`[gate] could not read the JSON report for '${project}' at ${reportPath}`)
			exitCode = exitCode || 1
			continue
		}
		const owned = REQUIRED_SPECS.filter(entry => entry.project === project || entry.project === SETUP_PROJECT)
		const resultViolations = checkResults(report, owned, scenarios)
		fs.writeFileSync(path.join(runDir, `summary-${project}.json`), JSON.stringify({
			project,
			processExit: result.status,
			violations: resultViolations,
			tests: collectSpecs(report.suites).map(spec => ({
				file: path.basename(spec.file),
				title: spec.title,
				results: spec.tests.map(test => ({
					project: test.projectName,
					status: test.status,
					attempts: test.results?.map(attempt => ({ status: attempt.status, retry: attempt.retry })),
				})),
			})),
		}, null, 2) + '\n')
		if (resultViolations.length > 0) {
			console.error(`[gate] RESULT CHECK FAILED for '${project}':`)
			for (const violation of resultViolations) {
				console.error(`  - ${violation}`)
			}
			exitCode = exitCode || 1
		} else {
			console.log(`[gate] result check ok for '${project}'.`)
		}
	}

	if (exitCode !== 0) {
		console.error('[gate] FAILED — see the violations above.')
	} else {
		console.log('[gate] PASSED — all three pools ran sequentially with zero skipped/failed/flaky required scenarios.')
	}
	return exitCode
}

module.exports = { collectSpecs, checkSelection, checkResults, runPlaywright, REQUIRED_SPECS, main }
if (require.main === module) {
	try { process.exitCode = main() } catch (error) {
		console.error(`[gate] FAILED: ${error.message}`)
		process.exitCode = 1
	}
}
