#!/usr/bin/env node

/**
 * Backlog structural gate (CS-PLAN-059 WP0; CS-PLAN-058 §2.2/§4.5).
 *
 * Keeps `docs/backlog/backlog.md` an executable, dependency-oriented program
 * inventory. Five deterministic checks:
 *
 *   1. Open items only: no entry whose Status line matches
 *      Resolved/Closed remains in backlog.md (completed items belong in the
 *      monthly archive).
 *   2. No duplicate IDs: every `### [P*] ID — Title` heading ID appears at
 *      most once in backlog.md.
 *   3. Required metadata: every open entry carries all nine standardized
 *      fields (Status, Effort, Layer, Journey stage, Owning, Depends on,
 *      Blocks, Folded-in, Source) from the CS-PLAN-058 §2.2 metadata block.
 *      `—` is the explicit empty value and satisfies presence.
 *   4. Resolvable dependency path: every Owning / Depends on / Blocks /
 *      Folded-in reference resolves — backlog IDs against the open IDs of
 *      backlog.md (Folded-in additionally against the monthly archive
 *      histories), `CS-PLAN-NNN` plan IDs against docs/plans/**, and WP
 *      tokens against WP0..WP9. ID ranges (`CR-WF-03..08`, `WP1..WP9`) are
 *      expanded. This keeps the CR-WF-01..08 → RELEASE-002 critical path
 *      machine-checkable.
 *   5. Tracked links resolve: every relative Markdown link in
 *      docs/backlog/*.md (top level) and all docs/plans markdown files
 *      points to an existing file (resolved relative to the containing
 *      file, must stay inside the repo root). External URLs and pure
 *      `#anchor` fragments are not validated; anchor suffixes on file
 *      links are ignored (ratified scope, CS-PLAN-059 D4).
 *
 * Usage:
 *   node scripts/check-backlog.js              # scan git-tracked files
 *   node scripts/check-backlog.js --self-test  # verify the checks
 */

'use strict'

const { execFileSync } = require('child_process')
const { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } = require('fs')
const { tmpdir } = require('os')
const { dirname, join, resolve, sep } = require('path')

const SCRIPT_NAME = 'check-backlog'
const BACKLOG_PATH = 'docs/backlog/backlog.md'
const ARCHIVE_DIR = 'docs/backlog/archive'

/**
 * Standardized metadata fields (CS-PLAN-058 §2.2). All are required on every
 * open entry.
 *
 * @type {string[]}
 */
const REQUIRED_FIELDS = [
	'Status',
	'Effort',
	'Layer',
	'Journey stage',
	'Owning',
	'Depends on',
	'Blocks',
	'Folded-in',
	'Source',
]

/**
 * Fields whose values are scanned for dependency references (check 4).
 *
 * @type {string[]}
 */
const REFERENCE_FIELDS = ['Owning', 'Depends on', 'Blocks', 'Folded-in']

/**
 * Valid work-package tokens (CS-PLAN-058 WP0..WP9).
 */
const MAX_WORK_PACKAGE = 9

/**
 * Entry heading: `### [P0-4] ID — Title`. The ID is an uppercase backlog key
 * with a numeric suffix (e.g. CR-WF-01, BUG-E2E-005, RELEASE-002).
 */
const ENTRY_HEADING_RE = /^### \[P\d\] ([A-Z][A-Z0-9]*(?:-[A-Za-z0-9]+)*-\d+) — (.+)$/

/**
 * Range references inside metadata values: `CR-WF-03..08` (backlog IDs) and
 * `WP1..WP9` (work packages).
 */
const ID_RANGE_RE = /\b([A-Z][A-Z0-9]*(?:-[A-Z0-9]+)*-)(\d+)\.\.(\d+)\b/g
const WP_RANGE_RE = /\bWP(\d{1,2})\.\.WP(\d{1,2})\b/g
const WP_SINGLE_RE = /\bWP(\d{1,2})\b/g
const PLAN_ID_RE = /\bCS-PLAN-\d+\b/g
const ID_SINGLE_RE = /\b([A-Z][A-Z0-9]*(?:-[A-Z0-9]+)*-\d+)\b/g
const MARKDOWN_LINK_RE = /\[[^\]]*\]\(([^)\s]+)\)/g

/**
 * One parsed `###` entry of backlog.md.
 *
 * @typedef {object} Entry
 * @property {string} id Entry ID (e.g. `CR-WF-01`).
 * @property {number} line 1-based line of the heading.
 * @property {string} text Full entry text (heading through the line before
 *   the next heading).
 */

/**
 * Split backlog text into entries at `### [P*] ID — Title` headings.
 *
 * @param {string} text Raw backlog.md content.
 * @returns {Entry[]} Parsed entries in document order.
 */
function parseEntries(text) {
	const lines = text.split(/\r?\n/)
	const entries = []
	let current = null
	for (let i = 0; i < lines.length; i++) {
		const match = ENTRY_HEADING_RE.exec(lines[i])
		if (match) {
			if (current) {
				current.text = lines.slice(current.startIdx, i).join('\n')
				entries.push(current)
			}
			current = { id: match[1], line: i + 1, startIdx: i, text: '' }
		}
	}
	if (current) {
		current.text = lines.slice(current.startIdx).join('\n')
		entries.push(current)
	}
	return entries
}

/**
 * Extract the standardized `**Field**: value` fields of one entry. A field's
 * value runs to the next `**...**:` label on the same line or to the line end.
 *
 * @param {string} entryText Raw entry text.
 * @returns {Map<string, {value: string, line: number}>} Fields keyed by label
 *   with their value and 1-based line (relative offset applied by caller —
 *   this is the line within the entry text, starting at 1 for the heading).
 */
function extractFields(entryText) {
	const lines = entryText.split(/\r?\n/)
	const fields = new Map()
	const labelRe = /\*\*([^*]+)\*\*:/g
	for (let i = 0; i < lines.length; i++) {
		const labels = []
		labelRe.lastIndex = 0
		let match
		while ((match = labelRe.exec(lines[i])) !== null) {
			labels.push({ label: match[1].trim(), labelStart: match.index, valueStart: match.index + match[0].length })
		}
		for (let j = 0; j < labels.length; j++) {
			const { label, labelStart, valueStart } = labels[j]
			if (fields.has(label)) {
				continue
			}
			const end = j + 1 < labels.length ? labels[j + 1].labelStart : lines[i].length
			const value = lines[i].slice(valueStart, end).trim().replace(/·\s*$/, '').trim()
			fields.set(label, { value, line: i + 1 })
		}
	}
	return fields
}

/**
 * Expand `PREFIXxx..yy` numeric ID ranges into explicit IDs, preserving the
 * zero-pad width of the range start (`CR-WF-03..08` → CR-WF-03..CR-WF-08).
 *
 * @param {string} value Field value.
 * @yields {string} Expanded IDs.
 */
function expandIdRanges(value) {
	const ids = []
	ID_RANGE_RE.lastIndex = 0
	let match
	while ((match = ID_RANGE_RE.exec(value)) !== null) {
		const prefix = match[1]
		const width = match[2].length
		const start = parseInt(match[2], 10)
		const end = parseInt(match[3], 10)
		if (end >= start && end - start <= 100) {
			for (let n = start; n <= end; n++) {
				ids.push(prefix + String(n).padStart(width, '0'))
			}
		}
	}
	return ids
}

/**
 * Extract every dependency reference from one metadata value.
 *
 * @param {string} value Raw field value.
 * @returns {{wpTokens: string[], planIds: string[], backlogIds: string[]}}
 */
function extractReferences(value) {
	const wpTokens = []
	WP_RANGE_RE.lastIndex = 0
	let match
	while ((match = WP_RANGE_RE.exec(value)) !== null) {
		for (let n = parseInt(match[1], 10); n <= parseInt(match[2], 10); n++) {
			wpTokens.push(n)
		}
	}
	WP_SINGLE_RE.lastIndex = 0
	while ((match = WP_SINGLE_RE.exec(value)) !== null) {
		wpTokens.push(parseInt(match[1], 10))
	}

	const planIds = []
	PLAN_ID_RE.lastIndex = 0
	while ((match = PLAN_ID_RE.exec(value)) !== null) {
		planIds.push(match[0])
	}

	const backlogIds = expandIdRanges(value)
	ID_SINGLE_RE.lastIndex = 0
	while ((match = ID_SINGLE_RE.exec(value)) !== null) {
		if (/^CS-PLAN-\d+$/.test(match[0])) {
			continue
		}
		if (value.slice(match.index, match.index + match[0].length + 2).includes('..')) {
			continue
		}
		backlogIds.push(match[0])
	}
	return { wpTokens, planIds, backlogIds }
}

/**
 * Validate work-package tokens are within WP0..WP9.
 *
 * @param {number[]} tokens Parsed work-package numbers.
 * @returns {number[]} Out-of-range tokens.
 */
function invalidWorkPackages(tokens) {
	return tokens.filter((n) => Number.isNaN(n) || n < 0 || n > MAX_WORK_PACKAGE)
}

/**
 * Run the backlog structural checks (1–4) on backlog.md content.
 *
 * @param {string} text Raw backlog.md content.
 * @param {object} index Resolution targets.
 * @param {Set<string>} index.openIds Open entry IDs of backlog.md.
 * @param {Set<string>} index.archiveIds IDs appearing in the monthly archive.
 * @param {Set<string>} index.planIds Known plan IDs (from docs/plans/**).
 * @returns {string[]} Findings as `line: message` (1-based backlog.md lines).
 */
function scanBacklog(text, index) {
	const findings = []
	const entries = parseEntries(text)
	const seen = new Map()

	for (const entry of entries) {
		if (seen.has(entry.id)) {
			findings.push(`${entry.line}: duplicate entry ID ${entry.id} (first occurrence at line ${seen.get(entry.id)})`)
		} else {
			seen.set(entry.id, entry.line)
		}

		const fields = extractFields(entry.text)
		const lineOffset = entry.line - 1

		const status = fields.get('Status')
		if (status && /resolved|closed/i.test(status.value)) {
			findings.push(`${status.line + lineOffset}: entry ${entry.id} is marked "${status.value}" — resolved/closed items belong in the monthly archive, not backlog.md`)
		}

		for (const field of REQUIRED_FIELDS) {
			const data = fields.get(field)
			if (!data || data.value.length === 0) {
				findings.push(`${entry.line}: entry ${entry.id} is missing the **${field}** metadata field (CS-PLAN-058 §2.2)`)
			}
		}

		for (const field of REFERENCE_FIELDS) {
			const data = fields.get(field)
			if (!data || data.value.length === 0 || data.value === '—') {
				continue
			}
			const refs = extractReferences(data.value)
			for (const bad of invalidWorkPackages(refs.wpTokens)) {
				findings.push(`${data.line + lineOffset}: ${entry.id} ${field} references WP${bad} — valid work packages are WP0..WP9`)
			}
			for (const planId of refs.planIds) {
				if (!index.planIds.has(planId)) {
					findings.push(`${data.line + lineOffset}: ${entry.id} ${field} references ${planId} — no such plan file under docs/plans/`)
				}
			}
			const allowedIds = field === 'Folded-in'
				? new Set([...index.openIds, ...index.archiveIds])
				: index.openIds
			for (const id of refs.backlogIds) {
				if (!allowedIds.has(id)) {
					const hint = index.archiveIds.has(id) && field !== 'Folded-in'
						? ' (exists in the monthly archive only — archived IDs cannot be open dependencies)'
						: ''
					findings.push(`${data.line + lineOffset}: ${entry.id} ${field} reference ${id} does not resolve to an open backlog entry${hint}`)
				}
			}
		}
	}
	return findings
}

/**
 * Collect the IDs of all resolved/closed entries currently in backlog.md
 * (check 1/2 helpers also need the open-ID set, which is derived here).
 *
 * @param {string} text Raw backlog.md content.
 * @returns {Set<string>} Open entry IDs.
 */
function collectOpenIds(text) {
	return new Set(parseEntries(text).map((entry) => entry.id))
}

/**
 * Collect backlog IDs mentioned anywhere in the monthly archive files —
 * `Folded-in` pointers may target an archived completion record.
 *
 * @param {Map<string, string>} archiveFiles Archive rel paths → content.
 * @returns {Set<string>} IDs appearing in the archive.
 */
function collectArchiveIds(archiveFiles) {
	const ids = new Set()
	const idRe = /\b([A-Z][A-Z0-9]*(?:-[A-Za-z0-9]+)*-\d+)\b/g
	for (const content of archiveFiles.values()) {
		let match
		idRe.lastIndex = 0
		while ((match = idRe.exec(content)) !== null) {
			if (/^CS-PLAN-\d+$/.test(match[0])) {
				continue
			}
			ids.add(match[0])
		}
	}
	return ids
}

/**
 * Derive plan IDs (CS-PLAN-NNN) from tracked paths under docs/plans/.
 *
 * @param {string[]} relPaths Tracked file paths.
 * @returns {Set<string>} Plan IDs.
 */
function collectPlanIds(relPaths) {
	const ids = new Set()
	const idRe = /cs-plan-(\d+)/gi
	for (const relPath of relPaths) {
		let match
		idRe.lastIndex = 0
		while ((match = idRe.exec(relPath)) !== null) {
			ids.add(`CS-PLAN-${match[1]}`)
		}
	}
	return ids
}

/**
 * Check relative Markdown links (check 5) of one file.
 *
 * @param {string} relPath Repo-relative file path (forward slashes).
 * @param {string} text Raw file content.
 * @param {string} rootDir Absolute repo root.
 * @returns {string[]} Findings as `line: message`.
 */
function scanLinks(relPath, text, rootDir) {
	const findings = []
	const lines = text.split(/\r?\n/)
	const baseDir = resolve(rootDir, dirname(relPath))
	for (let i = 0; i < lines.length; i++) {
		MARKDOWN_LINK_RE.lastIndex = 0
		let match
		while ((match = MARKDOWN_LINK_RE.exec(lines[i])) !== null) {
			const target = match[1]
			if (/^(https?:|mailto:|#)/i.test(target)) {
				continue
			}
			const filePart = target.split('#')[0]
			if (filePart.length === 0) {
				continue
			}
			const resolved = resolve(baseDir, filePart)
			if (resolved !== rootDir && !resolved.startsWith(rootDir + sep)) {
				findings.push(`${i + 1}: link target "${target}" escapes the repository root`)
				continue
			}
			if (!existsSync(resolved)) {
				findings.push(`${i + 1}: broken link target "${target}" (resolved: ${resolved})`)
			}
		}
	}
	return findings
}

/**
 * List all git-tracked files under the given pathspecs.
 *
 * @param {string[]} pathspecs Git pathspecs.
 * @returns {string[]}
 */
function listTrackedFiles(pathspecs) {
	const output = execFileSync('git', ['ls-files', '--', ...pathspecs], { encoding: 'utf8' })
	return output.split(/\r?\n/).filter((line) => line.length > 0)
}

/**
 * Self-test: build fixture trees in a temp directory and verify each check
 * rejects its violation class (resolved stub, duplicate ID, missing metadata
 * field, dangling dependency reference, broken link) while the clean fixture
 * stays green.
 *
 * @returns {number} Non-zero on any check misbehaviour.
 */
function selfTest() {
	const rootDir = mkdtempSync(join(tmpdir(), `${SCRIPT_NAME}-selftest-`))
	const cases = []
	try {
		const cleanEntry = [
			'## Section',
			'',
			'### [P1] CR-T-01 — Clean umbrella entry',
			'**Status**: Backlog · **Effort**: L · **Layer**: backend+frontend · **Journey stage**: testing',
			'**Owning**: CS-PLAN-058 WP2 · **Depends on**: — · **Blocks**: CR-T-02, CR-T-03..04 · **Folded-in**: —',
			'**Source**: Fixture review (2026-09-02)',
			'',
			'Body text.',
		].join('\n')
		const cleanEntry2 = [
			'### [P2] CR-T-02 — Clean dependent entry',
			'**Status**: Backlog · **Effort**: S · **Layer**: backend · **Journey stage**: persistence',
			'**Owning**: CS-PLAN-058 WP3 · **Depends on**: CR-T-01 · **Blocks**: — · **Folded-in**: CR-T-99',
			'**Source**: Fixture review (2026-09-02)',
		].join('\n')
		const cleanEntry3 = [
			'### [P3] CR-T-03 — Clean ranged entry',
			'**Status**: Backlog · **Effort**: XS · **Layer**: docs · **Journey stage**: documentation',
			'**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —',
			'**Source**: Fixture review (2026-09-02)',
		].join('\n')
		const cleanEntry4 = [
			'### [P3] CR-T-04 — Clean ranged entry four',
			'**Status**: Planned · **Effort**: XS · **Layer**: docs · **Journey stage**: documentation',
			'**Owning**: unassigned · **Depends on**: — · **Blocks**: — · **Folded-in**: —',
			'**Source**: Fixture review (2026-09-02)',
		].join('\n')
		const cleanBacklog = ['# Backlog', '', cleanEntry, '', cleanEntry2, '', cleanEntry3, '', cleanEntry4, ''].join('\n')

		cases.push({
			name: 'clean fixture is accepted',
			expectFindings: false,
			backlog: cleanBacklog,
			archive: ['# Archive', '', '- **CR-T-99** (2026-08) — folded completion record.', ''].join('\n'),
			files: { 'docs/backlog/README.md': '[backlog](backlog.md)\n', 'docs/plans/active/cs-plan-058-x.md': '# plan\n' },
		})

		cases.push({
			name: 'resolved stub is rejected',
			expectFindings: true,
			backlog: cleanBacklog + '\n' + [
				'### [P3] BUG-T-01 — Old flaky test',
				'**Status**: Resolved 2026-08-25 (CS-PLAN-048) → archive',
			].join('\n'),
			archive: '# Archive\n',
			files: {},
		})

		cases.push({
			name: 'duplicate ID is rejected',
			expectFindings: true,
			backlog: cleanBacklog + '\n' + cleanEntry2,
			archive: '# Archive\n',
			files: {},
		})

		cases.push({
			name: 'missing metadata field is rejected',
			expectFindings: true,
			backlog: cleanBacklog + '\n' + [
				'### [P3] TECH-T-01 — Entry without metadata',
				'**Status**: Open · **Effort**: S · **Source**: somewhere',
			].join('\n'),
			archive: '# Archive\n',
			files: {},
		})

		cases.push({
			name: 'dangling dependency reference is rejected',
			expectFindings: true,
			backlog: cleanBacklog.replace('**Blocks**: CR-T-02, CR-T-03..04', '**Blocks**: CR-T-02, CR-T-03..04, CR-GHOST-09'),
			archive: '# Archive\n',
			files: {},
		})

		cases.push({
			name: 'broken relative link is rejected',
			expectFindings: true,
			backlog: cleanBacklog,
			archive: '# Archive\n',
			files: { 'docs/backlog/README.md': '[ghost](../plans/done/cs-plan-000-ghost.md)\n' },
		})

		cases.push({
			name: 'out-of-range work package is rejected',
			expectFindings: true,
			backlog: cleanBacklog.replace('**Owning**: CS-PLAN-058 WP2', '**Owning**: CS-PLAN-058 WP12'),
			archive: '# Archive\n',
			files: {},
		})

		let failed = 0
		for (const testCase of cases) {
			mkdirSync(join(rootDir, 'docs', 'backlog'), { recursive: true })
			writeFileSync(join(rootDir, 'docs', 'backlog', 'backlog.md'), testCase.backlog, 'utf8')
			const fileEntries = [{ relPath: 'docs/backlog/backlog.md', text: testCase.backlog }]
			for (const [relPath, text] of Object.entries(testCase.files)) {
				const abs = join(rootDir, relPath.replace(/\//g, sep))
				mkdirSync(dirname(abs), { recursive: true })
				writeFileSync(abs, text, 'utf8')
				fileEntries.push({ relPath, text })
			}

			const openIds = collectOpenIds(testCase.backlog)
			const archiveIds = collectArchiveIds(new Map([['archive.md', testCase.archive]]))
			const planIds = new Set(['CS-PLAN-058'])
			const backlogPathRel = 'docs/backlog/backlog.md'
			const findings = scanBacklog(testCase.backlog, { openIds, archiveIds, planIds })
			for (const fileEntry of fileEntries) {
				if (fileEntry.relPath === backlogPathRel || fileEntry.relPath.startsWith('docs/plans/') || /^docs\/backlog\/[^/]+\.md$/.test(fileEntry.relPath)) {
					for (const finding of scanLinks(fileEntry.relPath, fileEntry.text, rootDir)) {
						findings.push(`${fileEntry.relPath}:${finding}`)
					}
				}
			}
			const hasFindings = findings.length > 0
			if (hasFindings !== testCase.expectFindings) {
				failed += 1
				console.error(`[${SCRIPT_NAME}] self-test FAILED for "${testCase.name}": expected findings=${testCase.expectFindings}, got ${JSON.stringify(findings, null, 2)}`)
			} else {
				console.log(`[${SCRIPT_NAME}] self-test ok: ${testCase.name}${hasFindings ? ` → ${findings.length} finding(s)` : ' → clean'}`)
			}
		}
		if (failed > 0) {
			console.error(`[${SCRIPT_NAME}] self-test: ${failed} case(s) misbehaved`)
			return 1
		}
		console.log(`[${SCRIPT_NAME}] self-test: all ${cases.length} cases behaved as expected`)
		return 0
	} finally {
		rmSync(rootDir, { recursive: true, force: true })
	}
}

function main() {
	if (process.argv.includes('--self-test')) {
		process.exit(selfTest())
	}

	const repoRoot = resolve(execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim())

	const backlogRel = 'docs/backlog/backlog.md'
	const backlogFiles = listTrackedFiles(['docs/backlog'])
	const planFiles = listTrackedFiles(['docs/plans'])

	let backlogText
	try {
		backlogText = readFileSync(join(repoRoot, backlogRel), 'utf8')
	} catch (error) {
		console.error(`[${SCRIPT_NAME}] cannot read ${backlogRel}: ${error.message}`)
		process.exit(1)
	}

	const findings = []
	const archiveContents = new Map()
	const archiveDirAbs = join(repoRoot, ARCHIVE_DIR)
	if (existsSync(archiveDirAbs)) {
		for (const file of listTrackedFiles([ARCHIVE_DIR])) {
			try {
				archiveContents.set(file, readFileSync(join(repoRoot, file), 'utf8'))
			} catch (error) {
				continue
			}
		}
		// A freshly created monthly archive is not yet git-tracked (the
		// backlog edit and its archive entry ship in one commit) — read it
		// from the worktree so the dependency index stays complete.
		const dirEntries = readdirSync(archiveDirAbs)
		for (const name of dirEntries) {
			if (/^\d{4}-\d{2}\.md$/.test(name) && !archiveContents.has(`${ARCHIVE_DIR}/${name}`)) {
				const rel = `${ARCHIVE_DIR}/${name}`
				try {
					archiveContents.set(rel, readFileSync(join(archiveDirAbs, name), 'utf8'))
				} catch (error) {
					continue
				}
			}
		}
	}
	for (const finding of scanBacklog(backlogText, {
		openIds: collectOpenIds(backlogText),
		archiveIds: collectArchiveIds(archiveContents),
		planIds: collectPlanIds(planFiles),
	})) {
		findings.push(`${backlogRel}:${finding}`)
	}

	const linkScopeRe = [/^docs\/backlog\/[^/]+\.md$/, /^docs\/plans\//]
	const scopedFiles = [...backlogFiles, ...planFiles].filter((file) => linkScopeRe.some((re) => re.test(file)))
	for (const file of scopedFiles) {
		let text
		try {
			text = readFileSync(join(repoRoot, file), 'utf8')
		} catch (error) {
			continue
		}
		for (const finding of scanLinks(file, text, repoRoot)) {
			findings.push(`${file}:${finding}`)
		}
	}

	if (findings.length > 0) {
		console.error(`[${SCRIPT_NAME}] ${findings.length} backlog finding(s):`)
		for (const finding of findings) {
			console.error(`  ${finding}`)
		}
		console.error(`[${SCRIPT_NAME}] fix the entries above (structure per CS-PLAN-058 §2.2, format per docs/backlog/README.md).`)
		process.exit(1)
	}

	console.log(`[${SCRIPT_NAME}] ${backlogRel}: open items only, unique IDs, full metadata, resolvable dependencies; ${scopedFiles.length} scoped file(s) link-checked — all green`)
}

main()
