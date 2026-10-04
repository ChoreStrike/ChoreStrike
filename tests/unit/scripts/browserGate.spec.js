const fs = require('node:fs')
const vm = require('node:vm')
const path = require('node:path')
const { EventEmitter } = require('node:events')
const { spawnSync } = require('node:child_process')
const { collectSpecs, checkSelection, checkResults, runPlaywright, REQUIRED_SPECS, main } = require('../../../scripts/run-e2e-gate.js')

jest.mock('node:child_process', () => ({ spawnSync: jest.fn() }))
// Pin the canonical journey (not a positional index): the manifest grows,
// and REQUIRED_SPECS[0] must never silently change what this suite excludes.
const required = REQUIRED_SPECS.find(row => row.file === 'core-household-journey.spec.ts')
const reportFor = (entry = required) => ({
	suites: [{
		file: entry.file,
		specs: Array.from({ length: entry.minTests }, (_, i) => ({
			title: `${entry.scenarios.join(' ')} case ${i}`,
			tests: [{ projectName: entry.project, status: 'expected', expectedStatus: 'passed', results: [{ status: 'passed', retry: 0 }] }],
		})),
	}],
})

afterEach(() => jest.restoreAllMocks())

describe('canonical browser evidence', () => {
	it('the noise gate launches the real npm lifecycle without a shell and rejects interrupted runs', () => {
		const child = new EventEmitter()
		child.stdout = new EventEmitter()
		child.stderr = new EventEmitter()
		const spawn = jest.fn().mockReturnValue(child)
		const exit = jest.fn()
		const console = { log: jest.fn(), error: jest.fn() }
		const source = fs.readFileSync(path.resolve(__dirname, '../../../scripts/check-test-noise.js'), 'utf8')
		vm.runInNewContext(source, {
			require: () => ({ spawn }),
			console,
			process: {
				execPath: process.execPath,
				env: { npm_execpath: 'npm-cli.js' },
				exit,
				stdout: { write: jest.fn() },
				stderr: { write: jest.fn() },
			},
		})
		expect(spawn).toHaveBeenCalledWith(process.execPath, ['npm-cli.js', 'test'], expect.objectContaining({ env: expect.any(Object) }))
		expect(spawn.mock.calls[0][2].shell).toBeUndefined()
		child.emit('close', null)
		expect(exit).toHaveBeenCalledWith(1)
	})
	it('accepts a first-attempt passing report and rejects an absent owner report', () => {
		expect(checkResults(reportFor(), [required])).toEqual([])
		expect(checkResults({ suites: [] }, [required])).not.toEqual([])
	})
	it.each(['skipped', 'flaky', 'unexpected', 'interrupted'])('rejects %s results', status => {
		const report = reportFor()
		report.suites[0].specs[0].tests[0].status = status
		expect(checkResults(report, [required])).not.toEqual([])
	})
	it.each(['empty', 'expected failure', 'retry', 'no test'])('rejects %s attempt evidence', kind => {
		const report = reportFor()
		const spec = report.suites[0].specs[0]
		if (kind === 'empty') spec.tests[0].results = []
		if (kind === 'expected failure') spec.tests[0].expectedStatus = 'failed'
		if (kind === 'retry') spec.tests[0].results[0].retry = 1
		if (kind === 'no test') spec.tests = []
		expect(checkResults(report, [required])).not.toEqual([])
	})
	it('pins every scenario, not just a file count or a substring', () => {
		const rows = collectSpecs(reportFor().suites)
		const inventory = { [required.file]: rows.map(row => row.identity).sort() }
		expect(checkSelection(rows, [required], inventory)).toEqual([])
		rows[0].identity += ' silently changed'
		expect(checkSelection(rows, [required], inventory)).not.toEqual([])
		expect(checkSelection([], [required], inventory)).not.toEqual([])
		expect(checkSelection([...rows, { ...rows[0], projectName: 'chromium' }], [required], inventory)).not.toEqual([])
	})
	// CS-PLAN-070 WP8 (T11): the reward UI scenarios join the serial pool.
	it('pins the family-rewards spec to the serial pool with its UI scenarios', () => {
		const entry = REQUIRED_SPECS.find(row => row.file === 'family-rewards.spec.ts')
		expect(entry).toBeDefined()
		expect(entry.project).toBe('chromium-serial-db')
		expect(entry.minTests).toBeGreaterThanOrEqual(9)
		expect(entry.scenarios.length).toBeGreaterThanOrEqual(8)
	})

	it.each(['family-membership-hardening.spec.ts', 'settings.spec.ts', 'setup-wizard-journey.spec.ts'])('requires exclusive serial discovery and first-attempt evidence for %s', file => {
		const entry = REQUIRED_SPECS.find(row => row.file === file)
		expect(entry).toBeDefined()
		expect(entry.project).toBe('chromium-serial-db')
		expect(entry.minTests).toBeGreaterThanOrEqual(file.startsWith('family-') ? 3 : file.startsWith('setup-') ? 5 : 8)
		if (file.startsWith('setup-')) expect(entry.scenarios).toEqual(expect.arrayContaining(['six-floor house cap', 'six-floor apartment cap']))
		expect(checkSelection([], [entry], {})).not.toEqual([])
		const rows = collectSpecs(reportFor(entry).suites)
		expect(checkSelection(rows.map(row => ({ ...row, projectName: 'chromium' })), [entry], {})).not.toEqual([])
		expect(checkResults({ suites: [] }, [entry])).not.toEqual([])
	})
	it('pins the real wizard inventory in canonical discovery order', () => {
		const file = 'setup-wizard-journey.spec.ts'
		const inventory = require('../../e2e/required-scenarios.json')[file]
		expect(inventory).toHaveLength(5)
		expect(inventory).toEqual([...inventory].sort())
	})

	it('uses direct Node execution and fails on spawn errors or signals', () => {
		spawnSync.mockReturnValue({ status: null, error: new Error('spawn failed') })
		expect(runPlaywright([]).status).toBe(1)
		expect(spawnSync.mock.calls.at(-1)[0]).toBe(process.execPath)
		spawnSync.mockReturnValue({ status: null, signal: 'SIGTERM' })
		expect(runPlaywright([]).status).toBe(1)
	})
	it('the actual main pipeline rejects a missing journey even with zero process exits', () => {
		const reports = REQUIRED_SPECS.map(entry => reportFor(entry))
		const inventory = Object.fromEntries(reports.map(report => [report.suites[0].file, collectSpecs(report.suites).map(row => row.identity).sort()]))
		// The gate logs its (expected) selection violations to the console;
		// the assertion is the exit code, so the log noise is silenced —
		// formatting dozens of buffered violation lines is not what this
		// test exercises.
		jest.spyOn(console, 'log').mockImplementation(() => {})
		jest.spyOn(console, 'error').mockImplementation(() => {})
		jest.spyOn(fs, 'mkdirSync').mockImplementation(() => {})
		jest.spyOn(fs, 'writeFileSync').mockImplementation(() => {})
		jest.spyOn(fs, 'mkdtempSync').mockReturnValue('build/e2e-gate/review-fixture')
		jest.spyOn(fs, 'readFileSync').mockImplementation(file => {
			if (String(file).endsWith('required-scenarios.json')) return JSON.stringify(inventory)
			const project = ['chromium', 'chromium-serial-db', 'chromium-scheduling-owner'].find(name => String(file).endsWith(`report-${name}.json`))
			return JSON.stringify({
				suites: reports.flatMap(report => report.suites).filter(suite => suite.file !== required.file
				&& ['setup', project].includes(suite.specs[0].tests[0].projectName)),
			})
		})
		spawnSync.mockImplementation((_command, args) => {
			const project = args.find(arg => arg.startsWith('--project=')).slice(10)
			return {
				status: 0,
				stdout: Buffer.from(JSON.stringify({
					suites: reports.flatMap(report => report.suites)
						.filter(suite => suite.specs[0].tests[0].projectName === project),
				})),
				stderr: Buffer.alloc(0),
			}
		})
		const argv = process.argv
		process.argv = ['node', 'run-e2e-gate.js']
		try { expect(main()).toBe(1) } finally { process.argv = argv }
	})
})
