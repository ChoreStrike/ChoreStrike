const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const zlib = require('node:zlib')
const {
	validatePackageTarget, extractArchive, verifyExtractedHashes, assertReportFresh,
	composeArguments, assertFreshProject, inspectOwnedResources, runQualification,
} = require('../../../scripts/run-package-smoke')
const { buildTar, sha256Hex } = require('../../../scripts/build-package')
const { validatePackageStatus, assertPackageCoherence } = require('../../../scripts/package-target')

const imageId = 'sha256:' + 'a'.repeat(64)

function makeContainer(overrides = {}) {
	return JSON.parse(JSON.stringify({
		Config: {
			Labels: {
				'org.chorestrike.package-smoke': 'true',
				'com.docker.compose.project': 'cs082-pkg-demo',
				'com.docker.compose.service': 'nextcloud-pgsql',
			},
			Env: ['NEXTCLOUD_TEST_MODE=false', 'NEXTCLOUD_TEST_ENV=false'],
		},
		State: { Health: { Status: 'healthy' } },
		Image: imageId,
		NetworkSettings: { Ports: { '80/tcp': [{ HostIp: '127.0.0.1', HostPort: '49152' }] } },
		Mounts: [{ Destination: '/var/www/html/custom_apps/chorestrike', Source: path.resolve('/tmp/extracted'), RW: false }],
		...overrides,
	}))
}

const target = {
	project: 'cs082-pkg-demo',
	container: 'cs082-pkg-demo-nextcloud-pgsql-1',
	url: 'http://127.0.0.1:49152',
	image: imageId,
}

describe('run-package-smoke target validation', () => {
	it('accepts a compliant package target without launching a browser', () => {
		expect(validatePackageTarget(makeContainer(), target, '/tmp/extracted')).toEqual({
			url: 'http://127.0.0.1:49152', container: target.container, project: target.project,
		})
	})

	it.each([
		['missing package-smoke label', makeContainer({ Config: { Labels: {}, Env: ['NEXTCLOUD_TEST_MODE=false', 'NEXTCLOUD_TEST_ENV=false'] } }), /label/, null],
		['true test marker', makeContainer({ Config: { Labels: makeContainer().Config.Labels, Env: ['NEXTCLOUD_TEST_MODE=true', 'NEXTCLOUD_TEST_ENV=false'] } }), /false/, null],
		['non-loopback binding', makeContainer({ NetworkSettings: { Ports: { '80/tcp': [{ HostIp: '0.0.0.0', HostPort: '49152' }] } } }), /loopback/, null],
		['writable package mount', makeContainer({ Mounts: [{ Destination: '/var/www/html/custom_apps/chorestrike', Source: path.resolve('/tmp/extracted'), RW: true }] }), /read-only/, null],
		['image drift', makeContainer({ Image: 'sha256:' + 'b'.repeat(64) }), /image/, null],
		['HTTP port mismatch', makeContainer(), /port/, { ...target, url: 'http://127.0.0.1:49999' }],
		['foreign project', makeContainer({ Config: { Labels: { ...makeContainer().Config.Labels, 'com.docker.compose.project': 'cs082-pkg-other' }, Env: makeContainer().Config.Env } }), /ownership/, null],
		['wrong service', makeContainer({ Config: { Labels: { ...makeContainer().Config.Labels, 'com.docker.compose.service': 'foreign' }, Env: makeContainer().Config.Env } }), /service/, null],
		['conflicting marker', makeContainer({ Config: { Labels: makeContainer().Config.Labels, Env: [...makeContainer().Config.Env, 'NEXTCLOUD_TEST_MODE=true'] } }), /false/, null],
		['URL credentials', makeContainer(), /credential-free/, { ...target, url: 'http://user:password@127.0.0.1:49152' }],
	])('rejects %s', (label, container, pattern, customTarget) => {
		expect(() => validatePackageTarget(container, customTarget ?? target, '/tmp/extracted')).toThrow(pattern)
	})

	it('preserves case-sensitive Linux mount paths', () => {
		const container = makeContainer({ Mounts: [{ Destination: '/var/www/html/custom_apps/chorestrike', Source: '/tmp/Extracted', RW: false }] })
		expect(() => validatePackageTarget(container, target, '/tmp/extracted')).toThrow(/exact extracted/)
	})
})

describe('run-package-smoke extraction and drift checks', () => {
	it('extracts the archive only when every manifest hash matches', () => {
		const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-extract-'))
		try {
			const data = Buffer.from('js')
			const archive = zlib.gzipSync(buildTar([
				{ name: 'js', type: 'dir', mode: 0o755, mtime: 1700000000, data: Buffer.alloc(0) },
				{ name: 'js/main.js', type: 'file', mode: 0o644, mtime: 1700000000, data },
			]))
			const extracted = path.join(dir, 'extracted')
			expect(extractArchive(archive, [{ path: 'js/main.js', sha256: sha256Hex(data) }], extracted)).toBe(true)
			expect(fs.readFileSync(path.join(extracted, 'js', 'main.js'), 'utf8')).toBe('js')
			expect(() => extractArchive(archive, [{ path: 'js/main.js', sha256: sha256Hex(data) }], extracted)).toThrow(/EEXIST/)
			expect(() => extractArchive(archive, [{ path: 'js/main.js', sha256: sha256Hex(Buffer.from('other')) }], path.join(dir, 'bad'))).toThrow(/drift/)
			expect(() => extractArchive(archive, [{ path: 'js/missing.js', sha256: sha256Hex(data) }], path.join(dir, 'missing'))).toThrow(/missing/)
		} finally {
			fs.rmSync(dir, { recursive: true, force: true })
		}
	})

	it('detects post-run extraction drift', () => {
		const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-drift-'))
		try {
			fs.mkdirSync(path.join(dir, 'js'), { recursive: true })
			fs.writeFileSync(path.join(dir, 'js', 'main.js'), 'clean')
			const files = [{ path: 'js/main.js', sha256: sha256Hex(Buffer.from('clean')) }]
			expect(verifyExtractedHashes(dir, files)).toBe(true)
			fs.writeFileSync(path.join(dir, 'js', 'main.js'), 'tampered')
			expect(() => verifyExtractedHashes(dir, files)).toThrow(/drift/)
		} finally {
			fs.rmSync(dir, { recursive: true, force: true })
		}
	})

	it('rejects extra files, extra directories and directory links before accepting identity', () => {
		const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-extra-'))
		try {
			fs.writeFileSync(path.join(dir, 'app.js'), 'app')
			const files = [{ path: 'app.js', sha256: sha256Hex(Buffer.from('app')) }]
			fs.writeFileSync(path.join(dir, 'extra.php'), 'extra')
			expect(() => verifyExtractedHashes(dir, files)).toThrow(/inventory drift/)
			fs.unlinkSync(path.join(dir, 'extra.php'))
			fs.mkdirSync(path.join(dir, 'empty'))
			expect(() => verifyExtractedHashes(dir, files)).toThrow(/inventory drift/)
			fs.rmdirSync(path.join(dir, 'empty'))
			fs.symlinkSync(dir, path.join(dir, 'link'), 'junction')
			expect(() => verifyExtractedHashes(dir, files)).toThrow(/link/)
		} finally { fs.rmSync(dir, { recursive: true, force: true }) }
	})
})

describe('package HTTP/OCC comparator', () => {
	const ready = { installed: true, version: '35.0.1.1', maintenance: false, needsDbUpgrade: false }
	it.each([{ version: '34.0.4.1' }, { maintenance: true }, { needsDbUpgrade: true }, { installed: false }])('rejects unready or mismatched HTTP status %j', override => {
		expect(() => validatePackageStatus(ready, { ...ready, ...override }, ready.version)).toThrow(/coherence/)
	})
	it('probes the real comparator without proxy or redirect following', () => {
		const calls = []
		assertPackageCoherence(target, ready.version, (command, args) => {
			calls.push({ command, args })
			return { status: 0, stdout: JSON.stringify(ready) }
		})
		expect(calls[1].command).toBe('curl')
		expect(calls[1].args).toContain('--noproxy')
		expect(calls[1].args).not.toContain('--location')
	})
})

describe('run-package-smoke evidence freshness', () => {
	it('binds reports to the run, action and case count', () => {
		const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-report-'))
		try {
			const report = path.join(dir, 'setup.json')
			fs.writeFileSync(report, JSON.stringify({ project: 'cs082-pkg-demo', action: 'setup', results: [{}] }))
			expect(assertReportFresh(report, { project: 'cs082-pkg-demo', action: 'setup', cases: 1 }, Date.now() - 1000)).toBeTruthy()
			expect(() => assertReportFresh(report, { project: 'cs082-pkg-demo', action: 'setup', cases: 1 }, Date.now() + 60000)).toThrow(/Stale/)
			expect(() => assertReportFresh(report, { project: 'cs082-pkg-other', action: 'setup', cases: 1 }, Date.now() - 1000)).toThrow(/different run/)
			expect(() => assertReportFresh(report, { project: 'cs082-pkg-demo', action: 'verify', cases: 6 }, Date.now() - 1000)).toThrow(/different run|exactly/)
			expect(() => assertReportFresh(path.join(dir, 'none.json'), { project: 'cs082-pkg-demo', action: 'setup', cases: 1 }, 0)).toThrow(/Missing/)
		} finally {
			fs.rmSync(dir, { recursive: true, force: true })
		}
	})

	// Regression (CS-PLAN-085): Linux stamps mtime from a coarse kernel clock
	// that can lag the caller's Date.now(), so a report written immediately
	// after sinceMs was sampled could carry an earlier mtime and be rejected as
	// stale. That broke the first real CI run. A report marginally older than
	// sinceMs must pass; one older than the tolerance must still be rejected.
	it('tolerates filesystem timestamp granularity but still rejects a previous run', () => {
		const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-mtime-'))
		try {
			const report = path.join(dir, 'setup.json')
			const identity = { project: 'cs082-pkg-demo', action: 'setup', cases: 1 }
			fs.writeFileSync(report, JSON.stringify({ project: 'cs082-pkg-demo', action: 'setup', results: [{}] }))
			const mtimeMs = fs.statSync(report).mtimeMs

			// Sampled "just before" the write, as the orchestrator does.
			expect(assertReportFresh(report, identity, mtimeMs + 500)).toBeTruthy()
			// Clearly from an earlier run.
			expect(() => assertReportFresh(report, identity, mtimeMs + 60000)).toThrow(/Stale/)
		} finally {
			fs.rmSync(dir, { recursive: true, force: true })
		}
	})
})

describe('run-package-smoke orchestration negatives', () => {
	const archive = zlib.gzipSync(buildTar([
		{ name: 'app.js', type: 'file', mode: 0o644, mtime: 1700000000, data: Buffer.from('app') },
	]))
	const archiveHash = sha256Hex(archive)

	const baseIo = (overrides = {}, extractDir = path.resolve('/tmp/extracted')) => ({
		run: (command, args) => {
			if (command === 'git') {
				const sub = args[0]
				if (sub === 'rev-parse') return { stdout: 'd'.repeat(40), status: 0 }
				if (sub === 'symbolic-ref') return { stdout: 'refs/heads/master', status: 0 }
				if (sub === 'status') return { stdout: '', status: 0 }
				if (sub === 'show') return { stdout: '1700000000', status: 0 }
			}
			if (command === 'docker' && args[0] === 'inspect') {
				const project = String(args[1]).replace(/-nextcloud-pgsql-1$/, '')
				return {
					stdout: JSON.stringify([makeContainer({
						Config: { Labels: { ...makeContainer().Config.Labels, 'com.docker.compose.project': project }, Env: makeContainer().Config.Env },
						Mounts: [{ Destination: '/var/www/html/custom_apps/chorestrike', Source: extractDir, RW: false }],
					})]),
					status: 0,
				}
			}
			return { stdout: '{"installed":true,"version":"35.0.1.1"}', status: 0 }
		},
		imageName: () => 'cs082-runtime-test',
		verifyImage: () => ({ imageId }),
		assertFreshProject: () => {},
		inspectOwnedResources: () => [],
		compose: () => {},
		assertAliasAbsence: () => {},
		assertOccCoherence: () => {},
		writeFile: (file, content) => {
			fs.mkdirSync(path.dirname(file), { recursive: true })
			fs.writeFileSync(file, content)
		},
		now: () => Date.now(),
		buildPackageFn: ({ outDir }) => {
			fs.mkdirSync(outDir, { recursive: true })
			fs.writeFileSync(path.join(outDir, 'chorestrike.tar.gz'), archive)
			fs.writeFileSync(path.join(outDir, 'package-manifest.json'), JSON.stringify({
				archive: { name: 'chorestrike.tar.gz', sha256: archiveHash, bytes: archive.length },
				files: [{ path: 'app.js', sha256: sha256Hex(Buffer.from('app')), size: 3 }],
			}))
			return { archive, manifests: { candidate: { commit: 'd'.repeat(40), sourceDateEpoch: 1700000000 }, archive: { sha256: archiveHash }, files: [{ path: 'app.js', sha256: sha256Hex(Buffer.from('app')) }] } }
		},
		ownerSmoke: (targetFile, extractDir, reportFile, action) => {
			const parsed = JSON.parse(fs.readFileSync(targetFile, 'utf8'))
			fs.writeFileSync(reportFile, JSON.stringify({ project: parsed.project, action, results: Array.from({ length: action === 'setup' ? 1 : 6 }) }))
		},
		...overrides,
	})

	const orchestrate = overrides => {
		const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-orchestrate-'))
		try {
			runQualification({ runId: 'specrun', outDir, io: baseIo(overrides, path.join(outDir, 'extracted')) })
			return null
		} catch (error) {
			return error.message
		} finally {
			fs.rmSync(outDir, { recursive: true, force: true })
		}
	}

	it('passes the fully injected happy path and binds evidence identities', () => {
		const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-happy-'))
		try {
			const record = runQualification({ runId: 'specrun', outDir, io: baseIo({}, path.join(outDir, 'extracted')) })
			expect(record.status).toBe('package-qualified')
			expect(record.candidate.commit).toBe('d'.repeat(40))
			expect(record.archive.sha256).toBe(archiveHash)
			expect(record.rows.map(row => row.row)).toEqual(['primary', 'aio'])
			for (const row of record.rows) {
				expect(row.setup).toBeTruthy()
				expect(row.verify.results).toHaveLength(6)
				expect(row.imageId).toBe(imageId)
			}
			const written = JSON.parse(fs.readFileSync(path.join(outDir, 'qualification.json'), 'utf8'))
			expect(written.status).toBe('package-qualified')
		} finally {
			fs.rmSync(outDir, { recursive: true, force: true })
		}
	})

	it('fails before mutation on a resource collision', () => {
		const message = orchestrate({
			assertFreshProject: settings => { throw new Error(`Package smoke project '${settings.project}' already has resources (foreign); refusing to mutate foreign fixtures`) },
		})
		expect(message).toMatch(/already has resources/)
	})

	it('fails on an existing /apps alias (alias-refusal)', () => {
		const message = orchestrate({ assertAliasAbsence: () => { throw new Error('Package smoke environment carries an /apps/chorestrike alias') } })
		expect(message).toMatch(/alias/)
	})

	it('fails on HTTP/OCC runtime coherence mismatch', () => {
		const message = orchestrate({ assertOccCoherence: () => { throw new Error('Package smoke HTTP/OCC runtime coherence mismatch') } })
		expect(message).toMatch(/coherence/)
	})

	it('fails on archive-vs-extraction drift', () => {
		const message = orchestrate({
			buildPackageFn: ({ outDir }) => {
				fs.mkdirSync(outDir, { recursive: true })
				fs.writeFileSync(path.join(outDir, 'chorestrike.tar.gz'), archive)
				return { archive, manifests: { candidate: { commit: 'd'.repeat(40), sourceDateEpoch: 1700000000 }, archive: { sha256: archiveHash }, files: [{ path: 'app.js', sha256: sha256Hex(Buffer.from('DIFFERENT')) }] } }
			},
		})
		expect(message).toMatch(/drift|mismatch/)
	})

	it('fails on a failed or interrupted setup', () => {
		const failed = orchestrate({ ownerSmoke: () => { throw new Error("owner-smoke 'setup' failed or was interrupted (exit 1); the fixture is retained for diagnosis") } })
		expect(failed).toMatch(/setup.*failed|interrupted/)
	})

	it('fails on a failed verify and never emits a success report', () => {
		const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'cs-pkg-verifyfail-'))
		try {
			let calls = 0
			expect(() => runQualification({
				runId: 'specrun', outDir,
				io: baseIo({
					ownerSmoke: (targetFile, extractDir, reportFile, action) => {
						calls++
						if (action === 'verify') throw new Error("owner-smoke 'verify' failed or was interrupted (SIGTERM); the fixture is retained for diagnosis")
						baseIo({}, extractDir).ownerSmoke(targetFile, extractDir, reportFile, action)
					},
				}, path.join(outDir, 'extracted')),
			})).toThrow(/verify.*interrupted/)
			expect(calls).toBe(2)
			// No success record may exist after a failed verify.
			expect(fs.existsSync(path.join(outDir, 'qualification.json'))).toBe(false)
			expect(fs.existsSync(path.join(outDir, 'failure-aio.json')) || fs.existsSync(path.join(outDir, 'failure-primary.json'))).toBe(true)
		} finally {
			fs.rmSync(outDir, { recursive: true, force: true })
		}
	})

	it('fails on a missing owner-smoke report', () => {
		const message = orchestrate({ ownerSmoke: () => {} })
		expect(message).toMatch(/Missing|report/)
	})

	it('fails on post-run extraction drift', () => {
		const message = orchestrate({
			ownerSmoke: (targetFile, extractDir, reportFile, action) => {
				if (action === 'verify') fs.writeFileSync(path.join(extractDir, 'app.js'), 'tampered')
				baseIo({}, extractDir).ownerSmoke(targetFile, extractDir, reportFile, action)
			},
		})
		expect(message).toMatch(/drift/)
	})

	it('scopes all cleanup arguments to the owned project', () => {
		const args = composeArguments({ project: 'cs082-pkg-demo' }, ['down', '--volumes'])
		expect(args).toEqual(['compose', '--project-name', 'cs082-pkg-demo', '--file', path.join('docker', 'compose.package-smoke.yml'), 'down', '--volumes'])
	})

	it('refuses an occupied project in the real probe', () => {
		const probe = () => ({ stdout: 'cs082-pkg-demo-nextcloud-pgsql-1\n', status: 0 })
		expect(() => assertFreshProject({ project: 'cs082-pkg-demo' }, probe)).toThrow(/already has resources/)
	})

	it.each(['volume', 'network'])('refuses orphaned %s resources before provisioning', kind => {
		const probe = (command, args) => ({ stdout: args[0] === kind ? 'retained-resource' : '', status: 0 })
		expect(() => assertFreshProject({ project: 'cs082-pkg-demo' }, probe)).toThrow(/already has resources/)
	})

	it('refuses foreign resource labels before cleanup', () => {
		expect(() => inspectOwnedResources({ project: 'cs082-pkg-demo', token: 'owned' }, () => ({ status: 0, stdout: '[{"Config":{"Labels":{}}}]' }))).toThrow(/ownership/)
	})

	it('rejects a package candidate different from qualification capture', () => {
		const message = orchestrate({ buildPackageFn: () => ({ manifests: { candidate: { commit: 'a'.repeat(40) } } }) })
		expect(message).toMatch(/candidate identity mismatch/)
	})

	it('never tears down resources whose identity changed during the browser run', () => {
		let checks = 0
		const compose = jest.fn()
		const message = orchestrate({ compose, inspectOwnedResources: () => [{ id: String(++checks) }] })
		expect(message).toMatch(/identity drift before cleanup/)
		expect(compose.mock.calls.some(call => call[2][0] === 'down')).toBe(false)
	})
})
