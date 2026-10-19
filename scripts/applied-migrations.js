/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * TECH-TEST-007: applied-migration immutability guard.
 *
 * Since the first retained baseline (`0.8.0-alpha.1`, adopted 2026-10-17)
 * every applied migration file is immutable (project-rules §1 Data-Retention
 * Boundary). `tests/migrations/applied-migrations.json` pins each applied step
 * with the SHA-256 from its adoption record; each later adoption appends its
 * newly applied steps. A pinned file that is edited, renamed or removed is a
 * violation; new, not yet applied `Version*.php` files stay allowed.
 *
 * Hashes cover the content Git stores, not the checkout: `* text=auto eol=lf`
 * turns CRLF into LF on commit, so a CRLF working copy hashes like its blob.
 *
 * Enforced in `npm test` by tests/unit/scripts/appliedMigrations.spec.js.
 */
'use strict'

const fs = require('node:fs')
const path = require('node:path')
const { sha256Hex } = require('./build-package.js')

const ROOT = path.resolve(__dirname, '..')
const INVENTORY_PATH = path.join(ROOT, 'tests', 'migrations', 'applied-migrations.json')
const MIGRATION_DIR = path.join(ROOT, 'lib', 'Migration')
const MIGRATION_ID = /^\d{4}Date\d{14}$/
const SHA256 = /^[a-f0-9]{64}$/
const RULE = 'applied migrations are immutable; change the schema with a new ordered migration step '
	+ '(docs/standards/ai/rules/project-rules.md §1 Data-Retention Boundary)'

/**
 * SHA-256 of the bytes Git stores: CRLF normalized to LF, binary-safe.
 *
 * @param {Buffer} data Working-tree file content.
 * @return {string} Lowercase hex digest.
 */
function gitContentSha256(data) {
	return sha256Hex(Buffer.from(data.toString('latin1').replace(/\r\n/g, '\n'), 'latin1'))
}

/**
 * Read and shape-check the pinned inventory.
 *
 * @param {string} file Inventory JSON path.
 * @return {Array<{id: string, sha256: string}>} Applied steps of all adoptions in order.
 */
function loadAppliedMigrations(file = INVENTORY_PATH) {
	const inventory = JSON.parse(fs.readFileSync(file, 'utf8'))
	if (!Array.isArray(inventory.adoptions) || !inventory.adoptions.length) throw new Error(`${file}: no adoptions recorded`)
	const migrations = []
	for (const adoption of inventory.adoptions) {
		if (!adoption.version || !/^\d{4}-\d{2}-\d{2}$/.test(adoption.adopted ?? '') || !Array.isArray(adoption.migrations)) {
			throw new Error(`${file}: adoption ${adoption.version ?? '?'} needs a version, an adopted date and migrations`)
		}
		for (const migration of adoption.migrations) {
			if (!MIGRATION_ID.test(migration.id ?? '') || !SHA256.test(migration.sha256 ?? '')) throw new Error(`${file}: malformed entry ${JSON.stringify(migration)}`)
			if (migrations.some(known => known.id === migration.id)) throw new Error(`${file}: duplicate migration ${migration.id}`)
			migrations.push({ id: migration.id, sha256: migration.sha256 })
		}
	}
	return migrations
}

/**
 * Compare the pinned migrations with the files in `dir`.
 *
 * @param {Array<{id: string, sha256: string}>} migrations Pinned applied steps.
 * @param {string} dir Directory holding the `Version<id>.php` files.
 * @return {string[]} Violations; empty when every pinned file is intact.
 */
function checkAppliedMigrations(migrations, dir = MIGRATION_DIR) {
	const violations = []
	for (const { id, sha256 } of migrations) {
		const name = `Version${id}.php`
		let data
		try {
			data = fs.readFileSync(path.join(dir, name))
		} catch (error) {
			if (error.code !== 'ENOENT') throw error
			violations.push(`${name} is missing (removed or renamed): ${RULE}`)
			continue
		}
		const actual = gitContentSha256(data)
		if (actual !== sha256) violations.push(`${name} changed (SHA-256 ${actual}, pinned ${sha256}): ${RULE}`)
	}
	return violations
}

module.exports = { INVENTORY_PATH, MIGRATION_DIR, gitContentSha256, loadAppliedMigrations, checkAppliedMigrations }
