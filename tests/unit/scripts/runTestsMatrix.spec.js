/** @jest-environment node */
// TECH-TEST-009 (CS-PLAN-088, Q-088-07): the explicit provider-matrix mode of
// scripts/run-tests.js. Fakes replace docker and the stage verifier; reports
// are real files under the gitignored build/test-reports/<run-id>/.
const fs = require('node:fs')
const path = require('node:path')
const { main, parseMatrixArgs, reportPaths, junitSummary, runMatrix } = require('../../../scripts/run-tests')

const root = path.resolve(__dirname, '../../..')
const T_PG = 'cs082-w1-pg:pgsql:cs082-runtime-35.0.2-local:8182'
const T_SQLITE = 'cs082-w1-sqlite:sqlite:cs082-runtime-35.0.2-local:8184'
const T_AIO = 'cs082-w1-aio:pgsql:cs082-runtime-34.0.4-local:8185:aio'

const xml = (counts = {}) => `<?xml version="1.0"?><testsuites><testsuite name="x" tests="${counts.tests ?? 12}" assertions="30" errors="${counts.errors ?? 0}" warnings="0" failures="${counts.failures ?? 0}" skipped="${counts.skipped ?? 0}" time="1.2"><testcase name="a"/></testsuite></testsuites>`

let runSeq = 0
const runIds = []
const nextRunId = () => {
	const id = `jest-matrix-${process.pid}-${++runSeq}`
	runIds.push(id)
	return id
}

afterAll(() => {
	for (const id of runIds) {
		fs.rmSync(path.join(root, 'build', 'test-reports', id), { recursive: true, force: true })
	}
})

/** A fake `docker exec`: records the call, writes the JUnit report the container would write, answers per suite. */
function fakeExec(behaviour = () => ({ status: 0 })) {
	const calls = []
	const exec = jest.fn(async (args, logPath) => {
		const container = args[1]
		const suite = /phpunit\.(\w+)\.xml/.exec(args.join(' '))[1]
		const containerJunit = args[args.indexOf('--log-junit') + 1]
		calls.push({ container, suite, containerJunit, args, logPath })
		const outcome = behaviour({ container, suite })
		fs.writeFileSync(logPath, `output of ${container} ${suite}`)
		if (outcome.report !== null && outcome.status === 0) {
			// The bind mount makes the in-container path visible on the host.
			const hostPath = containerJunit.replace('/var/www/html/custom_apps/chorestrike', root)
			fs.writeFileSync(hostPath, outcome.report ?? xml())
		}
		return { status: outcome.status, signal: outcome.signal }
	})
	return { exec, calls }
}

const verifyOk = jest.fn(() => ({ status: 0 }))

describe('matrix target parsing', () => {
	it('accepts verified-shape targets and defaults the row to primary', () => {
		const { runId, targets } = parseMatrixArgs(['--run-id', 'abc-123', T_PG, T_AIO])
		expect(runId).toBe('abc-123')
		expect(targets).toEqual([
			{ project: 'cs082-w1-pg', provider: 'pgsql', runtime: 'cs082-runtime-35.0.2-local', port: 8182, row: 'primary' },
			{ project: 'cs082-w1-aio', provider: 'pgsql', runtime: 'cs082-runtime-34.0.4-local', port: 8185, row: 'aio' },
		])
		expect(parseMatrixArgs([T_PG]).runId).toMatch(/^m\d{14}-[0-9a-f]{4}$/)
	})

	it.each([
		['no target', []],
		['a bad provider', ['cs082-x:oracle:cs082-runtime-1:8182']],
		['a project outside the owned namespace', ['canonical:pgsql:cs082-runtime-1:8182']],
		['a port below 1024', ['cs082-x:pgsql:cs082-runtime-1:80']],
		['an unknown row', ['cs082-x:pgsql:cs082-runtime-1:8182:other']],
		['extra fields', ['cs082-x:pgsql:cs082-runtime-1:8182:aio:extra']],
		['a duplicate project', [T_PG, 'cs082-w1-pg:sqlite:cs082-runtime-35.0.2-local:8190']],
		['a duplicate port', [T_PG, 'cs082-w1-other:sqlite:cs082-runtime-35.0.2-local:8182']],
		['two targets sharing one report directory', [T_PG, 'cs082-w1-pg2:pgsql:cs082-runtime-35.0.2-local:8190']],
		['a bad run id', ['--run-id', '../x', T_PG]],
	])('rejects %s', (label, args) => {
		expect(() => parseMatrixArgs(args)).toThrow()
	})

	it('gives every run, runtime, row, provider and suite its own report files', () => {
		const { runId, targets } = parseMatrixArgs(['--run-id', 'run-one', T_PG, T_SQLITE, T_AIO])
		const files = targets.flatMap(target => ['unit', 'integration'].flatMap((suite) => {
			const paths = reportPaths(runId, target, suite)
			return [paths.junit, paths.log]
		}))
		expect(new Set(files).size).toBe(files.length)
		expect(reportPaths(runId, targets[2], 'unit').containerJunit)
			.toBe('/var/www/html/custom_apps/chorestrike/build/test-reports/run-one/cs082-runtime-34.0.4-local-aio/pgsql/unit.junit.xml')
	})
})

describe('JUnit summary', () => {
	it('reads the outermost suite totals', () => {
		expect(junitSummary(xml({ tests: 5, failures: 1, errors: 2, skipped: 3 }))).toEqual({ tests: 5, failures: 1, errors: 2, skipped: 3 })
	})

	it('has no summary for an empty or foreign file', () => {
		expect(junitSummary('')).toBeNull()
		expect(junitSummary('<html/>')).toBeNull()
	})
})

describe('runMatrix', () => {
	const lines = []
	const out = line => lines.push(line)

	beforeEach(() => {
		lines.length = 0
		verifyOk.mockClear()
	})

	it('verifies every target read-only before it runs anything, and stops on a failed verification', async () => {
		const { exec } = fakeExec()
		const verify = jest.fn(target => ({ status: target.project === 'cs082-w1-sqlite' ? 3 : 0 }))

		const code = await runMatrix(['--run-id', nextRunId(), T_PG, T_SQLITE], { verify, exec, out })

		expect(code).toBe(3)
		expect(exec).not.toHaveBeenCalled()
		expect(lines.join('\n')).toMatch(/failed the read-only verification; nothing was run/)
	})

	it('runs both suites on every target with a unique report each and passes', async () => {
		const { exec, calls } = fakeExec()
		const runId = nextRunId()

		const code = await runMatrix(['--run-id', runId, T_PG, T_SQLITE, T_AIO], { verify: verifyOk, exec, out })

		expect(code).toBe(0)
		expect(verifyOk).toHaveBeenCalledTimes(3)
		expect(calls).toHaveLength(6)
		expect(new Set(calls.map(call => call.containerJunit)).size).toBe(6)
		expect(calls.map(call => `${call.container} ${call.suite}`).sort()).toEqual([
			'cs082-w1-aio-nextcloud-pgsql-1 integration', 'cs082-w1-aio-nextcloud-pgsql-1 unit',
			'cs082-w1-pg-nextcloud-pgsql-1 integration', 'cs082-w1-pg-nextcloud-pgsql-1 unit',
			'cs082-w1-sqlite-nextcloud-sqlite-1 integration', 'cs082-w1-sqlite-nextcloud-sqlite-1 unit',
		])
		for (const call of calls) {
			expect(call.args).toEqual(expect.arrayContaining(['--fail-on-skipped', '--do-not-cache-result', '--order-by=default', '--log-junit']))
			expect(fs.readFileSync(call.logPath, 'utf8')).toContain(call.suite)
		}
		expect(lines.at(-1)).toMatch(/PASSED/)
	})

	it('runs the suites of one target in sequence and independent targets concurrently', async () => {
		const active = { max: 0, now: 0 }
		const perTarget = new Map()
		const exec = jest.fn(async (args, logPath) => {
			const container = args[1]
			perTarget.set(container, (perTarget.get(container) ?? 0) + 1)
			expect(perTarget.get(container)).toBe(1)
			active.now++
			active.max = Math.max(active.max, active.now)
			await new Promise(resolve => setTimeout(resolve, 5))
			active.now--
			perTarget.set(container, perTarget.get(container) - 1)
			const hostPath = args[args.indexOf('--log-junit') + 1].replace('/var/www/html/custom_apps/chorestrike', root)
			fs.writeFileSync(hostPath, xml())
			fs.writeFileSync(logPath, '')
			return { status: 0 }
		})

		expect(await runMatrix(['--run-id', nextRunId(), T_PG, T_SQLITE], { verify: verifyOk, exec, out })).toBe(0)
		expect(active.max).toBe(2)
	})

	it('preserves the failing exit code of a suite and still runs the others', async () => {
		const { exec, calls } = fakeExec(({ container, suite }) => (
			container.includes('sqlite') && suite === 'integration' ? { status: 7 } : { status: 0 }
		))

		const code = await runMatrix(['--run-id', nextRunId(), T_PG, T_SQLITE], { verify: verifyOk, exec, out })

		expect(code).toBe(7)
		expect(calls).toHaveLength(4)
		expect(lines.join('\n')).toMatch(/FAIL cs082-w1-sqlite sqlite integration: process 7/)
		expect(lines.at(-1)).toMatch(/FAILED: 1 of 4/)
	})

	it('fails a green process whose report is missing', async () => {
		const { exec } = fakeExec(({ suite }) => (suite === 'unit' ? { status: 0, report: null } : { status: 0 }))

		const code = await runMatrix(['--run-id', nextRunId(), T_PG], { verify: verifyOk, exec, out })

		expect(code).toBe(1)
		expect(lines.join('\n')).toMatch(/missing or unreadable JUnit report/)
	})

	it.each([
		['a skipped test', { skipped: 1 }],
		['a failure', { failures: 1 }],
		['an error', { errors: 1 }],
		['no tests at all', { tests: 0 }],
	])('fails a green process whose report shows %s', async (label, counts) => {
		const { exec } = fakeExec(() => ({ status: 0, report: xml(counts) }))

		const code = await runMatrix(['--run-id', nextRunId(), T_PG], { verify: verifyOk, exec, out })

		expect(code).toBe(1)
		expect(lines.join('\n')).toMatch(/report shows/)
	})

	it('fails an interrupted or unstartable process', async () => {
		const killed = fakeExec(() => ({ status: null, signal: 'SIGTERM' }))
		expect(await runMatrix(['--run-id', nextRunId(), T_PG], { verify: verifyOk, exec: killed.exec, out })).toBe(1)

		const broken = jest.fn(async () => ({ status: null, error: new Error('docker unavailable') }))
		expect(await runMatrix(['--run-id', nextRunId(), T_PG], { verify: verifyOk, exec: broken, out })).toBe(1)
	})
})

describe('canonical mode', () => {
	it('stays argument-free and does not accept the matrix flag', () => {
		const run = jest.fn(() => ({ status: 0 }))
		expect(() => main(['--matrix', T_PG], run)).toThrow('Usage')
		expect(run).not.toHaveBeenCalled()
		expect(main([], run)).toBe(0)
		expect(run.mock.calls.map(([command, args]) => [command, args[0]])).toEqual([
			['docker', 'compose'], [process.execPath, 'scripts/test-runtime.js'], ['docker', 'exec'], ['docker', 'exec'],
		])
	})
})
