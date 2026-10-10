#!/usr/bin/env node
/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * CS-PLAN-083 WP2: package identity gate.
 *
 * The 2026-10 operator acceptance round found a built bundle that did not
 * match the pushed commit (1477 vs 1479/1518 test counts) because packaging
 * was a manual, host-side, unhashed step. This gate closes that blind spot:
 *
 *   1. CLEAN HEAD — a dirty or detached tree is a hard failure, matching how
 *      the drift happened. No auto-commit, stash or dirty-tree bypass.
 *   2. PINNED BUILDER — the production bundle (locked `npm ci` + webpack)
 *      and the production Composer autoloader (locked `composer install
 *      --no-dev`) are built inside a digest/platform-pinned Docker builder
 *      from a `git archive HEAD` stream, so ignored workspace outputs,
 *      host-OS path separators (the Windows `\\apps\\` base was part of the
 *      ChunkLoadError finding) and host timestamps can never leak in.
 *   3. DETERMINISTIC ARCHIVE — an explicit commit-derived SOURCE_DATE_EPOCH
 *      feeds webpack.config.js; the runtime archive is assembled in pure
 *      Node with sorted entries, 0:0 ownership, 0644/0755 modes, a fixed
 *      mtime and gzip mtime 0. Two independent builds of the same commit
 *      and pinned tools are byte-identical (`--verify-reproducible`).
 *   4. IDENTITY PROOF — a package manifest (per-file SHA-256), a source
 *      manifest (git blob comparison with documented CRLF/LF provenance;
 *      non-line-ending drift fails) and an asset-contract check (every
 *      template/controller-referenced asset exists; every emitted chunk is
 *      reachable from the entry or explicitly excepted).
 *
 * All reports live under gitignored build/package/<run-id>/. Volatile run
 * IDs/times stay outside the reproducible artifact and manifests.
 *
 * Self-test: `node scripts/build-package.js --self-test` exercises every
 * pure check with synthetic fixtures — no Docker, no git, no network.
 *
 * npm: `npm run test:package:build`
 */

'use strict'

const { spawnSync } = require('node:child_process')
const crypto = require('node:crypto')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const zlib = require('node:zlib')

const ROOT = path.resolve(__dirname, '..')
const CONFIG_PATH = path.join(ROOT, 'docker', 'package-build.json')
const ARCHIVE_NAME = 'chorestrike.tar.gz'
const DIGEST = /^sha256:[a-f0-9]{64}$/
const VERSION = /^\d+\.\d+\.\d+$/

// ---------------------------------------------------------------------------
// Small crypto helpers
// ---------------------------------------------------------------------------

function sha256Hex(buffer) {
	return crypto.createHash('sha256').update(buffer).digest('hex')
}

function sha1Hex(buffer) {
	return crypto.createHash('sha1').update(buffer).digest('hex')
}

// ---------------------------------------------------------------------------
// Git state (clean-HEAD gate)
// ---------------------------------------------------------------------------

function run(command, args, options = {}) {
	const result = spawnSync(command, args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, ...options })
	if (result.error) throw new Error(`Failed to execute ${command}: ${result.error.message}`)
	return result
}

/** Read the HEAD-pinned candidate identity. Refuses dirty/detached trees. */
function readGitState(probe = run) {
	const head = probe('git', ['rev-parse', 'HEAD']).stdout.trim()
	if (!/^[a-f0-9]{40}$/.test(head)) throw new Error('Cannot resolve HEAD; refusing to package')
	const branch = probe('git', ['symbolic-ref', '-q', 'HEAD']).stdout.trim()
	if (!branch) throw new Error('Refusing to package a detached HEAD; check out a branch tip')
	const status = probe('git', ['status', '--porcelain']).stdout
	if (status.trim()) {
		throw new Error(`Refusing to package a dirty tree (${status.trim().split('\n').length} change(s)); commit or explicitly reset first`)
	}
	const epoch = Number(probe('git', ['show', '-s', '--format=%ct', 'HEAD']).stdout.trim())
	if (!Number.isInteger(epoch) || epoch <= 0) throw new Error('Cannot derive the commit epoch for SOURCE_DATE_EPOCH')
	return { head, branch, epoch }
}

/** Tracked files of the candidate: [{mode, type, sha1, path}]. */
function readTree(probe = run) {
	const output = probe('git', ['ls-tree', '-r', 'HEAD']).stdout
	return output.split('\n').filter(Boolean).map(line => {
		const [meta, name] = line.split('\t')
		const [mode, type, sha1] = meta.split(' ')
		return { mode, type, sha1, path: name }
	})
}

// ---------------------------------------------------------------------------
// Deterministic ustar writer/reader (pure, host-independent)
// ---------------------------------------------------------------------------

function octal(value, width) {
	return value.toString(8).padStart(width - 1, '0') + '\0'
}

/** Format one pax extended header record (its length field includes itself). */
function paxRecord(keyword, value) {
	const body = ` ${keyword}=${value}\n`
	return `${body.length + String(body.length + 2).length}${body}`
}

/** True when the name fits the ustar name+prefix fields. */
function fitsUstar(name) {
	if (Buffer.byteLength(name, 'utf8') <= 100) return true
	const slash = name.lastIndexOf('/', name.length - 101)
	return slash > 0 && slash <= 155 && Buffer.byteLength(name.slice(slash + 1), 'utf8') <= 100
}

function ustarHeader(entry, nameOverride = null) {
	const header = Buffer.alloc(512)
	const name = Buffer.from(nameOverride ?? entry.name, 'utf8')
	if (name.length > 100) {
		// ustar prefix split: prefix <= 155, leaf <= 100.
		const slash = entry.name.lastIndexOf('/', entry.name.length - 101)
		if (slash <= 0 || slash > 155) throw new Error(`Archive path too long for ustar: ${entry.name}`)
		header.write(entry.name.slice(slash + 1), 0, 100)
		header.write(entry.name.slice(0, slash), 345, 155)
	} else {
		header.write(entry.name, 0, 100)
	}
	header.write(octal(entry.mode & 0o7777, 8), 100, 8)
	header.write(octal(0, 8), 108, 8) // uid — 0:0 ownership
	header.write(octal(0, 8), 116, 8) // gid
	header.write(octal(entry.type === 'dir' ? 0 : entry.data.length, 12), 124, 12)
	header.write(octal(entry.mtime, 12), 136, 12)
	header.fill(0x20, 148, 156) // checksum spaces
	header.write(entry.typeflag ?? (entry.type === 'dir' ? '5' : '0'), 156, 1)
	header.write('ustar\0', 257, 6)
	header.write('00', 263, 2)
	header.write('root\0', 265, 32)
	header.write('root\0', 297, 32)
	let checksum = 0
	for (const byte of header) checksum += byte
	header.write(checksum.toString(8).padStart(6, '0') + '\0 ', 148, 8)
	return header
}

/**
 * Assemble a deterministic ustar image. Entries are sorted by name and get
 * 0:0 ownership, their mode, a fixed mtime and no link metadata.
 * @param {Array<{name: string, type: 'file'|'dir', mode: number, mtime: number, data: Buffer}>} entries
 */
function buildTar(entries) {
	const sorted = [...entries].sort((a, b) => a.name < b.name ? -1 : a.name > b.name ? 1 : 0)
	const parts = []
	for (const entry of sorted) {
		if (entry.type !== 'file' && entry.type !== 'dir') throw new Error(`Unsupported archive entry type: ${entry.type}`)
		if (!fitsUstar(entry.name)) {
			// Overlong webpack vendor chunk names travel in a pax extended
			// header (parsed back by readTar); the ustar name field carries a
			// deterministic truncated placeholder.
			const record = Buffer.from(paxRecord('path', entry.name), 'utf8')
			const pax = { name: `./PaxHeaders/${entry.name.slice(0, 60)}`, typeflag: 'x', type: 'file', mode: 0o644, mtime: entry.mtime, data: record }
			parts.push(ustarHeader({ ...pax, type: 'file' }, pax.name), record)
			const padding = (512 - (record.length % 512)) % 512
			if (padding) parts.push(Buffer.alloc(padding))
			parts.push(ustarHeader(entry, entry.name.slice(0, 100)))
		} else {
			parts.push(ustarHeader(entry))
		}
		if (entry.type === 'file') {
			parts.push(entry.data)
			const padding = (512 - (entry.data.length % 512)) % 512
			if (padding) parts.push(Buffer.alloc(padding))
		}
	}
	parts.push(Buffer.alloc(1024))
	return Buffer.concat(parts)
}

/** Deterministic gzip: fixed level and a zero header mtime. */
function gzipDeterministic(buffer) {
	return zlib.gzipSync(buffer, { level: 9, mtime: 0 })
}

/** Parse a ustar/pax stream (regular files, directories, prefix splitting). */
function readTar(buffer) {
	const entries = []
	let offset = 0
	let longName = null
	while (offset + 512 <= buffer.length) {
		const header = buffer.subarray(offset, offset + 512)
		if (header.every(byte => byte === 0)) break
		const readString = (start, length) => header.subarray(start, start + length).toString('utf8').replace(/\0.*$/s, '')
		const type = String.fromCharCode(header[156])
		const size = parseInt(header.subarray(124, 136).toString('utf8').trim(), 8)
		const prefix = readString(345, 155)
		const name = (prefix ? prefix + '/' : '') + readString(0, 100)
		offset += 512
		const data = buffer.subarray(offset, offset + size)
		// git archive prefixes the stream with a pax global header carrying
		// the commit ID; pax/GNU extended metadata members carry records in
		// their data block. 'path' records override the FOLLOWING entry's name
		// (used for overlong webpack vendor chunk names).
		if (type === 'g' || type === 'x' || type === 'X') {
			if (type !== 'g') {
				for (const match of data.toString('utf8').matchAll(/(?:^|\n)\d+ (\w+)=([^\n]*)\n/g)) {
					if (match[1] === 'path') longName = match[2]
				}
			} else {
				longName = null
			}
		} else if (type === 'L') {
			longName = data.toString('utf8').replace(/\0.*$/s, '')
		} else if (type === '0' || type === '7') {
			entries.push({ name: longName ?? name, type: 'file', data })
			longName = null
		} else if (type === '5') {
			entries.push({ name: longName ?? name, type: 'dir', data: Buffer.alloc(0) })
			longName = null
		} else {
			throw new Error(`Unsupported tar member type '${type}' for ${name}`)
		}
		offset += Math.ceil(size / 512) * 512
	}
	return entries
}

// ---------------------------------------------------------------------------
// Archive path safety and runtime selection
// ---------------------------------------------------------------------------

/** Reject traversal, absolute paths, backslashes and link-like names. */
function assertSafeArchiveName(name) {
	if (!name || name.includes('\0')) throw new Error(`Unsafe archive path: ${JSON.stringify(name)}`)
	if (name.startsWith('/') || name.startsWith('~')) throw new Error(`Unsafe archive path: ${name}`)
	if (name.includes('\\')) throw new Error(`Archive path uses a host path separator: ${name}`)
	const segments = name.split('/')
	if (segments.some(segment => segment === '..' || segment === '.' || !segment)) {
		throw new Error(`Archive path traversal or empty segment: ${name}`)
	}
	return name
}

function matchesForbidden(name, forbidden) {
	const segments = name.split('/')
	return forbidden.some(rule => rule.startsWith('*.')
		? segments.some(segment => segment.endsWith(rule.slice(1)))
		: segments.includes(rule))
}

/**
 * Select the packaged files from tracked build inputs + built outputs.
 * Non-allowlisted paths are silently skipped; allowlisted paths that match a
 * forbidden rule or are symlinks fail closed.
 * @param {Array<{path: string, mode: string}>} candidates tracked or built files
 * @param {string[]} allowlist top-level runtime entries
 * @param {string[]} forbidden forbidden names/patterns
 */
function selectRuntimeFiles(candidates, allowlist, forbidden) {
	const selected = []
	for (const candidate of candidates) {
		assertSafeArchiveName(candidate.path)
		const top = candidate.path.split('/')[0]
		const exact = allowlist.includes(candidate.path)
		if (!exact && !allowlist.includes(top)) continue
		if (matchesForbidden(candidate.path, forbidden)) {
			throw new Error(`Forbidden file matched the runtime package: ${candidate.path}`)
		}
		if (candidate.mode === '120000') throw new Error(`Symlinks are not allowed in the runtime package: ${candidate.path}`)
		selected.push(candidate)
	}
	return selected
}

// ---------------------------------------------------------------------------
// Source-vs-blob identity with CRLF/LF provenance
// ---------------------------------------------------------------------------

function classifyLineEndings(buffer) {
	const text = buffer.toString('utf8')
	const crlf = (text.match(/\r\n/g) ?? []).length
	const bareCr = (text.match(/\r(?!\n)/g) ?? []).length
	const lf = (text.match(/(?<!\r)\n/g) ?? []).length
	if (bareCr > 0) return 'cr'
	if (crlf > 0 && lf > 0) return 'mixed'
	return crlf > 0 ? 'crlf' : 'lf'
}

/** Git object ID of a blob: sha1("blob <size>\0" + content). */
function gitBlobId(data) {
	return sha1Hex(Buffer.concat([Buffer.from(`blob ${data.length}\0`), data]))
}

/** Compare archived bytes with the captured git blob. */
function compareToBlob(data, blobSha1) {
	if (gitBlobId(data) === blobSha1) return 'match'
	if (gitBlobId(Buffer.from(data.toString('utf8').replace(/\r\n/g, '\n'), 'utf8')) === blobSha1) return 'crlf'
	return 'drift'
}

// ---------------------------------------------------------------------------
// Asset contract (webpack stats vs reviewed reference inventory)
// ---------------------------------------------------------------------------

/**
 * Reachability check between the reviewed reference inventory and the
 * emitted webpack chunk graph. No basename-only heuristics: referenced names
 * are exact emitted asset names, and reachability follows the stats chunk
 * children relations from the entrypoint. Exceptions are named and reviewed.
 */
function checkAssetContract(stats, contract) {
	const violations = []
	// Emitted asset names carry the deterministic cache-bust query
	// (chorestrike-main.js?v=<epoch>); the reviewed inventory names assets
	// without it. Reachability compares raw chunk files on both sides.
	const baseName = name => String(name).split('?')[0]
	const assets = new Set((stats.assets ?? []).map(asset => baseName(asset.name)))
	const chunkFiles = new Map()
	for (const chunk of stats.chunks ?? []) {
		for (const file of chunk.files ?? []) chunkFiles.set(file, chunk)
	}
	const entry = stats.entrypoints?.[contract.entry ?? 'main']
	if (!entry) {
		return [`asset contract: entrypoint '${contract.entry ?? 'main'}' is missing from the build stats`]
	}
	const byId = new Map((stats.chunks ?? []).map(chunk => [chunk.id, chunk]))
	const reachable = new Set()
	const queue = [...(entry.chunks ?? [])]
	while (queue.length) {
		const id = queue.pop()
		if (reachable.has(id)) continue
		reachable.add(id)
		for (const child of byId.get(id)?.children ?? []) queue.push(child)
	}
	const reachableFiles = new Set()
	for (const id of reachable) {
		for (const file of byId.get(id)?.files ?? []) reachableFiles.add(file)
	}
	for (const referenced of contract.referenced ?? []) {
		if (!assets.has(referenced)) {
			violations.push(`asset contract: referenced asset '${referenced}' is not emitted by the build (dangling reference)`)
		}
	}
	for (const [file] of chunkFiles) {
		if (!reachableFiles.has(file) && !(contract.exceptions ?? []).includes(file)) {
			violations.push(`asset contract: emitted chunk '${file}' has no registered load site (orphaned chunk)`)
		}
	}
	for (const exception of contract.exceptions ?? []) {
		if (!chunkFiles.has(exception)) {
			violations.push(`asset contract: exception '${exception}' is not an emitted chunk (stale exception)`)
		}
	}
	return violations.sort()
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

function validatePackageConfig(config) {
	const fail = message => { throw new Error(`Invalid docker/package-build.json: ${message}`) }
	if (config?.schemaVersion !== 1) fail('schemaVersion must be 1')
	if (config.platform !== 'linux/amd64') fail('platform must be linux/amd64')
	for (const [key, imagePrefix] of [['node', 'node'], ['composer', 'composer'], ['php', 'php']]) {
		const tool = config[key]
		if (!VERSION.test(tool?.version)) fail(`${key}.version`)
		const expectedImage = new RegExp(`^${imagePrefix}:${tool?.version.replaceAll('.', '\\.')}(?:-[a-z0-9.-]+)?$`)
		if (typeof tool?.image !== 'string' || !expectedImage.test(tool.image)) fail(`${key}.image`)
		if (!DIGEST.test(tool?.digest) || !DIGEST.test(tool?.platformDigest)) fail(`${key}.digest`)
	}
	if (!Array.isArray(config.runtimeAllowlist) || config.runtimeAllowlist.length === 0) fail('runtimeAllowlist')
	if (!Array.isArray(config.forbidden) || config.forbidden.length === 0) fail('forbidden')
	if (!Array.isArray(config.assetContract?.referenced) || config.assetContract.referenced.length === 0) fail('assetContract.referenced')
	if (!Array.isArray(config.assetContract?.exceptions)) fail('assetContract.exceptions')
	return config
}

// ---------------------------------------------------------------------------
// Docker builder orchestration
// ---------------------------------------------------------------------------

function builderTag(head) {
	return `chorestrike-package-builder:${head.slice(0, 12)}`
}

/** Local-only tag for a verified pinned base image (buildkit re-resolves
 * digest-pinned FROM references against the registry, which is unreliable;
 * the pin itself is enforced by the RepoDigests check below). */
function pinnedBaseTag(tool) {
	const repository = tool.image.split(':')[0]
	return `chorestrike-package-base:${repository}-${tool.platformDigest.slice(7, 19)}`
}

function verifyPinnedTag(tool, tag, invoke) {
	const pinned = `${tool.image.split(':')[0]}@${tool.platformDigest}`
	const result = invoke('docker', ['image', 'inspect', tag, '--format', '{{json .RepoDigests}}'], { encoding: 'utf8' })
	if (result.status !== 0) return false
	let digests = []
	try {
		digests = JSON.parse(result.stdout || '[]')
	} catch {
		return false
	}
	return digests.includes(pinned)
}

/** Resolve the pinned base image to a verified local tag without depending
 * on registry availability: reuse an already-verified local tag, otherwise
 * adopt a locally present pinned digest, otherwise pull (bounded retries). */
function ensurePinnedBase(tool, invoke = run) {
	const repository = tool.image.split(':')[0]
	const pinned = `${repository}@${tool.platformDigest}`
	const tag = pinnedBaseTag(tool)
	if (invoke('docker', ['image', 'inspect', tag], { encoding: 'utf8' }).status === 0 && verifyPinnedTag(tool, tag, invoke)) {
		return tag
	}
	if (invoke('docker', ['image', 'inspect', pinned], { encoding: 'utf8' }).status === 0) {
		if (invoke('docker', ['tag', pinned, tag], { encoding: 'utf8' }).status !== 0) throw new Error(`Cannot tag the pinned base image ${pinned}`)
		if (verifyPinnedTag(tool, tag, invoke)) return tag
	}
	let last = null
	for (let attempt = 1; attempt <= 3; attempt++) {
		// --quiet keeps progress bars out of captured pipes (they can break
		// nested stdio chains); the digest pin provides the identity.
		const pull = invoke('docker', ['pull', '--quiet', '--platform', 'linux/amd64', pinned], { encoding: 'utf8', timeout: 600000, maxBuffer: 64 * 1024 * 1024 })
		if (pull.status === 0) {
			if (invoke('docker', ['tag', pinned, tag], { encoding: 'utf8' }).status !== 0) throw new Error(`Cannot tag the pinned base image ${pinned}`)
			if (verifyPinnedTag(tool, tag, invoke)) return tag
			throw new Error(`Pinned base image verification failed: ${tag} does not carry ${pinned}`)
		}
		last = pull
		if (attempt < 3) process.stderr.write(`[build-package] pinned base pull attempt ${attempt} failed; retrying ${pinned}\n`)
	}
	throw new Error(`Cannot fetch the pinned base image ${pinned}; refusing to build with an unverifiable toolchain: ${(last?.stderr ?? '').trim().slice(0, 200)}`)
}

function buildBuilderImage(config, head, invoke = run) {
	const nodeBase = ensurePinnedBase(config.node, invoke)
	const composerBase = ensurePinnedBase(config.composer, invoke)
	const phpBase = ensurePinnedBase(config.php, invoke)
	const args = ['build', '--quiet', '--platform', config.platform, '--file', 'docker/Dockerfile.package',
		'--tag', builderTag(head),
		'--build-arg', `NODE_IMAGE=${nodeBase}`,
		'--build-arg', `COMPOSER_IMAGE=${composerBase}`,
		'--build-arg', `PHP_IMAGE=${phpBase}`,
		'.']
	let lastError = null
	// BuildKit occasionally fails transiently even on local inputs; the
	// pinned, verified base tags make a bounded retry safe (identical
	// inputs, no downgrade possible).
	for (let attempt = 1; attempt <= 3; attempt++) {
		const result = invoke('docker', args, { encoding: 'utf8', timeout: 600000, maxBuffer: 64 * 1024 * 1024 })
		if (result.status === 0) return
		lastError = result
		if (attempt < 3) process.stderr.write(`[build-package] builder image build attempt ${attempt} failed; retrying with the same pinned inputs\n`)
	}
	const diagnostics = (lastError?.stderr ?? '').split(/\r?\n/).filter(line => /error|not found|denied/i.test(line)).slice(-3)
	throw new Error(`Package builder image build failed; inputs were not packaged${diagnostics.length ? `:\n  ${diagnostics.join('\n  ')}` : ''}`)
}

const CONTAINER_BUILD_SCRIPT = [
	'set -euo pipefail',
	'mkdir -p /package',
	'cd /package',
	'tar -xf /inputs/source.tar', // build inputs: git archive HEAD (read-only mount)
	// Tool summaries must never touch stdout: it carries the transport tar.
	// Logs travel inside the transport for the runner's evidence records.
	'npm ci --no-audit --no-fund --loglevel=error >/tmp/npm.log 2>&1 || { cat /tmp/npm.log >&2; exit 1; }',
	'composer install --no-dev --no-interaction --prefer-dist --no-progress >/tmp/composer.log 2>&1 || { cat /tmp/composer.log >&2; exit 1; }',
	'NODE_ENV=production npx webpack --mode production --json /tmp/stats.json 1>/tmp/webpack.log',
	'{ node --version; npm --version; composer --version --no-ansi 2>/dev/null | head -n 1; } > /tmp/versions.txt',
	'tar --format=posix -C /package -czf /tmp/built.tar.gz js vendor',
	'tar --format=posix -C /tmp -cf /tmp/transport.tar built.tar.gz stats.json versions.txt webpack.log npm.log composer.log',
	'cat /tmp/transport.tar',
].join('\n')

/** Build js/vendor + stats inside the pinned builder from a HEAD archive. */
function buildInContainer(config, state, archivePath, invoke = run) {
	const result = invoke('docker', ['run', '--rm',
		'-v', `${archivePath}:/inputs/source.tar:ro`,
		'-e', `SOURCE_DATE_EPOCH=${state.epoch}`,
		builderTag(state.head), 'bash', '-c', CONTAINER_BUILD_SCRIPT],
	{ encoding: 'buffer', timeout: 900000, maxBuffer: 512 * 1024 * 1024 })
	const stdout = result.stdout ?? Buffer.alloc(0)
	const stderr = result.stderr ? result.stderr.toString('utf8') : ''
	if (result.status !== 0 || !stdout.length) {
		throw new Error(`Package build inside the pinned builder failed (exit ${result.status}):\n${stderr}`)
	}
	if (stderr.trim()) process.stderr.write(stderr)
	const transport = new Map(readTar(stdout).map(entry => [entry.name, entry.data]))
	for (const member of ['built.tar.gz', 'stats.json', 'versions.txt', 'npm.log', 'composer.log']) {
		if (!transport.has(member)) throw new Error(`Builder output is missing '${member}'`)
	}
	return {
		files: readTar(zlib.gunzipSync(transport.get('built.tar.gz'))),
		stats: JSON.parse(transport.get('stats.json').toString('utf8')),
		versions: transport.get('versions.txt').toString('utf8').split('\n').filter(Boolean),
		buildLog: [transport.get('webpack.log').toString('utf8'), transport.get('npm.log').toString('utf8'), transport.get('composer.log').toString('utf8')].join('\n'),
	}
}

// ---------------------------------------------------------------------------
// Package assembly + manifests
// ---------------------------------------------------------------------------

/**
 * Assemble the deterministic runtime archive from tracked candidate files
 * (with their ls-tree modes, so symlinks fail closed) and built files.
 */
function assemblePackage({ trackedFiles, builtFiles, allowlist, forbidden, epoch }) {
	const candidates = [
		...trackedFiles.map(entry => ({ path: entry.path, mode: entry.mode })),
		...builtFiles.filter(entry => entry.type === 'file').map(entry => ({ path: entry.name, mode: '100644' })),
	]
	const selected = selectRuntimeFiles(candidates, allowlist, forbidden)
	const dataByPath = new Map([
		...trackedFiles.map(entry => [entry.path, entry.data]),
		...builtFiles.filter(entry => entry.type === 'file').map(entry => [entry.name, entry.data]),
	])
	const dirs = new Set(['.'])
	for (const file of selected) {
		const segments = file.path.split('/')
		for (let i = 1; i < segments.length; i++) dirs.add(segments.slice(0, i).join('/'))
	}
	const entries = []
	for (const dir of dirs) {
		entries.push({ name: dir === '.' ? '.' : dir, type: 'dir', mode: 0o755, mtime: epoch, data: Buffer.alloc(0) })
	}
	const fileManifest = []
	for (const file of selected) {
		const data = dataByPath.get(file.path)
		if (!data) throw new Error(`Runtime file has no captured bytes: ${file.path}`)
		entries.push({ name: file.path, type: 'file', mode: 0o644, mtime: epoch, data })
		fileManifest.push({ path: file.path, sha256: sha256Hex(data), size: data.length })
	}
	return { tar: buildTar(entries), files: fileManifest.sort((a, b) => a.path < b.path ? -1 : 1) }
}

function buildManifests(archiveBuffer, files, sourceEntries, contractReport, state, config, versions) {
	return {
		archive: { name: ARCHIVE_NAME, sha256: sha256Hex(archiveBuffer), bytes: archiveBuffer.length },
		candidate: { commit: state.head, branch: state.branch, sourceDateEpoch: state.epoch },
		tools: {
			node: config.node.version,
			npm: versions?.[1] ?? null,
			php: config.php.version,
			composer: versions?.[2] ?? null,
			nodeImage: `${config.node.image}@${config.node.platformDigest}`,
			composerImage: `${config.composer.image}@${config.composer.platformDigest}`,
			phpImage: `${config.php.image}@${config.php.platformDigest}`,
		},
		files,
		source: sourceEntries,
		assetContract: contractReport,
	}
}

// ---------------------------------------------------------------------------
// Orchestration
// ---------------------------------------------------------------------------

function buildPackage({ outDir, io = { run, readConfig: () => JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8')) } } = {}) {
	const config = validatePackageConfig(io.readConfig())
	const state = readGitState(io.run)
	const archiveBuffer = io.run('git', ['archive', 'HEAD'], { encoding: 'buffer' }).stdout
	const tree = readTar(archiveBuffer)
	const tracked = readTree(io.run)

	buildBuilderImage(config, state.head, io.run)
	// Large stdin streams break spawnSync on Windows (libuv EOF); mount the
	// HEAD archive read-only instead of piping it into the container.
	const archivePath = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'cs-build-')), 'source.tar')
	let built = null
	try {
		fs.writeFileSync(archivePath, archiveBuffer)
		built = buildInContainer(config, state, archivePath, io.run)
	} finally {
		fs.rmSync(path.dirname(archivePath), { recursive: true, force: true })
	}

	// Source-vs-blob identity: every tracked file must match its git blob,
	// allowing CRLF->LF-only normalization with documented provenance.
	const treeByPath = new Map(tree.filter(entry => entry.type === 'file').map(entry => [entry.name, entry.data]))
	const trackedFiles = []
	const sourceEntries = []
	for (const entry of tracked) {
		const data = treeByPath.get(entry.path)
		if (!data) throw new Error(`Tracked file missing from the HEAD archive: ${entry.path}`)
		const comparison = compareToBlob(data, entry.sha1)
		if (comparison === 'drift') throw new Error(`Source drift: ${entry.path} does not match its git blob ${entry.sha1}`)
		trackedFiles.push({ path: entry.path, mode: entry.mode, data })
		sourceEntries.push({
			path: entry.path, blob: entry.sha1, sha256: sha256Hex(data),
			lineEndings: classifyLineEndings(data), comparison,
		})
	}

	const contractViolations = checkAssetContract(built.stats, config.assetContract)
	if (contractViolations.length) {
		throw new Error(`Asset contract violation(s):\n  - ${contractViolations.join('\n  - ')}`)
	}
	const contractReport = {
		entry: config.assetContract.entry ?? 'main',
		referenced: config.assetContract.referenced,
		exceptions: config.assetContract.exceptions,
		emittedAssets: (built.stats.assets ?? []).map(asset => asset.name).sort(),
		reachableChunkFiles: [...new Set((built.stats.chunks ?? []).flatMap(chunk => chunk.files ?? []))].sort(),
		violations: contractViolations,
	}

	const assembled = assemblePackage({
		trackedFiles, builtFiles: built.files,
		allowlist: config.runtimeAllowlist, forbidden: config.forbidden, epoch: state.epoch,
	})
	const archive = gzipDeterministic(assembled.tar)
	const manifests = buildManifests(archive, assembled.files, sourceEntries, contractReport, state, config, built.versions)

	fs.mkdirSync(outDir, { recursive: true })
	fs.writeFileSync(path.join(outDir, ARCHIVE_NAME), archive)
	fs.writeFileSync(path.join(outDir, 'package-manifest.json'), JSON.stringify(manifests, null, 2) + '\n')
	fs.writeFileSync(path.join(outDir, 'identity.json'), JSON.stringify({
		status: 'package-identity-record',
		gate: 'CS-PLAN-083 WP2',
		...manifests,
	}, null, 2) + '\n')
	if (built.buildLog.trim()) fs.writeFileSync(path.join(outDir, 'webpack.log'), built.buildLog)
	return { outDir, archive, manifests }
}

function verifyReproducible(options = {}) {
	const base = options.outDir ?? path.join('build', 'package', `run-${Date.now().toString(36)}`)
	const first = buildPackage({ ...options, outDir: path.join(base, 'build-1') })
	const second = buildPackage({ ...options, outDir: path.join(base, 'build-2') })
	const sameArchive = sha256Hex(first.archive) === sha256Hex(second.archive)
	const sameFiles = JSON.stringify(first.manifests.files) === JSON.stringify(second.manifests.files)
	if (!sameArchive || !sameFiles) {
		throw new Error(`Reproducibility proof FAILED: archive match=${sameArchive}, per-file manifest match=${sameFiles}`)
	}
	return {
		status: 'reproducible',
		outDir: base,
		archiveSha256: sha256Hex(first.archive),
		files: first.manifests.files.length,
		manifests: first.manifests,
	}
}

// ---------------------------------------------------------------------------
// Self-test (synthetic fixtures — no Docker, no git)
// ---------------------------------------------------------------------------

function throws(fn, pattern) {
	try {
		fn()
		return false
	} catch (error) {
		return pattern.test(error.message)
	}
}

function execNode(code) {
	// @nextcloud/webpack-vue-config derives the app identity from the npm env.
	const wrapped = 'process.env.npm_package_name=process.env.npm_package_name||"chorestrike";'
		+ 'process.env.npm_package_version=process.env.npm_package_version||"0.0.0";'
		+ code
	const result = spawnSync(process.execPath, ['-e', wrapped], { cwd: ROOT, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 })
	if (result.error) throw result.error
	if (result.status !== 0) throw new Error(result.stderr || 'node -e failed')
	return result.stdout
}

function selfTest() {
	const failures = []
	const ok = (label, condition) => {
		console.log(`  ${condition ? 'ok   ' : 'FAIL '} ${label}`)
		if (!condition) failures.push(label)
	}

	console.log('self-test: clean-HEAD gate')
	const probeOf = stdout => () => ({ stdout })
	ok('dirty tree is refused', throws(() => readGitState(probeOf('a'.repeat(40))), /dirty tree/))
	ok('detached HEAD is refused', throws(
		() => readGitState((command, args) => ({ stdout: args[0] === 'rev-parse' ? 'b'.repeat(40) : '' })), /detached HEAD/))
	ok('clean branch tip is accepted', readGitState((command, args) => ({
		stdout: args[0] === 'rev-parse' ? 'c'.repeat(40)
			: args[0] === 'symbolic-ref' ? 'refs/heads/master'
				: args[0] === 'status' ? '' : '1700000000',
	})).head === 'c'.repeat(40))

	console.log('self-test: archive metadata and determinism')
	const files = [
		{ path: 'js/chorestrike-main.js', data: Buffer.from('main') },
		{ path: 'lib/AppInfo/Application.php', data: Buffer.from('php') },
	]
	const makeEntries = () => [
		{ name: 'js', type: 'dir', mode: 0o755, mtime: 1700000000, data: Buffer.alloc(0) },
		...files.map(file => ({ name: file.path, type: 'file', mode: 0o644, mtime: 1700000000, data: file.data })),
	]
	const tarA = buildTar(makeEntries())
	const tarB = buildTar([...makeEntries()].reverse())
	ok('tar is order-independent (sorted)', tarA.equals(tarB))
	ok('gzip output is deterministic', gzipDeterministic(tarA).equals(gzipDeterministic(tarA)))
	const parsed = readTar(tarA)
	ok('tar round-trip preserves names', parsed.map(entry => entry.name).join() === 'js,js/chorestrike-main.js,lib/AppInfo/Application.php')
	ok('tar round-trip preserves bytes', parsed[1].data.toString() === 'main')
	// git archive prefixes a pax global header (commit ID): it must be skipped.
	const paxRecord = Buffer.from('52 comment=commit ' + 'e'.repeat(40) + '\n')
	const paxHeader = Buffer.alloc(512)
	paxHeader.write('pax_global_header', 0)
	paxHeader.write(octal(0o644, 8), 100, 8)
	paxHeader.write(octal(paxRecord.length, 12), 124, 12)
	paxHeader.write('g', 156, 1)
	let paxSum = 0
	paxHeader.fill(0x20, 148, 156)
	for (const byte of paxHeader) paxSum += byte
	paxHeader.write(paxSum.toString(8).padStart(6, '0') + '\0 ', 148, 8)
	const withPax = Buffer.concat([paxHeader, paxRecord, Buffer.alloc(512 - paxRecord.length), tarA])
	ok('pax global headers are skipped', readTar(withPax).map(entry => entry.name).join() === parsed.map(entry => entry.name).join())
	// Overlong webpack vendor chunk names travel in pax path records.
	const longName = `js/chorestrike-vendors-${'node_modules_x_'.repeat(8)}bundle-BIbJGxX-515202.js`
	const longTar = buildTar([{ name: longName, type: 'file', mode: 0o644, mtime: 1700000000, data: Buffer.from('vendor') }])
	const longParsed = readTar(longTar)
	ok('pax path records round-trip', longParsed.length === 1 && longParsed[0].name === longName && longParsed[0].data.toString() === 'vendor')
	ok('ownership is 0:0', tarA.subarray(108, 124).equals(Buffer.from('0000000\0'.repeat(2))))
	// The first sorted entry is the 'js' directory (0755); the file follows.
	ok('file mode is 0644', tarA.subarray(512 + 100, 512 + 108).toString().startsWith('0000644'))
	ok('dir mode is 0755', tarA.subarray(100, 108).toString().startsWith('0000755'))
	ok('mtime is the packaging epoch', tarA.subarray(136, 148).toString().startsWith(octal(1700000000, 12).slice(0, 11)))

	console.log('self-test: archive path safety and runtime selection')
	ok('traversal is rejected', throws(() => assertSafeArchiveName('a/../b'), /traversal/))
	ok('absolute path is rejected', throws(() => assertSafeArchiveName('/etc/passwd'), /Unsafe/))
	ok('backslash (host separator) is rejected', throws(() => assertSafeArchiveName('a\\b'), /separator/))
	ok('empty segment is rejected', throws(() => assertSafeArchiveName('a//b'), /traversal/))
	const allowlist = ['lib', 'js', 'LICENSE']
	const forbidden = ['*.map', 'node_modules', 'tests']
	const selected = selectRuntimeFiles([
		{ path: 'lib/AppInfo/Application.php', mode: '100644' },
		{ path: 'js/chorestrike-main.js', mode: '100644' },
		{ path: 'docs/readme.md', mode: '100644' },
		{ path: 'LICENSE', mode: '100644' },
	], allowlist, forbidden).map(entry => entry.path)
	ok('allowlist selects runtime files only', selected.join() === 'lib/AppInfo/Application.php,js/chorestrike-main.js,LICENSE')
	ok('source maps are rejected', throws(() => selectRuntimeFiles([{ path: 'js/a.js.map', mode: '100644' }], allowlist, forbidden), /Forbidden/))
	ok('symlinks are rejected', throws(() => selectRuntimeFiles([{ path: 'lib/link.php', mode: '120000' }], allowlist, forbidden), /Symlinks/))
	ok('node_modules is rejected', throws(() => selectRuntimeFiles([{ path: 'js/node_modules/x.js', mode: '100644' }], allowlist, forbidden), /Forbidden/))

	console.log('self-test: source-vs-blob identity and CRLF provenance')
	const blob = gitBlobId(Buffer.from('line\n'))
	ok('exact blob match passes', compareToBlob(Buffer.from('line\n'), blob) === 'match')
	ok('CRLF-only drift is classified provenance', compareToBlob(Buffer.from('line\r\n'), blob) === 'crlf')
	ok('content drift fails', compareToBlob(Buffer.from('other\n'), blob) === 'drift')
	ok('lf classification', classifyLineEndings(Buffer.from('a\nb\n')) === 'lf')
	ok('crlf classification', classifyLineEndings(Buffer.from('a\r\n')) === 'crlf')
	ok('mixed classification', classifyLineEndings(Buffer.from('a\nb\r\n')) === 'mixed')

	console.log('self-test: asset contract')
	const stats = (chunks, assets, entryChunks) => ({
		assets: assets.map(name => ({ name })),
		chunks,
		entrypoints: { main: { chunks: entryChunks } },
	})
	const contract = { entry: 'main', referenced: ['chorestrike-main.js'], exceptions: [] }
	ok('valid entry+shared/vendor graph passes', checkAssetContract(stats(
		[{ id: 0, files: ['chorestrike-main.js'], children: [1] }, { id: 1, files: ['chorestrike-vendors.js'], children: [] }],
		['chorestrike-main.js', 'chorestrike-vendors.js'], [0]), contract).length === 0)
	ok('dangling reference is rejected', checkAssetContract(stats([], [], [0]), contract).some(v => v.includes('dangling')))
	ok('orphaned chunk is rejected', checkAssetContract(stats(
		[{ id: 0, files: ['chorestrike-main.js'], children: [] }, { id: 2, files: ['chorestrike-orphan.js'], children: [] }],
		['chorestrike-main.js', 'chorestrike-orphan.js'], [0]), contract).some(v => v.includes('orphaned')))
	ok('named exception covers a deliberate orphan', checkAssetContract(stats(
		[{ id: 0, files: ['chorestrike-main.js'], children: [] }, { id: 2, files: ['chorestrike-orphan.js'], children: [] }],
		['chorestrike-main.js', 'chorestrike-orphan.js'], [0]),
	{ ...contract, exceptions: ['chorestrike-orphan.js'] }).length === 0)
	ok('stale exception is rejected', checkAssetContract(stats([{ id: 0, files: ['chorestrike-main.js'], children: [] }],
		['chorestrike-main.js'], [0]),
	{ ...contract, exceptions: ['chorestrike-gone.js'] }).some(v => v.includes('stale exception')))
	ok('missing entrypoint is rejected', checkAssetContract({ assets: [], chunks: [] }, contract).some(v => v.includes('entrypoint')))

	console.log('self-test: deterministic webpack epoch')
	const configWith = epoch => execNode(`delete process.env.SOURCE_DATE_EPOCH;${epoch ? `process.env.SOURCE_DATE_EPOCH=${JSON.stringify(epoch)};` : ''}const c = require('./webpack.config.js'); process.stdout.write(String(c.output.filename))`)
	ok('epoch changes the emitted filename', configWith('1700000000').includes('1700000000'))
	ok('invalid epoch fails the build', throws(() => execNode("process.env.SOURCE_DATE_EPOCH='nope'; require('./webpack.config.js')"), /SOURCE_DATE_EPOCH/))
	ok('dev builds keep Date.now behavior', /v=\d{13}/.test(configWith(null)))

	if (failures.length) {
		console.error(`\nself-test FAILED: ${failures.length} check(s)`)
		return 1
	}
	console.log('\nself-test passed: clean-HEAD gate, deterministic archive, path safety, blob identity and asset contract all verified.')
	return 0
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main() {
	const args = process.argv.slice(2)
	if (args.includes('--self-test')) return selfTest()
	const reproducible = args.includes('--verify-reproducible')
	const rest = args.filter(arg => arg !== '--verify-reproducible')
	let outDir = null
	const outIndex = rest.indexOf('--out')
	if (outIndex >= 0) {
		outDir = rest[outIndex + 1]
		rest.splice(outIndex, 2)
	}
	if (rest.length) throw new Error(`Unknown build-package argument: ${rest[0]}`)
	const target = outDir ?? path.join('build', 'package', `run-${Date.now().toString(36)}`)
	const result = reproducible ? verifyReproducible({ outDir: target }) : buildPackage({ outDir: target })
	console.log(JSON.stringify({
		status: result.status ?? 'package-built',
		outDir: path.resolve(target),
		archive: result.archiveSha256 ?? result.manifests.archive.sha256,
		files: result.manifests?.files.length ?? result.files,
	}, null, 2))
	return 0
}

module.exports = {
	readGitState, readTree, buildTar, readTar, gzipDeterministic, assertSafeArchiveName,
	selectRuntimeFiles, classifyLineEndings, compareToBlob, gitBlobId, checkAssetContract,
	validatePackageConfig, pinnedBaseTag, ensurePinnedBase, buildBuilderImage, buildInContainer, assemblePackage,
	buildManifests, buildPackage, verifyReproducible, sha256Hex, sha1Hex, octal, paxRecord, fitsUstar, CONTAINER_BUILD_SCRIPT,
}

if (require.main === module) {
	try {
		process.exitCode = main()
	} catch (error) {
		console.error(`[build-package] FAILED: ${error.message}`)
		process.exitCode = 1
	}
}
