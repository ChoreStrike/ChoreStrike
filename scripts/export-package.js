/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 * Explicit, byte-preserving export of one locally qualified package run.
 */
'use strict'

const crypto = require('node:crypto')
const fs = require('node:fs')
const path = require('node:path')
const { isDeepStrictEqual } = require('node:util')
const { sha256Hex } = require('./build-package')
const { OWNER_SMOKE_CASES } = require('./package-target')

const ROOT = path.resolve(__dirname, '..')
const ARCHIVE = 'chorestrike.tar.gz'
const SHA256 = /^[a-f0-9]{64}$/

class HandoffError extends Error {}

function requireCondition(condition, message) {
	if (!condition) throw new HandoffError(message)
}

function regularFile(file) {
	const stat = fs.lstatSync(file)
	requireCondition(stat.isFile() && !stat.isSymbolicLink(), 'Expected a regular file without links.')
	return fs.readFileSync(file)
}

function directory(dir) {
	const stat = fs.lstatSync(dir)
	requireCondition(stat.isDirectory() && !stat.isSymbolicLink(), 'Expected an existing directory without links.')
	return fs.realpathSync(dir)
}

function contains(parent, child) {
	const relative = path.relative(parent, child)
	return relative === '' || (!path.isAbsolute(relative) && relative !== '..' && !relative.startsWith(`..${path.sep}`))
}

function exchangeLocation(value) {
	requireCondition(typeof value === 'string' && path.isAbsolute(value), 'Set CHORESTRIKE_EXCHANGE_DIR to an absolute, existing exchange directory.')
	const exchange = directory(value)
	const repo = fs.realpathSync(ROOT)
	requireCondition(!contains(repo, exchange) && !contains(exchange, repo), 'Exchange and source repository must be separate directory trees.')
	requireCondition(regularFile(path.join(exchange, 'README.md')).toString('utf8').trim(), 'The exchange README must not be empty.')
	for (const name of ['von-chorestrike', 'an-chorestrike']) {
		requireCondition(directory(path.join(exchange, name)) === path.join(exchange, name), 'Exchange direction directories must not redirect elsewhere.')
	}
	return exchange
}

function validateManifest(manifest, identity, archive) {
	const { status, gate, ...record } = identity
	requireCondition(status === 'package-identity-record' && gate === 'CS-PLAN-083 WP2'
		&& isDeepStrictEqual(record, manifest), 'Package manifest and identity must describe the same build run.')
	requireCondition(/^[a-f0-9]{40}$/.test(manifest.candidate?.commit)
		&& Number.isSafeInteger(manifest.candidate?.sourceDateEpoch), 'Invalid source commit identity.')
	requireCondition(manifest.archive?.name === ARCHIVE && SHA256.test(manifest.archive.sha256)
		&& manifest.archive.bytes === archive.length && manifest.archive.sha256 === sha256Hex(archive), 'Archive size or SHA-256 does not match the manifest.')
	for (const name of ['files', 'source']) {
		requireCondition(Array.isArray(manifest[name]) && manifest[name].length > 0
			&& manifest[name].every(file => typeof file.path === 'string' && SHA256.test(file.sha256)), 'Missing or invalid package/source inventory.')
	}
	requireCondition(manifest.assetContract && Array.isArray(manifest.assetContract.violations)
		&& manifest.assetContract.violations.length === 0, 'Missing or failed asset contract.')
}

function validateQualification(record, manifest) {
	requireCondition(record.status === 'package-qualified' && record.gate === 'CS-PLAN-083 WP3'
		&& isDeepStrictEqual(record.candidate, manifest.candidate) && isDeepStrictEqual(record.archive, manifest.archive)
		&& record.extractedDriftCheck === 'post-qualification-rehash-ok'
		&& typeof record.qualifiedAt === 'string' && Number.isFinite(Date.parse(record.qualifiedAt)), 'Missing, failed or mismatched package qualification.')
	requireCondition(Array.isArray(record.rows) && record.rows.length === 2
		&& ['primary', 'aio'].every(name => record.rows.filter(row => row.row === name).length === 1), 'Both package qualification runtime rows are required.')
	for (const row of record.rows) {
		requireCondition(row.candidate === manifest.candidate.commit && !row.failure
			&& row.extractedDriftCheck === 'post-run-rehash-ok' && row.teardown === 'owned-down-volumes'
			&& typeof row.project === 'string' && /^\d+\.\d+\.\d+$/.test(row.nextcloud), 'Incomplete or mismatched runtime qualification.')
		for (const [action, report] of [['setup', row.setup], ['verify', row.verify]]) {
			const count = OWNER_SMOKE_CASES[action]
			// Genuine owner-smoke reports carry no `passed` field; these clauses
			// only trip on hand-edited records with an explicit failure flag.
			requireCondition(report?.action === action && report.project === row.project && report.passed !== false
				&& Array.isArray(report.results) && report.results.length === count
				&& report.results.every(result => result && typeof result === 'object' && result.passed !== false), 'Incomplete owner-smoke evidence.')
		}
	}
}

function prepareFiles(runDir, handoffFile) {
	const run = directory(runDir)
	const pkg = directory(path.join(run, 'package'))
	const files = new Map([ARCHIVE, 'package-manifest.json', 'identity.json'].map(name => [name, regularFile(path.join(pkg, name))]))
	const manifest = JSON.parse(files.get('package-manifest.json').toString('utf8'))
	validateManifest(manifest, JSON.parse(files.get('identity.json').toString('utf8')), files.get(ARCHIVE))
	const qualification = regularFile(path.join(run, 'qualification.json'))
	const record = JSON.parse(qualification.toString('utf8'))
	validateQualification(record, manifest)
	const notes = regularFile(handoffFile).toString('utf8')
	for (const section of ['Qualification', 'Limitations', 'Requested Action']) {
		const body = notes.match(new RegExp(`^## ${section}\\r?\\n([\\s\\S]*?)(?=^## |$(?![\\s\\S]))`, 'm'))?.[1]
		requireCondition(body?.trim(), 'Handoff notes require nonempty Qualification, Limitations and Requested Action sections.')
	}
	files.set('handoff.md', Buffer.from(`${composeHandoffHeader(files, manifest, record, qualification)}\n${notes}\n`))
	return { files, manifest }
}

function composeHandoffHeader(files, manifest, record, qualification) {
	return [
		'# Package Handoff', '',
		`- Source commit: ${manifest.candidate.commit}`,
		`- Archive: ${ARCHIVE} (${manifest.archive.bytes} bytes)`,
		`- Archive SHA-256: ${manifest.archive.sha256}`,
		`- Package manifest SHA-256: ${sha256Hex(files.get('package-manifest.json'))}`,
		`- Identity SHA-256: ${sha256Hex(files.get('identity.json'))}`,
		`- Local qualification record SHA-256: ${sha256Hex(qualification)}`,
		`- Package smoke: ${record.rows.map(row => `${row.row} / Nextcloud ${row.nextcloud}`).join(', ')}`,
		`- Qualified at: ${new Date(record.qualifiedAt).toISOString()}`, '',
		'Export verifies the local qualification record, not its authenticity or all release gates.',
		'This is not deployment approval, production browser acceptance, retained-baseline adoption or WP9 evidence.',
		'The receiver must independently verify the complete archive before extraction.', '',
	].join('\n')
}

function publish(exchange, files, manifest) {
	const id = `handoff-${manifest.candidate.commit.slice(0, 12)}-${crypto.randomUUID()}`
	const outgoing = path.join(exchange, 'von-chorestrike')
	const destination = path.join(outgoing, id)
	// Incomplete files stay outside the incoming direction, even after a hard stop.
	let staging = fs.mkdtempSync(path.join(exchange, '.chorestrike-export-'))
	try {
		for (const [name, data] of files) fs.writeFileSync(path.join(staging, name), data, { flag: 'wx' })
		for (const [name, data] of files) {
			requireCondition(sha256Hex(regularFile(path.join(staging, name))) === sha256Hex(data), 'Copied handoff bytes failed verification.')
		}
		// Revalidate immediately before publishing: a link swapped into the
		// outgoing path after the initial check must not redirect the rename.
		requireCondition(directory(outgoing) === outgoing, 'The outgoing exchange direction changed during export; refusing to publish.')
		requireCondition(!fs.existsSync(destination), 'A handoff with this identity already exists; refusing to overwrite it.')
		fs.renameSync(staging, destination)
		staging = null
		return { status: 'package-exported', handoff: id, commit: manifest.candidate.commit, archiveSha256: manifest.archive.sha256 }
	} finally {
		if (staging) fs.rmSync(staging, { recursive: true, force: true })
	}
}

function exportPackage({ runDir, handoffFile, reviewed, exchangeDir }) {
	requireCondition(reviewed === true, 'Read the exchange README and review the package and handoff notes before passing --reviewed.')
	const exchange = exchangeLocation(exchangeDir)
	const { files, manifest } = prepareFiles(runDir, handoffFile)
	return publish(exchange, files, manifest)
}

function main(args = process.argv.slice(2), env = process.env) {
	try {
		if (args.length === 1 && args[0] === '--check') {
			exchangeLocation(env.CHORESTRIKE_EXCHANGE_DIR)
			return { status: 'exchange-configured' }
		}
		const options = { exchangeDir: env.CHORESTRIKE_EXCHANGE_DIR }
		const names = { '--run': 'runDir', '--handoff': 'handoffFile' }
		for (let i = 0; i < args.length; i++) {
			if (args[i] === '--reviewed' && !options.reviewed) options.reviewed = true
			else {
				const name = Object.hasOwn(names, args[i]) ? names[args[i]] : null
				requireCondition(name && !options[name] && args[i + 1] && !args[i + 1].startsWith('--'), 'Invalid export arguments. Use --run <directory> --handoff <markdown> --reviewed, or --check.')
				options[name] = args[++i]
			}
		}
		requireCondition(options.runDir && options.handoffFile, 'Select a qualification run with --run and reviewed notes with --handoff.')
		return exportPackage(options)
	} catch (error) {
		// Native filesystem/JSON errors include private paths or input fragments.
		if (error instanceof HandoffError) throw error
		throw new HandoffError('Export failed while reading, validating or publishing local files. Check input JSON, file access and exchange permissions; no success was recorded.')
	}
}

module.exports = { main }

if (require.main === module) {
	try {
		process.stdout.write(`${JSON.stringify(main())}\n`)
	} catch (error) {
		process.stderr.write(`[package-export] ${error.message}\n`)
		process.exitCode = 1
	}
}
