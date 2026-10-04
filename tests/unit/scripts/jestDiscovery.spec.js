const fs = require('node:fs')
const path = require('node:path')
const { spawnSync } = require('node:child_process')

// TECH-JEST-001 (CS-PLAN-081 H1): testPathIgnorePatterns keeps worktree tests
// out of the run list, but without a discovery-level exclusion the haste map
// still indexes manual mocks inside worktrees and warns about duplicates.
// This spec plants a synthetic nested worktree fixture (never a real one),
// runs a small canonical suite against it and asserts the duplicate-mock
// warnings stay gone while the canonical mocks still resolve.
const ROOT = path.resolve(__dirname, '../../..')
const WORKTREES_DIR = path.join(ROOT, '.kilo', 'worktrees')
let fixtureWorktree = null
const CANONICAL_MOCKS_DIR = path.join(ROOT, 'tests', 'unit', '__mocks__')
const PROBE_SUITE = 'tests/unit/scripts/localizationGate.spec.js'

const runJest = args => {
	// The probe runs in-band: spawned worker processes would otherwise keep
	// pipes open past the child's exit and make the outer Jest report open
	// handles.
	const result = spawnSync(process.execPath, [path.join(ROOT, 'node_modules', 'jest', 'bin', 'jest.js'), '--runInBand', ...args], {
		cwd: ROOT,
		encoding: 'utf8',
		timeout: 180000,
	})
	return result
}

const plantFixture = () => {
	if (!fs.existsSync(WORKTREES_DIR)) fs.mkdirSync(WORKTREES_DIR, { recursive: true })
	fixtureWorktree = fs.mkdtempSync(path.join(WORKTREES_DIR, 'synthetic-jest-discovery-fixture-'))
	const mocksDir = path.join(fixtureWorktree, 'tests', 'unit', '__mocks__')
	fs.mkdirSync(mocksDir, { recursive: true })
	// Duplicate canonical manual mocks exactly where a real worktree copy
	// would place them; these are the duplicates haste must not index.
	for (const name of ['nextcloud-axios.js', 'svg.js', 'icons.js']) {
		fs.copyFileSync(path.join(CANONICAL_MOCKS_DIR, name), path.join(mocksDir, name))
	}
}

const removeFixture = () => {
	if (fixtureWorktree === null) return
	// mkdtemp, not an existing fixed-name path, establishes ownership.
	if (path.dirname(fixtureWorktree) !== WORKTREES_DIR
		|| !path.basename(fixtureWorktree).startsWith('synthetic-jest-discovery-fixture-')) throw new Error('refusing to remove unexpected path')
	// Windows indexers can briefly retain handles after the probe exits.
	fs.rmSync(fixtureWorktree, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 })
	fixtureWorktree = null
}

describe('jest worktree mock discovery exclusion', () => {
	afterEach(() => {
		removeFixture()
	})

	it('pins the discovery-level exclusion in jest.config.js', () => {
		const configSource = fs.readFileSync(path.join(ROOT, 'jest.config.js'), 'utf8')
		expect(configSource).toContain('modulePathIgnorePatterns')
		expect(configSource).toContain('<rootDir>.[.]kilo.worktrees.')
	})

	it('does not remove any worktree without an owned temporary fixture', () => {
		const remove = jest.spyOn(fs, 'rmSync')
		try {
			removeFixture()
			expect(remove).not.toHaveBeenCalled()
		} finally {
			remove.mockRestore()
		}
	})

	it('does not index duplicate worktree mocks and keeps canonical mocks working', () => {
		plantFixture()
		const ownedPath = fixtureWorktree
		try {
			const result = runJest([PROBE_SUITE])
			expect(result.error).toBeUndefined()
			expect(result.signal).toBeNull()
			expect(result.status).toBe(0)
			const output = `${result.stdout || ''}\n${result.stderr || ''}`
			expect(output).not.toMatch(/duplicate manual mock found/)
			expect(output).not.toMatch(/Haste module naming collision/)
			// The probe suite exercises t()/l10n code that resolves through the
			// canonical manual mocks — its green run proves the canonical mock
			// mappings still work with a worktree fixture present.
			expect(output).toMatch(/Tests:\s+13 passed, 13 total/)
		} finally {
			removeFixture()
		}
		expect(fs.existsSync(ownedPath)).toBe(false)
	})
})
