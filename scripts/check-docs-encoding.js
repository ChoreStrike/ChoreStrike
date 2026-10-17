#!/usr/bin/env node

/**
 * Docs encoding gate (CS-PLAN-050, TECH-DOCS-001; C0 hardening CS-PLAN-052,
 * TECH-DOCS-002).
 *
 * Fails when a git-tracked text file contains invalid UTF-8 byte sequences,
 * forbidden C0 control bytes, a lone CR, or a known cp1252-misdetection
 * mojibake signature. This class of corruption repeatedly crept into
 * docs/backlog files when tools wrote text through a cp1252 code path:
 * non-cp1252 characters (arrows, dashes, umlauts) turned into literal `?`
 * bytes or mojibake sequences.
 *
 * Detection is deterministic and narrowly scoped:
 *   - invalid UTF-8: strict structural validation, reported with the byte
 *     offset of the first offending byte;
 *   - forbidden C0 control bytes: any byte in 0x00–0x08, 0x0B, 0x0C,
 *     0x0E–0x1F (i.e. C0 except TAB/LF/CR; NUL also trips the binary probe
 *     below) is invalid in text, checked byte-level before UTF-8 decode;
 *   - lone CR: 0x0D not followed by 0x0A — CRLF or LF line endings only;
 *   - mojibake signatures: UTF-8 three-byte punctuation (E2 80/E2 82 …)
 *     misread as cp1252 yields U+00E2 followed by U+20AC/U+201A …, UTF-8
 *     two-byte umlauts (C3 xx) misread as cp1252 yields U+00C3 followed by
 *     a Latin-1 character, and UTF-8 misread as Latin-1 leaves a C1 control
 *     (U+0080–U+009F) after a U+00C2/U+00C3/U+00E2 lead (TECH-DOCS-004).
 *
 * Known residual (accepted, CS-PLAN-052 D4): a TAB or LF byte that *ate a
 * word-initial letter* is indistinguishable from legitimate code
 * indentation and stays undetectable — every indented JS line is
 * TAB-followed-by-letter, so word-adjacency heuristics would false-positive
 * on the whole tree. Only the non-TAB/LF/CR C0 signature is deterministic.
 *
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
	// TECH-DOCS-004: the two leads above miss the other continuation bytes.
	// A continuation byte 80-9F becomes the cp1252 character mapped to it
	// (arrow E2 86 92 -> U+00E2 U+2020 U+2019, U+00D6 C3 96 -> U+00C3 U+2013), or a
	// C1 control when read as Latin-1 (E2 86 92 -> U+00E2 U+0086 U+0092); a
	// section sign C2 A7 becomes U+00C2 U+00A7. Legitimate English/German text
	// never puts these characters directly after the lead.
	{ name: 'cp1252 mojibake lead \\u00C2/\\u00C3/\\u00E2 + cp1252 80-9F character (UTF-8 read as cp1252)', regex: /[\u00C2\u00C3\u00E2][\u20AC\u201A\u0192\u201E\u2026\u2020\u2021\u02C6\u2030\u0160\u2039\u0152\u017D\u2018\u2019\u201C\u201D\u2022\u2013\u2014\u02DC\u2122\u0161\u203A\u0153\u017E\u0178]/ },
	{ name: 'Latin-1 mojibake lead \\u00C2/\\u00C3/\\u00E2 + C1 control (UTF-8 read as Latin-1)', regex: /[\u00C2\u00C3\u00E2][\u0080-\u009F]/ },
	{ name: 'mojibake \\u00C2 + Latin-1 symbol (C2 A0-BF read as cp1252/Latin-1)', regex: /\u00C2[\u00A0-\u00BF]/ },
	// Four-byte emoji (F0 9F xx xx -> U+00F0 U+0178 ...) and an embedded BOM
	// (EF BB BF -> U+00EF U+00BB U+00BF) read as cp1252/Latin-1.
	{ name: 'mojibake emoji lead \\u00F0\\u0178 or BOM \\u00EF\\u00BB\\u00BF (read as cp1252/Latin-1)', regex: /\u00F0[\u0178\u009F]|\u00EF\u00BB\u00BF/ },
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
 * Return the byte offset of the first forbidden C0 control byte, or -1.
 *
 * Forbidden: 0x00–0x08, 0x0B, 0x0C, 0x0E–0x1F — all C0 controls except
 * TAB (0x09), LF (0x0A), and CR (0x0D). Byte-level check, valid before
 * UTF-8 decode since these bytes cannot appear inside multi-byte sequences.
 * NUL never reaches here in practice: it trips the binary probe first.
 *
 * @param {Buffer} buf - Raw file bytes.
 * @returns {number}
 */
function firstForbiddenControlByteOffset(buf) {
	for (let i = 0; i < buf.length; i++) {
		const b = buf[i]
		if (b < 0x20 && b !== 0x09 && b !== 0x0A && b !== 0x0D) {
			return i
		}
	}
	return -1
}

/**
 * Return the byte offset of the first lone CR (0x0D not followed by 0x0A),
 * or -1. CRLF and plain LF are legitimate; a bare CR is corruption
 * (typically an escape-sequence artifact).
 *
 * @param {Buffer} buf - Raw file bytes.
 * @returns {number}
 */
function firstLoneCrOffset(buf) {
	for (let i = 0; i < buf.length; i++) {
		if (buf[i] === 0x0D && (i + 1 >= buf.length || buf[i + 1] !== 0x0A)) {
			return i
		}
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

	const controlAt = firstForbiddenControlByteOffset(buf)
	if (controlAt !== -1) {
		findings.push(`forbidden C0 control byte 0x${buf[controlAt].toString(16).padStart(2, '0')} at byte offset ${controlAt} (TAB/LF/CR are the only valid control bytes)`)
		return findings
	}

	const loneCrAt = firstLoneCrOffset(buf)
	if (loneCrAt !== -1) {
		findings.push(`lone CR (0x0D without following LF) at byte offset ${loneCrAt}`)
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
		// Planted em-dash mojibake: UTF-8 E2 80 94 read as cp1252 and re-saved
		// as UTF-8 (chars U+00E2 U+20AC U+201D). Built from char codes, never
		// literal bytes — a literal in this comment would self-flag the gate.
		const mojibakeText = 'text ' + String.fromCharCode(0xE2, 0x20AC, 0x201D) + ' more\n'
		writeFileSync(mojibake, Buffer.from(mojibakeText, 'utf8'))

		const latin1Mojibake = join(dir, 'planted-latin1-c1.md')
		// Planted arrow mojibake: UTF-8 E2 86 92 read as Latin-1 (chars U+00E2
		// U+0086 U+0092, the TECH-DOCS-004 instance). Built from char codes.
		writeFileSync(latin1Mojibake, Buffer.from('a ' + String.fromCharCode(0xE2, 0x86, 0x92) + ' b\n', 'utf8'))

		const cp1252Arrow = join(dir, 'planted-cp1252-arrow.md')
		// The same arrow read as cp1252: U+00E2 U+2020 U+2019.
		writeFileSync(cp1252Arrow, Buffer.from('a ' + String.fromCharCode(0xE2, 0x2020, 0x2019) + ' b\n', 'utf8'))

		const emojiMojibake = join(dir, 'planted-emoji.md')
		// Emoji F0 9F 98 80 read as cp1252: U+00F0 U+0178 U+02DC U+20AC.
		writeFileSync(emojiMojibake, Buffer.from('hi ' + String.fromCharCode(0xF0, 0x178, 0x2DC, 0x20AC) + '\n', 'utf8'))

		const sectionSign = join(dir, 'planted-section-sign.md')
		// Section sign C2 A7 read as cp1252/Latin-1: U+00C2 U+00A7.
		writeFileSync(sectionSign, Buffer.from('see ' + String.fromCharCode(0xC2, 0xA7) + '4\n', 'utf8'))

		const cleanLatin1Lead = join(dir, 'clean-latin1-lead.md')
		// Legitimate lead characters followed by ordinary letters stay green.
		writeFileSync(cleanLatin1Lead, Buffer.from('château crème Ânes\n', 'utf8'))

		const invalid = join(dir, 'planted-invalid.md')
		// Valid ASCII prefix + a stray 0xE2 continuation-less lead byte.
		writeFileSync(invalid, Buffer.concat([Buffer.from('ok ', 'utf8'), Buffer.from([0xE2])]))

		const controlBytes = join(dir, 'planted-control-bytes.md')
		// Planted forbidden C0 bytes: BEL (0x07), VT (0x0B), ESC (0x1B) —
		// the escape-style corruption family found by the CS-PLAN-051
		// review. Built as raw bytes, never literals.
		writeFileSync(controlBytes, Buffer.concat([
			Buffer.from('word', 'utf8'),
			Buffer.from([0x07, 0x0B, 0x1B]),
			Buffer.from('end\n', 'utf8'),
		]))

		const loneCr = join(dir, 'planted-lone-cr.md')
		// Planted lone CR: 0x0D not followed by 0x0A.
		writeFileSync(loneCr, Buffer.concat([
			Buffer.from('line1\rline2\n', 'utf8'),
		]))

		const cleanTabsCrlf = join(dir, 'clean-tabs-crlf.md')
		// Legitimate TAB indentation and CRLF line endings must stay green.
		writeFileSync(cleanTabsCrlf, Buffer.from('\tindented line\r\n\tsecond line\r\n', 'utf8'))

		cases.push(
			{ path: cleanEmoji, expectFindings: false },
			{ path: cleanQuestion, expectFindings: false },
			{ path: cleanTabsCrlf, expectFindings: false },
			{ path: cleanLatin1Lead, expectFindings: false },
			{ path: mojibake, expectFindings: true },
			{ path: latin1Mojibake, expectFindings: true },
			{ path: cp1252Arrow, expectFindings: true },
			{ path: sectionSign, expectFindings: true },
			{ path: emojiMojibake, expectFindings: true },
			{ path: invalid, expectFindings: true },
			{ path: controlBytes, expectFindings: true },
			{ path: loneCr, expectFindings: true },
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

	console.log(`[${SCRIPT_NAME}] ${files.length} tracked file(s) scanned — no invalid UTF-8, no forbidden C0 control bytes, no lone CR, no mojibake signatures`)
}

main()
