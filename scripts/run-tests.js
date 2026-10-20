/**
 * Backend test runner.
 *
 * - No arguments: provision the canonical disposable stack, then execute both
 *   full PHP suites.
 * - `--matrix [--run-id <id>] <project:provider:runtime:port[:row]> ...`
 *   (TECH-TEST-009, Q-088-07): run both full suites on already staged, owned
 *   provider targets, independent targets in parallel. It never provisions,
 *   seeds or resets: every target must first pass the read-only
 *   `test-runtime-stage.js verify`. Each suite writes its own JUnit report and
 *   complete stdout/stderr under
 *   `build/test-reports/<run-id>/<runtime>-<row>/<provider>/` (gitignored), so
 *   a failing test is named in the report instead of lost with the summary.
 */
const { spawn, spawnSync } = require('node:child_process')
const crypto = require('node:crypto')
const fs = require('node:fs')
const path = require('node:path')

const ROOT = path.resolve(__dirname, '..')
const SUITES = ['unit', 'integration']
const PROVIDERS = ['mysql', 'pgsql', 'sqlite']
const ROWS = ['primary', 'aio']
const APP_DIR = '/var/www/html/custom_apps/chorestrike'
const MATRIX_USAGE = 'Usage: scripts/run-tests.js --matrix [--run-id <id>] <cs082-project>:<mysql|pgsql|sqlite>:<cs082-runtime-name>:<port>[:primary|aio] ...'

function main(args = process.argv.slice(2), run = spawnSync) {
	if (args.length) throw new Error('Usage: scripts/run-tests.sh (no arguments)')
	const commands = [
		['docker', ['compose', '-f', 'docker/compose.test.yml', 'up', '-d', '--build', '--wait', '--wait-timeout', '900']],
		[process.execPath, ['scripts/test-runtime.js', 'verify-canonical']],
		...SUITES.map(suite => ['docker', ['exec', 'chorestrike-test', 'vendor/bin/phpunit',
			'--configuration', `phpunit.${suite}.xml`, '--fail-on-skipped', '--do-not-cache-result', '--order-by=default']]),
	]
	for (const [command, arguments_] of commands) {
		const result = run(command, arguments_, { cwd: ROOT, stdio: 'inherit' })
		if (result.error || result.signal || result.status !== 0) return result.status || 1
	}
	return 0
}

// ---------------------------------------------------------------------------
// Matrix mode
// ---------------------------------------------------------------------------

function parseTarget(spec) {
	const [project, provider, runtime, port, row = 'primary', ...extra] = String(spec).split(':')
	if (extra.length || !/^cs082-[a-z0-9][a-z0-9-]*$/.test(project ?? '') || !PROVIDERS.includes(provider)
		|| !/^cs082-runtime-[a-z0-9][a-z0-9.-]*$/.test(runtime ?? '') || !/^\d+$/.test(port ?? '')
		|| Number(port) < 1024 || Number(port) > 65535 || !ROWS.includes(row)) {
		throw new Error(`Invalid matrix target ${JSON.stringify(spec)}. ${MATRIX_USAGE}`)
	}
	return { project, provider, runtime, port: Number(port), row }
}

/** Parse and validate the matrix arguments; every report path must be unique. */
function parseMatrixArgs(args) {
	const rest = [...args]
	let runId = `m${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}-${crypto.randomBytes(2).toString('hex')}`
	if (rest[0] === '--run-id') {
		rest.shift()
		runId = rest.shift() ?? ''
		if (!/^[A-Za-z0-9][A-Za-z0-9-]{2,40}$/.test(runId)) throw new Error(`Invalid run id. ${MATRIX_USAGE}`)
	}
	if (!rest.length) throw new Error(MATRIX_USAGE)
	const targets = rest.map(parseTarget)
	for (const key of ['project', 'port']) {
		if (new Set(targets.map(target => target[key])).size !== targets.length) throw new Error(`Matrix targets must have distinct ${key}s`)
	}
	if (new Set(targets.map(target => `${target.runtime}-${target.row}/${target.provider}`)).size !== targets.length) {
		throw new Error('Matrix targets must differ in runtime, row or provider (one report directory each)')
	}
	return { runId, targets }
}

/** Host and in-container paths of one target suite's JUnit report and log. */
function reportPaths(runId, target, suite) {
	const relative = [runId, `${target.runtime}-${target.row}`, target.provider]
	const base = path.join(ROOT, 'build', 'test-reports', ...relative)
	return {
		directory: base,
		junit: path.join(base, `${suite}.junit.xml`),
		log: path.join(base, `${suite}.log`),
		containerJunit: [APP_DIR, 'build', 'test-reports', ...relative, `${suite}.junit.xml`].join('/'),
	}
}

/** Totals of the outermost <testsuite> of a PHPUnit JUnit file, or null. */
function junitSummary(xml) {
	const tag = /<testsuite\s[^>]*>/.exec(xml)?.[0]
	if (!tag) return null
	const attribute = name => Number(new RegExp(`\\s${name}="(\\d+)"`).exec(tag)?.[1] ?? 0)
	return { tests: attribute('tests'), failures: attribute('failures'), errors: attribute('errors'), skipped: attribute('skipped') }
}

function suiteArguments(container, suite, junit) {
	return ['exec', container, 'vendor/bin/phpunit', '--configuration', `phpunit.${suite}.xml`, '--fail-on-skipped',
		'--do-not-cache-result', '--order-by=default', '--log-junit', junit]
}

/** Run one docker command with stdout and stderr retained in a log file. */
function execToLog(args, logPath) {
	return new Promise((resolve) => {
		const log = fs.createWriteStream(logPath)
		const child = spawn('docker', args, { cwd: ROOT, stdio: ['ignore', 'pipe', 'pipe'] })
		child.stdout.pipe(log, { end: false })
		child.stderr.pipe(log, { end: false })
		child.on('error', (error) => { log.end(); resolve({ status: null, error }) })
		child.on('close', (status, signal) => { log.end(); resolve({ status, signal }) })
	})
}

function verifyTarget(target) {
	return spawnSync(process.execPath, ['scripts/test-runtime-stage.js', 'verify', target.project, target.provider,
		target.runtime, String(target.port), target.row], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'ignore', 'inherit'] })
}

/** Judge one finished suite: process status first, then its report. */
function judge(target, suite, outcome, paths) {
	const name = `${target.project} ${target.provider} ${suite}`
	if (outcome.error || outcome.signal || outcome.status !== 0) {
		return { name, ok: false, status: outcome.status || 1, detail: `process ${outcome.signal ?? outcome.status ?? outcome.error?.message}; see ${paths.log}` }
	}
	let summary = null
	try {
		summary = junitSummary(fs.readFileSync(paths.junit, 'utf8'))
	} catch { /* reported below */ }
	if (!summary) {
		return { name, ok: false, status: 1, detail: `missing or unreadable JUnit report ${paths.junit} (is the target staged from this checkout?)` }
	}
	if (summary.tests === 0 || summary.failures || summary.errors || summary.skipped) {
		return { name, ok: false, status: 1, detail: `report shows ${JSON.stringify(summary)}; see ${paths.junit}` }
	}
	return { name, ok: true, status: 0, detail: `${summary.tests} tests` }
}

/**
 * Verify every target read-only, then run both suites on each (targets in
 * parallel, suites of one target in sequence). The aggregate fails on a
 * failed process, a missing report, or any failure, error or skip in a report.
 */
async function runMatrix(args, deps = {}) {
	const { verify = verifyTarget, exec = execToLog, out = line => process.stdout.write(`${line}\n`) } = deps
	const { runId, targets } = parseMatrixArgs(args)
	for (const target of targets) {
		const result = verify(target)
		if (result.error || result.status !== 0) {
			out(`[matrix] ${target.project} failed the read-only verification; nothing was run`)
			return result.status || 1
		}
	}
	const { containerName } = require('./test-runtime-stage')
	out(`[matrix] run ${runId}: ${targets.length} verified target(s); reports under build/test-reports/${runId}/`)
	const perTarget = await Promise.all(targets.map(async (target) => {
		const results = []
		for (const suite of SUITES) {
			const paths = reportPaths(runId, target, suite)
			fs.mkdirSync(paths.directory, { recursive: true })
			const outcome = await exec(suiteArguments(containerName(target.project, target.provider), suite, paths.containerJunit), paths.log)
			const judged = judge(target, suite, outcome, paths)
			out(`[matrix] ${judged.ok ? 'PASS' : 'FAIL'} ${judged.name}: ${judged.detail}`)
			results.push(judged)
		}
		return results
	}))
	const failed = perTarget.flat().filter(result => !result.ok)
	out(failed.length ? `[matrix] FAILED: ${failed.length} of ${perTarget.flat().length} suite(s)` : '[matrix] PASSED: every suite ran, reported and passed')
	return failed.length ? failed[0].status : 0
}

module.exports = { main, parseMatrixArgs, reportPaths, junitSummary, judge, runMatrix }

if (require.main === module) {
	const args = process.argv.slice(2)
	if (args[0] === '--matrix') {
		runMatrix(args.slice(1)).then(
			(code) => { process.exitCode = code },
			(error) => { process.stderr.write(`${error.message}\n`); process.exitCode = 1 },
		)
	} else {
		try { process.exitCode = main() } catch (error) {
			process.stderr.write(`${error.message}\n`)
			process.exitCode = 1
		}
	}
}
