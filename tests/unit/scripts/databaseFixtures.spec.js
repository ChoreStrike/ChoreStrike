const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const ts = require('typescript')

function loadFixtures(fetch) {
	const code = fs.readFileSync(path.resolve(__dirname, '../../e2e/fixtures/database-fixtures.ts'), 'utf8')
	const exports = {}
	const test = { extend: jest.fn(fixtures => fixtures) }
	vm.runInNewContext(ts.transpileModule(code, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText,
		{ exports, require: () => ({ test, expect }), process, Buffer, fetch, console })
	return exports
}

describe('destructive fixture ownership', () => {
	it.each(['chromium', 'firefox', 'webkit'])('refuses direct seed and reset from %s before a request', async project => {
		const fetch = jest.fn()
		const { DatabaseManager } = loadFixtures(fetch)
		const manager = new DatabaseManager('http://localhost:8080', project)
		await expect(manager.seed('family')).rejects.toThrow('Pool guard')
		await expect(manager.reset()).rejects.toThrow('Pool guard')
		expect(fetch).not.toHaveBeenCalled()
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
