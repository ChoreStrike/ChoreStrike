/** @jest-environment node */
/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * TECH-TEST-007: applied migrations are immutable since the first retained
 * baseline. The real check runs against lib/Migration; the negative cases use
 * synthetic temp directories and never touch a real migration file.
 */

const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { INVENTORY_PATH, gitContentSha256, loadAppliedMigrations, checkAppliedMigrations } = require('../../../scripts/applied-migrations.js')
const { sha256Hex } = require('../../../scripts/build-package.js')

const ROOT = path.resolve(__dirname, '../../..')
const ID = '1000Date20260224000000'
const NAME = `Version${ID}.php`
const CONTENT = '<?php\n\nclass Version1000Date20260224000000 {}\n'
let dir = null

/** Ordered `[id, sha256]` rows of the adoption record table in deployment.md. */
function recordedRows() {
	const text = fs.readFileSync(path.join(ROOT, 'docs/engineering/deployment.md'), 'utf8').replace(/\r\n/g, '\n')
	const section = text.split('### Adoption Record (2026-10-17)')[1].split('\n### ')[0]
	return [...section.matchAll(/^\| \d+ \| `(\w+)` \| `([a-f0-9]{64})` \|$/gm)].map(match => [match[1], match[2]])
}

function write(name, content) {
	fs.writeFileSync(path.join(dir, name), content)
}

describe('applied migration immutability guard', () => {
	beforeEach(() => {
		dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-applied-migrations-'))
	})
	afterEach(() => {
		fs.rmSync(dir, { recursive: true, force: true })
		dir = null
	})

	it('pins the 0.8.0-alpha.1 adoption record exactly', () => {
		const first = JSON.parse(fs.readFileSync(INVENTORY_PATH, 'utf8')).adoptions[0]
		expect(first).toMatchObject({ version: '0.8.0-alpha.1', adopted: '2026-10-17' })
		expect(recordedRows()).toHaveLength(26)
		expect(first.migrations.map(migration => [migration.id, migration.sha256])).toEqual(recordedRows())
	})

	it('finds every applied migration unchanged in lib/Migration', () => {
		expect(checkAppliedMigrations(loadAppliedMigrations())).toEqual([])
	})

	it('hashes the stored LF content, so a CRLF checkout matches its blob', () => {
		expect(gitContentSha256(Buffer.from('a\r\nb\n'))).toBe(sha256Hex(Buffer.from('a\nb\n')))
		expect(gitContentSha256(Buffer.from('a\rb\n'))).toBe(sha256Hex(Buffer.from('a\rb\n')))
		write(NAME, CONTENT.replace(/\n/g, '\r\n'))
		expect(checkAppliedMigrations([{ id: ID, sha256: sha256Hex(Buffer.from(CONTENT)) }], dir)).toEqual([])
	})

	it('reports an edited applied migration as immutable', () => {
		write(NAME, CONTENT + '// edited\n')
		const [violation, ...rest] = checkAppliedMigrations([{ id: ID, sha256: sha256Hex(Buffer.from(CONTENT)) }], dir)
		expect(rest).toEqual([])
		expect(violation).toContain(`${NAME} changed`)
		expect(violation).toContain('applied migrations are immutable')
		expect(violation).toContain('new ordered migration step')
		expect(violation).toContain('project-rules.md §1')
	})

	it('reports a removed or renamed applied migration and still allows new steps', () => {
		write('Version1034Date20261020000000.php', CONTENT)
		const pinned = [{ id: ID, sha256: sha256Hex(Buffer.from(CONTENT)) }]
		expect(checkAppliedMigrations(pinned, dir)).toEqual([expect.stringContaining(`${NAME} is missing (removed or renamed)`)])
		write(NAME, CONTENT)
		expect(checkAppliedMigrations(pinned, dir)).toEqual([])
	})

	it('rejects a malformed or duplicate inventory entry', () => {
		const inventory = migrations => JSON.stringify({ adoptions: [{ version: '0.8.0-alpha.1', adopted: '2026-10-17', migrations }] })
		const entry = { id: ID, sha256: 'a'.repeat(64) }
		write('inventory.json', inventory([entry, entry]))
		expect(() => loadAppliedMigrations(path.join(dir, 'inventory.json'))).toThrow(`duplicate migration ${ID}`)
		write('inventory.json', inventory([{ id: ID, sha256: 'not-a-hash' }]))
		expect(() => loadAppliedMigrations(path.join(dir, 'inventory.json'))).toThrow('malformed entry')
		write('inventory.json', JSON.stringify({ adoptions: [] }))
		expect(() => loadAppliedMigrations(path.join(dir, 'inventory.json'))).toThrow('no adoptions recorded')
	})
})
