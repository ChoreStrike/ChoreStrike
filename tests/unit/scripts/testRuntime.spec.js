/** @jest-environment node */
const fs = require('node:fs')
const path = require('node:path')
const { validateInventory, validateRuntimeShape, selectRuntime, selectRuntimeSource, selectionHash, stableRelease, tagRevision, registryIdentity, checkStable, inventoryHash, buildArguments, assertInstalled, assertPlatformGate, assertNotificationsBuild, verifyImage, execute, UPSTREAM_BUILD_CONDITIONS } = require('../../../scripts/test-runtime')

const frozen = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../docker/test-runtime.json'), 'utf8'))
const copy = () => JSON.parse(JSON.stringify(frozen))
const release = version => ({ tag_name: `v${version}`, draft: false, prerelease: false })

const STALE_LOCK_WARNING = 'Warning: The lock file is not up to date with the latest changes in composer.json. You may be getting outdated dependencies. It is recommended that you run `composer update` or `composer update <package name>`.'
const LCOBUCCI_FAILURE = { name: 'php', version: '8.4.25', status: 'failed', failed_requirement: { source: 'lcobucci/clock', type: 'requires', target: 'php', constraint: '~8.1.0 || ~8.2.0' }, provider: null }

function probeFixtures(row = 'primary', overrides = {}) {
	return jest.fn((command, args) => {
		const line = args.join(' ')
		if (line.includes('check-platform-reqs')) {
			const thirdparty = line.includes('/var/www/html/3rdparty')
			const failures = row === 'aio' && thirdparty ? [LCOBUCCI_FAILURE] : []
			const response = {
				status: failures.length ? 1 : 0,
				stdout: JSON.stringify([{ name: 'php', version: '8.4.25', status: 'success', failed_requirement: null, provider: null }, ...failures]),
				stderr: thirdparty ? 'Checking non-dev platform requirements for packages in the vendor dir' : 'No vendor dir present, checking non-dev platform requirements from the lock file',
			}
			return { ...response, ...(overrides[thirdparty ? 'thirdparty' : 'core'] || {}) }
		}
		if (args.includes('-r')) return { status: 0, stdout: JSON.stringify({ matches: true, locked: 15, installed: 15 }), stderr: '', ...overrides.lock }
		if (args.includes('pipefail')) return { status: 0, stdout: row === 'primary' ? `${STALE_LOCK_WARNING}\n` : '', stderr: '', ...overrides.warnings }
		throw new Error(`unexpected probe: ${line}`)
	})
}

function officialFixtures(inventory = frozen) {
	return {
		fetchMetadata: jest.fn(async url => {
			if (url.includes('/server/releases/')) return JSON.stringify(release(inventory.nextcloud.version))
			if (url.includes('/all-in-one/releases/')) return JSON.stringify(release(inventory.aio.version))
			return `ENV NEXTCLOUD_VERSION=${inventory.aio.nextcloudVersion}\n`
		}),
		execute: jest.fn((command, args) => {
			if (command === 'git') {
				const rows = [inventory, ...Object.values(inventory.compatibility || {})]
				const sources = rows.flatMap(row => {
					if (args[1].includes('notifications')) return [row.notifications, row.patches?.previousNotifications].filter(Boolean)
					if (args[1].includes('viewer')) return [row.viewer, row.patches?.previousViewer].filter(Boolean)
					return [row.nextcloud, row.previous]
				})
				const source = sources.find(item => args[2] === `refs/tags/${item.ref}`)
				return `${source.revision}\trefs/tags/${source.ref}\n`
			}
			const image = [inventory.php, inventory.composer, inventory.databases.mysql, inventory.databases.pgsql].find(item => item.image === args[3])
			return JSON.stringify({ digest: image.digest, manifests: [{ digest: image.platformDigest, platform: { os: 'linux', architecture: 'amd64' } }] })
		}),
	}
}

describe('frozen runtime inventory', () => {
	it('validates the checked-in candidate without calling upstream services', () => {
		expect(validateInventory(frozen)).toBe(frozen)
		expect(inventoryHash(frozen)).toMatch(/^[a-f0-9]{64}$/)
	})
	it.each([
		['schema', value => { value.schemaVersion = 2 }],
		['qualification', value => { value.status = 'green' }],
		['platform', value => { value.platform = 'linux/arm64' }],
		['date', value => { value.verifiedAt = '2026-02-31' }],
		['prerelease', value => { value.nextcloud.version += 'rc1' }],
		['internal version', value => { value.nextcloud.internalVersion = '31.0.0.1' }],
		['mutable ref', value => { value.nextcloud.ref = 'stable34' }],
		['source revision', value => { value.nextcloud.revision = 'HEAD' }],
		['fake previous patch', value => { value.previous = { ...value.nextcloud } }],
		['previous major', value => { value.previous.version = '33.0.4'; value.previous.ref = 'v33.0.4'; value.previous.internalVersion = '33.0.4.1' }],
		['notifications', value => { value.notifications.ref = 'stable34' }],
		['mutable image', value => { value.php.image = 'php:latest' }],
		['wrong image version', value => { value.php.image = 'php:8.2.0-apache' }],
		['image digest', value => { delete value.php.digest }],
		['database digest', value => { value.databases.pgsql.platformDigest = 'unknown' }],
		['unobserved SQLite', value => { value.databases.sqlite.version = null }],
		['tool', value => { delete value.tools.apcu }],
		['distribution', value => { value.aio.version = 'latest' }],
		['AIO compatibility identity', value => { value.compatibility.aio.nextcloud.version = '34.0.5' }],
		['AIO compatibility revision', value => { value.compatibility.aio.notifications.revision = 'HEAD' }],
		['unknown compatibility row', value => { value.compatibility.unknown = value.compatibility.aio }],
	])('rejects malformed %s before execution', (_, mutate) => {
		const value = copy()
		mutate(value)
		expect(() => validateInventory(value)).toThrow()
	})
	it('selects the explicit AIO runtime without changing the primary or its historical image identity', () => {
		const value = copy()
		const primary = selectRuntime(value)
		const aio = selectRuntime(value, 'aio')
		expect(primary.nextcloud.version).toBe('35.0.1')
		expect(aio.nextcloud.version).toBe('34.0.4')
		expect(aio.previous.version).toBe('34.0.3')
		expect(inventoryHash(aio)).toBe('eafff216a7ebe4a6d8202e2cfc1fd33d4e3cf0fcd2475256e11dada0f2388469')
		expect(inventoryHash(primary)).not.toBe(inventoryHash(aio))
		expect(value).toEqual(frozen)
		expect(() => selectRuntime(value, 'unknown')).toThrow('runtime selector')
		expect(() => selectRuntime(primary, 'aio')).toThrow('missing compatibility row')
	})
	it.each([
		['forged previous notifications ref', value => { value.patches.previousNotifications.ref = 'v35.0.1' }],
		['forged previous notifications revision', value => { value.patches.previousNotifications.revision = 'HEAD' }],
		['forged previous viewer pin', value => { value.patches.previousViewer.ref = 'v35.0.1' }],
		['unknown patches key', value => { value.patches.future = {} }],
		['missing previous viewer pin', value => { delete value.patches.previousViewer }],
		['AIO previous notifications pin', value => { value.compatibility.aio.patches.previousNotifications.ref = 'v34.0.4' }],
		['viewer ref', value => { value.viewer.ref = 'v34.0.4' }],
		['viewer revision', value => { value.viewer.revision = 'HEAD' }],
		['AIO viewer pin', value => { value.compatibility.aio.viewer.revision = 'HEAD' }],
	])('rejects malformed %s before execution', (_, mutate) => {
		const value = copy()
		mutate(value)
		expect(() => validateInventory(value)).toThrow()
	})
	it('resolves peeled annotated tags rather than mistaking tag objects for source revisions', () => {
		expect(tagRevision(`${'a'.repeat(40)} refs/tags/v34.0.4\n${'b'.repeat(40)} refs/tags/v34.0.4^{}\n`, 'v34.0.4')).toBe('b'.repeat(40))
		expect(() => tagRevision('', 'v34.0.4')).toThrow('source tag response')
	})
	it.each([
		{ tag_name: 'v35.0.2rc1', draft: false, prerelease: true },
		{ tag_name: 'v35.0.2', draft: true, prerelease: false },
		{ tag_name: 'v35.0.2', draft: false },
		{ tag_name: 'latest', draft: false, prerelease: false },
	])('refuses prerelease or malformed stable metadata %j', value => {
		expect(() => stableRelease(value, 'Nextcloud')).toThrow('stable release metadata')
	})
	it('requires the frozen architecture rather than taking the first registry row', () => {
		const manifest = { digest: frozen.php.digest, manifests: [{ digest: frozen.php.platformDigest, platform: { os: 'linux', architecture: 'arm64' } }] }
		expect(() => registryIdentity(manifest, frozen.platform)).toThrow('registry manifest/platform')
	})
})

describe('pinned update-source (previous patch) selection', () => {
	it('selects the real older patch with its own notifications pin, keeping the reviewed current hashes stable', () => {
		const value = copy()
		const currentPrimary = selectRuntimeSource(value, 'primary', 'current')
		const currentAio = selectRuntimeSource(value, 'aio', 'current')
		const previousPrimary = selectRuntimeSource(value, 'primary', 'previous')
		const previousAio = selectRuntimeSource(value, 'aio', 'previous')
		expect(previousPrimary.nextcloud.version).toBe('35.0.0')
		expect(previousPrimary.previous.version).toBe('35.0.0')
		expect(previousPrimary.notifications).toEqual({ ref: 'v35.0.0', revision: 'fe532372b98ed013321f95bc0095f19660191d09' })
		expect(previousPrimary.viewer).toEqual({ ref: 'v35.0.0', revision: 'cd26e86ac4123b5fcb90e523d257992f25ddf587' })
		expect(previousAio.nextcloud.version).toBe('34.0.3')
		expect(previousAio.notifications.ref).toBe('v34.0.3')
		expect(previousAio.viewer).toEqual({ ref: 'v34.0.3', revision: '1556dd3a53cc280a28eb082d1cca15b6eb8f3c96' })
		expect(selectionHash(currentPrimary)).toBe('588009a22fadb122059d509a4dab3256241258052fe6e1daa5a9ebf0c6e5546f')
		expect(selectionHash(currentAio)).toBe('eafff216a7ebe4a6d8202e2cfc1fd33d4e3cf0fcd2475256e11dada0f2388469')
		expect(selectionHash(previousPrimary)).not.toBe(selectionHash(currentPrimary))
		expect(selectionHash(previousAio)).not.toBe(selectionHash(currentAio))
		expect(value).toEqual(frozen)
	})
	it('refuses unknown patch selectors and missing previous app pins', () => {
		expect(() => selectRuntimeSource(frozen, 'primary', 'future')).toThrow('patch selector')
		const value = copy()
		delete value.patches.previousNotifications
		expect(() => selectRuntimeSource(value, 'primary', 'previous')).toThrow(/previous app pins|inventory: patches/)
		expect(() => selectRuntimeSource(value, 'primary', 'current')).toThrow('inventory: patches')
		delete value.patches
		expect(() => selectRuntimeSource(value, 'primary', 'previous')).toThrow('previous app pins')
		expect(() => selectRuntimeSource(value, 'primary', 'current')).not.toThrow()
	})
	it('builds the pinned previous-patch source image with its own label hash', () => {
		const previous = selectRuntimeSource(copy(), 'primary', 'previous')
		const args = buildArguments(previous, 'cs082-runtime-35.0.0-local')
		expect(args).toEqual(expect.arrayContaining([
			`NEXTCLOUD_REF=v35.0.0`, `NEXTCLOUD_REVISION=${frozen.previous.revision}`,
			`NOTIFICATIONS_REF=v35.0.0`, `NOTIFICATIONS_REVISION=${previous.notifications.revision}`,
			`VIEWER_REF=v35.0.0`, `VIEWER_REVISION=${previous.viewer.revision}`,
		]))
		expect(args.join(' ')).toContain(`RUNTIME_INVENTORY_SHA256=${selectionHash(previous)}`)
		expect(args.join(' ')).not.toContain(frozen.nextcloud.revision)
	})
	it('threads the patch through verifyImage so a previous source image records its own named conditions', () => {
		const previous = selectRuntimeSource(copy(), 'primary', 'previous')
		const actual = {
			nextcloud: previous.nextcloud.version, internalVersion: previous.nextcloud.internalVersion,
			revision: previous.nextcloud.revision, notificationsRevision: previous.notifications.revision, viewerRevision: previous.viewer.revision,
			php: previous.php.version, sqlite: previous.databases.sqlite.version, pcov: previous.tools.pcov, apcu: previous.tools.apcu,
		}
		const run = jest.fn((command, args) => {
			if (args[0] === 'image') return JSON.stringify([{ Id: `sha256:${'3'.repeat(64)}`, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': selectionHash(previous) } } }])
			if (args.includes('composer')) return `Composer version ${previous.composer.version}`
			if (args.at(-1).includes('notifications-ready')) return 'notifications-ready'
			return JSON.stringify(actual)
		})
		expect(verifyImage(previous, 'cs082-runtime-35.0.0-local', run, 'primary', probeFixtures(), 'previous')).toMatchObject({
			status: 'frozen-image-identity-match', nextcloud: '35.0.0',
			upstreamLimitations: [`${previous.notifications.ref}-notifications-stale-root-lock`],
		})
		const mismatchedLabel = jest.fn((command, args) => args[0] === 'image'
			? JSON.stringify([{ Id: `sha256:${'3'.repeat(64)}`, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': selectionHash(selectRuntime(copy())) } } }])
			: run(command, args))
		expect(() => verifyImage(previous, 'cs082-runtime-35.0.0-local', mismatchedLabel, 'primary', probeFixtures(), 'previous'))
			.toThrow('inventory/platform mismatch')
	})
})

describe('read-only stable discovery', () => {
	it('fails on diagnostic output even if a native probe exits successfully', () => {
		expect(() => execute(process.execPath, ['-e', "process.stdout.write('valid');process.stderr.write('diagnostic')"])).toThrow('emitted diagnostics')
		expect(() => execute(process.execPath, ['-e', "process.stdout.write('valid');process.stderr.write('warning')"], 'expected metadata')).toThrow('emitted diagnostics')
	})
	it('reports matching artifacts without upgrading a candidate to qualified', async () => {
		const io = officialFixtures()
		await expect(checkStable(frozen, io)).resolves.toMatchObject({ status: 'current', qualification: 'candidate', drift: [] })
		expect(io.execute.mock.calls.every(([command, args]) => command === 'git' ? args[0] === 'ls-remote' : args.slice(0, 3).join(' ') === 'buildx imagetools inspect')).toBe(true)
	})
	it('reports newer upstream separately from the unchanged AIO bundle without rewriting the lock', async () => {
		const value = copy()
		const io = officialFixtures()
		io.fetchMetadata.mockImplementation(async url => url.includes('/server/releases/') ? JSON.stringify(release('35.0.2'))
			: url.includes('/releases/') ? JSON.stringify(release(frozen.aio.version)) : `ENV NEXTCLOUD_VERSION=${frozen.aio.nextcloudVersion}`)
		await expect(checkStable(value, io)).resolves.toMatchObject({ status: 'update-needed', upstream: '35.0.2', aioNextcloud: '34.0.4', drift: ['upstream-nextcloud'] })
		expect(value).toEqual(frozen)
	})
	it('reports a different AIO bundle even when its Nextcloud version stays unchanged', async () => {
		const io = officialFixtures()
		io.fetchMetadata.mockImplementation(async url => url.includes('/server/releases/') ? JSON.stringify(release(frozen.nextcloud.version))
			: url.includes('/releases/') ? JSON.stringify(release('14.3.0')) : `ENV NEXTCLOUD_VERSION=${frozen.aio.nextcloudVersion}`)
		await expect(checkStable(frozen, io)).resolves.toMatchObject({ status: 'update-needed', drift: ['aio-bundle'] })
	})
	it('detects moved source tags and image digests', async () => {
		const io = officialFixtures()
		const execute = io.execute.getMockImplementation()
		io.execute.mockImplementation((command, args) => command === 'git' ? `${'a'.repeat(40)}\t${args[2]}\n`
			: JSON.stringify({ ...JSON.parse(execute(command, args)), digest: `sha256:${'f'.repeat(64)}` }))
		const result = await checkStable(frozen, io)
		expect(result.status).toBe('update-needed')
		expect(result.drift).toHaveLength(16)
	})
	it.each(['release network failure', 'registry failure', 'malformed release', 'malformed AIO bundle', 'prerelease'])('fails closed on %s', async failure => {
		const io = officialFixtures()
		if (failure === 'release network failure') io.fetchMetadata.mockRejectedValue(new Error('unavailable'))
		if (failure === 'registry failure') io.execute.mockImplementation(() => { throw new Error('registry unavailable') })
		if (failure === 'malformed release') io.fetchMetadata.mockResolvedValue('{}')
		if (failure === 'malformed AIO bundle') io.fetchMetadata.mockImplementation(async url => url.includes('/releases/') ? JSON.stringify(release('34.0.4')) : '')
		if (failure === 'prerelease') io.fetchMetadata.mockResolvedValue(JSON.stringify({ ...release('35.0.2'), prerelease: true }))
		await expect(checkStable(frozen, io)).rejects.toThrow()
	})
})

describe('frozen candidate execution', () => {
	it('bounds candidate metadata to the approved primary/AIO majors and PHP minor, not untested old runtimes', () => {
		const metadata = fs.readFileSync(path.resolve(__dirname, '../../../appinfo/info.xml'), 'utf8')
		const composer = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../composer.json'), 'utf8'))
		expect(metadata).toContain('<nextcloud min-version="34" max-version="35"/>')
		expect(metadata).toContain('<php min-version="8.4" max-version="8.4"/>')
		expect(composer.require.php).toBe('~8.4.0')
	})
	it('wires weekly and manual pre-release checks to the same read-only checker', () => {
		const workflow = fs.readFileSync(path.resolve(__dirname, '../../../.forgejo/workflows/runtime-check.yml'), 'utf8')
		expect(workflow).toContain("cron: '0 8 * * 1'")
		expect(workflow).toContain('workflow_dispatch:')
		expect(workflow).toContain('run: npm run test:runtime:validate')
		expect(workflow).toContain('run: npm run test:runtime:check')
		expect(workflow).not.toMatch(/docker (compose|build|run)|git (commit|push)/)
	})
	it('uses only locked identities and refuses non-owned image tags', () => {
		const args = buildArguments(selectRuntime(frozen), 'cs082-runtime-34.0.4-local')
		expect(args).toEqual(expect.arrayContaining([
		`PHP_IMAGE=${frozen.php.image}@${frozen.php.digest}`,
		`COMPOSER_IMAGE=${frozen.composer.image}@${frozen.composer.digest}`,
		`NEXTCLOUD_REVISION=${frozen.nextcloud.revision}`, `NOTIFICATIONS_REVISION=${frozen.notifications.revision}`,
		`VIEWER_REVISION=${frozen.viewer.revision}`,
	]))
		expect(args.join(' ')).not.toMatch(/latest|stable34/)
		expect(() => buildArguments(selectRuntime(frozen), 'docker-nextcloud-test')).toThrow('owned candidate image name')
		expect(() => buildArguments(frozen, 'cs082-runtime-34.0.4-local')).toThrow('exact selected runtime')
	})
	it('asserts actual runtime identity instead of trusting healthy older containers', () => {
		const selection = selectRuntime(frozen)
		const actual = {
			nextcloud: frozen.nextcloud.version, internalVersion: frozen.nextcloud.internalVersion,
			revision: frozen.nextcloud.revision, notificationsRevision: frozen.notifications.revision, viewerRevision: frozen.viewer.revision,
			php: frozen.php.version, sqlite: frozen.databases.sqlite.version,
			pcov: frozen.tools.pcov, apcu: frozen.tools.apcu, inventoryHash: inventoryHash(frozen),
		}
		expect(assertInstalled(selection, actual)).toEqual(actual)
		for (const key of Object.keys(actual)) expect(() => assertInstalled(selection, { ...actual, [key]: 'older' })).toThrow(`Installed runtime mismatch: ${key}`)
		expect(() => assertInstalled(frozen, actual)).toThrow('exact selected runtime')
	})
	it('rejects old or mismatched images before creating any ephemeral probe container', () => {
		const run = jest.fn(() => JSON.stringify([{ Os: 'linux', Architecture: 'amd64', Config: { Labels: {} } }]))
		expect(() => verifyImage(selectRuntime(frozen), 'cs082-runtime-34.0.4-local', run)).toThrow('inventory/platform mismatch')
		expect(run).toHaveBeenCalledTimes(1)
	})
	it('verifies the frozen image and tools without claiming an installed/provider gate', () => {
		const selection = selectRuntime(frozen)
		const actual = {
			nextcloud: frozen.nextcloud.version, internalVersion: frozen.nextcloud.internalVersion,
			revision: frozen.nextcloud.revision, notificationsRevision: frozen.notifications.revision, viewerRevision: frozen.viewer.revision,
			php: frozen.php.version, sqlite: frozen.databases.sqlite.version, pcov: frozen.tools.pcov, apcu: frozen.tools.apcu,
		}
		const run = jest.fn((command, args) => {
			if (args[0] === 'image') return JSON.stringify([{ Id: `sha256:${'1'.repeat(64)}`, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': inventoryHash(frozen) } } }])
			if (args.includes('composer')) return `Composer version ${frozen.composer.version}`
			if (args.at(-1).includes('notifications-ready')) return 'notifications-ready'
			return JSON.stringify(actual)
		})
		const probe = probeFixtures()
		expect(verifyImage(selection, 'cs082-runtime-34.0.4-local', run, 'primary', probe)).toMatchObject({ status: 'frozen-image-identity-match', qualification: 'candidate', composer: frozen.composer.version,
			notificationsLockedPackages: 15, upstreamLimitations: [`${frozen.notifications.ref}-notifications-stale-root-lock`] })
		expect(run.mock.calls.slice(1).every(([, args]) => args.includes('--rm') && args.includes('none'))).toBe(true)
		expect(run.mock.calls.slice(1).every(([, args]) => args.includes(`sha256:${'1'.repeat(64)}`) && !args.includes('cs082-runtime-34.0.4-local'))).toBe(true)
		expect(probe.mock.calls.every(([, args]) => args.includes('--rm') && args.includes('none') && args.includes(`sha256:${'1'.repeat(64)}`))).toBe(true)
		const original = run.getMockImplementation()
		run.mockImplementation((command, args) => args.at(-1).includes('notifications-ready') ? '' : original(command, args))
		expect(() => verifyImage(selection, 'cs082-runtime-34.0.4-local', run, 'primary', probeFixtures())).toThrow('notifications runtime autoload mismatch')
		run.mockImplementation((command, args) => args.includes('composer') ? 'Composer version 2.0.0' : original(command, args))
		expect(() => verifyImage(selection, 'cs082-runtime-34.0.4-local', run, 'primary', probeFixtures())).toThrow('mismatch: composer')
	})
})

describe('upstream build condition dispositions', () => {
	const imageId = `sha256:${'2'.repeat(64)}`
	it('accepts the clean primary platform gate and records the exact notifications stale-lock warning', () => {
		expect(assertPlatformGate('primary', imageId, probeFixtures())).toEqual([])
		expect(assertNotificationsBuild('primary', imageId, probeFixtures())).toEqual({ lockedPackages: 15, warnings: [STALE_LOCK_WARNING] })
	})
	it('accepts the exact named NC34 lcobucci/clock limitation on the AIO row without relabeling it as passed', () => {
		expect(assertPlatformGate('aio', imageId, probeFixtures('aio'))).toEqual([{ name: 'php', source: 'lcobucci/clock', constraint: '~8.1.0 || ~8.2.0' }])
		expect(assertNotificationsBuild('aio', imageId, probeFixtures('aio'))).toEqual({ lockedPackages: 15, warnings: [] })
	})
	it.each([
		['unexpected core failure', 'primary', { core: { status: 1, stdout: JSON.stringify([LCOBUCCI_FAILURE]) } }, 'core platform gate mismatch'],
		['unexpected primary 3rdparty failure', 'primary', { thirdparty: { status: 1, stdout: JSON.stringify([LCOBUCCI_FAILURE]) } }, '3rdparty platform gate mismatch'],
		['different AIO failure', 'aio', { thirdparty: { stdout: JSON.stringify([{ ...LCOBUCCI_FAILURE, failed_requirement: { ...LCOBUCCI_FAILURE.failed_requirement, constraint: '~8.2.0' } }]) } }, '3rdparty platform gate mismatch'],
		['additional AIO failure', 'aio', { thirdparty: { stdout: JSON.stringify([LCOBUCCI_FAILURE, { ...LCOBUCCI_FAILURE, name: 'ext-gmp' }]) } }, '3rdparty platform gate mismatch'],
		['unexpectedly clean AIO gate', 'aio', { thirdparty: { status: 0, stdout: JSON.stringify([{ name: 'php', version: '8.4.25', status: 'success', failed_requirement: null, provider: null }]) } }, '3rdparty platform gate mismatch'],
		['probe diagnostics', 'primary', { core: { stderr: 'Deprecated: something' } }, 'core platform probe emitted unexpected diagnostics'],
		['malformed probe output', 'primary', { core: { stdout: 'not json' } }, 'core platform probe malformed'],
	])('fails closed on %s', (_, row, overrides, message) => {
		expect(() => assertPlatformGate(row, imageId, probeFixtures(row, overrides))).toThrow(message)
	})
	it.each([
		['vendor divergence', 'primary', { lock: { stdout: JSON.stringify({ matches: false, locked: 15, installed: 14 }) } }, 'diverges from the upstream lock'],
		['lock probe failure', 'primary', { lock: { status: 1 } }, 'lock probe failed'],
		['missing primary stale-lock warning', 'primary', { warnings: { stdout: '' } }, 'build warnings mismatch'],
		['unexpected AIO warning', 'aio', { warnings: { stdout: `${STALE_LOCK_WARNING}\n` } }, 'build warnings mismatch'],
		['warnings probe failure', 'primary', { warnings: { status: 1 } }, 'build probe failed'],
	])('fails closed on %s', (_, row, overrides, message) => {
		expect(() => assertNotificationsBuild(row, imageId, probeFixtures(row, overrides))).toThrow(message)
	})
	it('rejects unknown rows or patches instead of defaulting to permissive expectations', () => {
		expect(() => assertPlatformGate('unknown', imageId, probeFixtures())).toThrow('Unknown runtime row/patch')
		expect(() => assertPlatformGate('primary', imageId, probeFixtures(), 'unknown')).toThrow('Unknown runtime row/patch')
		expect(UPSTREAM_BUILD_CONDITIONS.primary.current.notificationsWarnings).toEqual([STALE_LOCK_WARNING])
		expect(UPSTREAM_BUILD_CONDITIONS.aio.current['3rdpartyFailures']).toEqual([{ name: 'php', source: 'lcobucci/clock', constraint: '~8.1.0 || ~8.2.0' }])
	})
	it('threads the row through verifyImage so the AIO image records its named limitation', () => {
		const aio = selectRuntime(copy(), 'aio')
		const actual = {
			nextcloud: aio.nextcloud.version, internalVersion: aio.nextcloud.internalVersion,
			revision: aio.nextcloud.revision, notificationsRevision: aio.notifications.revision, viewerRevision: aio.viewer.revision,
			php: aio.php.version, sqlite: aio.databases.sqlite.version, pcov: aio.tools.pcov, apcu: aio.tools.apcu,
		}
		const run = jest.fn((command, args) => {
			if (args[0] === 'image') return JSON.stringify([{ Id: imageId, Os: 'linux', Architecture: 'amd64', Config: { Labels: { 'org.chorestrike.test-runtime.inventory': inventoryHash(aio) } } }])
			if (args.includes('composer')) return `Composer version ${aio.composer.version}`
			if (args.at(-1).includes('notifications-ready')) return 'notifications-ready'
			return JSON.stringify(actual)
		})
		expect(verifyImage(aio, 'cs082-runtime-34.0.4-local', run, 'aio', probeFixtures('aio'))).toMatchObject({
			status: 'frozen-image-identity-match',
			upstreamLimitations: [`${aio.nextcloud.ref}-3rdparty-lcobucci/clock-~8.1.0 || ~8.2.0-php-${aio.php.version}`],
		})
	})
})
