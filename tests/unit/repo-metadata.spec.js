/** @jest-environment node */
const { execFileSync } = require('node:child_process')
const fs = require('node:fs')
const path = require('node:path')

/**
 * Repository identity and metadata guard (CS-PLAN-085).
 *
 * The project history was consolidated onto a single contributor identity and
 * moved to the GitHub organization. This guard keeps that state from eroding:
 * no tracked file may reintroduce the retired forge identity, and the public
 * metadata files must keep pointing at the canonical repository.
 *
 * Two deliberate design choices:
 *
 *   1. **Patterns are assembled from fragments.** A guard that spells out the
 *      strings it bans either matches itself and can never pass, or becomes
 *      the one tracked file that reintroduces them (CS-PLAN-085 findings
 *      A-03/A-08). Joining fragments at runtime keeps the forbidden literals
 *      out of the repository entirely. Do not "simplify" these into plain
 *      string literals.
 *   2. **The bare forge name is allowed.** `Codeberg` as a word records
 *      factual provenance in the changelog, the ADR and archived records
 *      (CS-PLAN-085 §2). Only a `<host>/<user>/` URL path, the retired
 *      username and the retired addresses are forbidden. Do not tighten this
 *      into a bare-word ban — it would fail on legitimate history.
 */

const ROOT = path.resolve(__dirname, '../..')
const SELF = 'tests/unit/repo-metadata.spec.js'

const CANONICAL_URL = 'https://github.com/ChoreStrike/ChoreStrike'

/** Files whose public metadata must name the canonical repository. */
const MUST_REFERENCE_CANONICAL = [
	'appinfo/info.xml',
	'package.json',
	'README.md',
	'docs/CONTRIBUTING.md',
]

/**
 * Escape a literal string for use inside a regular expression.
 *
 * @param {string} value raw string
 * @return {string} regex-safe string
 */
const escapeRe = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

// Fragment-assembled tokens — see the header note, choice 1.
const LEGACY_USER = ['Willy', 'Wompel'].join('')
const EARLY_DOMAIN = ['pun', 'to', '.de'].join('')
const FORGE_HOST = ['codeberg', '.org'].join('')

/** Forbidden patterns, each with the reason it is banned. */
const FORBIDDEN = [
	{
		label: 'retired contributor username',
		pattern: new RegExp(escapeRe(LEGACY_USER), 'i'),
	},
	{
		label: 'early personal contributor domain',
		pattern: new RegExp(escapeRe(EARLY_DOMAIN), 'i'),
	},
	{
		label: 'retired forge noreply address',
		pattern: new RegExp(`${escapeRe(LEGACY_USER)}@[\\w.-]*${escapeRe(FORGE_HOST)}`, 'i'),
	},
	{
		label: 'retired forge repository URL',
		pattern: new RegExp(`${escapeRe(FORGE_HOST)}/[\\w.-]+/`, 'i'),
	},
]

/**
 * List the repository's tracked files.
 *
 * @return {string[]} repo-relative paths
 */
function trackedFiles() {
	return execFileSync('git', ['ls-files', '-z'], { cwd: ROOT, maxBuffer: 64 * 1024 * 1024 })
		.toString('utf8')
		.split('\0')
		.filter(Boolean)
}

/**
 * Read a tracked file as text, or return null when it is binary.
 *
 * @param {string} relPath repo-relative path
 * @return {string|null} file text, or null for binary content
 */
function readText(relPath) {
	const buffer = fs.readFileSync(path.join(ROOT, relPath))
	if (buffer.includes(0)) {
		return null
	}
	return buffer.toString('utf8')
}

describe('repository identity and metadata', () => {
	const files = trackedFiles().filter((relPath) => relPath !== SELF)

	it('tracks files to scan', () => {
		expect(files.length).toBeGreaterThan(100)
	})

	it.each(FORBIDDEN)('no tracked file contains the $label', ({ pattern }) => {
		const hits = []
		for (const relPath of files) {
			const text = readText(relPath)
			if (text === null) {
				continue
			}
			const line = text.split('\n').findIndex((candidate) => pattern.test(candidate))
			if (line !== -1) {
				hits.push(`${relPath}:${line + 1}`)
			}
		}

		expect(hits).toEqual([])
	})

	it.each(MUST_REFERENCE_CANONICAL)('%s names the canonical repository', (relPath) => {
		expect(files).toContain(relPath)
		expect(readText(relPath)).toContain(CANONICAL_URL)
	})
})
