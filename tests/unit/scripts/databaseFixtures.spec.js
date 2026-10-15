const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const ts = require('typescript')

function loadFixtures(fetch, assertTestTarget = jest.fn(url => ({ url: url.replace(/\/$/, '') }))) {
	const code = fs.readFileSync(path.resolve(__dirname, '../../e2e/fixtures/database-fixtures.ts'), 'utf8')
	const exports = {}
	const test = { extend: jest.fn(fixtures => fixtures) }
	vm.runInNewContext(ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText,
		{ exports, require: name => name.includes('test-target') ? { assertTestTarget } : { test, expect }, process, Buffer, fetch, console })
	return exports
}

describe('destructive fixture ownership', () => {
	it('constructs seed/reset requests from the normalized preflight origin', async () => {
		const fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: {} }) })
		const { DatabaseManager } = loadFixtures(fetch, jest.fn(() => ({ url: 'http://127.0.0.1:8082' })))
		const manager = new DatabaseManager('http://127.0.0.1:8082/', 'chromium-serial-db')
		await manager.seed('family')
		await manager.reset()
		expect(fetch.mock.calls.map(([url]) => url)).toEqual([
			'http://127.0.0.1:8082/apps/chorestrike/api/v1/test/seed/family',
			'http://127.0.0.1:8082/apps/chorestrike/api/v1/test/reset',
		])
	})
	it.each(['seed', 'reset'])('refuses %s on a mismatched target before sending a mutation', async operation => {
		const fetch = jest.fn()
		const assertTestTarget = jest.fn(() => { throw new Error('Test target preflight failed') })
		const { DatabaseManager } = loadFixtures(fetch, assertTestTarget)
		const manager = new DatabaseManager('http://localhost:8082', 'chromium-serial-db')
		await expect(manager[operation]('family')).rejects.toThrow('Test target preflight failed')
		expect(assertTestTarget).toHaveBeenCalledWith('http://localhost:8082')
		expect(fetch).not.toHaveBeenCalled()
	})
	it.each(['chromium', 'firefox', 'webkit'])('refuses direct seed and reset from %s before a request', async project => {
		const fetch = jest.fn()
		const { DatabaseManager } = loadFixtures(fetch)
		const manager = new DatabaseManager('http://localhost:8080', project)
		await expect(manager.seed('family')).rejects.toThrow('Pool guard')
		await expect(manager.reset()).rejects.toThrow('Pool guard')
		expect(fetch).not.toHaveBeenCalled()
	})
	// CS-PLAN-084 WP-G: the serial phone projects own the database while the gate runs them.
	it.each(['mobile-chromium', 'mobile-webkit'])('allows the phone project %s to seed', async project => {
		const fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: { profile: 'family' } }) })
		const { DatabaseManager } = loadFixtures(fetch)
		await expect(new DatabaseManager('http://localhost:8080', project).seed('family')).resolves.toEqual({ profile: 'family' })
		expect(fetch).toHaveBeenCalledTimes(1)
	})
	it('allows the serial owner and reports only sanitized errors', async () => {
		const fetch = jest.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: { profile: 'family' } }) })
		const { DatabaseManager, test } = loadFixtures(fetch)
		const manager = new DatabaseManager('http://localhost:8080', 'chromium-serial-db')
		await expect(manager.seed('family')).resolves.toEqual({ profile: 'family' })
		fetch.mockResolvedValue({ ok: false, status: 500, text: async () => 'sensitive-body' })
		await expect(manager.seed('family')).rejects.toThrow('HTTP 500')
		await expect(test.seededData({ databaseProfile: 'family', databaseManager: manager }, jest.fn(),
			{ project: { name: 'chromium-serial-db' }, title: 'required case' })).rejects.toThrow('Test mode unavailable')
	})
})
