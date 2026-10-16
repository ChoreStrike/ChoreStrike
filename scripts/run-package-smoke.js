#!/usr/bin/env node
/**
 * @copyright Copyright (c) 2026 ChoreStrike Contributors
 * @license MIT
 *
 * CS-PLAN-083 WP3: wired package smoke — one command for pre-handoff
 * qualification. Replaces the manual target-JSON orchestration:
 *
 *   build-package (clean HEAD, pinned builder) → safe extraction of the
 *   manifest-verified archive → per runtime row (NC35 canonical, NC34
 *   compatibility): owned `cs082-*` Compose project up → fail-closed package
 *   target validation (separate from scripts/test-target.js's true-marker
 *   requirements) → alias-absence proof → owner-smoke `setup` (cold first
 *   bootstrap) → `verify` (six owner cases) → evidence identity checks →
 *   owned teardown.
 *
 * Both rows consume the SAME extracted, hash-verified archive bytes — never a
 * staging directory that could differ from the archive. Evidence binds the
 * candidate commit, packaging inputs, archive hash, frozen runtime/image
 * identity and the unique run/row before AND after the browser checks.
 *
 * Failure contract: failed or interrupted fixtures and evidence are retained
 * for diagnosis; cleanup may only touch resources created and verified for
 * this run (Compose project-scoped down --volumes on the success path only).
 * Never reuse a failed first-install fixture as fresh evidence; a red setup
 * or verify fails qualification — no reset-and-retry that would turn a failed
 * cold start into a pass.
 *
 * This gate supplements, never replaces, the full canonical browser gate.
 *
 * Self-test: `node scripts/run-package-smoke.js --self-test` exercises the
 * target validation, evidence freshness, drift detection and orchestration
 * negatives with injected subprocesses — no Docker, no browser.
 *
 * npm: `npm run test:package:smoke`
 */

'use strict'

const { spawnSync } = require('node:child_process')
const crypto = require('node:crypto')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const zlib = require('node:zlib')
const { readGitState, readTar, buildTar, sha256Hex, buildPackage, assertSafeArchiveName } = require('./build-package')
const { validatePackageTarget, assertPackageCoherence } = require('./package-target')
const { readInventory, selectRuntime, selectionHash, verifyImage, execute, probe } = require('./test-runtime')

const ROOT = path.resolve(__dirname, '..')
const COMPOSE_FILE = path.join('docker', 'compose.package-smoke.yml')
const OWNER_SMOKE = path.join(ROOT, 'tests', 'package', 'owner-smoke.cjs')
const PROJECT_PATTERN = /^cs082-pkg-[a-z0-9]+(-aio)?$/
const ROWS = [{ row: 'primary' }, { row: 'aio' }]

function run(command, args, options = {}) {
	const result = spawnSync(command, args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024, ...options })
	if (result.error) throw new Error(`Failed to execute ${command}: ${result.error.message}`)
	return result
}

// ---------------------------------------------------------------------------
// Deterministic, separately fail-closed package target validation
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Extraction + drift checks
// ---------------------------------------------------------------------------

function extractArchive(archiveBuffer, files, extractDir) {
	const members = readTar(zlib.gunzipSync(archiveBuffer))
	for (const entry of members) if (entry.name !== '.') assertSafeArchiveName(entry.name)
	const entries = new Map(members.filter(entry => entry.type === 'file').map(entry => [entry.name, entry.data]))
	if (entries.size !== files.length || members.filter(entry => entry.type === 'file').length !== files.length) throw new Error('Archive inventory mismatch against package manifest')
	for (const file of files) assertSafeArchiveName(file.path)
	fs.mkdirSync(extractDir)
	for (const file of files) {
		const data = entries.get(file.path)
		if (!data) throw new Error(`Archive is missing a manifest file: ${file.path}`)
		if (sha256Hex(data) !== file.sha256) throw new Error(`Archive byte drift against the package manifest: ${file.path}`)
		const destination = path.join(extractDir, ...file.path.split('/'))
		fs.mkdirSync(path.dirname(destination), { recursive: true })
		fs.writeFileSync(destination, data)
	}
	return verifyExtractedHashes(extractDir, files)
}

function verifyExtractedHashes(extractDir, files) {
	const expected = new Set(files.map(file => assertSafeArchiveName(file.path)))
	const directories = new Set([''])
	for (const name of expected) {
		const parts = name.split('/')
		for (let i = 1; i < parts.length; i++) directories.add(parts.slice(0, i).join('/'))
	}
	const visit = relative => {
		const full = path.join(extractDir, relative)
		const stat = fs.lstatSync(full)
		if (stat.isSymbolicLink()) throw new Error('Extracted archive contains a link')
		if (stat.isDirectory()) {
			if (!directories.has(relative)) throw new Error('Extracted archive directory inventory drift')
			for (const name of fs.readdirSync(full)) visit(relative ? `${relative}/${name}` : name)
		} else if (!stat.isFile() || !expected.has(relative)) throw new Error('Extracted archive file inventory drift')
	}
	visit('')
	for (const file of files) {
		const data = fs.readFileSync(path.join(extractDir, ...file.path.split('/')))
		if (sha256Hex(data) !== file.sha256) throw new Error(`Extracted archive drifted from the package manifest: ${file.path}`)
	}
	return true
}

// ---------------------------------------------------------------------------
// Evidence freshness/identity
// ---------------------------------------------------------------------------

/**
 * Tolerance for filesystem timestamp granularity.
 *
 * Linux stamps mtime from a coarse kernel clock that can lag the fine-grained
 * `Date.now()` the caller samples, so a report written microseconds after
 * `sinceMs` can carry an mtime marginally *before* it and look stale. That
 * false rejection broke the first real CI run of this suite (CS-PLAN-085).
 * The guard exists to reject a *previous* run's report — those are minutes
 * old and land in a different run directory — so absorbing a couple of
 * seconds of clock skew keeps the intent intact.
 */
const REPORT_MTIME_TOLERANCE_MS = 2000

function assertReportFresh(reportFile, { project, action, cases }, sinceMs) {
	if (!fs.existsSync(reportFile)) throw new Error(`Missing owner-smoke report: ${reportFile}`)
	if (fs.statSync(reportFile).mtimeMs < sinceMs - REPORT_MTIME_TOLERANCE_MS) {
		throw new Error(`Stale owner-smoke report (predates this run): ${reportFile}`)
	}
	const report = JSON.parse(fs.readFileSync(reportFile, 'utf8'))
	if (report.project !== project || report.action !== action) throw new Error('Owner-smoke report belongs to a different run or action')
	if (!Array.isArray(report.results) || report.results.length !== cases) {
		throw new Error(`Owner-smoke report '${action}' must record exactly ${cases} case(s)`)
	}
	return report
}

// ---------------------------------------------------------------------------
// Compose orchestration
// ---------------------------------------------------------------------------

function composeArguments(settings, args) {
	return ['compose', '--project-name', settings.project, '--file', COMPOSE_FILE, ...args]
}

function composeEnvironment(settings, selection) {
	return {
		...process.env,
		PKG_IMAGE: settings.imageId,
		PKG_EXTRACT_DIR: settings.extractDir,
		PKG_RUN_TOKEN: settings.token,
		PKG_PGSQL_IMAGE: `${selection.databases.pgsql.image}@${selection.databases.pgsql.digest}`,
	}
}

/** No leftover containers from a previous run may share the owned project. */
function assertFreshProject(settings, execute = run) {
	for (const args of [
		['ps', '--all', '--filter', `label=com.docker.compose.project=${settings.project}`, '--format', '{{.Names}}'],
		['ps', '--all', '--filter', `name=^${settings.project}-`, '--format', '{{.Names}}'],
		['volume', 'ls', '--filter', `name=${settings.project}_`, '--format', '{{.Name}}'],
		['network', 'ls', '--filter', `name=${settings.project}_`, '--format', '{{.Name}}'],
	]) {
		const result = execute('docker', args)
		if (result.status !== 0 || result.signal || result.error || result.stdout.trim()) throw new Error(`Package smoke project '${settings.project}' already has resources or its probe failed; refusing to mutate foreign fixtures`)
	}
}

function inspectOwnedResources(settings, invoke = run) {
	const resources = [
		['container', `${settings.project}-db-pgsql-1`], ['container', `${settings.project}-nextcloud-pgsql-1`],
		...['db', 'config', 'data'].map(name => ['volume', `${settings.project}_${name}`]),
		['network', `${settings.project}_package-smoke`],
	]
	return resources.map(([kind, name]) => {
		const result = invoke('docker', [kind, 'inspect', name])
		if (result.status !== 0 || result.signal || result.error) throw new Error('Package resource ownership probe failed')
		const resource = JSON.parse(result.stdout)[0]
		const labels = kind === 'container' ? resource.Config?.Labels : resource.Labels
		if (labels?.['com.docker.compose.project'] !== settings.project || labels?.['org.chorestrike.package-smoke-run'] !== settings.token) throw new Error('Package resource ownership mismatch')
		return { kind, name, id: resource.Id ?? resource.Name, created: resource.Created ?? resource.CreatedAt }
	})
}

function defaultImageName(selection) {
	return `cs082-runtime-${selection.nextcloud.version}-local`
}

function ownerSmoke(targetFile, extractDir, reportFile, action, invoke = run) {
	const result = invoke(process.execPath, [OWNER_SMOKE, targetFile, extractDir, reportFile, action],
		{ stdio: 'inherit', timeout: 900000, encoding: 'utf8' })
	if (result.error || result.signal || result.status !== 0) {
		throw new Error(`owner-smoke '${action}' failed or was interrupted (exit ${result.status ?? result.signal}); the fixture is retained for diagnosis`)
	}
}

// ---------------------------------------------------------------------------
// One runtime row
// ---------------------------------------------------------------------------

function runRow({ row, inventory, state, built, extractDir, runDir, project, io }) {
	const selection = selectRuntime(inventory, row)
	const imageName = io.imageName(selection, row)
	const image = io.verifyImage(selection, imageName, row)
	const settings = {
		project,
		row,
		imageId: image.imageId,
		extractDir: path.resolve(extractDir),
		inventoryHash: selectionHash(selection),
		token: crypto.randomUUID(),
	}
	if (!PROJECT_PATTERN.test(project)) throw new Error(`Invalid owned package project name: ${project}`)
	io.assertFreshProject(settings)
	const evidence = {
		row,
		project,
		imageName,
		imageId: image.imageId,
		inventoryHash: settings.inventoryHash,
		nextcloud: selection.nextcloud.version,
		internalVersion: selection.nextcloud.internalVersion,
		candidate: state.head,
	}
	try {
		io.compose(settings, selection, ['up', '--detach', '--no-build', '--pull', 'never', '--wait', '--wait-timeout', '600'])
		const resources = io.inspectOwnedResources(settings)
		const containerName = `${project}-nextcloud-pgsql-1`
		const container = JSON.parse(io.run('docker', ['inspect', containerName]).stdout)[0]
		const bindings = Object.values(container?.NetworkSettings?.Ports ?? {}).flat().filter(Boolean)
		const port = bindings.find(binding => binding.HostIp === '127.0.0.1')?.HostPort
		if (!port) throw new Error('Package smoke container published no numeric loopback port')
		const target = { project, container: containerName, url: `http://127.0.0.1:${port}`, image: image.imageId, internalVersion: selection.nextcloud.internalVersion }
		validatePackageTarget(container, target, settings.extractDir)
		io.assertAliasAbsence(target)
		io.assertOccCoherence(target, selection)
		const targetFile = path.join(runDir, `target-${row}.json`)
		io.writeFile(targetFile, JSON.stringify(target, null, 2) + '\n')

		const setupReport = path.join(runDir, `setup-${row}.json`)
		const setupStart = io.now()
		io.ownerSmoke(targetFile, settings.extractDir, setupReport, 'setup')
		evidence.setup = assertReportFresh(setupReport, { project, action: 'setup', cases: 1 }, setupStart)

		const verifyReport = path.join(runDir, `owners-${row}.json`)
		const verifyStart = io.now()
		io.ownerSmoke(targetFile, settings.extractDir, verifyReport, 'verify')
		evidence.verify = assertReportFresh(verifyReport, { project, action: 'verify', cases: 6 }, verifyStart)

		verifyExtractedHashes(settings.extractDir, built.manifests.files)
		evidence.extractedDriftCheck = 'post-run-rehash-ok'
		if (JSON.stringify(io.inspectOwnedResources(settings)) !== JSON.stringify(resources)) throw new Error('Package resource identity drift before cleanup')
		evidence.resources = resources
		io.compose(settings, selection, ['down', '--volumes'])
		evidence.teardown = 'owned-down-volumes'
		return evidence
	} catch (error) {
		evidence.failure = { message: error.message, retained: true }
		io.writeFile(path.join(runDir, `failure-${row}.json`), JSON.stringify(evidence, null, 2) + '\n')
		throw new Error(`Package smoke '${row}' FAILED: ${error.message}. Failed fixture and evidence are retained under ${runDir}; diagnose before any retry.`)
	}
}

// ---------------------------------------------------------------------------
// Qualification orchestration
// ---------------------------------------------------------------------------

function runQualification({ runId = Date.now().toString(36), outDir, io } = {}) {
	const runDir = outDir ?? path.join('build', 'package-smoke', `run-${runId}`)
	const realIo = {
		run,
		imageName: defaultImageName,
		verifyImage: (selection, imageName, row) => {
			try {
				return verifyImage(selection, imageName, execute, row, probe, 'current')
			} catch (error) {
				throw new Error(`Frozen runtime image for row '${row}' is missing or unverified (${imageName}): ${error.message}. Build it via 'node scripts/test-runtime.js build --runtime=${row} ${imageName}' first.`)
			}
		},
		assertFreshProject,
		inspectOwnedResources,
		compose: (settings, selection, args) => {
			const result = spawnSync('docker', composeArguments(settings, args), {
				cwd: ROOT, env: composeEnvironment(settings, selection), stdio: 'inherit', encoding: 'utf8', timeout: 900000,
			})
			if (result.error || result.status !== 0) throw new Error(`Package smoke compose step failed: ${args[0]}`)
		},
		assertAliasAbsence: target => {
			const result = run('docker', ['exec', target.container, 'bash', '-c', 'test ! -e /var/www/html/apps/chorestrike && test ! -L /var/www/html/apps/chorestrike'])
			if (result.status !== 0) throw new Error('Package smoke environment carries an /apps/chorestrike alias')
		},
		assertOccCoherence: (target, selection) => assertPackageCoherence(target, selection.nextcloud.internalVersion),
		ownerSmoke,
		writeFile: (file, content) => {
			fs.mkdirSync(path.dirname(file), { recursive: true })
			fs.writeFileSync(file, content)
		},
		now: () => Date.now(),
		buildPackageFn: options => buildPackage(options),
	}
	const steps = { ...realIo, ...io }
	fs.mkdirSync(runDir, { recursive: true })
	if (!fs.lstatSync(runDir).isDirectory() || fs.lstatSync(runDir).isSymbolicLink() || fs.readdirSync(runDir).length) throw new Error('Package run directory must be new and empty, without links')
	fs.writeFileSync(path.join(runDir, '.run-owner'), crypto.randomUUID(), { flag: 'wx' })

	const state = readGitState(steps.run)
	const inventory = readInventory()
	const built = steps.buildPackageFn({ outDir: path.join(runDir, 'package') })
	if (built.manifests.candidate?.commit !== state.head || built.manifests.candidate?.sourceDateEpoch !== state.epoch) throw new Error('Package candidate identity mismatch')
	const extractDir = path.join(runDir, 'extracted')
	extractArchive(built.archive, built.manifests.files, extractDir)

	const rows = ROWS.map(({ row }) => runRow({
		row, inventory, state, built, extractDir, runDir,
		project: `cs082-pkg-${runId}${row === 'aio' ? '-aio' : ''}`, io: steps,
	}))

	// Post-qualification identity: the archive and the extracted bytes must
	// still match the package manifest after both rows ran.
	const archivePath = path.join(runDir, 'package', 'chorestrike.tar.gz')
	const archiveSha256 = sha256Hex(fs.readFileSync(archivePath))
	if (archiveSha256 !== built.manifests.archive.sha256) throw new Error('Archive hash drifted after the smoke rows')
	verifyExtractedHashes(extractDir, built.manifests.files)
	if (JSON.stringify(readGitState(steps.run)) !== JSON.stringify(state)) throw new Error('Candidate identity drifted during qualification')

	const record = {
		status: 'package-qualified',
		gate: 'CS-PLAN-083 WP3',
		candidate: { commit: state.head, branch: state.branch, sourceDateEpoch: state.epoch },
		archive: built.manifests.archive,
		packageManifest: path.join(runDir, 'package', 'package-manifest.json'),
		extractedDriftCheck: 'post-qualification-rehash-ok',
		rows,
		qualifiedAt: new Date().toISOString(),
	}
	fs.writeFileSync(path.join(runDir, 'qualification.json'), JSON.stringify(record, null, 2) + '\n')
	return record
}

// ---------------------------------------------------------------------------
// Self-test (injected subprocesses — no Docker, no browser)
// ---------------------------------------------------------------------------

function selfTest() {
	const failures = []
	const ok = (label, condition) => {
		console.log(`  ${condition ? 'ok   ' : 'FAIL '} ${label}`)
		if (!condition) failures.push(label)
	}
	const container = overrides => structuredClone({
		Config: {
			Labels: {
				'org.chorestrike.package-smoke': 'true',
				'com.docker.compose.project': 'cs082-pkg-demo',
				'com.docker.compose.service': 'nextcloud-pgsql',
			},
			Env: ['NEXTCLOUD_TEST_MODE=false', 'NEXTCLOUD_TEST_ENV=false'],
		},
		State: { Health: { Status: 'healthy' } },
		Image: 'sha256:' + 'a'.repeat(64),
		NetworkSettings: { Ports: { '80/tcp': [{ HostIp: '127.0.0.1', HostPort: '49152' }] } },
		Mounts: [{ Destination: '/var/www/html/custom_apps/chorestrike', Source: path.resolve('/tmp/extracted'), RW: false }],
		...overrides,
	})
	const target = { project: 'cs082-pkg-demo', container: 'cs082-pkg-demo-nextcloud-pgsql-1', url: 'http://127.0.0.1:49152', image: 'sha256:' + 'a'.repeat(64) }

	console.log('self-test: package target validation')
	ok('a compliant target passes', validatePackageTarget(container({}), target, '/tmp/extracted') && true)
	ok('missing label is rejected', throws(() => validatePackageTarget(container({ Config: { Labels: {}, Env: ['NEXTCLOUD_TEST_MODE=false', 'NEXTCLOUD_TEST_ENV=false'] } }), target, '/tmp/extracted'), /label/))
	ok('true test marker is rejected', throws(() => validatePackageTarget(container({ Config: { Labels: container().Config.Labels, Env: ['NEXTCLOUD_TEST_MODE=true', 'NEXTCLOUD_TEST_ENV=false'] } }), target, '/tmp/extracted'), /false/))
	ok('non-loopback binding is rejected', throws(() => validatePackageTarget(container({ NetworkSettings: { Ports: { '80/tcp': [{ HostIp: '0.0.0.0', HostPort: '49152' }] } } }), target, '/tmp/extracted'), /loopback/))
	ok('writable package mount is rejected', throws(() => validatePackageTarget(container({ Mounts: [{ Destination: '/var/www/html/custom_apps/chorestrike', Source: '/tmp/extracted', RW: true }] }), target, '/tmp/extracted'), /read-only/))
	ok('image drift is rejected', throws(() => validatePackageTarget(container({ Image: 'sha256:' + 'b'.repeat(64) }), target, '/tmp/extracted'), /image/))
	ok('HTTP port mismatch is rejected', throws(() => validatePackageTarget(container({}), { ...target, url: 'http://127.0.0.1:49999' }, '/tmp/extracted'), /port/))

	console.log('self-test: evidence freshness and drift')
	const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-smoke-'))
	try {
		const files = [{ path: 'js/main.js', sha256: sha256Hex(Buffer.from('js')) }]
		const { verifyExtractedHashes: verify } = require('./run-package-smoke')
		fs.mkdirSync(path.join(dir, 'js'), { recursive: true })
		fs.writeFileSync(path.join(dir, 'js', 'main.js'), 'js')
		ok('extracted hashes verify', verify(dir, files))
		fs.writeFileSync(path.join(dir, 'js', 'main.js'), 'tampered')
		ok('post-run drift is rejected', throws(() => verify(dir, files), /drift/))

		const report = path.join(dir, 'setup.json')
		fs.writeFileSync(report, JSON.stringify({ project: 'cs082-pkg-demo', action: 'setup', results: [{}] }))
		ok('fresh report passes', assertReportFresh(report, { project: 'cs082-pkg-demo', action: 'setup', cases: 1 }, Date.now() - 1000) && true)
		ok('stale report is rejected', throws(() => assertReportFresh(report, { project: 'cs082-pkg-demo', action: 'setup', cases: 1 }, Date.now() + 60000), /Stale/))
		ok('wrong-run report is rejected', throws(() => assertReportFresh(report, { project: 'cs082-pkg-other', action: 'setup', cases: 1 }, Date.now() - 1000), /different run/))
		ok('wrong case count is rejected', throws(() => assertReportFresh(report, { project: 'cs082-pkg-demo', action: 'setup', cases: 6 }, Date.now() - 1000), /exactly 6/))
		ok('missing report is rejected', throws(() => assertReportFresh(path.join(dir, 'nope.json'), { project: 'cs082-pkg-demo', action: 'setup', cases: 1 }, 0), /Missing/))
	} finally {
		fs.rmSync(dir, { recursive: true, force: true })
	}

	console.log('self-test: orchestration negatives (injected subprocesses)')
	const baseIo = overrides => ({
		run: (command, args) => {
			if (command === 'git') {
				const sub = args[0]
				if (sub === 'rev-parse') return { stdout: 'd'.repeat(40), status: 0 }
				if (sub === 'symbolic-ref') return { stdout: 'refs/heads/master', status: 0 }
				if (sub === 'status') return { stdout: '', status: 0 }
				if (sub === 'show') return { stdout: '1700000000', status: 0 }
			}
			if (command === 'docker' && args[0] === 'inspect') return { stdout: '[{}]', status: 0 }
			return { stdout: '{"installed":true,"version":"35.0.1.1"}', status: 0 }
		},
		imageName: () => 'cs082-runtime-test',
		verifyImage: () => ({ imageId: 'sha256:' + 'a'.repeat(64) }),
		assertFreshProject: () => {},
		inspectOwnedResources: () => [],
		compose: () => ({ status: 0 }),
		assertAliasAbsence: () => {},
		assertOccCoherence: () => {},
		ownerSmoke: () => ({ status: 0 }),
		writeFile: () => {},
		now: () => Date.now(),
		buildPackageFn: () => ({
			archive: zlib.gzipSync(buildTar([])),
			manifests: { candidate: { commit: 'd'.repeat(40), sourceDateEpoch: 1700000000 }, archive: { sha256: sha256Hex(zlib.gzipSync(buildTar([]))) }, files: [] },
		}),
		...overrides,
	})
	// Run the real orchestration far enough to hit each injected failure.
	const orchestrate = io => {
		const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-orchestrate-'))
		try {
			runQualification({ runId: 'selftest', outDir, io: baseIo(io) })
			return null
		} catch (error) {
			return error.message
		} finally {
			fs.rmSync(outDir, { recursive: true, force: true })
		}
	}
	ok('resource collision fails before mutation', (m => m && m.includes('already has resources'))(orchestrate({
		assertFreshProject: () => { throw new Error("Package smoke project 'cs082-pkg-selftest' already has resources (cs082-pkg-selftest-nextcloud-pgsql-1); refusing to mutate foreign fixtures") },
	})))
	// Foreign-cleanup guard: composeArguments binds every step to the owned project.
	const args = composeArguments({ project: 'cs082-pkg-demo' }, ['down', '--volumes'])
	ok('cleanup arguments stay project-scoped', args.includes('--project-name') && args.includes('cs082-pkg-demo') && args.includes('down'))

	if (failures.length) {
		console.error(`\nself-test FAILED: ${failures.length} check(s)`)
		return 1
	}
	console.log('\nself-test passed: package target validation, evidence identity, drift detection and orchestration negatives all verified.')
	return 0
}

function throws(fn, pattern) {
	try {
		fn()
		return false
	} catch (error) {
		return pattern.test(error.message)
	}
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function qualificationSummary(record) {
	return {
		status: record.status,
		runDir: path.resolve(path.dirname(record.packageManifest), '..'),
		candidate: record.candidate.commit,
		archive: record.archive.sha256,
		rows: record.rows.map(row => `${row.row}@${row.nextcloud}`),
	}
}

function main() {
	const args = process.argv.slice(2)
	if (args.includes('--self-test')) return selfTest()
	if (args.length) throw new Error(`Unknown run-package-smoke argument: ${args[0]}`)
	console.log(JSON.stringify(qualificationSummary(runQualification({})), null, 2))
	return 0
}

module.exports = { validatePackageTarget, extractArchive, verifyExtractedHashes, assertReportFresh, composeArguments, assertFreshProject, inspectOwnedResources, runQualification, qualificationSummary }

if (require.main === module) {
	try {
		process.exitCode = main()
	} catch (error) {
		console.error(`[package-smoke] FAILED: ${error.message}`)
		process.exitCode = 1
	}
}
