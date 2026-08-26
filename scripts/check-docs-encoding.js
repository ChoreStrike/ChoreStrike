#!/usr/bin/env node

/**
 * Docs encoding gate (CS-PLAN-050, TECH-DOCS-001).
 *
 * Fails when a git-tracked text file contains invalid UTF-8 byte sequences
 * or a known cp1252-misdetection mojibake signature. This class of
 * corruption repeatedly crept into docs/backlog files when tools wrote
 * text through a cp1252 code path: non-cp1252 characters (arrows, dashes,
 * umlauts) turned into literal `?` bytes or mojibake sequences.
 *
 * Detection is deterministic and narrowly scoped:
 *   - invalid UTF-8: strict structural validation, reported with the byte
 *     offset of the first offending byte;
 *   - mojibake signatures: UTF-8 three-byte punctuation (E2 80/E2 82 …)
 *     misread as cp1252 yields U+00E2 followed by U+20AC/U+201A …, UTF-8
 *     two-byte umlauts (C3 xx) misread as cp1252 yields U+00C3 followed by
 *     a Latin-1 character.
 * A bare `?` in prose is NEVER flagged — question marks are legitimate
 * text and cannot be distinguished from lossy corruption after the fact.
 *
 * Usage:
 *   node scripts/check-docs-encoding.js              # scan git-tracked files
 *   node scripts/check-docs-encoding.js --self-test  # verify the detector
 */

'use strict'

const { execFileSync } = require('child_process')
const { mkdtempSync, readFileSync, rmSync, writeFileSync } = require('fs')
const { tmpdir } = require('os')
const { join } = require('path')

const SCRIPT_NAME = 'check-docs-encoding'

/**
 * Known mojibake signatures, cited as escapes (never literal bytes).
 * `byteOf` computes the UTF-8 byte offset of the match for reporting.
 *
 * @type {Array<{name: string, regex: RegExp}>}
 */
const SIGNATURES = [
	{ name: 'cp1252 mojibake lead \\u00E2\\u20AC (E2 80 xx read as cp1252)', regex: /\u00E2\u20AC/ },
	{ name: 'cp1252 mojibake lead \\u00E2\\u201A (E2 82 xx read as cp1252)', regex: /\u00E2\u201A/ },
	{ name: 'cp1252 mojibake umlaut class \\u00C3 + Latin-1 (C3 xx read as cp1252)', regex: /\u00C3[\u0080-\u00BF]/ },
]

/**
 * Binary-file heuristic (mirrors git): a NUL byte in the first 8 KiB means
 * the file is not text and encoding checks do not apply.
 *
 * @param {Buffer} buf - Raw file bytes.
 * @returns {boolean}
 */
function looksBinary(buf) {
	const probe = buf.subarray(0, 8192)
	return probe.includes(0)
}

/**
 * Strictly validate a buffer as UTF-8 and return the byte offset of the
 * first invalid sequence, or -1 when the whole buffer is valid.
 *
 * @param {Buffer} buf - Raw file bytes.
 * @returns {number}
 */
function firstInvalidUtf8Offset(buf) {
	const len = buf.length
	let i = 0
	while (i < len) {
		const b = buf[i]
		if (b < 0x80) {
			i += 1
			continue
		}
		let seqLen, min
		if ((b & 0xE0) === 0xC0) {
			seqLen = 2
			min = 0x80
		} else if ((b & 0xF0) === 0xE0) {
			seqLen = 3
			min = 0x800
		} else if ((b & 0xF8) === 0xF0) {
			seqLen = 4
			min = 0x10000
		} else {
			return i
		}
		if (i + seqLen > len) {
			return i
		}
		let cp = b & (0xFF >> (seqLen + 1))
		for (let j = 1; j < seqLen; j++) {
			const cont = buf[i + j]
			if ((cont & 0xC0) !== 0x80) {
				return i
			}
			cp = (cp << 6) | (cont & 0x3F)
		}
		if (cp < min || (cp >= 0xD800 && cp <= 0xDFFF) || cp > 0x10FFFF) {
			return i
		}
		i += seqLen
	}
	return -1
}

/**
 * Scan one file for encoding corruption.
 *
 * @param {string} path - File path to scan.
 * @returns {Array<string>} Human-readable findings (empty when clean).
 */
function scanFile(path) {
	const findings = []
	let buf
	try {
		buf = readFileSync(path)
	} catch (error) {
		if (error.code === 'ENOENT') {
			// Tracked but deleted from the worktree (staged/unstaged
			// removal): nothing to scan — git reports the deletion itself.
			return findings
		}
		return [`unreadable: ${error.message}`]
	}
	if (looksBinary(buf)) {
		return findings
	}

	const invalidAt = firstInvalidUtf8Offset(buf)
	if (invalidAt !== -1) {
		findings.push(`invalid UTF-8 at byte offset ${invalidAt} (0x${buf[invalidAt].toString(16).padStart(2, '0')})`)
		return findings
	}

	const text = buf.toString('utf8')
	for (const signature of SIGNATURES) {
		const match = signature.regex.exec(text)
		if (match) {
			const byteOffset = Buffer.byteLength(text.slice(0, match.index), 'utf8')
			findings.push(`${signature.name} at byte offset ${byteOffset}`)
		}
	}
	return findings
}

/**
 * List all git-tracked files (the gate scans exactly what would ship).
 *
 * @returns {string[]}
 */
function listTrackedFiles() {
	const output = execFileSync('git', ['ls-files'], { encoding: 'utf8' })
	return output.split(/\r?\n/).filter((line) => line.length > 0)
}

/**
 * Self-test: plant corrupted fixtures programmatically (Buffer writes, so
 * this file itself never contains literal mojibake bytes) and verify the
 * detector flags them while clean fixtures stay green.
 *
 * @returns {number} Non-zero on any detector misbehaviour.
 */
function selfTest() {
	const dir = mkdtempSync(join(tmpdir(), `${SCRIPT_NAME}-selftest-`))
	const cases = []
	try {
		const cleanEmoji = join(dir, 'clean-emoji.md')
		// "Dash — and äöü ß" encoded as proper UTF-8.
		writeFileSync(cleanEmoji, Buffer.from('Dash — and äöü ß\n', 'utf8'))

		const cleanQuestion = join(dir, 'clean-question.md')
		writeFileSync(cleanQuestion, Buffer.from('Is this prose? Yes? Fine.\n', 'utf8'))

		const mojibake = join(dir, 'planted-mojibake.md')
		// "text â€" more" — the em-dash mojibake (E2 80 94 read as cp1252,
		// re-saved as UTF-8). Built from char codes, never literal bytes.
		const mojibakeText = 'text ' + String.fromCharCode(0xE2, 0x20AC, 0x201D) + ' more\n'
		writeFileSync(mojibake, Buffer.from(mojibakeText, 'utf8'))

		const invalid = join(dir, 'planted-invalid.md')
		// Valid ASCII prefix + a stray 0xE2 continuation-less lead byte.
		writeFileSync(invalid, Buffer.concat([Buffer.from('ok ', 'utf8'), Buffer.from([0xE2])]))

		cases.push(
			{ path: cleanEmoji, expectFindings: false },
			{ path: cleanQuestion, expectFindings: false },
			{ path: mojibake, expectFindings: true },
			{ path: invalid, expectFindings: true },
		)

		let failed = 0
		for (const testCase of cases) {
			const findings = scanFile(testCase.path)
			const hasFindings = findings.length > 0
			if (hasFindings !== testCase.expectFindings) {
				failed += 1
				console.error(`[${SCRIPT_NAME}] self-test FAILED for ${testCase.path}: expected findings=${testCase.expectFindings}, got ${JSON.stringify(findings)}`)
			} else {
				console.log(`[${SCRIPT_NAME}] self-test ok: ${testCase.path} → ${hasFindings ? findings.join('; ') : 'clean'}`)
			}
		}
		if (failed > 0) {
			console.error(`[${SCRIPT_NAME}] self-test: ${failed} case(s) misbehaved`)
			return 1
		}
		console.log(`[${SCRIPT_NAME}] self-test: all ${cases.length} cases behaved as expected`)
		return 0
	} finally {
		rmSync(dir, { recursive: true, force: true })
	}
}

function main() {
	if (process.argv.includes('--self-test')) {
		process.exit(selfTest())
	}

	const files = listTrackedFiles()
	const offenders = []
	for (const file of files) {
		const findings = scanFile(file)
		if (findings.length > 0) {
			offenders.push({ file, findings })
		}
	}

	if (offenders.length > 0) {
		console.error(`[${SCRIPT_NAME}] ${offenders.length} file(s) with encoding corruption:`)
		for (const offender of offenders) {
			for (const finding of offender.findings) {
				console.error(`  ${offender.file}: ${finding}`)
			}
		}
		console.error(`[${SCRIPT_NAME}] repair the file (save as UTF-8) or re-write the corrupted characters.`)
		process.exit(1)
	}

	console.log(`[${SCRIPT_NAME}] ${files.length} tracked file(s) scanned — no invalid UTF-8, no mojibake signatures`)
}

main()
